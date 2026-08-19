<?php
/**
 * The studio's clients screen.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Admin;

use Blueworx\Forge\Tenancy\ClientSites;
use Blueworx\Forge\Tenancy\Clients;

/**
 * Adding a client, and the sites beneath it, in the browser.
 *
 * Deliberately a plain WordPress admin screen, the same shape as the client
 * sites screen (#83, #195): this is an operational tool for us, not part of
 * the product's designed interface, and it should not wait on that design or
 * carry a build step of its own.
 */
final class ClientsScreen {

	/**
	 * The admin page slug.
	 */
	public const SLUG = 'blueworx-forge-clients';

	/**
	 * Adds the menu entry, beneath the Forge menu the sites screen creates.
	 */
	public static function register(): void {
		add_submenu_page(
			SitesScreen::SLUG,
			__( 'Clients', 'blueworx-forge' ),
			__( 'Clients', 'blueworx-forge' ),
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

		return '' === $notice ? $url : add_query_arg( 'bwx_notice', $notice, $url );
	}

	/**
	 * Renders the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Forge — clients', 'blueworx-forge' ) . '</h1>';

		self::notice();
		self::clients_list();
		self::add_client_form();

		echo '</div>';
	}

	/**
	 * The outcome of the last action, if there was one.
	 */
	private static function notice(): void {
		// A result code chosen from the fixed list below, never free text: it
		// comes off the URL, so anything it can say is something anyone can make
		// an administrator's screen say.
		$result = isset( $_GET['bwx_notice'] ) ? sanitize_key( wp_unslash( $_GET['bwx_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reporting the outcome of an action that carried its own nonce.

		$messages = array(
			'added'   => array( 'success', __( 'Saved.', 'blueworx-forge' ) ),
			'invalid' => array( 'error', __( 'That could not be saved.', 'blueworx-forge' ) ),
			'stale'   => array( 'error', __( 'That changed elsewhere first — reload and try again.', 'blueworx-forge' ) ),
			'unknown' => array( 'error', __( 'No such record.', 'blueworx-forge' ) ),
		);

		if ( ! isset( $messages[ $result ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s" data-bwx-notice="%2$s"><p>%3$s</p></div>',
			esc_attr( $messages[ $result ][0] ),
			esc_attr( $result ),
			esc_html( $messages[ $result ][1] )
		);
	}

	/**
	 * The clients, filtered by status, each with its sites.
	 */
	private static function clients_list(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reads a filter, changes nothing.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'active';
		$status = 'all' === $status ? 'all' : 'active';

		$clients = Clients::all( 'all' === $status ? null : 'active' );

		if ( array() === $clients ) {
			echo '<p data-bwx-no-clients="1">' . esc_html__( 'No clients yet.', 'blueworx-forge' ) . '</p>';

			return;
		}

		echo '<ul data-bwx-clients="1">';

		foreach ( $clients as $client ) {
			self::client_item( $client, $status );
		}

		echo '</ul>';
	}

	/**
	 * One client, its sites, and the forms to act on it.
	 *
	 * @param array<string, mixed> $client The client row.
	 * @param string               $status The status filter in effect.
	 */
	private static function client_item( array $client, string $status ): void {
		$client_id = (string) $client['id'];
		$label     = 'active' === (string) $client['status']
			? __( 'Active', 'blueworx-forge' )
			: __( 'Inactive', 'blueworx-forge' );

		echo '<li data-bwx-client="' . esc_attr( $client_id ) . '">';
		echo '<span data-bwx-client-name>' . esc_html( (string) $client['display_name'] ) . '</span> ';
		echo '<span data-bwx-status>' . esc_html( $label ) . '</span> ';

		self::deactivate_client_form( $client );

		self::sites_list( $client_id, $status );
		self::add_site_form( $client_id );

		echo '</li>';
	}

	/**
	 * The sites under one client.
	 *
	 * @param string $client_id Owning client id.
	 * @param string $status    The status filter in effect.
	 */
	private static function sites_list( string $client_id, string $status ): void {
		$sites = ClientSites::for_client( $client_id, 'all' === $status ? null : 'active' );

		if ( array() === $sites ) {
			return;
		}

		echo '<ul data-bwx-sites="1">';

		foreach ( $sites as $site ) {
			$site_label = 'active' === (string) $site['status']
				? __( 'Active', 'blueworx-forge' )
				: __( 'Inactive', 'blueworx-forge' );

			echo '<li data-bwx-site="' . esc_attr( (string) $site['id'] ) . '">';
			echo '<span data-bwx-site-name>' . esc_html( (string) $site['name'] ) . '</span> ';
			echo '<span data-bwx-status>' . esc_html( $site_label ) . '</span> ';

			self::deactivate_site_form( $site );

			echo '</li>';
		}

		echo '</ul>';
	}

	/**
	 * The form that adds a new client.
	 */
	private static function add_client_form(): void {
		echo '<h2>' . esc_html__( 'Add a client', 'blueworx-forge' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-bwx-add-client>';
		wp_nonce_field( 'bwx_forge_add_client' );
		echo '<input type="hidden" name="action" value="bwx_forge_add_client">';
		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row"><label for="bwx-client-name">' . esc_html__( 'Name', 'blueworx-forge' ) . '</label></th>';
		echo '<td><input type="text" id="bwx-client-name" name="display_name" class="regular-text" required></td></tr>';

		echo '<tr><th scope="row"><label for="bwx-client-timezone">' . esc_html__( 'Timezone', 'blueworx-forge' ) . '</label></th>';
		echo '<td><select id="bwx-client-timezone" name="timezone">';

		foreach ( timezone_identifiers_list() as $timezone ) {
			echo '<option value="' . esc_attr( $timezone ) . '"' . selected( 'UTC', $timezone, false ) . '>' . esc_html( $timezone ) . '</option>';
		}

		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="bwx-client-domains">' . esc_html__( 'Permitted email domains', 'blueworx-forge' ) . '</label></th>';
		echo '<td><input type="text" id="bwx-client-domains" name="email_domains" class="regular-text" placeholder="acme.co.uk, acme.com"></td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Add client', 'blueworx-forge' ) );
		echo '</form>';
	}

	/**
	 * The form that adds a site under one client.
	 *
	 * @param string $client_id Owning client id.
	 */
	private static function add_site_form( string $client_id ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-bwx-add-site>';
		wp_nonce_field( 'bwx_forge_add_client_site_' . $client_id );
		echo '<input type="hidden" name="action" value="bwx_forge_add_client_site">';
		echo '<input type="hidden" name="client_id" value="' . esc_attr( $client_id ) . '">';
		echo '<input type="text" name="name" placeholder="' . esc_attr__( 'Site name', 'blueworx-forge' ) . '" required>';
		echo '<input type="url" name="url" placeholder="https://">';
		submit_button( __( 'Add site', 'blueworx-forge' ), 'secondary', '', false );
		echo '</form>';
	}

	/**
	 * The form that deactivates a client, carrying its current version.
	 *
	 * @param array<string, mixed> $client The client row.
	 */
	private static function deactivate_client_form( array $client ): void {
		if ( 'active' !== (string) $client['status'] ) {
			return;
		}

		$client_id = (string) $client['id'];

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
		wp_nonce_field( 'bwx_forge_deactivate_client_' . $client_id );
		echo '<input type="hidden" name="action" value="bwx_forge_deactivate_client">';
		echo '<input type="hidden" name="client_id" value="' . esc_attr( $client_id ) . '">';
		echo '<input type="hidden" name="record_version" value="' . esc_attr( (string) $client['record_version'] ) . '">';
		echo '<button type="submit" class="button" data-bwx-deactivate-client onclick="return confirm(' . esc_attr( (string) wp_json_encode( __( 'Deactivate this client and every site under it?', 'blueworx-forge' ) ) ) . ')">';
		echo esc_html__( 'Deactivate', 'blueworx-forge' );
		echo '</button>';
		echo '</form>';
	}

	/**
	 * The form that deactivates a single site, carrying its current version.
	 *
	 * @param array<string, mixed> $site The site row.
	 */
	private static function deactivate_site_form( array $site ): void {
		if ( 'active' !== (string) $site['status'] ) {
			return;
		}

		$site_id = (string) $site['id'];

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
		wp_nonce_field( 'bwx_forge_deactivate_client_site_' . $site_id );
		echo '<input type="hidden" name="action" value="bwx_forge_deactivate_client_site">';
		echo '<input type="hidden" name="site_id" value="' . esc_attr( $site_id ) . '">';
		echo '<input type="hidden" name="record_version" value="' . esc_attr( (string) $site['record_version'] ) . '">';
		echo '<button type="submit" class="button" data-bwx-deactivate-site onclick="return confirm(' . esc_attr( (string) wp_json_encode( __( 'Deactivate this site?', 'blueworx-forge' ) ) ) . ')">';
		echo esc_html__( 'Deactivate', 'blueworx-forge' );
		echo '</button>';
		echo '</form>';
	}
}
