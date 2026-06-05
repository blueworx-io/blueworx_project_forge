<?php
defined( 'ABSPATH' ) || exit;

class Forge_PM_Post_Types {

	public static function register() {
		$types = [
			'forge_feature'      => [ 'Feature',       'Features' ],
			'forge_subitem'      => [ 'Sub-Item',       'Sub-Items' ],
			'forge_bug'          => [ 'Bug',            'Bugs' ],
			'forge_feedback'     => [ 'Feedback',       'Feedback' ],
			'forge_release'      => [ 'Release',        'Releases' ],
			'forge_company_date' => [ 'Company Date',   'Company Dates' ],
		];

		foreach ( $types as $slug => [ $singular, $plural ] ) {
			register_post_type( $slug, [
				'label'               => $plural,
				'labels'              => [
					'name'               => $plural,
					'singular_name'      => $singular,
					'add_new_item'       => "Add New {$singular}",
					'edit_item'          => "Edit {$singular}",
					'new_item'           => "New {$singular}",
					'view_item'          => "View {$singular}",
					'search_items'       => "Search {$plural}",
					'not_found'          => "No {$plural} found",
					'not_found_in_trash' => "No {$plural} found in trash",
				],
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => 'forge-project-management',
				'show_in_rest'        => false,
				'supports'            => [ 'title', 'editor' ],
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'hierarchical'        => false,
				'rewrite'             => false,
			] );
		}

		add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );
		add_action( 'add_meta_boxes', [ __CLASS__, 'register_meta_boxes' ] );
		add_action( 'save_post', [ __CLASS__, 'save_meta_boxes' ], 10, 2 );
	}

	public static function register_menu() {
		add_menu_page(
			__( 'Forge PM', 'forge-pm' ),
			__( 'Forge PM', 'forge-pm' ),
			'edit_posts',
			'forge-project-management',
			[ __CLASS__, 'render_dashboard' ],
			'dashicons-chart-bar',
			25
		);

		// Logical order: planning → features → sub-tasks → issues → input → calendar
		add_submenu_page( 'forge-project-management', __( 'Releases',       'forge-pm' ), __( 'Releases',       'forge-pm' ), 'edit_posts', 'edit.php?post_type=forge_release' );
		add_submenu_page( 'forge-project-management', __( 'Features',       'forge-pm' ), __( 'Features',       'forge-pm' ), 'edit_posts', 'edit.php?post_type=forge_feature' );
		add_submenu_page( 'forge-project-management', __( 'Sub-Items',      'forge-pm' ), __( 'Sub-Items',      'forge-pm' ), 'edit_posts', 'edit.php?post_type=forge_subitem' );
		add_submenu_page( 'forge-project-management', __( 'Bugs',           'forge-pm' ), __( 'Bugs',           'forge-pm' ), 'edit_posts', 'edit.php?post_type=forge_bug' );
		add_submenu_page( 'forge-project-management', __( 'Feedback',       'forge-pm' ), __( 'Feedback',       'forge-pm' ), 'edit_posts', 'edit.php?post_type=forge_feedback' );
		add_submenu_page( 'forge-project-management', __( 'Company Dates',  'forge-pm' ), __( 'Company Dates',  'forge-pm' ), 'edit_posts', 'edit.php?post_type=forge_company_date' );
	}

	public static function render_dashboard() {
		$page_id = get_option( 'forge_pm_page_id' );
		$url     = $page_id ? get_permalink( $page_id ) : '';
		echo '<div class="wrap"><h1>' . esc_html__( 'Forge Project Management', 'forge-pm' ) . '</h1>';
		if ( $url ) {
			echo '<p>' . sprintf(
				wp_kses( __( '<a href="%s" target="_blank">View the project management page &rarr;</a>', 'forge-pm' ), [ 'a' => [ 'href' => [], 'target' => [] ] ] ),
				esc_url( $url )
			) . '</p>';
		}
		echo '</div>';
	}

	public static function register_meta_boxes() {
		// Feature fields
		add_meta_box( 'forge_feature_meta', __( 'Feature Details', 'forge-pm' ), [ __CLASS__, 'render_feature_meta' ], 'forge_feature', 'normal', 'default' );
		// SubItem fields
		add_meta_box( 'forge_subitem_meta', __( 'Sub-Item Details', 'forge-pm' ), [ __CLASS__, 'render_subitem_meta' ], 'forge_subitem', 'normal', 'default' );
		// Bug fields
		add_meta_box( 'forge_bug_meta', __( 'Bug Details', 'forge-pm' ), [ __CLASS__, 'render_bug_meta' ], 'forge_bug', 'normal', 'default' );
		// Feedback fields
		add_meta_box( 'forge_feedback_meta', __( 'Feedback Details', 'forge-pm' ), [ __CLASS__, 'render_feedback_meta' ], 'forge_feedback', 'normal', 'default' );
		// Release fields
		add_meta_box( 'forge_release_meta', __( 'Release Details', 'forge-pm' ), [ __CLASS__, 'render_release_meta' ], 'forge_release', 'normal', 'default' );
		// Company Date fields
		add_meta_box( 'forge_company_date_meta', __( 'Date Details', 'forge-pm' ), [ __CLASS__, 'render_company_date_meta' ], 'forge_company_date', 'normal', 'default' );
	}

	// -----------------------------------------------------------------------
	// Meta box renderers (native WP form fields only)
	// -----------------------------------------------------------------------

	private static function nonce( $action ) {
		wp_nonce_field( $action, 'forge_pm_nonce' );
	}

	private static function text_field( $post_id, $key, $label, $type = 'text' ) {
		$value = get_post_meta( $post_id, $key, true );
		printf(
			'<p><label for="%1$s"><strong>%2$s</strong></label><br><input type="%3$s" id="%1$s" name="%1$s" value="%4$s" class="widefat"></p>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( $type ),
			esc_attr( $value )
		);
	}

	private static function select_field( $post_id, $key, $label, $options ) {
		$value = get_post_meta( $post_id, $key, true );
		echo '<p><label for="' . esc_attr( $key ) . '"><strong>' . esc_html( $label ) . '</strong></label><br>';
		echo '<select id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" class="widefat">';
		foreach ( $options as $opt_val => $opt_label ) {
			echo '<option value="' . esc_attr( $opt_val ) . '"' . selected( $value, $opt_val, false ) . '>' . esc_html( $opt_label ) . '</option>';
		}
		echo '</select></p>';
	}

	private static function checkbox_field( $post_id, $key, $label ) {
		$value = get_post_meta( $post_id, $key, true );
		printf(
			'<p><label><input type="checkbox" name="%1$s" value="1" %2$s> <strong>%3$s</strong></label></p>',
			esc_attr( $key ),
			checked( $value, '1', false ),
			esc_html( $label )
		);
	}

	private static function workflow_stages() {
		return [
			'bug-tracking'      => 'Bug Tracking',
			'scoping'           => 'Scoping',
			'future-idea'       => 'Future Idea',
			'up-next'           => 'Up Next',
			'in-development'    => 'In Development',
			'staging-features'  => 'Staging Features',
			'active-features'   => 'Active Features',
		];
	}

	private static function feature_price_options() {
		return [ 'scoping' => 'Scoping', 'premium' => 'Premium', 'teaser' => 'Teaser', 'free' => 'Free' ];
	}

	private static function release_select( $post_id, $key = '_forge_release_id' ) {
		$value    = get_post_meta( $post_id, $key, true );
		$releases = get_posts( [ 'post_type' => 'forge_release', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ] );
		echo '<p><label for="' . esc_attr( $key ) . '"><strong>' . esc_html__( 'Release', 'forge-pm' ) . '</strong></label><br>';
		echo '<select id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" class="widefat">';
		echo '<option value="">' . esc_html__( '— None —', 'forge-pm' ) . '</option>';
		foreach ( $releases as $r ) {
			echo '<option value="' . esc_attr( $r->ID ) . '"' . selected( $value, (string) $r->ID, false ) . '>' . esc_html( $r->post_title ) . '</option>';
		}
		echo '</select></p>';
	}

	private static function feature_select( $post_id, $key = '_forge_linked_feature_id', $label = 'Linked Feature' ) {
		$value    = get_post_meta( $post_id, $key, true );
		$features = get_posts( [ 'post_type' => 'forge_feature', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ] );
		echo '<p><label for="' . esc_attr( $key ) . '"><strong>' . esc_html( $label ) . '</strong></label><br>';
		echo '<select id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" class="widefat">';
		echo '<option value="">' . esc_html__( '— None —', 'forge-pm' ) . '</option>';
		foreach ( $features as $f ) {
			echo '<option value="' . esc_attr( $f->ID ) . '"' . selected( $value, (string) $f->ID, false ) . '>' . esc_html( $f->post_title ) . '</option>';
		}
		echo '</select></p>';
	}

	public static function render_feature_meta( $post ) {
		self::nonce( 'forge_feature_meta' );
		self::select_field(  $post->ID, '_forge_workflow_stage',    'Workflow Stage',  self::workflow_stages() );
		self::text_field(    $post->ID, '_forge_category',          'Category' );
		self::select_field(  $post->ID, '_forge_feature_price',     'Feature Price',   self::feature_price_options() );
		self::text_field(    $post->ID, '_forge_time_estimate',     'Time Estimate (hours)', 'number' );
		self::checkbox_field( $post->ID, '_forge_is_enabled',       'Enabled' );
		self::checkbox_field( $post->ID, '_forge_is_tracked_as_stat', 'Track as Stat' );
		self::release_select( $post->ID );
	}

	public static function render_subitem_meta( $post ) {
		self::nonce( 'forge_subitem_meta' );
		self::feature_select( $post->ID, '_forge_parent_feature_id', 'Parent Feature' );
		self::select_field(  $post->ID, '_forge_workflow_stage',  'Workflow Stage', self::workflow_stages() );
		self::text_field(    $post->ID, '_forge_category',        'Category' );
		self::select_field(  $post->ID, '_forge_feature_price',   'Feature Price',  self::feature_price_options() );
		self::text_field(    $post->ID, '_forge_time_estimate',   'Time Estimate (hours)', 'number' );
		self::release_select( $post->ID );
	}

	public static function render_bug_meta( $post ) {
		self::nonce( 'forge_bug_meta' );
		self::select_field(  $post->ID, '_forge_workflow_stage', 'Workflow Stage', self::workflow_stages() );
		self::select_field(  $post->ID, '_forge_bug_status',     'Bug Status', [ 'open' => 'Open', 'in-progress' => 'In Progress', 'resolved' => 'Resolved' ] );
		self::select_field(  $post->ID, '_forge_priority',       'Priority',   [ 'low' => 'Low', 'medium' => 'Medium', 'high' => 'High' ] );
		self::text_field(    $post->ID, '_forge_time_estimate',  'Time Estimate (hours)', 'number' );
		self::text_field(    $post->ID, '_forge_reported_date',  'Reported Date', 'date' );
		self::feature_select( $post->ID );
		self::release_select( $post->ID );
		$notes = get_post_meta( $post->ID, '_forge_notes', true );
		echo '<p><label for="_forge_notes"><strong>' . esc_html__( 'Notes', 'forge-pm' ) . '</strong></label><br>';
		echo '<textarea id="_forge_notes" name="_forge_notes" class="widefat" rows="4">' . esc_textarea( $notes ) . '</textarea></p>';
		$urls = get_post_meta( $post->ID, '_forge_urls', true );
		$urls_str = is_array( $urls ) ? implode( "\n", $urls ) : '';
		echo '<p><label for="_forge_urls"><strong>' . esc_html__( 'URLs (one per line)', 'forge-pm' ) . '</strong></label><br>';
		echo '<textarea id="_forge_urls" name="_forge_urls" class="widefat" rows="3">' . esc_textarea( $urls_str ) . '</textarea></p>';
	}

	public static function render_feedback_meta( $post ) {
		self::nonce( 'forge_feedback_meta' );
		self::select_field(  $post->ID, '_forge_workflow_stage', 'Workflow Stage', self::workflow_stages() );
		self::select_field(  $post->ID, '_forge_status',         'Status', [ 'open' => 'Open', 'in-progress' => 'In Progress', 'resolved' => 'Resolved' ] );
		self::select_field(  $post->ID, '_forge_priority',       'Priority', [ 'low' => 'Low', 'medium' => 'Medium', 'high' => 'High' ] );
		self::text_field(    $post->ID, '_forge_time_estimate',  'Time Estimate (hours)', 'number' );
		self::text_field(    $post->ID, '_forge_reported_date',  'Reported Date', 'date' );
		self::feature_select( $post->ID );
		// Bug select
		$bug_val  = get_post_meta( $post->ID, '_forge_linked_bug_id', true );
		$bugs     = get_posts( [ 'post_type' => 'forge_bug', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ] );
		echo '<p><label for="_forge_linked_bug_id"><strong>' . esc_html__( 'Linked Bug', 'forge-pm' ) . '</strong></label><br>';
		echo '<select id="_forge_linked_bug_id" name="_forge_linked_bug_id" class="widefat">';
		echo '<option value="">' . esc_html__( '— None —', 'forge-pm' ) . '</option>';
		foreach ( $bugs as $b ) {
			echo '<option value="' . esc_attr( $b->ID ) . '"' . selected( $bug_val, (string) $b->ID, false ) . '>' . esc_html( $b->post_title ) . '</option>';
		}
		echo '</select></p>';
		self::release_select( $post->ID );
		$notes = get_post_meta( $post->ID, '_forge_notes', true );
		echo '<p><label for="_forge_notes"><strong>' . esc_html__( 'Notes', 'forge-pm' ) . '</strong></label><br>';
		echo '<textarea id="_forge_notes" name="_forge_notes" class="widefat" rows="4">' . esc_textarea( $notes ) . '</textarea></p>';
	}

	public static function render_release_meta( $post ) {
		self::nonce( 'forge_release_meta' );
		self::text_field( $post->ID, '_forge_quarter',        'Quarter (e.g. Q2 2026)' );
		self::text_field( $post->ID, '_forge_start_week',     'Start Week', 'date' );
		self::text_field( $post->ID, '_forge_end_week',       'End Week', 'date' );
		self::select_field( $post->ID, '_forge_status', 'Status', [ 'planned' => 'Planned', 'in-progress' => 'In Progress', 'complete' => 'Complete' ] );
		self::text_field( $post->ID, '_forge_total_time_estimate', 'Total Time Estimate (hours)', 'number' );
		self::text_field( $post->ID, '_forge_capacity',       'Capacity (hours)', 'number' );
		self::checkbox_field( $post->ID, '_forge_is_big_wedge_campaign', 'Big Wedge Campaign' );
		// Multi-select linked items shown as comma-separated IDs (managed by frontend)
		$fids = get_post_meta( $post->ID, '_forge_linked_feature_ids', true );
		$bids = get_post_meta( $post->ID, '_forge_linked_bug_ids', true );
		$fbids = get_post_meta( $post->ID, '_forge_linked_feedback_ids', true );
		$fids_str  = is_array( $fids )  ? implode( ', ', $fids )  : '';
		$bids_str  = is_array( $bids )  ? implode( ', ', $bids )  : '';
		$fbids_str = is_array( $fbids ) ? implode( ', ', $fbids ) : '';
		echo '<p><label><strong>' . esc_html__( 'Linked Feature IDs (comma-separated)', 'forge-pm' ) . '</strong></label><br><input type="text" name="_forge_linked_feature_ids_str" value="' . esc_attr( $fids_str ) . '" class="widefat"></p>';
		echo '<p><label><strong>' . esc_html__( 'Linked Bug IDs (comma-separated)', 'forge-pm' ) . '</strong></label><br><input type="text" name="_forge_linked_bug_ids_str" value="' . esc_attr( $bids_str ) . '" class="widefat"></p>';
		echo '<p><label><strong>' . esc_html__( 'Linked Feedback IDs (comma-separated)', 'forge-pm' ) . '</strong></label><br><input type="text" name="_forge_linked_feedback_ids_str" value="' . esc_attr( $fbids_str ) . '" class="widefat"></p>';
	}

	public static function render_company_date_meta( $post ) {
		self::nonce( 'forge_company_date_meta' );
		self::text_field( $post->ID, '_forge_date',        'Date', 'date' );
		$desc = get_post_meta( $post->ID, '_forge_description', true );
		echo '<p><label for="_forge_description"><strong>' . esc_html__( 'Description', 'forge-pm' ) . '</strong></label><br>';
		echo '<textarea id="_forge_description" name="_forge_description" class="widefat" rows="3">' . esc_textarea( $desc ) . '</textarea></p>';
		self::checkbox_field( $post->ID, '_forge_tracked', 'Track on Timeline' );
	}

	// -----------------------------------------------------------------------
	// Save meta boxes
	// -----------------------------------------------------------------------

	public static function save_meta_boxes( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
		if ( ! isset( $_POST['forge_pm_nonce'] ) ) return;
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['forge_pm_nonce'] ) ), 'forge_' . str_replace( 'forge_', '', $post->post_type ) . '_meta' ) ) return;
		if ( ! current_user_can( 'edit_post', $post_id ) ) return;

		$simple_keys = [
			'_forge_workflow_stage', '_forge_category', '_forge_feature_price',
			'_forge_time_estimate', '_forge_bug_status', '_forge_status',
			'_forge_priority', '_forge_reported_date', '_forge_notes',
			'_forge_quarter', '_forge_start_week', '_forge_end_week',
			'_forge_total_time_estimate', '_forge_capacity',
			'_forge_date', '_forge_description',
			'_forge_release_id', '_forge_parent_feature_id',
			'_forge_linked_feature_id', '_forge_linked_bug_id',
		];

		foreach ( $simple_keys as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				update_post_meta( $post_id, $key, sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) );
			}
		}

		$checkboxes = [ '_forge_is_enabled', '_forge_is_tracked_as_stat', '_forge_is_big_wedge_campaign', '_forge_tracked' ];
		foreach ( $checkboxes as $key ) {
			update_post_meta( $post_id, $key, isset( $_POST[ $key ] ) ? '1' : '0' );
		}

		// Textarea URLs
		if ( isset( $_POST['_forge_urls'] ) ) {
			$raw  = sanitize_textarea_field( wp_unslash( $_POST['_forge_urls'] ) );
			$urls = array_filter( array_map( 'trim', explode( "\n", $raw ) ) );
			update_post_meta( $post_id, '_forge_urls', array_values( $urls ) );
		}

		// Release linked IDs (comma-separated)
		foreach ( [ '_forge_linked_feature_ids', '_forge_linked_bug_ids', '_forge_linked_feedback_ids' ] as $key ) {
			$str_key = $key . '_str';
			if ( isset( $_POST[ $str_key ] ) ) {
				$raw = sanitize_text_field( wp_unslash( $_POST[ $str_key ] ) );
				$ids = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
				update_post_meta( $post_id, $key, array_values( $ids ) );
			}
		}
	}
}
