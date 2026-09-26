<?php
/**
 * The client's review decision on their own work.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client;

/**
 * #391 (2026-09-26). When the studio makes the client a task's reviewer, the
 * task waits on the client, who approves it or sends it back with a note.
 *
 * The one thing on this site that decides anything about work, and only this:
 * the studio accepts it for an item in review with the client as its reviewer
 * and refuses everything else. Shaped after {@see ChecklistAnswer}: it reaches
 * the studio now or says it did not. Nothing is queued.
 */
final class Review {

	/**
	 * Approve it.
	 */
	public const APPROVE = 'approve';

	/**
	 * Send it back, with a note.
	 */
	public const SEND_BACK = 'send_back';

	/**
	 * The studio route one item's review decision goes to.
	 *
	 * @param string $item_id The work item.
	 * @return string
	 */
	public static function route( string $item_id ): string {
		return '/client/work-items/' . rawurlencode( $item_id ) . '/review';
	}

	/**
	 * Why a decision cannot be sent as it is, or '' when it can. Pure.
	 *
	 * @param string $decision APPROVE or SEND_BACK.
	 * @param string $note     What needs to change.
	 * @return string A result code, or ''.
	 */
	public static function problem( string $decision, string $note ): string {
		if ( ! in_array( $decision, array( self::APPROVE, self::SEND_BACK ), true ) ) {
			return 'unknown_decision';
		}

		if ( self::SEND_BACK === $decision && '' === trim( $note ) ) {
			return 'note_required';
		}

		return '';
	}

	/**
	 * Sends the decision.
	 *
	 * @param string $item_id  The work item.
	 * @param string $decision APPROVE or SEND_BACK.
	 * @param string $note     What needs to change; required to send back.
	 * @param string $author   Who decided, as the studio will show it.
	 * @return array{ok: bool, result: string, message: string}
	 */
	public static function send( string $item_id, string $decision, string $note, string $author ): array {
		if ( ! Connection::is_configured() ) {
			return self::failed( 'not_connected', '' );
		}

		$problem = self::problem( $decision, $note );

		if ( '' !== $problem ) {
			return self::failed( $problem, '' );
		}

		$answer = Connection::post(
			self::route( $item_id ),
			array(
				'decision'    => $decision,
				'note'        => trim( $note ),
				'author_name' => $author,
			)
		);

		if ( is_wp_error( $answer ) ) {
			$data    = (array) $answer->get_error_data();
			$studio  = is_array( $data['studio_answer'] ?? null ) ? $data['studio_answer'] : array();
			$message = trim( (string) ( $studio['message'] ?? '' ) );

			// The studio's own words where it gave some, such as "Already decided".
			return '' === $message
				? self::failed( 'unreachable', '' )
				: self::failed( 'refused', $message );
		}

		// The board and this item's thread are out of date now, and the person
		// who pressed the button is about to look at them.
		Cache::forget( Board::ROUTE );
		Cache::forget( Discussion::route( $item_id ) );

		return array(
			'ok'      => true,
			'result'  => self::APPROVE === $decision ? 'approved' : 'sent_back',
			'message' => '',
		);
	}

	/**
	 * A failure, in the shape a caller can read without checking three things.
	 *
	 * @param string $result  A result code the screen knows.
	 * @param string $message The studio's own words, where it gave any.
	 * @return array{ok: bool, result: string, message: string}
	 */
	private static function failed( string $result, string $message ): array {
		return array(
			'ok'      => false,
			'result'  => $result,
			'message' => $message,
		);
	}
}
