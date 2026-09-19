# Before-items Resolve Themselves — Implementation Plan (PR C of the task panel rework)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** No "before" item asks for typed-in words any more. Each is worked out from the task (auto), chosen from a dropdown on its row (pick), or filled in a proper box on the task that appears only when the next stage wants it (stage box). The Block form picks the client's items and people instead of taking free text.

**Architecture:** `Work\Gates` gains a fourth way a requirement is satisfied — `auto`, a resolver run against the item and a context the transition service supplies (evidence since the stage was entered, outstanding client questions, the dependencies' stages). Record requirements carry `control` (`pick`/`box`), `options`/`source` and `input`, and may name an `auto` fallback (a parent set, dependencies added, a checklist all ticked). The evaluation returns every requirement with a `met` flag, so the panel draws the whole gate. Picks and boxes are drafted in the panel and posted as gate records by Save changes; parent and dependencies go to their own field/table.

**Spec:** `docs/superpowers/specs/2026-09-18-task-panel-rework-design.md`, section "PR C".

## Global Constraints

- Branch `task-panel-gates` off `task-panel-rich-text`; draft PR to `task-panel-rich-text`. Version `2.116.0` → `2.117.0`; changelog `## [2.117.0] - 2026-09-18`.
- No new dependencies; nothing under `client/` but the version line; restore the client bundle after every build. Admin-only routes only.
- Gates removed outright: G-TRIAGE-5, G-DOCUMENTATION-2, G-DOCUMENTATION-4. Every other id keeps its number.
- `Gates` stays free of the database: everything an auto resolver needs arrives in `$context`.
- Existing e2e helpers keep working: `satisfy()` learns the two auto kinds it cannot satisfy by a field or a record (evidence → an evidence comment; hours → the three seat hours).
- Never build or edit PHP during a Playwright run. Lint once at the end.

## Rulings while planning

- The record route does not enforce `who` per requirement today (the move itself is what is authority-checked). The panel offers a PU/REV/DEL pick only to the person in that seat (or their substitute), from `forgeData().person.id`; APR picks are offered to everyone and the server's capability check stands.
- "Duplicate of…", "Parent" and "Dependencies" are one dropdown each: the fixed options first (No duplicate found / Top level / None), then the site's other open items. Dependencies are same-site by ARCH-3, so "the client's items" means the site's items.
- Block details keep their storage (reason and next action on the event); the controller turns a picked item or person into its title or name before the move, which is what the spec's test checks.

## File map

| File | Change |
|---|---|
| `includes/Work/Gates.php` | `BY_AUTO`; builders `auto()`, `pick()`, `box()`; the new table; `satisfied( …, $context )`; resolvers; `evaluate()` returns `all` |
| `includes/Work/Transition.php` | context for the resolvers (`evidence_since_entry`, `outstanding_questions`, `dependencies`); `entered_stage_at()`; merges `all` |
| `includes/Work/Fields.php` | `REQUIRED_FROM` loses `requirements`, gains `non_goals`, `references` |
| `includes/Rest/WorkItemsController.php` | `block()` resolves item/person ids to labels |
| `src/types.ts` | `Requirement.by` adds `'auto'`; `control`, `options`, `source`, `input`, `met`; `Readiness.all` |
| `src/components/ItemPanel.tsx` | GateList draws every row with a tick or its control; picks/boxes drafted and saved by Save changes; stage boxes and the two definition boxes on the Task card; Block form picks; End form duplicate pick |
| `src/components/StandupScreen.tsx` | GateList without `onPick` records a pick straight away |
| `src/styles.css` | the row's select, the met tick |
| `tests/php/WorkGatesTest.php` | updated ids; resolver tests |
| `tests/e2e/helpers/forge.js` | `satisfy()` handles evidence and hours |
| `tests/e2e/gates-resolve.spec.js` (create); `item-assignment.spec.js`, `standup-actions.spec.js` (picks instead of Record) | |
| version files, `CHANGELOG.md` | 2.117.0 |

---

### Task 1: Gates — the table, the resolvers, `all`

- Requirement shape gains `control` (`''|'pick'|'box'`), `options` (`array<int, array{value,label}>`), `source` (`''|'items'|'people'`), `input` (`''|'text'|'number'|'date'|'datetime'|'range'`), `auto` (`''|resolver`). `as_unmet()` passes them through and takes `$met`.
- `public const BY_AUTO = 'auto'`. `auto( $id, $label, $type, $resolver, $satisfied_by, $who = ANY )` → `by => BY_AUTO, check => $resolver`. `pick( $id, $label, $options, $satisfied_by, $who = ANY, $source = '', $auto = '' )` with `$options` as `array( 'key' => 'Label' )`. `box( $id, $label, $input, $satisfied_by, $who = ANY )`. `evidence()` becomes `auto( …, 'evidence', … )` with `evidence => true` kept.
- Table per the spec (ids unchanged; three removed). Source picks: G-TRIAGE-3 `top-level` + items (auto `parent`); G-TRIAGE-6 `none` + items; G-DOCUMENTATION-6 / G-TECHNICAL-AUDIT-2 / G-UP-NEXT-7 `none` + items (auto `dependencies`); G-BLOCKED-ENTRY-1/3 `other` + items; G-BLOCKED-ENTRY-2 `client` + people. Approvals: `approved => 'Approved'`. Checklists: `done => 'Done'`. G-IN-DEVELOPMENT-1 and G-IN-REVIEW-2: `done` pick with auto `checklist`. G-BUG-TRACKING-8 → `classification()` (field on `commercial_class`). G-FUTURE-IDEA-2 / G-TRIAGE-2 → `field( …, array( 'client_site_id' ) )`. G-FUTURE-IDEA-4 / G-IN-DEVELOPMENT-6 → `system( …, 'submission', 'Recorded by the move itself.' )`.
- `satisfied( $requirement, $item, $records, $context = array() )`: record → `isset( $records[ id ] ) || ( '' !== auto && self::resolve( auto, … ) )`; auto → `resolve( check, … )`. Resolvers: `evidence` → `! empty( $context['evidence_since_entry'] )`; `feedback` → `0 === (int) ( $context['outstanding_questions'] ?? 0 )`; `hours` → the three hours all `> 0`; `checklist` → non-empty and every row done; `parent` → `parent_id` filled; `dependencies` → `count( $context['dependencies'] ?? array() ) > 0`; `dependencies_ready` → every stage in `$context['dependencies']` at or after `completed` in `Stages::ALL` (none → true). `check()` learns `submission` → true.
- `evaluate()` also returns `'all'`: every non-system requirement as `as_unmet( $requirement, $met )`.
- `WorkGatesTest`: structured test admits `BY_AUTO`; the refusal test drops -2/-4 and asserts -3, -5, -8; new tests: evidence unmet without context and met with it; hours met by three positive hours; checklist ticked satisfies G-IN-DEVELOPMENT-1 with no record; a parent set satisfies G-TRIAGE-3; dependencies ready only when all completed. Run `vendor/bin/phpunit`. Commit.

### Task 2: Transition context, fields, the Block labels

- `Transition::evaluate()`: `if asks_about( 'evidence' )` → `$context['evidence_since_entry'] = self::evidence_since( $item )` (an entry in `Comments::for_item( id, SCOPE_STAFF )` with `url !== ''` and `created_at >= self::entered_stage_at( $item )`); `feedback` → `count( Comments::outstanding( id ) )`; `dependencies`/`dependencies_ready` → the stages of `Items::get()` for each `Dependencies::for_item()` row. `entered_stage_at()`: the newest event in `Events::for_item()` whose `to_stage` is the item's stage, else `0`. `asks_about()` also reads `auto`. Merge `all`.
- `Fields::REQUIRED_FROM`: drop `requirements`; add `non_goals`, `references` at `documentation-period`.
- Controller `block()`: `reason`/`dependency` beginning `wrk_` → that item's title if it is on the same site (else refused as absent); `owner` `client` → `The client`, `usr_…` → the person's display name.
- Unit tests pass; commit.

### Task 3: The panel

- Types. `Readiness.all?: Requirement[]`; `Requirement.met?: boolean`, `control`, `options`, `source`, `input`.
- State: `picks`, `boxes` (`Record<string,string>`), set from `records[id].value` on load; `siteItems` from `/work-items?client_site_id=` on load (not this item, not ended, not archived).
- `GateList( { heading, readiness, records, busy, onComplete, picks?, onPick?, people?, items?, allowed? } )`: rows from `readiness.all ?? readiness.unmet`; `data-met`; met rows show `✓`; unmet rows: field/auto/box → `satisfied_by` text; pick → `<select data-testid="bwx-pick" data-requirement>` with `Choose`/`Not yet` first, then options, then source items/people; `onPick` drafts, otherwise records at once. Rows whose `who` is PU/REV/DEL and `allowed` says no read "For the reviewer" etc. Standup passes nothing extra.
- Save changes: PATCH the draft (plus `parent_id` when the parent pick is an item); then for each changed pick/box: dependencies with an item → `POST /dependencies { depends_on_id }`; approval/done picks only when set; everything else `POST /gate { requirement, value }`. Triage outcome other than `proceed` opens End it with that outcome instead of recording. Duplicate pick of an item prefills the End form's duplicate.
- Task card: after the checklist, `Not covered` (`non_goals`) and `Reference material` (`references`) when `reached( 'documentation-period' )` or filled; then every `box` requirement from the next stages' `all` (or with a record): text → textarea, number/date/datetime/range → inputs (`range` = low and high, stored `low-high`). Test ids `bwx-box-<id>`.
- Block form: reason and dependency selects (`Something else` → a text input beside), owner select (people + The client), date, next action. End form: duplicate select of site items.
- `npm run build`; commit.

### Task 4: Tests

- `helpers/forge.js` `satisfy()`: `auto` + `evidence` → `POST /comments { body: 'Evidence.', url: 'https://example.test/evidence', kind: 'evidence', visibility: 'internal' }`; `auto` + `hours` → patch `hours_primary/hours_review/hours_delivery: 1` unless the seats gave them; `record` with a pick → value is the first option's key (approved/done/none/proceed/…).
- `gates-resolve.spec.js`: the spec's four scenarios. `item-assignment.spec.js`: `recordWhatIsAsked` picks the first option of every `bwx-pick` then Save. `standup-actions.spec.js`: the outstanding requirement is the one with a pick; select it; the viewer's refusal comes from the same select.
- Full Playwright run against the local instance; PHP lint; `npm run lint`; version + changelog; draft PR.
