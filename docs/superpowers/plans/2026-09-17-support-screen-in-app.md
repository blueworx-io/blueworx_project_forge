# Support Screen in the App — Implementation Plan (PR 5 of 7)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The Support screen — what a site is on, its periods, its hours ledger; assign a package, top up, adjust with a reason, suspend, resume, cancel — becomes a screen in the React studio app under Clients, backed by REST; the admin page stays until PR 7.

**Architecture:** A new `Rest\SupportController` over `Commerce\Assignments`, `Ledger`, `Support`, `ProRata`, `Sales`, mirroring `Admin\SupportActions` and reading what `Admin\SupportScreen` reads. A new `SupportScreen.tsx` with the site picker from `src/sites.ts` (`recallSite`/`rememberSite`), three sections, and the assign panel that previews the pro-rata figure with the same call the write uses (COMM-2). The `onSupport()` assignment half and `hourLedger()` in the test helpers move to REST.

**Tech Stack:** as PRs 1–4. **Spec:** "PR 5 — Support". **Written for pace** as PRs 3–4: the implementer reads `includes/Admin/SupportActions.php` and `SupportScreen.php`.

## Global Constraints

- Branch `support-in-app` off `clients-in-app`. Draft PR targets `clients-in-app`. Version `2.111.0` → `2.112.0`; changelog `## [2.112.0] - 2026-09-17`.
- No new dependencies; nothing under `client/` but the version lines; restore the client bundle after every build.
- Routes: `Server::register_route()` + `scope` (`Boundary::SCOPE_OPEN`, reason: support is administrator configuration of a site's commercial record), `Permissions::manage`, `Errors::rest`. Idempotency-Key on assign, top-up and adjust (each replay would add a period or a ledger entry; op names scoped by site as `AvailabilityController` scopes by person). Suspend/resume/cancel are idempotent by nature (a second call is a refusal) — say so in docblocks.
- Controllers do no validation the domain does not: `Assignments`, `Sales`, `Entries::refuse` decide; the controller only turns a null into the sentence the admin page shows (`refused` → 400 `bwx_forge_support_refused` "That could not be done from the site's current position."; adjust without a reason → 400 `bwx_forge_reason_required` "Say why." (CAP-3)).
- Every write answers the full GET answer so the screen never re-reads.
- Screen: test ids `bwx-support-`; kit only (+ `.bwx-support` root rule copied from `.bwx-packages`); results in place; forms in `Modal`; the site picker is the app's (`src/sites.ts` — read how `WorkScreen`/`StandupScreen` use `recallSite`/`rememberSite` and the site list from `GET /client-sites` or the shell's sites).
- `#screen=support&site=<id>` lands on that site: extend the `landing` parsing in `App.tsx` with `site` the way `person` was added in PR 1.
- Per-PR tests only; full suite at cutover. Admin page files (`SupportScreen.php`, `SupportActions.php`) and `support-assignment.spec.js`, `support-hours-gate.spec.js`, `tests/pair/acceptance-commercial.spec.js` bodies are NOT touched except where they call a helper that moves.
- Style, lint, harness rules as before.

## File map

| File | Responsibility |
|------|----------------|
| `includes/Rest/SupportController.php` (create) | Seven routes; `answer( site )` |
| `includes/Rest/Server.php` | register |
| `tests/e2e/support-rest.spec.js` (create) | routes |
| `tests/e2e/helpers/forge.js` | `onSupport()` assignment half and `hourLedger()` over REST; `assignSupport(api, siteId, packageId, from)` |
| `src/types.ts`, `src/App.tsx`, `src/components/SupportScreen.tsx`, `src/styles.css` | screen |
| `tests/e2e/support-app.spec.js`, `accessibility.spec.js` | screen spec; walk entry |
| version files, `CHANGELOG.md` | 2.112.0 |

## Answer shape (`answer( array $site )`)

```
{ ok, site: { id, name, client_id },
  position: { state, label, may_use_hours, covered_until|null, balance },   // Assignments::entitlement_on( id, today ), Support::label(), Ledger::balance()
  periods: [ …Assignments::for_site(), each with package_name from its version… ],
  ledger: [ …Ledger::for_site() entries, with when (date), event_type, hours, reason/note, source… ],
  packages: [ { id, name, current: { id (version id), hours, price, currency, validity_months } } ]   // active catalogue, what the assign panel lists
}
```

Take field names from what `Admin\SupportScreen` renders (position stat, periods table columns From/To/Position/Package/Hours granted/Why it ended, ledger When/What/Hours/Why); do not invent more.

---

### Task 1: Routes

**Mirror:** `SupportActions::assign/suspend/resume/cancel/top_up/adjust`; reads from `SupportScreen::render_*`.

- `GET /client-sites/<id>/support` — 404 unknown site (`Boundary::absent( 'client_site' )` as the sites controller does); `answer()`.
- `GET /client-sites/<id>/support/preview?package_version=<pkv id>&from=<date>&until=<date|empty>` — `Packages::version()`; 404 unknown version; `ProRata::preview( version, from, until )` when `until` is given, else the full grant (`hours`, `price`, `term_end` via `ProRata::term_end( from, validity_months )`). Answer `{ ok, hours, price, currency, ends_on, prorated: bool }` — exactly the numbers `assign` will write.
- `POST /client-sites/<id>/support` body `{ package_version, starts_on, ends_on?, note? }` — the same values array `SupportActions::assign()` builds (incl. the prorated branch when `ends_on`); null → 400 `support_refused`; answer `answer()` + `assignment`.
- `POST …/support/top-up` `{ hours, reason }` — `Sales::top_up`; null → 400 `support_refused` (hours ≤ 0 is the domain's refusal); answer + `entry`.
- `POST …/support/adjust` `{ hours, reason }` — empty reason → 400 `reason_required`; `Sales::adjust`; null (would go below nought — `Entries::refuse`) → 400 `support_refused`; answer + `entry`.
- `POST …/support/suspend` `{ from?, note? }`, `…/resume` `{ from? }`, `…/cancel` `{ from? }` — `from` defaults to today as `SupportActions::from()`; null/false → 400 `support_refused`; answer.

**Tests (`support-rest.spec.js`, run-id; `makeSite`, `makePackage`):** empty position reads `state: 'none'`, `balance: 0`, no periods; preview with `until` matches what assign then writes (`hours_granted` equals the preview's hours — COMM-2); assign → `state: 'active'`, balance = package hours, one period, one ledger allocation entry; top-up 5 → balance +5, ledger entry; adjust −2 with reason → balance −2; adjust without reason → 400 `reason_required`; adjust that would go below nought → 400; suspend → `state: 'suspended'`, resume → active, cancel → `state: 'cancelled'` (or whatever `Support::` names it — read the constants) and a second cancel → 400; replayed assign under one key makes one period; non-admin 403 on GET and assign.

Commit: "Support over REST: the position, periods, ledger, and every action the admin page has".

---

### Task 2: Helpers and the specs that drive the admin Support page

`tests/e2e/helpers/forge.js`: `onSupport()` — the assignment half (`page.goto(…support&site=)`, select `#bwx-assign-package`, fill `#bwx-assign-from`, click `#bwx-assign`, wait `[data-bwx-support-state="active"]`) becomes `assignSupport(api, siteId, package.current.id, today)` posting to `POST /client-sites/<id>/support` and asserting `position.state === 'active'`; no page needed any more (drop the `newPage`). `hourLedger(admin, siteId)` reads `GET /client-sites/<id>/support` and returns `{ balance, entries: [[event_type, hours], …] }` in the same shape it returned from the page (read its current return shape and keep it exactly; `tests/pair/acceptance-commercial.spec.js` reads `entries` as `[type, hours]` pairs). Callers of both keep working unchanged. Where `support-assignment.spec.js` or `acceptance-commercial.spec.js` drive the admin Support page directly (`#bwx-assign` etc.), leave them — those test the page until PR 7.

Run: `support-assignment.spec.js`, `support-hours-gate.spec.js`, `capacity-gate.spec.js`, `capacity-rest.spec.js`, `notification-events.spec.js` (uses onSupport?) — `grep -ln "onSupport\|hourLedger" tests/e2e tests/pair` and run every hit (pair via the pair config). Commit: "Specs put a site on support and read its ledger over REST".

---

### Task 3: The screen

**Rail:** `{ key: 'support', label: 'Support', icon: LifeBuoy, testId: 'bwx-screen-support' }` under Clients after Clients; `TITLES.support = 'Support'`; `OPENINGS.support = { crumbs: [ 'Clients', 'Support' ], eyebrow: 'What each site is on, and the hours it has', tile: LifeBuoy, hue: 'amber' }`; mount `<SupportScreen key={ generation } site={ landing.site } />`; `landing.site` parsed from `&site=`.

**Screen:** site picker `bwx-support-site` (Select of sites; remembered via `rememberSite`; `landing.site` wins on first render); idle state "Choose a site to see what it is on"; `Screen` loading/denied/error.
- "Position" Panel: `Stat` label from `position.label` (`bwx-support-state`, `data-state`), `Stat` "Hours left" `bwx-support-balance` (`data-balance`), "Covered until …" / "No end date on the record."; buttons by state as the admin page shows them: `bwx-support-assign` (none/lapsed/cancelled), `bwx-support-suspend` (active), `bwx-support-resume` (suspended), `bwx-support-cancel` (active/suspended; confirm), `bwx-support-top-up`, `bwx-support-adjust`.
- "Periods" DataView `bwx-support-periods` (From, To, Position Tag, Package, Hours granted, Why it ended) or "Never on a package".
- "Ledger" DataView `bwx-support-ledger` (When, What (`Entries` label), Hours (signed, mono), Why) with footer "Left: <balance>" or "Nothing on the ledger".
- Assign Modal `bwx-support-assign-form`: package Select `-assign-package` (from `answer.packages`), from `-assign-from` (date, default today), until `-assign-until` (optional), note `-assign-note`; a live preview line `bwx-support-assign-preview` ("<hours>h for <price> <currency>, until <ends_on>") fetched from `/support/preview` on every change (debounce not needed — fire on change; ignore stale answers by sequence); the save button `bwx-support-assign-save` reads "Assign <hours>h" and is disabled until a preview has landed.
- Top-up Modal `bwx-support-topup-form` (hours `-topup-hours`, reason `-topup-reason`, save `-topup-save`; help "Bought hours last twelve months from today, and are used after the package's own (COMM-4)."); Adjust Modal `bwx-support-adjust-form` (hours `-adjust-hours` "negative to take away", reason `-adjust-reason` required, help as the admin page, save `-adjust-save`); Suspend Modal `bwx-support-suspend-form` (from `-suspend-from`, note `-suspend-note`, save `-suspend-save`); Resume via confirm with today; Cancel via confirm.
- Every write's answer replaces the whole screen state (`landed`).

**Tests (`support-app.spec.js`, serial; site + package made in `beforeAll` over REST):** rail entry, pick the site → "Not on a package" state; assign: preview shows the package's hours, save label carries them, after save state Active and balance equals hours; top-up 5 → balance +5, ledger row; adjust −2 with reason → balance −2; adjust with no reason → form notice "Say why."; suspend → Suspended; resume → Active; cancel (confirm) → Cancelled and the assign button back; `#screen=support&site=<id>` (goto + reload) lands on that site. Accessibility: `[ 'Support', 'bwx-screen-support' ]` (the walk needs a site remembered — pick one in the walk via `rememberSite`? The walk visits screens by clicking the rail; the idle state is what axe sees unless a site is remembered. Store `localStorage` the way `src/sites.ts` does, before the walk, for the site the walk creates — read `sites.ts` for the key.)

Commit: "Support screen in the app: position, periods, ledger, and the assign preview".

---

### Task 4: Version, changelog, lint, targeted specs, PR

2.112.0; changelog: "Support is now in the app, under Clients: see what a site is on, its periods and its hours ledger; assign a package (the assign button shows the exact hours it will write), top up, adjust with a reason, suspend, resume or cancel — without going to WordPress admin. The admin page stays for now." Lint, build, restore the client bundle; run `support-rest`, `support-app`, the Task 2 set, `shell`, the accessibility app walk; commit with `assets/`; push; draft PR `--base clients-in-app` (body as PR 3's).
