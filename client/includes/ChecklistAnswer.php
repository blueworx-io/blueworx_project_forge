<?php
/**
 * Sending a client's answer to an onboarding step.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client;

/**
 * The write half of a client's checklist (#162).
 *
 * Shaped after {@see Submission}, and outside the read-through rule for the
 * same reason: an answer that will be sent later is an answer the client
 * believes was sent now. It either reaches the studio or it does not, and says
 * which. Nothing is queued and nothing is retried.
 *
 * **Four things a client cannot do here, and none of them is missing by
 * politeness.** There is no route on this artifact that creates a step, deletes
 * one, reorders them or approves one — the studio refuses all four at the API,
 * and the client plugin has nothing that could ask. Both halves exist because
 * either alone is a rule somebody eventually writes an exception to.
 *
 * The credential rule (ONB-3) is not repeated here either. It lives on the
 * studio's write path, so a client cannot get round it by talking to the route
 * directly, and what arrives back is a refusal naming the field — which this
 * hands to the screen so it can point at the right box.
 */
final class ChecklistAnswer {

	/**
	 * The largest file this site will try to send.
	 *
	 * The studio refuses anything bigger; checking here as well means somebody
	 * is told before a ten-megabyte upload crosses the wire rather than after.
	 */
	public const MAX_BYTES = 10485760;

	/**
	 * Sends an answer to one step.
	 *
	 * @param string               $step_id The step.
	 * @param array<string, mixed> $values  What the person filled in.
	 * @param string               $moving  Status to move to, or '' to stay put.
	 * @return array{ok: bool, result: string, step: array<string, mixed>, field: string, message: string}
	 */
	public static function send( string $step_id, array $values, string $moving = '' ): array {
		if ( ! Connection::is_configured() ) {
			return self::failed( 'not_connected' );
		}

		if ( '' !== $moving ) {
			$values['status'] = $moving;
		}

		$answer = Connection::post( self::route( $step_id ) . '/answer', $values );

		if ( is_wp_error( $answer ) ) {
			return self::refusal_from( $answer );
		}

		$step = is_array( $answer['step'] ?? null ) ? $answer['step'] : array();

		if ( array() === $step ) {
			return self::failed( 'unreachable' );
		}

		// The checklist this site is showing is now out of date, and the person
		// who pressed the button is about to look at it.
		Cache::forget( Checklist::ROUTE );

		return array(
			'ok'      => true,
			'result'  => 'saved',
			'step'    => $step,
			'field'   => '',
			'message' => '',
		);
	}

	/**
	 * Sends a file to attach to one step.
	 *
	 * The file crosses base64-encoded in the body rather than as a multipart
	 * upload, because this site signs the body it sends (ARCH-6) and a
	 * multipart body it cannot reproduce byte for byte is one it cannot sign.
	 *
	 * @param string               $step_id The step.
	 * @param array<string, mixed> $file    One entry from $_FILES, already
	 *                                      checked for an upload error by the
	 *                                      caller.
	 * @return array{ok: bool, result: string, step: array<string, mixed>, field: string, message: string}
	 */
	public static function attach( string $step_id, array $file ): array {
		if ( ! Connection::is_configured() ) {
			return self::failed( 'not_connected' );
		}

		$path = (string) ( $file['tmp_name'] ?? '' );
		$size = (int) ( $file['size'] ?? 0 );

		if ( '' === $path || $size <= 0 ) {
			return self::failed( 'no_file' );
		}

		if ( $size > self::MAX_BYTES ) {
			// Refused before it crosses the wire. The studio would refuse it
			// too, but only after the whole file had been sent.
			return self::failed( 'too_big' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading PHP's own upload temp file; WP_Filesystem is not initialised on admin-post.
		$contents = file_get_contents( $path );

		if ( ! is_string( $contents ) ) {
			return self::failed( 'no_file' );
		}

		$answer = Connection::post(
			self::route( $step_id ) . '/evidence',
			array(
				'filename'  => (string) ( $file['name'] ?? '' ),
				'mime_type' => (string) ( $file['type'] ?? '' ),
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- How the file crosses a signed connection; see the method comment.
				'contents'  => base64_encode( $contents ),
			)
		);

		if ( is_wp_error( $answer ) ) {
			return self::refusal_from( $answer );
		}

		Cache::forget( Checklist::ROUTE );

		return array(
			'ok'      => true,
			'result'  => 'attached',
			'step'    => array(),
			'field'   => '',
			'message' => '',
		);
	}

	/**
	 * The studio route for one step.
	 *
	 * @param string $step_id The step.
	 * @return string
	 */
	private static function route( string $step_id ): string {
		return Checklist::ROUTE . '/steps/' . rawurlencode( $step_id );
	}

	/**
	 * Turns a studio refusal into something the screen can say.
	 *
	 * A refusal and an outage are different things and get different words. The
	 * studio's own sentence is used as it stands where there is one — it is
	 * written for the person reading it, and rewording it here would give the
	 * same refusal two forms depending on which screen somebody was on.
	 *
	 * @param \WP_Error $error What came back.
	 * @return array{ok: bool, result: string, step: array<string, mixed>, field: string, message: string}
	 */
	private static function refusal_from( \WP_Error $error ): array {
		$data   = (array) $error->get_error_data();
		$studio = is_array( $data['studio_answer'] ?? null ) ? $data['studio_answer'] : array();
		$status = (int) ( $data['status'] ?? 0 );

		if ( $status < 400 || $status >= 500 ) {
			return self::failed( 'unreachable' );
		}

		return array(
			'ok'      => false,
			'result'  => 'refused',
			'step'    => array(),
			'field'   => (string) ( $studio['data']['field'] ?? '' ),
			'message' => (string) ( $studio['message'] ?? '' ),
		);
	}

	/**
	 * A failure, in the shape a caller can read without checking three things.
	 *
	 * @param string $result One of the result codes.
	 * @return array{ok: bool, result: string, step: array<string, mixed>, field: string, message: string}
	 */
	private static function failed( string $result ): array {
		return array(
			'ok'      => false,
			'result'  => $result,
			'step'    => array(),
			'field'   => '',
			'message' => '',
		);
	}
}
