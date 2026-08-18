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
	// Records the call *and* deletes: a unit that deletes an option and then
	// reads it back has to see it gone, or a cache that is never really cleared
	// passes its own test.
	unset( $GLOBALS['bwx_forge_test_options'][ $option ] );
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
	// Records the call *and* deletes, for the same reason delete_option() does:
	// this stub used to only record, so a unit that deleted a transient and read
	// it straight back still saw it, and "shown once" passed while showing twice.
	unset( $GLOBALS['bwx_forge_test_transients'][ $transient ] );
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

$GLOBALS['bwx_forge_test_options'] = array();
$GLOBALS['bwx_forge_test_actions'] = array();
$GLOBALS['bwx_forge_test_now']     = 1000000;

/**
 * Stub. In-memory option store.
 *
 * @param string $option  Option name.
 * @param mixed  $default_value Returned when the option is unset.
 * @return mixed
 */
function get_option( string $option, $default_value = false ) {
	return $GLOBALS['bwx_forge_test_options'][ $option ] ?? $default_value;
}

/**
 * Stub. In-memory option store.
 *
 * @param string $option Option name.
 * @param mixed  $value  Value.
 * @return bool
 */
function update_option( string $option, $value ): bool {
	$GLOBALS['bwx_forge_test_options'][ $option ] = $value;
	return true;
}

/**
 * Stub. Records the fired action so a test can assert on it.
 *
 * @param string $hook Hook name.
 * @param mixed  ...$args Arguments.
 */
function do_action( string $hook, ...$args ): void {
	$GLOBALS['bwx_forge_test_actions'][] = array( $hook, $args );
}

/**
 * Stub. A clock the tests control, so a window can be tested without waiting.
 *
 * @return int
 */
function bwx_forge_now(): int {
	return (int) ( $GLOBALS['bwx_forge_test_now'] ?? 1000000 );
}

/**
 * Stub. Identity — nothing under test relies on WordPress URL escaping.
 *
 * @param string $url URL.
 * @return string
 */
function esc_url_raw( string $url ): string {
	return $url;
}

/**
 * Stub. Trims to the characters WordPress allows in a key.
 *
 * @param string $key Candidate key.
 * @return string
 */
function sanitize_key( string $key ): string {
	return strtolower( preg_replace( '/[^a-zA-Z0-9_-]/', '', $key ) ?? '' );
}

/**
 * Stub. Identity — nothing under test relies on WordPress text sanitising.
 *
 * @param string $text Text.
 * @return string
 */
function sanitize_text_field( string $text ): string {
	return trim( $text );
}

/**
 * Stub. Identity — the tests do not go through WordPress's slashing.
 *
 * @param string $value Value.
 * @return string
 */
function wp_unslash( string $value ): string {
	return $value;
}

require_once dirname( __DIR__, 2 ) . '/includes/autoload.php';
bwx_forge_register_autoloader( dirname( __DIR__, 2 ) . '/includes' );

/*
 * The client artifact ships separately and has its own autoloader, so the units
 * register both. BlueworxForgeClientX starts with the studio prefix, so the
 * studio autoloader is asked first, finds no file, and hands on — which is
 * exactly the behaviour its "ignore what is not ours" rule is there to give.
 */
require_once dirname( __DIR__, 2 ) . '/client/includes/autoload.php';
bwx_forge_client_register_autoloader( dirname( __DIR__, 2 ) . '/client/includes' );

$GLOBALS['bwx_forge_test_http']          = array();
$GLOBALS['bwx_forge_test_http_requests'] = array();
$GLOBALS['bwx_forge_client_test_now']    = 2000000;

/**
 * Stub. A clock the client artifact's tests control, so a cache can be aged
 * without waiting a minute for it.
 *
 * @return int
 */
function bwx_forge_client_now(): int {
	return (int) ( $GLOBALS['bwx_forge_client_test_now'] ?? 2000000 );
}

/**
 * Stub. Returns whatever the test queued, and records the request.
 *
 * A queued WP_Error stands for the studio being unreachable; a queued array
 * stands for an answer, in WordPress's own response shape.
 *
 * @param string               $url  Request URL.
 * @param array<string, mixed> $args Request arguments.
 * @return array<string, mixed>|WP_Error
 */
function wp_remote_get( string $url, array $args = array() ) {
	$GLOBALS['bwx_forge_test_http_requests'][] = array(
		'url'  => $url,
		'args' => $args,
	);

	$queued = array_shift( $GLOBALS['bwx_forge_test_http'] );

	return null === $queued
		? new WP_Error( 'bwx_forge_test_no_response_queued', 'No response queued.' )
		: $queued;
}

/**
 * Stub.
 *
 * @param mixed $thing Anything.
 * @return bool
 */
function is_wp_error( $thing ): bool {
	return $thing instanceof WP_Error;
}

/**
 * Stub.
 *
 * @param array<string, mixed> $response Response.
 * @return int
 */
function wp_remote_retrieve_response_code( $response ): int {
	return (int) ( $response['response']['code'] ?? 0 );
}

/**
 * Stub.
 *
 * @param array<string, mixed> $response Response.
 * @return string
 */
function wp_remote_retrieve_body( $response ): string {
	return (string) ( $response['body'] ?? '' );
}

/**
 * Stub. JSON encoding, as WordPress's own wrapper does it.
 *
 * @param mixed $data Anything encodable.
 * @return string|false
 */
function wp_json_encode( $data ) {
	return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
}
