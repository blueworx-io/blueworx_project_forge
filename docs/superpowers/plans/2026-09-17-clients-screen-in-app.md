# Clients Screen in the App — Implementation Plan (PR 4 of 7)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The Clients screen — clients and their sites; add, edit, deactivate; the site's contact, its onboarding, its connection key; the studio's own name — becomes a screen in the React studio app, first under Clients on the rail, backed by REST; the admin page stays until PR 7.

**Architecture:** Most routes exist (`ClientsController`, `ClientSitesController`, `IntegrationsController` for keys). This PR adds the three the admin page's actions still lack — contact, start onboarding, rename the studio — mirroring `Admin\ClientActions`. A new `ClientsScreen.tsx` lists clients in a `DataView`, each selected client's sites beneath, and edits in kit `Modal`s. The onboarding specs and the pair onboarding helper that press "Start onboarding" on the admin page move to REST.

**Tech Stack:** as PRs 1–3. **Spec:** `docs/superpowers/specs/2026-09-16-admin-screens-to-app-design.md` — "What every PR does", "PR 4 — Clients", "Cross-cutting".

**Written for pace, as PR 3's plan:** contracts, files, test ids and test cases; the implementer reads `includes/Admin/ClientActions.php` and reproduces each action's checks in order. Patterns: `includes/Rest/PackagesController.php` (controller), `src/components/PackagesScreen.tsx` and `PeopleScreen.tsx` (screen; `PeopleScreen` shows a screen with several `Modal` forms and per-record refresh).

## Global Constraints

- Branch `clients-in-app` off `people-in-app` (PR 3, unmerged). Draft PR targets `people-in-app`.
- Version `2.110.0` → `2.111.0` in the five places; changelog `## [2.111.0] - 2026-09-17`.
- No new dependencies; nothing under `client/` but the version lines; restore `client/assets/js/blueworx-forge-client.js` after every build, never commit it.
- Every route: `Server::register_route()` with `scope`, `Permissions::manage`, `Errors::rest`; `Versioning::check()` before writes to versioned records (clients, sites); idempotency where a replay would make a second record.
- Controllers do no validation the domain does not (`Tenancy\Validate::client()/site()`, the admin action's own guards).
- **Keys:** the spec names `POST /client-sites/<id>/keys` and `DELETE …/keys/<key_id>`; the routes that already exist and that the admin page itself calls are `POST /client-sites/<id>/integration/key` and `DELETE …/integration/key` (`IntegrationsController`). Ruling: use the existing routes; do not add duplicates. The key is in the POST answer and nowhere else; the screen shows it once, in the panel, with a copy button, and forgets it when the panel closes.
- **Contact:** the admin page assigns a contact per client (`Contacts::assign( client_id, user_id )`), not per site as the spec's route path suggests. Ruling: `PUT /clients/<id>/contact`, mirroring the action.
- Screen: `data-testid`s start `bwx-clients-`; kit only (+ `.bwx-clients` root rule copied from `.bwx-packages`); results in place; forms in `Modal`.
- Per-PR tests only (the specs this PR adds or touches, `shell.spec.js`, the accessibility app walk); full suite once at cutover (user's instruction).
- Admin page files (`includes/Admin/ClientsScreen.php`, `ClientActions.php`, `IssuedKey.php`) and their spec `clients-screen.spec.js`, and `activation.spec.js` (visits the admin page to prove activation) are NOT touched.
- PHP/TS style, lint, harness rules as PRs 1–3.

---

## File map

| File | Responsibility |
|------|----------------|
| `includes/Rest/ClientsController.php` (modify) | `PUT /clients/<id>/contact`; `PUT /studio`; `GET /clients` carries `is_studio`, `contact` (from `Contacts::current_by_client()`) per client |
| `includes/Rest/ClientSitesController.php` (modify) | `POST /client-sites/<id>/onboarding`; `GET /clients/<id>/sites` carries `onboarding` (started/ready) and `integration` summary (`connected`, `key_issued_at`, `key_revoked`) per site if the existing answers lack them — read `Admin\ClientsScreen` to see what it shows and make one read enough |
| `tests/e2e/clients-rest.spec.js` (create, or extend `rest.spec.js` if clients routes are tested there — check) | Route behaviour |
| `tests/e2e/helpers/forge.js`, `tests/pair/helpers/onboarding.js`, `tests/e2e/onboarding-assign.spec.js`, `onboarding-board.spec.js`, `onboarding-launch-gate.spec.js` | "Start onboarding" over REST |
| `src/types.ts`, `src/App.tsx`, `src/components/ClientsScreen.tsx`, `src/styles.css` | The screen |
| `tests/e2e/clients-app.spec.js`, `accessibility.spec.js`, `shell.spec.js` | Screen spec; walk entry |
| version files, `CHANGELOG.md` | 2.111.0 |

---

### Task 1: Routes — contact, onboarding, studio, and the reads the screen needs

**Mirror:** `ClientActions::assign_contact()`, `::assign_onboarding()`, `::rename_studio()`, `::deactivate_client()`/`::deactivate_client_site()` (already `PATCH … status: 'inactive'` — confirm and test).

**Routes:**
- `PUT /clients/<id>/contact` body `{ user_id: string|'' }` — 404 unknown client; a non-active person → 400 `bwx_forge_invalid_contact` ("Somebody who has left cannot be the contact."); `Contacts::assign()`; answer `{ ok, client, contact }` where `contact` is `Contacts::resolve()`'s shape.
- `POST /client-sites/<id>/onboarding` — 404 unknown site; no current template → 409 `bwx_forge_no_checklist` ("No onboarding checklist has been published yet."); already onboarding → 409 `bwx_forge_already_onboarding`; answer `{ ok, site, onboarding }` (the `Assignment` row). Idempotent by nature (second call is the 409) — say so in the docblock.
- `PUT /studio` body `{ display_name, record_version }` — `Studio::client_id()`; `Validate::client( …, true )`; `Clients::update()`; stale → 409 via re-read; answer `{ ok, client }`.
- Reads: `GET /clients?status=all` — each client gains `is_studio: bool` and `contact` (resolved person or null); `GET /clients/<id>/sites?status=all` — each site gains `onboarding: { started: bool, ready: bool } | null` and `integration: { connected: bool, issued_at: int|0, revoked: bool }` — take the exact facts `Admin\ClientsScreen` renders per site and no more. If an existing answer already carries a fact, do not duplicate it.

**Tests (`clients-rest.spec.js`, run-id; `makeSite`, `makePerson`):** contact set to an active person → `contact.display_name`; to an offboarded person → 400; cleared with `''`; onboarding start → `onboarding` present, second call 409 `already_onboarding`, a site with no published template → 409 `no_checklist` (publish one first through whatever helper `onboarding-assign.spec.js` uses, or test the no-template case only on a fresh-template instance — read that spec and reuse its setup); `PUT /studio` renames and 409s on a stale version; `is_studio` true for exactly one client in the list; non-admin 403 on the three writes.

Commit: "Clients over REST: contact, start onboarding, the studio's name, and what the screen reads".

---

### Task 2: Specs and helpers that press "Start onboarding" on the admin page

Read `tests/pair/helpers/onboarding.js` (~line 100-115) and the three e2e onboarding specs: wherever they open the admin Clients page to click `[data-bwx-action="bwx_forge_assign_onboarding"]`, replace with `await api.post(\`/client-sites/${siteId}/onboarding\`)` (expect 200) through the caller they already have. Where a spec then asserts on the admin page's `[data-bwx-onboarding="…"]` markers to read onboarding state, that assertion is about the admin page — leave it (the page stays until PR 7) — unless it is only there to wait for the start to land, in which case wait on the REST answer instead. Add `startOnboarding(api, siteId)` to `forge.js` and use it from both suites. Run the three e2e specs and `npx playwright test -c playwright.pair.config.js tests/pair/onboarding*.spec.js --workers=1` (both sites are up). Commit: "Specs start onboarding over REST".

---

### Task 3: The screen

**Rail:** `{ key: 'clients', label: 'Clients', icon: Building2, testId: 'bwx-screen-clients' }` first under `{ group: 'Clients' }`; `TITLES.clients = 'Clients'`; `OPENINGS.clients = { crumbs: [ 'Clients', 'Clients' ], eyebrow: 'Who we work for, and their sites', tile: Building2, hue: 'teal' }`; mount `<ClientsScreen key={ generation } />`.

**Data:** `GET /clients?status=all`; on selecting a client, `GET /clients/<id>/sites?status=all` (cache by id in state); `GET /users` (active) for the contact picker; `GET /client-sites/<id>/integration` for the key panel if the sites read does not carry enough.

**Screen (`ClientsScreen.tsx`):**
- Root `bwx-clients`; toggle `bwx-clients-show-all` (active / everyone incl. deactivated); button `bwx-clients-add` → Modal `bwx-clients-form` (name `-form-name`, timezone `-form-timezone` (TextInput, default 'Europe/London'), email domains `-form-domains` (comma-separated, help as the admin page), save `-form-save`, cancel `-form-cancel`, notice `-form-notice`).
- Clients `DataView` `bwx-clients-list` (Name with a "Studio" Tag when `is_studio`, Status Tag, Contact, Sites count if cheap, else omit), `onRowClick` selects; `selectedId`.
- Selected client panel `bwx-clients-selected` (`data-client`): buttons `bwx-clients-edit` (Modal, same form + status Select; the studio's client shows name only and no status — rename goes to `PUT /studio`), `bwx-clients-deactivate` (confirm; hidden for the studio; `PATCH { status: 'inactive', record_version }`), `bwx-clients-contact` (Modal `bwx-clients-contact-form`, Select `-contact-pick` of active people + "Nobody", save `-contact-save`), `bwx-clients-add-site` (Modal `bwx-clients-site-form`: name `-site-name`, url `-site-url`, save `-site-save`).
- Sites `DataView` `bwx-clients-sites` (Name, URL, Status, Onboarding: "Not started" / "In progress" / "Ready", Connection: "Connected" / "Key issued, not yet connected" / "Not connected" / "Cut off"), each row with `bwx-clients-site-edit` (Modal: name, url, status), `bwx-clients-site-deactivate` (confirm), `bwx-clients-site-onboard` (confirm "Start onboarding for <site>?"; disabled once started; 409 reasons shown in `bwx-clients-notice`), `bwx-clients-site-key` → Modal `bwx-clients-key-form`: explains a key is shown once; button `bwx-clients-key-issue` → `POST …/integration/key`; the key appears in a read-only `TextInput` `bwx-clients-key-value` with `bwx-clients-key-copy` (`navigator.clipboard.writeText`, then the button reads "Copied"); a `bwx-clients-key-revoke` button (confirm) when a key is live; closing the modal drops the key from state.

**Tests (`clients-app.spec.js`, serial, run-id):** rail entry + list shows a client made in `beforeAll`; add a client → row appears; select it, add a site → sites list shows it; edit site name → updates; set contact → Contact column shows the person; issue a key → value visible, `Copied` after the copy click (grant clipboard permission in the test context or assert the button text only), reopen the modal → no key shown; revoke → Connection reads "Cut off"; deactivate site → Status Deactivated in "everyone" view; start onboarding → Onboarding "In progress" (publish a template first, as `onboarding-assign.spec.js` does; if no helper exists, skip the start assertion and test the 409 notice instead — say which); the studio row shows the Studio tag and no deactivate button; rename the studio → name updates. Accessibility: `[ 'Clients', 'bwx-screen-clients' ]` (a client exists from the walk's own setup).

Commit: "Clients screen in the app: clients, sites, contact, onboarding, keys, the studio's name".

---

### Task 4: Version, changelog, lint, targeted specs, PR

- 2.111.0; changelog: "Clients are now in the app, first under Clients: add and edit clients and their sites, set who the contact is, start onboarding, issue or revoke a site's connection key, and rename the studio — without going to WordPress admin. The admin page stays for now."
- `npm run lint`, `composer lint`, `npm run build`, restore the client bundle.
- Run: `clients-rest.spec.js`, `clients-app.spec.js`, the three onboarding e2e specs, `shell.spec.js`, the accessibility app walk, `site-integration-rest.spec.js` (keys), `tests/pair/onboarding*.spec.js` via the pair config. Green.
- Commit "Forge 2.111.0: Clients are in the app" with `assets/`; push; draft PR `--base people-in-app`, body as PR 3's (routes added, stacked note, suite deferred to cutover), attribution line.
