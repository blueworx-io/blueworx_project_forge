<?php
/**
 * What may be attached to an onboarding step, and where it lands.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Onboarding\EvidenceStore;
use PHPUnit\Framework\TestCase;

/**
 * #168. Uploaded evidence is safe, scoped and retained correctly.
 *
 * The decision taken with this issue is worth restating, because the tests only
 * make sense against it: **the allowlist is the guarantee, and scanning is
 * not.** A WordPress plugin cannot promise real malware scanning on the hosting
 * clients actually use — there may be no scanner, no shell and no memory to run
 * one in. So what is promised here is the narrow thing that can be kept: a
 * short list of file types that are accepted, a size limit, a stored name the
 * uploader does not choose, and a directory the web server will not serve.
 * A host that does have a scanner can refuse a file through a hook, and that is
 * offered as an addition rather than as the defence.
 *
 * Refusing an executable is therefore not "catching malware". It is not
 * accepting the file at all, which is a promise that holds without a scanner.
 */
final class OnboardingEvidenceStoreTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['bwx_forge_test_filters'] = array();
	}

	/* ------------------------------------------------------------- accepted */

	public function test_the_file_types_a_client_actually_sends_are_accepted(): void {
		$this->assertSame( '', EvidenceStore::refusal( 'invoice.pdf', 'application/pdf', 2048 ) );
		$this->assertSame( '', EvidenceStore::refusal( 'dns-records.png', 'image/png', 2048 ) );
		$this->assertSame( '', EvidenceStore::refusal( 'screenshot.JPG', 'image/jpeg', 2048 ) );
		$this->assertSame( '', EvidenceStore::refusal( 'accounts.csv', 'text/csv', 2048 ) );
		$this->assertSame( '', EvidenceStore::refusal( 'notes.txt', 'text/plain', 2048 ) );
	}

	/* -------------------------------------------------------------- refused */

	public function test_an_executable_is_refused(): void {
		$this->assertNotSame( '', EvidenceStore::refusal( 'shell.php', 'application/x-php', 2048 ) );
		$this->assertNotSame( '', EvidenceStore::refusal( 'run.exe', 'application/octet-stream', 2048 ) );
		$this->assertNotSame( '', EvidenceStore::refusal( 'go.sh', 'text/x-shellscript', 2048 ) );
	}

	public function test_an_svg_is_refused(): void {
		/*
		 * An SVG is a script that happens to draw a picture. It is refused
		 * despite being an image, and this test exists so that nobody adds it
		 * to the list later on the grounds that images are allowed.
		 */
		$this->assertNotSame( '', EvidenceStore::refusal( 'logo.svg', 'image/svg+xml', 2048 ) );
	}

	public function test_an_archive_is_refused(): void {
		/*
		 * We do not open archives, so we cannot say what is in one. Accepting
		 * a container whose contents are unknown is the one case where the
		 * allowlist would be promising something it cannot keep.
		 */
		$this->assertNotSame( '', EvidenceStore::refusal( 'evidence.zip', 'application/zip', 2048 ) );
	}

	public function test_a_file_with_no_extension_is_refused(): void {
		$this->assertNotSame( '', EvidenceStore::refusal( 'evidence', 'application/pdf', 2048 ) );
	}

	public function test_the_stated_type_has_to_match_the_name(): void {
		/*
		 * Either half alone is trivially forged. Requiring both to agree means
		 * a file has to lie consistently, and the stored name comes off the
		 * extension, so a lie that succeeds still lands as the harmless thing
		 * it claimed to be.
		 */
		$this->assertNotSame( '', EvidenceStore::refusal( 'invoice.pdf', 'application/x-php', 2048 ) );
		$this->assertNotSame( '', EvidenceStore::refusal( 'photo.png', 'text/html', 2048 ) );
	}

	public function test_an_oversized_file_is_refused(): void {
		$this->assertNotSame( '', EvidenceStore::refusal( 'huge.pdf', 'application/pdf', EvidenceStore::MAX_BYTES + 1 ) );
		$this->assertSame( '', EvidenceStore::refusal( 'big.pdf', 'application/pdf', EvidenceStore::MAX_BYTES ) );
	}

	public function test_an_empty_file_is_refused(): void {
		$this->assertNotSame( '', EvidenceStore::refusal( 'empty.pdf', 'application/pdf', 0 ) );
	}

	public function test_a_refusal_says_what_to_do_about_it(): void {
		// A refusal nobody can act on gets sent to us as an email instead.
		$refusal = EvidenceStore::refusal( 'shell.php', 'application/x-php', 2048 );

		$this->assertStringContainsString( 'PDF', $refusal );
	}

	/* --------------------------------------------------------- stored names */

	public function test_the_uploader_does_not_choose_the_name_on_disk(): void {
		$first  = EvidenceStore::stored_name( 'invoice.pdf' );
		$second = EvidenceStore::stored_name( 'invoice.pdf' );

		$this->assertNotSame( $first, $second );
		$this->assertNotSame( 'invoice.pdf', $first );
		$this->assertStringEndsWith( '.pdf', $first );
		$this->assertSame( 1, preg_match( '/^[a-f0-9]{32}\.pdf$/', $first ) );
	}

	public function test_only_the_last_extension_survives(): void {
		/*
		 * `shell.php.png` is the oldest trick there is. It never reaches disk
		 * under a name it chose, so a server that would have run the `.php`
		 * never sees one.
		 */
		$this->assertSame( 1, preg_match( '/^[a-f0-9]{32}\.png$/', EvidenceStore::stored_name( 'shell.php.png' ) ) );
	}

	public function test_a_traversing_name_cannot_escape(): void {
		$stored = EvidenceStore::stored_name( '../../../wp-config.php.pdf' );

		$this->assertSame( 1, preg_match( '/^[a-f0-9]{32}\.pdf$/', $stored ) );
		$this->assertStringNotContainsString( '..', $stored );
		$this->assertStringNotContainsString( '/', $stored );
		$this->assertStringNotContainsString( '\\', $stored );
	}

	/* ---------------------------------------------------------- where it is */

	public function test_each_site_has_its_own_folder(): void {
		$this->assertSame( 'bwx-forge-evidence/cst_abc', EvidenceStore::relative_dir( 'cst_abc' ) );
		$this->assertNotSame(
			EvidenceStore::relative_dir( 'cst_abc' ),
			EvidenceStore::relative_dir( 'cst_def' )
		);
	}

	public function test_a_site_id_cannot_reach_out_of_the_evidence_folder(): void {
		/*
		 * The id comes off a record rather than a request, so this is a second
		 * lock on a door that should already be shut. It is here because the
		 * cost of being wrong is writing into somebody else's directory.
		 */
		$this->assertSame( 'bwx-forge-evidence/wp-config', EvidenceStore::relative_dir( '../../wp-config' ) );
	}

	/* ------------------------------------------------------------- scanning */

	public function test_a_host_with_a_scanner_can_refuse_a_file(): void {
		$GLOBALS['bwx_forge_test_filters']['bwx_forge_scan_evidence'] = static function () {
			return 'Our scanner flagged this file.';
		};

		$this->assertSame(
			'Our scanner flagged this file.',
			EvidenceStore::scan_refusal( '/tmp/whatever.pdf', array( 'client_site_id' => 'cst_abc' ) )
		);
	}

	public function test_with_no_scanner_nothing_is_refused_on_those_grounds(): void {
		/*
		 * The default is to allow, and that is the honest default: with no
		 * scanner present we have not scanned anything, and pretending
		 * otherwise by refusing at random would be worse than saying so.
		 */
		$this->assertSame( '', EvidenceStore::scan_refusal( '/tmp/whatever.pdf', array( 'client_site_id' => 'cst_abc' ) ) );
	}

	/* ----------------------------------------------------------- protection */

	public function test_the_folder_is_shut_to_the_web_server(): void {
		/*
		 * The whole point of a stored name nobody can guess is lost if the
		 * directory lists itself, and the point of the allowlist is thinner if
		 * anything in there can be requested directly. Both server families get
		 * a file, because a studio does not choose its client's host.
		 */
		$files = EvidenceStore::protection_files();

		$this->assertArrayHasKey( '.htaccess', $files );
		$this->assertArrayHasKey( 'index.php', $files );
		$this->assertArrayHasKey( 'web.config', $files );

		$this->assertStringContainsString( 'deny from all', strtolower( $files['.htaccess'] ) );
		$this->assertStringContainsString( '<?php', $files['index.php'] );
	}
}
