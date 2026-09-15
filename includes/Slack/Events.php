<?php
/**
 * Which Slack messages have already been raised.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Slack;

use Blueworx\Forge\Data\Formats;
use Blueworx\Forge\Data\Schema;

/**
 * The same idea as Notifications\Register, kept apart from it on purpose:
 * that register is the list of emails a client site will send, and a Slack
 * message for a member of staff must never end up in it.
 *
 * The id is worked out from what happened — kind, subject, person, which
 * time round — so two callers noticing the same thing compute the same id,
 * and the primary key lets one of them through. A claim is not a send: the
 * row is settled afterwards, and a retry settles the row it already holds.
 */
final class Events {

	/**
	 * Id prefix.
	 */
	public const PREFIX = 'slk';

	/**
	 * A seat on a task was given to this person.
	 */
	public const ASSIGNED = 'assigned';

	/**
	 * A task reached the stage this person's seat acts at.
	 */
	public const READY = 'ready';

	/**
	 * Somebody commented on a task this person holds a seat on.
	 */
	public const COMMENT = 'comment';

	/**
	 * The morning message.
	 */
	public const MORNING = 'morning';

	/**
	 * A plain "Forge is connected", sent when a webhook is pasted.
	 */
	public const TEST = 'test';

	/**
	 * Every kind.
	 */
	public const ALL = array(
		self::ASSIGNED,
		self::READY,
		self::COMMENT,
		self::MORNING,
		self::TEST,
	);

	/**
	 * Claimed, nobody has tried to send it yet.
	 */
	public const RAISED = 'raised';

	/**
	 * It went.
	 */
	public const SENT = 'sent';

	/**
	 * It did not go, and will be tried again.
	 */
	public const RETRYING = 'retrying';

	/**
	 * Three misses; not tried again.
	 */
	public const FAILED = 'failed';

	/**
	 * How many attempts before giving up.
	 */
	public const MOST_ATTEMPTS = 3;

	/**
	 * The id an event has, wherever it is computed.
	 *
	 * @param string $kind       One of ALL.
	 * @param string $subject_id What it is about.
	 * @param string $user_id    Who it is for.
	 * @param string $occurrence Which time round — a cycle, a date, a seat.
	 * @return string Empty when the kind is unknown.
	 */
	public static function id_for( string $kind, string $subject_id, string $user_id, string $occurrence ): string {
		if ( ! in_array( $kind, self::ALL, true ) || '' === $user_id ) {
			return '';
		}

		return self::PREFIX . '_' . substr( sha1( $kind . '|' . $subject_id . '|' . $user_id . '|' . $occurrence ), 0, 28 );
	}

	/**
	 * Claims an event. The id for the caller who got it; empty otherwise.
	 *
	 * @param string               $kind       One of ALL.
	 * @param string               $subject_id What it is about.
	 * @param string               $user_id    Who it is for.
	 * @param string               $occurrence Which time round.
	 * @param array<string, mixed> $payload    The message, kept so a retry says the same.
	 * @return string
	 */
	public static function claim( string $kind, string $subject_id, string $user_id, string $occurrence, array $payload ): string {
		global $wpdb;

		$id = self::id_for( $kind, $subject_id, $user_id, $occurrence );

		if ( '' === $id ) {
			return '';
		}

		$row = array(
			'id'          => $id,
			'kind'        => $kind,
			'subject_id'  => $subject_id,
			'user_id'     => $user_id,
			'outcome'     => self::RAISED,
			'attempts'    => 0,
			'last_detail' => '',
			'payload'     => (string) wp_json_encode( $payload ),
			'created_at'  => bwx_forge_now(),
			'settled_at'  => 0,
		);

		$suppress = $wpdb->suppress_errors();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; the duplicate-key refusal is the claim.
		$inserted = $wpdb->insert( Schema::slack_events_table(), $row, Formats::for_row( $row ) );

		$wpdb->suppress_errors( $suppress );

		return false !== $inserted && 0 < (int) $inserted ? $id : '';
	}

	/**
	 * Records an attempt's outcome.
	 *
	 * @param string $id     Event id.
	 * @param bool   $sent   Whether it went.
	 * @param string $detail What went wrong, or empty.
	 */
	public static function attempted( string $id, bool $sent, string $detail = '' ): void {
		global $wpdb;

		$table = Schema::slack_events_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$attempts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT attempts FROM {$table} WHERE id = %s", $id ) ) + 1;

		$outcome = $sent ? self::SENT : ( $attempts >= self::MOST_ATTEMPTS ? self::FAILED : self::RETRYING );

		$changes = array(
			'outcome'     => $outcome,
			'attempts'    => $attempts,
			'last_detail' => mb_substr( $detail, 0, 191 ),
			'settled_at'  => bwx_forge_now(),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->update( $table, $changes, array( 'id' => $id ), Formats::for_row( $changes ), array( '%s' ) );
	}

	/**
	 * A person's messages still waiting to be sent again, oldest first.
	 *
	 * @param string $user_id Forge person id.
	 * @param int    $limit   At most this many.
	 * @return array<int, array<string, mixed>>
	 */
	public static function pending_for( string $user_id, int $limit = 5 ): array {
		global $wpdb;

		$table = Schema::slack_events_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %s AND outcome = %s ORDER BY created_at ASC LIMIT %d", $user_id, self::RETRYING, $limit ), ARRAY_A );

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Every message that gave up, newest first, for Sync health.
	 *
	 * @param int $limit At most this many.
	 * @return array<int, array<string, mixed>>
	 */
	public static function failed( int $limit = 50 ): array {
		global $wpdb;

		$table = Schema::slack_events_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE outcome = %s ORDER BY settled_at DESC LIMIT %d", self::FAILED, $limit ), ARRAY_A );

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * A row as the rest of the module reads it.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		$payload = json_decode( (string) $row['payload'], true );

		return array(
			'id'          => (string) $row['id'],
			'kind'        => (string) $row['kind'],
			'subject_id'  => (string) $row['subject_id'],
			'user_id'     => (string) $row['user_id'],
			'outcome'     => (string) $row['outcome'],
			'attempts'    => (int) $row['attempts'],
			'last_detail' => (string) $row['last_detail'],
			'payload'     => is_array( $payload ) ? $payload : array(),
			'created_at'  => (int) $row['created_at'],
			'settled_at'  => (int) $row['settled_at'],
		);
	}
}
