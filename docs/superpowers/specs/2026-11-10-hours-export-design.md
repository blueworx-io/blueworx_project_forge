# Hours export — design

**Date:** 2026-11-10
**Asked for by:** Luke
**Status:** draft, awaiting Luke's approval

## Intent

Luke needs to show a client where their support hours went. Today the Support
screen shows a site's ledger on screen, folded per task or meeting (#568), but
there is nothing to hand to the client. This adds a CSV download of that same
ledger, cut two ways:

- **by date range**, so a statement can cover a month, a quarter or a package
  term; and
- **by project or milestone**, so a client can be shown exactly how much time
  it took to build one thing.

The export is a statement, not a new figure: every number on it is a ledger
entry or a sum of ledger entries, so the sheet and the screen cannot disagree.

## Goals

- From a site's Support screen, download a CSV of its hours for a chosen
  period, optionally limited to one project or milestone.
- Lines read the way the screen reads: one per task or meeting, with package
  hours, top-ups, adjustments, expiries and carry-overs as lines of their own.
- **The sheet is about hours added and hours used, never holds.** A
  reservation is the studio holding hours for work not yet done; it is not
  something the client was charged, and a task reserved in one month and done
  in the next would otherwise show as nothing in either month. Reservations
  and releases are left off the sheet entirely.
- The sheet adds up: in "all work" scope it opens with the hours the client
  had at the start of the period and closes with what they had at the end,
  both counted **before holds**, and the lines between account for the
  difference exactly. One last line says how many hours are held right now for
  work in progress, so the closing figure reconciles with the balance the
  screen shows.
- In project or milestone scope it totals the hours the work under that item
  used in the period.

## Non-goals

- No change to what is recorded. The ledger stays append-only and the export
  is read-only.
- No export from the client portal in this round. Luke sends the file. (A
  later round can offer the same download on the client's Hours page, from the
  same route, once Luke has seen the sheet.)
- No PDF, no formatting beyond what a spreadsheet opens cleanly.
- No per-person breakdown. The ledger does not hold who did the work, and the
  client is buying the studio's hours, not a person's.

## Anti-goals

- A sheet whose lines do not sum to its own opening and closing figures. If a
  filter would make the numbers disagree, the filter is wrong.
- Showing the client raw ledger noise: a task reserved, released, reserved
  again and done must appear once, as it does on the screen.

## Where it lives

The studio app's Support screen, on the site's ledger table (ARCH-7: every
screen is the app, never a wp-admin page). An **Export CSV** button beside the
ledger opens a small form in a dialog:

- **From** and **To**, dates, inclusive. Default: the start of the site's
  current support period (or the first ledger entry where there is none) to
  today. When a project or milestone is picked, the default becomes the whole
  ledger, so "what did it cost to build" is one click.
- **Scope**: *All work* (default), or one project or milestone on this site.
  The picker lists the site's projects, each with its milestones indented
  under it, archived ones included and marked.
- **Download**.

Who can see it: whoever can open the site's Support screen today
(administrators and Managers, within their reach). Nothing new is granted.

## The file

Name: `<client>-<site>-hours-<from>-to-<to>.csv`, lower-case, spaces as
hyphens; with a scope, `-<project-or-milestone-title>` before `.csv`.

Columns, in this order:

| Column | What goes in it |
|---|---|
| Date | `YYYY-MM-DD`, the day the line's latest entry happened |
| What | The screen's words: Package hours, Top-up, Work done, Meeting held, Adjustment, Expired, Carried over, … |
| Task | The task or meeting title; blank for lines not about one |
| Milestone | The milestone the task sits under, if any |
| Project | The project the task sits under, if any |
| Note | The reason on an adjustment, override or top-up; otherwise blank |
| Hours | Signed, two decimals: hours added positive, hours used negative |
| Balance after | Running balance before holds, two decimals. **All work scope only**; blank in project scope, where the lines are a subset and a running balance would mislead |

Rows:

1. **Which entries count.** Everything except `work-reservation`,
   `work-release`, `meeting-reservation` and `meeting-release`: allocations,
   top-ups, carry-overs, expiries, adjustments, and work and meeting usage.
   An adjustment with a work-item source is a refund for hours lowered after
   the fact (#641) and folds under its task, as on the screen.
2. **All-work scope** opens with a `Balance brought forward` row dated the
   day before *From*: the sum of counting entries up to the end of that day.
   It closes with a `Balance carried forward` row dated *To*: the same sum up
   to the end of *To*. In between, every counting entry whose `occurred_at`
   falls on or between the two dates, folded per task or meeting as
   `ledgerLines()` folds them, oldest first, with a running balance. After the
   closing row, one `Held for work in progress` row: the hours reserved and
   not yet used or released as of the end of *To*, negative, with no running
   balance. Closing plus held is the balance the Support screen shows.
3. **Project or milestone scope** keeps only the work lines whose task is that
   item or sits anywhere under it, and ends with one `Total` row: the hours
   used, as a positive number. Lines not about that work (package, top-up,
   adjustment without a task, expiry, carry-over, meetings) are left out. A
   task that is only reserved, with nothing used yet, does not appear.

Days are UTC days, as the ledger's own `when` and the reports route already
are. The server works the opening, closing and held figures from the site's
entries each time; no new stored figure exists, and `Ledger::balance_at()`
stays as the screen's balance (after holds).

Encoding: UTF-8 with a byte-order mark, so Excel opens it with the right
characters; `\r\n` line endings; fields quoted when they hold a comma, quote
or newline.

## How it is built (Luke's calls and mine)

- **Server.** One new read route, `GET
  /client-sites/{site_id}/support/export?from=YYYY-MM-DD&to=YYYY-MM-DD[&under=wi_…]`,
  on `SupportController`, with the same permission and scope declaration as
  the site's support read. It answers JSON, not CSV: the app's normal
  authenticated fetch then works unchanged, and the file is written in the
  browser. It returns:
  - the site and client names;
  - `opening`, `closing` and `held` for the range (see "Rows");
  - the counting entries in range, holds already left out, each shaped as the
    support read shapes them (`when`, `source`, `about`), plus for every work
    source its task title, milestone id and title, and project id and title,
    looked up once per task (a year's ledger names the same task many times);
  - the site's projects and milestones for the picker (id, title, level,
    parent, archived), and the first ledger entry's date for the whole-ledger
    default.
  - `from`/`to` are validated as dates, `to` not before `from`, with no upper
    bound on the span; `under` must be a
    project or milestone on this site, or the route refuses.
  - Walking a task up to its milestone and project uses `parent_id` on
    `Items`, following the tree as it is now (a task moved under another
    milestone counts where it is today).
- **Browser.** A new pure module `src/hoursExport.mjs` beside
  `ledgerLines.mjs`: takes the route's answer and the chosen scope and returns
  the rows, then the CSV text and the file name. Pure so `node --test` covers
  it. The Support screen's dialog calls the route, builds the file, and
  downloads it through a Blob URL, kept to one small helper. The dialog and
  its fields use the kit in `src/kit/`.
- **MCP.** Nothing to add: the Labs plugin's `forge_request` reaches the new
  route for an administrator as it reaches every other Forge route.

## Tests

- `tests/unit/hours-export.test.mjs`: which entries count, folding in range,
  the two scopes, the opening, closing, held and total rows, escaping and
  encoding, the file name.
- PHPUnit for the pure PHP added (the balance-before-holds and held sums, the
  date validation, the ancestry walk).
- Playwright, in `tests/e2e/support-*.spec.js` style: a site with a package,
  a project with a milestone with a task that used hours, and a top-up; open
  the dialog, download all-work and milestone-scoped files, read them back
  and check the rows and sums. A test that assumes nothing already on the
  site (the pair sites are reused).

## Record-keeping

- `src/rules.ts` gains a "Support hours" section with an entry describing the
  export.
- Minor version bump and changelog entry in the same pull request.
- GitHub issue opened first; branch `export-hours-csv`.

## Left to the builder

- The dialog's layout and copy within the kit.
- The exact helper for walking a task to its project and milestone.
- Whether `ledgerLines()` needs a small extension to carry the milestone and
  project through a folded line, or the export module looks them up by task id
  after folding.
