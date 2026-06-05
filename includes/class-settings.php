<?php
defined( 'ABSPATH' ) || exit;

class Forge_PM_Settings {

	const OPTION_KEY = 'forge_pm_settings';

	public static function defaults() {
		return [
			'parentBrand'      => '',
			'teamMonthlyHours' => 160,
			'brands'           => [ 'SwingU', '18Birdies', 'TheGrint', 'Hole19', 'Golf Pad' ],
			'categories'       => [
				'GPS & Shot Tracking',
				'Training & Coaching',
				'Scoring & Stats',
				'Games & Leaderboards',
				'Gamification',
				'Handicap Options',
				'Premium Perks',
				'External Hardware',
				'App Tools',
			],
		];
	}

	public static function get() {
		return wp_parse_args( get_option( self::OPTION_KEY, [] ), self::defaults() );
	}

	// ── REST routes ─────────────────────────────────────────────
	public static function register_routes() {
		// Settings CRUD
		register_rest_route( 'forge/v1', '/settings', [
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'api_get' ],
				'permission_callback' => '__return_true',
			],
			[
				'methods'             => 'PUT',
				'callback'            => [ __CLASS__, 'api_put' ],
				'permission_callback' => [ __CLASS__, 'is_admin' ],
			],
		] );

		// Archive an item
		register_rest_route( 'forge/v1', '/items/(?P<type>[a-z_]+)/(?P<id>\d+)/archive', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'api_archive' ],
			'permission_callback' => [ __CLASS__, 'is_admin' ],
			'args'                => [
				'type' => [ 'sanitize_callback' => 'sanitize_key' ],
				'id'   => [ 'sanitize_callback' => 'absint' ],
			],
		] );

		// Restore an archived item
		register_rest_route( 'forge/v1', '/items/(?P<type>[a-z_]+)/(?P<id>\d+)/restore', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'api_restore' ],
			'permission_callback' => [ __CLASS__, 'is_admin' ],
			'args'                => [
				'type' => [ 'sanitize_callback' => 'sanitize_key' ],
				'id'   => [ 'sanitize_callback' => 'absint' ],
			],
		] );

		// Get all archived items
		register_rest_route( 'forge/v1', '/archive', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'api_get_archived' ],
			'permission_callback' => [ __CLASS__, 'is_admin' ],
		] );
	}

	public static function is_admin() {
		return current_user_can( 'manage_options' );
	}

	public static function api_get() {
		return rest_ensure_response( self::get() );
	}

	public static function api_put( $request ) {
		$data = $request->get_json_params();
		if ( ! $data ) {
			return new WP_Error( 'invalid_data', 'No JSON body.', [ 'status' => 400 ] );
		}

		$saved   = self::get();
		$allowed = [ 'parentBrand', 'teamMonthlyHours', 'brands', 'categories' ];

		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $data ) ) continue;
			if ( $key === 'teamMonthlyHours' ) {
				$saved[ $key ] = absint( $data[ $key ] );
			} elseif ( is_array( $data[ $key ] ) ) {
				$saved[ $key ] = array_values( array_filter( array_map( 'sanitize_text_field', $data[ $key ] ) ) );
			} else {
				$saved[ $key ] = sanitize_text_field( $data[ $key ] );
			}
		}

		update_option( self::OPTION_KEY, $saved );
		return rest_ensure_response( $saved );
	}

	public static function api_archive( $request ) {
		$post_id = absint( $request->get_param( 'id' ) );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Item not found.', [ 'status' => 404 ] );
		}

		// Remember the original status so we can restore it later
		update_post_meta( $post_id, '_forge_pre_archive_status', $post->post_status );

		wp_update_post( [ 'ID' => $post_id, 'post_status' => 'forge_archived' ] );

		return rest_ensure_response( [ 'success' => true, 'id' => $post_id ] );
	}

	public static function api_restore( $request ) {
		$post_id         = absint( $request->get_param( 'id' ) );
		$original_status = get_post_meta( $post_id, '_forge_pre_archive_status', true ) ?: 'publish';

		wp_update_post( [ 'ID' => $post_id, 'post_status' => $original_status ] );
		delete_post_meta( $post_id, '_forge_pre_archive_status' );

		return rest_ensure_response( [ 'success' => true, 'id' => $post_id ] );
	}

	public static function api_get_archived() {
		$type_map = [
			'forge_feature'      => 'feature',
			'forge_bug'          => 'bug',
			'forge_feedback'     => 'feedback',
			'forge_release'      => 'release',
			'forge_company_date' => 'company_date',
		];

		$items = [];
		foreach ( $type_map as $cpt => $label ) {
			$posts = get_posts( [
				'post_type'   => $cpt,
				'post_status' => 'forge_archived',
				'numberposts' => -1,
				'orderby'     => 'modified',
				'order'       => 'DESC',
			] );
			foreach ( $posts as $post ) {
				$items[] = [
					'id'         => (string) $post->ID,
					'itemType'   => $label,
					'name'       => $post->post_title,
					'archivedAt' => get_the_modified_date( 'Y-m-d', $post ),
				];
			}
		}

		return rest_ensure_response( $items );
	}

	// ── Post status registration ─────────────────────────────────
	public static function register_post_status() {
		register_post_status( 'forge_archived', [
			'label'                     => _x( 'Archived', 'post status', 'forge-pm' ),
			'public'                    => false,
			'show_in_admin_all_list'    => false,
			'show_in_admin_status_list' => true,
			'label_count'               => _n_noop(
				'Archived <span class="count">(%s)</span>',
				'Archived <span class="count">(%s)</span>',
				'forge-pm'
			),
		] );
	}
}
