<?php
/**
 * Who a client-facing email goes to.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Notifications;

/**
 * NOTIF-1. The original submitter and the client's nominated people, once each.
 *
 * **Nothing here is taken from the item.** A submission carries a
 * `submitted_by` field, and it is free text — whatever the client's own site
 * said the person was called. That string never becomes an address. It is used
 * only to *choose* among people Forge already has verified records for, and if
 * it matches nobody then the submitter simply is not on the list.
 *
 * The distinction sounds pedantic and is the whole decision. A free-text
 * address on a record is an address anybody who can edit the record can point
 * at themselves, and the thing being sent is a client's confidential update
 * about their own work. Resolving from verified records means the worst a bad
 * value can do is send to nobody.
 *
 * De-duplicated because the submitter is very often also a nominated recipient,
 * and two identical emails about one thing reads as a fault in the product.
 *
 * Nobody from the studio is on this list. These are the client's emails about
 * the client's work; what the studio needs to know it sees on the board.
 */
final class Recipients {

	/**
	 * The addresses one client-facing email goes to.
	 *
	 * @param array<int, array<string, mixed>> $people       Verified people on the
	 *                                                       client, each with an
	 *                                                       email and a display name.
	 * @param string                           $submitted_by Whatever the item says,
	 *                                                       used only to choose.
	 * @return array<int, string> Addresses, submitter first, each once.
	 */
	public static function choose( array $people, string $submitted_by = '' ): array {
		$verified = array();

		foreach ( $people as $person ) {
			$email = self::usable( (string) ( $person['email'] ?? '' ) );

			if ( '' === $email ) {
				continue;
			}

			$key = strtolower( $email );

			/*
			 * Keyed by the lower-cased address, which is what de-duplicates:
			 * two records for one person are two records and one inbox.
			 *
			 * First seen wins, rather than last. Both send exactly one email,
			 * so the difference is only which spelling of the address it goes
			 * to — but "the earlier record" is a rule somebody can predict, and
			 * "whichever happened to be read last" is not.
			 */
			if ( ! isset( $verified[ $key ] ) ) {
				$verified[ $key ] = $email;
			}
		}

		if ( array() === $verified ) {
			return array();
		}

		$first = self::matched( $verified, $submitted_by );

		if ( '' === $first ) {
			return array_values( $verified );
		}

		/*
		 * The submitter goes first. Not cosmetic: an email whose first
		 * recipient is the person who asked reads as a reply to them, and the
		 * rest as being kept informed — which is what it is.
		 */
		$rest = $verified;

		unset( $rest[ $first ] );

		return array_merge( array( $verified[ $first ] ), array_values( $rest ) );
	}

	/**
	 * Whether anybody at all can be written to.
	 *
	 * Asked separately so a caller can tell "nobody to write to" from "wrote to
	 * nobody" — the first is a client with no people set up, which somebody
	 * should fix, and the second is a bug.
	 *
	 * @param array<int, array<string, mixed>> $people Verified people.
	 * @return bool
	 */
	public static function any( array $people ): bool {
		return array() !== self::choose( $people );
	}

	/**
	 * Which verified address the item's free-text submitter refers to, if any.
	 *
	 * Matched on the address alone. A display name is not matched on: two
	 * people called Sam is an ordinary situation, and guessing between them
	 * sends one person's work to the other.
	 *
	 * @param array<string, string> $verified Lower-cased address to address.
	 * @param string                $said     The free-text field.
	 * @return string The key into $verified, or ''.
	 */
	private static function matched( array $verified, string $said ): string {
		$key = strtolower( trim( $said ) );

		return isset( $verified[ $key ] ) ? $key : '';
	}

	/**
	 * An address, if it is one.
	 *
	 * @param string $email Whatever the record holds.
	 * @return string
	 */
	private static function usable( string $email ): string {
		$email = trim( $email );

		return false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ? '' : $email;
	}
}
