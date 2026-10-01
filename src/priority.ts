/*
 * A task's priority, read the same way wherever it is shown: the colour of its
 * tag, and where it sorts among tasks due the same day (#483).
 */

/** Urgent reads red, high amber, normal blue, low grey. */
export const PRIORITY_TONE: Record< string, 'danger' | 'warn' | 'info' | 'neutral' > = {
  urgent: 'danger',
  high: 'warn',
  normal: 'info',
  low: 'neutral',
};

const RANK: Record< string, number > = { urgent: 0, high: 1, normal: 2, low: 3 };

/** Urgent first, then high, normal, low, and no priority last. */
export function priorityRank( priority: unknown ): number {
  return RANK[ String( priority ?? '' ) ] ?? 4;
}
