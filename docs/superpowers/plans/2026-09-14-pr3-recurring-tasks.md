# PR 3 — Recurring tasks: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The studio can define tasks that recur daily, weekly or monthly with seats and hours, and each due day becomes an ordinary task in Up Next the first time anyone opens Forge.

**Architecture:** A `Recurring` module: `Rule` (pure date arithmetic), `Sources` (the table of recurring definitions), `Occurrences` (the claim table — the insert is the lock), `Materialise` (turns due days into work items through `Items::create` and a new `Transition::place`). A REST controller and a React screen under Delivery. No cron: materialise runs on `GET /work-items`, `GET /work-items-all`, `GET /standup` and `GET /recurring`.

**Tech Stack:** PHP (own tables), React + TypeScript, PHPUnit, Playwright.

**Spec:** `docs/superpowers/specs/2026-09-14-studio-work-recurring-surecart-slack-design.md` (section "PR 3").

## Global Constraints

- Branch `recurring-tasks`, off `session-cache`. Draft PR against `session-cache`.
- Version `2.103.0`; changelog. Schema `VERSION` bumped by one and the new tables added to `definitions()` in dbDelta shape (two spaces after `PRIMARY KEY`).
- Every new REST route via `Server::register_route` with a `scope`. Writes `Permissions::manage`; reads `signed_in` with `SCOPE_LIST` narrowed by `Reach` to the studio site.
- Dates are `YYYY-MM-DD` strings in the WordPress timezone (`wp_timezone()`); "today" is `wp_date( 'Y-m-d' )`.
- No build or PHP edits during a Playwright run.

---

### Task 1: `Recurring\Rule` (pure)

**Files:** `includes/Recurring/Rule.php`, `tests/php/RecurringRuleTest.php`.

**Interfaces:** `Rule::normalise( array $input ): array|null` (null when invalid) → `{ every: 'day' }` | `{ every: 'week', days: int[] (ISO 1–7, sorted, unique) }` | `{ every: 'month', day: 1–31 }`; `Rule::next_on_or_after( array $rule, string $date ): string` — the first due date ≥ `$date`; `Rule::due_between( array $rule, string $from, string $to, string $ends_on = '' ): string[]` — every due date in `[from, to]`, stopping at `ends_on`; `Rule::describe( array $rule ): string` — "Every day", "Every Monday and Thursday", "Monthly on the 15th" (month day clamped: "on the last day" for 31).

- [ ] Tests: daily from a Tuesday; weekly `[1,4]` from a Wednesday → Thursday, then Monday; monthly 31 in February → Feb 28/29; `due_between` over a 10-day window stacks the right dates; `ends_on` cuts it; invalid input (`every: 'year'`, day 0, day 32, weekday 8) → null; `describe` wording.
- [ ] Implement with `DateTimeImmutable` in `wp_timezone()` (stub `wp_timezone` in the PHPUnit bootstrap to return `new DateTimeZone( 'UTC' )` if it is not already there). Commit.

### Task 2: Tables, `Sources`, `Occurrences`

**Files:** `includes/Data/Schema.php` (two tables, `VERSION` +1, `recurring_id` column on work items), `includes/Recurring/Sources.php`, `includes/Recurring/Occurrences.php`, `includes/Recurring/Validate.php`.

- `bwx_forge_recurring`: `id` (prefix `rec`), `kind` ('schedule'|'subscription'), `client_site_id`, `client_id`, `title`, `description` (text), `work_type`, `primary_user_id`, `reviewer_id`, `deliverer_id`, `hours_primary`, `hours_review`, `hours_delivery` decimal(8,2), `rule` text (JSON), `starts_on`, `ends_on`, `next_due` varchar(10), `last_created_at` bigint, `status` ('active'|'paused'|'ended'), `source_ref` varchar(64), created/updated/created_by/record_version. Keys: `site_status (client_site_id, status)`, `next_due`, `source_ref`.
- `bwx_forge_recurring_occurrences`: `recurring_id`, `due_on`, `work_item_id`, `created_at`; `PRIMARY KEY  (recurring_id, due_on)`.
- Work items: `recurring_id varchar(32) NOT NULL DEFAULT ''` + `KEY recurring_id (recurring_id)`. `Items::hydrate` passes it through; `Items::writable` does not (only the engine sets it, via `create`'s values — add it to the columns `create()` accepts rather than to the edit list).

**Interfaces:** `Sources::create( array $values, int $author ): ?array`, `get`, `for_site( string $site_id, ?string $status = null ): array`, `update( $id, $values, $version ): ?array`, `set_next_due( $id, $date )`, `end( $id )`, `due( string $today ): array` (active with `next_due <= today`), `by_source_ref( string $ref ): ?array`. `Occurrences::claim( $recurring_id, $due_on ): bool` (INSERT; false on duplicate key), `Occurrences::record_item( $recurring_id, $due_on, $item_id )`, `Occurrences::last_for( array $ids ): array` (newest `due_on` + `work_item_id` per source). `Validate::source( array $input, bool $partial ): { values, errors }` — title required, `work_type` in `Types::ALL`, rule via `Rule::normalise`, hours ≥ 0, seats are user ids or empty, `starts_on` a date (default today), `ends_on` empty or ≥ starts_on.

- [ ] Unit test `Validate::source` (title missing, bad rule, negative hours, ends before starts). Implement. `npm run wp:up` and confirm tables exist (`curl` the REST index still answers). Commit.

### Task 3: `Transition::place` and `Materialise`

**Files:** `includes/Work/Transition.php`, `includes/Work/Events.php` (`PLACED = 'placed'`, `VIA_SCHEDULE = 'schedule'`), `includes/Recurring/Materialise.php`, `src/components/ItemPanel.tsx` (`describe`: `case 'placed'` → "Placed in {stage} by the schedule").

**Interfaces:**
- `Transition::place( array $item, string $to, int $actor, string $why ): array|WP_Error` — the seventh door: an item put at a stage it never walked to, by the schedule. Uses `commit()` with event `{ action: Events::PLACED, gate: '', reason: $why, via: Events::VIA_SCHEDULE }`, no override mark. Refused when `! Stages::may_hold( $to, work_type )`.
- `Materialise::run( string $today ): int` — for each `Sources::due( $today )`: `$dates = Rule::due_between( rule, next_due, today, ends_on )`; for each date: if `Occurrences::claim( id, date )`: `Items::create( site, client, values, 0 )` with title `"{title} — {j M}"`, `problem` = description (or the title when empty), `level` `sub-feature`, `work_type`, seats, hours, `priority` `normal`, `planned_start`/`planned_due` = date, `commercial_class` `unclassified`, `recurring_id`; `Transition::record_creation`; `Transition::place( item, 'up-next', 0, 'Recurring task due ' . date )`; `Occurrences::record_item`. Then `Sources::set_next_due( id, Rule::next_on_or_after( rule, tomorrow ) )` (or `end` when past `ends_on`). Paused sources: `next_due` moved forward, nothing created. Returns the count created.
- Called from `WorkItemsController::index`, `index_all`, `StandupController` list and `RecurringController::index`, guarded by a per-request static so it runs once, and by a transient `bwx_forge_recurring_ran` (60 s) so a busy board does not re-scan every second.

- [ ] Unit test for the title format and the values Materialise builds (`Materialise::values( $source, $date )` pure). Implement. Commit.

### Task 4: REST

**Files:** `includes/Rest/RecurringController.php`, `includes/Rest/Server.php` (register).

Routes under `/recurring`: `GET` (list for the studio site, with `last` occurrence and `next_due`; `SCOPE_LIST` narrowed by `Reach::reaches_site` on the studio site; `denied: true` when out of reach, as `/standup` does), `POST` (create, `manage`), `GET /{id}`, `PATCH /{id}` (update; status `paused`/`active` via the same route; `manage`), `DELETE /{id}` (`end` — sources are ended, not removed, so occurrences keep their link; `manage`), `POST /run` (`Materialise::run( today )`, `manage`, returns `{ created }`).

- [ ] Playwright `tests/e2e/recurring-rest.spec.js`: create a daily source for the studio site with seats; `POST /recurring/run` → `created: 1`; `GET /work-items?client_site_id=<studio>` has an item titled with today's date at `up-next` with the seats; run again → `created: 0`; pause, run → 0; `DELETE` → status `ended`. Commit.

### Task 5: The screen

**Files:** `src/components/RecurringScreen.tsx`, `src/App.tsx` (rail entry under Delivery: `{ key: 'recurring', label: 'Recurring tasks', icon: Repeat }`, opening `{ crumbs: ['Delivery','Recurring tasks'], eyebrow: 'Every day, week or month', tile: Repeat, hue: 'teal' }`), `src/types.ts` (`ScreenName` + 'recurring'; `RecurringSource` type), `src/styles.css`.

- Table: title, cadence (`describe` text from the API), seats (names via `/users` like the panel's `everybody()`), hours, next due, last created (link opens the item panel), status. "Add recurring task" opens a side panel (same `bwx-panel` markup as NewWork) with title, description, type, rule (every: day/week/month; weekday checkboxes; day-of-month number), starts on, ends on, three seat selects, three hours inputs. Row actions: Edit (same panel), Pause/Resume, End (confirm), "Create today's now" (calls `/recurring/run`, admin only).
- `useLiveReload( load )` like every screen.

- [ ] Playwright `tests/e2e/recurring-screen.spec.js`: add a weekly task through the form for today's weekday, click "Create today's now", open Kanban on the studio site → the card is in Up Next; My tasks (signed in as the Primary) lists it. Commit.

### Task 6: Version, changelog, checks, PR

- [ ] `2.103.0`; changelog "Added — Recurring tasks: set something to repeat daily, weekly or monthly with the people and hours it needs, and each due day becomes a normal task in Up Next the first time anyone opens Forge." Lint once, build, PHPUnit, Playwright on a fresh instance. Push `recurring-tasks`, draft PR against `session-cache`.
