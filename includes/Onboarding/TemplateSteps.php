<?php
/**
 * The steps a template version is made of.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Onboarding;

use Blueworx\Forge\Data\Formats;
use Blueworx\Forge\Data\Schema;
use Blueworx\Forge\Tenancy\Ids;

/**
 * #159. One step, as the template defines it rather than as a client answers it.
 *
 * The distinction matters: this is the definition, and Onboarding\Steps is the
 * copy a client actually works through. Assignment (#160) turns each of these
 * into one of those, and from that moment they are separate things — the client
 * changes theirs, and this one never changes at all.
 *
 * **Nothing may be added to a published version.** That is checked here rather
 * than left to callers, because it is the rule ONB-E2 rests on: a version that
 * could gain a step after it was issued would leave two clients on "version 2"
 * with different checklists.
 */
final class TemplateSteps {

	/**
	 * Id prefix for a template step.
	 */
	public const PREFIX = 'ots';

	/**
	 * Who does a step.
	 */
	public const CLIENT   = 'client';
	public const INTERNAL = 'internal';

	/**
	 * Both sides, for validating what arrives.
	 *
	 * @var array<int, string>
	 */
	public const SIDES = array( self::CLIENT, self::INTERNAL );

	/**
	 * Longest a title may be, matching the column.
	 */
	public const MAX_TITLE = 191;

	/**
	 * Adds a step to a draft.
	 *
	 * @param string               $template_id The version it belongs to.
	 * @param array<string, mixed> $values      section, category, title,
	 *                                          description, owner_side,
	 *                                          optional, launch_critical,
	 *                                          allows_not_applicable,
	 *                                          depends_on, position.
	 * @param int                  $author      Who added it.
	 * @return array<string, mixed>|null Null when it was refused or not written.
	 */
	public static function add( string $template_id, array $values, int $author = 0 ): ?array {
		global $wpdb;

		$template = Templates::get( $template_id );

		// A step added to an issued version would leave two clients on the same
		// version number with different checklists (ONB-E2).
		if ( null === $template || ! Templates::may_edit( $template ) ) {
			return null;
		}

		$section = (string) ( $values['section'] ?? '' );

		if ( ! Sections::exists( $section ) ) {
			return null;
		}

		$side = (string) ( $values['owner_side'] ?? self::CLIENT );
		$now  = bwx_forge_now();

		$row = array(
			'id'                    => Ids::create( self::PREFIX ),
			'template_id'           => $template_id,
			'section'               => $section,
			'category'              => (string) ( $values['category'] ?? '' ),
			'title'                 => mb_substr( trim( (string) ( $values['title'] ?? '' ) ), 0, self::MAX_TITLE ),
			'description'           => (string) ( $values['description'] ?? '' ),
			'owner_side'            => in_array( $side, self::SIDES, true ) ? $side : self::CLIENT,
			'optional'              => empty( $values['optional'] ) ? 0 : 1,
			'launch_critical'       => empty( $values['launch_critical'] ) ? 0 : 1,
			'allows_not_applicable' => empty( $values['allows_not_applicable'] ) ? 0 : 1,
			'depends_on'            => self::render_dependencies( $values['depends_on'] ?? array() ),
			'position'              => (int) ( $values['position'] ?? 0 ),
			'created_at'            => $now,
			'updated_at'            => $now,
			'created_by'            => $author,
			'record_version'        => 1,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; there is no core API for it.
		$inserted = $wpdb->insert( Schema::onboarding_template_steps_table(), $row, Formats::for_row( $row ) );

		return $inserted ? self::hydrate( $row ) : null;
	}

	/**
	 * Every step in a version, in the order they are worked through.
	 *
	 * @param string $template_id The version.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_template( string $template_id ): array {
		global $wpdb;

		$table = Schema::onboarding_template_steps_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE template_id = %s ORDER BY position ASC", $template_id ), ARRAY_A );

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Copies every step of one version into another.
	 *
	 * How a published version is edited: the draft starts as what the last one
	 * said, and diverges from there.
	 *
	 * @param string $from_id Version to copy from.
	 * @param string $to_id   Draft to copy into.
	 * @param int    $author  Who is doing it.
	 * @return int How many steps were copied.
	 */
	public static function copy( string $from_id, string $to_id, int $author = 0 ): int {
		$copied = 0;

		foreach ( self::for_template( $from_id ) as $step ) {
			if ( null !== self::add( $to_id, $step, $author ) ) {
				++$copied;
			}
		}

		return $copied;
	}

	/**
	 * Dependencies as they are stored.
	 *
	 * A comma-separated list rather than a join table. A version cannot change
	 * once published, so these references can never come to dangle, and nothing
	 * ever queries backwards from a dependency to the steps that named it.
	 *
	 * @param mixed $depends_on An array of template step ids, or a stored string.
	 * @return string
	 */
	public static function render_dependencies( $depends_on ): string {
		if ( is_string( $depends_on ) ) {
			return $depends_on;
		}

		return implode( ',', array_filter( array_map( 'strval', (array) $depends_on ) ) );
	}

	/**
	 * Dependencies as a list.
	 *
	 * @param string $stored The stored string.
	 * @return array<int, string>
	 */
	public static function read_dependencies( string $stored ): array {
		return array_values( array_filter( array_map( 'trim', explode( ',', $stored ) ) ) );
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
			'template_id'           => (string) $row['template_id'],
			'section'               => (string) $row['section'],
			'category'              => (string) ( $row['category'] ?? '' ),
			'title'                 => (string) ( $row['title'] ?? '' ),
			'description'           => (string) ( $row['description'] ?? '' ),
			'owner_side'            => (string) ( $row['owner_side'] ?? self::CLIENT ),
			'optional'              => (int) ( $row['optional'] ?? 0 ),
			'launch_critical'       => (int) ( $row['launch_critical'] ?? 0 ),
			'allows_not_applicable' => (int) ( $row['allows_not_applicable'] ?? 0 ),
			'depends_on'            => (string) ( $row['depends_on'] ?? '' ),
			'position'              => (int) ( $row['position'] ?? 0 ),
		);
	}
}
