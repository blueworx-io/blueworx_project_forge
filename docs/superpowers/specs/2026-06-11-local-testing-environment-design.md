# Full Local Testing Environment (Issue #8)

**Issue:** https://github.com/blueworx-io/forge_project_management/issues/8
**Goal:** Make `npm run dev` (standalone, no WordPress) a fully functional sandbox where every
feature and area of the app can be exercised against expanded dummy data — all write actions work.

## Problem

In standalone dev there is no WordPress, so `window.forgePMData` is undefined. Today the app:

- Seeds the Zustand store from `src/app/data/sampleData.ts` and grants admin rights, so all views
  are **viewable**, but writes are broken in three ways:
  - `if ( window.forgePMData )` guards → create item / save release / company-date edits **no-op**.
  - Unguarded REST calls → delete, archive, restore, Kanban drag stage-change, calendar event
    create, settings save → **throw network errors** (no server to fetch).
  - `fetchArchived()` errors → the Archive tab cannot be tested.

## Decisions (confirmed with user)

1. **Approach:** a single in-memory mock backend in the API layer. Components keep calling the same
   `wordpress.ts` functions; when `window.forgePMData` is absent they operate on the in-memory store.
2. **Persistence:** in-memory only — every page reload restores the clean expanded sample set. No
   localStorage, no reset button.
3. **Data depth:** populate every area (all workflow stages, every bug/feedback status, seeded
   archive, varied prices/images/estimates).

## Architecture

```
component  →  wordpress.ts function  →  (no forgePMData?)  →  mockBackend  →  mutates Zustand store
                                     →  (WordPress?)        →  fetch()  (unchanged)
```

### 1. `src/app/api/mockBackend.ts` (new)
The in-memory "DB" for standalone dev. Operates directly on the live Zustand store
(`useDataStore.getState()` / store actions) so views re-render. Implements, mirroring the
`wordpress.ts` signatures and return shapes:

- `createItem( type, data )` → assigns id, fills entity defaults, appends to the right array,
  keeps parent links consistent (`subItemIds`, `releaseId`).
- `updateItem( type, id, data )` → patches the item in place.
- `deleteItem( type, id )` → removes it.
- `updateStage( type, id, workflowStage )` → sets the stage.
- `archiveItem` / `restoreItem` / `fetchArchived` → move between the live arrays and an in-memory
  `archived` list.
- `createCompanyDate` / `updateCompanyDate`.
- `saveSettings` / `fetchSettings` → echo back the passed settings (settings live in `App.tsx`
  state; the mock just resolves successfully).

A small `nextId( prefix )` helper generates stable unique ids (e.g. `f28`, `b4`).

### 2. `src/app/api/wordpress.ts`
Each mutating/fetching function gets a guard at the top:
`if ( ! window.forgePMData ) return mock.X( ... );`
The WordPress code path is untouched. `getConfig()`'s existing dev-admin behaviour stays.

### 3. Store — `src/app/store/useDataStore.ts`
Add the actions the mock needs so it can mutate state and trigger re-renders:
- `addItem( item )`, `removeItem( id )`, `setStage( id, stage )`
- `archived: ArchivedItem[]` plus `addArchived` / `removeArchived` (or expose setState helpers)

`triggerRefresh()` stays a no-op in dev (the App refetch effect already early-returns when
`window.forgePMData` is absent) and remains harmless.

### 4. Components
Remove the now-redundant `if ( window.forgePMData )` guards in `AddItemModal`, `Settings`,
`GanttTimeline`, `CalendarView` so they always call the API. No other component logic changes.

## Data expansion — `src/app/data/sampleData.ts`

Keep all existing entries; enrich so every area has content:
- Spread features/items across **all 10 workflow stages** (today almost all are `future-idea`) so
  every Kanban column is populated.
- Add **bugs and feedback** covering each status (`open` / `in-progress` / `resolved`) and each
  priority (`low` / `medium` / `high`).
- Add a **seeded archived set** returned by `fetchArchived()` on first load.
- Give several features **non-zero `timeEstimate`, `images`, and varied `featurePrice`** so Timeline
  capacity bars, the image lightbox, and price badges all have data.

## Out of scope

- Real WordPress / Docker environment.
- Automated test suite (Vitest / Playwright).
- localStorage persistence and reset UI.
- Any production (WordPress) behaviour change.

## Verification

- `npm run lint` and `npm run build` clean.
- `npm run dev`: create / edit / delete / archive / restore items; drag cards across Kanban
  columns; add and edit calendar events; save settings and releases — all persist on screen.
  Reload restores the clean sample set.
- User visually confirms in the browser (per project workflow) before commit / PR.
