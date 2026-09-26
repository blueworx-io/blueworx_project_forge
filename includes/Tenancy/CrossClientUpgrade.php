<?php
/**
 * The one-off step that retires the cross-client grant.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Tenancy;

use Blueworx\Forge\Data\Schema;

/**
 * #405. Which clients somebody works on is now set on each client, so the
 * grant that reached every client goes. Nobody loses access: everyone holding
 * it is first given a staff membership on every active client they are not
 * already on, which People can then take away one client at a time.
 *
 * Run once, from the schema step that adds All staff. Running it again finds
 * nobody holding the grant, so changes nothing.
 */
final class CrossClientUpgrade {

	/**
	 * The grant as it was stored. Not a Grants constant any more: a stored one
	 * reads as nothing, and only this step still looks for it.
	 */
	private const GRANT = 'cross_client';

	/**
	 * Moves this site's holders onto memberships and clears the grant.
	 */
	public static function run(): void {
		global $wpdb;

		$users = Schema::users_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; the name cannot be a placeholder.
		$holders = $wpdb->get_results( $wpdb->prepare( "SELECT id, grants, status FROM {$users} WHERE grants LIKE %s", '%' . self::GRANT . '%' ), ARRAY_A );

		if ( ! is_array( $holders ) || array() === $holders ) {
			return;
		}

		$memberships = array();

		foreach ( $holders as $holder ) {
			$memberships = array_merge( $memberships, Memberships::for_user( (string) $holder['id'], null ) );
		}

		$plan = self::plan( $holders, $memberships, array_column( Clients::all( 'active' ), 'id' ) );

		foreach ( $plan['create'] as $one ) {
			Memberships::create(
				$one['user_id'],
				$one['client_id'],
				array(
					'role'           => Roles::STAFF,
					'client_site_id' => '',
				),
				0
			);
		}

		foreach ( $plan['reactivate'] as $id => $version ) {
			Memberships::update(
				(string) $id,
				array(
					'status' => 'active',
					'role'   => Roles::STAFF,
					'grants' => array(),
				),
				(int) $version
			);
		}

		foreach ( $holders as $holder ) {
			if ( ! in_array( (string) $holder['id'], $plan['clear'], true ) ) {
				continue;
			}

			// The version moves, so a People form open on the old row is
			// refused rather than writing the grant back.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; the name cannot be a placeholder.
			$wpdb->query( $wpdb->prepare( "UPDATE {$users} SET grants = %s, record_version = record_version + 1 WHERE id = %s", self::without_grant( (string) $holder['grants'] ), (string) $holder['id'] ) );
		}
	}

	/**
	 * What to write. Pure.
	 *
	 * Only a studio person is given anything: the grant meant nothing to a
	 * client's own person, and nobody offboarded is given access. Every holder
	 * loses the grant. A whole-client row that has ended is brought back
	 * rather than duplicated; one that is active is left as it is.
	 *
	 * @param array<int, array<string, mixed>> $users       People: id, grants, status.
	 * @param array<int, array<string, mixed>> $memberships Their memberships, any status.
	 * @param array<int, string>               $client_ids  Every active client.
	 * @return array{create: array<int, array{user_id: string, client_id: string}>, reactivate: array<string, int>, clear: array<int, string>}
	 */
	public static function plan( array $users, array $memberships, array $client_ids ): array {
		$plan = array(
			'create'     => array(),
			'reactivate' => array(),
			'clear'      => array(),
		);

		foreach ( $users as $user ) {
			$user_id = (string) $user['id'];

			if ( ! self::holds( (string) ( $user['grants'] ?? '' ) ) ) {
				continue;
			}

			$plan['clear'][] = $user_id;

			$held = array_values( array_filter( $memberships, static fn( array $row ): bool => (string) $row['user_id'] === $user_id ) );

			if ( 'active' !== (string) ( $user['status'] ?? '' ) || ! Reach::is_studio_staff( $held ) ) {
				continue;
			}

			foreach ( $client_ids as $client_id ) {
				$whole = null;

				foreach ( $held as $row ) {
					if ( (string) $row['client_id'] === (string) $client_id && '' === (string) $row['client_site_id'] ) {
						$whole = $row;
					}
				}

				if ( null === $whole ) {
					$plan['create'][] = array(
						'user_id'   => $user_id,
						'client_id' => (string) $client_id,
					);
				} elseif ( 'active' !== (string) $whole['status'] ) {
					$plan['reactivate'][ (string) $whole['id'] ] = (int) $whole['record_version'];
				}
			}
		}

		return $plan;
	}

	/**
	 * A grants column with the retired grant taken out. Pure.
	 *
	 * @param string $stored The column as stored.
	 * @return string
	 */
	public static function without_grant( string $stored ): string {
		return Grants::format( array_map( 'trim', explode( ',', $stored ) ) );
	}

	/**
	 * Whether a grants column holds the retired grant.
	 *
	 * @param string $stored The column as stored.
	 * @return bool
	 */
	private static function holds( string $stored ): bool {
		return in_array( self::GRANT, array_map( 'trim', explode( ',', $stored ) ), true );
	}
}
