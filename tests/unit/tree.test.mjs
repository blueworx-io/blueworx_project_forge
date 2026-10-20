import test from 'node:test';
import assert from 'node:assert/strict';

// #561. One tree of projects, releases and features, read the same way by the
// client profile, the project page and the release page.

import { buildTree, summary, topLevel } from '../../src/tree.mjs';

const item = (id, parent_id, work_type, extra = {}) => ({
  id,
  parent_id,
  level: 'task',
  work_type,
  title: id,
  stage: 'triage',
  terminal_outcome: '',
  archived: false,
  planned_start: '',
  planned_due: '',
  created_at: 0,
  ...extra,
});

test('parts and attached are split by type', () => {
  const release = item('r', '', 'feature', { level: 'milestone' });
  const tree = buildTree([release, item('f', 'r', 'feature'), item('b', 'r', 'bug')], 'r');

  assert.equal(tree.parts.length, 1);
  assert.equal(tree.attached.length, 1);
  assert.equal(tree.parts[0].item.id, 'f');
  assert.equal(tree.attached[0].id, 'b');
});

test('nesting is recursive', () => {
  const tree = buildTree([item('r', '', 'feature'), item('f', 'r', 'feature'), item('s', 'f', 'feature'), item('t', 's', 'task')], 'r');

  assert.equal(tree.parts[0].parts[0].item.id, 's');
  assert.equal(tree.parts[0].parts[0].attached[0].id, 't');
});

test('parts are ordered by start then title, attached by created order', () => {
  const tree = buildTree(
    [
      item('r', '', 'feature'),
      item('b', 'r', 'feature', { title: 'B', planned_start: '2026-11-02' }),
      item('a', 'r', 'feature', { title: 'A', planned_start: '2026-11-02' }),
      item('c', 'r', 'feature', { title: 'C', planned_start: '2026-10-01' }),
      item('x', 'r', 'bug', { created_at: 20 }),
      item('y', 'r', 'bug', { created_at: 10 }),
    ],
    'r'
  );

  assert.deepEqual(tree.parts.map((p) => p.item.id), ['c', 'a', 'b']);
  assert.deepEqual(tree.attached.map((i) => i.id), ['y', 'x']);
});

test('topLevel returns one level, by planned start', () => {
  const items = [
    item('p2', '', 'feature', { level: 'project', planned_start: '2026-12-01' }),
    item('p1', '', 'feature', { level: 'project', planned_start: '2026-11-01' }),
    item('m', '', 'feature', { level: 'milestone' }),
  ];

  assert.deepEqual(topLevel(items, 'project').map((i) => i.id), ['p1', 'p2']);
});

test('an empty project has no progress', () => {
  const project = item('p', '', 'feature', { level: 'project' });

  assert.deepEqual(summary(buildTree([project], project.id)), { total: 0, finished: 0, start: '', due: '', ongoing: true });
});

test('cancelled and archived children are not counted', () => {
  const items = [
    item('r', '', 'feature'),
    item('done', 'r', 'feature', { stage: 'completed' }),
    item('shipped', 'r', 'bug', { stage: 'released' }),
    item('open', 'r', 'feature'),
    item('dropped', 'r', 'feature', { terminal_outcome: 'cancelled' }),
    item('old', 'r', 'bug', { archived: true }),
  ];
  const result = summary(buildTree(items, 'r'));

  assert.equal(result.total, 3);
  assert.equal(result.finished, 2);
});

test('dates come from the planned dates, else the derived ones; no due means ongoing', () => {
  const dated = item('r', '', 'feature', { planned_start: '2026-11-02', planned_due: '2026-11-20' });
  const derived = item('d', '', 'feature', { derived_start: '2026-10-05', derived_due: '2026-10-30' });

  assert.deepEqual(summary(buildTree([dated], 'r')), { total: 0, finished: 0, start: '2026-11-02', due: '2026-11-20', ongoing: false });
  assert.equal(summary(buildTree([derived], 'd')).due, '2026-10-30');
});

test('counts on the item win once the server sends them', () => {
  const root = item('r', '', 'feature', { children_total: 4, children_finished: 1 });

  assert.deepEqual(summary(buildTree([root], 'r')), { total: 4, finished: 1, start: '', due: '', ongoing: true });
});
