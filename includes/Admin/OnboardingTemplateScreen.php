<?php
/**
 * The launch checklist, and the versions of it.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Admin;

use Blueworx\Forge\Onboarding\Sections;
use Blueworx\Forge\Onboarding\TemplateSteps;
use Blueworx\Forge\Onboarding\Templates;
use Blueworx\Forge\Onboarding\Version1;

/**
 * Reading and versioning the onboarding checklist (#159).
 *
 * ARCH-7 puts it here rather than in the React application: a template is
 * configuration — the studio writes it once and rarely touches it again —
 * rather than work anybody does daily.
 *
 * The screen exists to make ONB-E2 visible. A published version has no editing
 * controls at all, because there is no way to edit one; what it offers instead
 * is a copy to work on. Somebody who has read this screen should come away
 * knowing that issued checklists do not move.
 */
final class OnboardingTemplateScreen {

	/**
	 * The submenu page slug.
	 */
	public const SLUG = 'blueworx-forge-onboarding-template';

	/**
	 * Adds the menu entry, under the Forge menu.
	 */
	public static function register(): void {
		add_submenu_page(
			SitesScreen::SLUG,
			__( 'Onboarding template', 'blueworx-forge' ),
			__( 'Onboarding template', 'blueworx-forge' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * This screen's URL, for one version and optionally a result.
	 *
	 * @param string $template_id The version being looked at, or ''.
	 * @param string $result      A result code, or ''.
	 * @return string
	 */
	public static function url( string $template_id = '', string $result = '' ): string {
		$url = admin_url( 'admin.php?page=' . self::SLUG );

		if ( '' !== $template_id ) {
			$url = add_query_arg( 'template', $template_id, $url );
		}

		return '' === $result ? $url : add_query_arg( 'bwx-result', $result, $url );
	}

	/**
	 * Renders the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Forge — onboarding template', 'blueworx-forge' ) . '</h1>';
		echo '<p>' . esc_html__( 'The launch checklist every client is given. Once a version is published it never changes again, so a client working through theirs is never rewritten underneath them. To change it, open a copy and publish that as the next version.', 'blueworx-forge' ) . '</p>';

		self::result_notice();

		$versions = Templates::all();

		if ( array() === $versions ) {
			self::nothing_yet();
			echo '</div>';

			return;
		}

		$chosen = self::chosen_version( $versions );

		self::version_list( $versions, $chosen );
		self::version_detail( $chosen );

		echo '</div>';
	}

	/**
	 * What to say when no version exists at all.
	 *
	 * Which, today, is every install: the starter checklist is waiting on the
	 * rest of its categories (Onboarding\Version1). Saying so plainly beats an
	 * empty screen that looks broken.
	 */
	private static function nothing_yet(): void {
		echo '<p data-bwx-no-template="1">';

		if ( Version1::READY ) {
			echo esc_html__( 'No checklist yet. Start one below.', 'blueworx-forge' );
		} else {
			echo esc_html__( 'The starter checklist is not finished yet — some of its categories have still to be written. You can start a version of your own in the meantime.', 'blueworx-forge' );
		}

		echo '</p>';

		self::start_draft_form();
	}

	/**
	 * Every version, and which one is being looked at.
	 *
	 * @param array<int, array<string, mixed>> $versions All of them.
	 * @param array<string, mixed>             $chosen   The one on screen.
	 */
	private static function version_list( array $versions, array $chosen ): void {
		echo '<h2>' . esc_html__( 'Versions', 'blueworx-forge' ) . '</h2>';
		echo '<table class="widefat striped" data-bwx-versions="1"><thead><tr>';
		echo '<th>' . esc_html__( 'Version', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Name', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'State', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Steps', 'blueworx-forge' ) . '</th>';
		echo '<th></th></tr></thead><tbody>';

		foreach ( $versions as $version ) {
			$is_draft = Templates::may_edit( $version );

			printf(
				'<tr data-bwx-version="%1$s" data-bwx-state="%2$s"%3$s>',
				esc_attr( (string) $version['id'] ),
				esc_attr( (string) $version['status'] ),
				(string) $version['id'] === (string) $chosen['id'] ? ' class="bwx-chosen"' : ''
			);

			echo '<td>' . ( $is_draft ? esc_html__( 'Draft', 'blueworx-forge' ) : esc_html( (string) $version['version'] ) ) . '</td>';
			echo '<td>' . esc_html( (string) $version['name'] ) . '</td>';
			echo '<td>' . esc_html( $is_draft ? __( 'Being written', 'blueworx-forge' ) : __( 'Issued — cannot change', 'blueworx-forge' ) ) . '</td>';
			echo '<td>' . esc_html( (string) count( TemplateSteps::for_template( (string) $version['id'] ) ) ) . '</td>';
			echo '<td><a href="' . esc_url( self::url( (string) $version['id'] ) ) . '">' . esc_html__( 'Look at it', 'blueworx-forge' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * One version, its steps, and what may be done to it.
	 *
	 * @param array<string, mixed> $version The version.
	 */
	private static function version_detail( array $version ): void {
		$is_draft = Templates::may_edit( $version );

		printf(
			'<h2 data-bwx-template-name="1" data-bwx-state="%1$s">%2$s</h2>',
			esc_attr( (string) $version['status'] ),
			esc_html( (string) $version['name'] )
		);

		if ( ! $is_draft ) {
			echo '<p>' . esc_html__( 'This version has been issued, so it cannot be changed. Open a copy to make the next one.', 'blueworx-forge' ) . '</p>';
			self::copy_form( $version );
		}

		self::steps( $version, $is_draft );

		if ( $is_draft ) {
			self::add_step_form( $version );
			self::publish_form( $version );
		}
	}

	/**
	 * A version's steps, grouped the way somebody works through them.
	 *
	 * @param array<string, mixed> $version  The version.
	 * @param bool                 $editable Whether it may still be changed.
	 */
	private static function steps( array $version, bool $editable ): void {
		$steps = TemplateSteps::for_template( (string) $version['id'] );

		if ( array() === $steps ) {
			echo '<p data-bwx-no-steps="1">' . esc_html__( 'No steps in this version yet.', 'blueworx-forge' ) . '</p>';

			return;
		}

		foreach ( Sections::ALL as $section ) {
			$in_section = array_values(
				array_filter(
					$steps,
					static function ( array $step ) use ( $section ): bool {
						return (string) $step['section'] === $section;
					}
				)
			);

			if ( array() === $in_section ) {
				continue;
			}

			echo '<h3>' . esc_html( Sections::label( $section ) ) . '</h3>';
			echo '<table class="widefat striped" data-bwx-steps="' . esc_attr( $section ) . '"><thead><tr>';
			echo '<th>' . esc_html__( 'Step', 'blueworx-forge' ) . '</th>';
			echo '<th>' . esc_html__( 'Category', 'blueworx-forge' ) . '</th>';
			echo '<th>' . esc_html__( 'Who', 'blueworx-forge' ) . '</th>';
			echo '<th>' . esc_html__( 'Needed to launch', 'blueworx-forge' ) . '</th>';
			echo '<th></th></tr></thead><tbody>';

			foreach ( $in_section as $step ) {
				echo '<tr data-bwx-step="' . esc_attr( (string) $step['id'] ) . '">';
				echo '<td><strong>' . esc_html( (string) $step['title'] ) . '</strong><br>' . esc_html( (string) $step['description'] ) . '</td>';
				echo '<td>' . esc_html( (string) $step['category'] ) . '</td>';
				echo '<td>' . esc_html( TemplateSteps::CLIENT === (string) $step['owner_side'] ? __( 'Client', 'blueworx-forge' ) : __( 'Us', 'blueworx-forge' ) ) . '</td>';
				echo '<td data-bwx-launch-critical="' . esc_attr( $step['launch_critical'] ? '1' : '0' ) . '">' . esc_html( $step['launch_critical'] ? __( 'Yes', 'blueworx-forge' ) : __( 'No', 'blueworx-forge' ) ) . '</td>';
				echo '<td>';

				if ( $editable ) {
					self::remove_step_form( $version, $step );
				}

				echo '</td></tr>';
			}

			echo '</tbody></table>';
		}
	}

	/**
	 * The form that starts a fresh, empty draft.
	 */
	private static function start_draft_form(): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-bwx-start-draft="1">';
		wp_nonce_field( 'bwx_forge_start_template' );
		echo '<input type="hidden" name="action" value="bwx_forge_start_template">';
		echo '<p><label for="bwx-template-name">' . esc_html__( 'Name', 'blueworx-forge' ) . '</label> ';
		echo '<input type="text" id="bwx-template-name" name="name" value="' . esc_attr( Version1::NAME ) . '" class="regular-text"></p>';
		submit_button( __( 'Start a checklist', 'blueworx-forge' ) );
		echo '</form>';
	}

	/**
	 * The form that opens a published version as a new draft.
	 *
	 * @param array<string, mixed> $version The version being copied.
	 */
	private static function copy_form( array $version ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-bwx-copy-template="1">';
		wp_nonce_field( 'bwx_forge_copy_template' );
		echo '<input type="hidden" name="action" value="bwx_forge_copy_template">';
		echo '<input type="hidden" name="template" value="' . esc_attr( (string) $version['id'] ) . '">';
		submit_button( __( 'Open a copy to edit', 'blueworx-forge' ), 'secondary' );
		echo '</form>';
	}

	/**
	 * The form that publishes a draft as the next version.
	 *
	 * @param array<string, mixed> $version The draft.
	 */
	private static function publish_form( array $version ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-bwx-publish-template="1">';
		wp_nonce_field( 'bwx_forge_publish_template' );
		echo '<input type="hidden" name="action" value="bwx_forge_publish_template">';
		echo '<input type="hidden" name="template" value="' . esc_attr( (string) $version['id'] ) . '">';
		echo '<p>' . esc_html__( 'Publishing issues this as the next version. After that it can never be changed — every client given it sees exactly this.', 'blueworx-forge' ) . '</p>';
		submit_button( __( 'Publish this version', 'blueworx-forge' ) );
		echo '</form>';
	}

	/**
	 * The form that adds a step to a draft.
	 *
	 * @param array<string, mixed> $version The draft.
	 */
	private static function add_step_form( array $version ): void {
		echo '<h3>' . esc_html__( 'Add a step', 'blueworx-forge' ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-bwx-add-step="1">';
		wp_nonce_field( 'bwx_forge_add_template_step' );
		echo '<input type="hidden" name="action" value="bwx_forge_add_template_step">';
		echo '<input type="hidden" name="template" value="' . esc_attr( (string) $version['id'] ) . '">';

		echo '<table class="form-table"><tbody>';

		echo '<tr><th><label for="bwx-step-title">' . esc_html__( 'Step', 'blueworx-forge' ) . '</label></th>';
		echo '<td><input type="text" id="bwx-step-title" name="title" class="regular-text" required></td></tr>';

		echo '<tr><th><label for="bwx-step-section">' . esc_html__( 'When', 'blueworx-forge' ) . '</label></th><td><select id="bwx-step-section" name="section">';

		foreach ( Sections::ALL as $section ) {
			printf(
				'<option value="%1$s">%2$s</option>',
				esc_attr( $section ),
				esc_html( Sections::label( $section ) )
			);
		}

		echo '</select></td></tr>';

		echo '<tr><th><label for="bwx-step-category">' . esc_html__( 'Category', 'blueworx-forge' ) . '</label></th>';
		echo '<td><input type="text" id="bwx-step-category" name="category" class="regular-text"></td></tr>';

		echo '<tr><th><label for="bwx-step-description">' . esc_html__( 'What to do', 'blueworx-forge' ) . '</label></th>';
		echo '<td><textarea id="bwx-step-description" name="description" class="large-text" rows="3"></textarea></td></tr>';

		echo '<tr><th><label for="bwx-step-owner">' . esc_html__( 'Who does it', 'blueworx-forge' ) . '</label></th><td><select id="bwx-step-owner" name="owner_side">';
		echo '<option value="' . esc_attr( TemplateSteps::CLIENT ) . '">' . esc_html__( 'The client', 'blueworx-forge' ) . '</option>';
		echo '<option value="' . esc_attr( TemplateSteps::INTERNAL ) . '">' . esc_html__( 'Us', 'blueworx-forge' ) . '</option>';
		echo '</select></td></tr>';

		echo '<tr><th>' . esc_html__( 'Needed to launch', 'blueworx-forge' ) . '</th>';
		echo '<td><label><input type="checkbox" id="bwx-step-launch-critical" name="launch_critical" value="1"> ';
		echo esc_html__( 'A site cannot go live until this is approved', 'blueworx-forge' ) . '</label></td></tr>';

		echo '<tr><th>' . esc_html__( 'May be skipped', 'blueworx-forge' ) . '</th>';
		echo '<td><label><input type="checkbox" id="bwx-step-allows-na" name="allows_not_applicable" value="1"> ';
		echo esc_html__( 'Can be marked not applicable, with a reason', 'blueworx-forge' ) . '</label></td></tr>';

		echo '<tr><th><label for="bwx-step-position">' . esc_html__( 'Order', 'blueworx-forge' ) . '</label></th>';
		echo '<td><input type="number" id="bwx-step-position" name="position" value="0" class="small-text"></td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Add the step', 'blueworx-forge' ) );
		echo '</form>';
	}

	/**
	 * The form that takes a step out of a draft.
	 *
	 * @param array<string, mixed> $version The draft.
	 * @param array<string, mixed> $step    The step.
	 */
	private static function remove_step_form( array $version, array $step ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-bwx-remove-step="1">';
		wp_nonce_field( 'bwx_forge_remove_template_step' );
		echo '<input type="hidden" name="action" value="bwx_forge_remove_template_step">';
		echo '<input type="hidden" name="template" value="' . esc_attr( (string) $version['id'] ) . '">';
		echo '<input type="hidden" name="step" value="' . esc_attr( (string) $step['id'] ) . '">';
		submit_button( __( 'Remove', 'blueworx-forge' ), 'link-delete small', 'submit', false );
		echo '</form>';
	}

	/**
	 * The version being looked at — the one asked for, or the newest.
	 *
	 * @param array<int, array<string, mixed>> $versions All of them.
	 * @return array<string, mixed>
	 */
	private static function chosen_version( array $versions ): array {
		$asked = isset( $_GET['template'] ) ? sanitize_text_field( wp_unslash( $_GET['template'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- choosing which version to look at changes nothing.

		foreach ( $versions as $version ) {
			if ( (string) $version['id'] === $asked ) {
				return $version;
			}
		}

		return $versions[0];
	}

	/**
	 * The outcome of the last action, if there was one.
	 */
	private static function result_notice(): void {
		// Chosen from the fixed list below, never free text: it comes off the
		// URL, so anything it can say is something anyone can make an
		// administrator's screen say.
		$result = isset( $_GET['bwx-result'] ) ? sanitize_key( wp_unslash( $_GET['bwx-result'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reporting the outcome of an action that carried its own nonce.

		$messages = array(
			'draft-started' => array( 'success', __( 'Draft started. Add its steps, then publish it.', 'blueworx-forge' ) ),
			'copy-opened'   => array( 'success', __( 'A copy is open as a draft. The issued version is untouched.', 'blueworx-forge' ) ),
			'step-added'    => array( 'success', __( 'Step added.', 'blueworx-forge' ) ),
			'step-removed'  => array( 'success', __( 'Step removed.', 'blueworx-forge' ) ),
			'published'     => array( 'success', __( 'Published. This version can no longer be changed.', 'blueworx-forge' ) ),
			'needs-title'   => array( 'error', __( 'A step needs something to call it.', 'blueworx-forge' ) ),
			'not-a-draft'   => array( 'error', __( 'That version has been issued, so it cannot be changed. Open a copy instead.', 'blueworx-forge' ) ),
			'unknown'       => array( 'error', __( 'That version could not be found.', 'blueworx-forge' ) ),
		);

		if ( ! isset( $messages[ $result ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s" data-bwx-result="%2$s"><p>%3$s</p></div>',
			esc_attr( $messages[ $result ][0] ),
			esc_attr( $result ),
			esc_html( $messages[ $result ][1] )
		);
	}
}
