<?php
namespace Blueworx\PageEditor;

/**
 * Which copy of the library actually runs. Every copy on the site announces
 * itself; the highest version is the one loaded, once.
 */
final class Registry {

	/** @var array<string,string> version => directory */
	private static $copies = [];

	/**
	 * @var array<string,string> version => the loader file that registered it
	 * (the plugin's own copy of blueworx-page-editor.php). Recorded so the
	 * winning copy can work out where its own plugin's assets live, rather
	 * than guessing at a fixed directory depth that only holds for this
	 * repo's own layout.
	 */
	private static $loaders = [];

	/** @var bool */
	private static $loaded = false;

	public static function add( string $version, string $dir, string $loader = '' ): void {
		self::$copies[ $version ] = $dir;
		if ( '' !== $loader ) {
			self::$loaders[ $version ] = $loader;
		}
	}

	public static function latest(): string {
		$versions = array_keys( self::$copies );
		usort( $versions, 'version_compare' );
		return (string) end( $versions );
	}

	/**
	 * The loader file for the copy of the library that is actually running.
	 * That loader lives at `<plugin>/blueworx-page-editor/blueworx-page-editor.php`,
	 * so its own directory is the vendored library folder, and that folder's
	 * parent is the plugin root — the only thing Screen::url() needs to find
	 * the plugin's assets.
	 */
	public static function loaderFile(): string {
		return self::$loaders[ self::latest() ] ?? '';
	}

	public static function load(): void {
		if ( self::$loaded ) {
			return;
		}
		self::$loaded = true;
		$dir = self::$copies[ self::latest() ];
		foreach ( [ 'Schema', 'Capabilities', 'Sanitise', 'Validate', 'Store', 'Settings', 'Rest', 'Screen', 'Icons', 'Editor' ] as $class ) {
			require_once $dir . '/' . $class . '.php';
		}
		\Blueworx\PageEditor\v1\Editor::boot();
	}
}
