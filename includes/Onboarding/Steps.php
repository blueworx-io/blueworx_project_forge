<?php
/**
 * A step on a client's own checklist.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Onboarding;

use Blueworx\Forge\Data\Formats;
use Blueworx\Forge\Data\Schema;

/**
 * #161. The live record: one step, on one site's checklist.
 *
 * The copy, not the definition. Onboarding\TemplateSteps holds what the
 * template says; this holds what a particular client is actually doing about
 * it. They are separate records from the moment a checklist is assigned, which
 * is what lets this one change while that one never does (ONB-E3).
 *
 * **There is nowhere here to put a credential** and that is deliberate (ONB-3):
 * Forge records which provider, which account, what access was asked for and
 * whether it was verified, and never the secret. The enforcement is the absence
 * of a column, because a rule written in a controller can be forgotten by the
 * next caller.
 *
 * Whether a step is late is not stored either. Onboarding\Statuses works it out
 * from the due date, so nothing has to be swept nightly to stay true.
 */
final class Steps {

	/**
	 * Id prefix for a live step.
	 */
	public const PREFIX = 'obs';

	/**
	 * Every step on a site's checklist, in the order they are worked through.
	 *
	 * @param string $client_site_id The site.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_site( string $client_site_id ): array {
		global $wpdb;

		$table = Schema::onboarding_steps_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE client_site_id = %s ORDER BY position ASC", $client_site_id ), ARRAY_A );

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * One step.
	 *
	 * @param string $id Step id.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $id ): ?array {
		global $wpdb;

		$table = Schema::onboarding_steps_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %s", $id ), ARRAY_A );

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Writes a step row.
	 *
	 * Called by Onboarding\Assignment and nothing else — a step exists because
	 * a checklist was assigned, never on its own. #162 refuses step creation to
	 * a client, and there being one door in is how that stays true.
	 *
	 * @param array<string, mixed> $row A row from Assignment::step_from().
	 * @return array<string, mixed>|null Null when it was not written.
	 */
	public static function insert( array $row ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; there is no core API for it.
		$inserted = $wpdb->insert( Schema::onboarding_steps_table(), $row, Formats::for_row( $row ) );

		return $inserted ? self::hydrate( $row ) : null;
	}

	/**
	 * A step with the question nobody stores answered: is it late?
	 *
	 * @param array<string, mixed> $step  The step, as read.
	 * @param string               $today YYYY-MM-DD.
	 * @return array<string, mixed>
	 */
	public static function with_lateness( array $step, string $today ): array {
		$step['overdue'] = Statuses::is_overdue(
			(string) $step['status'],
			(string) $step['due_on'],
			$today
		);

		return $step;
	}

	/**
	 * A row, as the rest of the product reads it.
	 *
	 * @param array<string, mixed> $row The row.
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		return array(
			'id'                    => (string) $row['id'],
			'site_onboarding_id'    => (string) $row['site_onboarding_id'],
			'client_site_id'        => (string) $row['client_site_id'],
			'template_step_id'      => (string) $row['template_step_id'],
			'section'               => (string) $row['section'],
			'title'                 => (string) ( $row['title'] ?? '' ),
			'status'                => (string) ( $row['status'] ?? Statuses::NOT_STARTED ),
			'owner_side'            => (string) ( $row['owner_side'] ?? TemplateSteps::CLIENT ),
			'owner_id'              => (string) ( $row['owner_id'] ?? '' ),
			'reviewer_id'           => (string) ( $row['reviewer_id'] ?? '' ),
			'launch_critical'       => (int) ( $row['launch_critical'] ?? 0 ),
			'optional'              => (int) ( $row['optional'] ?? 0 ),
			'allows_not_applicable' => (int) ( $row['allows_not_applicable'] ?? 0 ),
			'due_on'                => (string) ( $row['due_on'] ?? '' ),
			'response'              => (string) ( $row['response'] ?? '' ),
			'provider'              => (string) ( $row['provider'] ?? '' ),
			'account_identifier'    => (string) ( $row['account_identifier'] ?? '' ),
			'account_owner'         => (string) ( $row['account_owner'] ?? '' ),
			'access_role'           => (string) ( $row['access_role'] ?? '' ),
			'invitation_status'     => (string) ( $row['invitation_status'] ?? '' ),
			'verification_outcome'  => (string) ( $row['verification_outcome'] ?? '' ),
			'position'              => (int) ( $row['position'] ?? 0 ),
			'created_at'            => (int) ( $row['created_at'] ?? 0 ),
			'updated_at'            => (int) ( $row['updated_at'] ?? 0 ),
			'record_version'        => (int) ( $row['record_version'] ?? 1 ),
		);
	}
}
