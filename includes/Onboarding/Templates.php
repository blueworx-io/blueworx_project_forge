<?php
/**
 * Versions of the launch checklist.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Onboarding;

use Blueworx\Forge\Data\Formats;
use Blueworx\Forge\Data\Schema;
use Blueworx\Forge\Tenancy\Ids;

/**
 * ONB-E2 (#159): a template version is draft or published, and **published is
 * for ever**.
 *
 * That single rule is what makes everything downstream work. A client's
 * checklist points at a version (#160); if a version could be edited underneath
 * them, the pointer would be a lie and every assignment would need its own
 * defensive copy of the whole definition to be trustworthy. Freezing it instead
 * means the pointer is enough.
 *
 * So there is no way to change a published version. Editing one opens a draft
 * copied from it, and publishing that draft makes the next version. The old one
 * stays exactly where it is, still answering for every client who was given it.
 *
 * The table is global. A template is the studio's, not a client's — every site
 * is assigned one and none of them owns it — so nothing here is tenant-scoped,
 * deliberately, and callers are crossing that boundary knowingly.
 */
final class Templates {

	/**
	 * Id prefix for a template version.
	 */
	public const PREFIX = 'otv';

	/**
	 * Being written. Editable, and not yet given to anybody.
	 */
	public const DRAFT = 'draft';

	/**
	 * Issued. Never editable again.
	 */
	public const PUBLISHED = 'published';

	/**
	 * Longest a template's name may be, matching the column.
	 */
	public const MAX_NAME = 191;

	/**
	 * Whether this template may still be changed.
	 *
	 * Anything that is not a draft cannot — including a row that is not a
	 * template at all, which is the safe way round: a caller that fetched
	 * nothing should not be told it may edit it.
	 *
	 * @param array<string, mixed> $template The template, as read.
	 * @return bool
	 */
	public static function may_edit( array $template ): bool {
		return self::DRAFT === (string) ( $template['status'] ?? '' );
	}

	/**
	 * The number the next version takes.
	 *
	 * From the highest there has ever been, not from a count. A draft deleted
	 * before it was published would make a count reissue a number that some
	 * client's assignment already points at, and two different checklists would
	 * then both answer to version 4.
	 *
	 * @param int $highest The highest version number in existence.
	 * @return int
	 */
	public static function next_version( int $highest ): int {
		return max( 0, $highest ) + 1;
	}

	/**
	 * What publishing writes onto a version.
	 *
	 * @param int $actor Who published it.
	 * @param int $at    When, as a timestamp.
	 * @return array<string, mixed>
	 */
	public static function publication( int $actor, int $at ): array {
		return array(
			'status'       => self::PUBLISHED,
			'published_by' => $actor,
			'published_at' => $at,
		);
	}

	/**
	 * Starts a new draft, optionally copied from an existing version.
	 *
	 * Copying is how a published version is edited: its steps come across into
	 * the draft, the draft is changed, and publishing it makes the next version
	 * while the original stays untouched.
	 *
	 * @param string $name    What to call it.
	 * @param int    $author  Who started it.
	 * @param string $from_id A version to copy the steps from, or '' for empty.
	 * @return array<string, mixed>|null Null when it could not be written.
	 */
	public static function create_draft( string $name, int $author, string $from_id = '' ): ?array {
		global $wpdb;

		$now = bwx_forge_now();

		$row = array(
			'id'             => Ids::create( self::PREFIX ),
			'version'        => 0,
			'name'           => mb_substr( trim( $name ), 0, self::MAX_NAME ),
			'status'         => self::DRAFT,
			'published_at'   => 0,
			'published_by'   => 0,
			'created_at'     => $now,
			'updated_at'     => $now,
			'created_by'     => $author,
			'record_version' => 1,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; there is no core API for it.
		$inserted = $wpdb->insert( Schema::onboarding_templates_table(), $row, Formats::for_row( $row ) );

		if ( ! $inserted ) {
			return null;
		}

		if ( '' !== $from_id ) {
			TemplateSteps::copy( $from_id, (string) $row['id'], $author );
		}

		return self::hydrate( $row );
	}

	/**
	 * Publishes a draft as the next version.
	 *
	 * @param string $id    The draft.
	 * @param int    $actor Who is publishing it.
	 * @return array<string, mixed>|null Null when there is no such draft, or it
	 *                                   is already published.
	 */
	public static function publish( string $id, int $actor ): ?array {
		global $wpdb;

		$template = self::get( $id );

		if ( null === $template || ! self::may_edit( $template ) ) {
			// Publishing something already published is not an error worth
			// throwing, but it must not issue it a second version number.
			return null;
		}

		$changes = array_merge(
			self::publication( $actor, bwx_forge_now() ),
			array(
				'version'        => self::next_version( self::highest_version() ),
				'updated_at'     => bwx_forge_now(),
				'record_version' => (int) $template['record_version'] + 1,
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$updated = $wpdb->update(
			Schema::onboarding_templates_table(),
			$changes,
			array( 'id' => $id ),
			Formats::for_row( $changes ),
			array( '%s' )
		);

		return $updated ? self::get( $id ) : null;
	}

	/**
	 * One version.
	 *
	 * @param string $id Template id.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $id ): ?array {
		global $wpdb;

		$table = Schema::onboarding_templates_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %s", $id ), ARRAY_A );

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * The version in force: the highest published one.
	 *
	 * @return array<string, mixed>|null Null before anything has been published.
	 */
	public static function current(): ?array {
		global $wpdb;

		$table = Schema::onboarding_templates_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY version DESC LIMIT 1", self::PUBLISHED ), ARRAY_A );

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Every version, newest first, drafts included.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		global $wpdb;

		$table = Schema::onboarding_templates_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY version DESC, created_at DESC", ARRAY_A );

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * The highest version number ever issued.
	 *
	 * @return int Zero when nothing has been published.
	 */
	private static function highest_version(): int {
		global $wpdb;

		$table = Schema::onboarding_templates_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		return (int) $wpdb->get_var( "SELECT MAX(version) FROM {$table}" );
	}

	/**
	 * A row, as the rest of the product reads it.
	 *
	 * @param array<string, mixed> $row The row.
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		return array(
			'id'             => (string) $row['id'],
			'version'        => (int) ( $row['version'] ?? 0 ),
			'name'           => (string) ( $row['name'] ?? '' ),
			'status'         => (string) ( $row['status'] ?? self::DRAFT ),
			'published_at'   => (int) ( $row['published_at'] ?? 0 ),
			'published_by'   => (int) ( $row['published_by'] ?? 0 ),
			'created_at'     => (int) ( $row['created_at'] ?? 0 ),
			'updated_at'     => (int) ( $row['updated_at'] ?? 0 ),
			'created_by'     => (int) ( $row['created_by'] ?? 0 ),
			'record_version' => (int) ( $row['record_version'] ?? 1 ),
		);
	}
}
