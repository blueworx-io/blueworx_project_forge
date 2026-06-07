<?php
defined( 'ABSPATH' ) || exit;

class Forge_PM_Settings {

	const OPTION_KEY = 'forge_pm_settings';

	public static function defaults() {
		return [
			'projectName'      => '',
			'parentBrand'      => '',
			'teamMonthlyHours' => 160,
			'brands'           => [
				[ 'name' => 'SwingU',    'logo' => '' ],
				[ 'name' => '18Birdies', 'logo' => '' ],
				[ 'name' => 'TheGrint',  'logo' => '' ],
				[ 'name' => 'Hole19',    'logo' => '' ],
				[ 'name' => 'Golf Pad',  'logo' => '' ],
			],
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
			'statuses'         => [
				[ 'id' => 'bug-tracking',         'label' => 'Bug Tracking' ],
				[ 'id' => 'future-idea',           'label' => 'Future Idea' ],
				[ 'id' => 'triage',                'label' => 'Triage' ],
				[ 'id' => 'documentation-period',  'label' => 'Documentation Period' ],
				[ 'id' => 'technical-audit',        'label' => 'Technical Audit' ],
				[ 'id' => 'design-period',          'label' => 'Design Period' ],
				[ 'id' => 'up-next',               'label' => 'Up Next (Assign Hours)' ],
				[ 'id' => 'in-development',        'label' => 'In Development' ],
				[ 'id' => 'in-review',             'label' => 'In Review' ],
				[ 'id' => 'deployed',              'label' => 'Deployed' ],
			],
		];
	}

	public static function get() {
		$settings = wp_parse_args( get_option( self::OPTION_KEY, [] ), self::defaults() );

		// Backward compat: old sites stored brands as plain strings, normalize to {name, logo}
		if ( ! empty( $settings['brands'] ) && is_array( $settings['brands'] ) ) {
			$settings['brands'] = array_values( array_map( function ( $b ) {
				return is_string( $b ) ? [ 'name' => $b, 'logo' => '' ] : $b;
			}, $settings['brands'] ) );
		}

		return $settings;
	}

	// ── REST routes ─────────────────────────────────────────────
	public static function register_routes() {
		// Settings CRUD — manage_options only
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

		// Archive an item — edit_posts capability
		register_rest_route( 'forge/v1', '/items/(?P<type>[a-z_]+)/(?P<id>\d+)/archive', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'api_archive' ],
			'permission_callback' => [ __CLASS__, 'can_edit_items' ],
			'args'                => [
				'type' => [ 'sanitize_callback' => 'sanitize_key' ],
				'id'   => [ 'sanitize_callback' => 'absint' ],
			],
		] );

		// Restore an archived item — edit_posts capability
		register_rest_route( 'forge/v1', '/items/(?P<type>[a-z_]+)/(?P<id>\d+)/restore', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'api_restore' ],
			'permission_callback' => [ __CLASS__, 'can_edit_items' ],
			'args'                => [
				'type' => [ 'sanitize_callback' => 'sanitize_key' ],
				'id'   => [ 'sanitize_callback' => 'absint' ],
			],
		] );

		// Get all archived items — manage_options only
		register_rest_route( 'forge/v1', '/archive', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'api_get_archived' ],
			'permission_callback' => [ __CLASS__, 'is_admin' ],
		] );
	}

	public static function is_admin(): bool {
		return current_user_can( 'manage_options' );
	}

	public static function can_edit_items(): bool {
		return current_user_can( 'edit_posts' );
	}

	public static function api_get() {
		return rest_ensure_response( self::get() );
	}

	public static function api_put( WP_REST_Request $request ) {
		$data = $request->get_json_params();
		if ( ! $data ) {
			return new WP_Error( 'invalid_data', 'No JSON body.', [ 'status' => 400 ] );
		}

		$saved   = self::get();
		$allowed = [ 'projectName', 'parentBrand', 'teamMonthlyHours', 'brands', 'categories', 'statuses' ];

		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $data ) ) continue;

			if ( $key === 'teamMonthlyHours' ) {
				$saved[ $key ] = absint( $data[ $key ] );

			} elseif ( $key === 'statuses' && is_array( $data[ $key ] ) ) {
				$saved[ $key ] = array_values( array_filter(
					array_map( function( $s ) {
						if ( empty( $s['id'] ) || empty( $s['label'] ) ) return null;
						return [
							'id'    => sanitize_key( $s['id'] ),
							'label' => sanitize_text_field( $s['label'] ),
						];
					}, $data[ $key ] )
				) );

			} elseif ( $key === 'brands' && is_array( $data[ $key ] ) ) {
				$saved[ $key ] = array_values( array_filter(
					array_map( function( $b ) {
						// backward compat: accept plain strings
						if ( is_string( $b ) ) return [ 'name' => sanitize_text_field( $b ), 'logo' => '' ];
						if ( empty( $b['name'] ) ) return null;
						return [
							'name' => sanitize_text_field( $b['name'] ),
							'logo' => esc_url_raw( $b['logo'] ?? '' ),
						];
					}, $data[ $key ] )
				) );

			} elseif ( is_array( $data[ $key ] ) ) {
				$saved[ $key ] = array_values( array_filter( array_map( 'sanitize_text_field', $data[ $key ] ) ) );

			} else {
				$saved[ $key ] = sanitize_text_field( $data[ $key ] );
			}
		}

		update_option( self::OPTION_KEY, $saved );
		Forge_PM_REST_API::bust_cache();
		return rest_ensure_response( $saved );
	}

	public static function api_archive( WP_REST_Request $request ) {
		$post_id = absint( $request->get_param( 'id' ) );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Item not found.', [ 'status' => 404 ] );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'forbidden', 'Permission denied.', [ 'status' => 403 ] );
		}

		update_post_meta( $post_id, '_forge_pre_archive_status', $post->post_status );
		wp_update_post( [ 'ID' => $post_id, 'post_status' => 'forge_archived' ] );

		Forge_PM_REST_API::bust_cache();
		return rest_ensure_response( [ 'success' => true, 'id' => $post_id ] );
	}

	public static function api_restore( WP_REST_Request $request ) {
		$post_id         = absint( $request->get_param( 'id' ) );
		$post            = get_post( $post_id );
		$original_status = get_post_meta( $post_id, '_forge_pre_archive_status', true ) ?: 'publish';

		if ( $post && ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'forbidden', 'Permission denied.', [ 'status' => 403 ] );
		}

		wp_update_post( [ 'ID' => $post_id, 'post_status' => $original_status ] );
		delete_post_meta( $post_id, '_forge_pre_archive_status' );

		Forge_PM_REST_API::bust_cache();
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
