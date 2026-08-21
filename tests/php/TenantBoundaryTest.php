<?php
/**
 * Tests for the one door every route is scoped at.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Rest\Boundary;
use Blueworx\Forge\Rest\Server;
use PHPUnit\Framework\TestCase;

/**
 * #92 asks for a single enforcement point every query passes through, so that
 * scoping cannot be forgotten on a new endpoint. This is the test that makes
 * that true rather than hoped for: a route that does not say how it is scoped
 * does not register, so forgetting it is a failing test here instead of a live
 * hole on somebody else's data.
 *
 * It is the same shape as the permission callback rule above it, and for the
 * same reason — the mistake both prevent is one forgotten line.
 */
final class TenantBoundaryTest extends TestCase {

	/**
	 * Clears the recorded routes.
	 */
	protected function setUp(): void {
		$GLOBALS['bwx_forge_test_routes'] = array();
		$GLOBALS['bwx_forge_test_can']    = array();
	}

	/**
	 * A route declaration, with whatever scope the test is about.
	 *
	 * @param array<string, mixed>|null $scope The scope, or null to omit it.
	 * @return array<string, mixed>
	 */
	private function route( ?array $scope ): array {
		$args = array(
			'methods'             => 'GET',
			'callback'            => '__return_true',
			'permission_callback' => '__return_true',
		);

		if ( null !== $scope ) {
			$args['scope'] = $scope;
		}

		return $args;
	}

	// -----------------------------------------------------------------------
	// Declaring a scope is not optional.
	// -----------------------------------------------------------------------

	/**
	 * The rule #92 asks for, at the door: a route that says nothing about how it
	 * is scoped does not register at all.
	 */
	public function test_a_route_that_declares_no_scope_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );

		Server::register_route( 'blueworx-forge/v1', '/unscoped', $this->route( null ) );
	}

	/**
	 * A scope nobody defined is the same mistake with more typing.
	 */
	public function test_a_scope_nobody_defined_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );

		Server::register_route(
			'blueworx-forge/v1',
			'/nonsense',
			$this->route( array( 'kind' => 'whatever' ) )
		);
	}

	/**
	 * A scope resolved from a request parameter has to name the parameter, or
	 * there is nothing to resolve it from.
	 */
	public function test_a_record_scope_with_no_parameter_named_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );

		Server::register_route(
			'blueworx-forge/v1',
			'/sited',
			$this->route( array( 'kind' => Boundary::SCOPE_SITE ) )
		);
	}

	/**
	 * The deliberate exception exists, and it costs a written reason. A route
	 * can be outside the boundary — the gate registry is the product's own rules
	 * and belongs to nobody — but saying so has to be an act rather than an
	 * omission.
	 */
	public function test_an_unscoped_route_needs_a_written_reason(): void {
		$this->expectException( InvalidArgumentException::class );

		Server::register_route(
			'blueworx-forge/v1',
			'/rules',
			$this->route( array( 'kind' => Boundary::SCOPE_OPEN ) )
		);
	}

	/**
	 * With the reason, it registers.
	 */
	public function test_an_unscoped_route_with_a_reason_registers(): void {
		Server::register_route(
			'blueworx-forge/v1',
			'/rules',
			$this->route(
				array(
					'kind'   => Boundary::SCOPE_OPEN,
					'reason' => 'The gate definitions are the product\'s rules, not anybody\'s data.',
				)
			)
		);

		$this->assertCount( 1, $GLOBALS['bwx_forge_test_routes'] );
	}

	/**
	 * A properly scoped route registers, and every scope the boundary defines is
	 * accepted — so a controller cannot be refused for using one that exists.
	 */
	public function test_every_defined_scope_is_accepted(): void {
		foreach ( array( Boundary::SCOPE_SITE, Boundary::SCOPE_CLIENT, Boundary::SCOPE_ITEM ) as $kind ) {
			Server::register_route(
				'blueworx-forge/v1',
				'/thing',
				$this->route(
					array(
						'kind'   => $kind,
						'param'  => 'id',
						'record' => 'work_item',
					)
				)
			);
		}

		Server::register_route(
			'blueworx-forge/v1',
			'/list',
			$this->route( array( 'kind' => Boundary::SCOPE_LIST ) )
		);

		$this->assertCount( 4, $GLOBALS['bwx_forge_test_routes'] );
	}

	// -----------------------------------------------------------------------
	// The plugin's own routes, as a set.
	// -----------------------------------------------------------------------

	/**
	 * Walks everything Server::register_routes() actually registers, so a
	 * controller added later cannot slip an unscoped route past the suite. This
	 * is the check the issue asks for by name.
	 */
	public function test_every_registered_route_declares_a_scope(): void {
		Server::register_routes();

		$this->assertNotEmpty( $GLOBALS['bwx_forge_test_routes'], 'no routes were registered at all' );

		foreach ( $GLOBALS['bwx_forge_test_routes'] as $route ) {
			$this->assertArrayHasKey( 'scope', $route['args'], sprintf( '%s declares no scope', $route['route'] ) );
			$this->assertTrue(
				Boundary::is_scope( (string) ( $route['args']['scope']['kind'] ?? '' ) ),
				sprintf( '%s declares a scope nobody defined', $route['route'] )
			);
		}
	}

	/**
	 * Every route left outside the boundary carries its reason, and the reasons
	 * are readable together — which is the list somebody reviewing tenancy wants
	 * to see, rather than a grep across ten controllers.
	 */
	public function test_every_unscoped_route_carries_its_reason(): void {
		Server::register_routes();

		foreach ( $GLOBALS['bwx_forge_test_routes'] as $route ) {
			if ( Boundary::SCOPE_OPEN !== (string) ( $route['args']['scope']['kind'] ?? '' ) ) {
				continue;
			}

			$this->assertNotSame(
				'',
				trim( (string) ( $route['args']['scope']['reason'] ?? '' ) ),
				sprintf( '%s is outside the boundary with no reason given', $route['route'] )
			);
		}
	}

	// -----------------------------------------------------------------------
	// What a refusal looks like.
	// -----------------------------------------------------------------------

	/**
	 * D-1 and D-2. A record outside somebody's reach does not exist as far as
	 * they are concerned, so the answer is the same one they would get for an id
	 * nobody has ever used. A 403 here would confirm which ids are real.
	 */
	public function test_a_record_out_of_reach_is_answered_as_absent(): void {
		$refusal = Boundary::hidden();

		$this->assertInstanceOf( WP_Error::class, $refusal );
		$this->assertSame( 404, $refusal->get_error_data()['status'] );
	}

	/**
	 * The refusal says nothing about tenancy. A message reading "that belongs to
	 * another client" is the same disclosure as a 403, written politely.
	 */
	public function test_the_refusal_does_not_mention_another_tenant(): void {
		$message = strtolower( Boundary::hidden()->get_error_message() );

		$this->assertStringNotContainsString( 'another', $message );
		$this->assertStringNotContainsString( 'permission', $message );
	}

	/**
	 * The one that matters, and the one an end-to-end test found: hiding a
	 * record is only hiding it if the answer is **byte for byte** the answer an
	 * id nobody has ever used would get.
	 *
	 * Two different 404s are not the same answer. A caller comparing the error
	 * codes learns which ids are real on a tenant they have nothing to do with,
	 * which is precisely what D-1 and D-2 forbid — and it is a leak that reads
	 * as correct in every code review, because both are 404s.
	 */
	public function test_a_hidden_record_answers_exactly_as_a_missing_one(): void {
		foreach ( Boundary::RECORDS as $record => $ignored ) {
			$hidden  = Boundary::hidden( $record );
			$missing = Boundary::absent( $record );

			$this->assertSame( $missing->get_error_code(), $hidden->get_error_code(), $record );
			$this->assertSame( $missing->get_error_message(), $hidden->get_error_message(), $record );
			$this->assertSame( $missing->get_error_data(), $hidden->get_error_data(), $record );
		}
	}

	/**
	 * A route scoped to a record has to say which kind, or the boundary cannot
	 * refuse it in that record's own words and would fall back to a generic
	 * answer — which is the leak above, reintroduced quietly.
	 */
	public function test_a_record_scope_must_name_the_kind_of_record(): void {
		$this->expectException( InvalidArgumentException::class );

		Server::register_route(
			'blueworx-forge/v1',
			'/thing',
			$this->route(
				array(
					'kind'  => Boundary::SCOPE_ITEM,
					'param' => 'item_id',
				)
			)
		);
	}

	/**
	 * And every one of the plugin's own does.
	 */
	public function test_every_record_scoped_route_names_its_record(): void {
		Server::register_routes();

		foreach ( $GLOBALS['bwx_forge_test_routes'] as $route ) {
			$scope = (array) ( $route['args']['scope'] ?? array() );
			$kind  = (string) ( $scope['kind'] ?? '' );

			if ( ! in_array( $kind, array( Boundary::SCOPE_SITE, Boundary::SCOPE_CLIENT, Boundary::SCOPE_ITEM ), true ) ) {
				continue;
			}

			$this->assertArrayHasKey( 'record', $scope, sprintf( '%s names no record', $route['route'] ) );
			$this->assertArrayHasKey(
				(string) $scope['record'],
				Boundary::RECORDS,
				sprintf( '%s names a record nobody defined', $route['route'] )
			);
		}
	}
}
