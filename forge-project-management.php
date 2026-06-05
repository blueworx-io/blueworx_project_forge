<?php
/**
 * Plugin Name: Forge Project Management
 * Plugin URI:  https://github.com/blueworx-io/forge-project-management
 * Description: Product planning and release management for WordPress.
 * Version:     1.0.9
 * Author:      Blueworx
 * License:     GPL-2.0-or-later
 * Text Domain: forge-pm
 */

defined( 'ABSPATH' ) || exit;

define( 'FORGE_PM_VERSION',  '1.0.9' );
define( 'FORGE_PM_DIR',      plugin_dir_path( __FILE__ ) );
define( 'FORGE_PM_URL',      plugin_dir_url( __FILE__ ) );
define( 'FORGE_PM_BASENAME', plugin_basename( __FILE__ ) );

require_once FORGE_PM_DIR . 'includes/class-post-types.php';
require_once FORGE_PM_DIR . 'includes/class-rest-api.php';
require_once FORGE_PM_DIR . 'includes/class-sample-data.php';
require_once FORGE_PM_DIR . 'includes/class-page-generator.php';
require_once FORGE_PM_DIR . 'includes/class-enqueue.php';
require_once FORGE_PM_DIR . 'includes/class-settings.php';

register_activation_hook(   __FILE__, [ 'Forge_PM_Page_Generator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'Forge_PM_Page_Generator', 'deactivate' ] );

add_action( 'init',               [ 'Forge_PM_Post_Types',    'register' ] );
add_action( 'init',               [ 'Forge_PM_Page_Generator', 'register_template' ] );
add_action( 'init',               [ 'Forge_PM_Settings',       'register_post_status' ] );
add_action( 'rest_api_init',      [ 'Forge_PM_REST_API',        'register_routes' ] );
add_action( 'rest_api_init',      [ 'Forge_PM_Settings',        'register_routes' ] );
add_action( 'wp_enqueue_scripts', [ 'Forge_PM_Enqueue',         'enqueue' ] );

add_shortcode( 'forge_project_management', [ 'Forge_PM_Enqueue', 'render_app' ] );
