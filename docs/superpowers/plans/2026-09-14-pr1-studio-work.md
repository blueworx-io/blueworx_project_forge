# PR 1 — Studio work and tidy-ups: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The studio can log its own work in Forge, administrators can delete work, and four small board/standup tidy-ups.

**Architecture:** A `Tenancy\Studio` class owns "the studio's own client" as one option pointing at a client and a site created on first run. Delete is a new REST route over a cascading `Items::delete`. The app grows a `siteLabel` helper, an "All clients" picker choice backed by a new list-scoped route, a collapsed-by-default standup section, and an admin-only Delete in the item panel.

**Tech Stack:** PHP 7.4+ (WordPress plugin, own tables via `$wpdb`), React 18 + TypeScript + Vite, Playwright against the local WordPress harness, PHPUnit without WordPress.

**Spec:** `docs/superpowers/specs/2026-09-14-studio-work-recurring-surecart-slack-design.md` (section "PR 1").

## Global Constraints

- Branch `studio-work`, off `main`. Never merge; open a draft PR.
- Version `2.101.0` in `package.json`, `blueworx-forge.php` (header + `BWX_FORGE_VERSION`), `client/blueworx-forge-client.php` (header + constant). Changelog entry under `## [2.101.0] - 2026-09-14`.
- No new dependency.
- PHP follows WordPress Coding Standards (`composer lint`). Own tables are queried with `$wpdb` and the existing `phpcs:ignore` comment style.
- Every new REST route goes through `Server::register_route` with a `scope` declaration (`Boundary`).
- Playwright specs create everything they need (sites are reused between runs); unique labels via a `RUN_ID`.
- The studio's local test site is live-linked: do not run `npm run build` or edit PHP while a Playwright run is in progress.
- Do not run lint in a loop; once at the end.

---

### Task 1: `Tenancy\Studio` — the studio's own client

**Files:**
- Create: `includes/Tenancy/Studio.php`
- Modify: `includes/Plugin.php:44` (call `Studio::ensure()` after `maybe_upgrade()`)
- Modify: `includes/Rest/ClientSitesController.php:192-195` (mark `studio => true`)
- Test: `tests/php/StudioTest.php`

**Interfaces:**
- Produces: `Studio::OPTION = 'bwx_forge_studio'`; `Studio::ensure(): void`; `Studio::client_id(): string`; `Studio::site_id(): string` (empty string when not yet made); `Studio::is_studio_site( string $site_id ): bool`; `Studio::needs_creating( $option, ?array $client ): bool` (pure).

- [ ] **Step 1: Write the failing unit test** for the pure decision:

```php
<?php
declare( strict_types = 1 );
use Blueworx\Forge\Tenancy\Studio;
use PHPUnit\Framework\TestCase;

final class StudioTest extends TestCase {
	public function test_nothing_recorded_means_create(): void {
		self::assertTrue( Studio::needs_creating( false, null ) );
	}
	public function test_recorded_and_present_means_nothing_to_do(): void {
		self::assertFalse( Studio::needs_creating( array( 'client_id' => 'cli_1', 'site_id' => 'cst_1' ), array( 'id' => 'cli_1' ) ) );
	}
	public function test_recorded_but_gone_means_create_again(): void {
		self::assertTrue( Studio::needs_creating( array( 'client_id' => 'cli_1', 'site_id' => 'cst_1' ), null ) );
	}
	public function test_malformed_option_means_create(): void {
		self::assertTrue( Studio::needs_creating( 'garbage', null ) );
	}
}
```

- [ ] **Step 2: Run** `vendor/bin/phpunit tests/php/StudioTest.php` — expect "Class not found".

- [ ] **Step 3: Implement** `includes/Tenancy/Studio.php`:

```php
final class Studio {
	public const OPTION = 'bwx_forge_studio';

	public static function needs_creating( $option, ?array $client ): bool {
		if ( ! is_array( $option ) || '' === (string) ( $option['client_id'] ?? '' ) || '' === (string) ( $option['site_id'] ?? '' ) ) {
			return true;
		}
		return null === $client;
	}

	public static function ensure(): void {
		$option = get_option( self::OPTION, false );
		$client = is_array( $option ) && isset( $option['client_id'] ) ? Clients::get( (string) $option['client_id'] ) : null;
		if ( ! self::needs_creating( $option, $client ) ) {
			return;
		}
		$name   = trim( wp_strip_all_tags( (string) get_bloginfo( 'name' ) ) );
		$name   = '' === $name ? 'Studio' : $name;
		$domain = (string) substr( strrchr( (string) get_option( 'admin_email' ), '@' ), 1 );
		$client = Clients::create( array( 'display_name' => $name, 'timezone' => wp_timezone_string(), 'email_domains' => '' === $domain ? array() : array( $domain ) ), 0 );
		if ( null === $client ) { return; }
		$site = ClientSites::create( (string) $client['id'], array( 'name' => $name, 'url' => home_url( '/' ) ), 0 );
		if ( null === $site ) { return; }
		update_option( self::OPTION, array( 'client_id' => (string) $client['id'], 'site_id' => (string) $site['id'] ), true );
	}

	public static function client_id(): string { /* read option, '' when absent */ }
	public static function site_id(): string { /* same */ }
	public static function is_studio_site( string $site_id ): bool { return '' !== $site_id && $site_id === self::site_id(); }
}
```

Note `Clients::create` validates nothing; run values through `Validate::client` first if it rejects an empty legal name (check `includes/Tenancy/Validate.php`). Author `0` is "the system"; check `created_by` is `bigint` and accepts it.

- [ ] **Step 4: Wire** `Tenancy\Studio::ensure();` in `Plugin::boot()` directly after `Data\Schema::maybe_upgrade();`. In `ClientSitesController::all()` add `$site['studio'] = Studio::is_studio_site( (string) $site['id'] );` inside the loop.

- [ ] **Step 5: Run** the unit test — PASS. Then `npm run wp:up` and `curl` `/client-sites` signed in (or use a quick Playwright check in Task 7) to see the studio site with `"studio":true`.

- [ ] **Step 6: Commit** `git commit -m "The studio has a client of its own, made on first run"`.

---

### Task 2: Naming the studio on the Clients screen

**Files:**
- Modify: `includes/Admin/ClientsScreen.php:129-150` (a "Studio" panel before the client list)
- Modify: `includes/Admin/ClientActions.php:39-50, 199` (action `bwx_forge_rename_studio`)
- Test: `tests/e2e/studio-client.spec.js` (Task 7 covers it)

- [ ] **Step 1: Screen.** Add `private static function studio_panel(): void` rendering `Page::panel_open( 'The studio', 'studio' )`, a sentence naming which client is the studio's own, and a one-field form (`display_name`, submit "Save name") posting to `admin-post.php` with `action=bwx_forge_rename_studio`, `wp_nonce_field( 'bwx_forge_rename_studio' )`, `record_version` hidden. Call it from `render()` after `self::notice()`. When `Studio::client_id()` is empty, the panel says the client will be created on the next load.

- [ ] **Step 2: Action.** `rename_studio()` : `require_admin()`, `check_admin_referer( 'bwx_forge_rename_studio' )`, load the studio client, run `Validate::client( array_merge( current values, [ 'display_name' => posted ] ), true )`, `Clients::update( id, values, version )`, `back( 'added' | 'stale' | 'invalid' )`. Register it in `boot()`.

- [ ] **Step 3: Commit** `git commit -m "The studio can be renamed on the Clients screen"`.

---

### Task 3: `Items::delete` and `DELETE /work-items/{id}`

**Files:**
- Modify: `includes/Work/Items.php` (add `delete`, `descendants`)
- Modify: `includes/Rest/WorkItemsController.php:80` (register route), add `destroy()`
- Modify: `includes/Rest/Permissions.php` (nothing new; use `manage`)
- Test: `tests/php/ItemsDeleteTest.php` (pure ordering), `tests/e2e/work-delete.spec.js`

**Interfaces:**
- Produces: `Items::descendants( string $id ): array` (ids, deepest first); `Items::delete( string $id ): int` (count removed, 0 when absent); route `DELETE /work-items/{item_id}` → `{ ok: true, deleted: n }`.
- Consumes: `Schema::dependencies_table()`, `work_events_table()`, `gate_records_table()`, `comments_table()`, `submissions_table()`.

- [ ] **Step 1: Unit test** the ordering helper `Items::delete_order( array $rows, string $root ): array` (pure: given `[ id, parent_id ]` rows, returns the root and every descendant, children before parents):

```php
public function test_children_come_before_parents(): void {
	$rows = array(
		array( 'id' => 'a', 'parent_id' => '' ),
		array( 'id' => 'b', 'parent_id' => 'a' ),
		array( 'id' => 'c', 'parent_id' => 'b' ),
		array( 'id' => 'x', 'parent_id' => '' ),
	);
	self::assertSame( array( 'c', 'b', 'a' ), Items::delete_order( $rows, 'a' ) );
}
```

- [ ] **Step 2: Run** — fails (method missing).

- [ ] **Step 3: Implement.** `delete_order` builds a child map and walks depth-first, appending on the way out. `delete( $id )`: `get( $id )` or return 0; read `id, parent_id` for the whole site; `$order = delete_order(...)`; for each id: `$wpdb->delete` on dependencies (`item_id` and `depends_on_id`), work_events, gate_records, comments; `$wpdb->update( submissions, [ 'converted_item_id' => '' ], [ 'converted_item_id' => $id ] )`; then `$wpdb->delete( work_items, [ 'id' => $id ] )`. Return count. Hour ledger and notification records stay (they are the record of what happened).

- [ ] **Step 4: Route.** Register:

```php
Server::register_route( $route_namespace, '/work-items/(?P<item_id>[A-Za-z0-9_\-]+)', array(
	'methods'             => 'DELETE',
	'callback'            => array( self::class, 'destroy' ),
	'permission_callback' => array( Permissions::class, 'manage' ),
	'scope'               => array( 'kind' => Boundary::SCOPE_ITEM, 'param' => 'item_id', 'record' => 'work_item' ),
) );
```

`destroy()` : `$deleted = Items::delete( id )`; 0 → `Boundary::absent( 'work_item' )`; else `{ ok, deleted }`.

- [ ] **Step 5: Playwright** `tests/e2e/work-delete.spec.js`: admin seeds a site, a parent item and a child (`parent_id`); `DELETE` as admin → 200, `deleted: 2`, `GET` the parent → 404. Then a subscriber (`makePerson` with role `viewer`) `DELETE` on another item → 403 and the item still loads.

- [ ] **Step 6: Run** `npm test -- tests/e2e/work-delete.spec.js` — PASS.

- [ ] **Step 7: Commit** `git commit -m "Administrators can delete work, and everything under it"`.

---

### Task 4: Delete in the item panel

**Files:**
- Modify: `src/components/ItemPanel.tsx` (panel header actions area near `Close`)
- Modify: `src/components/WorkScreen.tsx:544` (onChanged already reloads; add `onDeleted` closing the panel)
- Test: `tests/e2e/work-delete.spec.js` (extend)

- [ ] **Step 1: UI.** When `forgeData()?.canManage` is true and the item is loaded, show a `Delete` button (`data-testid="bwx-item-delete"`, kit `Button` with `variant="danger"` if the kit has one, else `className="bwx-button" data-variant="danger"`). Click → `window.confirm( count > 0 ? `Delete this and the ${count} item(s) under it? This cannot be undone.` : 'Delete this item? This cannot be undone.' )`. The count comes from `detail.children?.length ?? 0` (check the `Detail` type for the children list the panel already shows; if it only has `child_count`, use that). On confirm: `await api( `/work-items/${ itemId }`, { method: 'DELETE' } )`, then `onChanged(); onClose();`. Errors go to `notice`.

- [ ] **Step 2: Playwright.** As admin, open the item on the board, click Delete (accept the dialog with `page.on('dialog', d => d.accept())`), assert the card is gone. As the viewer, open an item and assert `bwx-item-delete` is not present.

- [ ] **Step 3: Build** `npm run build` (writes `assets/`), run the spec — PASS. Commit `git commit -m "Delete in the item panel, for administrators"`.

---

### Task 5: Anvil menu icon

**Files:**
- Modify: `includes/Admin/SitesScreen.php:41-49`
- Modify: `includes/Admin/Page.php` (`chrome()` gets the menu CSS; `enqueue` runs only on our screens, so add a second tiny `admin_head` print for the icon on every admin screen — `Page::menu_icon_css()` hooked in `Plugin::boot()` on `admin_head`)
- Test: `tests/e2e/sites-screen.spec.js` (extend: the menu item's `.wp-menu-image` has a background/mask image containing `svg`)

- [ ] **Step 1: Icon.** In `SitesScreen`, `private static function icon(): string` returns `'data:image/svg+xml;base64,' . base64_encode( $svg )` where `$svg` is the lucide anvil with `stroke="#a7aaad"` (24×24, fill none, stroke-width 2, round caps/joins). Pass `self::icon()` to `add_menu_page`.

- [ ] **Step 2: Colour.** `Page::menu_icon_css()` prints, on every admin page: `#adminmenu .toplevel_page_blueworx-forge-sites .wp-menu-image { background-image: none !important; } #adminmenu .toplevel_page_blueworx-forge-sites .wp-menu-image::before { content: ''; display: block; width: 20px; height: 20px; margin: 7px auto 0; background-color: currentColor; -webkit-mask: url("<data-uri with stroke=white>") center / 20px 20px no-repeat; mask: url(...) center / 20px 20px no-repeat; }`. `currentColor` follows the menu's normal/hover/current colours, which is what the painter would have done. Keep the base64 SVG in one place: a `Admin\MenuIcon` class with `svg( string $stroke ): string` and `data_uri( string $stroke ): string`, used by both.

- [ ] **Step 3: Verify** by loading wp-admin in Playwright and asserting the computed `mask-image` of the `::before` contains `data:image/svg+xml`. Commit `git commit -m "The Forge menu wears the anvil"`.

---

### Task 6: `siteLabel`, "All clients", parent default, standup collapsed

**Files:**
- Create: `src/sites.ts`
- Modify: `src/components/WorkScreen.tsx:120-160, 265-300, 430, 551`, `src/components/NewWork.tsx`, `src/components/OnboardingScreen.tsx:385`, `src/components/MyTasksScreen.tsx` (wherever a site is named), `src/components/Card.tsx` (client chip), `src/components/ListView.tsx` (client column)
- Modify: `includes/Rest/WorkItemsController.php` (route `GET /work-items-all`, `SCOPE_LIST`)
- Modify: `includes/Work/Items.php` (`for_sites( array $ids, array $narrow )`)
- Modify: `src/components/StandupScreen.tsx:260-335`
- Test: `tests/e2e/site-picker.spec.js`, `tests/e2e/standup-board.spec.js` (extend)

**Interfaces:**
- Produces: `siteLabel( site: SiteOption, sites: SiteOption[] ): string`; `ALL_SITES = 'all'`; `rememberSite( id )` / `recallSite( sites ): string` (localStorage `bwx-forge-site`; falls back to the `studio` site, then the first).
- Route `GET /work-items-all` → same shape as `/work-items` (items carry `client_id`, `client_site_id`, plus `client_name` and `site_name` added by the controller from one `ClientSites::all()` + `Clients::all()` read).

- [ ] **Step 1: `src/sites.ts`** with the three functions. `siteLabel`: count sites sharing `client_id`; one → `client_name || name`; several → `${ client_name } — ${ name }`; no client name → `name`.

- [ ] **Step 2: Backend.** `Items::for_sites( $ids, $narrow )`: same query as `for_site` with `client_site_id IN (...)` (return `[]` for an empty list). Controller `index_all()`: `$reach = Boundary::current()`; sites = `Reach::keep_sites( $reach, ClientSites::all( 'active' ), 'id' )`; items = `for_sites( ids )`, derive per site with the existing `derive_across`, apply `Filters`, attach `client_name`/`site_name`. Register `'/work-items-all'` with `SCOPE_LIST`.

- [ ] **Step 3: Picker.** In `WorkScreen`, sites list gets a leading `{ id: 'all', name: 'All clients' }` option; `loadItems` uses `/work-items-all?…` when `siteId === 'all'`. Initial `siteId` = `recallSite( siteList.sites )`; `onChange` calls `rememberSite`. `NewWork` under `all`: a site `<select>` at the top (default the studio site) — `clientSiteId` prop becomes `sites` + `defaultSiteId` when `'all'`; otherwise unchanged. `Card` shows `item.client_name` as a small chip (`data-testid="bwx-card-client"`) when present; `ListView` a "Client" column when any item has one.

- [ ] **Step 4: Standup.** In `Section`, `const [ open, setOpen ] = useState( () => remembered( id ) )`; the head becomes a `<button aria-expanded>` (or the title gets a toggle button, `data-testid="bwx-standup-section-toggle"`); cards render only when `open`; `sessionStorage` `bwx-forge-standup-open` holds the open ids (try/catch around storage).

- [ ] **Step 5: Playwright** `site-picker.spec.js`: two seeded clients (one with one site, one with two) → option labels follow the rule; first option is "All clients"; the studio site is selected on a fresh context (clear localStorage); selecting "All clients" shows cards from both seeded sites with client chips; reload keeps "All clients". `standup-board.spec.js`: a section's cards are hidden until its toggle is clicked; reload keeps it open.

- [ ] **Step 6: Build, run both specs, commit** `git commit -m "All clients on the board, the studio by default, tidier site names, standup starts folded"`.

---

### Task 7: Version, changelog, full checks, PR

- [ ] **Step 1:** Bump to `2.101.0` in the four places; changelog:

```
## [2.101.0] - 2026-09-14

### Added

- Forge now has a client for the studio itself, created automatically, so the studio's own work can be added and tracked like any client's. Rename it on the Clients screen.
- Administrators can delete a piece of work, and everything under it, from the item panel.
- The site picker has an "All clients" choice showing every site's work together, opens on the studio's own site the first time, and remembers your last choice.

### Changed

- The Forge menu uses an anvil icon.
- Site names in the picker are no longer repeated when a client has one site.
- Daily standup sections start folded; open the ones you want.
```

- [ ] **Step 2:** `npm run lint`, `composer lint`, `vendor/bin/phpunit`, `npm run build`, `npm test` (full studio suite, fresh instance: `npm run wp:down && npm run wp:up`). Fix real failures; report lint findings rather than looping.

- [ ] **Step 3:** Commit, push `studio-work`, open a draft PR against `main` with what changed, what was tested, and "visual check outstanding".
