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
	 * The connection routes prove which client this site is (ARCH-6); the
	 * workspace route and screen render what the studio holds for it (ARCH-2).
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( Rest\ConnectionController::class, 'register_routes' ) );
		add_action( 'rest_api_init', array( Rest\WorkspaceController::class, 'register_routes' ) );
		add_action( 'admin_menu', array( Admin\Screen::class, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( Admin\Screen::class, 'enqueue' ) );
		add_action( 'admin_menu', array( Admin\BoardScreen::class, 'register' ) );
		add_action( 'admin_menu', array( Admin\TimelineScreen::class, 'register' ) );
		add_action( 'admin_menu', array( Admin\CalendarScreen::class, 'register' ) );
		add_action( 'admin_menu', array( Admin\ChecklistScreen::class, 'register' ) );
		add_action( 'admin_menu', array( Admin\AskScreen::class, 'register' ) );
		add_action( 'admin_menu', array( Admin\AskedScreen::class, 'register' ) );
		add_action( 'admin_menu', array( Admin\ConnectionScreen::class, 'register' ) );

		Admin\AskActions::boot();
		Admin\ChecklistActions::boot();
		Admin\ItemActions::boot();
		Admin\ConnectionActions::boot();

		// So the studio's connection record knows what this site is running and
		// whether it can email anybody (#89).
		Mail::boot();
		Report::boot();
	}

	/**
	 * Runs on activation.
	 */
	public function activate(): void {
		update_option( 'bwx_forge_client_installed_version', BWX_FORGE_CLIENT_VERSION );

		// A site that has just been updated or reactivated is exactly the one
		// whose recorded version is wrong, so it says so immediately rather
		// than waiting up to a day for the cron.
		Report::send();
	}

	/**
	 * Runs on deactivation.
	 */
	public function deactivate(): void {
		// The daily report is the one thing that would otherwise keep firing
		// into a plugin that is no longer here.
		Report::unschedule();
	}
}
