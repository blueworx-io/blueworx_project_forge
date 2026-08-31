<?php
/**
 * What Forge tells a client about, and how one telling is told apart from another.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Notifications;

use Blueworx\Forge\Work\Stages;

/**
 * #172, NOTIF-3. The qualifying events, and the id that makes each one itself.
 *
 * **The id is worked out from what happened, not from when we noticed.** That
 * is the whole issue in one sentence. Every other id in Forge comes from
 * Tenancy\Ids and is random, because every other record is created once by one
 * caller who is right there. A notification is not: the same release can be
 * noticed by the studio saving it, by a client site syncing, by a retry after a
 * timeout, and by somebody refreshing the page — four callers, four moments,
 * one thing that happened. If the id were made at the moment of noticing there
 * would be four of them, and the client would get four emails.
 *
 * So the id is a function of the event: its kind, what it is about, and which
 * time round. Any caller computing it arrives at the same string, and the
 * primary key on the table then decides which one of them is first. Nothing has
 * to read before it writes, which is what makes it safe when two callers arrive
 * at once — see {@see Register::claim()}.
 *
 * **Which time round is part of the identity, deliberately.** Work that is
 * reopened and released again has genuinely been released twice, and the client
 * should hear about it twice. The cycle number (#113) is what separates them, so
 * a second release under a new cycle is a new event, and a replay of the first
 * release is still the first event. Leaving the cycle out would suppress the
 * second release for ever; using a timestamp instead would fail to suppress
 * anything at all.
 *
 * The kinds are a closed list. An event nobody defined is refused rather than
 * raised, because the thing at the other end of this is an email to a client.
 */
final class Events {

	/**
	 * Id prefix for a notification event.
	 */
	public const PREFIX = 'nev';

	/**
	 * A client asked us for something and we have it (§13.3).
	 */
	public const RECEIVED = 'request-received';

	/**
	 * Work is approved and ready to release.
	 *
	 * NOTIF-2: Completed still tells the client something, but it is not the
	 * final word. Telling somebody their work is done before it is live is the
	 * message that generates the support ticket, so this one is worded as ready
	 * rather than as finished.
	 */
	public const COMPLETED = 'work-completed';

	/**
	 * It is live. NOTIF-2 makes this the final confirmation, not Completed.
	 */
	public const RELEASED = 'work-released';

	/**
	 * The three events that send a client an email.
	 *
	 * @var array<int, string>
	 */
	public const ALL = array(
		self::RECEIVED,
		self::COMPLETED,
		self::RELEASED,
	);

	/**
	 * A record a notification can be about.
	 */
	public const WORK_ITEM = 'work_item';

	/**
	 * The other one: something a client sent in before it became work.
	 */
	public const SUBMISSION = 'submission';

	/**
	 * How many hex characters an id carries after its prefix.
	 *
	 * Twenty-six, matching Tenancy\Ids, so an event id pasted into a message
	 * looks like every other id in the product. What it does not carry is the
	 * time — an id made from the clock could not be recomputed later, which is
	 * the one thing this id has to be able to do.
	 */
	private const DIGITS = 26;

	/**
	 * Which stages tell the client something when work arrives in them.
	 *
	 * A map rather than two comparisons, because "which moves send an email" is
	 * a question somebody will ask of the code, and it should be answerable by
	 * reading one thing.
	 *
	 * @var array<string, string>
	 */
	private const FROM_STAGE = array(
		Stages::COMPLETED => self::COMPLETED,
		Stages::RELEASED  => self::RELEASED,
	);

	/**
	 * Whether this is one of the three.
	 *
	 * @param string $kind Event kind.
	 * @return bool
	 */
	public static function exists( string $kind ): bool {
		return in_array( $kind, self::ALL, true );
	}

	/**
	 * The event arriving in a stage raises, if any.
	 *
	 * @param string $stage The stage work has just entered.
	 * @return string The event kind, or '' where that stage tells nobody anything.
	 */
	public static function for_stage( string $stage ): string {
		return self::FROM_STAGE[ $stage ] ?? '';
	}

	/**
	 * The id this event has, and will have again next time anybody works it out.
	 *
	 * Returns '' for an event that is not one of the three, or that names
	 * nothing — rather than an id for a thing that cannot be sent. A caller
	 * holding '' has been told not to raise anything, which is a better answer
	 * than a well-formed id for nonsense.
	 *
	 * @param string $kind       One of {@see self::ALL}.
	 * @param string $subject_id The record it is about.
	 * @param int    $occurrence Which time round — the item's cycle, or 1.
	 * @return string
	 */
	public static function id_for( string $kind, string $subject_id, int $occurrence = 1 ): string {
		if ( ! self::exists( $kind ) || '' === $subject_id ) {
			return '';
		}

		/*
		 * A hash rather than the parts joined together. The parts would be
		 * readable, which is tempting, but they would also make the id as long
		 * as the longest subject id plus the longest kind, and an id has to fit
		 * a column. sha1 is the right tool here and not a security choice:
		 * nothing is being authenticated, and an id nobody can guess is not a
		 * property this needs — anybody who may see the event may see the item.
		 */
		$of = sha1( $kind . '|' . $subject_id . '|' . max( 1, $occurrence ) );

		return self::PREFIX . '_' . substr( $of, 0, self::DIGITS );
	}

	/**
	 * What kind of record an event of this kind is about.
	 *
	 * @param string $kind Event kind.
	 * @return string
	 */
	public static function subject_type( string $kind ): string {
		return self::RECEIVED === $kind ? self::SUBMISSION : self::WORK_ITEM;
	}
}
