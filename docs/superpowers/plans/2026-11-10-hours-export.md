# Hours Export Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** From a site's Support screen, download a CSV statement of the client's hours for a date range, optionally limited to one project or milestone.

**Architecture:** One new read route on `SupportController` returns the counting ledger entries in range (holds left out), the opening/closing/held figures and each task's milestone and project, as JSON. A pure browser module turns that into rows and CSV text; the Support screen's dialog downloads it. Nothing is stored.

**Tech Stack:** PHP 8 (WordPress REST, PHPUnit), React + TypeScript (Vite), plain `.mjs` modules tested with `node --test`, Playwright.

**Spec:** `docs/superpowers/specs/2026-11-10-hours-export-design.md`

## Global Constraints

- Branch `export-hours-csv` from `main`, after opening the GitHub issue; commits reference its number.
- Every route is registered through `Server::register_route()` with a permission callback and a scope declaration (see `SupportController::register_routes()`).
- Holds never appear on the sheet: `work-reservation`, `work-release`, `meeting-reservation`, `meeting-release` are left out everywhere.
- Days are UTC days; `from`/`to` are `YYYY-MM-DD`, inclusive, `to` not before `from`, at most 366 days apart.
- Hours are rounded to two decimals, as `ledgerLines.mjs` rounds.
- CSV: UTF-8 with BOM, `\r\n` line endings, a field is quoted when it holds `,` `"` or a newline (and `"` is doubled).
- File name: `<client>-<site>-hours-<from>-to-<to>[-<scope title>].csv`, lower case, runs of anything but `a-z0-9` become one `-`.
- New screens and dialogs use the kit in `src/kit/` only.
- `npm run lint`, `npm run build`, `composer lint`, `npm run test:unit`, `vendor/bin/phpunit` all pass before the PR; minor version bump + changelog + `src/rules.ts` entry in the same PR.
- The studio test instance is live-linked: never build or edit PHP while a local e2e run is in progress.

## Review Focus

1. A task whose only in-range entry is a #641 refund adjustment (hours lowered after it was done): it must fold under the task as a positive `Work done` correction, not appear as a bare `Adjustment`. Pinned in Task 4.
2. A milestone picked as scope whose tasks sit two levels down (milestone → feature → sub-feature): every descendant counts, not just direct children. Pinned in Task 2.
3. `under` naming a task on another site, or a sub-feature rather than a project/milestone: refused with a 400, never an empty sheet that looks right. Pinned in Task 3.
4. A title holding a comma and quotes (`Fix "header", mobile`): the CSV cell stays one cell when opened in Excel. Pinned in Task 4.
5. A range with no entries at all: the all-work sheet still has its opening, closing and held rows, and the numbers agree (opening = closing). Pinned in Task 4.

---

### Task 1: The statement's arithmetic, in pure PHP

**Files:**
- Create: `includes/Commerce/Statement.php`
- Test: `tests/php/StatementTest.php`

**Interfaces:**
- Produces:
  - `Statement::counts( string $event_type ): bool` — false for the four hold types, true otherwise.
  - `Statement::sum_through( array $entries, int $at ): float` — sum of counting entries with `occurred_at <= $at`, two decimals.
  - `Statement::held_at( array $entries, int $at ): float` — sum of hold entries (reservations and releases) with `occurred_at <= $at`, two decimals; negative when hours are held.
  - `Statement::in_range( array $entries, int $from, int $to ): array` — counting entries with `$from <= occurred_at <= $to`, in the order given.
  - `Statement::MAX_DAYS = 366`.
  - `Statement::range( string $from, string $to ): array|WP_Error` — `array( 'from' => int, 'to' => int )` where `from` is 00:00:00 UTC of that day and `to` is 23:59:59 UTC of that day; `WP_Error( 'bwx_forge_invalid_range', … )` for a bad date, `to` before `from`, or more than `MAX_DAYS` apart.

- [ ] **Step 1: Write the failing tests** in `tests/php/StatementTest.php` (same bootstrap as `tests/php/HourLedgerTest.php`):

```php
public function test_holds_do_not_count(): void {
    $this->assertFalse( Statement::counts( 'work-reservation' ) );
    $this->assertFalse( Statement::counts( 'meeting-release' ) );
    $this->assertTrue( Statement::counts( 'work-usage' ) );
    $this->assertTrue( Statement::counts( 'adjustment' ) );
}

public function test_sums_split_hours_from_holds(): void {
    $entries = array(
        $this->entry( 'allocation', 20, 100 ),
        $this->entry( 'work-reservation', -3, 200 ),
        $this->entry( 'work-release', 3, 300 ),
        $this->entry( 'work-usage', -3, 300 ),
        $this->entry( 'work-reservation', -2, 400 ),
    );
    $this->assertSame( 17.0, Statement::sum_through( $entries, 400 ) );
    $this->assertSame( -2.0, Statement::held_at( $entries, 400 ) );
    $this->assertSame( 20.0, Statement::sum_through( $entries, 250 ) );
    $this->assertSame( -3.0, Statement::held_at( $entries, 250 ) );
    $this->assertSame( array( 'work-usage' ), array_column( Statement::in_range( $entries, 300, 400 ), 'event_type' ) );
}

public function test_range_is_inclusive_utc_days(): void {
    $range = Statement::range( '2026-03-01', '2026-03-31' );
    $this->assertSame( strtotime( '2026-03-01 00:00:00 UTC' ), $range['from'] );
    $this->assertSame( strtotime( '2026-03-31 23:59:59 UTC' ), $range['to'] );
    $this->assertInstanceOf( WP_Error::class, Statement::range( '2026-03-31', '2026-03-01' ) );
    $this->assertInstanceOf( WP_Error::class, Statement::range( '2026-02-30', '2026-03-01' ) );
    $this->assertInstanceOf( WP_Error::class, Statement::range( '2025-01-01', '2026-01-03' ) );
}
```
`$this->entry( $type, $hours, $at )` is a private helper returning `array( 'event_type' => $type, 'hours' => $hours, 'occurred_at' => $at )`.

- [ ] **Step 2: Run to verify it fails.** Run: `vendor/bin/phpunit --filter StatementTest`. Expected: FAIL, class not found.

- [ ] **Step 3: Implement `Statement`** in `includes/Commerce/Statement.php`, namespace `Blueworx\Forge\Commerce`, `final class`, static methods per the Interfaces block. Reuse the four `Entries::*_RESERVATION` / `*_RELEASE` constants for the hold set. `range()` parses with `strtotime( $day . ' 00:00:00 UTC' )` and refuses when `gmdate( 'Y-m-d', $parsed ) !== $day` (so `2026-02-30` is refused), as `ReportsController::timestamp()` does.

- [ ] **Step 4: Run to verify it passes.** Run: `vendor/bin/phpunit --filter StatementTest`. Expected: OK.

- [ ] **Step 5: Commit.** `git add includes/Commerce/Statement.php tests/php/StatementTest.php && git commit -m "Add the hours statement arithmetic (#<issue>)"`

---

### Task 2: A task's milestone and project

**Files:**
- Create: `includes/Work/Lineage.php`
- Test: `tests/php/LineageTest.php`

**Interfaces:**
- Produces:
  - `Lineage::of( array $items, string $id ): array` — `$items` is rows keyed by id, each with `id`, `parent_id`, `level`, `title`. Returns `array( 'milestone' => array|null, 'project' => array|null )`, each `array( 'id' => string, 'title' => string )`, found by walking `parent_id` upward; `level` `'milestone'` fills `milestone`, `level` `'project'` fills `project`. Stops at a missing parent or after 10 hops.
  - `Lineage::under( array $items, string $root, string $id ): bool` — true when `$id` is `$root` or any descendant of it, walking upward the same way.
  - `Lineage::for_site( string $client_site_id ): array` — rows keyed by id for every item on the site including archived (`Items::for_site( $id, array( 'include_archived' => true ) )`), with only `id`, `parent_id`, `level`, `title`, `archived` kept.

- [ ] **Step 1: Write the failing tests:**

```php
public function test_walks_up_to_milestone_and_project(): void {
    $items = $this->tree(); // p1 (project) > m1 (milestone) > f1 (feature) > s1 (sub-feature); loose (sub-feature, no parent)
    $this->assertSame( array( 'milestone' => array( 'id' => 'm1', 'title' => 'Phase one' ), 'project' => array( 'id' => 'p1', 'title' => 'Rebuild' ) ), Lineage::of( $items, 's1' ) );
    $this->assertSame( array( 'milestone' => null, 'project' => array( 'id' => 'p1', 'title' => 'Rebuild' ) ), Lineage::of( $items, 'm1' ) );
    $this->assertSame( array( 'milestone' => null, 'project' => null ), Lineage::of( $items, 'loose' ) );
}

public function test_under_counts_every_descendant(): void {
    $items = $this->tree();
    $this->assertTrue( Lineage::under( $items, 'm1', 's1' ) );
    $this->assertTrue( Lineage::under( $items, 'p1', 's1' ) );
    $this->assertTrue( Lineage::under( $items, 'm1', 'm1' ) );
    $this->assertFalse( Lineage::under( $items, 'm1', 'loose' ) );
}

public function test_a_cycle_does_not_hang(): void {
    $items = array( 'a' => $this->row( 'a', 'b', 'feature' ), 'b' => $this->row( 'b', 'a', 'feature' ) );
    $this->assertSame( array( 'milestone' => null, 'project' => null ), Lineage::of( $items, 'a' ) );
}
```

- [ ] **Step 2: Run to verify it fails.** Run: `vendor/bin/phpunit --filter LineageTest`. Expected: FAIL.

- [ ] **Step 3: Implement `Lineage`** in `includes/Work/Lineage.php`, namespace `Blueworx\Forge\Work`. `for_site()` is the only method that touches the database and is not unit-tested here.

- [ ] **Step 4: Run to verify it passes.** Run: `vendor/bin/phpunit --filter LineageTest`. Expected: OK.

- [ ] **Step 5: Commit.** `git commit -m "Walk a task up to its milestone and project (#<issue>)"`

---

### Task 3: The export route

**Files:**
- Modify: `includes/Rest/SupportController.php` (`register_routes()`, around lines 65–95; new method `export()`; `entry()` reused)
- Test: `tests/e2e/support-export-rest.spec.js`

**Interfaces:**
- Consumes: Task 1 `Statement::*`, Task 2 `Lineage::*`, existing `Ledger::for_site()`, `ClientSites::get()`, `Clients::get()` (or however `read()` names the client), `Assignments::for_site()` + `Support::entitlement_on()` (as `summary()` uses them) for the current period's start.
- Produces: `GET /blueworx-forge/v1/client-sites/{site_id}/support/export?from&to[&under]` answering:

```json
{
  "site": { "id": "cs_…", "name": "acme.com" },
  "client": { "id": "cl_…", "name": "Acme" },
  "from": "2026-03-01", "to": "2026-03-31",
  "opening": 20, "closing": 14.5, "held": -2,
  "first_entry_on": "2025-11-02",
  "period_starts_on": "2026-01-01",
  "scope": null,
  "entries": [ { "...the support read's entry shape...", "task": { "id": "wi_…", "title": "…", "milestone": {"id":"…","title":"…"}, "project": {"id":"…","title":"…"} } } ],
  "items": [ { "id": "wi_…", "title": "…", "level": "project|milestone", "parent_id": "", "archived": false } ]
}
```
`task` is present only on entries whose `source_type` is `work-item`. `scope` is `{ "id", "title", "level" }` when `under` was given. With `under`, `entries` holds only work-item entries whose task is under it (`Lineage::under`), and `opening`/`closing`/`held` are still the site's. `items` is every project and milestone on the site, archived included. Missing `from`/`to` default to `period_starts_on` (or `first_entry_on` when no period) and today.

- [ ] **Step 1: Write the failing Playwright REST test** `tests/e2e/support-export-rest.spec.js`, after `tests/e2e/support-rest.spec.js`'s pattern (`Forge.signedIn`, `Forge.makeSite`, `Forge.onSupport`, `Forge.makeItem`, `Forge.walkTo` to `in-development` so a task uses hours):
  - make a site on support with 20 hours, a project (`level: 'project'`), a milestone under it (`level: 'milestone'`), a feature under the milestone walked to `in-development` with `hours_primary: 2` chargeable, and a loose feature likewise with `hours_primary: 1`;
  - `GET /client-sites/{site}/support/export?from=2020-01-01&to=<today>`: expect status 200, `opening` 0, `closing` = 20 − used hours of both tasks, `held` 0 after both are done (or the reservations' sum if the walk leaves holds; assert `closing + held` equals `Forge.hourLedger(admin, site.id).balance`), no entry with an `event_type` ending `-reservation` or `-release`, the feature's entry carrying `task.milestone.id` and `task.project.id`;
  - with `&under=<milestone>`: only the one task's entries, `scope.level` `'milestone'`;
  - with `&under=<loose feature>`: status 400; with `&under=` a work item on another site: 400; `from` after `to`: 400; `to` 400 days after `from`: 400.

- [ ] **Step 2: Run to verify it fails.** Run: `npx playwright test tests/e2e/support-export-rest.spec.js` against `npm run wp:up`. Expected: FAIL with 404 `rest_no_route`.

- [ ] **Step 3: Register the route.** In `register_routes()`, add `array( 'GET', '/export', 'export' )` to `$routes` and treat it as a read: change `$read = 'read' === $callback;` to `$read = in_array( $callback, array( 'read', 'export' ), true );`.

- [ ] **Step 4: Implement `public static function export( WP_REST_Request $request )`**: resolve the site as `read()` does; `Statement::range()` on the params (defaults as above); `Ledger::for_site( $id )` once; `opening = Statement::sum_through( $all, $from - 1 )`, `closing = Statement::sum_through( $all, $to )`, `held = Statement::held_at( $all, $to )`; `Statement::in_range()`; map through `self::entry()` and add `task` via `Lineage::of( Lineage::for_site( $id ), $source_id )`, title from the same rows; `under` checked against those rows (level project or milestone, on this site) else `WP_Error( 'bwx_forge_invalid_scope', …, array( 'status' => 400 ) )`; filter with `Lineage::under()`.

- [ ] **Step 5: Run to verify it passes.** Run the spec again. Expected: PASS.

- [ ] **Step 6: Commit.** `git commit -m "Add the hours export route (#<issue>)"`

---

### Task 4: Rows and CSV in the browser

**Files:**
- Create: `src/hoursExport.mjs`
- Test: `tests/unit/hours-export.test.mjs`

**Interfaces:**
- Consumes: `ledgerLines(entries)` from `src/ledgerLines.mjs` (folds per `source_type:source_id`; a folded line has `kind`, `about`, `used`, `reserved`, `hours`, `label`, `when`, `history`, `source_id`).
- Produces:
  - `statementRows(answer)` → `Array<{ date, what, task, milestone, project, note, hours, balance }>` with `hours` and `balance` as numbers or `null`. All-work: `Balance brought forward` (date = day before `from`, hours null, balance = opening), the folded lines (balance running from opening), `Balance carried forward` (date = `to`, balance = closing), `Held for work in progress` (hours = held, balance null; only when held ≠ 0). Scope (`answer.scope` set): the folded work lines, then `Total` with hours = the positive sum of their used hours, balance null.
  - `csvText(rows)` → string beginning with `﻿`, header `Date,What,Task,Milestone,Project,Note,Hours,Balance after`, numbers as `toFixed(2)`, `null` as empty, `\r\n` endings, quoting per Global Constraints.
  - `fileName(answer)` → per Global Constraints.
  - `whatFor(line)` → the screen's words for a folded line or lone entry: reuse `ledgerLines`' `label` for work/meeting lines; for lone entries map `allocation`→`Package hours`, `top-up`→`Top-up`, `rollover`→`Carried over`, `expiry`→`Expired`, `adjustment`→`Adjustment` (copy the map `SupportScreen.tsx` uses at its line ~43 rather than inventing one).

- [ ] **Step 1: Write the failing tests** in `tests/unit/hours-export.test.mjs` with the same `entry()`/`work()` builders as `tests/unit/ledger-lines.test.mjs`, plus `answer({ entries, opening, closing, held, scope })`:
  - `all-work rows open, run and close, and the lines account for the difference`: `opening` 0, entries allocation +20, work-usage −3 (task under m1/p1), top-up +5, adjustment +1 reason "Goodwill", `closing` 23, `held` −2 → rows: Balance brought forward (balance 0), Package hours +20 (20), Work done −3 (17, milestone and project titles filled), Top-up +5 (22), Adjustment +1 note Goodwill (23), Balance carried forward (23), Held for work in progress −2 (balance null).
  - `a refund folds under its task` (#641): work-usage −3 then adjustment +0.5 with `source_type: 'work-item'`, same `source_id` → one `Work done` row, hours −2.5.
  - `a range with nothing in it still reconciles`: `entries: []`, opening 10, closing 10 → exactly brought forward, carried forward (and no held row when `held: 0`).
  - `scope keeps only the work under the item and totals it`: two tasks, one with `task.milestone.id` `m1`, one loose, `scope: { id: 'm1', … }` → the loose one is absent (the server already filtered; the module just trusts `entries`), rows end with `Total` hours 3.00, `balance` null on every row.
  - `cells with commas and quotes stay one cell`: task `Fix "header", mobile` → the CSV line holds `"Fix ""header"", mobile"`.
  - `the file is named for the client, site, range and scope`: `Acme Ltd` / `acme.com` / 2026-03-01..2026-03-31 / scope `Phase One!` → `acme-ltd-acme-com-hours-2026-03-01-to-2026-03-31-phase-one.csv`.
  - `the text starts with a byte-order mark and uses CRLF`.

- [ ] **Step 2: Run to verify it fails.** Run: `node --test tests/unit/hours-export.test.mjs`. Expected: FAIL, module not found.

- [ ] **Step 3: Implement `src/hoursExport.mjs`** per the Interfaces block, importing `ledgerLines`. Rounding: `Math.round(v * 100) / 100` as `ledgerLines.mjs` does.

- [ ] **Step 4: Run to verify it passes.** Run: `npm run test:unit`. Expected: all pass.

- [ ] **Step 5: Commit.** `git commit -m "Build the hours statement rows and CSV (#<issue>)"`

---

### Task 5: The Export CSV dialog on the Support screen

**Files:**
- Modify: `src/components/SupportScreen.tsx` (ledger `DataView` at ~line 308: add the button; a new `ExportDialog` component in the same file, after the existing `Modal` components at ~472–695)
- Modify: `src/types.ts` (add `SupportExportAnswer` matching Task 3's JSON)
- Create: `src/download.ts` — `export function downloadText(name: string, text: string, type = 'text/csv;charset=utf-8'): void` (Blob → object URL → temporary `<a download>` → revoke)
- Modify: `src/rules.ts` — new section `{ id: 'hours', title: 'Support hours', rules: [ … ] }` placed after `'requests'`
- Test: covered by Task 6 (end to end) and `npm run build`

**Interfaces:**
- Consumes: Task 3's route via `api<SupportExportAnswer>('/client-sites/' + siteId + '/support/export?from=…&to=…[&under=…]', { cache: 'fresh' })`; Task 4's `statementRows`, `csvText`, `fileName`.

- [ ] **Step 1: Add the button.** Next to the ledger table's footer/heading, `<Button size="sm" data-testid="bwx-support-export">Export CSV</Button>` opening the dialog. Only rendered when `answer` is loaded.

- [ ] **Step 2: Build `ExportDialog`** with the kit's `Modal`, `Field`, `TextInput type="date"` ×2, `Select` for scope, `Button` Download. On open it calls the route once with no range to learn `items`, `period_starts_on`, `first_entry_on`, and sets the defaults (period start → today; switching scope to a project or milestone sets From to `first_entry_on`). Download calls the route with the chosen `from`/`to`/`under`, then `downloadText(fileName(answer), csvText(statementRows(answer)))`. A `WP_Error` from the route shows in the dialog's error line with the server's message. Test ids: `bwx-export-from`, `bwx-export-to`, `bwx-export-scope`, `bwx-export-download`, `bwx-export-error`.

- [ ] **Step 3: Add the rules entry** in `src/rules.ts`:
  - what: `From a site's Support screen, Export CSV downloads a statement of the client's hours for a date range: hours added and hours used, one line per task or meeting, opening and closing balances, and the hours held for work in progress. Pick a project or release to get only the work under it, with its total. Holds are never on the sheet.`
  - why: `A client asking where their hours went needs something they can open and keep, and it has to add up to what the screen shows.`

- [ ] **Step 4: Build.** Run: `npm run build`. Expected: no errors.

- [ ] **Step 5: Commit.** `git commit -m "Export a site's hours as CSV from Support (#<issue>)"`

---

### Task 6: End to end

**Files:**
- Test: `tests/e2e/support-export.spec.js`

- [ ] **Step 1: Write the test** after `tests/e2e/support-ledger-lines.spec.js`: same setup as Task 3's spec (site on 20 hours, project → milestone → feature done with 2h, loose feature done with 1h). Open Support, pick the site, click `bwx-support-export`, leave the range, `const [download] = await Promise.all([page.waitForEvent('download'), page.getByTestId('bwx-export-download').click()])`, read `await download.path()` into text and assert: starts with `﻿`, header line, a `Work done` line for each task with `-2.00`/`-1.00` (use the planned sums the walk leaves, as the ledger-lines spec computes `PLANNED`), `Balance carried forward` equals `20 - used` and reconciles with `Forge.hourLedger(admin, site.id).balance` after adding the `Held` row. Then choose the milestone in `bwx-export-scope`, download again, assert the loose task is absent and the last line is `Total,…,2.00`. Assert the suggested file name matches `fileName` rules.

- [ ] **Step 2: Run it.** Run: `npx playwright test tests/e2e/support-export.spec.js` against `npm run wp:up`. Expected: PASS.

- [ ] **Step 3: Commit.** `git commit -m "Prove the hours export end to end (#<issue>)"`

---

### Task 7: Ship

**Files:**
- Modify: `package.json`, `blueworx-forge.php` (header + version constant), `client/blueworx-forge-client.php` (header, must match), `CHANGELOG.md`

- [ ] **Step 1: Bump** the minor version everywhere the repo checks (see "Both plugin headers carry the same version" in `CLAUDE.md`); changelog entry under the new version, dated today, in the user's words: "Export a client's hours as CSV from Support, for a date range or one project or release."
- [ ] **Step 2: Run everything once.** `npm run lint`, `npm run build`, `composer lint`, `npm run test:unit`, `vendor/bin/phpunit`. Present lint findings; fix only on approval.
- [ ] **Step 3: Commit, push, draft PR** linked to the issue: what it does, what was tested, the holds decision called out for Luke.
