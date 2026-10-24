<?php
/**
 * What Forge last knew about the studio's servers.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Cloudways;

use Blueworx\Forge\Tenancy\ClientSites;

/**
 * #621. Cloudways is asked only when somebody looks: opening the Servers
 * page, or pressing Check now. Nothing runs in the background, so what is
 * kept here is the last thing anybody saw, and the standup flags from it.
 *
 * Two throttles, so opening the page twice does not ask Cloudways twice:
 * opening reuses a check under five minutes old, and Check now reuses one
 * under fifteen seconds old.
 *
 * When Cloudways cannot be asked, each server shows as unreachable — Forge
 * does not know what it is doing — but keeps the state Cloudways last
 * reported, and the standup goes on flagging by that. An outage at
 * Cloudways neither hides a server that was down nor flags every server.
 */
final class Status {

	/**
	 * What Cloudways calls a server that is up.
	 */
	public const RUNNING = 'running';

	/**
	 * What a server is shown as when Cloudways could not be asked.
	 */
	public const UNREACHABLE = 'unreachable';

	/**
	 * How old a check may be before opening the page asks again, in seconds.
	 */
	private const REUSE = 300;

	/**
	 * How old a check may be before Check now asks again, in seconds.
	 */
	private const FORCED_REUSE = 15;

	/**
	 * The servers, checked now if the last check is old enough.
	 *
	 * @param bool                                  $force Check now was pressed.
	 * @param array<int, array<string, mixed>>|null $sites Client sites to match against; the active ones when null.
	 * @return array{checked_at: int, error: string, servers: array<int, array<string, mixed>>}
	 */
	public static function read( bool $force, ?array $sites = null ): array {
		$last = self::last();
		$age  = bwx_forge_now() - (int) $last['checked_at'];

		if ( (int) $last['checked_at'] > 0 && $age < ( $force ? self::FORCED_REUSE : self::REUSE ) ) {
			return $last;
		}

		$servers = Client::servers();

		if ( is_wp_error( $servers ) ) {
			$known = array();

			foreach ( $last['servers'] as $server ) {
				$server['last_state'] = (string) ( $server['last_state'] ?? $server['state'] );
				$server['state']      = self::UNREACHABLE;
				$known[]              = $server;
			}

			return self::keep( $known, $servers->get_error_message() );
		}

		$hosts = self::hosts( $sites ?? ClientSites::all( 'active' ) );
		$kept  = array();

		foreach ( $servers as $server ) {
			$apps = array();

			foreach ( $server['apps'] as $app ) {
				$match = array(
					'id'   => '',
					'name' => '',
				);

				foreach ( $app['domains'] as $domain ) {
					$host = Address::host( $domain );

					if ( isset( $hosts[ $host ] ) ) {
						$match = $hosts[ $host ];
						break;
					}
				}

				$apps[] = array(
					'label'          => $app['label'],
					'domains'        => $app['domains'],
					'client_site_id' => $match['id'],
					'site_name'      => $match['name'],
				);
			}

			$kept[] = array(
				'id'         => $server['id'],
				'label'      => $server['label'],
				'state'      => $server['status'],
				'last_state' => $server['status'],
				'apps'       => $apps,
			);
		}

		return self::keep( $kept, '' );
	}

	/**
	 * What was last seen, without asking Cloudways.
	 *
	 * @return array{checked_at: int, error: string, servers: array<int, array<string, mixed>>}
	 */
	public static function last(): array {
		$stored = get_option( Settings::STATUS, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'checked_at' => (int) ( $stored['checked_at'] ?? 0 ),
			'error'      => (string) ( $stored['error'] ?? '' ),
			'servers'    => array_values( (array) ( $stored['servers'] ?? array() ) ),
		);
	}

	/**
	 * Servers that were not running when Cloudways was last asked, as the
	 * standup's "needs intervention" problems. Nothing when Cloudways is not
	 * connected.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function problems(): array {
		if ( ! Settings::connected() ) {
			return array();
		}

		$last     = self::last();
		$problems = array();

		foreach ( $last['servers'] as $server ) {
			$state = (string) ( $server['last_state'] ?? $server['state'] ?? '' );

			if ( self::RUNNING === $state ) {
				continue;
			}

			$clients = array_values( array_unique( array_filter( array_column( (array) $server['apps'], 'site_name' ) ) ) );

			$problems[] = array(
				'id'             => 'server-' . (string) $server['id'],
				'subject_type'   => 'server',
				'about'          => (string) $server['id'],
				'title'          => (string) $server['label'],
				'kind'           => 'server-down',
				'client_site_id' => '',
				'detail'         => array() === $clients
					/* translators: %s: the state Cloudways reported, such as "stopped". */
					? sprintf( __( 'Server %s', 'blueworx-forge' ), '' === $state ? __( 'not running', 'blueworx-forge' ) : $state )
					/* translators: 1: the state Cloudways reported, 2: the clients on that server. */
					: sprintf( __( 'Server %1$s. Affects %2$s', 'blueworx-forge' ), '' === $state ? __( 'not running', 'blueworx-forge' ) : $state, implode( ', ', $clients ) ),
				'clients'        => $clients,
				'since'          => (int) $last['checked_at'],
			);
		}

		return $problems;
	}

	/**
	 * Saves a check.
	 *
	 * @param array<int, array<string, mixed>> $servers The servers.
	 * @param string                           $error   What went wrong, or ''.
	 * @return array{checked_at: int, error: string, servers: array<int, array<string, mixed>>}
	 */
	private static function keep( array $servers, string $error ): array {
		$status = array(
			'checked_at' => bwx_forge_now(),
			'error'      => $error,
			'servers'    => $servers,
		);

		update_option( Settings::STATUS, $status, false );

		return $status;
	}

	/**
	 * Client sites by host.
	 *
	 * @param array<int, array<string, mixed>> $sites Client sites.
	 * @return array<string, array{id: string, name: string}>
	 */
	private static function hosts( array $sites ): array {
		$hosts = array();

		foreach ( $sites as $site ) {
			$host = Address::host( (string) ( $site['url'] ?? '' ) );

			if ( '' !== $host && ! isset( $hosts[ $host ] ) ) {
				$hosts[ $host ] = array(
					'id'   => (string) $site['id'],
					'name' => (string) ( $site['name'] ?? '' ),
				);
			}
		}

		return $hosts;
	}
}
