<?php
/**
 * What Forge still has to tell GitHub.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\GitHub;

use Blueworx\Forge\Data\Schema;
use Throwable;
use WP_Error;

/**
 * #681. The outbox. A save never waits on GitHub: it asks for a job here, and
 * the job runs once the response is on its way and again every five minutes
 * until it goes or runs out of tries.
 *
 * There is one job per kind of change per item, so asking again replaces what
 * is still waiting rather than adding a second. A runner claims a job before
 * it calls GitHub, so two runners at once never send the same change twice.
 */
final class Jobs {

	/**
	 * How many tries a job gets before it gives up.
	 */
	public const MAX_ATTEMPTS = 8;

	/**
	 * Waiting to run, now or after its back-off.
	 */
	public const PENDING = 'pending';

	/**
	 * Claimed by a runner that is calling GitHub.
	 */
	public const RUNNING = 'running';

	/**
	 * Sent.
	 */
	public const DONE = 'done';

	/**
	 * Gave up after MAX_ATTEMPTS tries.
	 */
	public const FAILED = 'failed';

	/**
	 * How long a claim is trusted. A runner that died part way (a timeout, a
	 * fatal error) leaves its job claimed; after fifteen minutes it counts as
	 * a failed try. A call to GitHub gives up long before that.
	 */
	public const STALE = 900;

	/**
	 * Whether this request asked for anything, so shutdown knows to run.
	 *
	 * @var bool
	 */
	private static bool $queued = false;

	/**
	 * The job's id: one per kind per item.
	 *
	 * @param string $kind    What it does.
	 * @param string $item_id The item it is for.
	 * @return string
	 */
	public static function id_for( string $kind, string $item_id ): string {
		return 'ghj_' . substr( hash( 'sha256', $kind . '|' . $item_id ), 0, 28 );
	}

	/**
	 * Seconds until the next try, after this many have failed.
	 *
	 * @param int $attempts Tries so far.
	 * @return int
	 */
	public static function backoff( int $attempts ): int {
		return (int) min( 3600, 60 * 2 ** max( 0, min( $attempts, 12 ) ) );
	}

	/**
	 * Asks for a job.
	 *
	 * @param string               $kind    What it does.
	 * @param string               $item_id The item it is for.
	 * @param array<string, mixed> $payload What it needs beyond the item.
	 * @param bool                 $once    True for a job that must only ever happen once: one
	 *                                      already done is never re-opened.
	 */
	public static function queue( string $kind, string $item_id, array $payload = array(), bool $once = false ): void {
		global $wpdb;

		$table = Schema::github_jobs_table();
		$id    = self::id_for( $kind, $item_id );
		$now   = bwx_forge_now();
		$json  = (string) wp_json_encode( $payload );
		$row   = self::get( $id );

		if ( null === $row ) {
			$fresh = array(
				'id'         => $id,
				'item_id'    => $item_id,
				'kind'       => $kind,
				'payload'    => $json,
				'status'     => self::PENDING,
				'attempts'   => 0,
				'last_error' => '',
				'run_after'  => $now,
				'created_at' => $now,
				'updated_at' => $now,
			);

			$suppress = $wpdb->suppress_errors();

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; the key refuses a second row.
			$inserted = $wpdb->insert( $table, $fresh, array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%d' ) );

			$wpdb->suppress_errors( $suppress );

			if ( false !== $inserted && 0 < (int) $inserted ) {
				self::$queued = true;
				return;
			}

			// Another request asked for the same job a moment ago.
			$row = self::get( $id );

			if ( null === $row ) {
				return;
			}
		}

		if ( $once && self::DONE === $row['status'] ) {
			return;
		}

		self::$queued = true;

		// A runner has it now. Only what to send changes: when the runner
		// finishes it sees the change and leaves the job to run again.
		if ( self::RUNNING === $row['status'] ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
			$wpdb->update( $table, array( 'payload' => $json ), array( 'id' => $id ), array( '%s' ), array( '%s' ) );
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->update(
			$table,
			array(
				'payload'    => $json,
				'status'     => self::PENDING,
				'attempts'   => 0,
				'last_error' => '',
				'run_after'  => $now,
				'updated_at' => $now,
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%d', '%s', '%d', '%d' ),
			array( '%s' )
		);
	}

	/**
	 * Whether this request asked for a job.
	 *
	 * @return bool
	 */
	public static function queued(): bool {
		return self::$queued;
	}

	/**
	 * Drops a job, so asking for it later starts afresh.
	 *
	 * @param string $kind    What it does.
	 * @param string $item_id The item it is for.
	 */
	public static function forget( string $kind, string $item_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->delete( Schema::github_jobs_table(), array( 'id' => self::id_for( $kind, $item_id ) ), array( '%s' ) );
	}

	/**
	 * Runs the jobs that are due, oldest first. Nothing happens while GitHub
	 * is not connected: the jobs wait.
	 *
	 * @param int $limit At most this many.
	 * @return int How many went.
	 */
	public static function run( int $limit = 20 ): int {
		global $wpdb;

		if ( ! Settings::connected() ) {
			return 0;
		}

		self::recover();

		$table = Schema::github_jobs_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$ids  = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE status = %s AND run_after <= %d ORDER BY created_at ASC, id ASC LIMIT %d", self::PENDING, bwx_forge_now(), max( 1, $limit ) ) );
		$sent = 0;

		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			$job = self::claim( (string) $id );

			if ( null === $job ) {
				continue;
			}

			try {
				$result = Outbound::perform( $job );
			} catch ( Throwable $thrown ) {
				$result = new WP_Error( 'bwx_forge_github_job', __( 'Forge ran into an error sending this change to GitHub.', 'blueworx-forge' ) );
			}

			if ( true === $result ) {
				self::sent( $job );
				++$sent;
				continue;
			}

			// The token is refused: every job would be, and none of them is at
			// fault. They wait, as when GitHub is not connected, and the GitHub
			// row says to reconnect.
			if ( ! self::counts_as_try( $result ) ) {
				self::release( $job, $result->get_error_message() );
				break;
			}

			self::tried(
				$job,
				is_wp_error( $result ) ? $result->get_error_message() : __( 'GitHub did not take the change.', 'blueworx-forge' )
			);
		}

		return $sent;
	}

	/**
	 * Whether a job's failure uses up one of its tries. A refused token does
	 * not: it is the connection that is wrong, not the job.
	 *
	 * @param mixed $result What Outbound::perform() gave back.
	 * @return bool
	 */
	public static function counts_as_try( $result ): bool {
		return ! ( is_wp_error( $result ) && Client::DENIED === $result->get_error_code() );
	}

	/**
	 * After a good connect: jobs that gave up get their tries back, and this
	 * request runs what is waiting once it has answered.
	 */
	public static function reopen(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->update(
			Schema::github_jobs_table(),
			array(
				'status'     => self::PENDING,
				'attempts'   => 0,
				'run_after'  => bwx_forge_now(),
				'updated_at' => bwx_forge_now(),
			),
			array( 'status' => self::FAILED ),
			array( '%s', '%d', '%d', '%d' ),
			array( '%s' )
		);

		self::$queued = true;
	}

	/**
	 * Jobs that gave up, for the Connections page.
	 *
	 * @return array{count: int, last_error: string}
	 */
	public static function failures(): array {
		return self::tally( self::FAILED );
	}

	/**
	 * Jobs still waiting that have hit a problem: a failed try, or a refused token.
	 *
	 * @return array{count: int, last_error: string}
	 */
	public static function waiting(): array {
		return self::tally( self::PENDING );
	}

	/**
	 * What the GitHub row on Connections says about changes not yet sent.
	 *
	 * @param array{count: int, last_error: string} $failures Jobs that gave up.
	 * @param array{count: int, last_error: string} $waiting  Jobs still being tried.
	 * @return string '' when everything went.
	 */
	public static function describe( array $failures, array $waiting ): string {
		$lines = array();

		if ( 0 < $failures['count'] ) {
			$lines[] = sprintf(
				/* translators: 1: how many changes, 2: what last went wrong. */
				_n( '%1$d change couldn\'t be sent to GitHub: %2$s', '%1$d changes couldn\'t be sent to GitHub: %2$s', $failures['count'], 'blueworx-forge' ),
				$failures['count'],
				$failures['last_error']
			);
		}

		if ( 0 < $waiting['count'] ) {
			$lines[] = sprintf(
				/* translators: 1: how many changes, 2: what went wrong on the last try. */
				_n( '%1$d change is waiting to be sent to GitHub. Last try: %2$s', '%1$d changes are waiting to be sent to GitHub. Last try: %2$s', $waiting['count'], 'blueworx-forge' ),
				$waiting['count'],
				$waiting['last_error']
			);
		}

		return implode( ' ', $lines );
	}

	/**
	 * Takes a job for this runner, unless another got there first.
	 *
	 * @param string $id The job.
	 * @return array<string, mixed>|null The job as claimed.
	 */
	private static function claim( string $id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; the status in the WHERE is the claim.
		$claimed = $wpdb->update(
			Schema::github_jobs_table(),
			array(
				'status'     => self::RUNNING,
				'updated_at' => bwx_forge_now(),
			),
			array(
				'id'     => $id,
				'status' => self::PENDING,
			),
			array( '%s', '%d' ),
			array( '%s', '%s' )
		);

		return 1 === (int) $claimed ? self::get( $id ) : null;
	}

	/**
	 * Marks a claimed job sent. If it was asked for again while it ran, with
	 * something different to send, it goes back in the queue instead.
	 *
	 * @param array<string, mixed> $job The job as claimed.
	 */
	private static function sent( array $job ): void {
		global $wpdb;

		$table = Schema::github_jobs_table();
		$now   = bwx_forge_now();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$done = $wpdb->update(
			$table,
			array(
				'status'     => self::DONE,
				'last_error' => '',
				'updated_at' => $now,
			),
			array(
				'id'      => (string) $job['id'],
				'status'  => self::RUNNING,
				'payload' => (string) $job['payload'],
			),
			array( '%s', '%s', '%d' ),
			array( '%s', '%s', '%s' )
		);

		if ( 1 !== (int) $done ) {
			$wpdb->update(
				$table,
				array(
					'status'     => self::PENDING,
					'run_after'  => $now,
					'updated_at' => $now,
				),
				array(
					'id'     => (string) $job['id'],
					'status' => self::RUNNING,
				),
				array( '%s', '%d', '%d' ),
				array( '%s', '%s' )
			);
		}
		// phpcs:enable
	}

	/**
	 * Records a failed try: it waits longer each time, and gives up at
	 * MAX_ATTEMPTS, which shows on the GitHub row on Connections.
	 *
	 * @param array<string, mixed> $job   The job as claimed.
	 * @param string               $error What went wrong, in plain words.
	 */
	private static function tried( array $job, string $error ): void {
		global $wpdb;

		$attempts = (int) $job['attempts'] + 1;
		$gave_up  = $attempts >= self::MAX_ATTEMPTS;
		$now      = bwx_forge_now();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->update(
			Schema::github_jobs_table(),
			array(
				'status'     => $gave_up ? self::FAILED : self::PENDING,
				'attempts'   => $attempts,
				'last_error' => mb_substr( $error, 0, 191 ),
				'run_after'  => $now + self::backoff( $attempts ),
				'updated_at' => $now,
			),
			array(
				'id'     => (string) $job['id'],
				'status' => self::RUNNING,
			),
			array( '%s', '%d', '%s', '%d', '%d' ),
			array( '%s', '%s' )
		);

		if ( $gave_up ) {
			/* translators: %s: what went wrong. */
			Settings::failed( sprintf( __( 'A change couldn\'t be sent to GitHub: %s', 'blueworx-forge' ), $error ) );
		}
	}

	/**
	 * Puts a claimed job back as it was, tries untouched, noting why.
	 *
	 * @param array<string, mixed> $job   The job as claimed.
	 * @param string               $error Why it could not go.
	 */
	private static function release( array $job, string $error ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->update(
			Schema::github_jobs_table(),
			array(
				'status'     => self::PENDING,
				'last_error' => mb_substr( $error, 0, 191 ),
				'updated_at' => bwx_forge_now(),
			),
			array(
				'id'     => (string) $job['id'],
				'status' => self::RUNNING,
			),
			array( '%s', '%s', '%d' ),
			array( '%s', '%s' )
		);
	}

	/**
	 * Counts a try that never finished as a failed one, so its job is tried
	 * again rather than left claimed for good.
	 */
	private static function recover(): void {
		global $wpdb;

		$table = Schema::github_jobs_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s AND updated_at < %d", self::RUNNING, bwx_forge_now() - self::STALE ), ARRAY_A );

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			self::tried( $row, __( 'The last try didn\'t finish.', 'blueworx-forge' ) );
		}
	}

	/**
	 * How many jobs are in a state, and what last went wrong with one.
	 *
	 * @param string $status Which state.
	 * @return array{count: int, last_error: string}
	 */
	private static function tally( string $status ): array {
		global $wpdb;

		$table = Schema::github_jobs_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s AND last_error <> ''", $status ) );
		$last  = 0 === $count ? '' : (string) $wpdb->get_var( $wpdb->prepare( "SELECT last_error FROM {$table} WHERE status = %s AND last_error <> '' ORDER BY updated_at DESC LIMIT 1", $status ) );
		// phpcs:enable

		return array(
			'count'      => $count,
			'last_error' => $last,
		);
	}

	/**
	 * One job.
	 *
	 * @param string $id The job.
	 * @return array<string, mixed>|null
	 */
	private static function get( string $id ): ?array {
		global $wpdb;

		$table = Schema::github_jobs_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %s", $id ), ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}

		$row['attempts']  = (int) $row['attempts'];
		$row['run_after'] = (int) $row['run_after'];

		return $row;
	}
}
