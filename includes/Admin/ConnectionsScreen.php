<?php
/**
 * The studio's connections screen.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Admin;

use Blueworx\Forge\Commerce\SureCart\Connections;
use Blueworx\Forge\Tenancy\Users;

/**
 * Where outside services are plugged in — SureCart stores for now. A
 * WordPress admin screen per ARCH-7: this configures the system, it is not
 * work anybody does for a client.
 *
 * A token is entered here and never shown again. The screen says whether
 * the last refresh worked and how many subscriptions it saw, which is all
 * anybody needs to know about a token that is working.
 */
final class ConnectionsScreen {

	/**
	 * The admin page slug.
	 */
	public const SLUG = 'blueworx-forge-connections';

	/**
	 * Adds the menu entry.
	 */
	public static function register(): void {
		add_submenu_page(
			SitesScreen::SLUG,
			__( 'Connections', 'blueworx-forge' ),
			__( 'Connections', 'blueworx-forge' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * This screen's URL, optionally carrying a result to report.
	 *
	 * @param string $notice A result code, or an empty string.
	 * @return string
	 */
	public static function url( string $notice = '' ): string {
		$url = admin_url( 'admin.php?page=' . self::SLUG );

		return '' === $notice ? $url : add_query_arg( 'bwx-result', $notice, $url );
	}

	/**
	 * Renders the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		Page::open(
			__( 'Connections', 'blueworx-forge' ),
			__( 'Forge', 'blueworx-forge' ),
			__( 'Outside services Forge reads from, and who each one\'s reminders are for.', 'blueworx-forge' )
		);

		self::notice();

		Page::panel_open( __( 'SureCart stores', 'blueworx-forge' ), 'surecart' );
		self::stores();
		Page::panel_close();

		self::add_store_form();

		Page::close();
	}

	/**
	 * The outcome of the last action, if there was one.
	 */
	private static function notice(): void {
		$result = isset( $_GET['bwx-result'] ) ? sanitize_key( wp_unslash( $_GET['bwx-result'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reporting the outcome of an action that carried its own nonce.
		$count  = isset( $_GET['bwx-count'] ) ? absint( wp_unslash( $_GET['bwx-count'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- same.

		$messages = array(
			'added'     => array( 'success', __( 'Store connected. Its subscriptions will be read on the next refresh.', 'blueworx-forge' ) ),
			'saved'     => array( 'success', __( 'Saved.', 'blueworx-forge' ) ),
			'removed'   => array( 'success', __( 'Store removed. Its reminders have been ended.', 'blueworx-forge' ) ),
			/* translators: %d: number of active subscriptions */
			'connected' => array( 'success', sprintf( __( 'Connected — %d active subscriptions.', 'blueworx-forge' ), $count ) ),
			/* translators: %d: number of active subscriptions */
			'refreshed' => array( 'success', sprintf( __( 'Refreshed — %d active subscriptions.', 'blueworx-forge' ), $count ) ),
			'failed'    => array( 'danger', __( 'SureCart could not be reached with that token. The store\'s row below says why.', 'blueworx-forge' ) ),
			'invalid'   => array( 'danger', __( 'That could not be saved. A name, a token and somebody to check the payment are all needed.', 'blueworx-forge' ) ),
			'unknown'   => array( 'danger', __( 'No such store.', 'blueworx-forge' ) ),
			'stale'     => array( 'danger', __( 'That changed elsewhere first — reload and try again.', 'blueworx-forge' ) ),
		);

		if ( ! isset( $messages[ $result ] ) ) {
			return;
		}

		Page::notice( $messages[ $result ][0], $messages[ $result ][1], array( 'data-bwx-result' => $result ) );
	}

	/**
	 * The connected stores.
	 */
	private static function stores(): void {
		$stores = Connections::all();
		$people = self::people();

		if ( array() === $stores ) {
			echo '<div class="bw-empty">';
			echo '<p class="bw-empty__title">' . esc_html__( 'No store is connected yet', 'blueworx-forge' ) . '</p>';
			echo '<p class="bw-empty__body">' . esc_html__( 'Connect one below with an API token from SureCart → Settings → API.', 'blueworx-forge' ) . '</p>';
			echo '</div>';

			return;
		}

		echo '<ul class="bw-panels" data-bwx-stores="1">';

		foreach ( $stores as $store ) {
			$settings = (array) $store['settings'];

			echo '<li class="bw-panel__loose" data-bwx-store="' . esc_attr( (string) $store['id'] ) . '">';
			echo '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">';
			echo '<strong data-bwx-store-name>' . esc_html( (string) $store['name'] ) . '</strong> ';

			if ( '' !== (string) $store['last_error'] ) {
				echo '<span class="bw-chip bw-chip--danger" data-bwx-store-state="failed">' . esc_html( (string) $store['last_error'] ) . '</span>';
			} elseif ( 0 < (int) $store['last_ok_at'] ) {
				printf(
					'<span class="bw-chip bw-chip--success" data-bwx-store-state="ok">%s</span>',
					/* translators: 1: number of active subscriptions, 2: date and time */
					esc_html( sprintf( __( '%1$d active · refreshed %2$s', 'blueworx-forge' ), (int) $store['last_count'], wp_date( 'j M H:i', (int) $store['last_ok_at'] ) ) )
				);
			} else {
				echo '<span class="bw-chip bw-chip--plain" data-bwx-store-state="new">' . esc_html__( 'Not refreshed yet', 'blueworx-forge' ) . '</span>';
			}

			echo '</div>';

			echo '<p class="bw-muted">';
			echo esc_html(
				sprintf(
					/* translators: 1: who checks, 2: who reviews, 3: who ships */
					__( 'Reminders go to %1$s (checks), %2$s (reviews), %3$s (ships).', 'blueworx-forge' ),
					$people[ (string) ( $settings['primary_user_id'] ?? '' ) ] ?? __( 'nobody', 'blueworx-forge' ),
					$people[ (string) ( $settings['reviewer_id'] ?? '' ) ] ?? __( 'nobody', 'blueworx-forge' ),
					$people[ (string) ( $settings['deliverer_id'] ?? '' ) ] ?? __( 'nobody', 'blueworx-forge' )
				)
			);
			echo '</p>';

			echo '<div style="display:flex;gap:8px;flex-wrap:wrap;margin:8px 0">';
			self::button_form( 'bwx_forge_test_connection', $store, __( 'Test', 'blueworx-forge' ), 'test' );
			self::button_form( 'bwx_forge_refresh_connection', $store, __( 'Refresh now', 'blueworx-forge' ), 'refresh' );
			self::button_form( 'bwx_forge_remove_connection', $store, __( 'Remove', 'blueworx-forge' ), 'remove', true );
			echo '</div>';

			Page::accordion_open( __( 'Edit staff and token', 'blueworx-forge' ), array( 'data-bwx-store-edit' => (string) $store['id'] ) );
			self::edit_form( $store, $people );
			Page::accordion_close();

			echo '</li>';
		}

		echo '</ul>';
	}

	/**
	 * One-button forms for test, refresh and remove.
	 *
	 * @param string               $action  The admin-post action.
	 * @param array<string, mixed> $store   The store.
	 * @param string               $label   Button label.
	 * @param string               $name    The data-bwx-action value.
	 * @param bool                 $confirm Whether to ask first.
	 */
	private static function button_form( string $action, array $store, string $label, string $name, bool $confirm = false ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline"';

		if ( $confirm ) {
			echo ' onsubmit="return window.confirm(' . esc_attr( wp_json_encode( __( 'Remove this store? Its renewal reminders will stop.', 'blueworx-forge' ) ) ) . ')"';
		}

		echo '>';
		wp_nonce_field( $action . '_' . (string) $store['id'] );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		echo '<input type="hidden" name="connection_id" value="' . esc_attr( (string) $store['id'] ) . '">';
		submit_button( $label, 'bw-btn bw-btn--secondary', 'submit', false, array( 'data-bwx-action' => $name ) );
		echo '</form> ';
	}

	/**
	 * The form that changes a store's name, seats, hours and (if filled) token.
	 *
	 * @param array<string, mixed>  $store  The store.
	 * @param array<string, string> $people Active people, id to name.
	 */
	private static function edit_form( array $store, array $people ): void {
		$settings = (array) $store['settings'];

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-bwx-edit-store="' . esc_attr( (string) $store['id'] ) . '">';
		wp_nonce_field( 'bwx_forge_edit_connection_' . (string) $store['id'] );
		echo '<input type="hidden" name="action" value="bwx_forge_edit_connection">';
		echo '<input type="hidden" name="connection_id" value="' . esc_attr( (string) $store['id'] ) . '">';
		echo '<input type="hidden" name="record_version" value="' . esc_attr( (string) $store['record_version'] ) . '">';

		self::text_row( 'name', __( 'Name', 'blueworx-forge' ), (string) $store['name'], true );
		self::text_row( 'token', __( 'Replace token (leave blank to keep)', 'blueworx-forge' ), '', false, 'password' );
		self::seat_rows( $people, $settings );

		echo '<div style="margin-top:12px">';
		submit_button( __( 'Save', 'blueworx-forge' ), 'bw-btn bw-btn--primary', 'submit', false );
		echo '</div></form>';
	}

	/**
	 * The form that connects a new store.
	 */
	private static function add_store_form(): void {
		Page::panel_open( __( 'Connect a SureCart store', 'blueworx-forge' ), 'add-store', array( 'data-bwx-add-store' => '1' ) );

		wp_nonce_field( 'bwx_forge_add_connection' );
		echo '<input type="hidden" name="action" value="bwx_forge_add_connection">';

		echo '<p class="bw-muted">' . esc_html__( 'The token comes from SureCart → Settings → API on the store\'s own site. It is kept sealed and never shown again.', 'blueworx-forge' ) . '</p>';

		self::text_row( 'name', __( 'Name', 'blueworx-forge' ), '', true );
		self::text_row( 'token', __( 'API token', 'blueworx-forge' ), '', true, 'password' );
		self::seat_rows( self::people(), array() );

		Page::actions_open();
		submit_button( __( 'Connect store', 'blueworx-forge' ), 'bw-btn bw-btn--primary', 'submit', false );
		Page::panel_close();
	}

	/**
	 * A text field row.
	 *
	 * @param string $name     Field name.
	 * @param string $label    Label.
	 * @param string $value    Current value.
	 * @param bool   $required Whether the browser insists on it.
	 * @param string $type     Input type.
	 */
	private static function text_row( string $name, string $label, string $value, bool $required, string $type = 'text' ): void {
		$id = 'bwx-store-' . $name . '-' . wp_unique_id();

		echo '<div class="bw-formrow">';
		echo '<label class="bw-formrow__label" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
		echo '<div class="bw-formrow__control"><input type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" class="bw-input" value="' . esc_attr( $value ) . '" autocomplete="off"' . ( $required ? ' required' : '' ) . '></div>';
		echo '</div>';
	}

	/**
	 * The three seats with their hours.
	 *
	 * @param array<string, string> $people   Active people, id to name.
	 * @param array<string, mixed>  $settings Current settings, or empty.
	 */
	private static function seat_rows( array $people, array $settings ): void {
		$seats = array(
			'primary_user_id' => array( __( 'Checks the payment', 'blueworx-forge' ), 'hours_primary', '0.25' ),
			'reviewer_id'     => array( __( 'Reviews (optional)', 'blueworx-forge' ), 'hours_review', '0' ),
			'deliverer_id'    => array( __( 'Ships (optional)', 'blueworx-forge' ), 'hours_delivery', '0' ),
		);

		foreach ( $seats as $seat => list( $label, $hours, $fallback ) ) {
			$id = 'bwx-store-' . $seat . '-' . wp_unique_id();

			echo '<div class="bw-formrow">';
			echo '<label class="bw-formrow__label" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
			echo '<div class="bw-formrow__control" style="display:flex;gap:8px">';
			echo '<div class="bw-select" style="flex:1"><select class="bw-select__el" id="' . esc_attr( $id ) . '" name="' . esc_attr( $seat ) . '">';
			echo '<option value="">' . esc_html__( 'Nobody', 'blueworx-forge' ) . '</option>';

			foreach ( $people as $person_id => $person_name ) {
				echo '<option value="' . esc_attr( $person_id ) . '"' . selected( (string) ( $settings[ $seat ] ?? '' ), $person_id, false ) . '>' . esc_html( $person_name ) . '</option>';
			}

			echo '</select><i class="bw-icon bw-select__arrow" data-lucide="chevron-down"></i></div>';
			echo '<input type="number" min="0" step="0.25" name="' . esc_attr( $hours ) . '" class="bw-input" style="width:90px" aria-label="' . esc_attr( $label . ' ' . __( 'hours', 'blueworx-forge' ) ) . '" value="' . esc_attr( (string) ( $settings[ $hours ] ?? $fallback ) ) . '">';
			echo '</div></div>';
		}
	}

	/**
	 * Active people, id to name.
	 *
	 * @return array<string, string>
	 */
	private static function people(): array {
		$people = array();

		foreach ( Users::all( 'active' ) as $user ) {
			$people[ (string) $user['id'] ] = (string) $user['display_name'];
		}

		return $people;
	}
}
