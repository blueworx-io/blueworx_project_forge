<?php
/**
 * BlueWorx page editor — loader.
 *
 * A plugin requires this one file. Several plugins on a site may each carry
 * their own copy; every copy registers its version here and the highest wins,
 * so the newest library on the site serves all of them.
 *
 * Do not edit this folder in a plugin. It is compared against the foundation on
 * every pull request; the fix for a difference is always to re-pull.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'BWPE_TESTING' ) ) {
	exit;
}

if ( ! class_exists( 'Blueworx\PageEditor\Registry', false ) ) {
	require_once __DIR__ . '/Registry.php';
}

// The design system is a peer of the library, not part of it: a plugin can use
// the design system without the editor. Required here only because a plugin
// that has both should not have to remember the order.
$blueworx_admin_design = dirname( __DIR__ ) . '/assets/blueworx-admin-design.php';
if ( file_exists( $blueworx_admin_design ) ) {
	require_once $blueworx_admin_design;
}

\Blueworx\PageEditor\Registry::add( '1.0.0', __DIR__ . '/v1', __FILE__ );

add_action( 'plugins_loaded', [ '\Blueworx\PageEditor\Registry', 'load' ], 0 );
