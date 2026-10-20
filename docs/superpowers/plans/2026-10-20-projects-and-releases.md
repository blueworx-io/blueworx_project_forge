# Projects and Releases Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Group each client's work into projects made of releases made of features, with a Projects page, a release review, client visibility, and a copy-link button on every item.

**Architecture:** Projects and releases stay ordinary work items, using the existing `level` (`project`, `milestone`) and `parent_id` (WORK-1). Nothing new is stored except one column marking bugs and feedback raised in a release's review. The new screens (client profile, Projects, project page, client Projects) build a tree client-side from item lists the API already serves, using one shared pure module.

**Tech Stack:** PHP 8 (WordPress REST, `Blueworx\Forge`), React + TypeScript (Vite), shared kit in `src/kit/`, PHPUnit, Playwright, `node --test`.

**Spec:** `docs/superpowers/specs/2026-10-20-projects-and-releases-design.md`

**Issues:** Task 1 → #559, Task 2 → #560, Task 3 → #561, Task 4 → #562, Task 5 → #563, Task 6 → #564, Task 7 → #565. Milestone "Projects and releases".

## Global Constraints

- One task = one branch = one draft PR, linked to its issue. Never merge or tag.
- Each PR bumps the minor version (each task adds a feature) in `package.json`, the `blueworx-forge.php` header and `BWX_FORGE_VERSION`, the `client/blueworx-forge-client.php` header and `BWX_FORGE_CLIENT_VERSION`, all to the same value. Leave `package-lock.json`'s version alone. Chain the PRs so each bumps from the one before (current is 2.192.0).
- Each PR adds a `CHANGELOG.md` entry at the top, in the reader's words, under `### Added` / `### Changed`.
- A change to how Forge behaves updates `src/rules.ts` in the same PR.
- Screens use `src/kit/` components and the `frontend-design` skill. Test ids are `data-testid="bwx-<kebab>"`.
- People read "Release" wherever they used to read "Milestone". The stored level value stays `milestone` (our call, so no migration).
- No new npm or composer dependency. Icons come from `lucide-react`, already approved.
- Never build, edit PHP, or switch branches while a local Playwright run is going: the studio test site links this checkout.
- Run before each commit: `npm run lint`, `npm run build`, `composer lint`, `vendor/bin/phpunit`, `npm run test:unit`, and only the Playwright specs the task touches (`npx playwright test tests/e2e/<spec>`). CI runs the full suite.
- Visible changes: `npm run dev` and let Luke confirm in the browser before committing.

## Review Focus

1. **A bug, task or feedback item named as a parent.** It must be refused, so work never nests under a leaf and no loop forms (Task 4: `test_a_bug_task_or_feedback_cannot_be_a_parent`).
2. **Changing an item's level while it already has a parent or children.** Today only a changed `parent_id` is re-checked, so a feature could be renamed a project under its own release. It must be refused (Task 4: e2e `changing level is checked against parent and children`).
3. **A project with no releases, or releases with no dates.** It must read "Ongoing" and "No work yet", never 0% or NaN (Task 3: `tree.test.mjs` "an empty project has no progress").
4. **The clipboard isn't available** (plain http, permission refused). The link must still be reachable: show it in a read-only field to copy by hand (Task 1: e2e `falls back to showing the link`).
5. **A review fix that is cancelled, archived or moved elsewhere.** It must stop holding the review open, as `Derived::counted()` already does for children (Task 6: `test_a_cancelled_or_moved_fix_does_not_hold_the_review`).

---

### Task 1: Copy a link to any item (#559)

**Files:**
- Modify: `includes/Frontend.php` (the `$data` array in `enqueue()`, ~line 193)
- Modify: `src/types.ts` (`ForgeData`, lines 1–20)
- Create: `src/components/CopyLink.tsx`
- Modify: `src/components/ItemPanel.tsx` (header `bwx-panel-head`, lines 1430–1469, beside the close button)
- Modify: `CHANGELOG.md`, version files
- Test: `tests/e2e/copy-link.spec.js`

**Interfaces:**
- Produces: `window.bwxForgeData.appUrl: string`, set to `Frontend::instance()->app_page_url()`.
- Produces: `export function CopyLink({ itemId }: { itemId: string })`. It renders the kit `IconButton`, with lucide `Link` icon, label "Copy link" and `data-testid="bwx-copy-link"`, carrying `data-link={ url }`, where `url = appUrl + '#item=' + encodeURIComponent(itemId)`. The click calls `navigator.clipboard.writeText(url)`. On success, a polite live region `bwx-copy-link-done` says "Link copied". If the clipboard is missing or throws, it shows a read-only kit `TextInput` (`bwx-copy-link-field`) holding the url, selected.
- Task 4's project page and Task 5's Projects page reuse `CopyLink`.

- [ ] **Step 1: Write the failing e2e spec** `tests/e2e/copy-link.spec.js`. Preamble as in `up-next-dates.spec.js` (`Forge.signedIn`, `Forge.makeSite(admin.api, 'Linked Co', RUN_ID)`, `Forge.makeItem`).
  - `copies a link that opens the item`: grant `clipboard-read` and `clipboard-write`, open `/blueworx-forge/#item=${item.id}`, click `bwx-copy-link`. Expect `bwx-copy-link-done` to have the text "Link copied", and the clipboard to equal `bwx-copy-link`'s `data-link`, ending `#item=${item.id}`. Open that link in a new page and expect `bwx-panel` to contain the item title.
  - `falls back to showing the link`: before load, `page.addInitScript` deletes `navigator.clipboard`. Click, then expect `bwx-copy-link-field` to have the value of `data-link`.
- [ ] **Step 2: Run** `npx playwright test tests/e2e/copy-link.spec.js`. Expected: FAIL, `bwx-copy-link` not found.
- [ ] **Step 3: Add `appUrl` to `Frontend::enqueue()` and `ForgeData`; create `CopyLink`; place it in the panel header before the close button.**
- [ ] **Step 4: Run the spec again.** Expected: PASS (2 tests).
- [ ] **Step 5: Changelog** ("Every item has a Copy link button…"), rules entry under `journey` ("Every item has its own link…"), version bump, then `npm run dev` for Luke to confirm.
- [ ] **Step 6: Commit** `Copy a link to any item (#559)` and open a draft PR.

---

### Task 2: Rename Milestone to Release, and show release weeks (#560)

**Files:**
- Modify: `includes/Work/Levels.php:105` (`label()` returns `__( 'Release', 'blueworx-forge' )` for `MILESTONE`, plus docblocks)
- Modify: `src/components/NewWork.tsx:8` (`{ id: 'milestone', label: 'Release' }`)
- Modify: `src/client/screens/Requests.tsx:336` (copy: "project, release or feature")
- Modify: `src/kit/Gallery.tsx:259` (demo text)
- Create: `src/weeks.mjs`
- Modify: `src/components/Card.tsx` (~117) and `src/components/ListView.tsx` (~65): show weeks under the level label when `level === 'milestone'`
- Test: `tests/php/WorkHierarchyTest.php`, `tests/unit/weeks.test.mjs`
- Leave alone: `src/client/screens/Board.tsx:241` ("Milestone" there is a date-column header, not the level). Also leave the M2/M4/M6 build-milestone references in Denials, Sites and Acceptance.

**Interfaces:**
- Produces: `export function isoWeek(date: string): number` (ISO-8601 week of a `Y-m-d`).
- Produces: `export function releaseWeeks(start: string, end: string): string`. It returns `'Week 27'` when both dates fall in one week, `'Weeks 27 → 30'` when they differ, `'Week 27'` from whichever single date is set, and `''` when neither is.
- The weeks come from the item's own `planned_start` and `planned_due`, falling back to `derived_start` and `derived_due`. Task 4 and Task 7 reuse `releaseWeeks`.

- [ ] **Step 1: Write the failing tests.**
  - `tests/unit/weeks.test.mjs`:
    - `isoWeek('2026-06-30') === 27`
    - `isoWeek('2026-01-01') === 1`
    - `isoWeek('2027-01-01') === 53`
    - `releaseWeeks('2026-06-30','2026-07-21') === 'Weeks 27 → 30'`
    - `releaseWeeks('2026-06-22','2026-06-26') === 'Week 26'`
    - `releaseWeeks('','2026-07-21') === 'Week 30'`
    - `releaseWeeks('','') === ''`
  - PHP: add `test_a_milestone_reads_as_a_release`, asserting `Levels::label( Levels::MILESTONE ) === 'Release'`.
- [ ] **Step 2: Run** `npm run test:unit` and `vendor/bin/phpunit --filter WorkHierarchyTest`. Expected: both FAIL.
- [ ] **Step 3: Implement** `src/weeks.mjs`, the label change, and the four copy changes. Show weeks on Card and ListView (`data-testid="bwx-release-weeks"`).
- [ ] **Step 4: Run again.** Expected: PASS. `grep -rn "Milestone" src includes client --include=*.ts* --include=*.php` lists only the excluded lines.
- [ ] **Step 5: Changelog** ("Milestones are now called releases, and show their weeks"), rules entry under `journey`, version bump, then `npm run dev` for Luke.
- [ ] **Step 6: Commit** `Rename Milestone to Release (#560)` and open a draft PR.

---

### Task 3: Client profile page and the shared tree (#561)

**Files:**
- Create: `src/tree.mjs`
- Create: `src/components/ClientProfileScreen.tsx`
- Modify: `src/types.ts:199` (`ScreenName` gets `'client'`)
- Modify: `src/App.tsx`:
  - the landing parser (291–307): add `client` from `/(?:^|[#&])client=([A-Za-z0-9_-]+)/`
  - `TITLES` and `OPENINGS`: add the new screen
  - render list (578–597): `{ 'client' === screen && <ClientProfileScreen clientId={ clientId } onBack={ () => go('clients') } /> }`
  - `go()`: accept an id
  - not in `RAIL`, as it is reached from Clients
- Modify: `src/components/ClientsScreen.tsx` (selected panel, ~line 400): an "Open profile" `Button` (`bwx-clients-open-profile`) that calls a new `onOpen(clientId)` prop
- Test: `tests/unit/tree.test.mjs`, `tests/e2e/client-profile.spec.js`

**Interfaces:**
- Produces, in `src/tree.mjs`:
  - `export const LEAF_TYPES = ['bug', 'task', 'feedback']`
  - `export function buildTree(items, rootId)`. It returns `{ item, parts: Node[], attached: Item[] }`. `parts` are children whose `work_type` is not in `LEAF_TYPES`, recursive, ordered by `planned_start` then title. `attached` are leaf-type children, ordered by created order.
  - `export function topLevel(items, level)`, which returns items of that level, ordered by `planned_start`.
  - `export function summary(node)`, which returns `{ total, finished, start, due, ongoing }`:
    - `total` and `finished` come from the node item's `children_total` and `children_finished` once Task 4 adds them, and are counted from the tree until then.
    - `ongoing = !due`.
    - `finished` means stage `completed` or `released`, and items with `terminal_outcome` or `archived` are not counted, matching `Derived::counted()`.
- Produces: `ClientProfileScreen({ clientId, onBack })`. It shows the client's name, contact and sites (from the existing `/clients/{id}` GET), and an empty "Projects" section (`bwx-client-projects`) that Task 4 fills.
- Produces: the hash `#screen=client&client=<id>` opens the profile.

- [ ] **Step 1: Write the failing unit tests** `tests/unit/tree.test.mjs`:
  - "parts and attached are split by type": a release with one feature and one bug gives `parts.length === 1` and `attached.length === 1`.
  - "nesting is recursive": a feature's sub-feature appears under `parts[0].parts`.
  - "an empty project has no progress": `summary(buildTree([project], project.id))` deep-equals `{ total: 0, finished: 0, start: '', due: '', ongoing: true }`.
  - "cancelled and archived children are not counted".
- [ ] **Step 2: Write the failing e2e spec** `client-profile.spec.js`, `opens a client's profile from Clients and by link`. It makes a site with `Forge.makeSite`, goes to `#screen=clients`, selects the row, clicks `bwx-clients-open-profile`, then expects `bwx-client-profile` to contain the client name and `bwx-client-projects` to be visible. It then opens `/blueworx-forge/#screen=client&client=${client.id}` directly and expects the same.
- [ ] **Step 3: Run both.** Expected: FAIL.
- [ ] **Step 4: Implement** `tree.mjs`, the screen, the routing and the button.
- [ ] **Step 5: Run both again.** Expected: PASS.
- [ ] **Step 6: Changelog**, version bump, then `npm run dev` for Luke. No rules change: nothing behaves differently.
- [ ] **Step 7: Commit** `Add a client profile page (#561)` and open a draft PR.

---

### Task 4: Projects and releases on a client (#562)

**Files:**
- Modify: `includes/Rest/WorkItemsController.php`:
  - `parent_error()` (2540): new signature `parent_error( string $parent_id, string $level, string $client_site_id, string $child_id = '', string $work_type = '' )`
  - `create()` (1374) and `update()` (1473): pass the work type through
  - `update()`: also re-check when `level` is patched
- Modify: `includes/Work/Derived.php`: add `children_total` and `children_finished` to `fields()` and to `NAMES` (line 60)
- Modify: `includes/Work/Gates.php`: the `G-UP-NEXT` date rows `-5`, `-10`, `-11`, `-12` (225–231) are marked `not_for_projects` through a new `private static function not_for_projects( array $requirement ): array`. They are skipped in the requirement loop (~390) when `$item['level'] === Levels::PROJECT`, beside the existing designer skip.
- Modify: `src/types.ts`: add `children_total?: number; children_finished?: number` (beside 147–150)
- Modify: `src/components/NewWork.tsx`: new optional prop `preset?: { level?: string; parentId?: string; workType?: string; reviewOf?: string }`. When set, the level and type are fixed and shown read-only, and `parent_id` (and Task 6's `review_of`) are posted. `TYPES` gains `{ id: 'feature', label: 'Feature' }` for structural items.
- Create: `src/components/ProjectScreen.tsx`
- Create: `src/components/SitsUnder.tsx`, placed in `ItemPanel.tsx` with the auto-save pattern from #544
- Modify: `src/components/ClientProfileScreen.tsx` (fill `bwx-client-projects`)
- Modify: `src/App.tsx`: screen `'project'`, landing `project=` param, `#screen=project&project=<id>`
- Modify: `src/rules.ts`: `journey` gets three entries:
  - projects, releases and features nest, and bugs, tasks and feedback sit under any of them
  - a project or release's own hours are the work on it as a whole, counted once
  - projects don't need dates to leave Up Next, and take their dates from their releases
- Test: `tests/php/WorkHierarchyTest.php`, `tests/php/WorkDerivedTest.php`, `tests/php/WorkGatesTest.php`, `tests/e2e/projects-and-releases.spec.js`, plus the existing `tests/e2e/work-items-rest.spec.js` "a parent has to be a higher level" test

**Interfaces:**
- Parent rule (in `parent_error`, after the existing checks):
  - a parent whose `work_type` is bug, task or feedback is refused: 400 `invalid_parent`, "Bugs, tasks and feedback can't have work beneath them."
  - a child whose `work_type` is bug, task or feedback may sit under any level, sub-feature included, so it skips `Levels::may_parent`
  - every other child keeps `Levels::may_parent`
  - existing rows are not re-validated
- Level patch: when `level` changes, re-run `parent_error` against the current parent, then refuse with 400 `invalid_level` if any non-leaf child no longer ranks below it.
- `Derived::fields()` adds `children_total` (count of `counted()`) and `children_finished` (count of `is_finished()` among them).
- `ProjectScreen({ projectId, onBack })`:
  - loads `GET /work-items?client_site_id=<site>` once and builds the tree with `buildTree`
  - header: title, client, dates (`derived_start` to `derived_due`, else "Ongoing"), "N of M done", and `CopyLink`
  - "+ Add release" (`bwx-project-add-release`) opens `NewWork` with `preset={{ level: 'milestone', parentId: projectId, workType: 'feature' }}`
  - each release row (`bwx-project-release`, `data-item`) shows its title, `releaseWeeks`, stage chip and progress. It expands to its parts, recursively, then its attached items.
  - each node has "+ Add feature" (inside releases; level `feature`, or `sub-feature` inside a feature) and "+ Add bug / task / feedback" (leaf types, at level `sub-feature`)
  - clicking any row opens `ItemPanel` for it
- `SitsUnder({ item, siteItems, onChanged })`: a kit `Select` (`bwx-sits-under`) listing "Nothing" plus every item on the same site that may be a parent under the rule above. It PATCHes `parent_id`.
- `ClientProfileScreen` "Projects" section: lists the client's projects (`bwx-client-project` rows linking to the project screen), plus "+ Add project" (`bwx-client-add-project`) opening `NewWork` with `preset={{ level: 'project', workType: 'feature' }}` on the client's site. A client with several sites gets a site choice.

- [ ] **Step 1: Write failing PHP tests.**
  - `WorkHierarchyTest`:
    - `test_a_bug_task_or_feedback_cannot_be_a_parent`: each of the three is refused as a parent.
    - `test_a_bug_can_sit_under_a_sub_feature`
  - `WorkDerivedTest`: `test_fields_count_total_and_finished_children`. Two counted children, one completed, one cancelled: `children_total === 1`, `children_finished === 1`.
  - `WorkGatesTest`: `test_a_project_leaves_up_next_without_dates`. A project item with no `planned_due`, `review_target` or `release_target` has no `G-UP-NEXT-5`, `-10` or `-11` rows. A feature with no dates still fails them.
- [ ] **Step 2: Run** `vendor/bin/phpunit`. Expected: the new tests FAIL.
- [ ] **Step 3: Implement the PHP rules** (parent, level patch, derived counts, project date skip).
- [ ] **Step 4: Run** `vendor/bin/phpunit`. Expected: PASS. Fix `WorkHierarchyTest` and `work-items-rest.spec.js` expectations only where they asserted that leaf types obey `may_parent`.
- [ ] **Step 5: Write the failing e2e spec** `projects-and-releases.spec.js`:
  - `builds a project from a client's page`:
    - open the client profile, then add project "App 2026"; `bwx-client-project` appears
    - open it, then add two releases with dates; two `bwx-project-release` rows appear, each showing its weeks
    - add a feature to the first release and a bug to that feature; the feature row shows the bug under it
  - `a project can't be released while a release is open`: drive the project with `Forge.walkTo` to Completed, then try Released. Expect refusal naming `G-COMPLETED-8`.
  - `changing level is checked against parent and children`: PATCH a feature that sits under a release to `level: 'project'`. Expect 400.
  - `moves an existing task under a release from its panel`: open the task, choose the release in `bwx-sits-under`, reload, and the task shows under the release on the project page.
- [ ] **Step 6: Run the spec.** Expected: FAIL.
- [ ] **Step 7: Implement** `NewWork` presets, `ProjectScreen`, `SitsUnder`, the profile Projects section and routing.
- [ ] **Step 8: Run** the spec and `tests/e2e/work-items-rest.spec.js`. Expected: PASS.
- [ ] **Step 9: Rules entries, changelog, version bump,** then `npm run dev` for Luke.
- [ ] **Step 10: Commit** `Add projects and releases to clients (#562)` and open a draft PR.

---

### Task 5: Projects page (#563)

**Files:**
- Create: `src/components/ProjectsScreen.tsx`
- Modify: `src/types.ts` (`ScreenName` gets `'projects'`)
- Modify: `src/App.tsx`: a `RAIL` entry `{ key: 'projects', label: 'Projects', icon: FolderKanban, testId: 'bwx-screen-projects' }` in the work group, plus `TITLES`, `OPENINGS` and the render list. Not admin-only.
- Test: `tests/e2e/projects-page.spec.js`

**Interfaces:**
- Consumes: `GET /work-items-all?level[]=project` (the `level` filter already exists in `Filters::FROM_LIST`), `useClientChoice()` (`siteId`, `label`), `summary()` and the Task 4 derived fields.
- Produces: `ProjectsScreen({ onOpen }: { onOpen: (projectId: string) => void })`:
  - a kit `DataView` (`bwx-projects-list`), one row per project, with columns Project, Client (`label(site)`), Dates (`derived_start` – `derived_due`, else "Ongoing"), Releases (`children_total`), and Progress (kit `Meter` plus "N of M done", or "No work yet" when M is 0)
  - when `siteId` is not `ALL_SITES`, only that site's rows
  - an Active / Completed switch (`bwx-projects-active` / `bwx-projects-completed`, default Active). Completed means stage `released`.
  - the row click calls `onOpen`, which App routes to `'project'`

- [ ] **Step 1: Write the failing e2e spec** `projects-page.spec.js`:
  - `lists projects across clients and filters by client`: two sites, one project each. The page shows both rows. Picking one site in the global picker leaves one row.
  - `opens a project from its row`: the click lands on `bwx-project-screen` with the title.
  - `a project with nothing in it reads Ongoing and No work yet`
  - `completed projects sit behind the Completed switch`
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run.** Expected: PASS.
- [ ] **Step 5: Changelog, version bump,** then `npm run dev` for Luke.
- [ ] **Step 6: Commit** `Add a Projects page (#563)` and open a draft PR.

---

### Task 6: Release review (#564)

**Files:**
- Modify: `includes/Data/Schema.php`: `VERSION` 41 → 42, and `work_items` gets `review_of varchar(32) NOT NULL DEFAULT ''` (after `parent_id`, line 692)
- Modify: `includes/Work/Items.php` (defaults ~682, hydrate ~755), `includes/Work/Validate.php` (accept `review_of`, default '')
- Modify: `includes/Rest/WorkItemsController.php` `create()`. When `review_of` is set, it must equal `parent_id`, the parent must have level `milestone` and stage `in-review`, and `work_type` must be bug or feedback. Otherwise 400 `invalid_review_fix`, "Only bugs and feedback can be raised in a release's review, while it is in review."
- Modify: `includes/Work/Gates.php`:
  - `G-IN-DEVELOPMENT` gets a system row `G-IN-DEVELOPMENT-R`, "Every feature Completed", check `parts_completed`, for level `milestone` only. Every counted child that is not a review fix and not a leaf type must have reached Completed.
  - `G-IN-REVIEW` gets a system row `G-IN-REVIEW-6`, "Review fixes completed", check `review_fixes_completed`. Every counted child whose `review_of` equals the item id must have reached Completed. It passes when there are none.
  - Both checks go in `Gates::check()` beside `children_completed` (607–618) and read `$context['children']`.
- Modify: `src/components/ProjectScreen.tsx`: a review box (`bwx-release-review`) on a release in `in-review`. It lists its review fixes with done or not, shows "N of M done before this can be released", and has "+ Raise a bug" / "+ Raise feedback" (`bwx-release-raise`) opening `NewWork` with `preset={{ level: 'sub-feature', parentId, workType, reviewOf: parentId }}`.
- Modify: `src/types.ts` (`review_of?: string`), `src/tree.mjs` (`buildTree` puts `review_of === rootId` children in a separate `review: Item[]`; `summary` counts them, per the spec)
- Modify: `src/rules.ts`: `review` gets entries for when a release goes into review, raising fixes, and why it can't be approved until they're done
- Test: `tests/php/WorkGatesTest.php`, `tests/php/SchemaTest.php`, `tests/unit/tree.test.mjs`, `tests/e2e/release-review.spec.js`

**Interfaces:**
- Produces: column `review_of` on items in API output. `tree.mjs` `buildTree` returns `{ item, parts, attached, review }`.

- [ ] **Step 1: Write failing PHP tests** in `WorkGatesTest`:
  - `test_a_release_goes_to_review_only_once_its_features_are_completed`. One feature in development fails `G-IN-DEVELOPMENT-R`; a completed one passes; a bug child doesn't block.
  - `test_a_release_review_waits_for_its_fixes`. A review fix in development fails `G-IN-REVIEW-6`; completed passes.
  - `test_a_cancelled_or_moved_fix_does_not_hold_the_review`. A fix with `terminal_outcome` `cancelled` passes; a fix whose `review_of` is a different id passes.
  - `test_review_rows_apply_to_releases_only`. A feature item has neither row.
  - `SchemaTest`: `work_items` has `review_of`.
- [ ] **Step 2: Run** `vendor/bin/phpunit`. Expected: FAIL.
- [ ] **Step 3: Implement** the schema, validation, create rule and gate rows.
- [ ] **Step 4: Run.** Expected: PASS.
- [ ] **Step 5: Add a failing unit test,** "review fixes are kept apart and counted": `buildTree(...).review.length === 1` and `summary` total includes it.
- [ ] **Step 6: Write the failing e2e spec** `release-review.spec.js`:
  - `raising a fix in review blocks approval until it is completed`:
    - walk a release with one completed feature to In Review
    - on the project page, raise a bug in `bwx-release-review`; it shows "0 of 1 done"
    - try to approve the review; the refusal names "Review fixes completed"
    - walk the bug to Completed; approving now succeeds
  - `only bugs and feedback, only while in review`: POST a task with `review_of`, and POST a bug with `review_of` on a release in development. Both 400 `invalid_review_fix`.
  - `a feature can be released before its release is`: walk a feature to Released while its release is still In Development.
- [ ] **Step 7: Implement** the review box and the tree change.
- [ ] **Step 8: Run** the spec and `npm run test:unit`. Expected: PASS.
- [ ] **Step 9: Rules entries, changelog, version bump,** then `npm run dev` for Luke.
- [ ] **Step 10: Commit** `Review a release before it goes out (#564)` and open a draft PR.

---

### Task 7: Projects and releases in the client workspace (#565)

**Files:**
- Modify: `includes/Work/Derived.php`: new `public static function across( array $items ): array`. It is moved from the child-map part of `WorkItemsController::derive_across()` (657), which now calls it, and merges `Derived::fields` into each item.
- Modify: `includes/Rest/ClientController.php::board()` (853): run items through `Derived::across()` before `ClientView::items()`
- Modify: `includes/Work/ClientView.php::item()` (73): the allowlist gains `progress`, `derived_start`, `derived_due`, `children_total`, `children_finished`. `review_of` is not published (our call: review fixes show to the client as ordinary bugs and feedback).
- Create: `src/client/screens/Projects.tsx`
- Modify: `src/client/App.tsx`: `ScreenName` and `SCREENS` (32–68) get `{ id: 'projects', label: 'Projects', ... }`, routed as `#projects/<id>`
- Modify: `src/client/api.ts`: the `Item` type gains the five fields
- Modify: `src/rules.ts`: `requests` or `people` gets "clients see their own projects and releases, read only"
- Test: `tests/php/ClientViewTest.php` (create if absent), `tests/pair/client-projects.spec.js`

**Interfaces:**
- Consumes: `buildTree`, `topLevel`, `summary` from `src/tree.mjs`, and `releaseWeeks` from `src/weeks.mjs`, imported into the client app.
- Produces: `Projects({ project }: { project: string })`:
  - with no id, a list of the client's projects (`bwx-client-projects-list`) with dates and progress
  - with an id, that project's releases, features and attached items, read only (`bwx-client-project`)
  - item rows link to `#board/<id>`, the existing item record

- [ ] **Step 1: Write the failing PHP test** `test_client_view_publishes_progress_but_not_review_of`.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement** `Derived::across`, the board change and the allowlist. Run `vendor/bin/phpunit`. Expected: PASS, and the existing tests still pass.
- [ ] **Step 4: Write the failing pair spec** `tests/pair/client-projects.spec.js`:
  - `a client sees their projects and releases`: on the studio, make a project with a release and a feature for the paired client. On the client site's `/forge/#projects`, the project is listed; opening it shows the release with its weeks and the feature.
  - `a client never sees another client's projects`: a project on a second client is absent.
- [ ] **Step 5: Run** `npm run wp:pair:up`, then `npx playwright test -c playwright.pair.config.js tests/pair/client-projects.spec.js`. Expected: FAIL.
- [ ] **Step 6: Implement** the client screen and route.
- [ ] **Step 7: Run the pair spec.** Expected: PASS.
- [ ] **Step 8: Rules entry, changelog, version bump,** then `npm run dev:client` for Luke.
- [ ] **Step 9: Commit** `Show projects and releases in the client workspace (#565)` and open a draft PR.
