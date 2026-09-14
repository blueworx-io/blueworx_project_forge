# PR 2 — Session cache and "Last refreshed": Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Switching screens shows what the app already has instantly, re-checks quietly in the background, and every page header says when its data was last fetched.

**Architecture:** The cache lives inside `api()` so no screen has to change how it loads: a GET with a cached answer resolves at once and a background re-check follows; when the re-check finds a difference the cache updates and a window event fires, and a small hook every screen already can use (`useLiveReload( load )`) re-runs that screen's own `load()`, which now reads the fresh cache. Any write clears the cache. The header reads the newest fetch time from the same module.

**Tech Stack:** React 18 + TypeScript, Playwright.

**Spec:** `docs/superpowers/specs/2026-09-14-studio-work-recurring-surecart-slack-design.md` (section "PR 2"). One deliberate deviation: the cache is **in memory for the tab**, not `sessionStorage`. A page that starts from a stored copy would show stale data on every full load — including in tests that reload and read at once — and the complaint being fixed is screen switching inside the app, which memory alone covers.

## Global Constraints

- Branch `session-cache`, off `studio-work`. Draft PR against `studio-work`.
- Version `2.102.0` in the four places; changelog entry.
- No new dependency. `npm run lint` once at the end.
- Do not build or edit PHP while a Playwright run is in progress.

---

### Task 1: The cache in `api()`

**Files:**
- Create: `src/cache.ts` — pure store, no fetch: `get( path )`, `set( path, value )`, `clear()`, `newest()`, `subscribe( fn )`, TTL 30 minutes, revalidation throttle 5 seconds per path.
- Modify: `src/api.ts` — GET path: cached → return it and revalidate in the background; miss → fetch, store. Non-GET: on success `clear()`.
- Test: `tests/unit/cache.test.mjs` (`node --test`, like the other unit tests; `src/cache.ts` must be importable — write it as plain TS with no DOM, and the test imports the built copy from `assets/`? No: keep the store DOM-free and test it through `npx tsx`? Neither is in the repo. Instead the unit test exercises the logic by `import()` of a `.mjs` twin? Avoid duplication: **write the store as `src/cache.mjs` with JSDoc types**, imported by `api.ts` (Vite handles `.mjs`), and tested directly by `node --test`.)

**Interfaces:**
- `src/cache.mjs`: `export function read( path, now = Date.now() )` → `{ value, at } | undefined` (undefined when absent or older than TTL); `export function write( path, value, now )`; `export function clear()`; `export function newestAt()` → number (0 when empty); `export function shouldRevalidate( path, now )` → boolean (false within 5 s of the last fetch start for that path; marks the start); `export function subscribe( fn )` → unsubscribe; `export function announce( path )` (calls subscribers).
- `src/api.ts`: `api< T >( path, options )` unchanged signature; adds `options.cache?: 'stale-ok' | 'fresh'` (default `stale-ok` for GET); `export function refreshedAt(): number` = `newestAt()`; `export function onRefreshed( fn ): () => void` = `subscribe`; `export function forgetAll()` = `clear()`.

- [ ] Write `tests/unit/cache.test.mjs`: read after write returns the value; read after TTL returns undefined; `shouldRevalidate` true first time, false within 5 s, true after; `clear` empties and `newestAt` returns 0; `announce` reaches subscribers and unsubscribe stops it.
- [ ] Run `node --test tests/unit/cache.test.mjs` — fails (module missing).
- [ ] Implement `src/cache.mjs`; run — passes.
- [ ] Wire `api.ts`:

```ts
if ( 'GET' === method && 'fresh' !== options.cache ) {
  const hit = read( path );
  if ( hit ) {
    if ( shouldRevalidate( path ) ) {
      void fetchJson( path ).then( ( fresh ) => {
        if ( JSON.stringify( fresh ) !== JSON.stringify( hit.value ) ) {
          write( path, fresh );
          announce( path );
        } else {
          touch( path ); // refreshed time moves even when nothing changed
        }
      } ).catch( () => undefined );
    }
    return hit.value as T;
  }
}
const payload = await fetchJson( path, options );
if ( 'GET' === method ) { write( path, payload ); announce( path ); } else { clear(); }
return payload as T;
```

`fetchJson` is the existing body of `api()` moved into a function. Errors on the background path are swallowed: the screen still has what it had. Add `touch( path )` to the store (updates `at` without changing the value).

- [ ] Commit `Reads are kept for the session and re-checked in the background`.

### Task 2: `useLiveReload` and the screens

**Files:**
- Create: `src/live.ts` — `export function useLiveReload( load: () => void | Promise< void > )`: `useEffect` subscribing with `onRefreshed`, calling `load()` when any path announces; the subscription is made once with a ref to the latest `load`.
- Modify: every screen with a `load`/`loadShell` on mount: `WorkScreen` (`loadItems( siteId )` and the shell), `StandupScreen`, `MyTasksScreen`, `CapacityScreen`, `ReportsScreen`, `QueueScreen`, `OnboardingScreen`, `Signals`, `ItemPanel` (its `load()`), `RequestPanel`, `OnboardingPanel`.

- [ ] For each: add `useLiveReload( () => void load() )` next to the mount effect. In `WorkScreen` the reload is `loadItems( siteId )`; the shell lists (stages, sites, saved views) are reloaded only by the header button.
- [ ] The loading state must not flash: screens set `'loading'` before calling `load()` on mount. Leave that; `useLiveReload`'s call goes straight to `load()` without setting `'loading'`, so an update lands in place.
- [ ] Playwright `tests/e2e/session-cache.spec.js`: seed a site with one item; open the board; block the REST API with `page.route( '**/wp-json/blueworx-forge/**', ( r ) => r.abort() )`; click Standup, then Kanban; the card is visible (came from the cache, nothing was fetched). Unroute; create a second item through the API; click Standup then Kanban; the second card appears (the background re-check found it).
- [ ] Commit `Screens reuse what they already have and update in place`.

### Task 3: "Last refreshed" in the page header

**Files:**
- Modify: `src/kit/primitives.tsx` `PageHeader` — it already takes `meta` and `actions`; no change needed unless the right-hand slot needs a class.
- Modify: `src/App.tsx` — a `Refreshed` component: `useState( refreshedAt() )`, subscribes with `onRefreshed` to update; renders `Refreshed 14:32` (`bwx-mono`, `data-testid="bwx-refreshed"`, `toLocaleTimeString` HH:MM) and a `↻ Refresh` quiet button (`data-testid="bwx-refresh-all"`) that calls `forgetAll()` and bumps a `key` on the mounted screen so it loads afresh. Passed to `PageHeader` as `meta`.
- Test: extend `session-cache.spec.js`: the header shows `Refreshed HH:MM`; clicking Refresh re-fetches (`page.waitForRequest( /work-items/ )`).

- [ ] Implement, build, run the spec, commit `Every page says when it was last refreshed`.

### Task 4: Version, changelog, checks, PR

- [ ] `2.102.0`; changelog: "Added — Switching between screens no longer reloads everything: each screen shows what it already has at once and quietly checks for changes. Every page header shows when its data was last refreshed, with a refresh button." Then lint, build, PHPUnit, full Playwright on a fresh instance; push `session-cache`; draft PR against `studio-work`.
