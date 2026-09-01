# Operational reports — design

#176, the last of M10. The numbers that say whether delivery is working.

## Scope

Seven reports, the core delivery set:

1. **Where work is sitting** — open work per stage, right now.
2. **Time in stage** — how long work spends in each stage before moving on.
3. **Cycle time** — first move out of Future Idea to Released.
4. **Blocked time** — how long work spends stopped.
5. **Review turnaround** — In Review to a decision, whichever way it went.
6. **Planned against actual** — planned due date against the date it was released.
7. **Throughput** — work released per week across the window.

The remaining six the issue lists — capacity utilisation, overrides, package and
meeting hours, onboarding readiness, the request funnel and email delivery —
are a follow-on issue. Package and meeting hours could not be built here in any
case: M8 has not built the ledger they would read.

## Where the numbers come from

**The changelog, and nothing else.** Six of the seven are the work event log read
back: every move Forge makes already records its stages, its actor and its time,
appended in the same transaction as the move itself. The seventh, where work is
sitting now, is the items.

Nothing is stored and nothing is swept overnight. A report is not a figure kept
alongside the records — it is those records, counted, every time somebody asks.
That is what makes "each report reconciles to the records behind it" true by
construction rather than something a test has to keep checking, and it is the
same argument Standup\Rules makes about the day's list: a stored number and the
thing it counts drift apart silently, and the drift is only ever found by
somebody who acts on the wrong one.

It also means the reports cover history the product did not know it would be
asked about. The log has been written since #106.

## Shape

The same split as the day's list, for the same reason:

- `Reports\Source` knows about tables and nothing about meaning. It applies the
  caller's reach first and on its own, then fetches the items and the events for
  the window in one query each.
- `Reports\Delivery` is pure. Everything it needs is handed to it, so every
  report can be argued with in a test against a known event log rather than
  against a database.

Reach is applied before any arithmetic, never in the same pass — a mistake in a
calculation must not be able to widen what somebody can see.

One pass over one dataset, rather than seven reports each fetching their own.
Two queries to draw the screen, whatever the window. #183 measures it.

## The edges that matter

These are where the arithmetic is wrong if nobody thinks about it, and they are
what the unit tests are for:

- **Work still in progress.** Counted in "where work is sitting", excluded from
  cycle time — an unfinished item has no cycle. Reporting it as zero, or as time
  so far, both make the average lie.
- **A stage entered twice.** Work sent back re-enters a stage. Both visits count
  towards time in stage; the last exit is the one cycle time reads.
- **Work that moved backwards.** A return is a move like any other and its
  duration belongs to the stage it left.
- **A window that cuts a stay in half.** A stay that started before the window
  counts from where the window starts, not from the move. Otherwise a narrow
  window reports durations longer than the window itself.
- **Blocked time.** Blocked is a stage with a prior stage, so its duration is
  taken from the blocked and unblocked events rather than from stage moves, or
  it would be counted twice.
- **Nothing to report.** An empty window says so. A zero and an absence are
  different answers and only one of them means anything.

Medians rather than means, with the count alongside. One piece of work that sat
for a year moves a mean and tells nobody anything.

## Where it lives

A Reports screen in the React app. ARCH-7: screens people use to do the work are
the application, and these are numbers somebody reads to decide what to do next.

One screen, a date window, seven sections. `GET /reports?from=&to=`, a list
scope like the day's list — it names no record, and Source scopes it.

## Testing

- Unit tests for `Delivery` against a known event log, one per edge above.
- One Playwright spec that the screen shows what the API answers, and that a
  window with nothing in it says so rather than drawing zeroes.
- Tenant scoping is proved the way every other list is: through the existing
  boundary tests, since the route declares its scope like the rest.

## Not in this

- Export. Nobody has asked, and a CSV of a median is not a report.
- Stored or cached figures. The whole design is that there are none.
- Per-person reports. Capacity already answers that question and answering it
  twice is how two screens come to disagree.
