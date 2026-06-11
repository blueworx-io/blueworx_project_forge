# Dependency Tracking Between Items — Design

**Issue:** [#42 — Add Dependency Tracking Between Items](https://github.com/blueworx-io/forge_project_management/issues/42)
**Date:** 2026-06-11
**Status:** Approved for planning

## Goal

Let items declare directional **blocks / blocked-by** dependencies on any other item, and
surface those relationships visually across the app. Dependencies are informational and
**warn on conflict** — they never hard-block an action.

## Scope

- **Semantics:** directional. An item stores the items that *block* it ("blocked by"); the
  reverse ("blocks") is derived.
- **Item types:** any item to any item — feature, sub-item, bug, feedback, release.
- **Visualisation:** Detail modal, Kanban card badges, and the Gantt timeline.
- **Behaviour:** warn on conflict — allow the action, surface a warning.

### Out of scope

- Cycle detection beyond excluding the item itself from its own picker.
- Dependency-aware auto-scheduling or date shifting.
- A dedicated node-and-edge graph view.
- Hard enforcement (blocking stage changes or saves).

## Data model

Add one optional field to each item type in [`src/app/types.ts`](../../../src/app/types.ts):

```ts
dependsOn?: string[]; // IDs of items that block this item ("blocked by")
```

Applies to `Feature`, `SubItem`, `Bug`, `Feedback`, `Release`. Item IDs are globally unique
WordPress post IDs, so a single flat array supports any-to-any across types.

**Why a single directional array (not dual `dependsOn` + `blocks`):** the "blocks" direction
is always derivable by scanning all items, so storing it separately would require a bug-prone
dual-write on every edit — the same reason the codebase derives release↔item membership rather
than mirroring it. This mirrors the existing `subItemIds` / `linkedFeatureIds` array-meta pattern.

### Persistence (WordPress REST)

New meta key `_forge_depends_on`, wired through the established array-meta paths in
[`includes/class-rest-api.php`](../../../includes/class-rest-api.php):

- **Read:** add `'dependsOn' => self::meta_array( $p->ID, '_forge_depends_on' )` to each
  `shape_*` function (feature, subitem, bug, feedback, release).
- **Write:** add `'dependsOn' => '_forge_depends_on'` to the array-meta map (~line 699) so it
  is sanitised with `array_map( 'sanitize_text_field', ... )` like the other ID arrays.

### Persistence (standalone mock)

Mirror the read/write in [`src/app/api/mockBackend.ts`](../../../src/app/api/mockBackend.ts) so
`npm run dev` round-trips `dependsOn` like any other field. Add a couple of sample
dependencies to [`src/app/data/sampleData.ts`](../../../src/app/data/sampleData.ts) for visual
testing.

## Helpers

New module [`src/app/utils/dependencies.ts`](../../../src/app/utils/dependencies.ts):

```ts
// "Done" varies by type, matching how the app already reads completion.
isItemComplete(item): boolean
//   feature | subitem  → workflowStage === 'deployed'
//   bug                → bugStatus === 'resolved'
//   feedback           → status === 'resolved'
//   release            → status === 'complete'

getBlockingDependencies(item, allItems): Item[]  // incomplete items in item.dependsOn
getDependents(item, allItems): Item[]            // items whose dependsOn includes item.id ("blocks")
hasUnresolvedBlockers(item, allItems): boolean   // getBlockingDependencies(...).length > 0
```

These are pure functions over the item arrays already available from `useDataStore`
(`selectAllItems` exists). No new store state.

## UI

### 1. Detail modal — [`DetailModal.tsx`](../../../src/app/components/DetailModal.tsx)

A new **Dependencies** `Section`, rendered for every item type.

- **View mode:**
  - A persistent amber warning banner when `getBlockingDependencies(item)` is non-empty:
    *"Blocked by N unresolved item(s)"*.
  - **Depends on** list — each row shows the item's type badge, title, and a ✓ (complete) or
    ⚠ (incomplete) marker; clicking opens that item in the modal (`openModal`).
  - **Blocks** list — derived read-only via `getDependents`, same row style.
  - Section hidden entirely when both lists are empty and not editing.
- **Edit mode:** a searchable, any-type multi-select picker modelled on the existing
  `renderReleaseItemPicker` (search box + scrollable list of all items with type-coloured
  badges and add/remove toggles), excluding the item itself. Selection is held in
  `editForm.dependsOn` and saved through the existing `handleSave` → `updateItem` flow (no
  special-casing needed — it is just another array field on `editForm`).

### 2. Kanban card badges — [`ItemCard.tsx`](../../../src/app/components/ItemCard.tsx)

In each card's meta row:

- A `Link2` badge with the dependency count when `dependsOn?.length > 0`
  (e.g. "2 deps"), consistent with the existing sub-item / linked badges.
- A red **"Blocked"** pill when `hasUnresolvedBlockers(item, allItems)` is true.

`ItemCard` already subscribes to the store; it will read the item arrays to compute these.

### 3. Gantt timeline — [`GanttTimeline.tsx`](../../../src/app/components/GanttTimeline.tsx)

**Constraint:** every item bar inherits its release's `leftPct` / `widthPct`, so all items in
a release overlap horizontally. Diagonal dependency arrows would be geometrically misleading,
so we use lighter cues:

- A small **blocked indicator** (e.g. an `AlertCircle` / link glyph) rendered on an `InlineBar`
  when that item has unresolved blockers.
- **On row click**, highlight the rows of that item's dependencies and dependents (subtle
  background tint), and draw thin connector lines between the highlighted rows' bars. Highlight
  clears on the next click / deselect.

This is additive to `InlineBar` / `ReleaseGroup`; no change to timeline date math.

### 4. Kanban warn-on-conflict — [`KanbanBoard.tsx`](../../../src/app/components/KanbanBoard.tsx)

Kanban columns are user-configurable stages with no fixed "done", so the warning is scoped to
the unambiguous case: when a **blocked** item (`hasUnresolvedBlockers`) is dragged into the
**last configured stage**, show a small inline warning/confirm. The move still proceeds (warn,
not enforce) — there is no toast system, so the warning is a brief inline element on the card or
column, then auto-dismisses.

## Data flow

1. User edits an item's "Depends on" list in the Detail modal → `editForm.dependsOn` →
   `handleSave` → `updateItem('<type>', id, { dependsOn })` → REST writes `_forge_depends_on`
   (or mock in standalone) → store patched via `patchItem`.
2. All visual surfaces (badges, banners, Gantt cues) recompute from the helper functions over
   the current store arrays — no derived state to keep in sync, so adding/removing a dependency
   updates both directions everywhere on the next render.

## Error handling

- Reads tolerate a missing `dependsOn` (treated as `[]`).
- Stale references (a dependency whose target was archived/deleted) are simply filtered out by
  the helpers when the target id is not found in `allItems` — no error surfaced.
- Save failures reuse the existing modal behaviour (stay in edit mode on error).

## Testing

- **Unit (helpers):** `isItemComplete` per type; `getBlockingDependencies` /
  `getDependents` including the stale-reference filter and self-exclusion.
- **Manual (`npm run dev`, sample data):** add a dependency in the modal, confirm the banner +
  card "Blocked" pill appear, confirm the derived "Blocks" list shows on the target, mark the
  blocker complete and confirm the warning clears, confirm the Gantt indicator + row highlight,
  and confirm the Kanban last-stage drag warning.
- **Required checks:** `npm run lint`, `npm run build`.

## Affected files

- `src/app/types.ts` — `dependsOn` field.
- `src/app/utils/dependencies.ts` — new helpers.
- `src/app/components/DetailModal.tsx` — Dependencies section + picker + banner.
- `src/app/components/ItemCard.tsx` — count badge + Blocked pill.
- `src/app/components/GanttTimeline.tsx` — blocked indicator + row highlight.
- `src/app/components/KanbanBoard.tsx` — last-stage drag warning.
- `src/app/api/mockBackend.ts`, `src/app/data/sampleData.ts` — standalone round-trip + samples.
- `includes/class-rest-api.php` — read/write `_forge_depends_on`.
- Version bump in `forge-project-management.php` + `package.json`.
