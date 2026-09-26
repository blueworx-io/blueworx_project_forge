<?php
/**
 * How far one person's access reaches.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Tenancy;

/**
 * The tenant boundary as a rule (#92), with nobody in it.
 *
 * Given the memberships somebody holds and which clients are set to All staff,
 * this says which clients and which sites exist as far as they are concerned.
 * It asks no questions of the database and knows nothing about the current
 * request, which is what makes the boundary readable: the rule is here, and
 * Rest\Boundary is the part that knows who is asking and refuses them.
 *
 * ARCH-3 makes the **site** the unit, not the client. A membership naming no
 * site covers every site under its client, including ones added later; a
 * membership naming a site covers that site alone. Both are needed, and the
 * difference between them is the whole of the isolation this file exists for.
 *
 * **Nothing is the default.** Every method below adds reach; none takes it
 * away. A caller that fails to build a reach, or builds one from an empty set,
 * gets a boundary that hides everything rather than one that hides nothing.
 */
final class Reach {

	/**
	 * A reach that covers everything there is.
	 *
	 * Held by the studio's own WordPress administrator, and by nobody else. This is not a hole in the boundary: Forge runs on
	 * our site, and somebody able to install plugins on it can already read
	 * every table the boundary could hide.
	 *
	 * @return array<string, mixed>
	 */
	public static function everything(): array {
		return array(
			'everything' => true,
			'clients'    => array(),
			'sites'      => array(),
		);
	}

	/**
	 * A reach that covers nothing.
	 *
	 * @return array<string, mixed>
	 */
	public static function nothing(): array {
		return array(
			'everything' => false,
			'clients'    => array(),
			'sites'      => array(),
		);
	}

	/**
	 * What a set of memberships reaches.
	 *
	 * A client set to All staff (#405) is reached by every studio person,
	 * membership or not, including people added after it was set.
	 *
	 * @param array<int, array<string, mixed>> $memberships Membership rows, any status.
	 * @param array<int, string>               $all_staff   Clients set to All staff.
	 * @return array<string, mixed>
	 */
	public static function for_memberships( array $memberships, array $all_staff = array() ): array {
		$reach = self::nothing();

		foreach ( $memberships as $membership ) {
			$role = (string) ( $membership['role'] ?? '' );

			// A membership that has ended, or that names a role nobody defined,
			// is a row rather than access. Neither reaches anything.
			if ( 'active' !== (string) ( $membership['status'] ?? 'active' ) || ! Roles::exists( $role ) ) {
				continue;
			}

			$client = (string) ( $membership['client_id'] ?? '' );
			$site   = (string) ( $membership['client_site_id'] ?? '' );

			if ( '' === $client ) {
				continue;
			}

			if ( '' === $site ) {
				if ( ! in_array( $client, $reach['clients'], true ) ) {
					$reach['clients'][] = $client;
				}

				continue;
			}

			if ( ! isset( $reach['sites'][ $client ] ) ) {
				$reach['sites'][ $client ] = array();
			}

			if ( ! in_array( $site, $reach['sites'][ $client ], true ) ) {
				$reach['sites'][ $client ][] = $site;
			}
		}

		if ( array() !== $all_staff && self::is_studio_staff( $memberships ) ) {
			foreach ( $all_staff as $client ) {
				if ( ! in_array( (string) $client, $reach['clients'], true ) ) {
					$reach['clients'][] = (string) $client;
				}
			}
		}

		return $reach;
	}

	/**
	 * Whether somebody is one of our people rather than a client's (#405).
	 *
	 * Ours: they hold a studio-side membership, or have never held a client
	 * one — somebody new with no access yet counts, as in Users::ours(). A
	 * client's person whose access has ended does not, or ending it would
	 * open every All staff client to them.
	 *
	 * @param array<int, array<string, mixed>> $memberships Membership rows, any status.
	 * @return bool
	 */
	public static function is_studio_staff( array $memberships ): bool {
		$client_side = false;

		foreach ( $memberships as $membership ) {
			$role = (string) ( $membership['role'] ?? '' );

			if ( Roles::is_client_side( $role ) ) {
				$client_side = true;
				continue;
			}

			if ( Roles::exists( $role ) && 'active' === (string) ( $membership['status'] ?? 'active' ) ) {
				return true;
			}
		}

		return ! $client_side;
	}

	/**
	 * The role somebody works with on an All staff client they hold no
	 * membership on: Staff, or Internal viewer when that is all they ever are
	 * with us. Never more than Staff, and never a grant.
	 *
	 * @param array<int, array<string, mixed>> $memberships Membership rows, any status.
	 * @return string
	 */
	public static function all_staff_role( array $memberships ): string {
		$viewer = false;

		foreach ( $memberships as $membership ) {
			if ( 'active' !== (string) ( $membership['status'] ?? 'active' ) ) {
				continue;
			}

			$role = (string) ( $membership['role'] ?? '' );

			if ( Roles::INTERNAL_VIEWER === $role ) {
				$viewer = true;
			} elseif ( Roles::STAFF === $role || Roles::PRIMARY_ADMIN === $role ) {
				return Roles::STAFF;
			}
		}

		return $viewer ? Roles::INTERNAL_VIEWER : Roles::STAFF;
	}

	/**
	 * Whether this reach covers nothing at all.
	 *
	 * The listing routes have to ask, because an empty list is the wrong answer
	 * to it. Somebody who holds nothing and somebody whose clients happen to
	 * have no sites yet both come back with an empty array, and those mean
	 * completely different things: "not yours to see" and "nothing here yet"
	 * (#125). A screen cannot tell them apart from the list alone, so the API
	 * has to.
	 *
	 * @param array<string, mixed> $reach The reach.
	 * @return bool
	 */
	public static function is_nothing( array $reach ): bool {
		return empty( $reach['everything'] )
			&& array() === (array) ( $reach['clients'] ?? array() )
			&& array() === (array) ( $reach['sites'] ?? array() );
	}

	/**
	 * Whether a client is reached at all.
	 *
	 * A client reached through one of its sites counts, or the site somebody
	 * genuinely holds could not be listed under anything.
	 *
	 * @param array<string, mixed> $reach     The reach.
	 * @param string               $client_id Client id.
	 * @return bool
	 */
	public static function reaches_client( array $reach, string $client_id ): bool {
		if ( ! empty( $reach['everything'] ) ) {
			return true;
		}

		if ( in_array( $client_id, (array) ( $reach['clients'] ?? array() ), true ) ) {
			return true;
		}

		return array() !== (array) ( $reach['sites'][ $client_id ] ?? array() );
	}

	/**
	 * Whether one site is reached.
	 *
	 * The client is asked for as well as the site, and both have to match. A
	 * site id alone would answer a caller who had muddled two records, and the
	 * whole point of the boundary is that it never answers on a guess.
	 *
	 * @param array<string, mixed> $reach     The reach.
	 * @param string               $client_id The client the site sits under.
	 * @param string               $site_id   Site id.
	 * @return bool
	 */
	public static function reaches_site( array $reach, string $client_id, string $site_id ): bool {
		if ( ! empty( $reach['everything'] ) ) {
			return true;
		}

		// A client-wide membership covers every site under it, including ones
		// created after the membership was written.
		if ( in_array( $client_id, (array) ( $reach['clients'] ?? array() ), true ) ) {
			return true;
		}

		return in_array( $site_id, (array) ( $reach['sites'][ $client_id ] ?? array() ), true );
	}

	/**
	 * Keeps the rows whose site is reached.
	 *
	 * A list filters rather than refuses: somebody asking for the work they can
	 * see should get it, not a refusal because some of the set is somebody
	 * else's.
	 *
	 * The key holding the site id is named rather than guessed. A work item
	 * carries client_site_id and a site record carries its own id, and a filter
	 * that tried both would silently keep every row of whichever shape it did
	 * not understand.
	 *
	 * @param array<string, mixed>             $reach    The reach.
	 * @param array<int, array<string, mixed>> $rows     Rows carrying client_id.
	 * @param string                           $site_key Which key holds the site id.
	 * @return array<int, array<string, mixed>>
	 */
	public static function keep_sites( array $reach, array $rows, string $site_key = 'client_site_id' ): array {
		$kept = array();

		foreach ( $rows as $row ) {
			if ( self::reaches_site( $reach, (string) ( $row['client_id'] ?? '' ), (string) ( $row[ $site_key ] ?? '' ) ) ) {
				$kept[] = $row;
			}
		}

		return $kept;
	}

	/**
	 * Keeps the clients that are reached.
	 *
	 * @param array<string, mixed>             $reach The reach.
	 * @param array<int, array<string, mixed>> $rows  Client rows.
	 * @return array<int, array<string, mixed>>
	 */
	public static function keep_clients( array $reach, array $rows ): array {
		$kept = array();

		foreach ( $rows as $row ) {
			if ( self::reaches_client( $reach, (string) ( $row['id'] ?? '' ) ) ) {
				$kept[] = $row;
			}
		}

		return $kept;
	}
}
