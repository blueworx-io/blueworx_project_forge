import test from 'node:test';
import assert from 'node:assert/strict';

// 2026-10-20. A release reads as the weeks it runs, not as two dates.

import { isoWeek, releaseWeeks } from '../../src/weeks.mjs';

test('isoWeek follows ISO-8601, including the year edges', () => {
  assert.equal(isoWeek('2026-06-30'), 27);
  assert.equal(isoWeek('2026-01-01'), 1);
  assert.equal(isoWeek('2027-01-01'), 53);
});

test('releaseWeeks gives a range when the weeks differ', () => {
  assert.equal(releaseWeeks('2026-06-30', '2026-07-21'), 'Weeks 27 → 30');
});

test('releaseWeeks gives one week when both dates share it', () => {
  assert.equal(releaseWeeks('2026-06-22', '2026-06-26'), 'Week 26');
});

test('releaseWeeks uses whichever single date is set', () => {
  assert.equal(releaseWeeks('', '2026-07-21'), 'Week 30');
  assert.equal(releaseWeeks('2026-07-21', ''), 'Week 30');
});

test('releaseWeeks is empty with no dates', () => {
  assert.equal(releaseWeeks('', ''), '');
});
