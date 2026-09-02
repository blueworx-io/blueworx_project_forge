<?php
/**
 * What the support screen's forms do.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Admin;

use Blueworx\Forge\Commerce\Assignments;
use Blueworx\Forge\Commerce\Packages;
use Blueworx\Forge\Commerce\ProRata;
use Blueworx\Forge\Commerce\Support;
use Blueworx\Forge\Tenancy\ClientSites;

/**
 * Changing a site's commercial position (#146).
 *
 * Separate from the screen because these change state and that one does not.
 *
 * The one piece of arithmetic here is the pro-rata sum, and it is not arithmetic
 * so much as a call: {@see ProRata::preview()} produces the figure, and the
 * figure is handed to the assignment unchanged. Working the hours out again on
 * the way in would be a second calculation, which is precisely what #147 exists
 * to prevent — the preview somebody agreed to and the allocation the ledger
 * receives have to be the same number, and the only way to be sure is for there
 * to be one of them.
 */
final class SupportActions {

	/**
	 * Hooks the handlers up.
	 */
	public static function boot(): void {
		add_action( 'admin_post_bwx_forge_assign_support', array( self::class, 'assign' ) );
		add_action( 'admin_post_bwx_forge_suspend_support', array( self::class, 'suspend' ) );
		add_action( 'admin_post_bwx_forge_resume_support', array( self::class, 'resume' ) );
		add_action( 'admin_post_bwx_forge_cancel_support', array( self::class, 'cancel' ) );
	}

	/**
	 * Puts a site on a package.
	 */
	public static function assign(): void {
		self::require_admin();
		check_admin_referer( 'bwx_forge_assign_support' );

		$site_id = self::text( 'site' );
		$site    = ClientSites::get( $site_id );
		$version = Packages::version( self::text( 'package_version' ) );
		$from    = self::text( 'starts_on' );
		$until   = self::text( 'ends_on' );

		if ( null === $site || null === $version || '' === $from ) {
			self::back( $site_id, null === $site ? 'unknown' : 'refused' );
		}

		$values = array(
			'client_site_id'     => $site_id,
			'client_id'          => (string) $site['client_id'],
			'package_version_id' => (string) $version['id'],
			'starts_on'          => $from,
			'note'               => self::text( 'note' ),
		);

		/*
		 * COMM-1: an ordinary assignment starts its own twelve-month term and
		 * gets the whole package. A date here means the client asked to align
		 * with a shared renewal, which is the only case pro-rata applies to —
		 * so the sum happens exactly when somebody asked for it and never
		 * quietly because two dates did not make a round year.
		 */
		if ( '' !== $until ) {
			$sum = ProRata::preview( $version, $from, $until );

			$values['ends_on']       = $until;
			$values['hours_granted'] = (float) $sum['hours'];
			$values['price_charged'] = (int) $sum['price'];
			$values['prorated']      = true;
		}

		$assigned = Assignments::assign( $values, get_current_user_id() );

		self::back( $site_id, null === $assigned ? 'refused' : 'assigned' );
	}

	/**
	 * Stops a site's cover.
	 */
	public static function suspend(): void {
		self::require_admin();
		check_admin_referer( 'bwx_forge_suspend_support' );

		$site_id = self::text( 'site' );
		$stopped = Assignments::suspend( $site_id, self::from(), get_current_user_id() );

		self::back( $site_id, null === $stopped ? 'refused' : 'suspended' );
	}

	/**
	 * Puts it back.
	 */
	public static function resume(): void {
		self::require_admin();
		check_admin_referer( 'bwx_forge_resume_support' );

		$site_id = self::text( 'site' );
		$back    = Assignments::resume( $site_id, self::from(), get_current_user_id() );

		self::back( $site_id, null === $back ? 'refused' : 'resumed' );
	}

	/**
	 * Ends it.
	 */
	public static function cancel(): void {
		self::require_admin();
		check_admin_referer( 'bwx_forge_cancel_support' );

		$site_id = self::text( 'site' );
		$done    = Assignments::cancel( $site_id, self::from(), get_current_user_id(), Support::CANCELLED );

		self::back( $site_id, $done ? 'cancelled' : 'refused' );
	}

	/**
	 * The date an action takes effect from, defaulting to today.
	 *
	 * @return string YYYY-MM-DD.
	 */
	private static function from(): string {
		$from = self::text( 'from' );

		return '' === $from ? gmdate( 'Y-m-d', bwx_forge_now() ) : $from;
	}

	/**
	 * One posted field.
	 *
	 * @param string $name Field name.
	 * @return string
	 */
	private static function text( string $name ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- every caller checks its own nonce first.
		return isset( $_POST[ $name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $name ] ) ) : '';
	}

	/**
	 * Refuses anybody who may not administer the studio.
	 */
	private static function require_admin(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot change a site\'s support package.', 'blueworx-forge' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Back to the screen, saying what happened.
	 *
	 * @param string $site_id The site being looked at.
	 * @param string $result  A result code.
	 */
	private static function back( string $site_id, string $result ): void {
		wp_safe_redirect( SupportScreen::url( $site_id, $result ) );
		exit;
	}
}
