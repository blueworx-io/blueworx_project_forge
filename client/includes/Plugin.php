<?php
/**
 * Client plugin lifecycle.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client;

/**
 * The client artifact's lifecycle, and for now nothing else.
 *
 * This is a skeleton on purpose. The client workspace needs the site to prove
 * which client it is before it can show anything (ARCH-6), and that layer is
 * not built yet. What this establishes is the shape: a second plugin that
 * installs, activates and updates itself independently of the studio one, with
 * no studio code inside it to reach for.
 */
final class Plugin {

	/**
	 * The single instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Returns the single instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Hooks everything up. Called on plugins_loaded.
	 *
	 * Nothing yet. The workspace arrives with the authentication layer.
	 */
	public function boot(): void {
		/*
		 * Intentionally empty. The client plugin activating cleanly and doing
		 * nothing is exactly what this milestone claims, and a stub that quietly
		 * registered a hook would make that claim untrue.
		 */
	}

	/**
	 * Runs on activation.
	 */
	public function activate(): void {
		update_option( 'bwx_forge_client_installed_version', BWX_FORGE_CLIENT_VERSION );
	}

	/**
	 * Runs on deactivation.
	 */
	public function deactivate(): void {
		/*
		 * Nothing to tear down. Deactivating is not uninstalling, and the one
		 * option this plugin owns is removed by uninstall.php.
		 */
	}
}
