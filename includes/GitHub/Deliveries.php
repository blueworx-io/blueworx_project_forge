<?php
/**
 * The messages GitHub has sent, kept so none is handled twice.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\GitHub;

use Blueworx\Forge\Data\Schema;

/**
 * #680. GitHub sends a message again when it thinks one was missed, and every
 * one carries its own delivery id. The id is the primary key, so the table
 * lets the first copy through and refuses the rest.
 */
final class Deliveries {

	/**
	 * Claims a delivery.
	 *
	 * @param string $id     GitHub's delivery id.
	 * @param string $event  The event, such as issues.
	 * @param string $action The action, such as opened.
	 * @return bool False when the id has been seen before.
	 */
	public static function claim( string $id, string $event, string $action ): bool {
		global $wpdb;

		if ( '' === $id ) {
			return false;
		}

		$suppress = $wpdb->suppress_errors();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; the duplicate-key refusal is the claim.
		$inserted = $wpdb->insert(
			Schema::github_deliveries_table(),
			array(
				'id'          => substr( $id, 0, 64 ),
				'event'       => substr( $event, 0, 32 ),
				'action'      => substr( $action, 0, 32 ),
				'outcome'     => '',
				'received_at' => bwx_forge_now(),
			),
			array( '%s', '%s', '%s', '%s', '%d' )
		);

		$wpdb->suppress_errors( $suppress );

		return false !== $inserted && 0 < (int) $inserted;
	}

	/**
	 * Records what became of a delivery.
	 *
	 * @param string $id      GitHub's delivery id.
	 * @param string $outcome What was done with it.
	 */
	public static function settle( string $id, string $outcome ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->update(
			Schema::github_deliveries_table(),
			array( 'outcome' => substr( $outcome, 0, 20 ) ),
			array( 'id' => substr( $id, 0, 64 ) ),
			array( '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Forgets deliveries received before a time.
	 *
	 * @param int $older_than Unix time.
	 * @return int How many were removed.
	 */
	public static function prune( int $older_than ): int {
		global $wpdb;

		$table = Schema::github_deliveries_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; the name cannot be a placeholder.
		$removed = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE received_at < %d", $older_than ) );

		return false === $removed ? 0 : (int) $removed;
	}
}
