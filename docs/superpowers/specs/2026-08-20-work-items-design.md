# Work items, stages and transitions — design

Issues #96, #97, #104 and #106. Per WORK-1 to WORK-3, WF-1 to WF-6,
[`data-model.md`](../../architecture/data-model.md) and
[`workflow-state-machine.md`](../../architecture/workflow-state-machine.md).

## Goal

The spine the board stands on: one record for every piece of work, the twelve
stages it can be in, and the one service that moves it between them. Four issues
in one change because they are one thing — an item with no stages is a note, and
stages with no transition service are decoration.

## Scope

In scope: the work item entity and its fields, the level and type rules, the
stage registry, the transition service for single-step moves, and a minimal
append-only changelog so a move records what happened.

Out of scope, each with the issue that owns it: gate definitions and their
requirements (#105), gate failure responses (#107), returns (#108), Blocked
(#109), terminal outcomes (#111), reopen (#113), override (#114), the full
changelog (#99), roles deciding who may move what (#112, #91). The transition
service is built with the seams for all of them and enforces none of them yet.

## One entity, not five

WORK-1's four rungs are a `level` column and a `parent_id`, not four tables.
That is what lets a level be skipped — a Feature straight under a Project — and
what lets a Bug hang anywhere or nowhere. Five tables would make "skip a level"
a schema change.

Two rules hold the hierarchy together, and both are refused rather than
corrected:

- **A parent must be a higher level.** Rank them project 1, milestone 2,
  feature 3, sub-feature 4: a parent's rank must be strictly lower. Equal or
  lower is refused, which is what stops a cycle without walking the tree.
- **A child belongs to its parent's site.** ARCH-3 scopes work to the site, so
  an item whose parent sits under another site would be reachable from two
  tenants at once. Refused at the door.

Bug and Feedback attach at any level or stand alone (WORK-1), so neither rule
mentions work type.

## Storage

Schema version 4. One table, `{$wpdb->prefix}bwx_forge_work_items`, carrying the
data model's Work Item fields. Notable columns and why they exist:

| Column | Notes |
|---|---|
| `id` | `wrk_` + 16 hex, as every other record. |
| `site_id` | The scoping unit. Named `site_id`… **no** — see below. |
| `level`, `work_type` | The two enums above. |
| `parent_id` | Nullable, stored as `''` rather than NULL so every read is a string. |
| `stage` | One of the twelve. Written **only** by the transition service. |
| `prior_stage`, `blocked_elapsed` | For Blocked (#109); stored now so the stage column never needs widening later. |
| `terminal_outcome`, `duplicate_of` | For #111. |
| `cycle` | Increments on reopen (#113). Starts at 1. |
| `self_reviewed`, `override_used`, `override_reason` | Permanent marks (AUTH-3, WF-5). |
| `commercial_class`, `delivered_by_forge` | Set at Triage and Bug Tracking (COMM-5). |
| Definition fields | Title, problem, scope, non-goals, requirements, acceptance criteria, references. |
| Planning fields | `planned_start`, `planned_due`, `review_target`, `release_target`, `remaining_estimate`. |
| `release_method` | Per WF-6, required at Completed. |

**The site column is `client_site_id`, not `site_id`.** WordPress core maps the
name `site_id` to an integer format for every `$wpdb` write, so a `site_id`
column holding `cst_…` silently stores `0`. `Data\Formats` now defends against
this everywhere, but the name is avoided as well: two defences, because this one
fails silently.

A second table, `bwx_forge_work_changelog`, append-only: item, actor, action,
from and to stage, reason, time. #99 expands it; it exists now because #106's
"records what happened, atomically" is not true without it.

## Stages

Twelve, fixed, in `Work\Stages` as a class constant. There is no create, rename,
reorder or delete — not gated, absent. #104 asks for that to be proved rather
than promised, so the test asserts no method mutates the set and no REST route
touches it.

Each stage carries its kind: linear, conditional (Bug Tracking) or exception
(Blocked). The kinds are what later issues switch on, and they belong beside the
list rather than in the code that reads it.

## Transitions

`Work\Transitions` owns the forward path from the state machine's own table, and
answers one question: may this item move from here to there, now.

- **Single step only.** The table is a map of from → allowed to. A jump from
  Triage to In Development is not a fast route, it is a bug, and it is refused
  by there being no entry for it.
- **Bug Tracking is conditional on work type.** Triage goes to Bug Tracking for
  a bug and to Documentation Period for anything else. Both are in the table;
  the work type decides which is offered.
- **The move is one write.** Stage change and changelog entry go in a
  transaction, so a failure leaves the item exactly as it was. #106's acceptance
  is precisely this: a partial failure must change nothing.
- **Creation is a transition too.** A new item enters at Future Idea; nothing
  else may be its first stage.

Gate requirements are not evaluated yet. The service calls one
`gate_for( $from, $to )` that returns the gate's name, and #105 fills in what
that gate demands. The seam is here so #105 is an addition rather than a rewrite.

## REST

`Permissions::manage()` for all of it; #91 swaps each for a capability.

- `GET /work-items` — filtered by site, stage, level, parent.
- `POST /work-items` — creates at Future Idea.
- `GET /work-items/{id}`, `PATCH /work-items/{id}` — fields, never the stage.
- `POST /work-items/{id}/transition` — the only way the stage moves.

A PATCH that names `stage` is refused rather than ignored. Silently dropping it
would let a caller believe they had moved an item.

## Tests

- Unit: the level rule including the equal-level refusal; the stage set cannot
  be mutated; every forward transition in the state machine's table is allowed
  and everything else is refused; the field rules per stage.
- REST: creation enters at Future Idea; a multi-step jump is refused; a PATCH
  naming a stage is refused; an item cannot take a parent from another site; a
  failed transition leaves the item and the changelog untouched.
