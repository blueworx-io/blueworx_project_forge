<?php
/**
 * What the onboarding template screen's forms do.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Admin;

use Blueworx\Forge\Onboarding\Sections;
use Blueworx\Forge\Onboarding\TemplateSteps;
use Blueworx\Forge\Onboarding\Templates;

/**
 * Writing the launch checklist (#159).
 *
 * Separate from the screen because these change state and that one does not.
 *
 * Every handler here can be refused by Onboarding\Templates as well: a step
 * added to an issued version is turned away there, not only here. That is
 * deliberate — ONB-E2 is the rule the client's whole snapshot rests on, and a
 * rule enforced only at the screen is a rule that lasts until the second
 * caller.
 */
final class OnboardingTemplateActions {

	/**
	 * Hooks the handlers up.
	 */
	public static function boot(): void {
		add_action( 'admin_post_bwx_forge_start_template', array( self::class, 'start' ) );
		add_action( 'admin_post_bwx_forge_copy_template', array( self::class, 'copy' ) );
		add_action( 'admin_post_bwx_forge_add_template_step', array( self::class, 'add_step' ) );
		add_action( 'admin_post_bwx_forge_remove_template_step', array( self::class, 'remove_step' ) );
		add_action( 'admin_post_bwx_forge_publish_template', array( self::class, 'publish' ) );
	}

	/**
	 * Starts a fresh, empty draft.
	 */
	public static function start(): void {
		self::require_admin();
		check_admin_referer( 'bwx_forge_start_template' );

		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';

		$draft = Templates::create_draft( $name, get_current_user_id() );

		self::back( null === $draft ? '' : (string) $draft['id'], null === $draft ? 'unknown' : 'draft-started' );
	}

	/**
	 * Opens a published version as a new draft.
	 */
	public static function copy(): void {
		self::require_admin();
		check_admin_referer( 'bwx_forge_copy_template' );

		$template_id = isset( $_POST['template'] ) ? sanitize_text_field( wp_unslash( $_POST['template'] ) ) : '';
		$template    = Templates::get( $template_id );

		if ( null === $template ) {
			self::back( '', 'unknown' );
		}

		$draft = Templates::create_draft( (string) $template['name'], get_current_user_id(), $template_id );

		self::back( null === $draft ? $template_id : (string) $draft['id'], null === $draft ? 'unknown' : 'copy-opened' );
	}

	/**
	 * Adds a step to a draft.
	 */
	public static function add_step(): void {
		self::require_admin();
		check_admin_referer( 'bwx_forge_add_template_step' );

		$template_id = isset( $_POST['template'] ) ? sanitize_text_field( wp_unslash( $_POST['template'] ) ) : '';
		$template    = Templates::get( $template_id );

		if ( null === $template ) {
			self::back( '', 'unknown' );
		}

		if ( ! Templates::may_edit( $template ) ) {
			self::back( $template_id, 'not-a-draft' );
		}

		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';

		if ( '' === trim( $title ) ) {
			self::back( $template_id, 'needs-title' );
		}

		$section = isset( $_POST['section'] ) ? sanitize_key( wp_unslash( $_POST['section'] ) ) : '';

		$added = TemplateSteps::add(
			$template_id,
			array(
				'section'               => Sections::exists( $section ) ? $section : Sections::FOUNDATIONS,
				'category'              => isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : '',
				'title'                 => $title,
				'description'           => isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '',
				'owner_side'            => isset( $_POST['owner_side'] ) ? sanitize_key( wp_unslash( $_POST['owner_side'] ) ) : TemplateSteps::CLIENT,
				'launch_critical'       => isset( $_POST['launch_critical'] ) ? 1 : 0,
				'allows_not_applicable' => isset( $_POST['allows_not_applicable'] ) ? 1 : 0,
				'position'              => isset( $_POST['position'] ) ? (int) sanitize_text_field( wp_unslash( $_POST['position'] ) ) : 0,
			),
			get_current_user_id()
		);

		self::back( $template_id, null === $added ? 'not-a-draft' : 'step-added' );
	}

	/**
	 * Takes a step out of a draft.
	 */
	public static function remove_step(): void {
		self::require_admin();
		check_admin_referer( 'bwx_forge_remove_template_step' );

		$template_id = isset( $_POST['template'] ) ? sanitize_text_field( wp_unslash( $_POST['template'] ) ) : '';
		$step_id     = isset( $_POST['step'] ) ? sanitize_text_field( wp_unslash( $_POST['step'] ) ) : '';

		$removed = TemplateSteps::remove( $step_id );

		self::back( $template_id, $removed ? 'step-removed' : 'not-a-draft' );
	}

	/**
	 * Issues a draft as the next version.
	 */
	public static function publish(): void {
		self::require_admin();
		check_admin_referer( 'bwx_forge_publish_template' );

		$template_id = isset( $_POST['template'] ) ? sanitize_text_field( wp_unslash( $_POST['template'] ) ) : '';

		if ( null === Templates::get( $template_id ) ) {
			self::back( '', 'unknown' );
		}

		$published = Templates::publish( $template_id, get_current_user_id() );

		self::back( $template_id, null === $published ? 'not-a-draft' : 'published' );
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
				esc_html__( 'You are not allowed to change the onboarding template.', 'blueworx-forge' ),
				'',
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Returns to the screen with the outcome, and stops.
	 *
	 * @param string $template_id Which version's screen to return to.
	 * @param string $result      One of the result codes the screen knows.
	 */
	private static function back( string $template_id, string $result ): void {
		wp_safe_redirect( OnboardingTemplateScreen::url( $template_id, $result ) );
		exit;
	}
}
