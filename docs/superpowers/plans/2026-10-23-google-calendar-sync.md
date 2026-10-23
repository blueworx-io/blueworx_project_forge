# Google Calendar meetings — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Each staff member connects their own Google work calendar; its events come into Forge one way, show on the calendar, standup and capacity, and are settled to a client site with the existing meeting hour rules.

**Architecture:** Three new tables (connections, synced meetings, who is on each). A pure normaliser turns a Google event into Forge's shape; a sync class pages Google's Calendar API with sync tokens, triggered when the app opens (no cron). Hours reuse `Meetings\MeetingHours::plan()` / `state_of()` unchanged, against a new ledger source type; capacity reuses the allocation shape `Meetings\Load` makes.

**Tech Stack:** PHP 7.4+ (WordPress, `wp_remote_*`, libsodium via `Tenancy\Secrets`), React + TypeScript (src/kit), PHPUnit, Playwright.

**Spec:** `docs/superpowers/specs/2026-10-23-google-calendar-sync-design.md`

## Global Constraints

- Version 2.208.0 in package.json, blueworx-forge.php (header + `BWX_FORGE_VERSION`), client/blueworx-forge-client.php (header + `BWX_FORGE_CLIENT_VERSION`); CHANGELOG `## [2.208.0] - 2026-10-23`.
- Forge never writes to Google. Scope requested: `https://www.googleapis.com/auth/calendar.readonly` only.
- Tokens sealed with `Tenancy\Secrets`, never in any REST answer. Client secret write-only; shown back as its last four characters.
- No new dependency. No cron. Screens in the React app (ARCH-7). `src/rules.ts` updated.
- Hours of a timed meeting = length rounded up to the next half hour (`Recurrence::planned_hours()`).
- Sync window: 28 days back, 84 days ahead (`MeetingHours::HORIZON_DAYS`).
- Outside services are never called in tests: PHPUnit uses the queued `wp_remote_*` stubs; e2e uses an mu-plugin on `pre_http_request`.

## Rulings

- Ruling: storage is three new tables, not the occurrence table — occurrences are keyed to a series slot and a site; synced meetings have neither until settled — cost if wrong: a later merge of the two meeting kinds needs a migration.
- Ruling: ledger source type `google-meeting` (fits varchar(20)); `Commerce\Reconcile` and the support hours list learn it — cost: one more name to know.
- Ruling: OAuth callback is `wp-admin/admin-post.php?action=bwx_forge_google` (cookie-authenticated; a REST route would see no user on Google's redirect). Google allows a query string in a redirect URI — cost if wrong: Luke's redirect URI is refused and the route moves.
- Ruling: shared key = md5 of iCalUID (or event id) plus `originalStartTime` for a recurring instance, so each instance of a recurring invite is its own shared meeting — cost: two separately created events with the same iCalUID merge (Google does not do this).
- Ruling: an event counts against time only when timed, not declined by that person, not "free" (transparent) and not an out-of-office / working-location / focus-time event — the anti-goal says leave must not fill capacity; focus time is work, not a meeting — cost: a "free" event that was really a meeting needs re-marking in Google.
- Ruling: standing-meeting matching is worked out when meetings are read, not stored, so it follows either side moving without Google sending a change. Only unsettled meetings (no site, still scheduled) are skipped; one somebody already settled stays — cost: a settled meeting that later lines up with a standing one is counted twice until somebody cancels one.
- Ruling: a meeting is skipped if it matches a standing meeting for any of its people — the point is charging once — cost: a colleague on the Google event but not on the standing meeting loses that time from capacity; add them as an attendee.
- Ruling: overlap = same local day and `start < other.end && other.start < end`.
- Ruling: unsettled meetings count in capacity under the studio's own client/site — capacity rows want a client — cost: unsettled time reads as internal until settled.
- Ruling: "No client" saves the studio's site; time never comes off support for the studio's site.
- Ruling: once a meeting has used hours (marked held with time off support), its client and support choice cannot change (409) — matches "spend is a floor" — cost: a mistake is corrected by a ledger adjustment.
- Ruling: changing the client before it is held releases what the old site held.
- Ruling: sync runs when the app opens (`POST /me/google/sync`), at most every 5 minutes per person; "Sync now" forces it. A full sync runs on first connect, when Google says the sync token expired (410), and once a day; it removes that person's copies Google no longer returned.
- Ruling: deleted/cancelled in Google removes that person's copy; a meeting with nobody left is removed (its held hours released first) unless held. Disconnect removes only unsettled copies.
- Ruling: "pick a client ahead" is offered from the calendar (click a Google meeting); "held / cancelled / nobody came" from the standup, with the client picker on the same line.
- Ruling: "No client" goes through the same reach check as any site, so somebody who cannot reach the studio's own site cannot pick it — the design keeps internal meetings inside the access rules — cost: such a person asks an administrator to settle internal meetings.
- Ruling: the background sync on opening Forge does not clear the app's kept answers (a new `background()` call beside `api()`), or every page load would throw away the session's warm copies — cost: a meeting that arrives shows on the next read rather than at once.
- Ruling: synced meetings show to everyone in reach while unsettled (site ''), and once settled follow the site's reach — always to the people on them. A private event reads "Busy" to anyone not on it and not an administrator.

## Review Focus

1. Google returns 401 / `invalid_grant` on refresh (access withdrawn) → connection shows an error asking to connect again; nothing crashes; sync stops. Test in Task 3.
2. Google 410 on an incremental sync → full resync, not an error. Test in Task 3.
3. Two people connected to the same invite → one meeting, both people; one declines → still one meeting, only the other counts. Test in Task 4.
4. A meeting settled to a site then moved in Google → time and hours follow, settlement kept. Test in Task 7 (e2e).
5. The callback hit by a different signed-in user than the one who started it, or with a reused/unknown state → refused. Test in Task 5.

---

### Task 1: Event normaliser (pure)

**Files:** Create `includes/Google/Events.php`; Test `tests/php/GoogleEventsTest.php`

**Interfaces — Produces:** `Events::normalise( array $item, \DateTimeZone $zone ): ?array` returning `event_id, share_key, cancelled, title, description, on, ends_on, at, starts_at, ends_at, all_day, private, response, counts, planned_hours, attendees (list of {email,name}, self left out)`. Null when the item has no id.

- [ ] Tests: timed 10:00–11:10 → planned_hours 1.5, at '10:00', counts true; declined self → counts false, response 'declined'; all-day 3-day → all_day, ends_on last day inclusive, planned_hours 0, counts false; visibility private/confidential → private; status cancelled → cancelled; recurring instances share iCalUID but get different share_keys, same instance on two calendars gets the same share_key; outOfOffice / transparent → counts false; no summary → '(No title)'.
- [ ] Run, see fail; implement; run, see pass; commit.

### Task 2: Schema, connections, settings

**Files:** Modify `includes/Data/Schema.php` (VERSION 49, three tables), `tests/php/SchemaTest.php` (count 39, connections + people append-only list); Create `includes/Google/Settings.php`, `includes/Google/Connections.php`; Test `tests/php/GoogleSettingsTest.php`.

**Interfaces — Produces:**
- `Schema::google_connections_table()`, `google_meetings_table()`, `google_people_table()`.
- `Settings::OPTION = 'bwx_forge_google_client'`, `save( string $id, string $secret ): void` (blank secret keeps the old one), `client_id(): string`, `secret(): ?string`, `secret_hint(): string`, `configured(): bool`, `redirect_uri(): string`, `auth_url( string $state ): string`, `SCOPE`.
- `Connections::connect( string $user_id, int $wp_user_id, string $email, array $tokens )`, `get( $user_id ): ?array` (no secret), `tokens( $user_id ): ?array`, `store_tokens`, `set_sync_token`, `mark( $user_id, string $error )`, `synced( $user_id, bool $full )`, `all(): array`, `disconnect( $user_id )`.

- [ ] Tests: auth_url carries client_id, redirect_uri, scope calendar.readonly, access_type offline, prompt consent, state; secret_hint never contains more than the last 4; save with blank secret keeps the earlier one. Fail, implement, pass, commit.

### Task 3: Google client and sync

**Files:** Create `includes/Google/Client.php`, `includes/Google/Sync.php`, `includes/Google/Meetings.php`; Test `tests/php/GoogleClientTest.php`.

**Interfaces — Produces:**
- `Client::exchange( string $code ): array|WP_Error` (tokens incl. `expires_at`), `refresh( string $refresh ): array|WP_Error`, `primary_email( string $access ): string|WP_Error`, `events( string $access, array $query ): array|WP_Error` (one page; `WP_Error` code `bwx_forge_google_gone` on 410, `bwx_forge_google_denied` on 401/invalid_grant).
- `Meetings::apply( string $user_id, array $event ): ?string` (upsert by share_key, people row by user+event id; returns meeting id), `drop( string $user_id, string $event_id ): void`, `drop_unseen( string $user_id, array $seen_event_ids, string $from, string $to )`, `get( $id )`, `between( $from, $to ): array` (each with `people`), `settle( $id, string $site_id, string $client_id, bool $off_support, ?string $status, int $actor ): ?array`, `forget_unsettled( string $user_id )`, `hydrate`.
- `Sync::run( string $user_id, bool $force = false ): array{ran: bool, error: string}`; `THROTTLE = 300`; full sync uses `timeMin/timeMax/singleEvents=true`, incremental uses `syncToken`; ≤ 20 pages of 250.

- [ ] Tests (queued HTTP): exchange posts code + secret + redirect_uri to `https://oauth2.googleapis.com/token`; refresh with `invalid_grant` → `bwx_forge_google_denied`; events 410 → `bwx_forge_google_gone`; events passes Bearer token. Fail, implement, pass, commit.

### Task 4: Reading synced meetings — matching, capacity, calendar, standup

**Files:** Create `includes/Google/Matching.php`, `includes/Google/Load.php`; Modify `includes/Capacity/Commitments.php`, `includes/Calendar/Feed.php`; Test `tests/php/GoogleMatchingTest.php`.

**Interfaces — Produces:** `Matching::skipped( array $meeting, array $slots ): bool` (pure), `Matching::standing_slots( string $from, string $to ): array` of `{people, on, starts_at, ends_at}`; `Load::allocations( array $meetings, array $slots, string $studio_client, string $studio_site ): array` (pure) and `Load::across( $from, $to )`; Feed meeting entries for Google carry `source: 'google'`, `google_id`; to_settle Google entries carry `google_id, can_settle, off_support, site_id`.

- [ ] Tests: overlap same day matches for host or attendee; different person or non-overlapping time does not; a settled meeting never skipped; allocations — one per counting person, declined person none, all-day none, cancelled none, held → status done, unsettled → studio client/site, shared meeting with two counting people → two allocations of the same hours. Fail, implement, pass, commit.

### Task 5: Hours and REST

**Files:** Create `includes/Google/Hours.php`, `includes/Google/OAuth.php`, `includes/Rest/GoogleController.php`; Modify `includes/Rest/Server.php`, `includes/Plugin.php`, `includes/Commerce/Reconcile.php`, `includes/Rest/SupportController.php`; Test `tests/php/GoogleHoursTest.php`.

**Interfaces — Produces:** `Hours::SOURCE = 'google-meeting'`, pure `Hours::plan_for( array $meeting, array $entries, string $today, string $term_ends_on ): array` of `{client_site_id, event_type, hours}`; `Hours::settle_one( array $meeting, int $actor ): int`; `Hours::reconcile( int $actor ): int`. Routes: `GET/POST /google/settings` (admin), `DELETE /google/connections/{user_id}` (admin), `GET /me/google`, `POST /me/google/connect` → `{url}`, `DELETE /me/google`, `POST /me/google/sync`, `POST /google-meetings/{id}/settle` (admin or one of its people; site must be in reach). `OAuth::callback()` on `admin_post_bwx_forge_google`, single-use state transient bound to the WP user.

- [ ] Tests: plan_for — off support reserves on the picked site inside the horizon; not off support → nothing; moved to another site → release on the old one first; held + off support → usage; held not off support → nothing. Fail, implement, pass, commit.

### Task 6: Screens

**Files:** Modify `src/components/ProfileScreen.tsx`, `src/components/SettingsScreen.tsx`, `src/components/StandupScreen.tsx`, `src/components/CalendarView.tsx` (or Diary), `src/App.tsx` (sync on open), `src/types.ts`; Create `src/components/GoogleMeeting.tsx` (settle form: site select incl. "No client", "Comes off support hours" checkbox, actions).

- [ ] Build; check in the browser via `npm run dev` where possible.

### Task 7: E2E with a stubbed Google

**Files:** Create `tests/support/google-stub.php`, `tests/e2e/helpers/google.js`, `tests/e2e/google-calendar.spec.js`.

- [ ] Spec: admin saves the client (secret never read back); two people connect through the stubbed consent; meetings arrive; shared one shows once with both; declined/all-day show without capacity or settling; settle to a site off support + held → one usage line; moved in Google → follows, settlement kept; deleted → gone; standing meeting at the same time hides the Google copy; private reads Busy to a colleague; profile panel and standup settle via the UI.

### Task 8: Rules, docs, version, review

**Files:** `src/rules.ts`, `CHANGELOG.md`, versions, `docs/google-calendar-setup.md`.

- [ ] security-review; lint once; build; PR.
