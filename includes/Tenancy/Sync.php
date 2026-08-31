<?php
/**
 * Whether a client site is still talking to us, and what it is behind on.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Tenancy;

use Blueworx\Forge\Notifications\Register;

/**
 * #177. A broken client site is noticed by us, not by the client.
 *
 * {@see Health} already answers "what is this connection doing", and this does
 * not repeat it. It answers the harder question underneath: *is anybody going
 * to have to do something about it*, which needs two things Health has no
 * business knowing — how long a site has been silent, and whether there is work
 * piled up behind that silence.
 *
 * **Silence is the whole problem.** Every other fault in the product announces
 * itself: a refused request returns an error, a failed save says so. A client
 * site that has stopped calling produces nothing at all, and looks from here
 * exactly like a client site with nothing to say. The only way to tell the two
 * apart is to go looking, on a clock, and that is what this class is for.
 *
 * Derived, never stored, for the same reason Health is: a stored "stalled" flag
 * is only as current as whatever last wrote it, and the case that matters is
 * precisely the one where nothing writes.
 */
final class Sync {

	/**
	 * Its last call failed and nothing has worked since.
	 */
	public const BROKEN = 'broken';

	/**
	 * It used to call and has now been silent long enough to be worrying.
	 */
	public const STALLED = 'stalled';

	/**
	 * It has work waiting that it has not come and collected.
	 */
	public const DELAYED = 'delayed';

	/**
	 * A key was issued and never once used, so connecting it never finished.
	 */
	public const NEVER_USED = 'never-used';

	/**
	 * Every reason a site can be in the queue, worst first.
	 *
	 * The order is the order somebody should work down them, and it is not
	 * arbitrary: broken is a site actively failing, stalled is one that has
	 * silently gone away, delayed is one that is late rather than gone, and
	 * never-used is a setup that was started and not finished — the only one of
	 * the four that has never yet worked, and so the least urgent.
	 *
	 * @var array<int, string>
	 */
	public const REASONS = array(
		self::BROKEN,
		self::STALLED,
		self::DELAYED,
		self::NEVER_USED,
	);

	/**
	 * How long a site may be silent before it counts as stalled, in seconds.
	 *
	 * Three days, which is three times the window that makes a site read as
	 * idle. The gap between the two is deliberate and is the difference between
	 * a quiet client and a broken one: a site nobody is using goes a day without
	 * calling all the time, and putting that in front of a person every morning
	 * is how the queue becomes something nobody opens. Three missed daily
	 * reports is not quiet.
	 */
	public const STALLED_SECONDS = 3 * 86400;

	/**
	 * How long an email may sit uncollected before the site is late, in seconds.
	 *
	 * Two hours. The client plugin looks for work every hour, so one missed
	 * round could be a slow cron and two is a site that is not running them.
	 * This is about collection rather than delivery: whether the email then
	 * sends is #174's question, and a site that has taken the work and failed to
	 * send it is that site's failure to report, not this one's silence.
	 */
	public const DELAYED_SECONDS = 7200;

	/**
	 * Every client site's connection, as it stands right now.
	 *
	 * The one method here that reads anything, and it is here rather than on
	 * whichever screen wanted it first so that every screen gets the same
	 * answer. The sync screen draws it as a table and Standup draws the worst of
	 * it as cards (#169); a board saying a site is fine while the screen next
	 * door says it has been silent for a week is the disagreement this exists to
	 * prevent.
	 *
	 * Two queries however many sites there are: the integrations, and one
	 * grouped count of what is waiting across all of them.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		$integrations = Integrations::all();
		$waiting      = Register::waiting_for_sites( array_column( $integrations, 'client_site_id' ) );
		$now          = bwx_forge_now();
		$rows         = array();

		foreach ( $integrations as $integration ) {
			$rows[] = self::row(
				$integration,
				$waiting[ (string) $integration['client_site_id'] ] ?? array(),
				$now
			);
		}

		return $rows;
	}

	/**
	 * Everything worth knowing about one site's connection.
	 *
	 * Pure: it is handed the record and the count of what is waiting, and works
	 * out the rest. Nothing here reads a table, which is what lets the whole of
	 * "is this site in trouble" be argued with in a unit test rather than only
	 * against a live database.
	 *
	 * @param array<string, mixed> $integration The integration record.
	 * @param array<string, mixed> $waiting     count and oldest (a Unix time), if any.
	 * @param int                  $now         Unix time.
	 * @return array<string, mixed>
	 */
	public static function row( array $integration, array $waiting, int $now ): array {
		$state = Health::state( $integration );
		$seen  = (int) ( $integration['last_seen_at'] ?? 0 );
		$count = (int) ( $waiting['count'] ?? 0 );
		$since = (int) ( $waiting['oldest'] ?? 0 );

		$reasons = self::reasons( $state, $seen, $count, $since, $now );

		return array(
			'id'              => (string) ( $integration['id'] ?? '' ),
			'client_site_id'  => (string) ( $integration['client_site_id'] ?? '' ),
			'client_id'       => (string) ( $integration['client_id'] ?? '' ),
			'state'           => $state,
			'state_label'     => Health::label( $state ),
			'last_seen_at'    => $seen,
			'last_report_at'  => (int) ( $integration['last_report_at'] ?? 0 ),
			'last_error_at'   => (int) ( $integration['last_error_at'] ?? 0 ),
			'last_error_code' => (string) ( $integration['last_error_code'] ?? '' ),
			'plugin_version'  => (string) ( $integration['plugin_version'] ?? '' ),

			// Zero rather than "for ever" when it has never called. A site that
			// has never spoken has not been silent *for* any length of time, and
			// a screen sorting by this must not put it above a real outage.
			'silent_for'      => $seen > 0 ? max( 0, $now - $seen ) : 0,
			'waiting'         => $count,
			'waiting_since'   => $since,
			'waiting_for'     => $since > 0 ? max( 0, $now - $since ) : 0,
			'reasons'         => $reasons,
			'needs_attention' => array() !== $reasons,
		);
	}

	/**
	 * Why this site is somebody's problem, or an empty list if it is not.
	 *
	 * More than one can be true and all of them are given. A site that broke and
	 * then went silent with three emails behind it is one conversation, and
	 * naming only the first thing found would send somebody to fix the smaller
	 * half of it.
	 *
	 * @param string $state   The connection's state.
	 * @param int    $seen    When it last called.
	 * @param int    $waiting How many emails it has not collected.
	 * @param int    $since   When the oldest of those was raised.
	 * @param int    $now     Unix time.
	 * @return array<int, string>
	 */
	public static function reasons( string $state, int $seen, int $waiting, int $since, int $now ): array {
		/*
		 * Two states are never anybody's problem, and both for the same reason:
		 * nothing has gone wrong. A site nobody has connected yet is waiting on
		 * a decision, and a site we cut off is doing exactly what was asked of
		 * it. Putting either in a queue of faults teaches people the queue is
		 * full of things that are fine.
		 */
		if ( in_array( $state, array( Health::UNCONFIGURED, Health::REVOKED ), true ) ) {
			return array();
		}

		$reasons = array();

		if ( Health::BROKEN === $state ) {
			$reasons[] = self::BROKEN;
		}

		if ( Health::NEVER_CONNECTED === $state ) {
			$reasons[] = self::NEVER_USED;
		}

		// Silent long enough to have missed three daily reports. Only a site
		// that has called before can be stalled: one that never has is a setup
		// that did not finish, which is a different job and already named.
		if ( $seen > 0 && $now - $seen > self::STALLED_SECONDS ) {
			$reasons[] = self::STALLED;
		}

		if ( $waiting > 0 && $since > 0 && $now - $since > self::DELAYED_SECONDS ) {
			$reasons[] = self::DELAYED;
		}

		return self::in_reason_order( $reasons );
	}

	/**
	 * How a reason reads to a person, and what it means for the client.
	 *
	 * The second half is the point. "Stalled" tells somebody in the studio what
	 * the machine is doing; "we cannot reach it, and anything it owes the client
	 * is not going out" tells them why they should stop what they are doing.
	 *
	 * @param string $reason One of the constants above.
	 * @return string
	 */
	public static function label( string $reason ): string {
		switch ( $reason ) {
			case self::BROKEN:
				return __( 'Failing — its last call to us did not work', 'blueworx-forge' );
			case self::STALLED:
				return __( 'Gone quiet — it has not called in days, and nothing it owes the client is going out', 'blueworx-forge' );
			case self::DELAYED:
				return __( 'Behind — it has email waiting that it has not collected', 'blueworx-forge' );
			case self::NEVER_USED:
				return __( 'Never connected — a key was issued and the site has never used it', 'blueworx-forge' );
			default:
				return __( 'Unknown', 'blueworx-forge' );
		}
	}

	/**
	 * What to do about it, in the order it is worth trying.
	 *
	 * A queue entry without this is a notification rather than a queue: "a
	 * criterion is that a stalled site appears with enough detail to act on",
	 * and the detail somebody is short of is not the timestamp.
	 *
	 * @param string $reason One of the constants above.
	 * @return string
	 */
	public static function what_to_do( string $reason ): string {
		switch ( $reason ) {
			case self::BROKEN:
				return __( 'Check the error below. A rejected signature usually means the key was rotated here and not on the site.', 'blueworx-forge' );
			case self::STALLED:
				return __( 'Check the site is up and the Forge client plugin is still active. WP-Cron on a quiet site only runs when somebody visits it.', 'blueworx-forge' );
			case self::DELAYED:
				return __( 'Open any Forge screen on the client site, which makes it check immediately, then look again.', 'blueworx-forge' );
			case self::NEVER_USED:
				return __( 'Finish connecting it: paste the key into the Forge client plugin on the site.', 'blueworx-forge' );
			default:
				return '';
		}
	}

	/**
	 * How bad the worst thing wrong with this site is, for sorting.
	 *
	 * A number rather than a comparison so the caller can sort a mixed list in
	 * one pass. Lower is worse, matching the declared order of the reasons.
	 *
	 * @param array<int, string> $reasons This site's reasons.
	 * @return int
	 */
	public static function severity( array $reasons ): int {
		$worst = count( self::REASONS );

		foreach ( $reasons as $reason ) {
			$at = array_search( $reason, self::REASONS, true );

			if ( false !== $at && $at < $worst ) {
				$worst = (int) $at;
			}
		}

		return $worst;
	}

	/**
	 * Only the sites somebody has to do something about, worst first.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows from {@see self::row()}.
	 * @return array<int, array<string, mixed>>
	 */
	public static function queue( array $rows ): array {
		$queue = array_values(
			array_filter( $rows, static fn( array $row ): bool => (bool) $row['needs_attention'] )
		);

		usort(
			$queue,
			static function ( array $a, array $b ): int {
				$by_severity = self::severity( $a['reasons'] ) <=> self::severity( $b['reasons'] );

				// Then longest-standing first. Among equally bad problems, the
				// one that has been true for a week is the one somebody has been
				// walking past.
				return 0 !== $by_severity
					? $by_severity
					: ( (int) $b['silent_for'] <=> (int) $a['silent_for'] );
			}
		);

		return $queue;
	}

	/**
	 * The reasons in the declared order, however they were found.
	 *
	 * @param array<int, string> $reasons Reasons.
	 * @return array<int, string>
	 */
	private static function in_reason_order( array $reasons ): array {
		return array_values( array_filter( self::REASONS, static fn( string $one ): bool => in_array( $one, $reasons, true ) ) );
	}
}
