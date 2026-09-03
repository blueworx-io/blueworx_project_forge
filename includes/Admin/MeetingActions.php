<?php
/**
 * What the meetings screen does when somebody presses something.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Admin;

use Blueworx\Forge\Meetings\Diary;
use Blueworx\Forge\Meetings\Events;
use Blueworx\Forge\Meetings\Hours;
use Blueworx\Forge\Meetings\Occurrence;
use Blueworx\Forge\Meetings\Series;
use Blueworx\Forge\Meetings\Validate;
use Blueworx\Forge\Tenancy\ClientSites;

/**
 * #152 to #155. The four things the meetings screen can do.
 *
 * Each one settles the site's hours afterwards rather than working out the
 * ledger for itself. Marking a meeting held has to draw its hours, cancelling
 * has to give them back, and ending a series has to release everything its
 * remaining meetings were holding — and all three are the same question asked
 * of {@see Hours::reconcile_site()}, which already knows the answer. Doing it
 * per action would be three implementations of one rule.
 */
final class MeetingActions {

	/**
	 * Hooks the actions.
	 */
	public static function boot(): void {
		add_action( 'admin_post_bwx_forge_add_series', array( self::class, 'add_series' ) );
		add_action( 'admin_post_bwx_forge_end_series', array( self::class, 'end_series' ) );
		add_action( 'admin_post_bwx_forge_move_meeting', array( self::class, 'move' ) );
		add_action( 'admin_post_bwx_forge_settle_meeting', array( self::class, 'settle' ) );
	}

	/**
	 * Starts a standing meeting.
	 */
	public static function add_series(): void {
		self::require_admin();
		check_admin_referer( 'bwx_forge_add_series' );

		$site_id = self::text( 'site' );
		$site    = ClientSites::get( $site_id );

		if ( null === $site ) {
			self::back( $site_id, 'unknown' );
		}

		$checked = Validate::series(
			array(
				'client_site_id' => $site_id,
				'title'          => self::text( 'title' ),
				'frequency'      => self::text( 'frequency' ),
				'starts_on'      => self::text( 'starts_on' ),
				'ends_on'        => self::text( 'ends_on' ),
				'time_of_day'    => self::text( 'time_of_day' ),
				'duration_mins'  => (int) self::text( 'duration_mins' ),
				'timezone'       => self::text( 'timezone' ),
				'host_user_id'   => self::text( 'host_user_id' ),
				'attendees'      => self::text( 'attendees' ),
				'planned_hours'  => (float) self::text( 'planned_hours' ),
			)
		);

		if ( array() !== $checked['errors'] ) {
			self::back( $site_id, 'invalid' );
		}

		$created = Series::create( $checked['values'], (string) $site['client_id'], get_current_user_id() );

		if ( null === $created ) {
			self::back( $site_id, 'refused' );
		}

		Hours::reconcile_site( $site_id, get_current_user_id() );
		self::back( $site_id, 'added' );
	}

	/**
	 * Stops one.
	 */
	public static function end_series(): void {
		self::require_admin();
		check_admin_referer( 'bwx_forge_end_series' );

		$site_id = self::text( 'site' );
		$series  = Series::get( self::text( 'series' ) );

		if ( null === $series || (string) $series['client_site_id'] !== $site_id ) {
			self::back( $site_id, 'refused' );
		}

		$ended = Series::end( (string) $series['id'], (int) self::text( 'record_version' ) );

		/*
		 * Settled after ending, which is what gives back the hours its
		 * remaining meetings were holding. Ending a series and leaving a
		 * client's balance committed to meetings that will never happen is the
		 * failure this line exists to stop.
		 */
		Hours::reconcile_site( $site_id, get_current_user_id() );

		self::back( $site_id, $ended ? 'ended' : 'refused' );
	}

	/**
	 * Moves one meeting.
	 */
	public static function move(): void {
		self::require_admin();
		check_admin_referer( 'bwx_forge_move_meeting' );

		$site_id = self::text( 'site' );
		$series  = self::series_on_site( self::text( 'series' ), $site_id );
		$slot    = self::text( 'slot' );
		$to      = self::text( 'on' );

		if ( null === $series || '' === $slot || '' === $to ) {
			self::back( $site_id, 'refused' );
		}

		$moved = Diary::except(
			$series,
			$slot,
			array( 'on' => $to ),
			Events::MOVED,
			get_current_user_id()
		);

		Hours::reconcile_site( $site_id, get_current_user_id() );

		self::back( $site_id, null === $moved ? 'refused' : 'moved' );
	}

	/**
	 * Says what became of one meeting.
	 */
	public static function settle(): void {
		self::require_admin();
		check_admin_referer( 'bwx_forge_settle_meeting' );

		$site_id = self::text( 'site' );
		$series  = self::series_on_site( self::text( 'series' ), $site_id );
		$slot    = self::text( 'slot' );
		$status  = self::text( 'status' );

		if ( null === $series || '' === $slot || ! Occurrence::exists( $status ) ) {
			self::back( $site_id, 'refused' );
		}

		$actions = array(
			Occurrence::HELD      => Events::HELD,
			Occurrence::CANCELLED => Events::CANCELLED,
			Occurrence::NO_SHOW   => Events::NO_SHOW,
			Occurrence::SCHEDULED => Events::REINSTATED,
		);

		$settled = Diary::except(
			$series,
			$slot,
			array( 'status' => $status ),
			$actions[ $status ],
			get_current_user_id()
		);

		Hours::reconcile_site( $site_id, get_current_user_id() );

		self::back( $site_id, null === $settled ? 'refused' : $status );
	}

	/**
	 * One series, if it is this site's.
	 *
	 * The site is checked rather than trusted from the form: the id comes off a
	 * posted field, and a series belonging to another client would otherwise be
	 * editable by anybody who could edit the request (ARCH-3).
	 *
	 * @param string $series_id The series.
	 * @param string $site_id   The site it should belong to.
	 * @return array<string, mixed>|null
	 */
	private static function series_on_site( string $series_id, string $site_id ): ?array {
		$series = Series::get( $series_id );

		if ( null === $series || (string) $series['client_site_id'] !== $site_id ) {
			return null;
		}

		return $series;
	}

	/**
	 * Refuses anybody who may not be here.
	 */
	private static function require_admin(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot do that.', 'blueworx-forge' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * One posted field, cleaned.
	 *
	 * @param string $name Field name.
	 * @return string
	 */
	private static function text( string $name ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- every caller checks its own nonce first.
		return isset( $_POST[ $name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $name ] ) ) : '';
	}

	/**
	 * Back to the screen, with the outcome on the URL.
	 *
	 * @param string $site_id The site.
	 * @param string $result  What happened.
	 */
	private static function back( string $site_id, string $result ): void {
		wp_safe_redirect( MeetingsScreen::url( $site_id, $result ) );
		exit;
	}
}
