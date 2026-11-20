/**
 * Each task once on the standup (Luke, 2026-11-20).
 *
 * The rules report everything true about a task, so a task can come back as
 * due today and ready to ship at once. The standup shows it once, under the
 * most pressing of those, in the rules' own order: late first, then due, then
 * stopped, then waiting on a requirement or a person. The other reasons ride
 * on that row as `also`, so nothing true is hidden. When the task moves on,
 * the next list is worked out afresh, so it shows under whatever still holds.
 *
 * Plain JavaScript with no DOM, so it can be tested with node alone.
 */

/** The work rules, most pressing first: Standup\Rules::ALL's order. */
const RANK = ['overdue', 'due-today', 'blocked', 'gate-unmet', 'awaiting-review', 'awaiting-release', 'returned', 'stale'];

const rankOf = (rule) => {
  const at = RANK.indexOf(rule);

  return -1 === at ? RANK.length : at;
};

/**
 * The cards with each task's collapsed into one. Every other card, and the
 * order the tasks first appear in, is left as it was.
 *
 * @param {Array<{rule: string, subject_type: string, subject_id: string}>} cards
 * @returns {Array<object>} New cards; those given are not changed.
 */
export function oncePerTask(cards) {
  const byTask = new Map();

  for (const card of cards) {
    if ('work_item' !== card.subject_type) continue;
    const held = byTask.get(card.subject_id) ?? [];
    held.push(card);
    byTask.set(card.subject_id, held);
  }

  const shown = [];
  const done = new Set();

  for (const card of cards) {
    if ('work_item' !== card.subject_type) {
      shown.push(card);
      continue;
    }

    if (done.has(card.subject_id)) continue;
    done.add(card.subject_id);

    const all = [...byTask.get(card.subject_id)].sort((a, b) => rankOf(a.rule) - rankOf(b.rule));
    // What each reason carries stays with the row (the outstanding list a
    // Pending card has, say), the main reason's own winning where they differ.
    const detail = Object.assign({}, ...[...all].reverse().map((one) => one.detail ?? {}));
    shown.push({ ...all[0], detail, also: all.slice(1).map((one) => one.rule) });
  }

  return shown;
}
