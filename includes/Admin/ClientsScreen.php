<?php
/**
 * The studio's clients screen.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Admin;

use Blueworx\Forge\Onboarding\Assignment;
use Blueworx\Forge\Onboarding\Progress;
use Blueworx\Forge\Onboarding\Steps;
use Blueworx\Forge\Onboarding\Templates;
use Blueworx\Forge\Tenancy\ClientSites;
use Blueworx\Forge\Tenancy\Clients;
use Blueworx\Forge\Tenancy\Contacts;
use Blueworx\Forge\Tenancy\Health;
use Blueworx\Forge\Tenancy\Integrations;
use Blueworx\Forge\Tenancy\Memberships;
use Blueworx\Forge\Tenancy\Users;
use Blueworx\Forge\Tenancy\Validate;

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
	 * Id of the one timezone list every client's edit form points at.
	 */
	private const TIMEZONE_LIST = 'bwx-forge-timezones';

	/**
	 * Active people, for the add-access dropdowns. Read once per render.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private static array $people = array();

	/**
	 * Every person's name by id, including offboarded ones, so an ended
	 * membership still shows whose it was.
	 *
	 * @var array<string, string>
	 */
	private static array $people_by_id = array();

	/**
	 * Memberships grouped by client. Read once per render.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private static array $memberships = array();

	/**
	 * Integrations by client site id. Read once per render.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $integrations = array();

	/**
	 * The current contact for each client. Read once per render.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $contacts = array();

	/**
	 * Every person by id, including offboarded ones, so a contact who has left
	 * can still be named. Read once per render.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $everyone = array();

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

		$status = self::status_filter();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Forge — clients', 'blueworx-forge' ) . '</h1>';

		self::notice();
		self::issued_key();
		self::timezone_list();
		self::status_toggle_link( $status );
		self::clients_list( $status );
		self::add_client_form();

		echo '</div>';
	}

	/**
	 * The status filter in effect, from the URL.
	 *
	 * @return string 'active' or 'all'.
	 */
	private static function status_filter(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reads a filter, changes nothing.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'active';

		return 'all' === $status ? 'all' : 'active';
	}

	/**
	 * A link toggling between the default active-only view and every record,
	 * including inactive ones — otherwise a deactivated client is unreachable
	 * from this screen.
	 *
	 * @param string $status The status filter in effect.
	 */
	private static function status_toggle_link( string $status ): void {
		if ( 'all' === $status ) {
			$url   = admin_url( 'admin.php?page=' . self::SLUG );
			$label = __( 'Show active only', 'blueworx-forge' );
		} else {
			$url   = add_query_arg( 'status', 'all', admin_url( 'admin.php?page=' . self::SLUG ) );
			$label = __( 'Show all, including inactive', 'blueworx-forge' );
		}

		echo '<p><a href="' . esc_url( $url ) . '" data-bwx-status-toggle="' . esc_attr( $status ) . '">' . esc_html( $label ) . '</a></p>';
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
			'added'              => array( 'success', __( 'Saved.', 'blueworx-forge' ) ),
			'invalid'            => array( 'error', __( 'That could not be saved.', 'blueworx-forge' ) ),
			'stale'              => array( 'error', __( 'That changed elsewhere first — reload and try again.', 'blueworx-forge' ) ),
			'unknown'            => array( 'error', __( 'No such record.', 'blueworx-forge' ) ),
			'onboarding-started' => array( 'success', __( 'Onboarding started. Their checklist is fixed at this version.', 'blueworx-forge' ) ),
			'already-onboarding' => array( 'error', __( 'That site already has a checklist. A client onboards once.', 'blueworx-forge' ) ),
			'no-checklist'       => array( 'error', __( 'There is no published checklist to give them yet.', 'blueworx-forge' ) ),
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
	 * A key that has just been issued, shown once and then gone.
	 *
	 * Same panel as the client sites screen's, and the same reasoning: the key
	 * survives exactly one redirect, in a transient held for the administrator
	 * who issued it, never in the URL where it would end up in browser history
	 * and access logs.
	 */
	private static function issued_key(): void {
		$issued = IssuedKey::take( get_current_user_id() );

		if ( null === $issued ) {
			return;
		}

		echo '<div class="notice notice-warning" data-bwx-issued-key="1">';
		echo '<p><strong>' . esc_html__( 'Copy this key now. It cannot be shown again.', 'blueworx-forge' ) . '</strong></p>';
		echo '<p>' . esc_html__( 'Site id', 'blueworx-forge' ) . ': <code data-bwx-site-id="1">' . esc_html( $issued['site_id'] ) . '</code></p>';
		echo '<p>' . esc_html__( 'Key', 'blueworx-forge' ) . ': <code data-bwx-key="1">' . esc_html( $issued['key'] ) . '</code></p>';
		echo '<p class="description">' . esc_html__( 'Paste both into the client site. If the key is lost, issue a new one — there is nowhere to look it up.', 'blueworx-forge' ) . '</p>';
		echo '</div>';
	}

	/**
	 * The timezones, once for the page, for every client's edit form to point
	 * at. See the note in edit_client_form() for why this is not a select.
	 */
	private static function timezone_list(): void {
		echo '<datalist id="' . esc_attr( self::TIMEZONE_LIST ) . '">';

		foreach ( timezone_identifiers_list() as $timezone ) {
			echo '<option value="' . esc_attr( $timezone ) . '"></option>';
		}

		echo '</datalist>';
	}

	/**
	 * The clients, filtered by status, each with its sites.
	 *
	 * @param string $status The status filter in effect.
	 */
	private static function clients_list( string $status ): void {
		$clients = Clients::all( 'all' === $status ? null : 'active' );

		if ( array() === $clients ) {
			echo '<p data-bwx-no-clients="1">' . esc_html__( 'No clients yet.', 'blueworx-forge' ) . '</p>';

			return;
		}

		/*
		 * Everything this screen needs about people and connections, read once
		 * for the whole page rather than per client. Asked per client, a studio
		 * with eighty clients paid a query a row three times over and the page
		 * stopped loading inside a test's patience.
		 */
		self::$people       = Users::all( 'active' );
		self::$people_by_id = array();
		self::$memberships  = Memberships::by_client( 'all' === $status ? null : 'active' );
		self::$integrations = Integrations::all();

		// The current contact for every client, in one query. Asked per client
		// this cost a query a row — twice, with the person's record — and it was
		// found the same way the note above was: a test ran out of patience.
		self::$contacts = Contacts::current_by_client();
		self::$everyone = array();

		foreach ( Users::all( null ) as $person ) {
			self::$people_by_id[ (string) $person['id'] ] = (string) $person['display_name'];
			self::$everyone[ (string) $person['id'] ]     = $person;
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

		self::edit_client_form( $client );

		self::contact( $client );

		self::sites_list( $client_id, $status );
		self::add_site_form( $client_id );

		self::people( $client );

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

		// Read once for the whole screen in clients_list(), not per client: this
		// is the page somebody opens to see the state of every client's estate,
		// and it should not cost a query a row to answer.
		$integrations = self::$integrations;

		echo '<ul data-bwx-sites="1">';

		foreach ( $sites as $site ) {
			$site_label = 'active' === (string) $site['status']
				? __( 'Active', 'blueworx-forge' )
				: __( 'Inactive', 'blueworx-forge' );

			echo '<li data-bwx-site="' . esc_attr( (string) $site['id'] ) . '">';
			echo '<span data-bwx-site-name>' . esc_html( (string) $site['name'] ) . '</span> ';
			echo '<span data-bwx-status>' . esc_html( $site_label ) . '</span> ';

			self::connection( $site, $integrations[ (string) $site['id'] ] ?? null );

			self::onboarding( $site );

			self::deactivate_site_form( $site );

			self::edit_site_form( $site );

			echo '</li>';
		}

		echo '</ul>';
	}

	/**
	 * Where a site is with its onboarding, and the form that starts it (#160).
	 *
	 * On the site's own row because onboarding belongs to a site rather than to
	 * a client (ARCH-3): two sites for the same client launch separately and
	 * have their own checklists.
	 *
	 * Assignment is offered once and then never again. ONB-1 fixes a client's
	 * checklist at the moment they are given it, so there is deliberately no
	 * control here to change it afterwards or to move them to a newer version —
	 * a client onboards once.
	 *
	 * @param array<string, mixed> $site The site row.
	 */
	private static function onboarding( array $site ): void {
		$site_id    = (string) $site['id'];
		$onboarding = Assignment::for_site( $site_id );

		if ( null !== $onboarding ) {
			$progress = Progress::of( Steps::for_site( $site_id ) );

			printf(
				' <span data-bwx-onboarding="%1$s" data-bwx-onboarding-ready="%2$s">%3$s</span>',
				esc_attr( $site_id ),
				esc_attr( $progress['launch_ready'] ? 'yes' : 'no' ),
				esc_html(
					sprintf(
						/* translators: 1: checklist version, 2: percentage complete. */
						__( 'Checklist v%1$d — %2$s%% done', 'blueworx-forge' ),
						(int) $onboarding['template_version'],
						(string) $progress['completion']
					)
				)
			);

			if ( ! $progress['launch_ready'] ) {
				echo ' <span data-bwx-onboarding-blocking="' . esc_attr( (string) count( $progress['blocking'] ) ) . '">';
				echo esc_html(
					array() === $progress['blocking']
						? __( 'not ready to launch', 'blueworx-forge' )
						: sprintf(
							/* translators: %d: how many steps are outstanding. */
							_n( '%d thing still needed to launch', '%d things still needed to launch', count( $progress['blocking'] ), 'blueworx-forge' ),
							count( $progress['blocking'] )
						)
				);
				echo '</span>';
			}

			return;
		}

		$template = Templates::current();

		if ( null === $template ) {
			echo ' <span data-bwx-onboarding-unavailable="1">' . esc_html__( 'No checklist published yet', 'blueworx-forge' ) . '</span>';

			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline" data-bwx-assign-onboarding="1">';
		wp_nonce_field( 'bwx_forge_assign_onboarding_' . $site_id );
		echo '<input type="hidden" name="action" value="bwx_forge_assign_onboarding">';
		echo '<input type="hidden" name="site_id" value="' . esc_attr( $site_id ) . '">';
		echo '<button type="submit" class="button" data-bwx-action="bwx_forge_assign_onboarding"';
		echo ' onclick="return confirm(' . esc_attr( (string) wp_json_encode( __( 'Give this site the current checklist? It is fixed at this version and cannot be changed afterwards.', 'blueworx-forge' ) ) ) . ')">';
		echo esc_html(
			sprintf(
				/* translators: %d: the checklist version number. */
				__( 'Start onboarding (v%d)', 'blueworx-forge' ),
				(int) $template['version']
			)
		);
		echo '</button> ';
		echo '</form>';
	}

	/**
	 * Who works with this client, and the form that adds somebody (#90).
	 *
	 * On the client's own row rather than on the people screen, because this is
	 * where somebody is thinking about who works with that client. The people
	 * screen answers the other question — everywhere one person works.
	 *
	 * The status filter was already applied when the memberships were read for
	 * the whole page, so this takes only the client it is rendering.
	 *
	 * @param array<string, mixed> $client The client row.
	 */
	private static function people( array $client ): void {
		$client_id = (string) $client['id'];
		$held      = self::$memberships[ $client_id ] ?? array();

		echo '<h4>' . esc_html__( 'People', 'blueworx-forge' ) . '</h4>';

		if ( array() === $held ) {
			echo '<p data-bwx-no-client-people="1">' . esc_html__( 'Nobody has access to this client yet.', 'blueworx-forge' ) . '</p>';
		} else {
			$names = self::$people_by_id;

			echo '<ul data-bwx-client-people="1">';

			foreach ( $held as $membership ) {
				echo '<li data-bwx-membership="' . esc_attr( (string) $membership['id'] ) . '" data-bwx-membership-role="' . esc_attr( (string) $membership['role'] ) . '">';
				echo '<span data-bwx-membership-person>' . esc_html( $names[ (string) $membership['user_id'] ] ?? __( 'Unknown person', 'blueworx-forge' ) ) . '</span> — ';
				echo '<span data-bwx-membership-role-label>' . esc_html( (string) $membership['role_label'] ) . '</span> ';
				echo '<span data-bwx-status>' . esc_html( 'active' === (string) $membership['status'] ? __( 'Active', 'blueworx-forge' ) : __( 'Ended', 'blueworx-forge' ) ) . '</span> ';

				self::end_membership_form( $membership );

				echo '</li>';
			}

			echo '</ul>';
		}

		if ( 'active' === (string) $client['status'] ) {
			self::add_membership_form( $client );
		}
	}

	/**
	 * Who we are to this client (#95), and the form that changes it.
	 *
	 * A change appends rather than overwrites, so the previous contacts are
	 * still there — and a contact who has been offboarded is said out loud
	 * rather than quietly left in place, because a client pointed at somebody
	 * who has left has no contact at all and nobody would find out from here.
	 *
	 * @param array<string, mixed> $client The client row.
	 */
	private static function contact( array $client ): void {
		$client_id  = (string) $client['id'];
		$assignment = self::$contacts[ $client_id ] ?? null;
		$person     = null === $assignment || '' === (string) $assignment['user_id']
			? null
			: ( self::$everyone[ (string) $assignment['user_id'] ] ?? null );

		$state = Contacts::resolve( $assignment, $person );

		echo '<h4>' . esc_html__( 'Point of contact', 'blueworx-forge' ) . '</h4>';
		echo '<p data-bwx-contact="' . esc_attr( $client_id ) . '">';

		if ( null === $state['contact'] ) {
			echo '<span data-bwx-contact-none="1">' . esc_html__( 'Nobody is our contact for this client.', 'blueworx-forge' ) . '</span>';
		} else {
			echo '<span data-bwx-contact-name>' . esc_html( (string) $state['contact']['display_name'] ) . '</span>';
		}

		if ( $state['needs_reassignment'] ) {
			echo ' <strong data-bwx-contact-needs-reassignment="1">' . esc_html__( 'Needs reassigning — until then the client\'s contact is the studio.', 'blueworx-forge' ) . '</strong>';
		}

		echo '</p>';

		if ( 'active' === (string) $client['status'] ) {
			self::assign_contact_form( $client, null === $assignment ? '' : (string) $assignment['user_id'] );
		}
	}

	/**
	 * The form that names our contact for a client.
	 *
	 * Naming nobody is one of the options, and a real one: it is the difference
	 * between a client that has never had a contact and one whose contact just
	 * left.
	 *
	 * @param array<string, mixed> $client  The client row.
	 * @param string               $current The person currently named, or ''.
	 */
	private static function assign_contact_form( array $client, string $current ): void {
		$client_id = (string) $client['id'];

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-bwx-assign-contact="' . esc_attr( $client_id ) . '">';
		wp_nonce_field( 'bwx_forge_assign_contact_' . $client_id );
		echo '<input type="hidden" name="action" value="bwx_forge_assign_contact">';
		echo '<input type="hidden" name="client_id" value="' . esc_attr( $client_id ) . '">';
		echo '<select name="user_id" aria-label="' . esc_attr__( 'Point of contact', 'blueworx-forge' ) . '">';
		echo '<option value=""' . selected( '', $current, false ) . '>' . esc_html__( 'Nobody', 'blueworx-forge' ) . '</option>';

		foreach ( self::$people as $candidate ) {
			// Only our own people. A client's own administrator being our
			// internal contact for them is not a thing that can be true.
			if ( 'active' !== (string) $candidate['status'] ) {
				continue;
			}

			echo '<option value="' . esc_attr( (string) $candidate['id'] ) . '"' . selected( (string) $candidate['id'], $current, false ) . '>';
			echo esc_html( (string) $candidate['display_name'] );
			echo '</option>';
		}

		echo '</select> ';
		submit_button( __( 'Set contact', 'blueworx-forge' ), 'secondary', '', false );
		echo '</form>';
	}

	/**
	 * The form that gives somebody a role on this client.
	 *
	 * @param array<string, mixed> $client The client row.
	 */
	private static function add_membership_form( array $client ): void {
		$client_id = (string) $client['id'];
		$people    = self::$people;

		if ( array() === $people ) {
			echo '<p data-bwx-no-people-yet="1">' . esc_html__( 'Add somebody on Forge → People first.', 'blueworx-forge' ) . '</p>';

			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-bwx-add-membership>';
		wp_nonce_field( 'bwx_forge_add_membership_' . $client_id );
		echo '<input type="hidden" name="action" value="bwx_forge_add_membership">';
		echo '<input type="hidden" name="client_id" value="' . esc_attr( $client_id ) . '">';

		echo '<select name="user_id" aria-label="' . esc_attr__( 'Person', 'blueworx-forge' ) . '">';

		foreach ( $people as $person ) {
			echo '<option value="' . esc_attr( (string) $person['id'] ) . '">' . esc_html( (string) $person['display_name'] ) . '</option>';
		}

		echo '</select> ';

		echo '<select name="role" aria-label="' . esc_attr__( 'Role', 'blueworx-forge' ) . '">';
		PeopleScreen::role_options();
		echo '</select> ';

		// Empty means every site under the client, which is a real answer rather
		// than a missing one — so it is the first option and says so.
		echo '<select name="client_site_id" aria-label="' . esc_attr__( 'Scope', 'blueworx-forge' ) . '">';
		echo '<option value="">' . esc_html__( 'Every site', 'blueworx-forge' ) . '</option>';

		foreach ( ClientSites::for_client( $client_id, 'active' ) as $site ) {
			echo '<option value="' . esc_attr( (string) $site['id'] ) . '">' . esc_html( (string) $site['name'] ) . '</option>';
		}

		echo '</select> ';

		submit_button( __( 'Give access', 'blueworx-forge' ), 'secondary', '', false );
		echo '</form>';
	}

	/**
	 * The form that ends one membership, carrying its current version.
	 *
	 * @param array<string, mixed> $membership The membership row.
	 */
	private static function end_membership_form( array $membership ): void {
		if ( 'active' !== (string) $membership['status'] ) {
			return;
		}

		$id = (string) $membership['id'];

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
		wp_nonce_field( 'bwx_forge_end_membership_' . $id );
		echo '<input type="hidden" name="action" value="bwx_forge_end_membership">';
		echo '<input type="hidden" name="membership_id" value="' . esc_attr( $id ) . '">';
		echo '<input type="hidden" name="record_version" value="' . esc_attr( (string) $membership['record_version'] ) . '">';
		echo '<button type="submit" class="button" data-bwx-end-membership onclick="return confirm(' . esc_attr( (string) wp_json_encode( __( 'End this access?', 'blueworx-forge' ) ) ) . ')">';
		echo esc_html__( 'End access', 'blueworx-forge' );
		echo '</button>';
		echo '</form>';
	}

	/**
	 * One site's connection: what state it is in, when it was last heard from,
	 * whether it can send mail, and the actions that change any of that (#89).
	 *
	 * @param array<string, mixed>      $site        The site row.
	 * @param array<string, mixed>|null $integration Its integration, if it has one.
	 */
	private static function connection( array $site, ?array $integration ): void {
		$health = null === $integration ? Health::UNCONFIGURED : (string) $integration['health'];

		echo '<span data-bwx-connection="' . esc_attr( $health ) . '">';
		echo esc_html( Health::label( $health ) );

		if ( null !== $integration && $integration['last_seen_at'] > 0 ) {
			echo ' <span data-bwx-last-seen>';
			printf(
				/* translators: %s: how long ago the site last called, e.g. "2 hours". */
				esc_html__( 'last seen %s ago', 'blueworx-forge' ),
				esc_html( human_time_diff( (int) $integration['last_seen_at'], bwx_forge_now() ) )
			);
			echo '</span>';
		}

		echo '</span> ';

		echo '<span data-bwx-mail="' . esc_attr( null === $integration ? 'unknown' : (string) $integration['mail_capable'] ) . '">';
		echo esc_html( self::mail_label( null === $integration ? 'unknown' : (string) $integration['mail_capable'] ) );
		echo '</span> ';

		self::key_forms( $site, $integration );
	}

	/**
	 * How a site's mail capability reads to a human.
	 *
	 * @param string $capable One of unknown, yes, no.
	 * @return string
	 */
	private static function mail_label( string $capable ): string {
		switch ( $capable ) {
			case 'yes':
				return __( 'Can send mail', 'blueworx-forge' );
			case 'no':
				return __( 'Cannot send mail', 'blueworx-forge' );
			default:
				return __( 'Mail unknown', 'blueworx-forge' );
		}
	}

	/**
	 * Issue, rotate and revoke, as forms rather than links: each changes state,
	 * and a link that changes state is one a browser can follow by prefetching
	 * it.
	 *
	 * @param array<string, mixed>      $site        The site row.
	 * @param array<string, mixed>|null $integration Its integration, if it has one.
	 */
	private static function key_forms( array $site, ?array $integration ): void {
		if ( 'active' !== (string) $site['status'] ) {
			return;
		}

		$site_id  = (string) $site['id'];
		$has_key  = null !== $integration && Integrations::KEY_ACTIVE === $integration['key_state'];
		$issuing  = $has_key ? __( 'Rotate key', 'blueworx-forge' ) : __( 'Issue key', 'blueworx-forge' );
		$question = $has_key
			? __( 'Issue a new key? The site stops working until the new one is installed on it.', 'blueworx-forge' )
			: __( 'Issue a key for this site?', 'blueworx-forge' );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
		wp_nonce_field( 'bwx_forge_issue_site_key_' . $site_id );
		echo '<input type="hidden" name="action" value="bwx_forge_issue_site_key">';
		echo '<input type="hidden" name="site_id" value="' . esc_attr( $site_id ) . '">';
		echo '<button type="submit" class="button" data-bwx-issue-key onclick="return confirm(' . esc_attr( (string) wp_json_encode( $question ) ) . ')">';
		echo esc_html( $issuing );
		echo '</button>';
		echo '</form> ';

		if ( ! $has_key ) {
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
		wp_nonce_field( 'bwx_forge_revoke_site_key_' . $site_id );
		echo '<input type="hidden" name="action" value="bwx_forge_revoke_site_key">';
		echo '<input type="hidden" name="site_id" value="' . esc_attr( $site_id ) . '">';
		echo '<button type="submit" class="button" data-bwx-revoke-key onclick="return confirm(' . esc_attr( (string) wp_json_encode( __( 'Cut this site off? Its key stops working immediately.', 'blueworx-forge' ) ) ) . ')">';
		echo esc_html__( 'Revoke key', 'blueworx-forge' );
		echo '</button>';
		echo '</form> ';
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

	/**
	 * The form that edits a client — every writable field, including status, so
	 * this is also how an inactive client is set back to active.
	 *
	 * @param array<string, mixed> $client The client row.
	 */
	private static function edit_client_form( array $client ): void {
		$client_id = (string) $client['id'];

		echo '<details data-bwx-edit-client="' . esc_attr( $client_id ) . '">';
		echo '<summary>' . esc_html__( 'Edit', 'blueworx-forge' ) . '</summary>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'bwx_forge_edit_client_' . $client_id );
		echo '<input type="hidden" name="action" value="bwx_forge_edit_client">';
		echo '<input type="hidden" name="client_id" value="' . esc_attr( $client_id ) . '">';
		echo '<input type="hidden" name="record_version" value="' . esc_attr( (string) $client['record_version'] ) . '">';
		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row"><label for="bwx-edit-client-name-' . esc_attr( $client_id ) . '">' . esc_html__( 'Name', 'blueworx-forge' ) . '</label></th>';
		echo '<td><input type="text" id="bwx-edit-client-name-' . esc_attr( $client_id ) . '" name="display_name" class="regular-text" value="' . esc_attr( (string) $client['display_name'] ) . '" required></td></tr>';

		echo '<tr><th scope="row"><label for="bwx-edit-client-legal-' . esc_attr( $client_id ) . '">' . esc_html__( 'Legal name', 'blueworx-forge' ) . '</label></th>';
		echo '<td><input type="text" id="bwx-edit-client-legal-' . esc_attr( $client_id ) . '" name="legal_name" class="regular-text" value="' . esc_attr( (string) $client['legal_name'] ) . '"></td></tr>';

		/*
		 * An input against one shared list, not a select of its own. There are
		 * about four hundred timezones and this form is rendered once per
		 * client: as a select, a studio with eighty clients was served a
		 * two-and-a-half megabyte page carrying thirty-six thousand options,
		 * which the browser then had to lay out. The rule that matters is
		 * server-side in Validate::client() either way.
		 */
		echo '<tr><th scope="row"><label for="bwx-edit-client-timezone-' . esc_attr( $client_id ) . '">' . esc_html__( 'Timezone', 'blueworx-forge' ) . '</label></th>';
		echo '<td><input type="text" id="bwx-edit-client-timezone-' . esc_attr( $client_id ) . '" name="timezone" class="regular-text" list="' . esc_attr( self::TIMEZONE_LIST ) . '" value="' . esc_attr( (string) $client['timezone'] ) . '"></td></tr>';

		echo '<tr><th scope="row"><label for="bwx-edit-client-domains-' . esc_attr( $client_id ) . '">' . esc_html__( 'Permitted email domains', 'blueworx-forge' ) . '</label></th>';
		echo '<td><input type="text" id="bwx-edit-client-domains-' . esc_attr( $client_id ) . '" name="email_domains" class="regular-text" value="' . esc_attr( implode( ', ', (array) $client['email_domains'] ) ) . '"></td></tr>';

		echo '<tr><th scope="row"><label for="bwx-edit-client-status-' . esc_attr( $client_id ) . '">' . esc_html__( 'Status', 'blueworx-forge' ) . '</label></th>';
		echo '<td><select id="bwx-edit-client-status-' . esc_attr( $client_id ) . '" name="status">';

		foreach ( Validate::STATUSES as $status_option ) {
			echo '<option value="' . esc_attr( $status_option ) . '"' . selected( (string) $client['status'], $status_option, false ) . '>' . esc_html( ucfirst( $status_option ) ) . '</option>';
		}

		echo '</select></td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Save', 'blueworx-forge' ), 'secondary', '', false );
		echo '</form>';
		echo '</details>';
	}

	/**
	 * The form that edits a client site — every writable field, including
	 * status, so this is also how an inactive site is set back to active.
	 *
	 * @param array<string, mixed> $site The site row.
	 */
	private static function edit_site_form( array $site ): void {
		$site_id = (string) $site['id'];

		echo '<details data-bwx-edit-site="' . esc_attr( $site_id ) . '">';
		echo '<summary>' . esc_html__( 'Edit', 'blueworx-forge' ) . '</summary>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'bwx_forge_edit_client_site_' . $site_id );
		echo '<input type="hidden" name="action" value="bwx_forge_edit_client_site">';
		echo '<input type="hidden" name="site_id" value="' . esc_attr( $site_id ) . '">';
		echo '<input type="hidden" name="record_version" value="' . esc_attr( (string) $site['record_version'] ) . '">';

		echo '<input type="text" name="name" value="' . esc_attr( (string) $site['name'] ) . '" required>';
		echo '<input type="url" name="url" value="' . esc_attr( (string) $site['url'] ) . '">';
		echo '<select name="status">';

		foreach ( Validate::STATUSES as $status_option ) {
			echo '<option value="' . esc_attr( $status_option ) . '"' . selected( (string) $site['status'], $status_option, false ) . '>' . esc_html( ucfirst( $status_option ) ) . '</option>';
		}

		echo '</select>';
		submit_button( __( 'Save', 'blueworx-forge' ), 'secondary', '', false );
		echo '</form>';
		echo '</details>';
	}
}
