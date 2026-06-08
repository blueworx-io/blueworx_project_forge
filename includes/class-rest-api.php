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

		// POST new item by type
		register_rest_route( self::NS, '/items/(?P<type>[a-z_]+)', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'create_item' ],
			'permission_callback' => [ __CLASS__, 'can_edit_items' ],
			'args'                => [
				'type' => [ 'sanitize_callback' => 'sanitize_key' ],
			],
		] );

		// GET single item / PUT update / DELETE
		register_rest_route( self::NS, '/items/(?P<type>[a-z_]+)/(?P<id>\d+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_item' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'type' => [ 'sanitize_callback' => 'sanitize_key' ],
					'id'   => [ 'sanitize_callback' => 'absint' ],
				],
			],
			[
				'methods'             => 'PUT',
				'callback'            => [ __CLASS__, 'update_item' ],
				'permission_callback' => [ __CLASS__, 'can_edit_items' ],
				'args'                => [
					'type' => [ 'sanitize_callback' => 'sanitize_key' ],
					'id'   => [ 'sanitize_callback' => 'absint' ],
				],
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ __CLASS__, 'delete_item' ],
				'permission_callback' => [ __CLASS__, 'can_edit_items' ],
				'args'                => [
					'type' => [ 'sanitize_callback' => 'sanitize_key' ],
					'id'   => [ 'sanitize_callback' => 'absint' ],
				],
			],
		] );

		// PATCH workflow stage only (lightweight for Kanban DnD)
		register_rest_route( self::NS, '/items/(?P<type>[a-z_]+)/(?P<id>\d+)/stage', [
			'methods'             => 'PATCH',
			'callback'            => [ __CLASS__, 'update_stage' ],
			'permission_callback' => [ __CLASS__, 'can_edit_items' ],
			'args'                => [
				'type' => [ 'sanitize_callback' => 'sanitize_key' ],
				'id'   => [ 'sanitize_callback' => 'absint' ],
			],
		] );

		// POST new company date
		register_rest_route( self::NS, '/company-dates', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'create_company_date' ],
			'permission_callback' => [ __CLASS__, 'can_edit_items' ],
		] );
	}

	public static function is_admin(): bool {
		return current_user_can( 'manage_options' );
	}

	public static function can_edit_items(): bool {
		return current_user_can( 'edit_posts' );
	}

	// -----------------------------------------------------------------------
	// GET /forge/v1/items  (list)
	// GET /forge/v1/items/{type}/{id}  (single)
	// -----------------------------------------------------------------------

	public static function get_all_items( $request ) {
		$auth = current_user_can( 'read' );
		$key  = $auth ? 'forge_pm_items_auth' : 'forge_pm_items_pub';

		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return rest_ensure_response( $cached );
		}

		$payload = [
			'features'     => self::get_features(),
			'subitems'     => self::get_subitems(),
			'bugs'         => self::get_bugs(),
			'feedback'     => self::get_feedback(),
			'releases'     => self::get_releases(),
			'companyDates' => self::get_company_dates(),
		];

		if ( ! $auth ) {
			$payload = self::strip_sensitive( $payload );
		}

		set_transient( $key, $payload, HOUR_IN_SECONDS );
		return rest_ensure_response( $payload );
	}

	public static function get_item( $request ) {
		$post_id = absint( $request->get_param( 'id' ) );
		$item    = self::read_single_item( $post_id );
		if ( ! $item ) {
			return new WP_Error( 'not_found', 'Item not found.', [ 'status' => 404 ] );
		}
		return rest_ensure_response( $item );
	}

	public static function bust_cache(): void {
		delete_transient( 'forge_pm_items_auth' );
		delete_transient( 'forge_pm_items_pub' );
	}

	private static function strip_sensitive( array $payload ): array {
		$private = [ 'changeLog', 'notes', 'urls', 'description' ];
		foreach ( $payload as $type => &$items ) {
			if ( ! is_array( $items ) ) continue;
			$items = array_map(
				fn( $item ) => array_diff_key( $item, array_flip( $private ) ),
				$items
			);
		}
		return $payload;
	}

	private static function base_query( string $post_type, array $extra = [] ): array {
		return get_posts( array_merge( [
			'post_type'              => $post_type,
			'post_status'            => 'publish',
			'numberposts'            => -1,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,   // skip SQL_CALC_FOUND_ROWS (no pagination needed)
			'update_post_term_cache' => false,  // no taxonomies used; skip term cache warming
			'update_post_meta_cache' => true,   // keep: many meta reads per post
		], $extra ) );
	}

	private static function append_changelog( $post_id, $entry ) {
		$existing = get_post_meta( $post_id, '_forge_change_log', true ) ?: '';
		$new_log  = $existing ? "{$entry} | {$existing}" : $entry;
		update_post_meta( $post_id, '_forge_change_log', $new_log );
	}

	private static function current_user_name() {
		$user = wp_get_current_user();
		return $user->display_name ?: $user->user_login ?: 'Unknown';
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

	/**
	 * Read labeled links from _forge_links (JSON). If empty, migrate legacy
	 * scalar _forge_urls entries into { label: url, url } shape.
	 */
	private static function meta_links( int $post_id ): array {
		$raw = get_post_meta( $post_id, '_forge_links', true );
		if ( is_string( $raw ) && $raw !== '' ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				return array_values( array_filter( array_map(
					fn( $l ) => is_array( $l ) && isset( $l['url'] )
						? [ 'label' => (string) ( $l['label'] ?? '' ), 'url' => (string) $l['url'] ]
						: null,
					$decoded
				) ) );
			}
			// Stored a non-empty value that decoded to a non-array (valid JSON
			// scalar/object, or corrupt data → null). Treat as "no links" rather
			// than silently surfacing the legacy migration fallback below.
			return [];
		}
		// Migration fallback (only meaningful for bug/feedback, the item types
		// that ever persisted _forge_urls): legacy plain URLs become labeled links.
		$urls = self::meta_array( $post_id, '_forge_urls' );
		return array_map( fn( $u ) => [ 'label' => (string) $u, 'url' => (string) $u ], $urls );
	}

	private static function get_stage_dates( $post_id ) {
		$raw = get_post_meta( $post_id, '_forge_stage_dates', true );
		if ( ! $raw ) return (object) [];
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) && ! empty( $decoded ) ? $decoded : (object) [];
	}

	// ── Shape helpers (single post → normalised array) ─────────────────────

	private static function shape_feature( WP_Post $p ): array {
		return [
			'id'              => (string) $p->ID,
			'type'            => 'feature',
			'name'            => $p->post_title,
			'description'     => $p->post_content,
			'workflowStage'   => self::meta( $p->ID, '_forge_workflow_stage', 'scoping' ),
			'category'        => self::meta( $p->ID, '_forge_category', '' ),
			'featurePrice'    => self::meta( $p->ID, '_forge_feature_price', 'free' ),
			'timeEstimate'    => self::meta_int( $p->ID, '_forge_time_estimate' ),
			'releaseId'       => self::meta( $p->ID, '_forge_release_id' ) ?: null,
			'subItemIds'      => self::meta_array( $p->ID, '_forge_subitem_ids' ),
			'isEnabled'       => self::meta_bool( $p->ID, '_forge_is_enabled' ),
			'isTrackedAsStat' => self::meta_bool( $p->ID, '_forge_is_tracked_as_stat' ),
			'createdDate'     => get_the_date( 'Y-m-d', $p ),
			'images'          => self::meta_array( $p->ID, '_forge_image_urls' ),
			'brands'          => self::meta_array( $p->ID, '_forge_brands' ),
			'stageDates'      => self::get_stage_dates( $p->ID ),
			'changeLog'       => self::meta( $p->ID, '_forge_change_log' ) ?: null,
			'links'           => self::meta_links( $p->ID ),
		];
	}

	private static function shape_subitem( WP_Post $p ): array {
		return [
			'id'              => (string) $p->ID,
			'type'            => 'subitem',
			'name'            => $p->post_title,
			'description'     => $p->post_content,
			'parentFeatureId' => self::meta( $p->ID, '_forge_parent_feature_id', '' ),
			'workflowStage'   => self::meta( $p->ID, '_forge_workflow_stage', 'scoping' ),
			'category'        => self::meta( $p->ID, '_forge_category', '' ),
			'featurePrice'    => self::meta( $p->ID, '_forge_feature_price', 'free' ),
			'timeEstimate'    => self::meta_int( $p->ID, '_forge_time_estimate' ),
			'releaseId'       => self::meta( $p->ID, '_forge_release_id' ) ?: null,
			'images'          => self::meta_array( $p->ID, '_forge_image_urls' ),
			'brands'          => self::meta_array( $p->ID, '_forge_brands' ),
			'links'           => self::meta_links( $p->ID ),
		];
	}

	private static function shape_bug( WP_Post $p ): array {
		return [
			'id'              => (string) $p->ID,
			'type'            => 'bug',
			'title'           => $p->post_title,
			'description'     => $p->post_content,
			'linkedFeatureId' => self::meta( $p->ID, '_forge_linked_feature_id' ) ?: null,
			'releaseId'       => self::meta( $p->ID, '_forge_release_id' ) ?: null,
			'bugStatus'       => self::meta( $p->ID, '_forge_bug_status', 'open' ),
			'workflowStage'   => self::meta( $p->ID, '_forge_workflow_stage', 'bug-tracking' ),
			'priority'        => self::meta( $p->ID, '_forge_priority', 'medium' ),
			'timeEstimate'    => self::meta_int( $p->ID, '_forge_time_estimate' ),
			'reportedDate'    => self::meta( $p->ID, '_forge_reported_date', get_the_date( 'Y-m-d', $p ) ),
			'notes'           => self::meta( $p->ID, '_forge_notes' ) ?: null,
			'images'          => self::meta_array( $p->ID, '_forge_image_urls' ),
			'urls'            => self::meta_array( $p->ID, '_forge_urls' ),
			'links'           => self::meta_links( $p->ID ),
			'linkedSubItemId' => self::meta( $p->ID, '_forge_linked_subitem_id' ) ?: null,
		];
	}

	private static function shape_feedback( WP_Post $p ): array {
		return [
			'id'              => (string) $p->ID,
			'type'            => 'feedback',
			'title'           => $p->post_title,
			'description'     => $p->post_content,
			'linkedFeatureId' => self::meta( $p->ID, '_forge_linked_feature_id' ) ?: null,
			'linkedBugId'     => self::meta( $p->ID, '_forge_linked_bug_id' ) ?: null,
			'releaseId'       => self::meta( $p->ID, '_forge_release_id' ) ?: null,
			'status'          => self::meta( $p->ID, '_forge_status', 'open' ),
			'workflowStage'   => self::meta( $p->ID, '_forge_workflow_stage', 'scoping' ),
			'priority'        => self::meta( $p->ID, '_forge_priority', 'medium' ),
			'timeEstimate'    => self::meta_int( $p->ID, '_forge_time_estimate' ),
			'reportedDate'    => self::meta( $p->ID, '_forge_reported_date', get_the_date( 'Y-m-d', $p ) ),
			'notes'           => self::meta( $p->ID, '_forge_notes' ) ?: null,
			'images'          => self::meta_array( $p->ID, '_forge_image_urls' ),
			'urls'            => self::meta_array( $p->ID, '_forge_urls' ),
			'links'           => self::meta_links( $p->ID ),
			'linkedSubItemId' => self::meta( $p->ID, '_forge_linked_subitem_id' ) ?: null,
		];
	}

	private static function shape_release( WP_Post $p ): array {
		return [
			'id'                 => (string) $p->ID,
			'type'               => 'release',
			'name'               => $p->post_title,
			'releaseName'        => self::meta( $p->ID, '_forge_release_name', '' ),
			'versionNumber'      => self::meta( $p->ID, '_forge_version_number', '' ),
			'versionType'        => self::meta( $p->ID, '_forge_version_type', '' ),
			'quarter'            => self::meta( $p->ID, '_forge_quarter', '' ),
			'startWeek'          => self::meta( $p->ID, '_forge_start_week', '' ),
			'endWeek'            => self::meta( $p->ID, '_forge_end_week', '' ),
			'status'             => self::meta( $p->ID, '_forge_status', 'planned' ),
			'totalTimeEstimate'  => self::meta_int( $p->ID, '_forge_total_time_estimate' ),
			'capacity'           => self::meta_int( $p->ID, '_forge_capacity' ),
			'isBigWedgeCampaign' => self::meta_bool( $p->ID, '_forge_is_big_wedge_campaign' ),
			'linkedFeatureIds'   => self::meta_array( $p->ID, '_forge_linked_feature_ids' ),
			'linkedBugIds'       => self::meta_array( $p->ID, '_forge_linked_bug_ids' ),
			'linkedFeedbackIds'  => self::meta_array( $p->ID, '_forge_linked_feedback_ids' ),
			'links'              => self::meta_links( $p->ID ),
		];
	}

	private static function shape_company_date( WP_Post $p ): array {
		return [
			'id'          => (string) $p->ID,
			'title'       => $p->post_title,
			'date'        => self::meta( $p->ID, '_forge_date', '' ),
			'description' => self::meta( $p->ID, '_forge_description' ) ?: null,
			'tracked'     => self::meta_bool( $p->ID, '_forge_tracked' ),
		];
	}

	// ── Collection getters (used by get_all_items) ───────────────────────────

	private static function get_features(): array {
		return array_map( [ __CLASS__, 'shape_feature' ], self::base_query( 'forge_feature' ) );
	}

	private static function get_subitems(): array {
		return array_map( [ __CLASS__, 'shape_subitem' ], self::base_query( 'forge_subitem' ) );
	}

	private static function get_bugs(): array {
		return array_map( [ __CLASS__, 'shape_bug' ], self::base_query( 'forge_bug' ) );
	}

	private static function get_feedback(): array {
		return array_map( [ __CLASS__, 'shape_feedback' ], self::base_query( 'forge_feedback' ) );
	}

	private static function get_releases(): array {
		return array_map( [ __CLASS__, 'shape_release' ], self::base_query( 'forge_release' ) );
	}

	private static function get_company_dates(): array {
		return array_map( [ __CLASS__, 'shape_company_date' ], self::base_query( 'forge_company_date' ) );
	}

	// -----------------------------------------------------------------------
	// POST /forge/v1/items/{type}  — create new item
	// -----------------------------------------------------------------------

	private static function cpt_map(): array {
		return [
			'feature'      => 'forge_feature',
			'subitem'      => 'forge_subitem',
			'bug'          => 'forge_bug',
			'feedback'     => 'forge_feedback',
			'release'      => 'forge_release',
			'company_date' => 'forge_company_date',
		];
	}

	// Returns the normalised array shape for a single post (used by get_item and write responses).
	private static function read_single_item( int $post_id ): ?array {
		$post = get_post( $post_id );
		if ( ! $post ) return null;

		switch ( $post->post_type ) {
			case 'forge_feature':
				$rows = self::base_query( 'forge_feature', [ 'post__in' => [ $post_id ], 'numberposts' => 1 ] );
				return $rows ? self::shape_feature( $rows[0] ) : null;
			case 'forge_subitem':
				$rows = self::base_query( 'forge_subitem', [ 'post__in' => [ $post_id ], 'numberposts' => 1 ] );
				return $rows ? self::shape_subitem( $rows[0] ) : null;
			case 'forge_bug':
				$rows = self::base_query( 'forge_bug', [ 'post__in' => [ $post_id ], 'numberposts' => 1 ] );
				return $rows ? self::shape_bug( $rows[0] ) : null;
			case 'forge_feedback':
				$rows = self::base_query( 'forge_feedback', [ 'post__in' => [ $post_id ], 'numberposts' => 1 ] );
				return $rows ? self::shape_feedback( $rows[0] ) : null;
			case 'forge_release':
				$rows = self::base_query( 'forge_release', [ 'post__in' => [ $post_id ], 'numberposts' => 1 ] );
				return $rows ? self::shape_release( $rows[0] ) : null;
			case 'forge_company_date':
				$rows = self::base_query( 'forge_company_date', [ 'post__in' => [ $post_id ], 'numberposts' => 1 ] );
				return $rows ? self::shape_company_date( $rows[0] ) : null;
		}
		return null;
	}

	public static function create_item( $request ) {
		$type    = $request->get_param( 'type' );
		$data    = $request->get_json_params() ?: [];
		$cpt_map = self::cpt_map();

		if ( ! isset( $cpt_map[ $type ] ) ) {
			return new WP_Error( 'invalid_type', 'Invalid item type.', [ 'status' => 400 ] );
		}

		$title = sanitize_text_field( $data['name'] ?? $data['title'] ?? 'Untitled' );

		$post_id = wp_insert_post( [
			'post_type'    => $cpt_map[ $type ],
			'post_title'   => $title,
			'post_content' => sanitize_textarea_field( $data['description'] ?? '' ),
			'post_status'  => 'publish',
		] );

		if ( is_wp_error( $post_id ) ) return $post_id;

		// Save meta fields using the same map as update_item
		$scalar_meta = [
			'workflowStage'     => '_forge_workflow_stage',
			'category'          => '_forge_category',
			'featurePrice'      => '_forge_feature_price',
			'timeEstimate'      => '_forge_time_estimate',
			'releaseId'         => '_forge_release_id',
			'parentFeatureId'   => '_forge_parent_feature_id',
			'linkedFeatureId'   => '_forge_linked_feature_id',
			'linkedBugId'       => '_forge_linked_bug_id',
			'linkedSubItemId'   => '_forge_linked_subitem_id',
			'bugStatus'         => '_forge_bug_status',
			'status'            => '_forge_status',
			'priority'          => '_forge_priority',
			'reportedDate'      => '_forge_reported_date',
			'notes'             => '_forge_notes',
			'releaseName'       => '_forge_release_name',
			'versionNumber'     => '_forge_version_number',
			'versionType'       => '_forge_version_type',
			'quarter'           => '_forge_quarter',
			'startWeek'         => '_forge_start_week',
			'endWeek'           => '_forge_end_week',
			'capacity'          => '_forge_capacity',
			'date'              => '_forge_date',
		];

		$textarea_meta_keys = [ '_forge_description', '_forge_notes' ];

		foreach ( $scalar_meta as $js_key => $meta_key ) {
			if ( array_key_exists( $js_key, $data ) ) {
				$v = $data[ $js_key ];
				if ( is_string( $v ) ) {
					$v = in_array( $meta_key, $textarea_meta_keys, true )
						? sanitize_textarea_field( $v )
						: sanitize_text_field( $v );
				}
				update_post_meta( $post_id, $meta_key, $v );
			}
		}

		$bool_meta = [
			'isEnabled'          => '_forge_is_enabled',
			'isTrackedAsStat'    => '_forge_is_tracked_as_stat',
			'isBigWedgeCampaign' => '_forge_is_big_wedge_campaign',
		];
		foreach ( $bool_meta as $js_key => $meta_key ) {
			if ( array_key_exists( $js_key, $data ) ) {
				update_post_meta( $post_id, $meta_key, $data[ $js_key ] ? '1' : '0' );
			}
		}

		if ( array_key_exists( 'images', $data ) && is_array( $data['images'] ) ) {
			update_post_meta( $post_id, '_forge_image_urls', array_map( 'sanitize_text_field', $data['images'] ) );
		}

		if ( array_key_exists( 'brands', $data ) && is_array( $data['brands'] ) ) {
			update_post_meta( $post_id, '_forge_brands', array_map( 'sanitize_text_field', $data['brands'] ) );
		}

		if ( array_key_exists( 'stageDates', $data ) && is_array( $data['stageDates'] ) ) {
			$clean = [];
			foreach ( $data['stageDates'] as $stage_id => $date ) {
				$clean[ sanitize_key( $stage_id ) ] = sanitize_text_field( $date );
			}
			update_post_meta( $post_id, '_forge_stage_dates', wp_json_encode( $clean ) );
		}

		if ( array_key_exists( 'links', $data ) && is_array( $data['links'] ) ) {
			$clean = [];
			foreach ( $data['links'] as $link ) {
				if ( ! is_array( $link ) || empty( $link['url'] ) ) continue;
				$clean[] = [
					'label' => sanitize_text_field( $link['label'] ?? '' ),
					'url'   => esc_url_raw( $link['url'] ),
				];
			}
			update_post_meta( $post_id, '_forge_links', wp_json_encode( $clean ) );
		}

		// Auto-generate initial changelog entry
		$verb  = in_array( $type, [ 'bug', 'feedback' ], true ) ? 'Reported' : 'Created';
		$stage = sanitize_text_field( $data['workflowStage'] ?? '' );
		$entry = gmdate( 'Y-m-d' ) . ": {$verb}" . ( $stage ? " (→ {$stage})" : '' ) . ' — ' . self::current_user_name();
		update_post_meta( $post_id, '_forge_change_log', $entry );

		self::bust_cache();
		$item = self::read_single_item( $post_id );
		return rest_ensure_response( [ 'success' => true, 'id' => (string) $post_id, 'item' => $item ] );
	}

	// -----------------------------------------------------------------------
	// DELETE /forge/v1/items/{type}/{id}
	// -----------------------------------------------------------------------

	public static function delete_item( $request ) {
		$post_id  = absint( $request->get_param( 'id' ) );
		$req_type = $request->get_param( 'type' );
		$post     = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Item not found.', [ 'status' => 404 ] );
		}

		$cpt_map = self::cpt_map();
		if ( ! isset( $cpt_map[ $req_type ] ) || $post->post_type !== $cpt_map[ $req_type ] ) {
			return new WP_Error( 'type_mismatch', 'Post type does not match route.', [ 'status' => 400 ] );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'forbidden', 'Permission denied.', [ 'status' => 403 ] );
		}

		// When a release is deleted, remove its reference from all linked items.
		if ( $post->post_type === 'forge_release' ) {
			global $wpdb;
			$linked_ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_forge_release_id' AND meta_value = %s",
				(string) $post_id
			) );
			foreach ( $linked_ids as $linked_id ) {
				delete_post_meta( (int) $linked_id, '_forge_release_id' );
			}
		}

		wp_trash_post( $post_id );

		self::bust_cache();
		return rest_ensure_response( [ 'success' => true, 'id' => $post_id ] );
	}

	// -----------------------------------------------------------------------
	// PUT /forge/v1/items/{type}/{id}
	// -----------------------------------------------------------------------

	public static function update_item( $request ) {
		$post_id  = absint( $request->get_param( 'id' ) );
		$req_type = $request->get_param( 'type' );
		$data     = $request->get_json_params();

		if ( ! $data || ! is_array( $data ) ) {
			return new WP_Error( 'invalid_data', 'No JSON body supplied.', [ 'status' => 400 ] );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Item not found.', [ 'status' => 404 ] );
		}

		$cpt_map = self::cpt_map();
		if ( ! isset( $cpt_map[ $req_type ] ) || $post->post_type !== $cpt_map[ $req_type ] ) {
			return new WP_Error( 'type_mismatch', 'Post type does not match route.', [ 'status' => 400 ] );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'forbidden', 'Permission denied.', [ 'status' => 403 ] );
		}

		// Auto-generate changelog entry (features only) — must run before any saves
		if ( $post->post_type === 'forge_feature' ) {
			$changes = [];

			$scalar_checks = [
				'name'         => [ 'old' => $post->post_title,                                      'label' => 'Name' ],
				'workflowStage'=> [ 'old' => get_post_meta( $post_id, '_forge_workflow_stage', true ), 'label' => 'Stage' ],
				'featurePrice' => [ 'old' => get_post_meta( $post_id, '_forge_feature_price',  true ), 'label' => 'Price' ],
				'category'     => [ 'old' => get_post_meta( $post_id, '_forge_category',       true ), 'label' => 'Category' ],
				'releaseId'    => [ 'old' => get_post_meta( $post_id, '_forge_release_id',     true ) ?: '', 'label' => 'Release' ],
			];
			foreach ( $scalar_checks as $key => $info ) {
				if ( ! array_key_exists( $key, $data ) ) continue;
				$old = (string) ( $info['old'] ?? '' );
				$new = (string) $data[ $key ];
				if ( $old !== $new ) {
					$changes[] = "{$info['label']} changed ({$old} -> {$new})";
				}
			}

			$bool_checks = [
				'isEnabled'       => [ 'meta' => '_forge_is_enabled',        'label' => 'Feature' ],
				'isTrackedAsStat' => [ 'meta' => '_forge_is_tracked_as_stat', 'label' => 'Stat tracking' ],
			];
			foreach ( $bool_checks as $key => $info ) {
				if ( ! array_key_exists( $key, $data ) ) continue;
				$old = self::meta_bool( $post_id, $info['meta'] );
				$new = (bool) $data[ $key ];
				if ( $old !== $new ) {
					$changes[] = "{$info['label']} " . ( $new ? 'enabled' : 'disabled' );
				}
			}

			if ( isset( $data['description'] ) && trim( $data['description'] ) !== trim( $post->post_content ) ) {
				$changes[] = 'Description updated';
			}

			if ( isset( $data['brands'] ) && is_array( $data['brands'] ) ) {
				$old_brands = self::meta_array( $post_id, '_forge_brands' );
				sort( $old_brands ); $new_brands = $data['brands']; sort( $new_brands );
				if ( $old_brands !== $new_brands ) $changes[] = 'Brands updated';
			}

			if ( ! empty( $changes ) ) {
				$entry = gmdate( 'Y-m-d' ) . ': ' . implode( ', ', $changes ) . ' — ' . self::current_user_name();
				self::append_changelog( $post_id, $entry );
			}
		}

		// Update title / content
		$update = [ 'ID' => $post_id ];
		if ( isset( $data['name'] ) )  $update['post_title'] = sanitize_text_field( $data['name'] );
		if ( isset( $data['title'] ) ) $update['post_title'] = sanitize_text_field( $data['title'] );
		// company_date stores description only in _forge_description meta — skip post_content write
		if ( isset( $data['description'] ) && $post->post_type !== 'forge_company_date' ) {
			$update['post_content'] = sanitize_textarea_field( $data['description'] );
		}
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
			'linkedSubItemId'   => '_forge_linked_subitem_id',
			'bugStatus'         => '_forge_bug_status',
			'status'            => '_forge_status',
			'priority'          => '_forge_priority',
			'reportedDate'      => '_forge_reported_date',
			'notes'             => '_forge_notes',
			'releaseName'       => '_forge_release_name',
			'versionNumber'     => '_forge_version_number',
			'versionType'       => '_forge_version_type',
			'quarter'           => '_forge_quarter',
			'startWeek'         => '_forge_start_week',
			'endWeek'           => '_forge_end_week',
			'totalTimeEstimate' => '_forge_total_time_estimate',
			'capacity'          => '_forge_capacity',
			'date'              => '_forge_date',
			// company_date stores description in meta, not post_content
			'description'       => '_forge_description',
		];

		// Textarea fields need sanitize_textarea_field to preserve newlines
		$textarea_meta_keys = [ '_forge_description', '_forge_notes' ];

		foreach ( $scalar_meta as $js_key => $meta_key ) {
			if ( array_key_exists( $js_key, $data ) ) {
				$v = $data[ $js_key ];
				if ( is_string( $v ) ) {
					$v = in_array( $meta_key, $textarea_meta_keys, true )
						? sanitize_textarea_field( $v )
						: sanitize_text_field( $v );
				}
				update_post_meta( $post_id, $meta_key, $v );
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
			'images'              => '_forge_image_urls',
			'brands'              => '_forge_brands',
		];
		foreach ( $array_meta as $js_key => $meta_key ) {
			if ( array_key_exists( $js_key, $data ) && is_array( $data[ $js_key ] ) ) {
				update_post_meta( $post_id, $meta_key, array_map( 'sanitize_text_field', $data[ $js_key ] ) );
			}
		}

		// stageDates — stored as JSON object
		if ( array_key_exists( 'stageDates', $data ) && is_array( $data['stageDates'] ) ) {
			$clean = [];
			foreach ( $data['stageDates'] as $stage_id => $date ) {
				$clean[ sanitize_key( $stage_id ) ] = sanitize_text_field( $date );
			}
			update_post_meta( $post_id, '_forge_stage_dates', wp_json_encode( $clean ) );
		}

		if ( array_key_exists( 'links', $data ) && is_array( $data['links'] ) ) {
			$clean = [];
			foreach ( $data['links'] as $link ) {
				if ( ! is_array( $link ) || empty( $link['url'] ) ) continue;
				$clean[] = [
					'label' => sanitize_text_field( $link['label'] ?? '' ),
					'url'   => esc_url_raw( $link['url'] ),
				];
			}
			update_post_meta( $post_id, '_forge_links', wp_json_encode( $clean ) );
		}

		self::bust_cache();
		$item = self::read_single_item( $post_id );
		return rest_ensure_response( [ 'success' => true, 'id' => (string) $post_id, 'item' => $item ] );
	}

	// -----------------------------------------------------------------------
	// PATCH /forge/v1/items/{type}/{id}/stage
	// -----------------------------------------------------------------------

	public static function update_stage( $request ) {
		$post_id  = absint( $request->get_param( 'id' ) );
		$req_type = $request->get_param( 'type' );
		$data     = $request->get_json_params();

		if ( empty( $data['workflowStage'] ) ) {
			return new WP_Error( 'missing_stage', 'workflowStage is required.', [ 'status' => 400 ] );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Item not found.', [ 'status' => 404 ] );
		}

		$cpt_map = self::cpt_map();
		if ( ! isset( $cpt_map[ $req_type ] ) || $post->post_type !== $cpt_map[ $req_type ] ) {
			return new WP_Error( 'type_mismatch', 'Post type does not match route.', [ 'status' => 400 ] );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'forbidden', 'Permission denied.', [ 'status' => 403 ] );
		}

		// Stages are user-configurable — accept any sanitized string
		$stage     = sanitize_text_field( $data['workflowStage'] );
		$old_stage = get_post_meta( $post_id, '_forge_workflow_stage', true ) ?: '';
		update_post_meta( $post_id, '_forge_workflow_stage', $stage );

		if ( $post->post_type === 'forge_feature' && $old_stage !== $stage ) {
			$entry = gmdate( 'Y-m-d' ) . ": Stage changed ({$old_stage} -> {$stage}) — " . self::current_user_name();
			self::append_changelog( $post_id, $entry );
		}

		self::bust_cache();
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

		self::bust_cache();
		return rest_ensure_response( [
			'id'          => (string) $post_id,
			'title'       => $data['title'],
			'date'        => $data['date'],
			'description' => $data['description'] ?? null,
			'tracked'     => ! empty( $data['tracked'] ),
		] );
	}
}
