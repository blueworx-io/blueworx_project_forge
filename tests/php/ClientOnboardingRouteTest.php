<?php
/**
 * The routes a client works through their own checklist on.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Rest\Permissions;
use Blueworx\Forge\Rest\Server;
use Blueworx\Forge\Tenancy\Capabilities;
use Blueworx\Forge\Tenancy\Roles;
use PHPUnit\Framework\TestCase;

/**
 * #162, #167, #168. A client may do their steps and nothing else.
 *
 * The prohibitions are written as absences, the same way #133 wrote them: a
 * rule saying "clients must not approve" is a rule somebody writes a
 * reasonable-looking exception to, and a route that does not exist cannot be
 * called whatever anybody later finds convenient.
 *
 * Read the three tests at the end together with {@see ClientContributionRouteTest},
 * which holds the whole `/client/` prefix to having no PATCH, PUT or DELETE on
 * it. Between them, the client's side of the connection can add and cannot edit.
 */
final class ClientOnboardingRouteTest extends TestCase {

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
	 * The onboarding routes on the client's side of the connection.
	 *
	 * @return array<int, string> "METHOD path".
	 */
	private function onboarding_routes(): array {
		$found = array();

		foreach ( $this->routes() as $route ) {
			$path = (string) $route['route'];

			if ( str_starts_with( $path, '/client/onboarding' ) ) {
				$found[] = (string) ( $route['args']['methods'] ?? '' ) . ' ' . $path;
			}
		}

		sort( $found );

		return $found;
	}

	/* ------------------------------------------------------- what exists */

	public function test_the_client_surface_is_exactly_three_routes(): void {
		/*
		 * Read their checklist, answer a step, attach something to a step.
		 * Counted rather than merely spot-checked, because the guarantee this
		 * issue is closed on is about what is *not* there, and a fourth route
		 * added later must fail something.
		 */
		$this->assertSame(
			array(
				'GET /client/onboarding',
				'POST /client/onboarding/steps/(?P<step_id>[A-Za-z0-9_\-]+)/answer',
				'POST /client/onboarding/steps/(?P<step_id>[A-Za-z0-9_\-]+)/evidence',
			),
			$this->onboarding_routes()
		);
	}

	public function test_every_onboarding_route_is_guarded_by_the_signature(): void {
		foreach ( $this->routes() as $route ) {
			if ( ! str_starts_with( (string) $route['route'], '/client/onboarding' ) ) {
				continue;
			}

			$this->assertSame(
				array( Permissions::class, 'client_site' ),
				$route['args']['permission_callback'] ?? null,
				(string) $route['route'] . ' must be guarded by the client site signature'
			);
		}
	}

	/* ---------------------------------------------------- what does not */

	public function test_there_is_no_route_that_creates_deletes_or_reorders_a_step(): void {
		$forbidden = array( 'create', 'delete', 'remove', 'reorder', 'position', 'approve' );
		$found     = array();

		foreach ( $this->onboarding_routes() as $route ) {
			foreach ( $forbidden as $word ) {
				if ( str_contains( strtolower( $route ), $word ) ) {
					$found[] = $route;
				}
			}
		}

		$this->assertSame( array(), $found );
	}

	/* --------------------------------------------------- and permission */

	public function test_a_client_may_answer_and_attach(): void {
		// The matrix, asked directly. The routes above ask it the same way, so
		// a change to the grid changes both together.
		foreach ( array( Capabilities::ANSWER_INFORMATION, Capabilities::ATTACH_EVIDENCE ) as $capability ) {
			$decision = Capabilities::decide(
				$capability,
				array(
					'role'      => Roles::CLIENT_ADMIN,
					'interface' => Capabilities::CLIENT,
					'own_site'  => true,
				)
			);

			$this->assertTrue( $decision['allowed'], $capability . ' must be something a client may do' );
		}
	}

	public function test_a_client_viewer_may_not_answer_or_attach(): void {
		/*
		 * A viewer is somebody with a login and nothing to say on behalf of the
		 * organisation. Reach is not permission, and this is the case that
		 * proves the two are asked separately.
		 */
		foreach ( array( Capabilities::ANSWER_INFORMATION, Capabilities::ATTACH_EVIDENCE ) as $capability ) {
			$decision = Capabilities::decide(
				$capability,
				array(
					'role'      => Roles::CLIENT_VIEWER,
					'interface' => Capabilities::CLIENT,
					'own_site'  => true,
				)
			);

			$this->assertFalse( $decision['allowed'], $capability . ' must not be open to a viewer' );
		}
	}
}
