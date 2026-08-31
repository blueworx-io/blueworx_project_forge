<?php
/**
 * Forge stores no mail credentials.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Data\Schema;
use PHPUnit\Framework\TestCase;

/**
 * #173, NOTIF-3. "Forge stores no SMTP credentials anywhere."
 *
 * That is the acceptance criterion, and it is a claim about the *absence* of
 * something — which no ordinary test can make, because a test exercises code
 * that exists. So this one reads the source instead.
 *
 * It is worth the unusual shape. The decision behind it is that the client's
 * own WordPress sends the mail, using mail settings we never see, so that a
 * credential we never had cannot leak from us. That guarantee does not survive
 * somebody adding an "SMTP host" field to the connection screen one afternoon
 * because a client's host was awkward — and nothing else in the build would
 * notice. This notices.
 *
 * The list of names is deliberately broad and the exclusions are narrow. A
 * false positive here costs somebody thirty seconds and a rename; a false
 * negative costs a plugin that quietly became a credential store.
 */
final class NotificationCredentialsTest extends TestCase {

	/**
	 * The words that would mean Forge had started holding a way to send mail.
	 *
	 * `mailer` and `sender` are absent on purpose: both appear in perfectly
	 * innocent code about who an email is from, and neither is a credential.
	 * What is here is the set of things you would need to *connect* to a mail
	 * server, which is the thing this product must never know.
	 *
	 * @var array<int, string>
	 */
	private const FORBIDDEN = array(
		'smtp_host',
		'smtp_port',
		'smtp_user',
		'smtp_username',
		'smtp_pass',
		'smtp_password',
		'smtp_secure',
		'smtp_auth',
		'mail_password',
		'mail_username',
		'phpmailer',
	);

	/**
	 * Every PHP file in the studio plugin.
	 *
	 * @return array<int, string>
	 */
	private function studioFiles(): array {
		$root  = dirname( __DIR__, 2 );
		$found = array();

		$walk = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root . '/includes', FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $walk as $file ) {
			if ( 'php' === strtolower( $file->getExtension() ) ) {
				$found[] = $file->getPathname();
			}
		}

		$found[] = $root . '/blueworx-forge.php';

		return $found;
	}

	public function test_the_studio_holds_no_way_to_connect_to_a_mail_server(): void {
		$offending = array();

		foreach ( $this->studioFiles() as $file ) {
			$source = strtolower( (string) file_get_contents( $file ) );

			foreach ( self::FORBIDDEN as $word ) {
				if ( str_contains( $source, $word ) ) {
					$offending[] = basename( $file ) . ' mentions ' . $word;
				}
			}
		}

		$this->assertSame(
			array(),
			$offending,
			"Forge must hold no mail credentials (NOTIF-3). The client's own site sends, with its own settings."
		);
	}

	public function test_no_table_has_anywhere_to_put_one(): void {
		/*
		 * The stronger half. A rule in a controller can be forgotten by the
		 * next caller; a column that does not exist cannot be written to. This
		 * is the same argument Onboarding\Steps makes about credentials on a
		 * checklist, applied to mail.
		 */
		$offending = array();

		foreach ( Schema::definitions() as $table => $sql ) {
			foreach ( self::FORBIDDEN as $word ) {
				if ( str_contains( strtolower( $sql ), $word ) ) {
					$offending[] = $table . ' has a column mentioning ' . $word;
				}
			}
		}

		$this->assertSame( array(), $offending );
	}

	public function test_the_studio_never_calls_a_mail_function_itself(): void {
		/*
		 * Not even to send to ourselves. The moment this plugin sends one
		 * email, somebody will point the next one at a client — and the whole
		 * arrangement of "their site, their settings, their domain" is gone
		 * without anybody deciding to give it up.
		 */
		$offending = array();

		foreach ( $this->studioFiles() as $file ) {
			$source = (string) file_get_contents( $file );

			if ( 1 === preg_match( '/(?<![a-z_])(wp_mail|mail)\s*\(/i', $source ) ) {
				$offending[] = basename( $file );
			}
		}

		$this->assertSame(
			array(),
			$offending,
			'The studio must not send email. The client site does, through its own wp_mail (#173).'
		);
	}
}
