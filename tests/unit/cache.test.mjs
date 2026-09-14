import test from 'node:test';
import assert from 'node:assert/strict';

// The session cache behind api(): what a screen gets back at once, when a
// re-check is due, and what a write throws away. Pure, so it can be argued
// with here rather than through a browser.

import {
  announce,
  clear,
  newestAt,
  read,
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

test('a re-check is due once, then not again for five seconds', () => {
  assert.equal(shouldRevalidate('/a', 1000), true);
  assert.equal(shouldRevalidate('/a', 4000), false);
  assert.equal(shouldRevalidate('/a', 6001), true);
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
