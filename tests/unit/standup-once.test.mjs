import test from 'node:test';
import assert from 'node:assert/strict';

// Each task shows once on the standup (Luke, 2026-11-20), under its most
// pressing reason; the others ride on that one row so nothing is hidden.

import { oncePerTask } from '../../src/standupOnce.mjs';

const work = (rule, id) => ({ rule, subject_type: 'work_item', subject_id: id, detail: {} });

test('a task due today and ready to ship shows once, as due today', () => {
  const shown = oncePerTask([work('awaiting-release', 'w1'), work('due-today', 'w1')]);
  assert.equal(shown.length, 1);
  assert.equal(shown[0].rule, 'due-today');
  assert.deepEqual(shown[0].also, ['awaiting-release']);
});

test('overdue comes before everything else', () => {
  const shown = oncePerTask([work('blocked', 'w1'), work('gate-unmet', 'w1'), work('overdue', 'w1')]);
  assert.equal(shown[0].rule, 'overdue');
  assert.deepEqual(shown[0].also, ['blocked', 'gate-unmet']);
});

test('not triaged is the least pressing reason but comes before untouched', () => {
  const shown = oncePerTask([work('stale', 'w1'), work('untriaged', 'w1'), work('returned', 'w1')]);
  assert.equal(shown[0].rule, 'returned');
  assert.deepEqual(shown[0].also, ['untriaged', 'stale']);
});

test('different tasks each keep their own row, in the order given', () => {
  const shown = oncePerTask([work('due-today', 'w2'), work('overdue', 'w1')]);
  assert.deepEqual(shown.map((one) => one.subject_id), ['w2', 'w1']);
  assert.deepEqual(shown.map((one) => one.also), [[], []]);
});

test('what the other reasons carry stays on the row, the main reason winning', () => {
  const due = { ...work('due-today', 'w1'), detail: { due: '2026-11-20', title: 'Launch' } };
  const pending = { ...work('gate-unmet', 'w1'), detail: { unmet: [{ id: 'G-1' }], title: 'Old title' } };
  const shown = oncePerTask([pending, due]);
  assert.deepEqual(shown[0].detail, { unmet: [{ id: 'G-1' }], title: 'Launch', due: '2026-11-20' });
});

test('anything that is not a task is left alone', () => {
  const request = { rule: 'request-waiting', subject_type: 'submission', subject_id: 's1', detail: {} };
  const over = { rule: 'over-committed', subject_type: 'user', subject_id: 'u1', detail: {} };
  assert.deepEqual(oncePerTask([request, over]), [request, over]);
});

test('the cards it was given are not changed', () => {
  const given = [work('awaiting-release', 'w1'), work('due-today', 'w1')];
  oncePerTask(given);
  assert.equal(given[1].also, undefined);
});
