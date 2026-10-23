# Day planner (#620) - build plan

Design: `docs/superpowers/specs/2026-10-23-day-planner-design.md`. Version 2.209.0, branch `day-planner-build` off `google-calendar-build`.

1. Pure module `src/dayplan.mjs` + `tests/unit/dayplan.test.mjs` (TDD): ordering, placing, splitting, overflow, away, clashes, move/bring up/send down.
2. Feed: meeting entries gain `starts_at`, `ends_at`, `attendee_ids`, `all_day` (additive, so the planner knows exact times and attendees; other screens ignore them). PHP unit test.
3. Storage: `includes/Work/DayPlan.php` (user meta, last 3 days), `GET/PUT /day-plan`, PHP unit test.
4. Screen: `src/components/DayPlanner.tsx` replaces the Today table + diary strip on the Today tab; other tabs unchanged. CSS in `src/styles.css`.
5. Playwright `tests/e2e/day-planner.spec.js`; rules.ts, changelog, version.

## Rulings

Ruling: meeting times come from new additive feed fields (exact instants), not from parsing the detail text - the detail has no zone or length - cost if wrong: one more field per meeting entry.
Ruling: a task that does not fully fit goes to "Doesn't fit today" whole; later, smaller tasks may still fill the open time - otherwise open time would show beside unfit work - cost if wrong: a low task can sit above a high one that was too long.
Ruling: no task or chore has a time of day in Forge, so chores/reminders sit as tasks in order; only meetings are timed.
Ruling: whole-day away = a "leave" diary entry for the person covering today; their no-hours tasks stay in "No hours set" (they were never placeable) - cost if wrong: none visible.
Ruling: "Bring up" on a no-hours task opens the task to set hours and puts it first in the person's order; it lands in the day once hours exist.
Ruling: the plan's "today" is the browser's day (as the Today tab); the planner reads its own diary window of three days and keeps meetings whose instants fall inside that day.
Ruling: order is stored on the account only (user meta, per person per day, three days kept), no browser copy - cost if wrong: a brief flash on reload.
