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
	 * The published checklist, if there is one. Read once per render.
	 *
	 * Every site on the screen asks the same question — whether there is a
	 * checklist to give it — and on a studio with a hundred sites that was a
	 * hundred identical reads of the same row.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $template = null;

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

		Page::open(
			__( 'Clients', 'blueworx-forge' ),
			__( 'Forge', 'blueworx-forge' ),
			__( 'Everyone we work for, the sites we look after for them, and who may reach each one.', 'blueworx-forge' )
		);

		self::notice();
		self::issued_key();
		self::timezone_list();
		self::status_toggle_link( $status );
		self::clients_list( $status );
		self::add_client_form();

		Page::close();
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
			'invalid'            => array( 'danger', __( 'That could not be saved.', 'blueworx-forge' ) ),
			'stale'              => array( 'danger', __( 'That changed elsewhere first — reload and try again.', 'blueworx-forge' ) ),
			'unknown'            => array( 'danger', __( 'No such record.', 'blueworx-forge' ) ),
			'onboarding-started' => array( 'success', __( 'Onboarding started. Their checklist is fixed at this version.', 'blueworx-forge' ) ),
			'already-onboarding' => array( 'danger', __( 'That site already has a checklist. A client onboards once.', 'blueworx-forge' ) ),
			'no-checklist'       => array( 'danger', __( 'There is no published checklist to give them yet.', 'blueworx-forge' ) ),
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

		// Markup, so the id and the key keep the elements the specs read them
		// out of. Everything interpolated is escaped as it is assembled, and
		// the key itself is never written anywhere but here.
		$text = '<strong>' . esc_html__( 'Copy this key now. It cannot be shown again.', 'blueworx-forge' ) . '</strong>'
			. '<br>' . esc_html__( 'Site id', 'blueworx-forge' ) . ': <code class="bw-input bw-input--mono" data-bwx-site-id="1">' . esc_html( $issued['site_id'] ) . '</code>'
			. '<br>' . esc_html__( 'Key', 'blueworx-forge' ) . ': <code class="bw-input bw-input--mono" data-bwx-key="1">' . esc_html( $issued['key'] ) . '</code>'
			. '<br>' . esc_html__( 'Paste both into the client site. If the key is lost, issue a new one — there is nowhere to look it up.', 'blueworx-forge' );

		Page::notice( 'warning', $text, array( 'data-bwx-issued-key' => '1' ), true );
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
			echo '<div class="bw-empty" data-bwx-no-clients="1">';
			echo '<i class="bw-icon bw-empty__icon" data-lucide="users"></i>';
			echo '<p class="bw-empty__title">' . esc_html__( 'No clients yet', 'blueworx-forge' ) . '</p>';
			echo '<p class="bw-empty__text">' . esc_html__( 'Add the first one below.', 'blueworx-forge' ) . '</p>';
			echo '</div>';

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
		self::$template = Templates::current();

		foreach ( Users::all( null ) as $person ) {
			self::$people_by_id[ (string) $person['id'] ] = (string) $person['display_name'];
			self::$everyone[ (string) $person['id'] ]     = $person;
		}

		// Still a list, because that is what it is; the design system's card is
		// what each item wears. Kept a <ul> so the specs' [data-bwx-clients]
		// container and its children read exactly as they did.
		echo '<ul class="bw-panels" data-bwx-clients="1">';

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

		$tone = 'active' === (string) $client['status']
			? 'bw-badge'
			: 'bw-badge bw-badge--neutral';

		echo '<li class="bw-card" data-bwx-client="' . esc_attr( $client_id ) . '">';
		echo '<div class="bw-card__head"><div class="bw-card__titles">';
		echo '<h2 class="bw-card__title" data-bwx-client-name>' . esc_html( (string) $client['display_name'] ) . '</h2>';
		echo '</div><div class="bw-card__actions">';
		echo '<span class="' . esc_attr( $tone ) . '" data-bwx-status>' . esc_html( $label ) . '</span>';

		self::deactivate_client_form( $client );

		echo '</div></div>';
		echo '<div class="bw-card__body bw-panel__loose">';

		// Four separate things about one client, so four separate sections.
		// Run together in one column they read as a single wall, which is what
		// they were: a heading is not a boundary.
		Page::section_open( __( 'Details', 'blueworx-forge' ), 'client-details' );
		self::edit_client_form( $client );
		Page::section_close();

		Page::section_open( __( 'Point of contact', 'blueworx-forge' ), 'client-contact' );
		self::contact( $client );
		Page::section_close();

		Page::section_open( __( 'Sites', 'blueworx-forge' ), 'client-sites' );
		self::sites_list( $client_id, $status );
		self::add_site_form( $client_id );
		Page::section_close();

		Page::section_open( __( 'People', 'blueworx-forge' ), 'client-people' );
		self::people( $client );
		Page::section_close();

		echo '</div>';
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

		/*
		 * A card each, kept a <ul>/<li> because the specs address a site as
		 * li[data-bwx-site]. Everything a site says about itself used to run
		 * along one line — name, status, connection, mail, onboarding, then
		 * three buttons — and by the third site nothing was findable. Now the
		 * name and its status are the head, the facts are chips, and the
		 * buttons are one row with one gap between them.
		 */
		echo '<ul class="bw-panel__loose" data-bwx-sites="1">';

		foreach ( $sites as $site ) {
			$active = 'active' === (string) $site['status'];

			$site_label = $active
				? __( 'Active', 'blueworx-forge' )
				: __( 'Inactive', 'blueworx-forge' );

			echo '<li class="bw-card" data-bwx-site="' . esc_attr( (string) $site['id'] ) . '">';
			echo '<div class="bw-card__head"><div class="bw-card__titles">';
			echo '<h4 class="bw-card__title" data-bwx-site-name>' . esc_html( (string) $site['name'] ) . '</h4>';

			if ( '' !== (string) $site['url'] ) {
				echo '<p class="bw-card__eyebrow">' . esc_html( (string) $site['url'] ) . '</p>';
			}

			echo '</div><div class="bw-card__actions">';
			echo '<span class="' . ( $active ? 'bw-badge bw-badge--success' : 'bw-badge bw-badge--neutral' ) . '" data-bwx-status>' . esc_html( $site_label ) . '</span>';
			echo '</div></div>';

			echo '<div class="bw-card__body bw-panel__loose">';

			// Asked once and handed to both halves. Where a site is with its
			// onboarding is read for the chips and again for the button that
			// starts it, and asking twice cost a query a site on a screen that
			// already lists every site the studio has.
			$onboarding = Assignment::for_site( (string) $site['id'] );

			echo '<div class="bw-chips">';
			self::connection( $site, $integrations[ (string) $site['id'] ] ?? null );
			self::onboarding( $site, $onboarding );
			echo '</div>';

			echo '<div class="bw-toolbar bw-toolbar--card">';
			self::key_forms( $site, $integrations[ (string) $site['id'] ] ?? null );
			self::assign_onboarding_form( $site, $onboarding );
			self::deactivate_site_form( $site );
			echo '</div>';

			self::edit_site_form( $site );

			echo '</div>';
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
	 * @param array<string, mixed>      $site       The site row.
	 * @param array<string, mixed>|null $onboarding Its assignment, if it has one.
	 */
	private static function onboarding( array $site, ?array $onboarding ): void {
		$site_id = (string) $site['id'];

		if ( null !== $onboarding ) {
			$progress = Progress::of( Steps::for_site( $site_id ) );

			printf(
				'<span class="bw-chip bw-chip--plain" data-bwx-onboarding="%1$s" data-bwx-onboarding-ready="%2$s">%3$s</span>',
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
				echo '<span class="bw-badge bw-badge--warning" data-bwx-onboarding-blocking="' . esc_attr( (string) count( $progress['blocking'] ) ) . '">';
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

		if ( null === self::$template ) {
			echo '<span class="bw-chip bw-chip--plain" data-bwx-onboarding-unavailable="1">' . esc_html__( 'No checklist published yet', 'blueworx-forge' ) . '</span>';
		}
	}

	/**
	 * The button that gives a site the current checklist.
	 *
	 * Split out from onboarding() so a site's state and the buttons that change
	 * it sit in the two places the card keeps them — the chips and the action
	 * row — rather than interleaved on one line.
	 *
	 * @param array<string, mixed>      $site       The site row.
	 * @param array<string, mixed>|null $onboarding Its assignment, if it has one.
	 */
	private static function assign_onboarding_form( array $site, ?array $onboarding ): void {
		$site_id = (string) $site['id'];

		if ( null !== $onboarding ) {
			return;
		}

		$template = self::$template;

		if ( null === $template ) {
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-bwx-assign-onboarding="1">';
		wp_nonce_field( 'bwx_forge_assign_onboarding_' . $site_id );
		echo '<input type="hidden" name="action" value="bwx_forge_assign_onboarding">';
		echo '<input type="hidden" name="site_id" value="' . esc_attr( $site_id ) . '">';
		echo '<button type="submit" class="bw-btn bw-btn--secondary" data-bwx-action="bwx_forge_assign_onboarding"';
		echo ' onclick="return confirm(' . esc_attr( (string) wp_json_encode( __( 'Give this site the current checklist? It is fixed at this version and cannot be changed afterwards.', 'blueworx-forge' ) ) ) . ')">';
		echo esc_html(
			sprintf(
				/* translators: %d: the checklist version number. */
				__( 'Start onboarding (v%d)', 'blueworx-forge' ),
				(int) $template['version']
			)
		);
		echo '</button>';
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

		if ( array() === $held ) {
			echo '<p class="bw-card__note" data-bwx-no-client-people="1">' . esc_html__( 'Nobody has access to this client yet.', 'blueworx-forge' ) . '</p>';
		} else {
			$names = self::$people_by_id;

			/*
			 * A table, because that is what this is: the same four facts about
			 * each person, one row each. As a run-on list — name, dash, role,
			 * status, button — nothing lined up with anything and the button
			 * landed in a different place on every row.
			 */
			echo '<div class="bw-tablescroll"><table class="bw-table" data-bwx-client-people="1">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'Person', 'blueworx-forge' ) . '</th>';
			echo '<th>' . esc_html__( 'Role', 'blueworx-forge' ) . '</th>';
			echo '<th>' . esc_html__( 'Status', 'blueworx-forge' ) . '</th>';
			echo '<th>' . esc_html__( 'Access', 'blueworx-forge' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $held as $membership ) {
				$active = 'active' === (string) $membership['status'];

				echo '<tr data-bwx-membership="' . esc_attr( (string) $membership['id'] ) . '" data-bwx-membership-role="' . esc_attr( (string) $membership['role'] ) . '">';
				echo '<td><span class="bw-table__primary" data-bwx-membership-person>' . esc_html( $names[ (string) $membership['user_id'] ] ?? __( 'Unknown person', 'blueworx-forge' ) ) . '</span></td>';
				echo '<td data-bwx-membership-role-label>' . esc_html( (string) $membership['role_label'] ) . '</td>';
				echo '<td><span class="' . ( $active ? 'bw-badge bw-badge--success' : 'bw-badge bw-badge--neutral' ) . '" data-bwx-status>';
				echo esc_html( $active ? __( 'Active', 'blueworx-forge' ) : __( 'Ended', 'blueworx-forge' ) );
				echo '</span></td>';
				echo '<td>';

				self::end_membership_form( $membership );

				echo '</td>';
				echo '</tr>';
			}

			echo '</tbody></table></div>';
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

		echo '<p class="bw-card__note" data-bwx-contact="' . esc_attr( $client_id ) . '">';

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

		// A row of controls that belong to one action, spaced by the design
		// system rather than by the whitespace between two echoes.
		echo '<form class="bw-toolbar bw-toolbar--card" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-bwx-assign-contact="' . esc_attr( $client_id ) . '">';
		wp_nonce_field( 'bwx_forge_assign_contact_' . $client_id );
		echo '<input type="hidden" name="action" value="bwx_forge_assign_contact">';
		echo '<input type="hidden" name="client_id" value="' . esc_attr( $client_id ) . '">';
		echo '<div class="bw-select">';
		echo '<select class="bw-select__el" name="user_id" aria-label="' . esc_attr__( 'Point of contact', 'blueworx-forge' ) . '">';
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

		echo '</select><i class="bw-icon bw-select__arrow" data-lucide="chevron-down"></i></div>';
		submit_button( __( 'Set contact', 'blueworx-forge' ), 'bw-btn bw-btn--secondary', '', false );
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
			echo '<p class="bw-card__note" data-bwx-no-people-yet="1">' . esc_html__( 'Add somebody on Forge → People first.', 'blueworx-forge' ) . '</p>';

			return;
		}

		echo '<form class="bw-toolbar bw-toolbar--card" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-bwx-add-membership>';
		wp_nonce_field( 'bwx_forge_add_membership_' . $client_id );
		echo '<input type="hidden" name="action" value="bwx_forge_add_membership">';
		echo '<input type="hidden" name="client_id" value="' . esc_attr( $client_id ) . '">';

		echo '<div class="bw-select">';
		echo '<select class="bw-select__el" name="user_id" aria-label="' . esc_attr__( 'Person', 'blueworx-forge' ) . '">';

		foreach ( $people as $person ) {
			echo '<option value="' . esc_attr( (string) $person['id'] ) . '">' . esc_html( (string) $person['display_name'] ) . '</option>';
		}

		echo '</select><i class="bw-icon bw-select__arrow" data-lucide="chevron-down"></i></div>';

		echo '<div class="bw-select">';
		echo '<select class="bw-select__el" name="role" aria-label="' . esc_attr__( 'Role', 'blueworx-forge' ) . '">';
		PeopleScreen::role_options();
		echo '</select><i class="bw-icon bw-select__arrow" data-lucide="chevron-down"></i></div>';

		// Empty means every site under the client, which is a real answer rather
		// than a missing one — so it is the first option and says so.
		echo '<div class="bw-select">';
		echo '<select class="bw-select__el" name="client_site_id" aria-label="' . esc_attr__( 'Scope', 'blueworx-forge' ) . '">';
		echo '<option value="">' . esc_html__( 'Every site', 'blueworx-forge' ) . '</option>';

		foreach ( ClientSites::for_client( $client_id, 'active' ) as $site ) {
			echo '<option value="' . esc_attr( (string) $site['id'] ) . '">' . esc_html( (string) $site['name'] ) . '</option>';
		}

		echo '</select><i class="bw-icon bw-select__arrow" data-lucide="chevron-down"></i></div>';

		submit_button( __( 'Give access', 'blueworx-forge' ), 'bw-btn bw-btn--secondary', '', false );
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

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'bwx_forge_end_membership_' . $id );
		echo '<input type="hidden" name="action" value="bwx_forge_end_membership">';
		echo '<input type="hidden" name="membership_id" value="' . esc_attr( $id ) . '">';
		echo '<input type="hidden" name="record_version" value="' . esc_attr( (string) $membership['record_version'] ) . '">';
		// A row action rather than a filled danger button: one of these on every
		// row turned the section into a column of red, which said this was the
		// thing to do here. It is the exception.
		echo '<div class="bw-rowactions">';
		echo '<button type="submit" class="bw-rowactions__link bw-rowactions__link--danger" data-bwx-end-membership onclick="return confirm(' . esc_attr( (string) wp_json_encode( __( 'End this access?', 'blueworx-forge' ) ) ) . ')">';
		echo esc_html__( 'End access', 'blueworx-forge' );
		echo '</button>';
		echo '</div>';
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

		echo '<span class="' . esc_attr( self::health_tone( $health ) ) . '" data-bwx-connection="' . esc_attr( $health ) . '">';
		echo '<span class="bw-badge__dot"></span>';
		echo esc_html( Health::label( $health ) );
		echo '</span>';

		if ( null !== $integration && $integration['last_seen_at'] > 0 ) {
			echo '<span class="bw-chip bw-chip--plain" data-bwx-last-seen>';
			printf(
				/* translators: %s: how long ago the site last called, e.g. "2 hours". */
				esc_html__( 'last seen %s ago', 'blueworx-forge' ),
				esc_html( human_time_diff( (int) $integration['last_seen_at'], bwx_forge_now() ) )
			);
			echo '</span>';
		}

		echo '<span class="bw-chip bw-chip--plain" data-bwx-mail="' . esc_attr( null === $integration ? 'unknown' : (string) $integration['mail_capable'] ) . '">';
		echo esc_html( self::mail_label( null === $integration ? 'unknown' : (string) $integration['mail_capable'] ) );
		echo '</span>';
	}

	/**
	 * How a connection's health reads as a badge.
	 *
	 * Whole class names rather than a stem with the state appended, for the
	 * reason Page::notice() gives: the admin UI check reads the classes a
	 * screen writes, and one assembled from a variable is one it cannot see.
	 *
	 * @param string $health The health state.
	 * @return string
	 */
	private static function health_tone( string $health ): string {
		switch ( $health ) {
			case Health::CONNECTED:
				return 'bw-badge bw-badge--success';
			case Health::IDLE:
				return 'bw-badge bw-badge--warning';
			case Health::BROKEN:
			case Health::REVOKED:
				return 'bw-badge bw-badge--danger';
			default:
				return 'bw-badge bw-badge--neutral';
		}
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

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'bwx_forge_issue_site_key_' . $site_id );
		echo '<input type="hidden" name="action" value="bwx_forge_issue_site_key">';
		echo '<input type="hidden" name="site_id" value="' . esc_attr( $site_id ) . '">';
		echo '<button type="submit" class="bw-btn bw-btn--secondary" data-bwx-issue-key onclick="return confirm(' . esc_attr( (string) wp_json_encode( $question ) ) . ')">';
		echo esc_html( $issuing );
		echo '</button>';
		echo '</form>';

		if ( ! $has_key ) {
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'bwx_forge_revoke_site_key_' . $site_id );
		echo '<input type="hidden" name="action" value="bwx_forge_revoke_site_key">';
		echo '<input type="hidden" name="site_id" value="' . esc_attr( $site_id ) . '">';
		echo '<button type="submit" class="bw-btn bw-btn--danger" data-bwx-revoke-key onclick="return confirm(' . esc_attr( (string) wp_json_encode( __( 'Cut this site off? Its key stops working immediately.', 'blueworx-forge' ) ) ) . ')">';
		echo esc_html__( 'Revoke key', 'blueworx-forge' );
		echo '</button>';
		echo '</form>';
	}

	/**
	 * The form that adds a new client.
	 */
	private static function add_client_form(): void {
		Page::panel_open(
			__( 'Add a client', 'blueworx-forge' ),
			'add-client',
			array( 'data-bwx-add-client' => '1' )
		);

		wp_nonce_field( 'bwx_forge_add_client' );
		echo '<input type="hidden" name="action" value="bwx_forge_add_client">';

		echo '<div class="bw-formrow">';
		echo '<label class="bw-formrow__label" for="bwx-client-name">' . esc_html__( 'Name', 'blueworx-forge' ) . '</label>';
		echo '<div class="bw-formrow__control"><input type="text" id="bwx-client-name" name="display_name" class="bw-input" required></div>';
		echo '</div>';

		echo '<div class="bw-formrow">';
		echo '<label class="bw-formrow__label" for="bwx-client-timezone">' . esc_html__( 'Timezone', 'blueworx-forge' ) . '</label>';
		echo '<div class="bw-formrow__control"><div class="bw-select">';
		echo '<select class="bw-select__el" id="bwx-client-timezone" name="timezone">';

		foreach ( timezone_identifiers_list() as $timezone ) {
			echo '<option value="' . esc_attr( $timezone ) . '"' . selected( 'UTC', $timezone, false ) . '>' . esc_html( $timezone ) . '</option>';
		}

		echo '</select><i class="bw-icon bw-select__arrow" data-lucide="chevron-down"></i>';
		echo '</div></div></div>';

		echo '<div class="bw-formrow">';
		echo '<label class="bw-formrow__label" for="bwx-client-domains">' . esc_html__( 'Permitted email domains', 'blueworx-forge' ) . '</label>';
		echo '<div class="bw-formrow__control"><input type="text" id="bwx-client-domains" name="email_domains" class="bw-input" placeholder="acme.co.uk, acme.com"></div>';
		echo '</div>';

		Page::actions_open();
		submit_button( __( 'Add client', 'blueworx-forge' ), 'bw-btn bw-btn--primary', 'submit', false );
		Page::panel_close();
	}

	/**
	 * The form that adds a site under one client.
	 *
	 * @param string $client_id Owning client id.
	 */
	private static function add_site_form( string $client_id ): void {
		echo '<form class="bw-toolbar bw-toolbar--card" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-bwx-add-site>';
		wp_nonce_field( 'bwx_forge_add_client_site_' . $client_id );
		echo '<input type="hidden" name="action" value="bwx_forge_add_client_site">';
		echo '<input type="hidden" name="client_id" value="' . esc_attr( $client_id ) . '">';
		echo '<input type="text" class="bw-input" name="name" placeholder="' . esc_attr__( 'Site name', 'blueworx-forge' ) . '" aria-label="' . esc_attr__( 'Site name', 'blueworx-forge' ) . '" required>';
		echo '<input type="url" class="bw-input" name="url" placeholder="https://" aria-label="' . esc_attr__( 'Site address', 'blueworx-forge' ) . '">';
		submit_button( __( 'Add site', 'blueworx-forge' ), 'bw-btn bw-btn--secondary', '', false );
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

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'bwx_forge_deactivate_client_' . $client_id );
		echo '<input type="hidden" name="action" value="bwx_forge_deactivate_client">';
		echo '<input type="hidden" name="client_id" value="' . esc_attr( $client_id ) . '">';
		echo '<input type="hidden" name="record_version" value="' . esc_attr( (string) $client['record_version'] ) . '">';
		echo '<button type="submit" class="bw-btn bw-btn--danger" data-bwx-deactivate-client onclick="return confirm(' . esc_attr( (string) wp_json_encode( __( 'Deactivate this client and every site under it?', 'blueworx-forge' ) ) ) . ')">';
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

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'bwx_forge_deactivate_client_site_' . $site_id );
		echo '<input type="hidden" name="action" value="bwx_forge_deactivate_client_site">';
		echo '<input type="hidden" name="site_id" value="' . esc_attr( $site_id ) . '">';
		echo '<input type="hidden" name="record_version" value="' . esc_attr( (string) $site['record_version'] ) . '">';
		echo '<button type="submit" class="bw-btn bw-btn--danger" data-bwx-deactivate-site onclick="return confirm(' . esc_attr( (string) wp_json_encode( __( 'Deactivate this site?', 'blueworx-forge' ) ) ) . ')">';
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

		Page::accordion_open(
			__( 'Edit', 'blueworx-forge' ),
			array( 'data-bwx-edit-client' => $client_id )
		);

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'bwx_forge_edit_client_' . $client_id );
		echo '<input type="hidden" name="action" value="bwx_forge_edit_client">';
		echo '<input type="hidden" name="client_id" value="' . esc_attr( $client_id ) . '">';
		echo '<input type="hidden" name="record_version" value="' . esc_attr( (string) $client['record_version'] ) . '">';
		echo '<div class="bw-formrow">';
		echo '<label class="bw-formrow__label" for="bwx-edit-client-name-' . esc_attr( $client_id ) . '">' . esc_html__( 'Name', 'blueworx-forge' ) . '</label>';
		echo '<div class="bw-formrow__control"><input type="text" id="bwx-edit-client-name-' . esc_attr( $client_id ) . '" name="display_name" class="bw-input" value="' . esc_attr( (string) $client['display_name'] ) . '" required></div>';
		echo '</div>';

		echo '<div class="bw-formrow">';
		echo '<label class="bw-formrow__label" for="bwx-edit-client-legal-' . esc_attr( $client_id ) . '">' . esc_html__( 'Legal name', 'blueworx-forge' ) . '</label>';
		echo '<div class="bw-formrow__control"><input type="text" id="bwx-edit-client-legal-' . esc_attr( $client_id ) . '" name="legal_name" class="bw-input" value="' . esc_attr( (string) $client['legal_name'] ) . '"></div>';
		echo '</div>';

		/*
		 * An input against one shared list, not a select of its own. There are
		 * about four hundred timezones and this form is rendered once per
		 * client: as a select, a studio with eighty clients was served a
		 * two-and-a-half megabyte page carrying thirty-six thousand options,
		 * which the browser then had to lay out. The rule that matters is
		 * server-side in Validate::client() either way.
		 */
		echo '<div class="bw-formrow">';
		echo '<label class="bw-formrow__label" for="bwx-edit-client-timezone-' . esc_attr( $client_id ) . '">' . esc_html__( 'Timezone', 'blueworx-forge' ) . '</label>';
		echo '<div class="bw-formrow__control"><input type="text" id="bwx-edit-client-timezone-' . esc_attr( $client_id ) . '" name="timezone" class="bw-input" list="' . esc_attr( self::TIMEZONE_LIST ) . '" value="' . esc_attr( (string) $client['timezone'] ) . '"></div>';
		echo '</div>';

		echo '<div class="bw-formrow">';
		echo '<label class="bw-formrow__label" for="bwx-edit-client-domains-' . esc_attr( $client_id ) . '">' . esc_html__( 'Permitted email domains', 'blueworx-forge' ) . '</label>';
		echo '<div class="bw-formrow__control"><input type="text" id="bwx-edit-client-domains-' . esc_attr( $client_id ) . '" name="email_domains" class="bw-input" value="' . esc_attr( implode( ', ', (array) $client['email_domains'] ) ) . '"></div>';
		echo '</div>';

		echo '<div class="bw-formrow">';
		echo '<label class="bw-formrow__label" for="bwx-edit-client-status-' . esc_attr( $client_id ) . '">' . esc_html__( 'Status', 'blueworx-forge' ) . '</label>';
		echo '<div class="bw-formrow__control"><div class="bw-select">';
		echo '<select class="bw-select__el" id="bwx-edit-client-status-' . esc_attr( $client_id ) . '" name="status">';

		foreach ( Validate::STATUSES as $status_option ) {
			echo '<option value="' . esc_attr( $status_option ) . '"' . selected( (string) $client['status'], $status_option, false ) . '>' . esc_html( ucfirst( $status_option ) ) . '</option>';
		}

		echo '</select><i class="bw-icon bw-select__arrow" data-lucide="chevron-down"></i>';
		echo '</div></div></div>';

		echo '<div class="bw-toolbar bw-toolbar--card">';
		submit_button( __( 'Save', 'blueworx-forge' ), 'bw-btn bw-btn--primary', '', false );
		echo '</div>';
		echo '</form>';

		Page::accordion_close();
	}

	/**
	 * The form that edits a client site — every writable field, including
	 * status, so this is also how an inactive site is set back to active.
	 *
	 * @param array<string, mixed> $site The site row.
	 */
	private static function edit_site_form( array $site ): void {
		$site_id = (string) $site['id'];

		Page::accordion_open(
			__( 'Edit', 'blueworx-forge' ),
			array( 'data-bwx-edit-site' => $site_id )
		);

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'bwx_forge_edit_client_site_' . $site_id );
		echo '<input type="hidden" name="action" value="bwx_forge_edit_client_site">';
		echo '<input type="hidden" name="site_id" value="' . esc_attr( $site_id ) . '">';
		echo '<input type="hidden" name="record_version" value="' . esc_attr( (string) $site['record_version'] ) . '">';

		echo '<div class="bw-formrow">';
		echo '<label class="bw-formrow__label" for="bwx-edit-site-name-' . esc_attr( $site_id ) . '">' . esc_html__( 'Name', 'blueworx-forge' ) . '</label>';
		echo '<div class="bw-formrow__control"><input type="text" id="bwx-edit-site-name-' . esc_attr( $site_id ) . '" class="bw-input" name="name" value="' . esc_attr( (string) $site['name'] ) . '" required></div>';
		echo '</div>';

		echo '<div class="bw-formrow">';
		echo '<label class="bw-formrow__label" for="bwx-edit-site-url-' . esc_attr( $site_id ) . '">' . esc_html__( 'Address', 'blueworx-forge' ) . '</label>';
		echo '<div class="bw-formrow__control"><input type="url" id="bwx-edit-site-url-' . esc_attr( $site_id ) . '" class="bw-input" name="url" value="' . esc_attr( (string) $site['url'] ) . '" placeholder="https://"></div>';
		echo '</div>';

		echo '<div class="bw-formrow">';
		echo '<label class="bw-formrow__label" for="bwx-edit-site-status-' . esc_attr( $site_id ) . '">' . esc_html__( 'Status', 'blueworx-forge' ) . '</label>';
		echo '<div class="bw-formrow__control"><div class="bw-select">';
		echo '<select class="bw-select__el" id="bwx-edit-site-status-' . esc_attr( $site_id ) . '" name="status">';

		foreach ( Validate::STATUSES as $status_option ) {
			echo '<option value="' . esc_attr( $status_option ) . '"' . selected( (string) $site['status'], $status_option, false ) . '>' . esc_html( ucfirst( $status_option ) ) . '</option>';
		}

		echo '</select><i class="bw-icon bw-select__arrow" data-lucide="chevron-down"></i>';
		echo '</div></div></div>';

		echo '<div class="bw-toolbar bw-toolbar--card">';
		submit_button( __( 'Save', 'blueworx-forge' ), 'bw-btn bw-btn--primary', '', false );
		echo '</div>';
		echo '</form>';

		Page::accordion_close();
	}
}
