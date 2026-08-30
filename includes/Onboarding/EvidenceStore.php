<?php
/**
 * What may be attached to an onboarding step, and where it lands.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Onboarding;

/**
 * #168. The rules an uploaded file has to pass, and the place it goes.
 *
 * **The allowlist is the promise; scanning is not.** A WordPress plugin cannot
 * honestly guarantee malware scanning on the hosting clients actually use —
 * there may be no scanner installed, no way to shell out to one, and no memory
 * to run one in. Claiming otherwise would put a sentence in a contract that the
 * software cannot keep.
 *
 * So this class promises the narrow set of things that hold everywhere:
 *
 * - a short list of file types, matched on both the name and the stated type;
 * - a size limit;
 * - a name on disk that the uploader did not choose and cannot guess;
 * - a directory the web server refuses to serve, on both server families.
 *
 * Refusing an executable is not catching malware. It is declining to hold the
 * file at all, which needs no scanner to be true.
 *
 * {@see self::scan_refusal()} is then offered on top for a host that does have
 * a scanner. It is an addition, deliberately not the defence, and its default
 * is to allow — because with nothing hooked we have not scanned anything, and
 * refusing at random would be a worse lie than saying so.
 */
final class EvidenceStore {

	/**
	 * The largest file that may be attached.
	 *
	 * Ten megabytes covers a scan of a signed document, which is the biggest
	 * thing anybody sends as onboarding evidence, and stops the folder becoming
	 * somewhere a client's video ends up.
	 */
	public const MAX_BYTES = 10485760;

	/**
	 * The folder inside WordPress's own uploads directory.
	 */
	public const FOLDER = 'bwx-forge-evidence';

	/**
	 * Accepted extension, and the types that extension may claim to be.
	 *
	 * Deliberately short. Everything on it is a document or a picture that is
	 * inert when opened.
	 *
	 * Two things are missing on purpose and should stay missing. **SVG** is a
	 * script that happens to draw a picture, so it is not an image for this
	 * purpose. **Archives** hide their contents, and a container we do not open
	 * is the one case where this list would be promising something it cannot
	 * keep.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const ALLOWED = array(
		'pdf'  => array( 'application/pdf' ),
		'png'  => array( 'image/png' ),
		'jpg'  => array( 'image/jpeg', 'image/jpg' ),
		'jpeg' => array( 'image/jpeg', 'image/jpg' ),
		'gif'  => array( 'image/gif' ),
		'webp' => array( 'image/webp' ),
		'txt'  => array( 'text/plain' ),
		'csv'  => array( 'text/csv', 'text/plain', 'application/csv' ),
	);

	/**
	 * What a person is told they can send.
	 *
	 * Written out rather than generated from the list above, because a refusal
	 * assembled from file extensions reads like a error message and this one
	 * has to read like a sentence.
	 */
	private const ACCEPTED_IN_WORDS = 'You can attach a PDF, an image (PNG, JPG, GIF or WebP), or a text or CSV file.';

	/**
	 * Why this file may not be attached.
	 *
	 * @param string $filename What the uploader called it.
	 * @param string $mime     The type the upload claims to be.
	 * @param int    $bytes    Its size.
	 * @return string Empty when it may be attached; otherwise a sentence for
	 *                the person who tried.
	 */
	public static function refusal( string $filename, string $mime, int $bytes ): string {
		if ( $bytes <= 0 ) {
			return 'That file is empty.';
		}

		if ( $bytes > self::MAX_BYTES ) {
			return sprintf(
				'That file is larger than %dMB. Send a smaller one, or a link to it.',
				(int) ( self::MAX_BYTES / 1048576 )
			);
		}

		$extension = self::extension( $filename );

		if ( '' === $extension || ! isset( self::ALLOWED[ $extension ] ) ) {
			return 'That kind of file is not accepted. ' . self::ACCEPTED_IN_WORDS;
		}

		if ( ! in_array( strtolower( trim( $mime ) ), self::ALLOWED[ $extension ], true ) ) {
			/*
			 * Both halves have to agree. Either one alone is trivially forged,
			 * and a file that lies consistently still lands under the harmless
			 * extension it claimed, because that is where the stored name comes
			 * from.
			 */
			return 'That file is not the kind of file it says it is. ' . self::ACCEPTED_IN_WORDS;
		}

		return '';
	}

	/**
	 * The name the file is given on disk.
	 *
	 * The uploader never chooses it. That removes traversal, collision and
	 * every trick that depends on a server looking at part of a name — a
	 * `shell.php.png` arrives as thirty-two hex characters and `.png`, so there
	 * is no `.php` left for anything to act on.
	 *
	 * @param string $filename What the uploader called it.
	 * @return string
	 */
	public static function stored_name( string $filename ): string {
		$extension = self::extension( $filename );

		return bin2hex( random_bytes( 16 ) ) . ( '' === $extension ? '' : '.' . $extension );
	}

	/**
	 * Where one site's evidence lives, relative to the uploads directory.
	 *
	 * A folder per site, so that a mistake in a query is a mistake within one
	 * client rather than across all of them.
	 *
	 * @param string $client_site_id The site.
	 * @return string
	 */
	public static function relative_dir( string $client_site_id ): string {
		/*
		 * The id comes off a record rather than off a request, so this is the
		 * second lock on a door that should already be shut. It is here anyway
		 * because being wrong means writing into another client's folder.
		 */
		$safe = preg_replace( '/[^A-Za-z0-9_\-]/', '', $client_site_id );

		return self::FOLDER . '/' . (string) $safe;
	}

	/**
	 * Where one site's evidence lives on disk.
	 *
	 * Inside WordPress's own uploads directory rather than beside the plugin,
	 * because that is the one place a WordPress install guarantees is writable
	 * and is backed up with the site. It is shut to the web server by
	 * {@see self::prepare()} — being under `uploads` is where it lives, not how
	 * it is protected.
	 *
	 * @param string $client_site_id The site.
	 * @return string
	 */
	public static function absolute_dir( string $client_site_id ): string {
		$uploads = wp_upload_dir();
		$base    = (string) ( $uploads['basedir'] ?? '' );

		return rtrim( $base, '/\\' ) . '/' . self::relative_dir( $client_site_id );
	}

	/**
	 * Whether a host's own scanner refuses this file.
	 *
	 * The extension point, not the defence — see the class comment. A host with
	 * ClamAV or an equivalent hooks this and returns a sentence; anything
	 * non-empty refuses the upload and is shown to the person who tried.
	 *
	 * @param string               $path    Where the file is, before it is kept.
	 * @param array<string, mixed> $context client_site_id, step_id, filename.
	 * @return string Empty when nothing objected.
	 */
	public static function scan_refusal( string $path, array $context ): string {
		return trim( (string) apply_filters( 'bwx_forge_scan_evidence', '', $path, $context ) );
	}

	/**
	 * The files that shut the evidence folder to the web server.
	 *
	 * Both server families get one, because a studio does not choose what its
	 * client's host runs, and a folder that is only closed on Apache is open.
	 * The `index.php` is the belt to those braces: a server configured to
	 * ignore both still lists nothing.
	 *
	 * @return array<string, string> File name to contents.
	 */
	public static function protection_files(): array {
		return array(
			'.htaccess'  => "# Blueworx Forge (#168). Evidence is read through the REST route, which\n"
				. "# checks who is asking. Nothing here is served directly.\nDeny from all\n"
				. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n"
				. "\t\t<authorization>\n\t\t\t<deny users=\"*\" />\n\t\t</authorization>\n"
				. "\t</system.webServer>\n</configuration>\n",
		);
	}

	/**
	 * Makes the folder if it is not there, and shuts it either way.
	 *
	 * Written every time rather than once, because the cheap thing to get wrong
	 * is a folder restored from a backup, or copied between environments,
	 * without the file that closes it.
	 *
	 * @param string $absolute_dir The folder.
	 * @return bool Whether it is there and shut.
	 */
	public static function prepare( string $absolute_dir ): bool {
		if ( ! is_dir( $absolute_dir ) && ! wp_mkdir_p( $absolute_dir ) ) {
			return false;
		}

		foreach ( self::protection_files() as $name => $contents ) {
			$file = $absolute_dir . '/' . $name;

			if ( ! file_exists( $file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Own directory, created above; WP_Filesystem is not initialised on a REST request.
				file_put_contents( $file, $contents );
			}
		}

		return true;
	}

	/**
	 * The last extension, lower-cased, and nothing else from the name.
	 *
	 * @param string $filename What the uploader called it.
	 * @return string
	 */
	private static function extension( string $filename ): string {
		$base = basename( str_replace( '\\', '/', $filename ) );
		$dot  = strrpos( $base, '.' );

		if ( false === $dot || strlen( $base ) - 1 === $dot ) {
			return '';
		}

		$extension = strtolower( substr( $base, $dot + 1 ) );

		return 1 === preg_match( '/^[a-z0-9]{1,8}$/', $extension ) ? $extension : '';
	}
}
