<?php
/**
 * Which milestone and project a piece of work sits under.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Work;

/**
 * #669. Walks `parent_id` upward. The hours statement needs each task's
 * milestone and project, and whether it falls under a chosen one.
 *
 * The walk gives up after a fixed number of hops, so a loop in bad data ends
 * the walk rather than the request.
 */
final class Lineage {

	/**
	 * Most steps up the tree a walk will take.
	 */
	private const MAX_HOPS = 10;

	/**
	 * The milestone and project above an item.
	 *
	 * @param array<string, array<string, mixed>> $items Rows keyed by id.
	 * @param string                              $id    The item to start from.
	 * @return array{milestone: array{id: string, title: string}|null, project: array{id: string, title: string}|null}
	 */
	public static function of( array $items, string $id ): array {
		$found   = array(
			'milestone' => null,
			'project'   => null,
		);
		$current = $items[ $id ] ?? null;

		for ( $hop = 0; $hop < self::MAX_HOPS && null !== $current; $hop++ ) {
			$parent = (string) ( $current['parent_id'] ?? '' );

			if ( '' === $parent || ! isset( $items[ $parent ] ) ) {
				break;
			}

			$current = $items[ $parent ];
			$level   = (string) ( $current['level'] ?? '' );

			if ( Levels::MILESTONE === $level && null === $found['milestone'] ) {
				$found['milestone'] = array(
					'id'    => (string) $current['id'],
					'title' => (string) ( $current['title'] ?? '' ),
				);
			} elseif ( Levels::PROJECT === $level && null === $found['project'] ) {
				$found['project'] = array(
					'id'    => (string) $current['id'],
					'title' => (string) ( $current['title'] ?? '' ),
				);
			}
		}

		return $found;
	}

	/**
	 * Whether an item is a chosen one or anywhere beneath it.
	 *
	 * @param array<string, array<string, mixed>> $items Rows keyed by id.
	 * @param string                              $root  The ancestor to look for.
	 * @param string                              $id    The item to start from.
	 * @return bool
	 */
	public static function under( array $items, string $root, string $id ): bool {
		$current = $id;

		for ( $hop = 0; $hop <= self::MAX_HOPS; $hop++ ) {
			if ( $current === $root ) {
				return true;
			}

			if ( ! isset( $items[ $current ] ) ) {
				return false;
			}

			$current = (string) ( $items[ $current ]['parent_id'] ?? '' );

			if ( '' === $current ) {
				return false;
			}
		}

		return false;
	}

	/**
	 * Every item on a site, archived ones included, trimmed to what the walk reads.
	 *
	 * @param string $client_site_id The site.
	 * @return array<string, array{id: string, parent_id: string, level: string, title: string, archived: bool}>
	 */
	public static function for_site( string $client_site_id ): array {
		$rows = array();

		foreach ( Items::for_site( $client_site_id, array( 'include_archived' => true ) ) as $item ) {
			$id          = (string) $item['id'];
			$rows[ $id ] = array(
				'id'        => $id,
				'parent_id' => (string) ( $item['parent_id'] ?? '' ),
				'level'     => (string) ( $item['level'] ?? '' ),
				'title'     => (string) ( $item['title'] ?? '' ),
				'archived'  => (bool) ( $item['archived'] ?? false ),
			);
		}

		return $rows;
	}
}
