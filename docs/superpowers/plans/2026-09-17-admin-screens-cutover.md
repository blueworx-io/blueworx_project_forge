# Admin Screens Cutover — Implementation Plan (PR 7 of 7)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The six WordPress admin pages — Clients, People, Availability, Packages, Support, Meetings — are removed; their specs are deleted or rewritten to REST; the ARCH-7 decision is rewritten; the full suite is green.

**Architecture:** Deletion, mostly. Every screen and route already exists on the branches beneath. What remains is removing the PHP admin pages and their `Plugin` registrations, deleting the specs that only drove those pages, moving the few domain-behaviour specs that reached the domain through a page onto REST, rewriting the decision record and the two documents that point readers at the pages, and running the whole suite once on the result.

**Spec:** "PR 7 — Cutover" and "Cross-cutting". The spec's precondition ("done only once all six screens have been used for real") is overridden by Luke's instruction of 2026-09-17 to ship all of it tonight and test together afterwards.

## Global Constraints

- Branch `admin-screens-cutover` off `meetings-in-app`. Draft PR targets `meetings-in-app`. Version `2.113.0` → `2.114.0`; changelog `## [2.114.0] - 2026-09-17`.
- Nothing under `client/` but the version lines; no new dependencies; restore the client bundle after every build.
- What stays in WordPress admin and must keep working: Sites (`SiteActions`), Connections, Onboarding templates, Sales, Sync health, Updates, `Admin\Page`, `Admin\BoardLink`, `Admin\ProfileSlack`, the app page. `Admin\IssuedKey` goes only if nothing but `ClientActions` used it (grep).
- `activation.spec.js` visits the Clients admin page to prove the plugin activated; it is rewritten to visit a page that still exists (Sites or the app page) — not deleted.
- The two shared helpers (`tests/e2e/helpers/forge.js`, `tests/pair/helpers/onboarding.js`) must not reference any of the six page slugs (`blueworx-forge-{clients,people,availability,packages,support,meetings}`) or their `admin_post_bwx_forge_*` actions afterwards; `grep` proves it.
- The full suite runs here: `npm run wp:down && rm -rf .wp-test && npm run wp:up` first (fresh instance), then `WP_ADMIN_USER=admin WP_ADMIN_PASS=admin npx playwright test --workers=1`, then `npm run wp:pair:reset` and `npm run test:pair`. Every red is either fixed on this branch or named in the PR with its cause.
- Style, lint rules as before; `composer lint` and `npm run lint` once at the end; `vendor/bin/phpunit` once (unit tests may reference deleted classes — fix those references).

## File map

| Delete | |
|---|---|
| `includes/Admin/{Clients,People,Availability,Packages,Support,Meetings}Screen.php` and `{Client,People,Availability,Package,Support,Meeting}Actions.php`; `includes/Admin/IssuedKey.php` if unused | the pages |
| `tests/e2e/{availability-screen,package-catalogue,people-screen,clients-screen,meetings}.spec.js` | drove the pages only (confirm each by reading its head comment; a spec that proves domain behaviour through the page is rewritten, not deleted) |
| **Rewrite** | |
| `tests/e2e/people-accounts.spec.js` | the WordPress↔person sync it proves is domain behaviour: rewrite each test to reach it through `POST /users` (with/without `make_account`), `PATCH /users/<id>`, `/wp-json/wp/v2/users`, `DELETE`/offboard, and the People screen where it read from the page |
| `tests/e2e/support-assignment.spec.js`, `support-hours-gate.spec.js`, `tests/pair/acceptance-commercial.spec.js` | wherever they drive the Support/Packages/Meetings pages, use the REST helpers (`makePackage`, `assignSupport`, `hourLedger`, `POST …/support/*`, `POST …/meetings/*`); the assertions stay |
| `tests/e2e/activation.spec.js` | visit a surviving page |
| `tests/e2e/accessibility.spec.js` | `ADMIN_SCREENS` loses the six |
| `tests/e2e/helpers/forge.js`, `tests/pair/helpers/onboarding.js` | no page references left |
| `includes/Plugin.php` | the twelve registration lines |
| `docs/architecture/decisions.md` (ARCH-7), `docs/architecture/decisions-manifest.json` | rewritten as the spec says |
| `src/App.tsx` header comment; `docs/moving-from-forge-project-management.md` | no pointer at a removed page |
| `README.md` / any doc listing admin menu entries (grep the six slugs across `docs/` and `*.md`) | |
| version files, `CHANGELOG.md` | 2.114.0 |

---

### Task 1: Remove the pages and their registrations; unit tests green

Delete the PHP files; remove the `add_action( 'admin_menu', … )` and `::boot()` lines in `Plugin.php` for the six; grep `includes/` for any remaining reference to the deleted classes (`IssuedKey`, `PackagesScreen::url`, `SupportScreen::url`, `MeetingsScreen::url`, `AvailabilityScreen`, `PeopleScreen`, `ClientsScreen`) and resolve each; `composer lint`; `vendor/bin/phpunit` green (fix references in `tests/php/`). Confirm the app page and the surviving admin pages still load (curl `/wp-admin/admin.php?page=blueworx-forge-sites` as admin via a quick Playwright check or `activation.spec.js` after Task 3). Commit: "The six admin pages are gone; every screen the studio uses is the app".

### Task 2: Specs — delete, rewrite, helpers clean

As the file map says. For `people-accounts.spec.js`, keep each test's name and assertion, change only how it reaches the behaviour. Prove the helper rule: `grep -n "blueworx-forge-\(clients\|people\|availability\|packages\|support\|meetings\)\|admin_post_bwx_forge_\|bwx_forge_add_\|bwx_forge_assign\|bwx_forge_issue\|bwx_forge_revoke" tests/e2e/helpers/forge.js tests/pair/helpers/onboarding.js` returns nothing. Run every touched spec (studio and pair). Commit: "Specs reach the domain over REST; the admin-page specs are gone".

### Task 3: Decision record, docs, shell comment, version, changelog

ARCH-7 rewritten: question stands; decision "Every screen the studio uses — to do the work or to configure it — is the React application. WordPress admin holds only what needs WordPress itself: the app page, site connection, updates and sync health. Approved by Luke on 16 September 2026, reversing the decision of 20 August 2026: the app is where staff spend their day, the kit and shell exist, and a rail that sent people out to WordPress admin for half of a client's setup was two interfaces for one job."; consequence if reversed: "Configuration screens are built a second time as WordPress admin pages, and the rail sends people out of the app to do half of a client's setup." Manifest entry's question text unchanged unless it names the old split. `App.tsx` header: Sync health is the one link out. `moving-from-forge-project-management.md`: every pointer at one of the six pages now points at the app screen (`#screen=…`). Changelog: "The six configuration screens — Clients, People, Availability, Packages, Support and Meetings — now live only in the app: Clients, Support and Meetings under Clients; People and Availability under Team; Packages under Insight. Their WordPress admin pages are gone; Sites, Connections, Onboarding templates, Sales, Sync health and Updates stay in WordPress admin." Version 2.114.0. `npm run lint`, `composer lint`, `npm run build`, restore the client bundle. Commit: "Forge 2.114.0: configuration screens live only in the app".

### Task 4: The full suites on a fresh instance; push; PR

Fresh instance (see constraints); full studio suite; pair reset + pair suite. Every red: fix on this branch (small) or name it in the PR with the cause. Push; draft PR `--base meetings-in-app` "Forge 2.114.0: configuration screens live only in the app", body: what is gone, where each screen now lives, the suite results with any named reds, "Stacked on #<PR 6>", attribution line.
