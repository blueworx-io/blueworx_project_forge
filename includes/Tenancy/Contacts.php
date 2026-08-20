<?php
/**
 * Who we are, to a client.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Tenancy;

use Blueworx\Forge\Data\Formats;
use Blueworx\Forge\Data\Schema;

/**
 * #95. One current internal contact per client, with the history kept.
 *
 * Stored as appended assignments rather than as a column on the client. A
 * column answers "who is it now" and destroys the answer to "who was it in
 * March", and the second question is the one that comes up when something went
 * wrong months ago. The current contact is simply the latest row.
 *
 * Nothing here is ever updated. An assignment naming nobody is a real
 * assignment — it says the contact left and the next one has not been named —
 * which is what lets "never had one" and "has not got one right now" be
 * different facts on the screen.
 */
final class Contacts {

	/**
	 * Id prefix for an assignment.
	 */
	public const PREFIX = 'poc';

	/**
	 * The defined fallback while there is no usable contact: the studio itself.
	 *
	 * Named rather than left to whoever is looking. A client with no contact
	 * still has somebody to call, and it is us.
	 */
	public const FALLBACK_STUDIO = 'studio';

	/**
	 * What the current assignment adds up to.
	 *
	 * Pure, and separate from reading it, so the rule can be read as a rule.
	 *
	 * @param array<string, mixed>|null $assignment The latest assignment, or null.
	 * @param array<string, mixed>|null $person     The person it names, or null
	 *                                              when there is none or they no
	 *                                              longer exist.
	 * @return array{contact: array<string, mixed>|null, needs_reassignment: bool, fallback: string}
	 */
	public static function resolve( ?array $assignment, ?array $person ): array {
		$named = null !== $assignment && '' !== (string) ( $assignment['user_id'] ?? '' );
		$here  = null !== $person && 'active' === (string) ( $person['status'] ?? '' );

		if ( $named && $here ) {
			return array(
				'contact'            => $person,
				'needs_reassignment' => false,
				'fallback'           => '',
			);
		}

		/*
		 * Somebody who has left is still named. Blanking them loses the one
		 * thing the person reassigning the client actually needs — who it used
		 * to be — and leaves a client that looks like it never had a contact.
		 */
		return array(
			'contact'            => $named && null !== $person ? $person : null,
			'needs_reassignment' => true,
			'fallback'           => self::FALLBACK_STUDIO,
		);
	}

	/**
	 * The contact as a client may see them.
	 *
	 * A name, and nothing else. An address, a WordPress account and the grants
	 * somebody holds are all ours; the last of those says what a member of staff
	 * is allowed to do, which no client has any business reading.
	 *
	 * @param array<string, mixed>|null $person The person, or null for nobody.
	 * @return array<string, mixed>
	 */
	public static function for_client( ?array $person ): array {
		if ( null === $person ) {
			return array();
		}

		return array( 'display_name' => (string) ( $person['display_name'] ?? '' ) );
	}

	/**
	 * Names the contact for a client, keeping what came before.
	 *
	 * @param string $client_id Client.
	 * @param string $user_id   The person, or '' for nobody.
	 * @param int    $author    WordPress user id making the change.
	 * @return array<string, mixed>|null Null when the write failed.
	 */
	public static function assign( string $client_id, string $user_id, int $author ): ?array {
		global $wpdb;

		$now = bwx_forge_now();

		$row = array(
			'id'         => Ids::create( self::PREFIX ),
			'client_id'  => $client_id,
			'user_id'    => $user_id,
			'started_at' => $now,
			'created_at' => $now,
			'created_by' => $author,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; there is no core API for it.
		$inserted = $wpdb->insert( Schema::contacts_table(), $row, Formats::for_row( $row ) );

		return $inserted ? self::hydrate( $row ) : null;
	}

	/**
	 * The current assignment for a client, or null when there has never been one.
	 *
	 * @param string $client_id Client.
	 * @return array<string, mixed>|null
	 */
	public static function current( string $client_id ): ?array {
		global $wpdb;

		$table = Schema::contacts_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE client_id = %s ORDER BY started_at DESC, id DESC LIMIT 1", $client_id ), ARRAY_A );

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Every assignment a client has had, newest first.
	 *
	 * @param string $client_id Client.
	 * @return array<int, array<string, mixed>>
	 */
	public static function history( string $client_id ): array {
		global $wpdb;

		$table = Schema::contacts_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE client_id = %s ORDER BY started_at DESC, id DESC", $client_id ), ARRAY_A );

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Every client whose contact has gone, for the screen that reassigns them.
	 *
	 * @return array<int, array<string, mixed>> Client id to its resolved state.
	 */
	public static function needing_reassignment(): array {
		$needing = array();

		foreach ( Clients::all( 'active' ) as $client ) {
			$assignment = self::current( (string) $client['id'] );
			$person     = null === $assignment || '' === (string) $assignment['user_id']
				? null
				: Users::get( (string) $assignment['user_id'] );

			$state = self::resolve( $assignment, $person );

			if ( $state['needs_reassignment'] ) {
				$needing[] = array(
					'client' => $client,
					'state'  => $state,
				);
			}
		}

		return $needing;
	}

	/**
	 * Turns a stored row into the record the rest of the plugin uses.
	 *
	 * @param array<string, mixed> $row Row as stored.
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		return array(
			'id'         => (string) $row['id'],
			'client_id'  => (string) $row['client_id'],
			'user_id'    => (string) $row['user_id'],
			'started_at' => (int) $row['started_at'],
			'created_at' => (int) $row['created_at'],
			'created_by' => (int) $row['created_by'],
		);
	}
}
