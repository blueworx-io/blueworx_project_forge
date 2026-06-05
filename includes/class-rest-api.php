<?php
defined( 'ABSPATH' ) || exit;

class Forge_PM_REST_API {

	const NS = 'forge/v1';

	public static function register_routes() {
		// GET all items (public)
		register_rest_route( self::NS, '/items', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'get_all_items' ],
			'permission_callback' => '__return_true',
		] );

		// PUT update any item (admin only)
		register_rest_route( self::NS, '/items/(?P<type>[a-z_]+)/(?P<id>\d+)', [
			'methods'             => 'PUT',
			'callback'            => [ __CLASS__, 'update_item' ],
			'permission_callback' => [ __CLASS__, 'is_admin' ],
			'args'                => [
				'type' => [ 'sanitize_callback' => 'sanitize_key' ],
				'id'   => [ 'sanitize_callback' => 'absint' ],
			],
		] );

		// PATCH workflow stage only (admin only, lightweight for Kanban DnD)
		register_rest_route( self::NS, '/items/(?P<type>[a-z_]+)/(?P<id>\d+)/stage', [
			'methods'             => 'PATCH',
			'callback'            => [ __CLASS__, 'update_stage' ],
			'permission_callback' => [ __CLASS__, 'is_admin' ],
			'args'                => [
				'type' => [ 'sanitize_callback' => 'sanitize_key' ],
				'id'   => [ 'sanitize_callback' => 'absint' ],
			],
		] );

		// POST new company date (admin only)
		register_rest_route( self::NS, '/company-dates', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'create_company_date' ],
			'permission_callback' => [ __CLASS__, 'is_admin' ],
		] );
	}

	public static function is_admin() {
		return current_user_can( 'manage_options' );
	}

	// -----------------------------------------------------------------------
	// GET /forge/v1/items
	// -----------------------------------------------------------------------

	public static function get_all_items( $request ) {
		return rest_ensure_response( [
			'features'     => self::get_features(),
			'subitems'     => self::get_subitems(),
			'bugs'         => self::get_bugs(),
			'feedback'     => self::get_feedback(),
			'releases'     => self::get_releases(),
			'companyDates' => self::get_company_dates(),
		] );
	}

	private static function base_query( $post_type ) {
		return get_posts( [
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'numberposts'    => -1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		] );
	}

	private static function meta( $id, $key, $default = '' ) {
		$v = get_post_meta( $id, $key, true );
		return ( $v !== '' && $v !== false ) ? $v : $default;
	}

	private static function meta_int( $id, $key, $default = 0 ) {
		return (int) self::meta( $id, $key, $default );
	}

	private static function meta_bool( $id, $key ) {
		return self::meta( $id, $key, '0' ) === '1';
	}

	private static function meta_array( $id, $key ) {
		$v = get_post_meta( $id, $key, true );
		return is_array( $v ) ? $v : [];
	}

	private static function get_features() {
		$items = [];
		foreach ( self::base_query( 'forge_feature' ) as $post ) {
			$items[] = [
				'id'               => (string) $post->ID,
				'type'             => 'feature',
				'name'             => $post->post_title,
				'description'      => $post->post_content,
				'workflowStage'    => self::meta( $post->ID, '_forge_workflow_stage', 'scoping' ),
				'category'         => self::meta( $post->ID, '_forge_category', '' ),
				'featurePrice'     => self::meta( $post->ID, '_forge_feature_price', 'free' ),
				'timeEstimate'     => self::meta_int( $post->ID, '_forge_time_estimate' ),
				'releaseId'        => self::meta( $post->ID, '_forge_release_id' ) ?: null,
				'subItemIds'       => self::meta_array( $post->ID, '_forge_subitem_ids' ),
				'isEnabled'        => self::meta_bool( $post->ID, '_forge_is_enabled' ),
				'isTrackedAsStat'  => self::meta_bool( $post->ID, '_forge_is_tracked_as_stat' ),
				'createdDate'      => get_the_date( 'Y-m-d', $post ),
				'images'           => self::meta_array( $post->ID, '_forge_image_urls' ),
			];
		}
		return $items;
	}

	private static function get_subitems() {
		$items = [];
		foreach ( self::base_query( 'forge_subitem' ) as $post ) {
			$items[] = [
				'id'              => (string) $post->ID,
				'type'            => 'subitem',
				'name'            => $post->post_title,
				'description'     => $post->post_content,
				'parentFeatureId' => self::meta( $post->ID, '_forge_parent_feature_id', '' ),
				'workflowStage'   => self::meta( $post->ID, '_forge_workflow_stage', 'scoping' ),
				'category'        => self::meta( $post->ID, '_forge_category', '' ),
				'featurePrice'    => self::meta( $post->ID, '_forge_feature_price', 'free' ),
				'timeEstimate'    => self::meta_int( $post->ID, '_forge_time_estimate' ),
				'releaseId'       => self::meta( $post->ID, '_forge_release_id' ) ?: null,
			];
		}
		return $items;
	}

	private static function get_bugs() {
		$items = [];
		foreach ( self::base_query( 'forge_bug' ) as $post ) {
			$items[] = [
				'id'              => (string) $post->ID,
				'type'            => 'bug',
				'title'           => $post->post_title,
				'description'     => $post->post_content,
				'linkedFeatureId' => self::meta( $post->ID, '_forge_linked_feature_id' ) ?: null,
				'releaseId'       => self::meta( $post->ID, '_forge_release_id' ) ?: null,
				'bugStatus'       => self::meta( $post->ID, '_forge_bug_status', 'open' ),
				'workflowStage'   => self::meta( $post->ID, '_forge_workflow_stage', 'bug-tracking' ),
				'priority'        => self::meta( $post->ID, '_forge_priority', 'medium' ),
				'timeEstimate'    => self::meta_int( $post->ID, '_forge_time_estimate' ),
				'reportedDate'    => self::meta( $post->ID, '_forge_reported_date', get_the_date( 'Y-m-d', $post ) ),
				'notes'           => self::meta( $post->ID, '_forge_notes' ) ?: null,
				'images'          => self::meta_array( $post->ID, '_forge_image_urls' ),
				'urls'            => self::meta_array( $post->ID, '_forge_urls' ),
			];
		}
		return $items;
	}

	private static function get_feedback() {
		$items = [];
		foreach ( self::base_query( 'forge_feedback' ) as $post ) {
			$items[] = [
				'id'              => (string) $post->ID,
				'type'            => 'feedback',
				'title'           => $post->post_title,
				'description'     => $post->post_content,
				'linkedFeatureId' => self::meta( $post->ID, '_forge_linked_feature_id' ) ?: null,
				'linkedBugId'     => self::meta( $post->ID, '_forge_linked_bug_id' ) ?: null,
				'releaseId'       => self::meta( $post->ID, '_forge_release_id' ) ?: null,
				'status'          => self::meta( $post->ID, '_forge_status', 'open' ),
				'workflowStage'   => self::meta( $post->ID, '_forge_workflow_stage', 'scoping' ),
				'priority'        => self::meta( $post->ID, '_forge_priority', 'medium' ),
				'timeEstimate'    => self::meta_int( $post->ID, '_forge_time_estimate' ),
				'reportedDate'    => self::meta( $post->ID, '_forge_reported_date', get_the_date( 'Y-m-d', $post ) ),
				'notes'           => self::meta( $post->ID, '_forge_notes' ) ?: null,
				'images'          => self::meta_array( $post->ID, '_forge_image_urls' ),
				'urls'            => self::meta_array( $post->ID, '_forge_urls' ),
			];
		}
		return $items;
	}

	private static function get_releases() {
		$items = [];
		foreach ( self::base_query( 'forge_release' ) as $post ) {
			$items[] = [
				'id'                   => (string) $post->ID,
				'type'                 => 'release',
				'name'                 => $post->post_title,
				'quarter'              => self::meta( $post->ID, '_forge_quarter', '' ),
				'startWeek'            => self::meta( $post->ID, '_forge_start_week', '' ),
				'endWeek'              => self::meta( $post->ID, '_forge_end_week', '' ),
				'status'               => self::meta( $post->ID, '_forge_status', 'planned' ),
				'totalTimeEstimate'    => self::meta_int( $post->ID, '_forge_total_time_estimate' ),
				'capacity'             => self::meta_int( $post->ID, '_forge_capacity' ),
				'isBigWedgeCampaign'   => self::meta_bool( $post->ID, '_forge_is_big_wedge_campaign' ),
				'linkedFeatureIds'     => self::meta_array( $post->ID, '_forge_linked_feature_ids' ),
				'linkedBugIds'         => self::meta_array( $post->ID, '_forge_linked_bug_ids' ),
				'linkedFeedbackIds'    => self::meta_array( $post->ID, '_forge_linked_feedback_ids' ),
			];
		}
		return $items;
	}

	private static function get_company_dates() {
		$items = [];
		foreach ( self::base_query( 'forge_company_date' ) as $post ) {
			$items[] = [
				'id'          => (string) $post->ID,
				'title'       => $post->post_title,
				'date'        => self::meta( $post->ID, '_forge_date', '' ),
				'description' => self::meta( $post->ID, '_forge_description' ) ?: null,
				'tracked'     => self::meta_bool( $post->ID, '_forge_tracked' ),
			];
		}
		return $items;
	}

	// -----------------------------------------------------------------------
	// PUT /forge/v1/items/{type}/{id}
	// -----------------------------------------------------------------------

	public static function update_item( $request ) {
		$post_id = absint( $request->get_param( 'id' ) );
		$data    = $request->get_json_params();

		if ( ! $data || ! is_array( $data ) ) {
			return new WP_Error( 'invalid_data', 'No JSON body supplied.', [ 'status' => 400 ] );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Item not found.', [ 'status' => 404 ] );
		}

		// Update title / content
		$update = [ 'ID' => $post_id ];
		if ( isset( $data['name'] ) )        $update['post_title']   = sanitize_text_field( $data['name'] );
		if ( isset( $data['title'] ) )       $update['post_title']   = sanitize_text_field( $data['title'] );
		if ( isset( $data['description'] ) ) $update['post_content'] = sanitize_textarea_field( $data['description'] );
		if ( count( $update ) > 1 ) wp_update_post( $update );

		// Update scalar meta
		$scalar_meta = [
			'workflowStage'     => '_forge_workflow_stage',
			'category'          => '_forge_category',
			'featurePrice'      => '_forge_feature_price',
			'timeEstimate'      => '_forge_time_estimate',
			'releaseId'         => '_forge_release_id',
			'parentFeatureId'   => '_forge_parent_feature_id',
			'linkedFeatureId'   => '_forge_linked_feature_id',
			'linkedBugId'       => '_forge_linked_bug_id',
			'bugStatus'         => '_forge_bug_status',
			'status'            => '_forge_status',
			'priority'          => '_forge_priority',
			'reportedDate'      => '_forge_reported_date',
			'notes'             => '_forge_notes',
			'quarter'           => '_forge_quarter',
			'startWeek'         => '_forge_start_week',
			'endWeek'           => '_forge_end_week',
			'totalTimeEstimate' => '_forge_total_time_estimate',
			'capacity'          => '_forge_capacity',
			'date'              => '_forge_date',
		];

		foreach ( $scalar_meta as $js_key => $meta_key ) {
			if ( array_key_exists( $js_key, $data ) ) {
				$v = $data[ $js_key ];
				update_post_meta( $post_id, $meta_key, is_string( $v ) ? sanitize_text_field( $v ) : $v );
			}
		}

		// Booleans
		$bool_meta = [
			'isEnabled'          => '_forge_is_enabled',
			'isTrackedAsStat'    => '_forge_is_tracked_as_stat',
			'isBigWedgeCampaign' => '_forge_is_big_wedge_campaign',
			'tracked'            => '_forge_tracked',
		];
		foreach ( $bool_meta as $js_key => $meta_key ) {
			if ( array_key_exists( $js_key, $data ) ) {
				update_post_meta( $post_id, $meta_key, $data[ $js_key ] ? '1' : '0' );
			}
		}

		// Arrays
		$array_meta = [
			'subItemIds'          => '_forge_subitem_ids',
			'linkedFeatureIds'    => '_forge_linked_feature_ids',
			'linkedBugIds'        => '_forge_linked_bug_ids',
			'linkedFeedbackIds'   => '_forge_linked_feedback_ids',
			'urls'                => '_forge_urls',
		];
		foreach ( $array_meta as $js_key => $meta_key ) {
			if ( array_key_exists( $js_key, $data ) && is_array( $data[ $js_key ] ) ) {
				update_post_meta( $post_id, $meta_key, array_map( 'sanitize_text_field', $data[ $js_key ] ) );
			}
		}

		return rest_ensure_response( [ 'success' => true, 'id' => $post_id ] );
	}

	// -----------------------------------------------------------------------
	// PATCH /forge/v1/items/{type}/{id}/stage
	// -----------------------------------------------------------------------

	public static function update_stage( $request ) {
		$post_id = absint( $request->get_param( 'id' ) );
		$data    = $request->get_json_params();

		if ( empty( $data['workflowStage'] ) ) {
			return new WP_Error( 'missing_stage', 'workflowStage is required.', [ 'status' => 400 ] );
		}

		$stage = sanitize_text_field( $data['workflowStage'] );
		$valid = [ 'bug-tracking', 'scoping', 'future-idea', 'up-next', 'in-development', 'staging-features', 'active-features' ];

		if ( ! in_array( $stage, $valid, true ) ) {
			return new WP_Error( 'invalid_stage', 'Invalid workflow stage.', [ 'status' => 400 ] );
		}

		update_post_meta( $post_id, '_forge_workflow_stage', $stage );

		return rest_ensure_response( [ 'success' => true, 'id' => $post_id, 'workflowStage' => $stage ] );
	}

	// -----------------------------------------------------------------------
	// POST /forge/v1/company-dates
	// -----------------------------------------------------------------------

	public static function create_company_date( $request ) {
		$data = $request->get_json_params();

		if ( empty( $data['title'] ) || empty( $data['date'] ) ) {
			return new WP_Error( 'missing_fields', 'title and date are required.', [ 'status' => 400 ] );
		}

		$post_id = wp_insert_post( [
			'post_type'   => 'forge_company_date',
			'post_title'  => sanitize_text_field( $data['title'] ),
			'post_status' => 'publish',
		] );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, '_forge_date',        sanitize_text_field( $data['date'] ) );
		update_post_meta( $post_id, '_forge_description', sanitize_textarea_field( $data['description'] ?? '' ) );
		update_post_meta( $post_id, '_forge_tracked',     ! empty( $data['tracked'] ) ? '1' : '0' );

		return rest_ensure_response( [
			'id'          => (string) $post_id,
			'title'       => $data['title'],
			'date'        => $data['date'],
			'description' => $data['description'] ?? null,
			'tracked'     => ! empty( $data['tracked'] ),
		] );
	}
}
