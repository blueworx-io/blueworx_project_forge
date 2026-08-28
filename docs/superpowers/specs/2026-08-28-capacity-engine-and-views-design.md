# Capacity engine and views (#137, #138, #139, #140)

**Status.** Approved 2026-08-28. Implements M7 issues 2 to 5.

## The goal

Four issues, one story: record what each person is committed to, count it once
across every client, show the studio the picture, and let a client ask whether
there is room without learning anything about anybody else.

The enforcement half of M7 — the gate at Up Next (#141), the recheck at In
Development (#142), the over-allocation override (#143) and recalculation with
audit (#144) — is deliberately not here. All four ask the same question of the
same engine, and the engine has to exist before there is anything to ask.

## What already exists

`Capacity\Availability` (#136) answers "how much time does this person have",
day by day, with a reason for every zero — no pattern recorded, a non-working
day, or dated leave. It is the only place that question is answered, and this
work keeps it that way.

`bwx_forge_work_items` already carries the three role seats, the two substitute
seats, `planned_start`, `planned_due` and `hours_primary` / `hours_review` /
`hours_delivery`. No new table is needed to say who is committed for how long:
the facts are already in the row. What is missing is anything that reads them
as commitments.

## Why this is architectural rather than four screens

Three things fix decisions that later work inherits, and each is expensive to
reverse once something depends on it.

**The shape of an allocation.** Every gate in #141 to #144 refuses or permits
against it, and the client answer in #140 is derived from it. If it is defined
loosely — as a total per item, say, rather than a dated series per person —
every consumer re-derives the dates and they disagree.

**A deliberate crossing of the tenant boundary.** Every read in Forge so far is
scoped to a client or a site. Capacity is the first read whose whole purpose is
to span them: a person committed on one client must not look free on another.
That crossing has to be declared and reasoned at the routing layer rather than
happening quietly inside a callback.

**What a client may learn about capacity.** #128 already recorded that a client
does not see staff names against capacity. #140 is the first thing that answers
a capacity question to a client at all, so it fixes how much can be inferred.
An answer shaped wrongly cannot be un-shown.

## Decisions

**Hours spread across the days the person actually works.** An allocation with
a start and a due date is shared evenly across that person's available working
days in the window, skipping their days off and their leave, rather than landing
whole in the start week or the due week. A four-week job then reads as a steady
load across four weeks, which is what it is. The alternative makes someone look
walled up this week and idle next, and every figure derived from it inherits
that.

*Consequence if reversed:* every historical capacity figure changes meaning,
and the Up Next gate starts refusing a different set of items.

**A day with no availability carries no commitment.** The spread asks
`Availability` which days count, so a person on leave carries none of the work
that week and the rest of the window absorbs it. Where a person has no working
days at all in the window — all leave, or no pattern recorded — the hours land
on the first day of the window rather than vanishing. Silently dropping
committed hours is the one outcome worse than showing them awkwardly, because
the total then reconciles to nothing.

**Commitment starts at Up Next.** Only items at Up Next or beyond, not archived
and not terminal, count against a person. This follows the existing reservation
rule (hours are reserved at Up Next, used at In Development). Counting earlier
stages would have everybody permanently over-committed on ideas that will never
be built, and a view nobody believes is a view nobody uses.

*Consequence if reversed:* the Up Next gate in #141 changes from "is there room
for this" to "was there ever room", and previously admitted items become
invalid.

**A substitute holds the commitment.** Where `reviewer_substitute_id` or
`deliverer_substitute_id` is set, the hours land on the substitute, not on the
person they are covering. Capacity answers who is doing the work; who the seat
belongs to is AUTH-4's record and a different question. A person covering three
reviews on top of their own workload is exactly the case this view exists to
make visible.

**A person on two seats of one item is committed twice.** Not double counting —
two genuine commitments that happen to share an item. The double counting #138
guards against is the same person read once per client and summed twice, which
the global user identity already prevents.

**Role hours are seeded, never forced.** Entering the primary estimate seeds
reviewer at 20% and deliverer at 10% per CAP-2, applied only when those figures
are still zero, and editable afterwards. Seeding a figure somebody has already
set would silently overwrite a deliberate number.

**The client gets a band and a date, not a number.** Room, tight, or none, plus
the earliest date there is room. A remaining-hours figure that moves week to
week tells a client about other clients' commitments by inference; a bare yes/no
gives them nothing to plan around and produces an email asking when.

*Consequence if reversed:* a client has seen an hours figure, and what they
inferred from it cannot be withdrawn.

## Shape

### `Capacity\Allocations` — what is committed

Two halves, the same way `Availability` is split: a pure rule that can be stated
in a test, and a reader that fetches what the rule needs.

`Allocations::from_item( array $item ): array` turns one work item row into zero
to three allocations. Each carries the person, the role, the hours, the window,
and the item it came from. A seat with no person, or with no hours, produces
nothing — an unassigned seat is not a commitment against nobody.

`Allocations::spread( array $allocation, array $days ): array` shares one
allocation's hours across days, given that person's availability for the window.
No database, so the spreading rule is testable directly.

`Commitments::live( string $from, string $to ): array`
reads every qualifying item across every client in one query and returns the
allocations, keyed by person. One query rather than one per person, because the
capacity view asks for everybody at once.

### `Capacity\Commitments` — the cross-client total

`Commitments::for_people( array $user_ids, string $from, string $to ): array` and
`Commitments::hours(...)`, mirroring `Availability`'s two methods so the two
sides of every figure are read the same way.

The read spans clients by design. It is reachable only from the studio, and the
route that exposes it declares `SCOPE_OPEN` with that reason written into the
declaration, the way `ClientController`'s routes already do.

### `Capacity\Position` — available, committed, remaining

One place where the two sides meet, so "they had no time" and "their time was
spoken for" are never confused, and so the view, the gates and the client answer
cannot disagree.

`Position::for_people( array $user_ids, string $from, string $to ): array`
returns available, committed, remaining, a status band, and whether the person's
hours have been recorded at all. Bands: `clear` under 80% committed, `tight` at
80% to 100%, `over` above 100%, and `unrecorded` where no pattern exists — which
is not a capacity state but a setup one, and reads as "go and set them up"
rather than "they have no room".

### `Capacity\ClientAnswer` — what a client is told

`ClientAnswer::for_window( string $from, string $to ): array`
returns `{ availability: 'room' | 'tight' | 'none', earliest: 'YYYY-MM-DD' or empty }`
and nothing else. Written as an explicit construction rather than a filtered
position, so a field added to `Position` cannot appear here by accident — the
same direction `Work\ClientView` is written in, and for the same reason.

The band is the studio's aggregate position across the people who could do the
work, not any one person's. `earliest` is the first date the aggregate reaches
`room`, searched to the end of the requested window and returned empty when
there is none in it.

### Routes

`GET /capacity` — studio. Takes `from`, `to` and optionally a list of people;
returns a position per person per week. Requires the capability that already
governs seeing staff against capacity; `SCOPE_OPEN` with the cross-client
reason written down.

`GET /capacity/person/{id}` — studio. The drill-down: that person's days, their
availability reasons, and the allocations behind each committed figure.

`GET /client/availability` — a fifth route on `ClientController`, same
`Permissions::client_site` callback and same reasoning as its siblings. The
client it answers for is the one that signed the request, resolved the way
`contact_for()` already resolves it, so there is no client parameter to tamper
with.

### The capacity screen

A third screen in the app beside Work and Requests. This is work-doing rather
than system-configuring, so it belongs in React under ARCH-7 — unlike the
availability screen (#136), which sets people up and is a WordPress admin page.

People down the side, weeks across the top, available / committed / remaining
in each cell with the band as its colour. A cell opens the work behind it.
Where a person has no recorded pattern the row says so instead of showing zero,
because zero hours and nothing recorded are different facts.

## Testing

The rules — `from_item`, `spread`, the band thresholds, the client projection —
are pure functions and get PHPUnit tests that state the rule directly, with no
WordPress behind them, the way `CapacityAvailabilityTest` already does.

The reader gets a test that a person on two clients shows one combined
commitment, which is #138's acceptance in one assertion.

Playwright covers the capacity screen reconciling to its drill-down, and the
client route in the pair suite — including a test that the client response
contains no other client's identity, no work title, and no commercial figure,
which is #140's acceptance.

## Out of scope

Enforcement of any kind. Nothing here refuses a transition, records an override
or writes an audit entry; #141 to #144 do that against this engine. Actual time
tracking stays excluded under CAP-3.
