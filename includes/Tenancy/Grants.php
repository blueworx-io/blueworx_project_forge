<?php
/**
 * The authorities somebody holds on top of a role.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Tenancy;

/**
 * A grant is an extra authority held by somebody who already has a role, stored
 * as a comma list rather than as a role of its own.
 *
 * They are not roles because they do not describe a kind of account. Two people
 * can both be Staff and one of them may approve their own work (AUTH-3) — that
 * is a fact about the person, not about what sort of person they are. Making
 * each a role would multiply the matrix by every combination of them.
 *
 * **The list is closed.** An unrecognised value in the column grants nothing at
 * all: the failure mode of the alternative is that somebody hand-edits a row,
 * or an old export is restored, and a string nobody defined turns into
 * authority the first time something reads the column loosely.
 *
 * Two of the three sit on a membership, because they are held with one client.
 * Cross-client (#93) sits on the user, because its whole meaning is that it is
 * not held with one client.
 */
final class Grants {

	/**
	 * AUTH-3. May be their own Reviewer.
	 */
	public const PRINCIPAL = 'principal';

	/**
	 * AUTH-1. Holds the Approver capabilities.
	 */
	public const APPROVER = 'approver';

	/**
	 * #93. Reaches every client rather than the ones they are a member of.
	 */
	public const CROSS_CLIENT = 'cross_client';

	/**
	 * Every grant there is.
	 */
	public const ALL = array(
		self::PRINCIPAL,
		self::APPROVER,
		self::CROSS_CLIENT,
	);

	/**
	 * The grants held on a membership, as against on the user.
	 */
	public const ON_MEMBERSHIP = array(
		self::PRINCIPAL,
		self::APPROVER,
	);

	/**
	 * The grants held on the user, across every client at once.
	 */
	public const ON_USER = array(
		self::CROSS_CLIENT,
	);

	/**
	 * Reads a stored column as the grants it holds.
	 *
	 * @param string $stored The column as stored.
	 * @return array<int, string>
	 */
	public static function parse( string $stored ): array {
		$found = array();

		foreach ( explode( ',', $stored ) as $candidate ) {
			$grant = trim( $candidate );

			if ( self::exists( $grant ) && ! in_array( $grant, $found, true ) ) {
				$found[] = $grant;
			}
		}

		return $found;
	}

	/**
	 * Whether a stored column holds one particular grant.
	 *
	 * @param string $stored The column as stored.
	 * @param string $grant  The grant being asked about.
	 * @return bool
	 */
	public static function held( string $stored, string $grant ): bool {
		return in_array( $grant, self::parse( $stored ), true );
	}

	/**
	 * Writes a set of grants back to the stored form, dropping anything that is
	 * not a grant so the column cannot carry one.
	 *
	 * @param array<int, string> $grants Grants to store.
	 * @return string
	 */
	public static function format( array $grants ): string {
		return implode( ',', self::parse( implode( ',', $grants ) ) );
	}

	/**
	 * Whether a string is a grant at all.
	 *
	 * @param string $grant Candidate.
	 * @return bool
	 */
	public static function exists( string $grant ): bool {
		return in_array( $grant, self::ALL, true );
	}

	/**
	 * How a grant reads to a human.
	 *
	 * @param string $grant Grant.
	 * @return string
	 */
	public static function label( string $grant ): string {
		switch ( $grant ) {
			case self::PRINCIPAL:
				return __( 'Principal — may review their own work', 'blueworx-forge' );
			case self::APPROVER:
				return __( 'Approver — may approve estimates and commercial terms', 'blueworx-forge' );
			case self::CROSS_CLIENT:
				return __( 'Cross-client — reaches every client, not only their own', 'blueworx-forge' );
			default:
				return __( 'Unknown grant', 'blueworx-forge' );
		}
	}

	/**
	 * What a grant means, for the screen that hands it out.
	 *
	 * @param string $grant Grant.
	 * @return string
	 */
	public static function description( string $grant ): string {
		switch ( $grant ) {
			case self::PRINCIPAL:
				return __( 'Waives the rule that the Reviewer must be somebody other than the Primary User. Give it to people who genuinely work alone.', 'blueworx-forge' );
			case self::APPROVER:
				return __( 'Lets them approve an estimate and confirm commercial terms. Without it those gates wait for somebody who has it.', 'blueworx-forge' );
			case self::CROSS_CLIENT:
				return __( 'Lets them see and work on every client. Without it they reach only the clients they are a member of, exactly like a client user.', 'blueworx-forge' );
			default:
				return '';
		}
	}
}
