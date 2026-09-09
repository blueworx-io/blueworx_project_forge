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
 *
 * That is why the panels a draft gets — "Add a step", "Publish this version" —
 * and the Remove control on every row are drawn only when the version on screen
 * is a draft. A published version's one control is the copy form, and that
 * asymmetry is the point rather than an omission.
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

		Page::open(
			__( 'Onboarding template', 'blueworx-forge' ),
			__( 'Forge', 'blueworx-forge' ),
			__( 'The launch checklist every client is given. Once a version is published it never changes again, so a client working through theirs is never rewritten underneath them. To change it, open a copy and publish that as the next version.', 'blueworx-forge' )
		);

		self::result_notice();

		$versions = Templates::all();

		if ( array() === $versions ) {
			self::nothing_yet();
			Page::close();

			return;
		}

		$chosen = self::chosen_version( $versions );

		self::version_list( $versions, $chosen );
		self::version_detail( $chosen );

		Page::close();
	}

	/**
	 * What to say when no version exists at all.
	 *
	 * Which, today, is every install: the starter checklist is waiting on the
	 * rest of its categories (Onboarding\Version1). Saying so plainly beats an
	 * empty screen that looks broken.
	 */
	private static function nothing_yet(): void {
		Page::panel_open( __( 'Versions', 'blueworx-forge' ), 'versions' );

		echo '<div class="bw-empty" data-bwx-no-template="1">';
		echo '<i class="bw-icon bw-empty__icon" data-lucide="list-checks"></i>';
		echo '<p class="bw-empty__title">' . esc_html__( 'No checklist yet', 'blueworx-forge' ) . '</p>';
		echo '<p class="bw-empty__text">';

		if ( Version1::READY ) {
			echo esc_html__( 'Start one below.', 'blueworx-forge' );
		} else {
			echo esc_html__( 'The starter checklist is not finished yet — some of its categories have still to be written. You can start a version of your own in the meantime.', 'blueworx-forge' );
		}

		echo '</p>';
		echo '</div>';

		Page::panel_close();

		self::start_draft_form();
	}

	/**
	 * Every version, and which one is being looked at.
	 *
	 * Still one row per version rather than a card each: the row count is what
	 * proves a copy left the original standing, and a table is the shape that
	 * says "these are the same kind of thing, in order".
	 *
	 * @param array<int, array<string, mixed>> $versions All of them.
	 * @param array<string, mixed>             $chosen   The one on screen.
	 */
	private static function version_list( array $versions, array $chosen ): void {
		Page::panel_open( __( 'Versions', 'blueworx-forge' ), 'versions' );

		echo '<div class="bw-tablescroll">';
		echo '<table class="bw-table" data-bwx-versions="1"><thead><tr>';
		echo '<th>' . esc_html__( 'Version', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Name', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'State', 'blueworx-forge' ) . '</th>';
		echo '<th class="bw-table__num">' . esc_html__( 'Steps', 'blueworx-forge' ) . '</th>';
		echo '<th class="bw-table__actions"></th></tr></thead><tbody>';

		foreach ( $versions as $version ) {
			$is_draft = Templates::may_edit( $version );

			printf(
				'<tr data-bwx-version="%1$s" data-bwx-state="%2$s"%3$s>',
				esc_attr( (string) $version['id'] ),
				esc_attr( (string) $version['status'] ),
				// Said rather than only coloured: which row is on screen has to
				// reach somebody who cannot see the highlight.
				(string) $version['id'] === (string) $chosen['id'] ? ' aria-current="true"' : ''
			);

			echo '<td class="bw-table__primary">' . ( $is_draft ? esc_html__( 'Draft', 'blueworx-forge' ) : esc_html( (string) $version['version'] ) ) . '</td>';
			echo '<td>' . esc_html( (string) $version['name'] ) . '</td>';
			echo '<td>' . self::state_badge( $is_draft ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- state_badge writes whole class names and escapes its label.
			echo '<td class="bw-table__num">' . esc_html( (string) count( TemplateSteps::for_template( (string) $version['id'] ) ) ) . '</td>';
			echo '<td class="bw-table__actions"><div class="bw-rowactions">';
			echo '<a class="bw-rowactions__link" href="' . esc_url( self::url( (string) $version['id'] ) ) . '">' . esc_html__( 'Look at it', 'blueworx-forge' ) . '</a>';
			echo '</div></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';

		Page::panel_close();
	}

	/**
	 * A version's state, as the badge that says whether it can still move.
	 *
	 * Whole class names rather than a stem with a tone appended, so the admin
	 * UI check can read them.
	 *
	 * @param bool $is_draft Whether the version may still be edited.
	 * @return string
	 */
	private static function state_badge( bool $is_draft ): string {
		if ( $is_draft ) {
			return '<span class="bw-badge bw-badge--neutral">' . esc_html__( 'Being written', 'blueworx-forge' ) . '</span>';
		}

		return '<span class="bw-badge">' . esc_html__( 'Issued — cannot change', 'blueworx-forge' ) . '</span>';
	}

	/**
	 * One version, its steps, and what may be done to it.
	 *
	 * @param array<string, mixed> $version The version.
	 */
	private static function version_detail( array $version ): void {
		$is_draft = Templates::may_edit( $version );

		/*
		 * The card is opened by hand rather than through Page::panel_open,
		 * because the heading carries the two hooks that say which version is
		 * on screen and whether it may still be changed. Same markup the shell
		 * writes, with those attributes added.
		 */
		printf(
			'<section class="bw-card" data-bwx-panel="version"><div class="bw-card__head"><div class="bw-card__titles"><p class="bw-card__eyebrow">%1$s</p><h2 class="bw-card__title" data-bwx-template-name="1" data-bwx-state="%2$s">%3$s</h2></div></div><div class="bw-card__body">',
			esc_html( $is_draft ? __( 'Draft', 'blueworx-forge' ) : __( 'Issued version', 'blueworx-forge' ) ),
			esc_attr( (string) $version['status'] ),
			esc_html( (string) $version['name'] )
		);

		if ( ! $is_draft ) {
			Page::notice(
				'info',
				__( 'This version has been issued, so it cannot be changed. Open a copy to make the next one.', 'blueworx-forge' )
			);

			self::copy_form( $version );
		}

		self::steps( $version, $is_draft );

		echo '</div></section>';

		if ( ! $is_draft ) {
			return;
		}

		self::add_step_form( $version );
		self::publish_form( $version );
	}

	/**
	 * A version's steps, grouped the way somebody works through them.
	 *
	 * One table with a group row per section rather than a table per section:
	 * the columns line up across the whole checklist that way, which is how
	 * somebody reads down it. data-bwx-steps rides on the group row, which is
	 * now the thing that stands for a section.
	 *
	 * @param array<string, mixed> $version  The version.
	 * @param bool                 $editable Whether it may still be changed.
	 */
	private static function steps( array $version, bool $editable ): void {
		$steps = TemplateSteps::for_template( (string) $version['id'] );

		if ( array() === $steps ) {
			echo '<div class="bw-empty" data-bwx-no-steps="1">';
			echo '<i class="bw-icon bw-empty__icon" data-lucide="list-checks"></i>';
			echo '<p class="bw-empty__title">' . esc_html__( 'No steps in this version yet', 'blueworx-forge' ) . '</p>';

			if ( $editable ) {
				echo '<p class="bw-empty__text">' . esc_html__( 'Add the first one below.', 'blueworx-forge' ) . '</p>';
			}

			echo '</div>';

			return;
		}

		$columns = $editable ? 5 : 4;

		echo '<div class="bw-tablescroll">';
		echo '<table class="bw-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Step', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Category', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Who', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Needed to launch', 'blueworx-forge' ) . '</th>';

		if ( $editable ) {
			echo '<th class="bw-table__actions"></th>';
		}

		echo '</tr></thead><tbody>';

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

			printf(
				'<tr class="bw-table__group" data-bwx-steps="%1$s"><td colspan="%2$d"><span class="bw-table__group-title">%3$s</span></td></tr>',
				esc_attr( $section ),
				(int) $columns,
				esc_html( Sections::label( $section ) )
			);

			foreach ( $in_section as $step ) {
				self::step_row( $version, $step, $editable );
			}
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * One step.
	 *
	 * @param array<string, mixed> $version  The version it belongs to.
	 * @param array<string, mixed> $step     The step.
	 * @param bool                 $editable Whether it may still be taken out.
	 */
	private static function step_row( array $version, array $step, bool $editable ): void {
		echo '<tr data-bwx-step="' . esc_attr( (string) $step['id'] ) . '">';

		echo '<td><span class="bw-table__primary">' . esc_html( (string) $step['title'] ) . '</span>';

		if ( '' !== (string) $step['description'] ) {
			echo '<span class="bw-table__sub">' . esc_html( (string) $step['description'] ) . '</span>';
		}

		echo '</td>';
		echo '<td>' . esc_html( '' !== (string) $step['category'] ? (string) $step['category'] : '—' ) . '</td>';
		echo '<td>' . esc_html( TemplateSteps::CLIENT === (string) $step['owner_side'] ? __( 'Client', 'blueworx-forge' ) : __( 'Us', 'blueworx-forge' ) ) . '</td>';

		echo '<td data-bwx-launch-critical="' . esc_attr( $step['launch_critical'] ? '1' : '0' ) . '">';

		if ( $step['launch_critical'] ) {
			echo '<span class="bw-badge bw-badge--danger">' . esc_html__( 'Yes', 'blueworx-forge' ) . '</span>';
		} else {
			echo '<span class="bw-badge bw-badge--neutral">' . esc_html__( 'No', 'blueworx-forge' ) . '</span>';
		}

		echo '</td>';

		if ( $editable ) {
			echo '<td class="bw-table__actions"><div class="bw-rowactions">';
			self::remove_step_form( $version, $step );
			echo '</div></td>';
		}

		echo '</tr>';
	}

	/**
	 * The form that starts a fresh, empty draft.
	 */
	private static function start_draft_form(): void {
		// A panel of its own rather than a tail on the empty state's, so the
		// name field and its button get a card with a footer instead of
		// trailing off the bottom of somebody else's.
		Page::panel_open(
			__( 'Start a checklist', 'blueworx-forge' ),
			'start-draft',
			array( 'data-bwx-start-draft' => '1' )
		);

		wp_nonce_field( 'bwx_forge_start_template' );
		echo '<input type="hidden" name="action" value="bwx_forge_start_template">';

		echo '<div class="bw-formrow">';
		echo '<label class="bw-formrow__label" for="bwx-template-name">' . esc_html__( 'Name', 'blueworx-forge' ) . '</label>';
		echo '<div class="bw-formrow__control">';
		echo '<input type="text" id="bwx-template-name" name="name" value="' . esc_attr( Version1::NAME ) . '" class="bw-input">';
		echo '<p class="bw-formrow__help">' . esc_html__( 'What this checklist is called. Every version of it keeps the name.', 'blueworx-forge' ) . '</p>';
		echo '</div></div>';

		/*
		 * submit_button() rather than a <button>, and this is not cosmetic:
		 * the specs click `input[type="submit"]`, so the element is as much
		 * part of the contract as a data-bwx hook is. What changes is the class
		 * it carries. The same is true of every submit below.
		 */
		Page::actions_open();
		submit_button( __( 'Start a checklist', 'blueworx-forge' ), 'bw-btn bw-btn--primary', 'submit', false );
		Page::panel_close();
	}

	/**
	 * The form that opens a published version as a new draft.
	 *
	 * The only control an issued version has, per ONB-E2.
	 *
	 * @param array<string, mixed> $version The version being copied.
	 */
	private static function copy_form( array $version ): void {
		echo '<div class="bw-card__actions">';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-bwx-copy-template="1">';
		wp_nonce_field( 'bwx_forge_copy_template' );
		echo '<input type="hidden" name="action" value="bwx_forge_copy_template">';
		echo '<input type="hidden" name="template" value="' . esc_attr( (string) $version['id'] ) . '">';
		submit_button( __( 'Open a copy to edit', 'blueworx-forge' ), 'bw-btn bw-btn--secondary', 'submit', false );
		echo '</form>';
		echo '</div>';
	}

	/**
	 * The form that publishes a draft as the next version.
	 *
	 * @param array<string, mixed> $version The draft.
	 */
	private static function publish_form( array $version ): void {
		Page::panel_open(
			__( 'Publish this version', 'blueworx-forge' ),
			'publish',
			array( 'data-bwx-publish-template' => '1' )
		);

		wp_nonce_field( 'bwx_forge_publish_template' );
		echo '<input type="hidden" name="action" value="bwx_forge_publish_template">';
		echo '<input type="hidden" name="template" value="' . esc_attr( (string) $version['id'] ) . '">';

		// The warning sits directly above the button, because that is where a
		// consequence which cannot be undone belongs.
		Page::notice(
			'warning',
			__( 'Publishing issues this as the next version. After that it can never be changed — every client given it sees exactly this.', 'blueworx-forge' )
		);

		Page::actions_open();
		submit_button( __( 'Publish this version', 'blueworx-forge' ), 'bw-btn bw-btn--primary', 'submit', false );
		Page::panel_close();
	}

	/**
	 * The form that adds a step to a draft.
	 *
	 * @param array<string, mixed> $version The draft.
	 */
	private static function add_step_form( array $version ): void {
		Page::panel_open(
			__( 'Add a step', 'blueworx-forge' ),
			'add-step',
			array( 'data-bwx-add-step' => '1' )
		);

		wp_nonce_field( 'bwx_forge_add_template_step' );
		echo '<input type="hidden" name="action" value="bwx_forge_add_template_step">';
		echo '<input type="hidden" name="template" value="' . esc_attr( (string) $version['id'] ) . '">';

		echo '<div class="bw-formrow">';
		echo '<label class="bw-formrow__label" for="bwx-step-title">' . esc_html__( 'Step', 'blueworx-forge' ) . '</label>';
		echo '<div class="bw-formrow__control">';
		echo '<input type="text" id="bwx-step-title" name="title" class="bw-input" required>';
		echo '</div></div>';

		echo '<div class="bw-formrow">';
		echo '<label class="bw-formrow__label" for="bwx-step-section">' . esc_html__( 'When', 'blueworx-forge' ) . '</label>';
		echo '<div class="bw-formrow__control"><div class="bw-select">';
		echo '<select id="bwx-step-section" name="section" class="bw-select__el">';

		foreach ( Sections::ALL as $section ) {
			printf(
				'<option value="%1$s">%2$s</option>',
				esc_attr( $section ),
				esc_html( Sections::label( $section ) )
			);
		}

		echo '</select>';
		echo '<i class="bw-icon bw-select__arrow" data-lucide="chevron-down"></i>';
		echo '</div></div></div>';

		echo '<div class="bw-formrow">';
		echo '<label class="bw-formrow__label" for="bwx-step-category">' . esc_html__( 'Category', 'blueworx-forge' ) . '</label>';
		echo '<div class="bw-formrow__control">';
		echo '<input type="text" id="bwx-step-category" name="category" class="bw-input">';
		echo '</div></div>';

		echo '<div class="bw-formrow">';
		echo '<label class="bw-formrow__label" for="bwx-step-description">' . esc_html__( 'What to do', 'blueworx-forge' ) . '</label>';
		echo '<div class="bw-formrow__control">';
		echo '<textarea id="bwx-step-description" name="description" class="bw-textarea" rows="3"></textarea>';
		echo '</div></div>';

		echo '<div class="bw-formrow">';
		echo '<label class="bw-formrow__label" for="bwx-step-owner">' . esc_html__( 'Who does it', 'blueworx-forge' ) . '</label>';
		echo '<div class="bw-formrow__control"><div class="bw-select">';
		echo '<select id="bwx-step-owner" name="owner_side" class="bw-select__el">';
		echo '<option value="' . esc_attr( TemplateSteps::CLIENT ) . '">' . esc_html__( 'The client', 'blueworx-forge' ) . '</option>';
		echo '<option value="' . esc_attr( TemplateSteps::INTERNAL ) . '">' . esc_html__( 'Us', 'blueworx-forge' ) . '</option>';
		echo '</select>';
		echo '<i class="bw-icon bw-select__arrow" data-lucide="chevron-down"></i>';
		echo '</div></div></div>';

		echo '<div class="bw-formrow">';
		echo '<span class="bw-formrow__label">' . esc_html__( 'Needed to launch', 'blueworx-forge' ) . '</span>';
		echo '<div class="bw-formrow__control">';
		echo '<label class="bw-check"><input type="checkbox" id="bwx-step-launch-critical" name="launch_critical" value="1">';
		echo '<span class="bw-check__text">' . esc_html__( 'A site cannot go live until this is approved', 'blueworx-forge' ) . '</span></label>';
		echo '</div></div>';

		echo '<div class="bw-formrow">';
		echo '<span class="bw-formrow__label">' . esc_html__( 'May be skipped', 'blueworx-forge' ) . '</span>';
		echo '<div class="bw-formrow__control">';
		echo '<label class="bw-check"><input type="checkbox" id="bwx-step-allows-na" name="allows_not_applicable" value="1">';
		echo '<span class="bw-check__text">' . esc_html__( 'Can be marked not applicable, with a reason', 'blueworx-forge' ) . '</span></label>';
		echo '</div></div>';

		echo '<div class="bw-formrow">';
		echo '<label class="bw-formrow__label" for="bwx-step-position">' . esc_html__( 'Order', 'blueworx-forge' ) . '</label>';
		echo '<div class="bw-formrow__control">';
		echo '<input type="number" id="bwx-step-position" name="position" value="0" class="bw-input">';
		echo '<p class="bw-formrow__help">' . esc_html__( 'Where it sits within its section. Lower comes first.', 'blueworx-forge' ) . '</p>';
		echo '</div></div>';

		Page::actions_open();
		submit_button( __( 'Add the step', 'blueworx-forge' ), 'bw-btn bw-btn--primary', 'submit', false );
		Page::panel_close();
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
		submit_button( __( 'Remove', 'blueworx-forge' ), 'bw-btn bw-btn--link', 'submit', false );
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
			'needs-title'   => array( 'danger', __( 'A step needs something to call it.', 'blueworx-forge' ) ),
			'not-a-draft'   => array( 'danger', __( 'That version has been issued, so it cannot be changed. Open a copy instead.', 'blueworx-forge' ) ),
			'unknown'       => array( 'danger', __( 'That version could not be found.', 'blueworx-forge' ) ),
		);

		if ( ! isset( $messages[ $result ] ) ) {
			return;
		}

		Page::notice(
			$messages[ $result ][0],
			$messages[ $result ][1],
			array( 'data-bwx-result' => $result )
		);
	}
}
