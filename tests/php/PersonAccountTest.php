<?php
/**
 * Tests for the rules that join a person to a WordPress account.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Tenancy\Accounts;
use PHPUnit\Framework\TestCase;

/**
 * #292. Every person is a WordPress user, and the two are kept in step.
 *
 * The half that needs no database is here: whether a link is allowed, and what
 * one side should copy from the other. Creating accounts and writing to them is
 * proved against real WordPress.
 *
 * The comparisons matter more than they look. Each side writes to the other on
 * change, so a difference reported where there is none is not a wrong answer —
 * it is two saves triggering each other for ever.
 */
final class PersonAccountTest extends TestCase {

	/**
	 * A person, in the shape Users::get() returns.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 * @return array<string, mixed>
	 */
	private function person( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'           => 'usr_ana',
				'display_name' => 'Ana Fielding',
				'email'        => 'ana@studio.example',
				'status'       => 'active',
				'wp_user_id'   => 7,
			),
			$overrides
		);
	}

	/**
	 * A WordPress account, in the words WordPress uses for it.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 * @return array<string, mixed>
	 */
	private function account( array $overrides = array() ): array {
		return array_merge(
			array(
				'display_name' => 'Ana Fielding',
				'user_email'   => 'ana@studio.example',
			),
			$overrides
		);
	}

	// -----------------------------------------------------------------------
	// Whether an account may be linked.
	// -----------------------------------------------------------------------

	/**
	 * The ordinary case: an account nobody has claimed.
	 */
	public function test_an_unclaimed_account_may_be_linked(): void {
		$this->assertNull( Accounts::link_error( null, 'usr_ana' ) );
	}

	/**
	 * The refusal this rule exists for. Two people sharing one account would
	 * make whoever signed in resolve to whichever row was found first.
	 */
	public function test_an_account_somebody_else_holds_is_refused(): void {
		$holder = $this->person( array( 'id' => 'usr_sam' ) );

		$this->assertNotNull( Accounts::link_error( $holder, 'usr_ana' ) );
	}

	/**
	 * Re-linking somebody to the account they already hold is not a mistake, and
	 * saving the edit form unchanged does exactly that.
	 */
	public function test_relinking_somebody_to_their_own_account_is_allowed(): void {
		$this->assertNull( Accounts::link_error( $this->person(), 'usr_ana' ) );
	}

	// -----------------------------------------------------------------------
	// What Forge takes from WordPress.
	// -----------------------------------------------------------------------

	/**
	 * Nothing to do is reported as nothing to do. This is the case that runs on
	 * every profile save, and a change reported here would be written back to
	 * WordPress, which would report a change again.
	 */
	public function test_an_account_that_already_matches_changes_nothing(): void {
		$this->assertSame( array(), Accounts::changes_from_wp( $this->person(), $this->account() ) );
	}

	/**
	 * Only what actually differs is copied, so an edit to one field cannot
	 * quietly rewrite the other.
	 */
	public function test_only_the_changed_field_is_taken_from_wordpress(): void {
		$changes = Accounts::changes_from_wp(
			$this->person(),
			$this->account( array( 'display_name' => 'Ana Fielding-Doyle' ) )
		);

		$this->assertSame( array( 'display_name' => 'Ana Fielding-Doyle' ), $changes );
	}

	/**
	 * Forge stores addresses lower-cased, because the unique index behind them
	 * is what makes one person one account. An address arriving from WordPress
	 * is no exception.
	 */
	public function test_an_address_from_wordpress_is_lower_cased(): void {
		$changes = Accounts::changes_from_wp(
			$this->person(),
			$this->account( array( 'user_email' => 'Ana.Fielding@Studio.Example' ) )
		);

		$this->assertSame( array( 'email' => 'ana.fielding@studio.example' ), $changes );
	}

	/**
	 * The loop guard. WordPress keeps the address as it was typed, so the same
	 * address in different capitals is the same address and not a change.
	 */
	public function test_the_same_address_in_different_capitals_is_not_a_change(): void {
		$changes = Accounts::changes_from_wp(
			$this->person(),
			$this->account( array( 'user_email' => 'ANA@STUDIO.EXAMPLE' ) )
		);

		$this->assertSame( array(), $changes );
	}

	/**
	 * A person needs a name and an address. An account that has lost one of
	 * them must not take the person's with it.
	 */
	public function test_an_empty_field_from_wordpress_is_ignored(): void {
		$changes = Accounts::changes_from_wp(
			$this->person(),
			$this->account(
				array(
					'display_name' => '',
					'user_email'   => '   ',
				)
			)
		);

		$this->assertSame( array(), $changes );
	}

	// -----------------------------------------------------------------------
	// What WordPress takes from Forge.
	// -----------------------------------------------------------------------

	/**
	 * The other side of the loop guard, and the same reason.
	 */
	public function test_a_person_who_already_matches_their_account_writes_nothing(): void {
		$this->assertSame( array(), Accounts::changes_to_wp( $this->person(), $this->account() ) );
	}

	/**
	 * What is written is written in WordPress's own words, because it is handed
	 * straight to wp_update_user().
	 */
	public function test_a_changed_person_is_written_in_wordpress_field_names(): void {
		$changes = Accounts::changes_to_wp(
			$this->person(
				array(
					'display_name' => 'Ana Doyle',
					'email'        => 'ana.doyle@studio.example',
				)
			),
			$this->account()
		);

		$this->assertSame(
			array(
				'display_name' => 'Ana Doyle',
				'user_email'   => 'ana.doyle@studio.example',
			),
			$changes
		);
	}

	/**
	 * Somebody carried over from before this rule existed has no account, and
	 * there is nothing to write to. They are shown on the screen as needing one
	 * rather than written to user zero.
	 */
	public function test_a_person_with_no_account_writes_nothing(): void {
		$changes = Accounts::changes_to_wp(
			$this->person(
				array(
					'wp_user_id'   => 0,
					'display_name' => 'Ana Doyle',
				)
			),
			$this->account()
		);

		$this->assertSame( array(), $changes );
	}
}
