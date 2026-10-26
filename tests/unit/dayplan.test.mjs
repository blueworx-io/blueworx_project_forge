import test from 'node:test';
import assert from 'node:assert/strict';

// #620. The day planner's placing rules, proven without a browser.

import { DAY_START, DAY_END, planDay, defaultOrder, minuteOfDay, moveInOrder, bringUp, sendDown, hoursText, layOut, slotsOf, drawDay, swapSlots, placeSlot, dropSlot, slotOrder } from '../../src/dayplan.mjs';

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

test('urgent or overdue work that is not in the day needs attention, most pressing first (#635)', () => {
  const plan = planDay({
    now: h(9),
    meetings: [],
    tasks: [
      task('urgent-none', 0, { priority: 'urgent' }),
      task('late-big', 4, { priority: 'high', due: -1 }),
      task('late-sent', 1, { due: -2 }),
      task('today-big', 5, { due: 0 }),
      task('fits', 1, { priority: 'urgent' }),
      task('later-none', 0, { due: 3 }),
    ],
    down: ['late-sent'],
  });
  assert.deepEqual(plan.attention, ['urgent-none', 'late-big', 'late-sent']);
});

test('nothing needs attention when the urgent and overdue work is in the day (#635)', () => {
  const plan = planDay({ now: 0, meetings: [], tasks: [task('u', 1, { priority: 'urgent' }), task('l', 1, { due: -1 })] });
  assert.deepEqual(plan.attention, []);
});

test('a task covers the hours it takes on the day, not one stretched row (#639)', () => {
  const boxes = layOut([{ id: 'epim', start: h(4), end: h(8) }], { hourPx: 80, minPx: 32 });
  assert.deepEqual(boxes.map(({ top, height }) => ({ top, height })), [{ top: 4 * 80, height: 4 * 80 }]);
});

test('back-to-back work sits end to end, each at its own time (#639)', () => {
  const boxes = layOut([{ id: 'a', start: h(1), end: h(2) }, { id: 'b', start: h(2), end: h(4) }], { hourPx: 80, minPx: 32 });
  assert.deepEqual(boxes.map(({ top, height }) => [top, height]), [[80, 80], [160, 160]]);
});

test('a few minutes still gets room to read, and what follows moves down only as far as it must (#639)', () => {
  const boxes = layOut([{ id: 'tiny', start: h(8), end: h(8) + 5 }, { id: 'next', start: h(8) + 5, end: h(9) }], { hourPx: 80, minPx: 32 });
  assert.equal(boxes[0].top, 8 * 80);
  assert.equal(boxes[0].height, 32);
  assert.equal(boxes[1].top, 8 * 80 + 32);
  assert.equal(boxes[1].top + boxes[1].height, 9 * 80, 'it still ends at its own time');
});

test('meetings that really overlap stay at their own times (#639)', () => {
  const boxes = layOut([{ id: 'm1', start: h(2), end: h(4) }, { id: 'm2', start: h(3), end: h(4) }], { hourPx: 80, minPx: 32 });
  assert.equal(boxes[1].top, 3 * 80);
});

// #649. The plan keeps the times it placed each task at. It is drawn from
// those times, so nothing moves until the person edits it or presses Schedule.

const slot = (id, from, to) => ({ id, start: h(from), end: h(to) });

test('a plan gives the times it placed each task at (#649)', () => {
  const plan = planDay({ now: 0, meetings: [{ id: 'm', start: h(1), end: h(2) }], tasks: [task('a', 1), task('b', 2)] });
  assert.deepEqual(slotsOf(plan), [slot('a', 0, 1), slot('b', 2, 4)]);
});

test('a new day plans afresh: with nothing kept, the first look lays the day out from now (#649)', () => {
  const plan = planDay({ now: h(2), meetings: [], tasks: [task('a', 1, { priority: 'low' }), task('b', 1, { priority: 'urgent' })] });
  assert.deepEqual(slotsOf(plan), [slot('b', 2, 3), slot('a', 3, 4)]);
});

test('a kept plan is drawn at its own times, whatever priority says now (#649)', () => {
  const day = drawDay({ now: 0, meetings: [], tasks: [task('a', 1, { priority: 'low' }), task('b', 1, { priority: 'urgent' })], slots: [slot('a', 0, 1), slot('b', 1, 2)] });
  assert.deepEqual(ids(day.segments, 'task'), [`a@${h(0)}-${h(1)}`, `b@${h(1)}-${h(2)}`]);
  assert.deepEqual(day.placedOrder, ['a', 'b']);
  assert.equal(day.plannedMins, 120);
});

test('a task that arrives after the day is planned waits beneath it, and moves nothing (#649)', () => {
  const day = drawDay({ now: 0, meetings: [], tasks: [task('a', 1), task('new', 1, { priority: 'urgent' }), task('later', 1, { due: 3 }), task('none', 0)], slots: [slot('a', 1, 2)] });
  assert.deepEqual(ids(day.segments, 'task'), [`a@${h(1)}-${h(2)}`]);
  assert.deepEqual(day.overflow, ['new', 'later']);
  assert.deepEqual(day.unset, ['none']);
  assert.deepEqual(day.attention, ['new']);
});

test('a finished task leaves its slot open, and later tasks do not slide up (#649)', () => {
  const day = drawDay({ now: 0, meetings: [], tasks: [task('b', 1)], slots: [slot('a', 0, 1), slot('b', 1, 2)] });
  assert.deepEqual(ids(day.segments, 'task'), [`b@${h(1)}-${h(2)}`]);
  assert.deepEqual(ids(day.segments, 'gap'), [`gap@${h(0)}-${h(1)}`, `gap@${h(2)}-${DAY_END}`]);
});

test('a task whose hours changed keeps its slot (#649)', () => {
  const day = drawDay({ now: 0, meetings: [], tasks: [task('a', 3), task('b', 0)], slots: [slot('a', 0, 1), slot('b', 1, 2)] });
  assert.deepEqual(ids(day.segments, 'task'), [`a@${h(0)}-${h(1)}`, `b@${h(1)}-${h(2)}`]);
  assert.deepEqual(day.unset, []);
});

test('a meeting that lands on a kept task leaves the task where it is, and both are a clash (#649)', () => {
  const day = drawDay({ now: 0, meetings: [{ id: 'm', start: h(1), end: h(2) }], tasks: [task('a', 1), task('b', 1)], slots: [slot('a', 0, 1), slot('b', 1, 2)] });
  assert.deepEqual(ids(day.segments, 'task'), [`a@${h(0)}-${h(1)}`, `b@${h(1)}-${h(2)}`]);
  assert.deepEqual(day.clashes.overlaps, { tasks: ['b'], meetings: ['m'] });
  assert.equal(day.clashes.count, 2);
});

test('a kept task split around a meeting still reads as its parts (#649)', () => {
  const day = drawDay({ now: 0, meetings: [{ id: 'm', start: h(1), end: h(2) }], tasks: [task('a', 2)], slots: [slot('a', 0, 1), slot('a', 2, 3)] });
  assert.deepEqual(day.segments.filter((s) => s.type === 'task').map((s) => [s.part, s.parts]), [[1, 2], [2, 2]]);
  assert.deepEqual(day.clashes.overlaps, { tasks: [], meetings: [] });
});

test('moving a task up swaps it with the one before, and nothing else moves (#649)', () => {
  const slots = [slot('a', 0, 1), slot('b', 1, 3), slot('c', 3, 4), slot('d', 5, 6)];
  const next = swapSlots({ slots, meetings: [], now: 0 }, 'b', 'c');
  assert.deepEqual(next, [slot('a', 0, 1), slot('c', 1, 2), slot('b', 2, 4), slot('d', 5, 6)]);
  assert.deepEqual(slotOrder(next), ['a', 'c', 'b', 'd']);
});

test('a swap never puts work in time already past (#649)', () => {
  const next = swapSlots({ slots: [slot('a', 1, 2), slot('b', 2, 3)], meetings: [], now: h(4) }, 'a', 'b');
  assert.deepEqual(next, [slot('b', 4, 5), slot('a', 5, 6)]);
});

test('a swap works around meetings and other tasks (#649)', () => {
  const next = swapSlots({ slots: [slot('a', 0, 1), slot('x', 1, 2), slot('b', 3, 5)], meetings: [{ id: 'm', start: h(2), end: h(3) }], now: 0 }, 'a', 'b');
  assert.deepEqual(next, [slot('b', 0, 1), slot('x', 1, 2), slot('b', 3, 4), slot('a', 4, 5)]);
});

test('bringing a task up puts it in the first open time from now, and moves nothing else (#649)', () => {
  const slots = [slot('a', 0, 1), slot('b', 2, 3)];
  assert.deepEqual(placeSlot({ slots, meetings: [], now: 0 }, 'n', 1), [slot('a', 0, 1), slot('n', 1, 2), slot('b', 2, 3)]);
  assert.deepEqual(placeSlot({ slots, meetings: [], now: 0 }, 'n', 2), [slot('a', 0, 1), slot('n', 1, 2), slot('b', 2, 3), slot('n', 3, 4)]);
  assert.equal(placeSlot({ slots, meetings: [], now: h(11) + 30 }, 'n', 1), null, 'too long for the time left');
});

test('sending a task down leaves its slot open (#649)', () => {
  assert.deepEqual(dropSlot([slot('a', 0, 1), slot('b', 1, 2)], 'a'), [slot('b', 1, 2)]);
});
