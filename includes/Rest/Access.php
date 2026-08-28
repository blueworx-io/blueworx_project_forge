<?php
/**
 * The facts a permission decision is made from.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Sites\SecurityLog;
use Blueworx\Forge\Tenancy\Capabilities;
use Blueworx\Forge\Tenancy\Grants;
use Blueworx\Forge\Tenancy\Memberships;
use Blueworx\Forge\Tenancy\Roles;
use Blueworx\Forge\Tenancy\Users;
use WP_Error;

/**
 * #91's other half: Tenancy\Capabilities decides, and this gathers what it
 * decides from.
 *
 * The split is the point. The rules are a table with no database behind them,
 * so they can be read and tested as rules; this class knows about memberships,
 * WordPress users and the item in front of it, and knows nothing about what any
 * of it means. When the two are one class, the answer to "who may release this"
 * can only be found by running it.
 *
 * **Every refusal is logged.** The permission matrix requires it — user, item,
 * source interface and time — and it is done here rather than in each route,
 * because a route that has to remember to log its own refusals is a route that
 * one day does not.
 */
final class Access {

	/**
	 * The capability context for the current user against one client's records.
	 *
	 * @param string                    $client_id The client whose records these are.
	 * @param array<string, mixed>|null $item      The item being acted on, where
	 *                                             there is one.
	 * @return array<string, mixed>
	 */
	public static function context( string $client_id, ?array $item = null ): array {
		$wp_user = get_current_user_id();

		/*
		 * The studio's own WordPress administrator is the Primary administrator
		 * of this installation. That is not a shortcut round the matrix: Forge
		 * runs on our site, and somebody who can install plugins on it can
		 * already do anything the matrix could stop them doing.
		 */
		$user    = $wp_user > 0 ? Users::by_wp_user( $wp_user ) : null;
		$user_id = null === $user ? '' : (string) $user['id'];

		if ( current_user_can( 'manage_options' ) ) {
			/*
			 * Their Forge id still matters. Being the Primary administrator is
			 * not the same as being the item's Reviewer (#112) — rank does not
			 * substitute for assignment — so the seats are resolved for them
			 * exactly as for anybody else, and the way past an assignment they
			 * do not hold is the override, which is marked on the item.
			 */
			return self::build( Roles::PRIMARY_ADMIN, $user_id, $item, true );
		}

		if ( null === $user ) {
			return self::build( '', '', $item, false );
		}

		$membership = self::membership_for( $user_id, $client_id, $item );

		if ( null === $membership ) {
			return self::build( '', $user_id, $item, false );
		}

		return self::build(
			(string) $membership['role'],
			$user_id,
			$item,
			self::reaches( $membership, $item ),
			$membership
		);
	}

	/**
	 * Decides, and refuses in the shape a route answers with.
	 *
	 * @param string                    $capability Capability being exercised.
	 * @param string                    $client_id  The client whose records these are.
	 * @param array<string, mixed>|null $item       The item, where there is one.
	 * @param array<string, mixed>      $extra      Anything the caller knows that
	 *                                              the context cannot work out.
	 * @return WP_Error|null Null when it is allowed.
	 */
	public static function refuse_unless( string $capability, string $client_id, ?array $item = null, array $extra = array() ) {
		$context  = array_merge( self::context( $client_id, $item ), $extra );
		$decision = Capabilities::decide( $capability, $context );

		if ( $decision['allowed'] ) {
			return null;
		}

		self::log_refusal( $capability, $decision, $item );

		/*
		 * 403 rather than 404. Hiding the existence of a record is the tenant
		 * boundary's job (D-1, D-2), and it does that by scoping the read
		 * before this is ever asked — by the time a capability is being
		 * checked, the caller is already entitled to know the thing is there.
		 */
		return Errors::rest(
			'not_permitted',
			$decision['reason'],
			403,
			array(
				'capability' => $capability,
				'denied_by'  => $decision['code'],
			)
		);
	}

	/**
	 * Whether the current user holds a capability. For a screen deciding what
	 * to draw, where a refusal is not being made yet.
	 *
	 * @param string                    $capability Capability.
	 * @param string                    $client_id  Client id.
	 * @param array<string, mixed>|null $item       The item, where there is one.
	 * @return bool
	 */
	public static function allows( string $capability, string $client_id, ?array $item = null ): bool {
		return Capabilities::allows( $capability, self::context( $client_id, $item ) );
	}

	/**
	 * Whether the current user holds a capability anywhere at all.
	 *
	 * For the one read whose subject is not a client's records (#138). Capacity
	 * spans every client by definition, so asking "do they hold this for client
	 * X" has no X to name — and asking it of no client at all would refuse
	 * every member of staff who is not a WordPress administrator.
	 *
	 * Holding it under any active membership is enough. Somebody who may see
	 * staff capacity for one client is not being shown anything new by seeing
	 * it for all of them: the answer is about the studio's own people and their
	 * own hours, not about anybody's work.
	 *
	 * @param string $capability Capability.
	 * @return bool
	 */
	public static function allows_anywhere( string $capability ): bool {
		$wp_user = get_current_user_id();

		if ( current_user_can( 'manage_options' ) ) {
			// The studio's own WordPress administrator is the Primary
			// administrator here, exactly as in context().
			return true;
		}

		$user = $wp_user > 0 ? Users::by_wp_user( $wp_user ) : null;

		if ( null === $user ) {
			return false;
		}

		$user_id = (string) $user['id'];

		foreach ( Memberships::for_user( $user_id ) as $membership ) {
			$context = self::build( (string) $membership['role'], $user_id, null, true, $membership );

			if ( Capabilities::allows( $capability, $context ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Records a refusal: who, what, where from, and when.
	 *
	 * @param string                    $capability What was attempted.
	 * @param array<string, mixed>      $decision   The decision that refused it.
	 * @param array<string, mixed>|null $item       The item, where there is one.
	 */
	private static function log_refusal( string $capability, array $decision, ?array $item ): void {
		SecurityLog::refused(
			null === $item ? '' : (string) $item['client_site_id'],
			(string) $decision['code'],
			array(
				'capability' => $capability,
				'item_id'    => null === $item ? '' : (string) $item['id'],
				'user'       => get_current_user_id(),
				'interface'  => Capabilities::STUDIO,
			)
		);
	}

	/**
	 * The membership that applies: the one for this site if there is one, and
	 * otherwise the client-wide one.
	 *
	 * A site membership is not "more specific and therefore better" — it is the
	 * only one that applies to that site. A client-wide membership is the one
	 * with no site named on it, and it applies everywhere under that client.
	 *
	 * @param string                    $user_id   Forge user id.
	 * @param string                    $client_id Client id.
	 * @param array<string, mixed>|null $item      The item, where there is one.
	 * @return array<string, mixed>|null
	 */
	private static function membership_for( string $user_id, string $client_id, ?array $item ) {
		$site_id  = null === $item ? '' : (string) $item['client_site_id'];
		$wide     = null;
		$for_site = null;

		foreach ( Memberships::for_user( $user_id ) as $membership ) {
			if ( (string) $membership['client_id'] !== $client_id ) {
				continue;
			}

			if ( '' === (string) $membership['client_site_id'] ) {
				$wide = $membership;
				continue;
			}

			if ( '' !== $site_id && (string) $membership['client_site_id'] === $site_id ) {
				$for_site = $membership;
			}
		}

		return $for_site ?? $wide;
	}

	/**
	 * Whether a membership reaches the site the item is on (ARCH-3, AUTH-6).
	 *
	 * @param array<string, mixed>      $membership The membership.
	 * @param array<string, mixed>|null $item       The item, where there is one.
	 * @return bool
	 */
	private static function reaches( array $membership, ?array $item ): bool {
		if ( null === $item ) {
			return true;
		}

		$granted = (string) $membership['client_site_id'];

		// An empty site on the membership means every site under the client.
		return '' === $granted || $granted === (string) $item['client_site_id'];
	}

	/**
	 * Assembles the context.
	 *
	 * @param string                    $role       Role held, or '' for none.
	 * @param string                    $user_id    Forge user id, or ''.
	 * @param array<string, mixed>|null $item       The item, where there is one.
	 * @param bool                      $own_site   Whether the membership reaches it.
	 * @param array<string, mixed>      $membership The membership, where there is one.
	 * @return array<string, mixed>
	 */
	private static function build( string $role, string $user_id, ?array $item, bool $own_site, array $membership = array() ): array {
		$grants = (string) ( $membership['grants'] ?? '' );

		return array(
			'role'                  => $role,
			'user_id'               => $user_id,
			'interface'             => Capabilities::STUDIO,
			'own_site'              => $own_site,

			/*
			 * The AUTH-3 Principal grant and the AUTH-1 Approver capabilities
			 * are held on the membership rather than being roles of their own,
			 * and are handed out on the people screen (#93). Absent is the
			 * default and the safe direction: nobody self-approves by accident.
			 */
			'principal'             => Grants::held( $grants, Grants::PRINCIPAL ),
			'holds_approver'        => Grants::held( $grants, Grants::APPROVER ),

			// #112. Who the item names, as against who is asking.
			'assigned_primary_user' => self::is_assigned( $item, 'primary_user_id', $user_id ),
			'assigned_reviewer'     => self::is_assigned( $item, 'reviewer_id', $user_id )
				|| self::is_assigned( $item, 'reviewer_substitute_id', $user_id ),
			'assigned_deliverer'    => self::is_assigned( $item, 'deliverer_id', $user_id )
				|| self::is_assigned( $item, 'deliverer_substitute_id', $user_id ),
			'acting_as_substitute'  => self::is_assigned( $item, 'reviewer_substitute_id', $user_id )
				|| self::is_assigned( $item, 'deliverer_substitute_id', $user_id ),
		);
	}

	/**
	 * Whether an item names this user in a particular seat.
	 *
	 * @param array<string, mixed>|null $item    The item, where there is one.
	 * @param string                    $field   Which seat.
	 * @param string                    $user_id Forge user id.
	 * @return bool
	 */
	private static function is_assigned( ?array $item, string $field, string $user_id ): bool {
		if ( null === $item || '' === $user_id ) {
			return false;
		}

		$assigned = (string) ( $item[ $field ] ?? '' );

		return '' !== $assigned && $assigned === $user_id;
	}
}
