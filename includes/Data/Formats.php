<?php
/**
 * Saying what type each column is, rather than letting core guess.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Data;

/**
 * `$wpdb->insert()` and `->update()` take an optional format list. Left out, as
 * every WordPress example leaves it out, core falls back to a global map of
 * column *names* to formats declared in `wp-includes/load.php` — which contains
 * `user_id`, `site_id`, `post_id`, `parent`, `count`, `active`, `public` and a
 * dozen others, all as `%d`.
 *
 * A plugin table with a column called `user_id` holding `usr_9f3a…` therefore
 * has that value cast to an integer on every write, stores `0`, and reports
 * success. Nothing errors. The row is simply wrong, and stays wrong until
 * somebody notices a query returning nothing.
 *
 * This was found the hard way twice in one evening (#89, #90). So no repository
 * here writes without a format list, and the list is derived from the values
 * being written rather than from anybody remembering which names are dangerous.
 *
 * The rule is the obvious one: an integer is `%d`, everything else is `%s`.
 * Every id in this plugin is a prefixed string, so "everything else" is the
 * correct default rather than a lucky one.
 */
final class Formats {

	/**
	 * The format list for a row, in the row's own key order.
	 *
	 * `$wpdb` matches formats to columns positionally, so this must be built
	 * from the same array that is being written — not from a table definition
	 * that might list its columns in a different order.
	 *
	 * @param array<string, mixed> $row Column to value.
	 * @return array<int, string>
	 */
	public static function for_row( array $row ): array {
		$formats = array();

		foreach ( $row as $value ) {
			$formats[] = is_int( $value ) ? '%d' : '%s';
		}

		return $formats;
	}
}
