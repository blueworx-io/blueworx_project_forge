<?php
/**
 * Where a client asks for something.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client\Admin;

use Blueworx\Forge\Client\Connection;
use Blueworx\Forge\Client\Denial;
use Blueworx\Forge\Client\Sync;
use Blueworx\Forge\Client\Workspace;

/**
 * The form a client asks through (#129).
 *
 * Available whether or not the client pays for support. That is the point of
 * the issue and it is worth stating on the screen as well as in the code: a
 * client who has not bought anything can still say "could we have this", and
 * the studio still answers. Nothing here consults a package, and there is no
 * state this form can be in where it refuses to send because of one.
 *
 * The form says plainly that what is sent cannot be edited afterwards (REQ-1).
 * That is not a warning about a limitation — it is the promise that makes an
 * intake record worth having, since what was asked can never drift to match
 * what was delivered. Somebody who wants to change their mind sends another
 * one, and both are visible.
 *
 * A failed send comes back with the words still in the boxes. Somebody who has
 * just written three paragraphs should not lose them because a network call
 * timed out.
 */
final class AskScreen {

	/**
	 * The submenu page slug.
	 */
	public const SLUG = 'blueworx-forge-client-ask';

	/**
	 * Where a failed send parks what was typed, so the form can offer it back.
	 */
	public const DRAFT = 'bwx_forge_client_ask_draft';

	/**
	 * Adds the menu entry.
	 */
	public static function register(): void {
		add_submenu_page(
			Screen::SLUG,
			__( 'Ask for something', 'blueworx-forge' ),
			__( 'Ask for something', 'blueworx-forge' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * This screen's URL, optionally carrying a result to report.
	 *
	 * @param string $result A result code, or an empty string.
	 * @return string
	 */
	public static function url( string $result = '' ): string {
		$url = admin_url( 'admin.php?page=' . self::SLUG );

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
		echo '<h1>' . esc_html__( 'Ask for something', 'blueworx-forge' ) . '</h1>';

		Nav::render( self::SLUG );

		self::result_notice();

		/*
		 * No form on a site with nowhere to send it (#134).
		 *
		 * This used to draw the whole thing and refuse on submit, which is the
		 * worst of both: somebody writes three paragraphs into boxes that were
		 * never going to work, and finds out at the end. A control that cannot
		 * succeed is not a control, so it is not drawn — and what replaces it
		 * says why and where to fix it, rather than leaving somebody looking at
		 * a page that has quietly lost its form.
		 */
		if ( ! Connection::is_configured() ) {
			Denial::render( Sync::STATE_NOT_CONFIGURED, Denial::ASKING, 'bwx-ask-unavailable' );

			echo '</div>';

			return;
		}

		self::availability();
		self::form();

		echo '</div>';
	}

	/**
	 * Whether the studio has room, said plainly (#140).
	 *
	 * Above the form rather than after it, because it changes what somebody
	 * writes. "There is room from mid-September" is the difference between
	 * asking for something now and asking for it with a date attached, and a
	 * client who finds out afterwards has already written three paragraphs
	 * against the wrong assumption.
	 *
	 * It never discourages the ask. Even at its worst the sentence says to send
	 * it anyway — the studio would rather have the conversation than have a
	 * client quietly decide not to bother.
	 *
	 * Taken from the workspace record this site already holds, never fetched on
	 * its own. A form whose job is to accept what somebody types must not pause
	 * on a studio call before it will draw itself — and it would pause longest
	 * exactly when the studio is unreachable, which is the worst moment to make
	 * somebody wait to tell us something.
	 */
	private static function availability(): void {
		$result = Workspace::availability_if_known();
		$band   = (string) ( $result['availability'] ?? '' );

		if ( '' === $band ) {
			// Nothing to say, and nothing is better than a guess. The sync
			// notice elsewhere on the screen already reports a studio that
			// cannot be reached.
			return;
		}

		echo '<div class="notice notice-info inline" data-bwx-availability="' . esc_attr( $band ) . '"><p>'
			. esc_html( self::availability_sentence( $band, (string) ( $result['earliest'] ?? '' ) ) )
			. '</p></div>';
	}

	/**
	 * The sentence a band is shown as.
	 *
	 * @param string $band     One of room, tight, none.
	 * @param string $earliest YYYY-MM-DD, or empty.
	 * @return string
	 */
	private static function availability_sentence( string $band, string $earliest ): string {
		if ( 'room' === $band ) {
			return __( 'There is room for new work at the moment.', 'blueworx-forge' );
		}

		if ( 'tight' === $band ) {
			return __( 'The next few weeks are tight. Ask anyway, and we will tell you what is possible.', 'blueworx-forge' );
		}

		if ( '' === $earliest ) {
			return __( 'There is no room in the next couple of months. Ask anyway, and we will talk about timing.', 'blueworx-forge' );
		}

		return sprintf(
			/* translators: %s: a date, as YYYY-MM-DD. */
			__( 'There is no room right now. The earliest we expect room is %s.', 'blueworx-forge' ),
			$earliest
		);
	}

	/**
	 * What happened last time, if anything did.
	 */
	private static function result_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A result code chooses which sentence to print and changes nothing; the send it reports on was itself nonce-checked.
		$result = isset( $_GET['bwx-result'] ) ? sanitize_key( wp_unslash( $_GET['bwx-result'] ) ) : '';

		if ( '' === $result ) {
			return;
		}

		$notices = array(
			'sent'          => array( 'success', __( 'Sent. The studio has it, and will come back to you.', 'blueworx-forge' ) ),
			'invalid'       => array( 'error', __( 'That could not be sent. Check the boxes below and try again.', 'blueworx-forge' ) ),
			'unreachable'   => array( 'error', __( 'The studio could not be reached, so nothing was sent. What you wrote is still here — try again in a moment.', 'blueworx-forge' ) ),
			'not_connected' => array( 'warning', __( 'This site is not connected to the studio yet, so there is nowhere to send this.', 'blueworx-forge' ) ),
		);

		if ( ! isset( $notices[ $result ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s" data-bwx-result="%2$s"><p>%3$s</p></div>',
			esc_attr( $notices[ $result ][0] ),
			esc_attr( $result ),
			esc_html( $notices[ $result ][1] )
		);
	}

	/**
	 * The form.
	 */
	private static function form(): void {
		$draft = get_transient( self::DRAFT );
		$draft = is_array( $draft ) ? $draft : array();

		delete_transient( self::DRAFT );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-testid="bwx-ask-form">';

		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( AskActions::ACTION ) );
		wp_nonce_field( AskActions::ACTION );

		echo '<table class="form-table" role="presentation"><tbody>';

		self::types( (string) ( $draft['type'] ?? 'request' ) );
		self::text( 'title', __( 'Title', 'blueworx-forge' ), (string) ( $draft['title'] ?? '' ), __( 'A short name for what you are asking about.', 'blueworx-forge' ) );
		self::area( 'description', __( 'What you are asking for', 'blueworx-forge' ), (string) ( $draft['description'] ?? '' ), __( 'As much or as little as you like. What is happening now, and what you would rather happened.', 'blueworx-forge' ) );
		self::area( 'desired_outcome', __( 'What good would look like', 'blueworx-forge' ), (string) ( $draft['desired_outcome'] ?? '' ), __( 'Optional. How you would know this had been done well.', 'blueworx-forge' ) );
		self::area( 'evidence', __( 'Anything that helps', 'blueworx-forge' ), (string) ( $draft['evidence'] ?? '' ), __( 'Optional. Links to the page in question, an error message, a screenshot somewhere we can see it.', 'blueworx-forge' ) );

		echo '</tbody></table>';

		printf(
			'<p class="description" data-testid="bwx-ask-immutable">%s</p>',
			esc_html__( 'Once sent, this is kept exactly as you wrote it and cannot be edited. If you change your mind, send another one — we will see both.', 'blueworx-forge' )
		);

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'You can send these whether or not you have a support package. Having one affects how quickly work can be scheduled, not whether you can ask.', 'blueworx-forge' )
		);

		submit_button( __( 'Send to the studio', 'blueworx-forge' ) );

		echo '</form>';
	}

	/**
	 * The three things a client might be sending.
	 *
	 * Radio buttons rather than a dropdown, because the difference between them
	 * matters and a closed list nobody opens is a list where everybody picks
	 * the first one.
	 *
	 * @param string $chosen The type currently selected.
	 */
	private static function types( string $chosen ): void {
		// The three values are written here rather than read from the studio,
		// because a form nobody can see until a network call succeeds is a form
		// that disappears exactly when somebody most wants to report that
		// something is wrong. The studio remains the authority: anything it
		// does not recognise comes back as a refusal on the field, which is a
		// safe way for the two to disagree.
		$types = array(
			'request'    => array(
				__( 'A request', 'blueworx-forge' ),
				__( 'Something you would like us to do.', 'blueworx-forge' ),
			),
			'idea'       => array(
				__( 'An idea', 'blueworx-forge' ),
				__( 'A thought worth considering, with no expectation attached.', 'blueworx-forge' ),
			),
			'suggestion' => array(
				__( 'A suggestion', 'blueworx-forge' ),
				__( 'Something you think could be better than it is.', 'blueworx-forge' ),
			),
		);

		echo '<tr><th scope="row">' . esc_html__( 'What is this?', 'blueworx-forge' ) . '</th><td>';
		echo '<fieldset>';

		foreach ( $types as $value => $labels ) {
			printf(
				'<label style="display:block;margin-bottom:.4rem"><input type="radio" name="type" value="%1$s"%2$s /> <strong>%3$s</strong> <span class="description">%4$s</span></label>',
				esc_attr( $value ),
				checked( $chosen, $value, false ),
				esc_html( $labels[0] ),
				esc_html( $labels[1] )
			);
		}

		echo '</fieldset></td></tr>';
	}

	/**
	 * One single-line field.
	 *
	 * @param string $name  Field name.
	 * @param string $label Field label.
	 * @param string $value What is in it.
	 * @param string $help  What it is for.
	 */
	private static function text( string $name, string $label, string $value, string $help ): void {
		printf(
			'<tr><th scope="row"><label for="bwx-%1$s">%2$s</label></th><td><input type="text" class="regular-text" id="bwx-%1$s" name="%1$s" value="%3$s" required /><p class="description">%4$s</p></td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( $value ),
			esc_html( $help )
		);
	}

	/**
	 * One multi-line field.
	 *
	 * @param string $name  Field name.
	 * @param string $label Field label.
	 * @param string $value What is in it.
	 * @param string $help  What it is for.
	 */
	private static function area( string $name, string $label, string $value, string $help ): void {
		printf(
			'<tr><th scope="row"><label for="bwx-%1$s">%2$s</label></th><td><textarea class="large-text" rows="5" id="bwx-%1$s" name="%1$s">%3$s</textarea><p class="description">%4$s</p></td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_textarea( $value ),
			esc_html( $help )
		);
	}
}
