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

		// The studio's own client, made on the first request that finds it
		// missing. After Schema, because it writes to the tables Schema makes.
		Tenancy\Studio::ensure();

		// The studio's open ClickUp work, brought across once (#346). After
		// Studio, because it needs the studio site to put the work on.
		Work\ClickUpImport::maybe_run();

		Frontend::instance()->boot();

		add_action( 'rest_api_init', array( Rest\Server::class, 'register_routes' ) );

		// Sync health is the Forge menu (2026-09-19): the first entry and the
		// one the menu opens. It has to be registered before anything hangs
		// off it, which is why it is here and not with the other screens.
		add_action( 'admin_menu', array( Admin\SyncScreen::class, 'register' ) );
		add_action( 'admin_menu', array( Admin\SitesScreen::class, 'register' ) );

		// One enqueue for every studio screen, rather than one per screen that
		// remembered to ask. Admin\Page decides which screens are ours.
		add_action( 'admin_enqueue_scripts', array( Admin\Page::class, 'enqueue' ) );

		// The board lives on the front end, so the admin menu needs a door to it.
		Admin\BoardLink::boot();

		Admin\SiteActions::boot();

		/*
		 * Last in the menu, whatever order the screens are hooked up in here.
		 * Updates is the one entry nobody opens as part of doing the work — it
		 * is housekeeping — so a late priority pins it to the bottom rather
		 * than leaving its place to depend on where this line happens to sit.
		 */
		add_action( 'admin_menu', array( Admin\UpdatesScreen::class, 'register' ), 99 );

		Admin\UpdatesActions::boot();

		add_action( 'admin_menu', array( Admin\OnboardingTemplateScreen::class, 'register' ) );
		add_action( 'admin_menu', array( Admin\ConnectionsScreen::class, 'register' ) );
		Admin\ConnectionActions::boot();
		Admin\ProfileSlack::boot();

		/*
		 * #157. The one studio screen that spans clients on purpose: who needs
		 * selling to, before the next thing they ask for is refused.
		 */
		add_action( 'admin_menu', array( Admin\SalesScreen::class, 'register' ) );

		Admin\OnboardingTemplateActions::boot();

		Tenancy\IntegrationEvents::boot();

		// #292. Every person is a WordPress user, and the two are kept in step.
		Tenancy\Accounts::boot();

		// The one scheduled job: the morning Slack message (PR 5).
		Slack\Morning::boot();
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
		Slack\Morning::unschedule();
		flush_rewrite_rules();
	}
}
