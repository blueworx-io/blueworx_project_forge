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
	'bwx_forge_client_studio_url',
	'bwx_forge_client_site_id',
	// The signing key. Leaving this behind would mean an uninstalled plugin's
	// credentials sitting in the database of a site nobody is watching.
	'bwx_forge_client_key',
	// The read-through cache. It is a copy of studio records, not this site's
	// own data, so there is nothing to keep once the plugin is gone.
	'bwx_forge_client_cache',
	// Says the copy above was taken out of every page's load (#712). Left behind,
	// an older version reinstalled would rebuild the copy and never have it taken out.
	'bwx_forge_client_cache_lean',
);

// Uninstalling from a network removes the plugin from every site on it, so
// every site's copy of these goes, not only the main site's. A flag left on
// another site would make a later upgrade there skip the fix it stands for.
$bwx_forge_client_sites = array( 0 );

if ( is_multisite() ) {
	$bwx_forge_client_sites = array_map(
		'intval',
		get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		)
	);
}

foreach ( $bwx_forge_client_sites as $bwx_forge_client_site ) {
	if ( $bwx_forge_client_site ) {
		switch_to_blog( $bwx_forge_client_site );
	}

	foreach ( $bwx_forge_client_options as $bwx_forge_client_option ) {
		delete_option( $bwx_forge_client_option );
	}

	if ( $bwx_forge_client_site ) {
		restore_current_blog();
	}
}
