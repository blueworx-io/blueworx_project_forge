# Search Function — Design (issue #45)

## Goal

Add a free-text search to the view pages so users can quickly narrow items by typing,
across the Timeline (Gantt), Kanban, and Calendar views.

## Approach

Fold search into the existing shared-filter system rather than building a parallel
mechanism. Every view already runs items through a single `ViewFilters` object via
`matchesFilters()`, persists that object to the URL hash, and shares it via the Share
button. Treating the query as one more filter dimension gives us all-view coverage,
shareable search links, and zero per-view filtering changes.

The search **input** lives in each view's toolbar (next to Filters/Share), not inside
the slide-out Filter panel.

## Scope of matching

- Case-insensitive substring match.
- Matches against the item's `name`/`title` **or** its `description`.
- Empty query matches everything (no narrowing).
- Combines with the dropdown filters using AND logic, consistent with existing filters.

## Changes

1. **`src/app/utils/filters.ts`**
   - Add `search: string` (default `''`) to `ViewFilters` and `EMPTY_FILTERS`.
   - Extend `matchesFilters()` to apply the substring test when `search` is non-empty.
   - `hasActiveFilters()` already returns true for any non-default field once search is included.

2. **`src/app/utils/urlState.ts`**
   - Encode/decode `search` as a `q=` hash param (written only when non-empty) so
     search state is shareable like the other filters.

3. **`src/app/components/ViewActions.tsx`**
   - New `SearchBox` component: compact toolbar text input with a search icon and a
     clear (×) button when non-empty. Reads/writes `filters.search` via
     `setFilter('search', …)`. Lightly debounced (~150 ms) for smooth typing.
   - The Filters badge count is unchanged (search is not counted there).

4. **`src/app/components/KanbanBoard.tsx`, `GanttTimeline.tsx`, `CalendarView.tsx`**
   - Render `<SearchBox />` in each toolbar beside `<FilterButton />` / `<ShareButton />`.
   - No filtering changes — `matchesFilters` already does the work.

## Behaviour notes

- The toolbar **Filters** badge count is unchanged; the search box shows its own active
  state and clear button instead.
- "Clear all filters" in the Filter panel also clears search (search is part of
  `ViewFilters`, which `resetFilters` resets).

## Out of scope

- Searching the Settings page.
- Fuzzy / typo-tolerant matching.
- Search history or suggestions.
- Highlighting matched text within results.
