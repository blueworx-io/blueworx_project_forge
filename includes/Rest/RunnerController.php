<?php
/**
 * The Forge Runner's routes, and the key that opens them.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Commerce\WorkLedger;
use Blueworx\Forge\GitHub\Links as GitHubLinks;
use Blueworx\Forge\Slack\Notify;
use Blueworx\Forge\Tenancy\Agents;
use Blueworx\Forge\Tenancy\RunnerKey;
use Blueworx\Forge\Work\Changelog;
use Blueworx\Forge\Work\Comments;
use Blueworx\Forge\Work\Events;
use Blueworx\Forge\Work\Items;
use Blueworx\Forge\Work\Runner;
use Blueworx\Forge\Work\Transition;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The Forge Runner on Luke's PC builds the cards the Agent is doing. It has no
 * login — the Agent never gets one (#689), and an administrator's would hand
 * it the whole site — so it sends the runner key, and the key opens these five
 * routes and nothing else:
 *
 * - GET  /runner/cards              the Agent's cards at one stage, light.
 * - GET  /runner/cards/{id}         one of them in full, with its comments.
 * - POST /runner/cards/{id}/claim   Up Next to In development.
 * - POST /runner/cards/{id}/hand-over  the hours and how to test it, then
 *                                   In development to In review.
 * - POST /runner/cards/{id}/comments   an internal note, as the Agent.
 *
 * Every one of them loads the card first and answers exactly as for a card
 * that does not exist unless the Agent is doing it and it is not archived.
 * The moves go through Work\Transition like anybody's, so every gate applies;
 * no override and no over-booking reason can be sent. Everything written is in
 * the card's history as the runner's, with no person behind it.
 *
 * An administrator makes, turns off and removes the key at /runner/key, from
 * the app's Settings.
 */
final class RunnerController {

	/**
	 * Why the runner's routes are outside the tenant boundary.
	 */
	private const OPEN = 'The Forge Runner, proved by the runner key rather than a login. Each route loads the card first and answers as absent unless the Agent is doing it and it is not archived (Work\\Runner::reaches).';

	/**
	 * Registers the routes.
	 *
	 * @param string $route_namespace REST namespace.
	 */
	public static function register_routes( string $route_namespace ): void {
		foreach ( array(
			'GET'    => 'key_status',
			'POST'   => 'make_key',
			'PATCH'  => 'turn_key',
			'DELETE' => 'remove_key',
		) as $method => $callback ) {
			Server::register_route(
				$route_namespace,
				'/runner/key',
				array(
					'methods'             => $method,
					'callback'            => array( self::class, $callback ),
					'permission_callback' => array( Permissions::class, 'manage' ),
					'scope'               => array(
						'kind'   => Boundary::SCOPE_OPEN,
						'reason' => 'The studio\'s own runner key: administrators only, and the key is in one answer, the one that makes it.',
					),
				)
			);
		}

		$scope = array(
			'kind'   => Boundary::SCOPE_OPEN,
			'reason' => self::OPEN,
		);
		$card  = '/runner/cards/(?P<item_id>[A-Za-z0-9_\-]+)';

		$routes = array(
			array( '/runner/cards', 'GET', 'cards' ),
			array( $card, 'GET', 'card' ),
			array( $card . '/claim', 'POST', 'claim' ),
			array( $card . '/hand-over', 'POST', 'hand_over' ),
			array( $card . '/comments', 'POST', 'comment' ),
		);

		foreach ( $routes as list( $path, $method, $callback ) ) {
			Server::register_route(
				$route_namespace,
				$path,
				array(
					'methods'             => $method,
					'callback'            => array( self::class, $callback ),
					'permission_callback' => array( Permissions::class, 'runner' ),
					'scope'               => $scope,
				)
			);
		}
	}

	// ---- The key -------------------------------------------------------

	/**
	 * Whether there is a key, and when it was made and last used.
	 *
	 * @return WP_REST_Response
	 */
	public static function key_status(): WP_REST_Response {
		return self::no_store( array( 'ok' => true ) + self::status() );
	}

	/**
	 * Makes a key, or replaces the one there was. The only answer that ever
	 * carries it.
	 *
	 * @return WP_REST_Response
	 */
	public static function make_key(): WP_REST_Response {
		$key = RunnerKey::make( get_current_user_id() );

		return self::no_store(
			array(
				'ok'  => true,
				'key' => $key,
			) + self::status()
		);
	}

	/**
	 * Turns the key on or off.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function turn_key( WP_REST_Request $request ) {
		$body = (array) $request->get_json_params();

		if ( ! array_key_exists( 'enabled', $body ) || ! is_bool( $body['enabled'] ) ) {
			return Errors::rest( 'invalid_runner_key', __( 'Say whether the key is on or off.', 'blueworx-forge' ), 400 );
		}

		if ( ! RunnerKey::set_enabled( $body['enabled'] ) ) {
			return Errors::rest( 'no_runner_key', __( 'There is no runner key yet.', 'blueworx-forge' ), 404 );
		}

		return self::key_status();
	}

	/**
	 * Removes the key.
	 *
	 * @return WP_REST_Response
	 */
	public static function remove_key(): WP_REST_Response {
		RunnerKey::forget();

		return self::key_status();
	}

	/**
	 * The key's status, as an administrator sees it.
	 *
	 * @return array<string, mixed>
	 */
	private static function status(): array {
		$status = RunnerKey::status();

		unset( $status['created_by'] );

		return $status;
	}

	/**
	 * An answer no browser or proxy keeps a copy of.
	 *
	 * @param array<string, mixed> $body The answer.
	 * @return WP_REST_Response
	 */
	private static function no_store( array $body ): WP_REST_Response {
		$response = rest_ensure_response( $body );

		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	// ---- The runner's routes -------------------------------------------

	/**
	 * The Agent's cards at one stage, with only what the runner chooses work
	 * by.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function cards( WP_REST_Request $request ) {
		$stage = Runner::stage( (string) $request->get_param( 'stage' ) );

		if ( null === $stage ) {
			return Errors::rest( 'invalid_stage', __( 'The runner reads Up Next, In development or In review.', 'blueworx-forge' ), 400 );
		}

		$agents = Agents::ids();
		$cards  = array();

		foreach ( Items::done_by_agent( $agents, $stage ) as $item ) {
			// Asked again of every row, so the list and the card answer to
			// the same rule.
			if ( Runner::reaches( $item, $agents ) ) {
				$cards[] = Runner::light( $item );
			}
		}

		return rest_ensure_response(
			array(
				'ok'    => true,
				'stage' => $stage,
				'cards' => $cards,
			)
		);
	}

	/**
	 * One card in full: its definition, what its next move is waiting on, and
	 * its own comments. Nothing about any other card.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function card( WP_REST_Request $request ) {
		$item = self::reached( $request );

		if ( $item instanceof WP_Error ) {
			return $item;
		}

		return rest_ensure_response(
			array(
				'ok'   => true,
				'card' => self::full( $item ),
			)
		);
	}

	/**
	 * Up Next to In development, through every gate.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function claim( WP_REST_Request $request ) {
		$item = self::reached( $request );

		if ( $item instanceof WP_Error ) {
			return $item;
		}

		$body = self::body( $request );

		// No override reason, no over-booking reason: only the version.
		$extra = array_diff( array_keys( $body ), array( Versioning::PARAM ) );

		if ( array() !== $extra ) {
			return Errors::rest( 'invalid_runner_claim', __( 'A claim sends the record version and nothing else.', 'blueworx-forge' ), 400, array( 'fields' => array_fill_keys( array_values( $extra ), __( 'The runner can\'t change that.', 'blueworx-forge' ) ) ) );
		}

		$stale = self::stale( $request, $item );

		if ( null !== $stale ) {
			return $stale;
		}

		list( $from, $to ) = Runner::MOVES['claim'];

		if ( $from !== (string) $item['stage'] ) {
			return self::wrong_stage( $item, $to );
		}

		return self::moved( Transition::move( $item, $to, (int) $item['record_version'], 0, Events::VIA_RUNNER ) );
	}

	/**
	 * The hours and how to test it, then In development to In review.
	 *
	 * Everything is checked before anything is written: what was sent, and
	 * then the gate, against the card as it would be. A refusal at either
	 * writes nothing. Only a failure after that — somebody else saving the
	 * card in the same instant, or the hours ledger refusing the move — can
	 * leave the fields saved without the move, and the answer then says so
	 * with `saved: true`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function hand_over( WP_REST_Request $request ) {
		global $wpdb;

		$item = self::reached( $request );

		if ( $item instanceof WP_Error ) {
			return $item;
		}

		$stale = self::stale( $request, $item );

		if ( null !== $stale ) {
			return $stale;
		}

		list( $from, $to ) = Runner::MOVES['hand-over'];

		if ( $from !== (string) $item['stage'] ) {
			return self::wrong_stage( $item, $to );
		}

		$checked = Runner::handover( self::body( $request ), $item );

		if ( array() !== $checked['errors'] ) {
			return Errors::rest( 'invalid_work_item', __( 'That could not be saved.', 'blueworx-forge' ), 400, array( 'fields' => $checked['errors'] ) );
		}

		$ready = Runner::readiness_view( Transition::readiness( Runner::after( $item, $checked['values'] ), $to, Items::children( (string) $item['id'] ) ) );

		if ( ! $ready['ready'] ) {
			return Errors::gate_failure( (string) $item['id'], (string) $item['stage'], $to, $ready['unmet'], $ready['checks'] );
		}

		// The same writes an edit makes: the fields, a history entry per
		// change, and the hours ledger, in one transaction.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control, not a read.
		$wpdb->query( 'START TRANSACTION' );

		$updated = Items::update( (string) $item['id'], $checked['values'], (int) $item['record_version'] );

		if ( null === $updated ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control, not a read.
			$wpdb->query( 'ROLLBACK' );

			return self::stale_now( $item );
		}

		$context = array(
			'actor'            => 0,
			'source_interface' => Events::VIA_RUNNER,
		);

		foreach ( Changelog::for_edit( $item, $checked['values'], $context ) as $entry ) {
			Events::append( array( 'via' => Events::VIA_RUNNER ) + $entry );
		}

		if ( ! WorkLedger::refund( $item, $updated, 0 ) || ! WorkLedger::reconcile( $updated, 0 ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control, not a read.
			$wpdb->query( 'ROLLBACK' );

			return Errors::rest( 'hours_not_available', __( 'Those hours need more support hours than the site has left. Nothing was saved.', 'blueworx-forge' ), 409 );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control, not a read.
		$wpdb->query( 'COMMIT' );

		$moved = Transition::move( $updated, $to, (int) $updated['record_version'], 0, Events::VIA_RUNNER );

		if ( $moved instanceof WP_Error ) {
			$refusal = self::moved( $moved );

			if ( $refusal instanceof WP_REST_Response ) {
				$refusal->set_data( array( 'saved' => true ) + (array) $refusal->get_data() );

				return $refusal;
			}

			return new WP_Error( $refusal->get_error_code(), $refusal->get_error_message(), array( 'saved' => true ) + (array) $refusal->get_error_data() );
		}

		return self::moved( $moved );
	}

	/**
	 * An internal note on the card, as the Agent, marked as the runner's.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function comment( WP_REST_Request $request ) {
		$item = self::reached( $request );

		if ( $item instanceof WP_Error ) {
			return $item;
		}

		$checked = Runner::comment( self::body( $request ) );

		if ( array() !== $checked['errors'] ) {
			return Errors::rest( 'comment_refused', __( 'That could not be saved.', 'blueworx-forge' ), 400, array( 'fields' => $checked['errors'] ) );
		}

		$comment = Comments::add(
			array(
				'item_id'        => (string) $item['id'],
				'client_site_id' => (string) $item['client_site_id'],
				'client_id'      => (string) $item['client_id'],
				'kind'           => Comments::COMMENT,
				'visibility'     => Comments::INTERNAL,
				'body'           => $checked['body'],
				'runner'         => true,
			)
		);

		if ( null === $comment ) {
			return Errors::rest( 'write_failed', __( 'That could not be saved.', 'blueworx-forge' ), 500 );
		}

		// Everyone holding a seat hears about it, except the Agent itself.
		Notify::commented( $item, $comment, (string) $item['primary_user_id'] );

		return rest_ensure_response(
			array(
				'ok'      => true,
				'comment' => self::comment_view( $comment ),
			)
		);
	}

	// ---- Shared --------------------------------------------------------

	/**
	 * The card the route names, or the answer a card that does not exist
	 * gets. A card the Agent is not doing, or that is archived, gets that
	 * same answer, word for word, so the key cannot tell which ids are real.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function reached( WP_REST_Request $request ) {
		$item = Items::get( (string) $request['item_id'] );

		if ( null === $item || ! Runner::reaches( $item, Agents::ids() ) ) {
			return Boundary::absent( 'work_item' );
		}

		return $item;
	}

	/**
	 * The card in full, as the runner reads it.
	 *
	 * @param array<string, mixed> $item The card.
	 * @return array<string, mixed>
	 */
	private static function full( array $item ): array {
		$to   = Runner::next_move( (string) $item['stage'] );
		$link = GitHubLinks::for_item( (string) $item['id'] );

		return array_merge(
			Runner::definition( $item ),
			array(
				'github_issue' => null === $link ? null : array(
					'repo'   => (string) $link['repo'],
					'number' => (int) $link['issue_number'],
					'url'    => GitHubLinks::url( $link ),
				),

				// The one move the runner may make from here, and what it is
				// waiting on. Children are read to answer the gate, and never
				// returned.
				'next'         => null === $to ? null : array_merge(
					array( 'to' => $to ),
					Runner::readiness_view( Transition::readiness( $item, $to, Items::children( (string) $item['id'] ) ) )
				),
				'comments'     => array_map( array( self::class, 'comment_view' ), Comments::for_item( (string) $item['id'], Comments::SCOPE_STAFF ) ),
			)
		);
	}

	/**
	 * A comment as the runner reads it.
	 *
	 * @param array<string, mixed> $comment The comment.
	 * @return array<string, mixed>
	 */
	private static function comment_view( array $comment ): array {
		return array(
			'id'          => (string) $comment['id'],
			'kind'        => (string) $comment['kind'],
			'visibility'  => (string) $comment['visibility'],
			'body'        => (string) $comment['body'],
			'url'         => (string) $comment['url'],
			'author_name' => (string) $comment['author_name'],
			'from_client' => ! empty( $comment['from_client'] ),
			'from_runner' => ! empty( $comment['from_runner'] ),
			'created_at'  => (int) $comment['created_at'],
		);
	}

	/**
	 * The request body, as an array.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private static function body( WP_REST_Request $request ): array {
		$body = $request->get_json_params();

		return is_array( $body ) ? $body : array();
	}

	/**
	 * Refuses a write that does not say which version it was made against,
	 * or names one that has moved on.
	 *
	 * @param WP_REST_Request      $request Request.
	 * @param array<string, mixed> $item    The card.
	 * @return WP_Error|null
	 */
	private static function stale( WP_REST_Request $request, array $item ): ?WP_Error {
		$sent = $request->get_param( Versioning::PARAM );

		return Versioning::check( null === $sent || ! is_numeric( $sent ) ? null : (int) $sent, (int) $item['record_version'] );
	}

	/**
	 * The stale answer, read again after a write found the version moved.
	 *
	 * @param array<string, mixed> $item The card as it was.
	 * @return WP_Error
	 */
	private static function stale_now( array $item ): WP_Error {
		$current = Items::get( (string) $item['id'] );

		return Versioning::check( (int) $item['record_version'], null === $current ? 0 : (int) $current['record_version'] )
			?? Errors::rest( 'write_failed', __( 'That change could not be saved.', 'blueworx-forge' ), 500 );
	}

	/**
	 * The refusal for a move the runner cannot make from where the card is.
	 * The same words as Work\Transition's own.
	 *
	 * @param array<string, mixed> $item The card.
	 * @param string               $to   Where the move goes.
	 * @return WP_Error
	 */
	private static function wrong_stage( array $item, string $to ): WP_Error {
		return new WP_Error(
			'bwx_forge_transition_not_allowed',
			__( 'Work cannot move there from where it is.', 'blueworx-forge' ),
			array(
				'status'    => 409,
				'from'      => (string) $item['stage'],
				'attempted' => $to,
			)
		);
	}

	/**
	 * What a move answers with: the card as it now stands, Forge's gate
	 * refusal in its own words, or any other refusal with nothing extra on it.
	 *
	 * @param array<string, mixed>|WP_Error $moved What Work\Transition said.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function moved( $moved ) {
		if ( $moved instanceof WP_Error ) {
			if ( Transition::GATE_FAILURE === $moved->get_error_code() ) {
				$data  = (array) $moved->get_error_data();
				$ready = Runner::readiness_view( $data );

				return Errors::gate_failure( (string) ( $data['item_id'] ?? '' ), (string) ( $data['stage'] ?? '' ), (string) ( $data['attempted'] ?? '' ), $ready['unmet'], $ready['checks'] );
			}

			return Runner::scrub( $moved );
		}

		return rest_ensure_response(
			array(
				'ok'   => true,
				'card' => Runner::light( $moved ),
			)
		);
	}
}
