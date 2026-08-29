<?php
/**
 * Giving a client site its checklist.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Onboarding;

use Blueworx\Forge\Data\Formats;
use Blueworx\Forge\Data\Schema;
use Blueworx\Forge\Tenancy\Ids;

/**
 * ONB-E3 (#160): assigning a template version to a site writes one real step
 * row per template step, there and then.
 *
 * Not a reference resolved when somebody reads it. Every step needs its own
 * status, owner, reviewer, due date, answer and history from the moment it
 * exists — that is what #161 is — so the rows have to exist anyway. Creating
 * them lazily would mean a step with no history until somebody first touched
 * it, and a studio board (#165) that cannot query what is not there.
 *
 * The assigned version is recorded on the site's onboarding as well as being
 * copied into the steps, so "which checklist did this client actually get" is
 * still answerable long after their own steps have diverged from it.
 *
 * One per site, and the unique index backs that up rather than trusting
 * callers: a second assignment would give a client two checklists and no way to
 * say which one counts.
 */
final class Assignment {

	/**
	 * Id prefix for a site's onboarding.
	 */
	public const PREFIX = 'sob';

	/**
	 * Gives a site a checklist.
	 *
	 * @param string $client_site_id The site.
	 * @param string $client_id      That site's client, denormalised.
	 * @param string $template_id    The version to assign.
	 * @param int    $actor          Who assigned it.
	 * @return array<string, mixed>|null Null when it was refused or not written.
	 */
	public static function assign( string $client_site_id, string $client_id, string $template_id, int $actor ): ?array {
		global $wpdb;

		if ( '' === $client_site_id || null !== self::for_site( $client_site_id ) ) {
			// Already has one. Re-assigning is deliberately not a feature: a
			// client onboards once, and ONB-1 says an assigned checklist keeps
			// its snapshot.
			return null;
		}

		$template = Templates::get( $template_id );

		// An unpublished draft is not something anybody may be given: it is
		// still being written, and freezing it for a client is exactly what
		// publishing is for (ONB-E2).
		if ( null === $template || Templates::PUBLISHED !== (string) $template['status'] ) {
			return null;
		}

		$row = array(
			'id'               => Ids::create( self::PREFIX ),
			'client_site_id'   => $client_site_id,
			'client_id'        => $client_id,
			'template_id'      => $template_id,
			'template_version' => (int) $template['version'],
			'assigned_at'      => bwx_forge_now(),
			'assigned_by'      => $actor,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; there is no core API for it.
		$inserted = $wpdb->insert( Schema::site_onboarding_table(), $row, Formats::for_row( $row ) );

		if ( ! $inserted ) {
			return null;
		}

		$onboarding = self::hydrate( $row );

		foreach ( TemplateSteps::for_template( $template_id ) as $template_step ) {
			$step = Steps::insert( self::step_from( $template_step, $onboarding ) );

			if ( null === $step ) {
				continue;
			}

			StepEvents::append(
				array(
					'step_id'        => $step['id'],
					'client_site_id' => $client_site_id,
					'action'         => StepEvents::CREATED,
					'to_status'      => $step['status'],
					'actor'          => $actor,
				)
			);
		}

		return $onboarding;
	}

	/**
	 * The row one template step becomes.
	 *
	 * Pure, so what a client's checklist is made of can be stated in a test
	 * rather than inferred from a site.
	 *
	 * Nobody is named on it. The reviewer is the Point of Contact by default
	 * and overridable per step (ONB-2), and neither is known at the moment of
	 * assignment — a guessed name is worse than a blank one, because somebody
	 * acts on it.
	 *
	 * @param array<string, mixed> $template_step The definition.
	 * @param array<string, mixed> $onboarding    The site's onboarding record.
	 * @return array<string, mixed>
	 */
	public static function step_from( array $template_step, array $onboarding ): array {
		$now = bwx_forge_now();

		return array(
			'id'                    => Ids::create( Steps::PREFIX ),
			'site_onboarding_id'    => (string) ( $onboarding['id'] ?? '' ),
			'client_site_id'        => (string) ( $onboarding['client_site_id'] ?? '' ),

			// Its own id, and the definition's alongside it. Two records from
			// the first moment, which is what lets one change and the other
			// never does.
			'template_step_id'      => (string) ( $template_step['id'] ?? '' ),
			'section'               => (string) ( $template_step['section'] ?? Sections::FOUNDATIONS ),
			'title'                 => (string) ( $template_step['title'] ?? '' ),
			'status'                => Statuses::NOT_STARTED,
			'owner_side'            => (string) ( $template_step['owner_side'] ?? TemplateSteps::CLIENT ),
			'owner_id'              => '',
			'reviewer_id'           => '',
			'launch_critical'       => (int) ( $template_step['launch_critical'] ?? 0 ),
			'optional'              => (int) ( $template_step['optional'] ?? 0 ),
			'allows_not_applicable' => (int) ( $template_step['allows_not_applicable'] ?? 0 ),
			'due_on'                => '',
			'response'              => '',

			/*
			 * The ONB-3 handover fields, present and blank. Present so the
			 * shape of a step never depends on how far through it is; blank
			 * because nothing has been handed over yet. There is deliberately
			 * no field here for the secret itself.
			 */
			'provider'              => '',
			'account_identifier'    => '',
			'account_owner'         => '',
			'access_role'           => '',
			'invitation_status'     => '',
			'verification_outcome'  => '',
			'position'              => (int) ( $template_step['position'] ?? 0 ),
			'created_at'            => $now,
			'updated_at'            => $now,
			'created_by'            => 0,
			'record_version'        => 1,
		);
	}

	/**
	 * A site's onboarding, where it has one.
	 *
	 * @param string $client_site_id The site.
	 * @return array<string, mixed>|null
	 */
	public static function for_site( string $client_site_id ): ?array {
		global $wpdb;

		$table = Schema::site_onboarding_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE client_site_id = %s", $client_site_id ), ARRAY_A );

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * A row, as the rest of the product reads it.
	 *
	 * @param array<string, mixed> $row The row.
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		return array(
			'id'               => (string) $row['id'],
			'client_site_id'   => (string) $row['client_site_id'],
			'client_id'        => (string) ( $row['client_id'] ?? '' ),
			'template_id'      => (string) ( $row['template_id'] ?? '' ),
			'template_version' => (int) ( $row['template_version'] ?? 0 ),
			'assigned_at'      => (int) ( $row['assigned_at'] ?? 0 ),
			'assigned_by'      => (int) ( $row['assigned_by'] ?? 0 ),
		);
	}
}
