# Rich Text and the Checklist — Implementation Plan (PR B of the task panel rework)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Item description and Completed when take basic formatting from an in-house editor; every task can carry a checklist of up to ten one-line items that saves with Save changes and shows its progress on the board card. Item codes leave the list views (a block-1 item missed: the board card, list rows and My tasks show a short id; only the panel keeps it).

**Architecture:** A `RichText` kit component (`contentEditable` + a small toolbar, HTML out, allowlist in). The server keeps the same two columns, passes them through `wp_kses` on the allowlist, and every "is it filled in" check reads the text with tags stripped. The checklist is a JSON column, validated to ten one-line rows, hydrated as an array.

**Spec:** `docs/superpowers/specs/2026-09-18-task-panel-rework-design.md`, section "PR B". Finding while planning: the client view (`Work\ClientView::item`) does not send the two fields at all, so no client-plugin PR is needed for rich text.

## Global Constraints

- Branch `task-panel-rich-text` off `task-panel-layout`; draft PR to `task-panel-layout`. Version `2.115.0` → `2.116.0`; changelog `## [2.116.0] - 2026-09-18`.
- No new dependencies; nothing under `client/` but the version line; restore the client bundle after every build.
- Schema `VERSION` 24 → 25; the new column is nullable (`checklist text NULL`) as the file's own rule says.
- Allowed HTML, both sides, exactly: `p, br, strong, em, u, ul, ol, li, a[href]`. The editor turns `b`/`i` into `strong`/`em` before emitting. Paste is plain text.
- Never build or edit PHP during a Playwright run. Lint once at the end.

## File map

| File | Change |
|---|---|
| `includes/Data/Schema.php` | `checklist text NULL` on work items; VERSION 25 |
| `includes/Work/Fields.php` | `RICH = [ 'problem', 'acceptance_criteria' ]`, `ALLOWED_HTML`, `plain( string ): string`, `'checklist'` in `writable()` |
| `includes/Work/Validate.php` | rich fields through `wp_kses`; `checklist_field()` (array → JSON, ≤10 rows, one line ≤191 each, `done` bool; error `checklist` otherwise) |
| `includes/Work/Items.php` | default `'checklist' => '[]'`; hydrate `checklist` → array of `{text, done}` |
| `includes/Work/Gates.php` | `filled()` uses `Fields::plain()` for the rich fields |
| `includes/Work/Filters.php` | search haystack uses `Fields::plain()` |
| `src/kit/RichText.tsx`, `src/kit/kit.css`, `src/kit/index.ts` | the editor |
| `src/types.ts` | `ChecklistRow`, `WorkItem.checklist` |
| `src/components/ItemPanel.tsx` | RichText for the two fields; the Checklist block; save sends `checklist` |
| `src/components/NewWork.tsx` | RichText for Item description |
| `src/components/Card.tsx`, `ListView.tsx`, `MyTasksScreen.tsx` | checklist "n/m" tag on the card; short ids gone from all three |
| `src/styles.css` | checklist rows |
| `tests/e2e/item-rich-text.spec.js`, `item-checklist.spec.js` (create); `board.spec.js` if it fills `#bwx-new-problem` (it does — use the editor's test id) | |
| version files, `CHANGELOG.md` | 2.116.0 |

---

### Task 1: Domain — allowlisted HTML, plain text, the checklist column

- `Fields`: `public const RICH = array( 'problem', 'acceptance_criteria' );` `public const ALLOWED_HTML = array( 'p' => array(), 'br' => array(), 'strong' => array(), 'em' => array(), 'u' => array(), 'ul' => array(), 'ol' => array(), 'li' => array(), 'a' => array( 'href' => true ) );` `public static function plain( string $html ): string { return trim( wp_strip_all_tags( str_replace( array( '</p>', '<br>', '<br/>', '<br />', '</li>' ), "\n", $html ), true ) ); }` (the replace keeps line breaks as newlines so a search or a one-line use still reads sensibly). `writable()` gains `'checklist'`. In the unit bootstrap (`tests/php/bootstrap.php`) stub `wp_strip_all_tags` and `wp_kses` if absent (check first; `wp_kses` may need a plain implementation via `strip_tags` with the allowed tag list for unit purposes).
- `Validate::text_fields()`: for a field in `Fields::RICH`, `$values[ $field ] = trim( wp_kses( (string) $input[ $field ], Fields::ALLOWED_HTML ) )`; an empty paragraph (`plain() === ''`) is stored as `''`.
- `Validate::checklist_field( $input, &$values, &$errors )`: when `checklist` is in the input: must be an array (else error); each row `[ 'text' => trim( (string) ), 'done' => (bool) ]`; rows with empty text dropped; a newline in text → error "Each checklist line is one line."; more than 10 kept rows → error "A checklist holds at most 10 items."; text over 191 → error; `$values['checklist'] = wp_json_encode( $rows )`. Called from `values()`/wherever the other groups are called.
- `Items::defaults()` `'checklist' => '[]'`; hydrate: `'checklist' => self::checklist( (string) ( $row['checklist'] ?? '' ) )` — `json_decode`, non-array → `array()`, each row normalised to `{ text: string, done: bool }`.
- `Gates::filled()`: `if ( in_array( $field, Fields::RICH, true ) ) { return '' !== Fields::plain( (string) $value ); }`.
- `Filters` haystack: `Fields::plain( $item['problem'] )`.
- Schema column + VERSION 25. `vendor/bin/phpunit` (add a unit test for `Validate` checklist rules and `Fields::plain` in the existing Validate test file); `vendor/bin/phpcs` on the touched files. Commit: "Descriptions may carry formatting; a task may carry a checklist".

### Task 2: The editor

`src/kit/RichText.tsx`:

```tsx
export function RichText( { id, value, onChange, label, testId, minHeight = 96 } )
```

- A wrapper `div.fk-rich` with a toolbar (`role="toolbar"`, buttons: Bold, Italic, Underline, Bulleted list, Numbered list, Link, Clear formatting — lucide icons `Bold, Italic, Underline, List, ListOrdered, Link2, RemoveFormatting`, each `aria-label`ed, `type="button"`, `onMouseDown={ e => e.preventDefault() }` so the selection survives) and the editable `div.fk-rich-area` (`contentEditable`, `role="textbox"`, `aria-multiline`, `aria-labelledby`/`aria-label`, `id`, `data-testid={ testId }`).
- Commands via `document.execCommand( 'bold' | 'italic' | 'underline' | 'insertUnorderedList' | 'insertOrderedList' | 'removeFormat' )`; Link prompts with `window.prompt( 'Link address' )` then `createLink`; Ctrl/Cmd+B/I/U handled in `onKeyDown`.
- `onInput` → `onChange( clean( area.innerHTML ) )`. `clean()` walks a `DOMParser` document: keeps only the allowed tags (b→strong, i→em, div→p, unknown → unwrapped), keeps only `href` on `a` (and only `http(s):`/`mailto:` values), drops empty `p`, returns `''` when the text content is blank.
- Value in: on mount and whenever `value` differs from `clean( area.innerHTML )` while the area is not focused, set `area.innerHTML = fromValue( value )` where a value without `<` becomes paragraphs (`split(/\n{2,}/)` → `<p>`, single `\n` → `<br>`), escaped.
- Paste: `onPaste` prevents default and `execCommand( 'insertText', false, clipboard text )`.
- CSS in `kit.css`: `.fk-rich` bordered like `.fk-input`; toolbar strip on `--surface-wash`; area padding 8px 12px, `min-height`; lists indented; focus ring on the wrapper via `:focus-within`.
- Export from `src/kit/index.ts`. Build. Commit: "An in-house rich text editor in the kit".

### Task 3: Panel, New task, cards, ids

- `ItemPanel`: `EDITABLE` gains `rich: true` on the two; the render uses `<RichText id={ \`bwx-${ field }\` } testId={ \`bwx-${ field }\` } … />` for those. Checklist: `const [ checklist, setChecklist ] = useState< ChecklistRow[] >( [] )` set alongside `setDraft( asDraft( loaded.item ) )` from `loaded.item.checklist`; block under Completed when inside the Task card: eyebrow-less heading "Checklist" (`<p className="bwx-field-label">`), rows `<div className="bwx-check-row" data-testid="bwx-checklist-row">` with `<input type="checkbox" data-testid="bwx-checklist-done">` and `<input className="bwx-input" maxLength={ 191 } data-testid="bwx-checklist-text">` (Enter → add a row below when under 10; Backspace on an empty row removes it), a small "Add a line" (`bwx-checklist-add`, hidden at 10) and the count "n of 10". `save()` sends `checklist: checklist.filter( r => '' !== r.text.trim() )`. `FIELD_LABELS.checklist = 'checklist'`.
- `NewWork`: Item description via `RichText` (`testId="bwx-new-problem"`; the `#bwx-new-problem` id stays on the area). `board.spec.js:256` fills it — change to `page.getByTestId('bwx-new-problem').fill(...)` (Playwright can `fill` a contenteditable).
- `Card.tsx`: after the type chip, `{ 0 < item.checklist.length && <Tag>{ \`${ done }/${ item.checklist.length }\` }</Tag> }` with `data-testid="bwx-card-checklist"`; the short id span goes. `ListView.tsx:66` and `MyTasksScreen.tsx:154` lose their id column/span (My tasks: drop the `ref` column).
- `types.ts`: `export interface ChecklistRow { text: string; done: boolean }`; `WorkItem.checklist: ChecklistRow[]`.
- Build; look at it; commit: "Formatted descriptions, a checklist on every task, and no codes on the cards".

### Task 4: Tests, version, changelog, lint, PR

- `tests/e2e/item-rich-text.spec.js` (admin context, a site + item via helpers): open the panel, click into `bwx-problem`, type a word, select all (`Control+A`), press Bold, save → `GET /work-items/<id>` has `<strong>` in `problem` and the reloaded panel shows it bold (`locator('#bwx-problem strong')`); PATCH `problem` = `<p>Hi</p><script>alert(1)</script><img src=x>` via the API → stored as `<p>Hi</p>`; PATCH `problem` = `<p></p>` → `''`.
- `tests/e2e/item-checklist.spec.js`: add three lines through the panel, tick one, save, reload → the card tag reads `1/3` and the rows come back ticked; PATCH with 11 rows via the API → 400 with a `checklist` field error; a row with a newline → 400.
- Run those two plus `board`, `item-panel-layout`, `item-assignment`, `mytasks`, `list`/`views` specs. 2.116.0; changelog: "Item description and Completed when take bold, italics, lists and links. Every task can carry a checklist of up to ten one-line items — tick them off, save, and the board card shows how many are done. The short item codes are gone from the board, the list and My tasks; the panel still shows them." Lint once; build; restore; commit; push; draft PR `--base task-panel-layout`.
