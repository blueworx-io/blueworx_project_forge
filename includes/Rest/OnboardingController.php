<?php
/**
 * Answering onboarding steps, and the files attached to them.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Onboarding\Answers;
use Blueworx\Forge\Onboarding\Evidence;
use Blueworx\Forge\Onboarding\EvidenceStore;
use Blueworx\Forge\Onboarding\Review;
use Blueworx\Forge\Onboarding\StepEvents;
use Blueworx\Forge\Onboarding\Steps;
use Blueworx\Forge\Tenancy\Capabilities;
use Blueworx\Forge\Tenancy\ClientSites;
use Blueworx\Forge\Tenancy\Reach;
use WP_REST_Request;
use WP_REST_Response;

/**
 * #167, #168. The studio's half of a client's checklist.
 *
 * Three of these routes name a step or an attachment rather than a site, so
 * they declare themselves list-scoped and do the tenant check in the callback:
 * the boundary cannot resolve a client from an id it does not know the shape
 * of, and the callback has to read the record anyway to know whose it is.
 *
 * **Every read of an attachment names the site as well as the record**, and
 * that is done inside {@see Evidence}, not here. A route is one caller; a WHERE
 * clause is the rule. This controller adds the reach check on top of it, so a
 * member of staff with no reach to a client gets the same answer as somebody
 * asking about an id that was never issued.
 *
 * The file itself is never served from its own URL. It lives in a directory the
 * web server refuses (#168), and {@see self::download()} is the only way to
 * read one — which is what makes "unreachable across tenants" a property of the
 * product rather than of a guessed filename.
 *
 * **Reach and permission are two questions, and both writes ask both.** Reach
 * says whether this client's records are any of your business; the capability
 * says whether answering or attaching is something you may do at all. A viewer
 * with perfectly good reach holds neither, so checking only the first would let
 * one write to a checklist.
 */
final class OnboardingController {

	/**
	 * Registers this controller's routes.
	 *
	 * @param string $route_namespace REST namespace.
	 */
	public static function register_routes( string $route_namespace ): void {
		$by_record = static function ( string $record ): array {
			return array(
				'kind'   => Boundary::SCOPE_LIST,
				'reason' => sprintf(
					'The route names %s rather than a site. The callback reads the record to learn whose it is, and checks reach before using it for anything.',
					$record
				),
			);
		};

		Server::register_route(
			$route_namespace,
			'/onboarding/sites/(?P<client_site_id>[A-Za-z0-9_\-]+)/steps',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'steps' ),
				'permission_callback' => array( Permissions::class, 'signed_in' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_SITE,
					'param'  => 'client_site_id',
					'record' => 'client_site',
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/onboarding/steps/(?P<step_id>[A-Za-z0-9_\-]+)',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( self::class, 'answer' ),
				'permission_callback' => array( Permissions::class, 'signed_in' ),
				'scope'               => $by_record( 'a step' ),
			)
		);

		Server::register_route(
			$route_namespace,
			'/onboarding/steps/(?P<step_id>[A-Za-z0-9_\-]+)/evidence',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'attach' ),
				'permission_callback' => array( Permissions::class, 'signed_in' ),
				'scope'               => $by_record( 'a step' ),
			)
		);

		/*
		 * The review decision (#163). Its own route rather than a status on the
		 * answer route above, because the two are different acts by different
		 * people: answering is somebody saying what they did, and reviewing is
		 * somebody deciding whether it counts. Folding them together would have
		 * meant one route whose permissions depended on which field was set.
		 */
		Server::register_route(
			$route_namespace,
			'/onboarding/steps/(?P<step_id>[A-Za-z0-9_\-]+)/review',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'review' ),
				'permission_callback' => array( Permissions::class, 'signed_in' ),
				'scope'               => $by_record( 'a step' ),
			)
		);

		Server::register_route(
			$route_namespace,
			'/onboarding/evidence/(?P<evidence_id>[A-Za-z0-9_\-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'download' ),
				'permission_callback' => array( Permissions::class, 'signed_in' ),
				'scope'               => $by_record( 'an attachment' ),
			)
		);
	}

	/**
	 * One site's checklist, with what is attached to each step.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function steps( WP_REST_Request $request ): WP_REST_Response {
		$site_id = (string) $request->get_param( 'client_site_id' );
		$today   = gmdate( 'Y-m-d', bwx_forge_now() );

		$steps = array();

		foreach ( Steps::for_site( $site_id ) as $step ) {
			$history = StepEvents::for_step( (string) $step['id'] );

			$step             = Steps::with_lateness( $step, $today );
			$step['evidence'] = Evidence::for_step( (string) $step['id'], $site_id );
			$step['history']  = $history;

			// The same value the client is shown, from the same call, so the two
			// screens cannot disagree about what we asked for.
			$step['feedback'] = Review::feedback_from( (string) $step['status'], $history );

			$steps[] = $step;
		}

		return rest_ensure_response( array( 'steps' => $steps ) );
	}

	/**
	 * Records an answer to a step.
	 *
	 * Everything this does about credentials happens inside
	 * {@see Answers::record()} — see that class for why the rule is not here.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function answer( WP_REST_Request $request ) {
		$step = self::their_step( $request );

		if ( ! is_array( $step ) ) {
			return $step;
		}

		$refused = Access::refuse_unless( Capabilities::ANSWER_INFORMATION, self::client_of( (string) $step['client_site_id'] ) );

		if ( null !== $refused ) {
			return $refused;
		}

		$body = (array) $request->get_json_params();

		$result = Answers::record(
			$step,
			$body,
			(string) ( $body['status'] ?? '' ),
			get_current_user_id(),
			Capabilities::STUDIO
		);

		if ( ! isset( $result['step'] ) ) {
			return Errors::rest(
				'answer_refused',
				(string) ( $result['message'] ?? '' ),
				400,
				array( 'field' => (string) ( $result['field'] ?? '' ) )
			);
		}

		return rest_ensure_response( array( 'step' => $result['step'] ) );
	}

	/**
	 * Decides whether a step is done (#163).
	 *
	 * Approving is a review capability rather than an answering one, which is
	 * the whole difference between this route and the one above: a person who
	 * may say what they did is not automatically a person who may say it counts.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function review( WP_REST_Request $request ) {
		$step = self::their_step( $request );

		if ( ! is_array( $step ) ) {
			return $step;
		}

		$refused = Access::refuse_unless( Capabilities::REVIEW_SUBMISSION, self::client_of( (string) $step['client_site_id'] ) );

		if ( null !== $refused ) {
			return $refused;
		}

		$body = (array) $request->get_json_params();

		$result = Review::record(
			$step,
			(string) ( $body['decision'] ?? '' ),
			(string) ( $body['reason'] ?? '' ),
			get_current_user_id()
		);

		if ( ! isset( $result['step'] ) ) {
			return Errors::rest( 'review_refused', (string) ( $result['message'] ?? '' ), 400 );
		}

		return rest_ensure_response( array( 'step' => $result['step'] ) );
	}

	/**
	 * Attaches a file to a step.
	 *
	 * The file arrives base64-encoded in the body rather than as a multipart
	 * upload. That is not a preference: the client site signs the request body
	 * (ARCH-6), and a multipart body it cannot reproduce byte for byte is a
	 * body it cannot sign. One encoding for both interfaces keeps the studio
	 * and the client on the same route, which is what stops the two drifting
	 * into different rules about what may be attached.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function attach( WP_REST_Request $request ) {
		$step = self::their_step( $request );

		if ( ! is_array( $step ) ) {
			return $step;
		}

		$refused = Access::refuse_unless( Capabilities::ATTACH_EVIDENCE, self::client_of( (string) $step['client_site_id'] ) );

		if ( null !== $refused ) {
			return $refused;
		}

		return self::keep(
			$step,
			(array) $request->get_json_params(),
			get_current_user_id(),
			'',
			Capabilities::STUDIO
		);
	}

	/**
	 * Takes a file described in a request body and keeps it against a step.
	 *
	 * Shared by both interfaces on purpose: what may be attached is a property
	 * of the product, not of the screen somebody is looking at.
	 *
	 * @param array<string, mixed> $step   The step it belongs to.
	 * @param array<string, mixed> $body   filename, mime_type, contents (base64).
	 * @param int                  $actor  Who is attaching, or 0 for a client site.
	 * @param string               $site   The signing site, or '' for a person.
	 * @param string               $source Which interface.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function keep( array $step, array $body, int $actor, string $site, string $source ) {
		$filename = (string) ( $body['filename'] ?? '' );
		$mime     = (string) ( $body['mime_type'] ?? '' );
		$encoded  = (string) ( $body['contents'] ?? '' );

		/*
		 * Strict decoding, so a body that is not base64 at all is a refusal
		 * rather than a shorter file than the sender meant to send.
		 */
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- This is how the file crosses the connection; see the method comment for why it is not multipart.
		$contents = '' === $encoded ? false : base64_decode( $encoded, true );

		if ( ! is_string( $contents ) ) {
			return Errors::rest( 'upload_unreadable', __( 'That file could not be read. Try attaching it again.', 'blueworx-forge' ), 400 );
		}

		$refusal = EvidenceStore::refusal( $filename, $mime, strlen( $contents ) );

		if ( '' !== $refusal ) {
			return Errors::rest( 'upload_refused', $refusal, 400 );
		}

		$site_id     = (string) $step['client_site_id'];
		$directory   = EvidenceStore::absolute_dir( $site_id );
		$stored_name = EvidenceStore::stored_name( $filename );

		if ( ! EvidenceStore::prepare( $directory ) ) {
			return Errors::rest( 'upload_not_kept', __( 'That could not be saved. Nothing has been attached.', 'blueworx-forge' ), 500 );
		}

		$path = $directory . '/' . $stored_name;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Own directory, prepared above; WP_Filesystem is not initialised on a REST request.
		if ( false === file_put_contents( $path, $contents ) ) {
			return Errors::rest( 'upload_not_kept', __( 'That could not be saved. Nothing has been attached.', 'blueworx-forge' ), 500 );
		}

		/*
		 * Scanned after it is written, because a scanner is given a path. It is
		 * removed again if anything objects, so a refused file never becomes an
		 * attachment — and see EvidenceStore for why this is an addition rather
		 * than the defence.
		 */
		$scanned = EvidenceStore::scan_refusal(
			$path,
			array(
				'client_site_id' => $site_id,
				'filename'       => $filename,
			)
		);

		if ( '' !== $scanned ) {
			wp_delete_file( $path );

			return Errors::rest( 'upload_refused', $scanned, 400 );
		}

		$recorded = Evidence::record(
			array(
				'step_id'          => (string) $step['id'],
				'client_site_id'   => $site_id,
				'client_id'        => self::client_of( $site_id ),
				'original_name'    => $filename,
				'stored_name'      => $stored_name,
				'mime_type'        => $mime,
				'size_bytes'       => strlen( $contents ),
				'checksum'         => hash( 'sha256', $contents ),
				'uploaded_by'      => $actor,
				'uploaded_site'    => $site,
				'source_interface' => $source,
			)
		);

		if ( null === $recorded ) {
			// The row is what makes the file findable. Without one the file is
			// litter, so it does not stay.
			wp_delete_file( $path );

			return Errors::rest( 'upload_not_kept', __( 'That could not be saved. Nothing has been attached.', 'blueworx-forge' ), 500 );
		}

		return rest_ensure_response( array( 'evidence' => $recorded ) );
	}

	/**
	 * Hands back the file itself.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function download( WP_REST_Request $request ) {
		$id = (string) $request->get_param( 'evidence_id' );

		$found = self::reachable_evidence( $id );

		if ( ! is_array( $found ) ) {
			return $found;
		}

		$path = EvidenceStore::absolute_dir( (string) $found['client_site_id'] ) . '/' . (string) $found['stored_name'];

		if ( ! is_readable( $path ) ) {
			return Boundary::absent( 'onboarding_upload' );
		}

		/*
		 * `attachment` rather than `inline`, and a type taken from the row
		 * rather than from the file. Nothing here is rendered in the browser,
		 * so a file that lied about itself on the way in has nothing to gain by
		 * being opened.
		 */
		header( 'Content-Type: ' . (string) $found['mime_type'] );
		header( 'Content-Disposition: attachment; filename="' . rawurlencode( (string) $found['original_name'] ) . '"' );
		header( 'Content-Length: ' . (string) $found['size_bytes'] );
		header( 'X-Content-Type-Options: nosniff' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming a file is the whole purpose of this route.
		readfile( $path );

		exit;
	}

	/**
	 * The step this request names, if the caller may reach it.
	 *
	 * A step on a client this person has nothing to do with gets the same
	 * answer as a step id nobody has ever used (D-1, D-2).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function their_step( WP_REST_Request $request ) {
		$step = Steps::get( (string) $request->get_param( 'step_id' ) );

		if ( null === $step || ! self::within_reach( (string) $step['client_site_id'] ) ) {
			return Boundary::absent( 'onboarding_step' );
		}

		return $step;
	}

	/**
	 * The attachment this request names, if the caller may reach it.
	 *
	 * @param string $id The attachment.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function reachable_evidence( string $id ) {
		global $wpdb;

		$table = Schema::onboarding_evidence_table();

		/*
		 * The one read of this table that cannot name a site up front: the
		 * caller has an attachment id and nothing else. So the site is read off
		 * the row and then checked, rather than trusted — which is the same
		 * answer Evidence::get() gives its callers, arrived at from the other
		 * direction.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT client_site_id FROM {$table} WHERE id = %s", $id ), ARRAY_A );

		if ( ! is_array( $row ) || ! self::within_reach( (string) $row['client_site_id'] ) ) {
			return Boundary::absent( 'onboarding_upload' );
		}

		$found = Evidence::get( $id, (string) $row['client_site_id'] );

		return null === $found ? Boundary::absent( 'onboarding_upload' ) : $found;
	}

	/**
	 * Whether the person asking has any business with this site.
	 *
	 * @param string $client_site_id The site.
	 * @return bool
	 */
	private static function within_reach( string $client_site_id ): bool {
		$site = ClientSites::get( $client_site_id );

		if ( null === $site ) {
			return false;
		}

		return Reach::reaches_site( Boundary::current(), (string) $site['client_id'], $client_site_id );
	}

	/**
	 * Which client a site belongs to.
	 *
	 * @param string $client_site_id The site.
	 * @return string
	 */
	private static function client_of( string $client_site_id ): string {
		$site = ClientSites::get( $client_site_id );

		return null === $site ? '' : (string) $site['client_id'];
	}
}
