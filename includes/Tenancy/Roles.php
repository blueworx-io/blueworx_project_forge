<?php
/**
 * The access roles a membership can hold.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Tenancy;

/**
 * The five roles, taken from the columns of
 * docs/architecture/permission-matrix.md.
 *
 * This issue (#90) stores a role; it does not enforce anything. #91 implements
 * the matrix itself, and every capability question it answers starts from this
 * list — which is why an unknown role is refused at the door rather than stored
 * and interpreted later.
 *
 * Viewer is two roles rather than one because AUTH-5 makes it two: an internal
 * viewer sees internal notes and a client viewer never does. Collapsing them
 * would leave #91 working out which kind of viewer it had from somewhere else,
 * and getting that wrong shows one client's internal notes to the client.
 *
 * The AUTH-3 Principal grant is not here. It is an additional capability held by
 * a staff user rather than a kind of account, and it means nothing until
 * capabilities exist.
 */
final class Roles {

	/**
	 * Primary administrator: cross-client access and all administration.
	 */
	public const PRIMARY_ADMIN = 'primary_admin';

	/**
	 * Our own people.
	 */
	public const STAFF = 'staff';

	/**
	 * The client's administrator.
	 */
	public const CLIENT_ADMIN = 'client_admin';

	/**
	 * A stakeholder on the client's side.
	 */
	public const CLIENT_VIEWER = 'client_viewer';

	/**
	 * A viewer on ours.
	 */
	public const INTERNAL_VIEWER = 'internal_viewer';

	/**
	 * Every role, in the order the matrix lists them.
	 */
	public const ALL = array(
		self::PRIMARY_ADMIN,
		self::STAFF,
		self::CLIENT_ADMIN,
		self::CLIENT_VIEWER,
		self::INTERNAL_VIEWER,
	);

	/**
	 * The roles held by the client's own people.
	 */
	public const CLIENT_SIDE = array(
		self::CLIENT_ADMIN,
		self::CLIENT_VIEWER,
	);

	/**
	 * Whether a role is one of the client's own people.
	 *
	 * @param string $role Role.
	 * @return bool
	 */
	public static function is_client_side( string $role ): bool {
		return in_array( $role, self::CLIENT_SIDE, true );
	}

	/**
	 * Whether a string is a role at all.
	 *
	 * @param string $role Role.
	 * @return bool
	 */
	public static function exists( string $role ): bool {
		return in_array( $role, self::ALL, true );
	}

	/**
	 * How a role reads to a human.
	 *
	 * @param string $role Role.
	 * @return string
	 */
	public static function label( string $role ): string {
		switch ( $role ) {
			case self::PRIMARY_ADMIN:
				return __( 'Primary administrator', 'blueworx-forge' );
			case self::STAFF:
				return __( 'Staff', 'blueworx-forge' );
			case self::CLIENT_ADMIN:
				return __( 'Client administrator', 'blueworx-forge' );
			case self::CLIENT_VIEWER:
				return __( 'Client viewer', 'blueworx-forge' );
			case self::INTERNAL_VIEWER:
				return __( 'Internal viewer', 'blueworx-forge' );
			default:
				return __( 'Unknown role', 'blueworx-forge' );
		}
	}
}
