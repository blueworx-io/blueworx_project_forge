<?php
/**
 * The emails a client site has yet to send.
 *
 * @package Blueworx\Forge\Notifications
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Notifications;

use Blueworx\Forge\Tenancy\Clients;
use Blueworx\Forge\Tenancy\Memberships;
use Blueworx\Forge\Tenancy\Roles;
use Blueworx\Forge\Tenancy\Users;
use Blueworx\Forge\Work\Items;
use Blueworx\Forge\Work\Submissions;

/**
 * #173, NOTIF-3. The studio decides what to say; the client's site says it.
 *
 * **Forge never sends a client's email, and holds no way to.** There is no SMTP
 * host, port, username or password anywhere in this plugin, and that is the
 * point of the whole arrangement rather than an omission. The client's own
 * WordPress sends through `wp_mail`, using whatever mail configuration that
 * site already has — so the email arrives from a domain the client recognises,
 * their own SPF and DKIM apply, and a credential we never had cannot leak from
 * us.
 *
 * What crosses between the two is a finished envelope: who it goes to, what it
 * says, and the event id it settles. The client site's job is to hand that to
 * `wp_mail` and report what happened. It decides nothing about the content,
 * because two implementations of what an email says is two versions of what we
 * told a client.
 *
 * The site asks, rather than being pushed to. A client's WordPress is not
 * something the studio can reach — it may be behind a firewall, asleep, or
 * moved — and ARCH-6 already gives the site a key it signs its own requests
 * with. So the direction is the one that works: the site asks what it should
 * send, and reports back.
 */
final class Outbox {

	/**
	 * How many envelopes one ask hands over.
	 */
	public const BATCH = 20;

	/**
	 * The envelopes a site should send now.
	 *
	 * An event with nobody to write to is settled as suppressed here rather
	 * than handed over: the client site cannot fix a client with no people set
	 * up, and passing it an envelope with no recipients would have it fail
	 * forever on something that is not its fault.
	 *
	 * @param string $client_site_id The signing site.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_site( string $client_site_id ): array {
		$envelopes = array();

		foreach ( Register::pending_for_site( $client_site_id, self::BATCH ) as $event ) {
			$envelope = self::envelope( $event );

			if ( array() === $envelope ) {
				/*
				 * Nothing to send, and nothing anybody is waiting for. Settled
				 * rather than left raised, so it stops being offered on every
				 * cron run for ever — and recorded as suppressed rather than
				 * failed, because "there was nobody to write to" and "we tried
				 * and it bounced" need different things done about them.
				 */
				Log::suppressed( (string) $event['id'], 'Nobody on this client to write to.' );
				continue;
			}

			$envelopes[] = $envelope;
		}

		return $envelopes;
	}

	/**
	 * One event as a finished email, or an empty array where there is none to send.
	 *
	 * @param array<string, mixed> $event The raised event.
	 * @return array<string, mixed>
	 */
	private static function envelope( array $event ): array {
		$kind    = (string) $event['event_kind'];
		$subject = self::subject_of( $event );

		if ( array() === $subject ) {
			// The record has gone, or never was. Nothing to say about it.
			return array();
		}

		$to = Recipients::choose( self::people( (string) $event['client_id'] ), (string) ( $subject['submitted_by'] ?? '' ) );

		if ( array() === $to ) {
			return array();
		}

		$client = Clients::get( (string) $event['client_id'] );

		$written = Templates::render(
			$kind,
			array(
				'title'       => (string) ( $subject['title'] ?? '' ),
				'client_name' => null === $client ? '' : (string) $client['display_name'],
				'reference'   => (string) $event['subject_id'],
				'destination' => (string) ( $subject['release_destination'] ?? '' ),
			)
		);

		if ( '' === $written['subject'] ) {
			return array();
		}

		return array(
			'event_id' => (string) $event['id'],
			'kind'     => $kind,
			'to'       => $to,
			'subject'  => $written['subject'],
			'body'     => $written['body'],
		);
	}

	/**
	 * The record an event is about.
	 *
	 * @param array<string, mixed> $event The event.
	 * @return array<string, mixed> Empty where the record is gone.
	 */
	private static function subject_of( array $event ): array {
		$id = (string) $event['subject_id'];

		if ( Events::SUBMISSION === (string) $event['subject_type'] ) {
			$submission = Submissions::get( $id );

			return null === $submission ? array() : $submission;
		}

		$item = Items::get( $id );

		return null === $item ? array() : $item;
	}

	/**
	 * The client's own people, as verified records.
	 *
	 * Client-side roles only. A member of studio staff with a membership on
	 * this client is not one of the client's nominated recipients, and putting
	 * them on the list would copy us in on every email we sent.
	 *
	 * @param string $client_id The client.
	 * @return array<int, array<string, mixed>>
	 */
	private static function people( string $client_id ): array {
		$people = array();

		foreach ( Memberships::for_client( $client_id ) as $membership ) {
			if ( ! Roles::is_client_side( (string) $membership['role'] ) ) {
				continue;
			}

			$user = Users::get( (string) $membership['user_id'] );

			// Active only. Somebody who has left the client has left, and the
			// deactivated record is kept for the history rather than the inbox.
			if ( null === $user || 'active' !== (string) $user['status'] ) {
				continue;
			}

			$people[] = $user;
		}

		return $people;
	}
}
