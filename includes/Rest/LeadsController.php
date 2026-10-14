<?php
/**
 * The lead routes (#492).
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Tenancy\Clients;
use Blueworx\Forge\Tenancy\Contacts;
use Blueworx\Forge\Tenancy\Users;
use Blueworx\Forge\Tenancy\Validate;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Leads: businesses we are talking to, before they are clients (#492).
 *
 * A lead is a row in the clients table with a lead status, and these routes
 * are the only way to read or move one. Everything else reads clients through
 * {@see Clients::all()}, which leaves leads out, so a lead can appear nowhere
 * a client does without every list having to remember that.
 */
final class LeadsController {

	/**
	 * Registers this controller's routes.
	 *
	 * @param string $route_namespace REST namespace.
	 */
	public static function register_routes( string $route_namespace ): void {
		$open = array(
			'kind'   => Boundary::SCOPE_OPEN,
			'reason' => 'A lead is nobody\'s tenant yet: there is no membership to scope it to, and only an administrator may read or move one.',
		);

		Server::register_route(
			$route_namespace,
			'/leads',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'index' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => $open,
				'args'                => array(
					'archived' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/leads',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'create' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => $open,
			)
		);

		Server::register_route(
			$route_namespace,
			'/leads/(?P<lead_id>[A-Za-z0-9_\-]+)',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( self::class, 'update' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => $open,
			)
		);

		Server::register_route(
			$route_namespace,
			'/leads/(?P<lead_id>[A-Za-z0-9_\-]+)/convert',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'convert' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => $open,
			)
		);
	}

	/**
	 * Every lead in play, or the archive.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function index( WP_REST_Request $request ): WP_REST_Response {
		$leads    = Clients::leads( (bool) $request->get_param( 'archived' ) );
		$contacts = Contacts::current_by_client();
		$people   = array();

		foreach ( Users::all( null ) as $person ) {
			$people[ (string) $person['id'] ] = $person;
		}

		foreach ( $leads as $index => $lead ) {
			$assignment = $contacts[ (string) $lead['id'] ] ?? null;
			$person     = null === $assignment || '' === (string) $assignment['user_id']
				? null
				: ( $people[ (string) $assignment['user_id'] ] ?? null );

			$leads[ $index ]['contact'] = self::contact( $assignment, $person );
		}

		return rest_ensure_response(
			array(
				'ok'    => true,
				'leads' => $leads,
			)
		);
	}

	/**
	 * Adds a lead. The same details as a client, held as a lead.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function create( WP_REST_Request $request ) {
		$input = (array) $request->get_json_params();
		unset( $input['status'], $input['staff_all'] );

		$checked = Validate::client( $input, false );

		if ( array() !== $checked['errors'] ) {
			return Errors::rest(
				'invalid_lead',
				__( 'That lead could not be saved.', 'blueworx-forge' ),
				400,
				array( 'fields' => $checked['errors'] )
			);
		}

		$values              = $checked['values'];
		$values['status']    = Clients::LEAD;
		$values['staff_all'] = false;

		$lead = Clients::create( $values, get_current_user_id() );

		if ( null === $lead ) {
			return Errors::rest( 'write_failed', __( 'That lead could not be saved.', 'blueworx-forge' ), 500 );
		}

		return rest_ensure_response(
			array(
				'ok'   => true,
				'lead' => $lead,
			)
		);
	}

	/**
	 * Edits a lead, or moves it: lead, opportunity, or into the archive and
	 * back out.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function update( WP_REST_Request $request ) {
		$lead = self::lead( (string) $request['lead_id'] );

		if ( null === $lead ) {
			return Boundary::absent( 'lead' );
		}

		$sent  = $request->get_param( Versioning::PARAM );
		$stale = Versioning::check( null === $sent ? null : (int) $sent, $lead['record_version'], $lead );

		if ( null !== $stale ) {
			return $stale;
		}

		$input  = (array) $request->get_json_params();
		$status = array_key_exists( 'status', $input ) ? trim( (string) $input['status'] ) : null;
		unset( $input['status'], $input['staff_all'], $input[ Versioning::PARAM ] );

		$checked = Validate::client( $input, true );

		if ( null !== $status && ! in_array( $status, Clients::LEAD_STATUSES, true ) ) {
			$checked['errors']['status'] = 'A lead is a lead, an opportunity, or archived.';
		}

		if ( array() !== $checked['errors'] ) {
			return Errors::rest(
				'invalid_lead',
				__( 'That change could not be saved.', 'blueworx-forge' ),
				400,
				array( 'fields' => $checked['errors'] )
			);
		}

		$values = $checked['values'];

		if ( null !== $status ) {
			$values['status'] = $status;
		}

		$updated = Clients::update( $lead['id'], $values, (int) $sent );

		if ( null === $updated ) {
			return self::not_written( $lead['id'], (int) $sent );
		}

		return rest_ensure_response(
			array(
				'ok'   => true,
				'lead' => $updated,
			)
		);
	}

	/**
	 * Makes an opportunity a client: the same row, now active, worked on by
	 * all staff so it is workable at once (#405).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function convert( WP_REST_Request $request ) {
		$lead = self::lead( (string) $request['lead_id'] );

		if ( null === $lead ) {
			return Boundary::absent( 'lead' );
		}

		if ( Clients::OPPORTUNITY !== (string) $lead['status'] ) {
			return Errors::rest(
				'not_an_opportunity',
				__( 'Only an opportunity becomes a client. Move it to Opportunity first.', 'blueworx-forge' ),
				409
			);
		}

		$sent  = $request->get_param( Versioning::PARAM );
		$stale = Versioning::check( null === $sent ? null : (int) $sent, $lead['record_version'], $lead );

		if ( null !== $stale ) {
			return $stale;
		}

		$client = Clients::update(
			$lead['id'],
			array(
				'status'    => 'active',
				'staff_all' => true,
			),
			(int) $sent
		);

		if ( null === $client ) {
			return self::not_written( $lead['id'], (int) $sent );
		}

		return rest_ensure_response(
			array(
				'ok'     => true,
				'client' => $client,
			)
		);
	}

	/**
	 * A lead by id: a row with a lead status, and nothing else. A client's id
	 * is absent here, so these routes cannot move a client.
	 *
	 * @param string $id Row id.
	 * @return array<string, mixed>|null
	 */
	private static function lead( string $id ): ?array {
		$row = Clients::get( $id );

		return null === $row || ! Clients::is_lead( $row ) ? null : $row;
	}

	/**
	 * Why a write changed nothing: a version that moved, or a failed write.
	 *
	 * @param string $id   Row id.
	 * @param int    $sent The version the change was made against.
	 * @return \WP_Error
	 */
	private static function not_written( string $id, int $sent ): \WP_Error {
		$current  = Clients::get( $id );
		$mismatch = Versioning::check(
			$sent,
			null === $current ? 0 : $current['record_version'],
			null === $current ? array() : $current
		);

		if ( null !== $mismatch ) {
			return $mismatch;
		}

		return Errors::rest( 'write_failed', __( 'That change could not be saved.', 'blueworx-forge' ), 500 );
	}

	/**
	 * Who looks after a lead on our side, as the Leads screen draws it.
	 *
	 * @param array<string, mixed>|null $assignment The current contact assignment.
	 * @param array<string, mixed>|null $person     The person named, where known.
	 * @return array<string, mixed>
	 */
	private static function contact( ?array $assignment, ?array $person ): array {
		$state = Contacts::resolve( $assignment, $person );

		if ( null !== $state['contact'] ) {
			$state['contact'] = array(
				'id'           => (string) $state['contact']['id'],
				'display_name' => (string) $state['contact']['display_name'],
				'status'       => (string) $state['contact']['status'],
			);
		}

		return $state;
	}
}
