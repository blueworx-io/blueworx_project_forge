/**
 * The day planner's placing rules (#620), kept out of the screen.
 *
 * Plain JavaScript with no DOM, so node alone can test it. Times are minutes
 * from the start of the day, in the viewer's own zone. The planner only ever
 * reads the tasks it is given: it never changes a due date, a stage, a
 * priority or anyone's hours.
 */

/** 7am and 7pm, the same for everyone (Luke, 2026-10-23). */
export const DAY_START = 420;
export const DAY_END = 1140;

const RANK = { urgent: 0, high: 1, normal: 2, low: 3 };

/** How coarse "now" is: the plan starts on the next five minutes. */
const STEP = 5;

/** The smallest open stretch worth drawing as a block. */
const MIN_GAP = 5;

function minutesOf(hours) {
  const mins = Math.round(Number(hours) * 60);
  return Number.isFinite(mins) && mins > 0 ? mins : 0;
}

/**
 * The order the day fills in: urgent, high, normal, low; within a priority
 * overdue first, then due earliest, then the order the list shows.
 *
 * @param {{ id: string, priority?: string, due: number | null, index: number }[]} tasks
 * @returns {string[]}
 */
export function defaultOrder(tasks) {
  const rank = (task) => RANK[String(task.priority ?? '')] ?? 4;
  const late = (task) => (task.due !== null && task.due < 0 ? 0 : 1);
  const due = (task) => task.due ?? Number.POSITIVE_INFINITY;

  return [...tasks]
    .sort((a, b) => rank(a) - rank(b) || late(a) - late(b) || (due(a) === due(b) ? 0 : due(a) < due(b) ? -1 : 1) || a.index - b.index)
    .map((task) => task.id);
}

/** Merge meeting intervals that touch or overlap. */
function merged(intervals) {
  const sorted = intervals.filter(([s, e]) => e > s).sort((a, b) => a[0] - b[0] || a[1] - b[1]);
  const out = [];
  for (const [s, e] of sorted) {
    const last = out[out.length - 1];
    if (last && s <= last[1]) last[1] = Math.max(last[1], e);
    else out.push([s, e]);
  }
  return out;
}

/** Where planning from `now` starts: 7am at the earliest, on the next five minutes. */
function cursorOf(now) {
  return Math.ceil(Math.max(now, DAY_START) / STEP) * STEP;
}

/** Takes `need` minutes from the front of the open time, or null if it does not fit whole. */
function take(free, need) {
  if (free.reduce((sum, [s, e]) => sum + (e - s), 0) < need) return null;
  const pieces = [];
  const rest = [];
  for (const [s, e] of free) {
    if (need <= 0) {
      rest.push([s, e]);
      continue;
    }
    const got = Math.min(need, e - s);
    pieces.push([s, s + got]);
    need -= got;
    if (s + got < e) rest.push([s + got, e]);
  }
  return { pieces, rest };
}

/** The open stretches of the day from `from`, with the meetings taken out. */
function openTime(from, meetings) {
  let free = [[Math.max(from, DAY_START), DAY_END]];
  if (free[0][0] >= DAY_END) return [];
  for (const [ms, me] of merged(meetings.map((m) => [m.start, m.end]))) {
    const next = [];
    for (const [s, e] of free) {
      if (me <= s || ms >= e) next.push([s, e]);
      else {
        if (ms > s) next.push([s, ms]);
        if (me < e) next.push([me, e]);
      }
    }
    free = next;
  }
  return free;
}

/**
 * Lays the day out.
 *
 * @param {object} input
 * @param {number} input.now Minutes from the start of the day.
 * @param {{ id: string, start: number, end: number }[]} input.meetings
 * @param {{ id: string, hours: number, priority?: string, due: number | null, index: number }[]} input.tasks
 * @param {string[]} [input.order] The order the person set, if any.
 * @param {string[]} [input.down] Tasks the person sent down to "Doesn't fit".
 * @param {boolean} [input.away] Whether the whole day is away.
 */
export function planDay({ now, meetings, tasks, order = [], down = [], away = false }) {
  const withHours = tasks.filter((task) => minutesOf(task.hours) > 0);
  const unset = tasks.filter((task) => minutesOf(task.hours) === 0).map((task) => task.id);
  const sentDown = new Set(down);
  const byId = new Map(withHours.map((task) => [task.id, task]));

  // The person's order first, then anything new in the default order.
  const seen = new Set();
  const sequence = [];
  for (const id of order) {
    if (byId.has(id) && !sentDown.has(id) && !seen.has(id)) {
      seen.add(id);
      sequence.push(id);
    }
  }
  for (const id of defaultOrder(withHours)) {
    if (!seen.has(id) && !sentDown.has(id)) sequence.push(id);
  }

  let free = away ? [] : openTime(cursorOf(now), meetings);

  const segments = [];
  const overflow = withHours.filter((task) => sentDown.has(task.id)).map((task) => task.id);
  const placedOrder = [];
  let plannedMins = 0;

  for (const id of sequence) {
    const got = take(free, minutesOf(byId.get(id).hours));
    if (!got) {
      overflow.push(id);
      continue;
    }
    const { pieces } = got;
    free = got.rest;
    pieces.forEach(([s, e], at) => segments.push({ type: 'task', id, start: s, end: e, part: at + 1, parts: pieces.length }));
    placedOrder.push(id);
    plannedMins += minutesOf(byId.get(id).hours);
  }

  return finish({ segments, free, meetings, tasks, overflow, unset, placedOrder, plannedMins, kept: [] });
}

/**
 * What a planned day and a kept one share (#649): open time as gaps,
 * meetings, the drawing order, clashes and what needs attention.
 */
function finish({ segments, free, meetings, tasks, overflow, unset, placedOrder, plannedMins, kept }) {
  const byId = new Map(tasks.map((task) => [task.id, task]));
  const gaps = free.filter(([s, e]) => e - s >= MIN_GAP);
  for (const [s, e] of gaps) segments.push({ type: 'gap', id: 'gap', start: s, end: e });

  for (const meeting of meetings) {
    const start = Math.max(meeting.start, DAY_START);
    const end = Math.min(meeting.end, DAY_END);
    if (end > start) segments.push({ type: 'meeting', id: meeting.id, start, end });
  }
  const weight = { meeting: 0, task: 1, gap: 2 };
  segments.sort((a, b) => a.start - b.start || weight[a.type] - weight[b.type] || a.end - b.end);

  // Clashes: meetings that overlap, and work due today or late that has no room.
  const clashing = new Set();
  for (let i = 0; i < meetings.length; i += 1) {
    for (let j = i + 1; j < meetings.length; j += 1) {
      const a = meetings[i];
      const b = meetings[j];
      if (a.start < b.end && b.start < a.end) {
        clashing.add(a.id);
        clashing.add(b.id);
      }
    }
  }
  const clashTasks = overflow.filter((id) => {
    const due = byId.get(id).due;
    return due !== null && due <= 0;
  });

  // #649. A meeting that lands on a kept task leaves the task where it is;
  // both are a clash until the person edits or plans again.
  const overlapTasks = new Set();
  const overlapMeetings = new Set();
  for (const piece of kept) {
    for (const meeting of meetings) {
      if (piece.start < meeting.end && meeting.start < piece.end) {
        overlapTasks.add(piece.id);
        overlapMeetings.add(meeting.id);
      }
    }
  }
  const flagged = new Set([...clashing, ...overlapMeetings]);

  // Urgent or overdue work the day has no room for (#635), most pressing
  // first, so it is shown above the day rather than lost below it.
  const placed = new Set(placedOrder);
  const attention = defaultOrder(tasks.filter((task) => !placed.has(task.id) && ('urgent' === task.priority || (task.due !== null && task.due < 0))));

  return {
    segments,
    overflow,
    unset,
    attention,
    placedOrder,
    plannedMins,
    openMins: gaps.reduce((sum, [s, e]) => sum + (e - s), 0),
    clashes: {
      meetings: [...clashing],
      tasks: clashTasks,
      overlaps: { tasks: [...overlapTasks], meetings: [...overlapMeetings] },
      count: flagged.size + clashTasks.length + overlapTasks.size,
    },
  };
}

/** Where each task was placed (#649): one {id, start, end} for each piece. */
export function slotsOf(plan) {
  return plan.segments.filter((seg) => seg.type === 'task').map(({ id, start, end }) => ({ id, start, end }));
}

const byStart = (a, b) => a.start - b.start || a.end - b.end;

/** The order tasks come in on a kept day: by when each first starts. */
export function slotOrder(slots) {
  const out = [];
  for (const piece of [...slots].sort(byStart)) if (!out.includes(piece.id)) out.push(piece.id);
  return out;
}

/**
 * Draws a kept day (#649): every task at the time it was given, whatever has
 * changed since. A task that has gone leaves its time open; one that arrived
 * since waits beneath the day until the person edits or plans again.
 *
 * @param {object} input
 * @param {number} input.now When the day was planned; open time is shown from then.
 * @param {{ id: string, start: number, end: number }[]} input.meetings
 * @param {{ id: string, hours: number, priority?: string, due: number | null, index: number }[]} input.tasks
 * @param {{ id: string, start: number, end: number }[]} input.slots
 * @param {boolean} [input.away]
 */
export function drawDay({ now, meetings, tasks, slots, away = false }) {
  const here = new Set(tasks.map((task) => task.id));
  const kept = slots.filter((piece) => here.has(piece.id) && piece.end > piece.start).sort(byStart);
  const placed = new Set(kept.map((piece) => piece.id));

  const parts = new Map();
  for (const piece of kept) parts.set(piece.id, (parts.get(piece.id) ?? 0) + 1);
  const seen = new Map();
  const segments = kept.map(({ id, start, end }) => {
    const part = (seen.get(id) ?? 0) + 1;
    seen.set(id, part);
    return { type: 'task', id, start, end, part, parts: parts.get(id) };
  });

  return finish({
    segments,
    free: away ? [] : openTime(cursorOf(now), [...meetings, ...kept]),
    meetings,
    tasks,
    overflow: defaultOrder(tasks.filter((task) => minutesOf(task.hours) > 0 && !placed.has(task.id))),
    unset: tasks.filter((task) => minutesOf(task.hours) === 0 && !placed.has(task.id)).map((task) => task.id),
    placedOrder: slotOrder(kept),
    plannedMins: kept.reduce((sum, piece) => sum + (piece.end - piece.start), 0),
    kept,
  });
}

/** Lays work into open time in turn, each whole or not at all. */
function fill(free, work) {
  const out = [];
  for (const [id, mins] of work) {
    const got = take(free, mins);
    if (!got) continue;
    free = got.rest;
    for (const [start, end] of got.pieces) out.push({ id, start, end });
  }
  return out;
}

/**
 * Swaps two tasks on a kept day (#649), `first` being the earlier: the later
 * one takes its place and the earlier follows, in the time they held. Nothing
 * else moves, and nothing lands in time already past.
 */
export function swapSlots({ slots, meetings, now }, first, second) {
  const mine = (id) => slots.filter((piece) => piece.id === id);
  const a = mine(first);
  const b = mine(second);
  if (0 === a.length || 0 === b.length) return [...slots];
  const length = (list) => list.reduce((sum, piece) => sum + (piece.end - piece.start), 0);
  const rest = slots.filter((piece) => piece.id !== first && piece.id !== second);
  const from = Math.max(cursorOf(now), Math.min(...a.map((piece) => piece.start), ...b.map((piece) => piece.start)));
  const moved = fill(openTime(from, [...meetings, ...rest]), [
    [second, length(b)],
    [first, length(a)],
  ]);
  return [...rest, ...moved].sort(byStart);
}

/** Puts a task into the first open time from now (#649), or null if it does not fit. */
export function placeSlot({ slots, meetings, now }, id, hours) {
  const rest = slots.filter((piece) => piece.id !== id);
  const got = take(openTime(cursorOf(now), [...meetings, ...rest]), minutesOf(hours));
  if (!got) return null;
  return [...rest, ...got.pieces.map(([start, end]) => ({ id, start, end }))].sort(byStart);
}

/** Takes a task out of the day, leaving its time open (#649). */
export function dropSlot(slots, id) {
  return slots.filter((piece) => piece.id !== id);
}

/** Moves one id a place up (-1) or down (+1) in an order; the ends stay put. */
export function moveInOrder(order, id, delta) {
  const at = order.indexOf(id);
  const to = at + delta;
  if (at < 0 || to < 0 || to >= order.length) return [...order];
  const next = [...order];
  [next[at], next[to]] = [next[to], next[at]];
  return next;
}

/** Brings a task to the top of the day, off the sent-down list. */
export function bringUp({ order, down }, id) {
  return { order: [id, ...order.filter((one) => one !== id)], down: down.filter((one) => one !== id) };
}

/** Sends a task down to "Doesn't fit today". */
export function sendDown({ order, down }, id) {
  return { order: order.filter((one) => one !== id), down: down.includes(id) ? [...down] : [...down, id] };
}

/** Whole minutes from the start of the day to a unix moment (seconds). */
export function minuteOfDay(unixSeconds, dayStartMs) {
  return Math.round((unixSeconds * 1000 - dayStartMs) / 60000);
}

/** 90 reads "1h 30m", 60 "1h", 45 "45m". */
export function hoursText(mins) {
  const whole = Math.max(0, Math.round(mins));
  const h = Math.floor(whole / 60);
  const m = whole % 60;
  if (h === 0) return `${m}m`;
  return m === 0 ? `${h}h` : `${h}h ${m}m`;
}

/** 9:30 style clock for a minute of the day. */
export function clockText(mins) {
  const h = Math.floor(mins / 60);
  const m = mins % 60;
  return `${h}:${String(m).padStart(2, '0')}`;
}

/**
 * Where each segment sits on the drawn day (#639): its top and height in
 * pixels from 7am, so a task covers the hours it takes rather than stretching
 * one row. A few minutes still gets minPx to be read; whatever follows moves
 * down only as far as it must, and still ends at its own time where it can.
 * Meetings that really overlap are a clash, and stay at their own times.
 *
 * @param {{start: number, end: number}[]} segments In time order.
 * @param {{hourPx: number, minPx: number}} sizes
 */
export function layOut(segments, { hourPx, minPx }) {
  let floor = 0;
  let last = -Infinity;
  return segments.map((seg) => {
    const at = Math.round(((seg.start - DAY_START) / 60) * hourPx);
    const top = seg.start >= last ? Math.max(at, floor) : at;
    last = Math.max(last, seg.end);
    const end = Math.round(((seg.end - DAY_START) / 60) * hourPx);
    const height = Math.max(end - top, minPx);
    floor = top + height;
    return { ...seg, top, height };
  });
}
