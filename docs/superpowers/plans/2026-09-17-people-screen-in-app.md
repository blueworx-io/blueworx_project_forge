# People Screen in the App — Implementation Plan (PR 3 of 7)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The People screen — every person, with every client they touch beneath them; add, add from an account, edit, link an account, offboard, delete, memberships and grants — becomes a screen in the React studio app under Team, backed by REST; the admin page stays until PR 7.

**Architecture:** `Rest\UsersController` and `Rest\MembershipsController` gain the routes the admin page's nine actions need, each mirroring `Admin\PeopleActions` line for line (same domain calls, same refusals, same order of checks). A new `PeopleScreen.tsx` renders one card per person with their memberships, and edits everything in kit `Modal`s. Test helpers already create people over REST, so only the two admin-page specs remain on the form (untouched until PR 7).

**Tech Stack:** PHP 7.4+ (WordPress REST API, WPCS), React 18 + TypeScript (Vite), kit in `src/kit/`, Playwright against the local harness.

**Spec:** `docs/superpowers/specs/2026-09-16-admin-screens-to-app-design.md` — "What every PR does", "PR 3 — People", "Cross-cutting".

**This plan is written for pace.** Where PR 1 and PR 2's plans carried every line of code, this one carries exact contracts, files, test ids and test cases, and names the admin action whose behaviour each route mirrors. The implementer reads that action and reproduces its checks in the same order. `includes/Rest/PackagesController.php` and `AvailabilityController.php` are the controller pattern; `src/components/PackagesScreen.tsx` is the screen pattern (states, `landed()`, `Modal` form, `messageFor`, `refusal`-by-field).

## Global Constraints

- Branch `people-in-app` off `packages-in-app` (PR 2, unmerged). Draft PR targets `packages-in-app`; retarget down the stack as parents merge.
- Version `2.109.0` → `2.110.0` in `package.json`, `blueworx-forge.php` (header + `BWX_FORGE_VERSION`), `client/blueworx-forge-client.php` (header + `BWX_FORGE_CLIENT_VERSION`). Changelog `## [2.110.0] - 2026-09-17`.
- No new dependencies. Nothing under `client/` but the version lines. `npm run build` also rewrites `client/assets/js/blueworx-forge-client.js` — restore it with `git checkout --` before every commit; never commit it.
- Every route: `Server::register_route()` with a `scope` (`Boundary::SCOPE_OPEN` + reason), `Permissions::manage`, errors via `Errors::rest`. Writes to a versioned record (users, memberships) carry `record_version` and run `Versioning::check()` first (ARCH-5) — as `UsersController::update()` already does. Writes whose replay would make a second record (`POST /users`, `POST /users/from-account`, `POST /clients/<id>/memberships` — already keyed) take `Idempotency-Key`.
- Controllers do no validation the domain does not: `Tenancy\Validate::user()` / `::membership()`, `Accounts::link_error()`, `Validate::domain_error()`, and the duplicate-email / duplicate-account checks exactly as `PeopleActions` makes them.
- Every write answers `{ ok, user, memberships }` for the person written (memberships = `Memberships::for_user( id, null )`, every status), so the screen updates that card without re-reading.
- Screen: every interactive element has a `data-testid` starting `bwx-people-`; nothing styled outside the kit but a `.bwx-people` root rule (copy `.bwx-packages`) and a `.bwx-people-cards` grid; results shown in place, no `useToast`; forms in the kit `Modal` (with `testId`); refusals by field shown in the form.
- Per-PR tests only: the specs this PR adds or touches, `shell.spec.js`, `accessibility.spec.js -g "every application screen passes"`. The full suite runs once on the cutover branch (user's instruction, 2026-09-17).
- Admin page files (`includes/Admin/PeopleScreen.php`, `PeopleActions.php`) and their specs (`people-screen.spec.js`, `people-accounts.spec.js`) are NOT touched.
- PHP: tabs, WPCS, docblocks; `composer lint` before each PHP commit (pre-existing CRLF findings in `client/` and `includes/Work/Transition.php` are not yours). TS: match `PackagesScreen.tsx` style. `npm run lint` once at the end.
- Local harness at http://127.0.0.1:8892 (admin/admin) links this repo; PHP is live at once, TS after `npm run build`; never build during a Playwright run; never `wp:up`/`wp:down`.

---

## File map

| File | Responsibility |
|------|----------------|
| `includes/Rest/UsersController.php` (modify) | `POST /users` makes the account when none is sent; new `GET /accounts`, `POST /users/from-account`, `POST /users/<id>/account`, `POST /users/<id>/offboard`, `DELETE /users/<id>`; `PATCH /users/<id>` gains the WordPress-side duplicate check and `Accounts::push()` |
| `includes/Rest/MembershipsController.php` (modify) | `PATCH /memberships/<id>` accepts `status: 'inactive'` (ends it) |
| `tests/e2e/people-rest.spec.js` (modify — exists; extend) | Route behaviour |
| `src/types.ts` | `'people'` in `ScreenName`; `Account`, `PersonAnswer` (`Person`/`Membership` exist — check and extend) |
| `src/App.tsx` | Rail entry Team → People (before Availability); `TITLES`, `OPENINGS`, mount; `#screen=people&person=<id>` opens that card's panel |
| `src/components/PeopleScreen.tsx` (create) | The screen |
| `src/styles.css` | `.bwx-people`, `.bwx-people-cards` |
| `tests/e2e/people-app.spec.js` (create) | The screen, driven as a person would |
| `tests/e2e/shell.spec.js`, `tests/e2e/accessibility.spec.js` | Rail order if asserted; `[ 'People', 'bwx-screen-people' ]` + a seeded person |
| version files, `CHANGELOG.md` | 2.110.0 |

## Answer shapes

- `GET /accounts` → `{ ok, accounts: [ { id, login, display_name, user_email } ] }` — `Accounts::unlinked()`, exactly what the admin page's two pickers list.
- Person answer (every user write, and `GET /users/<id>` gains `memberships`): `{ ok, user: {…Users row…, account: { id, login } | null }, memberships: [ {…Memberships row…, client_name, site_name|null } ] }`. `account` comes from `Accounts::account( wp_user_id )` when linked. `client_name` from `Clients::get`, `site_name` from `ClientSites::get` — the screen must not make N calls to label a card. Add a private `UsersController::answer( array $user ): array` that builds this; `GET /users` (index) is unchanged.

---

### Task 1: Accounts, add-from-account, link, and add-with-account

**Files:** `includes/Rest/UsersController.php`, `tests/e2e/people-rest.spec.js`

**Mirror:** `PeopleActions::add_person()`, `::add_person_from_wp()`, `::link_account()`.

**Routes:**
- `GET /accounts` — `Accounts::unlinked()`.
- `POST /users` (existing): when the body carries no `wp_user_id`, after `Validate::user` and the duplicate-email check, call `Accounts::ensure( display_name, email )`; `<= 0` → `400 bwx_forge_no_account` ("WordPress would not make an account for them, so nothing was saved."); an account already linked to a person → `409 bwx_forge_duplicate_user`; then set `wp_user_id` and create as now. When `wp_user_id` is sent, behaviour is unchanged (the helpers depend on it). Answer becomes the person answer.
- `POST /users/from-account` body `{ wp_user_id }` — `Accounts::account()` null → 404 `bwx_forge_unknown_account`; already a person → 409 `bwx_forge_duplicate_user`; `Validate::user` on the account's name/email; email held by another person → 409 duplicate; `Users::create`. Idempotency op `users.from_account`.
- `POST /users/<id>/account` body `{ wp_user_id: number|0, record_version }` — 0 means make one from the record (`Accounts::ensure`); unknown account → 400 `bwx_forge_no_account`; `Accounts::link_error()` non-null → 409 `bwx_forge_duplicate_user` with the sentence; `Users::update( id, { wp_user_id }, version )` null → 409 stale (via `Versioning::check` re-read, as `update()` does); then `Accounts::pull` (chosen) or `Accounts::push` (made). Answer: person.

**Tests (append to `people-rest.spec.js`, run-id names; `makeSite` for a client; create WP users through `/wp-json/wp/v2/users` as `makePerson` does):**
1. `GET /accounts` lists a WP user made this run and not linked; after `POST /users/from-account` with it, it is gone from the list and the person carries `account.login`.
2. `POST /users` without `wp_user_id` makes an account: the answer's `user.wp_user_id > 0`, `account.login` truthy, and `GET /wp-json/wp/v2/users/<id>?context=edit` shows the email.
3. `POST /users/from-account` twice → second is 409 `bwx_forge_duplicate_user`; unknown id → 404.
4. A person created with no account (`POST /users` with `wp_user_id: 0`? — check `Validate::user`; if 0 is refused, create via `Users::create` path used by pre-#292 rows is not reachable over REST; then test link with an existing account only): `POST /users/<id>/account` with a fresh unlinked account → `account.login` matches; with an account another person holds → 409; with a stale `record_version` → 409 `bwx_forge_stale_write`.
5. Non-admin: 403 on `GET /accounts` and `POST /users/from-account`.

Commit: "People over REST: accounts, add from an account, link an account".

---

### Task 2: Edit, offboard, delete, and ending a membership

**Files:** `includes/Rest/UsersController.php`, `includes/Rest/MembershipsController.php`, `tests/e2e/people-rest.spec.js`

**Mirror:** `PeopleActions::edit_person()`, `::offboard_person()`, `::delete_person()`, `::end_membership()`, `::set_membership_grants()`.

**Routes:**
- `PATCH /users/<id>` (existing) gains what the admin edit has and the route lacks: the WordPress-side email clash check (`get_user_by( 'email' )` holder ≠ this person's `wp_user_id` → 409 duplicate), and `Accounts::push( $updated )` after a successful write. `grants` already flow through `Validate::user`. Answer: person.
- `POST /users/<id>/offboard` body `{ record_version }` — `Users::deactivate( id, version )`; null → stale 409 via re-read. Answer: person (memberships now all inactive).
- `DELETE /users/<id>` — active person → 400 `bwx_forge_person_active` ("Offboard them first."); `Users::delete()` false (has history) → 400 `bwx_forge_person_has_history` ("Somebody with work attributed to them cannot be deleted, only offboarded."); success → `{ ok, deleted: id }`.
- `PATCH /memberships/<id>` (existing) — `Validate::membership` already yields `status`; when `status === 'inactive'` call `Memberships::deactivate( id, version )` instead of `update()` (exactly as `UsersController::update()` branches on status). Grants already accepted. Answer unchanged (`{ ok, membership }`) — the screen re-reads the person after a membership write via `GET /users/<id>` (one call; acceptable, the person answer is small).
- `GET /users/<id>` gains `memberships` and `account` (the person answer).

**Tests:**
1. Edit name → WP account display name follows (`/wp-json/wp/v2/users/<id>`); edit to another person's email → 409; stale version → 409.
2. Offboard → `user.status === 'inactive'`, every membership `inactive`; then `DELETE` on an offboarded person with no work → `deleted`; `DELETE` on an active person → 400 `person_active`.
3. A person with attributed work (make an item with `makeItem` and `walkTo`/assign — or simpler: `Users::has_history` counts memberships too? read it; use whichever the domain counts) → `DELETE` 400 `person_has_history`.
4. `PATCH /memberships/<id>` `{ status: 'inactive', record_version }` → `membership.status === 'inactive'`; `{ grants: [...], record_version }` → grants stored (use a grant from `Grants::ON_MEMBERSHIP`; read the list). A studio-only grant on a client role → 400 with `fields.grants`.
5. Non-admin 403 on offboard and delete.

Commit: "People over REST: edit follows the account, offboard, delete, end a membership".

---

### Task 3: The screen — cards, add, add from account, edit

**Files:** `src/types.ts`, `src/App.tsx`, `src/components/PeopleScreen.tsx`, `src/styles.css`, `tests/e2e/people-app.spec.js`, `tests/e2e/accessibility.spec.js`, `tests/e2e/shell.spec.js` (only if it asserts the Team group's order)

**Rail:** `{ key: 'people', label: 'People', icon: Users (lucide), testId: 'bwx-screen-people' }` first under `{ group: 'Team' }`, before Availability. `TITLES.people = 'People'`; `OPENINGS.people = { crumbs: [ 'Team', 'People' ], eyebrow: 'Everyone, and everywhere they work', tile: Users, hue: 'violet' }`. Mount `<PeopleScreen key={ generation } person={ landing.person } />` — `landing.person` already exists from PR 1; when set, the screen opens that person's panel.

**Data:** `GET /users?status=all` for the cards; `GET /clients` (existing) for names? — no: the person answer carries `client_name`/`site_name`; but the card list from `GET /users` does not carry memberships. Add `memberships: boolean` param? Keep it simple: the screen loads `GET /users?status=all` and `GET /memberships/by-client`? — Neither exists in the right shape. **Decision:** `GET /users` gains an optional `?with=memberships` param; when set, each user carries `memberships` (labelled as in the person answer) and `account`. One read draws the whole screen. Implement in Task 3 (small controller change, one test in `people-rest.spec.js`: `?with=memberships` returns memberships for a person who has one).

**Screen:**
- Root `bwx-people`; a toggle `bwx-people-show-all` (Check or Button) between "Active" and "Everyone, including offboarded" (default active, as the admin page).
- `bwx-people-add` button → Modal "Add somebody new" (`bwx-people-form`, fields `-form-name`, `-form-email`, `-form-save`, `-form-cancel`, `-form-notice`; help "They get a WordPress account and an email to set their password"); `bwx-people-add-from-account` → Modal "Add someone who already has an account" (`bwx-people-account-form`, Select `bwx-people-account-pick` listing `GET /accounts` as "display_name (login)", save `bwx-people-account-save`); empty list → the admin page's "Everyone with an account is already a person" line.
- Cards grid `bwx-people-cards`; each card `bwx-people-card` with `data-person=<id>`: name, email, status Tag (Active / Offboarded), "Signs in as <login>" or "No WordPress account — they cannot sign in." (`bwx-people-card-account`), and a memberships DataView `bwx-people-memberships` (Client, Role, Reaches: "every site"/"one site: <name>", Status, Grants) or the empty line "No client access yet". Card buttons: `bwx-people-edit`, `bwx-people-link` (only when no account), `bwx-people-offboard` (active only), `bwx-people-delete` (offboarded only), `bwx-people-add-membership`.
- Edit Modal `bwx-people-edit-form`: name, email, status Select (`-edit-status`: Active/Offboarded — choosing Offboarded warns in a `bwx-hint` that every membership ends), grants checkboxes from `Grants::ON_USER` — the screen needs the list: add `GET /grants` → `{ ok, on_user: [{ grant, label, description }], on_membership: [...] }` (tiny route in `UsersController`, `Grants::label/description`), saved with `record_version`.

**Tests (`people-app.spec.js`, serial, run-id):**
1. Rail offers People under Team; h1 "People"; a person made in `beforeAll` shows as a card with their client and role.
2. Add somebody new → card appears, "Signs in as" shown.
3. Add from account (make a WP user via `/wp-json/wp/v2/users` first) → card appears with that login.
4. Edit name → card updates without reload; email clash → notice in the form.
5. `#screen=people&person=<id>` (goto + reload) opens with that person's edit panel open.

Commit: "People screen in the app: cards, add, add from an account, edit".

---

### Task 4: Link, offboard, delete, memberships and grants

**Files:** `src/components/PeopleScreen.tsx`, `tests/e2e/people-app.spec.js`

- Link Modal `bwx-people-link-form`: Select `bwx-people-link-pick` ("Make them a new one" = 0, then unlinked accounts), help "An existing account keeps its own name and address. A new one takes theirs.", save `bwx-people-link-save`.
- Offboard: `window.confirm` "Offboard <name>? Every membership ends and they can no longer sign in." → `POST .../offboard`.
- Delete: confirm "Delete <name> from Forge? Their WordPress account stays." → `DELETE`; card removed; a refusal shows in `bwx-people-notice`.
- Add membership Modal `bwx-people-membership-form`: client Select (`-membership-client`, from `GET /clients`), role Select (`-membership-role`, from `Roles::ALL` — the screen has a `ROLES` const already? check `src/types.ts`/`ItemPanel`; else hardcode the five with labels), site Select (`-membership-site`, "Every site" or one of the client's sites from `GET /clients/<id>/sites` or `/client-sites?client=<id>` — use whichever exists), save `-membership-save`; `POST /clients/<id>/memberships` with `user_id`, then `GET /users/<id>` to refresh the card.
- Per membership row: `bwx-people-membership-end` (confirm; `PATCH /memberships/<id>` `{ status: 'inactive', record_version }`), `bwx-people-membership-grants` opens Modal `bwx-people-grants-form` with checkboxes from `GET /grants`.on_membership, save `-grants-save` (`PATCH` `{ grants, record_version }`).

**Tests:**
6. Add a membership on a second client → the card lists both clients.
7. Set a membership grant → the Grants column shows its label; end the membership → row shows Ended (in "Everyone" view) and is gone from the active view.
8. Offboard → status Offboarded, memberships ended; Delete → card gone.
9. Accessibility: `[ 'People', 'bwx-screen-people' ]` in `APP_SCREENS`; the walk already seeds a person via `makePerson`? — check; if not, seed one.

Commit: "People screen: link an account, offboard, delete, memberships and grants".

---

### Task 5: Version, changelog, lint, targeted specs, PR

- 2.110.0 in five places; changelog: "People are now in the app, under Team: one card per person with every client they work with beneath it. Add somebody, give them an account, edit, offboard, set their access and grants — without going to WordPress admin. The admin page stays for now."
- `npm run lint`, `composer lint`, `npm run build`, restore the client bundle.
- Run: `people-rest.spec.js`, `people-app.spec.js`, `shell.spec.js`, `availability-app.spec.js`, `accessibility.spec.js -g "every application screen passes"`, `capacity-rest.spec.js` (uses makePerson), `memberships`-related: `tests/e2e/rest.spec.js` and `tenant-isolation.spec.js` if they touch `/users` or memberships (grep `'/users'` in tests/e2e to pick the set). All green.
- Commit "Forge 2.110.0: People are in the app" (with `assets/`); push; `gh pr create --draft --base packages-in-app --title "Forge 2.110.0: People are in the app"` with a body: what moved, the routes added, "stacked on #351", full suite deferred to the cutover PR per the user's instruction, attribution line.
