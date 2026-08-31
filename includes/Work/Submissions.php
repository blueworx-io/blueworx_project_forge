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

/*
 * Aliased because Notifications\Events sitting beside Work\Events would be two
 * classes of one name in the reader's head, in a file that uses both ideas.
 */
use Blueworx\Forge\Notifications\Events as Notifications;
use Blueworx\Forge\Notifications\Register;
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

		if ( false === $written ) {
			return null;
		}

		/*
		 * #172, #173. A client who has just asked us for something should hear
		 * that we have it, and hear it once.
		 *
		 * Raised here rather than in the controller because this is the one
		 * door a submission comes through, and a second door added later would
		 * be a second place to remember. The claim is by the submission's own
		 * id, so a client site that genuinely asks twice gets two
		 * acknowledgements — they did ask twice — while a replay of one
		 * submission produces one of each.
		 */
		Register::claim(
			array(
				'kind'           => Notifications::RECEIVED,
				'subject_id'     => (string) $row['id'],
				'client_id'      => $client_id,
				'client_site_id' => $client_site_id,
			)
		);

		return self::get( $row['id'] );
	}

	/**
	 * What triage is allowed to write (#131).
	 *
	 * The studio's answer, and nothing that was in the client's message. This
	 * is the one place that decides it, rather than each write path filtering
	 * its own input: a controller that sanitised its own body would be one
	 * refactor away from a second controller that forgot to, and the failure
	 * would be silent — a request quietly rewritten to match what was
	 * delivered, with the client's own copy of their words gone.
	 *
	 * `converted_item_id` is deliberately absent. Conversion (#132) has to
	 * create the work item for the same client as the submission, and this
	 * write cannot check that. A triage write able to set the id would be a way
	 * to point a client's request at another client's work.
	 *
	 * A key carrying null means "not sent" rather than "set it to empty". That
	 * distinction is load-bearing: a save that changes only the state arrives
	 * with the response key present and null, and reading it as an empty string
	 * would delete a reply the client has already read. Sending an empty string
	 * deliberately still clears it — somebody who deletes what they wrote and
	 * saves means it.
	 *
	 * @param array<string, mixed> $input Whatever arrived.
	 * @return array<string, mixed> Only what may be written; possibly nothing.
	 */
	public static function changes( array $input ): array {
		$changes = array();

		if ( null !== ( $input['intake_state'] ?? null ) ) {
			$state = (string) $input['intake_state'];

			if ( self::is_state( $state ) ) {
				$changes['intake_state'] = $state;
			}
		}

		if ( null !== ( $input['response'] ?? null ) ) {
			$changes['response'] = (string) $input['response'];
		}

		return $changes;
	}

	/**
	 * Records the studio's answer to a request.
	 *
	 * Returns the submission unchanged when there is nothing to write, rather
	 * than performing an empty update: the client is shown when their request
	 * was last touched, and a save that changed nothing should not tell them
	 * something happened.
	 *
	 * @param string               $id    Submission id.
	 * @param array<string, mixed> $input Whatever arrived.
	 * @return array<string, mixed>|null The stored row, or null if it is gone
	 *                                   or could not be written.
	 */
	public static function respond( string $id, array $input ): ?array {
		global $wpdb;

		$existing = self::get( $id );

		if ( null === $existing ) {
			return null;
		}

		$changes = self::changes( $input );

		if ( array() === $changes ) {
			return $existing;
		}

		$changes['updated_at']     = bwx_forge_now();
		$changes['record_version'] = ( (int) $existing['record_version'] ) + 1;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This plugin's own table; there is no core API for it.
		$written = $wpdb->update(
			Schema::submissions_table(),
			$changes,
			array( 'id' => $id ),
			Formats::for_row( $changes ),
			array( '%s' )
		);

		return false === $written ? null : self::get( $id );
	}

	/**
	 * Links a request to the work it became (#132).
	 *
	 * Separate from {@see self::respond()} because the two are guarded by
	 * different facts. A triage write is checked against the person doing it; a
	 * conversion is checked against the *item* — it has to belong to the same
	 * client site as the submission, and {@see self::changes()} cannot see an
	 * item to check one. That is why `converted_item_id` is deliberately absent
	 * from the allowlist there, and why the only way to set it is through here,
	 * behind Work\Conversion's rules.
	 *
	 * It refuses a submission that has already been converted rather than
	 * repointing it. A request becomes work once: rewriting the link would
	 * silently change what the client reads under "this became" on their own
	 * site, and would leave the work it used to point at with nothing recording
	 * where it came from.
	 *
	 * The client's words are not among the columns written. That is the whole
	 * point of the method being this small.
	 *
	 * @param string $id      Submission id.
	 * @param string $item_id The work item it became.
	 * @return array<string, mixed>|null The stored row, or null when there is no
	 *                                   such submission, it is already
	 *                                   converted, or the write failed.
	 */
	public static function mark_converted( string $id, string $item_id ): ?array {
		global $wpdb;

		$existing = self::get( $id );

		if ( null === $existing || '' === $item_id || '' !== (string) $existing['converted_item_id'] ) {
			return null;
		}

		$changes = array(
			'converted_item_id' => $item_id,
			'intake_state'      => 'converted',
			'updated_at'        => bwx_forge_now(),
			'record_version'    => ( (int) $existing['record_version'] ) + 1,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This plugin's own table; there is no core API for it.
		$written = $wpdb->update(
			Schema::submissions_table(),
			$changes,
			array( 'id' => $id ),
			Formats::for_row( $changes ),
			array( '%s' )
		);

		return false === $written ? null : self::get( $id );
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
	 * Every submission there is, newest first (#131).
	 *
	 * Unscoped on purpose, and never handed to a screen as it stands: the
	 * caller filters it with {@see Queue::visible()} before anything else
	 * touches it. Splitting the read from the scoping is what lets the scoping
	 * live in one tested place instead of inside a query string.
	 *
	 * It reads the whole table. At intake volumes that is the right trade for
	 * keeping the boundary in PHP where it is provable, and the queue is a
	 * screen somebody works rather than one that sits open. If it ever stops
	 * being the right trade, that is the query-count pass (#183), not a reason
	 * to push the tenant check into SQL now.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		global $wpdb;

		$table = Schema::submissions_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is this class's own literal, and there is nothing to interpolate.
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC", ARRAY_A );

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
