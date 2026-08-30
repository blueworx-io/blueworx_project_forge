<?php
/**
 * Sending a client's checklist answer to the studio.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client\Admin;

use Blueworx\Forge\Client\ChecklistAnswer;
use Blueworx\Forge\Client\Connection;

/**
 * The handler behind the checklist form (#162).
 *
 * Two intents on one form, and the order they run in is the whole of it: the
 * typed answer is sent first, then the file. A file that fails to upload must
 * not take somebody's three sentences down with it, and the two are separate
 * requests to the studio precisely so that one can succeed while the other does
 * not.
 *
 * **This handler cannot approve anything, and cannot be talked into it.** The
 * only status it will ever send is `submitted`, written here as a constant
 * rather than read from the form — a status coming out of a `$_POST` would be a
 * status somebody could edit to `approved`, and ONB-2 exists because
 * self-certification makes the launch gate meaningless. The studio refuses it
 * too. Both, because either alone is a rule with one place left to get wrong.
 */
final class ChecklistActions {

	/**
	 * The form action.
	 */
	public const ACTION = 'bwx_forge_client_checklist';

	/**
	 * The one status this handler will ever ask for.
	 */
	private const HANDING_OVER = 'submitted';

	/**
	 * Hooks the handler up.
	 */
	public static function boot(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'send' ) );
	}

	/**
	 * Sends the form to the studio.
	 */
	public static function send(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You are not allowed to send this.', 'blueworx-forge' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::ACTION );

		if ( ! Connection::is_configured() ) {
			self::back( 'not_connected', array() );
		}

		$step_id = isset( $_POST['step_id'] ) ? sanitize_text_field( wp_unslash( $_POST['step_id'] ) ) : '';

		if ( '' === $step_id ) {
			self::back( 'unreachable', array() );
		}

		$intent = isset( $_POST['intent'] ) ? sanitize_key( wp_unslash( $_POST['intent'] ) ) : 'save';

		$answer = ChecklistAnswer::send(
			$step_id,
			array(
				'response' => isset( $_POST['response'] ) ? sanitize_textarea_field( wp_unslash( $_POST['response'] ) ) : '',
			),
			// Never from the form. See the class comment.
			'submit' === $intent ? self::HANDING_OVER : ''
		);

		if ( ! $answer['ok'] ) {
			self::back( $answer['result'], $answer );
		}

		$file = self::uploaded();

		if ( array() === $file ) {
			self::back( 'saved', array() );
		}

		$attached = ChecklistAnswer::attach( $step_id, $file );

		self::back( $attached['ok'] ? 'attached' : $attached['result'], $attached );
	}

	/**
	 * The file on the form, if one was chosen and it arrived.
	 *
	 * "No file chosen" is the ordinary case rather than a failure, so it is
	 * answered with an empty array and not an error. A file that was chosen and
	 * did not arrive is a failure, and PHP says so in its own way, which is why
	 * the error code is checked rather than only the name.
	 *
	 * @return array<string, mixed>
	 */
	private static function uploaded(): array {
		/*
		 * Each field is sanitised as it is read out, just below. The array
		 * itself cannot be, and neither can `tmp_name`, which is a path PHP
		 * wrote rather than anything the browser sent.
		 */
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- check_admin_referer() ran in the caller; each field is sanitised below.
		$file = isset( $_FILES['evidence'] ) ? (array) $_FILES['evidence'] : array();

		$error = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );

		if ( UPLOAD_ERR_NO_FILE === $error ) {
			return array();
		}

		if ( UPLOAD_ERR_OK !== $error ) {
			// Named so the screen can say something specific about the two that
			// a person can actually do something about.
			return array(
				'error' => $error,
				'size'  => 0,
			);
		}

		return array(
			'name'     => isset( $file['name'] ) ? sanitize_file_name( (string) $file['name'] ) : '',
			'type'     => isset( $file['type'] ) ? sanitize_mime_type( (string) $file['type'] ) : '',
			'tmp_name' => isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '',
			'size'     => isset( $file['size'] ) ? (int) $file['size'] : 0,
		);
	}

	/**
	 * Returns to the checklist with the outcome, and stops.
	 *
	 * @param string               $result One of the result codes the screen knows.
	 * @param array<string, mixed> $answer What came back, where the studio said something.
	 */
	private static function back( string $result, array $answer ): void {
		if ( array() !== $answer && '' !== (string) ( $answer['message'] ?? '' ) ) {
			set_transient(
				ChecklistScreen::OUTCOME,
				array(
					'field'   => (string) ( $answer['field'] ?? '' ),
					'message' => (string) $answer['message'],
				),
				5 * MINUTE_IN_SECONDS
			);
		}

		wp_safe_redirect( ChecklistScreen::url( $result ) );
		exit;
	}
}
