<?php
/**
 * The record of which notifications have already been raised.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Notifications;

use Blueworx\Forge\Data\Formats;
use Blueworx\Forge\Data\Schema;

/**
 * #172. Exactly one email per qualifying event, whatever happens underneath.
 *
 * **The claim is an insert, and the database decides.** This is the one thing
 * about this file worth reading. The obvious implementation — look for the
 * event, and send it if it is not there — is wrong, and wrong in the way that
 * only shows up in production: two callers both look, both find nothing, and
 * both send. A client site syncing while the studio saves is exactly that race,
 * and it is not rare, because both are reacting to the same moment.
 *
 * So nothing here reads before it writes. {@see self::claim()} inserts a row
 * whose primary key is the event's own id, worked out from what happened
 * ({@see Events::id_for()}). Two callers racing both attempt the same insert;
 * the database gives one of them the row and refuses the other. The return
 * value is the answer to "is this mine to send", and it is correct without a
 * lock, a transient or a second query.
 *
 * **A claim is not a send.** The row starts at `raised` and is moved to an
 * outcome afterwards by whoever attempted delivery (#173, #174). That separation
 * is why a *retry* also produces one email: the retry is the same event id, so
 * it never claims a second time — it settles the row it already holds. A send
 * that failed and is being tried again is one event with two attempts, not two
 * events, and the outcome column is where that difference lives.
 *
 * Nothing is ever deleted from here, and nothing is ever overwritten except the
 * outcome. What was sent to a client is part of the record of the relationship.
 */
final class Register {

	/**
	 * Claimed, nobody has tried to deliver it yet.
	 */
	public const RAISED = 'raised';

	/**
	 * It went.
	 */
	public const SENT = 'sent';

	/**
	 * It did not go, and will not be tried again.
	 */
	public const FAILED = 'failed';

	/**
	 * Deliberately not sent — no recipient, or a client who has asked not to be
	 * told. Recorded rather than skipped silently, because "why did they not
	 * get an email" is a question somebody asks weeks later.
	 */
	public const SUPPRESSED = 'suppressed';

	/**
	 * Every outcome a raised event can reach.
	 *
	 * @var array<int, string>
	 */
	public const OUTCOMES = array(
		self::RAISED,
		self::SENT,
		self::FAILED,
		self::SUPPRESSED,
	);

	/**
	 * Claims an event, if nobody has already.
	 *
	 * @param array<string, mixed> $event kind, subject_id, and optionally
	 *                                    occurrence, client_id, client_site_id.
	 * @return string The event id when this caller claimed it, and '' when
	 *                somebody else already had it or it is not a real event.
	 */
	public static function claim( array $event ): string {
		global $wpdb;

		$kind       = (string) ( $event['kind'] ?? '' );
		$subject    = (string) ( $event['subject_id'] ?? '' );
		$occurrence = max( 1, (int) ( $event['occurrence'] ?? 1 ) );

		$id = Events::id_for( $kind, $subject, $occurrence );

		if ( '' === $id ) {
			return '';
		}

		$row = array(
			'id'             => $id,
			'event_kind'     => $kind,
			'subject_type'   => Events::subject_type( $kind ),
			'subject_id'     => $subject,
			'client_id'      => (string) ( $event['client_id'] ?? '' ),
			'client_site_id' => (string) ( $event['client_site_id'] ?? '' ),
			'occurrence'     => $occurrence,
			'outcome'        => self::RAISED,
			'raised_at'      => bwx_forge_now(),
			'settled_at'     => 0,
		);

		/*
		 * The insert is the whole mechanism, so its failure is an expected
		 * answer rather than an error: a duplicate key means somebody else got
		 * here first, which is precisely what this is for. That does mean a
		 * genuine write failure — a table missing, a database gone away — reads
		 * the same as a duplicate and suppresses an email. The alternative is a
		 * second query to tell them apart, which reintroduces the read this
		 * file exists to avoid; #174 is where an event that never settles gets
		 * noticed, and that is the right place for it.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; there is no core API for it, and the insert failing is the answer rather than an error.
		$inserted = $wpdb->insert( Schema::notification_events_table(), $row, Formats::for_row( $row ) );

		return $inserted ? $id : '';
	}

	/**
	 * Runs something exactly once for an event, however often it is asked.
	 *
	 * The guarantee, in one place, so that no caller has to remember the order
	 * to do the two steps in. A caller that claimed and then sent without this
	 * would eventually send first and claim second, and the retry that followed
	 * a crash in between would send twice.
	 *
	 * @param array<string, mixed> $event The event, as {@see self::claim()} takes it.
	 * @param callable             $send  Given the event id; returns one of the
	 *                                    outcomes, or true/false for sent/failed.
	 * @return array{claimed: bool, id: string, outcome: string}
	 */
	public static function once( array $event, callable $send ): array {
		$id = self::claim( $event );

		if ( '' === $id ) {
			// Somebody else has it. Not a failure, and not something to tell
			// anybody about — this is the ordinary case on a busy day.
			return array(
				'claimed' => false,
				'id'      => Events::id_for(
					(string) ( $event['kind'] ?? '' ),
					(string) ( $event['subject_id'] ?? '' ),
					max( 1, (int) ( $event['occurrence'] ?? 1 ) )
				),
				'outcome' => '',
			);
		}

		$outcome = self::outcome_from( $send( $id ) );

		self::settle( $id, $outcome );

		return array(
			'claimed' => true,
			'id'      => $id,
			'outcome' => $outcome,
		);
	}

	/**
	 * Records how an event ended up.
	 *
	 * The one column that may be written twice: an event that failed and was
	 * retried settles again. Everything else about the row is what happened and
	 * never changes.
	 *
	 * @param string $id      Event id.
	 * @param string $outcome One of {@see self::OUTCOMES}.
	 * @return bool Whether it was recorded.
	 */
	public static function settle( string $id, string $outcome ): bool {
		global $wpdb;

		if ( '' === $id || ! in_array( $outcome, self::OUTCOMES, true ) ) {
			return false;
		}

		$changes = array(
			'outcome'    => $outcome,
			'settled_at' => self::RAISED === $outcome ? 0 : bwx_forge_now(),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; there is no core API for it.
		return false !== $wpdb->update(
			Schema::notification_events_table(),
			$changes,
			array( 'id' => $id ),
			Formats::for_row( $changes ),
			array( '%s' )
		);
	}

	/**
	 * One event, if it has been raised.
	 *
	 * @param string $id Event id.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $id ): ?array {
		global $wpdb;

		$table = Schema::notification_events_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %s", $id ), ARRAY_A );

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Everything raised about one record, oldest first.
	 *
	 * @param string $subject_id The work item or submission.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_subject( string $subject_id ): array {
		global $wpdb;

		$table = Schema::notification_events_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE subject_id = %s ORDER BY raised_at ASC, id ASC", $subject_id ), ARRAY_A );

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Everything raised about several records, grouped by which.
	 *
	 * One query, because the caller is a list screen. Asking per row is how a
	 * queue of forty requests becomes forty extra queries for a column most
	 * people never look at.
	 *
	 * @param array<int, string> $subject_ids The records.
	 * @return array<string, array<int, array<string, mixed>>> Keyed by subject id.
	 */
	public static function for_subjects( array $subject_ids ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'strval', $subject_ids ) ) ) );

		if ( array() === $ids ) {
			return array();
		}

		$table = Schema::notification_events_table();
		$slots = implode( ', ', array_fill( 0, count( $ids ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table name cannot be a placeholder, and the id placeholders are built above from the ids themselves; every value is still prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE subject_id IN ({$slots}) ORDER BY raised_at ASC, id ASC", $ids ), ARRAY_A );

		$grouped = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$grouped[ (string) $row['subject_id'] ][] = self::hydrate( $row );
		}

		return $grouped;
	}

	/**
	 * What one site still has to send, oldest first.
	 *
	 * Oldest first because these are told in the order they happened: a client
	 * receiving "now live" before "ready to go live" has been told the story
	 * backwards, and the second email then reads as a mistake.
	 *
	 * Bounded, because this is answered to a client site asking what it should
	 * send now. A site that has been offline for a month has a month of these,
	 * and handing it all of them in one response is how a cron run times out
	 * and never gets through any of them.
	 *
	 * @param string $client_site_id The site.
	 * @param int    $limit          How many at most.
	 * @return array<int, array<string, mixed>>
	 */
	public static function pending_for_site( string $client_site_id, int $limit = 20 ): array {
		global $wpdb;

		$table = Schema::notification_events_table();
		$limit = max( 1, min( 100, $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE client_site_id = %s AND outcome = %s ORDER BY raised_at ASC, id ASC LIMIT %d", $client_site_id, self::RAISED, $limit ), ARRAY_A );

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * What a sender's answer means.
	 *
	 * A sender may say exactly which outcome it reached, or answer true or
	 * false and let this decide. Both are accepted because #173 knows the
	 * difference between a failure and a deliberate suppression, and a caller
	 * that only knows whether it worked should not have to pretend otherwise.
	 *
	 * @param mixed $answer Whatever the sender returned.
	 * @return string
	 */
	private static function outcome_from( $answer ): string {
		if ( is_string( $answer ) && in_array( $answer, self::OUTCOMES, true ) ) {
			return $answer;
		}

		return $answer ? self::SENT : self::FAILED;
	}

	/**
	 * A row, as the rest of the product reads it.
	 *
	 * @param array<string, mixed> $row The row.
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		return array(
			'id'             => (string) $row['id'],
			'event_kind'     => (string) ( $row['event_kind'] ?? '' ),
			'subject_type'   => (string) ( $row['subject_type'] ?? '' ),
			'subject_id'     => (string) ( $row['subject_id'] ?? '' ),
			'client_id'      => (string) ( $row['client_id'] ?? '' ),
			'client_site_id' => (string) ( $row['client_site_id'] ?? '' ),
			'occurrence'     => (int) ( $row['occurrence'] ?? 1 ),
			'outcome'        => (string) ( $row['outcome'] ?? self::RAISED ),
			'raised_at'      => (int) ( $row['raised_at'] ?? 0 ),
			'settled_at'     => (int) ( $row['settled_at'] ?? 0 ),
		);
	}
}
