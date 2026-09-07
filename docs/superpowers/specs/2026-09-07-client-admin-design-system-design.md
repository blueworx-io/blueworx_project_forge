# The client site's backend, on the shared admin design system — design

Every screen the Blueworx Forge client plugin puts in wp-admin is built by hand
today. This brings all of them onto the design system the foundation ships, and
turns on the two guardrails that are supposed to keep them there.

## Why now

The foundation has carried a shared admin design system for a while, and the
WordPress CI workflow runs two checks against it on every pull request in this
repo: design system sync, and admin UI adherence. Both are at `error`. Both pass.

They pass because they find nothing. `check-design-system-sync.mjs` compares a
plugin's vendored copy of the system against the foundation's, and this repo has
no copy — there is nothing to compare, so it reports success. `adminUiAdherence`
returns `ok: true` with the message *"this plugin has no blueworx-admin-design
system — skipping"* for exactly the same reason. Two required checks, switched
on, watching nothing.

That is the same failure the foundation's own README writes up under *Merging is
half the job*: a guardrail that looks green everywhere while being switched off
everywhere. It happened there to the sync check itself. It is happening here to
both.

Meanwhile the client plugin has about 4,600 lines of hand-rolled admin PHP across
nineteen files, and a 200-line inline stylesheet of its own.

## Scope

The whole client backend. Ten screens:

| Screen | Today | Built from |
|---|---|---|
| Work (top level) | `Admin\Screen` — contact, attention, upcoming, support blocks | `ScreenLayout`, `PageHeader`, `Card`, `SummaryStrip`, `EmptyState` |
| Board | `Admin\BoardScreen` — hand-rolled columns | `Card`, `Badge`, `Chip`, `EmptyState` |
| Calendar | `Admin\CalendarScreen` | No counterpart yet — see below |
| Timeline | `Admin\TimelineScreen` | `Gantt` |
| Ask | `Admin\AskScreen` | `Field`, `FormRow`, `Textarea`, `Select`, `SaveBar` |
| Asked | `Admin\AskedScreen` | `Card`, `ActivityLog`, `EmptyState` |
| Checklist | `Admin\ChecklistScreen` | `Field`, `FormRow`, `Radio`, `Checkbox`, `ProgressBar` |
| Sales | `Admin\SalesScreen` | `DataTable`, `Pagination`, `EmptyState` |
| Item (hidden) | `Admin\ItemScreen` | `PageHeader`, `Card`, `DescriptionList`, `Tabs` |
| Connection | `Admin\ConnectionScreen` | The shared page editor library |

### The calendar has no component, and that is a decision to make

The system has 53 components and none of them is a calendar. `Gantt` covers the
timeline; nothing covers a month grid.

The foundation's instruction for this case is explicit: *if the system has no
pattern for what you need, add it to the system in the foundation first, then
build the screen on it.* So the honest options are to add a `Calendar` component
to the foundation — a foundation pull request, on its own release, before this
work can finish — or to build the client's calendar from `Card` and layout
primitives and accept that it is the one screen not made of named components.

**This is the one open question in this design.** It does not block the other
nine screens, and the implementation plan sequences the calendar last so the
decision can be made while the rest proceeds. Recommendation: add it to the
foundation. A calendar is not Forge-specific, and the second plugin that wants
one is how a hand-rolled version becomes permanent.

Plus the three things every screen passes through: `Admin\Nav`, `Admin\Card` and
the page wrapper inside `Admin\Screen`.

The studio plugin's React application is **out of scope**. ARCH-7 already settles
which screens are React and which are plain WordPress admin pages, and this
changes nothing about that split — it only changes how the plain admin pages are
built.

## What the client's backend will look like

The foundation's look: its palette, Sora and Inter, its spacing and elevation.
Not Forge's own token layer dressed up in foundation structure.

The two token sets do not collide — the foundation's variables are all `--bw-*`
and Forge's are `--color-*`, `--text-*`, `--surface-*` and so on, so both can load
without a fight. That makes "adopt the structure, keep our colours" technically
easy, and it is still the wrong answer. A mapping layer between the two palettes
is a thing to maintain, and it is invisible to the drift check — which is exactly
how the client's admin UI would quietly become its own design system again a year
from now.

A client site sees a different-looking backend after the next update. Nothing
about their data, their permissions or their workflow changes.

## The plumbing, and the three things that bite

### The foundation's paths are fixed, and `assets/` is not ours to write in

`check-design-system-sync.mjs` reads its paths from the environment, but
`ci-wordpress.yml` sets only `DESIGN_SYSTEM_SYNC`, `FOUNDATION_DIR` and
`FOUNDATION_REF`. There is no workflow input for the rest, so the defaults are
binding:

```
.claude/skills/blueworx-admin-design   the vendored system
assets/blueworx-admin-design.css       the stylesheet the plugin enqueues
assets/fonts                           Sora and Inter
assets/blueworx-admin-icons.js         the Lucide set
blueworx-page-editor                   the page editor PHP library
assets/blueworx-page-editor.js         its browser half
```

Four of those live in `assets/`, and `vite.config` builds there with
`emptyOutDir: true`. Anything hand-copied in and committed is deleted by the next
`npm run build`.

So the build produces them. A step in `npm run build`, running after Vite, copies
the stylesheet, the fonts, the icon set and the page editor script out of the
vendored skill folder into `assets/`. They stay committed, exactly as the app
bundle is committed, because WordPress serves them. The vendored skill folder
stays the only source; nothing in `assets/` is ever hand-edited.

This also means the copies cannot drift from the vendored system by accident,
because the build overwrites them — and the vendored system cannot drift from the
foundation, because the sync check now compares it.

### The client zip flattens what it borrows

`bin/build-zip.sh` stages a shared path with `cp -R "$ROOT/$item"
"$STAGE/$SLUG/"`, which drops the path and keeps only the last segment. It has
never mattered: every shared path so far — `tokens`, `plugin-update-checker`,
`CHANGELOG.md` — sits at the repo root, so there was no structure to lose.

These paths are nested. `assets/blueworx-admin-design.css` would land at the
plugin root rather than in `assets/`, and the client's PHP would have to enqueue
from a different place than the studio does for the same file. That is the sort
of difference that is fine until somebody moves one of them.

So the zip builder learns to preserve a shared path's directory structure, with a
unit test for the nested case. The existing top-level shares are unaffected —
their structure is one segment, preserved or not.

### The closed list grows by five

`bin/check-artifacts.mjs` keeps `SHAREABLE` as a closed set, with the comment
that adding to it *"is a decision, not a build change"*. This adds five:

```
assets/blueworx-admin-design.css
assets/fonts
assets/blueworx-admin-icons.js
assets/blueworx-page-editor.js
blueworx-page-editor
```

The boundary ARCH-1 draws is that a client's site cannot physically contain
command-centre code. None of these is command-centre code. They are the same
argument `tokens` was admitted on — appearance, and now the shared machinery for
editing settings, neither of which knows anything about clients, capacity or
anybody else's data.

That said, five at once more than doubles a list whose whole value is being
short. This gets recorded as a new architecture decision (ARCH-8) covering what
may cross the boundary and why, so the next person to want a sixth argues with a
written rule rather than a comment.

## The connection screen

It moves onto the shared page editor library, in the library's option-backed
mode. Its three values — studio URL, site ID, connection key — are already
options, so `store: 'option'` fits without reshaping anything.

The foundation's rule is that a screen where a site owner edits settings is built
by the library and never by hand. Leaving this one alone would mean the client
site is the single place in the estate still hand-building the one screen the
system explicitly covers.

This is the only screen whose save path changes, so it is the only one that needs
new test coverage rather than existing coverage held steady.

## The stylesheet mostly goes

`Admin\Styles` exists because the client allowlist could not accept a `.css`
file, so the client's styling rides along inline on the token stylesheet. That
reason disappears the moment the client ships a real stylesheet. Most of its 200
lines are re-implementations of things the design system already has — cards,
columns, empty states, the nav strip.

What is genuinely Forge-shaped and has no counterpart in the system stays inline,
for the same reason it is inline now. Anything that *should* have a counterpart
goes into the foundation's system first and comes back as a component, rather
than being reinvented here. That is the foundation's own instruction when the
system has no pattern for what you need.

## The nav

`Admin\Nav` renders a horizontal strip of links with `aria-current`, not a second
navigation column, so it does not run into the page editor's *no second
navigation column* rule. It becomes the design system's tabs, and keeps its
`data-testid` hooks.

## How we know it is done

**The pair suite is the regression net.** A dozen `tests/pair/client-*.spec.js`
files already cover these screens, and they anchor on `data-testid` attributes
rather than on markup or class names. Every screen keeps its test ids, so the
suite passes before and after, unchanged. That is what makes this a rebuild
rather than a rewrite with a hope attached.

Where a spec reaches for a hand-rolled class instead of a test id, it moves onto
a test id **first, as its own commit, before the screen it covers is touched** —
so the net is proven against the old screen before it has to catch the new one.

**New coverage** goes on the connection screen, whose save path genuinely
changes.

**Both guardrails start biting.** Design system sync compares a real vendored copy
from this point on. Admin UI adherence judges every changed screen — and because
all ten convert, there is nothing left for it to wave through later. Both stay at
`error`; neither gets a `warn` escape hatch, since a plugin left on `warn` is the
failure this whole change exists to fix.

**Accessibility** is covered by the existing `tests/pair/accessibility.spec.js`,
and the design system's components carry their own focus and contrast behaviour,
so this should improve rather than regress. PHPCS and PHPUnit run as they do now.

## Risks

**The client's backend changes appearance on the next update.** Deliberate, and
the point. Worth a changelog entry written for the site owner, not for us.

**Ten screens is a lot of surface at once.** Mitigated by the test ids: the suite
is what says a screen still works, and it does not care what the markup looks
like. A screen whose spec turns out to be thin gets its coverage strengthened
before conversion, not after.

**Five new shareable paths.** The one that would actually be dangerous is a
directory that later grows studio code inside it. `blueworx-page-editor` is a
vendored copy of a foundation library, kept byte-identical by the sync check, so
it cannot grow anything without that check failing.

**The foundation moves.** Its design system is on `v1`, which moves. A foundation
release that changes `styles.css` will fail Forge's sync check until the vendored
copy is refreshed — which is the check working, not breaking. Refreshing it is a
copy and a version bump.

**The calendar may need foundation work first.** If a `Calendar` component is the
answer, that is a foundation pull request, a foundation release and a `v1` move
before this repo can use it. Sequenced last for that reason.

## Out of scope

- The studio plugin's React application, and anything ARCH-7 places there.
- Adopting the page editor for anything other than the connection screen.
- Any change to what the client screens *do* — this is how they are built, not
  what they show.
