<?php
/**
 * Plugin Name: BlueWorx Labs | Forge Client Site
 * Plugin URI:  https://github.com/blueworx-io/blueworx_project_forge
 * Description: The client-side workspace for Blueworx Forge.
 * Version:     2.72.0
 * Requires at least: 6.5
 * Requires PHP: 8.2
 * Author:      Blueworx
 * Author URI:  https://blueworx.io
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: blueworx-forge
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

/*
 * This file deliberately does not sit at the repository root, and moving it
 * there breaks the release build. The shared release workflow finds a plugin's
 * main file by scanning the root first, alphabetically — and
 * "blueworx-forge-client.php" sorts before "blueworx-forge.php", so a root copy
 * would be resolved as the studio plugin's main file and the release would be
 * checked against the wrong header.
 *
 * Everything this artifact ships lives under client/. That is the whole
 * guarantee behind ARCH-1's two artifacts: a client's WordPress cannot
 * physically contain command-centre code, rather than being configured not to
 * run it. bin/check-artifacts.mjs enforces it on every pull request.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The plugin version. Must equal the Version: header above and the version in
 * package.json — CI fails the build if any two disagree.
 */
define( 'BWX_FORGE_CLIENT_VERSION', '2.72.0' );
define( 'BWX_FORGE_CLIENT_SLUG', 'blueworx-forge-client' );
define( 'BWX_FORGE_CLIENT_FILE', __FILE__ );
define( 'BWX_FORGE_CLIENT_PATH', plugin_dir_path( __FILE__ ) );
define( 'BWX_FORGE_CLIENT_URL', plugin_dir_url( __FILE__ ) );

require_once BWX_FORGE_CLIENT_PATH . 'includes/functions.php';
require_once BWX_FORGE_CLIENT_PATH . 'includes/autoload.php';

// Registered before the update checker below, which asks Updates for the token
// this site should authenticate with.
bwx_forge_client_register_autoloader( BWX_FORGE_CLIENT_PATH . 'includes' );

require_once BWX_FORGE_CLIENT_PATH . 'plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

// Client sites update themselves from this repo's GitHub Releases, from the
// client asset rather than the studio one. The third argument must equal the
// plugin's folder name on the site, or WordPress installs the update alongside
// the original as a second copy and deactivates it.
$bwx_forge_client_update_checker = PucFactory::buildUpdateChecker(
	'https://github.com/blueworx-io/blueworx_project_forge/',
	BWX_FORGE_CLIENT_FILE,
	'blueworx-forge-client'
);

/*
 * The repo is private, so a site needs a token to see releases at all. It can
 * be set on this site's Forge connection screen, or fixed in wp-config.php,
 * which wins — a secret in a file does not travel in a database export.
 */
$bwx_forge_client_update_token = \Blueworx\Forge\Client\Updates::token();

if ( '' !== $bwx_forge_client_update_token ) {
	$bwx_forge_client_update_checker->setAuthentication( $bwx_forge_client_update_token );
}

$bwx_forge_client_update_checker->getVcsApi()->enableReleaseAssets();

register_activation_hook( BWX_FORGE_CLIENT_FILE, array( \Blueworx\Forge\Client\Plugin::instance(), 'activate' ) );
register_deactivation_hook( BWX_FORGE_CLIENT_FILE, array( \Blueworx\Forge\Client\Plugin::instance(), 'deactivate' ) );

add_action( 'plugins_loaded', array( \Blueworx\Forge\Client\Plugin::instance(), 'boot' ) );
