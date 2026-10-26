<?php
/**
 * Whether a synced meeting is only studio staff.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Google;

use Blueworx\Forge\Tenancy\Clients;
use Blueworx\Forge\Tenancy\ClientStaff;
use Blueworx\Forge\Tenancy\Studio;

/**
 * #657. A meeting where everyone invited is studio staff is studio time, and
 * fills itself in as the studio. A guest counts as staff when their address
 * is a staff member's, a connected calendar's, or on the studio client's own
 * email domains. A meeting with no other guests is internal too: studio time
 * never spends a client's hours, so a wrong guess costs nothing.
 */
final class Internal {

	/**
	 * Staff addresses, read once per request.
	 *
	 * @var array<int, string>|null
	 */
	private static ?array $staff = null;

	/**
	 * Whether everyone invited is staff. Pure.
	 *
	 * @param array<int, array<string, mixed>> $attendees    As {@see Events::normalise()} gives them, the calendar's owner left out.
	 * @param array<int, string>               $staff_emails Lower-cased.
	 * @param array<int, string>               $domains      Lower-cased.
	 * @return bool
	 */
	public static function is_internal( array $attendees, array $staff_emails, array $domains ): bool {
		$staff_emails = array_flip( $staff_emails );
		$domains      = array_flip( $domains );

		foreach ( $attendees as $attendee ) {
			$email = strtolower( trim( (string) ( $attendee['email'] ?? '' ) ) );
			$at    = strrpos( $email, '@' );

			if ( isset( $staff_emails[ $email ] ) || ( false !== $at && isset( $domains[ substr( $email, $at + 1 ) ] ) ) ) {
				continue;
			}

			return false;
		}

		return true;
	}

	/**
	 * Every staff member's address, and every connected calendar's.
	 *
	 * @return array<int, string>
	 */
	public static function staff_emails(): array {
		if ( null === self::$staff ) {
			$emails = array_merge(
				array_column( ClientStaff::people(), 'email' ),
				array_column( Connections::all(), 'email' )
			);

			self::$staff = array_values( array_unique( array_filter( array_map( static fn( $email ): string => strtolower( trim( (string) $email ) ), $emails ) ) ) );
		}

		return self::$staff;
	}

	/**
	 * The studio client's email domains.
	 *
	 * @return array<int, string>
	 */
	public static function domains(): array {
		$studio = '' === Studio::client_id() ? null : Clients::get( Studio::client_id() );

		return null === $studio ? array() : array_values( array_filter( array_map( static fn( $domain ): string => strtolower( trim( (string) $domain ) ), (array) $studio['email_domains'] ) ) );
	}
}
