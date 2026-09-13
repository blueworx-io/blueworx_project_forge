# Forge: Manager Role Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A client-site role, "Forge: Manager", that can use everything Forge offers on the client site except the Connection screen.

**Architecture:** Every client-plugin access check today asks for WordPress's `manage_options` (Administrator). Replace those with two plugin capabilities — `bwx_forge_client_use` and `bwx_forge_client_connect` — owned by one new class, `Blueworx\Forge\Client\Access`. Administrators are granted both through the `user_has_cap` filter (no activation step needed); the new role holds `use` only and is created/repaired on activation and on every plugin version change.

**Tech Stack:** PHP (WordPress plugin, `client/`), PHPUnit with the stubs in `tests/php/bootstrap.php`, Playwright pair suite (`playwright.pair.config.js`, studio :8892 + client :8893).

**Spec:** This plan is its own spec; the agreed design is in the header above and was approved in conversation on 2026-09-13. Managers land on the normal WordPress dashboard after login (no redirect).

## Global Constraints

- Client plugin only: nothing outside `client/` and `tests/` changes except version/changelog.
- Role slug `forge_manager`, display name `Forge: Manager`. Capabilities `bwx_forge_client_use`, `bwx_forge_client_connect`.
- Administrators keep exactly the access they have today.
- Every replaced check keeps its current failure behaviour (`wp_die` 403 / REST 403 / redirect to login); only the capability name changes.
- Version bump: minor (2.99.1 → 2.100.0) in `package.json`, both plugin headers and both version constants; `CHANGELOG.md` entry under `## [2.100.0]`.
- Run PHPCS (`vendor/bin/phpcs <file>`) on every PHP file touched; CRLF "End of line" findings are Windows-checkout noise and can be ignored.
- Local test sites sign in as `admin` / `admin`. Pair suite runs with `WP_ADMIN_USER=admin WP_ADMIN_PASS=admin npx playwright test -c playwright.pair.config.js <spec> --workers=1`. The client site runs a *staged* copy: after any PHP change run
  `node --input-type=module -e "import { stageArtifact } from './bin/stage-artifact.mjs'; stageArtifact('client', 'C:/Users/LukeMcfarland/Documents/GitHub/blueworx_project_forge/.wp-test-client-plugin/blueworx-forge-client');"`
  before running a pair spec.

---

### Task 1: The Access class — capabilities, role, administrator grant

**Files:**
- Create: `client/includes/Access.php`
- Test: `tests/php/ClientAccessTest.php`

**Interfaces:**
- Produces: `Blueworx\Forge\Client\Access::USE` (string `bwx_forge_client_use`), `Access::CONNECT` (`bwx_forge_client_connect`), `Access::ROLE` (`forge_manager`), `Access::ROLE_NAME` (`Forge: Manager`), `Access::with_forge_caps( array $allcaps, array $roles ): array` (pure), `Access::role_caps(): array` (pure), `Access::boot(): void`, `Access::ensure_role(): void`.

- [ ] **Step 1: Write the failing unit test**

```php
<?php
/**
 * Who may use Forge on a client site.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

use Blueworx\Forge\Client\Access;
use PHPUnit\Framework\TestCase;

/**
 * Two capabilities and one role. Administrators get both capabilities
 * without anybody having to grant them; a Forge: Manager gets the one that
 * opens every screen but the connection.
 */
final class ClientAccessTest extends TestCase {

	public function test_an_administrator_can_use_forge_and_manage_the_connection(): void {
		$caps = Access::with_forge_caps( array( 'manage_options' => true ), array( 'administrator' ) );

		$this->assertTrue( $caps[ Access::USE ] );
		$this->assertTrue( $caps[ Access::CONNECT ] );
		$this->assertTrue( $caps['manage_options'], 'nothing they already had is taken away' );
	}

	public function test_anybody_else_is_left_with_what_their_role_gave_them(): void {
		$caps = Access::with_forge_caps( array( 'read' => true ), array( 'editor' ) );

		$this->assertSame( array( 'read' => true ), $caps );
	}

	public function test_the_manager_role_uses_forge_but_does_not_touch_the_connection(): void {
		$caps = Access::role_caps();

		$this->assertTrue( $caps['read'], 'a manager can reach wp-admin at all' );
		$this->assertTrue( $caps[ Access::USE ] );
		$this->assertArrayNotHasKey( Access::CONNECT, $caps );
		$this->assertArrayNotHasKey( 'manage_options', $caps );
	}

	public function test_the_names_are_the_agreed_ones(): void {
		$this->assertSame( 'forge_manager', Access::ROLE );
		$this->assertSame( 'Forge: Manager', Access::ROLE_NAME );
		$this->assertSame( 'bwx_forge_client_use', Access::USE );
		$this->assertSame( 'bwx_forge_client_connect', Access::CONNECT );
	}
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `vendor/bin/phpunit --filter ClientAccessTest`
Expected: errors — `Class "Blueworx\Forge\Client\Access" not found`.

- [ ] **Step 3: Write the class**

```php
<?php
/**
 * Who may use Forge on a client site.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client;

/**
 * Two capabilities and one role.
 *
 * Every screen, the workspace page and every REST route used to ask for
 * `manage_options`, which made "can use Forge" and "is an Administrator" the
 * same question. They are not: a client's project lead should run the work
 * without being able to break the site. So Forge asks for its own
 * capabilities, and who holds them is decided here and nowhere else.
 *
 * Administrators hold both, granted on the fly through `user_has_cap` so
 * nothing has to be written to the database for a site that already has
 * the plugin. The Forge: Manager role holds USE only; it is created on
 * activation and repaired on every version change, so an existing site
 * gets it on update without anybody reactivating anything.
 */
final class Access {

	/**
	 * Every screen but the connection, the workspace page, and every REST
	 * route that reads or writes work.
	 */
	public const USE = 'bwx_forge_client_use';

	/**
	 * The Connection screen, its actions, and the connection REST routes.
	 */
	public const CONNECT = 'bwx_forge_client_connect';

	/**
	 * The role slug and the name people see in Users.
	 */
	public const ROLE      = 'forge_manager';
	public const ROLE_NAME = 'Forge: Manager';

	/**
	 * Remembers which plugin version last checked the role exists.
	 */
	private const ROLE_OPTION = 'bwx_forge_client_role_version';

	/**
	 * Hooks the grant and the role repair up.
	 */
	public static function boot(): void {
		add_filter( 'user_has_cap', array( self::class, 'grant' ), 10, 4 );
		add_action( 'init', array( self::class, 'ensure_role_on_version_change' ) );
	}

	/**
	 * Administrators hold both capabilities. Pure; the filter below feeds it.
	 *
	 * @param array<string, bool> $allcaps What the user already has.
	 * @param array<int, string>  $roles   The user's roles.
	 * @return array<string, bool>
	 */
	public static function with_forge_caps( array $allcaps, array $roles ): array {
		if ( in_array( 'administrator', $roles, true ) ) {
			$allcaps[ self::USE ]     = true;
			$allcaps[ self::CONNECT ] = true;
		}

		return $allcaps;
	}

	/**
	 * What the Forge: Manager role is made of. Pure.
	 *
	 * `read` is what lets somebody into wp-admin at all; without it WordPress
	 * sends them to the front of the site.
	 *
	 * @return array<string, bool>
	 */
	public static function role_caps(): array {
		return array(
			'read'    => true,
			self::USE => true,
		);
	}

	/**
	 * The `user_has_cap` filter.
	 *
	 * @param array<string, bool> $allcaps What the user has.
	 * @param array<int, string>  $caps    The capabilities being asked about (unused).
	 * @param array<int, mixed>   $args    The arguments to current_user_can (unused).
	 * @param \WP_User            $user    The user.
	 * @return array<string, bool>
	 */
	public static function grant( $allcaps, $caps, $args, $user ): array {
		$roles = is_object( $user ) && isset( $user->roles ) && is_array( $user->roles ) ? $user->roles : array();

		return self::with_forge_caps( is_array( $allcaps ) ? $allcaps : array(), $roles );
	}

	/**
	 * Creates the role, or brings an existing one back to exactly role_caps().
	 */
	public static function ensure_role(): void {
		$role = get_role( self::ROLE );

		if ( null === $role ) {
			add_role( self::ROLE, self::ROLE_NAME, self::role_caps() );
			return;
		}

		foreach ( self::role_caps() as $cap => $grant ) {
			$role->add_cap( $cap, $grant );
		}

		// A role somebody widened by hand is narrowed back: the connection is
		// the one thing this role exists to keep away from.
		$role->remove_cap( self::CONNECT );
		$role->remove_cap( 'manage_options' );
	}

	/**
	 * Runs ensure_role() once per plugin version, so a site that updated
	 * without reactivating still gets the role.
	 */
	public static function ensure_role_on_version_change(): void {
		if ( get_option( self::ROLE_OPTION ) === BWX_FORGE_CLIENT_VERSION ) {
			return;
		}

		self::ensure_role();
		update_option( self::ROLE_OPTION, BWX_FORGE_CLIENT_VERSION );
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter ClientAccessTest`
Expected: `OK (4 tests, ...)`. If the bootstrap lacks a stub for a WordPress function this class *calls at load time*, it does not — every WP call is inside a method — so no bootstrap change should be needed.

- [ ] **Step 5: Lint and commit**

Run: `vendor/bin/phpcs client/includes/Access.php tests/php/ClientAccessTest.php`
Expected: no errors other than CRLF line-ending noise.

```bash
git add client/includes/Access.php tests/php/ClientAccessTest.php
git commit -m "Client plugin: two Forge capabilities and a Forge: Manager role"
```

---


---

### Task 2: Wire Access into boot and activation

**Files:**
- Modify: `client/includes/Plugin.php:49-51` (boot) and `client/includes/Plugin.php:90-91` (activate)

**Interfaces:**
- Consumes: `Access::boot()`, `Access::ensure_role()` from Task 1.

- [ ] **Step 1: Hook the grant and role repair up in boot()**

In `Plugin::boot()`, before the first `add_action( 'rest_api_init', ...` line, add:

```php
		// Who may use Forge here is decided by Access, not by whether somebody
		// happens to be an Administrator.
		Access::boot();
```

- [ ] **Step 2: Create the role on activation**

In `Plugin::activate()`, directly after `update_option( 'bwx_forge_client_installed_version', BWX_FORGE_CLIENT_VERSION );` add:

```php
		Access::ensure_role();
```

- [ ] **Step 3: Confirm the plugin still loads**

Run: `php -l client/includes/Plugin.php && vendor/bin/phpunit`
Expected: `No syntax errors`, all unit tests OK.

- [ ] **Step 4: Commit**

```bash
git add client/includes/Plugin.php
git commit -m "Client plugin boots Access and creates the Forge: Manager role on activation"
```

---


---

### Task 3: Every screen, page and route asks for the Forge capability

**Files:**
- Modify (replace `'manage_options'` with `Access::USE`): `client/includes/Admin/Screen.php`, `client/includes/Admin/AskScreen.php`, `client/includes/Admin/AskedScreen.php`, `client/includes/Admin/BoardScreen.php`, `client/includes/Admin/TimelineScreen.php`, `client/includes/Admin/CalendarScreen.php`, `client/includes/Admin/ChecklistScreen.php`, `client/includes/Admin/WorkScreen.php`, `client/includes/Admin/ItemScreen.php`, `client/includes/Admin/AskActions.php`, `client/includes/Admin/ChecklistActions.php`, `client/includes/Admin/ItemActions.php`, `client/includes/Frontend.php`, `client/includes/Rest/WorkspaceController.php`
- Modify (replace with `Access::CONNECT`): `client/includes/Admin/ConnectionScreen.php`, `client/includes/Admin/ConnectionActions.php`, `client/includes/Rest/ConnectionController.php`, `client/includes/Denial.php:153`
- Test: `tests/pair/client-roles.spec.js` (new)

**Interfaces:**
- Consumes: `Access::USE`, `Access::CONNECT`.

- [ ] **Step 1: Write the failing pair spec**

```js
import { test, expect } from '@playwright/test';
import { signedIn, requireEnvironment, CLIENT_URL } from './helpers/pair.js';

// A Forge: Manager runs the work on the client site without being able to
// break the site. Everything Forge draws opens for them; the Connection —
// the one screen about the arrangement rather than the work — does not.

requireEnvironment();

const RUN = `${Date.now()}${Math.floor(Math.random() * 1000)}`;
const PASSWORD = 'forge-test-pw-4471';

/** A user on the client site holding the Forge: Manager role. */
async function manager(admin) {
  const login = `manager${RUN}`;
  const made = await admin.context.request.post('/wp-json/wp/v2/users', {
    headers: admin.headers,
    data: { username: login, email: `${login}@example.test`, password: PASSWORD, roles: ['forge_manager'] },
  });
  expect(made.status(), await made.text()).toBe(201);
  return login;
}

/** A fresh browser context signed in to the client site as `login`. */
async function signedInAs(browser, login) {
  const context = await browser.newContext({ baseURL: CLIENT_URL });
  const page = await context.newPage();
  await page.goto('/wp-login.php');
  await page
    .waitForFunction(() => document.activeElement?.id === 'user_login', null, { timeout: 5000 })
    .catch(() => {});
  await page.fill('#user_login', login);
  await page.fill('#user_pass', PASSWORD);
  await page.click('#wp-submit');
  await page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php'));
  return { context, page };
}

test.describe('a Forge: Manager on the client site', () => {
  test('sees every Forge screen except the Connection', async ({ browser }) => {
    const admin = await signedIn(browser, CLIENT_URL);
    const login = await manager(admin);
    const { context, page } = await signedInAs(browser, login);

    await page.goto('/wp-admin/admin.php?page=blueworx-forge-client');

    const menu = page.locator('#adminmenu');
    await expect(menu.getByRole('link', { name: 'Overview' })).toBeVisible();
    await expect(menu.getByRole('link', { name: 'Work Board' })).toBeVisible();
    await expect(menu.getByRole('link', { name: 'New Request' })).toBeVisible();
    await expect(menu.getByRole('link', { name: 'Connection' })).toHaveCount(0);

    // Reaching it by URL is refused, not quietly shown.
    const refused = await page.goto('/wp-admin/admin.php?page=blueworx-forge-client-connection');
    expect(refused.status()).toBe(403);

    await context.close();
    await admin.context.close();
  });

  test('can open the workspace page and read the work, but not the connection', async ({ browser }) => {
    const admin = await signedIn(browser, CLIENT_URL);
    const login = await manager(admin);
    const { context, page } = await signedInAs(browser, login);

    await page.goto('/forge/');
    await expect(page.getByTestId('bwx-client-app')).toBeVisible({ timeout: 30_000 });

    const nonce = await page.evaluate(() => window.bwxForgeClientData?.nonce);
    expect(nonce, 'the page handed the app its REST nonce').toBeTruthy();

    const workspace = await context.request.get('/wp-json/blueworx-forge-client/v1/workspace', {
      headers: { 'X-WP-Nonce': nonce },
    });
    expect(workspace.status()).toBe(200);

    const connection = await context.request.get('/wp-json/blueworx-forge-client/v1/connection', {
      headers: { 'X-WP-Nonce': nonce },
    });
    expect(connection.status()).toBe(403);

    await context.close();
    await admin.context.close();
  });
});
```

Before writing it, confirm the three things it assumes and adjust the spec (not the plan's intent) if they differ:
- the Connection screen's page slug: `grep -n "SLUG =" client/includes/Admin/ConnectionScreen.php`
- the workspace and connection REST paths: `grep -n "register_rest_route" -A3 client/includes/Rest/WorkspaceController.php client/includes/Rest/ConnectionController.php`
- the app mount's test id on `/forge/`: `grep -rn "bwx-client-app" src/client tests/pair/client-app-shell.spec.js | head -3`

- [ ] **Step 2: Stage the client plugin and run the spec to watch it fail**

Run:
```
node --input-type=module -e "import { stageArtifact } from './bin/stage-artifact.mjs'; stageArtifact('client', 'C:/Users/LukeMcfarland/Documents/GitHub/blueworx_project_forge/.wp-test-client-plugin/blueworx-forge-client');"
WP_ADMIN_USER=admin WP_ADMIN_PASS=admin npx playwright test -c playwright.pair.config.js tests/pair/client-roles.spec.js --workers=1 --reporter=line
```
Expected: both tests fail. With Tasks 1–2 staged the role exists, so the user is created; the failures are the Overview/Work Board links missing (the menu still asks for `manage_options`). If the user creation itself fails with "role is not editable", Tasks 1–2 are not staged — stage and retry.

- [ ] **Step 3: Replace the checks**

Run this from the repo root; it swaps the literal in the listed files and leaves everything else alone:

```bash
for f in client/includes/Admin/Screen.php client/includes/Admin/AskScreen.php client/includes/Admin/AskedScreen.php client/includes/Admin/BoardScreen.php client/includes/Admin/TimelineScreen.php client/includes/Admin/CalendarScreen.php client/includes/Admin/ChecklistScreen.php client/includes/Admin/WorkScreen.php client/includes/Admin/ItemScreen.php client/includes/Admin/AskActions.php client/includes/Admin/ChecklistActions.php client/includes/Admin/ItemActions.php client/includes/Frontend.php client/includes/Rest/WorkspaceController.php; do
  sed -i "s/'manage_options'/Access::USE/g" "$f"
done
for f in client/includes/Admin/ConnectionScreen.php client/includes/Admin/ConnectionActions.php client/includes/Rest/ConnectionController.php client/includes/Denial.php; do
  sed -i "s/'manage_options'/Access::CONNECT/g" "$f"
done
grep -rn "manage_options" client/includes --include=*.php
```
Expected: the final grep prints only comment lines (e.g. the docblock in `ConnectionController.php:24`, which you should reword to "require the connection capability on *this* site"), no code.

- [ ] **Step 4: Import the class where it is used**

`Access` lives in namespace `Blueworx\Forge\Client`. Files in that namespace (`Frontend.php`, `Denial.php`) need nothing. Files in `Blueworx\Forge\Client\Admin` and `Blueworx\Forge\Client\Rest` need `use Blueworx\Forge\Client\Access;` added to their existing `use` block (or after the `namespace` line if there is none). Check each with:

```
php -l <file>
```
and then `vendor/bin/phpunit` — a missing import surfaces as an "Undefined constant" error the moment a test loads the class.

- [ ] **Step 5: Stage, run the spec, watch it pass**

Same two commands as Step 2.
Expected: `2 passed`.

- [ ] **Step 6: Run the client shell and denial pair specs to prove Administrators lost nothing**

Run: `WP_ADMIN_USER=admin WP_ADMIN_PASS=admin npx playwright test -c playwright.pair.config.js tests/pair/client-app-shell.spec.js tests/pair/client-denials.spec.js tests/pair/site-integration.spec.js --workers=1 --reporter=line`
Expected: all pass.

- [ ] **Step 7: Lint and commit**

Run: `vendor/bin/phpcs client/includes && npx eslint tests/pair/client-roles.spec.js`
Expected: no errors other than CRLF noise.

```bash
git add client/includes tests/pair/client-roles.spec.js
git commit -m "Client screens, workspace and routes ask for Forge's own capabilities (Forge: Manager)"
```

---


---

### Task 4: Version, changelog, full checks, PR

**Files:**
- Modify: `package.json`, `blueworx-forge.php`, `client/blueworx-forge-client.php`, `CHANGELOG.md`

- [ ] **Step 1: Bump to 2.100.0**

```bash
sed -i 's/"version": "2.99.1"/"version": "2.100.0"/' package.json
sed -i "s/Version:     2.99.1/Version:     2.100.0/; s/_VERSION', '2.99.1'/_VERSION', '2.100.0'/" blueworx-forge.php client/blueworx-forge-client.php
node ../bluegroup_core_foundation/scripts/check-plugin-version-sync.mjs
```
Expected: `Plugin header and package.json agree (2.100.0).`

- [ ] **Step 2: Changelog entry**

Insert above `## [2.99.1] - 2026-09-13` in `CHANGELOG.md`:

```markdown
## [2.100.0] - 2026-09-13

### Added

- A "Forge: Manager" role on the client site. A manager can use every Forge
  screen, the workspace page and raise requests, but cannot see or change the
  site's connection to the studio. Administrators keep everything they had.
  Add someone through Users → Add New and pick the role.

```

- [ ] **Step 3: Full local checks**

Run: `npm run lint && vendor/bin/phpunit && composer lint`
Expected: lint clean, unit tests OK, composer lint reporting only CRLF line-ending noise.

- [ ] **Step 4: Commit, push, draft PR**

```bash
git add -A
git commit -m "Bump to 2.100.0"
git push -u origin forge-manager-role
gh pr create --draft --title "Forge: Manager role on the client site" --body "..."
```
Body on the shared template (`.github/PULL_REQUEST_TEMPLATE.md`): what it does (the role, what it can and cannot reach, that administrators are unchanged, how to add someone), tested (unit tests for the capability rules, pair spec signing in as a manager), version 2.100.0, changelog updated, no new dependencies.

---

## Self-review

- Spec coverage: role name and slug (Task 1), Managers get everything but Connection (Tasks 1, 3), Administrators unchanged (Task 1 grant + Task 3 Step 6), created on activation and update (Tasks 1–2), hidden menu item and refused URL (Task 3 spec), normal dashboard landing (nothing added — default WordPress), tests (Tasks 1, 3), version/changelog (Task 4).
- Placeholders: the PR body in Task 4 says what to write rather than giving verbatim prose; everything else is concrete.
- Names: `Access::USE`, `Access::CONNECT`, `Access::ROLE`, `Access::ROLE_NAME`, `with_forge_caps`, `role_caps`, `ensure_role`, `boot` are used consistently across Tasks 1–3.
