<?php
namespace Blueworx\PageEditor\v1;

/**
 * The whole public surface of the library. A plugin calls register() with its
 * screen definition and does nothing else.
 */
final class Editor {

	/** @var array<string,array> slug => screen definition */
	private static $screens = [];

	/** @var array<string,string> slug => why the screen will not run */
	private static $problems = [];

	/** @var array<string,string> slug => why the screen definition would not register */
	private static $broken = [];

	/**
	 * Registration runs on plugins_loaded, where nothing catches anything: an
	 * exception here would white-screen wp-admin and the front end together,
	 * over a typo in one field's kind. So a screen definition this library
	 * refuses is recorded as unavailable instead, with the reason, and the
	 * screen says so when somebody opens it — exactly the way a record editor
	 * whose post type nobody registered already does. Registration must never
	 * be able to take a site down.
	 *
	 * Throwable, not just InvalidArgumentException: a tab, panel or field that
	 * is not an array at all raises a TypeError before any of Schema's own
	 * checks get to run.
	 */
	public static function register( array $screen ): void {
		try {
			$valid = Schema::validate( $screen );
		} catch ( \Throwable $e ) {
			self::unavailable( $screen, $e->getMessage() );
			return;
		}
		self::$screens[ $valid['slug'] ] = $valid;
		unset( self::$broken[ $valid['slug'] ], self::$problems[ $valid['slug'] ] );
	}

	/**
	 * Keeps just enough of a refused screen for its menu item to render and
	 * carry the message. The title and capability are taken as given if they
	 * are usable, because whatever else was wrong those two are what decide
	 * where the item appears and who sees it; the store is forced to 'option'
	 * so ready() never asks about a post type this screen may never have
	 * named.
	 *
	 * A screen with no usable slug is the one case nothing can be done about:
	 * there is no page to attach the message to and no menu item to reach it
	 * by, so nothing is registered at all — only a _doing_it_wrong() trace, for
	 * a developer running with WP_DEBUG on.
	 */
	private static function unavailable( array $screen, string $why ): void {
		$slug = ( isset( $screen['slug'] ) && is_string( $screen['slug'] ) ) ? $screen['slug'] : '';
		if ( '' === $slug ) {
			_doing_it_wrong( __METHOD__, $why, '1.0.0' );
			return;
		}

		$title      = ( isset( $screen['title'] ) && is_string( $screen['title'] ) && '' !== $screen['title'] ) ? $screen['title'] : $slug;
		$capability = ( isset( $screen['capability'] ) && is_string( $screen['capability'] ) && '' !== $screen['capability'] ) ? $screen['capability'] : 'manage_options';

		self::$screens[ $slug ] = [
			'slug'       => $slug,
			'title'      => $title,
			'capability' => $capability,
			'store'      => 'option',
			'eyebrow'    => '',
			'lede'       => '',
			'tabs'       => [],
		];
		self::$broken[ $slug ] = sprintf(
			'This editor is not ready. Its screen was set up wrongly: %s Ask whoever installed the plugin to fix it.',
			rtrim( $why, ' ' )
		);
	}

	/** @return array<string,array> */
	public static function all(): array {
		return self::$screens;
	}

	public static function get( string $slug ): ?array {
		return self::$screens[ $slug ] ?? null;
	}

	/**
	 * Whether this screen can actually run. A record editor whose post type
	 * nobody registered does not load: post meta on a post type that does not
	 * exist saves nothing, silently, and the site owner would have no way to
	 * tell. Better to refuse and say so.
	 */
	public static function ready( string $slug ): bool {
		$screen = self::get( $slug );
		if ( null === $screen ) {
			return false;
		}
		// A screen this library refused to register at all: it exists only so
		// its own menu item can say why — see unavailable() above.
		if ( isset( self::$broken[ $slug ] ) ) {
			return false;
		}
		if ( 'post' === $screen['store'] && ! post_type_exists( $screen['post_type'] ) ) {
			self::$problems[ $slug ] = sprintf(
				'This editor is not ready. It saves a "%s" record, and that record type has not been set up on this site yet. Ask whoever installed the plugin to finish setting it up.',
				$screen['post_type']
			);
			return false;
		}
		unset( self::$problems[ $slug ] );
		return true;
	}

	/**
	 * A screen refused at registration knows why from the moment it is
	 * refused, so that reason is available without ready() having been asked
	 * first, and it outranks anything ready() later works out.
	 */
	public static function problem( string $slug ): string {
		return self::$broken[ $slug ] ?? self::$problems[ $slug ] ?? '';
	}

	/**
	 * For a record ("post") screen, confirms the id is a real post of this
	 * screen's own post type, and that the current user may edit that
	 * specific post — not merely that they hold the screen's own capability.
	 * Without this, any user who can open one editor screen could post an
	 * arbitrary id and overwrite an unrelated post's columns and meta: the
	 * capability check above only ever looks at the screen, and an int cast
	 * on its own proves nothing about which post that id belongs to.
	 *
	 * An option screen has no record id at all, so this never applies to it.
	 *
	 * The same refusal covers "no such id" and "a post of a different type":
	 * telling those apart would let a caller use the endpoint to find out
	 * what exists on the site.
	 */
	private static function authoriseRecord( array $screen, int $id ): ?string {
		if ( 'post' !== $screen['store'] ) {
			return null;
		}
		if ( $id <= 0 ) {
			return 'That record could not be found.';
		}
		$post = get_post( $id );
		if ( null === $post || ! isset( $post->post_type ) || $screen['post_type'] !== $post->post_type ) {
			return 'That record could not be found.';
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return 'You do not have permission to edit this record.';
		}
		return null;
	}

	/**
	 * The registered screen with the Publish and settings tab (Settings::tab())
	 * appended, when one applies. This is the one place that merge happens;
	 * load() and save() both work from this, and everything downstream of them
	 * — Capabilities, Sanitise, Validate, Store — sees only the merged screen,
	 * never the plugin's bare one. Reading or saving from the bare screen would
	 * mean the settings tab renders but its values are never read back and are
	 * dropped on the way in, which is exactly the bug this exists to prevent.
	 */
	private static function screenFor( string $slug ): ?array {
		$screen = self::get( $slug );
		if ( null === $screen ) {
			return null;
		}
		$extra = Settings::tab( $screen );
		if ( null !== $extra ) {
			$screen['tabs'][] = $extra;
		}
		return $screen;
	}

	/**
	 * Everything the screen needs to draw itself: the schema this user is
	 * allowed to see, and the values behind it.
	 */
	public static function load( string $slug, int $id = 0 ): array {
		$screen = self::get( $slug );
		if ( null === $screen ) {
			return [ 'schema' => null, 'values' => [], 'problem' => self::problem( $slug ) ];
		}
		// The REST route's permission_callback is the first gate, but load()
		// is public API: a WP-CLI command, another plugin, or a future
		// admin-post handler could call it directly, bypassing that gate
		// entirely. save() already checked this; load() must too, or reading
		// a screen never requires the capability that opening it does.
		if ( ! current_user_can( $screen['capability'] ) ) {
			return [ 'schema' => null, 'values' => [], 'problem' => 'You do not have permission to open this editor.' ];
		}
		if ( ! self::ready( $slug ) ) {
			return [ 'schema' => null, 'values' => [], 'problem' => self::problem( $slug ) ];
		}
		$merged  = self::screenFor( $slug );
		$refusal = self::authoriseRecord( $merged, $id );
		if ( null !== $refusal ) {
			return [ 'schema' => null, 'values' => [], 'problem' => $refusal ];
		}
		$visible = Capabilities::filterSchema( $merged );
		$values  = Store::for( $merged )->read( $id );

		return [
			'schema' => $visible,
			'values' => Capabilities::filterValuesForDisplay( $merged, $values ),
		];
	}

	/**
	 * A validation failure writes nothing at all: values are checked before
	 * anything is written, so if any is invalid nothing reaches the store —
	 * that guarantee still holds. A write failure is a different matter: post
	 * meta and the post itself have no transaction, so it can leave part of
	 * the record already committed, and the message below says so rather
	 * than claiming otherwise.
	 */
	public static function save( string $slug, array $values, int $id = 0 ): array {
		$screen = self::get( $slug );
		if ( null === $screen ) {
			return [ 'ok' => false, 'errors' => [ '_screen' => 'That editor screen does not exist.' ] ];
		}
		if ( ! current_user_can( $screen['capability'] ) ) {
			return [ 'ok' => false, 'errors' => [ '_screen' => 'You do not have permission to change this.' ] ];
		}
		if ( ! self::ready( $slug ) ) {
			return [ 'ok' => false, 'errors' => [ '_screen' => self::problem( $slug ) ] ];
		}

		$merged  = self::screenFor( $slug );
		$refusal = self::authoriseRecord( $merged, $id );
		if ( null !== $refusal ) {
			return [ 'ok' => false, 'errors' => [ '_screen' => $refusal ] ];
		}

		// Sanitised and validated against the fields this user may write, not
		// against the screen as declared — see Capabilities::writableSchema().
		$writable = Capabilities::writableSchema( $merged );
		$given    = Capabilities::filterValues( $merged, $values );
		$clean    = Sanitise::values( $writable, $given );
		$store    = Store::for( $merged );

		// Whether a conditional field is on the screen at all is a different
		// question from whether this user may write it: a depends_on may name a
		// field they are only allowed to look at, and resolving it against the
		// writable schema alone would leave it unresolvable — see Validate::run().
		//
		// So conditions are read off the whole screen, from the record as
		// stored, overlaid with the values this user is allowed to change. A
		// locked field's condition therefore comes from the record and never
		// from the request: a request reporting one falsely — or leaving it
		// out — would otherwise skip a required dependent field's check, and
		// the browser would be trusted for exactly the field this library
		// goes to some length to stop it writing. Only $clean is ever
		// validated or written.
		//
		// array_replace(), not array_merge(): a field id is only required to
		// be non-empty, and array_merge() renumbers an integer-like key, so an
		// all-numeric id would lose the overlay and read as null.
		$errors = Validate::run( $writable, $clean, $merged, array_replace( $store->read( $id ), $clean ) );

		if ( $errors ) {
			return [ 'ok' => false, 'errors' => $errors ];
		}

		if ( ! $store->write( $clean, $id ) ) {
			return [
				'ok'     => false,
				'errors' => [ '_screen' => 'Some of your changes may not have saved. Reload the screen to see what changed, then try again.' ],
			];
		}

		// The browser replaces its whole record with this, so it has to be the
		// same shape load() sends: the record as stored, read back and filtered
		// for display. Handing back $clean instead would drop every read-only
		// field — capability filtering already stripped them — and the screen
		// would empty them and still read clean.
		return [
			'ok'     => true,
			'values' => Capabilities::filterValuesForDisplay( $merged, $store->read( $id ) ),
		];
	}

	public static function reset(): void {
		self::$screens  = [];
		self::$problems = [];
		self::$broken   = [];
	}

	public static function boot(): void {
		Screen::boot();
		Rest::boot();
	}
}
