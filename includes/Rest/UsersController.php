<?php
/**
 * The user routes.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Tenancy\Accounts;
use Blueworx\Forge\Tenancy\Clients;
use Blueworx\Forge\Tenancy\ClientSites;
use Blueworx\Forge\Tenancy\Grants;
use Blueworx\Forge\Tenancy\Memberships;
use Blueworx\Forge\Tenancy\PersonReach;
use Blueworx\Forge\Tenancy\Reach;
use Blueworx\Forge\Tenancy\Users;
use Blueworx\Forge\Tenancy\Validate;
use WP_REST_Request;
use WP_REST_Response;

/**
 * People (#90). One person, one account, whatever number of clients they work
 * with (AUTH-6) — so this creates a person, and Memberships decides where they
 * work and as what.
 *
 * Every route here is gated to Permissions::manage(); real access roles arrive
 * with #91.
 *
 * The routes that join a person to their WordPress account mirror the People
 * admin page's handlers check for check (PR 3 of the move into the app), so
 * the screen and the page cannot disagree about what is refused.
 */
final class UsersController {

	/**
	 * Name this write is remembered under.
	 */
	private const CREATE_OPERATION = 'create_user';

	/**
	 * Name the add-from-an-account write is remembered under.
	 */
	private const FROM_ACCOUNT_OPERATION = 'users.from_account';

	/**
	 * Why every route here sits outside the tenant boundary.
	 */
	private const OPEN = array(
		'kind'   => Boundary::SCOPE_OPEN,
		'reason' => 'People are global records held once and reachable everywhere (AUTH-6), not a client\'s. Administrator-only configuration (ARCH-7).',
	);

	/**
	 * Registers this controller's routes.
	 *
	 * @param string $route_namespace REST namespace.
	 */
	public static function register_routes( string $route_namespace ): void {
		$version = array(
			Versioning::PARAM => array(
				'type'        => 'integer',
				'required'    => false,
				'description' => 'The record version this write was made against.',
			),
		);

		Server::register_route(
			$route_namespace,
			'/users',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'index' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => self::OPEN,
				'args'                => array(
					'status' => array(
						'type'    => 'string',
						'default' => 'active',
					),
					'with'   => array(
						'type'        => 'string',
						'default'     => '',
						'description' => 'Set to "memberships" to carry each person\'s memberships and account.',
					),
				),
			)
		);

		/*
		 * Our people, by name, for anyone signed in (2026-09-20). The pickers
		 * — the seats, who does a chore, who comes to a meeting — need names
		 * to offer, and /users above is the administrator's: a staff member
		 * opening a task was getting an empty list and cards full of "?".
		 * Names only; nothing here that /users keeps to administrators.
		 */
		Server::register_route(
			$route_namespace,
			'/people',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'people' ),
				'permission_callback' => array( Permissions::class, 'signed_in' ),
				'scope'               => self::OPEN,
				'args'                => array(
					'client_site_id' => array(
						'type'        => 'string',
						'default'     => '',
						'description' => 'Only the people who can do work on this site (#393).',
					),
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/users',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'create' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => self::OPEN,
			)
		);

		Server::register_route(
			$route_namespace,
			'/users/from-account',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'create_from_account' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => self::OPEN,
			)
		);

		Server::register_route(
			$route_namespace,
			'/accounts',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'accounts' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => self::OPEN,
			)
		);

		Server::register_route(
			$route_namespace,
			'/grants',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'grants' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => self::OPEN,
			)
		);

		Server::register_route(
			$route_namespace,
			'/users/(?P<user_id>[A-Za-z0-9_\-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'show' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => self::OPEN,
			)
		);

		Server::register_route(
			$route_namespace,
			'/users/(?P<user_id>[A-Za-z0-9_\-]+)',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( self::class, 'update' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => self::OPEN,
				'args'                => $version,
			)
		);

		Server::register_route(
			$route_namespace,
			'/users/(?P<user_id>[A-Za-z0-9_\-]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( self::class, 'delete' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => self::OPEN,
			)
		);

		Server::register_route(
			$route_namespace,
			'/users/(?P<user_id>[A-Za-z0-9_\-]+)/account',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'link_account' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => self::OPEN,
				'args'                => $version,
			)
		);

		Server::register_route(
			$route_namespace,
			'/users/(?P<user_id>[A-Za-z0-9_\-]+)/offboard',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'offboard' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => self::OPEN,
				'args'                => $version,
			)
		);

		Server::register_route(
			$route_namespace,
			'/users/(?P<user_id>[A-Za-z0-9_\-]+)/memberships',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'memberships' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => self::OPEN,
				'args'                => array(
					'status' => array(
						'type'    => 'string',
						'default' => 'active',
					),
				),
			)
		);
	}

	/**
	 * Everyone, filtered by status.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function index( WP_REST_Request $request ): WP_REST_Response {
		$status = (string) $request->get_param( 'status' );
		$users  = Users::all( 'all' === $status ? null : $status );

		if ( 'memberships' === (string) $request->get_param( 'with' ) ) {
			$users = self::with_memberships( $users );
		}

		return rest_ensure_response(
			array(
				'ok'    => true,
				'users' => $users,
			)
		);
	}

	/**
	 * Our own people, by name, for the pickers.
	 *
	 * Given a site, only the ones who can do work on it (#393): the seat
	 * pickers offer these, and the save refuses anybody else. The site has to
	 * be one the caller reaches, or who works on it is not theirs to learn.
	 *
	 * @param WP_REST_Request|null $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function people( ?WP_REST_Request $request = null ) {
		$site_id = null === $request ? '' : trim( (string) $request->get_param( 'client_site_id' ) );
		$people  = Users::ours();

		if ( '' !== $site_id ) {
			$site = ClientSites::get( $site_id );

			if ( null === $site || ! Reach::reaches_site( Boundary::current(), (string) $site['client_id'], (string) $site['id'] ) ) {
				return Boundary::absent( 'client_site' );
			}

			$people = PersonReach::staff_on_site( (string) $site['client_id'], (string) $site['id'] );
		}

		return rest_ensure_response(
			array(
				'ok'     => true,
				'people' => array_map(
					static fn( array $person ): array => array(
						'id'           => (string) $person['id'],
						'display_name' => (string) $person['display_name'],
						'status'       => (string) $person['status'],
					),
					$people
				),
			)
		);
	}

	/**
	 * Everybody with their memberships and account, so the People screen draws
	 * itself from one read.
	 *
	 * Four reads for the whole list, however long it is: every membership,
	 * every client, every site, and the accounts in one warm of the user cache.
	 * The screen must not make a call per card to label it.
	 *
	 * @param array<int, array<string, mixed>> $users Every person listed.
	 * @return array<int, array<string, mixed>>
	 */
	private static function with_memberships( array $users ): array {
		$by_user = array();

		foreach ( Memberships::by_client( null ) as $held ) {
			foreach ( $held as $membership ) {
				$by_user[ (string) $membership['user_id'] ][] = $membership;
			}
		}

		$clients = array();
		$sites   = array();

		foreach ( Clients::all( null ) as $client ) {
			$clients[ (string) $client['id'] ] = $client;
		}

		foreach ( ClientSites::all( null ) as $site ) {
			$sites[ (string) $site['id'] ] = $site;
		}

		$wp_ids = array_filter( array_map( 'intval', array_column( $users, 'wp_user_id' ) ) );

		if ( array() !== $wp_ids ) {
			cache_users( array_values( $wp_ids ) );
		}

		$listed = array();

		foreach ( $users as $user ) {
			$person = self::person( $user, $by_user[ (string) $user['id'] ] ?? array(), $clients, $sites );

			$person['user']['memberships'] = $person['memberships'];

			$listed[] = $person['user'];
		}

		return $listed;
	}

	/**
	 * One person, with their account and everywhere they work.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function show( WP_REST_Request $request ) {
		$user = Users::get( (string) $request['user_id'] );

		if ( null === $user ) {
			return self::unknown_user();
		}

		return rest_ensure_response( self::answer( $user ) );
	}

	/**
	 * Every grant there is, with what it means, split by where it is held —
	 * for the screen that hands them out.
	 *
	 * @return WP_REST_Response
	 */
	public static function grants(): WP_REST_Response {
		$describe = static function ( string $grant ): array {
			return array(
				'grant'       => $grant,
				'label'       => Grants::label( $grant ),
				'description' => Grants::description( $grant ),
			);
		};

		return rest_ensure_response(
			array(
				'ok'            => true,
				'on_user'       => array_map( $describe, Grants::ON_USER ),
				'on_membership' => array_map( $describe, Grants::ON_MEMBERSHIP ),
			)
		);
	}

	/**
	 * Everything one person holds, across every client. This is the query #90
	 * exists to make answerable.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function memberships( WP_REST_Request $request ) {
		$user = Users::get( (string) $request['user_id'] );

		if ( null === $user ) {
			return Errors::rest( 'unknown_user', __( 'There is no such person.', 'blueworx-forge' ), 404 );
		}

		$status = (string) $request->get_param( 'status' );

		return rest_ensure_response(
			array(
				'ok'          => true,
				'memberships' => Memberships::for_user( $user['id'], 'all' === $status ? null : $status ),
			)
		);
	}

	/**
	 * Adds a person.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function create( WP_REST_Request $request ) {
		$key = (string) $request->get_header( Idempotency::HEADER );

		if ( '' !== $key ) {
			if ( ! Idempotency::is_valid_key( $key ) ) {
				return Errors::rest(
					'invalid_idempotency_key',
					__( 'That retry key cannot be used.', 'blueworx-forge' ),
					400
				);
			}

			$replay = Idempotency::replay( self::CREATE_OPERATION, $key );

			if ( null !== $replay ) {
				return rest_ensure_response( $replay );
			}
		}

		$body    = (array) $request->get_json_params();
		$checked = Validate::user( $body, false );

		if ( array() !== $checked['errors'] ) {
			return Errors::rest(
				'invalid_user',
				__( 'That person could not be saved.', 'blueworx-forge' ),
				400,
				array( 'fields' => $checked['errors'] )
			);
		}

		/*
		 * Checked before the insert so the answer is a usable one — "that is
		 * already somebody, here they are" rather than a failed write. The
		 * unique index behind the column is what makes it true when two
		 * requests arrive together; this is what makes it explicable.
		 */
		$existing = Users::by_email( (string) $checked['values']['email'] );

		if ( null !== $existing ) {
			return self::exists( $existing );
		}

		/*
		 * #292. The screen's "add somebody new" asks for an account with them,
		 * as the admin page makes one: a person who cannot sign in is not a
		 * person we have added. The account comes first so a refusal from
		 * WordPress leaves nothing behind.
		 *
		 * Opt-in rather than the default, because a body naming neither has
		 * always made a person with no account — the shape everybody added
		 * before #292 has — and callers still rely on being able to make one.
		 * A body that names an account is taken as it stands.
		 */
		if ( ! empty( $body['make_account'] ) && ! array_key_exists( 'wp_user_id', $body ) ) {
			$wp_user_id = Accounts::ensure(
				(string) $checked['values']['display_name'],
				(string) $checked['values']['email']
			);

			if ( $wp_user_id <= 0 ) {
				return self::no_account();
			}

			$holder = Users::by_wp_user( $wp_user_id );

			if ( null !== $holder ) {
				return self::exists( $holder );
			}

			$checked['values']['wp_user_id'] = $wp_user_id;
		}

		$user = Users::create( $checked['values'], get_current_user_id() );

		if ( null === $user ) {
			return Errors::rest(
				'write_failed',
				__( 'That person could not be saved.', 'blueworx-forge' ),
				500
			);
		}

		$response = self::answer( $user );

		if ( '' !== $key ) {
			Idempotency::remember( self::CREATE_OPERATION, $key, $response );
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Adds somebody who already has a WordPress account (#292).
	 *
	 * Their name and address come off the account rather than out of the body.
	 * Two places to state the same fact is two places for it to differ, and
	 * the account is the one they sign in with.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function create_from_account( WP_REST_Request $request ) {
		$key = (string) $request->get_header( Idempotency::HEADER );

		if ( '' !== $key ) {
			if ( ! Idempotency::is_valid_key( $key ) ) {
				return Errors::rest(
					'invalid_idempotency_key',
					__( 'That retry key cannot be used.', 'blueworx-forge' ),
					400
				);
			}

			$replay = Idempotency::replay( self::FROM_ACCOUNT_OPERATION, $key );

			if ( null !== $replay ) {
				return rest_ensure_response( $replay );
			}
		}

		$body    = (array) $request->get_json_params();
		$account = Accounts::account( (int) ( $body['wp_user_id'] ?? 0 ) );

		if ( null === $account ) {
			return Errors::rest(
				'unknown_account',
				__( 'There is no such WordPress account.', 'blueworx-forge' ),
				404
			);
		}

		$holder = Users::by_wp_user( (int) $account['id'] );

		if ( null !== $holder ) {
			return self::exists( $holder );
		}

		$checked = Validate::user(
			array(
				'display_name' => (string) $account['display_name'],
				'email'        => (string) $account['user_email'],
				'wp_user_id'   => (int) $account['id'],
			),
			false
		);

		if ( array() !== $checked['errors'] ) {
			return Errors::rest(
				'invalid_user',
				__( 'That account does not carry a name and address we can use.', 'blueworx-forge' ),
				400,
				array( 'fields' => $checked['errors'] )
			);
		}

		$existing = Users::by_email( (string) $checked['values']['email'] );

		if ( null !== $existing ) {
			return self::exists( $existing );
		}

		$user = Users::create( $checked['values'], get_current_user_id() );

		if ( null === $user ) {
			return Errors::rest(
				'write_failed',
				__( 'That person could not be saved.', 'blueworx-forge' ),
				500
			);
		}

		$response = self::answer( $user );

		if ( '' !== $key ) {
			Idempotency::remember( self::FROM_ACCOUNT_OPERATION, $key, $response );
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Gives somebody who was added before #292 the account they never had:
	 * either an existing one they are joined to, or a new one made from the
	 * name and address already on their record.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function link_account( WP_REST_Request $request ) {
		$user = Users::get( (string) $request['user_id'] );

		if ( null === $user ) {
			return self::unknown_user();
		}

		$sent  = $request->get_param( Versioning::PARAM );
		$stale = Versioning::check( null === $sent ? null : (int) $sent, $user['record_version'], $user );

		if ( null !== $stale ) {
			return $stale;
		}

		$body   = (array) $request->get_json_params();
		$chosen = (int) ( $body['wp_user_id'] ?? 0 );

		$wp_user_id = $chosen > 0
			? $chosen
			: Accounts::ensure( (string) $user['display_name'], (string) $user['email'] );

		if ( $wp_user_id <= 0 || null === Accounts::account( $wp_user_id ) ) {
			return $chosen > 0
				? Errors::rest( 'no_account', __( 'There is no such WordPress account.', 'blueworx-forge' ), 400 )
				: self::no_account();
		}

		$refusal = Accounts::link_error( Users::by_wp_user( $wp_user_id ), (string) $user['id'] );

		if ( null !== $refusal ) {
			return Errors::rest( 'user_exists', $refusal, 409 );
		}

		$updated = Users::update( $user['id'], array( 'wp_user_id' => $wp_user_id ), (int) $sent );

		if ( null === $updated ) {
			return self::not_written( $user['id'], (int) $sent );
		}

		// An account that was already there wins: somebody joined to an existing
		// login is joined to the person behind it, not renamed to match a record
		// made about them. One we just made takes the record's name instead.
		if ( $chosen > 0 ) {
			Accounts::pull( $wp_user_id );
		} else {
			Accounts::push( $updated );
		}

		// Re-read rather than answer with $updated: a pull has just rewritten
		// the person's name and address, and moved the version on with them.
		$current = Users::get( $user['id'] );

		return rest_ensure_response( self::answer( null === $current ? $updated : $current ) );
	}

	/**
	 * Every WordPress account that is nobody in Forge yet — what the add-from-
	 * an-account and link pickers offer.
	 *
	 * @return WP_REST_Response
	 */
	public static function accounts(): WP_REST_Response {
		return rest_ensure_response(
			array(
				'ok'       => true,
				'accounts' => Accounts::unlinked(),
			)
		);
	}

	/**
	 * Edits a person, including offboarding them.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function update( WP_REST_Request $request ) {
		$user = Users::get( (string) $request['user_id'] );

		if ( null === $user ) {
			return self::unknown_user();
		}

		$sent  = $request->get_param( Versioning::PARAM );
		$stale = Versioning::check( null === $sent ? null : (int) $sent, $user['record_version'], $user );

		if ( null !== $stale ) {
			return $stale;
		}

		$checked = Validate::user( (array) $request->get_json_params(), true );

		if ( array() !== $checked['errors'] ) {
			return Errors::rest(
				'invalid_user',
				__( 'That change could not be saved.', 'blueworx-forge' ),
				400,
				array( 'fields' => $checked['errors'] )
			);
		}

		if ( array_key_exists( 'email', $checked['values'] ) ) {
			$email  = (string) $checked['values']['email'];
			$holder = Users::by_email( $email );

			// Moving somebody to an address that is already somebody else's would
			// merge two people, so it is refused here rather than left to the index.
			if ( null !== $holder && $holder['id'] !== $user['id'] ) {
				return Errors::rest(
					'user_exists',
					__( 'Somebody else already has that email address.', 'blueworx-forge' ),
					409
				);
			}

			// #292. The same question asked of WordPress, before the write rather
			// than after it. An address another account holds is refused there
			// too, and finding that out afterwards would leave the two sides
			// disagreeing about who somebody is.
			//
			// Only for somebody who has an account, because only their save
			// writes to WordPress. Somebody added before #292 has none, and an
			// account that happens to hold their address is not a clash — it is
			// the one they will be joined to when they are given an account.
			$wp_holder = (int) $user['wp_user_id'] > 0 ? get_user_by( 'email', $email ) : false;

			if ( $wp_holder && (int) $wp_holder->ID !== (int) $user['wp_user_id'] ) {
				return Errors::rest(
					'user_exists',
					__( 'Another WordPress account already has that email address.', 'blueworx-forge' ),
					409
				);
			}
		}

		// Offboarding goes through deactivate(), which also ends every
		// membership they hold (AUTH-6). A plain update would leave somebody
		// with a closed account and live access.
		$updated = 'inactive' === (string) ( $checked['values']['status'] ?? '' )
			? Users::deactivate( $user['id'], (int) $sent, $checked['values'] )
			: Users::update( $user['id'], $checked['values'], (int) $sent );

		if ( null === $updated ) {
			return self::not_written( $user['id'], (int) $sent );
		}

		// #292. The account follows the person. Offboarding deliberately does
		// not delete it — their history is attributed to it, and WordPress
		// deleting a user reassigns or destroys everything they wrote.
		Accounts::push( $updated );

		return rest_ensure_response( self::answer( $updated ) );
	}

	/**
	 * Offboards somebody: the account and every membership, in one action.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function offboard( WP_REST_Request $request ) {
		$user = Users::get( (string) $request['user_id'] );

		if ( null === $user ) {
			return self::unknown_user();
		}

		$sent  = $request->get_param( Versioning::PARAM );
		$stale = Versioning::check( null === $sent ? null : (int) $sent, $user['record_version'], $user );

		if ( null !== $stale ) {
			return $stale;
		}

		$updated = Users::deactivate( $user['id'], (int) $sent );

		if ( null === $updated ) {
			return self::not_written( $user['id'], (int) $sent );
		}

		return rest_ensure_response( self::answer( $updated ) );
	}

	/**
	 * Deletes somebody from Forge. Their WordPress account stays.
	 *
	 * Only somebody already offboarded can go: offboarding is the step that
	 * ends their access, and deleting is for a record that should never have
	 * been here — somebody added by mistake, or twice.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function delete( WP_REST_Request $request ) {
		$user = Users::get( (string) $request['user_id'] );

		if ( null === $user ) {
			return self::unknown_user();
		}

		if ( 'active' === (string) $user['status'] ) {
			return Errors::rest(
				'person_active',
				__( 'Offboard them first.', 'blueworx-forge' ),
				400
			);
		}

		if ( ! Users::delete( $user['id'] ) ) {
			return Errors::rest(
				'person_has_history',
				__( 'Somebody with work attributed to them cannot be deleted, only offboarded.', 'blueworx-forge' ),
				400
			);
		}

		return rest_ensure_response(
			array(
				'ok'      => true,
				'deleted' => $user['id'],
			)
		);
	}

	// -----------------------------------------------------------------------
	// The person answer.
	// -----------------------------------------------------------------------

	/**
	 * What every write about a person answers with: the row, the account they
	 * sign in with, and everywhere they work with the client and site named —
	 * so the screen can redraw that one card without reading anything else.
	 *
	 * @param array<string, mixed> $user The person, as Users::get() returns.
	 * @return array<string, mixed>
	 */
	private static function answer( array $user ): array {
		$clients = array();
		$sites   = array();

		return array_merge(
			array( 'ok' => true ),
			self::person( $user, Memberships::for_user( (string) $user['id'], null ), $clients, $sites )
		);
	}

	/**
	 * One person with their account and labelled memberships.
	 *
	 * The client and site lookups are memoised in the arrays passed in, so a
	 * list of people shares one read per client rather than one per card.
	 *
	 * @param array<string, mixed>                     $user        The person.
	 * @param array<int, array<string, mixed>>         $memberships Their memberships, every status.
	 * @param array<string, array<string, mixed>|null> $clients     Clients by id, filled as needed.
	 * @param array<string, array<string, mixed>|null> $sites       Sites by id, filled as needed.
	 * @return array{user: array<string, mixed>, memberships: array<int, array<string, mixed>>}
	 */
	private static function person( array $user, array $memberships, array &$clients, array &$sites ): array {
		$account = Accounts::account( (int) $user['wp_user_id'] );

		$user['account'] = null === $account ? null : array(
			'id'    => (int) $account['id'],
			'login' => (string) $account['login'],
		);

		$labelled = array();

		foreach ( $memberships as $membership ) {
			$client_id = (string) $membership['client_id'];
			$site_id   = (string) $membership['client_site_id'];

			if ( ! array_key_exists( $client_id, $clients ) ) {
				$clients[ $client_id ] = Clients::get( $client_id );
			}

			if ( '' !== $site_id && ! array_key_exists( $site_id, $sites ) ) {
				$sites[ $site_id ] = ClientSites::get( $site_id );
			}

			$membership['client_name'] = null === $clients[ $client_id ] ? '' : (string) $clients[ $client_id ]['display_name'];
			$membership['site_name']   = '' === $site_id || null === $sites[ $site_id ] ? null : (string) $sites[ $site_id ]['name'];

			$labelled[] = $membership;
		}

		return array(
			'user'        => $user,
			'memberships' => $labelled,
		);
	}

	// -----------------------------------------------------------------------
	// The refusals more than one route makes.
	// -----------------------------------------------------------------------

	/**
	 * There is no such person.
	 *
	 * @return \WP_Error
	 */
	private static function unknown_user() {
		return Errors::rest( 'unknown_user', __( 'There is no such person.', 'blueworx-forge' ), 404 );
	}

	/**
	 * That is already somebody — by address or by account. Names who, so the
	 * fix is obvious: give that person a membership rather than a second row.
	 *
	 * @param array<string, mixed> $holder The person who already exists.
	 * @return \WP_Error
	 */
	private static function exists( array $holder ) {
		return Errors::rest(
			'user_exists',
			__( 'That is already somebody here.', 'blueworx-forge' ),
			409,
			array( 'user' => $holder )
		);
	}

	/**
	 * WordPress would not make the account, so nothing was saved.
	 *
	 * @return \WP_Error
	 */
	private static function no_account() {
		return Errors::rest(
			'no_account',
			__( 'WordPress would not make an account for them, so nothing was saved.', 'blueworx-forge' ),
			400
		);
	}

	/**
	 * A write to a person came back empty: either the row moved between the
	 * version check and the write, or the write itself failed. Re-read and ask
	 * Versioning again, so a real failure is never reported as a stale one.
	 *
	 * @param string $user_id The person.
	 * @param int    $sent    The version the write was made against.
	 * @return \WP_Error
	 */
	private static function not_written( string $user_id, int $sent ) {
		$current = Users::get( $user_id );

		$mismatch = Versioning::check(
			$sent,
			null === $current ? 0 : $current['record_version'],
			null === $current ? array() : $current
		);

		if ( null !== $mismatch ) {
			return $mismatch;
		}

		return Errors::rest(
			'write_failed',
			__( 'That change could not be saved.', 'blueworx-forge' ),
			500
		);
	}
}
