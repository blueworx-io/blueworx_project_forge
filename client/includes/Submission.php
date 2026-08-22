<?php
/**
 * Sending a submission to the studio.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client;

/**
 * The write half of the client artifact (#129).
 *
 * Deliberately not part of ReadThrough, and deliberately not cached. Every
 * other record here is read through and degrades to what the site last saw,
 * because showing something old is better than showing nothing. A submission
 * has no such fallback: there is no honest way to "queue" one, because a
 * request that will be sent later is a request the client believes was sent
 * now.
 *
 * So this either reaches the studio or it does not, and says which. Nothing is
 * retried, because a submission sent twice gives the studio two of the same
 * question to triage and the client no way to tell which answer belongs to
 * which.
 */
final class Submission {

	/**
	 * The studio route this posts to.
	 */
	public const ROUTE = '/client/submissions';

	/**
	 * Sends one submission.
	 *
	 * @param array<string, mixed> $values What the person filled in.
	 * @return array{ok: bool, result: string, submission: array<string, mixed>, fields: array<string, string>}
	 */
	public static function send( array $values ): array {
		if ( ! Connection::is_configured() ) {
			return self::failed( 'not_connected' );
		}

		$answer = Connection::post( self::ROUTE, $values );

		if ( is_wp_error( $answer ) ) {
			$data = (array) $answer->get_error_data();

			// A refusal on the fields is the studio telling the person what to
			// fix, which is a different thing from the studio being away. The
			// two get different words on screen, so nobody retypes a form
			// because of a full stop.
			$fields = $data['studio_answer']['data']['fields'] ?? null;

			if ( is_array( $fields ) ) {
				return self::failed( 'invalid', array_map( 'strval', $fields ) );
			}

			return self::failed( 'unreachable' );
		}

		$submission = is_array( $answer['submission'] ?? null ) ? $answer['submission'] : array();

		if ( array() === $submission ) {
			return self::failed( 'unreachable' );
		}

		return array(
			'ok'         => true,
			'result'     => 'sent',
			'submission' => $submission,
			'fields'     => array(),
		);
	}

	/**
	 * A failure, in the shape a caller can read without checking three things.
	 *
	 * @param string                $result One of the result codes.
	 * @param array<string, string> $fields Per-field refusals, where there are any.
	 * @return array{ok: bool, result: string, submission: array<string, mixed>, fields: array<string, string>}
	 */
	private static function failed( string $result, array $fields = array() ): array {
		return array(
			'ok'         => false,
			'result'     => $result,
			'submission' => array(),
			'fields'     => $fields,
		);
	}
}
