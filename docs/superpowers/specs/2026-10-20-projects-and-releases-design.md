# Projects and releases

Date: 2026-10-20. Follows issue #392 (how the old plugin linked features,
milestones and projects).

## Why

The old Forge Project Management plugin grouped work into releases: named blocks
of a few weeks with features, bugs and feedback attached, shown on a timeline and
board grouped by release. On Big Wedge it is the site's home page, open without a
login, so the client reads their roadmap that way. Blueworx Forge has nothing
equivalent: "Released" is only the last stage of a single item, and although
items already carry a level (project, milestone, feature, sub-feature) and a
parent, nothing on screen sets a parent or groups work under one.

Luke wants to plan each client's work as projects made of releases, see every
project in one place with its progress, review a release as a whole before it
goes out, and let the client see the same picture.

## The shape

```
Client
  Project        runs indefinitely, or between two dates
    Release      (today's "milestone", renamed)
      Feature
        Sub-feature
Bugs, tasks and feedback can sit under any of these, or stand alone.
```

- Projects and releases are work items, using the existing `level` and
  `parent_id` (WORK-1). "Milestone" is renamed "Release" everywhere a person
  reads it. Whether the stored value `milestone` is renamed too is the
  builder's call; if it is, existing rows must be migrated.
- A project has one or more releases. A release has one or more features. A
  feature is either whole or made of sub-features.
- Bugs, tasks and feedback can be attached to a project, release, feature or
  sub-feature, or created on their own as today ("tasks can still be created as
  normal").
- Bugs, tasks and feedback never have children of their own, so they may sit
  under any level, sub-feature included, without risking an item becoming its
  own ancestor. The tree reads the type for bugs, tasks and feedback and the
  level for everything else (our call).
- Everything belongs to one client site, as all work does today. A client with
  two sites has separate projects for each (our call; Luke did not object).

## How they move

- Projects and releases go through the same stages and checks as every other
  item (Luke, 2026-10-20). They carry the same "who and hours" panel: doing the
  work, designer, reviewing it, delivering it. Nothing about that panel changes.
- A project or release's own hours are the work on it as a whole (planning it,
  the release review, delivering it), never a total of what sits inside it.
  They count once, in capacity and against the client's package, like any
  other item's hours (Luke).
- Projects skip the date check for leaving Up Next. A project's dates are
  worked out from its releases, so one with no dated releases reads "Ongoing"
  (Luke).
- A project that runs indefinitely simply never reaches Released.
- Completion runs upward, as it already does for parents (G-COMPLETED-8): a
  project can't be completed until every release in it is, a release until its
  features and anything attached to it are, a feature until its sub-features
  are. Work that ended without being released (cancelled, rejected, duplicate,
  deferred, archived) doesn't hold a parent open, as today.
- Features can be finished and released on their own, before the release that
  holds them is done (Luke).

## Release review

- A release goes into review once every feature in it has reached Completed, so
  the release review comes after the per-item reviews, not alongside them (our
  call).
- The person named under "Reviewing it" on the release reviews the release as a
  whole, on top of the per-item reviews that already exist.
- While the release is in review, the reviewer adds bugs and feedback straight
  into it. They are ordinary items under the release, with their own who and
  hours.
- The release's review can't be approved until every bug and feedback item
  raised in it has reached Completed (our call: "done" means Completed, matching
  the existing parent rule). Then the person delivering it releases it.
- Anything raised in review counts toward the release's progress (our call).
- The client doesn't take part in the release review (our call); they can still
  give feedback on single items as today.
- The builder decides how the review is held in the data, as long as the screen
  can tell "raised in this release's review" apart from other bugs and feedback
  under the release.

## Release details

A release has a name, dates, and the release week or weeks, shown as week
numbers worked out from its dates (as the old plugin did: "Weeks 27 -> 30").
No version number (Luke).

## Screens

### Projects page (new, studio)

A new "Projects" page in the studio menu: a table of every project, one row
each, showing the project, its client, its dates ("Ongoing" when it has no end),
how many releases it has, and its progress. The global client filter narrows it.
Clicking a row opens the project. An Active / Completed switch keeps finished
projects out of the way (our call). The mockup Luke approved is in
`.superpowers/brainstorm/` (projects-page.html).

### Project page (new, studio)

The project's name, client, dates and progress, and its releases as rows
showing dates, status and progress. Opening a release shows its features,
sub-features, attached bugs, tasks and feedback, and the review box: what was
raised in review and how many are left before it can be released.

### Creating projects and releases

Projects and releases are created from inside a client (Luke). Features are
added inside a release, and bugs, tasks and feedback inside whatever they belong
to. An item that already exists can be moved under a parent from its panel.

This partly reverses the 19 September change ("turning a request into work no
longer asks what it sits under"). That change stays as it is for request
conversion; the parent choice comes back only when adding from a parent and on
an item's own panel (our call).

### Client workspace

The client sees their projects and releases in their own workspace (Luke): the
same tree, read only, with progress and dates. What exactly they see of review
items is the builder's call, within what `ClientView` already publishes.

### Sharing a link to any item

Every item (task, bug, feature, release, project) gets a "Copy link" button
that puts its address on the clipboard; opening that address takes a signed-in
person straight to the item. The address already works: the app opens
`#item=<id>` on landing (`src/App.tsx`), and Slack messages use it. Only the
button is new. It is small and independent, so it ships first, on its own
(our call). Whether the client workspace gets the same button is the builder's
call; its items already have their own addresses.

### Progress

Progress counts finished work under a project or release, as the plugin does
today. Decision WORK-2 says progress should be by hours; the code counts items.
This design doesn't settle that, and the screens show whichever the plugin
uses.

## Goals

- Share a link to any item that opens it.
- Group a client's work into projects and releases, and see it that way.
- One page listing every project with dates and progress.
- Review a release as a whole, with its own list of fixes to finish.
- The client sees the same picture.

## Non-goals

- Moving the old plugin's releases and items across. Items still move by hand,
  as `docs/moving-from-forge-project-management.md` says.
- Version numbers on releases.
- A public roadmap open without a login, like Big Wedge's old home page. Not for
  now (Luke); the client workspace stays signed-in only.
- Hours planned against capacity per release (the old plugin showed it but
  never added the hours up). A later change if wanted.
- Brands, the competitor comparison view and free/premium pricing from the old
  plugin.

## Anti-goals

- A second workflow for projects or releases. They use the one state machine.
- Storing links twice. The old plugin kept release membership on the item and
  on the release, and the two drifted apart. Membership lives in one place:
  the child's parent.
- Rebuilding any of this as a WordPress admin page (ARCH-7).

## Constraints

- Every change to how Forge behaves updates `src/rules.ts` in the same pull
  request as its changelog entry.
- Screens use the shared kit in `src/kit/` and the `frontend-design` skill.
- Version bump and changelog on every PR; Playwright tests for new behaviour.

## Left to the builder

- How the release review is stored, and which gate enforces "review items done
  before approval".
- Whether `milestone` is renamed in storage.
- How the work board, timeline and list show the new grouping, beyond what is
  described here.
- Splitting this into several pull requests, and their order.
