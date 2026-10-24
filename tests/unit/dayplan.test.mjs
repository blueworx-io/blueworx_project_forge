import test from 'node:test';
import assert from 'node:assert/strict';

// #620. The day planner's placing rules, proven without a browser.

import { DAY_START, DAY_END, planDay, defaultOrder, minuteOfDay, moveInOrder, bringUp, sendDown, hoursText } from '../../src/dayplan.mjs';

const h = (n) => DAY_START + n * 60; // minutes, n hours after 7am
const task = (id, hours, extra = {}) => ({ id, hours, priority: 'normal', due: 0, index: 0, ...extra });
const ids = (segments, type) => segments.filter((s) => s.type === type).map((s) => `${s.id}@${s.start}-${s.end}`);

test('the day runs 7am to 7pm', () => {
  assert.equal(DAY_START, 420);
  assert.equal(DAY_END, 1140);
});

test('tasks fill the day from the start, highest priority first', () => {
  const plan = planDay({
    now: 0,
    meetings: [],
    tasks: [task('low', 1, { priority: 'low' }), task('urgent', 2, { priority: 'urgent' }), task('high', 1, { priority: 'high' })],
  });
  assert.deepEqual(ids(plan.segments, 'task'), [`urgent@${h(0)}-${h(2)}`, `high@${h(2)}-${h(3)}`, `low@${h(3)}-${h(4)}`]);
});

test('within a priority, overdue first, then due earliest, then list order', () => {
  const order = defaultOrder([
    task('c', 1, { due: 2, index: 0 }),
    task('b', 1, { due: 0, index: 1 }),
    task('a', 1, { due: -3, index: 2 }),
    task('d', 1, { due: 0, index: 3 }),
    task('none', 1, { due: null, index: 4 }),
  ]);
  assert.deepEqual(order, ['a', 'b', 'd', 'c', 'none']);
});

test('opened at 2pm the plan fills from now, not from the morning', () => {
  const plan = planDay({ now: h(7), meetings: [], tasks: [task('a', 1)] });
  assert.deepEqual(ids(plan.segments, 'task'), [`a@${h(7)}-${h(8)}`]);
});

test('before 7am the plan still starts at 7am', () => {
  const plan = planDay({ now: 60, meetings: [], tasks: [task('a', 1)] });
  assert.deepEqual(ids(plan.segments, 'task'), [`a@${h(0)}-${h(1)}`]);
});

test('meetings sit at their times and tasks work around them', () => {
  const plan = planDay({ now: 0, meetings: [{ id: 'm', start: h(2), end: h(3) }], tasks: [task('a', 1), task('b', 2)] });
  assert.deepEqual(ids(plan.segments, 'meeting'), [`m@${h(2)}-${h(3)}`]);
  // a: 7-8, b: 8-9 then 10-11 in two parts, since the meeting is 9-10.
  assert.deepEqual(ids(plan.segments, 'task'), [`a@${h(0)}-${h(1)}`, `b@${h(1)}-${h(2)}`, `b@${h(3)}-${h(4)}`]);
  const parts = plan.segments.filter((s) => s.id === 'b');
  assert.deepEqual(parts.map((s) => [s.part, s.parts]), [[1, 2], [2, 2]]);
});

test('a task that fits in one run is not split', () => {
  const plan = planDay({ now: 0, meetings: [], tasks: [task('a', 1.5)] });
  assert.equal(plan.segments.find((s) => s.id === 'a').parts, 1);
});

test('open time is shown as gaps, and hours planned and open are counted', () => {
  const plan = planDay({ now: 0, meetings: [{ id: 'm', start: h(2), end: h(3) }], tasks: [task('a', 1)] });
  assert.deepEqual(ids(plan.segments, 'gap'), [`gap@${h(1)}-${h(2)}`, `gap@${h(3)}-${DAY_END}`]);
  assert.equal(plan.plannedMins, 60);
  assert.equal(plan.openMins, 60 + (DAY_END - h(3)));
});

test('work that runs past 7pm goes to Doesn\'t fit today, whole', () => {
  const plan = planDay({ now: 0, meetings: [], tasks: [task('a', 8), task('b', 6)] });
  assert.deepEqual(plan.overflow, ['b']);
  assert.deepEqual(ids(plan.segments, 'task'), [`a@${h(0)}-${h(8)}`]);
});

test('a smaller task can still use time a longer one could not', () => {
  const plan = planDay({ now: 0, meetings: [], tasks: [task('big', 11, { priority: 'urgent' }), task('huge', 4, { priority: 'high' }), task('small', 1, { priority: 'low' })] });
  assert.deepEqual(plan.overflow, ['huge']);
  assert.deepEqual(ids(plan.segments, 'task').map((s) => s.split('@')[0]), ['big', 'small']);
});

test('tasks with no hours are never placed', () => {
  const plan = planDay({ now: 0, meetings: [], tasks: [task('a', 0), task('b', 1), task('c', Number.NaN)] });
  assert.deepEqual(plan.unset, ['a', 'c']);
  assert.deepEqual(ids(plan.segments, 'task'), [`b@${h(0)}-${h(1)}`]);
});

test('a day away has no open time and everything with hours does not fit', () => {
  const plan = planDay({ now: 0, away: true, meetings: [], tasks: [task('a', 1), task('b', 0)] });
  assert.deepEqual(plan.overflow, ['a']);
  assert.deepEqual(plan.unset, ['b']);
  assert.equal(plan.openMins, 0);
  assert.deepEqual(plan.segments.filter((s) => s.type === 'gap'), []);
});

test('after 7pm nothing is placed', () => {
  const plan = planDay({ now: DAY_END + 30, meetings: [], tasks: [task('a', 1)] });
  assert.deepEqual(plan.overflow, ['a']);
  assert.equal(plan.openMins, 0);
});

test('overlapping meetings are clashes, and both are named', () => {
  const plan = planDay({ now: 0, meetings: [{ id: 'm1', start: h(3), end: h(4) }, { id: 'm2', start: h(3) + 30, end: h(5) }, { id: 'm3', start: h(6), end: h(7) }], tasks: [] });
  assert.deepEqual(plan.clashes.meetings.sort(), ['m1', 'm2']);
  assert.equal(plan.clashes.count, 2);
});

test('meetings that only touch do not clash', () => {
  const plan = planDay({ now: 0, meetings: [{ id: 'm1', start: h(3), end: h(4) }, { id: 'm2', start: h(4), end: h(5) }], tasks: [] });
  assert.deepEqual(plan.clashes.meetings, []);
});

test('work due today or overdue that does not fit is a clash; later work is not', () => {
  const plan = planDay({
    now: 0,
    meetings: [],
    tasks: [task('fill', 12, { priority: 'urgent' }), task('late', 1, { due: -2 }), task('today', 1, { due: 0 }), task('urgentnodate', 1, { due: 5 })],
  });
  assert.deepEqual(plan.overflow.sort(), ['late', 'today', 'urgentnodate']);
  assert.deepEqual(plan.clashes.tasks.sort(), ['late', 'today']);
  assert.equal(plan.clashes.count, 2);
});

test('the order a person sets holds; new tasks fill in after it without reshuffling', () => {
  const tasks = [task('a', 1, { priority: 'urgent' }), task('b', 1), task('c', 1, { priority: 'low' }), task('n', 1, { priority: 'urgent' })];
  const plan = planDay({ now: 0, meetings: [], tasks, order: ['c', 'b', 'a'] });
  assert.deepEqual(plan.placedOrder, ['c', 'b', 'a', 'n']);
});

test('a saved id that has gone is ignored', () => {
  const plan = planDay({ now: 0, meetings: [], tasks: [task('a', 1)], order: ['gone', 'a'] });
  assert.deepEqual(plan.placedOrder, ['a']);
});

test('a task sent down is in Doesn\'t fit even with room', () => {
  const plan = planDay({ now: 0, meetings: [], tasks: [task('a', 1), task('b', 1)], down: ['a'] });
  assert.deepEqual(plan.overflow, ['a']);
  assert.deepEqual(plan.placedOrder, ['b']);
});

test('moveInOrder moves up and down, and stops at the ends', () => {
  assert.deepEqual(moveInOrder(['a', 'b', 'c'], 'b', -1), ['b', 'a', 'c']);
  assert.deepEqual(moveInOrder(['a', 'b', 'c'], 'b', 1), ['a', 'c', 'b']);
  assert.deepEqual(moveInOrder(['a', 'b', 'c'], 'a', -1), ['a', 'b', 'c']);
  assert.deepEqual(moveInOrder(['a', 'b', 'c'], 'c', 1), ['a', 'b', 'c']);
});

test('bringUp puts a task first and takes it off the sent-down list', () => {
  assert.deepEqual(bringUp({ order: ['a', 'b'], down: ['c', 'd'] }, 'c'), { order: ['c', 'a', 'b'], down: ['d'] });
});

test('sendDown takes a task out of the order and onto the sent-down list', () => {
  assert.deepEqual(sendDown({ order: ['a', 'b'], down: [] }, 'a'), { order: ['b'], down: ['a'] });
});

test('minuteOfDay measures a moment from the start of the day', () => {
  const start = Date.UTC(2026, 9, 23, 0, 0, 0);
  assert.equal(minuteOfDay(start / 1000 + 9 * 3600, start), 540);
  assert.equal(minuteOfDay(start / 1000 - 3600, start), -60);
});

test('hoursText reads minutes the way the header and gaps do', () => {
  assert.equal(hoursText(90), '1h 30m');
  assert.equal(hoursText(60), '1h');
  assert.equal(hoursText(45), '45m');
  assert.equal(hoursText(0), '0m');
});
