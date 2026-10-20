/**
 * The weeks a release runs, as people say them (2026-10-20).
 *
 * Plain JavaScript so the unit tests can load it, as alphabetical.mjs is.
 */

/**
 * The ISO-8601 week of a date.
 *
 * @param {string} date A Y-m-d date.
 * @returns {number} The week, 1 to 53.
 */
export function isoWeek(date) {
  const [y, m, d] = date.slice(0, 10).split('-').map(Number);
  const day = new Date(Date.UTC(y, m - 1, d));
  // Move to the Thursday of this week; its year is the ISO year.
  day.setUTCDate(day.getUTCDate() + 4 - (day.getUTCDay() || 7));
  const yearStart = Date.UTC(day.getUTCFullYear(), 0, 1);

  return Math.ceil(((day.getTime() - yearStart) / 86400000 + 1) / 7);
}

/**
 * A release's weeks: "Week 27", "Weeks 27 → 30", or nothing without dates.
 *
 * @param {string} start Y-m-d, or ''.
 * @param {string} end Y-m-d, or ''.
 * @returns {string}
 */
export function releaseWeeks(start, end) {
  const from = start ? isoWeek(start) : 0;
  const to = end ? isoWeek(end) : 0;

  if (!from && !to) return '';
  if (!from || !to || from === to) return `Week ${from || to}`;

  return `Weeks ${from} → ${to}`;
}

/**
 * The weeks of a release item: its own planned dates, else the dates its
 * work adds up to.
 *
 * @param {{planned_start?: string, planned_due?: string, derived_start?: string, derived_due?: string}} item
 * @returns {string}
 */
export function itemWeeks(item) {
  const start = item.planned_start || item.derived_start || '';
  const end = item.planned_due || item.derived_due || '';

  return releaseWeeks(start, end);
}
