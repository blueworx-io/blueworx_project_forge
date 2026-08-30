<?php
/**
 * What may not be typed into an onboarding step.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Onboarding;

/**
 * #167, ONB-3. The content check that stops Forge becoming a credential store.
 *
 * ONB-3 is enforced in two places, and this is the second of them. The first is
 * the schema: {@see \Blueworx\Forge\Data\Schema} gives a step nowhere to put a
 * credential, so no caller can write one to a column meant for it. That covers
 * the honest mistake. It does not cover somebody typing a password into the
 * notes box, which is the mistake people actually make — so the decision also
 * asks for field-level validation, and this is it.
 *
 * It lives in one class rather than in each controller for the reason the
 * schema half exists at all: a rule spread across callers is a rule the next
 * caller forgets. Everything that writes a client's answer asks here first.
 *
 * **The false-positive side matters more than the false-negative side.** A
 * check that refuses ordinary answers is a check somebody turns off, and then
 * it catches nothing whatsoever. So each rule below is narrow on purpose, and
 * the two things ONB-3 explicitly says to keep — an account identifier, and the
 * completion reference for a one-time secret link, which is usually a URL — are
 * deliberately outside all of them.
 *
 * This is not a claim that nothing can get through. It is the difference
 * between a credential arriving by accident and one arriving on purpose, and
 * only the first of those is worth building for.
 */
final class Secrets {

	/**
	 * A PEM block: private keys and certificates.
	 *
	 * Nothing legitimate in an onboarding answer opens with this, so it is the
	 * one rule that needs no qualification.
	 */
	private const PEM = '/-----BEGIN [A-Z0-9 ]*(PRIVATE KEY|CERTIFICATE)-----/';

	/**
	 * A label, then a separator, then something after it.
	 *
	 * The trailing `\S` is what separates "password: hunter2" from "the
	 * password is held by the client" — the first hands one over, the second
	 * says where it lives, and the second is exactly what the box is for.
	 */
	private const LABELLED = '/\b(pass(?:word|wd)?|pwd|api[ _-]?key|secret|token|private[ _-]?key|credentials?|passphrase)\b\s*[:=]\s*\S/i';

	/**
	 * Keys that announce themselves by their prefix.
	 *
	 * A short list of the providers a web build actually touches, rather than
	 * an attempt at every key format in existence. Each is anchored on a word
	 * boundary so it is still caught mid-sentence, which is how they are
	 * usually sent: "here you go, the key is ...".
	 *
	 * @var array<int, string>
	 */
	private const PROVIDER_KEYS = array(
		'/\b[rsp]k_(live|test)_[A-Za-z0-9]{8,}/',   // Stripe and its lookalikes.
		'/\bgh[pousr]_[A-Za-z0-9]{20,}/',           // GitHub tokens.
		'/\bgithub_pat_[A-Za-z0-9_]{20,}/',
		'/\bglpat-[A-Za-z0-9_-]{16,}/',             // GitLab.
		'/\bAKIA[0-9A-Z]{16}\b/',                   // AWS access key id.
		'/\bxox[baprs]-[A-Za-z0-9-]{10,}/',         // Slack.
		'/\bAIza[0-9A-Za-z_-]{30,}/',               // Google API key.
		'/\bya29\.[0-9A-Za-z_-]{20,}/',             // Google OAuth.
		'/\bs\.[A-Za-z0-9]{24}\b/',                 // HashiCorp Vault.
	);

	/**
	 * Digits, possibly spaced or hyphenated the way somebody types a card.
	 *
	 * Length alone proves nothing — an order number is the same shape — so a
	 * match here is only a candidate, and Luhn decides.
	 */
	private const DIGIT_RUN = '/(?:\d[ -]?){12,18}\d/';

	/**
	 * How long a bare token has to be before its shape alone is suspicious.
	 *
	 * Set high deliberately. Shorter than this and ordinary content starts
	 * being refused — a long reference, a plugin slug, a file name — and the
	 * cost of that is the whole check being switched off.
	 */
	private const BLOB_LENGTH = 32;

	/**
	 * The order fields are checked in.
	 *
	 * Fixed, so the same submission always names the same field. Two offending
	 * fields answered in array order would give a different answer depending on
	 * how the caller happened to build the array, and nothing could be written
	 * against that.
	 *
	 * @var array<int, string>
	 */
	private const FIELD_ORDER = array(
		'provider',
		'account_identifier',
		'account_owner',
		'access_role',
		'invitation_status',
		'verification_outcome',
		'response',
		'reason',
	);

	/**
	 * Whether a value is shaped like a password, a key or a card number.
	 *
	 * @param string $value What somebody typed.
	 * @return bool
	 */
	public static function looks_like_secret( string $value ): bool {
		if ( '' === trim( $value ) ) {
			return false;
		}

		if ( 1 === preg_match( self::PEM, $value ) || 1 === preg_match( self::LABELLED, $value ) ) {
			return true;
		}

		foreach ( self::PROVIDER_KEYS as $pattern ) {
			if ( 1 === preg_match( $pattern, $value ) ) {
				return true;
			}
		}

		return self::holds_card_number( $value ) || self::holds_blob( $value );
	}

	/**
	 * The first field whose content may not be stored.
	 *
	 * Named rather than a bare refusal, because "your submission was refused"
	 * tells somebody nothing about which box to go and empty, and they will
	 * try again with the same content.
	 *
	 * @param array<string, mixed> $fields Field name to value. Non-strings are
	 *                                     ignored: a position or a flag cannot
	 *                                     carry a credential.
	 * @return string Field name, or '' when there is nothing wrong.
	 */
	public static function offending_field( array $fields ): string {
		$named = array_values( array_intersect( self::FIELD_ORDER, array_keys( $fields ) ) );

		$rest = array_diff( array_keys( $fields ), self::FIELD_ORDER );
		sort( $rest );

		foreach ( array_merge( $named, $rest ) as $name ) {
			$value = $fields[ $name ];

			if ( is_string( $value ) && self::looks_like_secret( $value ) ) {
				return (string) $name;
			}
		}

		return '';
	}

	/**
	 * Whether the value contains something that passes as a card number.
	 *
	 * @param string $value What somebody typed.
	 * @return bool
	 */
	private static function holds_card_number( string $value ): bool {
		$matches = array();

		preg_match_all( self::DIGIT_RUN, $value, $matches );

		foreach ( $matches[0] ?? array() as $candidate ) {
			$digits = preg_replace( '/\D/', '', (string) $candidate );

			if ( is_string( $digits ) && self::passes_luhn( $digits ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The check that separates a card number from a number of the same length.
	 *
	 * @param string $digits Digits only.
	 * @return bool
	 */
	private static function passes_luhn( string $digits ): bool {
		$length = strlen( $digits );

		if ( $length < 13 || $length > 19 ) {
			return false;
		}

		$sum    = 0;
		$double = false;

		for ( $i = $length - 1; $i >= 0; $i-- ) {
			$digit = (int) $digits[ $i ];

			if ( $double ) {
				$digit *= 2;

				if ( $digit > 9 ) {
					$digit -= 9;
				}
			}

			$sum   += $digit;
			$double = ! $double;
		}

		return 0 === $sum % 10;
	}

	/**
	 * Whether the value contains a long, random-looking, wordless token.
	 *
	 * The catch-all for keys with no prefix worth listing. It asks for three
	 * things together — length, mixed case and digits, and no separators — so
	 * that prose, however long, never trips it, and neither does a URL: ONB-3
	 * keeps the completion reference for a one-time secret link, and those are
	 * URLs, so refusing them would refuse the one thing it says to record.
	 *
	 * @param string $value What somebody typed.
	 * @return bool
	 */
	private static function holds_blob( string $value ): bool {
		$tokens = preg_split( '/\s+/', $value );

		foreach ( is_array( $tokens ) ? $tokens : array() as $token ) {
			$token = trim( (string) $token, ".,;:!?()[]{}<>\"'" );

			if ( strlen( $token ) < self::BLOB_LENGTH ) {
				continue;
			}

			// A URL is a reference, not a secret. See the method comment.
			if ( false !== strpos( $token, '://' ) || false !== strpos( $token, '@' ) ) {
				continue;
			}

			$mixed = 1 === preg_match( '/[a-z]/', $token )
				&& 1 === preg_match( '/[A-Z]/', $token )
				&& 1 === preg_match( '/[0-9]/', $token );

			if ( $mixed && 1 !== preg_match( '/[^A-Za-z0-9_\-]/', $token ) ) {
				return true;
			}
		}

		return false;
	}
}
