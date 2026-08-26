<?php
/**
 * What a client asked for, kept exactly as they asked it.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Work;

use Blueworx\Forge\Data\Formats;
use Blueworx\Forge\Data\Schema;
use Blueworx\Forge\Tenancy\Ids;

/**
 * Request Submissions (#129): the front door to the pipeline.
 *
 * A client may ask for something whether or not they pay for support. That is
 * deliberate and it is the reason this is a separate record rather than a work
 * item created early: work has a commercial life — packages, hours, gates — and
 * a question does not. Somebody with no package can still ask, and the studio
 * still answers.
 *
 * The client's words are fixed at submission (REQ-1). There is no update method
 * here for a reason: immutability kept by a rule is immutability somebody
 * eventually writes an exception to, and the whole value of an intake record is
 * that what was asked cannot drift to match what was delivered. The studio's
 * own answer — the intake state, the response, the work it became — is written
 * through separate methods that never touch the submitted text.
 */
final class Submissions {

	/**
	 * What a client may be asking for.
	 *
	 * Three rather than one, because "can we have this", "here is a thought"
	 * and "this could be better" are triaged differently and arrive with very
	 * different expectations of an answer.
	 *
	 * @var array<int, string>
	 */
	public const TYPES = array( 'request', 'idea', 'suggestion' );

	/**
	 * Where a submission has got to.
	 *
	 * @var array<int, string>
	 */
	public const STATES = array( 'received', 'in-review', 'accepted', 'declined', 'converted' );

	/**
	 * The state every submission starts in.
	 */
	public const RECEIVED = 'received';

	/**
	 * The id prefix for a submission.
	 */
	public const PREFIX = 'sub';

	/**
	 * Whether a string is one of the types a client may choose.
	 *
	 * @param string $type Candidate.
	 * @return bool
	 */
	public static function is_type( string $type ): bool {
		return in_array( $type, self::TYPES, true );
	}

	/**
	 * Whether a string is an intake state.
	 *
	 * @param string $state Candidate.
	 * @return bool
	 */
	public static function is_state( string $state ): bool {
		return in_array( $state, self::STATES, true );
	}

	/**
	 * What an intake state is called, in words a client reads (#130).
	 *
	 * The words live here, next to the states themselves, and travel out with
	 * the record — the same rule the board's stages follow. A client screen that
	 * turned 'in-review' into English itself would be a second copy of this
	 * vocabulary, in a second place, that nobody updates the day a state is
	 * added.
	 *
	 * An unrecognised state comes back as it arrived rather than as a guess.
	 * Showing a slug is a small ugliness; inventing a status somebody acts on is
	 * not.
	 *
	 * @param string $state One of STATES.
	 * @return string
	 */
	public static function label( string $state ): string {
		switch ( $state ) {
			case 'received':
				return __( 'Received', 'blueworx-forge' );
			case 'in-review':
				return __( 'Being looked at', 'blueworx-forge' );
			case 'accepted':
				return __( 'Accepted', 'blueworx-forge' );
			case 'declined':
				return __( 'Not going ahead', 'blueworx-forge' );
			case 'converted':
				return __( 'Became work', 'blueworx-forge' );
			default:
				return $state;
		}
	}

	/**
	 * Records a submission.
	 *
	 * The site and the client are passed in rather than read from the values,
	 * because they come from the signature on the request and the values come
	 * from a form. Only one of those two has been proved.
	 *
	 * @param string               $client_site_id The site it came from.
	 * @param string               $client_id      The client that site belongs to.
	 * @param array<string, mixed> $values         Already through Validate::submission().
	 * @param string               $submitted_by   Who the client site says sent it.
	 * @return array<string, mixed>|null The stored row, or null if it could not be written.
	 */
	public static function create( string $client_site_id, string $client_id, array $values, string $submitted_by ): ?array {
		global $wpdb;

		$now = bwx_forge_now();
		$row = array(
			'id'                => Ids::create( self::PREFIX ),
			'client_site_id'    => $client_site_id,
			'client_id'         => $client_id,
			'type'              => (string) ( $values['type'] ?? 'request' ),
			'title'             => (string) ( $values['title'] ?? '' ),
			'description'       => (string) ( $values['description'] ?? '' ),
			'desired_outcome'   => (string) ( $values['desired_outcome'] ?? '' ),
			'evidence'          => (string) ( $values['evidence'] ?? '' ),
			'submitted_by'      => $submitted_by,
			'intake_state'      => self::RECEIVED,
			'response'          => '',
			'converted_item_id' => '',
			'created_at'        => $now,
			'updated_at'        => $now,
			'created_by'        => 0,
			'record_version'    => 1,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This plugin's own table; there is no core API for it, and an intake record is read rarely enough that caching it would only add a way to be wrong.
		$written = $wpdb->insert( Schema::submissions_table(), $row, Formats::for_row( $row ) );

		return false === $written ? null : self::get( $row['id'] );
	}

	/**
	 * One submission.
	 *
	 * @param string $id Submission id.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $id ): ?array {
		global $wpdb;

		if ( '' === $id ) {
			return null;
		}

		$table = Schema::submissions_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is this class's own literal.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %s", $id ), ARRAY_A );

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Everything one site has ever asked for, newest first.
	 *
	 * @param string $client_site_id The site.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_site( string $client_site_id ): array {
		global $wpdb;

		$table = Schema::submissions_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is this class's own literal.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE client_site_id = %s ORDER BY created_at DESC", $client_site_id ), ARRAY_A );

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * A stored row, with its numbers as numbers.
	 *
	 * @param array<string, mixed> $row As the database returned it.
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		$row['created_at']     = (int) $row['created_at'];
		$row['updated_at']     = (int) $row['updated_at'];
		$row['created_by']     = (int) $row['created_by'];
		$row['record_version'] = (int) $row['record_version'];

		return $row;
	}
}
