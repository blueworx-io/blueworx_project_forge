<?php
/**
 * Which GitHub repositories belong to which client site.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\GitHub;

use Blueworx\Forge\Data\Schema;
use Blueworx\Forge\Rest\Errors;
use Blueworx\Forge\Tenancy\ClientSites;
use WP_Error;

/**
 * #680. A repository belongs to one client site at most; a site can have
 * several. Only repositories the connected GitHub account owns can be linked,
 * and linking one adds Forge's webhook to it (see Hooks).
 */
final class Repos {

	/**
	 * Tidies a repository name: trimmed, lower case, owner/name or nothing.
	 *
	 * @param string $repo What was typed or sent.
	 * @return string
	 */
	public static function normalise( string $repo ): string {
		$repo = strtolower( trim( $repo ) );

		return 1 === preg_match( '#^[a-z0-9_.-]+/[a-z0-9_.-]+$#', $repo ) ? $repo : '';
	}

	/**
	 * The wanted repositories that another site already holds.
	 *
	 * @param array<int, string>    $wanted  Repositories to link.
	 * @param string                $site_id The site they are for.
	 * @param array<string, string> $taken   Repository to site id, as linked now.
	 * @return array<string, string> Repository to the other site's id.
	 */
	public static function conflicts( array $wanted, string $site_id, array $taken ): array {
		$found = array();

		foreach ( $wanted as $repo ) {
			if ( isset( $taken[ $repo ] ) && $taken[ $repo ] !== $site_id ) {
				$found[ $repo ] = $taken[ $repo ];
			}
		}

		return $found;
	}

	/**
	 * Every link, repository to site id.
	 *
	 * @return array<string, string>
	 */
	public static function all(): array {
		global $wpdb;

		$table = Schema::github_repos_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$rows = $wpdb->get_results( "SELECT repo, client_site_id FROM {$table}", ARRAY_A );
		$all  = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$all[ (string) $row['repo'] ] = (string) $row['client_site_id'];
		}

		return $all;
	}

	/**
	 * The repositories linked to a site.
	 *
	 * @param string $site_id The site.
	 * @return array<int, string>
	 */
	public static function for_site( string $site_id ): array {
		$repos = array_keys( array_filter( self::all(), static fn( string $id ): bool => $id === $site_id ) );
		sort( $repos );

		return $repos;
	}

	/**
	 * The site a repository is linked to.
	 *
	 * @param string $repo The repository.
	 * @return string The site id, or an empty string.
	 */
	public static function site_for( string $repo ): string {
		return self::all()[ self::normalise( $repo ) ] ?? '';
	}

	/**
	 * Replaces a site's repositories.
	 *
	 * @param string             $site_id The site.
	 * @param array<int, string> $repos   Repositories to link.
	 * @param int                $by      The WordPress user.
	 * @return true|WP_Error
	 */
	public static function set_for_site( string $site_id, array $repos, int $by ) {
		$wanted = array();

		foreach ( $repos as $repo ) {
			$name = self::normalise( (string) $repo );

			if ( '' === $name ) {
				return Errors::rest( 'github_repo_unknown', __( 'That is not a repository name. Choose from the list.', 'blueworx-forge' ), 400 );
			}

			$wanted[ $name ] = $name;
		}

		$wanted = array_values( $wanted );

		if ( array() !== $wanted ) {
			$known = self::known( $wanted );

			if ( is_wp_error( $known ) ) {
				return Client::refusal(
					$known,
					__( 'GitHub could not be reached, so the repositories could not be checked. Try again.', 'blueworx-forge' ),
					__( 'GitHub would not list the repositories, so they could not be checked. Try again.', 'blueworx-forge' )
				);
			}

			foreach ( $wanted as $repo ) {
				if ( ! in_array( $repo, $known, true ) ) {
					return Errors::rest(
						'github_repo_unknown',
						/* translators: %s: the repository. */
						sprintf( __( '%s is not one of the connected GitHub account\'s repositories.', 'blueworx-forge' ), $repo ),
						400
					);
				}
			}
		}

		foreach ( self::conflicts( $wanted, $site_id, self::all() ) as $repo => $other ) {
			return self::taken( $repo, $other );
		}

		global $wpdb;

		$table   = Schema::github_repos_table();
		$current = self::for_site( $site_id );

		// Only what changes is touched, so a refused add leaves the rest as it was.
		foreach ( array_diff( $current, $wanted ) as $repo ) {
			// Unlinked whatever GitHub says: a hook left behind only sends
			// messages Forge ignores, and the failure is shown on Connections.
			if ( is_wp_error( Hooks::remove( $repo, self::hook_id( $repo ) ) ) ) {
				/* translators: %s: the repository. */
				Settings::failed( sprintf( __( 'Forge could not take its webhook off %s when it was unlinked. Delete it in the repo\'s settings on GitHub.', 'blueworx-forge' ), $repo ) );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete(
				$table,
				array(
					'repo'           => $repo,
					'client_site_id' => $site_id,
				)
			);
		}

		foreach ( array_diff( $wanted, $current ) as $repo ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$added = $wpdb->insert(
				$table,
				array(
					'repo'           => $repo,
					'client_site_id' => $site_id,
					'hook_id'        => 0,
					'created_at'     => bwx_forge_now(),
					'created_by'     => $by,
				)
			);

			if ( false === $added ) {
				// Someone linked it first: the repo is the table's key.
				return self::taken( $repo, self::all()[ $repo ] ?? '' );
			}

			// The link is claimed first, then the hook added. A repo GitHub's
			// messages would never reach is not linked at all.
			$hook = Hooks::put( $repo, (string) Settings::secret() );

			if ( is_wp_error( $hook ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->delete( $table, array( 'repo' => $repo ) );

				$refusal = Client::refusal(
					$hook,
					/* translators: %s: the repository. */
					sprintf( __( 'GitHub could not be reached, so %s wasn\'t linked. Try again.', 'blueworx-forge' ), $repo ),
					/* translators: %s: the repository. */
					sprintf( __( 'GitHub didn\'t let Forge add its webhook to %s, so it wasn\'t linked. Check the token can manage webhooks.', 'blueworx-forge' ), $repo )
				);

				Settings::failed( $refusal->get_error_message() );

				return $refusal;
			}

			self::keep_hook( $repo, $hook );
		}

		return true;
	}

	/**
	 * After a reconnect, signs every linked repo's hook with the new secret.
	 * A repo whose hook could not be updated stays linked; the failure is
	 * shown on the Connections page.
	 *
	 * @param string $secret The new secret.
	 * @return int How many could not be updated.
	 */
	public static function refresh_hooks( string $secret ): int {
		$failed = 0;

		foreach ( array_keys( self::all() ) as $repo ) {
			$hook = Hooks::put( $repo, $secret );

			if ( is_wp_error( $hook ) ) {
				++$failed;
				/* translators: %s: the repository. */
				Settings::failed( sprintf( __( 'Forge could not update its webhook on %s, so GitHub\'s messages from it will be refused. Unlink the repo and link it again.', 'blueworx-forge' ), $repo ) );
				continue;
			}

			self::keep_hook( $repo, $hook );
		}

		return $failed;
	}

	/**
	 * The repositories GitHub lists, asked again once if any wanted is missing:
	 * the list is kept for ten minutes and a repo may have been made since.
	 *
	 * @param array<int, string> $wanted Repositories to link.
	 * @return array<int, string>|WP_Error
	 */
	public static function known( array $wanted ) {
		$known = Client::repos();

		if ( is_wp_error( $known ) || array() === array_diff( $wanted, $known ) ) {
			return $known;
		}

		delete_transient( Settings::REPOS );

		return Client::repos();
	}

	/**
	 * The id of the hook Forge added to a repo.
	 *
	 * @param string $repo The repository.
	 * @return int 0 when none is recorded.
	 */
	private static function hook_id( string $repo ): int {
		global $wpdb;

		$table = Schema::github_repos_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT hook_id FROM {$table} WHERE repo = %s", $repo ) );
	}

	/**
	 * Records the hook Forge added to a repo.
	 *
	 * @param string $repo The repository.
	 * @param int    $id   The hook's id on GitHub.
	 */
	private static function keep_hook( string $repo, int $id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( Schema::github_repos_table(), array( 'hook_id' => $id ), array( 'repo' => $repo ), array( '%d' ), array( '%s' ) );
	}

	/**
	 * The name of a site, for telling a person who holds a repository.
	 *
	 * @param string $site_id The site.
	 * @return string The name, or an empty string if there is no such site.
	 */
	public static function site_name( string $site_id ): string {
		$site = '' === $site_id ? null : ClientSites::get( $site_id );

		return is_array( $site ) ? (string) $site['name'] : '';
	}

	/**
	 * The refusal for a repository another site holds.
	 *
	 * @param string $repo  The repository.
	 * @param string $other The site that holds it.
	 * @return WP_Error
	 */
	private static function taken( string $repo, string $other ): WP_Error {
		$name = self::site_name( $other );
		$name = '' === $name ? __( 'another site', 'blueworx-forge' ) : $name;

		return Errors::rest(
			'github_repo_taken',
			/* translators: 1: the repository, 2: the site it is linked to. */
			sprintf( __( '%1$s is already linked to %2$s. Unlink it there first.', 'blueworx-forge' ), $repo, $name ),
			409,
			array(
				'repo'      => $repo,
				'site_id'   => $other,
				'site_name' => $name,
			)
		);
	}
}
