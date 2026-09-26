<?php
/**
 * Whether one named person reaches a site.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Tenancy;

/**
 * The tenant boundary put to somebody other than whoever is asking.
 *
 * Rest\Boundary answers for the current user. Some writes name other people —
 * the people a reminder goes to, the seats on a task — and each of them has to
 * reach the site the record sits on, or the record names somebody who could
 * never open it (#393). The reminders asked this first; this is where it lives
 * now, so the two cannot drift apart.
 *
 * A seat asks it "as staff": only our side of a membership counts, because a
 * client's own people reach their site but do not do the work.
 */
final class PersonReach {

	/**
	 * Every field on a work item that names a person to do part of it.
	 */
	public const SEATS = array(
		'primary_user_id',
		'reviewer_id',
		'deliverer_id',
		'reviewer_substitute_id',
		'deliverer_substitute_id',
	);

	/**
	 * Whether a person, holding these memberships, reaches a site. Pure.
	 *
	 * @param array<string, mixed>             $person        The person.
	 * @param array<int, array<string, mixed>> $memberships   Their active memberships.
	 * @param bool                             $administrator Whether they are the studio's WordPress administrator.
	 * @param string                           $client_id     The client the site sits under.
	 * @param string                           $site_id       The site.
	 * @param bool                             $as_staff      Count only our side of a membership.
	 * @return bool
	 */
	public static function reaches( array $person, array $memberships, bool $administrator, string $client_id, string $site_id, bool $as_staff = false ): bool {
		if ( 'active' !== (string) ( $person['status'] ?? '' ) ) {
			return false;
		}

		// The studio's own administrator reaches everything, as in
		// Boundary::for_user(), which only answers for the current user.
		if ( $administrator ) {
			return true;
		}

		if ( $as_staff ) {
			$memberships = array_values(
				array_filter(
					$memberships,
					static fn( array $membership ): bool => ! Roles::is_client_side( (string) ( $membership['role'] ?? '' ) )
				)
			);
		}

		$reach = Reach::for_memberships( $memberships, (string) ( $person['grants'] ?? '' ) );

		return Reach::reaches_site( $reach, $client_id, $site_id );
	}

	/**
	 * Whether a person is the studio's WordPress administrator.
	 *
	 * @param array<string, mixed> $person The person.
	 * @return bool
	 */
	public static function is_administrator( array $person ): bool {
		$wp_user = (int) ( $person['wp_user_id'] ?? 0 );

		return $wp_user > 0 && user_can( $wp_user, 'manage_options' );
	}

	/**
	 * Whether the person with this id reaches a site.
	 *
	 * @param string $person_id Person id.
	 * @param string $client_id The client the site sits under.
	 * @param string $site_id   The site.
	 * @param bool   $as_staff  Count only our side of a membership.
	 * @return bool
	 */
	public static function person_reaches_site( string $person_id, string $client_id, string $site_id, bool $as_staff = false ): bool {
		$person = Users::get( $person_id );

		if ( null === $person ) {
			return false;
		}

		return self::reaches(
			$person,
			Memberships::for_user( (string) $person['id'] ),
			self::is_administrator( $person ),
			$client_id,
			$site_id,
			$as_staff
		);
	}

	/**
	 * The seats in these values that name somebody who cannot do work on this
	 * site, each with the sentence to show. Only the seats present are asked
	 * about, so an edit that does not send a seat is never refused over it.
	 *
	 * @param array<string, mixed> $values    Validated values.
	 * @param string               $client_id The client.
	 * @param string               $site_id   The site.
	 * @return array<string, string> Field to message; empty when all reach.
	 */
	public static function seat_refusals( array $values, string $client_id, string $site_id ): array {
		$errors = array();

		foreach ( self::SEATS as $field ) {
			$id = (string) ( $values[ $field ] ?? '' );

			if ( '' === $id || self::is_client( $id ) || self::person_reaches_site( $id, $client_id, $site_id, true ) ) {
				continue;
			}

			$errors[ $field ] = self::message( Users::get( $id ) );
		}

		return $errors;
	}

	/**
	 * Whether a seat holds the client rather than a person (#391). The client
	 * reaches their own work by definition, so it is never asked about.
	 *
	 * @param string $id Seat value.
	 * @return bool
	 */
	private static function is_client( string $id ): bool {
		return \Blueworx\Forge\Work\ClientReviewer::ID === $id;
	}

	/**
	 * Only the seats an edit changes. Pure.
	 *
	 * An edit that resends a seat as it already stands is not putting anybody
	 * anywhere, so it is not asked about: somebody who has since lost access
	 * is flagged in the picker, and does not block a save of something else.
	 *
	 * @param array<string, mixed> $values Validated values from the edit.
	 * @param array<string, mixed> $item   The item as stored.
	 * @return array<string, mixed> The seats in $values whose value differs.
	 */
	public static function changed_seats( array $values, array $item ): array {
		$changed = array();

		foreach ( self::SEATS as $field ) {
			$stored = (string) ( $item[ $field ] ?? '' );

			if ( array_key_exists( $field, $values ) && $stored !== (string) $values[ $field ] ) {
				$changed[ $field ] = $values[ $field ];
			}
		}

		return $changed;
	}

	/**
	 * The same values with every seat that does not reach the site emptied.
	 *
	 * For the paths nobody is standing at when they run, such as a recurring
	 * task being made overnight: an empty seat is visible and gets filled,
	 * where somebody without access would be assigned work they cannot open.
	 *
	 * @param array<string, mixed> $values    Values naming seats.
	 * @param string               $client_id The client.
	 * @param string               $site_id   The site.
	 * @param callable|null        $reaches   Whether a person id reaches the
	 *                                        site as staff; the real check when
	 *                                        null, a stand-in in a unit test.
	 * @return array<string, mixed>
	 */
	public static function drop_unreached( array $values, string $client_id, string $site_id, ?callable $reaches = null ): array {
		$reaches = $reaches ?? static fn( string $id ): bool => self::person_reaches_site( $id, $client_id, $site_id, true );

		foreach ( self::SEATS as $field ) {
			$id = (string) ( $values[ $field ] ?? '' );

			if ( '' !== $id && ! self::is_client( $id ) && ! $reaches( $id ) ) {
				$values[ $field ] = '';
			}
		}

		return $values;
	}

	/**
	 * Our people who can do work on a site, for the seat pickers.
	 *
	 * One read of the memberships for everybody rather than one per person.
	 *
	 * @param string $client_id The client.
	 * @param string $site_id   The site.
	 * @return array<int, array<string, mixed>>
	 */
	public static function staff_on_site( string $client_id, string $site_id ): array {
		$by_client = Memberships::by_client( 'active' );
		$held      = array();

		foreach ( $by_client as $rows ) {
			foreach ( $rows as $membership ) {
				$held[ (string) $membership['user_id'] ][] = $membership;
			}
		}

		return array_values(
			array_filter(
				Users::ours( $by_client ),
				static fn( array $person ): bool => self::reaches(
					$person,
					$held[ (string) $person['id'] ] ?? array(),
					self::is_administrator( $person ),
					$client_id,
					$site_id,
					true
				)
			)
		);
	}

	/**
	 * The sentence a refused seat is given. Pure.
	 *
	 * @param array<string, mixed>|null $person The person, or null when there is none.
	 * @return string
	 */
	public static function message( ?array $person ): string {
		$name = trim( (string) ( $person['display_name'] ?? '' ) );

		return sprintf(
			/* translators: %s: the name of the person put in a seat. */
			__( '%s doesn\'t have access to this client.', 'blueworx-forge' ),
			'' === $name ? __( 'That person', 'blueworx-forge' ) : $name
		);
	}
}
