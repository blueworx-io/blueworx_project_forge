import test from 'node:test';
import assert from 'node:assert/strict';

// #669. A client's hours as a statement: rows that open, run and close, and the
// CSV and file name made from them.

import { statementRows, csvText, fileName } from '../../src/hoursExport.mjs';

let at = 1_790_000_000;

function entry(event_type, hours, extra = {}) {
  at += 86_400;

  return {
    id: `hle_${at}`,
    event_type,
    hours,
    source_type: '',
    source_id: '',
    reason: '',
    about: '',
    occurred_at: at,
    when: new Date(at * 1000).toISOString().slice(0, 10),
    ...extra,
  };
}

function work(event_type, hours, id = 'wi_1', title = 'Build the header', milestone = { id: 'm1', title: 'Phase one' }) {
  return entry(event_type, hours, {
    source_type: 'work-item',
    source_id: id,
    about: title,
    task: { id, title, milestone, project: milestone ? { id: 'p1', title: 'Rebuild' } : null },
  });
}

function answer({ entries = [], opening = 0, closing = 0, held = 0, scope = null } = {}) {
  return {
    site: { id: 'cs_1', name: 'acme.com' },
    client: { id: 'cl_1', name: 'Acme Ltd' },
    from: '2026-03-01',
    to: '2026-03-31',
    opening,
    closing,
    held,
    first_entry_on: '',
    period_starts_on: null,
    scope,
    entries,
    items: [],
  };
}

test('all-work rows open, run and close, and the lines account for the difference', () => {
  const rows = statementRows(answer({
    opening: 0,
    closing: 23,
    held: -2,
    entries: [
      entry('allocation', 20),
      work('work-usage', -3),
      entry('top-up', 5),
      entry('adjustment', 1, { reason: 'Goodwill' }),
    ],
  }));

  assert.deepEqual(rows.map((r) => [r.what, r.hours, r.balance]), [
    ['Balance brought forward', null, 0],
    ['Package hours', 20, 20],
    ['Work done', -3, 17],
    ['Top-up', 5, 22],
    ['Adjustment', 1, 23],
    ['Balance carried forward', null, 23],
    ['Held for work in progress', -2, null],
    ['Total hours used', 3, null],
  ]);
  assert.deepEqual(Object.keys(rows[0]), ['date', 'what', 'task', 'milestone', 'project', 'note', 'hours', 'balance']);
  assert.equal(rows[0].date, '2026-02-28');
  assert.equal(rows[5].date, '2026-03-31');
  assert.equal(rows[2].task, 'Build the header');
  assert.equal(rows[2].milestone, 'Phase one');
  assert.equal(rows[2].project, 'Rebuild');
  assert.equal(rows[4].note, 'Goodwill');
  assert.equal(rows[2].note, '');
});

test('a refund folds under its task', () => {
  const rows = statementRows(answer({
    opening: 10,
    closing: 7.5,
    entries: [
      work('work-usage', -3),
      entry('adjustment', 0.5, { source_type: 'work-item', source_id: 'wi_1', reason: 'Hours lowered' }),
    ],
  }));
  const lines = rows.slice(1, -2);

  assert.equal(lines.length, 1);
  assert.equal(lines[0].what, 'Work done');
  assert.equal(lines[0].hours, -2.5);
  assert.equal(lines[0].note, 'Hours lowered');
});

test('a range with nothing in it still reconciles', () => {
  const rows = statementRows(answer({ opening: 10, closing: 10 }));

  assert.deepEqual(rows.map((r) => [r.what, r.balance]), [
    ['Balance brought forward', 10],
    ['Balance carried forward', 10],
    ['Total hours used', null],
  ]);
});

test('scope keeps only the work under the item and totals it', () => {
  const rows = statementRows(answer({
    scope: { id: 'm1', title: 'Phase one', level: 'milestone' },
    entries: [work('work-usage', -1, 'wi_1', 'One'), work('work-usage', -2, 'wi_2', 'Two')],
  }));

  assert.deepEqual(rows.map((r) => r.what), ['Work done', 'Work done', 'Total hours used']);
  assert.equal(rows[2].hours, 3);
  assert.ok(rows.every((r) => null === r.balance));
});

test('the scope total counts hours used, not hours still held', () => {
  const rows = statementRows(answer({
    scope: { id: 'm1', title: 'Phase one', level: 'milestone' },
    entries: [
      work('work-usage', -1, 'wi_1', 'One'),
      work('work-usage', -2, 'wi_2', 'Two'),
      work('work-reservation', -1, 'wi_2', 'Two'),
    ],
  }));

  assert.equal(rows[rows.length - 1].what, 'Total hours used');
  assert.equal(rows[rows.length - 1].hours, 3);
});

test('cells with commas and quotes stay one cell', () => {
  const text = csvText(statementRows(answer({
    opening: 5,
    closing: 2,
    entries: [work('work-usage', -3, 'wi_1', 'Fix "header", mobile')],
  })));

  assert.ok(text.includes('"Fix ""header"", mobile"'));
});

test('the file is named for the client, site, range and scope', () => {
  const a = answer({ scope: { id: 'm1', title: 'Phase One!', level: 'milestone' } });

  assert.equal(fileName(a), 'acme-ltd-acme-com-hours-2026-03-01-to-2026-03-31-phase-one.csv');
  assert.equal(fileName(answer()), 'acme-ltd-acme-com-hours-2026-03-01-to-2026-03-31.csv');
});

test('the text starts with a byte-order mark and uses CRLF', () => {
  const text = csvText(statementRows(answer({ opening: 1, closing: 1 })));

  assert.ok(text.startsWith('﻿Date,What,Task,Milestone,Project,Note,Hours,Balance after\r\n'));
  assert.ok(text.includes('\r\n2026-02-28,Balance brought forward,,,,,,1.00\r\n'));
  assert.ok(!/[^\r]\n/.test(text));
});

test('a meeting row names the meeting in the Task column', () => {
  const meeting = entry('meeting-usage', -1, { source_type: 'meeting-occurrence', source_id: 'mtg_1', about: 'Weekly call on 2026-03-05' });
  const rows = statementRows(answer({ entries: [meeting], scope: null }));
  const line = rows.find((r) => 'Meeting held' === r.what);

  assert.equal(line.task, 'Weekly call on 2026-03-05');
});

test('a refund on its own reads as hours given back, with its reason', () => {
  const refund = entry('adjustment', 0.5, { source_type: 'work-item', source_id: 'wi_9', about: 'Build the header', reason: 'Scope reduced' });
  const rows = statementRows(answer({ entries: [refund], scope: { id: 'p1', title: 'Rebuild', level: 'project' } }));
  const line = rows.find((r) => 'Hours given back' === r.what);

  assert.equal(rows.length, 2);
  assert.equal(line.hours, 0.5);
  assert.equal(line.note, 'Scope reduced');
});

test('text that a spreadsheet would run as a formula is made plain', () => {
  const rows = [
    { date: '2026-03-01', what: 'Work done', task: '=HYPERLINK("x")', milestone: '', project: '', note: '', hours: 1, balance: 1 },
    { date: '2026-03-02', what: 'Work done', task: '- fix header', milestone: '', project: '', note: '', hours: -1, balance: 0 },
  ];
  const text = csvText(rows);

  assert.ok(text.includes(`,"'=HYPERLINK(""x"")",`));
  assert.ok(text.includes(",'- fix header,"));
  assert.ok(text.includes(',-1.00,'));
});
