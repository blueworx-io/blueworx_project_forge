# Meetings Screen in the App — Implementation Plan (PR 6 of 7)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The Meetings screen — a site's standing meetings and the next twelve weeks of them; add a series, end one, move a meeting, settle one — becomes a screen in the React studio app under Clients, backed by REST; the admin page stays until PR 7.

**Architecture:** A new `Rest\MeetingsController` over `Meetings\Series`, `Diary`, `Occurrence`, `Hours`, `Validate`, mirroring `Admin\MeetingActions` and reading what `Admin\MeetingsScreen` reads — including `Hours::reconcile_site()` on the read, because there is no cron (MEET-4). A new `MeetingsScreen.tsx` with the same site picker as Support, series as cards, and the twelve weeks as a `DataView` grouped by week with move and settle on each row.

**Tech Stack:** as PRs 1–5. **Spec:** "PR 6 — Meetings". **Written for pace**: the implementer reads `includes/Admin/MeetingActions.php` and `MeetingsScreen.php`.

## Global Constraints

- Branch `meetings-in-app` off `support-in-app`. Draft PR targets `support-in-app`. Version `2.112.0` → `2.113.0`; changelog `## [2.113.0] - 2026-09-17`.
- No new dependencies; nothing under `client/` but the version lines; restore the client bundle after every build.
- Routes: `Server::register_route()` + `scope`, `Permissions::manage`, `Errors::rest`. `Idempotency-Key` on adding a series (a replay would make a second series). End carries `record_version` (`Series::end( id, version )`) with `Versioning::check()` first. Move and settle are keyed by slot and idempotent by nature (`Diary::except` on the same slot with the same change) — say so in docblocks.
- Controllers do no validation the domain does not (`Meetings\Validate::series()`, `Occurrence::exists()`, `Diary::except` refusals → 400 `bwx_forge_meeting_refused`). A series that is not on the site in the path → 404 `bwx_forge_unknown_series` (the cross-site guard `series_on_site()`).
- Every write runs `Hours::reconcile_site()` after, exactly as the actions do, and answers the full GET answer.
- Screen: test ids `bwx-meetings-`; kit only (+ `.bwx-meetings` root rule); results in place; forms in `Modal`; site picker via `src/sites.ts` as Support; `#screen=meetings&site=<id>` lands.
- Per-PR tests only; full suite at cutover. Admin page files and `tests/e2e/meetings.spec.js`, `tests/pair/acceptance-commercial.spec.js` (meetings half) are NOT touched.
- Style, lint, harness rules as before.

## File map

| File | Responsibility |
|------|----------------|
| `includes/Rest/MeetingsController.php` (create), `Server.php` | Five routes; `answer( site )` with reconcile |
| `tests/e2e/meetings-rest.spec.js` (create) | routes |
| `src/types.ts`, `src/App.tsx`, `src/components/MeetingsScreen.tsx`, `src/styles.css` | screen |
| `tests/e2e/meetings-app.spec.js`, `accessibility.spec.js` | screen spec; walk entry |
| version files, `CHANGELOG.md` | 2.113.0 |

## Answer shape (`answer( array $site )`)

```
{ ok, site: { id, name },
  series: [ …Series::for_site( id ), each with frequency_label (Recurrence::label), host_name, hours_each, state ],
  meetings: [ …Diary::for_site( id, today, MeetingHours::horizon_end( today ) ), each with on, time, status, status_label (Occurrence::label), hours, ledger_state (as MeetingsScreen::ledger_state() derives it), excepted_from|null, series_id, series_title, slot ],
  horizon: { from: today, to },
  people: [ { id, display_name } ]   // active people, for the host picker
}
```

Take the columns from `MeetingsScreen` (series: What/How often/When/Host/Hours each/State; meetings: When/Hours/What happened/Hours held/Move/Settle).

---

### Task 1: Routes

**Mirror:** `MeetingActions::add_series/end_series/move/settle`; `MeetingsScreen::render_*` incl. `Hours::reconcile_site()` on read.

- `GET /client-sites/<id>/meetings` — 404 unknown site; reconcile; `answer()`.
- `POST /client-sites/<id>/meetings/series` — body = the eleven `Validate::series()` inputs (`title, frequency, starts_on, ends_on, time_of_day, duration_mins, timezone, host_user_id, attendees, planned_hours`; `client_site_id` from the path); errors → 400 `bwx_forge_invalid_series` with `fields`; `Series::create` null → 400 `meeting_refused`; reconcile; answer + `series`. Idempotency op `meetings.series.create:<site>`.
- `POST /client-sites/<id>/meetings/series/<series_id>/end` `{ record_version }` — cross-site 404; `Versioning::check`; `Series::end`; reconcile; answer.
- `POST /client-sites/<id>/meetings/<series_id>/<slot>/move` `{ on }` — `Diary::except( series, slot, { on }, Events::MOVED, actor )`; null → 400; reconcile; answer + `meeting`.
- `POST /client-sites/<id>/meetings/<series_id>/<slot>/settle` `{ status }` — `Occurrence::exists( status )` else 400 `invalid_status`; the `$actions` map from the action; reconcile; answer + `meeting`.

(`slot` is the date-like key `MeetingActions` reads as `slot`; keep the same string. Route pattern `[A-Za-z0-9_\-]+` covers a `YYYY-MM-DD` slot.)

**Tests (`meetings-rest.spec.js`; site on support via `Forge.onSupport` so hours can reserve; a person as host):** empty read → no series, no meetings, horizon spans twelve weeks; add a weekly series starting next Monday → series listed, meetings within the horizon appear with `ledger_state` reserved once reconciled (assert the support balance dropped via `GET /client-sites/<id>/support`); invalid series (no title / bad frequency) → 400 with `fields`; move the first meeting a day later → `on` changed and `excepted_from` set; settle it `held` → `status held`, `ledger_state used`; settle `cancelled` on the next → released; end the series → `state ended`, meetings beyond the end gone from the horizon; end with a stale version → 409; a series id from another site → 404; replayed add under one key → one series; non-admin 403 on GET and add.

Commit: "Meetings over REST: series, the twelve weeks, move, settle, end".

---

### Task 2: The screen

**Rail:** `{ key: 'meetings', label: 'Meetings', icon: CalendarClock, testId: 'bwx-screen-meetings' }` under Clients after Support; `TITLES.meetings = 'Meetings'`; `OPENINGS.meetings = { crumbs: [ 'Clients', 'Meetings' ], eyebrow: 'Standing meetings, and the next twelve weeks of them', tile: CalendarClock, hue: 'rose' }`; mount `<MeetingsScreen key={ generation } site={ landing.site } />`.

**Screen:** site picker `bwx-meetings-site` (shared behaviour with Support — if Support's picker is a small component inside `SupportScreen.tsx`, lift it to `src/components/SitePicker.tsx` and use it from both; that is a screen component, not a kit piece); idle "Choose a site to see its meetings".
- "Standing meetings" Panel with `bwx-meetings-add` → Modal `bwx-meetings-series-form` (title `-series-title`, frequency Select `-series-frequency` from `Recurrence` labels — get them from the answer or hardcode the set `Recurrence::exists` knows; read the class), starts `-series-starts`, ends `-series-ends` (optional), time `-series-time`, duration mins `-series-duration`, timezone `-series-timezone` (default the site's client's or 'Europe/London'), host Select `-series-host` (from `people`), attendees `-series-attendees`, planned hours `-series-hours` (default `Recurrence::planned_hours( duration )` — compute client-side the same way or leave blank for the server default; read `Validate::series`), save `-series-save`). Series as `Card`s `bwx-meetings-series` (`data-series`): title, frequency label, when, host, hours each, state Tag; `bwx-meetings-series-end` (confirm; running only).
- "The next twelve weeks" DataView `bwx-meetings-list` grouped by week: the kit has no grouping — render one `DataView` per week with `title` "Week of <Monday>" (an array of small DataViews is fine), rows: When (date + time), What (series title), Hours, What happened (status label), Hours held (ledger state label), actions `bwx-meetings-move` (Modal `bwx-meetings-move-form`: date `-move-on`, save `-move-save`) and `bwx-meetings-settle` (Select `bwx-meetings-settle-status` inline in the row? No — inline forms are forbidden; a Modal `bwx-meetings-settle-form` with a status Select `-settle-status` of Held / Cancelled / No-show / Reinstate and save `-settle-save`). "(moved from …)" sub-line when `excepted_from`.
- Every write's answer replaces the screen state.

**Tests (`meetings-app.spec.js`, serial; site on support + a person, over REST):** rail entry; pick site → "No standing meetings"; add a weekly series → card appears, list shows meetings; move the first meeting → row shows the new date and "(moved from"; settle held → "Held"; end the series → Ended tag; `#screen=meetings&site=<id>` lands. Accessibility: `[ 'Meetings', 'bwx-screen-meetings' ]` with the remembered-site trick from Support.

Commit: "Meetings screen in the app: standing meetings and the next twelve weeks".

---

### Task 3: Version, changelog, lint, targeted specs, PR

2.113.0; changelog: "Meetings are now in the app, under Clients: a site's standing meetings and the next twelve weeks of them; add a series, end one, move a meeting or settle it — without going to WordPress admin. The admin page stays for now." Lint, build, restore; run `meetings-rest`, `meetings-app`, `support-app` (shared picker), `shell`, the accessibility app walk, `tests/e2e/meetings.spec.js` (admin page — must still pass since reconcile semantics are shared); commit with `assets/`; push; draft PR `--base support-in-app`.
