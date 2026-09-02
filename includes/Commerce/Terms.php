<?php
/**
 * What a support package actually offers, and what counts as changing it.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Commerce;

/**
 * #145, COMM-1. The terms of a package, and nothing about storing them.
 *
 * Pure, so the two questions worth arguing about can be argued about in a test
 * rather than against a database:
 *
 * - *What is a valid set of terms?* A package with no hours, or a validity of
 *   nine hundred months, is somebody's typo rather than an offer, and it is far
 *   cheaper to refuse it here than to find it in a client's paperwork.
 * - *Has anything actually changed?* {@see self::differ()} is what stops the
 *   catalogue minting a version every time somebody opens the form and saves
 *   it. A version history that records six identical versions is a history
 *   nobody trusts, and "which one did they buy" stops having a useful answer.
 */
final class Terms {

	/**
	 * Still on offer.
	 */
	public const ACTIVE = 'active';

	/**
	 * No longer sold. Everybody already on it keeps it.
	 */
	public const RETIRED = 'retired';

	/**
	 * Both statuses, for anything that has to enumerate them.
	 *
	 * @var array<int, string>
	 */
	public const STATUSES = array( self::ACTIVE, self::RETIRED );

	/**
	 * Longest a package name may be, matching the column.
	 */
	public const MAX_NAME = 191;

	/**
	 * Longest the written terms may be.
	 *
	 * Generous, because this is the paragraph a client is shown and somebody
	 * will paste a clause into it. It is a text column; the cap is here so a
	 * runaway paste is refused at the door rather than truncated silently on
	 * the way in.
	 */
	public const MAX_TERMS = 5000;

	/**
	 * How long a term runs unless somebody says otherwise, in months.
	 *
	 * Twelve, per COMM-1: assignment starts a new twelve-month term from the
	 * effective date.
	 */
	public const DEFAULT_VALIDITY_MONTHS = 12;

	/**
	 * The longest term anybody may set, in months.
	 *
	 * Ten years. Not a real offer — a bound, so a fat-fingered 1200 cannot
	 * commit the studio to a century of support at this year's price.
	 */
	public const MAX_VALIDITY_MONTHS = 120;

	/**
	 * The most hours a single package may carry.
	 *
	 * Matches what the column can hold, so a number that would be silently
	 * truncated by the database is refused by the code that can say why.
	 */
	public const MAX_HOURS = 9999.99;

	/**
	 * The fields that make up an offer.
	 *
	 * Everything here is copied into a version and frozen. Anything *not* here
	 * — where a package sits in the list, whether it is still sold — can be
	 * changed freely, because changing it cannot alter what anybody was sold.
	 *
	 * @var array<int, string>
	 */
	public const FIELDS = array( 'name', 'hours', 'price', 'currency', 'validity_months', 'terms' );

	/**
	 * A submitted set of terms, cleaned up.
	 *
	 * Every field is given a value, so a caller never has to decide what a
	 * missing one meant. Hours land on two decimals because the column holds
	 * two and a figure that reads 7.005 on one screen and 7.01 on the next is
	 * the sort of thing somebody spends an afternoon on.
	 *
	 * @param array<string, mixed> $values Whatever was submitted.
	 * @return array<string, mixed>
	 */
	public static function sanitise( array $values ): array {
		$hours  = (float) ( $values['hours'] ?? 0 );
		$months = (int) ( $values['validity_months'] ?? self::DEFAULT_VALIDITY_MONTHS );

		return array(
			'name'            => mb_substr( trim( (string) ( $values['name'] ?? '' ) ), 0, self::MAX_NAME ),
			'hours'           => round( max( 0.0, min( self::MAX_HOURS, $hours ) ), 2 ),
			'price'           => max( 0, (int) round( (float) ( $values['price'] ?? 0 ) ) ),
			'currency'        => self::currency( (string) ( $values['currency'] ?? 'GBP' ) ),
			'validity_months' => max( 1, min( self::MAX_VALIDITY_MONTHS, 0 === $months ? self::DEFAULT_VALIDITY_MONTHS : $months ) ),
			'terms'           => mb_substr( trim( (string) ( $values['terms'] ?? '' ) ), 0, self::MAX_TERMS ),
		);
	}

	/**
	 * Why a set of terms is not an offer, or '' when it is.
	 *
	 * A sentence rather than a code, because the only caller is a screen
	 * showing it to whoever typed it, and every one of these is a typo rather
	 * than an attack.
	 *
	 * @param array<string, mixed> $terms Sanitised terms.
	 * @return string
	 */
	public static function refuse( array $terms ): string {
		if ( '' === (string) $terms['name'] ) {
			return __( 'A package needs a name.', 'blueworx-forge' );
		}

		/*
		 * Zero hours is refused rather than allowed as a "support, no hours"
		 * product. COMM-5 already covers the only case that needs it — bug work
		 * on a site we delivered is free — and a nought-hour package would let
		 * that exemption be granted by accident, to everything, by whoever set
		 * the catalogue up.
		 */
		if ( 0.0 >= (float) $terms['hours'] ) {
			return __( 'A package needs some hours in it. Free bug work is already covered without one.', 'blueworx-forge' );
		}

		return '';
	}

	/**
	 * Whether two sets of terms are a different offer.
	 *
	 * Compared field by field over {@see self::FIELDS} rather than with a whole
	 * array comparison, so a stored row carrying created_at and an id does not
	 * read as different from the form that produced it. Hours are compared as
	 * numbers: '7.00' and 7 are the same offer, and a string comparison would
	 * mint a version every time a row went to the database and came back.
	 *
	 * @param array<string, mixed> $before One set.
	 * @param array<string, mixed> $after  The other.
	 * @return bool
	 */
	public static function differ( array $before, array $after ): bool {
		foreach ( self::FIELDS as $field ) {
			$was = $before[ $field ] ?? null;
			$now = $after[ $field ] ?? null;

			if ( in_array( $field, array( 'hours', 'price', 'validity_months' ), true ) ) {
				if ( abs( (float) $was - (float) $now ) > 0.001 ) {
					return true;
				}

				continue;
			}

			if ( (string) $was !== (string) $now ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a status is one of the two.
	 *
	 * @param string $status Candidate.
	 * @return bool
	 */
	public static function is_status( string $status ): bool {
		return in_array( $status, self::STATUSES, true );
	}

	/**
	 * A currency code, or GBP.
	 *
	 * Three letters, upper case, and nothing else — this ends up next to a
	 * number on a client's screen, and "£" or "pounds" in that slot is how a
	 * price becomes ambiguous.
	 *
	 * @param string $code Candidate.
	 * @return string
	 */
	private static function currency( string $code ): string {
		$code = strtoupper( trim( $code ) );

		return 1 === preg_match( '/^[A-Z]{3}$/', $code ) ? $code : 'GBP';
	}
}
