<?php
/**
 * Talking to SureCart.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Commerce\SureCart;

use WP_Error;

/**
 * The one place that knows SureCart's API. Everything else works with what
 * {@see Sync::normalise()} makes of the answer.
 *
 * The base address is filterable so the test suite can stand up a stub on
 * the same WordPress and point Forge at it; nothing else should ever change
 * it. A token is used here and nowhere else — it arrives already opened
 * from Connections and is never logged, returned or stored by this class.
 */
final class Client {

	/**
	 * Where SureCart lives.
	 */
	public const BASE = 'https://api.surecart.com/v1';

	/**
	 * Most pages read in one refresh. A hundred a page; two thousand
	 * subscriptions is more than any store this is for.
	 */
	private const MOST_PAGES = 20;

	/**
	 * Every active subscription in a store, with its customer and price.
	 *
	 * @param string $token The store's API token.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public static function subscriptions( string $token ) {
		$found = array();

		for ( $page = 1; $page <= self::MOST_PAGES; $page++ ) {
			$answer = self::page( $token, $page );

			if ( is_wp_error( $answer ) ) {
				return $answer;
			}

			foreach ( (array) ( $answer['data'] ?? array() ) as $subscription ) {
				if ( is_array( $subscription ) ) {
					$found[] = $subscription;
				}
			}

			$total = (int) ( $answer['pagination']['count'] ?? 0 );

			if ( count( $found ) >= $total || array() === (array) ( $answer['data'] ?? array() ) ) {
				break;
			}
		}

		return $found;
	}

	/**
	 * Whether a token works, and how many active subscriptions it can see.
	 *
	 * @param string $token The store's API token.
	 * @return int|WP_Error
	 */
	public static function test( string $token ) {
		$answer = self::page( $token, 1 );

		return is_wp_error( $answer ) ? $answer : (int) ( $answer['pagination']['count'] ?? count( (array) ( $answer['data'] ?? array() ) ) );
	}

	/**
	 * One page of active subscriptions.
	 *
	 * @param string $token The store's API token.
	 * @param int    $page  Page number, from 1.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function page( string $token, int $page ) {
		// Written by hand rather than through add_query_arg, which cannot
		// repeat a key, and SureCart reads its lists as repeated keys.
		$url = self::base() . '/subscriptions?status%5B%5D=active&expand%5B%5D=customer&expand%5B%5D=price&expand%5B%5D=price.product&limit=100&page=' . $page;

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'bwx_forge_surecart_unreachable', $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 401 === $status || 403 === $status ) {
			return new WP_Error( 'bwx_forge_surecart_refused', __( 'SureCart refused that token.', 'blueworx-forge' ) );
		}

		if ( $status < 200 || $status >= 300 || ! is_array( $body ) ) {
			return new WP_Error(
				'bwx_forge_surecart_failed',
				/* translators: %d: HTTP status code */
				sprintf( __( 'SureCart answered with status %d.', 'blueworx-forge' ), $status )
			);
		}

		return $body;
	}

	/**
	 * The API's base address.
	 *
	 * @return string
	 */
	private static function base(): string {
		return rtrim( (string) apply_filters( 'bwx_forge_surecart_base_url', self::BASE ), '/' );
	}
}
