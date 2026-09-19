# Task Panel Layout — Implementation Plan (PR A of the task panel rework)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The item panel gets a fixed header and footer, cards with right-aligned actions, the renamed fields, four "Who pays" options, a collapsed and honest history with a who-pill, a comments footer, and the New task form gets a client pick.

**Architecture:** Markup and CSS in `ItemPanel.tsx` / `NewWork.tsx` / `styles.css`; one enum widened in the domain with a rule (`free-bug` ⇒ `delivered_by_forge`); `actor_name` added to history entries at the REST layer. No schema change.

**Spec:** `docs/superpowers/specs/2026-09-18-task-panel-rework-design.md`, section "PR A".

## Global Constraints

- Branch `task-panel-layout` off `main`; draft PR to `main`. Version `2.114.1` → `2.115.0`; changelog `## [2.115.0] - 2026-09-18`.
- No new dependencies; nothing under `client/` but the version line; restore the client bundle after every build.
- Every existing test id stays. Tests change only where the spec changes behaviour (Who pays options, the checkbox gone, history grouping).
- Never build or edit PHP while a Playwright run is in flight. Lint once at the end.
- The `Aside` panels elsewhere (Availability, Recurring, New task) share `.bwx-panel`; the item panel's frame is a modifier so they are untouched.

## File map

| File | Change |
|---|---|
| `includes/Work/Fields.php`, `Validate.php` | `free-general` in `COMMERCIAL_CLASSES`; `free-bug` sets `delivered_by_forge = 1` in `enum_fields()` |
| `includes/Rest/WorkItemsController.php` | `actor_name` on each history entry (`Person`/`get_userdata` display name; `''` for 0) |
| `src/types.ts` | `WorkEvent.actor_name`, `.field` |
| `src/components/ItemPanel.tsx` | frame (`bwx-panel--framed`, `.bwx-panel-body`, `.bwx-panel-foot`), cards, right-aligned rows, renames, CLASSES, checkbox gone, comments footer, history `<details>` with grouped edits and who-pill, Task card |
| `src/components/NewWork.tsx`, `WorkScreen.tsx` | client pick always shown; `sites` always passed |
| `src/styles.css` | frame, foot, cards, `.bwx-row-actions`, `.bwx-form-foot`, toggles with arrows/colours, history pill |
| `tests/e2e/item-panel-layout.spec.js` (create); `item-assignment.spec.js`, any spec ticking `bwx-delivered_by_forge` or reading history lines | |
| version files, `CHANGELOG.md` | 2.115.0 |

---

### Task 1: Domain — Who pays, and who did it

- `Fields::COMMERCIAL_CLASSES = array( 'chargeable', 'free-bug', 'free-general', 'unclassified' )`. Grep `free-bug`/`chargeable` (`WorkHours::chargeable` reads `=== 'chargeable'`, so `free-general` is already not chargeable; confirm nothing enumerates the set elsewhere — `Gates::classification` checks non-empty and not `unclassified`, fine).
- `Validate::enum_fields()`: after the enum loop, `if ( 'free-bug' === ( $values['commercial_class'] ?? '' ) ) { $values['delivered_by_forge'] = 1; }` (the screen's checkbox is gone, so the class is the one source).
- `WorkItemsController` show route: `$history = array_map( fn( $e ) => $e + array( 'actor_name' => self::actor_name( (int) $e['actor'] ) ), Events::for_item( … ) )` with `actor_name()` reading `get_userdata()->display_name`, `''` when 0/none. Also add `actor_name` where the client view answers history, if it does (grep `history` in `ClientView`/client controller — the client plugin stays untouched; only the studio route changes).
- `vendor/bin/phpunit`; `composer lint` (or `vendor/bin/phpcs` on the touched files). Commit: "Who pays has four answers, and history says who".

### Task 2: The panel frame and cards

- `<aside className="bwx-panel bwx-panel--framed">`: header as now (title, marks, close; **Delete moves out**); `<div className="bwx-panel-body">` wraps everything from the notice to History; `<footer className="bwx-panel-foot">` after it with Delete (left, `canManage` + `detail`) and Save changes (right, `bwx-save`, shown when `detail && item && staff && ! ended` — the same condition the Save button has today; when Save is not offered the footer still shows with Delete or empty).
- CSS: `.bwx-panel--framed { overflow: hidden; padding: 0; gap: 0; }`, `.bwx-panel--framed .bwx-panel-head { padding: 20px 24px 14px; }`, `.bwx-panel-body { flex: 1; overflow-y: auto; padding: 16px 24px; display: flex; flex-direction: column; gap: 12px; }`, `.bwx-panel-foot { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 12px 24px; border-top: 1px solid var(--border-hairline); background: var(--surface-card); }` (a lone Save sits right: `margin-left: auto`).
- The card selectors `.bwx-panel > div:has(> .bwx-eyebrow:first-child)` etc. become `.bwx-panel-body > div…`. Every block gets an eyebrow so it is a card: **Task** wraps the three `EDITABLE` fields (`<div className="bwx-task" data-testid="bwx-task">` + eyebrow "Task"); the toggles (`Send back / Block / End it`) and their open form live in one `<div data-testid="bwx-actions">` with eyebrow "What else"; Archive joins that card's row.
- Right-aligned rows: `.bwx-panel-body .bwx-moves` gets `justify-content: flex-end` (the stage buttons, the toggles, form footers); the Blocked/unblock button too.
- Toggles: `data-tone="return|block|end"` on the three buttons; CSS colours the border/text from `--phase-return`-ish tokens (use `--stage-blocked-*` for Block, `--phase-done` for End, `--color-amber`/`--state-warn` for Send back); glyphs `←` / `⏸` / `→` inside the label as `<span aria-hidden>`.
- Form footers: the `bwx-moves` at the end of each form gets class `bwx-form-foot` (`margin-top: 16px; padding-top: 12px; border-top: 1px solid var(--border-hairline); display: flex; justify-content: flex-end`). Fields in a form: `.bwx-panel-body .bwx-field + .bwx-field { margin-top: 12px }` (or a `gap` on the form wrapper).
- Comments: the add form's Add button row becomes `bwx-form-foot`; the form's fields get the same 12px rhythm.
- Renames: `EDITABLE` labels "Item description" and "Completed when"; `scope` and `requirements` removed from `EDITABLE` (and from `asDraft`/save — the save only sends the draft's keys, so removing them from the list is enough; confirm `EDITABLE` is the only source).
- Who pays: `CLASSES` → unclassified "To be confirmed", `free-bug` "Site bug — no charge to client", `chargeable` "Client — charge to client", `free-general` "No charge — general item". The `bwx-delivered_by_forge` checkbox goes, and `delivered_by_forge` leaves `ASSIGNMENT` (the server sets it).
- New task: `NewWork` always shows the pick, labelled "Client", with `sites` passed always from `WorkScreen` (`sites={ sites }`), initial value `clientSiteId` when it is a real site, else the studio's. Option labels from `siteLabel`.
- Build; eyeball in the browser; commit: "The panel has a header, a body and a footer".

### Task 3: History

- `describe()` unchanged for real events. Rendering: `<details className="bwx-history-wrap" data-testid="bwx-history-wrap"><summary>History · N</summary><ul className="bwx-history">…</ul></details>` — N counts the lines after grouping.
- Grouping: walk `detail.history`; consecutive `edited` entries with the same `actor` and `occurred_at` within 2 seconds fold into one line "Edited " + the field labels (`FIELD_LABELS` map for the fields the panel knows: title, problem "description", acceptance_criteria "completed when", commercial_class "who pays", priority, the seats and hours, dates, release fields; unknown fields fall back to the field name with underscores as spaces; up to four, then "and N more"). Other actions stay one line each. Nothing is dropped.
- Each line: text, reason, time, then `<span className="bwx-history-who">{ actor_name || 'Forge' }</span>` pushed right (`margin-left: auto`, pill styling on `--surface-sunken`).
- Tests: `item-panel-layout.spec.js` (serial; a site + a staff person via helpers; open an item on the board): footer holds `bwx-item-delete` and `bwx-save`; `bwx-history-wrap` is closed by default and opens; after saving title + priority in one go, exactly one new history line contains "Edited" and the person's name pill; Who pays offers the four labels; choosing Site bug and saving → `GET /work-items/<id>` shows `commercial_class free-bug` and `delivered_by_forge true`; the New task form on a client's board shows the Client pick with the studio in it, and creating with another site chosen lands the item on that site.
- Grep specs for `bwx-delivered_by_forge` and `bwx-history` assertions and adjust. Commit: "History says what happened and who did it, and stays folded".

### Task 4: Version, changelog, lint, specs, PR

2.115.0; changelog in Luke's words: "The task panel has a fixed header and footer — Delete on the left, Save changes on the right — with every block in its own card and its buttons on the right. Problem it solves is now Item description, Done when is Completed when, and Scope and Requirements are gone. Who pays for it offers To be confirmed, Site bug, Client, or No charge; Site bug means we delivered the thing that broke. History stays folded until opened, lists what actually happened with one line per save and who did it, and adding a comment no longer needs Save. New task asks which client it is for." Lint once; build; restore client bundle; run `item-panel-layout`, `item-assignment`, `board`/`work` specs that open the panel, `capacity-gate`, `work-hours`, `queue`; push; draft PR to `main`.
