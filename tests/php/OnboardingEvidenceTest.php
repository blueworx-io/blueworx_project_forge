<?php
/**
 * The record of a file attached to an onboarding step.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Onboarding\Evidence;
use PHPUnit\Framework\TestCase;

/**
 * #168. Evidence is scoped to one client, and its history is not rewritten.
 *
 * {@see OnboardingEvidenceStoreTest} covers what may be uploaded. This covers
 * what is then written down about it, and the two rules that make it safe after
 * the upload: every read names the site as well as the record, and nothing is
 * ever edited.
 */
final class OnboardingEvidenceTest extends TestCase {

	/**
	 * A complete, valid attachment.
	 *
	 * @return array<string, mixed>
	 */
	private function attachment(): array {
		return array(
			'step_id'        => 'obs_abc',
			'client_site_id' => 'cst_abc',
			'client_id'      => 'cli_abc',
			'original_name'  => 'invoice.pdf',
			'stored_name'    => 'a1b2c3d4e5f60718293a4b5c6d7e8f90.pdf',
			'mime_type'      => 'application/pdf',
			'size_bytes'     => 2048,
			'checksum'       => str_repeat( 'a', 64 ),
			'uploaded_by'    => 7,
			'source_interface' => 'client',
		);
	}

	public function test_a_complete_attachment_becomes_a_row(): void {
		$row = Evidence::row_from( $this->attachment() );

		$this->assertNotSame( array(), $row );
		$this->assertStringStartsWith( 'obv_', (string) $row['id'] );
		$this->assertSame( 'obs_abc', $row['step_id'] );
		$this->assertSame( 'cst_abc', $row['client_site_id'] );
		$this->assertSame( 'invoice.pdf', $row['original_name'] );
		$this->assertSame( 2048, $row['size_bytes'] );
	}

	public function test_an_attachment_nobody_is_recorded_as_having_made_is_refused(): void {
		/*
		 * The same rule as the step history and the gate records: an upload
		 * with nobody's name on it proves nothing later, which is exactly when
		 * anybody looks at it.
		 */
		$without = $this->attachment();
		unset( $without['uploaded_by'] );

		$this->assertSame( array(), Evidence::row_from( $without ) );
		$this->assertSame( array(), Evidence::row_from( array_merge( $this->attachment(), array( 'uploaded_by' => 0 ) ) ) );
	}

	public function test_a_client_site_may_be_the_one_that_attached_it(): void {
		// A client site holds a key, not an account, so it can never satisfy a
		// rule that insists on a person. Work\Comments met this first.
		$row = Evidence::row_from(
			array_merge( $this->attachment(), array( 'uploaded_by' => 0, 'uploaded_site' => 'st_clientsite' ) )
		);

		$this->assertSame( 'st_clientsite', $row['uploaded_site'] );
		$this->assertSame( 0, $row['uploaded_by'] );
	}

	public function test_an_attachment_cannot_be_both_a_person_and_a_site(): void {
		$this->assertSame(
			array(),
			Evidence::row_from( array_merge( $this->attachment(), array( 'uploaded_site' => 'st_clientsite' ) ) )
		);
	}

	public function test_an_attachment_belonging_to_no_site_is_refused(): void {
		/*
		 * A row with no site is a row no tenant check can filter, so it must
		 * never be written in the first place.
		 */
		$this->assertSame( array(), Evidence::row_from( array_merge( $this->attachment(), array( 'client_site_id' => '' ) ) ) );
		$this->assertSame( array(), Evidence::row_from( array_merge( $this->attachment(), array( 'step_id' => '' ) ) ) );
	}

	public function test_an_attachment_with_no_file_behind_it_is_refused(): void {
		$this->assertSame( array(), Evidence::row_from( array_merge( $this->attachment(), array( 'stored_name' => '' ) ) ) );
	}

	public function test_the_original_name_is_kept_but_never_used_on_disk(): void {
		/*
		 * People recognise their own file names, so the record keeps one. It is
		 * a label shown next to a download and nothing reads it as a path — the
		 * stored name is the only thing that touches the filesystem.
		 */
		$row = Evidence::row_from( array_merge( $this->attachment(), array( 'original_name' => '../../wp-config.php' ) ) );

		$this->assertSame( 'wp-config.php', $row['original_name'] );
		$this->assertStringNotContainsString( '..', (string) $row['original_name'] );
	}

	public function test_a_very_long_name_is_cut_rather_than_refused(): void {
		// It lands in a varchar. Refusing somebody's holiday-snap filename
		// would be a refusal they cannot understand or act on.
		$row = Evidence::row_from( array_merge( $this->attachment(), array( 'original_name' => str_repeat( 'n', 400 ) . '.pdf' ) ) );

		$this->assertNotSame( array(), $row );
		$this->assertLessThanOrEqual( 191, strlen( (string) $row['original_name'] ) );
	}

	/* ------------------------------------------------------------ retention */

	public function test_evidence_is_kept_while_the_relationship_is_live(): void {
		// NOTIF-5. Zero means "no end date yet", not "delete now".
		$this->assertSame( 0, Evidence::retention_until( 0 ) );
	}

	public function test_evidence_outlives_the_relationship_by_a_year(): void {
		$ended = 1767225600; // 2026-01-01.

		$this->assertSame( $ended + ( 365 * DAY_IN_SECONDS ), Evidence::retention_until( $ended ) );
	}

	public function test_reaching_the_retention_date_does_not_delete_anything(): void {
		/*
		 * NOTIF-5 is explicit that an automated purge running through records
		 * with audit history is a foot-gun. The date says when a documented
		 * manual deletion becomes allowed; nothing acts on it by itself.
		 */
		$this->assertTrue( Evidence::may_be_deleted( 1767225600, 1767225601 ) );
		$this->assertFalse( Evidence::may_be_deleted( 1767225600, 1767225599 ) );
		$this->assertFalse( Evidence::may_be_deleted( 0, PHP_INT_MAX ) );
	}
}
