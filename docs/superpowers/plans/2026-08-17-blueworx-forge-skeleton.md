# Blueworx Forge Skeleton Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace Forge Project Management on `main` with Blueworx Forge — a new plugin with no features that installs, boots, serves an empty app page, answers a permission-checked REST namespace, uninstalls cleanly, and proves all of it with tests.

**Architecture:** One WordPress plugin, namespaced PHP (`Blueworx\Forge`) behind a prefix autoloader, with a Vite/React/TypeScript front end built into `assets/` and served by WordPress. The old plugin is deleted from `main` in Task 1 and lives on in the `v1.37.2` release and in git history. No feature, no data model, and no migration tooling is built here.

**Tech Stack:** PHP 8.2+, WordPress 6.5+, PHPCS (WordPress standard), PHPUnit 11, Vite 6, React 18, TypeScript, Tailwind 4, Playwright.

**Spec:** `docs/superpowers/specs/2026-08-17-blueworx-forge-rebuild-design.md`

## Global Constraints

Every task's requirements implicitly include this section.

- **Slug:** `blueworx-forge`. It is the main file name, the folder name inside the zip, the `plugin_slug` workflow input, the third argument to `buildUpdateChecker()`, and the `--slug` the test harness links. All five must agree.
- **Identity:** display name `Blueworx Forge`; namespace `Blueworx\Forge`; constants prefixed `BWX_FORGE_`; functions and options prefixed `bwx_forge_`; text domain `blueworx-forge`.
- **Version:** `2.0.0`, identical in the plugin header, the `BWX_FORGE_VERSION` constant, and `package.json`. CI fails if any two disagree. Never lower it — the version-bump guardrail reads a decrease as no bump.
- **No global name may collide with the old plugin.** Nothing may start `forge_pm_`, and no post type may start `forge_`. Both plugins will be active on the same site during migration.
- **PHPCS:** the full `WordPress` standard, no sniff exclusions. This is new code; write it to the standard rather than sweeping it later. That means docblocks on classes and functions, Yoda conditions, `array()` not `[]`, and tabs for indentation.
- **Every REST route declares an explicit `permission_callback`.** A route that is deliberately public says so in a comment. There is no default.
- **A skipped test is not a passing test.** CI fails a run that executes zero tests.
- **`main` is protected:** pull requests only, and the `guardrails / guardrails` check must pass. Never push to `main`, never merge without green CI, and never push a tag — releases are a separate, human decision.
- **Local WordPress runs on port 8892** (`npm run wp:up`), not the foundation's shared 8881, which another project's harness occupies.
- **`approved-deps.json` must list exactly what `package.json` declares** — no more, no less.
- **Every pull request updates `CHANGELOG.md`.** Entries say what changed for the person using the plugin, in their words.

---

## File Structure

**Created:**

| File | Responsibility |
|---|---|
| `blueworx-forge.php` | Plugin header, constants, update checker, boot call. Nothing else. |
| `uninstall.php` | Deletes this plugin's own options. Nothing else. |
| `includes/autoload.php` | `bwx_forge_register_autoloader()` — maps `Blueworx\Forge\X\Y` to `includes/X/Y.php`. |
| `includes/Plugin.php` | Singleton. `activate()`, `deactivate()`, `boot()`. Wires everything to hooks. |
| `includes/Frontend.php` | The app page, its template, the built assets, and the localised data object. |
| `includes/Rest/Server.php` | Registers the REST namespace and its controllers. |
| `includes/Rest/Permissions.php` | The permission callbacks, as testable static methods. |
| `includes/Rest/StatusController.php` | The skeleton's two routes. |
| `templates/app-page.php` | Full-page template: the mount point and nothing else. |
| `src/main.tsx`, `src/App.tsx`, `src/styles.css` | The React skeleton that mounts and reports it is alive. |
| `tests/php/bootstrap.php` | PHPUnit bootstrap: stubs the WordPress functions the units call. |
| `tests/php/AutoloaderTest.php`, `tests/php/PermissionsTest.php`, `tests/php/UninstallTest.php` | Unit tests. |
| `tests/e2e/activation.spec.js`, `app-page.spec.js`, `rest.spec.js` | Playwright specs against real WordPress. |
| `phpunit.xml.dist` | PHPUnit config. CI runs it because the file exists. |

**Modified:** `package.json`, `approved-deps.json`, `vite.config.ts`, `index.html`, `phpcs.xml.dist`, `bin/build-zip.sh`, `playwright.config.js`, `.github/workflows/ci.yml`, `.github/workflows/release.yml`, `.gitignore`, `CHANGELOG.md`, `CLAUDE.md`, `composer.json`.

**Deleted:** `forge-project-management.php`, `includes/class-*.php` (nine files), `templates/forge-pm-full.php`, all of `src/app/`, `assets/js/forge-app.js`, `assets/css/forge-app.css`, `tests/e2e/activation.spec.js`, `tests/e2e/app-page.spec.js`, `tests/e2e/status-page.spec.js`, `tests/global-setup.js` (rewritten in Task 1).

---

### Task 1: Clear the way and stand up the plugin file

The old plugin leaves and the new one arrives in one commit, because a half-deleted plugin does not boot and cannot be tested. At the end of this task WordPress activates Blueworx Forge with no errors and no features.

**Files:**
- Delete: `forge-project-management.php`, `includes/class-enqueue.php`, `includes/class-page-generator.php`, `includes/class-post-types.php`, `includes/class-rest-api.php`, `includes/class-roles.php`, `includes/class-sample-data.php`, `includes/class-settings.php`, `includes/class-status.php`, `templates/forge-pm-full.php`, `src/app/` (whole directory), `assets/js/forge-app.js`, `assets/css/forge-app.css`, `uninstall.php`, `tests/e2e/activation.spec.js`, `tests/e2e/app-page.spec.js`, `tests/e2e/status-page.spec.js`
- Create: `blueworx-forge.php`, `tests/e2e/activation.spec.js`
- Modify: `package.json`, `phpcs.xml.dist`, `playwright.config.js`, `tests/global-setup.js`, `.github/workflows/ci.yml`, `.github/workflows/release.yml`, `CHANGELOG.md`

**Interfaces:**
- Consumes: nothing.
- Produces: the constants `BWX_FORGE_VERSION` (string `'2.0.0'`), `BWX_FORGE_SLUG` (string `'blueworx-forge'`), `BWX_FORGE_FILE` (absolute path to the main file), `BWX_FORGE_PATH` (directory, trailing slash), `BWX_FORGE_URL` (URL, trailing slash). Every later task reads these.

- [ ] **Step 1: Write the failing test**

Replace `tests/e2e/activation.spec.js` entirely:

```javascript
import { test, expect } from '@playwright/test';

const ADMIN_USER = process.env.WP_ADMIN_USER;
const ADMIN_PASS = process.env.WP_ADMIN_PASS;

// A spec that skips itself is not a spec that passed. Fail loudly instead, so a
// missing secret is a broken build rather than a silently smaller suite.
test.beforeAll(() => {
  if (!ADMIN_USER || !ADMIN_PASS) {
    throw new Error('WP_ADMIN_USER and WP_ADMIN_PASS must be set.');
  }
});

async function signIn(page) {
  await page.goto('/wp-login.php');
  await page.fill('#user_login', ADMIN_USER);
  await page.fill('#user_pass', ADMIN_PASS);
  await page.click('#wp-submit');
  await page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php'));
  await page.goto('/wp-admin/', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('#adminmenu')).toBeVisible();
}

test('the plugin is installed and active', async ({ page }) => {
  await signIn(page);
  await page.goto('/wp-admin/plugins.php', { waitUntil: 'domcontentloaded' });

  const row = page.locator('tr[data-slug="blueworx-forge"], tr#blueworx-forge');
  await expect(row).toHaveCount(1);
  await expect(row).toContainText('Blueworx Forge');
  // Word-boundary match is load-bearing: WordPress renders an inactive row with
  // class="inactive", and the substring "active" inside "inactive" would match
  // /active/ regardless of activation state.
  await expect(row).toHaveClass(/(^|\s)active(\s|$)/);
});

test('activating the plugin raises no PHP error', async ({ page }) => {
  await signIn(page);
  await page.goto('/wp-admin/plugins.php', { waitUntil: 'domcontentloaded' });

  // A fatal on activation surfaces here as an error notice rather than a crash.
  // Filtered to :visible because WP core renders a hidden, empty .notice-error
  // template in every plugin row's auto-updates column.
  await expect(page.locator('#message.error, .notice-error:visible')).toHaveCount(0);
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
npm run wp:down
rm -rf .wp-test
npm run wp:up
WP_ADMIN_USER=admin WP_ADMIN_PASS=wptest-admin-pw npx playwright test tests/e2e/activation.spec.js --workers=1
```

Expected: FAIL — the harness cannot link a plugin called `blueworx-forge` yet, so no such row exists.

Note: `npm run wp:up` still says `--slug forge-project-management` at this point. Step 4 changes it; re-run `npm run wp:down && rm -rf .wp-test && npm run wp:up` after that change so the harness links the new slug.

- [ ] **Step 3: Delete the old plugin**

```bash
git rm -q forge-project-management.php uninstall.php
git rm -q includes/class-enqueue.php includes/class-page-generator.php includes/class-post-types.php includes/class-rest-api.php includes/class-roles.php includes/class-sample-data.php includes/class-settings.php includes/class-status.php
git rm -q templates/forge-pm-full.php
git rm -q -r src/app
git rm -q assets/js/forge-app.js assets/css/forge-app.css
git rm -q tests/e2e/app-page.spec.js tests/e2e/status-page.spec.js
```

`src/main.tsx` and `src/styles/` are replaced in Task 4, not deleted here — deleting them now would break `npm run build`, which CI runs on every pull request.

- [ ] **Step 4: Write the new plugin file**

Create `blueworx-forge.php`:

```php
<?php
/**
 * Plugin Name: Blueworx Forge
 * Plugin URI:  https://github.com/blueworx-io/blueworx_project_forge
 * Description: Product planning and release management for WordPress.
 * Version:     2.0.0
 * Requires at least: 6.5
 * Requires PHP: 8.2
 * Author:      Blueworx
 * Author URI:  https://blueworx.io
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: blueworx-forge
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The plugin version. Must equal the Version: header above and the version in
 * package.json — CI fails the build if any two disagree.
 */
define( 'BWX_FORGE_VERSION', '2.0.0' );
define( 'BWX_FORGE_SLUG', 'blueworx-forge' );
define( 'BWX_FORGE_FILE', __FILE__ );
define( 'BWX_FORGE_PATH', plugin_dir_path( __FILE__ ) );
define( 'BWX_FORGE_URL', plugin_dir_url( __FILE__ ) );

require_once BWX_FORGE_PATH . 'plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

// Sites update themselves from this repo's GitHub Releases. The third argument
// must equal the plugin's folder name, the release workflow's plugin_slug, and
// the site's installed directory name; if they disagree, WordPress installs the
// update alongside the original as a second copy and deactivates it.
$bwx_forge_update_checker = PucFactory::buildUpdateChecker(
	'https://github.com/blueworx-io/blueworx_project_forge/',
	BWX_FORGE_FILE,
	'blueworx-forge'
);

/*
 * The repo is private, so a site needs a token to see releases at all. It lives
 * in wp-config.php — never in the plugin, never in the repo:
 *
 *     define( 'BLUEWORX_PLUGIN_UPDATE_TOKEN', 'github_pat_...' );
 */
if ( defined( 'BLUEWORX_PLUGIN_UPDATE_TOKEN' ) && BLUEWORX_PLUGIN_UPDATE_TOKEN ) {
	$bwx_forge_update_checker->setAuthentication( BLUEWORX_PLUGIN_UPDATE_TOKEN );
}

/*
 * Install the zip attached to the Release, not GitHub's auto-generated source
 * tarball, whose folder is named <repo>-<version> — WordPress would treat that
 * as a different plugin, and it ships every dev file in the repo.
 */
$bwx_forge_update_checker->getVcsApi()->enableReleaseAssets();
```

- [ ] **Step 5: Point the tooling at the new identity**

In `package.json`, set `"name": "blueworx-forge"` and `"version": "2.0.0"`, and change the harness scripts:

```json
    "wp:up": "node ../bluegroup_core_foundation/scripts/wp-test-env.mjs up --plugin . --slug blueworx-forge --dir .wp-test --port 8892",
    "wp:down": "node ../bluegroup_core_foundation/scripts/wp-test-env.mjs down --dir .wp-test"
```

In `.github/workflows/ci.yml` and `.github/workflows/release.yml`, change `plugin_slug: forge-project-management` to `plugin_slug: blueworx-forge`, and add `/design` to the `exclude_paths` block in **both** files, keeping the two lists identical:

```yaml
      exclude_paths: |
        /src
        /design
        /playwright-report.json
        /index.html
        /vite.config.*
        /tsconfig.json
        /postcss.config.mjs
        /vendor
        /.vscode
        /.playwright-mcp
        /.wp-test
```

Replace `phpcs.xml.dist` entirely:

```xml
<?xml version="1.0"?>
<ruleset name="BlueworxForge">
	<description>
		WordPress Coding Standards for Blueworx Forge. Full WPCS with no sniff
		exclusions: this is a new codebase with no legacy style to accommodate, so
		the tree is clean before the first pull request rather than after.
	</description>

	<arg name="extensions" value="php"/>
	<arg name="colors"/>
	<arg value="sp"/>
	<arg name="parallel" value="8"/>

	<file>blueworx-forge.php</file>
	<file>uninstall.php</file>
	<file>includes</file>
	<file>templates</file>

	<exclude-pattern>*/vendor/*</exclude-pattern>
	<exclude-pattern>*/node_modules/*</exclude-pattern>
	<exclude-pattern>*/plugin-update-checker/*</exclude-pattern>
	<exclude-pattern>*/.wp-test/*</exclude-pattern>
	<exclude-pattern>*/tests/*</exclude-pattern>

	<rule ref="WordPress"/>

	<rule ref="WordPress.NamingConventions.PrefixAllGlobals">
		<properties>
			<property name="prefixes" type="array">
				<element value="bwx_forge"/>
				<element value="BWX_FORGE"/>
				<element value="Blueworx\Forge"/>
			</property>
		</properties>
	</rule>

	<rule ref="WordPress.WP.I18n">
		<properties>
			<property name="text_domain" type="array">
				<element value="blueworx-forge"/>
			</property>
		</properties>
	</rule>

	<config name="minimum_wp_version" value="6.5"/>
</ruleset>
```

`uninstall.php` and `templates/` are listed before they exist. PHPCS ignores a `<file>` that is not there, and Tasks 3 and 6 create them.

In `tests/global-setup.js`, change the mu-plugin's file name and comment from `forge-pm-test-offline.php` to `bwx-forge-test-offline.php` and its error code from `forge_pm_test_offline` to `bwx_forge_test_offline`. Everything else in that file stays.

- [ ] **Step 6: Run the tests to verify they pass**

```bash
npm run wp:down
rm -rf .wp-test
npm run wp:up
WP_ADMIN_USER=admin WP_ADMIN_PASS=wptest-admin-pw npx playwright test tests/e2e/activation.spec.js --workers=1
```

Expected: 2 passed.

Then the static checks:

```bash
php -l blueworx-forge.php
vendor/bin/phpcs
npm run lint
npm run build
```

Expected: no syntax errors, PHPCS silent, ESLint silent, build succeeds.

- [ ] **Step 7: Write the changelog entry**

Add to the top of `CHANGELOG.md`, above the `## [1.37.2]` section:

```markdown
## [2.0.0] - 2026-08-17

### Changed

- Forge has been rebuilt from the ground up and is now a separate plugin,
  **Blueworx Forge**. It installs alongside the old Forge Project Management
  rather than replacing it, so both can run on the same site while you move
  items across by hand. The old plugin is untouched and stays installable from
  its 1.37.2 release.
- This release is the new plugin's foundation: it installs, activates and
  updates itself, and nothing more. The screens follow, built from the new
  design.
```

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "Replace the old plugin with the Blueworx Forge plugin file

The rebuild starts from an empty plugin under a new slug, so it installs
alongside Forge Project Management instead of overwriting the data being
migrated out of it."
```

---

### Task 2: Autoloader and Plugin class

**Files:**
- Create: `includes/autoload.php`, `includes/Plugin.php`, `tests/php/bootstrap.php`, `tests/php/AutoloaderTest.php`, `phpunit.xml.dist`
- Modify: `blueworx-forge.php`, `composer.json`

**Interfaces:**
- Consumes: `BWX_FORGE_PATH`, `BWX_FORGE_FILE` from Task 1.
- Produces: `bwx_forge_register_autoloader( string $base_dir ): void`; `\Blueworx\Forge\Plugin::instance(): Plugin` with public methods `activate(): void`, `deactivate(): void`, `boot(): void`.

- [ ] **Step 1: Add PHPUnit and write the failing test**

```bash
composer require --dev --no-interaction phpunit/phpunit:^11.5
```

Create `phpunit.xml.dist`:

```xml
<?xml version="1.0"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
	xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
	bootstrap="tests/php/bootstrap.php"
	colors="true"
	failOnWarning="true"
	failOnRisky="true">
	<testsuites>
		<testsuite name="unit">
			<directory>tests/php</directory>
		</testsuite>
	</testsuites>
</phpunit>
```

Create `tests/php/bootstrap.php`:

```php
<?php
/**
 * PHPUnit bootstrap. These tests run without a WordPress runtime: anything that
 * needs a real site belongs in the Playwright suite. The stubs below are the
 * WordPress functions the units under test call, and each records its calls in
 * $GLOBALS so a test can assert on them.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'BWX_FORGE_PATH', dirname( __DIR__, 2 ) . '/' );

$GLOBALS['bwx_forge_test_calls'] = array();

/**
 * Records a stubbed call so a test can assert it happened.
 *
 * @param string $name Function name.
 * @param mixed  $arg  First argument.
 */
function bwx_forge_test_record( string $name, $arg ): void {
	$GLOBALS['bwx_forge_test_calls'][] = array( $name, $arg );
}

/**
 * Stub. Records the deleted option name.
 *
 * @param string $option Option name.
 * @return bool
 */
function delete_option( string $option ): bool {
	bwx_forge_test_record( 'delete_option', $option );
	return true;
}

/**
 * Stub. Records the deleted transient name.
 *
 * @param string $transient Transient name.
 * @return bool
 */
function delete_transient( string $transient ): bool {
	bwx_forge_test_record( 'delete_transient', $transient );
	return true;
}

/**
 * Stub. Returns whatever the test put in $GLOBALS['bwx_forge_test_can'].
 *
 * @param string $capability Capability being checked.
 * @return bool
 */
function current_user_can( string $capability ): bool {
	$allowed = $GLOBALS['bwx_forge_test_can'] ?? array();
	return in_array( $capability, $allowed, true );
}

require_once dirname( __DIR__, 2 ) . '/includes/autoload.php';
bwx_forge_register_autoloader( dirname( __DIR__, 2 ) . '/includes' );
```

Create `tests/php/AutoloaderTest.php`:

```php
<?php
/**
 * Autoloader tests.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

/**
 * The autoloader is the one piece every other class depends on: if it resolves
 * a name wrongly, every later failure is a confusing "class not found".
 */
final class AutoloaderTest extends TestCase {

	/**
	 * A namespaced class resolves to its file under includes/.
	 */
	public function test_it_loads_a_plugin_class(): void {
		$this->assertTrue( class_exists( '\Blueworx\Forge\Plugin' ) );
	}

	/**
	 * A class in a sub-namespace resolves to the matching sub-directory.
	 */
	public function test_it_loads_a_namespaced_class(): void {
		$this->assertTrue( class_exists( '\Blueworx\Forge\Rest\Permissions' ) );
	}

	/**
	 * A name outside the plugin's namespace is left alone rather than guessed at,
	 * so the autoloader cannot fight another plugin's autoloader.
	 */
	public function test_it_ignores_foreign_namespaces(): void {
		$this->assertFalse( class_exists( '\SomeOther\Package\Thing' ) );
	}
}
```

`Rest\Permissions` arrives in Task 5. Until then `test_it_loads_a_namespaced_class` fails; that is expected and is the reason Task 5 exists. If you need a green suite between tasks, mark that one test skipped with a comment naming Task 5, and remove the skip in Task 5.

- [ ] **Step 2: Run the test to verify it fails**

```bash
vendor/bin/phpunit
```

Expected: FAIL — `includes/autoload.php` does not exist, so the bootstrap cannot require it.

- [ ] **Step 3: Write the autoloader**

Create `includes/autoload.php`:

```php
<?php
/**
 * Class autoloading for the plugin's own namespace.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

if ( ! function_exists( 'bwx_forge_register_autoloader' ) ) {
	/**
	 * Registers a PSR-4 style autoloader for Blueworx\Forge.
	 *
	 * Blueworx\Forge\Rest\Server resolves to <base>/Rest/Server.php. Names outside
	 * the plugin's own namespace are ignored rather than guessed at, so this can
	 * never fight another plugin's autoloader.
	 *
	 * @param string $base_dir Directory holding the plugin's classes.
	 */
	function bwx_forge_register_autoloader( string $base_dir ): void {
		spl_autoload_register(
			static function ( string $class_name ) use ( $base_dir ): void {
				$prefix = 'Blueworx\\Forge\\';

				if ( 0 !== strpos( $class_name, $prefix ) ) {
					return;
				}

				$relative = substr( $class_name, strlen( $prefix ) );
				$path     = rtrim( $base_dir, '/\\' ) . '/' . str_replace( '\\', '/', $relative ) . '.php';

				if ( is_readable( $path ) ) {
					require_once $path;
				}
			}
		);
	}
}
```

- [ ] **Step 4: Write the Plugin class**

Create `includes/Plugin.php`:

```php
<?php
/**
 * Plugin lifecycle.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge;

/**
 * Wires the plugin's parts to WordPress, and owns activation and deactivation.
 *
 * Everything the plugin does is hooked from boot(), so the main plugin file
 * stays a header, some constants, and one call.
 */
final class Plugin {

	/**
	 * The single instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Returns the single instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Hooks everything up. Called on plugins_loaded.
	 */
	public function boot(): void {
		Frontend::instance()->boot();

		add_action( 'rest_api_init', array( Rest\Server::class, 'register_routes' ) );
	}

	/**
	 * Runs on activation.
	 */
	public function activate(): void {
		Frontend::instance()->create_app_page();
		flush_rewrite_rules();
	}

	/**
	 * Runs on deactivation. Leaves the app page in place: it is a published page
	 * the site owns, and deactivating a plugin is not a request to delete content.
	 */
	public function deactivate(): void {
		flush_rewrite_rules();
	}
}
```

`Frontend` arrives in Task 3. Between Tasks 2 and 3 the plugin file must not call `boot()` yet — Step 5 wires only the autoloader, and Task 3 adds the hooks.

- [ ] **Step 5: Wire the autoloader into the plugin file**

In `blueworx-forge.php`, immediately after the `define( 'BWX_FORGE_URL', ... );` line, add:

```php
require_once BWX_FORGE_PATH . 'includes/autoload.php';

bwx_forge_register_autoloader( BWX_FORGE_PATH . 'includes' );
```

- [ ] **Step 6: Run the tests to verify they pass**

```bash
vendor/bin/phpunit
vendor/bin/phpcs
```

Expected: PHPUnit passes (with the one skip named in Step 1, if you used it); PHPCS silent.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "Add the class autoloader and plugin lifecycle

Unit tests run without WordPress, against stubs, so a naming mistake in the
autoloader fails as itself rather than as a confusing missing class later."
```

---

### Task 3: The app page and its assets

**Files:**
- Create: `includes/Frontend.php`, `templates/app-page.php`, `tests/e2e/app-page.spec.js`
- Modify: `blueworx-forge.php`

**Interfaces:**
- Consumes: `Plugin::instance()` from Task 2.
- Produces: `\Blueworx\Forge\Frontend::instance()` with `boot(): void`, `create_app_page(): void`, `app_page_id(): int`, `app_page_url(): string`; the option name `bwx_forge_app_page_id`; the page slug `blueworx-forge`; the mount element `#bwx-forge-app`; the localised object `bwxForgeData`.

- [ ] **Step 1: Write the failing test**

Create `tests/e2e/app-page.spec.js`:

```javascript
import { test, expect } from '@playwright/test';

// Activation creates the app page and assigns the plugin's full-page template.
// This spec is the one that catches the failure that matters on a live site: the
// page exists but the app never mounts.

test('the app page exists and carries the mount point', async ({ page }) => {
  const response = await page.goto('/blueworx-forge/');
  expect(response?.status()).toBe(200);

  await expect(page.locator('#bwx-forge-app')).toHaveCount(1);
});

test('the app page localises what the front end needs', async ({ page }) => {
  await page.goto('/blueworx-forge/');

  const data = await page.evaluate(() => window.bwxForgeData);
  expect(data, 'bwxForgeData was not localised — the enqueue never ran').toBeTruthy();
  expect(data.restUrl).toContain('/blueworx-forge/v1');
  expect(typeof data.nonce).toBe('string');
  expect(data.nonce.length).toBeGreaterThan(0);
  // A logged-out visitor can read and nothing else. The front end uses this to
  // decide what to render; the REST layer enforces it independently.
  expect(data.canEdit).toBe(false);
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
WP_ADMIN_USER=admin WP_ADMIN_PASS=wptest-admin-pw npx playwright test tests/e2e/app-page.spec.js --workers=1
```

Expected: FAIL — `/blueworx-forge/` returns 404; no page is created.

- [ ] **Step 3: Write the Frontend class**

Create `includes/Frontend.php`:

```php
<?php
/**
 * The front-end app page.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge;

/**
 * Owns the page the app runs on: creating it, serving it through the plugin's
 * own full-page template, and handing the built bundle the data it needs.
 */
final class Frontend {

	/**
	 * Option holding the generated page's ID.
	 */
	public const PAGE_OPTION = 'bwx_forge_app_page_id';

	/**
	 * The generated page's slug, and the template's name.
	 */
	public const PAGE_SLUG = 'blueworx-forge';

	/**
	 * The single instance.
	 *
	 * @var Frontend|null
	 */
	private static ?Frontend $instance = null;

	/**
	 * Returns the single instance.
	 *
	 * @return Frontend
	 */
	public static function instance(): Frontend {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Hooks the front end up.
	 */
	public function boot(): void {
		add_filter( 'template_include', array( $this, 'use_app_template' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_shortcode( 'blueworx_forge', array( $this, 'render_mount_point' ) );
	}

	/**
	 * Creates the app page if it is missing. Runs on activation.
	 */
	public function create_app_page(): void {
		if ( 0 !== $this->app_page_id() ) {
			return;
		}

		$page_id = wp_insert_post(
			array(
				'post_title'   => __( 'Blueworx Forge', 'blueworx-forge' ),
				'post_name'    => self::PAGE_SLUG,
				'post_content' => '[blueworx_forge]',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			)
		);

		if ( is_wp_error( $page_id ) || 0 === $page_id ) {
			return;
		}

		update_option( self::PAGE_OPTION, (int) $page_id );
	}

	/**
	 * The generated page's ID, or 0 when it no longer exists.
	 *
	 * @return int
	 */
	public function app_page_id(): int {
		$page_id = (int) get_option( self::PAGE_OPTION, 0 );

		if ( 0 === $page_id || 'publish' !== get_post_status( $page_id ) ) {
			return 0;
		}

		return $page_id;
	}

	/**
	 * The generated page's URL, falling back to the site home.
	 *
	 * @return string
	 */
	public function app_page_url(): string {
		$page_id = $this->app_page_id();
		$url     = 0 !== $page_id ? get_permalink( $page_id ) : '';

		return is_string( $url ) && '' !== $url ? $url : home_url( '/' );
	}

	/**
	 * Serves the plugin's own full-page template on the app page.
	 *
	 * @param string $template Template WordPress resolved.
	 * @return string
	 */
	public function use_app_template( string $template ): string {
		if ( ! $this->is_app_page() ) {
			return $template;
		}

		$own = BWX_FORGE_PATH . 'templates/app-page.php';

		return file_exists( $own ) ? $own : $template;
	}

	/**
	 * Loads the built bundle and the data it needs, on the app page only.
	 */
	public function enqueue(): void {
		if ( ! $this->is_app_page() ) {
			return;
		}

		$script = BWX_FORGE_PATH . 'assets/js/blueworx-forge.js';
		$style  = BWX_FORGE_PATH . 'assets/css/blueworx-forge.css';

		if ( ! file_exists( $script ) ) {
			return;
		}

		if ( file_exists( $style ) ) {
			wp_enqueue_style(
				'blueworx-forge',
				BWX_FORGE_URL . 'assets/css/blueworx-forge.css',
				array(),
				(string) filemtime( $style )
			);
		}

		wp_enqueue_script(
			'blueworx-forge',
			BWX_FORGE_URL . 'assets/js/blueworx-forge.js',
			array(),
			(string) filemtime( $script ),
			true
		);

		wp_localize_script(
			'blueworx-forge',
			'bwxForgeData',
			array(
				'restUrl'    => rest_url( 'blueworx-forge/v1' ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'isLoggedIn' => is_user_logged_in(),
				'canEdit'    => current_user_can( 'edit_posts' ),
				'canManage'  => current_user_can( 'manage_options' ),
				'siteUrl'    => get_site_url(),
				'loginUrl'   => wp_login_url( $this->app_page_url() ),
				'logoutUrl'  => wp_logout_url( $this->app_page_url() ),
				'version'    => BWX_FORGE_VERSION,
			)
		);
	}

	/**
	 * The mount point, for the shortcode.
	 *
	 * @return string
	 */
	public function render_mount_point(): string {
		return '<div id="bwx-forge-app"></div>';
	}

	/**
	 * Whether the current request is the app page.
	 *
	 * @return bool
	 */
	private function is_app_page(): bool {
		$page_id = $this->app_page_id();

		return 0 !== $page_id && is_page( $page_id );
	}
}
```

- [ ] **Step 4: Write the template**

Create `templates/app-page.php`:

```php
<?php
/**
 * Full-page template for the app. Deliberately bare: the app owns the whole
 * viewport, so the theme's header, footer and sidebars are not loaded.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'bwx-forge-page' ); ?>>
<?php wp_body_open(); ?>
<div id="bwx-forge-app"></div>
<?php wp_footer(); ?>
</body>
</html>
```

- [ ] **Step 5: Hook the plugin up**

In `blueworx-forge.php`, below the autoloader registration added in Task 2, add:

```php
register_activation_hook( BWX_FORGE_FILE, array( \Blueworx\Forge\Plugin::instance(), 'activate' ) );
register_deactivation_hook( BWX_FORGE_FILE, array( \Blueworx\Forge\Plugin::instance(), 'deactivate' ) );

add_action( 'plugins_loaded', array( \Blueworx\Forge\Plugin::instance(), 'boot' ) );
```

- [ ] **Step 6: Run the test to verify it passes**

Activation already ran when the harness installed the plugin, so the page does not exist yet. Re-provision so activation runs against the new code:

```bash
npm run wp:down
rm -rf .wp-test
npm run wp:up
WP_ADMIN_USER=admin WP_ADMIN_PASS=wptest-admin-pw npx playwright test --workers=1
```

Expected: the activation specs and both app-page specs pass. The second app-page spec passes even though no bundle exists yet — `wp_localize_script` runs from the enqueue, which returns early only when `assets/js/blueworx-forge.js` is missing. **If it does fail for that reason, do not weaken the spec**: Task 4 builds the bundle, so run this spec again at the end of Task 4 and treat it as Task 4's gate instead.

```bash
vendor/bin/phpcs
```

Expected: silent.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "Serve an app page from the plugin's own template

Activation creates the page, the plugin serves it full-bleed, and the front end
gets one data object saying what the current user may do."
```

---

### Task 4: The React skeleton

**Files:**
- Create: `src/main.tsx`, `src/App.tsx`, `src/styles.css`
- Delete: `src/styles/` (old directory), the old `src/main.tsx` contents (overwritten)
- Modify: `vite.config.ts`, `index.html`, `package.json`, `approved-deps.json`

**Interfaces:**
- Consumes: `#bwx-forge-app` and `window.bwxForgeData` from Task 3.
- Produces: built files `assets/js/blueworx-forge.js` and `assets/css/blueworx-forge.css`; a rendered element carrying `data-testid="bwx-forge-ready"`.

- [ ] **Step 1: Write the failing test**

Append to `tests/e2e/app-page.spec.js`:

```javascript
test('the React app mounts into the page', async ({ page }) => {
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));

  await page.goto('/blueworx-forge/');

  // React renders into the empty mount div, so a child element is proof the
  // bundle loaded and executed rather than 404ing or throwing.
  await expect(page.getByTestId('bwx-forge-ready')).toBeVisible({ timeout: 15_000 });
  expect(errors, `the page raised JavaScript errors:\n${errors.join('\n')}`).toEqual([]);
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
WP_ADMIN_USER=admin WP_ADMIN_PASS=wptest-admin-pw npx playwright test tests/e2e/app-page.spec.js --workers=1
```

Expected: FAIL — no bundle is built under the new name, so nothing mounts.

- [ ] **Step 3: Trim the dependencies to what the skeleton uses**

The old app's libraries come back when a feature needs them, and each return is a decision recorded in `approved-deps.json`. Set `package.json`'s dependency blocks to exactly:

```json
  "dependencies": {},
  "devDependencies": {
    "@eslint/js": "^9.39.4",
    "@playwright/test": "^1.49.0",
    "@tailwindcss/vite": "4.1.12",
    "@types/react": "18.3.1",
    "@types/react-dom": "18.3.1",
    "@vitejs/plugin-react": "4.7.0",
    "eslint": "^9.39.4",
    "eslint-plugin-react": "^7.37.5",
    "eslint-plugin-react-hooks": "^7.1.1",
    "globals": "^17.6.0",
    "jiti": "^2.7.0",
    "react": "18.3.1",
    "react-dom": "18.3.1",
    "tailwindcss": "4.1.12",
    "typescript": "5.8.3",
    "typescript-eslint": "^8.60.1",
    "vite": "^6.4.3"
  }
```

Then make `approved-deps.json` identical to those two blocks — `"dependencies": {}` and the same devDependencies map. CI fails on any difference.

```bash
npm install
```

- [ ] **Step 4: Write the app**

Replace `src/main.tsx`:

```tsx
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { App } from './App';
import './styles.css';

const container = document.getElementById( 'bwx-forge-app' );

if ( container ) {
  createRoot( container ).render(
    <StrictMode>
      <App />
    </StrictMode>
  );
}
```

Create `src/App.tsx`:

```tsx
export interface ForgeData {
  restUrl: string;
  nonce: string;
  isLoggedIn: boolean;
  canEdit: boolean;
  canManage: boolean;
  siteUrl: string;
  loginUrl: string;
  logoutUrl: string;
  version: string;
}

declare global {
  interface Window {
    bwxForgeData?: ForgeData;
  }
}

/**
 * The skeleton screen. It exists to prove the pipeline end to end — the bundle
 * builds, WordPress serves it, it mounts, and it can read what the server told
 * it about the current user. The real screens replace it, built from the design.
 */
export function App() {
  const data = window.bwxForgeData;

  return (
    <main
      data-testid="bwx-forge-ready"
      style={ {
        display: 'flex',
        minHeight: '100dvh',
        flexDirection: 'column',
        alignItems: 'center',
        justifyContent: 'center',
        gap: 8,
        fontFamily: 'system-ui, sans-serif',
        color: '#1a1f36',
        backgroundColor: '#fafbfc',
      } }
    >
      <h1 style={ { fontSize: 20, fontWeight: 600, margin: 0 } }>Blueworx Forge</h1>
      <p style={ { margin: 0, color: '#64748b', fontSize: 13 } }>
        { data ? `Version ${ data.version } — ready` : 'Running without WordPress data' }
      </p>
    </main>
  );
}
```

Create `src/styles.css`:

```css
@import 'tailwindcss';

/* The app owns the viewport; the template loads no theme styles. */
html,
body {
  margin: 0;
  padding: 0;
}
```

If `src/styles/` still exists from the old app, delete it: `git rm -q -r src/styles`.

- [ ] **Step 5: Point the build at the new names**

In `vite.config.ts`, change the two output names inside `rollupOptions.output`:

```ts
        entryFileNames: 'js/blueworx-forge.js',
        assetFileNames: ( info ) => {
          if ( info.name?.endsWith( '.css' ) ) return 'css/blueworx-forge.css';
          return 'img/[name][extname]';
        },
```

Set `index.html`'s title to `Blueworx Forge` and confirm its script tag points at `/src/main.tsx` and its body contains `<div id="bwx-forge-app"></div>`.

- [ ] **Step 6: Build and run the tests to verify they pass**

```bash
npm run build
npm run lint
WP_ADMIN_USER=admin WP_ADMIN_PASS=wptest-admin-pw npx playwright test --workers=1
```

Expected: build writes `assets/js/blueworx-forge.js` and `assets/css/blueworx-forge.css`; ESLint silent; every spec passes, including the two from Task 3.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "Mount a React skeleton on the app page

Proves the whole pipeline: Vite builds the bundle, WordPress serves it, it
mounts, and it can read what the server said about the current user. The
dependency list drops to what this actually uses; the rest return when a
feature needs them."
```

---

### Task 5: The REST namespace

**Files:**
- Create: `includes/Rest/Server.php`, `includes/Rest/Permissions.php`, `includes/Rest/StatusController.php`, `tests/php/PermissionsTest.php`, `tests/e2e/rest.spec.js`
- Modify: `tests/php/AutoloaderTest.php` (remove the skip added in Task 2, if you added one)

**Interfaces:**
- Consumes: `BWX_FORGE_VERSION`; the autoloader from Task 2; `Plugin::boot()`'s `rest_api_init` hook from Task 2.
- Produces: `\Blueworx\Forge\Rest\Server::register_routes(): void`; `\Blueworx\Forge\Rest\Permissions::read(): bool` and `::manage(): bool`; the namespace `blueworx-forge/v1` with `GET /status` (public) and `POST /status/echo` (requires `manage_options`).

- [ ] **Step 1: Write the failing tests**

Create `tests/php/PermissionsTest.php`:

```php
<?php
/**
 * Permission callback tests.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Rest\Permissions;
use PHPUnit\Framework\TestCase;

/**
 * These are the functions standing between the API and the world, so they are
 * tested in isolation as well as through the live API in tests/e2e/rest.spec.js.
 */
final class PermissionsTest extends TestCase {

	/**
	 * Resets the fake capabilities before each test.
	 */
	protected function setUp(): void {
		$GLOBALS['bwx_forge_test_can'] = array();
	}

	/**
	 * Reading is open: the app's read side serves logged-out visitors.
	 */
	public function test_read_is_public(): void {
		$this->assertTrue( Permissions::read() );
	}

	/**
	 * Managing requires the capability, and nothing else.
	 */
	public function test_manage_requires_the_capability(): void {
		$this->assertFalse( Permissions::manage() );

		$GLOBALS['bwx_forge_test_can'] = array( 'manage_options' );

		$this->assertTrue( Permissions::manage() );
	}

	/**
	 * A user with some other capability is still refused — the check is for one
	 * named capability, not for "is logged in and probably fine".
	 */
	public function test_manage_refuses_an_unrelated_capability(): void {
		$GLOBALS['bwx_forge_test_can'] = array( 'edit_posts' );

		$this->assertFalse( Permissions::manage() );
	}
}
```

Create `tests/e2e/rest.spec.js`:

```javascript
import { test, expect } from '@playwright/test';

// Unit tests prove each permission callback in isolation. This proves the
// assembled system: the routes are actually registered, and the gated one
// actually refuses a stranger. A controller that was never registered, or a
// permission callback that was never attached, would leave the unit tests green
// and the live API open.

test('the status route answers without a login', async ({ request }) => {
  const response = await request.get('/wp-json/blueworx-forge/v1/status');
  expect(response.status()).toBe(200);

  const body = await response.json();
  expect(body.plugin).toBe('blueworx-forge');
  expect(typeof body.version).toBe('string');
  expect(body.ready).toBe(true);
});

test('a write is refused without permission', async ({ request }) => {
  const response = await request.post('/wp-json/blueworx-forge/v1/status/echo', {
    data: { message: 'hello' },
  });

  // 401 logged out, 403 logged in without the capability. Either is a refusal;
  // anything 2xx is the failure this spec exists to catch.
  expect([401, 403]).toContain(response.status());
});

test('an unknown route under the namespace is a 404, not a 500', async ({ request }) => {
  const response = await request.get('/wp-json/blueworx-forge/v1/nope');
  expect(response.status()).toBe(404);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
vendor/bin/phpunit
WP_ADMIN_USER=admin WP_ADMIN_PASS=wptest-admin-pw npx playwright test tests/e2e/rest.spec.js --workers=1
```

Expected: PHPUnit fails on the missing `Permissions` class; every REST spec fails with 404 because no routes exist.

- [ ] **Step 3: Write the permissions**

Create `includes/Rest/Permissions.php`:

```php
<?php
/**
 * REST permission callbacks.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

/**
 * The permission callbacks, in one place and as plain static methods, so each
 * can be tested without a WordPress runtime and so every route's answer to "who
 * may call this" is visible in a single file.
 */
final class Permissions {

	/**
	 * Reading is deliberately public: the app serves a read-only view to
	 * logged-out visitors. Any route returning something a visitor must not see
	 * uses manage() instead — this is not a default, it is a decision per route.
	 *
	 * @return bool
	 */
	public static function read(): bool {
		return true;
	}

	/**
	 * Anything that changes state, or reads configuration, requires the site's
	 * administrator capability.
	 *
	 * @return bool
	 */
	public static function manage(): bool {
		return current_user_can( 'manage_options' );
	}
}
```

- [ ] **Step 4: Write the controller and the server**

Create `includes/Rest/StatusController.php`:

```php
<?php
/**
 * The status routes.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use WP_REST_Request;
use WP_REST_Response;

/**
 * The skeleton's only routes. They exist so the namespace is real, and so both
 * shapes — a public read and a capability-gated write — are proven end to end
 * before any feature relies on them.
 */
final class StatusController {

	/**
	 * Registers this controller's routes.
	 *
	 * @param string $namespace REST namespace.
	 */
	public static function register_routes( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'status' ),
				'permission_callback' => array( Permissions::class, 'read' ),
			)
		);

		register_rest_route(
			$namespace,
			'/status/echo',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'echo_message' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'args'                => array(
					'message' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Reports that the plugin is installed and answering.
	 *
	 * @return WP_REST_Response
	 */
	public static function status(): WP_REST_Response {
		return rest_ensure_response(
			array(
				'plugin'  => BWX_FORGE_SLUG,
				'version' => BWX_FORGE_VERSION,
				'ready'   => true,
			)
		);
	}

	/**
	 * Echoes a message back. The gated counterpart to status(), and the route the
	 * access-control spec proves is refused.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function echo_message( WP_REST_Request $request ): WP_REST_Response {
		return rest_ensure_response(
			array(
				'message' => (string) $request->get_param( 'message' ),
			)
		);
	}
}
```

Create `includes/Rest/Server.php`:

```php
<?php
/**
 * REST namespace registration.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

/**
 * One place that knows which controllers exist. A controller missing from this
 * list is a route that silently does not exist, so new controllers are added
 * here and nowhere else.
 */
final class Server {

	/**
	 * The plugin's REST namespace.
	 */
	public const NAMESPACE = 'blueworx-forge/v1';

	/**
	 * Registers every controller's routes. Hooked to rest_api_init.
	 */
	public static function register_routes(): void {
		StatusController::register_routes( self::NAMESPACE );
	}
}
```

- [ ] **Step 5: Run the tests to verify they pass**

```bash
vendor/bin/phpunit
vendor/bin/phpcs
WP_ADMIN_USER=admin WP_ADMIN_PASS=wptest-admin-pw npx playwright test --workers=1
```

Expected: PHPUnit green including the autoloader's namespaced-class test; PHPCS silent; every Playwright spec passes.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "Serve a permission-checked REST namespace

Two routes, one public read and one that requires the administrator capability,
with the refusal proven against a real WordPress as well as in isolation."
```

---

### Task 6: Uninstall

**Files:**
- Create: `uninstall.php`, `tests/php/UninstallTest.php`

**Interfaces:**
- Consumes: `Frontend::PAGE_OPTION` (the string `bwx_forge_app_page_id`) from Task 3.
- Produces: nothing later tasks use.

The uninstall path is tested as a unit rather than through the browser on purpose: uninstalling through wp-admin deletes the plugin directory, and in the local harness that directory is a link to this repository. A browser test of uninstall would delete the working tree.

- [ ] **Step 1: Write the failing test**

Create `tests/php/UninstallTest.php`:

```php
<?php
/**
 * Uninstall tests.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

/**
 * Uninstall is the one code path nobody runs by accident and everybody relies on
 * being right. It is tested here, as a unit, rather than through the browser:
 * uninstalling through wp-admin deletes the plugin directory, which in the local
 * harness is a link to this repository.
 */
final class UninstallTest extends TestCase {

	/**
	 * Runs uninstall.php against the stubs and returns what it called.
	 *
	 * @return array<int, array{0: string, 1: mixed}>
	 */
	private function run_uninstall(): array {
		$GLOBALS['bwx_forge_test_calls'] = array();

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'blueworx-forge/blueworx-forge.php' );
		}

		require dirname( __DIR__, 2 ) . '/uninstall.php';

		return $GLOBALS['bwx_forge_test_calls'];
	}

	/**
	 * Every option the plugin owns is deleted.
	 */
	public function test_it_deletes_its_own_options(): void {
		$calls = $this->run_uninstall();

		$deleted = array_map(
			static fn( array $call ): string => (string) $call[1],
			array_filter( $calls, static fn( array $call ): bool => 'delete_option' === $call[0] )
		);

		$this->assertContains( 'bwx_forge_app_page_id', $deleted );
	}

	/**
	 * Nothing it touches belongs to anyone else: every deleted name carries the
	 * plugin's own prefix. This is what keeps an uninstall from taking the old
	 * Forge plugin's data with it while both are installed during migration.
	 */
	public function test_it_touches_nothing_it_does_not_own(): void {
		$calls = $this->run_uninstall();

		$this->assertNotEmpty( $calls );

		foreach ( $calls as $call ) {
			$this->assertStringStartsWith(
				'bwx_forge_',
				(string) $call[1],
				sprintf( '%s() was called with a name the plugin does not own', $call[0] )
			);
		}
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
vendor/bin/phpunit --filter UninstallTest
```

Expected: FAIL — `uninstall.php` does not exist.

- [ ] **Step 3: Write uninstall.php**

```php
<?php
/**
 * Removes Blueworx Forge's own data on uninstall, and nothing else.
 *
 * Options are listed here rather than read from the plugin's classes because
 * uninstall runs without the plugin loaded. Adding an option means adding it
 * here too, and tests/php/UninstallTest.php asserts every name carries the
 * plugin's prefix — the old Forge Project Management plugin may be installed
 * alongside this one during migration, and its data is not ours to delete.
 *
 * The generated app page is deliberately left alone: it is a published page the
 * site owns, not the plugin's to remove.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$bwx_forge_options = array(
	'bwx_forge_app_page_id',
);

foreach ( $bwx_forge_options as $bwx_forge_option ) {
	delete_option( $bwx_forge_option );
}
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
vendor/bin/phpunit
vendor/bin/phpcs
```

Expected: PHPUnit green, PHPCS silent.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "Remove only this plugin's own data on uninstall

Tested as a unit, not through wp-admin: uninstalling there deletes the plugin
directory, which in the local harness is a link to this repository."
```

---

### Task 7: Ship it

**Files:**
- Modify: `bin/build-zip.sh`, `CLAUDE.md`, `CHANGELOG.md`, `.gitignore`

**Interfaces:**
- Consumes: everything above.
- Produces: a verified `blueworx-forge-2.0.0.zip` one level above the repo, and a pull request.

- [ ] **Step 1: Point the zip build at the new plugin**

In `bin/build-zip.sh`, change:

```bash
SLUG="blueworx-forge"
```

the allowlist to:

```bash
INCLUDE=(
	"$SLUG.php"
	"uninstall.php"
	"CHANGELOG.md"
	"includes"
	"templates"
	"assets"
	"plugin-update-checker"
)
```

the forbidden lists to add `design` and `phpunit.xml*`:

```bash
FORBIDDEN_SEGMENTS=( "src" "design" "tests" "test-results" "docs" "bin" "node_modules" ".superpowers" ".github" ".git" ".wp-test" )
FORBIDDEN_FILES=( "*.spec.js" "*.ts" "*.tsx" "phpcs.xml*" "phpunit.xml*" "composer.json" "composer.lock" "package.json" "package-lock.json" "approved-deps.json" "playwright.config.js" "CLAUDE.md" ".gitignore" "*.zip" )
```

the version read to:

```bash
VERSION="$(grep -oE "define\( 'BWX_FORGE_VERSION', '[^']+'" "$ROOT/$SLUG.php" | grep -oE "[0-9]+\.[0-9]+\.[0-9]+")"
```

and the bundle check to:

```bash
check "the built app bundle ships" \
	"$(printf '%s\n' "$ENTRIES" | grep -qxF "$SLUG/assets/js/blueworx-forge.js" && true || echo "missing $SLUG/assets/js/blueworx-forge.js — run npm run build")"
```

Add `design/` to `.gitignore` **only if** the design arrives as an export you do not want committed; if Claude Design syncs a branch instead, leave `.gitignore` alone. Say which you did in the pull request.

- [ ] **Step 2: Run the zip build and verify the artifact**

```bash
npm run build:zip
unzip -l ../blueworx-forge-2.0.0.zip | head -20
```

Expected: every check prints `ok`, the archive is named `blueworx-forge-2.0.0.zip`, and every entry reads `blueworx-forge/…` with forward slashes.

- [ ] **Step 3: Update the project notes**

In `CLAUDE.md`, in the project-specific section only (never the shared foundation text above it), update: the project intent to name Blueworx Forge and its rebuild; the harness block to `npm run wp:up` on `http://127.0.0.1:8892`; the zip name to `blueworx-forge-<version>.zip`; and replace the Zustand note — the old app's store is gone — with a line saying the design lands on `design-sync` and is integrated through pull requests, never merged.

- [ ] **Step 4: Run every check, together**

```bash
npm run lint
npm run build
vendor/bin/phpcs
vendor/bin/phpunit
npm run wp:down && rm -rf .wp-test && npm run wp:up
WP_ADMIN_USER=admin WP_ADMIN_PASS=wptest-admin-pw npx playwright test --workers=1
npm run wp:down
```

Expected: ESLint silent, build succeeds, PHPCS silent, PHPUnit green, every Playwright spec passes against a WordPress provisioned from scratch. A fresh instance matters here: it is the only run that exercises activation on a site that has never had the plugin.

- [ ] **Step 5: Commit and open the pull request**

```bash
git add -A
git commit -m "Build and document the Blueworx Forge zip"
git push -u origin rebuild/blueworx-forge-skeleton
```

Open a pull request against `main` that says: the old plugin is gone from `main` and lives on in the v1.37.2 release; the new plugin installs alongside it under a new slug; this is a skeleton with no features; and the design intake is `design-sync`. Do not merge it without the `guardrails / guardrails` check passing, and do not tag a release — that is a separate decision.

- [ ] **Step 6: Close the superseded pull request**

PR #61 (the Foundry connector) targets files this branch deletes, so it cannot merge afterwards. Close it with a comment saying the work needs rebuilding against Blueworx Forge, and link this pull request.

---

## Self-Review

**Spec coverage:** new slug and identity — Task 1. Version 2.0.0 — Task 1. PHP layout, autoloader, namespaced classes, WPCS with no exclusions — Tasks 1, 2. REST namespace with explicit permission callbacks — Task 5. Front end built to `assets/` with one mount point and one data object — Tasks 3, 4. Design intake via `design-sync` / `design/` excluded from the zip — Tasks 1 (workflows), 7 (zip, notes). The four required tests — activation (Task 1), page mounts (Tasks 3, 4), REST answers and refuses (Task 5), uninstall removes only its own options (Task 6). PHPUnit configured with passing tests from the start — Task 2. Old plugin untouched, PR #61 closed — Task 7.

**Known ordering note:** `AutoloaderTest::test_it_loads_a_namespaced_class` depends on `Rest\Permissions`, which Task 5 creates. Task 2 says to skip it with a comment naming Task 5, and Task 5 says to remove the skip. This is the only deliberate cross-task dependency in the plan.

**Type consistency:** `BWX_FORGE_*` constants, `bwx_forge_register_autoloader`, `Plugin::instance/boot/activate/deactivate`, `Frontend::instance/boot/create_app_page/app_page_id/app_page_url`, `Frontend::PAGE_OPTION`, `Server::NAMESPACE`, `Server::register_routes`, `StatusController::register_routes/status/echo_message`, `Permissions::read/manage`, `#bwx-forge-app`, `bwxForgeData`, `data-testid="bwx-forge-ready"`, `assets/js/blueworx-forge.js` — each defined once and used under the same name everywhere else.
