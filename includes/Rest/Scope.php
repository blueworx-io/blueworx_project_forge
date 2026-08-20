<?php
/**
 * Whose eyes a request is being answered for.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Tenancy\Memberships;
use Blueworx\Forge\Tenancy\Roles;
use Blueworx\Forge\Tenancy\Users;
use Blueworx\Forge\Work\Comments;

/**
 * One question, asked in one place: is the person making this request one of
 * ours, or one of the client's?
 *
 * #100 turns on that answer and nothing else. The full capability matrix
 * arrives with #91; this is the single distinction the comment rules need, and
 * it is here rather than inlined in a controller so that when #91 lands it
 * replaces one implementation rather than three.
 *
 * **The default is client.** Somebody we cannot place is not given the internal
 * view: the failure mode of guessing wrong in the other direction is showing a
 * client what we said about them.
 */
final class Scope {

	/**
	 * Nobody we recognise at all.
	 */
	public const NONE = '';

	/**
	 * What scope the current user reads a client's records in.
	 *
	 * @param string $client_id The client whose records are being read.
	 * @return string One of Comments::SCOPE_STAFF, Comments::SCOPE_CLIENT, NONE.
	 */
	public static function current( string $client_id ): string {
		return self::for_user( get_current_user_id(), $client_id );
	}

	/**
	 * What scope a given WordPress user reads a client's records in.
	 *
	 * @param int    $wp_user_id WordPress user id.
	 * @param string $client_id  The client whose records are being read.
	 * @return string
	 */
	public static function for_user( int $wp_user_id, string $client_id ): string {
		if ( $wp_user_id <= 0 ) {
			return self::NONE;
		}

		// The studio's own administrator. There is one studio and the person
		// running it sees everything in it.
		if ( current_user_can( 'manage_options' ) ) {
			return Comments::SCOPE_STAFF;
		}

		$user = Users::by_wp_user( $wp_user_id );

		if ( null === $user ) {
			return self::NONE;
		}

		return self::from_roles( self::roles_for( (string) $user['id'], $client_id ) );
	}

	/**
	 * The roles a person holds with one client.
	 *
	 * @param string $user_id   Forge user id.
	 * @param string $client_id Client id.
	 * @return array<int, string>
	 */
	public static function roles_for( string $user_id, string $client_id ): array {
		$roles = array();

		foreach ( Memberships::for_user( $user_id ) as $membership ) {
			if ( (string) $membership['client_id'] === $client_id ) {
				$roles[] = (string) $membership['role'];
			}
		}

		return $roles;
	}

	/**
	 * The scope a set of roles adds up to.
	 *
	 * Held roles are not additive in the direction you might expect: somebody
	 * who is staff on one client and a client viewer on another reads each
	 * client in that client's scope. Within one client, any staff-side role
	 * wins, because a person cannot hold two roles with one client anyway — the
	 * memberships table's unique index sees to that.
	 *
	 * @param array<int, string> $roles Roles held with one client.
	 * @return string
	 */
	public static function from_roles( array $roles ): string {
		// An unrecognised role is not a role. It is a row somebody wrote by
		// hand, and it grants nothing — including the client view.
		$known = array_values( array_filter( $roles, array( Roles::class, 'exists' ) ) );

		if ( array() === $known ) {
			return self::NONE;
		}

		foreach ( $known as $role ) {
			if ( ! Roles::is_client_side( $role ) ) {
				return Comments::SCOPE_STAFF;
			}
		}

		return Comments::SCOPE_CLIENT;
	}
}
