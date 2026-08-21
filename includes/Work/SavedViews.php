<?php
/**
 * The views a person saves for themselves.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Work;

use Blueworx\Forge\Tenancy\Ids;

/**
 * #123's second half: a person's own shortcuts to a way of looking at the work.
 *
 * Held against the WordPress account rather than against a site or a client,
 * because that is what a saved view is — "how I like to look at things" travels
 * with somebody across every client they work with. It also means one person
 * cannot read or remove another's without that being a check somebody has to
 * remember: the list is simply not theirs to read.
 *
 * **A saved view changes what is shown and never what is allowed.** Everything
 * stored goes through Work\Filters, which builds the record key by key rather
 * than by removing what is not wanted — so a view cannot carry a capability, a
 * role, or somebody else's client, and cannot start doing so because a field
 * was added somewhere and nobody updated a list of things to strip.
 */
final class SavedViews {

	/**
	 * Id prefix for a saved view.
	 */
	public const PREFIX = 'svw';

	/**
	 * Where a person's views live.
	 */
	public const META_KEY = 'bwx_forge_saved_views';

	/**
	 * How many one person may keep.
	 *
	 * There is a limit because this lands in a single user meta row, and an
	 * unbounded list is an unbounded row. Generous enough that nobody meets it
	 * by working normally, and small enough that nothing here has to paginate.
	 */
	public const MOST = 50;

	/**
	 * One person's saved views.
	 *
	 * @param int $wp_user_id WordPress user id.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_user( int $wp_user_id ): array {
		if ( $wp_user_id <= 0 ) {
			return array();
		}

		$stored = get_user_meta( $wp_user_id, self::META_KEY, true );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$views = array();

		/*
		 * Read back through the same rules it was written with. A row that was
		 * hand-edited, or written by an older version, is brought back to what a
		 * saved view is allowed to be rather than trusted because it is stored.
		 */
		foreach ( $stored as $candidate ) {
			$view = self::clean( (array) $candidate );

			if ( null !== $view ) {
				$views[] = $view;
			}
		}

		return $views;
	}

	/**
	 * Saves one.
	 *
	 * @param int                  $wp_user_id WordPress user id.
	 * @param array<string, mixed> $input      Whatever arrived.
	 * @return array<string, mixed>|null Null when it could not be saved.
	 */
	public static function save( int $wp_user_id, array $input ): ?array {
		// A view held against user zero would be everybody's.
		if ( $wp_user_id <= 0 ) {
			return null;
		}

		$view = Filters::view_for_storage( $input );

		if ( null === $view ) {
			return null;
		}

		$views = self::for_user( $wp_user_id );

		if ( count( $views ) >= self::MOST ) {
			return null;
		}

		$view = array_merge( array( 'id' => Ids::create( self::PREFIX ) ), $view );

		$views[] = $view;

		update_user_meta( $wp_user_id, self::META_KEY, $views );

		return $view;
	}

	/**
	 * Removes one.
	 *
	 * @param int    $wp_user_id WordPress user id.
	 * @param string $view_id    Which view.
	 * @return bool Whether there was one to remove.
	 */
	public static function remove( int $wp_user_id, string $view_id ): bool {
		$views = self::for_user( $wp_user_id );
		$kept  = array();
		$found = false;

		foreach ( $views as $view ) {
			if ( (string) $view['id'] === $view_id ) {
				$found = true;
				continue;
			}

			$kept[] = $view;
		}

		if ( ! $found ) {
			return false;
		}

		update_user_meta( $wp_user_id, self::META_KEY, $kept );

		return true;
	}

	/**
	 * A stored row, brought back to what a saved view is allowed to be.
	 *
	 * @param array<string, mixed> $stored The row as stored.
	 * @return array<string, mixed>|null
	 */
	private static function clean( array $stored ): ?array {
		$view = Filters::view_for_storage( $stored );
		$id   = (string) ( $stored['id'] ?? '' );

		if ( null === $view || '' === $id ) {
			return null;
		}

		return array_merge( array( 'id' => $id ), $view );
	}
}
