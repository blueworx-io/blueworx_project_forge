# Day planner in My tasks

Date: 2026-10-23. Issue #620. Mockup reviewed with Luke:
`.superpowers/brainstorm/1082-1792782685/content/day-layout-v2.html` (git-ignored).

## Why

The Today tab in My tasks is a list. It says what is due but not whether it
fits: a day with two meetings and nine hours of work looks the same as an
empty one. Luke wants to see how the day actually plays out — meetings at
their times, tasks filling the gaps by priority, what is still open — and
rearrange it.

## Goals

- The Today tab becomes a planned day, top to bottom, for every staff member
  on their own My tasks.
- Meetings sit at their exact times; tasks fill the open time, highest
  priority first.
- Open time is shown, not only time used.
- The person can rearrange the plan.
- Clashes are flagged.

## Non-goals

- Tasks behave exactly as today (Luke, 2026-10-23). The planner is a view of
  the same tasks: it never changes due dates, stages, priorities or anyone's
  hours, and it is not stored on the task.
- No other day than today. Tomorrow, Next seven days and the other tabs stay
  lists as they are.
- No per-person working hours (see "The day").
- Depends on nothing from #606: it shows whatever meetings Forge has. When
  synced Google meetings arrive they appear like any other meeting.

## Anti-goals

- A task must never look scheduled when it has no hours to place it by.
- The planner must not quietly hide work: everything on today's list is
  somewhere on the screen, in the day or in a list beneath it.

## The day

- 7am to 7pm, the same for everyone (Luke, 2026-10-23), in the site's
  timezone, top to bottom in hour rows.
- The header says the hours planned and the hours open for the rest of the
  day.
- Open time between items is shown as its own block ("Open · 1h 30m").
- *Claude's call:* the plan fills from now onwards. Opened at 2pm, it does
  not fill the morning with work that has gone; earlier rows show what
  meetings were there and are otherwise left empty.

## What goes in

- Exactly the tasks on today's Today tab now, with the same details on each
  block as the table row has: title, "Done x of y", client, stage, your
  role, priority, hours, due (Luke, 2026-10-23: "It needs to carry the same
  task details"). Clicking a block opens the task as the row does today.
- A block's height follows its hours.
- Meetings and anything else in Today's diary that has a time sit at that
  time. Diary entries with no time (dates, renewals) sit in a strip above
  the first hour.
- The hours placed are the hours shown in the row's Hours column, i.e. this
  person's hours on the task.
- *Claude's call:* a meeting is in a person's day when they host it **or
  attend it**. The planner reads attendees itself; the diary feed used by
  other screens is not changed by this work.
- *Claude's call:* chores and reminders appear **once**, as their task row,
  at their time if they have one; the matching diary entry is not drawn
  again. With no hours and no time they sit in "No hours set".
- *Claude's call:* "today" stays the browser's day, as the Today tab works
  now, so the planned tasks are exactly the list's. Meeting times are shown
  converted into the viewer's own time, since a meeting is set in its
  client's timezone.
- *Claude's call:* on a whole day the person is away (leave, holiday,
  training), there is no open time: a banner says so and every task sits in
  "Doesn't fit today". Part days are not modelled, since Forge records none.

## Filling the open time

- Highest priority first: urgent, high, normal, low.
- *Claude's call:* within a priority, overdue first, then due earliest,
  then the order the list shows today.
- *Claude's call:* a task that runs into a meeting carries on after it, drawn
  in two parts.

## Below the day

Two lists, so nothing is hidden:

1. **Doesn't fit today** — tasks with hours that run past 7pm.
2. **No hours set** — tasks with no hours. They are never placed in the day
   (Luke, 2026-10-23).

## Edit plan

- An *Edit plan* button. While editing, each placed task can move up or down
  and any task in either list below can be brought up into the day; one can
  also be sent down to "Doesn't fit today". Keyboard reachable, not drag-only.
- *Claude's call:* the order the person sets holds for the rest of that day
  in their browser and on their account, and new tasks or meetings drop
  into open time without reshuffling it. The next day starts from scratch.
- A task with no hours can be brought up only once it has hours; bringing it
  up offers to set them (on the task, through the normal task edit, so the
  task's own rules still apply).

## Clashes

Flagged in red on the item and counted in the header:

- two meetings or timed entries that overlap;
- anything due today, or overdue, that sits in "Doesn't fit today" — which
  is what "more work than the day holds" means, since nothing placed in the
  day can run past 7pm. *(Claude's call.)*

## Left to the builder

- Where the person's order is stored (per person per day; small).
- Exact visual design, following the Forge kit in `src/kit/` and the
  `frontend-design` skill; the mockup is a guide, not a spec.
- Phone layout: the same rows, blocks stacking their details.
- How the list's existing filters (search, Your role, top-bar client)
  apply: they narrow what is planned, as they narrow the list today.

## Done when

- Opening Today shows meetings at their times and tasks filling the open
  time by priority, each with the row's details, with open time shown.
- Tasks with no hours sit in "No hours set"; work past 7pm sits in "Doesn't
  fit today".
- Moving an item, or bringing one up, changes the plan, and it holds after a
  reload that day.
- Overlapping meetings, and due or overdue work that doesn't fit, are
  flagged.
- `src/rules.ts`, the changelog and the version are updated in the same PR.
