/**
 * A client's support hours as a statement (#669): rows, CSV text and a file name.
 *
 * The server answers JSON; this turns it into what the client opens in a
 * spreadsheet. Plain JavaScript with no DOM, so it can be tested with node alone.
 */

import { ledgerLines } from './ledgerLines.mjs';

const HEADER = ['Date', 'What', 'Task', 'Milestone', 'Project', 'Note', 'Hours', 'Balance after'];

/** What a lone ledger entry is, in words (the Support screen's, with its top-up as "Top-up"). */
const ENTRY_WORDS = {
  'allocation': 'Package hours',
  'top-up': 'Top-up',
  'rollover': 'Carried over',
  'expiry': 'Expired',
  'adjustment': 'Adjustment',
};

/** Two decimal places, as the ledger stores hours. */
function round(value) {
  return Math.round(value * 100) / 100;
}

/** The day before a `YYYY-MM-DD` day. */
function dayBefore(day) {
  const date = new Date(`${day}T00:00:00Z`);

  date.setUTCDate(date.getUTCDate() - 1);

  return date.toISOString().slice(0, 10);
}

/**
 * The screen's words for a folded line or a lone entry.
 *
 * @param {Record<string, any>} line
 * @returns {string}
 */
export function whatFor(line) {
  if (line.label) {
    return line.label;
  }

  return ENTRY_WORDS[line.event_type] ?? line.event_type ?? '';
}

/**
 * The statement's rows, in order.
 *
 * @param {Record<string, any>} answer The export route's JSON.
 * @returns {Array<{date: string, what: string, task: string, milestone: string, project: string, note: string, hours: number|null, balance: number|null}>}
 */
export function statementRows(answer) {
  const scoped = Boolean(answer.scope);
  const row = (date, what, extra = {}) => ({ date, what, task: '', milestone: '', project: '', note: '', hours: null, balance: null, ...extra });
  const rows = [];
  let balance = Number(answer.opening) || 0;

  if (!scoped) {
    rows.push(row(dayBefore(answer.from), 'Balance brought forward', { balance: round(balance) }));
  }

  const lines = ledgerLines(answer.entries || []);

  for (const line of lines) {
    const hours = round(Number(line.hours) || 0);
    const detail = line.history.find((entry) => entry.task)?.task;

    const refund = 'work' === line.kind && 0 >= (Number(line.used) || 0) && 0 < hours;
    const reasons = [...new Set(line.history.filter((entry) => 'adjustment' === entry.event_type).map((entry) => entry.reason).filter(Boolean))].join('; ');

    balance += hours;
    rows.push(row(line.when, refund ? 'Hours given back' : whatFor(line), {
      task: detail?.title ?? line.about ?? '',
      milestone: detail?.milestone?.title ?? '',
      project: detail?.project?.title ?? '',
      note: line.history.length ? reasons : (line.reason ?? ''),
      hours,
      balance: scoped ? null : round(balance),
    }));
  }

  // #677. Every statement ends on what was used: work done and meetings held.
  const total = row(answer.to, 'Total hours used', { hours: round(lines.reduce((sum, line) => sum + (Number(line.used) || 0), 0)) });

  if (scoped) {
    rows.push(total);

    return rows;
  }

  rows.push(row(answer.to, 'Balance carried forward', { balance: round(Number(answer.closing) || 0) }));

  if (0 !== (Number(answer.held) || 0)) {
    rows.push(row(answer.to, 'Held for work in progress', { hours: round(Number(answer.held)) }));
  }

  rows.push(total);

  return rows;
}

function cell(value, guard = false) {
  let text = null === value || undefined === value ? '' : String(value);

  // A spreadsheet runs a cell that starts with one of these as a formula.
  if (guard && /^[=+\-@\t\r]/.test(text)) {
    text = `'${text}`;
  }

  return /[",\r\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
}

/**
 * The rows as CSV text, ready to save: UTF-8 byte-order mark, CRLF endings.
 *
 * @param {ReturnType<typeof statementRows>} rows
 * @returns {string}
 */
export function csvText(rows) {
  const number = (value) => (null === value ? '' : value.toFixed(2));
  const lines = rows.map((r) => [cell(r.date), cell(r.what, true), cell(r.task, true), cell(r.milestone, true), cell(r.project, true), cell(r.note, true), cell(number(r.hours)), cell(number(r.balance))].join(','));

  return `\uFEFF${[HEADER.join(','), ...lines].join('\r\n')}\r\n`;
}

/**
 * The file's name: client, site, range and any scope, lower case.
 *
 * @param {Record<string, any>} answer
 * @returns {string}
 */
export function fileName(answer) {
  const parts = [answer.client?.name, answer.site?.name, 'hours', answer.from, 'to', answer.to];

  if (answer.scope?.title) {
    parts.push(answer.scope.title);
  }

  const slug = parts.join(' ').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');

  return `${slug}.csv`;
}
