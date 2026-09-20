<?php
/**
 * A site's support: its position, its periods, its hours, over REST.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Commerce\Assignments;
use Blueworx\Forge\Commerce\Ledger;
use Blueworx\Forge\Commerce\Packages;
use Blueworx\Forge\Commerce\ProRata;
use Blueworx\Forge\Commerce\Sales;
use Blueworx\Forge\Commerce\Support;
use Blueworx\Forge\Commerce\Terms;
use Blueworx\Forge\Commerce\WorkHours;
use Blueworx\Forge\Meetings\Diary;
use Blueworx\Forge\Meetings\MeetingHours;
use Blueworx\Forge\Meetings\Series;
use Blueworx\Forge\Tenancy\ClientSites;
use Blueworx\Forge\Work\Items;
use WP_REST_Request;

/**
 * What the Support admin page did, as routes, so the studio app is the one
 * place it is done (PR 5 of spec 2026-09-16). The writes are the ones that
 * page made; the rules are `Commerce\Assignments`', `Commerce\Sales`' and
 * `Commerce\Entries`' own, and nothing is checked here that they do not
 * check themselves — a null from the domain becomes the sentence the page
 * showed, and that is all. Every answer is the whole picture for one site,
 * so a screen that has just written never has to read again.
 *
 * COMM-2 is visible in the shape: the preview route and the assign route
 * call the same `ProRata::preview()`, so the figure somebody agreed to and
 * the figure the ledger receives are one figure.
 *
 * Administrators only, reads included: the admin page it replaces requires
 * the same, and a site's commercial record is configuration, not work.
 */
final class SupportController {

	/**
	 * The idempotency operations for the three writes a replay would double —
	 * a period, or a ledger entry. Each is scoped by site when used, so one
	 * retry key cannot answer another site's replay. Suspend, resume and
	 * cancel carry no key: each is idempotent by nature, because a second
	 * call finds nothing to do and is refused.
	 */
	private const ASSIGN_OPERATION = 'support.assign';
	private const TOP_UP_OPERATION = 'support.top_up';
	private const ADJUST_OPERATION = 'support.adjust';

	/**
	 * Registers this controller's routes.
	 *
	 * @param string $route_namespace REST namespace.
	 */
	public static function register_routes( string $route_namespace ): void {
		$scope = array(
			'kind'   => Boundary::SCOPE_OPEN,
			'reason' => 'Support is administrator configuration of a site\'s commercial record, made from the studio for any site.',
		);

		$site = '/client-sites/(?P<site_id>[A-Za-z0-9_\-]+)/support';

		$routes = array(
			array( 'GET', '', 'read' ),
			array( 'GET', '/preview', 'preview' ),
			array( 'POST', '', 'assign' ),
			array( 'POST', '/top-up', 'top_up' ),
			array( 'POST', '/adjust', 'adjust' ),
			array( 'POST', '/suspend', 'suspend' ),
			array( 'POST', '/resume', 'resume' ),
			array( 'POST', '/cancel', 'cancel' ),
		);

		foreach ( $routes as list( $method, $path, $callback ) ) {
			Server::register_route(
				$route_namespace,
				$site . $path,
				array(
					'methods'             => $method,
					'callback'            => array( self::class, $callback ),
					'permission_callback' => array( Permissions::class, 'manage' ),
					'scope'               => $scope,
				)
			);
		}
	}

	/**
	 * The whole picture for one site.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function read( WP_REST_Request $request ) {
		$site = ClientSites::get( (string) $request['site_id'] );

		if ( null === $site ) {
			return Boundary::absent( 'client_site' );
		}

		return rest_ensure_response( self::answer( $site ) );
	}

	/**
	 * What assigning would write, before it is written (COMM-2).
	 *
	 * With an end date, the pro-rata sum; without one, the whole package to
	 * the end of its own term. Either way these are exactly the numbers
	 * {@see self::assign()} then hands to the domain.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function preview( WP_REST_Request $request ) {
		$site = ClientSites::get( (string) $request['site_id'] );

		if ( null === $site ) {
			return Boundary::absent( 'client_site' );
		}

		$version = Packages::version( sanitize_text_field( (string) $request->get_param( 'package_version' ) ) );

		if ( null === $version ) {
			return Errors::rest( 'unknown_package', __( 'There is no such package.', 'blueworx-forge' ), 404 );
		}

		$from  = sanitize_text_field( (string) $request->get_param( 'from' ) );
		$until = sanitize_text_field( (string) $request->get_param( 'until' ) );

		// The admin form's From field defaults to today, so the preview of
		// nothing typed is the preview of today.
		if ( '' === $from ) {
			$from = gmdate( 'Y-m-d', bwx_forge_now() );
		}

		return rest_ensure_response( array_merge( array( 'ok' => true ), self::grant( $version, $from, $until ) ) );
	}

	/**
	 * Puts the site on a package.
	 *
	 * Replay-safe under an idempotency key: a resend that assigned again
	 * would close the period it just opened and grant the hours twice.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function assign( WP_REST_Request $request ) {
		$site = ClientSites::get( (string) $request['site_id'] );

		if ( null === $site ) {
			return Boundary::absent( 'client_site' );
		}

		$key       = (string) $request->get_header( Idempotency::HEADER );
		$operation = self::ASSIGN_OPERATION . ':' . (string) $site['id'];
		$replay    = self::replay( $key, $operation );

		if ( null !== $replay ) {
			return $replay;
		}

		$body    = (array) $request->get_json_params();
		$version = Packages::version( sanitize_text_field( (string) ( $body['package_version'] ?? '' ) ) );
		$from    = sanitize_text_field( (string) ( $body['starts_on'] ?? '' ) );
		$until   = sanitize_text_field( (string) ( $body['ends_on'] ?? '' ) );

		if ( null === $version || '' === $from ) {
			return self::refused();
		}

		$values = array(
			'client_site_id'     => (string) $site['id'],
			'client_id'          => (string) $site['client_id'],
			'package_version_id' => (string) $version['id'],
			'starts_on'          => $from,
			'note'               => sanitize_text_field( (string) ( $body['note'] ?? '' ) ),
		);

		/*
		 * COMM-1: an ordinary assignment starts its own twelve-month term and
		 * gets the whole package. A date here means the client asked to align
		 * with a shared renewal, which is the only case pro-rata applies to.
		 * The figures come from the same call the preview route made, so the
		 * two cannot differ.
		 */
		if ( '' !== $until ) {
			$sum = self::grant( $version, $from, $until );

			$values['ends_on']       = $until;
			$values['hours_granted'] = (float) $sum['hours'];
			$values['price_charged'] = (int) $sum['price'];
			$values['prorated']      = true;
		}

		$assigned = Assignments::assign( $values, get_current_user_id() );

		if ( null === $assigned ) {
			return self::refused();
		}

		$response = array_merge( array( 'assignment' => self::period( $assigned ) ), self::answer( $site ) );

		self::remember( $key, $operation, $response );

		return rest_ensure_response( $response );
	}

	/**
	 * Sells the site more hours (#157).
	 *
	 * Replay-safe under an idempotency key: a resend would sell them twice.
	 * Hours of nought or fewer are the domain's refusal, not this route's.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function top_up( WP_REST_Request $request ) {
		$site = ClientSites::get( (string) $request['site_id'] );

		if ( null === $site ) {
			return Boundary::absent( 'client_site' );
		}

		$key       = (string) $request->get_header( Idempotency::HEADER );
		$operation = self::TOP_UP_OPERATION . ':' . (string) $site['id'];
		$replay    = self::replay( $key, $operation );

		if ( null !== $replay ) {
			return $replay;
		}

		$body  = (array) $request->get_json_params();
		$added = Sales::top_up(
			(string) $site['id'],
			(float) ( $body['hours'] ?? 0 ),
			sanitize_text_field( (string) ( $body['reason'] ?? '' ) ),
			get_current_user_id()
		);

		if ( null === $added ) {
			return self::refused();
		}

		$response = array_merge( array( 'entry' => self::entry( $added ) ), self::answer( $site ) );

		self::remember( $key, $operation, $response );

		return rest_ensure_response( $response );
	}

	/**
	 * Corrects the balance by hand, for a stated reason (#157, CAP-3).
	 *
	 * Replay-safe under an idempotency key: a resend would correct it twice.
	 * The missing reason is refused here as well as in the ledger, so the
	 * person gets a sentence about the reason rather than a flat "that could
	 * not be done" — the ledger is still the thing that enforces it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function adjust( WP_REST_Request $request ) {
		$site = ClientSites::get( (string) $request['site_id'] );

		if ( null === $site ) {
			return Boundary::absent( 'client_site' );
		}

		$key       = (string) $request->get_header( Idempotency::HEADER );
		$operation = self::ADJUST_OPERATION . ':' . (string) $site['id'];
		$replay    = self::replay( $key, $operation );

		if ( null !== $replay ) {
			return $replay;
		}

		$body   = (array) $request->get_json_params();
		$reason = sanitize_text_field( (string) ( $body['reason'] ?? '' ) );

		if ( '' === $reason ) {
			return Errors::rest( 'reason_required', __( 'Say why.', 'blueworx-forge' ), 400 );
		}

		$made = Sales::adjust( (string) $site['id'], (float) ( $body['hours'] ?? 0 ), $reason, get_current_user_id() );

		if ( null === $made ) {
			return self::refused();
		}

		$response = array_merge( array( 'entry' => self::entry( $made ) ), self::answer( $site ) );

		self::remember( $key, $operation, $response );

		return rest_ensure_response( $response );
	}

	/**
	 * Stops the site's cover from a date, leaving its hours alone.
	 *
	 * Idempotent by nature, so it carries no retry key: a second call finds
	 * the site already suspended and is refused.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function suspend( WP_REST_Request $request ) {
		$site = ClientSites::get( (string) $request['site_id'] );

		if ( null === $site ) {
			return Boundary::absent( 'client_site' );
		}

		$body    = (array) $request->get_json_params();
		$stopped = Assignments::suspend(
			(string) $site['id'],
			self::from( $body ),
			get_current_user_id(),
			sanitize_text_field( (string) ( $body['note'] ?? '' ) )
		);

		return null === $stopped ? self::refused() : rest_ensure_response( self::answer( $site ) );
	}

	/**
	 * Puts a suspended site back on cover from a date.
	 *
	 * Idempotent by nature, so it carries no retry key: a second call finds
	 * nothing suspended and is refused.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function resume( WP_REST_Request $request ) {
		$site = ClientSites::get( (string) $request['site_id'] );

		if ( null === $site ) {
			return Boundary::absent( 'client_site' );
		}

		$body = (array) $request->get_json_params();
		$back = Assignments::resume( (string) $site['id'], self::from( $body ), get_current_user_id() );

		return null === $back ? self::refused() : rest_ensure_response( self::answer( $site ) );
	}

	/**
	 * Ends the site's cover for good from a date. The hours stay (COMM-4).
	 *
	 * Idempotent by nature, so it carries no retry key: a second call finds
	 * nothing running and is refused.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function cancel( WP_REST_Request $request ) {
		$site = ClientSites::get( (string) $request['site_id'] );

		if ( null === $site ) {
			return Boundary::absent( 'client_site' );
		}

		$body = (array) $request->get_json_params();
		$done = Assignments::cancel( (string) $site['id'], self::from( $body ), get_current_user_id(), Support::CANCELLED );

		return $done ? rest_ensure_response( self::answer( $site ) ) : self::refused();
	}

	/* ------------------------------------------------------------ private */

	/**
	 * The date an action takes effect from, defaulting to today — as the
	 * admin page's forms do.
	 *
	 * @param array<string, mixed> $body The request body.
	 * @return string YYYY-MM-DD.
	 */
	private static function from( array $body ): string {
		$from = sanitize_text_field( (string) ( $body['from'] ?? '' ) );

		return '' === $from ? gmdate( 'Y-m-d', bwx_forge_now() ) : $from;
	}

	/**
	 * What a package version grants from a date: pro-rated to an end date
	 * when one is given, the whole package to its own term end when not.
	 *
	 * One method for both the preview and the write (COMM-2).
	 *
	 * @param array<string, mixed> $version The package version.
	 * @param string               $from    YYYY-MM-DD, the first day covered.
	 * @param string               $until   YYYY-MM-DD, the last day covered, or '' for a full term.
	 * @return array<string, mixed> hours, price, currency, ends_on, prorated.
	 */
	private static function grant( array $version, string $from, string $until ): array {
		if ( '' !== $until ) {
			$sum = ProRata::preview( $version, $from, $until );

			return array(
				'hours'    => (float) $sum['hours'],
				'price'    => (int) $sum['price'],
				'currency' => (string) $sum['currency'],
				'ends_on'  => $until,
				'prorated' => true,
			);
		}

		return array(
			'hours'    => round( (float) $version['hours'], 2 ),
			'price'    => (int) $version['price'],
			'currency' => (string) $version['currency'],
			'ends_on'  => ProRata::term_end( $from, (int) $version['validity_months'] ),
			'prorated' => false,
		);
	}

	/**
	 * The refusal for anything the domain would not do from where the site
	 * is: an unknown package, a missing date, a suspension of a site already
	 * suspended, an adjustment below nought.
	 *
	 * @return \WP_Error
	 */
	private static function refused() {
		return Errors::rest( 'support_refused', __( 'That could not be done from the site\'s current position.', 'blueworx-forge' ), 400 );
	}

	/**
	 * The remembered answer for a retry key, if there is one; an error when
	 * the key cannot be used; null when there is nothing to replay.
	 *
	 * @param string $key       The Idempotency-Key header, or ''.
	 * @param string $operation The operation, scoped by site.
	 * @return \WP_REST_Response|\WP_Error|null
	 */
	private static function replay( string $key, string $operation ) {
		if ( '' === $key ) {
			return null;
		}

		if ( ! Idempotency::is_valid_key( $key ) ) {
			return Errors::rest( 'invalid_idempotency_key', __( 'That retry key cannot be used.', 'blueworx-forge' ), 400 );
		}

		$replay = Idempotency::replay( $operation, $key );

		return null === $replay ? null : rest_ensure_response( $replay );
	}

	/**
	 * Keeps an answer for its retry key, when there was one.
	 *
	 * @param string               $key       The Idempotency-Key header, or ''.
	 * @param string               $operation The operation, scoped by site.
	 * @param array<string, mixed> $response  What was answered.
	 */
	private static function remember( string $key, string $operation, array $response ): void {
		if ( '' !== $key ) {
			Idempotency::remember( $operation, $key, $response );
		}
	}

	/**
	 * What every route answers with: the site, its position today, every
	 * period it has been in, every hour it has, and the packages it could be
	 * put on — the four panels of the admin page, as one answer.
	 *
	 * @param array<string, mixed> $site The site.
	 * @return array<string, mixed>
	 */
	private static function answer( array $site ): array {
		$id     = (string) $site['id'];
		$today  = gmdate( 'Y-m-d', bwx_forge_now() );
		$answer = Assignments::entitlement_on( $id, $today );
		$state  = (string) $answer['state'];

		return array(
			'ok'       => true,
			'site'     => array(
				'id'        => $id,
				'name'      => (string) $site['name'],
				'client_id' => (string) $site['client_id'],
			),
			'position' => array(
				'state'         => $state,
				'label'         => Support::label( $state ),
				'may_use_hours' => (bool) $answer['may_use_hours'],
				'covered_until' => '' !== (string) $answer['ends_on'] ? (string) $answer['ends_on'] : null,
				'balance'       => Ledger::balance( $id ),
			),
			'periods'  => array_map( array( self::class, 'period' ), Assignments::for_site( $id ) ),
			'ledger'   => array_map( array( self::class, 'entry' ), Ledger::for_site( $id ) ),
			'packages' => self::packages(),
		);
	}

	/**
	 * One period as the screen shows it: the row, with the package named.
	 *
	 * @param array<string, mixed> $period The period.
	 * @return array<string, mixed>
	 */
	private static function period( array $period ): array {
		$version = Packages::version( (string) $period['package_version_id'] );

		return array_merge(
			$period,
			array(
				'package_name'    => null === $version ? '' : (string) $version['name'],
				'package_version' => null === $version ? 0 : (int) $version['version'],
			)
		);
	}

	/**
	 * One ledger entry as the screen shows it: the row, with the day it
	 * counts from, what it was against (#158), and what that thing is called
	 * (2026-09-20) — "Weekly check-in on 2026-09-26", or the task's title —
	 * because a column of "Meeting reserved" lines with nothing to tell them
	 * apart explains no balance to anybody.
	 *
	 * @param array<string, mixed> $entry The entry.
	 * @return array<string, mixed>
	 */
	private static function entry( array $entry ): array {
		return array_merge(
			$entry,
			array(
				'when'   => gmdate( 'Y-m-d', (int) $entry['occurred_at'] ),
				'source' => (string) $entry['source_type'] . ':' . (string) $entry['source_id'],
				'about'  => self::about( (string) $entry['source_type'], (string) $entry['source_id'] ),
			)
		);
	}

	/**
	 * What a ledger line was for, in words.
	 *
	 * Looked up once per thing, not once per line: a year's ledger names the
	 * same weekly meeting fifty times.
	 *
	 * @param string $type The source type.
	 * @param string $id   The source id.
	 * @return string The name, or '' where there is nothing to name.
	 */
	private static function about( string $type, string $id ): string {
		static $names = array();

		$key = $type . ':' . $id;

		if ( isset( $names[ $key ] ) ) {
			return $names[ $key ];
		}

		$names[ $key ] = '';

		if ( MeetingHours::SOURCE === $type ) {
			$meeting = Diary::get( $id );
			$series  = null === $meeting ? null : Series::get( (string) $meeting['series_id'] );

			if ( null !== $meeting && null !== $series ) {
				$names[ $key ] = sprintf(
					/* translators: 1: the meeting's title, 2: its date. */
					__( '%1$s on %2$s', 'blueworx-forge' ),
					(string) $series['title'],
					(string) $meeting['on']
				);
			}
		} elseif ( WorkHours::SOURCE === $type ) {
			$item = Items::get( $id );

			$names[ $key ] = null === $item ? '' : (string) $item['title'];
		}

		return $names[ $key ];
	}

	/**
	 * What the site could be put on: every package on the shelf, with the
	 * version in force. A package with no version yet is not an offer and is
	 * left out, as the admin page leaves it out.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function packages(): array {
		$packages = Packages::all( Terms::ACTIVE );
		$versions = Packages::current_versions( array_column( $packages, 'id' ) );
		$offered  = array();

		foreach ( $packages as $package ) {
			$version = $versions[ (string) $package['id'] ] ?? null;

			if ( null === $version ) {
				continue;
			}

			$offered[] = array(
				'id'      => (string) $package['id'],
				'name'    => (string) $package['name'],
				'current' => array(
					'id'              => (string) $version['id'],
					'hours'           => (float) $version['hours'],
					'price'           => (int) $version['price'],
					'currency'        => (string) $version['currency'],
					'validity_months' => (int) $version['validity_months'],
				),
			);
		}

		return $offered;
	}
}
