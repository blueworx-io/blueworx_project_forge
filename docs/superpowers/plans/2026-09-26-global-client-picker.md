# One Client picker — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** One remembered Client picker in the top bar that every client-data screen follows.

**Architecture:** A React context (`src/ClientChoice.tsx`) owned by `App` holds the picked site id (or `ALL_SITES`) and the site list, persisted in the existing `bwx-forge-site` browser key. Screens read it with `useClientChoice()` instead of their own pickers. Most screens filter rows they already load; Support gets a new all-sites summary route, Reports a server filter, and `/calendar` entries a `site_id`.

**Tech Stack:** React + TypeScript (Vite), PHP REST (WordPress), Playwright e2e, PHPUnit.

**Spec:** docs/superpowers/specs/2026-09-26-global-client-picker-design.md

## Global Constraints

- Version 2.138.0 in package.json, blueworx-forge.php header + BWX_FORGE_VERSION, client/blueworx-forge-client.php header + BWX_FORGE_CLIENT_VERSION. Leave package-lock.json alone.
- CHANGELOG under Added: "One Client picker in the top bar. Pick a client once and every screen follows it; Support with All clients lists every client's hours."
- Picker test id `bwx-client-choice`; option for all is value `all` labelled "All clients".
- Capacity is not filtered; it shows "Capacity counts all clients."
- Empty text when narrowed: "Nothing for <client> here."
- New routes are reach-filtered and batched (no per-site query loops); `tests/e2e/performance.spec.js` must stay within budget.
- Studio only. The client plugin is untouched.
- The studio test site is live-linked: never build, edit PHP or git checkout during a Playwright run.
- Commits: one plain line ending "(#402)", then `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`. Commit rebuilt assets.

## Review Focus

1. A remembered site the person can no longer reach → falls back to All, never a blank screen or a 403 loop.
2. A `#…&site=` link → opens on that site and moves the top bar to it, and the pick is remembered.
3. Switching the picker while a screen is loading → the screen shows the new client's data, not the old response arriving late.
4. Diary entries with no client (company dates, leave) → still show when one client is picked.
5. Old per-screen picker test ids (`bwx-site`, `bwx-meetings-site`, `bwx-support-site`) → every spec using them moves to `bwx-client-choice`.

---

### Task 1: The picker, and Work following it

**Files:** Create `src/ClientChoice.tsx`. Modify `src/App.tsx` (provider, top bar), `src/sites.ts` (fallback rule), `src/components/WorkScreen.tsx` (remove `bwx-site` select, read context; New work defaults to the picked site), `src/styles.css`. Update every e2e spec that selects `bwx-site`. Test: new `tests/e2e/global-client-picker.spec.js`.

**Interfaces — Produces:** `ClientChoiceProvider`, `useClientChoice(): { siteId: string; sites: SiteOption[]; ready: boolean; setSiteId( id: string ): void; label( id?: string ): string }`. `siteId` is a reachable site id or `ALL_SITES`; never ''.

- [ ] Write the failing e2e: pick a client in `bwx-client-choice`; the board shows only that client's items; reload keeps the pick; open a `#screen=work&site=<other>` link and the picker shows the other site.
- [ ] Build the provider: loads `/client-sites` once; opens on a `&site=` landing if reachable, else the remembered value if reachable, else `ALL_SITES`; every set writes `rememberSite`.
- [ ] Put the Select in `fs-topbar` beside New task, labelled "Client".
- [ ] WorkScreen reads `siteId` from context; delete its own select and site state; loading guards against a stale response (keep the requested id and drop answers for another).
- [ ] Move specs from `bwx-site` to `bwx-client-choice`. Run the work, board, calendar, list, schedule specs and the new spec. Commit.

### Task 2: Meetings and Support

**Files:** `src/components/MeetingsScreen.tsx`, `src/components/SupportScreen.tsx`, delete `src/components/SitePicker.tsx` if unused, new route in `includes/Rest/SupportController.php` (`GET /support-summary`), specs using `bwx-meetings-site` / `bwx-support-site`.

- [ ] Failing e2e: with All picked, Support shows a table (`bwx-support-summary`) with a row per reachable site (client, package, hours this period, used, left, status); clicking a row sets the picker to that site and shows its support page. `/support-summary` is refused (403) to a non-administrator.
- [ ] Route: administrator-only like the rest of Support, reach-filtered, one batched read for all sites.
- [ ] Meetings: read context; All uses `/meetings`, a site uses the site route, as today.
- [ ] Move specs to `bwx-client-choice`. Run meetings and support specs. Commit.

### Task 3: Screens that filter what they load

**Files:** `includes/Calendar/Feed.php` (entries gain `site_id`, '' when no client), `src/types.ts` (`DiaryEntry.site_id`), `src/components/Diary.tsx`, `CalendarView.tsx`, `MyTasksScreen.tsx` (filter; remove its Client chip), `StandupScreen.tsx`, `RecurringScreen.tsx`, `RemindersScreen.tsx`, `QueueScreen.tsx`.

- [ ] Failing e2e in `global-client-picker.spec.js`: with one client picked, My Tasks, Standup, Recurring tasks, Reminders and Requests show only that client's rows; a company date for everyone still shows on the calendar and My Tasks diary; a screen with nothing for the client says "Nothing for <client> here."
- [ ] `/calendar` entries carry `site_id`; a feed test checks it.
- [ ] Filter each screen in the browser by `client_site_id` / `site_id`; rows with no site always show. Recurring tasks and Reminders' new-item forms default to the picked site.
- [ ] Run my-tasks, standup-board, recurring-screen, reminders, queue, calendar-feed specs. Commit.

### Task 4: Reports, Capacity, and finish

**Files:** `includes/Rest/ReportsController.php`, `includes/Reports/Source.php`, `src/components/ReportsScreen.tsx`, `src/components/CapacityScreen.tsx`, version files, CHANGELOG.

- [ ] Failing e2e: `/reports?client_site_id=` narrows the numbers to that site; an unreachable site is 403; the Reports screen sends the picked site. Capacity shows everyone whatever is picked and the note "Capacity counts all clients."
- [ ] Server filter applied before the rows are added up.
- [ ] Version 2.138.0 and changelog. Run phpunit, phpcs on touched PHP, build, lint once; the full studio suite; performance spec. Commit.
