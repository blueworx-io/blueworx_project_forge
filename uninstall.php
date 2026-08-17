<?php
/**
 * Removes Blueworx Forge's own data on uninstall, and nothing else.
 *
 * Options are listed here rather than read from the plugin's classes because
 * uninstall runs without the plugin loaded. Adding an option means adding it
 * here too, and tests/php/UninstallTest.php asserts every name carries the
 * plugin's prefix — the old Forge Project Management plugin may be installed
 * alongside this one during migration, and its data is not ours to delete.
 *
 * The generated app page is deliberately left alone: it is a published page the
 * site owns, not the plugin's to remove.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$bwx_forge_options = array(
	'bwx_forge_app_page_id',
);

foreach ( $bwx_forge_options as $bwx_forge_option ) {
	delete_option( $bwx_forge_option );
}
