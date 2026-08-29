<?php
/**
 * Plugin lifecycle.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge;

/**
 * Wires the plugin's parts to WordPress, and owns activation and deactivation.
 *
 * Everything the plugin does is hooked from boot(), so the main plugin file
 * stays a header, some constants, and one call.
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
	 */
	public function boot(): void {
		Data\Schema::maybe_upgrade();

		Frontend::instance()->boot();

		add_action( 'rest_api_init', array( Rest\Server::class, 'register_routes' ) );

		add_action( 'admin_menu', array( Admin\SitesScreen::class, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( Admin\SitesScreen::class, 'enqueue' ) );

		Admin\SiteActions::boot();

		add_action( 'admin_menu', array( Admin\ClientsScreen::class, 'register' ) );

		Admin\ClientActions::boot();

		add_action( 'admin_menu', array( Admin\PeopleScreen::class, 'register' ) );

		Admin\PeopleActions::boot();

		add_action( 'admin_menu', array( Admin\UpdatesScreen::class, 'register' ) );

		Admin\UpdatesActions::boot();

		add_action( 'admin_menu', array( Admin\AvailabilityScreen::class, 'register' ) );

		Admin\AvailabilityActions::boot();

		Tenancy\IntegrationEvents::boot();
	}

	/**
	 * Runs on activation.
	 */
	public function activate(): void {
		Data\Schema::maybe_upgrade();

		/*
		 * A fresh install gets a working launch checklist without anybody
		 * having to build one (ONB-E1). It does nothing once a version exists,
		 * so this is safe on every activation rather than only the first — a
		 * plugin reactivated a year in never disturbs a checklist somebody is
		 * halfway through.
		 */
		Onboarding\Version1::seed( get_current_user_id() );

		Frontend::instance()->create_app_page();
		flush_rewrite_rules();
	}

	/**
	 * Runs on deactivation. Leaves the app page in place: it is a published page
	 * the site owns, and deactivating a plugin is not a request to delete content.
	 */
	public function deactivate(): void {
		flush_rewrite_rules();
	}
}
