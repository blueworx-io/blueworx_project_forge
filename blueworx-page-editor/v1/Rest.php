<?php
namespace Blueworx\PageEditor\v1;

/**
 * Two routes, because the screen only ever does two things: load the record,
 * and save the record. Both re-check permission — the admin page having
 * rendered is not proof of anything by the time a request arrives.
 */
final class Rest {

	const NS = 'blueworx-page-editor/v1';

	public static function boot(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
	}

	public static function routes(): void {
		foreach ( Editor::all() as $slug => $screen ) {
			register_rest_route( self::NS, '/' . $slug, [
				[
					'methods'             => 'GET',
					'callback'            => static function ( $request ) use ( $slug ) {
						return rest_ensure_response( Editor::load( $slug, (int) $request->get_param( 'id' ) ) );
					},
					'permission_callback' => static function () use ( $screen ) {
						return current_user_can( $screen['capability'] );
					},
					'args'                => [ 'id' => [ 'type' => 'integer', 'default' => 0 ] ],
				],
				[
					'methods'             => 'POST',
					'callback'            => static function ( $request ) use ( $slug ) {
						$values = $request->get_param( 'values' );
						$result = Editor::save( $slug, is_array( $values ) ? $values : [], (int) $request->get_param( 'id' ) );
						return new \WP_REST_Response( $result, $result['ok'] ? 200 : 422 );
					},
					'permission_callback' => static function () use ( $screen ) {
						return current_user_can( $screen['capability'] );
					},
				],
			] );
		}
	}
}
