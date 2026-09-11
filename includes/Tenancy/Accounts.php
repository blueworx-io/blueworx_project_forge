<?php
/**
 * The join between a person and their WordPress account.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Tenancy;

/**
 * #292. Every person is somebody who can sign in.
 *
 * A Forge person used to be a record that might or might not have an account on
 * this site, and in practice never did — which meant the people we added could
 * not use the thing we added them to. Now the two are made together and kept in
 * step: the person is the account, seen from Forge's side.
 *
 * The rules live here as plain comparisons with no WordPress behind them, for a
 * reason that is not tidiness. Each side writes to the other whenever it sees a
 * difference, so a difference reported where there is none is not a wrong
 * answer — it is two saves calling each other until something gives way. These
 * are the functions that decide there is nothing to do, and they are the ones
 * worth being able to test on their own.
 */
final class Accounts {

	/**
	 * The WordPress role an account Forge makes is given.
	 *
	 * The lowest one there is, deliberately. Being a person in Forge says
	 * nothing about what somebody may do in WordPress; their membership says
	 * what they may do in Forge, and that is the only authority they get from
	 * being added here.
	 */
	public const ROLE = 'subscriber';

	/**
	 * True while Forge is writing to WordPress.
	 *
	 * Each side copies from the other on change, so the write out fires the hook
	 * that reads back in. The comparisons below already stop that settling into
	 * a loop — by the time the hook runs the two sides agree, so there is
	 * nothing to copy — but there is no reason to make the round trip at all.
	 *
	 * @var bool
	 */
	private static $writing = false;

	/**
	 * Hooks the WordPress side up.
	 */
	public static function boot(): void {
		add_action( 'profile_update', array( self::class, 'pull' ) );
		add_action( 'user_register', array( self::class, 'pull' ) );
		add_action( 'deleted_user', array( self::class, 'on_deleted' ) );
	}

	/**
	 * Whether an account may be joined to a person.
	 *
	 * @param array<string, mixed>|null $holder    The person already holding the
	 *                                             account, or null for nobody.
	 * @param string                    $person_id The person being linked.
	 * @return string|null The refusal, or null when there is nothing to refuse.
	 */
	public static function link_error( ?array $holder, string $person_id ): ?string {
		if ( null === $holder || (string) $holder['id'] === $person_id ) {
			return null;
		}

		return 'That WordPress user is already somebody else.';
	}

	/**
	 * What Forge should take from a WordPress account.
	 *
	 * An empty field is never taken. A person needs a name and an address, and
	 * an account that has somehow lost one must not take the person's with it.
	 *
	 * @param array<string, mixed> $person  The person, as Users::get() returns.
	 * @param array<string, mixed> $account The account, in WordPress's own words.
	 * @return array<string, mixed> Fields to write, in Forge's words. Empty when
	 *                              the two already agree.
	 */
	public static function changes_from_wp( array $person, array $account ): array {
		$changes = array();

		$name = trim( (string) ( $account['display_name'] ?? '' ) );

		if ( '' !== $name && $name !== (string) $person['display_name'] ) {
			$changes['display_name'] = $name;
		}

		// Lower-cased before it is compared as well as before it is stored:
		// WordPress keeps an address as it was typed, and the same address in
		// different capitals is the same address.
		$email = strtolower( trim( (string) ( $account['user_email'] ?? '' ) ) );

		if ( '' !== $email && strtolower( (string) $person['email'] ) !== $email ) {
			$changes['email'] = $email;
		}

		return $changes;
	}

	/**
	 * What a WordPress account should take from Forge.
	 *
	 * @param array<string, mixed> $person  The person, as Users::get() returns.
	 * @param array<string, mixed> $account The account, in WordPress's own words.
	 * @return array<string, mixed> Fields for wp_update_user(), in WordPress's
	 *                              words. Empty when the two already agree, and
	 *                              for somebody who has no account to write to.
	 */
	public static function changes_to_wp( array $person, array $account ): array {
		if ( (int) $person['wp_user_id'] <= 0 ) {
			return array();
		}

		$changes = array();

		$name = (string) $person['display_name'];

		if ( '' !== $name && (string) ( $account['display_name'] ?? '' ) !== $name ) {
			$changes['display_name'] = $name;
		}

		$email = strtolower( (string) $person['email'] );

		if ( '' !== $email && strtolower( (string) ( $account['user_email'] ?? '' ) ) !== $email ) {
			$changes['user_email'] = $email;
		}

		return $changes;
	}

	// -----------------------------------------------------------------------
	// The WordPress side.
	// -----------------------------------------------------------------------

	/**
	 * The account behind a WordPress user id, in the words WordPress uses.
	 *
	 * @param int $wp_user_id WordPress user id.
	 * @return array<string, mixed>|null Null when there is no such account.
	 */
	public static function account( int $wp_user_id ): ?array {
		if ( $wp_user_id <= 0 ) {
			return null;
		}

		$wp_user = get_userdata( $wp_user_id );

		if ( ! $wp_user ) {
			return null;
		}

		return array(
			'id'           => (int) $wp_user->ID,
			'login'        => (string) $wp_user->user_login,
			'display_name' => (string) $wp_user->display_name,
			'user_email'   => (string) $wp_user->user_email,
		);
	}

	/**
	 * The account somebody should be joined to, making one if there is none.
	 *
	 * An address that already has an account nobody in Forge holds is joined to
	 * rather than refused. Most people here will have signed up on this site
	 * before anybody thought to add them to Forge, and giving them a second
	 * account under a mangled login would be the wrong answer to that.
	 *
	 * No mail is sent. An account made this way is a record of somebody, not an
	 * invitation, and a notification would tell a client we had made them a
	 * login before anybody meant them to have one.
	 *
	 * @param string $name  What we call them.
	 * @param string $email Their address, lower-cased.
	 * @return int The WordPress user id, or 0 when the account could not be made.
	 */
	public static function ensure( string $name, string $email ): int {
		$existing = get_user_by( 'email', $email );

		if ( $existing ) {
			return (int) $existing->ID;
		}

		$created = wp_insert_user(
			array(
				'user_login'   => self::login_for( $email ),
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 24, true, true ),
				'display_name' => $name,
				'nickname'     => $name,
				'role'         => self::ROLE,
			)
		);

		return is_wp_error( $created ) ? 0 : (int) $created;
	}

	/**
	 * Writes a person's name and address to their WordPress account.
	 *
	 * @param array<string, mixed> $person The person, as Users::get() returns.
	 * @return bool False when WordPress refused the write — an address another
	 *              account already holds is how that happens.
	 */
	public static function push( array $person ): bool {
		$account = self::account( (int) $person['wp_user_id'] );

		if ( null === $account ) {
			return true;
		}

		$changes = self::changes_to_wp( $person, $account );

		if ( array() === $changes ) {
			return true;
		}

		$changes['ID'] = (int) $person['wp_user_id'];

		self::$writing = true;
		$updated       = wp_update_user( $changes );
		self::$writing = false;

		return ! is_wp_error( $updated );
	}

	/**
	 * Writes a WordPress account's name and address back to the person.
	 *
	 * Hooked to every profile save on the site, so the first thing it does is
	 * find out whether this account is anybody here at all — most are not.
	 *
	 * @param int $wp_user_id WordPress user id.
	 */
	public static function pull( int $wp_user_id ): void {
		if ( self::$writing ) {
			return;
		}

		$person = Users::by_wp_user( $wp_user_id );

		if ( null === $person ) {
			return;
		}

		$account = self::account( $wp_user_id );

		if ( null === $account ) {
			return;
		}

		$changes = self::changes_from_wp( $person, $account );

		if ( array() === $changes ) {
			return;
		}

		/*
		 * Somebody else here already holding the address is the one way this
		 * can fail. The address is left behind rather than taken, because the
		 * alternative is two people merged into one row's worth of history —
		 * the thing the unique index on that column exists to prevent.
		 */
		if ( array_key_exists( 'email', $changes ) ) {
			$holder = Users::by_email( (string) $changes['email'] );

			if ( null !== $holder && (string) $holder['id'] !== (string) $person['id'] ) {
				unset( $changes['email'] );
			}
		}

		if ( array() === $changes ) {
			return;
		}

		Users::update( (string) $person['id'], $changes, (int) $person['record_version'] );
	}

	/**
	 * Offboards the person behind an account that has been deleted.
	 *
	 * Their access ends and their history stays, exactly as offboarding them on
	 * the People screen would. The link goes with it: WordPress hands the same
	 * id out again eventually, and a row still pointing at it would make
	 * whoever received it next into somebody they are not.
	 *
	 * @param int $wp_user_id The WordPress user id that has gone.
	 */
	public static function on_deleted( int $wp_user_id ): void {
		$person = Users::by_wp_user( $wp_user_id );

		if ( null === $person ) {
			return;
		}

		Users::deactivate(
			(string) $person['id'],
			(int) $person['record_version'],
			array( 'wp_user_id' => 0 )
		);
	}

	/**
	 * Every WordPress account that is nobody in Forge yet.
	 *
	 * @return array<int, array<string, mixed>> Id, login, name and address, by name.
	 */
	public static function unlinked(): array {
		// Excluded in the query and asked for by column, because this runs on
		// every load of the People screen and a site where everybody signs up
		// is exactly the site where it would otherwise read every user in full.
		$accounts = get_users(
			array(
				'exclude' => Users::linked_wp_ids(),
				'orderby' => 'display_name',
				'fields'  => array( 'ID', 'user_login', 'display_name', 'user_email' ),
			)
		);

		$free = array();

		foreach ( $accounts as $wp_user ) {
			$free[] = array(
				'id'           => (int) $wp_user->ID,
				'login'        => (string) $wp_user->user_login,
				'display_name' => (string) $wp_user->display_name,
				'user_email'   => (string) $wp_user->user_email,
			);
		}

		return $free;
	}

	/**
	 * A login name for a new account, taken from the address and made unique.
	 *
	 * @param string $email The address.
	 * @return string
	 */
	private static function login_for( string $email ): string {
		$stem = sanitize_user( (string) strstr( $email . '@', '@', true ), true );

		if ( '' === $stem ) {
			$stem = 'person';
		}

		$login = $stem;
		$next  = 2;

		while ( username_exists( $login ) ) {
			$login = $stem . $next;
			++$next;
		}

		return $login;
	}
}
