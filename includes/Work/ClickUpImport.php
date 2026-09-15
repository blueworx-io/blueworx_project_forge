<?php
/**
 * The one-time import of the open ClickUp work (#346).
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Work;

use Blueworx\Forge\Tenancy\Studio;
use Blueworx\Forge\Tenancy\Users;

/**
 * The studio's own open work lived in ClickUp until September 2026. This
 * brings it across with a plugin update rather than a script run against the
 * live site: the rows ship inside the plugin, the first request after the
 * update creates them, and an option remembers that it happened.
 *
 * Three things keep it a one-off rather than a hazard:
 *
 * - **It runs on one host.** The rows are blueworx.io's work and nobody
 *   else's, so a test site, CI, or a second studio never gets them.
 * - **It runs once.** The option is written after the run, whatever the
 *   count, so a row that fails is looked into rather than retried on every
 *   page load.
 * - **It leaves no override marks.** Each item is placed in its ClickUp
 *   stage the way the recurring engine places a chore in Up Next, with the
 *   origin written into the item's history.
 *
 * People are named by email in the file and resolved here, because a person's
 * id is a property of the database that has them, not of the person.
 */
final class ClickUpImport {

	/**
	 * Written once the import has run. Holds the time it ran.
	 */
	public const OPTION = 'bwx_forge_import_clickup_2026_09';

	/**
	 * The only site whose work this is.
	 */
	public const HOST = 'blueworx.io';

	/**
	 * The shipped rows, relative to the plugin's includes directory.
	 */
	private const FILE = 'Data/clickup-blueworx-2026-09.json';

	/**
	 * Runs the import if this is the site, the time, and the first time.
	 */
	public static function maybe_run(): void {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		if ( ! self::should_run( $host, get_option( self::OPTION, false ), Studio::site_id() ) ) {
			return;
		}

		self::run();
	}

	/**
	 * Whether the import belongs here and has not happened yet.
	 *
	 * @param string $host    The site's host name.
	 * @param mixed  $done    The option's value; false until the import has run.
	 * @param string $site_id The studio site's id, empty before it exists.
	 * @return bool
	 */
	public static function should_run( string $host, $done, string $site_id ): bool {
		return self::HOST === $host && false === $done && '' !== $site_id;
	}

	/**
	 * Creates every row on the studio site and marks the import done.
	 *
	 * @return int How many items were made.
	 */
	public static function run(): int {
		$site_id   = Studio::site_id();
		$client_id = Studio::client_id();
		$people    = self::people();
		$made      = 0;

		foreach ( self::rows() as $row ) {
			$item = Items::create( $site_id, $client_id, self::values( $row, $people ), 0 );

			if ( null === $item ) {
				continue;
			}

			Transition::record_creation( $item, 0 );
			Transition::place( $item, (string) $row['stage'], 0, self::why( $row ) );

			++$made;
		}

		update_option( self::OPTION, wp_date( 'Y-m-d H:i' ) );

		return $made;
	}

	/**
	 * The shipped rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function rows(): array {
		$rows = json_decode( (string) file_get_contents( __DIR__ . '/../' . self::FILE ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A file the plugin ships, not a remote one.

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * A row as the values a work item is created from.
	 *
	 * Every row is a Feature: standalone work with no parent, which is the
	 * level ClickUp's flat list maps to. The stage is not among the values,
	 * because a stage is entered, never written.
	 *
	 * @param array<string, mixed>  $row    A shipped row.
	 * @param array<string, string> $people Forge person id by email.
	 * @return array<string, mixed>
	 */
	public static function values( array $row, array $people ): array {
		return array(
			'title'           => (string) $row['title'],
			'level'           => Levels::FEATURE,
			'work_type'       => (string) $row['work_type'],
			'problem'         => (string) ( $row['problem'] ?? '' ),
			'references'      => (string) ( $row['references'] ?? '' ),
			'priority'        => (string) ( $row['priority'] ?? 'normal' ),
			'primary_user_id' => $people[ (string) ( $row['primary'] ?? '' ) ] ?? '',
			'reviewer_id'     => $people[ (string) ( $row['reviewer'] ?? '' ) ] ?? '',
			'planned_start'   => (string) ( $row['planned_start'] ?? '' ),
			'planned_due'     => (string) ( $row['planned_due'] ?? '' ),
			'hours_primary'   => (float) ( $row['hours_primary'] ?? 0 ),
		);
	}

	/**
	 * What the item's history says about where it came from.
	 *
	 * @param array<string, mixed> $row A shipped row.
	 * @return string
	 */
	public static function why( array $row ): string {
		return sprintf( 'Imported from ClickUp (was "%s" in %s).', (string) $row['clickup_status'], (string) $row['clickup_list'] );
	}

	/**
	 * Forge person id by email, for everyone the rows name.
	 *
	 * @return array<string, string>
	 */
	private static function people(): array {
		$people = array();

		foreach ( self::rows() as $row ) {
			foreach ( array( 'primary', 'reviewer' ) as $seat ) {
				$email = strtolower( (string) ( $row[ $seat ] ?? '' ) );

				if ( '' === $email || isset( $people[ $email ] ) ) {
					continue;
				}

				$person           = Users::by_email( $email );
				$people[ $email ] = null === $person ? '' : (string) $person['id'];
			}
		}

		return $people;
	}
}
