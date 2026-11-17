<?php
/**
 * Which GitHub repositories belong to which client site.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\GitHub;

use Blueworx\Forge\Data\Schema;
use Blueworx\Forge\Rest\Errors;
use Blueworx\Forge\Tenancy\ClientSites;
use WP_Error;

/**
 * #680. A repository belongs to one client site at most; a site can have
 * several. Only repositories in the connected organisation can be linked.
 */
final class Repos {

	/**
	 * Tidies a repository name: trimmed, lower case, owner/name or nothing.
	 *
	 * @param string $repo What was typed or sent.
	 * @return string
	 */
	public static function normalise( string $repo ): string {
		$repo = strtolower( trim( $repo ) );

		return 1 === preg_match( '#^[a-z0-9_.-]+/[a-z0-9_.-]+$#', $repo ) ? $repo : '';
	}

	/**
	 * The wanted repositories that another site already holds.
	 *
	 * @param array<int, string>    $wanted  Repositories to link.
	 * @param string                $site_id The site they are for.
	 * @param array<string, string> $taken   Repository to site id, as linked now.
	 * @return array<string, string> Repository to the other site's id.
	 */
	public static function conflicts( array $wanted, string $site_id, array $taken ): array {
		$found = array();

		foreach ( $wanted as $repo ) {
			if ( isset( $taken[ $repo ] ) && $taken[ $repo ] !== $site_id ) {
				$found[ $repo ] = $taken[ $repo ];
			}
		}

		return $found;
	}

	/**
	 * Every link, repository to site id.
	 *
	 * @return array<string, string>
	 */
	public static function all(): array {
		global $wpdb;

		$table = Schema::github_repos_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$rows = $wpdb->get_results( "SELECT repo, client_site_id FROM {$table}", ARRAY_A );
		$all  = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$all[ (string) $row['repo'] ] = (string) $row['client_site_id'];
		}

		return $all;
	}

	/**
	 * The repositories linked to a site.
	 *
	 * @param string $site_id The site.
	 * @return array<int, string>
	 */
	public static function for_site( string $site_id ): array {
		$repos = array_keys( array_filter( self::all(), static fn( string $id ): bool => $id === $site_id ) );
		sort( $repos );

		return $repos;
	}

	/**
	 * The site a repository is linked to.
	 *
	 * @param string $repo The repository.
	 * @return string The site id, or an empty string.
	 */
	public static function site_for( string $repo ): string {
		return self::all()[ self::normalise( $repo ) ] ?? '';
	}

	/**
	 * Replaces a site's repositories.
	 *
	 * @param string             $site_id The site.
	 * @param array<int, string> $repos   Repositories to link.
	 * @param int                $by      The WordPress user.
	 * @return true|WP_Error
	 */
	public static function set_for_site( string $site_id, array $repos, int $by ) {
		$wanted = array();

		foreach ( $repos as $repo ) {
			$name = self::normalise( (string) $repo );

			if ( '' === $name ) {
				return Errors::rest( 'github_repo_unknown', __( 'That is not a repository name. Choose from the list.', 'blueworx-forge' ), 400 );
			}

			$wanted[ $name ] = $name;
		}

		$wanted = array_values( $wanted );

		if ( array() !== $wanted ) {
			$known = Client::org_repos( Settings::org() );

			if ( is_wp_error( $known ) ) {
				return Errors::rest( 'github_unreachable', __( 'GitHub could not be reached, so the repositories could not be checked. Try again.', 'blueworx-forge' ), 502 );
			}

			foreach ( $wanted as $repo ) {
				if ( ! in_array( $repo, $known, true ) ) {
					return Errors::rest(
						'github_repo_unknown',
						/* translators: %s: the repository. */
						sprintf( __( '%s is not in the connected GitHub organisation.', 'blueworx-forge' ), $repo ),
						400
					);
				}
			}
		}

		foreach ( self::conflicts( $wanted, $site_id, self::all() ) as $repo => $other ) {
			$site = ClientSites::get( $other );
			$name = is_array( $site ) ? (string) $site['name'] : $other;

			return Errors::rest(
				'github_repo_taken',
				/* translators: 1: the repository, 2: the site it is linked to. */
				sprintf( __( '%1$s is already linked to %2$s. Unlink it there first.', 'blueworx-forge' ), $repo, $name ),
				409,
				array(
					'repo'      => $repo,
					'site_id'   => $other,
					'site_name' => $name,
				)
			);
		}

		global $wpdb;

		$table = Schema::github_repos_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $table, array( 'client_site_id' => $site_id ) );

		foreach ( $wanted as $repo ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$table,
				array(
					'repo'           => $repo,
					'client_site_id' => $site_id,
					'created_at'     => bwx_forge_now(),
					'created_by'     => $by,
				)
			);
		}

		return true;
	}
}
