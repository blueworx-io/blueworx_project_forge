<?php
/**
 * How fresh what a client site is showing actually is.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client;

/**
 * The six states any read-through record on a client site can be in.
 *
 * These live in one place because more than one screen now depends on them, and
 * two screens disagreeing about what "stale" means is exactly the failure
 * ARCH-4 is written to prevent: a person looking at two tabs, one honest about
 * its age and one not.
 *
 * Five of the six are about age. The sixth, {@see self::STATE_REFUSED}, is not
 * about age at all and is the one that had to be added rather than folded into
 * the others: a studio that answers "there is no such work" has answered, and
 * treating that as an outage tells a client their connection is broken when in
 * fact they asked for something that is not theirs (#133, #134).
 */
final class Sync {

	/**
	 * Never connected to the studio.
	 */
	public const STATE_NOT_CONFIGURED = 'not_configured';

	/**
	 * Read from the studio just now.
	 */
	public const STATE_LIVE = 'live';

	/**
	 * Served from a copy still within the acceptable staleness window.
	 */
	public const STATE_CACHED = 'cached';

	/**
	 * Served from a copy that is past the window because the studio could not be
	 * reached to refresh it.
	 */
	public const STATE_STALE = 'stale';

	/**
	 * The studio could not be reached and there is nothing cached to fall back
	 * on.
	 */
	public const STATE_UNREACHABLE = 'unreachable';

	/**
	 * The studio was reached and said no.
	 *
	 * Not a network problem and not an old copy: the connection worked, and the
	 * answer was a refusal — this site asked for something that is not there,
	 * or not theirs. Those two are deliberately one state here for the same
	 * reason they are one answer on the studio: telling them apart is telling a
	 * client which ids are real on somebody else's tenant (D-1, D-2).
	 *
	 * It is a state of its own because the alternative is worse in both
	 * directions. Called an outage, it tells a client their site is broken when
	 * it is working perfectly. Called a cached copy, it shows them a record the
	 * studio has just said they may not have.
	 */
	public const STATE_REFUSED = 'refused';

	/**
	 * Whether a state means what is on screen may be out of date.
	 *
	 * A refusal is not stale. There is nothing on screen for it to be out of
	 * date about — the answer was "no", and "no, as of an hour ago" is not a
	 * thing anybody needs told.
	 *
	 * @param string $state One of the STATE_ constants.
	 * @return bool
	 */
	public static function is_stale( string $state ): bool {
		return self::STATE_STALE === $state || self::STATE_UNREACHABLE === $state;
	}

	/**
	 * Whether the studio answered at all.
	 *
	 * The question a screen asks before it decides which kind of nothing to
	 * show. "We could not ask" and "we asked and were told no" want completely
	 * different sentences, and a screen that cannot tell them apart writes the
	 * wrong one half the time.
	 *
	 * @param string $state One of the STATE_ constants.
	 * @return bool
	 */
	public static function is_refusal( string $state ): bool {
		return self::STATE_REFUSED === $state;
	}
}
