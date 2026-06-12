<?php
defined( 'ABSPATH' ) || exit;

class Forge_PM_Sample_Data {

	public static function maybe_seed() {
		// Only seed once — check if any features already exist
		$existing = get_posts( [ 'post_type' => 'forge_feature', 'numberposts' => 1, 'post_status' => 'publish' ] );
		if ( ! empty( $existing ) ) return;

		self::seed();
	}

	public static function seed() {
		// ----------------------------------------------------------------
		// Releases (seed first so we can reference their IDs)
		// ----------------------------------------------------------------
		$r1_id = self::insert_post( 'forge_release', 'v2.0 - Major Release', '', [
			'_forge_quarter'              => 'Q2 2026',
			'_forge_start_week'           => '2026-05-01',
			'_forge_end_week'             => '2026-07-15',
			'_forge_status'               => 'in-progress',
			'_forge_total_time_estimate'  => 88,
			'_forge_capacity'             => 80,
			'_forge_is_big_wedge_campaign' => '1',
		] );

		$r2_id = self::insert_post( 'forge_release', 'v2.1 - Feature Expansion', '', [
			'_forge_quarter'              => 'Q3 2026',
			'_forge_start_week'           => '2026-07-20',
			'_forge_end_week'             => '2026-09-30',
			'_forge_status'               => 'planned',
			'_forge_total_time_estimate'  => 58,
			'_forge_capacity'             => 60,
			'_forge_is_big_wedge_campaign' => '0',
		] );

		// ----------------------------------------------------------------
		// Features
		// ----------------------------------------------------------------
		$f1_id = self::insert_post( 'forge_feature', 'User Authentication System',
			'Implement OAuth 2.0 authentication with support for Google, GitHub, and Microsoft providers. Include session management and refresh token handling.',
			[
				'_forge_workflow_stage'    => 'in-development',
				'_forge_category'          => 'Security',
				'_forge_feature_price'     => 'premium',
				'_forge_time_estimate'     => 40,
				'_forge_release_id'        => $r1_id,
				'_forge_is_tracked_as_stat' => '1',
				'_forge_image_urls'        => [
					'https://images.unsplash.com/photo-1633265486064-086b219458ec?w=800',
					'https://images.unsplash.com/photo-1614064641938-3bbee52942c7?w=800',
				],
			]
		);

		$f2_id = self::insert_post( 'forge_feature', 'Dashboard Performance Optimization',
			'Optimize dashboard loading time by implementing lazy loading, caching strategies, and reducing initial bundle size.',
			[
				'_forge_workflow_stage'    => 'in-development',
				'_forge_category'          => 'Performance',
				'_forge_feature_price'     => 'free',
				'_forge_time_estimate'     => 24,
				'_forge_release_id'        => $r1_id,
				'_forge_is_tracked_as_stat' => '1',
			]
		);

		$f3_id = self::insert_post( 'forge_feature', 'Dark Mode Support',
			'Add comprehensive dark mode theme with automatic detection and manual toggle. Ensure all components support both themes.',
			[
				'_forge_workflow_stage'    => 'up-next',
				'_forge_category'          => 'UI/UX',
				'_forge_feature_price'     => 'teaser',
				'_forge_time_estimate'     => 16,
				'_forge_release_id'        => $r1_id,
				'_forge_is_tracked_as_stat' => '0',
				'_forge_image_urls'        => [ 'https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=800' ],
			]
		);

		$f4_id = self::insert_post( 'forge_feature', 'Export to Multiple Formats',
			'Allow users to export project data to CSV, JSON, Excel, and PDF formats with customizable field selection.',
			[
				'_forge_workflow_stage'    => 'scoping',
				'_forge_category'          => 'Data Management',
				'_forge_feature_price'     => 'scoping',
				'_forge_time_estimate'     => 20,
				'_forge_release_id'        => $r2_id,
				'_forge_is_tracked_as_stat' => '0',
			]
		);

		$f5_id = self::insert_post( 'forge_feature', 'Email Notification System',
			'Send email notifications for important project updates, deadlines, and status changes.',
			[
				'_forge_workflow_stage'    => 'future-idea',
				'_forge_category'          => 'Communication',
				'_forge_feature_price'     => 'premium',
				'_forge_time_estimate'     => 28,
				'_forge_is_tracked_as_stat' => '0',
			]
		);

		$f6_id = self::insert_post( 'forge_feature', 'Advanced Search & Filters',
			'Implement comprehensive search with filters, saved searches, and full-text search capabilities.',
			[
				'_forge_workflow_stage'    => 'staging-features',
				'_forge_category'          => 'Search',
				'_forge_feature_price'     => 'premium',
				'_forge_time_estimate'     => 32,
				'_forge_release_id'        => $r2_id,
				'_forge_is_tracked_as_stat' => '1',
			]
		);

		$f7_id = self::insert_post( 'forge_feature', 'Mobile App Integration',
			'Native mobile app with offline support and push notifications.',
			[
				'_forge_workflow_stage'    => 'active-features',
				'_forge_category'          => 'Mobile',
				'_forge_feature_price'     => 'premium',
				'_forge_time_estimate'     => 120,
				'_forge_is_tracked_as_stat' => '1',
			]
		);

		// ----------------------------------------------------------------
		// Sub-Items (linked to f1)
		// ----------------------------------------------------------------
		$s1_id = self::insert_post( 'forge_subitem', 'OAuth Provider Integration',
			'Integrate Google, GitHub, and Microsoft OAuth providers with proper error handling.',
			[
				'_forge_parent_feature_id' => $f1_id,
				'_forge_workflow_stage'    => 'in-development',
				'_forge_category'          => 'Security',
				'_forge_feature_price'     => 'premium',
				'_forge_time_estimate'     => 20,
				'_forge_release_id'        => $r1_id,
			]
		);

		$s2_id = self::insert_post( 'forge_subitem', 'Session Management',
			'Implement secure session handling with refresh token rotation and automatic session cleanup.',
			[
				'_forge_parent_feature_id' => $f1_id,
				'_forge_workflow_stage'    => 'in-development',
				'_forge_category'          => 'Security',
				'_forge_feature_price'     => 'premium',
				'_forge_time_estimate'     => 20,
				'_forge_release_id'        => $r1_id,
			]
		);

		// Now update f1 with sub-item IDs
		update_post_meta( $f1_id, '_forge_subitem_ids', [ (string) $s1_id, (string) $s2_id ] );

		// ----------------------------------------------------------------
		// Bugs
		// ----------------------------------------------------------------
		$b1_id = self::insert_post( 'forge_bug', 'Login redirect 404 error',
			'Users are redirected to 404 page after successful login instead of dashboard. Occurs in production environment only.',
			[
				'_forge_linked_feature_id' => $f1_id,
				'_forge_release_id'        => $r1_id,
				'_forge_bug_status'        => 'in-progress',
				'_forge_workflow_stage'    => 'bug-tracking',
				'_forge_priority'          => 'high',
				'_forge_time_estimate'     => 4,
				'_forge_reported_date'     => '2026-05-20',
				'_forge_notes'             => 'Appears to be related to routing configuration in production build.',
				'_forge_image_urls'        => [
					'https://images.unsplash.com/photo-1563206767-5b18f218e8de?w=800',
					'https://images.unsplash.com/photo-1551288049-bebda4e38f71?w=800',
					'https://images.unsplash.com/photo-1460925895917-afdab827c52f?w=800',
				],
				'_forge_urls'              => [
					'https://github.com/example/issue/123',
					'https://errorlogs.example.com/abc123',
				],
			]
		);

		$b2_id = self::insert_post( 'forge_bug', 'Memory leak in data grid',
			'Memory continuously increases when scrolling through large datasets in the grid component.',
			[
				'_forge_linked_feature_id' => $f2_id,
				'_forge_bug_status'        => 'resolved',
				'_forge_workflow_stage'    => 'bug-tracking',
				'_forge_priority'          => 'high',
				'_forge_time_estimate'     => 8,
				'_forge_reported_date'     => '2026-05-10',
				'_forge_notes'             => 'Fixed by properly cleaning up event listeners on component unmount.',
				'_forge_image_urls'        => [
					'https://images.unsplash.com/photo-1551288049-bebda4e38f71?w=800',
					'https://images.unsplash.com/photo-1542831371-29b0f74f9713?w=800',
				],
			]
		);

		$b3_id = self::insert_post( 'forge_bug', 'Export fails for large datasets',
			'CSV export times out when dataset exceeds 10,000 rows.',
			[
				'_forge_linked_feature_id' => $f4_id,
				'_forge_release_id'        => $r2_id,
				'_forge_bug_status'        => 'open',
				'_forge_workflow_stage'    => 'bug-tracking',
				'_forge_priority'          => 'medium',
				'_forge_time_estimate'     => 6,
				'_forge_reported_date'     => '2026-05-25',
			]
		);

		// ----------------------------------------------------------------
		// Feedback
		// ----------------------------------------------------------------
		$fb1_id = self::insert_post( 'forge_feedback', 'Add keyboard shortcuts',
			'Power users want keyboard shortcuts for common actions like creating items, navigating between views, and quick search.',
			[
				'_forge_linked_feature_id' => $f6_id,
				'_forge_status'            => 'open',
				'_forge_workflow_stage'    => 'scoping',
				'_forge_priority'          => 'medium',
				'_forge_time_estimate'     => 12,
				'_forge_reported_date'     => '2026-05-15',
				'_forge_notes'             => '32 votes from users. High demand feature.',
			]
		);

		$fb2_id = self::insert_post( 'forge_feedback', 'Improve mobile responsiveness',
			"Kanban board is hard to use on mobile devices. Cards are too small and drag-and-drop doesn't work well on touch screens.",
			[
				'_forge_linked_feature_id' => $f7_id,
				'_forge_linked_bug_id'     => $b1_id,
				'_forge_status'            => 'in-progress',
				'_forge_workflow_stage'    => 'in-development',
				'_forge_priority'          => 'high',
				'_forge_time_estimate'     => 16,
				'_forge_reported_date'     => '2026-05-05',
				'_forge_urls'              => [ 'https://feedback.example.com/mobile-ux' ],
			]
		);

		$fb3_id = self::insert_post( 'forge_feedback', 'Dark mode color contrast needs improvement',
			'Some text is hard to read in dark mode, particularly status badges and secondary text.',
			[
				'_forge_linked_feature_id' => $f3_id,
				'_forge_release_id'        => $r1_id,
				'_forge_status'            => 'open',
				'_forge_workflow_stage'    => 'up-next',
				'_forge_priority'          => 'low',
				'_forge_time_estimate'     => 4,
				'_forge_reported_date'     => '2026-05-22',
			]
		);

		// Now update releases with linked IDs
		update_post_meta( $r1_id, '_forge_linked_feature_ids',  [ (string) $f1_id, (string) $f2_id, (string) $f3_id ] );
		update_post_meta( $r1_id, '_forge_linked_bug_ids',      [ (string) $b1_id ] );
		update_post_meta( $r1_id, '_forge_linked_feedback_ids', [ (string) $fb3_id ] );

		update_post_meta( $r2_id, '_forge_linked_feature_ids',  [ (string) $f4_id, (string) $f6_id ] );
		update_post_meta( $r2_id, '_forge_linked_bug_ids',      [ (string) $b3_id ] );
		update_post_meta( $r2_id, '_forge_linked_feedback_ids', [] );

		// ----------------------------------------------------------------
		// Company Dates
		// ----------------------------------------------------------------
		self::insert_post( 'forge_company_date', 'Q3 Planning Summit', '', [
			'_forge_date'        => '2026-06-15',
			'_forge_description' => 'All-hands meeting for Q3 strategy.',
			'_forge_tracked'     => '1',
		] );

		self::insert_post( 'forge_company_date', 'Company Holiday', '', [
			'_forge_date'        => '2026-06-19',
			'_forge_description' => 'Juneteenth observance.',
			'_forge_tracked'     => '0',
		] );

		self::insert_post( 'forge_company_date', 'End of Q2', '', [
			'_forge_date'    => '2026-06-30',
			'_forge_tracked' => '1',
		] );

		self::insert_post( 'forge_company_date', 'Summer Retreat', '', [
			'_forge_date'    => '2026-07-20',
			'_forge_tracked' => '0',
		] );

		self::insert_post( 'forge_company_date', 'Product Launch Day', '', [
			'_forge_date'        => '2026-08-10',
			'_forge_description' => 'Marketing campaign go-live.',
			'_forge_tracked'     => '0',
		] );
	}

	private static function insert_post( $post_type, $title, $content, $meta ) {
		$post_id = wp_insert_post( [
			'post_type'    => $post_type,
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => 'publish',
		] );

		if ( is_wp_error( $post_id ) ) return 0;

		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		return $post_id;
	}
}
