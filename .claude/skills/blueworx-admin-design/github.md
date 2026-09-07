repo: blueworx-io/bluegroup_core_foundation
branch: main
path: .claude/skills/blueworx-admin-design/

## Sync contract

This design system IS the skill folder committed at the `path` above. That folder is the
source; the Claude Design project mirrors it, never the reverse.

- **Code → design.** Pushes go from the committed folder to the design project, one
  component at a time. Never a wholesale replace of the design project.
- **Nothing is deleted in the design project** unless the exact files to delete are named.
- **Design → code only for a component that doesn't exist in code yet**, and only when
  explicitly asked for. Once a component exists in code, it stays code-owned.
- **`SKILL.md` at the folder root.**
- **`styles.css` at the folder root** — that exact filename and location. Each
  plugin copies it to `assets/blueworx-admin-design.css` and CI compares the two
  byte for byte.
- **No minification, no build step, LF line endings.** `styles.css` is one
  self-contained file (no `@import`) so a plugin copying that single file gets the
  whole system. Its `@font-face` rules load
  `fonts/*.woff2`, which resolves both at the folder root and from a plugin's
  `assets/blueworx-admin-design.css` next to `assets/fonts/`.
- **Self-contained.** No path, link or import may point outside the folder.

## Last sync
date: 2026-08-19T00:00:00Z
exported: complete folder (219 files) — SKILL.md + styles.css at root, LF, unminified

### Updated in this project
- Retargeted this project at `bluegroup_core_foundation` · `.claude/skills/blueworx-admin-design/`
  and recorded the sync contract above.
- Flattened the stylesheet: the 19 `tokens/` and `components/**` partials are merged
  into a single self-contained `styles.css` (630 lines, unminified, LF) and deleted, so
  a plugin copying that one file gets the whole system and no duplicate CSS ships.
- Moved the webfonts to `fonts/` at the folder root so one `url("fonts/…")` resolves
  both here and from the plugin's `assets/` copy.
- `readme.md` index and the `wp_enqueue_style` example now name
  `assets/blueworx-admin-design.css`.
- `SKILL.md` now points at `readme.md` (was `README.md` — broken on case-sensitive
  checkouts) and states the copy-verbatim rule for `styles.css` + `fonts/`.
- Dropped `screenshots/` and `uploads/` — working files, unreferenced, not part of the skill.

### Earlier
- Replaced the Dashicons icon font with **Lucide**: geometry copied verbatim from
  `lucide-icons/lucide` into `assets/icons/lucide-icons.js`, `Icon` rewritten to render
  inline SVG, every screen, card and template renamed to Lucide names.
- Copied the BlueWorx brand webfonts (Sora 400/600/700, Inter 400/500/600) and `assets/img/logo.png`.
- Took the brand palette from `assets/css/public.css` `:root` into the colour tokens.
- Read the BlueWorx internal plugin admin CSS (`admin-setup.css`, `admin-content.css`)
  for the real admin patterns behind every component.
- Read `bluegroup_core_foundation` (README, recipe book, WordPress starter prompt) for
  tone of voice and plugin conventions.

## Screen map
| Surface | Built from |
|---|---|
| `styles.css` — colour tokens | bluegroup_project_blueworx · `assets/css/public.css` |
| `styles.css` — `@font-face`, `fonts/*` | bluegroup_project_blueworx · `assets/css/blueworx-fonts.css`, `assets/fonts/` |
| `styles.css` — component rules | BlueWorx internal plugin admin CSS · `admin-setup.css`, `admin-content.css` |
| `assets/img/logo.png` | bluegroup_project_blueworx · `assets/img/logo.png` |
| `components/layout/*`, `components/forms/*` | BlueWorx internal plugin admin CSS · `admin-setup.css`, `admin-content.css` |
| `ui_kits/setup_wizard/*` | BlueWorx internal plugin admin CSS · Setup screen (`admin-setup.css`) |
| `assets/icons/lucide-icons.js` | lucide-icons/lucide@main (ISC) · `icons/*.svg` |
| readme.md — Content fundamentals | bluegroup_core_foundation · `README.md`, `docs/recipe-book.md`, `docs/starter-prompt-wordpress-plugin.md` |

## Sync history
- 2026-08-18T16:15:00Z — repo `blueworx-io/bluegroup_project_blueworx`, path `assets`:
  Lucide icon migration; brand fonts, logo and palette imported.
