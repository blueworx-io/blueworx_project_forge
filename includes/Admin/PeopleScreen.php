<?php
/**
 * The studio's people screen.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Admin;

use Blueworx\Forge\Tenancy\Clients;
use Blueworx\Forge\Tenancy\Memberships;
use Blueworx\Forge\Tenancy\Roles;
use Blueworx\Forge\Tenancy\Users;

/**
 * Adding a person, and seeing everywhere they work (#90).
 *
 * The listing is built around the thing AUTH-6 exists for: one person, one row,
 * with every client they touch shown beneath their name. If somebody appears
 * twice on this screen, something has gone wrong that capacity and attribution
 * will both inherit — so the screen is the place it shows.
 *
 * Deliberately a plain WordPress admin screen, the same shape as the clients
 * screen: an operational tool for us, not part of the product's designed
 * interface.
 */
final class PeopleScreen {

	/**
	 * The admin page slug.
	 */
	public const SLUG = 'blueworx-forge-people';

	/**
	 * Adds the menu entry, beneath the Forge menu.
	 */
	public static function register(): void {
		add_submenu_page(
			SitesScreen::SLUG,
			__( 'People', 'blueworx-forge' ),
			__( 'People', 'blueworx-forge' ),
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

		$status = self::status_filter();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Forge — people', 'blueworx-forge' ) . '</h1>';

		self::notice();
		self::status_toggle_link( $status );
		self::people_list( $status );
		self::add_person_form();

		echo '</div>';
	}

	/**
	 * The status filter in effect, from the URL.
	 *
	 * @return string 'active' or 'all'.
	 */
	private static function status_filter(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only view filter.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'active';

		return 'all' === $status ? 'all' : 'active';
	}

	/**
	 * The link between the two views. Without it an offboarded person is
	 * unreachable from this screen, and bringing somebody back is impossible.
	 *
	 * @param string $status The status filter in effect.
	 */
	private static function status_toggle_link( string $status ): void {
		if ( 'all' === $status ) {
			$url   = admin_url( 'admin.php?page=' . self::SLUG );
			$label = __( 'Show active only', 'blueworx-forge' );
		} else {
			$url   = add_query_arg( 'status', 'all', admin_url( 'admin.php?page=' . self::SLUG ) );
			$label = __( 'Show all, including offboarded', 'blueworx-forge' );
		}

		echo '<p><a href="' . esc_url( $url ) . '" data-bwx-status-toggle="' . esc_attr( $status ) . '">' . esc_html( $label ) . '</a></p>';
	}

	/**
	 * The outcome of the last action, if there was one.
	 */
	private static function notice(): void {
		// A result code from the fixed list below, never free text: it comes off
		// the URL, so anything it can say is something anyone can make an
		// administrator's screen say.
		$result = isset( $_GET['bwx_notice'] ) ? sanitize_key( wp_unslash( $_GET['bwx_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reporting the outcome of an action that carried its own nonce.

		$messages = array(
			'added'     => array( 'success', __( 'Saved.', 'blueworx-forge' ) ),
			'invalid'   => array( 'error', __( 'That could not be saved.', 'blueworx-forge' ) ),
			'stale'     => array( 'error', __( 'That changed elsewhere first — reload and try again.', 'blueworx-forge' ) ),
			'unknown'   => array( 'error', __( 'No such record.', 'blueworx-forge' ) ),
			'duplicate' => array( 'error', __( 'Somebody already has that email address.', 'blueworx-forge' ) ),
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
	 * Everyone, each with everywhere they work.
	 *
	 * @param string $status The status filter in effect.
	 */
	private static function people_list( string $status ): void {
		$people = Users::all( 'all' === $status ? null : 'active' );

		if ( array() === $people ) {
			echo '<p data-bwx-no-people="1">' . esc_html__( 'Nobody yet.', 'blueworx-forge' ) . '</p>';

			return;
		}

		// Read once and looked up per membership: a screen listing thirty people
		// should not cost a query per client name.
		$clients = array();

		foreach ( Clients::all( null ) as $client ) {
			$clients[ (string) $client['id'] ] = (string) $client['display_name'];
		}

		echo '<ul data-bwx-people="1">';

		foreach ( $people as $person ) {
			$label = 'active' === (string) $person['status']
				? __( 'Active', 'blueworx-forge' )
				: __( 'Offboarded', 'blueworx-forge' );

			echo '<li data-bwx-person="' . esc_attr( (string) $person['id'] ) . '">';
			echo '<strong data-bwx-person-name>' . esc_html( (string) $person['display_name'] ) . '</strong> ';
			echo '<span data-bwx-person-email>' . esc_html( (string) $person['email'] ) . '</span> ';
			echo '<span data-bwx-status>' . esc_html( $label ) . '</span> ';

			self::offboard_form( $person );
			self::edit_person_form( $person );
			self::memberships_list( $person, $clients, $status );

			echo '</li>';
		}

		echo '</ul>';
	}

	/**
	 * Everywhere one person works. This is the view a per-client account model
	 * cannot produce at all, and the reason #90 exists.
	 *
	 * @param array<string, mixed>  $person  The person.
	 * @param array<string, string> $clients Client id to name.
	 * @param string                $status  The status filter in effect.
	 */
	private static function memberships_list( array $person, array $clients, string $status ): void {
		$held = Memberships::for_user( (string) $person['id'], 'all' === $status ? null : 'active' );

		if ( array() === $held ) {
			echo '<p data-bwx-no-memberships="1">' . esc_html__( 'No client access yet.', 'blueworx-forge' ) . '</p>';

			return;
		}

		echo '<ul data-bwx-memberships="1">';

		foreach ( $held as $membership ) {
			$client = $clients[ (string) $membership['client_id'] ] ?? __( 'Unknown client', 'blueworx-forge' );

			echo '<li data-bwx-membership="' . esc_attr( (string) $membership['id'] ) . '" data-bwx-membership-role="' . esc_attr( (string) $membership['role'] ) . '">';
			echo '<span data-bwx-membership-client>' . esc_html( $client ) . '</span> — ';
			echo '<span data-bwx-membership-role-label>' . esc_html( (string) $membership['role_label'] ) . '</span> ';

			// Named site or whole client: the distinction matters enough to say
			// out loud, because one of them will grow a second site later.
			echo '<span data-bwx-membership-scope>';
			echo '' === (string) $membership['client_site_id']
				? esc_html__( 'every site', 'blueworx-forge' )
				: esc_html__( 'one site', 'blueworx-forge' );
			echo '</span> ';

			echo '<span data-bwx-status>' . esc_html( 'active' === (string) $membership['status'] ? __( 'Active', 'blueworx-forge' ) : __( 'Ended', 'blueworx-forge' ) ) . '</span>';
			echo '</li>';
		}

		echo '</ul>';
	}

	/**
	 * The form that adds a person.
	 */
	private static function add_person_form(): void {
		echo '<h2>' . esc_html__( 'Add a person', 'blueworx-forge' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-bwx-add-person>';
		wp_nonce_field( 'bwx_forge_add_person' );
		echo '<input type="hidden" name="action" value="bwx_forge_add_person">';
		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row"><label for="bwx-person-name">' . esc_html__( 'Name', 'blueworx-forge' ) . '</label></th>';
		echo '<td><input type="text" id="bwx-person-name" name="display_name" class="regular-text" required></td></tr>';

		echo '<tr><th scope="row"><label for="bwx-person-email">' . esc_html__( 'Email', 'blueworx-forge' ) . '</label></th>';
		echo '<td><input type="email" id="bwx-person-email" name="email" class="regular-text" required>';
		echo '<p class="description">' . esc_html__( 'One person, one address, however many clients they work with.', 'blueworx-forge' ) . '</p></td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Add person', 'blueworx-forge' ) );
		echo '</form>';
	}

	/**
	 * The form that offboards somebody: the account and every membership with
	 * it, in one action (AUTH-6).
	 *
	 * @param array<string, mixed> $person The person.
	 */
	private static function offboard_form( array $person ): void {
		if ( 'active' !== (string) $person['status'] ) {
			return;
		}

		$id = (string) $person['id'];

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
		wp_nonce_field( 'bwx_forge_offboard_person_' . $id );
		echo '<input type="hidden" name="action" value="bwx_forge_offboard_person">';
		echo '<input type="hidden" name="user_id" value="' . esc_attr( $id ) . '">';
		echo '<input type="hidden" name="record_version" value="' . esc_attr( (string) $person['record_version'] ) . '">';
		echo '<button type="submit" class="button" data-bwx-offboard onclick="return confirm(' . esc_attr( (string) wp_json_encode( __( 'Offboard this person? Their access to every client ends; their history stays.', 'blueworx-forge' ) ) ) . ')">';
		echo esc_html__( 'Offboard', 'blueworx-forge' );
		echo '</button>';
		echo '</form> ';
	}

	/**
	 * The form that edits somebody, including bringing them back.
	 *
	 * @param array<string, mixed> $person The person.
	 */
	private static function edit_person_form( array $person ): void {
		$id = (string) $person['id'];

		echo '<details data-bwx-edit-person="' . esc_attr( $id ) . '">';
		echo '<summary>' . esc_html__( 'Edit', 'blueworx-forge' ) . '</summary>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'bwx_forge_edit_person_' . $id );
		echo '<input type="hidden" name="action" value="bwx_forge_edit_person">';
		echo '<input type="hidden" name="user_id" value="' . esc_attr( $id ) . '">';
		echo '<input type="hidden" name="record_version" value="' . esc_attr( (string) $person['record_version'] ) . '">';
		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row"><label for="bwx-edit-person-name-' . esc_attr( $id ) . '">' . esc_html__( 'Name', 'blueworx-forge' ) . '</label></th>';
		echo '<td><input type="text" id="bwx-edit-person-name-' . esc_attr( $id ) . '" name="display_name" class="regular-text" value="' . esc_attr( (string) $person['display_name'] ) . '" required></td></tr>';

		echo '<tr><th scope="row"><label for="bwx-edit-person-email-' . esc_attr( $id ) . '">' . esc_html__( 'Email', 'blueworx-forge' ) . '</label></th>';
		echo '<td><input type="email" id="bwx-edit-person-email-' . esc_attr( $id ) . '" name="email" class="regular-text" value="' . esc_attr( (string) $person['email'] ) . '" required></td></tr>';

		echo '<tr><th scope="row"><label for="bwx-edit-person-status-' . esc_attr( $id ) . '">' . esc_html__( 'Status', 'blueworx-forge' ) . '</label></th>';
		echo '<td><select id="bwx-edit-person-status-' . esc_attr( $id ) . '" name="status">';
		echo '<option value="active"' . selected( 'active', (string) $person['status'], false ) . '>' . esc_html__( 'Active', 'blueworx-forge' ) . '</option>';
		echo '<option value="inactive"' . selected( 'inactive', (string) $person['status'], false ) . '>' . esc_html__( 'Offboarded', 'blueworx-forge' ) . '</option>';
		echo '</select></td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Save', 'blueworx-forge' ), 'secondary', '', false );
		echo '</form>';
		echo '</details>';
	}

	/**
	 * The role options, shared with the clients screen's add-membership form.
	 *
	 * @param string $selected The role to pre-select.
	 */
	public static function role_options( string $selected = Roles::STAFF ): void {
		foreach ( Roles::ALL as $role ) {
			echo '<option value="' . esc_attr( $role ) . '"' . selected( $selected, $role, false ) . '>' . esc_html( Roles::label( $role ) ) . '</option>';
		}
	}
}
