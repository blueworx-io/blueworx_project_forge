<?php
/**
 * Which staff work on one client.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Tenancy;

/**
 * #405. A client is worked on by All staff, or by the staff chosen for it. The
 * chosen are the studio-side memberships on that client — the rows the People
 * page edits — so the client's Edit screen and People always agree.
 *
 * Choosing somebody gives them a Staff membership on the whole client, or
 * brings back the one that ended. Unchoosing ends every studio-side membership
 * they hold there. A client's own person is never made staff from here.
 */
final class ClientStaff {

	/**
	 * Who is on a client now: people with an active studio-side membership
	 * there, in the order their rows came. Pure.
	 *
	 * @param array<int, array<string, mixed>> $memberships The client's memberships, any status.
	 * @return array<int, string> Person ids.
	 */
	public static function chosen( array $memberships ): array {
		$chosen = array();

		foreach ( $memberships as $membership ) {
			if ( ! self::is_studio_row( $membership ) ) {
				continue;
			}

			$user_id = (string) $membership['user_id'];

			if ( ! in_array( $user_id, $chosen, true ) ) {
				$chosen[] = $user_id;
			}
		}

		return $chosen;
	}

	/**
	 * What to write for a new pick. Pure.
	 *
	 * @param array<int, string>               $picked      Person ids chosen.
	 * @param array<int, array<string, mixed>> $memberships The client's memberships, any status.
	 * @return array{add: array<int, string>, reactivate: array<string, int>, end: array<string, int>, refused: array<int, string>}
	 */
	public static function changes( array $picked, array $memberships ): array {
		$current = self::chosen( $memberships );
		$changes = array(
			'add'        => array(),
			'reactivate' => array(),
			'end'        => array(),
			'refused'    => array(),
		);

		foreach ( $picked as $user_id ) {
			$user_id = (string) $user_id;

			if ( in_array( $user_id, $current, true ) ) {
				continue;
			}

			$step = self::whole_client_step(
				array_values( array_filter( $memberships, static fn( array $row ): bool => (string) $row['user_id'] === $user_id ) )
			);

			if ( 'add' === $step['action'] ) {
				$changes['add'][] = $user_id;
			} elseif ( 'reactivate' === $step['action'] ) {
				$changes['reactivate'][ (string) $step['row']['id'] ] = (int) $step['row']['record_version'];
			} elseif ( 'refuse' === $step['action'] ) {
				$changes['refused'][] = $user_id;
			}
		}

		if ( array() !== $changes['refused'] ) {
			return array(
				'add'        => array(),
				'reactivate' => array(),
				'end'        => array(),
				'refused'    => $changes['refused'],
			);
		}

		foreach ( $memberships as $membership ) {
			if ( self::is_studio_row( $membership ) && ! in_array( (string) $membership['user_id'], array_map( 'strval', $picked ), true ) ) {
				$changes['end'][ (string) $membership['id'] ] = (int) $membership['record_version'];
			}
		}

		return $changes;
	}

	/**
	 * How to put one person on one client as Staff, from their rows there.
	 * Pure. Only the whole-client row matters, since there can be one of it,
	 * and it is picked by role rather than by the order the rows came in:
	 *
	 * - held: an active studio-side row is already there;
	 * - refuse: a client-side (or unknown) row is there, active or ended. It
	 *   is never brought back as Staff, and blocks a second row;
	 * - reactivate: an ended studio-side row comes back as Staff;
	 * - add: there is no row, so a new one is made.
	 *
	 * @param array<int, array<string, mixed>> $rows The person's rows on the client, any status.
	 * @return array{action: string, row: array<string, mixed>|null}
	 */
	public static function whole_client_step( array $rows ): array {
		$ended  = null;
		$action = 'add';

		foreach ( $rows as $row ) {
			if ( '' !== (string) $row['client_site_id'] ) {
				continue;
			}

			$role   = (string) $row['role'];
			$studio = Roles::exists( $role ) && ! Roles::is_client_side( $role );

			if ( ! $studio ) {
				$action = 'refuse';
			} elseif ( 'active' === (string) $row['status'] ) {
				return array(
					'action' => 'held',
					'row'    => $row,
				);
			} else {
				$ended = $row;
			}
		}

		if ( 'refuse' !== $action && null !== $ended ) {
			return array(
				'action' => 'reactivate',
				'row'    => $ended,
			);
		}

		return array(
			'action' => $action,
			'row'    => null,
		);
	}

	/**
	 * Our people who can be chosen: active, and staff by Reach's rule — an
	 * active studio-side membership somewhere. One read of the memberships.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function people(): array {
		$held = array();

		foreach ( Memberships::by_client( null ) as $rows ) {
			foreach ( $rows as $membership ) {
				$held[ (string) $membership['user_id'] ][] = $membership;
			}
		}

		return array_values(
			array_filter(
				Users::all( 'active' ),
				static fn( array $person ): bool => Reach::is_studio_staff( $held[ (string) $person['id'] ] ?? array() )
			)
		);
	}

	/**
	 * The role somebody holds on a client through All staff, or '' when they
	 * hold none that way. Only asked when they hold no membership there: a
	 * membership of their own always wins.
	 *
	 * @param string                                $user_id     Person id.
	 * @param string                                $client_id   Client id.
	 * @param array<int, array<string, mixed>>|null $memberships Their memberships, any status, when already read.
	 * @return string
	 */
	public static function role_through_all_staff( string $user_id, string $client_id, ?array $memberships = null ): string {
		if ( '' === $user_id || ! in_array( $client_id, Clients::all_staff_ids(), true ) ) {
			return '';
		}

		$memberships = $memberships ?? Memberships::for_user( $user_id, null );

		return Reach::is_studio_staff( $memberships ) ? Reach::all_staff_role( $memberships ) : '';
	}

	/**
	 * Whether a row is an active studio-side membership.
	 *
	 * @param array<string, mixed> $membership Membership row.
	 * @return bool
	 */
	private static function is_studio_row( array $membership ): bool {
		$role = (string) ( $membership['role'] ?? '' );

		return 'active' === (string) ( $membership['status'] ?? '' ) && Roles::exists( $role ) && ! Roles::is_client_side( $role );
	}
}
