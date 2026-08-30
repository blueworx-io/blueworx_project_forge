<?php
/**
 * The one door every route is scoped at.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Tenancy\ClientSites;
use Blueworx\Forge\Tenancy\Memberships;
use Blueworx\Forge\Tenancy\Reach;
use Blueworx\Forge\Tenancy\Users;
use Blueworx\Forge\Work\Items;
use InvalidArgumentException;
use WP_Error;
use WP_REST_Request;

/**
 * #92: one enforcement point every request passes through, so that scoping is
 * something a route cannot forget rather than something every author has to
 * remember.
 *
 * It works the way the permission callback rule works, which is the only reason
 * that rule has held. A route declares how it is scoped as part of registering,
 * a route that declares nothing does not register at all, and the declaration
 * is not a note — Server::register_route() wraps the callback with it, so the
 * check runs whether or not the callback would have done it.
 *
 * There are five ways to be scoped and one of them is "not":
 *
 * - **site**    the request names a site, and the site has to be within reach.
 * - **client**  the request names a client.
 * - **item**    the request names a record, and the record's site is checked.
 * - **list**    the response is a set, and the callback filters it with Reach.
 * - **open**    deliberately outside the boundary, with a written reason.
 *
 * Only the last one is a decision. The other four are answered here.
 *
 * A record the three built-in resolvers do not know how to place — a membership
 * row, say — names its own `resolve` callable alongside the parameter, which
 * returns the client and site the record sits under. That is still the boundary
 * deciding; the route only says where to look.
 *
 * **A record out of reach is answered as absent** (D-1, D-2). Not 403: telling
 * somebody they lack permission on a record confirms the record exists, and
 * which ids are real on a tenant they have nothing to do with is exactly what
 * ARCH-3 hides. The message says the same thing an unused id would get.
 */
final class Boundary {

	/**
	 * The request names a client site, which has to be within reach.
	 */
	public const SCOPE_SITE = 'site';

	/**
	 * The request names a client.
	 */
	public const SCOPE_CLIENT = 'client';

	/**
	 * The request names a work item, whose site is checked.
	 */
	public const SCOPE_ITEM = 'item';

	/**
	 * The response is a set, filtered by the callback with Reach.
	 */
	public const SCOPE_LIST = 'list';

	/**
	 * Deliberately outside the boundary. Costs a written reason.
	 */
	public const SCOPE_OPEN = 'open';

	/**
	 * Every scope there is.
	 */
	public const ALL = array(
		self::SCOPE_SITE,
		self::SCOPE_CLIENT,
		self::SCOPE_ITEM,
		self::SCOPE_LIST,
		self::SCOPE_OPEN,
	);

	/**
	 * The scopes resolved from a named request parameter.
	 */
	private const FROM_PARAM = array(
		self::SCOPE_SITE,
		self::SCOPE_CLIENT,
		self::SCOPE_ITEM,
	);

	/**
	 * The kinds of record a route can be scoped to, and how each says it is not
	 * there.
	 *
	 * There is one answer per kind and both callers use it: the route, when the
	 * id genuinely is not a record, and the boundary, when it is somebody
	 * else's. **They have to be identical**, because two different 404s are two
	 * different answers, and a caller comparing them learns which ids are real
	 * on a tenant they have nothing to do with (D-1, D-2).
	 *
	 * That is not a hypothetical. It shipped, briefly: the boundary answered
	 * "there is no such record" while the route answered "there is no such work
	 * item", both with a 404, and the two together told a caller exactly what
	 * the boundary existed to hide. Holding the pair here is what stops the two
	 * drifting apart again.
	 */
	public const RECORDS = array(
		'work_item'         => 'unknown_work_item',
		'client_site'       => 'unknown_client_site',
		'client'            => 'unknown_client',
		'membership'        => 'unknown_membership',
		'onboarding_step'   => 'unknown_onboarding_step',
		'onboarding_upload' => 'unknown_onboarding_upload',
	);

	/**
	 * Whether a string is a scope at all.
	 *
	 * @param string $kind Candidate.
	 * @return bool
	 */
	public static function is_scope( string $kind ): bool {
		return in_array( $kind, self::ALL, true );
	}

	/**
	 * Checks a route's scope declaration and wraps its callback with it.
	 *
	 * @param string               $route Route pattern, for the message.
	 * @param array<string, mixed> $args  Route arguments.
	 * @return array<string, mixed> The arguments, with the callback wrapped.
	 *
	 * @throws InvalidArgumentException When the declaration is missing or unusable.
	 */
	public static function apply( string $route, array $args ): array {
		$scope = isset( $args['scope'] ) && is_array( $args['scope'] ) ? $args['scope'] : null;
		$kind  = null === $scope ? '' : (string) ( $scope['kind'] ?? '' );

		if ( '' === $kind ) {
			throw new InvalidArgumentException(
				sprintf(
					'%s was registered without a scope. Every route must say how it is scoped to a tenant; one that genuinely is not says so with Boundary::SCOPE_OPEN and a reason.',
					esc_html( $route )
				)
			);
		}

		if ( ! self::is_scope( $kind ) ) {
			throw new InvalidArgumentException(
				sprintf( '%s declares the scope "%s", which is not one of them.', esc_html( $route ), esc_html( $kind ) )
			);
		}

		if ( in_array( $kind, self::FROM_PARAM, true ) ) {
			if ( '' === (string) ( $scope['param'] ?? '' ) ) {
				throw new InvalidArgumentException(
					sprintf( '%s is scoped by "%s" but names no parameter to resolve it from.', esc_html( $route ), esc_html( $kind ) )
				);
			}

			if ( ! array_key_exists( (string) ( $scope['record'] ?? '' ), self::RECORDS ) ) {
				throw new InvalidArgumentException(
					sprintf( '%s does not say which kind of record it is scoped to, so it cannot be refused in that record\'s own words.', esc_html( $route ) )
				);
			}
		}

		if ( self::SCOPE_OPEN === $kind && '' === trim( (string) ( $scope['reason'] ?? '' ) ) ) {
			throw new InvalidArgumentException(
				sprintf( '%s is outside the tenant boundary and gives no reason. Leaving the boundary has to be an act, not an omission.', esc_html( $route ) )
			);
		}

		/*
		 * Nothing to wrap for these two. An open route is outside on purpose,
		 * and a list route's set is filtered by the callback — there is no one
		 * record to refuse, and refusing the whole list because part of it is
		 * somebody else's would be the wrong answer anyway.
		 */
		if ( self::SCOPE_OPEN === $kind || self::SCOPE_LIST === $kind ) {
			return $args;
		}

		$callback = $args['callback'];
		$param    = (string) $scope['param'];
		$record   = (string) $scope['record'];
		$resolve  = isset( $scope['resolve'] ) && is_callable( $scope['resolve'] ) ? $scope['resolve'] : null;

		$args['callback'] = static function ( WP_REST_Request $request ) use ( $callback, $kind, $param, $record, $resolve ) {
			$refused = self::check( $kind, $param, $request, $resolve, $record );

			if ( null !== $refused ) {
				return $refused;
			}

			return call_user_func( $callback, $request );
		};

		return $args;
	}

	/**
	 * Whether this request may see the record it names.
	 *
	 * @param string          $kind    Scope kind.
	 * @param string          $param   Parameter carrying the id.
	 * @param WP_REST_Request $request Request.
	 * @param callable|null   $resolve A route's own way of placing its record,
	 *                                 for the kinds of record the three built-in
	 *                                 resolvers do not know about.
	 * @param string          $record  Which kind of record, so a refusal reads
	 *                                 exactly as a missing one.
	 * @return WP_Error|null Null when it may.
	 */
	public static function check( string $kind, string $param, WP_REST_Request $request, ?callable $resolve = null, string $record = '' ): ?WP_Error {
		$id = (string) $request->get_param( $param );

		/*
		 * An absent id is not a tenancy question. The route's own validation
		 * says whether the parameter was required, and answering "no such
		 * record" here would replace a clear message about a missing parameter
		 * with a confusing one about a missing record.
		 */
		if ( '' === $id ) {
			return null;
		}

		$where = null === $resolve ? self::locate( $kind, $id ) : call_user_func( $resolve, $id );

		// A record that does not exist is not a boundary matter either. The
		// callback will say so, in its own words, for its own kind of record.
		if ( ! is_array( $where ) ) {
			return null;
		}

		$reach = self::current();

		if ( Reach::reaches_site( $reach, $where['client_id'], $where['client_site_id'] ) ) {
			return null;
		}

		// A client-level route asks about the client rather than a site, since
		// somebody holding one site of a client still reaches the client itself.
		if ( self::SCOPE_CLIENT === $kind && Reach::reaches_client( $reach, $where['client_id'] ) ) {
			return null;
		}

		return self::hidden( $record );
	}

	/**
	 * Which client and site a named record sits under.
	 *
	 * @param string $kind Scope kind.
	 * @param string $id   The id named in the request.
	 * @return array{client_id: string, client_site_id: string}|null Null when
	 *                                                              there is no
	 *                                                              such record.
	 */
	private static function locate( string $kind, string $id ) {
		if ( self::SCOPE_CLIENT === $kind ) {
			return array(
				'client_id'      => $id,
				'client_site_id' => '',
			);
		}

		if ( self::SCOPE_SITE === $kind ) {
			$site = ClientSites::get( $id );

			return null === $site ? null : array(
				'client_id'      => (string) $site['client_id'],
				'client_site_id' => (string) $site['id'],
			);
		}

		$item = Items::get( $id );

		return null === $item ? null : array(
			'client_id'      => (string) $item['client_id'],
			'client_site_id' => (string) $item['client_site_id'],
		);
	}

	/**
	 * How far the person making this request reaches.
	 *
	 * @return array<string, mixed>
	 */
	public static function current(): array {
		return self::for_user( get_current_user_id() );
	}

	/**
	 * How far one WordPress user reaches.
	 *
	 * @param int $wp_user_id WordPress user id.
	 * @return array<string, mixed>
	 */
	public static function for_user( int $wp_user_id ): array {
		if ( $wp_user_id <= 0 ) {
			return Reach::nothing();
		}

		// The studio's own administrator. Forge runs on our site, and somebody
		// who can install plugins on it already reaches every table.
		if ( current_user_can( 'manage_options' ) ) {
			return Reach::everything();
		}

		$user = Users::by_wp_user( $wp_user_id );

		if ( null === $user || 'active' !== (string) $user['status'] ) {
			return Reach::nothing();
		}

		return Reach::for_memberships(
			Memberships::for_user( (string) $user['id'] ),
			(string) ( $user['grants'] ?? '' )
		);
	}

	/**
	 * The answer for a record outside somebody's reach.
	 *
	 * Deliberately the same answer an id nobody has ever used would get — the
	 * very same one, from absent() below — and deliberately silent about
	 * tenancy: "that belongs to another client" is the disclosure a 403 would
	 * make, written politely.
	 *
	 * @param string $record Which kind of record, from RECORDS.
	 * @return WP_Error
	 */
	public static function hidden( string $record = '' ): WP_Error {
		return self::absent( $record );
	}

	/**
	 * The answer for a record that is not there.
	 *
	 * Called by the routes themselves when an id resolves to nothing, so that
	 * "never existed" and "not yours" are one answer rather than two that happen
	 * to share a status code.
	 *
	 * @param string $record Which kind of record, from RECORDS.
	 * @return WP_Error
	 */
	public static function absent( string $record = '' ): WP_Error {
		$code = self::RECORDS[ $record ] ?? 'not_found';

		return Errors::rest( $code, self::not_there( $record ), 404 );
	}

	/**
	 * How each kind of record says it is not there.
	 *
	 * @param string $record Which kind of record.
	 * @return string
	 */
	private static function not_there( string $record ): string {
		switch ( $record ) {
			case 'work_item':
				return __( 'There is no such work item.', 'blueworx-forge' );
			case 'client_site':
				return __( 'There is no such client site.', 'blueworx-forge' );
			case 'client':
				return __( 'There is no such client.', 'blueworx-forge' );
			case 'membership':
				return __( 'There is no such membership.', 'blueworx-forge' );
			case 'onboarding_step':
				return __( 'There is no such onboarding step.', 'blueworx-forge' );
			case 'onboarding_upload':
				return __( 'There is no such attachment.', 'blueworx-forge' );
			default:
				return __( 'There is no such record.', 'blueworx-forge' );
		}
	}
}
