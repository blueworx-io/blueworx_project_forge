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

require_once dirname( __DIR__, 2 ) . '/includes/autoload.php';
bwx_forge_register_autoloader( dirname( __DIR__, 2 ) . '/includes' );
