<?php
/**
 * Removes Blueworx Forge Client's own data on uninstall, and nothing else.
 *
 * Options are listed here rather than read from the plugin's classes because
 * uninstall runs without the plugin loaded. Every name carries this artifact's
 * own prefix: the studio plugin may be installed on the same machine during
 * development, and its data is not ours to delete.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$bwx_forge_client_options = array(
	'bwx_forge_client_installed_version',
);

foreach ( $bwx_forge_client_options as $bwx_forge_client_option ) {
	delete_option( $bwx_forge_client_option );
}
