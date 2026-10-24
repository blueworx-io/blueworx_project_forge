<?php
/**
 * Who has connected their Google Calendar.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Google;

use Blueworx\Forge\Data\Formats;
use Blueworx\Forge\Data\Schema;
use Blueworx\Forge\Tenancy\Secrets;

/**
 * #606. One row per connected person, keyed by their Forge id. The tokens
 * are sealed together in `secret` and opened only by the sync; every read
 * that could reach a screen leaves the column out.
 */
final class Connections {

	/**
	 * The columns that are safe to read anywhere.
	 */
	private const PUBLIC_COLUMNS = 'id, wp_user_id, email, full_synced_at, synced_at, last_error, connected_at';

	/**
	 * Connects a person, or reconnects them from scratch.
	 *
	 * A reconnection drops the old sync token, so the next sync reads the
	 * whole window again.
	 *
	 * @param string               $user_id    Forge person id.
	 * @param int                  $wp_user_id Their WordPress account.
	 * @param string               $email      The Google account's address.
	 * @param array<string, mixed> $tokens     From {@see Client::exchange()}.
	 */
	public static function connect( string $user_id, int $wp_user_id, string $email, array $tokens ): void {
		global $wpdb;

		$now = bwx_forge_now();
		$row = array(
			'id'             => $user_id,
			'wp_user_id'     => $wp_user_id,
			'email'          => mb_substr( $email, 0, 191 ),
			'secret'         => Secrets::seal( (string) wp_json_encode( $tokens ) ),
			'sync_token'     => '',
			'full_synced_at' => 0,
			'synced_at'      => 0,
			'last_error'     => '',
			'connected_at'   => $now,
			'updated_at'     => $now,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->replace( Schema::google_connections_table(), $row, Formats::for_row( $row ) );
	}

	/**
	 * One person's connection, without its tokens.
	 *
	 * @param string $user_id Forge person id.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $user_id ): ?array {
		global $wpdb;

		$table   = Schema::google_connections_table();
		$columns = self::PUBLIC_COLUMNS;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and column names cannot be placeholders.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT {$columns} FROM {$table} WHERE id = %s", $user_id ), ARRAY_A );

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Everyone connected, without tokens.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		global $wpdb;

		$table   = Schema::google_connections_table();
		$columns = self::PUBLIC_COLUMNS;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and column names cannot be placeholders.
		$rows = $wpdb->get_results( "SELECT {$columns} FROM {$table} ORDER BY connected_at ASC", ARRAY_A );

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * A person's tokens, opened, for the sync alone.
	 *
	 * @param string $user_id Forge person id.
	 * @return array{access_token: string, refresh_token: string, expires_at: int}|null
	 */
	public static function tokens( string $user_id ): ?array {
		global $wpdb;

		$table = Schema::google_connections_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$sealed = $wpdb->get_var( $wpdb->prepare( "SELECT secret FROM {$table} WHERE id = %s", $user_id ) );
		$plain  = is_string( $sealed ) ? Secrets::open( $sealed ) : null;
		$tokens = null === $plain ? null : json_decode( $plain, true );

		if ( ! is_array( $tokens ) || '' === (string) ( $tokens['refresh_token'] ?? '' ) ) {
			return null;
		}

		return array(
			'access_token'  => (string) ( $tokens['access_token'] ?? '' ),
			'refresh_token' => (string) $tokens['refresh_token'],
			'expires_at'    => (int) ( $tokens['expires_at'] ?? 0 ),
		);
	}

	/**
	 * Keeps a refreshed access token.
	 *
	 * @param string               $user_id Forge person id.
	 * @param array<string, mixed> $tokens  From {@see Client::refresh()}.
	 */
	public static function store_tokens( string $user_id, array $tokens ): void {
		self::change(
			$user_id,
			array( 'secret' => Secrets::seal( (string) wp_json_encode( $tokens ) ) )
		);
	}

	/**
	 * The bookmark for the next incremental read.
	 *
	 * @param string $user_id Forge person id.
	 * @return string '' when the next read must be a full one.
	 */
	public static function sync_token( string $user_id ): string {
		global $wpdb;

		$table = Schema::google_connections_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT sync_token FROM {$table} WHERE id = %s", $user_id ) );
	}

	/**
	 * Records a finished sync.
	 *
	 * @param string $user_id    Forge person id.
	 * @param string $sync_token Google's next bookmark.
	 * @param bool   $full       Whether it was a full read.
	 */
	public static function synced( string $user_id, string $sync_token, bool $full ): void {
		$changes = array(
			'sync_token' => $sync_token,
			'synced_at'  => bwx_forge_now(),
			'last_error' => '',
		);

		if ( $full ) {
			$changes['full_synced_at'] = bwx_forge_now();
		}

		self::change( $user_id, $changes );
	}

	/**
	 * Forgets the bookmark, so the next sync is a full read.
	 *
	 * @param string $user_id Forge person id.
	 */
	public static function forget_sync_token( string $user_id ): void {
		self::change( $user_id, array( 'sync_token' => '' ) );
	}

	/**
	 * Records what went wrong, and when it was tried.
	 *
	 * @param string $user_id Forge person id.
	 * @param string $error   In words a person can act on.
	 */
	public static function mark( string $user_id, string $error ): void {
		self::change(
			$user_id,
			array(
				'last_error' => mb_substr( $error, 0, 191 ),
				'synced_at'  => bwx_forge_now(),
			)
		);
	}

	/**
	 * Disconnects a person. Their tokens are forgotten outright.
	 *
	 * @param string $user_id Forge person id.
	 */
	public static function disconnect( string $user_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->delete( Schema::google_connections_table(), array( 'id' => $user_id ), array( '%s' ) );
	}

	/**
	 * Writes some columns.
	 *
	 * @param string               $user_id Forge person id.
	 * @param array<string, mixed> $changes Columns.
	 */
	private static function change( string $user_id, array $changes ): void {
		global $wpdb;

		$changes['updated_at'] = bwx_forge_now();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->update( Schema::google_connections_table(), $changes, array( 'id' => $user_id ), Formats::for_row( $changes ), array( '%s' ) );
	}

	/**
	 * A row as the rest of the plugin reads it.
	 *
	 * @param array<string, mixed> $row Database row, without the secret.
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		return array(
			'user_id'        => (string) $row['id'],
			'wp_user_id'     => (int) $row['wp_user_id'],
			'email'          => (string) $row['email'],
			'full_synced_at' => (int) $row['full_synced_at'],
			'synced_at'      => (int) $row['synced_at'],
			'last_error'     => (string) $row['last_error'],
			'connected_at'   => (int) $row['connected_at'],
		);
	}
}
