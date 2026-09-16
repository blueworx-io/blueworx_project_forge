# Moving the configuration screens into the app

Approved 2026-09-16. Six WordPress admin pages — Clients, People, Availability,
Support Packages, Support and Meetings — become screens in the React studio
application. When all six are live, one final pull request removes the admin
pages.

This reverses ARCH-7, which put configuration screens in WordPress admin and
work screens in the app. The reason for the reversal: the app is now where
staff spend their day, the kit and shell exist, and a rail that sends people
out to WordPress admin for half of a client's setup is two interfaces for one
job. The decision record is rewritten at cutover, not before.

| PR | What | Version |
|----|------|---------|
| 1 | Availability | 2.108.0 |
| 2 | Packages | 2.109.0 |
| 3 | People | 2.110.0 |
| 4 | Clients | 2.111.0 |
| 5 | Support | 2.112.0 |
| 6 | Meetings | 2.113.0 |
| 7 | Cutover: the six admin pages go | 2.114.0 |

Each PR is its own branch off `main`, opened as a draft, and merged in order.
Versions are the intent; a bump landing between them shifts the rest. Nothing
is merged or tagged by Claude.

Out of scope: Sites, Connections, Onboarding templates, Sales, Sync health and
Updates stay as WordPress admin pages. They can get their own plan later.

---

## What every PR does

The shape is the same seven times, so it is written once.

### REST first

Every screen talks to the server through `blueworx-forge/v1` routes, and only
through them. The admin form handlers (`admin_post_bwx_forge_*`) are never
called from the app. Where a route already exists (clients, client sites,
users, memberships) the screen uses it; where one is missing, the PR adds it.

New routes follow the conventions in `docs/architecture/rest-conventions.md`
and the checks in `tests/e2e/rest-conventions.spec.js` and
`write-conventions.spec.js`: administrator-only through
`Rest\Permissions`, versioned, idempotency key on every write, errors through
`Rest\Errors`. Controllers are thin. The logic stays where the admin actions
already call it — `Capacity`, `Commerce`, `Meetings`, `Tenancy` — and a
controller that finds itself doing arithmetic or validation the domain class
does not do is wrong.

A response returns the record as the screen will show it, so a screen never
has to re-read after a write.

### Then the screen

One component in `src/components/<Name>Screen.tsx`, added to `ScreenName`,
`TITLES`, `OPENINGS` and the rail in `src/App.tsx`, mounted the way the other
screens are (keyed on `generation`, so the header's refresh re-reads it).

Built the way Recurring and Subscriptions are built:

- Data through `api()` from `src/api.ts`; live reload through `useLiveReload`.
- The shell through `Screen` in `States.tsx`: loading, error, denied and empty
  states are the shared ones.
- Lists on the kit's `DataView`; forms in a kit `Panel` opened from the list,
  never inline in a row; confirmations that carry a reason through
  `ReasonAction`; results through `useToast`.
- Errors shown through `messageFor`; a denied request through `isDenied`.
- Nothing styled outside the kit. A screen that needs a piece the kit lacks
  adds it to `src/kit/` with a Gallery entry, and the piece is written for both
  apps, not for the screen.
- Every interactive element carries a `data-testid` with the `bwx-` prefix,
  and the screen honours the accessibility rules the shell already tests:
  labelled controls, heading order, keyboard reach.

Screens that are about one site at a time (Support, Meetings) use the site
picker from `src/sites.ts` — `recallSite` and `rememberSite` — so the site a
person chose on the board is the site these screens open on. Screens that are
about one person (Availability) pick the person the same way, with a picker of
the studio's people.

A screen can be opened by hash, `#screen=<name>` with `&site=<id>` or
`&person=<id>` where the screen takes one, so a link from elsewhere in the app
or from Slack lands on the right thing. This extends the `#item=` handling
that already exists in `App.tsx`; it does not replace it.

### Tests

- `tests/e2e/<name>-rest.spec.js` for the routes: each route's happy path, a
  denied non-administrator, and the validation the domain class enforces.
- `tests/e2e/<name>-screen.spec.js` for the screen, driving it as a person
  would: open, see, add, change, and the result showing without a reload.
- The shared helpers, `tests/e2e/helpers/forge.js` and
  `tests/pair/helpers/onboarding.js`, currently build test state by driving
  the admin forms. Each PR moves the helper functions its routes cover onto
  REST. The nineteen specs that set up state through those helpers keep
  passing without change; at cutover there is nothing left to move.
- The admin page and its spec stay exactly as they are until PR 7. Both
  interfaces run side by side, and the admin page is the fallback if a screen
  turns out to have a gap.

### Version and changelog

Minor bump in `package.json`, both plugin headers and the version constant, and
a changelog entry written for the person using it.

---

## PR 1 — Availability

**Today:** `Admin\AvailabilityScreen`, `Admin\AvailabilityActions`. Three
actions: set a person's weekly hours, add a leave period, remove one. Logic in
`Capacity\Availability`, `Capacity\Patterns`, `Capacity\Unavailability`.

**Routes:**

- `GET /people/<id>/availability` — the weekly pattern and every leave period,
  with the effective-from date of the pattern.
- `PUT /people/<id>/availability/hours` — set the week. Body: hours per day and
  effective-from. Returns the record.
- `POST /people/<id>/leave` — add a period: from, to, reason. Returns it.
- `DELETE /people/<id>/leave/<leave_id>` — remove a period.

**Screen:** a person picker across the top; beneath it the week as seven
fields, and the leave periods as a DataView with an add panel and a remove
action. The same validation the admin page applies, returned by the route
rather than repeated in the screen.

**Rail:** Team → Availability.

---

## PR 2 — Packages

**Today:** `Admin\PackagesScreen`, `Admin\PackageActions`. Four actions: add,
revise, set status, reorder. Logic in `Commerce\Packages`. COMM-1 is the point
of the screen: editing writes a new version and the old ones stay visible.

**Routes:**

- `GET /packages` — the catalogue in order, each package with its current
  version and full version history.
- `POST /packages` — add. Returns the package.
- `POST /packages/<id>/versions` — revise: hours, price, terms. Returns the
  package with its new current version. There is no `PATCH` of a version; that
  is the promise made visible in the API's shape.
- `PATCH /packages/<id>` — status (active, retired) only.
- `PUT /packages/order` — the ordered list of ids.

**Screen:** a DataView of the catalogue with drag or up/down ordering, each
row expandable to its version history. Add and revise in a panel; the revise
panel says, in words, that saving creates version N+1.

**Rail:** Insight → Packages, replacing the "Packages & hours" link out.

---

## PR 3 — People

**Today:** `Admin\PeopleScreen`, `Admin\PeopleActions`. Nine actions: add a
person, add from an existing WordPress account, edit, link an account, delete,
offboard, add a membership, end one, set a membership's grants. Logic in
`Tenancy\Users`, `Tenancy\Accounts`, `Tenancy\Memberships`, `Tenancy\Grants`.

**Routes already there:** `GET/POST /users`, `GET/PATCH /users/<id>`,
`GET/POST /users/<id>/memberships`, `PATCH .../memberships/<id>`.

**Routes to add:**

- `GET /accounts` — WordPress accounts not yet linked to a person, for "add
  from an existing account" and "link account".
- `POST /users/from-account` — add a person from an account.
- `POST /users/<id>/account` — link an existing person to an account.
- `POST /users/<id>/offboard` — end every membership and disable sign-in,
  with a reason. Returns the person.
- `DELETE /users/<id>` — only where the admin page allows it today (no
  memberships, no attributed work); otherwise the same refusal.
- `PATCH .../memberships/<id>` gains `ended_at` and `grants` if it does not
  carry them already.

**Screen:** the AUTH-6 view the admin page is built around — one card per
person, every client they touch beneath it. Add, edit, link and offboard in
panels; memberships and grants edited inside the person's panel.

**Rail:** Team → People.

---

## PR 4 — Clients

**Today:** `Admin\ClientsScreen`, `Admin\ClientActions`. Eleven actions: add
and edit a client, deactivate one, add and edit a site, deactivate a site,
assign a contact, assign an onboarding template, issue a site key, revoke one,
rename the studio. Logic in `Tenancy\Clients`, `Tenancy\ClientSites`,
`Tenancy\Contacts`, `Tenancy\Secrets`, `Onboarding`.

**Routes already there:** `GET/POST /clients`, `GET/PATCH /clients/<id>`,
`GET/POST /client-sites`, `GET/PATCH /client-sites/<id>`.

**Routes to add:**

- `PATCH /clients/<id>` and `PATCH /client-sites/<id>` gain `active: false`
  for deactivation if they do not carry it already.
- `PUT /client-sites/<id>/contact` — the site's contact person.
- `PUT /client-sites/<id>/onboarding-template` — the template a site runs.
- `POST /client-sites/<id>/keys` — issue a key. The key is in this response
  and nowhere else, exactly as `Admin\IssuedKey` promises: never stored
  readable, never in a URL. The screen shows it once in the panel, with a copy
  button, and it is gone when the panel closes.
- `DELETE /client-sites/<id>/keys/<key_id>` — revoke.
- `PUT /studio` — the studio's own name.

**Screen:** a DataView of clients, each expanding to its sites. Client and
site forms in panels; contact, template and keys inside the site's panel. The
studio's own client (from `Tenancy\Studio`) is shown and can be renamed but
not deactivated.

**Rail:** Clients → Clients, first in the group.

---

## PR 5 — Support

**Today:** `Admin\SupportScreen`, `Admin\SupportActions`. Six actions: assign a
package, top up, adjust with a reason, suspend, resume, cancel. Logic in
`Commerce\Support`, `Commerce\Assignments`, `Commerce\Ledger`,
`Commerce\ProRata`, `Commerce\Entries`.

**Routes:**

- `GET /client-sites/<id>/support` — the position today, every period, and
  the ledger with each entry's reason.
- `GET /client-sites/<id>/support/preview?package=<id>&from=<date>` — the
  pro-rata figure. The same `Commerce\ProRata` call the assignment writes
  with, so the number shown and the number written cannot differ (COMM-2).
- `POST /client-sites/<id>/support` — assign a package from a date. Returns
  the position.
- `POST /client-sites/<id>/support/top-up` — hours and reason.
- `POST /client-sites/<id>/support/adjust` — signed hours and a required
  reason (CAP-3).
- `POST /client-sites/<id>/support/suspend`, `.../resume`, `.../cancel`.

**Screen:** site picker; three sections as the admin page has them — what the
site is on, its periods, its ledger. Assign opens a panel that shows the
preview figure as the package and date change, and the assign button carries
the figure it will write.

**Rail:** Clients → Support.

Depends on PR 2 (the catalogue the assign panel lists) and PR 4 (the sites).

---

## PR 6 — Meetings

**Today:** `Admin\MeetingsScreen`, `Admin\MeetingActions`. Four actions: add a
series, end one, move an occurrence, settle one. Logic in `Meetings\Series`,
`Meetings\Recurrence`, `Meetings\Occurrence`, `Meetings\Hours`,
`Meetings\Validate`.

**Routes:**

- `GET /client-sites/<id>/meetings` — the site's series and the next twelve
  weeks of occurrences (MEET-4's horizon). `Hours::reconcile_site()` runs on
  this read, as it runs on the admin page's render today: there is no cron,
  so the moment someone looks is the moment the balance is settled.
- `POST /client-sites/<id>/meetings/series` — add.
- `POST /client-sites/<id>/meetings/series/<id>/end` — end from a date.
- `POST /client-sites/<id>/meetings/<occurrence>/move` — new date and time.
- `POST /client-sites/<id>/meetings/<occurrence>/settle` — hours used, which
  releases or charges against the reservation.

**Screen:** site picker; the series as cards, the twelve weeks as a DataView
grouped by week with move and settle actions on each row.

**Rail:** Clients → Meetings.

Depends on PR 5 (hours reserve against the support balance).

---

## PR 7 — Cutover

Done only once all six screens have been used for real and nobody has needed
the admin page.

- Delete `includes/Admin/{Clients,People,Availability,Packages,Support,Meetings}Screen.php`
  and their `*Actions.php`, and the `Plugin` lines that register them.
- Delete `tests/e2e/{availability-screen,package-catalogue,people-screen,people-accounts,clients-screen,support-assignment,meetings}.spec.js`
  where the spec exists to drive the admin page; a spec that tests domain
  behaviour through the page is rewritten to reach it through REST rather
  than deleted.
- Confirm the two shared helpers no longer reference any of the six admin
  page slugs or actions.
- Remove the rail's `href` entry type if Sync health is the only user left,
  or leave it if it still is — the link out to Sync health stays.
- Rewrite ARCH-7 in `docs/architecture/decisions.md` and
  `decisions-manifest.json`: the question stands, the decision becomes "every
  screen the studio uses, for the work or to configure it, is the React
  application; WordPress admin holds only what needs WordPress itself — the
  app page, site connection, updates and sync", with the date and reasoning
  above. The "consequence if reversed" is rewritten to match.
- Update the header comments in `src/App.tsx` that say Packages is still an
  admin screen, and `docs/moving-from-forge-project-management.md` wherever it
  points a reader at one of the six pages.
- Changelog entry: the six pages are now in the app; where to find each.

---

## Cross-cutting

- **Permissions do not change.** All six admin pages require
  `manage_options`; every route added here uses the same administrator check
  in `Rest\Permissions`. A member without it sees the denied state, not the
  screen.
- **The client plugin is untouched.** Nothing here crosses into `client/`;
  `bin/check-artifacts.mjs` will say so.
- **No new dependencies.** Everything is built from what the kit and the
  existing routes provide.
- **Design:** built from the kit on the existing tokens, with the
  `frontend-design` skill for each screen as the project's rules require. No
  Claude Design round trip; the kit is the design.
- **What is deliberately not done:** no bulk actions, no import or export, no
  audit view beyond what the admin pages show today. Each screen does what its
  admin page does, in the app, and no more.
