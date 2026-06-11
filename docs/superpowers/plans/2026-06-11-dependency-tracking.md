# Dependency Tracking Between Items — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let any item declare directional "blocked by" dependencies on any other item, and surface those relationships (and unresolved-blocker warnings) in the Detail modal, Kanban cards, the Gantt timeline, and on Kanban drag.

**Architecture:** A single `dependsOn: string[]` array per item stores the IDs that block it; the reverse ("blocks") direction is derived at render time by pure helper functions. Persistence reuses the existing array-meta pattern (`_forge_depends_on`) in the WordPress REST layer; the standalone mock backend round-trips it automatically via its generic patch. All UI surfaces recompute from helpers over the live Zustand store — no new store state, no dual-write.

**Tech Stack:** React 18 + TypeScript, Zustand v5 (`useShallow` for derived selectors), Vite, Tailwind v4, lucide-react icons, WordPress PHP REST API. No unit-test runner is configured; the project's required checks are `npm run lint` and `npm run build`, with visual confirmation via `npm run dev`.

**Testing note:** This repo has no vitest/jest harness, and CLAUDE.md forbids adding frameworks without asking. Each task is therefore verified with `npm run build` (type/compile correctness) + `npm run lint`, and the feature is verified end-to-end with the manual checklist in Task 8 using `npm run dev` sample data. Helper functions are written as pure, side-effect-free functions so a runner can be added later without rework.

**Branch:** `feature/dependency-tracking-42` (already created; the design spec is committed here).

---

## File Structure

- `src/app/types.ts` — add `dependsOn?: string[]` to each item interface. *(modify)*
- `src/app/utils/dependencies.ts` — pure helpers: completeness, blocking deps, dependents, display name. *(create)*
- `src/app/data/sampleData.ts` — add a couple of `dependsOn` links for visual testing. *(modify)*
- `includes/class-rest-api.php` — read `dependsOn` in each `shape_*`; write it via the array-meta map. *(modify)*
- `src/app/components/DetailModal.tsx` — Dependencies section: warning banner, view lists, edit picker. *(modify)*
- `src/app/components/ItemCard.tsx` — dependency-count badge + "Blocked" pill. *(modify)*
- `src/app/components/GanttTimeline.tsx` — blocked indicator on bars + on-click related-row highlight. *(modify)*
- `src/app/components/KanbanBoard.tsx` — transient warning when a blocked item is dropped into the last stage. *(modify)*
- `forge-project-management.php` + `package.json` — version bump. *(modify)*

---

## Task 1: Data model — `dependsOn` field + sample data

**Files:**
- Modify: `src/app/types.ts`
- Modify: `src/app/data/sampleData.ts`

- [ ] **Step 1: Add `dependsOn` to each item interface**

In `src/app/types.ts`, add the field to `Feature`, `SubItem`, `Bug`, `Feedback`, and `Release`. Place it next to the other optional reference fields (e.g. after `links?: ItemLink[];` in each interface). The exact line to add in all five interfaces:

```ts
  /** IDs of items that block this item ("blocked by"). The reverse ("blocks") is derived. */
  dependsOn?: string[];
```

- [ ] **Step 2: Seed sample dependencies**

In `src/app/data/sampleData.ts`, find two existing sample features (note their `id` values, e.g. the first two entries of `sampleFeatures`). Add `dependsOn: [ '<id-of-first-feature>' ]` to the **second** feature object so it is blocked by the first, and add `dependsOn: [ '<id-of-second-feature>' ]` to one sample bug so a cross-type dependency exists. Use the actual ids present in the file — open it, read the ids, and reference them literally. Do not invent ids.

- [ ] **Step 3: Verify build + lint**

Run: `npm run build`
Expected: completes with no TypeScript errors.
Run: `npm run lint`
Expected: no new errors introduced by this change.

- [ ] **Step 4: Commit**

```bash
git add src/app/types.ts src/app/data/sampleData.ts
git commit -m "Add dependsOn field to item types + sample data (#42)"
```

---

## Task 2: Dependency helper functions

**Files:**
- Create: `src/app/utils/dependencies.ts`

- [ ] **Step 1: Create the helpers module**

Create `src/app/utils/dependencies.ts` with this exact content:

```ts
import { Item } from '../types';

/**
 * Whether an item counts as "done" for dependency purposes. Mirrors how the rest
 * of the app reads completion per type (the Gantt treats 'deployed' as done, etc.).
 */
export function isItemComplete( item: Item ): boolean {
  switch ( item.type ) {
    case 'feature':
    case 'subitem':  return item.workflowStage === 'deployed';
    case 'bug':      return item.bugStatus === 'resolved';
    case 'feedback': return item.status === 'resolved';
    case 'release':  return item.status === 'complete';
    default:         return false;
  }
}

/** The IDs this item is blocked by (safe against a missing field). */
export function getDependencyIds( item: Item ): string[] {
  return ( item as { dependsOn?: string[] } ).dependsOn ?? [];
}

/** Resolve this item's "blocked by" IDs to items, dropping stale/deleted references. */
export function getDependencies( item: Item, allItems: Item[] ): Item[] {
  const ids = new Set( getDependencyIds( item ) );
  return allItems.filter( i => ids.has( i.id ) );
}

/** This item's blockers that are not yet complete. */
export function getBlockingDependencies( item: Item, allItems: Item[] ): Item[] {
  return getDependencies( item, allItems ).filter( i => ! isItemComplete( i ) );
}

/** Items that list this item in their dependsOn — i.e. the items this one blocks. */
export function getDependents( item: Item, allItems: Item[] ): Item[] {
  return allItems.filter( i => getDependencyIds( i ).includes( item.id ) );
}

/** True when at least one blocker is unresolved. */
export function hasUnresolvedBlockers( item: Item, allItems: Item[] ): boolean {
  return getBlockingDependencies( item, allItems ).length > 0;
}

/** Display label for an item (features/sub-items/releases use name; bugs/feedback use title). */
export function itemDisplayName( item: Item ): string {
  return 'name' in item ? item.name : ( item as { title: string } ).title;
}
```

- [ ] **Step 2: Verify build + lint**

Run: `npm run build`
Expected: completes with no TypeScript errors (the `switch` narrows the union so `item.bugStatus` / `item.status` / `item.workflowStage` type-check).
Run: `npm run lint`
Expected: no errors.

- [ ] **Step 3: Commit**

```bash
git add src/app/utils/dependencies.ts
git commit -m "Add dependency helper functions (#42)"
```

---

## Task 3: WordPress REST persistence

**Files:**
- Modify: `includes/class-rest-api.php`

The standalone mock backend (`src/app/api/mockBackend.ts`) already round-trips `dependsOn` via its generic `patchArr` in `updateItem`, so no mock change is needed. Only the live WordPress read/write paths need the new meta key.

- [ ] **Step 1: Read `dependsOn` in each shape function**

In `includes/class-rest-api.php`, add this line to **each** of `shape_feature`, `shape_subitem`, `shape_bug`, `shape_feedback`, and `shape_release` (place it alongside the other `meta_array` reads such as `subItemIds` / `linkedFeatureIds`):

```php
			'dependsOn'       => self::meta_array( $p->ID, '_forge_depends_on' ),
```

- [ ] **Step 2: Write `dependsOn` via the array-meta map**

In the update handler's `$array_meta` map (around line 698), add the new entry:

```php
		$array_meta = [
			'subItemIds'          => '_forge_subitem_ids',
			'linkedFeatureIds'    => '_forge_linked_feature_ids',
			'linkedBugIds'        => '_forge_linked_bug_ids',
			'linkedFeedbackIds'   => '_forge_linked_feedback_ids',
			'dependsOn'           => '_forge_depends_on',
			'images'              => '_forge_image_urls',
			'brands'              => '_forge_brands',
		];
```

This reuses the existing `array_map( 'sanitize_text_field', ... )` write loop, so each id is sanitised like the other id arrays.

- [ ] **Step 3: Verify PHP lints**

Run: `php -l includes/class-rest-api.php`
Expected: `No syntax errors detected`.
(If `php` is unavailable on the machine, skip this and rely on the JS build; the change is a literal array entry + read line mirroring established patterns.)

- [ ] **Step 4: Commit**

```bash
git add includes/class-rest-api.php
git commit -m "Persist dependsOn via REST array meta (#42)"
```

---

## Task 4: Detail modal — Dependencies section

**Files:**
- Modify: `src/app/components/DetailModal.tsx`

The component already spreads `{ ...item }` into `editForm`, so `editForm.dependsOn` is populated automatically and saved by the existing `handleSave` → `updateItem` flow (no save changes needed). We add a searchable picker (edit), view lists, and a warning banner.

- [ ] **Step 1: Add imports**

At the top of `src/app/components/DetailModal.tsx`, add the helper imports (place after the existing util imports near `buildShareUrl`):

```ts
import { getDependencies, getBlockingDependencies, getDependents, isItemComplete, itemDisplayName } from '../utils/dependencies';
```

Add `AlertTriangle` to the existing `lucide-react` import list (the others — `Link2`, `X`, `Plus`, `Check`, `CheckCircle`, `Circle` — are already imported).

- [ ] **Step 2: Add picker search state**

Next to the existing `const [itemSearch, setItemSearch] = useState( '' );`, add:

```ts
  const [depSearch, setDepSearch] = useState( '' );
```

And reset it in the `useEffect` that runs on `item` change, alongside `setItemSearch( '' );`:

```ts
      setDepSearch( '' );
```

- [ ] **Step 3: Add the `renderDependenciesSection` function**

Add this function inside the component, just before `const renderFeatureDetails = ( feature: Feature ) => {`:

```tsx
  const renderDependenciesSection = () => {
    const dependsOn   = ( editForm.dependsOn as string[] | undefined ) ?? ( item as { dependsOn?: string[] } ).dependsOn ?? [];
    const blockers    = getBlockingDependencies( item, allItems );
    const dependsList = getDependencies( item, allItems );
    const dependents  = getDependents( item, allItems );

    const typeColors: Record<string, string> = {
      feature:  'bg-blue-50 text-blue-700 border-blue-200',
      subitem:  'bg-cyan-50 text-cyan-700 border-cyan-200',
      bug:      'bg-red-50 text-red-700 border-red-200',
      feedback: 'bg-purple-50 text-purple-700 border-purple-200',
      release:  'bg-emerald-50 text-emerald-700 border-emerald-200',
    };

    const depRow = ( dep: Item ) => {
      const complete = isItemComplete( dep );
      return (
        <button key={ dep.id } onClick={ () => useUIStore.getState().openModal( dep ) }
          className="w-full flex items-center gap-3 p-2.5 rounded-lg border border-border hover:bg-accent/50 transition-colors text-left">
          <span className={ `px-1.5 py-0.5 text-[10px] font-semibold rounded border flex-shrink-0 ${ typeColors[dep.type] }` }>{ dep.type }</span>
          <span className="flex-1 text-sm font-medium text-foreground truncate">{ itemDisplayName( dep ) }</span>
          { complete
            ? <CheckCircle className="w-4 h-4 text-green-600 flex-shrink-0" />
            : <Circle className="w-4 h-4 text-amber-500 flex-shrink-0" /> }
        </button>
      );
    };

    // Edit mode: searchable any-type picker (excludes self)
    if ( isEditing ) {
      const candidates = allItems.filter( i => i.id !== item.id );
      const filtered = depSearch
        ? candidates.filter( i => itemDisplayName( i ).toLowerCase().includes( depSearch.toLowerCase() ) )
        : candidates;
      const selected = new Set( dependsOn );
      const sorted = [ ...filtered ].sort( ( a, b ) => {
        const aS = selected.has( a.id ), bS = selected.has( b.id );
        if ( aS !== bS ) return aS ? -1 : 1;
        return itemDisplayName( a ).localeCompare( itemDisplayName( b ) );
      } );
      const toggle = ( id: string ) => {
        const next = selected.has( id ) ? dependsOn.filter( x => x !== id ) : [ ...dependsOn, id ];
        setEditForm( { ...editForm, dependsOn: next } );
      };
      return (
        <Section title={ <><Link2 className="w-4 h-4" /> Depends On ({ dependsOn.length })</> }>
          <p className="text-xs text-muted-foreground mb-2">Items that must be done before this one. This item is "blocked by" them.</p>
          <input type="text" placeholder="Search all items…" value={ depSearch } onChange={ e => setDepSearch( e.target.value ) }
            className="w-full text-sm px-3 py-2 border border-input rounded-lg outline-none focus:border-primary focus:ring-1 focus:ring-primary mb-2" />
          <div className="border border-border rounded-lg divide-y divide-border" style={{ maxHeight: 320, overflowY: 'auto' }}>
            { sorted.length === 0 ? (
              <div className="px-3 py-4 text-sm text-muted-foreground text-center">No items found</div>
            ) : sorted.map( it => {
              const isSel = selected.has( it.id );
              return (
                <div key={ it.id } className={ `flex items-center gap-3 px-3 py-2 transition-colors ${ isSel ? 'bg-amber-50/60' : 'hover:bg-accent/50' }` }>
                  <span className={ `px-1.5 py-0.5 text-[10px] font-semibold rounded border flex-shrink-0 ${ typeColors[it.type] }` }>{ it.type }</span>
                  <span className={ `flex-1 text-sm truncate ${ isSel ? 'font-medium text-foreground' : 'text-muted-foreground' }` }>{ itemDisplayName( it ) }</span>
                  <button onClick={ () => toggle( it.id ) }
                    className={ `flex-shrink-0 w-6 h-6 flex items-center justify-center rounded transition-colors ${ isSel ? 'text-red-500 hover:bg-red-50' : 'text-amber-600 hover:bg-amber-50' }` }>
                    { isSel ? <X className="w-3.5 h-3.5" /> : <Plus className="w-3.5 h-3.5" /> }
                  </button>
                </div>
              );
            } ) }
          </div>
        </Section>
      );
    }

    // View mode
    if ( dependsList.length === 0 && dependents.length === 0 ) return null;
    return (
      <Section title={ <><Link2 className="w-4 h-4" /> Dependencies</> }>
        { blockers.length > 0 && (
          <div className="flex items-start gap-2 p-3 mb-3 rounded-lg bg-amber-50 border border-amber-200">
            <AlertTriangle className="w-4 h-4 text-amber-600 flex-shrink-0 mt-0.5" />
            <span className="text-sm font-medium text-amber-800">Blocked by { blockers.length } unresolved item{ blockers.length !== 1 ? 's' : '' }.</span>
          </div>
        ) }
        { dependsList.length > 0 && (
          <div className="mb-3">
            <div className="text-xs font-medium text-muted-foreground mb-1.5">Depends on</div>
            <div className="space-y-2">{ dependsList.map( depRow ) }</div>
          </div>
        ) }
        { dependents.length > 0 && (
          <div>
            <div className="text-xs font-medium text-muted-foreground mb-1.5">Blocks</div>
            <div className="space-y-2">{ dependents.map( depRow ) }</div>
          </div>
        ) }
      </Section>
    );
  };
```

- [ ] **Step 4: Render the section in each item type's detail body**

In each of `renderFeatureDetails`, `renderSubItemDetails`, `renderBugDetails`, `renderFeedbackDetails`, and `renderReleaseDetails`, add `{ renderDependenciesSection() }` immediately before the closing `</>` of the returned fragment (after the existing `{ renderLinksSection() }` where present). For example, in `renderFeatureDetails` place it right after the `renderLinksSection()` / sub-items block and before `</>`.

- [ ] **Step 5: Verify build + lint**

Run: `npm run build`
Expected: no TypeScript errors. (`allItems`, `useUIStore`, `Item`, `Section`, `setEditForm`, `editForm`, `isEditing` are all already in scope in this file.)
Run: `npm run lint`
Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add src/app/components/DetailModal.tsx
git commit -m "Add Dependencies section to detail modal (#42)"
```

---

## Task 5: Kanban card badges

**Files:**
- Modify: `src/app/components/ItemCard.tsx`

- [ ] **Step 1: Add imports + derived data**

In `src/app/components/ItemCard.tsx`:
- Add to the `lucide-react` import: `AlertTriangle` (keep `Link2`, already imported).
- Add these imports:

```ts
import { useShallow } from 'zustand/react/shallow';
import { selectAllItems } from '../store/useDataStore';
import { getDependencyIds, hasUnresolvedBlockers } from '../utils/dependencies';
```

Inside the component, after `const releases = useDataStore( s => s.releases );`, add:

```ts
  const allItems = useDataStore( useShallow( selectAllItems ) );
  const depCount = getDependencyIds( item ).length;
  const blocked  = hasUnresolvedBlockers( item, allItems );
```

> Note (per CLAUDE.md): `selectAllItems` returns a new array each call, so it MUST be wrapped in `useShallow` to avoid the React #185 infinite-loop.

- [ ] **Step 2: Render the badges**

In the JSX returned by the component, inside the outer card `<div className="flex-1 min-w-0">`, render a dependency row directly **after** the type-specific render call and before the closing `</div>`. Insert:

```tsx
          { ( depCount > 0 || blocked ) && (
            <div className="flex items-center gap-2 mt-2 pt-2 border-t border-border">
              { depCount > 0 && (
                <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                  <Link2 className="w-3 h-3" />{ depCount } dep{ depCount !== 1 ? 's' : '' }
                </span>
              ) }
              { blocked && (
                <span className="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium rounded border bg-red-100 text-red-700 border-red-300">
                  <AlertTriangle className="w-3 h-3" />Blocked
                </span>
              ) }
            </div>
          ) }
```

- [ ] **Step 3: Verify build + lint**

Run: `npm run build`
Expected: no TypeScript errors.
Run: `npm run lint`
Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add src/app/components/ItemCard.tsx
git commit -m "Add dependency count + Blocked badge to cards (#42)"
```

---

## Task 6: Gantt timeline — blocked indicator + related-row highlight

**Files:**
- Modify: `src/app/components/GanttTimeline.tsx`

- [ ] **Step 1: Add a `blocked` prop to `InlineBar`**

In `InlineBarProps`, add `blocked?: boolean;`. In the `InlineBar` function signature destructure, add `blocked = false`. Inside the bar, render a small indicator before the hours span. Replace the existing hours `<span>` block so the right side becomes:

```tsx
        <span style={{ display: 'flex', alignItems: 'center', gap: 4, flexShrink: 0 }}>
          { blocked && <AlertCircle size={ 11 } style={{ color: '#dc2626' }} aria-label="Blocked" /> }
          <span style={{ opacity: 0.6, whiteSpace: 'nowrap', fontSize: 10, fontWeight: 600 }}>{ hours }h</span>
        </span>
```

(`AlertCircle` is already imported in this file.)

- [ ] **Step 2: Add highlight + blocked state in the main component**

In `GanttTimeline`, add imports near the other util imports:

```ts
import { useShallow } from 'zustand/react/shallow';
import { selectAllItems } from '../store/useDataStore';
import { getDependencies, getDependents, hasUnresolvedBlockers } from '../utils/dependencies';
```

Inside `GanttTimeline`, after the existing `useDataStore` selectors, add:

```ts
  const allItems = useDataStore( useShallow( selectAllItems ) );
  const [highlightId, setHighlightId] = useState<string | null>( null );

  const highlightedIds = useMemo( () => {
    if ( ! highlightId ) return new Set<string>();
    const target = allItems.find( i => i.id === highlightId );
    if ( ! target ) return new Set<string>();
    const related = [ ...getDependencies( target, allItems ), ...getDependents( target, allItems ) ];
    return new Set( related.map( i => i.id ) );
  }, [ highlightId, allItems ] );
```

- [ ] **Step 3: Make a bar click both open the modal and set the highlight**

Define a handler in `GanttTimeline` and pass it down. After `const openModal = useUIStore( s => s.openModal );`, add:

```ts
  const handleItemClick = useCallback( ( clicked: Item ) => {
    setHighlightId( prev => prev === clicked.id ? null : clicked.id );
    openModal( clicked );
  }, [ openModal ] );
```

Change the `<ReleaseGroup ... onItemClick={ openModal } ... />` prop to `onItemClick={ handleItemClick }`.

- [ ] **Step 4: Thread `allItems` + `highlightedIds` into `ReleaseGroup`**

Add to `ReleaseGroupProps`:

```ts
  allItems: Item[];
  highlightedIds: Set<string>;
```

Add `allItems` and `highlightedIds` to the destructured props of the `ReleaseGroup` function, and pass them in the JSX where `ReleaseGroup` is rendered:

```tsx
                allItems={ allItems }
                highlightedIds={ highlightedIds }
```

- [ ] **Step 5: Use them on each bar/row**

For each `<InlineBar ... />` (feature, sub-item, nested bug/feedback, top-level bug, top-level feedback), add a `blocked` prop computed from the item, e.g. for the feature bar:

```tsx
                  <InlineBar label={ feature.name } hours={ feature.timeEstimate } color={ C.feature } leftPct={ leftPct } widthPct={ widthPct } onClick={ () => onItemClick( feature ) } blocked={ hasUnresolvedBlockers( feature, allItems ) } />
```

Apply the analogous `blocked={ hasUnresolvedBlockers( <item>, allItems ) }` to the sub-item bar (`si`), nested `child` bar, top-level `bug` bar, and top-level `fb` bar. Import `hasUnresolvedBlockers` is shared — since `ReleaseGroup` is in the same file, add it to the same import added in Step 2 (already done).

For the row highlight tint, on each item **row** container (`childRowStyle` rows for feature/sub-item/child/bug/feedback), add a conditional background when the item is highlighted. The simplest reliable approach: wrap the row's `style` with a highlight tint. For the feature row, change its bar-area `<div style={{ ...barArea, backgroundColor: '#eff6ff1a' }}>` to:

```tsx
                <div style={{ ...barArea, backgroundColor: highlightedIds.has( feature.id ) ? '#fef9c3' : '#eff6ff1a' }}>
```

Apply the same pattern to the other rows, substituting the correct id (`si.id`, `child.id`, `bug.id`, `fb.id`) and keeping each row's existing default background as the `else` value.

- [ ] **Step 6: Verify build + lint**

Run: `npm run build`
Expected: no TypeScript errors. (`Item` is already imported in this file; `useState`, `useMemo`, `useCallback` are already imported.)
Run: `npm run lint`
Expected: no errors.

- [ ] **Step 7: Commit**

```bash
git add src/app/components/GanttTimeline.tsx
git commit -m "Add Gantt blocked indicator + related-row highlight (#42)"
```

---

## Task 7: Kanban warn-on-conflict on drag

**Files:**
- Modify: `src/app/components/KanbanBoard.tsx`

The board uses a deferred-save model: `handleDrop` stages a pending change. We surface a transient warning when a blocked item is dropped into the **last configured stage**.

- [ ] **Step 1: Add imports + helper data**

In `src/app/components/KanbanBoard.tsx`:
- Add to the `lucide-react` import: `AlertTriangle`.
- Add:

```ts
import { hasUnresolvedBlockers, itemDisplayName } from '../utils/dependencies';
```

Inside the component (the desktop/main body where `storeAllItems`, `settings`, and `handleDrop` live), add state near the other `useState` hooks:

```ts
  const [depWarning, setDepWarning] = useState<string | null>( null );
```

- [ ] **Step 2: Detect the conflict in `handleDrop`**

Replace the existing `handleDrop` with:

```ts
  const handleDrop = ( itemId: string, itemType: string, newStage: WorkflowStage ) => {
    const lastStageId = settings.statuses.length > 0
      ? settings.statuses[ settings.statuses.length - 1 ].id
      : undefined;
    if ( newStage === lastStageId ) {
      const dropped = storeAllItems.find( i => i.id === itemId );
      if ( dropped && hasUnresolvedBlockers( dropped, storeAllItems ) ) {
        setDepWarning( `"${ itemDisplayName( dropped ) }" still has unresolved dependencies.` );
        window.setTimeout( () => setDepWarning( null ), 4000 );
      }
    }
    setPendingStages( ( prev ) => {
      const filtered = prev.filter( ( p ) => p.id !== itemId );
      return [...filtered, { id: itemId, type: itemType, stage: newStage }];
    } );
  };
```

> The move still proceeds — this only warns. `storeAllItems` and `settings` are already in scope in this component.

- [ ] **Step 3: Render the transient banner**

In the desktop board JSX, render the banner at the top of the board container (e.g. just inside the top-level wrapper, after the header bar). Insert:

```tsx
      { depWarning && (
        <div className="flex items-center gap-2 mx-4 mt-2 px-3 py-2 rounded-lg bg-amber-50 border border-amber-200 text-sm text-amber-800">
          <AlertTriangle className="w-4 h-4 flex-shrink-0" />
          <span className="flex-1">{ depWarning }</span>
          <button onClick={ () => setDepWarning( null ) } className="text-amber-600 hover:text-amber-800">✕</button>
        </div>
      ) }
```

If the component renders distinct mobile and desktop trees, place the banner in the desktop tree (mobile has no DnD, so the warning is not reachable there).

- [ ] **Step 4: Verify build + lint**

Run: `npm run build`
Expected: no TypeScript errors.
Run: `npm run lint`
Expected: no errors.

- [ ] **Step 5: Commit**

```bash
git add src/app/components/KanbanBoard.tsx
git commit -m "Warn when a blocked item is dragged to the final stage (#42)"
```

---

## Task 8: Version bump, final checks, and manual verification

**Files:**
- Modify: `forge-project-management.php`
- Modify: `package.json`

- [ ] **Step 1: Bump the version (minor — new feature)**

Current version is `1.34.0`. Bump to `1.35.0` in **both** files (they must stay in sync):
- `package.json` → `"version": "1.35.0"`
- `forge-project-management.php` → the `Version:` plugin header line **and** the `FORGE_PM_VERSION` constant → `1.35.0`

- [ ] **Step 2: Run the required checks**

Run: `npm run lint`
Expected: no errors. (Per CLAUDE.md: do not auto-fix in a loop — if there are findings, list them for the user to decide.)
Run: `npm run build`
Expected: builds cleanly.

- [ ] **Step 3: Manual verification (`npm run dev`)**

Run: `npm run dev` and confirm in the browser:
1. Open an item that has a sample dependency → the Detail modal shows the "Dependencies" section with a "Depends on" list and the amber "Blocked by N unresolved item(s)" banner.
2. Open the blocker item → it shows the dependent under "Blocks".
3. Edit an item → search and add/remove a dependency → save → reopen and confirm it persisted, and the dependent's "Blocks" list updates.
4. On the Kanban board, the blocked item's card shows the "Blocked" pill and a "N deps" badge.
5. Mark the blocker complete (feature → `Deployed`, bug → `Resolved`, etc.) → the banner and "Blocked" pill clear.
6. On the Gantt, a blocked item's bar shows the red indicator; clicking a bar tints its related rows.
7. On the Kanban board, drag a blocked item into the last column → the transient amber warning appears and the move still goes through.

- [ ] **Step 4: Commit the version bump**

```bash
git add forge-project-management.php package.json
git commit -m "Bump version to 1.35.0 for dependency tracking (#42)"
```

- [ ] **Step 5: Open a draft PR**

Push the branch and open a **draft** PR linked to issue #42 (do not merge). The PR description must include: issue link, summary of changes, files changed, test results (`npm run lint` / `npm run build` output), review notes, and anything still requiring human review (e.g. live-WordPress persistence of `_forge_depends_on` can only be verified on a real WP install). Leave it for human review.

---

## Self-Review

**Spec coverage:**
- Directional blocks/blocked-by, any-to-any → Task 1 (`dependsOn` on all five types) + Task 2 helpers. ✓
- Persistence (WP + mock) → Task 3 (WP read/write); mock is generic (noted in Task 3). ✓
- Detail modal (banner, depends-on list, derived blocks list, edit picker) → Task 4. ✓
- Card badges (count + Blocked) → Task 5. ✓
- Gantt (blocked indicator + related-row highlight; no connector lines) → Task 6, matches updated spec. ✓
- Kanban warn-on-conflict (last stage, non-blocking) → Task 7. ✓
- `isItemComplete` per-type definition → Task 2. ✓
- Version bump + checks + manual verification + draft PR → Task 8. ✓
- Out-of-scope items (cycle detection beyond self-exclude, auto-scheduling, graph view, hard enforcement) → not implemented. ✓

**Placeholder scan:** No TBD/TODO; all code steps include complete code. The only deliberately deferred literals are the sample-data ids in Task 1 Step 2, which instruct the engineer to read the actual ids from the file (they are not knowable without opening it). ✓

**Type consistency:** Helper names (`isItemComplete`, `getDependencies`, `getBlockingDependencies`, `getDependents`, `hasUnresolvedBlockers`, `getDependencyIds`, `itemDisplayName`) are used identically across Tasks 4–7. The field `dependsOn` is referenced consistently. The `InlineBar` `blocked` prop and `ReleaseGroup` `allItems` / `highlightedIds` props are defined in Task 6 before use. ✓
