import test from 'node:test';
import assert from 'node:assert/strict';

// #568. A site's ledger shows one line per task or meeting, with what it used
// and still holds, and its reserved / released / done history folded beneath
// it. Package hours, top-ups and adjustments stay as lines of their own.

import { ledgerLines } from '../../src/ledgerLines.mjs';

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

const work = (event_type, hours, id = 'wrk_design') => entry(event_type, hours, { source_type: 'work-item', source_id: id, about: 'Design Updates' });

test('a task reserved, released and done twice over reads as one line of hours used', () => {
  const entries = [
    entry('allocation', 20),
    work('work-reservation', -2.25),
    work('work-release', 2.25),
    work('work-usage', -2.25),
    // Sent back to Design and forward again.
    work('work-reservation', -2.25),
    work('work-release', 2.25),
  ];

  const lines = ledgerLines(entries);

  assert.equal(lines.length, 2);
  assert.equal(lines[0].event_type, 'allocation');

  const task = lines[1];
  assert.equal(task.label, 'Work done');
  assert.equal(task.used, 2.25);
  assert.equal(task.held, 0);
  assert.equal(task.hours, -2.25);
  assert.equal(task.about, 'Design Updates');
  assert.equal(task.history.length, 5);
  assert.equal(task.when, entries[5].when);
});

test('the lines add up to the same balance as the entries', () => {
  const entries = [
    entry('allocation', 20),
    work('work-reservation', -3, 'wrk_a'),
    work('work-reservation', -1.5, 'wrk_b'),
    work('work-release', 3, 'wrk_a'),
    work('work-usage', -3, 'wrk_a'),
    entry('adjustment', -2, { reason: 'Written off' }),
  ];

  const sum = (rows) => Math.round(rows.reduce((total, row) => total + row.hours, 0) * 100) / 100;

  assert.equal(sum(ledgerLines(entries)), sum(entries));
});

test('work still planned reads as reserved, and work given back as released', () => {
  const held = ledgerLines([work('work-reservation', -4, 'wrk_held')]);
  assert.equal(held[0].label, 'Work reserved');
  assert.equal(held[0].held, 4);
  assert.equal(held[0].hours, -4);

  const back = ledgerLines([work('work-reservation', -4, 'wrk_back'), work('work-release', 4, 'wrk_back')]);
  assert.equal(back[0].label, 'Work released');
  assert.equal(back[0].hours, 0);

  const both = ledgerLines([
    work('work-reservation', -2, 'wrk_more'),
    work('work-release', 2, 'wrk_more'),
    work('work-usage', -2, 'wrk_more'),
    work('work-reservation', -1, 'wrk_more'),
  ]);
  assert.equal(both[0].label, 'Work done, more reserved');
  assert.equal(both[0].used, 2);
  assert.equal(both[0].held, 1);
});

test('meetings fold the same way, and lines without a source stay as they are', () => {
  const meeting = (event_type, hours) => entry(event_type, hours, { source_type: 'meeting-occurrence', source_id: 'mtg_one', about: 'Weekly check-in on 2026-09-26' });
  const lines = ledgerLines([
    meeting('meeting-reservation', -1),
    entry('top-up', 5, { reason: 'Five more' }),
    meeting('meeting-release', 1),
    meeting('meeting-usage', -1),
  ]);

  assert.deepEqual(lines.map((line) => line.label ?? line.event_type), ['top-up', 'Meeting held']);
  assert.equal(lines[1].history.length, 3);
});

test('each line sits where its latest entry happened', () => {
  const lines = ledgerLines([
    work('work-reservation', -2, 'wrk_early'),
    entry('top-up', 5),
    work('work-release', 2, 'wrk_early'),
    work('work-usage', -2, 'wrk_early'),
    work('work-reservation', -1, 'wrk_late'),
  ]);

  assert.deepEqual(lines.map((line) => line.source_id || line.event_type), ['top-up', 'wrk_early', 'wrk_late']);
});
