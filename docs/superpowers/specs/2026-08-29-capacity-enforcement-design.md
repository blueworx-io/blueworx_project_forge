# Capacity enforcement (#141, #142, #143, #144)

**Status.** Approved 2026-08-29. Implements M7 issues 6 to 9, and closes the
milestone.

## The goal

The engine landed with #137 to #140: who is committed, for how long, counted
once across every client. It answers questions. It refuses nothing.

This is the half that refuses. Nothing reaches Up Next without a real plan
behind it (#141). A plan made weeks ago is rechecked before work starts (#142).
Over-committing somebody is deliberate and recorded rather than accidental
(#143). And a change to hours, leave or dates leaves a trail against the work
it affects (#144).

## What already exists

`Capacity\Allocations` reads a work item's three seats, its two substitute
seats, its hours and its dates as dated commitments. `Capacity\Commitments`
gathers them across every client. `Capacity\Position` turns available hours and
committed hours into a figure and a band, `Position::OVER` among them.

`Work\Gates` holds every exit gate as structured requirements rather than
prose, evaluates them all at once rather than stopping at the first failure,
and already carries **`G-UP-NEXT-8`, a capacity check marked `deferred`** —
a system check that reports its result and refuses nothing, left there by #105
against exactly this work:

> The capacity model arrives with CAP-4; until it does this reports as passed.

CAP-4 has arrived. The placeholder is the seam this work is built into.

`Work\Override` and `Transition::override()` are the WF-5 override: any stage to
any stage, by a studio administrator, permanently marked on the item. This work
does **not** reuse it, for reasons set out below.

## Why this is architectural rather than four small changes

**A system check that refuses is a different thing from one that reports.**
Every deferred check in `Work\Gates` is currently unreachable as a refusal. This
is the first one to become real, and how it names its failure sets the shape
every later one follows — the support-hours check in M8 is the next, and the
client-communication check after that. Shaped wrongly here, it is reshaped three
times.

**A second kind of override.** Forge has had exactly one way round a rule, and
its whole design rests on being rare and expensive. Adding a second one changes
what the first one means. That has to be reasoned once, in the open, rather than
discovered later when the override report turns out to be mostly noise.

**Where the question is answered.** The gate, the recheck and the Capacity
screen must give the same answer about the same person in the same week, for
ever. That is a single-owner decision, and the owner has to exist before any of
the three can call it.

## Decisions

### CAP-E1 — one owner for "would this over-book anybody?"

**Decision.** A new `Capacity\Impact` is the only place that answers whether a
move would over-book anybody. It takes a work item, adds that item's own
allocations on top of what each of its three people already carries, and reports
per person, per week, whether they go over.

The prospective addition is the reason it cannot simply read `Position`.
`Allocations::COMMITTING` begins at `up-next`, so an item **entering** Up Next
is not yet counted anywhere. Asking `Position` directly would report the world
without this job in it and permit every move.

**Consequence if reversed.** The gate and the Capacity screen can disagree about
the same person in the same week, and there is no single place to correct.

### CAP-E2 — week by week, not across the job

**Decision.** Any single week in which a person goes over counts as
over-booked. The window is the item's planned start to planned due, cut into
the same weeks the Capacity screen uses.

Across-the-job totals hide the case the check exists to catch: a two-month job
that is comfortable on average and still leaves somebody with nothing free next
week. Day by day is the opposite failure — one busy Tuesday refusing a sensible
three-month job, and an override given so often it stops meaning anything.

**Consequence if reversed.** Refusal counts change in both directions, and
overrides already given were given against a different question.

### CAP-E3 — the capacity override is its own, lighter mark

**Decision.** Over-allocation is permitted by any studio administrator, with a
reason, recorded as its own mark on the work item and its own history entry. It
is **not** the WF-5 override and does not set `override_used`.

CAP-4 says "as with WF-5" about *who* may do it — any studio administrator, so a
real week never waits on one person — not about which mechanism it uses. The
WF-5 mark means something specific and permanent: this item's history has a hole
in it, somebody moved it by hand. Over-booking somebody is not that. It is a
normal call a manager makes on better information than the model has, which is
precisely why CAP-4 chose it over a hard block. Stamping it with the WF-5 mark
would make the override report — which exists to surface genuine workflow
corrections — mostly routine capacity decisions, and therefore useless.

**Consequence if reversed.** Every capacity override already given becomes a
workflow override in the report, and the two can no longer be told apart.

### CAP-E4 — the permission covers one crossing, not the item

**Decision.** A reason given at Up Next does not satisfy the recheck at In
Development. Each crossing asks again.

The recheck in #142 exists because the picture changes between planning and
starting. A permission granted against the old picture is a permission granted
against a question nobody asked.

The mark itself is not cleared — the item keeps saying it was over-booked. The
column carries the most recent reason, and every reason ever given is kept as
its own history entry, so the item reads as current while the trail stays
complete. What does not carry forward is the satisfaction of the gate.

**Consequence if reversed.** An item over-booked once passes every later
capacity check for free, and the recheck at In Development stops being a check.

### CAP-E5 — nothing is recalculated, because nothing is stored

**Decision.** #144 adds no recalculation job, no cache and no stored capacity
figure. Every figure in the engine is read from the work items and availability
records at the moment it is asked for, so it cannot be stale.

What #144 adds is the missing half: a change to somebody's hours, their leave,
or a work item's dates or hours writes a history entry against the work it
affects, so the trail exists afterwards.

The client availability answer in #140 is derived the same way and therefore
already reflects any such change the moment it is next asked. That is the
issue's "a change on the studio updates the client availability result", and it
needs a test rather than an implementation.

**Consequence if reversed.** A stored figure needs invalidating from every
write path that could affect it, which is every one of them.

### CAP-E6 — nothing chases anybody

**Decision.** Work that becomes over-booked after the fact turns red on the
screens and leaves a history entry. Nothing notifies, flags a new state, or
refuses the change that caused it.

Refusing the change is the wrong way round: somebody's hours genuinely did
change, and a system that will not record a fact about the real world is worse
than one showing an uncomfortable figure. Chasing is M10's notification work,
and a half version built here is built twice.

**Consequence if reversed.** M10 inherits a second notification path to
reconcile with its own.

## What gets built

### `Capacity\Impact`

The owner from CAP-E1. Given a work item, it answers:

- Which of the three seats hold somebody, and who — substitutes included, since
  `Allocations` already resolves them.
- For each of those people, their existing committed hours per week over the
  item's planned window, **excluding this item** where it is already counted.
- The hours this item would add per week, spread across the days that person
  actually works, exactly as `Allocations` already spreads them.
- Per person, per week: available, committed with this item included, and
  whether that crosses into `Position::OVER`.

It returns everybody who goes over and the weeks in which they do. Not the
first — the same reason `Gates::evaluate` returns every unmet requirement.

A person whose hours nobody has set up is **not** over-booked. `Position`
already separates "no room" from "nobody has said", and the gate must not refuse
a move because of an unfilled admin screen.

### #141 — the gate at Up Next

`G-UP-NEXT-8` stops being deferred. Its check calls `Impact` and refuses when
anybody goes over and no capacity override covers the crossing.

It refuses alongside every other unmet requirement, as the gate already does,
so somebody missing planned dates *and* over-booking a reviewer is told both at
once. `G-UP-NEXT-9`, support hours, stays deferred until M8.

The refusal carries who is over and in which weeks, in the existing `unmet`
shape so the screens render it with what they already have, plus the detail the
override control needs.

### #143 — the override

Two new columns on `bwx_forge_work_items` for the capacity override's mark and
reason, alongside — never instead of — the WF-5 pair. One new history action.

The reason is mandatory and the crossing is refused without it, the same way
`Transition::override()` already refuses an empty reason.

Who may give it is asked of `Tenancy\Capabilities`, not decided here, and a
client role is refused whatever else they hold — the client transition lock is a
security boundary and is not opened by any override.

### #142 — the recheck at In Development

A capacity check on `G-IN-DEVELOPMENT`, calling the same `Impact`. Per CAP-E4 it
needs its own reason rather than accepting the one given at Up Next.

### #144 — the trail

History entries against affected work when availability patterns, unavailability
records, or an item's dates or hours change. Plus the test that proves a studio
change moves the client's availability answer.

## Errors

A capacity refusal is a `WP_Error` in the shape the gate already refuses in, so
no consumer learns a second format. It names, per person: who, which weeks, how
much over. The existing screens list unmet requirements already and will render
it without change; the override control is the only new thing on screen.

An override attempted by somebody without the studio administrator role is
refused as a permission failure, not a capacity one — the two are different and
the message should not conflate them.

## Testing

**PHP unit** — `Impact` carries the load: a job that fits, one that does not,
one that fits overall but not in one week (CAP-E2), a person with no pattern
recorded (not over-booked), a substitute in a seat, a job whose window crosses a
year boundary, and an item already counted not being double-counted.

**PHP unit** — the gate: refuses with everything else unmet, permits with an
override, and the recheck refusing despite the earlier override (CAP-E4).

**Playwright** — the refusal on screen naming who and when, giving a reason and
the move going through, and the mark showing on the item afterwards.

**Playwright, pair** — a studio change moving the client's availability answer
(CAP-E5), with the client still learning nothing about who.

## Order

`Impact` first: the other four have nothing to call without it. Then #141, which
proves it against a real gate. Then #143, which the refusal needs before it is
usable. Then #142, which is the same check in a second place. Then #144.

## Out of scope

The support-hours check `G-UP-NEXT-9` (M8, COMM-3). Notifications about work
that became over-booked (M10). Any stored or cached capacity figure (CAP-E5).
Post-review hours adjustment (CAP-3's second half, which belongs with the
ledger in M8).
