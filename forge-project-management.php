<?php
/**
 * Plugin Name: Forge Project Management
 * Plugin URI:  https://github.com/blueworx-io/blueworx_project_forge
 * Description: Product planning and release management for WordPress.
 * Version:     1.38.0
 * Requires at least: 6.5
 * Requires PHP: 8.2
 * Author:      Blueworx
 * License:     GPL-2.0-or-later
 * Text Domain: forge-pm
 */

defined( 'ABSPATH' ) || exit;

// Must equal the Version: header above and the version in package.json — CI
// fails the build if any two disagree.
define( 'FORGE_PM_VERSION', '1.38.0' );
define( 'FORGE_PM_DIR', plugin_dir_path( __FILE__ ) );
define( 'FORGE_PM_URL', plugin_dir_url( __FILE__ ) );
define( 'FORGE_PM_BASENAME', plugin_basename( __FILE__ ) );

require_once FORGE_PM_DIR . 'plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

// Sites update themselves from this repo's GitHub Releases — nobody uploads a
// zip to update the plugin. The third argument must equal the plugin's folder
// name, the release workflow's plugin_slug, and the site's installed directory
// name; if they disagree, WordPress installs the update alongside the original
// as a second copy and deactivates it.
$forge_pm_update_checker = PucFactory::buildUpdateChecker(
	'https://github.com/blueworx-io/blueworx_project_forge/',
	__FILE__,
	'forge-project-management'
);

/*
 * The repo is private, so a site needs a token to see releases at all. It lives
 * in wp-config.php — never in the plugin, never in the repo:
 *
 *     define( 'BLUEWORX_PLUGIN_UPDATE_TOKEN', 'github_pat_...' );
 */
if ( defined( 'BLUEWORX_PLUGIN_UPDATE_TOKEN' ) && BLUEWORX_PLUGIN_UPDATE_TOKEN ) {
	$forge_pm_update_checker->setAuthentication( BLUEWORX_PLUGIN_UPDATE_TOKEN );
}

/*
 * Install the zip attached to the Release, not GitHub's auto-generated source
 * tarball. The tarball's folder is named <repo>-<version>, so without this the
 * update extracts to the wrong folder — WordPress treats it as a different
 * plugin and the original deactivates — and it ships every dev file in the repo.
 */
$forge_pm_update_checker->getVcsApi()->enableReleaseAssets();

require_once FORGE_PM_DIR . 'includes/class-status.php';
require_once FORGE_PM_DIR . 'includes/class-roles.php';
require_once FORGE_PM_DIR . 'includes/class-post-types.php';
require_once FORGE_PM_DIR . 'includes/class-rest-api.php';
require_once FORGE_PM_DIR . 'includes/class-sample-data.php';
require_once FORGE_PM_DIR . 'includes/class-page-generator.php';
require_once FORGE_PM_DIR . 'includes/class-enqueue.php';
require_once FORGE_PM_DIR . 'includes/class-settings.php';

// Register the fatal-error catcher as early as possible so it captures plugin
// fatals during any request (front-end, REST, or admin).
Forge_PM_Status::init();

register_activation_hook( __FILE__, array( 'Forge_PM_Page_Generator', 'activate' ) );
register_activation_hook( __FILE__, array( 'Forge_PM_Roles', 'add_roles' ) );
register_deactivation_hook( __FILE__, array( 'Forge_PM_Page_Generator', 'deactivate' ) );
register_deactivation_hook( __FILE__, array( 'Forge_PM_Roles', 'remove_roles' ) );

add_action( 'init', array( 'Forge_PM_Post_Types', 'register' ) );
add_action( 'init', array( 'Forge_PM_Page_Generator', 'register_template' ) );
add_action( 'init', array( 'Forge_PM_Settings', 'register_post_status' ) );
add_action( 'rest_api_init', array( 'Forge_PM_REST_API', 'register_routes' ) );
add_action( 'rest_api_init', array( 'Forge_PM_Settings', 'register_routes' ) );
add_action( 'rest_api_init', array( 'Forge_PM_Status', 'register_routes' ) );
add_action( 'wp_enqueue_scripts', array( 'Forge_PM_Enqueue', 'enqueue' ) );
add_filter( 'show_admin_bar', array( 'Forge_PM_Enqueue', 'maybe_hide_admin_bar' ) );
add_filter( 'login_redirect', array( 'Forge_PM_Page_Generator', 'login_redirect' ), 10, 3 );
add_action( 'save_post', array( 'Forge_PM_REST_API', 'bust_cache' ) );

add_shortcode( 'forge_project_management', array( 'Forge_PM_Enqueue', 'render_app' ) );
