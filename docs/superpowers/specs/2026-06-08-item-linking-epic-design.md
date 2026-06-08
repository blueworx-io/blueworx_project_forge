# Item-Linking Epic — Design Spec

**Date:** 2026-06-08
**Issues:** [#22](https://github.com/blueworx-io/forge_project_management/issues/22) (Item Linking), [#21](https://github.com/blueworx-io/forge_project_management/issues/21) (Sub Item Separation), [#24](https://github.com/blueworx-io/forge_project_management/issues/24) (Add "Links" to items)
**Delivery:** Single themed PR / branch covering the three issues together.

## Background & Goal

This epic reshapes how items relate to one another and how external links are attached.
Three issues are coupled because they all touch the data model and the nesting logic shared
by the Kanban board and the Gantt chart:

- **#24** — every item type can carry optional, labeled external links (name + URL), opened in a new tab.
- **#22** — items can link "up" to a parent across types (bug/feedback → feature *or* sub-item), and that link drives nesting.
- **#21** — features and sub-items are independent across releases/columns, and nest **only** when they share the same release (Gantt) or column (Kanban).

### Approved design decisions (from brainstorming)

1. **#22 link model = single-parent**, not many-to-many. A bug or feedback links "up" to exactly
   one parent (a Feature *or* a Sub-Item). This extends the existing single-parent fields rather
   than introducing a join table.
2. **#24 links migrate the legacy `urls`.** Bugs and feedback already store a plain `urls: string[]`
   (no labels). On read, legacy URLs surface as labeled links (`{label: url, url}`) so nothing is
   lost; the old plain "Related URLs" UI section is removed.

## Current State (grounded in code, 2026-06-08)

- **Data model** ([src/app/types.ts](../../../src/app/types.ts)):
  - `Feature.subItemIds?: string[]`
  - `SubItem.parentFeatureId: string` (required)
  - `Bug.linkedFeatureId?: string`, `Bug.urls?: string[]`
  - `Feedback.linkedFeatureId?: string`, `Feedback.linkedBugId?: string`, `Feedback.urls?: string[]`
  - All item types have `releaseId?: string`.
- **Persistence** ([includes/class-rest-api.php](../../../includes/class-rest-api.php)):
  - Each field maps to post meta; read shape per type at lines ~207–286, save maps at ~397–660.
  - Scalar string arrays (`urls`, `images`, `subItemIds`, …) save via the `$array_meta` map (line ~647)
    with `array_map('sanitize_text_field', …)` — this only handles arrays of strings.
  - Object/structured data (`stageDates`) saves as JSON via a dedicated handler (line ~662).
  - `strip_sensitive` (line 130) removes `['changeLog','notes','urls','description']` for non-admin/public responses.
- **Nesting today:**
  - **Kanban** nests a child under its parent **only when both are in the same column** (grouped by release).
    A child in a different release than its same-column parent can be wrongly pulled into the parent's group.
  - **Gantt** nests a sub-item under its feature via `subItemIds` **regardless** of the sub-item's own release.
    Bugs/feedback render flat (never nested).

## Design

### Part 1 — Data model & persistence

**#24 Links**

- New shared type: `ItemLink = { label: string; url: string }`.
- Add `links?: ItemLink[]` to `Feature`, `SubItem`, `Bug`, `Feedback`, and `Release` (covers "all items").
- PHP: store in new meta `_forge_links` as **JSON** (an array of `{ label, url }`), because the existing
  scalar `$array_meta` map can't represent objects. Add a dedicated save handler mirroring `stageDates`
  that sanitizes each entry (`label` → `sanitize_text_field`, `url` → `esc_url_raw`) and `wp_json_encode`s
  the result; add a matching read accessor that `json_decode`s `_forge_links` per type.
- **Migration (read path):** if `_forge_links` is empty **and** legacy `_forge_urls` has entries,
  return them as `{ label: url, url }`. Existing bug/feedback URLs survive and become editable labeled links.
- `strip_sensitive`: add `'links'` to the private list (alongside `urls`).

**#22 Linking (extend single-parent)**

- Add `linkedSubItemId?: string` to `Bug` and `Feedback` (meta `_forge_linked_subitem_id`).
  A bug/feedback "links up to" **one** parent — a Feature *or* a Sub-Item.
- Unchanged: `SubItem.parentFeatureId` (sub-item → feature), `Feedback.linkedBugId` (feedback → bug cross-ref).
- This covers the whole #22 matrix:
  - bug/feedback → feature ✔ (existing `linkedFeatureId`)
  - bug/feedback → sub-item ✔ (new `linkedSubItemId`)
  - sub-item → feature ✔ (existing `parentFeatureId`)
  - feedback → bug ✔ (existing `linkedBugId`)
  - bug↔feedback represented from the feedback side.
- Shared nesting-parent helper:
  - `getNestingParentId(item)` = `linkedSubItemId ?? linkedFeatureId` for bug/feedback;
    `parentFeatureId` for sub-item; `undefined` otherwise.

### Part 2 — Linking & Links UI

- **"Links up to" parent picker** (AddItemModal & DetailModal, bug/feedback only):
  the parent selector lists **Features *and* Sub-Items** (grouped), single-select; stores the chosen id
  in `linkedSubItemId` or `linkedFeatureId`. Today it lists features only.
- **Links editor** (both modals, all item types): a repeatable list of `[label] [url] [×]` rows + an
  "Add link" button.
- **Links display** (read mode): a "Links" section rendering each link as a clickable row that opens in a
  new tab (`target="_blank" rel="noopener noreferrer"`), showing the label and falling back to the URL when
  no label is set.
- The old plain "Related URLs" section is removed (its data is migrated into `links`).

### Part 3 — Nesting & separation (#21 + #22 "in releases and columns/rows")

- **Rule:** a child nests under its parent **only when they share the same release (Gantt) / same column
  (Kanban)**; otherwise each renders independently in its own release/column.
- **Kanban:** nest only if parent is in the same column **and** `child.releaseId === parent.releaseId`
  (fixes today's same-column-only behavior). Uses `getNestingParentId`, so bugs/feedback nest under a
  sub-item parent too.
- **Gantt:** within a release group, show a feature's sub-item as nested only if the sub-item is in that
  same release; a sub-item in another release renders under *its own* release. Bugs/feedback nest under
  their linked feature/sub-item when in the same release, else sit at release level (today always flat).

## Out of Scope (scope guard)

- No change to release↔item linking arrays (`linkedFeatureIds` / `linkedBugIds` / `linkedFeedbackIds`).
- No change to drag-and-drop persistence or the archive flow beyond the nesting rule above.
- No many-to-many linking / join table.
- The other batched issues (#20, #19, #18, #15, #2) are separate themed PRs, not part of this epic.

## Affected Files (anticipated)

- [src/app/types.ts](../../../src/app/types.ts) — `ItemLink`, `links?`, `linkedSubItemId?`.
- [includes/class-rest-api.php](../../../includes/class-rest-api.php) — read shape, save maps, `_forge_links`
  meta + migration, `strip_sensitive`.
- Nesting helper (new shared util) + Kanban board + Gantt components.
- AddItemModal & DetailModal — parent picker, links editor, links display; remove "Related URLs".

## Testing / Acceptance

- #24: a link with label+URL can be added, saved, reloaded, and opens in a new tab; legacy bug/feedback
  URLs appear as labeled links after migration.
- #22: a bug/feedback can be linked to a feature *or* a sub-item; the link persists and drives nesting.
- #21: feature and sub-item in different releases/columns render independently; in the same one they nest.
- `npm run lint` and `npm run build` pass; visual confirmation via `npm run dev` before commit.
