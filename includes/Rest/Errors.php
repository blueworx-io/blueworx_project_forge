<?php
/**
 * The one error envelope every route answers with.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use WP_Error;
use WP_REST_Response;

/**
 * Two shapes, and only two.
 *
 * An ordinary refusal is a WP_Error, which WordPress renders in its own
 * `{ code, message, data: { status } }` shape — the same shape core uses, so a
 * client parses one thing rather than two. The code always carries the plugin's
 * prefix, so our refusals are distinguishable from core's.
 *
 * A gate failure is not an error in that sense: the request was well-formed and
 * the caller was allowed to make it, the item simply is not ready to move. It
 * has its own documented body, fixed by workflow-state-machine.md.
 */
final class Errors {

	/**
	 * Prefix on every error code the plugin emits.
	 */
	public const CODE_PREFIX = 'bwx_forge_';

	/**
	 * Status for a write made against a version that is no longer current.
	 */
	public const STATUS_CONFLICT = 409;

	/**
	 * Status for a transition the item is not yet eligible for.
	 */
	public const STATUS_GATE_FAILURE = 409;

	/**
	 * Builds an error in the shared envelope.
	 *
	 * @param string               $code    Error code, without the plugin prefix.
	 * @param string               $message Human-readable message.
	 * @param int                  $status  HTTP status.
	 * @param array<string, mixed> $data    Anything the client needs to recover.
	 * @return WP_Error
	 */
	public static function rest( string $code, string $message, int $status, array $data = array() ): WP_Error {
		return new WP_Error(
			self::CODE_PREFIX . $code,
			$message,
			array_merge( array( 'status' => $status ), $data )
		);
	}

	/**
	 * Builds the response for a transition the item is not eligible for.
	 *
	 * The stage returned is the item's current one, unchanged: a failed
	 * transition changes nothing. Every unmet requirement is returned, never
	 * just the first — a person told about one missing thing at a time fixes it,
	 * resubmits, and is refused again for the next.
	 *
	 * @param string                    $item_id   The item that did not move.
	 * @param string                    $stage     Its stage, unchanged.
	 * @param string                    $attempted The stage that was attempted.
	 * @param array<int, array<string>> $unmet     Every unmet requirement.
	 * @param array<int, array<string>> $checks    Every system result, where the
	 *                                             gate has any.
	 * @return WP_REST_Response
	 */
	public static function gate_failure( string $item_id, string $stage, string $attempted, array $unmet, array $checks = array() ): WP_REST_Response {
		$body = array(
			'ok'        => false,
			'item_id'   => $item_id,
			'stage'     => $stage,
			'attempted' => $attempted,
			'unmet'     => array_values( $unmet ),
		);

		/*
		 * The system results, where the gate has any. G-UP-NEXT #8 and #9 are
		 * evaluated independently and **both** are always reported, so a pass on
		 * the capacity check and a failure on support hours shows as two results
		 * rather than as one refusal a person has to guess the cause of.
		 */
		if ( array() !== $checks ) {
			$body['checks'] = array_values( $checks );
		}

		return new WP_REST_Response( $body, self::STATUS_GATE_FAILURE );
	}

	/**
	 * Turns the transition service's gate refusal into that body.
	 *
	 * The service answers in WP_Error because that is how it answers everything;
	 * only the REST layer knows a gate failure has a body of its own. Keeping
	 * the translation here means one place decides what a gate failure looks
	 * like on the wire.
	 *
	 * @param WP_Error $error A Work\Transition::GATE_FAILURE error.
	 * @return WP_REST_Response
	 */
	public static function from_gate_failure( WP_Error $error ): WP_REST_Response {
		$data = (array) $error->get_error_data();

		return self::gate_failure(
			(string) ( $data['item_id'] ?? '' ),
			(string) ( $data['stage'] ?? '' ),
			(string) ( $data['attempted'] ?? '' ),
			(array) ( $data['unmet'] ?? array() ),
			(array) ( $data['checks'] ?? array() )
		);
	}
}
