<?php
/**
 * Sending what a client asked for to the studio.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client\Admin;

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

		$sent = array(
			'type'            => isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '',
			'title'           => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
			'description'     => isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '',
			'desired_outcome' => isset( $_POST['desired_outcome'] ) ? sanitize_textarea_field( wp_unslash( $_POST['desired_outcome'] ) ) : '',
			'evidence'        => isset( $_POST['evidence'] ) ? sanitize_textarea_field( wp_unslash( $_POST['evidence'] ) ) : '',
			'submitted_by'    => self::who(),
		);

		$answer = Submission::send( $sent );

		if ( ! $answer['ok'] ) {
			// The words come back with the failure. Somebody who has just
			// written three paragraphs should not have to write them again
			// because a network call failed.
			self::back( $answer['result'], $sent );
		}

		self::back( 'sent', array() );
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
