<?php
/**
 * Slack, on a person's WordPress profile.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Admin;

use Blueworx\Forge\Slack\Morning;
use Blueworx\Forge\Slack\Notify;
use Blueworx\Forge\Slack\People;
use Blueworx\Forge\Tenancy\Users;
use WP_Error;
use WP_User;

/**
 * A person's own Slack connection lives where their other settings do: the
 * profile page. They paste a webhook, tick what they want to hear about, and
 * save the profile. An administrator editing someone's profile sees the same
 * section and can cut them off or set them up.
 *
 * The webhook is sealed on save and never printed again; the field is always
 * empty on the way out, and only writes when something is typed into it.
 */
final class ProfileSlack {

	/**
	 * Hooks the section into both profile screens.
	 */
	public static function boot(): void {
		add_action( 'show_user_profile', array( self::class, 'render' ) );
		add_action( 'edit_user_profile', array( self::class, 'render' ) );
		add_action( 'user_profile_update_errors', array( self::class, 'save' ), 10, 3 );
	}

	/**
	 * Prints the section.
	 *
	 * @param WP_User $user Whose profile it is.
	 */
	public static function render( WP_User $user ): void {
		$me = Users::by_wp_user( (int) $user->ID );

		echo '<h2 id="bwx-forge-slack">' . esc_html__( 'Slack', 'blueworx-forge' ) . '</h2>';

		if ( null === $me ) {
			echo '<p class="description" data-bwx-slack="nobody">' . esc_html__( 'This account is not a person in Forge yet, so there is nothing to connect Slack to.', 'blueworx-forge' ) . '</p>';

			return;
		}

		$person    = People::get( (string) $me['id'] );
		$connected = null !== $person;
		$prefs     = null === $person ? People::prefs_from( array() ) : $person['prefs'];

		if ( $connected ) {
			$status = '' !== (string) $person['last_error']
				/* translators: %s: what Slack said */
				? sprintf( __( 'Connected, but the last message failed: %s', 'blueworx-forge' ), (string) $person['last_error'] )
				: ( 0 < (int) $person['last_ok_at']
					/* translators: %s: date and time */
					? sprintf( __( 'Connected. Last message %s.', 'blueworx-forge' ), wp_date( 'j M H:i', (int) $person['last_ok_at'] ) )
					: __( 'Connected.', 'blueworx-forge' ) );
		} else {
			$status = __( 'Not connected.', 'blueworx-forge' );
		}

		echo '<p data-bwx-slack="' . ( $connected ? 'connected' : 'not-connected' ) . '">' . esc_html( $status ) . '</p>';

		echo '<table class="form-table" role="presentation">';

		echo '<tr><th><label for="bwx-forge-slack-url">' . esc_html__( 'Webhook URL', 'blueworx-forge' ) . '</label></th><td>';
		echo '<input type="url" id="bwx-forge-slack-url" name="bwx_forge_slack_url" class="regular-text" placeholder="https://hooks.slack.com/services/…" autocomplete="off">';
		echo '<p class="description">';
		printf(
			/* translators: %s: link to Slack's incoming webhooks page */
			esc_html__( 'Create an %s in Slack for the channel or DM you want Forge to post to, and paste it here. Forge keeps it sealed and never shows it again; saving sends a test message.', 'blueworx-forge' ),
			'<a href="https://api.slack.com/messaging/webhooks" target="_blank" rel="noreferrer">' . esc_html__( 'incoming webhook', 'blueworx-forge' ) . '</a>'
		);
		echo '</p></td></tr>';

		echo '<tr><th>' . esc_html__( 'Tell me about', 'blueworx-forge' ) . '</th><td><fieldset>';

		foreach ( self::labels() as $key => $label ) {
			echo '<p><label><input type="checkbox" id="bwx-forge-slack-pref-' . esc_attr( $key ) . '" name="bwx_forge_slack_prefs[' . esc_attr( $key ) . ']" value="1"' . checked( ! empty( $prefs[ $key ] ), true, false ) . '> ' . esc_html( $label );

			if ( 'morning' === $key ) {
				echo ' <span class="description">(' . esc_html( Morning::time() ) . ')</span>';
			}

			echo '</label></p>';
		}

		// Tells save() the boxes were on the page, so an unticked one is a no.
		echo '<input type="hidden" name="bwx_forge_slack_prefs_sent" value="1">';
		echo '</fieldset></td></tr>';

		if ( $connected ) {
			echo '<tr><th>' . esc_html__( 'Disconnect', 'blueworx-forge' ) . '</th><td>';
			echo '<label><input type="checkbox" id="bwx-forge-slack-disconnect" name="bwx_forge_slack_disconnect" value="1"> ' . esc_html__( 'Forget my webhook and stop messaging me', 'blueworx-forge' ) . '</label>';
			echo '</td></tr>';
		}

		echo '</table>';
	}

	/**
	 * Saves what the section asked, while the profile is being saved.
	 *
	 * Runs on the profile's own validation hook, after WordPress has checked
	 * the form's nonce and that the person saving may edit this user. A
	 * webhook that is not Slack's, or one Slack will not take, is reported
	 * through the profile's own errors so it is seen.
	 *
	 * @param WP_Error $errors The profile's errors so far.
	 * @param bool     $update Whether this is an existing user.
	 * @param object   $user   The user being saved, with ID.
	 */
	public static function save( WP_Error $errors, bool $update, object $user ): void {
		$wp_user_id = (int) ( $user->ID ?? 0 );

		if ( ! $update || $wp_user_id <= 0 || ! current_user_can( 'edit_user', $wp_user_id ) ) {
			return;
		}

		$me = Users::by_wp_user( $wp_user_id );

		if ( null === $me ) {
			return;
		}

		$user_id = (string) $me['id'];

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- The profile form's nonce was checked by WordPress before this hook fires.
		if ( ! empty( $_POST['bwx_forge_slack_disconnect'] ) ) {
			People::disconnect( $user_id );

			return;
		}

		$url = isset( $_POST['bwx_forge_slack_url'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['bwx_forge_slack_url'] ) ) ) : '';

		if ( '' !== $url ) {
			if ( ! People::acceptable( $url ) ) {
				$errors->add( 'bwx_forge_slack_webhook', __( 'That is not a Slack incoming webhook. It starts with https://hooks.slack.com/.', 'blueworx-forge' ) );

				return;
			}

			People::connect( $user_id, $url );

			$sent = Notify::test( $user_id, $url );

			if ( true !== $sent ) {
				/* translators: %s: what went wrong */
				$errors->add( 'bwx_forge_slack_webhook', sprintf( __( 'Slack could not be reached with that webhook: %s', 'blueworx-forge' ), $sent->get_error_message() ) );
			}
		}

		if ( ! empty( $_POST['bwx_forge_slack_prefs_sent'] ) && null !== People::get( $user_id ) ) {
			$ticked = isset( $_POST['bwx_forge_slack_prefs'] ) && is_array( $_POST['bwx_forge_slack_prefs'] ) ? array_map( 'sanitize_key', array_keys( wp_unslash( $_POST['bwx_forge_slack_prefs'] ) ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only the keys are read, and each is sanitised.
			$prefs  = array();

			foreach ( People::PREFS as $key ) {
				$prefs[ $key ] = in_array( $key, $ticked, true );
			}

			People::set_prefs( $user_id, $prefs );
		}
		// phpcs:enable
	}

	/**
	 * What each preference is called.
	 *
	 * @return array<string, string>
	 */
	private static function labels(): array {
		return array(
			'assigned' => __( 'Work assigned to me, or arriving for me', 'blueworx-forge' ),
			'ready'    => __( 'Something ready for my review or delivery', 'blueworx-forge' ),
			'comment'  => __( 'Comments on tasks I hold a seat on', 'blueworx-forge' ),
			'morning'  => __( 'A morning message of what is due today', 'blueworx-forge' ),
		);
	}
}
