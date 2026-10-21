/**
 * A site's ledger as people read it (#568): one line per task or meeting.
 *
 * The ledger itself is never rewritten — a task that is planned, started,
 * sent back and started again leaves a reserved, released and done entry
 * each time, and every one of them stays on the record. Read one by one
 * they look like the same work charged over and over. Folded per task they
 * say what the client wants to know: what it used, and what it still holds.
 *
 * Each folded line carries every entry it came from (`history`), and its
 * hours are those entries added up, so the lines still sum to the balance.
 * Package hours, top-ups, adjustments, expiries and carry-overs are not
 * about one piece of work and stay as lines of their own.
 *
 * Plain JavaScript with no DOM, so it can be tested with node alone.
 */

/** The event types that fold, by what they are about. */
const KINDS = {
  'work-reservation': 'work',
  'work-release': 'work',
  'work-usage': 'work',
  'meeting-reservation': 'meeting',
  'meeting-release': 'meeting',
  'meeting-usage': 'meeting',
};

/** Two decimal places, as the ledger stores hours. */
function round(value) {
  return Math.round(value * 100) / 100;
}

/**
 * What a folded line is now, in words.
 *
 * @param {'work'|'meeting'} kind
 * @param {number} used Hours spent, as a size.
 * @param {number} held Hours still reserved, as a size.
 * @returns {string}
 */
function labelFor(kind, used, held) {
  if ('meeting' === kind) {
    if (0 < used) {
      return 0 < held ? 'Meeting held, more reserved' : 'Meeting held';
    }

    return 0 < held ? 'Meeting reserved' : 'Meeting released';
  }

  if (0 < used) {
    return 0 < held ? 'Work done, more reserved' : 'Work done';
  }

  return 0 < held ? 'Work reserved' : 'Work released';
}

/**
 * The ledger's entries, oldest first, as the lines to show.
 *
 * A line that is not folded is the entry itself with an empty history. A
 * folded line has `key`, `kind`, `label`, `used`, `held`, `hours` (signed,
 * what it takes off the balance), `about`, `when` (its latest entry's day)
 * and `history` (its entries, oldest first). Lines are ordered by their
 * latest entry, so a task sits where it last moved.
 *
 * @param {Array<Record<string, any>>} entries
 * @returns {Array<Record<string, any>>}
 */
export function ledgerLines(entries) {
  const lines = [];
  const folded = new Map();

  entries.forEach((entry, index) => {
    const kind = KINDS[entry.event_type];

    if (!kind || !entry.source_id) {
      lines.push({ ...entry, key: entry.id, history: [], last: entry.occurred_at, order: index });

      return;
    }

    const key = `${entry.source_type}:${entry.source_id}`;
    let line = folded.get(key);

    if (!line) {
      line = { key, id: key, kind, source_type: entry.source_type, source_id: entry.source_id, about: '', reserved: 0, used: 0, history: [] };
      folded.set(key, line);
      lines.push(line);
    }

    const size = Math.abs(Number(entry.hours) || 0);

    if (entry.event_type.endsWith('-reservation')) {
      line.reserved += size;
    } else if (entry.event_type.endsWith('-release')) {
      line.reserved -= size;
    } else {
      line.used += size;
    }

    line.about = line.about || entry.about || '';
    line.history.push(entry);
    line.when = entry.when;
    line.last = entry.occurred_at;
    line.order = index;
  });

  for (const line of folded.values()) {
    const held = round(Math.max(0, line.reserved));

    line.used = round(line.used);
    line.held = held;
    line.hours = round(line.history.reduce((total, entry) => total + (Number(entry.hours) || 0), 0));
    line.label = labelFor(line.kind, line.used, held);
    delete line.reserved;
  }

  return lines.sort((a, b) => a.last - b.last || a.order - b.order);
}
