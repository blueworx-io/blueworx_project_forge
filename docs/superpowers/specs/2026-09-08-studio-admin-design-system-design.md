# The studio's backend, on the shared admin design system — design

Every screen the Blueworx Forge studio plugin puts in wp-admin is built from raw
WordPress admin markup today. This brings all of them onto the design system the
foundation ships — the same system the client plugin moved onto in #285 — and
moves the screens that are really configuration forms onto the shared page editor
library.

## Why now

#285 did the hard part and did it once. The foundation's design system is already
vendored in this repo at the path its drift check expects; `bin/sync-design-system.mjs`
already lays the stylesheet, fonts, icon set and page editor script into `assets/`
after Vite empties it; `bin/artifacts.json` already carries the widened shareable
list, and ARCH-8 already records why those paths may cross the artifact boundary.

None of it reaches the studio. The studio plugin ships `assets/` wholesale, so the
stylesheet is physically present in its zip and no screen enqueues it. Eleven
screens, about 6,600 lines of PHP, drawing on `wrap`, `form-table`,
`widefat striped`, `regular-text` and `notice` — WordPress's own admin classes and
nothing else. One screen, Sites, enqueues Forge's `tokens/forge.css` and is
otherwise the same.

So the studio's backend is the last place in the estate still hand-building admin
screens, and it is now the only reason the client's backend and the studio's
backend look like two different products.

## Scope

Eleven screens:

| Screen | Slug | Shape | Existing spec |
|---|---|---|---|
| Sites | `blueworx-forge-sites` | List + connect/disconnect form | `sites-screen.spec.js` |
| Clients | `blueworx-forge-clients` | List + detail + several forms | `clients-screen.spec.js` |
| People | `blueworx-forge-people` | List + add form + grants | `people-screen.spec.js` |
| Packages | `blueworx-forge-packages` | Catalogue + versioned edit form | `package-catalogue.spec.js` |
| Onboarding templates | `blueworx-forge-onboarding-template` | Versioned template editor | `onboarding-template.spec.js` |
| Availability | `blueworx-forge-availability` | Per-person patterns and time off | `availability-screen.spec.js`, `capacity-screen.spec.js` |
| Meetings | `blueworx-forge-meetings` | Series editor + occurrence list | `meetings.spec.js` |
| Support | `blueworx-forge-support` | Entitlement form + hours ledger | `support-assignment.spec.js`, `support-hours-gate.spec.js` |
| Sync | `blueworx-forge-sync` | Two lists, no editing | `sync-health.spec.js` |
| Sales | `blueworx-forge-sales` | Cross-client dashboard | `sales-admin.spec.js` |
| Updates | `blueworx-forge-updates` | One option, three values | `updates-screen.spec.js` |

`Admin\BoardLink` is a menu entry that opens the React application in a new tab.
It renders no screen and is untouched.

The React application is **out of scope**. ARCH-7 settles which screens are React
and which are plain WordPress admin pages, and this changes nothing about that
split — only how the plain admin pages are built.

## Delivered as two pull requests

One spec, two branches, in this order.

**PR one is the look.** Nothing anyone saves changes. Every screen keeps the save
path it has, and every existing screen spec must pass unchanged.

**PR two is the forms.** The screens that are really configuration forms move onto
the shared page editor library, which is the only part of this work that changes a
save path.

The split is not ceremony. Restyling eleven screens is a change with a clean
pass/fail story — the suite is green or it is not. Moving a save path onto a
different library is a behaviour change that needs its own reading. Landing both in
one pull request means one bad save path can hold up eleven screens' worth of
finished work, and it means a reviewer reading a 6,600-line diff cannot tell which
lines are which.

## PR one — the look

### What the studio's backend will look like

The foundation's look: its palette, Sora and Inter, its spacing and elevation. The
same answer #285 gave for the client, for the same reason — a mapping layer
between the foundation's `--bw-*` variables and Forge's `--color-*` layer is a
thing to maintain and is invisible to the drift check, which is exactly how an
admin UI quietly becomes its own design system again.

Forge's token layer keeps loading for the React application's sake. The admin
screens stop drawing on it, and `Admin\SitesScreen::enqueue()` — the one screen
that pulls in `tokens/forge.css` today — stops doing so.

### The shared page shell

A new `Admin\Page`, the studio's counterpart to `client/includes/Admin/Page.php`:
the foundation's `wrap bw-wrap` / `bw-admin bw-page` skeleton, the page header,
and the enqueue.

The enqueue is the part that needs care. The studio serves both the React
application and these admin screens from the same wp-admin, so the design system
is enqueued **on studio admin screens only**, keyed on the hook suffix the way
`SitesScreen::enqueue()` already keys on `toplevel_page_`. `style-isolation.spec.js`
exists because this has been a problem before; it is the spec that has to stay
green.

There is no tab strip. The client learned that lesson in #126 — the WordPress
side menu already lists these pages, and one navigation is enough.

### The screens

Each of the eleven is rebuilt from named components rather than WordPress admin
classes: `DataTable`, `Pagination` and `RowActions` for the listings; `Card`,
`SummaryStrip`, `StatCard` and `EmptyState` for the dashboards; `Field`, `FormRow`,
`Input`, `Select`, `Textarea`, `Checkbox` and `SaveBar` for the forms; `Badge`,
`Chip`, `Notice`, `DescriptionList`, `ActivityLog` and `ProgressBar` for
everything else. The system has 52 components and, unlike the client's calendar,
nothing on the studio side has no counterpart — these are lists, forms and
dashboards, which is what the system is for.

**Every screen keeps its existing `data-testid` attributes.** They are the contract
the eleven specs hold, and they are what makes this a rebuild rather than a
rewrite with a hope attached. Adding new ones is fine; removing or renaming one is
not, unless the same commit updates the spec that reads it.

## PR two — the forms

### What the library can and cannot take

The page editor library edits **one record's fields**. It does not build a listing,
row actions, or a dashboard. So on most of these screens it takes the edit half and
the list half stays hand-built on components — and on two screens it takes nothing
at all.

| Screen | What moves | Store |
|---|---|---|
| Updates | The whole screen | `option` |
| Sites | The connect form | callback |
| Clients | The client detail form | callback |
| People | The add-person and grants forms | callback |
| Packages | The package edit form | callback |
| Onboarding templates | The template edit form | callback |
| Availability | The pattern and time-off forms | callback |
| Meetings | The series form | callback |
| Support | The entitlement form | callback |
| Sync | Nothing — it is two lists | — |
| Sales | Nothing — it is a dashboard | — |

Updates is the only clean option-backed fit: one option, three values, exactly the
shape the client's connection screen had.

Everything else lives in custom tables — `wp_bwx_forge_clients`,
`wp_bwx_forge_users`, `wp_bwx_forge_memberships` and the rest — not in posts or
options. The library already has the door for this: supplying a screen with `read`
and `write` callbacks selects `CallbackStore`, and the schema, the capability
filtering in both directions, the sanitising, the validation and the save bar all
still apply. The plugin keeps owning its own storage; it stops owning the form.

### The exclusion that has to be reversed, and argued

`.github/workflows/ci.yml` and `.github/workflows/release.yml` both exclude
`/blueworx-page-editor` from the studio zip, with a reason written into both:

> Vendored for the client plugin, which registers the shared page editor. The
> studio's screens are the React application (ARCH-7), so this has no business in
> the studio artifact.

PR two contradicts that reason, so it has to answer it rather than delete it.

ARCH-7 has not changed. The screens people use to do the work — the board, work
items, every view of work — are still the React application. What the comment
misses is the other half of ARCH-7: the screens that *configure* the system are
plain WordPress admin pages, there are eleven of them on the studio, and the page
editor library is precisely what the foundation says builds a settings screen.

So PR two removes the exclusion from both workflows, adds `blueworx-page-editor` to
the studio's `include` list in `bin/artifacts.json`, and records the change of
reasoning as **ARCH-9** — the studio ships the page editor library because its
configuration screens are admin pages under ARCH-7, not because the boundary moved.
A comment edited away leaves the next person no way to know the question was asked.

Note that this is an `include`, not a `shared` path: the library sits at the repo
root, which is studio territory by ARCH-1's geography rule, so ARCH-8's closed list
is untouched.

## What actually catches a regression

Worth being plain about, because #285's spec was written around two guardrails and
only one of them applies here.

**The drift check works and is unaffected.** `check-design-system-sync.mjs` compares
the vendored system against the foundation's, and it has real content to compare
since #285. Nothing in this work touches it.

**The adherence check will not see any of this.** `adminUiAdherence` runs oxlint
with the React plugin over JavaScript and JSX. These screens are PHP rendering
markup, so the check will keep passing by finding nothing on the studio side no
matter what we build. It is not a reason to skip the work and it is not a reason to
turn anything to `warn` — it is a reason not to mistake a green run for coverage.

**The net is the eleven existing specs, plus review.** Every screen has one, they
anchor on `data-testid` rather than on markup or class names, and they pass before
and after. PR one adds no new coverage because it changes no behaviour. PR two adds
coverage for each save path it moves, because a save path is behaviour.

`accessibility.spec.js` and `style-isolation.spec.js` are the two that matter
beyond the per-screen specs: the first because a rebuild is exactly when heading
order and form labels get lost, the second because the design system is now loading
next to the React application.

## How we know it is done

- The eleven screen specs pass unchanged after PR one.
- No admin screen enqueues `tokens/forge.css`; the React application still does.
- `style-isolation.spec.js` and `accessibility.spec.js` pass after both PRs.
- Nine screens' forms are the page editor library's, with new specs covering each
  moved save path.
- The studio zip contains `blueworx-page-editor`, and ARCH-9 says why.
- `npm run lint`, `npm run build`, `composer lint` and `npm run test:unit` pass on
  both branches.
