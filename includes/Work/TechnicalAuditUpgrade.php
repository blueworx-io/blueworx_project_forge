<?php
/**
 * The one-off step that moves work out of the stage that no longer exists.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Work;

use Blueworx\Forge\Data\Schema;

/**
 * #546. Technical Audit is gone: Documentation Period moves straight on to
 * Up Next. Work sitting in Technical Audit when the update installs is put in
 * Up Next once, with a line in its history saying so; work blocked out of
 * Technical Audit will come back to Up Next instead.
 *
 * Run once, from the schema step that retires the stage. It only touches rows
 * still naming the old stage, so running it again changes nothing.
 */
final class TechnicalAuditUpgrade {

	/**
	 * The stage that went, and the one its work moves to.
	 */
	public const GONE = 'technical-audit';
	public const TO   = 'up-next';

	/**
	 * Moves every task still in Technical Audit to Up Next, and points blocked
	 * work that would have gone back there at Up Next instead.
	 */
	public static function run(): void {
		global $wpdb;

		$items = Schema::work_items_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; the name cannot be a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, stage, client_site_id, cycle, review_attempt FROM {$items} WHERE stage = %s", self::GONE ),
			ARRAY_A
		);

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			// Only what is actually still there: a second run finds nothing.
			if ( self::GONE !== (string) ( $row['stage'] ?? '' ) ) {
				continue;
			}

			$wpdb->query( $wpdb->prepare( "UPDATE {$items} SET stage = %s WHERE id = %s AND stage = %s", self::TO, (string) $row['id'], self::GONE ) );

			Events::append( self::entry( $row ) );
		}

		$wpdb->query( $wpdb->prepare( "UPDATE {$items} SET prior_stage = %s WHERE prior_stage = %s", self::TO, self::GONE ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * The history line one moved task gets. Pure, so a test can read it.
	 *
	 * @param array<string, mixed> $row id, client_site_id, cycle, review_attempt.
	 * @return array<string, mixed>
	 */
	public static function entry( array $row ): array {
		return array(
			'item_id'        => (string) $row['id'],
			'client_site_id' => (string) ( $row['client_site_id'] ?? '' ),
			'action'         => Events::MOVED,
			'from_stage'     => self::GONE,
			'to_stage'       => self::TO,
			'gate'           => '',
			'reason'         => 'The Technical Audit stage was removed, so this moved on to Up Next.',
			'cycle'          => (int) ( $row['cycle'] ?? 1 ),
			'attempt'        => (int) ( $row['review_attempt'] ?? 1 ),
			'actor'          => 0,
		);
	}
}
