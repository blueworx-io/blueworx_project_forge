<?php
/**
 * Who is connected to Slack, and how.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Slack;

use Blueworx\Forge\Data\Formats;
use Blueworx\Forge\Data\Schema;
use Blueworx\Forge\Tenancy\Secrets;

/**
 * One row per person who has pasted a webhook. The webhook is theirs — it
 * posts into a channel or DM they chose — so it is sealed at rest and opened
 * only at the moment of sending, and nothing ever hands it back to a
 * browser, theirs included. What they get is decided by four preferences,
 * all on until they say otherwise.
 */
final class People {

	/**
	 * Where a webhook has to point. Anything else is refused at the door.
	 */
	public const HOST = 'https://hooks.slack.com/';

	/**
	 * The preferences, all on by default.
	 */
	public const PREFS = array( 'assigned', 'ready', 'comment', 'morning' );

	/**
	 * Cleans a preferences object: every known key, true unless said false.
	 *
	 * @param array<string, mixed> $input   Raw input.
	 * @param array<string, bool>  $current What is set now, for a partial change.
	 * @return array<string, bool>
	 */
	public static function prefs_from( array $input, array $current = array() ): array {
		$prefs = array();

		foreach ( self::PREFS as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$prefs[ $key ] = (bool) $input[ $key ];
			} else {
				$prefs[ $key ] = (bool) ( $current[ $key ] ?? true );
			}
		}

		return $prefs;
	}

	/**
	 * Whether a URL is a Slack incoming webhook at all.
	 *
	 * @param string $url The URL.
	 * @return bool
	 */
	public static function acceptable( string $url ): bool {
		$hosts = (array) apply_filters( 'bwx_forge_slack_webhook_hosts', array( self::HOST ) );

		foreach ( $hosts as $host ) {
			if ( 0 === strpos( $url, (string) $host ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Connects a person, or replaces their webhook.
	 *
	 * @param string $user_id Forge person id.
	 * @param string $url     The webhook, plain.
	 */
	public static function connect( string $user_id, string $url ): void {
		global $wpdb;

		$current = self::get( $user_id );
		$now     = bwx_forge_now();
		$row     = array(
			'id'           => $user_id,
			'secret'       => Secrets::seal( $url ),
			'prefs'        => (string) wp_json_encode( self::prefs_from( array(), null === $current ? array() : $current['prefs'] ) ),
			'connected_at' => $now,
			'last_ok_at'   => 0,
			'last_error'   => '',
			'updated_at'   => $now,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->replace( Schema::slack_people_table(), $row, Formats::for_row( $row ) );
	}

	/**
	 * One person's row, without the secret.
	 *
	 * @param string $user_id Forge person id.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $user_id ): ?array {
		global $wpdb;

		$table = Schema::slack_people_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, prefs, connected_at, last_ok_at, last_error FROM {$table} WHERE id = %s", $user_id ), ARRAY_A );

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Everyone connected, without secrets.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function connected(): array {
		global $wpdb;

		$table = Schema::slack_people_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$rows = $wpdb->get_results( "SELECT id, prefs, connected_at, last_ok_at, last_error FROM {$table} ORDER BY connected_at ASC", ARRAY_A );

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * A person's webhook, opened. Null when not connected or unreadable.
	 *
	 * @param string $user_id Forge person id.
	 * @return string|null
	 */
	public static function webhook( string $user_id ): ?string {
		global $wpdb;

		$table = Schema::slack_people_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$sealed = $wpdb->get_var( $wpdb->prepare( "SELECT secret FROM {$table} WHERE id = %s", $user_id ) );

		return is_string( $sealed ) ? Secrets::open( $sealed ) : null;
	}

	/**
	 * Changes what a person is told about.
	 *
	 * @param string              $user_id Forge person id.
	 * @param array<string, bool> $prefs   Cleaned preferences.
	 */
	public static function set_prefs( string $user_id, array $prefs ): void {
		global $wpdb;

		$changes = array(
			'prefs'      => (string) wp_json_encode( $prefs ),
			'updated_at' => bwx_forge_now(),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->update( Schema::slack_people_table(), $changes, array( 'id' => $user_id ), Formats::for_row( $changes ), array( '%s' ) );
	}

	/**
	 * Records how the last send went.
	 *
	 * @param string $user_id Forge person id.
	 * @param bool   $ok      Whether it arrived.
	 * @param string $error   What went wrong, or empty.
	 */
	public static function mark( string $user_id, bool $ok, string $error = '' ): void {
		global $wpdb;

		$changes = array(
			'last_error' => mb_substr( $error, 0, 191 ),
			'updated_at' => bwx_forge_now(),
		);

		if ( $ok ) {
			$changes['last_ok_at'] = bwx_forge_now();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->update( Schema::slack_people_table(), $changes, array( 'id' => $user_id ), Formats::for_row( $changes ), array( '%s' ) );
	}

	/**
	 * Disconnects a person. Their webhook is forgotten outright.
	 *
	 * @param string $user_id Forge person id.
	 */
	public static function disconnect( string $user_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->delete( Schema::slack_people_table(), array( 'id' => $user_id ), array( '%s' ) );
	}

	/**
	 * A row as the rest of the plugin reads it.
	 *
	 * @param array<string, mixed> $row Database row, without the secret.
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		$prefs = json_decode( (string) $row['prefs'], true );

		return array(
			'user_id'      => (string) $row['id'],
			'prefs'        => self::prefs_from( array(), is_array( $prefs ) ? $prefs : array() ),
			'connected_at' => (int) $row['connected_at'],
			'last_ok_at'   => (int) $row['last_ok_at'],
			'last_error'   => (string) $row['last_error'],
		);
	}
}
