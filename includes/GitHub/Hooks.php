<?php
/**
 * The webhook Forge keeps on each linked repo.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\GitHub;

use WP_Error;

/**
 * #680. The GitHub account is a person's, not an organisation's, so there is
 * no one webhook for every repo. Forge adds its own to each repo it links,
 * pointing at its webhook route and signed with the secret it made, and takes
 * it off again when the repo is unlinked. A repo that already has Forge's
 * hook has it updated rather than given a second one.
 */
final class Hooks {

	/**
	 * Where GitHub sends its messages.
	 *
	 * @return string
	 */
	public static function url(): string {
		return rest_url( 'blueworx-forge/v1/github/webhook' );
	}

	/**
	 * Makes sure a repo has Forge's hook, signed with this secret.
	 *
	 * @param string $repo   The repository, owner/name, normalised.
	 * @param string $secret The secret GitHub signs with.
	 * @return int|WP_Error The hook's id on GitHub.
	 */
	public static function put( string $repo, string $secret ) {
		$hooks = Client::request( 'GET', '/repos/' . $repo . '/hooks?per_page=100' );

		if ( is_wp_error( $hooks ) ) {
			return $hooks;
		}

		$body = array(
			'active' => true,
			'events' => array( 'issues' ),
			'config' => array(
				'url'          => self::url(),
				'content_type' => 'json',
				'secret'       => $secret,
				'insecure_ssl' => '0',
			),
		);

		foreach ( $hooks as $hook ) {
			if ( is_array( $hook ) && self::url() === (string) ( $hook['config']['url'] ?? '' ) && 0 < (int) ( $hook['id'] ?? 0 ) ) {
				$updated = Client::request( 'PATCH', '/repos/' . $repo . '/hooks/' . (int) $hook['id'], $body );

				return is_wp_error( $updated ) ? $updated : (int) $hook['id'];
			}
		}

		$body['name'] = 'web';
		$made         = Client::request( 'POST', '/repos/' . $repo . '/hooks', $body );

		if ( is_wp_error( $made ) ) {
			return $made;
		}

		$id = (int) ( $made['id'] ?? 0 );

		return 0 < $id ? $id : new WP_Error( Client::REFUSED, __( 'GitHub did not say which webhook it made.', 'blueworx-forge' ) );
	}

	/**
	 * Takes Forge's hook off a repo. One already gone counts as taken off.
	 *
	 * @param string $repo The repository, owner/name, normalised.
	 * @param int    $id   The hook's id on GitHub; 0 when none was recorded.
	 * @return true|WP_Error
	 */
	public static function remove( string $repo, int $id ) {
		if ( 0 >= $id ) {
			return true;
		}

		$gone = Client::request( 'DELETE', '/repos/' . $repo . '/hooks/' . $id );

		if ( is_wp_error( $gone ) && 404 !== (int) ( $gone->get_error_data()['github_status'] ?? 0 ) ) {
			return $gone;
		}

		return true;
	}
}
