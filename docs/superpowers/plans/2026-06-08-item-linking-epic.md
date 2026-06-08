# Item-Linking Epic Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add labeled external links to all item types (#24), let bugs/feedback link "up" to a Feature *or* Sub-Item (#22), and make features/sub-items/bugs/feedback nest only when they share the same release (Gantt) / column (Kanban), else render independently (#21).

**Architecture:** Extend the existing single-parent data model (no join table). New `ItemLink {label,url}` stored as JSON post-meta `_forge_links`, with read-time migration from the legacy scalar `_forge_urls`. A new `linkedSubItemId` scalar meta on Bug/Feedback. A single shared `getNestingParentId()` helper drives nesting in both Kanban and Gantt, gated by same-release/same-column.

**Tech Stack:** React 18 + TypeScript (Vite), Zustand v5 store, WordPress REST API (PHP). **No unit-test framework** — per `CLAUDE.md` the verification gates are `npm run lint`, `npm run build`, and visual confirmation via `npm run dev`. Each phase ends with those gates + a commit.

**Branch:** `feature/22-21-24-item-linking` (already created; the design spec is committed there).

---

## File Structure

**New files:**
- `src/app/utils/nesting.ts` — pure `getNestingParentId(item)` helper, imported by Kanban + Gantt. *(New `utils/` dir — confirm during plan review.)*
- `src/app/components/LinksField.tsx` — shared links editor (edit mode) + links display (read mode), used by AddItemModal and DetailModal.

**Modified files:**
- `src/app/types.ts` — `ItemLink`, `links?` on all item types, `linkedSubItemId?` on Bug/Feedback.
- `includes/class-rest-api.php` — read shapes, both save maps, `_forge_links` JSON + migration, `strip_sensitive`.
- `src/app/components/KanbanBoard.tsx` — nesting via helper + same-release gate (desktop + mobile).
- `src/app/components/GanttTimeline.tsx` — sub-item nests only when same release; bugs/feedback nest under linked parent when same release.
- `src/app/components/AddItemModal.tsx` — parent picker (Features + Sub-Items) for bug/feedback; links editor.
- `src/app/components/DetailModal.tsx` — parent picker; links editor/display; remove legacy "Related URLs".

Each phase is independently buildable and commit-able. Implement in order: data model → nesting → links UI.

---

## Phase 1 — Data model & persistence

### Task 1: Add link types & fields to `types.ts`

**Files:**
- Modify: `src/app/types.ts`

- [ ] **Step 1: Add the `ItemLink` type** above `interface Feature` (after line 9, the `Priority` type):

```ts
export interface ItemLink {
  label: string;
  url: string;
}
```

- [ ] **Step 2: Add `links?` to `Feature`** (inside `interface Feature`, after `changeLog?: string;`):

```ts
  links?: ItemLink[];
```

- [ ] **Step 3: Add `links?` to `SubItem`** (after `brands?: string[];` in `interface SubItem`):

```ts
  links?: ItemLink[];
```

- [ ] **Step 4: Add `linkedSubItemId?` and `links?` to `Bug`** (after `urls?: string[];` in `interface Bug`):

```ts
  linkedSubItemId?: string;
  links?: ItemLink[];
```

- [ ] **Step 5: Add `linkedSubItemId?` and `links?` to `Feedback`** (after `urls?: string[];` in `interface Feedback`):

```ts
  linkedSubItemId?: string;
  links?: ItemLink[];
```

- [ ] **Step 6: Add `links?` to `Release`** (after `linkedFeedbackIds: string[];` in `interface Release`):

```ts
  links?: ItemLink[];
```

- [ ] **Step 7: Verify build**

Run: `npm run build`
Expected: PASS (no new TypeScript errors).

---

### Task 2: PHP read — `links` accessor with `_forge_urls` migration

**Files:**
- Modify: `includes/class-rest-api.php`

- [ ] **Step 1: Add a `meta_links` helper** next to the existing `meta_array` helper (search for `private static function meta_array`). Add:

```php
	/**
	 * Read labeled links from _forge_links (JSON). If empty, migrate legacy
	 * scalar _forge_urls entries into { label: url, url } shape.
	 */
	private static function meta_links( int $post_id ): array {
		$raw = get_post_meta( $post_id, '_forge_links', true );
		if ( is_string( $raw ) && $raw !== '' ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				return array_values( array_filter( array_map(
					fn( $l ) => is_array( $l ) && isset( $l['url'] )
						? [ 'label' => (string) ( $l['label'] ?? '' ), 'url' => (string) $l['url'] ]
						: null,
					$decoded
				) ) );
			}
		}
		// Migration fallback: legacy plain URLs become labeled links.
		$urls = self::meta_array( $post_id, '_forge_urls' );
		return array_map( fn( $u ) => [ 'label' => (string) $u, 'url' => (string) $u ], $urls );
	}
```

- [ ] **Step 2: Add `links` to the bug read shape.** Find the bug shape (the block containing `'urls' => self::meta_array( $p->ID, '_forge_urls' ),` near line 246). Add directly below that line:

```php
			'links'           => self::meta_links( $p->ID ),
			'linkedSubItemId' => self::meta( $p->ID, '_forge_linked_subitem_id' ) ?: null,
```

- [ ] **Step 3: Add `links` to the feedback read shape.** Find the feedback shape (the block with `'urls' => self::meta_array( $p->ID, '_forge_urls' ),` near line 266). Add directly below it:

```php
			'links'           => self::meta_links( $p->ID ),
			'linkedSubItemId' => self::meta( $p->ID, '_forge_linked_subitem_id' ) ?: null,
```

- [ ] **Step 4: Add `links` to feature, subitem, and release read shapes.** In each of those three shape blocks (they contain `'images' => self::meta_array( $p->ID, '_forge_image_urls' ),`), add directly below the images line:

```php
			'links'           => self::meta_links( $p->ID ),
```

- [ ] **Step 5: Verify PHP syntax**

Run: `php -l includes/class-rest-api.php`
Expected: `No syntax errors detected`. (If `php` is unavailable on PATH, skip — `npm run build` does not check PHP; rely on the visual test in Step 7 of Task 4.)

---

### Task 3: PHP save — `linkedSubItemId` scalar + `links` JSON (create & update)

**Files:**
- Modify: `includes/class-rest-api.php`

There are **two** save paths with their own scalar maps: the create handler (`$scalar`/meta map near line 397) and the update handler (near line 599). Apply to **both**.

- [ ] **Step 1: Add `linkedSubItemId` to the create scalar-meta map** (near line 398, after `'linkedBugId' => '_forge_linked_bug_id',`):

```php
			'linkedSubItemId'   => '_forge_linked_subitem_id',
```

- [ ] **Step 2: Add `linkedSubItemId` to the update scalar-meta map** (near line 600, after `'linkedBugId' => '_forge_linked_bug_id',`):

```php
			'linkedSubItemId'   => '_forge_linked_subitem_id',
```

- [ ] **Step 3: Add a `links` JSON save block to the create handler.** Find the create handler's `stageDates` block (the `if ( array_key_exists( 'stageDates', $data ) ... )` near line 446). Add directly after its closing brace:

```php
		if ( array_key_exists( 'links', $data ) && is_array( $data['links'] ) ) {
			$clean = [];
			foreach ( $data['links'] as $link ) {
				if ( ! is_array( $link ) || empty( $link['url'] ) ) continue;
				$clean[] = [
					'label' => sanitize_text_field( $link['label'] ?? '' ),
					'url'   => esc_url_raw( $link['url'] ),
				];
			}
			update_post_meta( $post_id, '_forge_links', wp_json_encode( $clean ) );
		}
```

- [ ] **Step 4: Add the identical `links` JSON save block to the update handler.** Find the update handler's `stageDates` block (the second `if ( array_key_exists( 'stageDates', $data ) ... )`, near line 662). Add the **same** block (from Step 3) directly after its closing brace:

```php
		if ( array_key_exists( 'links', $data ) && is_array( $data['links'] ) ) {
			$clean = [];
			foreach ( $data['links'] as $link ) {
				if ( ! is_array( $link ) || empty( $link['url'] ) ) continue;
				$clean[] = [
					'label' => sanitize_text_field( $link['label'] ?? '' ),
					'url'   => esc_url_raw( $link['url'] ),
				];
			}
			update_post_meta( $post_id, '_forge_links', wp_json_encode( $clean ) );
		}
```

- [ ] **Step 5: Verify PHP syntax**

Run: `php -l includes/class-rest-api.php`
Expected: `No syntax errors detected` (or skip if `php` unavailable, per Task 2 Step 5).

---

### Task 4: PHP — expose `links` to non-admins & commit Phase 1

**Files:**
- Modify: `includes/class-rest-api.php`

- [ ] **Step 1: Keep `links` readable for non-admins.** `strip_sensitive` (line 130) removes `urls` for public/non-admin payloads. Links are user-facing, so they must NOT be stripped — confirm `links` is **not** added to the `$private` array. No code change unless a later edit added it; if so, remove it. Leave line 130 as:

```php
		$private = [ 'changeLog', 'notes', 'urls', 'description' ];
```

- [ ] **Step 2: Build**

Run: `npm run build`
Expected: PASS.

- [ ] **Step 3: Visual confirmation**

Run: `npm run dev`, open `localhost:5173`. The app loads with sample data (no WP). Confirm no console errors and the board/Gantt/modals render. (Persistence is WP-only; this step just confirms the type changes didn't break rendering.)

- [ ] **Step 4: Commit Phase 1**

```bash
git add src/app/types.ts includes/class-rest-api.php
git commit -m "Add links + linkedSubItemId data model & persistence (#24, #22)"
```

---

## Phase 2 — Nesting helper & Kanban

### Task 5: Create the shared `getNestingParentId` helper

**Files:**
- Create: `src/app/utils/nesting.ts`

- [ ] **Step 1: Write the helper**

```ts
import { Item } from '../types';

/**
 * The id of the item this item should nest under, or null if top-level.
 * Bug/feedback prefer a linked sub-item, then a linked feature.
 * Sub-items nest under their parent feature.
 */
export function getNestingParentId( item: Item ): string | null {
  if ( item.type === 'subitem' ) return item.parentFeatureId || null;
  if ( item.type === 'bug' || item.type === 'feedback' ) {
    return item.linkedSubItemId || item.linkedFeatureId || null;
  }
  return null;
}
```

- [ ] **Step 2: Verify build**

Run: `npm run build`
Expected: PASS (file compiles; unused-export is fine — it's consumed in Task 6).

---

### Task 6: Kanban — nest via helper, gated by same release

**Files:**
- Modify: `src/app/components/KanbanBoard.tsx`

The nesting loop is duplicated in `KanbanColumn` (lines ~129–141) and `MobileKanbanColumn` (lines ~241–252). Replace both with the helper + a same-release gate.

- [ ] **Step 1: Import the helper.** After line 12 (`import { useDataStore, selectAllItems } ...`), add:

```ts
import { getNestingParentId } from '../utils/nesting';
```

- [ ] **Step 2: Replace the desktop nesting loop** (`KanbanColumn`, the `items.forEach( ( item ) => { ... } )` block at lines ~129–141) with:

```ts
  items.forEach( ( item ) => {
    const parentId = getNestingParentId( item );
    const parent = parentId ? items.find( ( i ) => i.id === parentId ) : undefined;
    const sameRelease = parent
      && ( 'releaseId' in item ? item.releaseId : undefined )
        === ( 'releaseId' in parent ? parent.releaseId : undefined );
    if ( parent && sameRelease ) {
      if ( ! nestedChildren[parentId!] ) nestedChildren[parentId!] = [];
      nestedChildren[parentId!].push( item );
    } else {
      topLevelItems.push( item );
    }
  } );
```

- [ ] **Step 3: Replace the mobile nesting loop** (`MobileKanbanColumn`, the `items.forEach( ( item ) => { ... } )` block at lines ~241–252) with the **same** block from Step 2.

- [ ] **Step 4: Build**

Run: `npm run build`
Expected: PASS.

- [ ] **Step 5: Visual confirmation**

Run: `npm run dev`. In the Kanban board: a sub-item/bug/feedback nests under its parent **only** when both are in the same release group within a column; when their releases differ they appear as separate top-level cards. Verify desktop and (via narrow window) mobile tab view.

- [ ] **Step 6: Commit**

```bash
git add src/app/utils/nesting.ts src/app/components/KanbanBoard.tsx
git commit -m "Nest Kanban items only within the same release; support sub-item parents (#21, #22)"
```

---

## Phase 3 — Gantt nesting

### Task 7: Gantt — same-release sub-item nesting + nest bugs/feedback

**Files:**
- Modify: `src/app/components/GanttTimeline.tsx`

Today `ReleaseGroup` renders each feature then **all** its `subItemIds` children regardless of the sub-item's release (lines ~210–232), and renders bugs/feedback flat (lines ~236–272). Change so: a feature's sub-item renders nested **only if** the sub-item belongs to this release; bugs/feedback nest under their linked feature/sub-item when in this release, else sit at release level.

- [ ] **Step 1: Import the helper.** After line 10 (`import { useUIStore } ...`), add:

```ts
import { getNestingParentId } from '../utils/nesting';
```

- [ ] **Step 2: Gate sub-item rows by release.** In `ReleaseGroup`, the sub-item map currently starts:

```ts
              { ( feature.subItemIds || [] ).map( subItemId => {
                const si = subitems.find( s => s.id === subItemId ) as SubItem | undefined;
                if ( !si ) return null;
```

Replace the guard line with one that also requires the sub-item to be in this release:

```ts
              { ( feature.subItemIds || [] ).map( subItemId => {
                const si = subitems.find( s => s.id === subItemId ) as SubItem | undefined;
                if ( !si || si.releaseId !== release.id ) return null;
```

- [ ] **Step 3: Compute nested vs. top-level bugs/feedback.** Immediately after the three `useMemo` blocks (`linkedFeatures`, `linkedBugs`, `linkedFeedback`, ending ~line 109), add a memo that splits bugs/feedback into those nested under an in-release feature/sub-item vs. release-level:

```ts
  const inReleaseParentIds = useMemo( () => {
    const ids = new Set<string>();
    linkedFeatures.forEach( f => ids.add( f.id ) );
    subitems.forEach( s => { if ( s.releaseId === release.id ) ids.add( s.id ); } );
    return ids;
  }, [ linkedFeatures, subitems, release.id ] );

  const nestedChildren = useMemo( () => {
    const map: Record<string, ( Bug | Feedback )[]> = {};
    [ ...linkedBugs, ...linkedFeedback ].forEach( item => {
      const pid = getNestingParentId( item );
      if ( pid && inReleaseParentIds.has( pid ) ) {
        ( map[pid] ||= [] ).push( item );
      }
    } );
    return map;
  }, [ linkedBugs, linkedFeedback, inReleaseParentIds ] );

  const topLevelBugs     = useMemo( () => linkedBugs.filter(     b => { const p = getNestingParentId( b ); return ! ( p && inReleaseParentIds.has( p ) ); } ), [ linkedBugs, inReleaseParentIds ] );
  const topLevelFeedback = useMemo( () => linkedFeedback.filter( f => { const p = getNestingParentId( f ); return ! ( p && inReleaseParentIds.has( p ) ); } ), [ linkedFeedback, inReleaseParentIds ] );
```

- [ ] **Step 4: Render nested bug/feedback rows under their parent.** After the sub-item `.map(...)` closes inside the feature `<Fragment>` (the `} ) }` ending the sub-item map, ~line 232, before `</Fragment>`), add a render of any bugs/feedback nested under this feature:

```tsx
              { ( nestedChildren[feature.id] || [] ).map( child => (
                <div key={ child.id } style={ childRowStyle }>
                  <div className={ isSidebarOpen ? 'border-r flex items-center gap-2 sticky left-0 z-10 overflow-hidden relative' : 'border-r flex items-center justify-center px-1 sticky left-0 z-10' }
                    style={{ ...indentStyle, paddingLeft: isSidebarOpen ? 80 : undefined }}>
                    { isSidebarOpen ? (
                      <>
                        <CornerDownRight size={ 12 } style={{ color: C.mutedFg, opacity: 0.5, flexShrink: 0 }} />
                        <button onClick={ () => onNavigate( leftPct ) } title="Jump to this item on the timeline" style={{ flex: 1, textAlign: 'left', background: 'none', border: 'none', cursor: 'pointer', minWidth: 0 }}>
                          <span style={{ fontSize: 13, color: C.mutedFg, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', display: 'block' }}>{ ( child as Bug ).title }</span>
                        </button>
                        <span style={{ fontSize: 11, color: C.mutedFg, flexShrink: 0 }}>{ child.timeEstimate }h</span>
                      </>
                    ) : <div style={{ width: 6, height: 6, borderRadius: '50%', backgroundColor: child.type === 'bug' ? C.bug.bar : C.feedback.bar }} title={ ( child as Bug ).title } /> }
                  </div>
                  <div style={{ ...barArea, backgroundColor: child.type === 'bug' ? '#fff1f21a' : '#faf5ff1a' }}>
                    <InlineBar label={ ( child as Bug ).title } hours={ child.timeEstimate } color={ child.type === 'bug' ? C.bug : C.feedback } leftPct={ leftPct } widthPct={ widthPct } onClick={ () => onItemClick( child ) } isNested />
                  </div>
                </div>
              ) ) }
```

> Note: nested bugs/feedback under a **sub-item** parent are rare in the Gantt's feature-grouped layout; this plan nests them under the feature row for simplicity. Sub-item-parented children still appear (nested under the feature that owns the sub-item is out of scope) — if `getNestingParentId` returns a sub-item id, they render at release level via `topLevelBugs/Feedback`. This matches the spec's "else sit at release level."

- [ ] **Step 5: Render only top-level bugs/feedback at release level.** Change the two release-level maps to use the filtered lists: replace `{ linkedBugs.map( bug => (` with `{ topLevelBugs.map( bug => (` and `{ linkedFeedback.map( fb => (` with `{ topLevelFeedback.map( fb => (`.

- [ ] **Step 6: Build**

Run: `npm run build`
Expected: PASS.

- [ ] **Step 7: Visual confirmation**

Run: `npm run dev`, open the Gantt. Verify: a feature's sub-item shows nested under it only when the sub-item shares the feature's release; a sub-item assigned to a different release shows under that other release. A bug/feedback linked to an in-release feature shows nested under it; otherwise it shows at release level. No duplicate rows.

- [ ] **Step 8: Commit**

```bash
git add src/app/components/GanttTimeline.tsx
git commit -m "Gantt: nest sub-items and bugs/feedback only within the same release (#21, #22)"
```

---

## Phase 4 — Links & linking UI

### Task 8: Shared `LinksField` component

**Files:**
- Create: `src/app/components/LinksField.tsx`

- [ ] **Step 1: Write the component** (edit mode = repeatable rows; read mode = clickable list opening in new tab):

```tsx
import { X, Plus, ExternalLink } from 'lucide-react';
import { ItemLink } from '../types';

const C = { border: '#e2e8f0', fg: '#1a1f36', mutedFg: '#64748b', white: '#ffffff', primary: '#2563eb' };

export function LinksEditor( { links, onChange }: { links: ItemLink[]; onChange: ( next: ItemLink[] ) => void } ) {
  const update = ( i: number, patch: Partial<ItemLink> ) =>
    onChange( links.map( ( l, idx ) => idx === i ? { ...l, ...patch } : l ) );
  const remove = ( i: number ) => onChange( links.filter( ( _, idx ) => idx !== i ) );
  const add = () => onChange( [ ...links, { label: '', url: '' } ] );

  const input: React.CSSProperties = { padding: '7px 9px', borderRadius: 6, border: `1px solid ${ C.border }`, fontSize: 13, color: C.fg, outline: 'none', background: C.white, boxSizing: 'border-box' };

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
      { links.map( ( link, i ) => (
        <div key={ i } style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
          <input type="text" placeholder="Label" value={ link.label } onChange={ e => update( i, { label: e.target.value } ) } style={{ ...input, width: 140, flexShrink: 0 }} />
          <input type="url" placeholder="https://…" value={ link.url } onChange={ e => update( i, { url: e.target.value } ) } style={{ ...input, flex: 1, minWidth: 0 }} />
          <button type="button" onClick={ () => remove( i ) } title="Remove link" style={{ background: 'none', border: 'none', cursor: 'pointer', color: C.mutedFg, display: 'flex', flexShrink: 0 }}>
            <X size={ 16 } />
          </button>
        </div>
      ) ) }
      <button type="button" onClick={ add } style={{ display: 'inline-flex', alignItems: 'center', gap: 6, alignSelf: 'flex-start', padding: '6px 12px', borderRadius: 6, fontSize: 13, fontWeight: 500, border: `1px solid ${ C.border }`, background: C.white, color: C.fg, cursor: 'pointer' }}>
        <Plus size={ 14 } /> Add link
      </button>
    </div>
  );
}

export function LinksDisplay( { links }: { links: ItemLink[] } ) {
  if ( ! links || links.length === 0 ) return null;
  return (
    <div className="flex flex-col gap-2">
      { links.map( ( link, i ) => (
        <a key={ i } href={ link.url } target="_blank" rel="noopener noreferrer"
          className="flex items-center gap-2 p-2.5 rounded-lg border border-border hover:bg-accent transition-colors text-sm text-blue-700">
          <ExternalLink className="w-4 h-4 flex-shrink-0" />
          <span className="truncate">{ link.label?.trim() || link.url }</span>
        </a>
      ) ) }
    </div>
  );
}
```

- [ ] **Step 2: Verify build**

Run: `npm run build`
Expected: PASS.

---

### Task 9: AddItemModal — parent picker (Features + Sub-Items) + links editor

**Files:**
- Modify: `src/app/components/AddItemModal.tsx`

- [ ] **Step 1: Import types + editor.** Update the imports at the top: add `ItemLink` to the `../types` import (line 4) and add:

```ts
import { LinksEditor } from './LinksField';
import { useDataStore } from '../store/useDataStore';
```

(`useDataStore` is already imported — only add `LinksEditor` and ensure `subitems` is selected next.)

- [ ] **Step 2: Select sub-items and add state.** After `const releases = useDataStore( s => s.releases );` (line 72) add:

```ts
  const subitems = useDataStore( s => s.subitems );
```

After the `linkedFeatureId` state (line 83) add:

```ts
  const [linkedSubItemId, setLinkedSubItemId] = useState( '' );
  const [links, setLinks] = useState<ItemLink[]>( [] );
```

- [ ] **Step 3: Reset new state on type change & close.** In `handleTypeChange` (after `setLinkedFeatureId( '' );`, line 106) add `setLinkedSubItemId( '' );`. In `handleClose` (after `setLinkedFeatureId( '' );`, line 187) add `setLinkedSubItemId( '' ); setLinks( [] );`.

- [ ] **Step 4: Send the new fields in the payload.** In `handleSubmit`, for the `isBug` and `isFeedback` branches, after `if ( linkedFeatureId ) payload.linkedFeatureId = linkedFeatureId;` add in **each**:

```ts
      if ( linkedSubItemId ) payload.linkedSubItemId = linkedSubItemId;
```

Then, before the `try {` (after the if/else chain, line 169), for all types add:

```ts
    if ( links.length ) payload.links = links.filter( l => l.url.trim() );
```

- [ ] **Step 5: Replace the bug/feedback parent select with a Features+Sub-Items picker.** The current parent block (lines ~296–317) handles both sub-items (parent feature) and bug/feedback (linked feature). Keep the sub-item branch as-is, but for bug/feedback render a select listing both. Replace the single `<select>` (lines ~303–312) so that when `! isSubitem` it offers grouped options:

```tsx
              { isSubitem ? (
                <select
                  value={ linkedFeatureId }
                  onChange={ e => handleParentFeatureChange( e.target.value ) }
                  required
                  style={{ ...inputStyle, borderColor: ! linkedFeatureId ? '#fca5a5' : C.border }}>
                  <option value="">— Select a parent feature —</option>
                  { [ ...features ].sort( ( a, b ) => a.name.localeCompare( b.name ) ).map( f => (
                    <option key={ f.id } value={ f.id }>{ f.name }</option>
                  ) ) }
                </select>
              ) : (
                <select
                  value={ linkedSubItemId ? `s:${ linkedSubItemId }` : linkedFeatureId ? `f:${ linkedFeatureId }` : '' }
                  onChange={ e => {
                    const v = e.target.value;
                    if ( v.startsWith( 's:' ) ) { setLinkedSubItemId( v.slice( 2 ) ); setLinkedFeatureId( '' ); }
                    else if ( v.startsWith( 'f:' ) ) { setLinkedFeatureId( v.slice( 2 ) ); setLinkedSubItemId( '' ); }
                    else { setLinkedFeatureId( '' ); setLinkedSubItemId( '' ); }
                  } }
                  style={ inputStyle }>
                  <option value="">— None —</option>
                  <optgroup label="Features">
                    { [ ...features ].sort( ( a, b ) => a.name.localeCompare( b.name ) ).map( f => (
                      <option key={ f.id } value={ `f:${ f.id }` }>{ f.name }</option>
                    ) ) }
                  </optgroup>
                  { subitems.length > 0 && (
                    <optgroup label="Sub-Items">
                      { [ ...subitems ].sort( ( a, b ) => a.name.localeCompare( b.name ) ).map( s => (
                        <option key={ s.id } value={ `s:${ s.id }` }>{ s.name }</option>
                      ) ) }
                    </optgroup>
                  ) }
                </select>
              ) }
```

Also update the surrounding condition (`hasLinkedFeature && features.length > 0`) to `hasLinkedFeature && ( features.length > 0 || subitems.length > 0 )`, and the label text "Linked feature" → "Links up to" for the bug/feedback case (line ~299): `{ isSubitem ? 'Parent feature' : 'Links up to' }`.

- [ ] **Step 6: Add a Links editor section.** Before the Images section (`{ hasImages && (`, line ~361) add:

```tsx
          {/* Links — all item types */}
          <div>
            <label style={ labelStyle }>Links <span style={{ fontSize: 12, fontWeight: 400, color: C.mutedFg }}>(optional)</span></label>
            <LinksEditor links={ links } onChange={ setLinks } />
          </div>
```

- [ ] **Step 7: Build**

Run: `npm run build`
Expected: PASS.

- [ ] **Step 8: Visual confirmation**

Run: `npm run dev`. Open "Add Item": for a Bug/Feedback, the "Links up to" picker lists Features and Sub-Items (grouped); the Links editor adds/removes label+URL rows. (Persistence is WP-only; just confirm the UI behaves.)

- [ ] **Step 9: Commit**

```bash
git add src/app/components/AddItemModal.tsx src/app/components/LinksField.tsx
git commit -m "AddItemModal: links editor + link bug/feedback to feature or sub-item (#24, #22)"
```

---

### Task 10: DetailModal — links editor/display, parent picker, remove legacy URLs

**Files:**
- Modify: `src/app/components/DetailModal.tsx`

- [ ] **Step 1: Imports & form type.** Add to the `../types` import (line 3) `ItemLink`, and after line 7 add:

```ts
import { LinksEditor, LinksDisplay } from './LinksField';
```

In `EditFormState` (line 21), add fields:

```ts
  links?: ItemLink[];
  linkedSubItemId?: string;
```

- [ ] **Step 2: Add a reusable Links section helper.** Near `renderImagesSection` (line ~244), add:

```tsx
  const renderLinksSection = () => {
    const current: ItemLink[] = ( editForm.links as ItemLink[] | undefined ) ?? ( ( item as { links?: ItemLink[] } ).links ) ?? [];
    if ( isEditing ) {
      return (
        <Section title={ <><ExternalLink className="w-4 h-4" /> Links</> }>
          <LinksEditor links={ current } onChange={ next => setEditForm( { ...editForm, links: next } ) } />
        </Section>
      );
    }
    if ( current.length === 0 ) return null;
    return (
      <Section title={ <><ExternalLink className="w-4 h-4" /> Links ({ current.length })</> }>
        <LinksDisplay links={ current } />
      </Section>
    );
  };
```

- [ ] **Step 3: Render the Links section in each item view.** In the bug, feedback, feature, sub-item, and release render functions, add `{ renderLinksSection() }` alongside the other sections (e.g. next to `renderImagesSection(...)`). For bug & feedback specifically, place it where the legacy `urls` block was (next step removes that).

- [ ] **Step 4: Remove the legacy "Related URLs" read block in the bug view.** Delete the block at lines ~580–589 that maps `bug.urls` into anchor tags (the `{ bug.urls && bug.urls.length > 0 && ( ... ) }` block). The migrated data now renders via `renderLinksSection()`.

- [ ] **Step 5: Remove the legacy "Related URLs" read block in the feedback view.** Delete the equivalent `feedback.urls` block in the feedback render (the analogous `{ feedback.urls && ... }` block). If feedback has no such block, skip.

- [ ] **Step 6: Add the "Links up to" parent picker for bug/feedback edit.** Where the bug/feedback edit currently shows the linked-feature picker, extend it to list Features + Sub-Items, mirroring Task 9 Step 5 but writing into `editForm`. Concretely, render a select bound to `editForm.linkedSubItemId`/`editForm.linkedFeatureId`:

```tsx
          { ( item.type === 'bug' || item.type === 'feedback' ) && isEditing && (
            <Section title={ <><Link2 className="w-4 h-4" /> Links up to</> }>
              <select
                value={ editForm.linkedSubItemId ? `s:${ editForm.linkedSubItemId }` : editForm.linkedFeatureId ? `f:${ editForm.linkedFeatureId }` : '' }
                onChange={ e => {
                  const v = e.target.value;
                  if ( v.startsWith( 's:' ) ) setEditForm( { ...editForm, linkedSubItemId: v.slice( 2 ), linkedFeatureId: '' } );
                  else if ( v.startsWith( 'f:' ) ) setEditForm( { ...editForm, linkedFeatureId: v.slice( 2 ), linkedSubItemId: '' } );
                  else setEditForm( { ...editForm, linkedFeatureId: '', linkedSubItemId: '' } );
                } }
                className="w-full px-3 py-2 text-sm border border-input rounded-lg bg-background">
                <option value="">— None —</option>
                <optgroup label="Features">
                  { [ ...features ].sort( ( a, b ) => a.name.localeCompare( b.name ) ).map( f => <option key={ f.id } value={ `f:${ f.id }` }>{ f.name }</option> ) }
                </optgroup>
                { subitems.length > 0 && (
                  <optgroup label="Sub-Items">
                    { [ ...subitems ].sort( ( a, b ) => a.name.localeCompare( b.name ) ).map( s => <option key={ s.id } value={ `s:${ s.id }` }>{ s.name }</option> ) }
                  </optgroup>
                ) }
              </select>
            </Section>
          ) }
```

Place this inside the editing branch of the bug and feedback views (where other editable Sections render). `subitems` is already selected at line 65.

- [ ] **Step 7: Render the linked sub-item badge in read mode.** In the bug view's read-mode badges (lines ~592–597) and feedback view (lines ~622–627), add a sub-item badge. After the `linkedFeature && linkedBadge(...)` line in each, add:

```tsx
              { ( item as Bug ).linkedSubItemId && linkedBadge( 'cyan', 'sub-item', subitems.find( s => s.id === ( item as Bug ).linkedSubItemId )?.name ?? '' ) }
```

(For the feedback block, cast to `Feedback`.) Update the enclosing `( linkedFeature || release )` conditions to also include the sub-item, e.g. `( linkedFeature || linkedSubItem || release )` — compute `const linkedSubItem = bug.linkedSubItemId ? getItemById( bug.linkedSubItemId ) as SubItem | undefined : undefined;` near the other `linkedFeature` lookups (line ~565 / ~605).

- [ ] **Step 8: Ensure `links` & `linkedSubItemId` save.** The save path sends `editForm` via `updateItem`, which forwards all fields to PHP (added in Phase 1). No extra wiring needed — confirm the save handler spreads `editForm` (it does). Note: `patchItem` already merges arbitrary fields into the store optimistically.

- [ ] **Step 9: Build**

Run: `npm run build`
Expected: PASS.

- [ ] **Step 10: Visual confirmation**

Run: `npm run dev`. Open a Bug/Feedback detail: edit mode shows a Links editor and a "Links up to" picker (Features + Sub-Items); read mode shows clickable links opening in a new tab and a sub-item badge when linked. Confirm the old plain "Related URLs" list is gone. Open a Feature/Sub-Item/Release: Links section appears in edit and read modes.

- [ ] **Step 11: Commit**

```bash
git add src/app/components/DetailModal.tsx
git commit -m "DetailModal: links editor/display, link to feature or sub-item, drop legacy URLs (#24, #22)"
```

---

## Phase 5 — Version bump, finalize, PR

### Task 11: Version bump & PR

**Files:**
- Modify: `forge-project-management.php`, `package.json`

- [ ] **Step 1: Bump the version** (minor — new feature). Update `Version:` and `FORGE_PM_VERSION` in `forge-project-management.php`, and `"version"` in `package.json`, keeping them in sync (e.g. `1.24.0` → `1.25.0`).

- [ ] **Step 2: Final build + lint**

Run: `npm run lint` then `npm run build`
Expected: both PASS.

- [ ] **Step 3: Regenerate the plugin zip** (per CLAUDE.md, after a version bump)

Run: `npm run zip`
Expected: `forge-project-management.zip` written beside the repo.

- [ ] **Step 4: Commit & open draft PR**

```bash
git add forge-project-management.php package.json
git commit -m "Bump version for item-linking epic (#24, #22, #21)"
git push -u origin feature/22-21-24-item-linking
gh pr create --draft --title "Item-linking epic: links, cross-type linking, release-scoped nesting (#24, #22, #21)" --body "<see PR template in CLAUDE.md>"
```

The PR body must include: issue links (#24, #22, #21), summary, files changed, test results (lint/build + visual notes), review notes, and anything needing human review. Leave as draft for human review — never auto-merge.

---

## Self-Review

**Spec coverage:**
- #24 labeled links on all items → Tasks 1, 2, 3, 8, 9, 10. ✔
- #24 legacy `urls` migration → Task 2 Step 1 (`meta_links` fallback) + Task 10 Steps 4–5 (remove old UI). ✔
- #22 bug/feedback → feature *or* sub-item → Tasks 1, 3, 5, 9 Step 5, 10 Step 6. ✔
- #22 nesting driven by link → Task 5 helper used in Tasks 6 & 7. ✔
- #21 same-release/same-column nesting → Task 6 (Kanban), Task 7 (Gantt). ✔
- Out-of-scope guards (no join table, no release-array changes) respected. ✔

**Placeholder scan:** Each code step contains real code. UI insertion steps reference exact anchor lines and provide the literal JSX/TS to insert. The one explicit simplification (Gantt sub-item-parented bug/feedback rendering at release level) is documented and matches the spec's fallback wording.

**Type consistency:** `getNestingParentId` (Task 5) is consumed identically in Tasks 6 & 7. `ItemLink {label,url}` (Task 1) is used uniformly in PHP (Tasks 2–3), `LinksField` (Task 8), and both modals (Tasks 9–10). `linkedSubItemId` naming matches across types.ts, PHP meta `_forge_linked_subitem_id`, and both modals. Payload key `links` matches the PHP `array_key_exists( 'links', ... )` checks.

**Note for implementer:** Local `npm run dev` uses sample data with no WordPress, so persistence (save/reload) of `links`/`linkedSubItemId` can only be fully verified against a real WP install. Sample data in `src/app/data/sampleData.ts` may optionally be extended with a couple of `links` entries to make the read-mode UI visible locally.
