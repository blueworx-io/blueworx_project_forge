<?php
/**
 * Plugin Name: BlueWorx Labs | Forge Parent Site
 * Plugin URI:  https://github.com/blueworx-io/blueworx_project_forge
 * Description: Product planning and release management for WordPress.
 * Version:     2.18.0
 * Requires at least: 6.5
 * Requires PHP: 8.2
 * Author:      Blueworx
 * Author URI:  https://blueworx.io
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: blueworx-forge
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The plugin version. Must equal the Version: header above and the version in
 * package.json — CI fails the build if any two disagree.
 */
define( 'BWX_FORGE_VERSION', '2.18.0' );
define( 'BWX_FORGE_SLUG', 'blueworx-forge' );
define( 'BWX_FORGE_FILE', __FILE__ );
define( 'BWX_FORGE_PATH', plugin_dir_path( __FILE__ ) );
define( 'BWX_FORGE_URL', plugin_dir_url( __FILE__ ) );

require_once BWX_FORGE_PATH . 'includes/functions.php';
require_once BWX_FORGE_PATH . 'includes/autoload.php';

bwx_forge_register_autoloader( BWX_FORGE_PATH . 'includes' );

register_activation_hook( BWX_FORGE_FILE, array( \Blueworx\Forge\Plugin::instance(), 'activate' ) );
register_deactivation_hook( BWX_FORGE_FILE, array( \Blueworx\Forge\Plugin::instance(), 'deactivate' ) );

add_action( 'plugins_loaded', array( \Blueworx\Forge\Plugin::instance(), 'boot' ) );

require_once BWX_FORGE_PATH . 'plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

// Sites update themselves from this repo's GitHub Releases. The third argument
// must equal the plugin's folder name, the release workflow's plugin_slug, and
// the site's installed directory name; if they disagree, WordPress installs the
// update alongside the original as a second copy and deactivates it.
$bwx_forge_update_checker = PucFactory::buildUpdateChecker(
	'https://github.com/blueworx-io/blueworx_project_forge/',
	BWX_FORGE_FILE,
	'blueworx-forge'
);

/*
 * The repo is private, so a site needs a token to see releases at all. It lives
 * in wp-config.php — never in the plugin, never in the repo:
 *
 *     define( 'BLUEWORX_PLUGIN_UPDATE_TOKEN', 'github_pat_...' );
 */
if ( defined( 'BLUEWORX_PLUGIN_UPDATE_TOKEN' ) && BLUEWORX_PLUGIN_UPDATE_TOKEN ) {
	$bwx_forge_update_checker->setAuthentication( BLUEWORX_PLUGIN_UPDATE_TOKEN );
}

/*
 * Install the zip attached to the Release, not GitHub's auto-generated source
 * tarball, whose folder is named <repo>-<version> — WordPress would treat that
 * as a different plugin, and it ships every dev file in the repo.
 */
$bwx_forge_update_checker->getVcsApi()->enableReleaseAssets();
