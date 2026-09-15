<?php
/**
 * Connected SureCart stores.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Commerce\SureCart;

use Blueworx\Forge\Data\Formats;
use Blueworx\Forge\Data\Schema;
use Blueworx\Forge\Recurring\Sources;
use Blueworx\Forge\Tenancy\Ids;
use Blueworx\Forge\Tenancy\Secrets;
use Blueworx\Forge\Tenancy\Studio;

/**
 * A connection is plumbing: a name, a sealed token, and which of our people
 * a renewal reminder is for. It is removed outright when it is no longer
 * wanted — there is no relationship to keep a record of, only a key — and
 * removing it ends the reminders it was feeding and drops its copy of the
 * subscriptions.
 *
 * The token goes in sealed ({@see Secrets}) and comes out only here, only
 * for the sync. No route and no screen shows it again.
 */
final class Connections {

	/**
	 * Id prefix for a connection.
	 */
	public const PREFIX = 'con';

	/**
	 * The only kind so far.
	 */
	public const SURECART = 'surecart';

	/**
	 * Refreshed on the hour and when asked.
	 */
	public const ACTIVE = 'active';

	/**
	 * Kept but not read.
	 */
	public const DISABLED = 'disabled';

	/**
	 * Stores a new connection.
	 *
	 * @param string               $name     What to call the store.
	 * @param string               $token    The API token, plain.
	 * @param array<string, mixed> $settings Seats and hours, validated.
	 * @param int                  $author   WordPress user id.
	 * @return array<string, mixed>|null
	 */
	public static function create( string $name, string $token, array $settings, int $author ): ?array {
		global $wpdb;

		$now = bwx_forge_now();
		$row = array(
			'id'             => Ids::create( self::PREFIX ),
			'kind'           => self::SURECART,
			'name'           => $name,
			'secret'         => Secrets::seal( $token ),
			'settings'       => (string) wp_json_encode( $settings ),
			'status'         => self::ACTIVE,
			'last_ok_at'     => 0,
			'last_error'     => '',
			'last_count'     => 0,
			'created_at'     => $now,
			'updated_at'     => $now,
			'created_by'     => $author,
			'record_version' => 1,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$inserted = $wpdb->insert( Schema::connections_table(), $row, Formats::for_row( $row ) );

		return $inserted ? self::hydrate( $row ) : null;
	}

	/**
	 * One connection.
	 *
	 * @param string $id Connection id.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $id ): ?array {
		global $wpdb;

		$table = Schema::connections_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %s", $id ), ARRAY_A );

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Every connection, oldest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		global $wpdb;

		$table = Schema::connections_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at ASC", ARRAY_A );

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * The token, opened. Null when it cannot be opened — AUTH_KEY changed.
	 *
	 * @param string $id Connection id.
	 * @return string|null
	 */
	public static function token( string $id ): ?string {
		global $wpdb;

		$table = Schema::connections_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$sealed = $wpdb->get_var( $wpdb->prepare( "SELECT secret FROM {$table} WHERE id = %s", $id ) );

		return is_string( $sealed ) ? Secrets::open( $sealed ) : null;
	}

	/**
	 * Changes a connection's name and seats.
	 *
	 * @param string               $id           Connection id.
	 * @param string               $name         What to call the store.
	 * @param array<string, mixed> $settings     Seats and hours, validated.
	 * @param int                  $sent_version Version the caller saw.
	 * @return array<string, mixed>|null
	 */
	public static function update( string $id, string $name, array $settings, int $sent_version ): ?array {
		global $wpdb;

		$changes = array(
			'name'           => $name,
			'settings'       => (string) wp_json_encode( $settings ),
			'updated_at'     => bwx_forge_now(),
			'record_version' => $sent_version + 1,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$changed = $wpdb->update(
			Schema::connections_table(),
			$changes,
			array(
				'id'             => $id,
				'record_version' => $sent_version,
			),
			Formats::for_row( $changes ),
			array( '%s', '%d' )
		);

		return $changed ? self::get( $id ) : null;
	}

	/**
	 * Replaces the token.
	 *
	 * @param string $id    Connection id.
	 * @param string $token The new token, plain.
	 */
	public static function set_token( string $id, string $token ): void {
		global $wpdb;

		$changes = array(
			'secret'     => Secrets::seal( $token ),
			'last_error' => '',
			'updated_at' => bwx_forge_now(),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->update( Schema::connections_table(), $changes, array( 'id' => $id ), Formats::for_row( $changes ), array( '%s' ) );
	}

	/**
	 * Records how the last refresh went.
	 *
	 * @param string $id    Connection id.
	 * @param bool   $ok    Whether it worked.
	 * @param string $error What went wrong, or empty.
	 * @param int    $count How many active subscriptions it saw.
	 */
	public static function mark( string $id, bool $ok, string $error, int $count ): void {
		global $wpdb;

		$changes = array(
			'last_error' => mb_substr( $error, 0, 191 ),
			'updated_at' => bwx_forge_now(),
		);

		if ( $ok ) {
			$changes['last_ok_at'] = bwx_forge_now();
			$changes['last_count'] = $count;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->update( Schema::connections_table(), $changes, array( 'id' => $id ), Formats::for_row( $changes ), array( '%s' ) );
	}

	/**
	 * Removes a connection, its copy of the subscriptions, and ends the
	 * reminders it was feeding.
	 *
	 * @param string $id Connection id.
	 */
	public static function remove( string $id ): void {
		global $wpdb;

		$site_id = Studio::site_id();

		if ( '' !== $site_id ) {
			foreach ( Sources::for_site( $site_id, Sources::SUBSCRIPTION ) as $source ) {
				if ( 0 === strpos( (string) $source['source_ref'], $id . ':' ) ) {
					Sources::end( (string) $source['id'] );
				}
			}
		}

		Subscriptions::replace( $id, array() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->delete( Schema::connections_table(), array( 'id' => $id ), array( '%s' ) );
	}

	/**
	 * Cleans the seats and hours a connection is given. Pure.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array{values: array<string, mixed>, errors: array<string, string>}
	 */
	public static function settings_from( array $input ): array {
		$values = array();
		$errors = array();

		foreach ( array( 'primary_user_id', 'reviewer_id', 'deliverer_id' ) as $seat ) {
			$id = trim( (string) ( $input[ $seat ] ?? '' ) );

			if ( '' !== $id && 1 !== preg_match( '/^usr_[A-Za-z0-9]+$/', $id ) ) {
				$errors[ $seat ] = 'That is not a person.';
				continue;
			}

			$values[ $seat ] = $id;
		}

		if ( '' === ( $values['primary_user_id'] ?? '' ) && ! isset( $errors['primary_user_id'] ) ) {
			$errors['primary_user_id'] = 'Somebody has to be the one who checks the payment.';
		}

		$defaults = array(
			'hours_primary'  => '0.25',
			'hours_review'   => '0',
			'hours_delivery' => '0',
		);

		foreach ( $defaults as $hours => $fallback ) {
			$raw = trim( (string) ( $input[ $hours ] ?? '' ) );

			if ( '' === $raw ) {
				$values[ $hours ] = $fallback;
				continue;
			}

			if ( ! is_numeric( $raw ) || (float) $raw < 0 ) {
				$errors[ $hours ] = 'Hours are a number, zero or more.';
				continue;
			}

			$values[ $hours ] = (string) round( (float) $raw, 2 );
		}

		return array(
			'values' => $values,
			'errors' => $errors,
		);
	}

	/**
	 * A row as the rest of the plugin reads it. The secret never leaves.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		$settings = json_decode( (string) $row['settings'], true );

		return array(
			'id'             => (string) $row['id'],
			'kind'           => (string) $row['kind'],
			'name'           => (string) $row['name'],
			'settings'       => is_array( $settings ) ? $settings : array(),
			'status'         => (string) $row['status'],
			'last_ok_at'     => (int) $row['last_ok_at'],
			'last_error'     => (string) $row['last_error'],
			'last_count'     => (int) $row['last_count'],
			'created_at'     => (int) $row['created_at'],
			'updated_at'     => (int) $row['updated_at'],
			'created_by'     => (int) $row['created_by'],
			'record_version' => (int) $row['record_version'],
		);
	}
}
