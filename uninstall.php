<?php
/**
 * Removes Forge Project Management's own data on uninstall, and nothing else.
 *
 * Forge stores no custom tables — its items are custom post types and its
 * configuration lives in options — so uninstall clears the options, the cached
 * item payloads, and the two roles the plugin adds.
 *
 * Deliberately left alone: the Forge items themselves (features, releases, bugs,
 * feedback, sub-items, company dates) and the generated app page. Those are the
 * site's content, entered by its users, not the plugin's to delete — and a
 * reinstall picks them straight back up.
 *
 * @package Forge_PM
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Options are listed here rather than read from the classes because uninstall
// runs without the plugin loaded. Adding an option means adding it here too.
$forge_pm_options = array(
	'forge_pm_page_id',
	'forge_pm_settings',
	'forge_pm_client_errors',
	'forge_pm_php_errors',
);

foreach ( $forge_pm_options as $forge_pm_option ) {
	delete_option( $forge_pm_option );
}

// Cached REST payloads, one per audience.
delete_transient( 'forge_pm_items_auth' );
delete_transient( 'forge_pm_items_pub' );

// The roles the plugin adds on activation. Users holding them keep their
// accounts and fall back to the site's default role.
remove_role( 'forge_manager' );
remove_role( 'forge_user' );
