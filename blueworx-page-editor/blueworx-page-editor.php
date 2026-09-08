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

\Blueworx\PageEditor\Registry::add( '1.0.0', __DIR__ . '/v1', __FILE__ );

add_action( 'plugins_loaded', [ '\Blueworx\PageEditor\Registry', 'load' ], 0 );
