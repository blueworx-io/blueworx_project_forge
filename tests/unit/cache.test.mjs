import test from 'node:test';
import assert from 'node:assert/strict';

// The session cache behind api(): what a screen gets back at once, when a
// re-check is due, and what a write throws away. Pure, so it can be argued
// with here rather than through a browser.

import {
  announce,
  clear,
  forget,
  newestAt,
  read,
  readsPath,
  recheckSoon,
  sameAnswer,
  shouldRevalidate,
  subscribe,
  touch,
  write,
} from '../../src/cache.mjs';

test.beforeEach(() => clear());

test('a written answer is read back, with when it arrived', () => {
  write('/a', { ok: true }, 1000);
  assert.deepEqual(read('/a', 1000), { value: { ok: true }, at: 1000 });
});

test('an answer older than half an hour is as good as absent', () => {
  write('/a', { ok: true }, 0);
  assert.equal(read('/a', 30 * 60 * 1000 - 1) !== undefined, true);
  assert.equal(read('/a', 30 * 60 * 1000 + 1), undefined);
});

// #701. A tab left open re-checked a slow answer every five seconds, for ever.
test('a re-check is due once, then not again for three minutes', () => {
  assert.equal(shouldRevalidate('/a', 1000), true);
  assert.equal(shouldRevalidate('/a', 6001), false);
  assert.equal(shouldRevalidate('/a', 1000 + 3 * 60 * 1000 - 1), false);
  assert.equal(shouldRevalidate('/a', 1000 + 3 * 60 * 1000 + 1), true);
});

test('nothing is re-checked while the tab is hidden', () => {
  assert.equal(shouldRevalidate('/a', 1000, false), false);
  // Not marked as started either, so it is due the moment the tab is back.
  assert.equal(shouldRevalidate('/a', 2000, true), true);
});

test('moving to another screen makes the next read re-check at once', () => {
  assert.equal(shouldRevalidate('/a', 1000), true);
  recheckSoon();
  assert.equal(shouldRevalidate('/a', 2000), true);
});

test('the time an answer was worked out is not a change', () => {
  assert.equal(sameAnswer({ generated: '10:00', unread: 2 }, { generated: '10:05', unread: 2 }), true);
  assert.equal(sameAnswer({ generated: '10:00', unread: 2 }, { generated: '10:00', unread: 3 }), false);
  assert.equal(sameAnswer([1, 2], [1, 2]), true);
  assert.equal(sameAnswer(null, { generated: 'x' }), false);
});

test('forgetting one path leaves the rest', () => {
  write('/a', 1, 1000);
  write('/b', 2, 1000);
  forget('/a');
  assert.equal(read('/a', 1000), undefined);
  assert.deepEqual(read('/b', 1000), { value: 2, at: 1000 });
});

test('a screen reads a path, its queries and what sits under it, and nothing else', () => {
  assert.equal(readsPath(['/standup'], '/standup'), true);
  assert.equal(readsPath(['/standup'], '/standup?view=week'), true);
  assert.equal(readsPath(['/work-items'], '/work-items/wi_1'), true);
  assert.equal(readsPath(['/work-items'], '/work-items-all'), false);
  assert.equal(readsPath(['/signals'], '/standup'), false);
  // A screen that has not said what it reads hears everything.
  assert.equal(readsPath(undefined, '/anything'), true);
});

test('touching moves the time without changing the answer', () => {
  write('/a', { n: 1 }, 1000);
  touch('/a', 2000);
  assert.deepEqual(read('/a', 2000), { value: { n: 1 }, at: 2000 });
});

test('the newest time is the latest fetch, and nothing when empty', () => {
  assert.equal(newestAt(), 0);
  write('/a', 1, 1000);
  write('/b', 2, 3000);
  write('/c', 3, 2000);
  assert.equal(newestAt(), 3000);
  clear();
  assert.equal(newestAt(), 0);
});

test('subscribers hear an announcement, and whether it changed anything, until they leave', () => {
  const heard = [];
  const leave = subscribe((path, changed) => heard.push([path, changed]));

  announce('/a');
  announce('/c', false);
  leave();
  announce('/b');

  assert.deepEqual(heard, [['/a', true], ['/c', false]]);
});

test('clearing forgets every answer but keeps the subscribers', () => {
  const heard = [];
  subscribe((path) => heard.push(path));
  write('/a', 1, 1000);
  clear();

  assert.equal(read('/a', 1000), undefined);
  announce('/a');
  assert.deepEqual(heard, ['/a']);
});
