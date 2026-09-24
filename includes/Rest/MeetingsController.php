<?php
/**
 * A site's standing meetings, the twelve weeks they imply, and what became
 * of each, over REST.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Meetings\Diary;
use Blueworx\Forge\Meetings\Events;
use Blueworx\Forge\Meetings\Hours;
use Blueworx\Forge\Meetings\MeetingHours;
use Blueworx\Forge\Meetings\Occurrence;
use Blueworx\Forge\Meetings\Series;
use Blueworx\Forge\Meetings\Validate;
use Blueworx\Forge\Tenancy\ClientSites;
use Blueworx\Forge\Tenancy\Users;
use WP_REST_Request;

/**
 * What the Meetings admin page did, as routes, so the studio app is the one
 * place it is done (PR 6 of spec 2026-09-17). The writes are the ones that
 * page made; the rules are `Meetings\Validate`'s, `Meetings\Series`' and
 * `Meetings\Diary`'s own, and nothing is checked here that they do not check
 * themselves — a null from the domain becomes the sentence the page showed,
 * and that is all.
 *
 * **Every answer is settled first.** There is no cron in this plugin, so
 * {@see Hours::reconcile_site()} runs before the picture is drawn, on a read
 * as on a write — which is the moment somebody is looking at a client's
 * meetings and the moment their balance most needs to be right. It is
 * idempotent, so a read that finds nothing to do writes nothing. Marking a
 * meeting held has to draw its hours, cancelling has to give them back, and
 * ending a series has to release everything its remaining meetings held; all
 * three are the same question asked of the reconcile, not three
 * implementations of one rule.
 *
 * Every answer is the whole picture for one site, so a screen that has just
 * written never has to read again.
 *
 * Administrators only, reads included: the admin page it replaces requires
 * the same, and a site's standing meetings are configuration, not work.
 */
final class MeetingsController {

	/**
	 * The idempotency operation for the one write a replay would double: a
	 * second series. Scoped by site when used, so one retry key cannot answer
	 * another site's replay. Ending a series carries a record version instead,
	 * and a move or a settle is keyed by its slot: the same change against the
	 * same slot lands on the same row, so a resend changes nothing.
	 */
	private const ADD_OPERATION = 'meetings.series.create';

	/**
	 * Registers this controller's routes.
	 *
	 * @param string $route_namespace REST namespace.
	 */
	public static function register_routes( string $route_namespace ): void {
		$scope = array(
			'kind'   => Boundary::SCOPE_OPEN,
			'reason' => 'Meetings are administrator configuration of a site\'s standing arrangements, made from the studio for any site.',
		);

		$site = '/client-sites/(?P<site_id>[A-Za-z0-9_\-]+)/meetings';
		$slot = '/(?P<series_id>[A-Za-z0-9_\-]+)/(?P<slot>[A-Za-z0-9_\-]+)';

		$routes = array(
			array( 'GET', '', 'read' ),
			array( 'POST', '/series', 'add_series' ),
			array( 'POST', '/series/(?P<series_id>[A-Za-z0-9_\-]+)', 'edit_series' ),
			array( 'POST', '/series/(?P<series_id>[A-Za-z0-9_\-]+)/end', 'end_series' ),
			array( 'POST', $slot . '/move', 'move' ),
			array( 'POST', $slot . '/settle', 'settle' ),
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
	 * The whole picture for one site, settled first.
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
	 * Starts a standing meeting.
	 *
	 * Replay-safe under an idempotency key: a resend that created again would
	 * be a second series, and twelve weeks of meetings holding hours twice.
	 * The body is the eleven inputs {@see Validate::series()} reads, less the
	 * site, which is the path.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function add_series( WP_REST_Request $request ) {
		$site = ClientSites::get( (string) $request['site_id'] );

		if ( null === $site ) {
			return Boundary::absent( 'client_site' );
		}

		$key       = (string) $request->get_header( Idempotency::HEADER );
		$operation = self::ADD_OPERATION . ':' . (string) $site['id'];
		$replay    = self::replay( $key, $operation );

		if ( null !== $replay ) {
			return $replay;
		}

		$checked = self::checked( (array) $request->get_json_params(), (string) $site['id'] );

		if ( ! isset( $checked['values'] ) ) {
			return $checked['error'];
		}

		$created = Series::create( $checked['values'], (string) $site['client_id'], get_current_user_id() );

		if ( null === $created ) {
			return self::refused();
		}

		$response = array_merge( array( 'added' => self::series( $created ) ), self::answer( $site ) );

		self::remember( $key, $operation, $response );

		return rest_ensure_response( $response );
	}

	/**
	 * Changes a running series (2026-09-24): the same inputs as adding one,
	 * against the record version it was read at.
	 *
	 * Settled afterwards through {@see self::answer()}, so the hours its coming
	 * meetings hold follow the new rule.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function edit_series( WP_REST_Request $request ) {
		$site = ClientSites::get( (string) $request['site_id'] );

		if ( null === $site ) {
			return Boundary::absent( 'client_site' );
		}

		$series = self::series_on_site( (string) $request['series_id'], (string) $site['id'] );

		if ( null === $series ) {
			return self::unknown_series();
		}

		$body  = (array) $request->get_json_params();
		$sent  = isset( $body[ Versioning::PARAM ] ) ? (int) $body[ Versioning::PARAM ] : null;
		$stale = Versioning::check( $sent, (int) $series['record_version'], self::series( $series ) );

		if ( null !== $stale ) {
			return $stale;
		}

		if ( Series::ACTIVE !== (string) $series['state'] ) {
			return self::refused();
		}

		$checked = self::checked( $body, (string) $site['id'] );

		if ( ! isset( $checked['values'] ) ) {
			return $checked['error'];
		}

		if ( null === Series::update( (string) $series['id'], $checked['values'], (int) $sent ) ) {
			return self::refused();
		}

		return rest_ensure_response( self::answer( $site ) );
	}

	/**
	 * Stops a series.
	 *
	 * Carries the record version it was read at, so two people ending and
	 * editing the same series do not cross. Settled afterwards through
	 * {@see self::answer()}, which is what gives back the hours its remaining
	 * meetings were holding: ending a series and leaving a client's balance
	 * committed to meetings that will never happen is the failure that line
	 * exists to stop.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function end_series( WP_REST_Request $request ) {
		$site = ClientSites::get( (string) $request['site_id'] );

		if ( null === $site ) {
			return Boundary::absent( 'client_site' );
		}

		$series = self::series_on_site( (string) $request['series_id'], (string) $site['id'] );

		if ( null === $series ) {
			return self::unknown_series();
		}

		$body = (array) $request->get_json_params();
		$sent = isset( $body[ Versioning::PARAM ] ) ? (int) $body[ Versioning::PARAM ] : null;

		$stale = Versioning::check( $sent, (int) $series['record_version'], self::series( $series ) );

		if ( null !== $stale ) {
			return $stale;
		}

		if ( ! Series::end( (string) $series['id'], (int) $sent ) ) {
			return self::refused();
		}

		return rest_ensure_response( self::answer( $site ) );
	}

	/**
	 * Moves one meeting to another day.
	 *
	 * Idempotent by nature, so it carries no retry key: the meeting is named
	 * by the slot the rule put it on, and {@see Diary::except()} writes the
	 * one row for that slot whether this is the first move or the fifth — a
	 * resend of the same date lands on the same row and changes nothing.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function move( WP_REST_Request $request ) {
		$site = ClientSites::get( (string) $request['site_id'] );

		if ( null === $site ) {
			return Boundary::absent( 'client_site' );
		}

		$series = self::series_on_site( (string) $request['series_id'], (string) $site['id'] );

		if ( null === $series ) {
			return self::unknown_series();
		}

		$body = (array) $request->get_json_params();
		$slot = (string) $request['slot'];
		$to   = sanitize_text_field( (string) ( $body['on'] ?? '' ) );

		if ( '' === $to ) {
			return self::refused();
		}

		$moved = Diary::except( $series, $slot, array( 'on' => $to ), Events::MOVED, get_current_user_id() );

		if ( null === $moved ) {
			return self::refused();
		}

		return rest_ensure_response( self::acted( $site, $series, $slot ) );
	}

	/**
	 * Says what became of one meeting: held, cancelled, nobody came, or
	 * scheduled again.
	 *
	 * Idempotent by nature, so it carries no retry key: keyed by slot, as
	 * {@see self::move()} is, so the same status sent twice lands on the same
	 * row and the reconcile finds nothing more to write.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function settle( WP_REST_Request $request ) {
		$site = ClientSites::get( (string) $request['site_id'] );

		if ( null === $site ) {
			return Boundary::absent( 'client_site' );
		}

		$series = self::series_on_site( (string) $request['series_id'], (string) $site['id'] );

		if ( null === $series ) {
			return self::unknown_series();
		}

		$body   = (array) $request->get_json_params();
		$slot   = (string) $request['slot'];
		$status = sanitize_text_field( (string) ( $body['status'] ?? '' ) );

		if ( ! Occurrence::exists( $status ) ) {
			return Errors::rest( 'invalid_status', __( 'A meeting is scheduled, held, cancelled or a no-show; nothing else.', 'blueworx-forge' ), 400 );
		}

		$actions = array(
			Occurrence::HELD      => Events::HELD,
			Occurrence::CANCELLED => Events::CANCELLED,
			Occurrence::NO_SHOW   => Events::NO_SHOW,
			Occurrence::SCHEDULED => Events::REINSTATED,
		);

		$settled = Diary::except( $series, $slot, array( 'status' => $status ), $actions[ $status ], get_current_user_id() );

		if ( null === $settled ) {
			return self::refused();
		}

		return rest_ensure_response( self::acted( $site, $series, $slot ) );
	}

	/* ------------------------------------------------------------ private */

	/**
	 * A series' inputs, read from a body and checked. Adding and editing take
	 * the same eleven, less the site, which is the path.
	 *
	 * @param array<string, mixed> $body    The request body.
	 * @param string               $site_id The site.
	 * @return array{values?: array<string, mixed>, error?: \WP_Error}
	 */
	private static function checked( array $body, string $site_id ): array {
		$checked = Validate::series(
			array(
				'client_site_id' => $site_id,
				'title'          => sanitize_text_field( (string) ( $body['title'] ?? '' ) ),
				'frequency'      => sanitize_text_field( (string) ( $body['frequency'] ?? '' ) ),
				'starts_on'      => sanitize_text_field( (string) ( $body['starts_on'] ?? '' ) ),
				'ends_on'        => sanitize_text_field( (string) ( $body['ends_on'] ?? '' ) ),
				'time_of_day'    => sanitize_text_field( (string) ( $body['time_of_day'] ?? '' ) ),
				'duration_mins'  => (int) ( $body['duration_mins'] ?? 0 ),
				'timezone'       => sanitize_text_field( (string) ( $body['timezone'] ?? '' ) ),
				'host_user_id'   => sanitize_text_field( (string) ( $body['host_user_id'] ?? '' ) ),
				'attendees'      => sanitize_textarea_field( (string) ( $body['attendees'] ?? '' ) ),
				// Who else comes, as people (2026-09-19).
				'attendee_ids'   => array_map( 'sanitize_text_field', array_map( 'strval', (array) ( $body['attendee_ids'] ?? array() ) ) ),
				'planned_hours'  => (float) ( $body['planned_hours'] ?? 0 ),
			)
		);

		if ( array() !== $checked['errors'] ) {
			return array(
				'error' => Errors::rest(
					'invalid_series',
					__( 'That series could not be saved — check the highlighted fields.', 'blueworx-forge' ),
					400,
					array( 'fields' => $checked['errors'] )
				),
			);
		}

		return array( 'values' => $checked['values'] );
	}

	/**
	 * One series, if it is this site's.
	 *
	 * The site is checked rather than trusted from the path: a series
	 * belonging to another client would otherwise be editable by anybody who
	 * could edit the request (ARCH-3).
	 *
	 * @param string $series_id The series.
	 * @param string $site_id   The site it should belong to.
	 * @return array<string, mixed>|null
	 */
	private static function series_on_site( string $series_id, string $site_id ): ?array {
		$series = Series::get( $series_id );

		if ( null === $series || (string) $series['client_site_id'] !== $site_id ) {
			return null;
		}

		return $series;
	}

	/**
	 * The answer to a move or a settle: the whole picture, settled, with the
	 * meeting that was acted on named — read back after the reconcile, so its
	 * ledger state is the one the reconcile just wrote.
	 *
	 * @param array<string, mixed> $site   The site.
	 * @param array<string, mixed> $series The series the meeting belongs to.
	 * @param string               $slot   The slot the rule put it on.
	 * @return array<string, mixed>
	 */
	private static function acted( array $site, array $series, string $slot ): array {
		$answer = self::answer( $site );
		$stored = Diary::slot( (string) $series['id'], $slot );

		$meeting = null === $stored
			? null
			: self::meeting( Occurrence::merge( array(), array( $stored ) )[0], $series );

		return array_merge( array( 'meeting' => $meeting ), $answer );
	}

	/**
	 * The refusal for anything the domain would not write: a series the
	 * database would not take, a move with nowhere to go, an exception that
	 * could not be recorded.
	 *
	 * @return \WP_Error
	 */
	private static function refused() {
		return Errors::rest( 'meeting_refused', __( 'That could not be done.', 'blueworx-forge' ), 400 );
	}

	/**
	 * A series that is not on the site in the path. Not found rather than
	 * forbidden: the boundary does not say whether it exists elsewhere.
	 *
	 * @return \WP_Error
	 */
	private static function unknown_series() {
		return Errors::rest( 'unknown_series', __( 'There is no such series on this site.', 'blueworx-forge' ), 404 );
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
	 * What every route answers with: the site, its standing meetings, the
	 * next twelve weeks of them, and the people who could host one — the
	 * panels of the admin page, as one answer, settled first.
	 *
	 * The list runs to the same horizon hours are reserved over (MEET-4), so
	 * what a person can see and what the balance has committed are the same
	 * set of meetings.
	 *
	 * @param array<string, mixed> $site The site.
	 * @return array<string, mixed>
	 */
	private static function answer( array $site ): array {
		$id    = (string) $site['id'];
		$today = gmdate( 'Y-m-d' );
		$to    = MeetingHours::horizon_end( $today );

		Hours::reconcile_site( $id, get_current_user_id() );

		$all      = Series::for_site( $id );
		$by_id    = array_column( $all, null, 'id' );
		$meetings = array();

		foreach ( Diary::for_site( $id, $today, $to ) as $meeting ) {
			$meetings[] = self::meeting( $meeting, $by_id[ (string) $meeting['series_id'] ] ?? array() );
		}

		return array(
			'ok'       => true,
			'site'     => array(
				'id'   => $id,
				'name' => (string) $site['name'],
			),
			'series'   => array_map( array( self::class, 'series' ), $all ),
			'meetings' => $meetings,
			'horizon'  => array(
				'from' => $today,
				'to'   => $to,
			),
			'people'   => array_map(
				static fn( array $person ): array => array(
					'id'           => (string) $person['id'],
					'display_name' => (string) $person['display_name'],
				),
				Users::ours()
			),
		);
	}

	/**
	 * One series as the screen shows it: the row, with the host named.
	 *
	 * @param array<string, mixed> $series The series.
	 * @return array<string, mixed>
	 */
	private static function series( array $series ): array {
		$host = Users::get( (string) $series['host_user_id'] );

		return array_merge(
			$series,
			array( 'host_name' => null === $host ? '' : (string) $host['display_name'] )
		);
	}

	/**
	 * One meeting as the screen shows it.
	 *
	 * `slot` is the key a move or a settle names it by: the date the rule put
	 * it on. A meeting nobody has touched is its own slot; one that has moved
	 * keeps the slot it moved from, so moving it twice does not create a
	 * second exception against the second date.
	 *
	 * `excepted_from` is only the date it moved from. A meeting the reconcile
	 * gave a row to is stored against its own date, and saying it was
	 * "moved from" the day it is on would be an explanation of nothing.
	 *
	 * @param array<string, mixed> $meeting One merged occurrence.
	 * @param array<string, mixed> $series  The series it came from.
	 * @return array<string, mixed>
	 */
	private static function meeting( array $meeting, array $series ): array {
		$from  = (string) ( $meeting['excepted_from'] ?? '' );
		$id    = (string) ( $meeting['id'] ?? '' );
		$moved = (bool) ( $meeting['moved'] ?? false );

		return array(
			'id'            => '' === $id ? null : $id,
			'series_id'     => (string) $meeting['series_id'],
			'series_title'  => (string) ( $series['title'] ?? '' ),
			'slot'          => '' !== $from ? $from : (string) $meeting['on'],
			'on'            => (string) $meeting['on'],
			'time'          => (string) $meeting['at'],
			'status'        => (string) $meeting['status'],
			'status_label'  => Occurrence::label( (string) $meeting['status'] ),
			'hours'         => (float) $meeting['planned_hours'],
			'ledger_state'  => self::ledger_state( $id ),
			'excepted_from' => $moved ? $from : null,
			'moved'         => $moved,
		);
	}

	/**
	 * What the ledger holds against one meeting on the list, as the admin
	 * page derives it. A meeting with no row of its own has nothing held
	 * against it — it is a forecast, and saying so is more use than nothing.
	 *
	 * @param string $id The stored occurrence's id, or '' for a forecast.
	 * @return string One of MeetingHours' four.
	 */
	private static function ledger_state( string $id ): string {
		if ( '' === $id ) {
			return MeetingHours::FORECAST;
		}

		$stored = Diary::get( $id );

		return null === $stored ? MeetingHours::FORECAST : (string) $stored['ledger_state'];
	}
}
