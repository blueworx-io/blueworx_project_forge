<?php
/**
 * Raising and writing a client's daily and weekly email.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Notifications;

use Blueworx\Forge\Commerce\Assignments;
use Blueworx\Forge\Commerce\Ledger;
use Blueworx\Forge\Onboarding\Steps;
use Blueworx\Forge\Tenancy\Clients;
use Blueworx\Forge\Tenancy\ClientSites;
use Blueworx\Forge\Work\Comments;
use Blueworx\Forge\Work\Events as WorkEvents;
use Blueworx\Forge\Work\Items;
use Blueworx\Forge\Work\Stages;
use DateTimeImmutable;
use DateTimeZone;

/**
 * #673. The half of the daily and weekly emails that reads records.
 *
 * **There is no clock of our own.** A client site already asks the studio
 * what it should send, every hour and whenever somebody is using Forge there
 * (NOTIF-3). That question is when these are raised: each time a site asks,
 * the studio works out whether today's or this week's email is due for it and
 * has not gone. A studio-side cron would be a second schedule to keep alive on
 * a WordPress that only runs cron when somebody visits, for an email that
 * cannot leave until the site asks anyway.
 *
 * **It goes to the client's email, and only there.** The address the studio
 * keeps on the client record, not the client's people: this is the studio
 * writing to its contact, and who that is, is the studio's to say. No address,
 * no email — nothing is raised at all, so nothing sits waiting to be sent to
 * nobody.
 *
 * The email is written when it is collected rather than when it is raised, as
 * every other one is, so it says what is true at the moment it goes.
 */
final class DigestRun {

	/**
	 * Raises whichever of the two is due for a site and has something in it.
	 *
	 * Safe to call on every ask: an email already raised is found by its id
	 * before anything is read, and the claim itself is what makes it one.
	 *
	 * @param string $client_id      The client.
	 * @param string $client_site_id The site asking.
	 */
	public static function raise_for_site( string $client_id, string $client_site_id ): void {
		$client = Clients::get( $client_id );

		if ( '' === self::address( $client ) ) {
			return;
		}

		foreach ( Digests::due( self::now_for( $client ) ) as $kind => $occurrence ) {
			if ( null !== Register::get( Events::id_for( $kind, $client_site_id, $occurrence ) ) ) {
				continue;
			}

			if ( Digests::is_empty( self::build( $kind, $client_site_id, Digests::day_of( $occurrence ), $client ) ) ) {
				continue;
			}

			Register::claim(
				array(
					'kind'           => $kind,
					'subject_id'     => $client_site_id,
					'occurrence'     => $occurrence,
					'client_id'      => $client_id,
					'client_site_id' => $client_site_id,
				)
			);
		}
	}

	/**
	 * A raised daily or weekly as a finished email, or an empty array where
	 * there is none to send.
	 *
	 * None to send is three cases: the client has no address any more, the
	 * day or week it was raised for has passed while it waited, or what it
	 * would have said is no longer true.
	 *
	 * @param array<string, mixed> $event The raised event.
	 * @return array<string, mixed>
	 */
	public static function envelope( array $event ): array {
		$kind   = (string) $event['event_kind'];
		$site   = ClientSites::get( (string) $event['client_site_id'] );
		$client = Clients::get( (string) $event['client_id'] );
		$to     = self::address( $client );

		if ( null === $site || '' === $to ) {
			return array();
		}

		// Yesterday's "being worked on today" is not worth sending today.
		if ( ( Digests::due( self::now_for( $client ) )[ $kind ] ?? 0 ) !== (int) $event['occurrence'] ) {
			return array();
		}

		$day     = Digests::day_of( (int) $event['occurrence'] );
		$written = Templates::render(
			$kind,
			array(
				'day'      => $day,
				'site_url' => (string) ( $site['url'] ?? '' ),
				'digest'   => self::build( $kind, (string) $site['id'], $day, $client ),
			)
		);

		if ( '' === $written['subject'] ) {
			return array();
		}

		return array(
			'event_id' => (string) $event['id'],
			'kind'     => $kind,
			'to'       => array( $to ),
			'subject'  => $written['subject'],
			'body'     => $written['body'],
		);
	}

	/**
	 * What one of the two would say for a site.
	 *
	 * @param string                    $kind           Events::DAILY or Events::WEEKLY.
	 * @param string                    $client_site_id The site.
	 * @param string                    $day            The day, or the week's Monday.
	 * @param array<string, mixed>|null $client         The client record.
	 * @return array<string, mixed>
	 */
	private static function build( string $kind, string $client_site_id, string $day, ?array $client ): array {
		if ( '' === $day ) {
			return array();
		}

		$items = Items::for_site( $client_site_id );

		if ( Events::DAILY === $kind ) {
			return Digests::daily(
				$items,
				Comments::unanswered_for_site( $client_site_id ),
				Steps::for_site( $client_site_id ),
				$day
			);
		}

		$zone   = self::zone( $client );
		$monday = new DateTimeImmutable( $day . ' 00:00:00', $zone );

		$weekly = Digests::weekly(
			$items,
			WorkEvents::reached_between(
				$client_site_id,
				array( Stages::COMPLETED, Stages::RELEASED ),
				$monday->modify( '-7 days' )->getTimestamp(),
				$monday->getTimestamp()
			),
			$day
		);

		// The hours are worth a line only for a client who has some to count.
		$entitlement = Assignments::entitlement_on( $client_site_id, $day );

		if ( ! empty( $entitlement['may_use_hours'] ) ) {
			$weekly['hours'] = Ledger::balance( $client_site_id );
		}

		return $weekly;
	}

	/**
	 * The address a client's emails go to, or '' when there is none to use.
	 *
	 * @param array<string, mixed>|null $client The client record.
	 * @return string
	 */
	private static function address( ?array $client ): string {
		if ( null === $client || 'active' !== (string) ( $client['status'] ?? '' ) ) {
			return '';
		}

		$email = (string) ( $client['contact_email'] ?? '' );

		return false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ? '' : $email;
	}

	/**
	 * Now, on the client's own clock.
	 *
	 * @param array<string, mixed>|null $client The client record.
	 * @return DateTimeImmutable
	 */
	private static function now_for( ?array $client ): DateTimeImmutable {
		return ( new DateTimeImmutable( '@' . bwx_forge_now() ) )->setTimezone( self::zone( $client ) );
	}

	/**
	 * The client's timezone, or UTC where the record names none we know.
	 *
	 * @param array<string, mixed>|null $client The client record.
	 * @return DateTimeZone
	 */
	private static function zone( ?array $client ): DateTimeZone {
		try {
			return new DateTimeZone( (string) ( $client['timezone'] ?? 'UTC' ) );
		} catch ( \Exception $e ) {
			return new DateTimeZone( 'UTC' );
		}
	}
}
