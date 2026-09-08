<?php
/**
 * The client's onboarding checklist.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client\Admin;

use Blueworx\Forge\Client\Checklist;
use Blueworx\Forge\Client\Denial;
use Blueworx\Forge\Client\Workspace;

/**
 * Getting a client's site launched, from their side (#162).
 *
 * The screen is built round one question — what is waiting on you — because
 * that is the only question somebody opening it has. So it opens with the next
 * thing rather than with a summary, and the three sections underneath are the
 * *when*, not the what: Foundations, Build reviews, Launch. A flat list of
 * forty items tells a client nothing about which are theirs today and which are
 * months away, and a checklist nobody can find their place in is one that gets
 * abandoned and chased by email instead.
 *
 * **Only a client's own outstanding steps get a form.** Ours are shown, because
 * a client watching for their site to go live wants to see that we are doing
 * something, but they are shown as text. A step already submitted is shown as
 * waiting on us. Nothing on this page can create a step, delete one, reorder
 * them or approve one, and none of those four is absent by politeness — the
 * studio refuses all four at the API (#162), and this artifact has nothing that
 * could ask.
 *
 * The one thing this screen must never say is nothing at all. A studio it
 * cannot reach is said plainly, because an empty page reads as "you have no
 * checklist" and that is a different, worse sentence.
 */
final class ChecklistScreen {

	/**
	 * The submenu page slug.
	 */
	public const SLUG = 'blueworx-forge-client-checklist';

	/**
	 * Where the result of the last save is kept between the post and the redirect.
	 */
	public const OUTCOME = 'bwx_forge_client_checklist_outcome';

	/**
	 * How each status reads to the person whose checklist it is.
	 *
	 * Their words, not the system's: a client does not have a mental model of
	 * "not-started", they have one of "not done yet".
	 *
	 * @var array<string, string>
	 */
	private const READS = array(
		'not-started'    => 'Not started',
		'in-progress'    => 'In progress',
		'submitted'      => 'With us to check',
		'returned'       => 'Needs another look',
		'approved'       => 'Done',
		'not-applicable' => 'Does not apply',
		'blocked'        => 'Held up',
	);

	/**
	 * The statuses worth colouring, and the bw-badge tone each gets.
	 *
	 * Chosen for what the status means, the same way Card::stage_tone() picks a
	 * tone: approved is a finished, positive outcome, the same shape as a
	 * released item, so it reads success. Blocked is a step that has stalled
	 * outright with nothing left for the client to do until somebody unsticks
	 * it — the one thing on this screen that needs attention, the same reading
	 * Card gives its own "blocked" stage — so it is the one danger tone here.
	 * Returned sits between the two: it is work coming back to the client, not
	 * work that has stopped, so it warns rather than alarms. Everything else —
	 * not started, in progress, submitted, not applicable — is left neutral,
	 * because a screen where every row is coloured tells somebody nothing about
	 * which row to read first.
	 *
	 * @var array<string, string>
	 */
	private const TONES = array(
		'approved' => 'success',
		'returned' => 'warning',
		'blocked'  => 'danger',
	);

	/**
	 * Adds the menu entry.
	 */
	public static function register(): void {
		add_submenu_page(
			Screen::SLUG,
			__( 'Onboarding', 'blueworx-forge' ),
			__( 'Onboarding', 'blueworx-forge' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * The screen's own address, optionally carrying the result of a save.
	 *
	 * @param string $result One of the result codes the screen knows.
	 * @return string
	 */
	public static function url( string $result = '' ): string {
		$url = admin_url( 'admin.php?page=' . self::SLUG );

		return '' === $result ? $url : add_query_arg( 'result', $result, $url );
	}

	/**
	 * Renders the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$view      = Checklist::view( SyncNotice::refresh_requested() );
		$workspace = Workspace::view( false );

		Page::open( __( 'Onboarding', 'blueworx-forge' ), Nav::scope_text( $workspace ) );

		SyncNotice::render( $view['sync'], self::SLUG );

		self::outcome();

		if ( ! $view['ok'] ) {
			Denial::render( (string) $view['sync']['state'], Denial::REQUESTS, 'bwx-checklist-unavailable' );
			Page::close();

			return;
		}

		if ( array() === $view['steps'] ) {
			self::nothing_yet();
			Page::close();

			return;
		}

		self::progress( (array) $view['progress'] );
		self::next( (array) $view['next'] );

		echo '<div class="bwx-checklist" data-testid="bwx-checklist">';

		foreach ( (array) $view['sections'] as $section => $steps ) {
			self::section( (string) $section, (array) $steps );
		}

		echo '</div>';

		Page::close();
	}

	/**
	 * How far along they are.
	 *
	 * The design system's ProgressBar. The visible count reads exactly as it
	 * did before conversion ("N of M done") so nothing here changes what a
	 * client is being told, only how it looks; the percentage alongside it is
	 * new. The track carries a progressbar role of its own, valued against the
	 * step counts rather than the rounded percentage, because that is the more
	 * precise of the two numbers this screen actually has.
	 *
	 * @param array<string, mixed> $progress As the studio worked it out (#164).
	 */
	private static function progress( array $progress ): void {
		$done  = (int) ( $progress['approved'] ?? 0 );
		$total = (int) ( $progress['required'] ?? 0 );

		if ( $total <= 0 ) {
			return;
		}

		$text = sprintf(
			/* translators: 1: steps done, 2: steps in total. */
			__( '%1$d of %2$d done', 'blueworx-forge' ),
			$done,
			$total
		);
		$pct = (int) round( ( $done / $total ) * 100 );

		echo '<div class="bw-progress" data-testid="bwx-checklist-progress">';
		echo '<div class="bw-progress__row">';
		printf( '<span class="bw-progress__label">%s</span>', esc_html( $text ) );
		printf( '<p class="bw-progress__pct">%s%%</p>', esc_html( (string) $pct ) );
		echo '</div>';
		printf(
			'<div class="bw-progress__track" role="progressbar" aria-valuenow="%1$s" aria-valuemin="0" aria-valuemax="%2$s" aria-label="%3$s">',
			esc_attr( (string) $done ),
			esc_attr( (string) $total ),
			esc_attr( $text )
		);
		printf( '<div class="bw-progress__bar" style="width:%s%%"></div>', esc_attr( (string) $pct ) );
		echo '</div>';
		echo '</div>';
	}

	/**
	 * The one thing to do next.
	 *
	 * At the top, because it is the only question somebody opening this page
	 * has. Nothing outstanding is worth saying too — it is the difference
	 * between "we are waiting on you" and "we are getting on with it".
	 *
	 * A bw-card--intro: the design system's own rules for that modifier drop
	 * the head's dividing border when the head is the card's only child, and
	 * drop a note's own trailing margin when it is — which is exactly the two
	 * shapes the two branches below draw, so nothing here is fighting the card
	 * for space.
	 *
	 * @param array<string, mixed> $step The step, or empty.
	 */
	private static function next( array $step ): void {
		echo '<div class="bw-card bw-card--intro" data-testid="bwx-checklist-next">';

		if ( array() === $step ) {
			echo '<div class="bw-card__body">';
			echo '<p class="bw-card__note">' . esc_html__( 'Nothing is waiting on you right now.', 'blueworx-forge' ) . '</p>';
			echo '</div>';
		} else {
			echo '<div class="bw-card__head"><div class="bw-card__titles">';
			echo '<p class="bw-card__eyebrow">' . esc_html__( 'Next for you', 'blueworx-forge' ) . '</p>';
			echo '<h2 class="bw-card__title"><a href="#' . esc_attr( self::anchor( $step ) ) . '">';
			echo esc_html( (string) ( $step['title'] ?? '' ) );
			echo '</a></h2>';
			echo '</div></div>';
		}

		echo '</div>';
	}

	/**
	 * One section and its steps.
	 *
	 * @param string                           $section The section name.
	 * @param array<int, array<string, mixed>> $steps   Its steps, in order.
	 */
	private static function section( string $section, array $steps ): void {
		printf(
			'<section class="bwx-checklist-section" data-testid="bwx-checklist-section" data-section="%s">',
			esc_attr( $section )
		);

		echo '<h2>' . esc_html( Checklist::label( $section ) ) . '</h2>';

		foreach ( $steps as $step ) {
			self::step( (array) $step );
		}

		echo '</section>';
	}

	/**
	 * One step.
	 *
	 * @param array<string, mixed> $step The step.
	 */
	private static function step( array $step ): void {
		$status = (string) ( $step['status'] ?? '' );
		$theirs = Checklist::is_theirs( $step );

		printf(
			'<article class="bw-card" data-testid="bwx-checklist-step" id="%s" data-status="%s">',
			esc_attr( self::anchor( $step ) ),
			esc_attr( $status )
		);

		echo '<div class="bw-card__head"><div class="bw-card__titles">';
		echo '<h3 class="bw-card__title">' . esc_html( (string) ( $step['title'] ?? '' ) ) . '</h3>';
		echo '</div>';
		echo '<div class="bw-card__actions">';
		self::badges( $step, $status );
		echo '</div>';
		echo '</div>';

		echo '<div class="bw-card__body">';

		if ( '' !== (string) ( $step['description'] ?? '' ) ) {
			echo '<p class="bw-card__note">' . esc_html( (string) $step['description'] ) . '</p>';
		}

		self::feedback( $step );
		self::attachments( (array) ( $step['evidence'] ?? array() ) );

		if ( $theirs ) {
			self::form( $step );
		} else {
			self::waiting( $step, $status );
		}

		echo '</div>';

		echo '</article>';
	}

	/**
	 * The small badges beside a step's title.
	 *
	 * The two flags read as different kinds of urgent, and are toned
	 * differently on purpose. "Needed before you can go live" is a standing
	 * fact about the step — true on the day it is created and true every day
	 * after, whether or not anyone is behind — so it is informational rather
	 * than alarming, the same way the original markup never coloured it at
	 * all. "Overdue" is a live, time-sensitive problem, the one the original
	 * markup already coloured (bwx-tone-attention) precisely because it
	 * changes and demands notice, so it carries the strongest tone this screen
	 * has. Giving them the same tone would flatten "this matters" and "this is
	 * late" into one signal, when a client's next action differs for each.
	 *
	 * @param array<string, mixed> $step   The step.
	 * @param string               $status Where it is.
	 */
	private static function badges( array $step, string $status ): void {
		printf(
			'<span class="bw-badge bw-badge--%1$s bwx-checklist-status" data-testid="bwx-checklist-status">%2$s</span>',
			esc_attr( self::TONES[ $status ] ?? 'neutral' ),
			esc_html( self::READS[ $status ] ?? $status )
		);

		if ( ! empty( $step['launch_critical'] ) ) {
			echo '<span class="bw-badge bw-badge--neutral">' . esc_html__( 'Needed before you can go live', 'blueworx-forge' ) . '</span>';
		}

		if ( ! empty( $step['overdue'] ) ) {
			echo '<span class="bw-badge bw-badge--danger">' . esc_html__( 'Overdue', 'blueworx-forge' ) . '</span>';
		}
	}

	/**
	 * What we said when we sent a step back.
	 *
	 * A Notice, toned as something the client needs to act on rather than as an
	 * error — nothing here is broken, a person just needs to look again — so it
	 * warns rather than alarms, the same distinction badges() draws above.
	 *
	 * The reason a returned step is worth returning: without the feedback on
	 * the client's own screen, "needs another look" is an instruction with no
	 * content and the client emails to ask what we meant.
	 *
	 * @param array<string, mixed> $step The step.
	 */
	private static function feedback( array $step ): void {
		$feedback = trim( (string) ( $step['feedback'] ?? '' ) );

		if ( '' === $feedback ) {
			return;
		}

		echo '<div class="bw-notice bw-notice--warning" data-testid="bwx-checklist-feedback" role="status">';
		echo '<i class="bw-icon bw-notice__icon" data-lucide="triangle-alert"></i>';
		echo '<div class="bw-notice__body">';
		echo '<p class="bw-notice__title">' . esc_html__( 'What we need changed', 'blueworx-forge' ) . '</p>';
		echo '<p class="bw-notice__text">' . esc_html( $feedback ) . '</p>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * What has been attached so far.
	 *
	 * A plain list rather than bw-chip: a chip is a compact, reference-only
	 * pill for a short value like a seat or a filter (see Card::people()), and
	 * reads oddly stretched across a filename that can run to forty characters
	 * with an extension on the end. A named row with the design system's own
	 * icon-plus-text note is the closer fit, and is what Card already uses for
	 * exactly this shape of thing (its own dated fieldnotes).
	 *
	 * Named, dated and not linked. The file is readable through the studio by
	 * somebody it belongs to, and putting a link on this screen would mean this
	 * artifact holding an address for it — see #168 for why nothing here has
	 * one.
	 *
	 * @param array<int, array<string, mixed>> $evidence What is attached.
	 */
	private static function attachments( array $evidence ): void {
		if ( array() === $evidence ) {
			return;
		}

		echo '<ul class="bwx-checklist-evidence" data-testid="bwx-checklist-evidence">';

		foreach ( $evidence as $file ) {
			printf(
				'<li class="bw-fieldnote"><i class="bw-icon" data-lucide="file"></i>%s</li>',
				esc_html( (string) ( $file['original_name'] ?? '' ) )
			);
		}

		echo '</ul>';
	}

	/**
	 * What a step says when it is not the client's to do.
	 *
	 * @param array<string, mixed> $step   The step.
	 * @param string               $status Where it is.
	 */
	private static function waiting( array $step, string $status ): void {
		if ( 'submitted' === $status ) {
			echo '<p class="bw-fieldnote"><i class="bw-icon" data-lucide="clock"></i>' . esc_html__( 'Sent to us. We will come back to you.', 'blueworx-forge' ) . '</p>';

			return;
		}

		if ( 'client' !== (string) ( $step['owner_side'] ?? '' ) ) {
			echo '<p class="bw-fieldnote"><i class="bw-icon" data-lucide="user"></i>' . esc_html__( 'This one is ours to do.', 'blueworx-forge' ) . '</p>';
		}
	}

	/**
	 * The form for a step that is the client's to do.
	 *
	 * Two things a person can send: what they did, and a file showing it. The
	 * two are separate submits rather than one, because a file that fails to
	 * upload must not take somebody's typed answer down with it.
	 *
	 * The posting mechanism is untouched by this conversion: method, action,
	 * enctype, nonce, the hidden action/step_id inputs, every name and id, and
	 * the submit button's own name/value are exactly what ChecklistActions
	 * expects. Only the presentation around them changes.
	 *
	 * The file input stays a plain `<input type="file">` rather than the
	 * design system's UploadField: that component keeps its choose/drag state
	 * in React and never wires its own `<input>` to a working change handler
	 * (it renders hidden and readonly, with selection left to JavaScript this
	 * plugin does not ship for its server-rendered admin screens). Reaching
	 * for its class on a plain input would look styled but not function, which
	 * is worse than the unstyled control this screen already had.
	 *
	 * @param array<string, mixed> $step The step.
	 */
	private static function form( array $step ): void {
		$id = (string) ( $step['id'] ?? '' );

		echo '<form class="bwx-checklist-form" data-testid="bwx-checklist-form" method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';

		wp_nonce_field( ChecklistActions::ACTION );

		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( ChecklistActions::ACTION ) );
		printf( '<input type="hidden" name="step_id" value="%s" />', esc_attr( $id ) );

		echo '<div class="bw-formrow">';
		printf(
			'<label class="bw-formrow__label" for="response-%1$s">%2$s</label>',
			esc_attr( $id ),
			esc_html__( 'What have you done?', 'blueworx-forge' )
		);
		echo '<div class="bw-formrow__control">';
		printf(
			'<textarea id="response-%1$s" name="response" rows="3" class="bw-textarea" data-testid="bwx-checklist-response">%2$s</textarea>',
			esc_attr( $id ),
			esc_textarea( (string) ( $step['response'] ?? '' ) )
		);

		/*
		 * Said before somebody types rather than after they are refused. ONB-3
		 * is a rule about what we will hold, and a person who has just had a
		 * password rejected without warning tends to email it to us instead.
		 */
		printf(
			'<p class="bw-formrow__help">%s</p>',
			esc_html__( 'Please do not put passwords or keys here — invite our account instead, and tell us which account you invited.', 'blueworx-forge' )
		);
		echo '</div>';
		echo '</div>';

		echo '<div class="bw-formrow">';
		printf(
			'<label class="bw-formrow__label" for="evidence-%1$s">%2$s</label>',
			esc_attr( $id ),
			esc_html__( 'Attach something (optional)', 'blueworx-forge' )
		);
		echo '<div class="bw-formrow__control">';
		printf(
			'<input type="file" id="evidence-%s" name="evidence" data-testid="bwx-checklist-file" />',
			esc_attr( $id )
		);
		echo '</div>';
		echo '</div>';

		echo '<p class="bwx-checklist-actions bwx-formactions">';

		printf(
			'<button type="submit" name="intent" value="save" class="bw-btn bw-btn--secondary" data-testid="bwx-checklist-save">%s</button> ',
			esc_html__( 'Save', 'blueworx-forge' )
		);

		printf(
			'<button type="submit" name="intent" value="submit" class="bw-btn bw-btn--primary" data-testid="bwx-checklist-submit">%s</button>',
			esc_html__( 'Send to us', 'blueworx-forge' )
		);

		echo '</p></form>';
	}

	/**
	 * A connected site with no checklist on it yet.
	 */
	private static function nothing_yet(): void {
		echo '<div class="bw-empty" data-testid="bwx-checklist-empty">';
		echo '<i class="bw-icon bw-empty__icon" data-lucide="package"></i>';
		echo '<h3 class="bw-empty__title">' . esc_html__( 'Nothing here yet', 'blueworx-forge' ) . '</h3>';
		echo '<p class="bw-empty__text">' . esc_html__( 'There is no checklist on this site yet. We will send one over when your build starts.', 'blueworx-forge' ) . '</p>';
		echo '</div>';
	}

	/**
	 * How the last save went.
	 */
	private static function outcome(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a result code this screen put in its own redirect; it changes nothing.
		$result = isset( $_GET['result'] ) ? sanitize_key( wp_unslash( $_GET['result'] ) ) : '';

		if ( '' === $result ) {
			return;
		}

		$refusal = get_transient( self::OUTCOME );
		$notice  = self::says( $result, is_array( $refusal ) ? $refusal : array() );

		delete_transient( self::OUTCOME );

		printf(
			'<div class="notice notice-%s" data-testid="bwx-checklist-outcome"><p>%s</p></div>',
			'saved' === $result || 'attached' === $result ? 'success' : 'error',
			esc_html( $notice )
		);
	}

	/**
	 * What each outcome says.
	 *
	 * @param string               $result  The result code.
	 * @param array<string, mixed> $refusal What the studio said, where it said something.
	 * @return string
	 */
	private static function says( string $result, array $refusal ): string {
		switch ( $result ) {
			case 'saved':
				return __( 'Saved.', 'blueworx-forge' );
			case 'attached':
				return __( 'Attached.', 'blueworx-forge' );
			case 'refused':
				// The studio's own words. It wrote them for the person reading
				// them, and rewording them here would give one refusal two
				// forms depending on which screen somebody was on.
				return (string) ( $refusal['message'] ?? __( 'That could not be saved.', 'blueworx-forge' ) );
			case 'too_big':
				return __( 'That file is too large. Send one under 10MB, or a link to it.', 'blueworx-forge' );
			case 'no_file':
				return __( 'That file did not arrive. Try attaching it again.', 'blueworx-forge' );
			case 'not_connected':
				return __( 'This site is not connected to the studio yet.', 'blueworx-forge' );
			default:
				return __( 'We could not reach the studio, so nothing was saved. Try again shortly.', 'blueworx-forge' );
		}
	}

	/**
	 * A step's anchor on the page.
	 *
	 * @param array<string, mixed> $step The step.
	 * @return string
	 */
	private static function anchor( array $step ): string {
		return 'step-' . (string) ( $step['id'] ?? '' );
	}
}
