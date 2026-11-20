<?php
/**
 * Sending what a client asked for to the studio.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client\Admin;

use Blueworx\Forge\Client\Access;
use Blueworx\Forge\Client\Connection;
use Blueworx\Forge\Client\Submission;

/**
 * The one thing a client site sends rather than reads (#129).
 *
 * Everything else in this plugin is a read: the studio is canonical and this
 * site renders what it is told. A submission is the exception, and it is worth
 * being clear about why it is safe to be one. It creates a record that belongs
 * to the client sending it, it names nothing outside that client, and the
 * studio answers it for whoever signed the request — so there is no version of
 * this that reaches another client's data, whatever is typed into the form.
 *
 * It is not cached and it is not retried. A submission that quietly went twice
 * would give the studio two of the same request to triage, and a client no way
 * to tell which one the answer belongs to. If the studio cannot be reached the
 * form comes back with the words still in it and says so.
 */
final class AskActions {

	/**
	 * The form action.
	 */
	public const ACTION = 'bwx_forge_client_ask';

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
		if ( ! current_user_can( Access::USE ) ) {
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

		$sent = array(
			'type'            => isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '',
			'title'           => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
			'description'     => isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '',
			'desired_outcome' => isset( $_POST['desired_outcome'] ) ? sanitize_textarea_field( wp_unslash( $_POST['desired_outcome'] ) ) : '',
			'evidence'        => isset( $_POST['evidence'] ) ? sanitize_textarea_field( wp_unslash( $_POST['evidence'] ) ) : '',
			'submitted_by'    => self::who(),
		);

		$attached = self::with_attachment( $sent );
		$answer   = Submission::send( $attached['sent'] );

		if ( ! $answer['ok'] ) {
			// The words come back with the failure. Somebody who has just
			// written three paragraphs should not have to write them again
			// because a network call failed.
			self::back( $answer['result'], $sent );
		}

		// Sent, but the file was not: said so, rather than left to be found
		// out when the studio asks where it is.
		self::back( $attached['refused'] ? 'sent_file_refused' : 'sent', array() );
	}

	/**
	 * A request with the file somebody attached added as a line of evidence.
	 *
	 * The file becomes a line of evidence rather than a field of its own. It
	 * is the client's own file on the client's own site, and what the studio
	 * is sent is the address — the same thing somebody would have pasted in by
	 * hand. Nothing on the studio side has to learn a new shape for it.
	 *
	 * Public so the app's route (#298) sends a file through exactly this and
	 * no second copy of it.
	 *
	 * @param array<string, string> $sent The request as it will be sent.
	 * @return array{sent: array<string, string>, refused: bool} The request,
	 *     and whether a file was attached but could not be kept.
	 */
	public static function with_attachment( array $sent ): array {
		$file = self::attachment();

		if ( '' !== $file['url'] ) {
			$sent['evidence'] = trim(
				$sent['evidence'] . "\n\n" . sprintf(
					/* translators: 1: "Screenshot" or "File", 2: the address of the uploaded file. */
					__( '%1$s: %2$s', 'blueworx-forge' ),
					$file['image'] ? __( 'Screenshot', 'blueworx-forge' ) : __( 'File', 'blueworx-forge' ),
					$file['url']
				)
			);
		}

		return array(
			'sent'    => $sent,
			'refused' => $file['refused'],
		);
	}

	/**
	 * The file somebody attached, in this site's own media library.
	 *
	 * An image or a PDF. Anything else, a failed upload, or somebody not
	 * allowed to upload, is refused — but a refused file must not take a
	 * request down with it. Somebody reporting that their site is broken has
	 * told us something useful whether or not the file arrived, so the words
	 * still go, and the person is told the file did not.
	 *
	 * Checked after the upload rather than on the name it arrived under.
	 * WordPress decides what a file actually is; the extension is whatever
	 * the browser was told, and on this route the browser is on the other
	 * side of the internet.
	 *
	 * @return array{url: string, image: bool, refused: bool}
	 */
	private static function attachment(): array {
		$none = array(
			'url'     => '',
			'image'   => false,
			'refused' => false,
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce, or the REST cookie check, ran on this request before anything read the form.
		if ( ! isset( $_FILES['screenshot'] ) || ! is_array( $_FILES['screenshot'] ) ) {
			return $none;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- As above.
		$error = isset( $_FILES['screenshot']['error'] ) ? (int) $_FILES['screenshot']['error'] : UPLOAD_ERR_NO_FILE;

		if ( UPLOAD_ERR_NO_FILE === $error ) {
			return $none;
		}

		$refused = array_merge( $none, array( 'refused' => true ) );

		if ( ! current_user_can( 'upload_files' ) ) {
			return $refused;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$attachment = media_handle_upload(
			'screenshot',
			0,
			array(),
			array(
				'test_form' => false,
				'mimes'     => array(
					'png'          => 'image/png',
					'jpg|jpeg|jpe' => 'image/jpeg',
					'gif'          => 'image/gif',
					'webp'         => 'image/webp',
					'pdf'          => 'application/pdf',
				),
			)
		);

		if ( is_wp_error( $attachment ) ) {
			return $refused;
		}

		$image = wp_attachment_is_image( $attachment );

		if ( ! $image && 'application/pdf' !== get_post_mime_type( $attachment ) ) {
			wp_delete_attachment( $attachment, true );

			return $refused;
		}

		$url = wp_get_attachment_url( $attachment );

		if ( ! is_string( $url ) ) {
			return $refused;
		}

		return array(
			'url'     => $url,
			'image'   => $image,
			'refused' => false,
		);
	}

	/**
	 * Who this site says is asking.
	 *
	 * Their display name on their own WordPress, which is what a person at the
	 * studio needs in order to reply to a human rather than to a site. It is
	 * recorded as a name and used as one — NOTIF-1 resolves who to write to
	 * from verified client records, never from something typed on the far side
	 * of a connection.
	 *
	 * @return string
	 */
	private static function who(): string {
		$user = wp_get_current_user();

		return $user instanceof \WP_User ? (string) $user->display_name : '';
	}

	/**
	 * Returns to the form with the outcome, and stops.
	 *
	 * @param string               $result One of the result codes the screen knows.
	 * @param array<string, mixed> $keep   What to put back in the form.
	 */
	private static function back( string $result, array $keep ): void {
		set_transient( AskScreen::DRAFT, $keep, 5 * MINUTE_IN_SECONDS );

		wp_safe_redirect( AskScreen::url( $result ) );
		exit;
	}
}
