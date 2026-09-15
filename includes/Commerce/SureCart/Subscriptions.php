<?php
/**
 * Forge's copy of what SureCart said.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Commerce\SureCart;

use Blueworx\Forge\Data\Formats;
use Blueworx\Forge\Data\Schema;

/**
 * A copy, never the record: SureCart owns the subscriptions, and this is
 * what it said the last time Forge asked, kept so the Subscriptions screen
 * can draw without a round trip and so a store that stops answering still
 * shows what it last knew. Each refresh replaces a store's rows wholesale.
 */
final class Subscriptions {

	/**
	 * Replaces a store's rows with what it says now.
	 *
	 * @param string                           $connection_id The connection.
	 * @param array<int, array<string, mixed>> $rows          Normalised subscriptions.
	 */
	public static function replace( string $connection_id, array $rows ): void {
		global $wpdb;

		$table = Schema::subscriptions_table();
		$now   = bwx_forge_now();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; a replace is a transaction.
		$wpdb->query( 'START TRANSACTION' );
		$wpdb->delete( $table, array( 'connection_id' => $connection_id ), array( '%s' ) );

		foreach ( $rows as $row ) {
			$record = array(
				'id'             => self::key( $connection_id, (string) $row['id'] ),
				'connection_id'  => $connection_id,
				'external_id'    => (string) $row['id'],
				'customer_name'  => (string) $row['customer_name'],
				'customer_email' => (string) $row['customer_email'],
				'product_name'   => (string) $row['product_name'],
				'amount'         => (int) $row['amount'],
				'currency'       => (string) $row['currency'],
				'billing'        => (string) $row['interval'],
				'status'         => (string) $row['status'],
				'renews_on'      => (string) $row['renews_on'],
				'fetched_at'     => $now,
			);

			$wpdb->replace( $table, $record, Formats::for_row( $record ) );
		}

		$wpdb->query( 'COMMIT' );
		// phpcs:enable
	}

	/**
	 * Every subscription Forge knows, soonest renewal first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		global $wpdb;

		$table = Schema::subscriptions_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY renews_on ASC, customer_name ASC", ARRAY_A );

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Forge's own id for a store's subscription.
	 *
	 * SureCart's ids are UUIDs, longer than the 32 characters every Forge
	 * table keys on, so the row's key is derived from store and id together.
	 *
	 * @param string $connection_id The store.
	 * @param string $external_id   SureCart's id.
	 * @return string
	 */
	public static function key( string $connection_id, string $external_id ): string {
		return 'sub_' . substr( sha1( $connection_id . ':' . $external_id ), 0, 28 );
	}

	/**
	 * A row as the rest of the plugin reads it.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		return array(
			'id'             => (string) $row['id'],
			'connection_id'  => (string) $row['connection_id'],
			'external_id'    => (string) $row['external_id'],
			'customer_name'  => (string) $row['customer_name'],
			'customer_email' => (string) $row['customer_email'],
			'product_name'   => (string) $row['product_name'],
			'amount'         => (int) $row['amount'],
			'currency'       => (string) $row['currency'],
			'interval'       => (string) $row['billing'],
			'status'         => (string) $row['status'],
			'renews_on'      => (string) $row['renews_on'],
			'fetched_at'     => (int) $row['fetched_at'],
		);
	}
}
