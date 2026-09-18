# Visual Tidy-up — Implementation Plan (block 1 of Luke's feedback, 2026-09-17)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The layout and colour points Luke raised on 2026-09-17 are fixed across the studio app, with no change to what any screen does.

**Architecture:** CSS and markup only, plus two tiny kit additions (a `link` button variant and a toned notice). Every table that sat inside a panel now *is* the panel's body (`Panel flush` + `DataView bare`), which also fixes the People corner bug. Notices carry a tone. No domain, REST or PHP change beyond the version lines.

**Spec:** Luke's feedback messages of 2026-09-17, block 1 ("Visual tidy-up") of the breakdown he approved with "Proceed with the visual tidy up please".

## Global Constraints

- Branch `visual-tidy-up` off `admin-screens-cutover`. Draft PR targets `admin-screens-cutover`. Version `2.114.0` → `2.114.1`; changelog `## [2.114.1] - 2026-09-18`.
- No new dependencies; nothing under `client/` but the version line; restore `client/assets/js/blueworx-forge-client.js` after every build (`git checkout -- client/assets`).
- No behaviour change: every REST call, field and test id stays. Tests change only where the thing they asserted on was removed on purpose (the People card line).
- Never build or edit PHP while a Playwright run is in flight.
- Lint once at the end; findings presented, not looped.

## Decisions (Luke's words → what is built)

- "Tables inside tables" (Clients ×2, Support ×2, Packages ×2, People memberships, Availability ×3): `Panel flush` + `DataView bare`; a hint line above a table gets its own padded strip.
- "Who we work for" header: the "Show everyone…" toggle first, then "Add a client".
- Banners: `.bwx-notice[data-tone='ok']` green, `warn` yellow, default red. Screens set `ok` on the messages `landed()` shows, `warn` on the availability "nobody has said" line; errors stay red. ItemPanel "Saved." and Standup "Recorded." go green too.
- Standing meetings: one card per row.
- Meetings actions: header "Actions"; Move left, Settle right (`bwx-meetings-actions`, space-between); `Button variant="link"` (brand-coloured text); widths trimmed so the week table fits.
- People: the email/"Signs in as" line is gone. A `Tag tone="warn"` "No account" (`bwx-people-card-account`) stays in the card head when they cannot sign in.
- Availability: `align-content: start` on the screen root (and Packages); picker uses the same `.bwx-site-picker` look; the Working week `dl` becomes tiles with room; the hours form grid gets a 12px gap; the three tables use `fixed` so columns fill the width.
- Reports: each `.bwx-report` becomes a card (surface, hairline, radius-xl, padding 16–20); the figures inside go sunken.
- Rail: Intake above Delivery.
- Profile: a 32px pill matching `fk-btn[data-size='sm']`, avatar left, violet tint (`--tile-violet-*` or the closest existing tokens).
- View switcher: Board, Schedule, Calendar, List.
- New task: "Feature" gone from the type list; default type "Task".
- Recurring day picker: seven pill toggles in a row (`.bwx-recurring-day` restyled; the checkbox stays, `appearance: none`, so tests keep clicking it).
- Per-seat hours (ItemPanel `measure()` for the three seats, Recurring `seat()`): a `Select` with exactly 10 min (0.17), 30 min (0.5), 45 min (0.75), 1 h, 1.5 h, 2 h, 2.5 h, 3 h, plus "Not set"; a stored value outside the list is shown as its own option so nothing is lost silently. "Hours still to do" stays a number box.

## File map

| File | Change |
|---|---|
| `src/kit/primitives.tsx`, `src/kit/kit.css` | `link` button variant |
| `src/styles.css` | notice tones; screen roots; meetings cards/actions; availability tiles/grid; reports cards; recurring day pills; profile pill (or `src/shell.css`) |
| `src/components/States.tsx` | `Said` type + `Notice` component |
| `src/hours.ts` (create) | `HOUR_OPTIONS`, `HoursSelect` |
| `src/components/{Clients,Support,Packages,People,Meetings,Availability}Screen.tsx` | flush/bare; toned notices; the per-screen items above |
| `src/components/ItemPanel.tsx`, `RecurringScreen.tsx` | hours select; day pills; "Saved." green |
| `src/components/StandupScreen.tsx` | "Recorded." green |
| `src/components/WorkScreen.tsx`, `NewWork.tsx`, `App.tsx`, `src/shell.css` | switcher order; types; rail order; profile pill |
| `tests/e2e/people-app.spec.js`, `people-accounts.spec.js` | assert the edit form's email / the "No account" tag instead of the removed line |
| `tests/e2e/item-assignment.spec.js`, `recurring-screen.spec.js` | `selectOption` on the hours selects |
| version files, `CHANGELOG.md` | 2.114.1 |

---

### Task 1: Kit and shared pieces
`link` variant; `Notice`/`Said`; `HoursSelect`; notice tone CSS; profile pill; rail order; switcher order; types list. Build. Commit: "Kit: link buttons, toned notices, an hours pick; shell order and profile pill".

### Task 2: The six configuration screens
Flush panels + bare tables; toned notices; Clients header order; Meetings cards and actions; People line; Availability layout. Build; run `clients-app`, `support-app`, `packages-app`, `people-app`, `people-accounts`, `meetings-app`, `availability-app`, `accessibility` (app walk). Commit: "Configuration screens: one frame per table, green for success, tidier headers".

### Task 3: Work surfaces
Reports cards; recurring day pills; seat hours selects (ItemPanel + Recurring); Standup/ItemPanel success tone. Build; run `item-assignment`, `recurring-screen`, `reports`, `standup` specs. Commit: "Reports in cards, day pills, seat hours as a pick".

### Task 4: Version, changelog, lint, PR
2.114.1; changelog in Luke's words; `npm run lint`, `composer lint`; build; restore client bundle; commit `assets/`; push; draft PR `--base admin-screens-cutover`.
