<?php
/**
 * The routes a client site adds to its own work through.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Rest\Boundary;
use Blueworx\Forge\Rest\Permissions;
use Blueworx\Forge\Rest\Server;
use PHPUnit\Framework\TestCase;

/**
 * #133. A client site can comment, attach evidence and answer — and there is
 * nothing on its side of the connection that moves work.
 *
 * The second half is the one written as an absence, the same way #129 wrote
 * submission immutability. A rule saying "client routes must not transition" is
 * a rule somebody writes a reasonable-looking exception to; a route that does
 * not exist cannot be called, whatever anybody later decides would be
 * convenient.
 *
 * That absence is checked over the whole `/client/` prefix rather than over the
 * two routes this issue added, because the claim is about the interface and not
 * about these two callbacks. A transition route appearing anywhere under that
 * prefix would open the lock regardless of which issue put it there.
 */
final class ClientContributionRouteTest extends TestCase {

	/**
	 * The contribution route's path.
	 */
	private const COMMENTS = '/client/work-items/(?P<item_id>[A-Za-z0-9_\-]+)/comments';

	/**
	 * Every registered route.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function routes(): array {
		$GLOBALS['bwx_forge_test_routes'] = array();

		Server::register_routes();

		return $GLOBALS['bwx_forge_test_routes'];
	}

	/**
	 * One route by path and method.
	 *
	 * @param string $path   The route path.
	 * @param string $method The HTTP method.
	 * @return array<string, mixed>|null
	 */
	private function route( string $path, string $method ): ?array {
		foreach ( $this->routes() as $route ) {
			if ( $path === $route['route'] && $method === ( $route['args']['methods'] ?? '' ) ) {
				return $route;
			}
		}

		return null;
	}

	/**
	 * Every route a client site can reach.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function client_routes(): array {
		$found = array();

		foreach ( $this->routes() as $route ) {
			$path = (string) $route['route'];

			if ( str_starts_with( $path, '/client/' ) ) {
				$found[] = array(
					'path'   => $path,
					'method' => (string) ( $route['args']['methods'] ?? '' ),
				);
			}
		}

		return $found;
	}

	// ---- What a client site may do --------------------------------------

	/**
	 * A client site can read the discussion on a piece of its own work.
	 */
	public function test_a_client_site_can_read_a_discussion(): void {
		$this->assertNotNull( $this->route( self::COMMENTS, 'GET' ) );
	}

	/**
	 * And add to it.
	 */
	public function test_a_client_site_can_add_to_a_discussion(): void {
		$this->assertNotNull( $this->route( self::COMMENTS, 'POST' ) );
	}

	/**
	 * Both guarded by the signature, like every other route a machine calls. A
	 * capability check here would be asking a logged-in person for permission
	 * on a request no person makes.
	 */
	public function test_both_are_guarded_by_the_client_site_signature(): void {
		foreach ( array( 'GET', 'POST' ) as $method ) {
			$this->assertSame(
				array( Permissions::class, 'client_site' ),
				$this->route( self::COMMENTS, $method )['args']['permission_callback'],
				"the {$method} is guarded by something else"
			);
		}
	}

	/**
	 * Both outside the tenancy boundary with a written reason, like the other
	 * client routes — the signature names which site is calling, and the
	 * callback refuses any item that is not on it (ARCH-6).
	 */
	public function test_both_say_why_they_are_outside_the_boundary(): void {
		foreach ( array( 'GET', 'POST' ) as $method ) {
			$scope = $this->route( self::COMMENTS, $method )['args']['scope'];

			$this->assertSame( Boundary::SCOPE_OPEN, $scope['kind'] );
			$this->assertNotSame( '', trim( (string) $scope['reason'] ) );
		}
	}

	/**
	 * Nothing on the way in names a client or a site. The item is checked
	 * against the site that signed for it, and there is no second id to check
	 * it against instead.
	 */
	public function test_the_contribution_route_takes_no_parameter_naming_a_client_or_site(): void {
		$args = (array) ( $this->route( self::COMMENTS, 'POST' )['args']['args'] ?? array() );

		$naming = array_values(
			array_filter(
				array_keys( $args ),
				static fn( $name ): bool => str_contains( (string) $name, 'client' )
					|| str_contains( (string) $name, 'site' )
			)
		);

		$this->assertSame( array(), $naming );
	}

	// ---- And what it can never do ---------------------------------------

	/**
	 * No route a client site can reach moves work.
	 *
	 * The whole `/client/` surface, checked against every word the workflow
	 * routes are spelled with. This is the client transition lock as a fact
	 * about what exists rather than as a permission somebody has to keep
	 * getting right (§14, D-10 to D-19).
	 */
	public function test_no_client_route_moves_work(): void {
		$moves = array( 'transition', 'reopen', 'override', 'send-back', 'block', 'unblock', 'outcome', 'archive', 'gate' );
		$found = array();

		foreach ( $this->client_routes() as $route ) {
			foreach ( $moves as $move ) {
				if ( str_contains( $route['path'], $move ) ) {
					$found[] = $route['method'] . ' ' . $route['path'];
				}
			}
		}

		$this->assertSame( array(), $found );
	}

	/**
	 * And none of them edits or deletes anything either.
	 *
	 * A client contributes by adding. What has been said is not edited away —
	 * the comments table is append-only for the same reason the changelog is,
	 * and a route that could rewrite an entry would be the exception that ends
	 * it.
	 */
	public function test_no_client_route_edits_or_deletes(): void {
		$writes = array();

		foreach ( $this->client_routes() as $route ) {
			if ( in_array( $route['method'], array( 'PUT', 'PATCH', 'DELETE' ), true ) ) {
				$writes[] = $route['method'] . ' ' . $route['path'];
			}
		}

		$this->assertSame( array(), $writes );
	}

	/**
	 * Every route a client site can reach is guarded by the signature.
	 *
	 * Checked across the prefix rather than route by route, so a new client
	 * route added later cannot quietly be guarded by something weaker — or, the
	 * failure that actually happens, by a permission callback written for a
	 * logged-in person on a route only a machine ever calls.
	 */
	public function test_every_client_route_is_guarded_by_the_signature(): void {
		foreach ( $this->routes() as $route ) {
			if ( ! str_starts_with( (string) $route['route'], '/client/' ) ) {
				continue;
			}

			$this->assertSame(
				array( Permissions::class, 'client_site' ),
				$route['args']['permission_callback'],
				sprintf( '%s %s is guarded by something else', $route['args']['methods'] ?? '', $route['route'] )
			);
		}
	}
}
