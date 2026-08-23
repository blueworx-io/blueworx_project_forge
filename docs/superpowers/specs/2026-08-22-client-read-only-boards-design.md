# Client read-only boards (#128)

**Status.** Approved 2026-08-22. Implements M6 issue 3.

## The goal

The client sees the same work the studio sees, and has no authority over any of
it. The issue's acceptance is deliberately stronger than "the buttons are
hidden": *the client build contains no transition control to hide*.

## Why this is architectural rather than a screen

This fixes the client-visible projection of a work item — the shape #129, #130
and #133 all inherit. Get the field list wrong here and every later client
screen inherits the leak.

It also settles a duplication question. The studio's three views are ~370 lines
of layout logic and ~600 lines of React, and none of it can enter the client
artifact: `bin/artifacts.json` admits only paths under `client/`, and the
checker refuses anything else. So this is not a port. Everything the client
renders is written again, in PHP, under `client/`.

## Decisions

**Simpler read-only views, not a facsimile.** Stage columns, a static bar chart
on a date axis, a month grid. No week/day modes, no dependency-chain
highlighting, no zoom, no drag. The client never interacts with these, so the
interaction logic is never written a second time. This is the difference
between ~3-4 hours and ~6-8, and between one copy of the layout maths and two
that drift.

**All twelve stages, including Future Idea, Triage and Blocked.** The
permission matrix grants a client admin "view work on any site" for their own
site with no stage filter, so this is what is already written down. Showing
Blocked is the honest option: the alternative is work that silently stops.

**Cards name who is working on it.** Primary, Reviewer and Deliverer, as
display names only, through `Contacts::for_client()` — the same client-safe
person shape the workspace contact already uses. This is distinct from "view
staff names against capacity", which is the capacity view and stays refused.

**Cards do not carry hours, priority or commercial class.** Each is defensible
and none is required by this issue. Planned hours invite an argument about
estimates before the M8 ledger exists to frame them; priority springs an
internal judgement on a client; commercial class asserts fault, and
`unclassified` leaks that nobody has decided yet. They can be added later
against a decision; they cannot be un-leaked.

## Shape

### Studio: `GET /client/board`

A third route on `ClientController`, beside the handshake and the workspace,
with the same `Permissions::client_site` callback and the same `SCOPE_OPEN`
reasoning: the caller is a machine, and the signature names which site it is.

The site it answers for is the one that signed the request. It resolves the
same way `contact_for()` already does — `Integrations::by_site_id()` to the
`client_site_id`, then `Items::for_site()`. There is no site or client
parameter to supply, so there is none to tamper with, and D-2 ("read a record
belonging to another client") has no surface here to attack.

A site with no integration record gets the same refusal `report()` gives, for
the same reason: a connection key issued outside a Client Site has no client to
answer for, and inventing one would have to guess.

### The projection

One function, `board_item()`, and it is an allowlist rather than an unset list.
A projection built by removing fields is one that leaks every column added
later; a projection built by naming fields leaks nothing by default. It emits:

    id, parent_id, title, stage, level, work_type,
    planned_start, planned_due, review_target, release_target,
    people: { primary, reviewer, deliverer }   // display_name only

Everything else on the row — internal notes, gate records, approver
identities, hours, commercial class, priority, substitutes, versions — is
absent because it was never named.

### Client: the read-through

`client/includes/Board.php` mirrors `Workspace.php` exactly: its own cache
entry, the same five states, the same fallback to what the site last saw with
the same honest label when it is old (ARCH-4). It is a second instance of that
pattern rather than a generalisation of it — the two records have different
lifetimes and would end up fighting over one cache key.

### Client: the screens

Three submenu pages under the existing Forge menu, added to `Nav::pages()`:

| Slug suffix | Label | What it draws |
|---|---|---|
| `-board` | Board | One column per stage, in `Stages::ALL` order, each card a title, its dates and its people |
| `-timeline` | Timeline | One row per item with a start and a due date, placed on a shared date axis, with a today marker |
| `-calendar` | Calendar | A month grid, each item's dates as entries on their day |

Each is its own registered page, so every nav href still carries a page and
nothing else — #126's rule holds, and no view parameter needs validating.

Items with no dates are not silently dropped. The Timeline and Calendar both
list them under "No dates yet", the same way the studio's Calendar keeps
undated work visible (#120).

## Testing

**PHPUnit** — the projection. That `board_item()` emits exactly the named keys;
that a row carrying internal fields still emits exactly those keys; that a
signed site reads only its own client's items; that a site with no integration
is refused.

**Playwright, pair suite** — the client build. That all three pages render
against a real client site; that undated work still appears; that a stale
studio still draws a board with the staleness said out loud.

**The absence test, which is the issue's actual acceptance.** A grep-style
assertion over the staged client artifact: no transition verb, no stage-change
control, no drag attribute anywhere in what ships. This asserts the artifact,
not the rendered page, because a control that is merely never rendered is one a
future change can start rendering.
