# Per-seat dates (#513) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Each person on a task has their own date (Design by, Deliver by, Review by, Release by) in a Dates section of the task panel, and Capacity puts that person's hours on that one day.

**Architecture:** One new column, `design_target`. `planned_due` is shown as "Deliver by". `planned_start` stops being a person's input: Forge sets it to the earliest of the four dates on every edit, so the Gantt, client timelines and filters keep working unchanged. Capacity gives each seat a one-day window (its own date, or Deliver by when that seat has none); recurring chores keep their existing window.

**Tech Stack:** PHP 8 (WordPress plugin, `includes/`), React + Vite + TypeScript (`src/`), PHPUnit, Playwright.

**Spec:** Issue #513 and the design agreed in chat on 2026-10-13 (Luke): Design by is new and hidden when no design is needed; Starts becomes Deliver by; Review by stays; Release by stays; Due is no longer shown. Every assignment is at most 5 hours and done in a single day.

## Global Constraints

- Branch `per-seat-dates` from `main`; PR targets `main`, draft, linked to #513.
- Version: minor bump to `2.172.0` in `package.json`, `blueworx-forge.php` (header + `BWX_FORGE_VERSION`), `client/blueworx-forge-client.php` (header + constant); CHANGELOG entry in plain words.
- `src/rules.ts` updated in the same PR.
- Schema `VERSION` 39 → 40.
- No new dependencies.
- Copy: sentence case; labels exactly "Design by", "Deliver by", "Review by", "Release by"; section titles "Who and hours" and "Dates".
- Run lint once at the end; present findings, do not loop.

## Review Focus

1. A task saved with only Deliver by set: Capacity puts every seat's hours on Deliver by, never drops them.
2. A recurring chore (assignees) or a reminder: its dates and hours behave exactly as before; Forge never rewrites its start.
3. Changing the designer to "No design required" with a Design by still stored: the date is hidden and no design hours are counted (hours_designer is already 0 without a designer).
4. Existing tasks with an old start earlier than their due: nothing moves until someone edits a date; Capacity still counts them on their seat dates.
5. Capacity check at Up Next (G-UP-NEXT-8) for a task whose seats fall on different days reads every one of those days, not just the first seat's.

---

### Task 1: Store Design by

**Files:**
- Modify: `includes/Data/Schema.php` (VERSION 40; `design_target varchar(10) NOT NULL DEFAULT ''` before `planned_start`)
- Modify: `includes/Work/Items.php` (defaults and hydrate add `design_target`)
- Modify: `includes/Work/Fields.php` (`PLANNING` adds `design_target`; `DATE_ORDER` = `design_target, planned_due, review_target, release_target`; `REQUIRED_FROM` drops `planned_start`)
- Modify: `includes/Work/Validate.php` (`planning_fields` list adds `design_target`; `dates_in_order` labels: design by, deliver by, review by, release by)
- Modify: `src/types.ts` (`design_target?: string;`)
- Test: `tests/php/WorkValidateTest.php` (or the existing dates-order test file)

- [ ] Write failing tests: `Validate::dates_in_order( array( 'design_target' => '2026-10-15', 'planned_due' => '2026-10-14' ) )` returns an error on `planned_due` naming "design by"; a `planned_start` later than `planned_due` is no longer an error.
- [ ] Run `vendor/bin/phpunit --filter dates` — expect FAIL.
- [ ] Make the changes above.
- [ ] Run `vendor/bin/phpunit` — expect PASS (fix any test asserting the old order/labels).
- [ ] Commit `Store a Design by date on tasks (#513)`.

### Task 2: Forge sets the start itself

**Files:**
- Create: `includes/Work/StartDate.php`
- Modify: `includes/Work/Items.php` (`create` and `update` wrap the settled values: `StartDate::settle( ClientReviewer::settle(...), $current )`)
- Test: `tests/php/WorkStartDateTest.php`

**Interfaces:**
- Produces: `StartDate::settle( array $changes, array $current = array() ): array` — when `$changes` touches any of `Fields::DATE_ORDER`, does not set `planned_start` itself, and the item has no assignees (neither in `$changes` nor `$current`), sets `planned_start` to the earliest non-empty of the four dates after the edit. Otherwise returns `$changes` unchanged.

```php
public static function settle( array $changes, array $current = array() ): array {
	if ( array_key_exists( 'planned_start', $changes ) || array() === array_intersect_key( $changes, array_flip( Fields::DATE_ORDER ) ) ) {
		return $changes;
	}
	$assignees = $changes['assignees'] ?? $current['assignees'] ?? array();
	$assignees = is_string( $assignees ) ? (array) json_decode( $assignees, true ) : (array) $assignees;
	if ( array() !== $assignees ) {
		return $changes;
	}
	$dates = array();
	foreach ( Fields::DATE_ORDER as $field ) {
		$date = (string) ( $changes[ $field ] ?? $current[ $field ] ?? '' );
		if ( '' !== $date ) {
			$dates[] = $date;
		}
	}
	if ( array() !== $dates ) {
		$changes['planned_start'] = min( $dates );
	}
	return $changes;
}
```

- [ ] Tests: design 10-14 + due 10-16 → start 10-14; due only → start = due; explicit `planned_start` kept; item with assignees untouched; change with no date fields untouched.
- [ ] Run, see FAIL; implement; run, see PASS; commit `Set a task's start from its earliest date (#513)`.

### Task 3: Capacity puts each seat on its own day

**Files:**
- Modify: `includes/Capacity/Allocations.php` (`SEATS` gains the date column: primary → `planned_due`, design → `design_target`, review → `review_target`, delivery → `release_target`; `seats_of` gives each seat `from = to = ( seat date ?: planned_due )` and skips a seat with neither; assignees keep `window()`; an item with no dates at all still returns nothing)
- Modify: `includes/Capacity/Commitments.php` (`live()` keeps an item when its start..due overlaps the window — chores — or when any of `planned_due`, `design_target`, `review_target`, `release_target` is inside the window, or the existing #487 open-stage clause)
- Modify: `includes/Capacity/Impact.php` (`of()` uses the earliest `from` and latest `to` across all proposed allocations)
- Modify: `includes/Work/Gates.php` (G-UP-NEXT-5 → 'Deliver by date', fields `planned_due`, 'Set a deliver by date.'; G-UP-NEXT-8 wording "planned dates" → "their dates")
- Test: `tests/php/CapacityAllocationsTest.php`, `tests/php/CapacityImpactTest.php`

- [ ] Failing tests: an item with design 10-14 (2h), due 10-15 (3h), review 10-16 (1h), release 10-17 (1h) gives four allocations each with `from === to` on its own date; a seat with no date falls on `planned_due`; a chore with start 10-13..due 10-15 keeps that window; `Impact::of` window spans 10-14..10-17.
- [ ] Run, FAIL; implement; run full `vendor/bin/phpunit`; update existing tests that assumed one shared window (CapacityAllocationsTest, CapacityImpactTest, CapacityGateTest, WorkGatesTest, SupportHoursGateTest) to the new behaviour; PASS.
- [ ] Commit `Put each person's hours on their own date in Capacity (#513)`.

### Task 4: Dates section in the task panel

**Files:**
- Modify: `src/components/ItemPanel.tsx`
  - `DATES` = Design by (`design_target`), Deliver by (`planned_due`, needed `up-next`), Review by, Release by.
  - `NEEDED_FROM`: drop `planned_start`.
  - Move the dates out of the `assign` section into a new section `dates` (same card pattern: `head( 'dates', 'Dates' )`, `data-testid="bwx-dates"`, `data-collapsed`), placed directly after it, under the same `staff && ! ended && ! chore` condition.
  - Rename the `assign` head to "Who and hours".
  - Hide Design by while `draft.designer_id` is empty.
  - The jump-to-field helper sends the four date fields to the `dates` section.
  - History labels: `design_target` → 'Design by', `planned_due` → 'Deliver by'.
- Modify: `src/styles.css` only if the new card needs a class the others already have (reuse `.bwx-assign`'s rules via a shared selector rather than copying).
- Modify: `src/calendar.ts` ('starts' becomes 'design' labelled "Design by" from `design_target`; 'due' labelled "Deliver by").
- Test: `tests/e2e/item-dates.spec.js`

- [ ] Failing e2e: open a task with a designer → Dates section shows Design by, Deliver by, Review by, Release by and no Starts/Due; set designer to "No design required" → Design by gone; "Who and hours" section has no date inputs. Save dates → `GET /work-items/:id` shows `design_target` and `planned_start` equal to the earliest.
- [ ] Run, FAIL; implement; `npm run build`; run, PASS.
- [ ] Commit `Move a task's dates into their own section (#513)`.

### Task 5: Capacity end to end, rules, version

**Files:**
- Test: `tests/e2e/capacity-per-seat.spec.js` — a person with 8h days, a task with them as doer (3h, Deliver by today+2) and deliverer (1h, Release by today+3), moved to in-development by override; `/capacity/person/:id?from=today&to=today+6` shows `committed_by_day[today+2] == 3` and `[today+3] == 1`, nothing on other days.
- Modify: `src/rules.ts` (date-order rule → "design by, then deliver by, then review by, then release by"; new rule: each person's hours land on their own date, all on that day; Deliver by needed to leave Up Next).
- Modify: `CHANGELOG.md`, version files (2.172.0).
- [ ] Run new spec + `tests/e2e/capacity-rest.spec.js`, `capacity-gate.spec.js`, `standup-over-hours.spec.js`, `item-assignment.spec.js`, `calendar.spec.js`; fix tests that assumed spreading or Starts/Due labels.
- [ ] `npm run build`, `npm run lint` once, `vendor/bin/phpunit`.
- [ ] Commit `Rules and version for per-seat dates (#513)`; push; draft PR.

## Out of scope (say so in the PR)

- Client sites keep showing the start and due they show now (start is now Forge-set); adding Design by there is a follow-up.
- The Gantt keeps drawing start..due.
