# Hours Until a Date — Implementation Plan (PR D, block 4)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A working week can be recorded from a date until a date, and no day may hold more than 12 hours.

**Architecture:** One nullable column on the effective-dated pattern row. `Patterns::pick()` skips a row whose end is before the date asked about, so an ended pattern hands back to whatever was in force before it. The route refuses a day over 12 and an end before the start, by field; `record()` clamps as the backstop.

**Spec:** `docs/superpowers/specs/2026-09-18-availability-and-profile-design.md`, "PR D".

## Global Constraints

- Branch `availability-until` off `task-panel-gates`; draft PR to `task-panel-gates`. Version `2.117.0` → `2.118.0`; changelog `## [2.118.0] - 2026-09-18`.
- Schema `VERSION` 25 → 26; `effective_to varchar(10) NULL`.
- No new dependencies; nothing under `client/` but the version line; restore the client bundle after every build. Never build or edit PHP during a Playwright run. Lint once at the end.

## File map

| File | Change |
|---|---|
| `includes/Data/Schema.php` | `effective_to varchar(10) NULL` after `effective_from`; VERSION 26 |
| `includes/Capacity/Patterns.php` | `MAX_DAY = 12.0`; `record( …, string $note = '', string $effective_to = '' )` clamps each day to MAX_DAY and stores `effective_to` (`null` when empty); `pick()` skips rows ended before the date; `hydrate()` adds `effective_to` (string, '' when null) |
| `includes/Rest/AvailabilityController.php` | `set_hours()`: `effective_to` optional date, must be `>= effective_from` ("The end has to be on or after the start."); any day `> 12` → field error on that day ("At most 12 hours in a day.") |
| `src/types.ts` | `AvailabilityPattern.effective_to: string` |
| `src/components/AvailabilityScreen.tsx` | hours form: "Until" date (optional, help "Leave empty for ongoing hours."), inputs `max="12"`, body sends `effective_to`; history columns From, Until ("—" when ongoing) |
| `tests/php/CapacityAvailabilityTest.php` | pick with an end; clamp |
| `tests/e2e/availability-rest.spec.js` | until + 13-hour refusal |
| version files, `CHANGELOG.md` | 2.118.0 |

### Task 1: Domain and schema

- Tests first in `CapacityAvailabilityTest`: `Patterns::pick()` over `[ {from: 2026-01-01, mon: 8}, {from: 2026-03-01, to: 2026-03-31, mon: 4, created later} ]` gives 4 on 2026-03-10 and 8 on 2026-04-01; a row with `effective_to` before `effective_from` is never picked. Run, see them fail.
- Implement per the file map. `vendor/bin/phpunit`, `vendor/bin/phpcs` on the touched files. Commit "A working week may end; no day above 12 hours".

### Task 2: Route and screen

- `set_hours()` validation as above. `AvailabilityScreen` form and history as above.
- `availability-rest.spec.js`: POST hours from today until today → `GET /users/<id>/availability` has `current` today; POST 13 hours on Monday → 400 with `fields.hours_mon`. `availability-app.spec.js` unchanged unless it asserts the history's columns.
- Build, restore the client bundle, run the two availability specs, version + changelog, commit, draft PR.
