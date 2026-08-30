<?php
/**
 * The record of a file attached to an onboarding step.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Onboarding;

use Blueworx\Forge\Data\Formats;
use Blueworx\Forge\Data\Schema;
use Blueworx\Forge\Tenancy\Ids;

/**
 * #168. What was attached, by whom, to which step, on whose site.
 *
 * {@see EvidenceStore} decides what may be uploaded and where the bytes go.
 * This is the record that survives, and it carries the two rules that keep the
 * file safe after the upload itself is over.
 *
 * **Every read names the site as well as the record.** A caller that has an id
 * and nothing else gets nothing back. Tenant isolation is a WHERE clause here
 * rather than a check in a controller, because a controller can be added and a
 * WHERE clause cannot be forgotten.
 *
 * **Nothing is edited.** Replacing evidence writes another row; the earlier one
 * stays, which is what makes a submission history worth reading. NOTIF-5 keeps
 * attachments for the life of the client relationship plus twelve months, and
 * records that as a date rather than acting on it — an automated purge running
 * through records with audit history is the foot-gun that decision names.
 */
final class Evidence {

	/**
	 * Id prefix for an attachment.
	 */
	public const PREFIX = 'obv';

	/**
	 * How long evidence outlives the relationship it belongs to (NOTIF-5).
	 */
	public const RETENTION_DAYS = 365;

	/**
	 * Longest the name a person recognises may be. It lands in a varchar(191).
	 */
	public const MAX_NAME = 191;

	/**
	 * Builds the row for an attachment, or refuses it.
	 *
	 * Separate from writing it so that what makes an attachment valid can be
	 * read, and tested, without a database.
	 *
	 * @param array<string, mixed> $attachment step_id, client_site_id, client_id,
	 *                                         original_name, stored_name,
	 *                                         mime_type, size_bytes, checksum,
	 *                                         uploaded_by, source_interface.
	 * @return array<string, mixed> Empty when it may not be written.
	 */
	public static function row_from( array $attachment ): array {
		$step_id       = (string) ( $attachment['step_id'] ?? '' );
		$site_id       = (string) ( $attachment['client_site_id'] ?? '' );
		$stored_name   = (string) ( $attachment['stored_name'] ?? '' );
		$uploaded_by   = (int) ( $attachment['uploaded_by'] ?? 0 );
		$uploaded_site = trim( (string) ( $attachment['uploaded_site'] ?? '' ) );

		/*
		 * No site, no step, or no file: all three make a row that cannot be
		 * scoped or cannot be found. Refuse here rather than write it and
		 * discover later.
		 */
		if ( '' === $step_id || '' === $site_id || '' === $stored_name ) {
			return array();
		}

		/*
		 * And somebody, or something, has to be named. A client site counts and
		 * a person counts; neither counts twice. This is the same rule as
		 * Onboarding\StepEvents and Work\Comments, and it is the same rule on
		 * purpose — an attachment with two possible authors is one nobody can
		 * account for later.
		 */
		if ( ( $uploaded_by <= 0 && '' === $uploaded_site ) || ( $uploaded_by > 0 && '' !== $uploaded_site ) ) {
			return array();
		}

		$now = bwx_forge_now();

		return array(
			'id'               => Ids::create( self::PREFIX ),
			'step_id'          => $step_id,
			'client_site_id'   => $site_id,
			'client_id'        => (string) ( $attachment['client_id'] ?? '' ),
			'original_name'    => self::label( (string) ( $attachment['original_name'] ?? '' ) ),
			'stored_name'      => $stored_name,
			'mime_type'        => (string) ( $attachment['mime_type'] ?? '' ),
			'size_bytes'       => max( 0, (int) ( $attachment['size_bytes'] ?? 0 ) ),
			'checksum'         => (string) ( $attachment['checksum'] ?? '' ),
			'uploaded_by'      => $uploaded_by,
			'uploaded_site'    => mb_substr( $uploaded_site, 0, 32 ),
			'source_interface' => (string) ( $attachment['source_interface'] ?? '' ),
			'uploaded_at'      => $now,
			'retention_until'  => 0,
		);
	}

	/**
	 * Writes an attachment down.
	 *
	 * @param array<string, mixed> $attachment As {@see self::row_from()} takes.
	 * @return array<string, mixed>|null The row, or null when it was refused.
	 */
	public static function record( array $attachment ): ?array {
		global $wpdb;

		$row = self::row_from( $attachment );

		if ( array() === $row ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; there is no core API for it.
		$inserted = $wpdb->insert( Schema::onboarding_evidence_table(), $row, Formats::for_row( $row ) );

		return $inserted ? $row : null;
	}

	/**
	 * Everything attached to one step, oldest first.
	 *
	 * The site is a parameter rather than something read off the step, so that
	 * a caller holding only a step id from somewhere else gets nothing.
	 *
	 * @param string $step_id        The step.
	 * @param string $client_site_id The site it must belong to.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_step( string $step_id, string $client_site_id ): array {
		global $wpdb;

		if ( '' === $step_id || '' === $client_site_id ) {
			return array();
		}

		$table = Schema::onboarding_evidence_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE step_id = %s AND client_site_id = %s ORDER BY uploaded_at ASC", $step_id, $client_site_id ), ARRAY_A );

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * One attachment, if it belongs to the site asking.
	 *
	 * @param string $id             The attachment.
	 * @param string $client_site_id The site it must belong to.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $id, string $client_site_id ): ?array {
		global $wpdb;

		if ( '' === $id || '' === $client_site_id ) {
			return null;
		}

		$table = Schema::onboarding_evidence_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %s AND client_site_id = %s", $id, $client_site_id ), ARRAY_A );

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * When evidence may be deleted, given when the relationship ended.
	 *
	 * @param int $relationship_ended_at When it ended, or 0 while it is live.
	 * @return int Timestamp, or 0 meaning "no end date yet".
	 */
	public static function retention_until( int $relationship_ended_at ): int {
		if ( $relationship_ended_at <= 0 ) {
			return 0;
		}

		return $relationship_ended_at + ( self::RETENTION_DAYS * DAY_IN_SECONDS );
	}

	/**
	 * Whether the retention period has run out.
	 *
	 * Asked by the documented manual process, and by nothing automatic. A true
	 * here permits a deletion; it does not perform one.
	 *
	 * @param int $retention_until As stored on the row.
	 * @param int $now             Now.
	 * @return bool
	 */
	public static function may_be_deleted( int $retention_until, int $now ): bool {
		return $retention_until > 0 && $now > $retention_until;
	}

	/**
	 * The name a person recognises, made safe to show and short enough to store.
	 *
	 * Kept as a label only. Nothing reads it as a path — {@see EvidenceStore}
	 * names the file on disk — so this strips directories rather than trusting
	 * them, and then stops caring.
	 *
	 * @param string $name What the uploader called it.
	 * @return string
	 */
	private static function label( string $name ): string {
		$base = basename( str_replace( '\\', '/', $name ) );

		return mb_substr( $base, 0, self::MAX_NAME );
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
			'step_id'          => (string) ( $row['step_id'] ?? '' ),
			'client_site_id'   => (string) ( $row['client_site_id'] ?? '' ),
			'client_id'        => (string) ( $row['client_id'] ?? '' ),
			'original_name'    => (string) ( $row['original_name'] ?? '' ),
			'stored_name'      => (string) ( $row['stored_name'] ?? '' ),
			'mime_type'        => (string) ( $row['mime_type'] ?? '' ),
			'size_bytes'       => (int) ( $row['size_bytes'] ?? 0 ),
			'checksum'         => (string) ( $row['checksum'] ?? '' ),
			'uploaded_by'      => (int) ( $row['uploaded_by'] ?? 0 ),
			'uploaded_site'    => (string) ( $row['uploaded_site'] ?? '' ),
			'from_client'      => '' !== (string) ( $row['uploaded_site'] ?? '' ),
			'source_interface' => (string) ( $row['source_interface'] ?? '' ),
			'uploaded_at'      => (int) ( $row['uploaded_at'] ?? 0 ),
			'retention_until'  => (int) ( $row['retention_until'] ?? 0 ),
		);
	}
}
