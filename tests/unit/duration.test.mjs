import test from 'node:test';
import assert from 'node:assert/strict';

// #387. Capacity shows time the way tasks do: "10 min", "1h 30m", never
// 0.17h. Hours are stored to two decimal places, so ten minutes arrives as
// 0.17 and has to come back out as ten minutes.

import { durationLabel } from '../../src/duration.mjs';

test('under an hour reads in minutes', () => {
  assert.equal(durationLabel(0.17), '10 min');
  assert.equal(durationLabel(0.08), '5 min');
  assert.equal(durationLabel(0.58), '35 min');
  assert.equal(durationLabel(0.5), '30 min');
});

test('whole hours read as hours', () => {
  assert.equal(durationLabel(1), '1h');
  assert.equal(durationLabel(2), '2h');
  assert.equal(durationLabel(40), '40h');
});

test('anything else reads as hours and minutes', () => {
  assert.equal(durationLabel(1.5), '1h 30m');
  assert.equal(durationLabel(2.25), '2h 15m');
  assert.equal(durationLabel(1.17), '1h 10m');
});

test('rounds to the nearest five minutes', () => {
  assert.equal(durationLabel(0.99), '1h');
  assert.equal(durationLabel(0.34), '20 min');
});

test('nothing is nought, and a shortfall keeps its sign', () => {
  assert.equal(durationLabel(0), '0h');
  assert.equal(durationLabel(0.01), '0h');
  assert.equal(durationLabel(-1.5), '-1h 30m');
  assert.equal(durationLabel(-0.17), '-10 min');
});
