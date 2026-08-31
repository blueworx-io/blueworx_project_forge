<?php
/**
 * Sending this client's own email, from this client's own site.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client;

/**
 * #173, NOTIF-3. The studio writes the email; this site sends it.
 *
 * **Forge holds no mail credentials, because it never sends anything.** It
 * hands over a finished envelope and this site passes it to `wp_mail`, which
 * uses whatever mail configuration the site already has — its own SMTP plugin,
 * its host's transport, whatever it is. The client's own SPF and DKIM apply,
 * the email arrives from a domain they recognise, and there is no password
 * anywhere in the studio for anybody to lose.
 *
 * **This site asks; it is never told.** A client's WordPress is not something
 * the studio can reach — behind a firewall, asleep, or moved — and ARCH-6
 * already gives this site a key it signs its own requests with. So the loop
 * runs from here: ask what to send, send it, say what happened.
 *
 * It runs on cron, and again whenever somebody is on a Forge screen. The cron
 * is the real mechanism; the page load exists because WP-Cron on a quiet site
 * only fires when somebody visits, and a client site can be quiet for days.
 * Both go through the same method, and an empty outbox costs one request.
 *
 * Nothing is decided here. Not who it goes to, not what it says, not whether it
 * is due — all of that is the studio's, because two implementations of what we
 * told a client is two versions of the truth. This end knows how to send an
 * email and how to admit it did not.
 */
final class Notifications {

	/**
	 * The cron hook.
	 */
	public const CRON_HOOK = 'bwx_forge_client_send_notifications';

	/**
	 * How often it fires when nobody is looking.
	 */
	public const SCHEDULE = 'hourly';

	/**
	 * Studio route the envelopes come from and the outcomes go back to.
	 */
	public const ROUTE = '/client/notifications';

	/**
	 * How long to leave between runs triggered by somebody loading a page.
	 *
	 * At most one check a minute while somebody is actually using Forge. A
	 * client admin clicking through four screens must not make four rounds of
	 * requests to the studio — but the check is one small signed GET, and
	 * somebody who is on these screens is very often the person waiting for the
	 * email. A longer gap saves almost nothing and delays the thing it exists
	 * to hurry along.
	 */
	public const PAGE_LOAD_GAP = 60;

	/**
	 * Option holding when a page load last triggered a run.
	 */
	public const OPTION_LAST_RUN = 'bwx_forge_client_notifications_run';

	/**
	 * Hooks it up.
	 */
	public static function boot(): void {
		add_action( self::CRON_HOOK, array( self::class, 'run' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::SCHEDULE, self::CRON_HOOK );
		}

		add_action( 'bwx_forge_client_screen_loaded', array( self::class, 'maybe_run' ) );
	}

	/**
	 * Stops sending. Called on deactivation, so a disabled plugin leaves no
	 * cron event firing into nothing.
	 */
	public static function unschedule(): void {
		$next = wp_next_scheduled( self::CRON_HOOK );

		if ( false !== $next ) {
			wp_unschedule_event( (int) $next, self::CRON_HOOK );
		}
	}

	/**
	 * Runs, unless one ran recently.
	 *
	 * The gap is recorded before the work rather than after, so two page loads
	 * arriving together do not both get through while the first is still
	 * talking to the studio.
	 */
	public static function maybe_run(): void {
		$last = (int) get_option( self::OPTION_LAST_RUN, 0 );

		if ( time() - $last < self::PAGE_LOAD_GAP ) {
			return;
		}

		update_option( self::OPTION_LAST_RUN, time() );

		self::run();
	}

	/**
	 * Ask, send, report.
	 *
	 * @return array{asked: int, sent: int, failed: int} What happened, for a test
	 *                                                   and for the log.
	 */
	public static function run(): array {
		$nothing = array(
			'asked'  => 0,
			'sent'   => 0,
			'failed' => 0,
		);

		if ( ! Connection::is_configured() ) {
			return $nothing;
		}

		$answer = Connection::get( self::ROUTE );

		if ( ! is_array( $answer ) || ! isset( $answer['send'] ) || ! is_array( $answer['send'] ) ) {
			// The studio is unreachable or said something unexpected. Nothing is
			// lost: the events stay pending and the next run asks again.
			return $nothing;
		}

		$outcomes = array();
		$sent     = 0;
		$failed   = 0;

		foreach ( $answer['send'] as $envelope ) {
			if ( ! is_array( $envelope ) ) {
				continue;
			}

			$id = (string) ( $envelope['event_id'] ?? '' );

			if ( '' === $id ) {
				continue;
			}

			$went = self::deliver( $envelope );

			$outcomes[] = array(
				'event_id' => $id,
				'sent'     => $went,
			);

			if ( $went ) {
				++$sent;
				continue;
			}

			++$failed;
		}

		if ( array() !== $outcomes ) {
			/*
			 * Reported even when every one failed, and that is the point of
			 * reporting at all. An email that fails and is never mentioned is
			 * an email nobody finds out about until the client asks why they
			 * heard nothing.
			 */
			Connection::post( self::ROUTE, array( 'outcomes' => $outcomes ) );
		}

		return array(
			'asked'  => count( $answer['send'] ),
			'sent'   => $sent,
			'failed' => $failed,
		);
	}

	/**
	 * Hands one envelope to WordPress.
	 *
	 * Plain text, and the headers say so. Left to default, some mail stacks
	 * guess at the content type from the body and turn a line of a client's
	 * work item into markup.
	 *
	 * @param array<string, mixed> $envelope to, subject, body.
	 * @return bool Whether WordPress accepted it for delivery.
	 */
	private static function deliver( array $envelope ): bool {
		$to = array_values(
			array_filter(
				array_map( 'strval', (array) ( $envelope['to'] ?? array() ) ),
				static fn( string $one ): bool => '' !== $one && is_email( $one )
			)
		);

		$subject = (string) ( $envelope['subject'] ?? '' );

		if ( array() === $to || '' === $subject ) {
			return false;
		}

		if ( ! function_exists( 'wp_mail' ) ) {
			// A plugin can unhook wp_mail entirely, and a site in that state
			// delivers nothing silently. Reported as a failure so the studio
			// sees it, which is what Mail::capability() is also for.
			Mail::remember_failure( 'wp_mail is not available on this site.' );

			return false;
		}

		return (bool) wp_mail(
			$to,
			$subject,
			(string) ( $envelope['body'] ?? '' ),
			array( 'Content-Type: text/plain; charset=UTF-8' )
		);
	}
}
