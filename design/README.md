# Design intake — BlueWorx | Labs | Forge Command

Reference material for the rebuild. **Nothing in this folder ships**: it is excluded from
the plugin zip by `exclude_paths` in both CI workflows and by `bin/build-zip.sh`.

## Source

| | |
|---|---|
| Claude Design project | `BlueWorx \| Labs \| Forge Command` |
| Project ID | `8ada8730-32ad-4fca-a5ec-165186491f92` |
| Design system | Labs \| Forge Command **v3**, 17 Aug 2026 |
| Bound at | `_ds/labs-forge-command-8dd30bb5-a4da-4849-bd99-834f29c7d50c/` in the project |
| Imported | 17 Aug 2026, via the `DesignSync` tool |

The project remains readable in full through `DesignSync` (`list_files`, `get_file`), so
anything not copied here can be pulled on demand. That is the intended way to work: read a
screen's source when you build that screen, rather than carrying a stale copy of everything.

## What is here

`_ds/labs-forge-command/` — the complete v3 token layer and its root stylesheet. This is the
visual contract, so it is pinned locally rather than read on demand: the build compiles
against these values, and they must not move underneath it without a deliberate re-import.

| File | Holds |
|---|---|
| `styles.css` | The `@import` list. Consumers link this one file |
| `tokens/colors.css` | Neutral ramp, brand ramp, semantic states, surfaces, borders |
| `tokens/typography.css` | Inter + JetBrains Mono, the 11–68px scale, weights capped at 500 |
| `tokens/spacing.css` | Spacing scale, app-shell sizes, radii, control heights |
| `tokens/effects.css` | Shadows, motion, focus ring |
| `tokens/areas.css` | The four work-area hues, as markers only |
| `tokens/tiles.css` | The seven feature-tile hues, and the chart ramp |
| `tokens/workflow.css` | Workflow phase, gate and exception colours |

## What is deliberately not here

- **The component bundle** (`_ds_bundle.js`) and the 44 components' sources. Pull the one you
  need, when you need it.
- **The UI kits** (`ui_kits/primary/`, `ui_kits/client/`) — twelve and five screens
  respectively. Read a screen when building that screen.
- **The screenshots and uploads** — binaries, including the product brief `.docx`. The brief's
  full text is readable as `research/brief.txt` in the project.
- **The project's root `components/`, `guidelines/`, `tokens/` and `templates/` folders.** The
  project's own readme says these are earlier local material, **superseded by `_ds/`, kept for
  reference only — do not paint from it**. They were in the import list; they are excluded on
  purpose. `_ds/` is the authority.

## Rules the design system states, that the build must honour

Recorded here because they are decisions, not preferences, and a reviewer needs them to hand:

- **Light theme only.** There is no dark token set, and adding one means pairing every value.
- **No gradients anywhere**, including the primary button. No photography, illustration,
  pattern or texture. The visual content is the data.
- **Font weight never exceeds 500.** Hierarchy is size, tracking and colour.
- **Inter for text, JetBrains Mono for every figure** — hours, balances, IDs, counts,
  percentages, versions, timestamps — with tabular figures so columns align.
- **One filled primary action per view.** Repeated row-level actions are white secondary pills.
- **Three colour systems that never overlap:** semantic (a condition, always written out beside
  the colour), area (a marker — rail, dot, chip; never a fill), tile (a destination or category
  inside an `IconTile`; never a state).
- **Motion is colour and shadow only**, 0.15–0.4s. No transform, no lift, no entrance
  animation. Board cards do not animate between stages: a transition is a server round-trip
  with a result, and animating it would lie about the gate.
- **Focus is never removed** — 1.5px Signal Blue ring plus a 4px halo.
- **Copy is British English, sentence case, specification-plain.** Buttons are verb-led and
  specific. No emoji, no exclamation marks. Every recorded reason (return, block, override,
  decline) appears verbatim in the changelog, so the UI always shows that field.
- **Icons are a flagged substitution** — Lucide 0.417.0 stands in because no Forge icon set was
  supplied. Icons never carry meaning alone.

## Known gap

The design system was built against the **old** Forge plugin (it read `main` on 17 Aug 2026 and
took its neutrals from `src/styles/theme.css`). That app is the one being replaced. The values
it contributed are the ones we keep; the screens are all new.
