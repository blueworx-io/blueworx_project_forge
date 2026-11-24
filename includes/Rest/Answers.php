<?php
/**
 * The heavy answers, kept for a moment.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use WP_REST_Request;
use WP_REST_Response;

/**
 * #702. All work, the board, the standup and the bell each do a lot of work
 * per item, and several open tabs ask for them over and over. Each answer is
 * kept for under a minute, per person and per question, and every kept answer
 * is dropped the moment anything is saved — so nobody is shown work from
 * before their own change.
 *
 * Dropping is one number going up rather than a sweep of keys: every key
 * carries the number it was kept under, so a change makes them all unfindable
 * at once and they expire on their own.
 */
final class Answers {

	/**
	 * How long an answer is kept, in seconds.
	 */
	public const KEEP = 45;

	/**
	 * The option holding the number every kept answer is filed under.
	 */
	private const GENERATION = 'bwx_forge_answers_generation';

	/**
	 * Hooks up the forgetting.
	 */
	public static function register(): void {
		// Any change through the REST API.
		add_filter( 'rest_request_after_callbacks', array( self::class, 'after_request' ), 10, 3 );

		// A change made from a WordPress admin screen, such as connecting a site.
		add_action( 'admin_init', array( self::class, 'after_admin_post' ) );

		// A change made by a scheduled job, such as GitHub moving work.
		add_action( 'bwx_forge_item_moved', array( self::class, 'forget' ) );
		add_action( 'bwx_forge_item_updated', array( self::class, 'forget' ) );
	}

	/**
	 * Forgets every kept answer after a change through Forge's routes.
	 *
	 * @param mixed           $response The response, passed through untouched.
	 * @param mixed           $handler  The route's handler.
	 * @param WP_REST_Request $request  The request.
	 * @return mixed
	 */
	public static function after_request( $response, $handler, $request ) {
		unset( $handler );

		if ( $request instanceof WP_REST_Request && self::is_change( $request->get_method(), $request->get_route() ) ) {
			self::forget();
		}

		return $response;
	}

	/**
	 * Forgets every kept answer after a form is sent from the admin.
	 */
	public static function after_admin_post(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) : 'GET';

		if ( 'POST' === strtoupper( $method ) ) {
			self::forget();
		}
	}

	/**
	 * Whether a request may have changed something. Any write through the
	 * REST API counts, not only Forge's own: a person renamed through
	 * WordPress's users route shows on the standup too. Pure, so it can be
	 * tested.
	 *
	 * @param string $method The HTTP method.
	 * @param string $route  The route, such as /blueworx-forge/v1/standup.
	 * @return bool
	 */
	public static function is_change( string $method, string $route ): bool {
		unset( $route );
		$method = strtoupper( $method );

		return 'GET' !== $method && 'HEAD' !== $method && 'OPTIONS' !== $method;
	}

	/**
	 * Forgets every kept answer.
	 */
	public static function forget(): void {
		update_option( self::GENERATION, self::generation() + 1, false );
	}

	/**
	 * The answer to a request: the one kept, or a fresh one that is then kept.
	 *
	 * Only a whole, successful answer is kept. A refusal or a failure is
	 * worked out again next time.
	 *
	 * @param WP_REST_Request $request The request.
	 * @param callable        $answer  Works the answer out.
	 * @return mixed
	 */
	public static function remember( WP_REST_Request $request, callable $answer ) {
		$key = self::key(
			$request->get_route(),
			(array) $request->get_query_params(),
			get_current_user_id(),
			self::viewer_day(),
			self::generation()
		);

		$kept = get_transient( $key );

		if ( is_array( $kept ) ) {
			return rest_ensure_response( $kept );
		}

		$response = $answer();

		if ( $response instanceof WP_REST_Response && 200 === $response->get_status() && is_array( $response->get_data() ) ) {
			set_transient( $key, $response->get_data(), self::KEEP );
		}

		return $response;
	}

	/**
	 * The key an answer is kept under. Pure, so it can be tested: the same
	 * question from the same person on the same day, since the last change,
	 * is the same key, and anything else is not.
	 *
	 * @param string               $route      The route.
	 * @param array<string, mixed> $params     The query.
	 * @param int                  $user       Who is asking.
	 * @param string               $day        The browser's today, or ''.
	 * @param int                  $generation The number since the last change.
	 * @return string
	 */
	public static function key( string $route, array $params, int $user, string $day, int $generation ): string {
		ksort( $params );

		return 'bwx_forge_ans_' . md5( (string) wp_json_encode( array( $route, $params, $user, $day, $generation ) ) );
	}

	/**
	 * The number every kept answer is filed under.
	 *
	 * @return int
	 */
	public static function generation(): int {
		return (int) get_option( self::GENERATION, 0 );
	}

	/**
	 * The browser's today (#648): the standup and the board both answer for it.
	 *
	 * @return string
	 */
	private static function viewer_day(): string {
		return isset( $_SERVER['HTTP_X_BWX_FORGE_TODAY'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_X_BWX_FORGE_TODAY'] ) ) : '';
	}
}
