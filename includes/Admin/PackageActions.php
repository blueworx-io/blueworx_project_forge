<?php
/**
 * What the package catalogue screen's forms do.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Admin;

use Blueworx\Forge\Commerce\Packages;
use Blueworx\Forge\Commerce\Terms;

/**
 * Writing the package catalogue (#145).
 *
 * Separate from the screen because these change state and that one does not.
 *
 * Nothing here decides anything. Whether a set of terms is an offer, and
 * whether an edit is a new version at all, are both settled in
 * {@see \Blueworx\Forge\Commerce\Terms} and {@see Packages} — a rule enforced
 * only at the screen is a rule that lasts until the second caller, and COMM-1
 * is the rule the entire commercial record rests on.
 */
final class PackageActions {

	/**
	 * Hooks the handlers up.
	 */
	public static function boot(): void {
		add_action( 'admin_post_bwx_forge_add_package', array( self::class, 'add' ) );
		add_action( 'admin_post_bwx_forge_revise_package', array( self::class, 'revise' ) );
		add_action( 'admin_post_bwx_forge_set_package_status', array( self::class, 'set_status' ) );
		add_action( 'admin_post_bwx_forge_reorder_packages', array( self::class, 'reorder' ) );
	}

	/**
	 * Adds a package.
	 */
	public static function add(): void {
		self::require_admin();
		check_admin_referer( 'bwx_forge_add_package' );

		$added = Packages::create( self::submitted(), get_current_user_id() );

		self::back( null === $added ? 'refused' : 'added' );
	}

	/**
	 * Writes the next version of one.
	 */
	public static function revise(): void {
		self::require_admin();
		check_admin_referer( 'bwx_forge_revise_package' );

		$package_id = self::text( 'package' );
		$before     = Packages::current_version( $package_id );

		if ( null === $before ) {
			self::back( 'unknown' );
		}

		$after = Packages::revise( $package_id, self::submitted(), get_current_user_id() );

		if ( null === $after ) {
			self::back( 'refused' );
		}

		/*
		 * "Saved" and "nothing to save" are different sentences, and the second
		 * one matters: somebody who has just changed a price and is told
		 * nothing changed knows immediately that they were on the wrong form.
		 */
		$now = Packages::current_version( $package_id );

		self::back(
			null !== $now && (int) $now['version'] > (int) $before['version'] ? 'revised' : 'unchanged'
		);
	}

	/**
	 * Takes one off the shelf, or puts it back.
	 */
	public static function set_status(): void {
		self::require_admin();
		check_admin_referer( 'bwx_forge_set_package_status' );

		$status  = self::text( 'status' );
		$changed = Packages::set_status( self::text( 'package' ), $status );

		if ( null === $changed ) {
			self::back( 'unknown' );
		}

		self::back( Terms::RETIRED === $status ? 'retired' : 'restored' );
	}

	/**
	 * Puts the catalogue in the submitted order.
	 */
	public static function reorder(): void {
		self::require_admin();
		check_admin_referer( 'bwx_forge_reorder_packages' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked immediately above.
		$submitted = isset( $_POST['order'] ) && is_array( $_POST['order'] ) ? wp_unslash( $_POST['order'] ) : array();
		$wanted    = array();

		foreach ( $submitted as $id => $place ) {
			$wanted[ sanitize_text_field( (string) $id ) ] = (int) $place;
		}

		// Sorted by the number somebody typed, then handed over as a plain
		// order. Two packages given the same number keep the order they had,
		// which is the only sensible reading of a tie.
		asort( $wanted, SORT_NUMERIC );

		Packages::reorder( array_keys( $wanted ) );

		self::back( 'reordered' );
	}

	/**
	 * The terms as submitted, untouched beyond WordPress's own unslashing.
	 *
	 * Cleaning up is {@see Terms::sanitise()}'s job and is not repeated here:
	 * two places that tidy the same values are two places that can come to
	 * disagree about what a valid one is.
	 *
	 * @return array<string, mixed>
	 */
	private static function submitted(): array {
		return array(
			'name'            => self::text( 'name' ),
			'hours'           => self::text( 'hours' ),
			'price'           => self::text( 'price' ),
			'currency'        => self::text( 'currency' ),
			'validity_months' => self::text( 'validity_months' ),

			// Not sanitize_text_field: the terms are a paragraph, and that
			// would fold the line breaks somebody typed into one long line.
			'terms'           => isset( $_POST['terms'] ) ? sanitize_textarea_field( wp_unslash( $_POST['terms'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- every caller checks its own nonce first.
		);
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
			wp_die( esc_html__( 'You cannot change the package catalogue.', 'blueworx-forge' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Back to the screen, saying what happened.
	 *
	 * @param string $result A result code.
	 */
	private static function back( string $result ): void {
		wp_safe_redirect( PackagesScreen::url( $result ) );
		exit;
	}
}
