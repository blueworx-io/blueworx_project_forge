# Task panel rework — design

Blocks 2 and 3 of Luke's feedback of 2026-09-17, approved to proceed on
2026-09-18 with three rulings: the Scope and Requirements checks go with their
boxes; the "before" items that need words become stage boxes on the task; the
studio ships rich text first and the client plugin follows in its own PR.

The panel is `src/components/ItemPanel.tsx` (the side panel every board, list,
queue and standup opens an item in). Everything below is about that panel, the
New task form (`NewWork.tsx`) and the domain that feeds them.

## Shape of the work

Three studio PRs, in order, each its own branch and draft PR off `main`:

| PR | Version | What |
|---|---|---|
| A — Panel layout | 2.115.0 | Header, fixed footer, cards, alignment, copy, Who pays, client pick on New task, history shown properly |
| B — Description, Completed when, checklist | 2.116.0 | In-house rich text editor for two fields; the in-item checklist |
| C — Before-items and Block form | 2.117.0 | Every "before" item resolves from the task, a pick, or a stage box; Block form picks |

Then one client PR (the only change under `client/`): render the two rich-text
fields as formatting rather than tags.

A is layout only. B and C change the domain and get a spec-level test each.

## PR A — Panel layout

### Frame

- **Header** (stays put): title, stage chip, the short id (`wrk_…` trimmed, as
  today) plus due and blocked-for, and the close button. Nothing else.
- **Body** scrolls. Each block below is a card (`Card`, or the panel's own
  `.bwx-panel-card` on the same tokens) with an eyebrow title; blocks are
  separated by 12px, not by eyebrows floating in space.
- **Footer** (fixed at the bottom of the panel, always visible while the item
  is loaded): **Delete** on the left (danger, only for `canManage`, as now),
  **Save changes** on the right (primary). Save is the one general save for
  everything the body's boxes hold. Comments do not need it (they post on Add,
  as today — that is the "auto save").

### Blocks, in order

1. **Still needed / Blocked** — as today, in a card.
2. **Move to** — the stage buttons right-aligned on their row.
3. **Before &lt;stage&gt;** — one card per reachable stage. Each requirement is a
   row: label and how-to on the left, its control right-aligned. (PR C changes
   what the control is; PR A only aligns it.)
4. **Send back / Block / End it** — one card holding the three toggles, each
   with an arrow and a colour: Send back `←` in the return colour (amber),
   Block `⏸` in the exception colour (`--phase-exception`), End it `→` in the
   done colour (`--phase-done`). The form that opens for each sits inside the same
   card, and its action button sits in a small footer strip of the form,
   right-aligned. Spacing inside: 12px between fields, 16px above the footer.
5. **Task** — one card holding Title, **Item description** (was Problem it
   solves), **Completed when** (was Done when). Scope and Requirements are gone
   from the panel. (PR B turns the two long boxes into rich text and adds the
   checklist beneath them, in this same card.)
6. **Who and when** — as today, in a card, with:
   - **Who pays for it**: To be confirmed / Site bug — no charge to client /
     Client — charge to client / No charge — general item. Picking Site bug is
     the "we delivered the thing that broke" answer, so the checkbox goes.
   - The seats, hours picks (from 2.114.1), dates, remaining estimate, release
     fields — unchanged.
7. **Comments and evidence** — the list, then the add form, then a small
   footer strip holding **Add**, right-aligned. 12px between the form's rows.
8. **History** — collapsed by default (`<details>`, summary "History · N
   events"). Each line: what happened, when, and a **pill on the far right with
   who did it**.

### History content

Only real events are listed: created, moved, returned, blocked, unblocked,
ended, archived, reopened, placed, overridden, converted, over-allocated,
corrected. Field edits are shown as one line per save — "Edited title, due"
(the field labels, up to four, then "and N more") — not one line per field,
and never worded as a move. The API adds `actor_name` to each history entry
(display name of the WordPress user, "Forge" for 0) so the pill has a name.

### Domain changes in A

- `Fields::COMMERCIAL_CLASSES` gains `free-general`. Labels as above.
  `Validate` accepts it; the reports and COMM-5 treat it as not chargeable
  (grep every use of `free-bug`/`chargeable` and give the new value the
  not-chargeable branch). Choosing `free-bug` sets `delivered_by_forge` to 1
  on the same write (`Validate`, so the API cannot disagree with the screen).
- History entries carry `actor_name` (`WorkItemsController` show route).

### New task form

A **Client** pick at the top, always shown: every active site grouped by
client (from `src/sites.ts`), including the studio's own, defaulting to the
board's current site (or the studio when the board is on All clients). Type
list is Bug / Feedback / Task (Feature already gone).

### Tests (A)

`tests/e2e/item-panel-layout.spec.js`: the footer holds Delete and Save;
history is collapsed and opens; a save of two fields is one history line with
the actor's name; Who pays offers four options and Site bug marks
delivered-by-forge on the record (`GET /work-items/<id>` → `delivered_by_forge
true`, `commercial_class free-bug`); New task's client pick creates the item on
the chosen site.

## PR B — Rich text and the checklist

### Rich text

An in-house editor, `src/kit/RichText.tsx` (kit, because the client app will
use it later to read the same HTML): a `contentEditable` region with a toolbar
of Bold, Italic, Underline, Bulleted list, Numbered list, Link, Clear
formatting. It emits HTML; the allowed set is `p, br, strong, em, u, ul, ol,
li, a[href]`. Paste is plain-texted (keeps lines). Keyboard: the usual
Ctrl/Cmd+B/I/U. `aria-label` from the field; the toolbar buttons are labelled.

Used for **Item description** (`problem`) and **Completed when**
(`acceptance_criteria`). Stored in the same columns.

Server side: `Validate::text_fields()` passes those two through `wp_kses()`
with exactly that allowlist, and a helper `Fields::plain( $html )`
(`wp_strip_all_tags`, trimmed) is what every "is it filled in" check uses —
the `problem` and `acceptance_criteria` field gates, the client view, search.

Everywhere the two fields are read as text today (`ClientView`, the client
plugin's routes, the ClickUp import, reports, Slack/email copy): the studio
side reads `plain()` for one-line uses and the HTML for the panel. The client
plugin keeps showing what it gets until its own PR.

### Checklist

A new column `checklist` (text, JSON) on the work-items table; schema version
bumped. Shape: `[ { "text": "…", "done": false }, … ]`, at most **10** rows,
each at most one line (no newlines; 191 characters). `Validate` enforces both
and refuses over the limit with a field error.

Panel: a **Checklist** block inside the Task card, under Completed when. Rows
are a tick and a one-line box; Enter in a row adds the next (up to 10); an
empty row is dropped on save; a small "Add a line" button when under 10. Ticks
and text both save with Save changes. The board card shows "3/5" next to the
title when a checklist exists (`Card.tsx`), nothing when it does not.

API: the item answer carries `checklist`; `PATCH /work-items/<id>` accepts it.

### Tests (B)

`tests/e2e/item-rich-text.spec.js`: bold a word in the description, save,
reload → the `<strong>` survives; a pasted `<script>` does not; the Documentation
gate for the problem statement is met by formatted text and not by an empty
`<p></p>`. `tests/e2e/item-checklist.spec.js`: add three lines, tick one, save,
reload → 1/3 on the card; an eleventh line is refused with a message.

## PR C — Before-items resolve themselves; the Block form picks

### Principle

No "before" item asks for a free-typed record any more. Each is one of:

- **Auto** — worked out from what the task already holds. No control; the row
  shows a tick when met and what would meet it when not.
- **Pick** — a dropdown right-aligned on the row, saved with Save changes.
- **Stage box** — a proper box on the task, shown only while the task is at a
  stage whose next gate wants it (or already holds a value), saved with Save
  changes. Words, numbers and dates.

Under the hood a pick or a stage box still becomes a gate record
(`GateRecords::complete`) — the save posts one per changed value — so the
record of who answered what, and when, is unchanged. Auto items are decided
in `Gates::satisfied()` from the item, so the API and the panel agree.

### The table

Gates removed outright: G-TRIAGE-5 (Scope summary), G-DOCUMENTATION-2 (Scope),
G-DOCUMENTATION-4 (Requirements).

| Requirement | Kind | How it resolves |
|---|---|---|
| Site or portfolio scope confirmed; Site confirmed (triage) | auto | the item has a site |
| Source recorded | pick | Client request / Internal / Bug report / Meeting |
| Submitted for triage; Submitted to Reviewer | auto | the move itself records it |
| Parent chosen or created | pick | Top level / any other open item on the same client (sets `parent_id`) |
| Duplicate check completed | pick | No duplicate found / Duplicate of… (a second pick of the client's items; choosing one is what End it → duplicate uses) |
| Triage outcome recorded | pick | Proceed / Rejected / Duplicate / Deferred (the last three hand over to End it) |
| Commercial classification | auto | Who pays is not "To be confirmed" |
| Bug classification confirmed | pick | Broken feature / Regression / Content or data / Performance / Security |
| Expected versus actual; Reproduction steps; Environment and version; Initial diagnosis | stage box | text |
| Evidence attached; Work evidence; Test evidence; Release evidence; Approved design artifact | auto | an evidence entry (a comment with a link) added since the item entered the current stage |
| Impact and severity | pick | Low / Medium / High / Critical |
| Delivered-by-Forge determination | auto | Who pays is Site bug (yes) or Client / No charge (no) |
| Problem statement; Non-goals; Reference material; Acceptance criteria | field | Non-goals and Reference material get stage boxes (they had no box at all); the others are the Task card |
| Dependencies (documentation); Dependencies confirmed (audit, up next); Dependencies confirmed ready | pick | None / one or more of the client's items — backed by `Dependencies` (the existing table); "ready" is auto when every dependency is Completed or later |
| Affected sites and data | pick | This site only / More than one site |
| Documentation, Technical, Design, Review approval | pick | Not yet / Approved — offered only to the person the gate allows (`who`), as the record rule already enforces |
| Architecture assessment; Data and sync impact; Security and privacy impact; Test approach | stage box | text |
| Estimate range | stage box | two numbers, low and high |
| Risks; Responsive states; Empty/loading/error/denied states; Accessibility considerations; Completion checklist; Review checklist; Delivery checklist; Post-release check | pick | Not yet / Done |
| Requirements confirmed implemented | auto | the item's checklist is all ticked (or empty and the pick "Done") |
| Every acceptance criterion confirmed | auto | as above, on the checklist |
| All feedback resolved or returned | auto | no outstanding client question on the item (`Comments::outstanding`) |
| Planned hours per role | auto | all three seats' hours above zero |
| Post-review hours adjustment | stage box | a number (0 means none) |
| Release window | stage box | date |
| Release notes; Environment and version, or handover destination | stage box | text |
| Release date and time | stage box | date and time |
| Blocker reason; Dependency (blocker) | pick | one of the client's other items, or "Something else" with a line of text |
| Blocker owner | pick | any person, or "The client" |
| Target resolution date; Next action; Resolution note | stage box | date / text / text (the Block and Unblock forms) |

### Where the values live

- Picks and stage boxes: gate records, one per requirement, value as the pick's
  key or the box's text. The panel reads `detail.records` to fill them back in.
- Parent, dependencies, duplicate-of: their own fields/tables
  (`parent_id`, `Dependencies`, the End it duplicate id), so the rest of Forge
  sees them.
- Auto: nothing stored; `Gates::satisfied()` gains a `self::auto( … )`
  requirement kind alongside field/record/system with a resolver per id.

### Block form

The five fields become: What is blocking it (pick: client's items or Something
else + text), Who owns the blocker (pick: people, or The client), What it is
waiting on (pick: client's items or Something else + text), Target date
(date), Next action (text). Posted as today to `/block`; the domain accepts
either an item id or text for the two picks and stores the label.

### Tests (C)

`tests/e2e/gates-resolve.spec.js`: a Future Idea with a site and a source pick
shows only "Submitted for triage" left, and the move does it; a bug at Bug
Tracking with the four stage boxes filled, a pick each for classification and
severity, an evidence comment and Who pays = Site bug reaches Documentation;
Up Next's hours item is met by the three seat picks alone; a Block with an item
picked as the blocker stores that item's title as the reason. Existing gate
specs are updated where they recorded by hand.

## Not in this work

Recurring tasks (block 5), calendar dates (block 6), the Profile page and
availability patterns (block 4), the client plugin's rendering of rich text
(its own PR after B).
