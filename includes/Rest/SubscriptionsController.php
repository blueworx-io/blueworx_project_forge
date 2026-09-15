<?php
/**
 * The subscriptions routes.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Commerce\SureCart\Connections;
use Blueworx\Forge\Commerce\SureCart\Subscriptions;
use Blueworx\Forge\Commerce\SureCart\Sync;
use Blueworx\Forge\Recurring\Occurrences;
use Blueworx\Forge\Recurring\Sources;
use Blueworx\Forge\Tenancy\ClientSites;
use Blueworx\Forge\Tenancy\Reach;
use Blueworx\Forge\Tenancy\Studio;
use Blueworx\Forge\Work\Items;

/**
 * What SureCart says about every connected store's subscriptions, and which
 * renewal reminder each one has on the studio's board. The studio's own, so
 * the same reach rule as recurring tasks: anyone who reaches the studio's
 * site may read; only an administrator may force a refresh.
 */
final class SubscriptionsController {

	/**
	 * Registers this controller's routes.
	 *
	 * @param string $route_namespace REST namespace.
	 */
	public static function register_routes( string $route_namespace ): void {
		Server::register_route(
			$route_namespace,
			'/subscriptions',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'index' ),
				'permission_callback' => array( Permissions::class, 'signed_in' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_LIST,
					'reason' => 'Subscriptions belong to the studio; the callback refuses anyone whose reach does not include the studio\'s own site.',
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/subscriptions/refresh',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'refresh' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_OPEN,
					'reason' => 'Asks every connected store again, now; administrators only, and it names no record.',
				),
			)
		);
	}

	/**
	 * Every subscription, with its store and its reminder.
	 *
	 * @return \WP_REST_Response
	 */
	public static function index() {
		$site_id = Studio::site_id();
		$site    = '' === $site_id ? null : ClientSites::get( $site_id );

		if ( null === $site || ! Reach::reaches_site( Boundary::current(), (string) $site['client_id'], (string) $site['id'] ) ) {
			return rest_ensure_response(
				array(
					'ok'            => true,
					'denied'        => true,
					'connections'   => array(),
					'subscriptions' => array(),
				)
			);
		}

		// Fresh when opened, if an hour has passed; otherwise what was kept.
		Sync::maybe();

		return rest_ensure_response(
			array(
				'ok'            => true,
				'denied'        => false,
				'connections'   => Connections::all(),
				'subscriptions' => self::with_reminders( Subscriptions::all(), $site_id ),
			)
		);
	}

	/**
	 * Asks every store again, now.
	 *
	 * @return \WP_REST_Response
	 */
	public static function refresh() {
		$results = array();

		foreach ( Connections::all() as $connection ) {
			$results[ (string) $connection['id'] ] = Sync::refresh( $connection );
		}

		return rest_ensure_response(
			array(
				'ok'      => true,
				'results' => $results,
			)
		);
	}

	/**
	 * Each subscription with the reminder task it last produced, if any.
	 *
	 * @param array<int, array<string, mixed>> $subscriptions The rows.
	 * @param string                           $site_id       The studio's site.
	 * @return array<int, array<string, mixed>>
	 */
	private static function with_reminders( array $subscriptions, string $site_id ): array {
		$by_ref = array();

		foreach ( Sources::for_site( $site_id, Sources::SUBSCRIPTION ) as $source ) {
			$by_ref[ (string) $source['source_ref'] ] = $source;
		}

		$latest = Occurrences::latest_for( array_column( $by_ref, 'id' ) );
		$items  = Items::summaries_for( array_column( $latest, 'work_item_id' ) );

		foreach ( $subscriptions as $index => $subscription ) {
			$ref    = (string) $subscription['connection_id'] . ':' . (string) $subscription['external_id'];
			$source = $by_ref[ $ref ] ?? null;
			$last   = null === $source ? null : ( $latest[ (string) $source['id'] ] ?? null );
			$item   = null === $last ? null : ( $items[ (string) $last['work_item_id'] ] ?? null );

			$subscriptions[ $index ]['reminder'] = null === $item ? null : array(
				'work_item_id' => (string) $item['id'],
				'due_on'       => (string) $last['due_on'],
				'stage'        => (string) $item['stage'],
			);
		}

		return $subscriptions;
	}
}
