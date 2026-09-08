<?php
/**
 * The studio's people screen.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Admin;

use Blueworx\Forge\Tenancy\Clients;
use Blueworx\Forge\Tenancy\Grants;
use Blueworx\Forge\Tenancy\Memberships;
use Blueworx\Forge\Tenancy\Roles;
use Blueworx\Forge\Tenancy\Users;

/**
 * Adding a person, and seeing everywhere they work (#90).
 *
 * The listing is built around the thing AUTH-6 exists for: one person, one
 * card, with every client they touch shown beneath their name. If somebody
 * appears twice on this screen, something has gone wrong that capacity and
 * attribution will both inherit — so the screen is the place it shows.
 *
 * Deliberately a WordPress admin screen rather than a screen in the
 * application, the same shape as the clients screen: an operational tool for
 * us, not part of the product's designed interface.
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

		Page::open(
			__( 'People', 'blueworx-forge' ),
			__( 'Forge', 'blueworx-forge' ),
			__( 'One person, one card, with everywhere they work beneath their name.', 'blueworx-forge' )
		);

		self::notice();
		self::add_person_form();
		self::people_list( $status );

		Page::close();
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
	 * A link rather than the design system's switch, and deliberately: a switch
	 * draws its on state from a checked input, so an anchor wearing one would
	 * read as "off" whichever of the two views you were looking at.
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

		echo '<div class="bw-toolbar bw-toolbar--card"><div class="bw-toolbar__group">';
		printf(
			'<a class="bw-btn bw-btn--secondary" href="%1$s" data-bwx-status-toggle="%2$s">%3$s</a>',
			esc_url( $url ),
			esc_attr( $status ),
			esc_html( $label )
		);
		echo '</div></div>';
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
			'invalid'   => array( 'danger', __( 'That could not be saved.', 'blueworx-forge' ) ),
			'stale'     => array( 'danger', __( 'That changed elsewhere first — reload and try again.', 'blueworx-forge' ) ),
			'unknown'   => array( 'danger', __( 'No such record.', 'blueworx-forge' ) ),
			'duplicate' => array( 'danger', __( 'Somebody already has that email address.', 'blueworx-forge' ) ),
		);

		if ( ! isset( $messages[ $result ] ) ) {
			return;
		}

		Page::notice(
			$messages[ $result ][0],
			$messages[ $result ][1],
			array( 'data-bwx-notice' => $result )
		);
	}

	/**
	 * Everyone, each with everywhere they work.
	 *
	 * The filter panel says which of the two views is showing and offers the
	 * other; the people themselves follow it as cards, one per person.
	 *
	 * @param string $status The status filter in effect.
	 */
	private static function people_list( string $status ): void {
		$people = Users::all( 'all' === $status ? null : 'active' );

		Page::panel_open( __( 'Everyone', 'blueworx-forge' ), 'people' );

		echo '<p class="bw-card__note">';
		echo esc_html(
			'all' === $status
				? __( 'Showing everyone, including people who have been offboarded.', 'blueworx-forge' )
				: __( 'Showing active people only.', 'blueworx-forge' )
		);
		echo '</p>';

		self::status_toggle_link( $status );

		if ( array() === $people ) {
			echo '<div class="bw-empty" data-bwx-no-people="1">';
			echo '<i class="bw-icon bw-empty__icon" data-lucide="users"></i>';
			echo '<p class="bw-empty__title">' . esc_html__( 'Nobody yet', 'blueworx-forge' ) . '</p>';
			echo '<p class="bw-empty__text">' . esc_html__( 'Add somebody above and they will appear here.', 'blueworx-forge' ) . '</p>';
			echo '</div>';
			Page::panel_close();

			return;
		}

		Page::panel_close();

		// Read once and looked up per membership: a screen listing thirty people
		// should not cost a query per client name.
		$clients = array();

		foreach ( Clients::all( null ) as $client ) {
			$clients[ (string) $client['id'] ] = (string) $client['display_name'];
		}

		// A panel column of its own, so every person is a card in the same
		// stack the rest of the page is built from.
		echo '<div class="bw-panels" data-bwx-people="1">';

		foreach ( $people as $person ) {
			self::person_card( $person, $clients, $status );
		}

		echo '</div>';
	}

	/**
	 * One person: who they are, whether they are still with us, everywhere they
	 * work, and the form that edits them.
	 *
	 * @param array<string, mixed>  $person  The person.
	 * @param array<string, string> $clients Client id to name.
	 * @param string                $status  The status filter in effect.
	 */
	private static function person_card( array $person, array $clients, string $status ): void {
		printf( '<section class="bw-card" data-bwx-person="%s">', esc_attr( (string) $person['id'] ) );

		echo '<div class="bw-card__head"><div class="bw-card__titles">';
		printf(
			'<h2 class="bw-card__title" data-bwx-person-name>%s</h2>',
			esc_html( (string) $person['display_name'] )
		);
		printf(
			'<p class="bw-fieldnote" data-bwx-person-email>%s</p>',
			esc_html( (string) $person['email'] )
		);
		echo '</div>';

		echo '<div class="bw-card__actions">';

		// Whole class names rather than a stem with the tone appended, so the
		// admin UI check can read what this screen writes.
		if ( 'active' === (string) $person['status'] ) {
			printf( '<span class="bw-badge" data-bwx-status>%s</span>', esc_html__( 'Active', 'blueworx-forge' ) );
		} else {
			printf( '<span class="bw-badge bw-badge--neutral" data-bwx-status>%s</span>', esc_html__( 'Offboarded', 'blueworx-forge' ) );
		}

		self::offboard_form( $person );

		echo '</div></div>';

		echo '<div class="bw-card__body">';
		self::memberships_list( $person, $clients, $status );
		self::edit_person_form( $person );
		echo '</div>';

		echo '</section>';
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
			echo '<div class="bw-empty" data-bwx-no-memberships="1">';
			echo '<i class="bw-icon bw-empty__icon" data-lucide="users"></i>';
			echo '<p class="bw-empty__title">' . esc_html__( 'No client access yet', 'blueworx-forge' ) . '</p>';
			echo '</div>';

			return;
		}

		echo '<div class="bw-tablescroll">';
		echo '<table class="bw-table" data-bwx-memberships="1"><thead><tr>';
		echo '<th>' . esc_html__( 'Client', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Role', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Reaches', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Grants', 'blueworx-forge' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $held as $membership ) {
			self::membership_row( $membership, $clients );
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * One membership: the client, what they are there, and what they may do.
	 *
	 * @param array<string, mixed>  $membership The membership.
	 * @param array<string, string> $clients    Client id to name.
	 */
	private static function membership_row( array $membership, array $clients ): void {
		$client = $clients[ (string) $membership['client_id'] ] ?? __( 'Unknown client', 'blueworx-forge' );

		printf(
			'<tr data-bwx-membership="%1$s" data-bwx-membership-role="%2$s">',
			esc_attr( (string) $membership['id'] ),
			esc_attr( (string) $membership['role'] )
		);

		printf(
			'<td><span class="bw-table__primary" data-bwx-membership-client>%s</span></td>',
			esc_html( $client )
		);

		printf(
			'<td><span data-bwx-membership-role-label>%s</span></td>',
			esc_html( (string) $membership['role_label'] )
		);

		// Named site or whole client: the distinction matters enough to say
		// out loud, because one of them will grow a second site later.
		echo '<td><span data-bwx-membership-scope>';
		echo '' === (string) $membership['client_site_id']
			? esc_html__( 'every site', 'blueworx-forge' )
			: esc_html__( 'one site', 'blueworx-forge' );
		echo '</span></td>';

		echo '<td>';

		if ( 'active' === (string) $membership['status'] ) {
			printf( '<span class="bw-badge" data-bwx-status>%s</span>', esc_html__( 'Active', 'blueworx-forge' ) );
		} else {
			printf( '<span class="bw-badge bw-badge--neutral" data-bwx-status>%s</span>', esc_html__( 'Ended', 'blueworx-forge' ) );
		}

		echo '</td>';

		echo '<td><div class="bw-chips">';

		foreach ( Grants::parse( (string) ( $membership['grants'] ?? '' ) ) as $grant ) {
			printf(
				'<span class="bw-chip bw-chip--plain" data-bwx-membership-grant="%1$s">%2$s</span>',
				esc_attr( $grant ),
				esc_html( Grants::label( $grant ) )
			);
		}

		echo '</div>';

		self::membership_grants_form( $membership );

		echo '</td></tr>';
	}

	/**
	 * The form that adds a person.
	 */
	private static function add_person_form(): void {
		echo '<section class="bw-card" data-bwx-panel="add-person">';
		echo '<div class="bw-card__head"><div class="bw-card__titles">';
		echo '<h2 class="bw-card__title">' . esc_html__( 'Add a person', 'blueworx-forge' ) . '</h2>';
		echo '</div></div>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-bwx-add-person>';
		wp_nonce_field( 'bwx_forge_add_person' );
		echo '<input type="hidden" name="action" value="bwx_forge_add_person">';

		echo '<div class="bw-card__body">';

		echo '<div class="bw-formrow">';
		echo '<label class="bw-formrow__label" for="bwx-person-name">' . esc_html__( 'Name', 'blueworx-forge' ) . '</label>';
		echo '<div class="bw-formrow__control">';
		echo '<input type="text" id="bwx-person-name" name="display_name" class="bw-input" required>';
		echo '</div></div>';

		echo '<div class="bw-formrow">';
		echo '<label class="bw-formrow__label" for="bwx-person-email">' . esc_html__( 'Email', 'blueworx-forge' ) . '</label>';
		echo '<div class="bw-formrow__control">';
		echo '<input type="email" id="bwx-person-email" name="email" class="bw-input" required>';
		echo '<p class="bw-formrow__help">' . esc_html__( 'One person, one address, however many clients they work with.', 'blueworx-forge' ) . '</p>';
		echo '</div></div>';

		echo '</div>';

		// submit_button() rather than a <button>, and this is not cosmetic: the
		// specs click `input[type="submit"]`, so the element is as much part of
		// the contract as a data-bwx hook is. What changes is the class it
		// carries.
		echo '<div class="bw-card__foot">';
		submit_button( __( 'Add person', 'blueworx-forge' ), 'bw-btn bw-btn--primary', 'submit', false );
		echo '</div>';

		echo '</form>';
		echo '</section>';
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

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'bwx_forge_offboard_person_' . $id );
		echo '<input type="hidden" name="action" value="bwx_forge_offboard_person">';
		echo '<input type="hidden" name="user_id" value="' . esc_attr( $id ) . '">';
		echo '<input type="hidden" name="record_version" value="' . esc_attr( (string) $person['record_version'] ) . '">';
		echo '<button type="submit" class="bw-rowactions__link bw-rowactions__link--danger" data-bwx-offboard onclick="return confirm(' . esc_attr( (string) wp_json_encode( __( 'Offboard this person? Their access to every client ends; their history stays.', 'blueworx-forge' ) ) ) . ')">';
		echo esc_html__( 'Offboard', 'blueworx-forge' );
		echo '</button>';
		echo '</form>';
	}

	/**
	 * The form that edits somebody, including bringing them back.
	 *
	 * @param array<string, mixed> $person The person.
	 */
	private static function edit_person_form( array $person ): void {
		$id = (string) $person['id'];

		echo '<details data-bwx-edit-person="' . esc_attr( $id ) . '">';
		echo '<summary class="bw-rowactions__link">' . esc_html__( 'Edit', 'blueworx-forge' ) . '</summary>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'bwx_forge_edit_person_' . $id );
		echo '<input type="hidden" name="action" value="bwx_forge_edit_person">';
		echo '<input type="hidden" name="user_id" value="' . esc_attr( $id ) . '">';
		echo '<input type="hidden" name="record_version" value="' . esc_attr( (string) $person['record_version'] ) . '">';

		echo '<div class="bw-formrow">';
		echo '<label class="bw-formrow__label" for="bwx-edit-person-name-' . esc_attr( $id ) . '">' . esc_html__( 'Name', 'blueworx-forge' ) . '</label>';
		echo '<div class="bw-formrow__control">';
		echo '<input type="text" id="bwx-edit-person-name-' . esc_attr( $id ) . '" name="display_name" class="bw-input" value="' . esc_attr( (string) $person['display_name'] ) . '" required>';
		echo '</div></div>';

		echo '<div class="bw-formrow">';
		echo '<label class="bw-formrow__label" for="bwx-edit-person-email-' . esc_attr( $id ) . '">' . esc_html__( 'Email', 'blueworx-forge' ) . '</label>';
		echo '<div class="bw-formrow__control">';
		echo '<input type="email" id="bwx-edit-person-email-' . esc_attr( $id ) . '" name="email" class="bw-input" value="' . esc_attr( (string) $person['email'] ) . '" required>';
		echo '</div></div>';

		echo '<div class="bw-formrow">';
		echo '<label class="bw-formrow__label" for="bwx-edit-person-status-' . esc_attr( $id ) . '">' . esc_html__( 'Status', 'blueworx-forge' ) . '</label>';
		echo '<div class="bw-formrow__control"><span class="bw-select">';
		echo '<select class="bw-select__el" id="bwx-edit-person-status-' . esc_attr( $id ) . '" name="status">';
		echo '<option value="active"' . selected( 'active', (string) $person['status'], false ) . '>' . esc_html__( 'Active', 'blueworx-forge' ) . '</option>';
		echo '<option value="inactive"' . selected( 'inactive', (string) $person['status'], false ) . '>' . esc_html__( 'Offboarded', 'blueworx-forge' ) . '</option>';
		echo '</select>';
		echo '<i class="bw-icon bw-select__arrow" data-lucide="chevron-down"></i>';
		echo '</span></div></div>';

		self::reach_row( $person );

		echo '<div class="bw-card__foot">';
		submit_button( __( 'Save', 'blueworx-forge' ), 'bw-btn bw-btn--secondary', '', false );
		echo '</div>';

		echo '</form>';
		echo '</details>';
	}

	/**
	 * How far this person reaches (#93).
	 *
	 * One checkbox, because there is one grant that is not held with a client.
	 * Its absence is the default and the point: a studio user without it is
	 * scoped exactly like a client user, and reaches only what their memberships
	 * name.
	 *
	 * @param array<string, mixed> $person The person.
	 */
	private static function reach_row( array $person ): void {
		$id     = (string) $person['id'];
		$grants = Grants::parse( (string) ( $person['grants'] ?? '' ) );

		echo '<div class="bw-formrow">';
		echo '<p class="bw-formrow__label">' . esc_html__( 'Reach', 'blueworx-forge' ) . '</p>';
		echo '<div class="bw-formrow__control">';
		echo '<label class="bw-check" for="bwx-edit-person-cross-' . esc_attr( $id ) . '">';
		echo '<input type="checkbox" id="bwx-edit-person-cross-' . esc_attr( $id ) . '" name="grants[]" value="' . esc_attr( Grants::CROSS_CLIENT ) . '"';
		checked( in_array( Grants::CROSS_CLIENT, $grants, true ) );
		echo ' data-bwx-grant="' . esc_attr( Grants::CROSS_CLIENT ) . '">';
		echo '<span class="bw-check__text">' . esc_html( Grants::label( Grants::CROSS_CLIENT ) );
		echo '<span class="bw-check__help">' . esc_html( Grants::description( Grants::CROSS_CLIENT ) ) . '</span>';
		echo '</span>';
		echo '</label>';
		echo '</div></div>';
	}

	/**
	 * The grants held with one client, and the form that changes them (#93).
	 *
	 * These sit on the membership rather than the person because they are held
	 * with one client: somebody can be trusted to approve their own work here
	 * and not there, which a grant on the person could not say.
	 *
	 * @param array<string, mixed> $membership The membership.
	 */
	private static function membership_grants_form( array $membership ): void {
		if ( 'active' !== (string) $membership['status'] || Roles::is_client_side( (string) $membership['role'] ) ) {
			// Nothing to offer. The two grants here are studio authority, and a
			// membership that has ended grants nothing at all.
			return;
		}

		$id   = (string) $membership['id'];
		$held = Grants::parse( (string) ( $membership['grants'] ?? '' ) );

		echo '<details data-bwx-membership-grants="' . esc_attr( $id ) . '">';
		echo '<summary class="bw-rowactions__link">' . esc_html__( 'Grants', 'blueworx-forge' ) . '</summary>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'bwx_forge_set_membership_grants_' . $id );
		echo '<input type="hidden" name="action" value="bwx_forge_set_membership_grants">';
		echo '<input type="hidden" name="membership_id" value="' . esc_attr( $id ) . '">';
		echo '<input type="hidden" name="record_version" value="' . esc_attr( (string) $membership['record_version'] ) . '">';

		foreach ( Grants::ON_MEMBERSHIP as $grant ) {
			$field = 'bwx-grant-' . $grant . '-' . $id;

			echo '<p><label class="bw-check" for="' . esc_attr( $field ) . '">';
			echo '<input type="checkbox" id="' . esc_attr( $field ) . '" name="grants[]" value="' . esc_attr( $grant ) . '"';
			checked( in_array( $grant, $held, true ) );
			echo ' data-bwx-grant="' . esc_attr( $grant ) . '">';
			echo '<span class="bw-check__text">' . esc_html( Grants::label( $grant ) );
			echo '<span class="bw-check__help">' . esc_html( Grants::description( $grant ) ) . '</span>';
			echo '</span></label></p>';
		}

		submit_button( __( 'Save grants', 'blueworx-forge' ), 'bw-btn bw-btn--secondary', '', false );
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
