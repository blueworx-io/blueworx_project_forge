<?php
/**
 * What may not be typed into an onboarding step.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Onboarding\Secrets;
use PHPUnit\Framework\TestCase;

/**
 * #167, ONB-3. Forge never becomes a place credentials are stored.
 *
 * The schema already refuses one half of this by having nowhere to put a
 * credential. This is the other half the decision asks for: the content of the
 * fields that do exist is checked, so that a password typed into the notes box
 * is refused rather than quietly kept.
 *
 * The tests below are in two halves on purpose. The first half is what must be
 * caught. The second half is what must *not* be, and it is the more important
 * of the two: a check that refuses ordinary answers gets switched off, and then
 * it catches nothing at all.
 */
final class OnboardingSecretsTest extends TestCase {

	/* ---------------------------------------------------------------- caught */

	public function test_a_private_key_block_is_refused(): void {
		$this->assertTrue(
			Secrets::looks_like_secret( "-----BEGIN RSA PRIVATE KEY-----\nMIIEpAIBAAKC\n-----END RSA PRIVATE KEY-----" )
		);
	}

	public function test_an_openssh_private_key_is_refused(): void {
		$this->assertTrue( Secrets::looks_like_secret( '-----BEGIN OPENSSH PRIVATE KEY-----' ) );
	}

	public function test_a_labelled_password_is_refused(): void {
		$this->assertTrue( Secrets::looks_like_secret( 'password: hunter2' ) );
		$this->assertTrue( Secrets::looks_like_secret( 'Password = hunter2' ) );
		$this->assertTrue( Secrets::looks_like_secret( 'pwd:hunter2' ) );
	}

	public function test_a_labelled_key_or_token_is_refused(): void {
		$this->assertTrue( Secrets::looks_like_secret( 'API key: abcd1234' ) );
		$this->assertTrue( Secrets::looks_like_secret( 'apikey=abcd1234' ) );
		$this->assertTrue( Secrets::looks_like_secret( 'access token: abcd1234' ) );
		$this->assertTrue( Secrets::looks_like_secret( 'secret = abcd1234' ) );
	}

	public function test_a_label_with_nothing_after_it_is_allowed(): void {
		/*
		 * "The password is with the client" is somebody explaining where a
		 * credential lives, which is exactly the sort of note this field is
		 * for. Only a label followed by something that looks like the value
		 * itself is refused.
		 */
		$this->assertFalse( Secrets::looks_like_secret( 'password:' ) );
		$this->assertFalse( Secrets::looks_like_secret( 'The password is held by the client, not by us.' ) );
	}

	public function test_a_recognised_provider_key_is_refused(): void {
		$this->assertTrue( Secrets::looks_like_secret( 'sk_live_51H8xTgKq9wPmNvRt2Jc4Lb' ) );
		$this->assertTrue( Secrets::looks_like_secret( 'ghp_16C7e42F292c6912E7710c838347Ae178B4a' ) );
		$this->assertTrue( Secrets::looks_like_secret( 'AKIAIOSFODNN7EXAMPLE' ) );
		$this->assertTrue( Secrets::looks_like_secret( 'xoxb-2154537400-abcdefghijkl' ) );
	}

	public function test_a_provider_key_buried_in_a_sentence_is_still_refused(): void {
		$this->assertTrue(
			Secrets::looks_like_secret( 'Here you go, the key is sk_live_51H8xTgKq9wPmNvRt2Jc4Lb — let me know.' )
		);
	}

	public function test_a_card_number_is_refused(): void {
		// A Luhn-valid test number, spaced the way somebody would type it.
		$this->assertTrue( Secrets::looks_like_secret( '4242 4242 4242 4242' ) );
		$this->assertTrue( Secrets::looks_like_secret( '4242-4242-4242-4242' ) );
		$this->assertTrue( Secrets::looks_like_secret( 'card 4242424242424242' ) );
	}

	public function test_a_long_random_looking_blob_is_refused(): void {
		$this->assertTrue( Secrets::looks_like_secret( 'aB3xQ7vLmN2pR8sT4uW9yZ1cD6fG0hJ5' ) );
	}

	/* -------------------------------------------------------------- allowed */

	public function test_an_ordinary_answer_is_allowed(): void {
		$this->assertFalse( Secrets::looks_like_secret( 'Invited support@blueworx.io as an administrator on Monday.' ) );
		$this->assertFalse( Secrets::looks_like_secret( 'Done — the DNS is with Cloudflare and you have been added.' ) );
		$this->assertFalse( Secrets::looks_like_secret( '' ) );
	}

	public function test_an_account_identifier_is_allowed(): void {
		// The very things ONB-3 says Forge does store.
		$this->assertFalse( Secrets::looks_like_secret( 'accounts@example.co.uk' ) );
		$this->assertFalse( Secrets::looks_like_secret( 'Cloudflare' ) );
		$this->assertFalse( Secrets::looks_like_secret( 'Administrator' ) );
	}

	public function test_a_url_is_allowed(): void {
		/*
		 * ONB-3 keeps the completion reference for a one-time secret link, and
		 * a reference is very often a URL. A rule that refused long URLs would
		 * refuse the one thing the decision says to record.
		 */
		$this->assertFalse( Secrets::looks_like_secret( 'https://onetimesecret.com/secret/abcd1234efgh5678ijkl' ) );
		$this->assertFalse( Secrets::looks_like_secret( 'Handed over: https://vault.example.com/r/9f2b7c1e4a6d8035' ) );
	}

	public function test_a_reference_number_is_allowed(): void {
		$this->assertFalse( Secrets::looks_like_secret( 'Ref OTS-2026-00418' ) );
		$this->assertFalse( Secrets::looks_like_secret( 'Ticket 4242424' ) );
	}

	public function test_a_long_sentence_is_allowed(): void {
		/*
		 * Length alone must never be the reason. A client explaining
		 * themselves at length is the normal case, not a suspicious one.
		 */
		$this->assertFalse(
			Secrets::looks_like_secret(
				'We have invited your named account to the registrar and to the DNS provider, both with permission to change records, and we are waiting on the hosting company to confirm the third invitation.'
			)
		);
	}

	public function test_a_number_that_is_not_a_card_is_allowed(): void {
		// Right length, fails Luhn — an order number, not a card.
		$this->assertFalse( Secrets::looks_like_secret( '4242424242424241' ) );
	}

	/* ------------------------------------------------------------- refusing */

	public function test_the_first_offending_field_is_named(): void {
		/*
		 * Named, because "your submission was refused" tells somebody nothing
		 * about which box to go and empty.
		 */
		$offending = Secrets::offending_field(
			array(
				'provider' => 'Cloudflare',
				'response' => 'password: hunter2',
			)
		);

		$this->assertSame( 'response', $offending );
	}

	public function test_a_clean_submission_names_nothing(): void {
		$this->assertSame(
			'',
			Secrets::offending_field(
				array(
					'provider'           => 'Cloudflare',
					'account_identifier' => 'accounts@example.co.uk',
					'access_role'        => 'Administrator',
					'response'           => 'Invited on Monday, waiting for them to accept.',
				)
			)
		);
	}

	public function test_fields_are_checked_in_a_fixed_order(): void {
		/*
		 * Two offending fields must always name the same one, or the same
		 * submission gives a different answer on a different day and nobody
		 * can write a test against it.
		 */
		$fields = array(
			'response' => 'password: hunter2',
			'provider' => 'sk_live_51H8xTgKq9wPmNvRt2Jc4Lb',
		);

		$this->assertSame(
			Secrets::offending_field( $fields ),
			Secrets::offending_field( array_reverse( $fields, true ) )
		);
	}

	public function test_a_non_string_field_is_ignored_rather_than_crashing(): void {
		$this->assertSame( '', Secrets::offending_field( array( 'position' => 10, 'launch_critical' => true ) ) );
	}
}
