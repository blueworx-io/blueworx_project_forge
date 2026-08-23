<?php
/**
 * Routes a registered client site may call.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Sites\Registry;
use Blueworx\Forge\Work\ClientView;
use Blueworx\Forge\Work\Items;
use Blueworx\Forge\Work\Stages;
use Blueworx\Forge\Work\Submissions;
use Blueworx\Forge\Work\Validate as WorkValidate;
use Blueworx\Forge\Tenancy\Contacts;
use Blueworx\Forge\Tenancy\Integrations;
use Blueworx\Forge\Tenancy\Users;
use Blueworx\Forge\Tenancy\Validate;
use WP_REST_Request;
use WP_REST_Response;

/**
 * What a client site can ask the studio, once it has proved which client it is.
 *
 * Two routes: the handshake, which answers "yes, you are this site", and the
 * workspace, which is the first canonical record a client site renders without
 * holding a copy of it (ARCH-2).
 *
 * Every route here uses Permissions::client_site, never a capability check: the
 * caller is a machine, not a logged-in user.
 */
final class ClientController {

	/**
	 * Registers this controller's routes.
	 *
	 * @param string $route_namespace REST namespace.
	 */
	public static function register_routes( string $route_namespace ): void {
		Server::register_route(
			$route_namespace,
			'/client/handshake',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'handshake' ),
				'permission_callback' => array( Permissions::class, 'client_site' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_OPEN,
					'reason' => 'Authenticated by the client site\'s own key, not by a person: the signature names which site is calling, so the boundary is the signature (ARCH-6).',
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/client/report',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'report' ),
				'permission_callback' => array( Permissions::class, 'client_site' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_OPEN,
					'reason' => 'Authenticated by the client site\'s own key, not by a person: the signature names which site is calling, so the boundary is the signature (ARCH-6).',
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/client/workspace',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'workspace' ),
				'permission_callback' => array( Permissions::class, 'client_site' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_OPEN,
					'reason' => 'Authenticated by the client site\'s own key, not by a person: the signature names which site is calling, so the boundary is the signature (ARCH-6).',
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/client/board',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'board' ),
				'permission_callback' => array( Permissions::class, 'client_site' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_OPEN,
					'reason' => 'Authenticated by the client site\'s own key, not by a person: the signature names which site is calling, so the boundary is the signature (ARCH-6).',
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/client/submissions',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'submissions' ),
				'permission_callback' => array( Permissions::class, 'client_site' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_OPEN,
					'reason' => 'Authenticated by the client site\'s own key, not by a person: the signature names which site is calling, so the boundary is the signature (ARCH-6).',
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/client/submissions',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'submit' ),
				'permission_callback' => array( Permissions::class, 'client_site' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_OPEN,
					'reason' => 'Authenticated by the client site\'s own key, not by a person: the signature names which site is calling, so the boundary is the signature (ARCH-6).',
				),
			)
		);
	}

	/**
	 * Confirms the calling site's identity.
	 *
	 * The site id is read from the verified header rather than from a parameter:
	 * the header is what the signature covers, so it is the only one that has
	 * been proven. A site_id in the body would be whatever the caller typed.
	 *
	 * `server_time` is here so a client whose clock has drifted can tell that is
	 * what is wrong, rather than seeing its requests refused for no visible
	 * reason once drift passes the signing window.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handshake( WP_REST_Request $request ): WP_REST_Response {
		$site_id = (string) $request->get_header( Signature::HEADER_SITE );
		$site    = Registry::get( $site_id );

		return rest_ensure_response(
			array(
				'ok'          => true,
				'site_id'     => $site_id,
				'name'        => $site['name'] ?? '',
				'status'      => $site['status'] ?? '',
				'server_time' => bwx_forge_now(),
				'version'     => BWX_FORGE_VERSION,
			)
		);
	}

	/**
	 * Records what the calling site says about itself (#89).
	 *
	 * The site it describes is the one that signed the request, never one named
	 * in the body — the same rule the workspace read follows, and for the same
	 * reason: a signed request proves which site is calling and that is the only
	 * site it may touch.
	 *
	 * A site with no integration record is one whose key was issued through M1's
	 * routes rather than against a Client Site. It is turned away rather than
	 * given a record: an integration exists to say which client site a
	 * connection belongs to, and inventing one here would have to guess.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function report( WP_REST_Request $request ) {
		$site_id = (string) $request->get_header( Signature::HEADER_SITE );
		$checked = Validate::report( (array) $request->get_json_params() );

		if ( array() !== $checked['errors'] ) {
			return Errors::rest(
				'invalid_report',
				__( 'That report could not be recorded.', 'blueworx-forge' ),
				400,
				array( 'fields' => $checked['errors'] )
			);
		}

		$updated = Integrations::note_report( $site_id, $checked['values'] );

		if ( null === $updated ) {
			return Errors::rest(
				'no_integration',
				__( 'This site is not connected to a client site.', 'blueworx-forge' ),
				409
			);
		}

		return rest_ensure_response(
			array(
				'ok'       => true,
				'recorded' => $updated['last_report_at'],
			)
		);
	}

	/**
	 * The calling site's workspace record.
	 *
	 * This is the canonical record the client site renders and does not store
	 * (ARCH-2). The site it describes is always the one that signed the request,
	 * never one named in a parameter — a signed request proves which site is
	 * calling, and that is the only site it may read.
	 *
	 * `generated` is the studio's own clock at the moment the record was read.
	 * The client site stamps its cache with the time it received the answer
	 * rather than with this, because the age it shows a human has to be measured
	 * on the clock that human's browser is on. It is here for support: a record
	 * that looks stale on one side and fresh on the other is clock drift, and
	 * this is what shows that.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function workspace( WP_REST_Request $request ): WP_REST_Response {
		$site_id = (string) $request->get_header( Signature::HEADER_SITE );
		$site    = Registry::get( $site_id );

		return rest_ensure_response(
			array(
				'ok'        => true,
				'generated' => bwx_forge_now(),
				'record'    => array(
					'site_id'         => $site_id,
					'name'            => $site['name'] ?? '',
					'url'             => $site['url'] ?? '',
					'status'          => $site['status'] ?? '',
					'connected_since' => (int) ( $site['created_at'] ?? 0 ),
				),
				'contact'   => self::contact_for( $site_id ),
			)
		);
	}

	/**
	 * The calling site's board (#128).
	 *
	 * Everything the client site draws its three views from, in one read. The
	 * site is the one that signed the request, resolved to its client the same
	 * way the contact is — there is no site or client parameter here, so there
	 * is nothing to edit into somebody else's (D-2).
	 *
	 * A connection with no integration record is turned away rather than
	 * answered with an empty board. The two are not the same thing: an empty
	 * board says "you have no work", and a key issued outside a Client Site has
	 * no client whose work it could be describing.
	 *
	 * The stage list travels with the answer rather than being written down a
	 * second time on the client. A board whose columns are its own copy of the
	 * state machine is a board that goes wrong the day a stage is added, and
	 * goes wrong quietly.
	 *
	 * Archived work is left out by Items::for_site()'s own default, which is
	 * where that decision already lives.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function board( WP_REST_Request $request ) {
		$site_id     = (string) $request->get_header( Signature::HEADER_SITE );
		$integration = Integrations::by_site_id( $site_id );

		if ( null === $integration ) {
			return Errors::rest(
				'no_integration',
				__( 'This site is not connected to a client site.', 'blueworx-forge' ),
				409
			);
		}

		$rows = Items::for_site( (string) $integration['client_site_id'] );

		return rest_ensure_response(
			array(
				'ok'        => true,
				'generated' => bwx_forge_now(),
				'stages'    => self::columns(),
				'items'     => ClientView::items( $rows, array( Users::class, 'get' ) ),
			)
		);
	}

	/**
	 * What the calling site has asked for, and what happened to it (#130).
	 *
	 * The mirror of the send, on the same route: a client who asks is owed a way
	 * to see what became of it, and this is that. The site is the one that
	 * signed the request, so a client reads their own submissions and there is
	 * no parameter here through which they could ask for anybody else's (D-2).
	 *
	 * The state names travel with the answer, as the board's column names do.
	 * A client artifact that turned 'in-review' into English itself would hold a
	 * second copy of the studio's intake vocabulary, and that copy would be
	 * wrong the day a state is added.
	 *
	 * The point of contact comes with it because this is the screen where
	 * somebody wants to chase an answer, and a status with nobody's name against
	 * it invites a reply into a support address nobody reads.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function submissions( WP_REST_Request $request ) {
		$site_id     = (string) $request->get_header( Signature::HEADER_SITE );
		$integration = Integrations::by_site_id( $site_id );

		if ( null === $integration ) {
			return Errors::rest(
				'no_integration',
				__( 'This site is not connected to a client site.', 'blueworx-forge' ),
				409
			);
		}

		$rows = Submissions::for_site( (string) $integration['client_site_id'] );

		return rest_ensure_response(
			array(
				'ok'          => true,
				'generated'   => bwx_forge_now(),
				'states'      => self::intake_states(),
				'submissions' => ClientView::submissions( $rows, array( Items::class, 'get' ) ),
				'contact'     => self::contact_for( $site_id ),
			)
		);
	}

	/**
	 * Records something the calling site is asking for (#129).
	 *
	 * A client may ask whether or not they pay for support. That is the point
	 * of the record existing at all: work has a commercial life and a question
	 * does not, so nothing here consults a package, and nothing here can be
	 * refused for the want of one.
	 *
	 * The site is the one that signed the request, and the client is that
	 * site's client. Neither is taken from the body, so a submission cannot be
	 * filed against somebody else's client however it is addressed (D-2).
	 *
	 * What comes back is the whole stored record. The client site shows it as
	 * the receipt, which is the moment their words become fixed (REQ-1).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function submit( WP_REST_Request $request ) {
		$site_id     = (string) $request->get_header( Signature::HEADER_SITE );
		$integration = Integrations::by_site_id( $site_id );

		if ( null === $integration ) {
			return Errors::rest(
				'no_integration',
				__( 'This site is not connected to a client site.', 'blueworx-forge' ),
				409
			);
		}

		$body    = (array) $request->get_json_params();
		$checked = WorkValidate::submission( $body );

		if ( array() !== $checked['errors'] ) {
			return Errors::rest(
				'invalid_submission',
				__( 'That could not be sent.', 'blueworx-forge' ),
				400,
				array( 'fields' => $checked['errors'] )
			);
		}

		// Whoever the client site says was at the keyboard. Recorded as a name
		// and used as a name: NOTIF-1 resolves who to write to from verified
		// client records, never from something typed on the far side of a
		// connection.
		$by = trim( (string) ( $body['submitted_by'] ?? '' ) );

		$stored = Submissions::create(
			(string) $integration['client_site_id'],
			(string) $integration['client_id'],
			$checked['values'],
			mb_substr( $by, 0, 191 )
		);

		if ( null === $stored ) {
			return Errors::rest(
				'submission_not_recorded',
				__( 'That could not be recorded. Nothing has been sent.', 'blueworx-forge' ),
				500
			);
		}

		return rest_ensure_response(
			array(
				'ok'         => true,
				'submission' => $stored,
			)
		);
	}

	/**
	 * The board's columns: every stage, named as the studio names it.
	 *
	 * The names travel with the answer so the client artifact never holds its
	 * own copy of the state machine. A client that translated 'up-next' into
	 * words itself would be a second list to update, in a second language, that
	 * nobody remembers on the day a stage changes.
	 *
	 * @return array<int, array<string, string>>
	 */
	private static function columns(): array {
		return array_map(
			static fn( string $stage ): array => array(
				'slug'  => $stage,
				'label' => Stages::label( $stage ),
			),
			Stages::ALL
		);
	}
	/**
	 * Every intake state, named as the studio names it.
	 *
	 * Sent whole rather than only the states in use, so a client screen can put
	 * words to a submission whatever it has since become without asking again.
	 *
	 * @return array<int, array<string, string>>
	 */
	private static function intake_states(): array {
		return array_map(
			static fn( string $state ): array => array(
				'slug'  => $state,
				'label' => Submissions::label( $state ),
			),
			Submissions::STATES
		);
	}

	/**
	 * Who the client's contact is here, as they may see it (#95).
	 *
	 * A name, and nothing else. The address, the WordPress account and the
	 * grants somebody holds are ours; the last of those says what a member of
	 * staff is allowed to do, and no client has any business reading it.
	 *
	 * While there is no usable contact the client is told nothing rather than
	 * told a name that no longer answers. The flag that somebody needs to fix
	 * that is on our screen, not theirs.
	 *
	 * @param string $site_id The registry site making the request.
	 * @return array<string, mixed>
	 */
	private static function contact_for( string $site_id ): array {
		$integration = Integrations::by_site_id( $site_id );

		if ( null === $integration ) {
			return array();
		}

		$assignment = Contacts::current( (string) $integration['client_id'] );
		$person     = null === $assignment || '' === (string) $assignment['user_id']
			? null
			: Users::get( (string) $assignment['user_id'] );

		$state = Contacts::resolve( $assignment, $person );

		return $state['needs_reassignment'] ? array() : Contacts::for_client( $state['contact'] );
	}
}
