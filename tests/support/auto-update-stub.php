<?php
/**
 * Release offers and the background updater, for the test suite only.
 *
 * Copied into a test WordPress's mu-plugins directory by
 * tests/e2e/helpers/auto-update.js before a spec that needs it, and removed
 * afterwards. Never shipped: tests/ is outside every build allowlist.
 *
 * The suite blocks outbound requests (tests/global-setup.js) and there is no
 * real release to install, so this supplies both halves a spec needs to watch
 * WordPress update by itself:
 *
 * - update data for the plugins under test, so the Plugins screen has
 *   something to say about each of them without asking WordPress.org; and
 * - a route that offers a release, runs the same background updater cron
 *   runs, and reports which packages it asked for and what is installed.
 *
 * A package is served from the stub rather than over HTTP. One that has no
 * zip behind it answers the download with an error, so the updater has asked
 * for it, which is the decision to install, and stops there. That is how a
 * plugin that is offered but not ours is shown to be left alone.
 *
 * Nothing leaves the machine, and only the releases a test offers are ever on
 * offer: whatever WordPress.org or GitHub would offer is dropped.
 *
 * The routes install code, so they take an administrator only, and a version
 * that is a plain number.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

const BWX_FORGE_UPDATE_STUB_OFFERS    = 'bwx_forge_test_update_offers';
const BWX_FORGE_UPDATE_STUB_DOWNLOADS = 'bwx_forge_test_update_downloads';
const BWX_FORGE_UPDATE_STUB_ZIPS      = 'bwx_forge_test_update_zips';
const BWX_FORGE_UPDATE_STUB_PREFIX    = 'https://example.test/updates/';

/**
 * The plugins the stub knows about.
 *
 * @return array<int, string>
 */
function bwx_forge_update_stub_plugins(): array {
	return array(
		'akismet/akismet.php',
		'hello.php',
		'blueworx-forge/blueworx-forge.php',
		'blueworx-forge-client/blueworx-forge-client.php',
	);
}

/**
 * A plugin's slug from its file.
 *
 * @param string $file Folder and main file, or a single file.
 * @return string
 */
function bwx_forge_update_stub_slug( string $file ): string {
	return '.' === dirname( $file ) ? basename( $file, '.php' ) : dirname( $file );
}

/**
 * The version each installed plugin's file declares, read fresh from disk.
 *
 * @return array<string, string>
 */
function bwx_forge_update_stub_versions(): array {
	$versions = array();

	foreach ( bwx_forge_update_stub_plugins() as $file ) {
		$path = WP_PLUGIN_DIR . '/' . $file;

		if ( file_exists( $path ) ) {
			$versions[ $file ] = get_file_data( $path, array( 'Version' => 'Version' ) )['Version'];
		}
	}

	return $versions;
}

/**
 * Copies a plugin into a release zip with its header version changed.
 *
 * @param string $file    The plugin's folder and main file.
 * @param string $version The version the release carries.
 * @return string The zip's path.
 */
function bwx_forge_update_stub_build_zip( string $file, string $version ): string {
	require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';

	$slug   = bwx_forge_update_stub_slug( $file );
	$source = WP_PLUGIN_DIR . '/' . $slug;
	$work   = trailingslashit( get_temp_dir() ) . 'bwx-release-' . wp_generate_password( 8, false );
	$copy   = $work . '/' . $slug;

	$zip = $work . '.zip';

	try {
		wp_mkdir_p( $copy );

		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $items as $item ) {
			$target = $copy . '/' . substr( $item->getPathname(), strlen( $source ) + 1 );

			if ( $item->isDir() ) {
				wp_mkdir_p( $target );
			} else {
				copy( $item->getPathname(), $target );
			}
		}

		$main    = $copy . '/' . basename( $file );
		$changed = preg_replace( '/^(\s*\*\s*Version:\s*).+$/m', '${1}' . $version, (string) file_get_contents( $main ), 1 );

		file_put_contents( $main, $changed );

		$archive = new PclZip( $zip );
		$archive->create( $copy, PCLZIP_OPT_REMOVE_PATH, $work );
	} catch ( Throwable $failure ) {
		if ( file_exists( $zip ) ) {
			unlink( $zip ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}

		throw $failure;
	} finally {
		// The copy is only there to be zipped.
		bwx_forge_update_stub_remove_tree( $work );
	}

	return $zip;
}

/**
 * Removes a folder and everything in it.
 *
 * @param string $dir The folder.
 */
function bwx_forge_update_stub_remove_tree( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}

	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $items as $item ) {
		if ( $item->isDir() && ! $item->isLink() ) {
			rmdir( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rmdir_rmdir
		} else {
			unlink( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}

	rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rmdir_rmdir
}

/**
 * Whether a plugin's folder is safe to have an update replace.
 *
 * @param string $file The plugin's folder and main file.
 * @return bool
 */
function bwx_forge_update_stub_may_replace( string $file ): bool {
	$dir = WP_PLUGIN_DIR . '/' . bwx_forge_update_stub_slug( $file );

	// A link swapped for a real folder between requests must not be answered
	// from the path cache of an earlier one.
	clearstatcache( true );

	$real = realpath( $dir );

	if ( false === $real ) {
		return false;
	}

	$real    = wp_normalize_path( $real );
	$plugins = wp_normalize_path( (string) realpath( WP_PLUGIN_DIR ) );

	// A real folder inside the plugins directory, or the staged copy the
	// two-site harness builds for the client site and throws away.
	return 0 === strpos( $real, $plugins . '/' ) || false !== strpos( $real, '/.wp-test-client-plugin/' );
}

add_filter(
	'site_transient_update_plugins',
	/**
	 * Gives every plugin under test an entry, and a release to the ones offered.
	 *
	 * @param mixed $value The stored update data.
	 * @return object
	 */
	static function ( $value ) {
		if ( ! is_object( $value ) ) {
			$value = new stdClass();
		}

		// Whatever WordPress.org or GitHub offered is dropped: a test must
		// never install a live release.
		$value->response  = array();
		$value->no_update = isset( $value->no_update ) ? (array) $value->no_update : array();
		$offers           = (array) get_option( BWX_FORGE_UPDATE_STUB_OFFERS, array() );

		foreach ( bwx_forge_update_stub_plugins() as $file ) {
			if ( ! file_exists( WP_PLUGIN_DIR . '/' . $file ) ) {
				continue;
			}

			$slug  = bwx_forge_update_stub_slug( $file );
			$entry = (object) array(
				'id'          => 'w.org/plugins/' . $slug,
				'slug'        => $slug,
				'plugin'      => $file,
				'new_version' => '1.0.0',
				'url'         => '',
				'package'     => '',
			);

			if ( isset( $offers[ $file ] ) ) {
				$entry->new_version = (string) $offers[ $file ];
				$entry->package     = BWX_FORGE_UPDATE_STUB_PREFIX . $slug . '.zip';

				unset( $value->no_update[ $file ] );
				$value->response[ $file ] = $entry;
			} elseif ( ! isset( $value->response[ $file ] ) && ! isset( $value->no_update[ $file ] ) ) {
				$value->no_update[ $file ] = $entry;
			}
		}

		return $value;
	},
	99
);

add_filter(
	'pre_http_request',
	static function () {
		return new WP_Error( 'bwx_forge_test_offline', 'Outbound HTTP is blocked during the test run.' );
	},
	0
);

add_filter(
	'upgrader_pre_download',
	/**
	 * Answers the download of an offered package from the stub.
	 *
	 * @param mixed  $reply   What an earlier filter decided.
	 * @param string $package Where the updater was told to fetch it.
	 * @return mixed A path to the zip, an error when there is none, or $reply.
	 */
	static function ( $reply, $package ) {
		if ( 0 !== strpos( (string) $package, BWX_FORGE_UPDATE_STUB_PREFIX ) ) {
			return $reply;
		}

		$downloads   = (array) get_option( BWX_FORGE_UPDATE_STUB_DOWNLOADS, array() );
		$downloads[] = (string) $package;
		update_option( BWX_FORGE_UPDATE_STUB_DOWNLOADS, $downloads, false );

		$zips = (array) get_option( BWX_FORGE_UPDATE_STUB_ZIPS, array() );

		if ( isset( $zips[ $package ] ) ) {
			return $zips[ $package ];
		}

		return new WP_Error( 'bwx_test_stop', 'The test asked for no zip behind this package.' );
	},
	1,
	2
);

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'bwx-forge-test/v1',
			'/updates/versions',
			array(
				'methods'             => 'GET',
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
				'callback'            => static function () {
					return new WP_REST_Response( bwx_forge_update_stub_versions(), 200 );
				},
			)
		);

		// Sets which of the two plugins that are not ours have automatic updates
		// on, and says what they were, so a test starts from a known choice and
		// puts the site's own back. Nothing else in the option is touched.
		register_rest_route(
			'bwx-forge-test/v1',
			'/updates/others',
			array(
				'methods'             => 'POST',
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
				'callback'            => static function ( WP_REST_Request $request ) {
					$others  = array( 'akismet/akismet.php', 'hello.php' );
					$wanted  = array_values( array_intersect( $others, array_map( 'strval', (array) $request->get_param( 'enabled' ) ) ) );
					$current = array_map( 'strval', (array) get_site_option( 'auto_update_plugins', array() ) );
					$before  = array_values( array_intersect( $others, $current ) );
					$kept    = array_values( array_diff( $current, $others ) );

					update_site_option( 'auto_update_plugins', array_merge( $kept, $wanted ) );

					return new WP_REST_Response( array( 'enabled' => $before ), 200 );
				},
			)
		);

		register_rest_route(
			'bwx-forge-test/v1',
			'/updates/run',
			array(
				'methods'             => 'POST',
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
				'callback'            => static function ( WP_REST_Request $request ) {
					$offers = (array) $request->get_param( 'offers' );
					$build  = (array) $request->get_param( 'build' );
					$zips   = array();

					// A version is written into a plugin file that is then installed,
					// so only a plain version number, for a plugin under test, goes in.
					foreach ( $offers as $file => $version ) {
						if ( ! in_array( $file, bwx_forge_update_stub_plugins(), true ) || ! is_string( $version ) || 1 !== preg_match( '/^\d+\.\d+\.\d+$/', $version ) ) {
							return new WP_REST_Response( array( 'error' => 'not a plugin under test, or not a version' ), 400 );
						}
					}

					foreach ( $build as $file ) {
						$file = (string) $file;

						// Installing replaces the plugin's folder. Where that folder is a link,
						// it empties whatever the link points at: a developer's own checkout, in
						// the studio's case. Only the client site's disposable staged copy is
						// built for.
						if ( ! isset( $offers[ $file ] ) || ! bwx_forge_update_stub_may_replace( $file ) ) {
							return new WP_REST_Response( array( 'error' => 'refused to build ' . $file ), 400 );
						}

						$zips[ BWX_FORGE_UPDATE_STUB_PREFIX . bwx_forge_update_stub_slug( $file ) . '.zip' ] = bwx_forge_update_stub_build_zip( $file, (string) $offers[ $file ] );
					}

					update_option( BWX_FORGE_UPDATE_STUB_OFFERS, $offers, false );
					update_option( BWX_FORGE_UPDATE_STUB_ZIPS, $zips, false );
					delete_option( BWX_FORGE_UPDATE_STUB_DOWNLOADS );

					$before = bwx_forge_update_stub_versions();

					// A run a few minutes ago leaves its lock behind.
					delete_option( 'auto_updater.lock' );

					// As cron runs it: plugins stay active through an update, and a
					// test site inside a checkout is not mistaken for a developer's.
					add_filter( 'wp_doing_cron', '__return_true' );
					add_filter( 'automatic_updates_is_vcs_checkout', '__return_false' );
					add_filter( 'auto_core_update_send_email', '__return_false' );
					add_filter( 'auto_plugin_update_send_email', '__return_false' );
					add_filter( 'auto_update_core', '__return_false' );
					add_filter( 'auto_update_theme', '__return_false' );
					add_filter( 'auto_update_translation', '__return_false' );

					// After an update WordPress asks the site's own front page
					// whether it still loads, and a test server that answers one
					// request at a time cannot answer itself. Nothing to report
					// is what a healthy page says.
					add_filter(
						'pre_http_request',
						static function ( $answer, $args, $url ) {
							if ( false === strpos( (string) $url, 'wp_scrape_key=' ) ) {
								return $answer;
							}

							return array(
								'headers'  => array(),
								'body'     => '',
								'response' => array(
									'code'    => 200,
									'message' => 'OK',
								),
								'cookies'  => array(),
								'filename' => null,
							);
						},
						PHP_INT_MAX,
						3
					);

					wp_maybe_auto_update();

					wp_clean_plugins_cache();
					delete_option( BWX_FORGE_UPDATE_STUB_OFFERS );
					delete_option( BWX_FORGE_UPDATE_STUB_ZIPS );

					// An installed zip is deleted by WordPress; one that was refused is not.
					foreach ( $zips as $zip ) {
						if ( file_exists( $zip ) ) {
							unlink( $zip ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
						}
					}

					return new WP_REST_Response(
						array(
							'before'    => $before,
							'after'     => bwx_forge_update_stub_versions(),
							'downloads' => array_values( (array) get_option( BWX_FORGE_UPDATE_STUB_DOWNLOADS, array() ) ),
						),
						200
					);
				},
			)
		);
	}
);
