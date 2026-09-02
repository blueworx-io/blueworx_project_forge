<?php
/**
 * What a site may do, given the package it is on.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Commerce;

/**
 * #151, COMM-2. The one statement of what a support position permits.
 *
 * **Said once, on the server, and published.** A client with no package is
 * restricted by the service and not by a hidden menu — which means two things
 * that are easy to get half right. The service has to actually refuse, so a
 * caller going straight to the API gets the same answer as somebody pressing a
 * button. And the client has to be *told*, so a screen with a button missing
 * reads as a decision somebody made rather than as a page that failed to load.
 *
 * So this answers both questions from one list, and {@see self::for_state()} is
 * sent to the client site rather than each end working it out. A client artifact
 * that decided for itself which of its own buttons to hide would be a second
 * implementation of this rule, and the two would disagree the first time one
 * changed.
 *
 * **What stays open is the interesting half.** A client with no package is a
 * sales conversation, not a locked door: they can still report a bug, still ask
 * for something, still reach their point of contact, and still talk to us about
 * buying. Everything in {@see self::ALWAYS} is available in every state,
 * including to a site that has never bought anything — because a product that
 * goes quiet when somebody stops paying cannot be sold back to them.
 *
 * Only chargeable work is gated, and only on a site that may not draw hours.
 * COMM-5 free bugs are not chargeable work: something Forge delivered and broke
 * is fixed whatever the client's package is doing.
 */
final class Restrictions {

	/**
	 * Planning and doing work the client pays for.
	 */
	public const CHARGEABLE_WORK = 'chargeable-work';

	/**
	 * Reporting something that is broken.
	 */
	public const BUG_INTAKE = 'bug-intake';

	/**
	 * Asking for something new.
	 */
	public const REQUEST_INTAKE = 'request-intake';

	/**
	 * Talking to us about buying.
	 */
	public const SALES = 'sales';

	/**
	 * Reaching the person who looks after this client.
	 */
	public const POINT_OF_CONTACT = 'point-of-contact';

	/**
	 * Following and commenting on work already under way.
	 */
	public const DISCUSSION = 'discussion';

	/**
	 * Everything a site can do whatever its position.
	 *
	 * @var array<int, string>
	 */
	public const ALWAYS = array(
		self::BUG_INTAKE,
		self::REQUEST_INTAKE,
		self::SALES,
		self::POINT_OF_CONTACT,
		self::DISCUSSION,
	);

	/**
	 * Everything a site can do at all.
	 *
	 * @var array<int, string>
	 */
	public const EVERYTHING = array(
		self::CHARGEABLE_WORK,
		self::BUG_INTAKE,
		self::REQUEST_INTAKE,
		self::SALES,
		self::POINT_OF_CONTACT,
		self::DISCUSSION,
	);

	/**
	 * Whether a site in this position may do this.
	 *
	 * @param string $state One of {@see Support::STATES}.
	 * @param string $what  One of {@see self::EVERYTHING}.
	 * @return bool
	 */
	public static function allows( string $state, string $what ): bool {
		if ( in_array( $what, self::ALWAYS, true ) ) {
			return true;
		}

		/*
		 * Chargeable work, and only on an active package. A lapsed site's hours
		 * are still on the ledger — COMM-4 freezes them rather than voiding
		 * them — and a rule written as "has a balance" would spend them.
		 */
		return self::CHARGEABLE_WORK === $what && Support::ACTIVE === $state;
	}

	/**
	 * A site's position and what it can do, in the shape the client is sent.
	 *
	 * @param string $state One of {@see Support::STATES}.
	 * @return array<string, mixed>
	 */
	public static function for_state( string $state ): array {
		$allowed = array();
		$refused = array();

		foreach ( self::EVERYTHING as $what ) {
			if ( self::allows( $state, $what ) ) {
				$allowed[] = $what;
				continue;
			}

			$refused[] = $what;
		}

		return array(
			'state'   => $state,
			'label'   => Support::label( $state ),
			'allowed' => $allowed,

			// Named rather than implied by absence. A client screen that had to
			// work out what was missing from what was present would be guessing,
			// and a screen that guesses shows a blank where a sentence belongs.
			'refused' => $refused,
		);
	}
}
