<?php
/**
 * Which Forge item each GitHub issue is.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\GitHub;

use Blueworx\Forge\Data\Schema;

/**
 * #680. An item is one issue at most and an issue one item: the item id is
 * the table's key and the issue id is unique, so the database refuses a
 * second link either way.
 */
final class Links {

	/**
	 * Links an item to an issue.
	 *
	 * @param string $item_id   The item.
	 * @param string $repo      The repository, owner/name.
	 * @param int    $number    The issue's number in that repository.
	 * @param int    $issue_id  GitHub's own id for the issue.
	 * @param string $origin    Where it started: github or forge.
	 * @param string $title     The title as last seen.
	 * @param string $body_hash The body's sha256 as last seen.
	 * @param int    $updated   When GitHub last changed the issue, as last seen.
	 * @return bool False when the item or the issue is linked already.
	 */
	public static function add( string $item_id, string $repo, int $number, int $issue_id, string $origin, string $title, string $body_hash, int $updated = 0 ): bool {
		global $wpdb;

		// The issue id is unique and defaults to 0: a link without a real one
		// would hold that 0 and refuse every other link written the same way.
		if ( '' === $item_id || 0 >= $issue_id ) {
			return false;
		}

		$suppress = $wpdb->suppress_errors();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; the unique keys are the check.
		$inserted = $wpdb->insert(
			Schema::github_links_table(),
			array(
				'item_id'      => $item_id,
				'repo'         => $repo,
				'issue_number' => $number,
				'issue_id'     => $issue_id,
				'origin'       => $origin,
				'title_seen'   => mb_substr( $title, 0, 191 ),
				'body_hash'    => $body_hash,
				'updated_seen' => max( 0, $updated ),
				'created_at'   => bwx_forge_now(),
			),
			array( '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%d' )
		);

		$wpdb->suppress_errors( $suppress );

		return false !== $inserted && 0 < (int) $inserted;
	}

	/**
	 * The link for an item.
	 *
	 * @param string $item_id The item.
	 * @return array<string, mixed>|null
	 */
	public static function for_item( string $item_id ): ?array {
		global $wpdb;

		$table = Schema::github_links_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		return self::hydrate( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE item_id = %s", $item_id ), ARRAY_A ) );
	}

	/**
	 * The link for an issue.
	 *
	 * @param int $issue_id GitHub's id for the issue.
	 * @return array<string, mixed>|null
	 */
	public static function for_issue( int $issue_id ): ?array {
		global $wpdb;

		if ( 0 >= $issue_id ) {
			return null;
		}

		$table = Schema::github_links_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		return self::hydrate( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE issue_id = %d", $issue_id ), ARRAY_A ) );
	}

	/**
	 * Records what was last seen of the issue.
	 *
	 * @param string               $item_id The item.
	 * @param array<string, mixed> $fields  title_seen, body_hash and state_seen; anything else is left alone.
	 */
	public static function seen( string $item_id, array $fields ): void {
		global $wpdb;

		$changes = array_intersect_key( $fields, array_flip( array( 'title_seen', 'body_hash', 'state_seen' ) ) );

		if ( array() === $changes ) {
			return;
		}

		if ( isset( $changes['title_seen'] ) ) {
			$changes['title_seen'] = mb_substr( (string) $changes['title_seen'], 0, 191 );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->update(
			Schema::github_links_table(),
			array_map( 'strval', $changes ),
			array( 'item_id' => $item_id ),
			array_fill( 0, count( $changes ), '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Records when GitHub last changed the issue, as far as Forge has seen.
	 * Only ever moves forward, so an older answer arriving late can't undo
	 * a newer one.
	 *
	 * @param string $item_id The item.
	 * @param int    $at      GitHub's time for the change, in seconds.
	 */
	public static function updated( string $item_id, int $at ): void {
		global $wpdb;

		if ( 0 >= $at ) {
			return;
		}

		$table = Schema::github_links_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; the name cannot be a placeholder.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET updated_seen = %d WHERE item_id = %s AND updated_seen < %d", $at, $item_id, $at ) );
	}

	/**
	 * Whether an issue, as GitHub sends it, shows nothing Forge hasn't seen:
	 * its title, description and state are the ones last seen. A link with
	 * no state seen yet was made with its issue open.
	 *
	 * @param array<string, mixed> $link  The link, as read.
	 * @param array<string, mixed> $issue The issue, as GitHub sends it.
	 * @return bool
	 */
	public static function matches( array $link, array $issue ): bool {
		$state = (string) ( $link['state_seen'] ?? '' );

		return IssueText::title( (string) ( $issue['title'] ?? '' ) ) === IssueText::title( (string) ( $link['title_seen'] ?? '' ) )
			&& hash( 'sha256', (string) ( $issue['body'] ?? '' ) ) === (string) ( $link['body_hash'] ?? '' )
			&& (string) ( $issue['state'] ?? '' ) === ( '' === $state ? 'open' : $state );
	}

	/**
	 * When GitHub last changed an issue, in seconds, from the issue as it
	 * sends it. 0 when it doesn't say.
	 *
	 * @param array<string, mixed> $issue The issue, as GitHub sends it.
	 * @return int
	 */
	public static function time_of( array $issue ): int {
		$at = strtotime( (string) ( $issue['updated_at'] ?? '' ) );

		return false === $at ? 0 : max( 0, $at );
	}

	/**
	 * Where the issue is on GitHub.
	 *
	 * @param array<string, mixed> $link A link, as read.
	 * @return string
	 */
	public static function url( array $link ): string {
		return sprintf( 'https://github.com/%s/issues/%d', (string) $link['repo'], (int) $link['issue_number'] );
	}

	/**
	 * A row as a link.
	 *
	 * @param mixed $row The row, or whatever the read gave back.
	 * @return array<string, mixed>|null
	 */
	private static function hydrate( $row ): ?array {
		if ( ! is_array( $row ) ) {
			return null;
		}

		return array(
			'item_id'      => (string) $row['item_id'],
			'repo'         => (string) $row['repo'],
			'issue_number' => (int) $row['issue_number'],
			'issue_id'     => (int) $row['issue_id'],
			'origin'       => (string) $row['origin'],
			'title_seen'   => (string) $row['title_seen'],
			'body_hash'    => (string) $row['body_hash'],
			'state_seen'   => (string) ( $row['state_seen'] ?? '' ),
			'updated_seen' => (int) ( $row['updated_seen'] ?? 0 ),
			'created_at'   => (int) $row['created_at'],
		);
	}
}
