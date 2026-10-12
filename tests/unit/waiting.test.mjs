import test from 'node:test';
import assert from 'node:assert/strict';

// #496. A request's waiting time counts working days only: Saturdays and
// Sundays never add to it, and one sent at the weekend starts on Monday.
// Dates are built in local time, as the screen reads them.

import { workingDaysBetween } from '../../src/waiting.mjs';

const at = (day, hour = 10) => new Date(2026, 9, day, hour).getTime() / 1000; // October 2026; the 9th is a Friday.

test('the same day is nought', () => {
  assert.equal(workingDaysBetween(at(5, 9), at(5, 17)), 0);
});

test('sent Friday, still open on Monday, is one day', () => {
  assert.equal(workingDaysBetween(at(9), at(12)), 1);
});

test('sent at the weekend starts counting from Monday', () => {
  assert.equal(workingDaysBetween(at(10), at(12)), 0, 'Saturday to Monday');
  assert.equal(workingDaysBetween(at(11), at(13)), 1, 'Sunday to Tuesday');
});

test('a fortnight is ten working days', () => {
  assert.equal(workingDaysBetween(at(5), at(19)), 10);
});

test('weekdays in a row count as they always did', () => {
  assert.equal(workingDaysBetween(at(5), at(7)), 2);
});
