<?php
/**
 * Writing down what happened to an email.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Notifications;

use Blueworx\Forge\Work\Events as Changelog;
use Blueworx\Forge\Work\Items;

/**
 * #174. A failed email is visible rather than lost.
 *
 * The failure mode this exists to stop is a quiet one. An email does not go;
 * nothing throws; nobody is told; and the first anybody hears is a client
 * asking why they were never informed their site went live. Nothing about that
 * sequence looks like a fault while it is happening, which is exactly why it
 * has to be written down at the moment it does not happen.
 *
 * **It is written in two places on purpose, and they answer different
 * questions.** The notification record answers "what is outstanding" and is
 * what Standup reads. The item's own changelog answers "did the client ever
 * hear about this", which is asked while looking at the work, usually in the
 * middle of a conversation with the client — and an answer that lives on
 * another screen is one nobody finds in time.
 *
 * Every attempt is recorded, not only the last. Three failures and then a
 * success is a different story from one clean send, and it is the story
 * somebody wants when the client says the email arrived four hours late.
 *
 * A submission has no changelog of its own, so an acknowledgement leaves its
 * trace in the notification record alone. That is a gap in the record rather
 * than a decision, and it is the one thing here worth revisiting if request
 * intake ever grows a history.
 */
final class Log {

	/**
	 * Records one attempt, in both places.
	 *
	 * @param string $event_id The event.
	 * @param bool   $sent     Whether it went.
	 * @param string $detail   What the mailer said, where it said anything.
	 * @return string The outcome recorded, or '' where the event is not there.
	 */
	public static function attempt( string $event_id, bool $sent, string $detail = '' ): string {
		$before = Register::get( $event_id );

		if ( null === $before ) {
			return '';
		}

		$outcome = Register::attempted( $event_id, $sent, $detail );

		if ( '' === $outcome ) {
			return '';
		}

		self::to_changelog( $before, $outcome, (int) $before['attempts'] + 1, $detail );

		return $outcome;
	}

	/**
	 * Records that there was nobody to send to.
	 *
	 * Its own method because it is not an attempt: nothing was tried, so
	 * nothing can be retried, and counting it against the ladder would burn a
	 * rung on a problem no amount of waiting fixes. What it needs is somebody
	 * to add a person to the client.
	 *
	 * @param string $event_id The event.
	 * @param string $why      What was missing.
	 * @return bool Whether it was recorded.
	 */
	public static function suppressed( string $event_id, string $why ): bool {
		$event = Register::get( $event_id );

		if ( null === $event ) {
			return false;
		}

		$done = Register::settle( $event_id, Register::SUPPRESSED );

		self::to_changelog( $event, Register::SUPPRESSED, (int) $event['attempts'], $why );

		return $done;
	}

	/**
	 * Puts one attempt on the item's own history.
	 *
	 * Silently does nothing for an event about anything other than a work item.
	 * Not a swallowed error: a submission genuinely has nowhere to put it, and
	 * the notification record already holds the whole story.
	 *
	 * @param array<string, mixed> $event   The event as it was before the attempt.
	 * @param string               $outcome What happened.
	 * @param int                  $attempt Which attempt this was.
	 * @param string               $detail  What the mailer said.
	 */
	private static function to_changelog( array $event, string $outcome, int $attempt, string $detail ): void {
		if ( Events::WORK_ITEM !== (string) $event['subject_type'] ) {
			return;
		}

		$item = Items::get( (string) $event['subject_id'] );

		if ( null === $item ) {
			return;
		}

		Changelog::append(
			array(
				'item_id'        => (string) $item['id'],
				'client_site_id' => (string) $item['client_site_id'],
				'action'         => Changelog::NOTIFIED,
				'outcome'        => $outcome,

				// Which telling it was, so a history reads as a sequence of
				// attempts at one thing rather than as several things.
				'reason'         => self::said( $event, $attempt ),
				'detail'         => $detail,
			)
		);
	}

	/**
	 * The one line an entry carries about itself.
	 *
	 * @param array<string, mixed> $event   The event.
	 * @param int                  $attempt Which attempt.
	 * @return string
	 */
	private static function said( array $event, int $attempt ): string {
		$kind = (string) $event['event_kind'];

		return 1 >= $attempt ? $kind : $kind . ', attempt ' . $attempt;
	}
}
