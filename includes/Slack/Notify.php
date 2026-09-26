<?php
/**
 * The moments a member of staff is told about in Slack.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Slack;

use Blueworx\Forge\Frontend;
use Blueworx\Forge\Tenancy\Clients;
use Blueworx\Forge\Tenancy\Users;
use Blueworx\Forge\Work\ClientReviewer;
use Blueworx\Forge\Work\Stages;

/**
 * Each method is called from the one place the thing happens — a seat set,
 * a move made, a comment saved — and does nothing unless the person is
 * connected and wants that kind. Nothing here throws: a Slack that is down
 * must not stop a save.
 *
 * Every message is claimed before it is sent ({@see Events::claim()}), so a
 * save that fires twice, or two people noticing the same move, produces one
 * ping. Before a person's new message goes, their messages that failed
 * lately are tried once more, so a Slack hiccup is recovered from the next
 * time there is a reason to speak to them.
 */
final class Notify {

	/**
	 * The three seats, in words.
	 */
	private const SEATS = array(
		'primary_user_id' => 'to do',
		'reviewer_id'     => 'to review',
		'deliverer_id'    => 'to ship',
	);

	/**
	 * Which seats changed hands, and to whom.
	 *
	 * Pure. A seat counts when it is set to somebody it was not set to
	 * before; clearing a seat, or setting it to the same person, is nothing
	 * to tell anyone.
	 *
	 * @param array<string, mixed> $before The item before the write.
	 * @param array<string, mixed> $after  The item after.
	 * @return array<string, string> Seat field to the person now holding it.
	 */
	public static function seat_changes( array $before, array $after ): array {
		$changes = array();

		foreach ( array_keys( self::SEATS ) as $seat ) {
			$now = (string) ( $after[ $seat ] ?? '' );

			// #391. The client is not somebody Slack can reach.
			if ( ClientReviewer::ID === $now ) {
				continue;
			}

			if ( '' !== $now && (string) ( $before[ $seat ] ?? '' ) !== $now ) {
				$changes[ $seat ] = $now;
			}
		}

		return $changes;
	}

	/**
	 * Seats given on a write: each new holder is told.
	 *
	 * @param array<string, mixed> $before The item before; empty for a creation.
	 * @param array<string, mixed> $after  The item after.
	 */
	public static function assigned( array $before, array $after ): void {
		$fresh = array() === $before;

		foreach ( self::seat_changes( $before, $after ) as $seat => $user_id ) {
			self::send(
				Events::ASSIGNED,
				(string) $after['id'],
				$user_id,
				$seat . ':' . $user_id,
				'assigned',
				Message::assigned( $after, self::SEATS[ $seat ], $fresh, self::client_name( $after ), self::link( $after ) )
			);
		}
	}

	/**
	 * A move that lands where a seat acts: In review for the Reviewer,
	 * Completed for the Deliverer.
	 *
	 * @param array<string, mixed> $item The item after the move.
	 */
	public static function moved( array $item ): void {
		$stage = (string) ( $item['stage'] ?? '' );

		if ( 'in-review' === $stage && '' !== (string) ( $item['reviewer_id'] ?? '' ) && ! ClientReviewer::is( $item ) ) {
			self::send(
				Events::READY,
				(string) $item['id'],
				(string) $item['reviewer_id'],
				'review:' . (int) ( $item['cycle'] ?? 1 ) . ':' . (int) ( $item['review_attempt'] ?? 1 ),
				'ready',
				Message::ready( $item, 'review', self::client_name( $item ), self::link( $item ) )
			);
		}

		if ( Stages::COMPLETED === $stage && '' !== (string) ( $item['deliverer_id'] ?? '' ) ) {
			self::send(
				Events::READY,
				(string) $item['id'],
				(string) $item['deliverer_id'],
				'delivery:' . (int) ( $item['cycle'] ?? 1 ),
				'ready',
				Message::ready( $item, 'delivery', self::client_name( $item ), self::link( $item ) )
			);
		}
	}

	/**
	 * A comment on a task: everyone holding a seat, except whoever wrote it.
	 *
	 * @param array<string, mixed> $item       The item.
	 * @param array<string, mixed> $comment    The comment, with id, body, author_id (Forge person).
	 * @param string               $author_uid The Forge person who wrote it, or empty.
	 */
	public static function commented( array $item, array $comment, string $author_uid ): void {
		$author = '' === $author_uid ? null : Users::get( $author_uid );
		$name   = null === $author ? '' : (string) $author['display_name'];

		foreach ( array_keys( self::SEATS ) as $seat ) {
			$user_id = (string) ( $item[ $seat ] ?? '' );

			if ( '' === $user_id || $user_id === $author_uid || ClientReviewer::ID === $user_id ) {
				continue;
			}

			self::send(
				Events::COMMENT,
				(string) $comment['id'],
				$user_id,
				(string) $item['id'],
				'comment',
				Message::comment( $item, $name, (string) ( $comment['body'] ?? '' ), self::client_name( $item ), self::link( $item ) )
			);
		}
	}

	/**
	 * "Forge is connected", when a webhook is pasted. Not claimed: every
	 * paste is a fresh test.
	 *
	 * @param string $user_id The person.
	 * @param string $url     The webhook, plain.
	 * @return true|\WP_Error
	 */
	public static function test( string $user_id, string $url ) {
		$sent = Webhook::send( $url, Message::test() );

		People::mark( $user_id, true === $sent, true === $sent ? '' : $sent->get_error_message() );

		return $sent;
	}

	/**
	 * Claims, sends, settles — and retries what failed lately for the same
	 * person first.
	 *
	 * @param string               $kind       One of Events::ALL.
	 * @param string               $subject_id What it is about.
	 * @param string               $user_id    Who it is for.
	 * @param string               $occurrence Which time round.
	 * @param string               $pref       The preference that allows it.
	 * @param array<string, mixed> $message    `{ text, blocks }`.
	 */
	public static function send( string $kind, string $subject_id, string $user_id, string $occurrence, string $pref, array $message ): void {
		$person = People::get( $user_id );

		if ( null === $person || empty( $person['prefs'][ $pref ] ) ) {
			return;
		}

		$id = Events::claim( $kind, $subject_id, $user_id, $occurrence, $message );

		if ( '' === $id ) {
			return;
		}

		$url = People::webhook( $user_id );

		if ( null === $url ) {
			Events::attempted( $id, false, __( 'The webhook could not be read — connect again.', 'blueworx-forge' ) );

			return;
		}

		self::retry_pending( $user_id, $url );
		self::attempt( $id, $user_id, $url, $message );
	}

	/**
	 * One attempt, recorded either way.
	 *
	 * @param string               $id      Event id.
	 * @param string               $user_id The person.
	 * @param string               $url     Their webhook.
	 * @param array<string, mixed> $message The message.
	 */
	private static function attempt( string $id, string $user_id, string $url, array $message ): void {
		$sent = Webhook::send( $url, $message );

		Events::attempted( $id, true === $sent, true === $sent ? '' : $sent->get_error_message() );
		People::mark( $user_id, true === $sent, true === $sent ? '' : $sent->get_error_message() );
	}

	/**
	 * Tries a person's recently failed messages once more.
	 *
	 * @param string $user_id The person.
	 * @param string $url     Their webhook.
	 */
	private static function retry_pending( string $user_id, string $url ): void {
		foreach ( Events::pending_for( $user_id ) as $event ) {
			self::attempt( (string) $event['id'], $user_id, $url, (array) $event['payload'] );
		}
	}

	/**
	 * Where a task opens.
	 *
	 * @param array<string, mixed> $item The item.
	 * @return string
	 */
	public static function link( array $item ): string {
		return Frontend::instance()->app_page_url() . '#item=' . rawurlencode( (string) $item['id'] );
	}

	/**
	 * Whose work a task is.
	 *
	 * @param array<string, mixed> $item The item.
	 * @return string
	 */
	public static function client_name( array $item ): string {
		$client = Clients::get( (string) ( $item['client_id'] ?? '' ) );

		return null === $client ? '' : (string) $client['display_name'];
	}
}
