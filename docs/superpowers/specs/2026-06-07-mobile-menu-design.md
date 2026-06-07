# Mobile Navigation — Design Spec

**Issue:** [#10 Create Mobile Menu](https://github.com/blueworx-io/forge_project_management/issues/10)
**Date:** 2026-06-07
**Branch:** `feature/10-mobile-menu`

## Goal

Give the app a proper mobile navigation experience below 640px, with transition
animations. Today the desktop top-header tab group collapses to cramped icon-only
buttons on small screens. Replace that on mobile with a fixed bottom tab bar plus a
left slide-in drawer. Desktop navigation is unchanged.

## Scope

**In scope**
- Responsive switch at the 640px breakpoint (reuse the existing `useIsMobile` hook).
- Mobile-only bottom tab bar: Timeline · Kanban · Calendar · Menu.
- Mobile-only left slide-in drawer opened by the Menu tab; contains project info and
  the admin-only Settings entry.
- Transition animations (drawer slide + scrim fade + staggered items; bottom-bar active
  indicator).
- Layout padding so fixed bottom bar does not obscure content.
- Patch version bump.

**Out of scope**
- Any change to desktop (≥640px) navigation.
- New navigation destinations beyond the four that exist today.
- New dependencies (animation done with CSS only).
- Restructuring view-mount / `visited` logic in `App.tsx`.

## Behaviour

### Responsive switch
- `useIsMobile(640)` drives the choice.
- **Desktop (≥640px):** the existing top-header tab-button group and Settings button
  render exactly as today.
- **Mobile (<640px):** that top tab group + Settings button are not rendered; the header
  keeps only the project title. `<MobileNav/>` renders instead.

### Bottom tab bar (mobile only)
- Fixed to the viewport bottom, full width, ~56px tall, white with a top border,
  `z-index` above content and at/above the sticky header's stacking context.
- Four equal tabs, icon over short label:
  - Timeline (`BarChart3`), Kanban (`LayoutGrid`), Calendar (`Calendar`) → call
    `switchView(view)`.
  - Menu (`Menu`) → toggles the drawer open.
- The active view tab is highlighted in primary blue (`#2563eb`). An active indicator
  (pill/underline) animates between tabs on change.
- When the drawer is open, the Menu tab reads as active.

### Left slide-in drawer (mobile only)
- Overlay follows the existing `AddItemModal` pattern: a `position:fixed; inset:0`
  container with an `rgba(0,0,0,0.45)` scrim; rendered inline in the component tree
  (no portal), consistent with current modals.
- Drawer panel anchored to the left edge, ~80% width (max ~320px), full height, white.
- Contents, top to bottom:
  - Project name (`settings.projectName`) + description line.
  - Settings entry (`SettingsIcon` + label) — **only when `adminMode` is true** — calls
    `openSettings()` then closes the drawer.
- Closes on: tap scrim, tap any drawer item, Escape key, or the X close button.
- Body scroll behind the drawer is locked while open (match modal behaviour if present;
  otherwise acceptable to skip).
- Non-admin users see a drawer with project info only; the Menu tab still appears for
  everyone.

### Animations (CSS only — transitions + injected `<style>` keyframes)
- Drawer panel: `translateX(-100%) → translateX(0)`, ~250ms ease-out on open; reverse on
  close.
- Scrim: opacity `0 → 1` over the same duration.
- Drawer items: staggered fade/slide-in (small incremental delay per item).
- Bottom-bar active indicator: slide/scale + color transition (~150–200ms), matching the
  `transition: 'all 0.15s'` feel used elsewhere.

### Layout impact
- On mobile, add bottom spacing equal to the bar height so content and scroll areas are
  not hidden behind the fixed bar.
- Take care with the Calendar view, which uses `minHeight:100dvh` and
  `overflow:visible` on `App`'s root/`main`; ensure the bottom bar still clears its
  content (use padding-bottom on the scroll container rather than shrinking `100dvh`).

## Components & files

- **New:** `src/app/components/MobileNav.tsx`
  - Renders both the bottom bar and the drawer (tightly coupled).
  - Drawer open/closed state held locally via `useState`.
  - Props: `currentView`, `switchView`, `openSettings`, `adminMode`, `settings`.
- **Edit:** `src/app/App.tsx`
  - Import `useIsMobile` and `MobileNav`.
  - Render the desktop tab group + Settings button only when `!isMobile`.
  - Render `<MobileNav/>` only when `isMobile`.
  - Apply mobile bottom spacing to the content area.
- **Edit:** `forge-project-management.php` + `package.json` — patch version bump, kept in
  sync.

## Acceptance criteria

1. At ≥640px the navigation is visually identical to today.
2. At <640px the top tab group and Settings button are gone; a bottom bar with
   Timeline / Kanban / Calendar / Menu is fixed at the bottom.
3. Tapping Timeline/Kanban/Calendar switches the view and highlights the active tab with
   an animated indicator.
4. Tapping Menu opens a drawer that slides in from the left over a fading scrim; it shows
   project info and (for admins) Settings.
5. The drawer closes via scrim tap, item tap, Escape, and the X button.
6. Content is never hidden behind the fixed bottom bar on any of the three views.
7. `npm run lint` and `npm run build` pass; no new dependencies added.

## Requires human review
- Visual confirmation in the browser (`npm run dev`) across the three views before
  commit/PR, per project workflow.
- Decision confirmation: Menu tab remains visible for non-admins (drawer shows project
  info only).
