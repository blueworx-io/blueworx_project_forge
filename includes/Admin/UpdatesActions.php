<?php
/**
 * What the updates screen's buttons do.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Admin;

use Blueworx\Forge\Updates;

/**
 * Saving and forgetting this site's update token (#200).
 *
 * Separate from the screen because these change state and that one does not.
 */
final class UpdatesActions {

	/**
	 * Hooks the handlers up.
	 */
	public static function boot(): void {
		add_action( 'admin_post_bwx_forge_save_update_token', array( self::class, 'save' ) );
		add_action( 'admin_post_bwx_forge_forget_update_token', array( self::class, 'forget' ) );
	}

	/**
	 * Stores a token typed into the dashboard.
	 */
	public static function save(): void {
		self::require_admin();
		check_admin_referer( 'bwx_forge_save_update_token' );

		// wp-config.php wins, and the form does not offer the field at all when
		// it is set. Refuse rather than store something that would never be
		// read, which would leave the screen disagreeing with itself.
		if ( Updates::is_fixed() ) {
			self::back( 'saved' );
		}

		$token = isset( $_POST['update_token'] ) ? sanitize_text_field( wp_unslash( $_POST['update_token'] ) ) : '';

		// An empty field means "leave the stored token alone", not "delete it":
		// the field is never filled in for editing, so an untouched form posts
		// it empty every time. Removing one is its own button.
		if ( '' === $token ) {
			self::back( '' === Updates::stored_token() ? 'empty' : 'saved' );
		}

		Updates::store( $token );

		self::back( 'saved' );
	}

	/**
	 * Forgets it.
	 */
	public static function forget(): void {
		self::require_admin();
		check_admin_referer( 'bwx_forge_forget_update_token' );

		Updates::forget();

		self::back( 'forgotten' );
	}

	/**
	 * Refuses anyone who does not administer this site.
	 *
	 * The nonce check sits in each handler rather than here, because the coding
	 * standard only recognises a nonce checked in the same function as the form
	 * data it protects.
	 */
	private static function require_admin(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You are not allowed to change this site\'s update token.', 'blueworx-forge' ),
				'',
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Returns to the screen with the outcome, and stops.
	 *
	 * @param string $result One of the result codes the screen knows.
	 */
	private static function back( string $result ): void {
		wp_safe_redirect( UpdatesScreen::url( $result ) );
		exit;
	}
}
