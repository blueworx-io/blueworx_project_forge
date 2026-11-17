<?php
/**
 * Claiming a GitHub delivery.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\GitHub\Deliveries;
use PHPUnit\Framework\TestCase;

/**
 * #680. A delivery seen before is a repeat; a database that refused the
 * write for any other reason is a failure, and must not read as a repeat.
 */
final class GitHubDeliveriesTest extends TestCase {

	/**
	 * The stub $wpdb the other tests share.
	 *
	 * @var object
	 */
	private $shared;

	protected function setUp(): void {
		$this->shared = $GLOBALS['wpdb'];
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->shared;
	}

	/**
	 * A database that answers inserts as told, and holds the ids named.
	 *
	 * @param int|false          $insert What insert() answers.
	 * @param array<int, string> $held   Ids already in the table.
	 * @return object
	 */
	private function database( $insert, array $held ): object {
		return new class( $insert, $held ) {

			/**
			 * Table prefix.
			 *
			 * @var string
			 */
			public string $prefix = 'wp_';

			/**
			 * The last error.
			 *
			 * @var string
			 */
			public string $last_error = '';

			/**
			 * What insert() answers.
			 *
			 * @var int|false
			 */
			private $insert;

			/**
			 * Ids already in the table.
			 *
			 * @var array<int, string>
			 */
			private array $held;

			/**
			 * Sets it up.
			 *
			 * @param int|false          $insert What insert() answers.
			 * @param array<int, string> $held   Ids already in the table.
			 */
			public function __construct( $insert, array $held ) {
				$this->insert = $insert;
				$this->held   = $held;
			}

			/**
			 * Stub.
			 *
			 * @param bool $suppress Ignored.
			 * @return bool
			 */
			public function suppress_errors( bool $suppress = true ): bool {
				unset( $suppress );
				return false;
			}

			/**
			 * Stub.
			 *
			 * @return int|false
			 */
			public function insert() {
				return $this->insert;
			}

			/**
			 * Stub: %s only.
			 *
			 * @param string $query Query.
			 * @param mixed  ...$args Values.
			 * @return string
			 */
			public function prepare( string $query, ...$args ): string {
				foreach ( $args as $arg ) {
					$query = (string) preg_replace( '/%s/', "'" . (string) $arg . "'", $query, 1 );
				}

				return $query;
			}

			/**
			 * Stub: the id asked for, when held.
			 *
			 * @param string $query Prepared query.
			 * @return string|null
			 */
			public function get_var( string $query ) {
				foreach ( $this->held as $id ) {
					if ( false !== strpos( $query, "'" . $id . "'" ) ) {
						return $id;
					}
				}

				return null;
			}
		};
	}

	public function test_a_new_delivery_is_claimed(): void {
		$GLOBALS['wpdb'] = $this->database( 1, array() );

		self::assertTrue( Deliveries::claim( 'd-1', 'issues', 'opened' ) );
	}

	public function test_a_delivery_seen_before_is_a_repeat(): void {
		$GLOBALS['wpdb'] = $this->database( false, array( 'd-1' ) );

		self::assertFalse( Deliveries::claim( 'd-1', 'issues', 'opened' ) );
	}

	public function test_a_database_failure_is_not_a_repeat(): void {
		$GLOBALS['wpdb'] = $this->database( false, array() );

		$this->expectException( RuntimeException::class );

		Deliveries::claim( 'd-1', 'issues', 'opened' );
	}
}
