<?php
defined( 'ABSPATH' ) || exit;

class Forge_PM_Connectors {

	const NS         = 'forge/v1';
	const OPTION_KEY = 'forge_pm_connections';
	const LOG_KEY    = 'forge_pm_connection_log';
	const LOG_MAX    = 50;

	const ITEM_TYPES = [ 'feature', 'subitem', 'bug', 'feedback', 'release' ];

	// ── Permissions ─────────────────────────────────────────────────────────

	/** Connections hold API credentials — admins only, not Forge Managers. */
	public static function is_admin(): bool {
		return current_user_can( 'manage_options' );
	}

	// ── Storage ─────────────────────────────────────────────────────────────

	/** Full records including authToken. Never send these to a client. */
	public static function get_all(): array {
		$rows = get_option( self::OPTION_KEY, [] );
		return is_array( $rows ) ? $rows : [];
	}

	public static function get( string $id ): ?array {
		foreach ( self::get_all() as $row ) {
			if ( ( $row['id'] ?? '' ) === $id ) return $row;
		}
		return null;
	}

	private static function save_all( array $rows ): void {
		update_option( self::OPTION_KEY, array_values( $rows ) );
	}

	/** Strip the token and add a display hint. Every response passes through this. */
	private static function to_public( array $row ): array {
		$token = $row['authToken'] ?? '';
		unset( $row['authToken'] );
		if ( $token !== '' ) {
			$row['authTokenHint'] = '••••' . substr( $token, -4 );
		}
		return $row;
	}

	// ── Validation ──────────────────────────────────────────────────────────

	/** Returns a WP_Error on invalid input, or null when valid. */
	private static function validate( array $data, bool $is_create ): ?WP_Error {
		if ( $is_create || isset( $data['name'] ) ) {
			if ( trim( (string) ( $data['name'] ?? '' ) ) === '' ) {
				return new WP_Error( 'invalid_name', 'Name is required.', [ 'status' => 400 ] );
			}
		}
		if ( $is_create || isset( $data['url'] ) ) {
			$url = (string) ( $data['url'] ?? '' );
			if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
				return new WP_Error( 'invalid_url', 'A valid URL is required.', [ 'status' => 400 ] );
			}
			if ( stripos( $url, 'https://' ) !== 0 ) {
				return new WP_Error( 'insecure_url', 'Connection URLs must use https://.', [ 'status' => 400 ] );
			}
		}
		if ( isset( $data['itemTypes'] ) ) {
			if ( ! is_array( $data['itemTypes'] ) ) {
				return new WP_Error( 'invalid_item_types', 'itemTypes must be an array.', [ 'status' => 400 ] );
			}
			foreach ( $data['itemTypes'] as $t ) {
				if ( ! in_array( $t, self::ITEM_TYPES, true ) ) {
					return new WP_Error( 'invalid_item_types', 'Unknown item type: ' . $t, [ 'status' => 400 ] );
				}
			}
		}
		return null;
	}

	// ── REST routes ─────────────────────────────────────────────────────────

	public static function register_routes() {
		register_rest_route( self::NS, '/connections', [
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'api_list' ],
				'permission_callback' => [ __CLASS__, 'is_admin' ],
			],
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'api_create' ],
				'permission_callback' => [ __CLASS__, 'is_admin' ],
			],
		] );

		register_rest_route( self::NS, '/connections/(?P<id>[a-z0-9\-]+)', [
			[
				'methods'             => 'PUT',
				'callback'            => [ __CLASS__, 'api_update' ],
				'permission_callback' => [ __CLASS__, 'is_admin' ],
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ __CLASS__, 'api_delete' ],
				'permission_callback' => [ __CLASS__, 'is_admin' ],
			],
		] );
	}

	public static function api_list() {
		return rest_ensure_response( array_map( [ __CLASS__, 'to_public' ], self::get_all() ) );
	}

	public static function api_create( WP_REST_Request $request ) {
		$data = $request->get_json_params() ?: [];

		$error = self::validate( $data, true );
		if ( $error ) return $error;

		$row = [
			'id'        => wp_generate_uuid4(),
			'name'      => sanitize_text_field( $data['name'] ),
			'url'       => esc_url_raw( $data['url'] ),
			'authToken' => (string) ( $data['authToken'] ?? '' ),
			'itemTypes' => array_values( array_intersect( self::ITEM_TYPES, $data['itemTypes'] ?? [] ) ),
			'enabled'   => ! empty( $data['enabled'] ),
			'createdAt' => current_time( 'c', true ),
		];

		$rows   = self::get_all();
		$rows[] = $row;
		self::save_all( $rows );

		return rest_ensure_response( self::to_public( $row ) );
	}

	public static function api_update( WP_REST_Request $request ) {
		$id   = $request->get_param( 'id' );
		$data = $request->get_json_params() ?: [];

		$error = self::validate( $data, false );
		if ( $error ) return $error;

		$rows  = self::get_all();
		$found = false;

		foreach ( $rows as &$row ) {
			if ( ( $row['id'] ?? '' ) !== $id ) continue;
			$found = true;

			if ( isset( $data['name'] ) )      $row['name']      = sanitize_text_field( $data['name'] );
			if ( isset( $data['url'] ) )       $row['url']       = esc_url_raw( $data['url'] );
			if ( isset( $data['itemTypes'] ) ) $row['itemTypes'] = array_values( array_intersect( self::ITEM_TYPES, $data['itemTypes'] ) );
			if ( isset( $data['enabled'] ) )   $row['enabled']   = (bool) $data['enabled'];

			// A blank/absent authToken means "leave unchanged" — never wipe a
			// stored token just because the UI sent an empty masked field.
			if ( ! empty( $data['authToken'] ) ) {
				$row['authToken'] = (string) $data['authToken'];
			}
			break;
		}
		unset( $row );

		if ( ! $found ) {
			return new WP_Error( 'not_found', 'Connection not found.', [ 'status' => 404 ] );
		}

		self::save_all( $rows );
		return rest_ensure_response( self::to_public( self::get( $id ) ) );
	}

	public static function api_delete( WP_REST_Request $request ) {
		$id   = $request->get_param( 'id' );
		$rows = self::get_all();
		$next = array_filter( $rows, fn( $r ) => ( $r['id'] ?? '' ) !== $id );

		if ( count( $next ) === count( $rows ) ) {
			return new WP_Error( 'not_found', 'Connection not found.', [ 'status' => 404 ] );
		}

		self::save_all( $next );
		return rest_ensure_response( [ 'success' => true ] );
	}
}
