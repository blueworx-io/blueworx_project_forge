<?php
/**
 * PHPUnit bootstrap. These tests run without a WordPress runtime: anything that
 * needs a real site belongs in the Playwright suite. The stubs below are the
 * WordPress functions the units under test call, and each records its calls in
 * $GLOBALS so a test can assert on them.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'BWX_FORGE_PATH', dirname( __DIR__, 2 ) . '/' );

$GLOBALS['bwx_forge_test_calls'] = array();

/**
 * Records a stubbed call so a test can assert it happened.
 *
 * @param string $name Function name.
 * @param mixed  $arg  First argument.
 */
function bwx_forge_test_record( string $name, $arg ): void {
	$GLOBALS['bwx_forge_test_calls'][] = array( $name, $arg );
}

/**
 * Stub. Records the deleted option name.
 *
 * @param string $option Option name.
 * @return bool
 */
function delete_option( string $option ): bool {
	bwx_forge_test_record( 'delete_option', $option );
	return true;
}

/**
 * Stub. Records the deleted transient name.
 *
 * @param string $transient Transient name.
 * @return bool
 */
function delete_transient( string $transient ): bool {
	bwx_forge_test_record( 'delete_transient', $transient );
	return true;
}

/**
 * Stub. Returns whatever the test put in $GLOBALS['bwx_forge_test_can'].
 *
 * @param string $capability Capability being checked.
 * @return bool
 */
function current_user_can( string $capability ): bool {
	$allowed = $GLOBALS['bwx_forge_test_can'] ?? array();
	return in_array( $capability, $allowed, true );
}

$GLOBALS['bwx_forge_test_routes']     = array();
$GLOBALS['bwx_forge_test_transients'] = array();

/**
 * Stub of WordPress's error object, with only the parts the units use.
 */
class WP_Error {

	/**
	 * Error code.
	 *
	 * @var string
	 */
	private string $code;

	/**
	 * Human-readable message.
	 *
	 * @var string
	 */
	private string $message;

	/**
	 * Arbitrary error data.
	 *
	 * @var array<string, mixed>
	 */
	private array $data;

	/**
	 * Builds the error.
	 *
	 * @param string               $code    Error code.
	 * @param string               $message Message.
	 * @param array<string, mixed> $data    Error data.
	 */
	public function __construct( string $code = '', string $message = '', array $data = array() ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	/**
	 * The error code.
	 *
	 * @return string
	 */
	public function get_error_code(): string {
		return $this->code;
	}

	/**
	 * The error message.
	 *
	 * @return string
	 */
	public function get_error_message(): string {
		return $this->message;
	}

	/**
	 * The error data.
	 *
	 * @return array<string, mixed>
	 */
	public function get_error_data(): array {
		return $this->data;
	}
}

/**
 * Stub of WordPress's REST response object.
 */
class WP_REST_Response {

	/**
	 * Response body.
	 *
	 * @var mixed
	 */
	private $data;

	/**
	 * HTTP status.
	 *
	 * @var int
	 */
	private int $status;

	/**
	 * Builds the response.
	 *
	 * @param mixed $data   Body.
	 * @param int   $status HTTP status.
	 */
	public function __construct( $data = null, int $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}

	/**
	 * The body.
	 *
	 * @return mixed
	 */
	public function get_data() {
		return $this->data;
	}

	/**
	 * The HTTP status.
	 *
	 * @return int
	 */
	public function get_status(): int {
		return $this->status;
	}
}

/**
 * Stub. Records the route instead of registering it.
 *
 * @param string               $route_namespace Namespace.
 * @param string               $route           Route.
 * @param array<string, mixed> $args            Route arguments.
 * @return bool
 */
function register_rest_route( string $route_namespace, string $route, array $args = array() ): bool {
	$GLOBALS['bwx_forge_test_routes'][] = array(
		'namespace' => $route_namespace,
		'route'     => $route,
		'args'      => $args,
	);

	return true;
}

/**
 * Stub. Reads from the in-memory transient store.
 *
 * @param string $transient Transient name.
 * @return mixed False when absent, matching WordPress.
 */
function get_transient( string $transient ) {
	return $GLOBALS['bwx_forge_test_transients'][ $transient ] ?? false;
}

/**
 * Stub. Writes to the in-memory transient store.
 *
 * @param string $transient  Transient name.
 * @param mixed  $value      Value.
 * @param int    $expiration Lifetime in seconds.
 * @return bool
 */
function set_transient( string $transient, $value, int $expiration = 0 ): bool {
	unset( $expiration );
	$GLOBALS['bwx_forge_test_transients'][ $transient ] = $value;

	return true;
}

/**
 * Stub. Identity — the units under test do not assert on translation.
 *
 * @param string $text        Text.
 * @param string $text_domain Text domain.
 * @return string
 */
function __( string $text, string $text_domain = 'default' ): string {
	unset( $text_domain );
	return $text;
}

/**
 * Stub. Identity — nothing under test renders HTML.
 *
 * @param string $text Text.
 * @return string
 */
function esc_html( string $text ): string {
	return $text;
}

require_once dirname( __DIR__, 2 ) . '/includes/autoload.php';
bwx_forge_register_autoloader( dirname( __DIR__, 2 ) . '/includes' );
