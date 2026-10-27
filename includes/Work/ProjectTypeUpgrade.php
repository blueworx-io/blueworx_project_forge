<?php
/**
 * Projects and releases stored as a bug, task or feedback item, put right.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Work;

use Blueworx\Forge\Data\Schema;

/**
 * #665. Once: a project or release stored with a type that never holds work —
 * the New work form's Task, left as it was — becomes the Feature it always is
 * now, so work can go beneath it. Anything lower keeps its type.
 */
final class ProjectTypeUpgrade {

	/**
	 * Puts every such project and release right. A second run finds nothing.
	 */
	public static function run(): void {
		global $wpdb;

		$items = Schema::work_items_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; the name cannot be a placeholder.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$items} SET work_type = %s WHERE level IN (%s, %s) AND work_type <> %s",
				Types::FEATURE,
				Levels::PROJECT,
				Levels::MILESTONE,
				Types::FEATURE
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
