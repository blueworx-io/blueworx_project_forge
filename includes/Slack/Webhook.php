<?php
/**
 * Posting to a Slack incoming webhook.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Slack;

use WP_Error;

/**
 * One POST, one answer. Slack says "ok" with a 200 when it took the message
 * and something short with a 4xx when it did not; either way the answer is
 * what the register records.
 */
final class Webhook {

	/**
	 * Sends a message.
	 *
	 * @param string               $url     The webhook.
	 * @param array<string, mixed> $message `{ text, blocks }`.
	 * @return true|WP_Error
	 */
	public static function send( string $url, array $message ) {
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 10,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => (string) wp_json_encode( $message ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'bwx_forge_slack_unreachable', $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( $status >= 200 && $status < 300 ) {
			return true;
		}

		return new WP_Error(
			'bwx_forge_slack_refused',
			/* translators: 1: HTTP status code, 2: Slack's answer */
			sprintf( __( 'Slack answered %1$d: %2$s', 'blueworx-forge' ), $status, mb_substr( trim( (string) wp_remote_retrieve_body( $response ) ), 0, 120 ) )
		);
	}
}
