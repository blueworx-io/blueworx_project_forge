<?php
/**
 * What state an onboarding step is in.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Onboarding;

/**
 * #161's statuses, and the one that is not a status.
 *
 * The data model lists eight. Seven of them are recorded: somebody moves the
 * step into them, and the move is an entry in its history.
 *
 * **Overdue is the eighth and is not stored.** It is a fact about today's date
 * rather than about the step. Storing it would need something sweeping every
 * step every night, and the answer would be wrong for up to a day at a time —
 * which is the same mistake #164 exists to stop being made about completion.
 * A figure that has to be maintained to stay true is a figure that is
 * eventually false.
 *
 * So it is asked instead, here, in one place, so that the client's page and the
 * studio's board can never disagree about whether the same step is late.
 */
final class Statuses {

	/**
	 * Nobody has begun it.
	 */
	public const NOT_STARTED = 'not-started';

	/**
	 * Somebody is doing it.
	 */
	public const IN_PROGRESS = 'in-progress';

	/**
	 * Handed over, waiting to be looked at.
	 */
	public const SUBMITTED = 'submitted';

	/**
	 * Looked at, and sent back with something to change (ONB-2).
	 */
	public const RETURNED = 'returned';

	/**
	 * Done, and somebody said so (ONB-2).
	 */
	public const APPROVED = 'approved';

	/**
	 * It does not apply to this client, decided with a reason and only where
	 * the template permits it (ONB-2).
	 */
	public const NOT_APPLICABLE = 'not-applicable';

	/**
	 * Stopped on something outside the owner's control, with a reason.
	 */
	public const BLOCKED = 'blocked';

	/**
	 * Every status a step can be put into.
	 *
	 * @var array<int, string>
	 */
	public const ALL = array(
		self::NOT_STARTED,
		self::IN_PROGRESS,
		self::SUBMITTED,
		self::RETURNED,
		self::APPROVED,
		self::NOT_APPLICABLE,
		self::BLOCKED,
	);

	/**
	 * The two that finish a step.
	 *
	 * Both mean there is nothing left to do, and neither counts against anybody
	 * afterwards. Kept as a list rather than as two comparisons because
	 * completion (#164) and the launch gate (#166) both ask this same question,
	 * and two places asking it two ways is how they come to disagree.
	 *
	 * @var array<int, string>
	 */
	public const SETTLED = array(
		self::APPROVED,
		self::NOT_APPLICABLE,
	);

	/**
	 * Whether this is one of the seven.
	 *
	 * @param string $status Status name.
	 * @return bool
	 */
	public static function exists( string $status ): bool {
		return in_array( $status, self::ALL, true );
	}

	/**
	 * Whether a step is late.
	 *
	 * Late is "the day has been and gone", not "the day is today" — somebody
	 * with a step due today still has today to do it in.
	 *
	 * Finished work is never late, however long it took. It is done, and a
	 * board still shouting about it is a board people stop reading. Blocked
	 * work does still go overdue, because the date passing is exactly what
	 * turns an unremarkable blocker into one worth surfacing.
	 *
	 * @param string $status Where the step is.
	 * @param string $due_on YYYY-MM-DD, or '' where it has no date.
	 * @param string $today  YYYY-MM-DD.
	 * @return bool
	 */
	public static function is_overdue( string $status, string $due_on, string $today ): bool {
		if ( '' === $due_on || in_array( $status, self::SETTLED, true ) ) {
			return false;
		}

		return $due_on < $today;
	}
}
