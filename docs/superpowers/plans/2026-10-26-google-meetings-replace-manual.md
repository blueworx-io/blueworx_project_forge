# Google meetings replace manual meetings Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Google meetings get a per-person start date, remember a recurring meeting's client, fill in studio time for all-staff meetings, and show on the Meetings page beside manual ones (#657).

**Architecture:** Two new columns on the Google meetings table (`series_key`, `prefill`) and one new table (the series' remembered choice) carry the new state. A pre-filled client is a real `client_site_id` (so hours are set aside by the existing `Hours` rules), but `prefill <> ''` keeps the meeting "not settled", which is what hides duplicates (`Matching`) and lets a disconnect clear it. The Meetings page reads Google meetings by site and folds them into the answer it already gives.

**Tech Stack:** PHP 8 (WordPress plugin, `$wpdb`, PHPUnit with stubs), React + TypeScript (Vite), Playwright against the local WordPress harness.

**Spec:** `docs/superpowers/specs/2026-10-26-google-meetings-replace-manual-design.md`

## Global Constraints

- Branch `google-meetings-replace-manual`; commits reference `#657`.
- Version bump is minor: `2.214.1` → `2.215.0` in `package.json`, the studio header and `BWX_FORGE_VERSION` in `blueworx-forge.php`, and the client header in `client/blueworx-forge-client.php` (CI fails if they disagree). `package-lock.json`'s own version is left alone.
- `CHANGELOG.md` gets a `## [2.215.0] - 2026-10-26` entry in plain words; `src/rules.ts` is updated in the same PR.
- `Schema::VERSION` goes `49` → `50`. dbDelta formatting rules in `Schema::definitions()`' docblock apply (new columns nullable or with defaults).
- No new dependencies.
- Hours rules are reused, never reimplemented: anything that changes a meeting's client calls `Hours::settle_one()`.
- Google is never written to.
- Local tests are targeted (only the specs a task touches); CI runs the full suite. Never build or edit PHP while a local e2e run is going (the test site is live-linked).
- PHP follows `composer lint`; JS/TS follows `npm run lint`. Run each once at the end, report findings, don't loop.

## Review Focus

1. **Reconnecting** — disconnect then reconnect must keep the original start date; old meetings must not reappear. Test in Task 1.
2. **A held or charged meeting in a series** — changing the series' client must never touch a meeting already marked held, cancelled or nobody came. Test in Task 3.
3. **Attendee email case and domain** — `Jess@BlueWorx.io` is staff when the staff list has `jess@blueworx.io`; a guest at `blueworx.io.evil.com` is not. Test in Task 4.
4. **A guest added in Google after the meeting arrived as studio time** — it must go back to "Pick a client…" on the next sync, and give back nothing it never held. Test in Task 4.
5. **Private meetings on the client page** — a Manager who isn't on a private meeting sees "Busy", as standup does. Test in Task 6.

---

### Task 1: A start date for each person

**Files:**
- Create: `includes/Google/Starts.php`
- Modify: `includes/Google/Sync.php` (`window()`, `read()`)
- Modify: `includes/Google/Meetings.php` (add `forget_before()`)
- Modify: `includes/Google/OAuth.php` (record the start on a successful connect)
- Test: `tests/php/GoogleStartsTest.php`, `tests/e2e/google-calendar.spec.js`

**Interfaces:**
- Produces: `Starts::OPTION = 'bwx_forge_google_starts'` (array `user_id => 'Y-m-d'`, not autoloaded); `Starts::with(array $map, string $user_id, string $day): array` (pure, never overwrites); `Starts::of(string $user_id): string` (stored day, else backfilled from the connection's `connected_at` in `wp_timezone()` and stored, else `''`); `Starts::record(string $user_id): void` (today, via `with`); `Starts::window_from(string $back_from, string $start): string` (pure, the later of the two, `$start` may be `''`).
- `Sync::window(string $user_id = ''): array{0:string,1:string}` — `from` becomes `Starts::window_from( today − BACK_DAYS, Starts::of( $user_id ) )`.
- `Meetings::forget_before(string $user_id, string $start): void` — like `forget_unsettled()` but only copies with `on_date < $start`.

- [ ] **Step 1: Write the failing unit tests** in `tests/php/GoogleStartsTest.php`:
  - `test_a_first_day_is_kept()` — `Starts::with([], 'usr_a', '2026-10-26')` === `['usr_a' => '2026-10-26']`.
  - `test_a_later_day_never_moves_it()` — `Starts::with(['usr_a' => '2026-10-26'], 'usr_a', '2026-11-03')['usr_a']` === `'2026-10-26'`.
  - `test_the_window_starts_at_the_later_of_the_two()` — `window_from('2026-09-28', '2026-10-26')` === `'2026-10-26'`; `window_from('2026-09-28', '')` === `'2026-09-28'`; `window_from('2026-11-01', '2026-10-26')` === `'2026-11-01'`.
- [ ] **Step 2: Run** `vendor/bin/phpunit --filter GoogleStartsTest` — expect FAIL (class not found).
- [ ] **Step 3: Implement `Starts`**, then use it: `Sync::read()` takes the window for `$user_id`; after every successful read, full or not, it calls `Meetings::forget_before( $user_id, Starts::of( $user_id ) )` when the start is not `''` (so the old meetings already on a live site go on the first sync after release, not the next full one). `OAuth`'s successful callback calls `Starts::record()` after the connection is stored. Disconnecting does not touch the option.
- [ ] **Step 4: Run** `vendor/bin/phpunit --filter "GoogleStartsTest|GoogleEventsTest"` — expect PASS.
- [ ] **Step 5: Add e2e** `test('a meeting before the person first connected never shows')` to `tests/e2e/google-calendar.spec.js`: connect a fresh person, put an event 3 days ago and one tomorrow, force a sync; `googleEntries()` for `day(-5)..day(5)` holds the tomorrow one only. Then disconnect, reconnect, force a sync: still only tomorrow's.
- [ ] **Step 6: Run** `npx playwright test tests/e2e/google-calendar.spec.js --workers=1` (instance up via `npm run wp:up`) — expect PASS.
- [ ] **Step 7: Commit** `Give each person a Google start date (#657)`.

### Task 2: Series key and the pre-filled marker

**Files:**
- Modify: `includes/Data/Schema.php` (`VERSION` 50; `series_key varchar(32) NOT NULL DEFAULT ''`, `prefill varchar(10) NOT NULL DEFAULT ''`, `KEY series (series_key, on_date)` on the Google meetings table; new table `google_series_table()` → `bwx_forge_google_series`: `series_key varchar(32) NOT NULL`, `client_site_id varchar(32) NOT NULL DEFAULT ''`, `client_id varchar(32) NOT NULL DEFAULT ''`, `off_support tinyint(1) NOT NULL DEFAULT 0`, `updated_by bigint(20) unsigned NOT NULL DEFAULT 0`, `updated_at bigint(20) unsigned NOT NULL DEFAULT 0`, `PRIMARY KEY  (series_key)`)
- Modify: `includes/Google/Events.php` (`normalise()` adds `series_key`)
- Modify: `includes/Google/Meetings.php` (`apply()` writes `series_key`; `hydrate()`; `settle()` clears `prefill`)
- Modify: `includes/Tenancy/ClientErasure.php` if it lists Google tables (keep erasure complete)
- Test: `tests/php/GoogleEventsTest.php`, `tests/php/SchemaTest.php`, new `tests/php/GoogleMeetingsHydrateTest.php`

**Interfaces:**
- Produces: normalised event key `series_key` — `md5( iCalUID ?: id )` **without** the `@start` suffix when `recurringEventId` is set, `''` for a one-off. Hydrated meeting gains `series_key: string`, `prefill: '' | 'series' | 'studio'`, and `settled` becomes `( '' !== client_site_id && '' === prefill ) || status !== scheduled`. `Meetings::PREFILL_SERIES = 'series'`, `Meetings::PREFILL_STUDIO = 'studio'`.

- [ ] **Step 1: Write failing tests:**
  - `GoogleEventsTest::test_occurrences_of_one_series_share_a_series_key()` — two items with the same `iCalUID`, `recurringEventId`, different `originalStartTime`: equal `series_key`, different `share_key`.
  - `GoogleEventsTest::test_a_one_off_has_no_series_key()` — `series_key` === `''`.
  - `GoogleMeetingsHydrateTest::test_a_prefilled_meeting_is_not_settled()` — row with `client_site_id` set, `prefill 'series'`, `status 'scheduled'` → `settled` false; same with `prefill ''` → true; `prefill 'studio'`, `status 'held'` → true.
  - `SchemaTest` — whatever it asserts per table, extended to the new table and columns.
- [ ] **Step 2: Run** `vendor/bin/phpunit --filter "GoogleEventsTest|GoogleMeetingsHydrateTest|SchemaTest"` — expect FAIL.
- [ ] **Step 3: Implement.** `Meetings::settle()` adds `'prefill' => ''` to its changes (a person's pick is never a pre-fill).
- [ ] **Step 4: Re-run** — expect PASS.
- [ ] **Step 5: Commit** `Keep a Google meeting's series and whether Forge filled it in (#657)`.

### Task 3: A recurring meeting remembers its client

**Files:**
- Create: `includes/Google/SeriesChoice.php`
- Modify: `includes/Google/Meetings.php` (`apply()` pre-fills new and pre-fillable rows; add `prefill_changes()`)
- Modify: `includes/Rest/GoogleController.php` (`settle()` calls `SeriesChoice::remember()`)
- Test: `tests/php/GooglePrefillTest.php`, `tests/e2e/google-calendar.spec.js`

**Interfaces:**
- Produces:
  - `SeriesChoice::get(string $series_key): ?array{client_site_id, client_id, off_support: bool}`
  - `SeriesChoice::remember(array $meeting, string $site_id, string $client_id, bool $off_support, int $actor): int` — `0` when `series_key` is `''` or the stored `client_site_id` already equals `$site_id` (a tick-only change is one-off). Otherwise stores the choice and updates every meeting with the same `series_key`, `on_date >= $meeting['on']`, `id <> $meeting['id']`, `status = 'scheduled'`, and (`client_site_id = ''` or `prefill <> ''`) to the new site, client, `off_support` and `prefill = 'series'`; runs `Hours::settle_one()` on each; returns how many changed.
  - `Meetings::prefill_changes(array $row, ?array $choice, bool $internal, string $studio_site, string $studio_client): array` — pure. Empty array unless the row is pre-fillable (`status === 'scheduled'` and (`client_site_id === ''` or `prefill !== ''`)). Desired state: a series choice wins (`prefill 'series'`, its site, client, off_support); else `$internal` gives the studio (`prefill 'studio'`, `off_support 0`); else nothing (`client_site_id ''`, `client_id ''`, `off_support 0`, `prefill ''`). Returns only the fields that differ.
- Consumes: Task 2's `series_key`, `prefill`, `settled`.

- [ ] **Step 1: Write failing unit tests** in `tests/php/GooglePrefillTest.php` for `prefill_changes()`:
  - `test_a_series_choice_fills_an_empty_meeting()` → `['client_site_id' => 'cst_w', 'client_id' => 'cli_w', 'off_support' => 1, 'prefill' => 'series']`.
  - `test_a_persons_pick_is_never_overwritten()` — `client_site_id 'cst_x'`, `prefill ''` → `[]`.
  - `test_a_settled_outcome_is_never_overwritten()` — `status 'held'`, `prefill 'series'` → `[]`.
  - `test_the_series_choice_beats_studio_time()` — choice given and `$internal` true → `prefill 'series'`.
  - `test_studio_time_goes_when_it_stops_being_internal()` — row `prefill 'studio'`, studio site, `$internal` false, no choice → clears all four fields.
  (`Task 4` adds the internal-on case.)
- [ ] **Step 2: Run** `vendor/bin/phpunit --filter GooglePrefillTest` — expect FAIL.
- [ ] **Step 3: Implement** `SeriesChoice` and `prefill_changes()`. In `apply()`, after the insert/update, read the row back if needed and apply `prefill_changes( $row, SeriesChoice::get( $series_key ), false, Studio::site_id(), Studio::client_id() )` (Task 4 swaps `false` for the real check). Bump `record_version` with the write. Hours follow on the next `Hours::reconcile()` (the sync route already runs it).
- [ ] **Step 4: Wire `GoogleController::settle()`:** after `Hours::settle_one( $settled, … )`, call `SeriesChoice::remember( $settled, $site['id'], $site['client_id'], $off_support, get_current_user_id() )`. "No client" (the studio site) is a person's pick and is remembered.
- [ ] **Step 5: Re-run** `vendor/bin/phpunit --filter "GooglePrefillTest|GoogleHoursTest"` — expect PASS.
- [ ] **Step 6: Add e2e** to `tests/e2e/google-calendar.spec.js` (`timedEvent` with `recurringEventId` and `originalStartTime` overrides, three weekly occurrences from `day(1)`):
  - `test('picking a client for one meeting in a series fills in the later ones')` — settle week 1 to `site` with `off_support: true`; weeks 2 and 3 have `site_id === site.id`, `off_support === true`, `status === 'scheduled'`, and still appear in standup's to-settle list once due.
  - `test('a new meeting in the series arrives filled in')` — add week 4 in Google, sync: it has the site.
  - `test('changing only the support tick on one meeting stays on that meeting')` — settle week 2 with `off_support: false`, same site; week 3 still `true`.
  - `test('changing the client on a later meeting carries forward from there')` — settle week 2 to a second site; week 1 keeps `site`, weeks 3–4 move.
  - `test('a meeting already marked held keeps its client')` — mark week 1 held, then change week 2's client; week 1 unchanged (Review Focus 2).
- [ ] **Step 7: Run** `npx playwright test tests/e2e/google-calendar.spec.js --workers=1` — expect PASS.
- [ ] **Step 8: Commit** `Remember a recurring Google meeting's client (#657)`.

### Task 4: All-staff meetings fill in as studio time

**Files:**
- Create: `includes/Google/Internal.php`
- Modify: `includes/Google/Meetings.php` (`apply()` passes the real `$internal`)
- Test: `tests/php/GoogleInternalTest.php`, `tests/php/GooglePrefillTest.php`, `tests/e2e/google-calendar.spec.js`

**Interfaces:**
- Produces: `Internal::is_internal(array $attendees, array $staff_emails, array $domains): bool` — pure; `$attendees` as `Events::attendees()` gives them (the calendar owner already left out); compares lower-cased, trimmed emails; an email is staff if it is in `$staff_emails` or its part after the last `@` exactly equals one of `$domains`; no attendees → `true`. `Internal::staff_emails(): array` (lower-cased emails of `ClientStaff::people()`, cached for the request) and `Internal::domains(): array` (the studio client's `email_domains`, lower-cased).

- [ ] **Step 1: Write failing tests** in `tests/php/GoogleInternalTest.php`:
  - `test_only_staff_is_internal()` — `[['email' => 'Jess@BlueWorx.io']]`, staff `['jess@blueworx.io']` → true.
  - `test_the_studio_domain_counts_as_staff()` — `ross@blueworx.io`, domains `['blueworx.io']` → true.
  - `test_a_lookalike_domain_does_not()` — `x@blueworx.io.evil.com` → false.
  - `test_one_outside_guest_makes_it_a_client_meeting()` → false.
  - `test_no_guests_is_internal()` — `[]` → true.
  And in `GooglePrefillTest`: `test_an_internal_meeting_fills_in_as_studio_time()` → `prefill 'studio'`, studio site and client, `off_support 0`.
- [ ] **Step 2: Run** `vendor/bin/phpunit --filter "GoogleInternalTest|GooglePrefillTest"` — expect FAIL.
- [ ] **Step 3: Implement**, and in `apply()` pass `Internal::is_internal( (array) $event['attendees'], Internal::staff_emails(), Internal::domains() )`. The automatic studio choice is never stored in `SeriesChoice`.
- [ ] **Step 4: Re-run** — expect PASS.
- [ ] **Step 5: Add e2e** `test('an all-staff meeting arrives as studio time and goes back when a guest is added')`: event tomorrow whose only attendee is another staff person → after sync, `site_id` is the studio site, `needs_settling` true; then put the same event with an outside attendee, sync → `site_id === ''` (Review Focus 4). And `test('a remembered client is not overridden by studio time')` for a series whose later occurrence is internal.
- [ ] **Step 6: Run** `npx playwright test tests/e2e/google-calendar.spec.js --workers=1` — expect PASS.
- [ ] **Step 7: Commit** `Fill in studio time for all-staff Google meetings (#657)`.

### Task 5: Meetings page answers carry Google meetings

**Files:**
- Modify: `includes/Google/Meetings.php` (add `for_site()`, `earliest_for_site()`, `held_hours_by_site()`)
- Modify: `includes/Meetings/Diary.php` (add `held_hours_by_site()`)
- Modify: `includes/Rest/MeetingsController.php` (`answer()`, `past()`, `index_all()`)
- Modify: `src/types.ts`
- Test: `tests/e2e/meetings-rest.spec.js`, plus `tests/e2e/global-client-picker.spec.js` and `tests/e2e/client-delete.spec.js` where they read `/meetings`

**Interfaces:**
- Produces:
  - `Meetings::for_site(string $site_id, string $from, string $to): array` — hydrated Google meetings with that `client_site_id` (pre-filled included), `on_date` in range, ordered by `on_date, at_time`.
  - `Meetings::earliest_for_site(string $site_id): string` — earliest `on_date`, or `''`.
  - `Meetings::held_hours_by_site(): array<string, float>` and `Diary::held_hours_by_site(): array<string, float>` — one `SUM(planned_hours) … WHERE status = 'held' GROUP BY client_site_id` query each.
  - Site answer rows gain `source: 'forge' | 'google'`; Google rows also carry `google_id`, `title`, `off_support`, `can_settle`, `people` (names, declined marked as standup does), `private`. For Google rows `series_id ''`, `slot ''`, `series_title` = title (or `'Busy'` when private and the caller is neither admin nor on it), `excepted_from null`, `moved false`, `ledger_state` from the row. They are merged into `meetings` (today → horizon) and `past.meetings` (the page window, newest first) by date and time. `past.more` is also true when `earliest_for_site()` is before `from`.
  - `/meetings` answers `{ ok: true, clients: AllClientsRow[] }`, where `AllClientsRow = { client_site_id, client_id, client_name, site_name, held_hours: number, meetings: number }` — one row per site in reach with any manual series or any Google meeting with a client; `held_hours` is all-time manual + Google held; sorted by client name then site name.
- `src/types.ts`: `Meeting` gains the optional Google fields; `AllClientsMeetingsAnswer` becomes `{ ok: true; clients: AllClientsRow[] }`.

- [ ] **Step 1: Write failing e2e** in `tests/e2e/meetings-rest.spec.js`:
  - `test('a site\'s meetings include its Google meetings')` — a Google meeting settled to the site tomorrow appears in `meetings` with `source 'google'`; one held 3 days ago appears in `past.meetings`.
  - `test('every client lists all-time held meeting hours')` — a manual meeting held for 1h and a Google one held for 0.5h on one site → that row's `held_hours === 1.5`.
  - `test('a private Google meeting reads Busy to a Manager not on it')` (Review Focus 5).
  Update the existing `/meetings` assertions in the three specs to the new `clients` shape.
- [ ] **Step 2: Run** `npx playwright test tests/e2e/meetings-rest.spec.js --workers=1` — expect FAIL.
- [ ] **Step 3: Implement** the reads and the controller changes. `answer()` keeps its existing `Hours::reconcile_site()` call and also runs `Hours::settle_one()` over that site's Google meetings it returns, so their "Hours held" is current.
- [ ] **Step 4: Run** `npx playwright test tests/e2e/meetings-rest.spec.js tests/e2e/global-client-picker.spec.js tests/e2e/client-delete.spec.js --workers=1` — expect PASS.
- [ ] **Step 5: Commit** `Put Google meetings in the Meetings page answers (#657)`.

### Task 6: Meetings page screen

**Files:**
- Modify: `src/components/MeetingsScreen.tsx` (`AllClientsList`, the meeting columns, `loadAll`)
- Modify: `src/components/GoogleMeeting.tsx` only if `GoogleMeetingPanel` needs a prop to be opened from a `Meeting` row
- Test: `tests/e2e/meetings-app.spec.js`

**Interfaces:**
- Consumes: Task 5's answer shapes; `GoogleMeetingPanel` / `GoogleSettle` and `POST /google-meetings/<id>/settle` as they are.

- [ ] **Step 1: Write failing e2e** in `tests/e2e/meetings-app.spec.js`:
  - `test('All clients lists each client with its meeting hours and opens its page')` — rows `data-testid="bwx-meetings-all-row"` with `data-site`; the row shows the hours (`hoursLabel`); clicking opens that site's view (Standing meetings, The next twelve weeks, Past meetings still there).
  - `test('a Google meeting shows on its client\'s page and is settled there')` — the row has `data-source="google"` and a "Google" tag beside its title; it has no Move; its Settle opens the Google panel; marking it held changes "What happened" to Held.
- [ ] **Step 2: Run** `npx playwright test tests/e2e/meetings-app.spec.js --workers=1` — expect FAIL.
- [ ] **Step 3: Implement.** `AllClientsList` becomes a Panel titled "Meetings, every client" with columns Client, Site, Meetings, Hours used (all time); empty text "No client has any meetings yet." The site view is unchanged apart from Google rows: `What` shows the title plus a `Tag` "Google"; Actions for a Google row are a single Settle (shown when `can_settle`) opening `GoogleMeetingPanel` built from the row; `data-source` on the When cell. Use the existing kit only (`frontend-design` skill for the UI).
- [ ] **Step 4: Run** `npm run build`, then the spec again — expect PASS. Run `npm run dev` and ask Luke to look at the page before committing (visual confirmation step).
- [ ] **Step 5: Commit** the source and the rebuilt `assets/` as `Show Google meetings on the Meetings page (#657)`.

### Task 7: Rules page, version and changelog

**Files:**
- Modify: `src/rules.ts`, `CHANGELOG.md`, `package.json`, `blueworx-forge.php`, `client/blueworx-forge-client.php`

- [ ] **Step 1: Update `src/rules.ts`:** the Google entries at the "arrives with no client" and "Disconnecting" rules gain the four behaviours in plain words: meetings before a person first connected never come in; a recurring meeting's later dates arrive with the client last picked for it (a support-hours change on one date stays on that date); a meeting with only BlueWorx staff arrives as studio time, and stops being studio time if someone from outside is added; the Meetings page lists every client's all-time meeting hours and shows Google meetings on each client's page.
- [ ] **Step 2: Bump** to `2.215.0` in all four places; add the changelog entry under `### Added` / `### Changed`, written for the person using Forge.
- [ ] **Step 3: Run** `npm run lint`, `composer lint`, `npm run build`, `vendor/bin/phpunit` once each. Report lint findings to Luke rather than fixing them in a loop.
- [ ] **Step 4: Commit** `Rules, version and changelog for #657`, push, and open a draft PR linked to #657.
