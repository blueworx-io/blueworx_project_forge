# Changelog page and Settings (rules) page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Changelog screen in the Forge WordPress admin menu (#466) and a read-only Settings screen under Insight in the studio app listing Forge's rules in plain English (#467).

**Architecture:** The Changelog is a PHP admin screen beside Updates. A pure parser turns the shipped `CHANGELOG.md` into releases, and the screen draws them with the existing `Page` helpers. Settings is a React screen wired into `src/App.tsx` like every other screen. It reads a static `src/rules.ts` and needs no REST route.

**Tech Stack:** PHP 8 (WordPress, PHPUnit with stubs in `tests/php/bootstrap.php`), React + TypeScript + Vite, Playwright.

**Spec:** `docs/superpowers/specs/2026-09-29-changelog-and-rules-design.md`

## Global Constraints

- Studio only; the client plugin is untouched (apart from its version header, which must match).
- Two pull requests, Changelog first, each a minor bump: Changelog 2.154.0, Settings 2.155.0 (assuming #469 lands as 2.153.0 first). Version in `package.json`, `blueworx-forge.php` (header + `BWX_FORGE_VERSION`) and `client/blueworx-forge-client.php` (header + `BWX_FORGE_CLIENT_VERSION`), plus a `CHANGELOG.md` entry.
- Changelog: `manage_options` only. Settings: admin only (`admin: true` in the rail).
- No new dependencies. UI from `src/kit/` only.
- Rule text: plain UK English, one sentence for what happens and one for why. No field names, codes or issue numbers.
- The studio test site is live-linked to this checkout. Never build, edit PHP, or switch branches while a local Playwright run is going.

## Review Focus

1. A release with a plain paragraph instead of bullets (the `### Note` under 1.x) should still show its text, not drop it. Pinned in Task 1.
2. Text with `<script>` or `&` in the changelog should be shown as text, never run as markup. Pinned in Task 1.
3. The changelog file missing from a hand-built zip should give a one-line notice, not a PHP warning. Pinned in Task 1 and Task 2.
4. A Manager who deep-links to `#screen=settings` should land on the board, not the rules. Pinned in Task 4.
5. On a phone, the Settings screen should open from the menu without spilling off the side. Pinned in Task 4 (added to the mobile screen list).

---

## PR 1 — Changelog (#466), branch `add-changelog-page`

### Task 0: Bring the branch up to date

- [ ] **Step 1:** After #469 merges: `git switch add-changelog-page && git rebase origin/main`. The branch holds only the spec commit, so no conflicts are expected.

### Task 1: The changelog parser

**Files:**
- Create: `includes/Admin/Changelog.php`
- Test: `tests/php/ChangelogTest.php`

**Interfaces:**
- Produces: `Changelog::parse( string $markdown ): array` returns a list of `array{version: string, date: string, sections: list<array{heading: string, entries: list<string>}>}`, newest first (file order). `Changelog::inline( string $text ): string` returns escaped HTML with `**bold**` → `<strong>`, `` `code` `` → `<code>`, and `[text](url)` → the text only. `Changelog::read( string $path ): ?array` returns `null` when the file is missing or unreadable, otherwise `parse()` of its contents.

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * The changelog, read for the admin screen (#466).
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Admin\Changelog;
use PHPUnit\Framework\TestCase;

final class ChangelogTest extends TestCase {

	private const SAMPLE = "# Changelog\n\nIntro with a [link](https://example.com).\n\n## [2.1.0] - 2026-09-29\n\n### Added\n\n- One thing.\n- Another thing that\n  carries on here.\n\n### Fixed\n\n- A fix.\n\n## [2.0.0] - 2026-09-01\n\n### Note\n\nA plain paragraph\nover two lines.\n";

	public function test_releases_come_out_newest_first_with_their_dates(): void {
		$releases = Changelog::parse( self::SAMPLE );

		$this->assertSame( array( '2.1.0', '2.0.0' ), array_column( $releases, 'version' ) );
		$this->assertSame( '2026-09-29', $releases[0]['date'] );
	}

	public function test_the_intro_is_skipped(): void {
		$this->assertStringNotContainsString( 'Intro', (string) wp_json_encode( Changelog::parse( self::SAMPLE ) ) );
	}

	public function test_sections_hold_their_entries_and_continuations_join(): void {
		$sections = Changelog::parse( self::SAMPLE )[0]['sections'];

		$this->assertSame( array( 'Added', 'Fixed' ), array_column( $sections, 'heading' ) );
		$this->assertSame( array( 'One thing.', 'Another thing that carries on here.' ), $sections[0]['entries'] );
	}

	public function test_a_plain_paragraph_is_kept_as_one_entry(): void {
		$sections = Changelog::parse( self::SAMPLE )[1]['sections'];

		$this->assertSame( array( 'A plain paragraph over two lines.' ), $sections[0]['entries'] );
	}

	public function test_inline_formatting_and_escaping(): void {
		$this->assertSame( '<strong>Bold</strong> and <code>npm run x</code>', Changelog::inline( '**Bold** and `npm run x`' ) );
		$this->assertSame( 'See the docs', Changelog::inline( 'See [the docs](https://example.com)' ) );
		$this->assertSame( '&lt;script&gt; &amp; more', Changelog::inline( '<script> & more' ) );
	}

	public function test_an_empty_file_has_no_releases(): void {
		$this->assertSame( array(), Changelog::parse( '' ) );
	}

	public function test_a_missing_file_reads_as_null(): void {
		$this->assertNull( Changelog::read( __DIR__ . '/no-such-changelog.md' ) );
	}
}
```

Check `wp_json_encode` is stubbed in `tests/php/bootstrap.php`. If it isn't, use `json_encode`.

- [ ] **Step 2: Run it and see it fail**

Run: `vendor/bin/phpunit --filter ChangelogTest`
Expected: FAIL with "Class "Blueworx\Forge\Admin\Changelog" not found".

- [ ] **Step 3: Write the parser**

```php
<?php
/**
 * The plugin's changelog, read for the admin screen.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Admin;

/**
 * Reads CHANGELOG.md into releases (#466). It understands only the shape the
 * file is written in: `## [x.y.z] - date`, `### Heading`, `- entry` with
 * indented continuation lines, and the odd plain paragraph. Pure apart from
 * read().
 */
final class Changelog {

	/**
	 * The releases in a changelog, in file order (newest first).
	 *
	 * @param string $markdown The file's contents.
	 * @return list<array{version: string, date: string, sections: list<array{heading: string, entries: list<string>}>}>
	 */
	public static function parse( string $markdown ): array {
		$releases = array();
		$release  = -1;
		$section  = -1;
		$open     = false;

		foreach ( preg_split( '/\r\n|\n/', $markdown ) as $line ) {
			if ( 1 === preg_match( '/^## \[([^\]]+)\](?:\s*-\s*(\S+))?/', $line, $found ) ) {
				$releases[] = array(
					'version'  => $found[1],
					'date'     => $found[2] ?? '',
					'sections' => array(),
				);
				$release    = count( $releases ) - 1;
				$section    = -1;
				$open       = false;
				continue;
			}

			// Anything before the first release is the file's own intro.
			if ( $release < 0 ) {
				continue;
			}

			if ( 1 === preg_match( '/^### (.+)$/', $line, $found ) ) {
				$releases[ $release ]['sections'][] = array(
					'heading' => trim( $found[1] ),
					'entries' => array(),
				);
				$section                            = count( $releases[ $release ]['sections'] ) - 1;
				$open                               = false;
				continue;
			}

			if ( '' === trim( $line ) ) {
				$open = false;
				continue;
			}

			if ( $section < 0 ) {
				continue;
			}

			$entries = &$releases[ $release ]['sections'][ $section ]['entries'];

			if ( 1 === preg_match( '/^- (.*)$/', $line, $found ) ) {
				$entries[] = trim( $found[1] );
				$open      = true;
			} elseif ( $open ) {
				$entries[ count( $entries ) - 1 ] .= ' ' . trim( $line );
			} else {
				$entries[] = trim( $line );
				$open      = true;
			}

			unset( $entries );
		}

		return $releases;
	}

	/**
	 * One entry as HTML: escaped, with bold and code kept and links reduced to
	 * their text.
	 *
	 * @param string $text The entry.
	 * @return string
	 */
	public static function inline( string $text ): string {
		$html = esc_html( preg_replace( '/\[([^\]]+)\]\([^)]*\)/', '$1', $text ) ?? $text );
		$html = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $html ) ?? $html;

		return preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $html ) ?? $html;
	}

	/**
	 * The releases in a changelog file, or null when it cannot be read.
	 *
	 * @param string $path The file.
	 * @return list<array{version: string, date: string, sections: list<array{heading: string, entries: list<string>}>}>|null
	 */
	public static function read( string $path ): ?array {
		if ( ! is_readable( $path ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file shipped with the plugin, not a remote URL.
		$markdown = file_get_contents( $path );

		return false === $markdown ? null : self::parse( $markdown );
	}
}
```

Check the `esc_html` stub escapes `<`, `>` and `&` the way WordPress does. If it doesn't, the escaping assertion tells you.

- [ ] **Step 4: Run it and see it pass**

Run: `vendor/bin/phpunit --filter ChangelogTest`
Expected: OK (7 tests).

- [ ] **Step 5: Commit**

```bash
git add includes/Admin/Changelog.php tests/php/ChangelogTest.php
git commit -m "Read the changelog into releases (#466)"
```

### Task 2: The Changelog screen

**Files:**
- Create: `includes/Admin/ChangelogScreen.php`
- Modify: `includes/Plugin.php:79` (register beside Updates)
- Test: `tests/e2e/changelog-screen.spec.js`

**Interfaces:**
- Consumes: `Changelog::read( string $path ): ?array`, `Changelog::inline( string $text ): string`, `Page::open/panel_open/panel_close/notice/close`, `SyncScreen::SLUG`, `BWX_FORGE_VERSION`, `BWX_FORGE_DIR` (check the constant name for the plugin's folder in `blueworx-forge.php`).
- Produces: `ChangelogScreen::SLUG = 'blueworx-forge-changelog'`. Markup hooks: `[data-bwx-release="x.y.z"]` on each release panel, `[data-bwx-installed-release]` on the installed one, and `[data-bwx-changelog="missing"]` on the notice.

- [ ] **Step 1: Write the failing test**

```js
import { test, expect } from '@playwright/test';
import { signIn } from '../helpers/sign-in.js';
import { readFileSync } from 'node:fs';

// #466. Every release and what changed in it, beside Updates, with the
// release this site runs marked.

const CHANGELOG = '/wp-admin/admin.php?page=blueworx-forge-changelog';
const VERSION = JSON.parse(readFileSync(new URL('../../package.json', import.meta.url))).version;

test.beforeEach(async ({ page }) => {
  await signIn(page);
});

test('the Forge menu offers the changelog, after Updates', async ({ page }) => {
  await page.goto('/wp-admin/admin.php?page=blueworx-forge-updates');

  const links = page.locator('#adminmenu a[href*="page=blueworx-forge-"]');
  const hrefs = await links.evaluateAll((all) => all.map((a) => a.getAttribute('href')));
  const updates = hrefs.findIndex((h) => h.endsWith('page=blueworx-forge-updates'));
  expect(hrefs[updates + 1]).toMatch(/page=blueworx-forge-changelog$/);
});

test('it lists the releases, newest first, with this site\'s marked', async ({ page }) => {
  await page.goto(CHANGELOG);

  await expect(page.locator('.bw-admin.bw-page')).toBeVisible();
  const installed = page.locator('[data-bwx-installed-release]');
  await expect(installed).toHaveAttribute('data-bwx-release', VERSION);
  await expect(installed).toContainText('Installed');

  const first = page.locator('[data-bwx-release]').first();
  await expect(first).toHaveAttribute('data-bwx-release', VERSION);
  expect(await page.locator('[data-bwx-release]').count()).toBeGreaterThan(50);
});
```

- [ ] **Step 2: Run it and see it fail**

Run: `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8892 WP_ADMIN_USER=admin WP_ADMIN_PASS=admin npx playwright test tests/e2e/changelog-screen.spec.js --workers=1`
Expected: FAIL (no menu link, and the page is not found).

- [ ] **Step 3: Write the screen**

```php
<?php
/**
 * Every release, and what changed in it.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Admin;

/**
 * The changelog, beside Updates (#466). It shows what this site has installed
 * and everything before it. Notes for a newer release stay where WordPress
 * shows them, in the update's View details box.
 */
final class ChangelogScreen {

	/**
	 * The submenu page slug.
	 */
	public const SLUG = 'blueworx-forge-changelog';

	/**
	 * Adds the menu entry, under the Forge menu.
	 */
	public static function register(): void {
		add_submenu_page(
			SyncScreen::SLUG,
			__( 'Changelog', 'blueworx-forge' ),
			__( 'Changelog', 'blueworx-forge' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * Renders the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		Page::open(
			__( 'Changelog', 'blueworx-forge' ),
			__( 'Forge', 'blueworx-forge' ),
			__( 'Every release of Forge, newest first, and what changed in it.', 'blueworx-forge' )
		);

		$releases = Changelog::read( BWX_FORGE_DIR . 'CHANGELOG.md' );

		if ( null === $releases || array() === $releases ) {
			Page::notice( 'info', __( 'The changelog is not included in this copy of Forge.', 'blueworx-forge' ), array( 'data-bwx-changelog' => 'missing' ) );
			Page::close();
			return;
		}

		foreach ( $releases as $release ) {
			self::render_release( $release );
		}

		Page::close();
	}

	/**
	 * One release as a panel.
	 *
	 * @param array{version: string, date: string, sections: list<array{heading: string, entries: list<string>}>} $release The release.
	 */
	private static function render_release( array $release ): void {
		$installed = BWX_FORGE_VERSION === $release['version'];
		$heading   = '' === $release['date'] ? $release['version'] : sprintf( '%1$s — %2$s', $release['version'], $release['date'] );

		printf(
			'<div data-bwx-release="%1$s"%2$s>',
			esc_attr( $release['version'] ),
			$installed ? ' data-bwx-installed-release="1"' : ''
		);
		Page::panel_open( $installed ? sprintf( '%s · Installed', $heading ) : $heading, 'release-' . $release['version'] );

		foreach ( $release['sections'] as $section ) {
			printf( '<h3 class="bw-card__subhead">%s</h3>', esc_html( $section['heading'] ) );
			echo '<ul class="bw-list">';

			foreach ( $section['entries'] as $entry ) {
				// Escaped inside inline(); only strong and code survive.
				echo '<li>' . Changelog::inline( $entry ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Changelog::inline().
			}

			echo '</ul>';
		}

		Page::panel_close();
		echo '</div>';
	}
}
```

Before relying on `bw-card__subhead` and `bw-list`, check the class names exist in the admin design system (`grep -rn "bw-card__\|bw-list" includes/Admin assets/`). Use the nearest existing class; don't invent CSS. Check the plugin-folder constant too (`grep -n "define(" blueworx-forge.php`) and use its real name. If the panel heading helper escapes its title, "Installed" is plain text inside it, which is fine.

In `includes/Plugin.php`, directly after the Updates line (priority 99):

```php
		// Beside Updates, and after it (#466).
		add_action( 'admin_menu', array( Admin\ChangelogScreen::class, 'register' ), 100 );
```

- [ ] **Step 4: Run it and see it pass**

Run: `npm run build`, then run the Step 2 command again (PHP is live-linked, so no restart is needed).
Expected: 2 passed. Also re-run `tests/e2e/updates-screen.spec.js` and `tests/e2e/admin-design-system.spec.js`: both pass.

- [ ] **Step 5: Version, changelog, commit**

Bump to 2.154.0 in all four places. Add this at the top of `CHANGELOG.md`:

```markdown
## [2.154.0] - 2026-09-29

### Added

- A Changelog page in the Forge menu in WordPress admin, beside Updates. It lists every release, newest first, with what changed in each, and marks the version this site runs.
```

Re-run the spec so the Installed marker follows the new version. Then:

```bash
git add includes/Admin/ChangelogScreen.php includes/Plugin.php tests/e2e/changelog-screen.spec.js package.json blueworx-forge.php client/blueworx-forge-client.php CHANGELOG.md assets/
git commit -m "Changelog page in the Forge admin menu (#466)"
```

- [ ] **Step 6:** Push, and open a draft PR "Add a Changelog page to the Forge admin menu", closing #466, with the plan and spec included.

---

## PR 2 — Settings (#467), branch `add-settings-rules`, cut from main after PR 1 merges

### Task 3: The rules content

**Files:**
- Create: `src/rules.ts`

**Interfaces:**
- Produces: `interface Rule { what: string; why: string }`, `interface RuleSection { id: string; title: string; rules: Rule[] }`, `export const RULES: RuleSection[]`. Section ids in order: `journey`, `triage`, `review`, `blocked`, `back`, `recurring`, `meetings`, `capacity`, `reminders`, `requests`, `people`.

- [ ] **Step 1:** Copy the drafted rules (made while this plan was written, checked against the code) from the session scratchpad `rules.ts` into `src/rules.ts`. Read every rule once more against the code it describes. Where a draft rule and the code disagree, the code wins. Record each disagreement in the PR description for Luke.
- [ ] **Step 2:** `npm run build`. Expected: builds with no type errors from `src/rules.ts`.
- [ ] **Step 3: Commit**: `git add src/rules.ts && git commit -m "Forge's rules in plain English (#467)"`

### Task 4: The Settings screen

**Files:**
- Create: `src/components/SettingsScreen.tsx`
- Modify: `src/types.ts:192` (`ScreenName` gains `'settings'`), `src/App.tsx` (import, `RAIL`, `TITLES`, `OPENINGS`, render line)
- Test: `tests/e2e/settings-screen.spec.js`. Modify `tests/e2e/forge-manager.spec.js:80` (add `'settings'` to the hidden list), `tests/e2e/mobile-screens.spec.js:98` (add `'bwx-screen-settings'`), `tests/e2e/accessibility.spec.js:42` (add `[ 'Settings', 'bwx-screen-settings' ]`).

**Interfaces:**
- Consumes: `RULES`, `RuleSection` from `src/rules.ts`; `SectionTitle`, `Panel` from `src/kit`.
- Produces: test ids `bwx-screen-settings` (rail), `bwx-settings` (screen root), `bwx-rules-jump` (jump links nav), `bwx-rules-<id>` (each section).

- [ ] **Step 1: Write the failing test**

```js
import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #467. Every rule Forge follows, in plain English, for administrators.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';
const SECTIONS = [ 'journey', 'triage', 'review', 'blocked', 'back', 'recurring', 'meetings', 'capacity', 'reminders', 'requests', 'people' ];

test('an administrator reads the rules under Insight, and jumps to a section', async ({ browser, baseURL }) => {
  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const page = await admin.context.newPage();
  await page.goto('/blueworx-forge/');
  await expect(page.getByTestId('bwx-forge-ready')).toBeVisible({ timeout: 15_000 });

  await page.getByTestId('bwx-screen-settings').click();
  const screen = page.getByTestId('bwx-settings');
  await expect(screen).toBeVisible();

  for (const id of SECTIONS) {
    const section = page.getByTestId(`bwx-rules-${id}`);
    await expect(section).toHaveCount(1);
    expect(await section.locator('li').count(), `${id} has no rules`).toBeGreaterThan(0);
  }

  await page.getByTestId('bwx-rules-jump').getByRole('link', { name: 'Reminders' }).click();
  await expect(page.getByTestId('bwx-rules-reminders')).toBeInViewport();

  await admin.context.close();
});
```

Also make the three edits to the existing specs listed under Files.

- [ ] **Step 2: Run it and see it fail**

Run: `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8892 WP_ADMIN_USER=admin WP_ADMIN_PASS=admin npx playwright test tests/e2e/settings-screen.spec.js --workers=1`
Expected: FAIL, because `bwx-screen-settings` is not found.

- [ ] **Step 3: Write the screen**

`src/components/SettingsScreen.tsx`:

```tsx
import { Panel, SectionTitle } from '../kit';
import { RULES } from '../rules';

/**
 * Every rule Forge follows, in plain English (#467): what happens, and why.
 *
 * Read-only by design. The rules are written in src/rules.ts, and a change to
 * how Forge behaves changes that file in the same pull request.
 */
export function SettingsScreen() {
  return (
    <div className="bwx-settings" data-testid="bwx-settings">
      <p className="bwx-hint">
        How Forge behaves, and why. Nothing here can be changed from this page.
      </p>

      <nav aria-label="Rule sections" data-testid="bwx-rules-jump" className="bwx-rules-jump">
        { RULES.map( ( section ) => (
          <a key={ section.id } href={ `#rules-${ section.id }` } onClick={ ( event ) => {
            event.preventDefault();
            document.getElementById( `rules-${ section.id }` )?.scrollIntoView( { behavior: 'smooth', block: 'start' } );
          } }>
            { section.title }
          </a>
        ) ) }
      </nav>

      { RULES.map( ( section ) => (
        <section key={ section.id } id={ `rules-${ section.id }` } data-testid={ `bwx-rules-${ section.id }` } aria-labelledby={ `rules-${ section.id }-title` }>
          <SectionTitle><span id={ `rules-${ section.id }-title` }>{ section.title }</span></SectionTitle>
          <Panel>
            <ul className="bwx-rules">
              { section.rules.map( ( rule ) => (
                <li key={ rule.what }>
                  <p className="bwx-rule-what">{ rule.what }</p>
                  <p className="bwx-hint">{ rule.why }</p>
                </li>
              ) ) }
            </ul>
          </Panel>
        </section>
      ) ) }
    </div>
  );
}
```

The jump links use `preventDefault` because the app reads `#screen=` from the hash on load, and a bare `#rules-x` would be left in the address bar. Check how `SectionTitle` renders its heading level (it should be the screen's `h2`), and use the kit's own heading if it gives one. Add the few layout rules (`.bwx-rules-jump` as a wrapping row of links with a gap, `.bwx-rules` with no bullets and a gap between rules, `.bwx-rule-what` in the body weight) next to the other screen styles. Find where `bwx-hint` is defined and put them in that stylesheet, using existing tokens only.

`src/types.ts:192`: add `| 'settings'` to `ScreenName`.

`src/App.tsx`:
- Import: `import { SettingsScreen } from './components/SettingsScreen';`. Import `ScrollText` from `lucide-react` alongside the other icons.
- `RAIL`, after Packages: `{ key: 'settings', label: 'Settings', icon: ScrollText, testId: 'bwx-screen-settings', admin: true },`
- `TITLES`: `settings: 'Settings',`
- `OPENINGS`: `settings: { crumbs: [ 'Insight', 'Settings' ], eyebrow: 'How Forge behaves, and why', tile: ScrollText, hue: 'slate' },`
- Render, after packages: `{ 'settings' === screen && <SettingsScreen key={ generation } /> }`

- [ ] **Step 4: Run and see it pass**

Run: `npm run build`, then the Step 2 command. Expected: 1 passed.
Then run `tests/e2e/forge-manager.spec.js`, `tests/e2e/mobile-screens.spec.js` and `tests/e2e/accessibility.spec.js`: all pass. The Manager spec also covers the deep-link case, because `ADMIN_ONLY` is derived from `RAIL`. Confirm the spec has a deep-link assertion for an admin screen. If it doesn't, add `await page.goto('/blueworx-forge/#screen=settings'); await expect(page.getByTestId('bwx-settings')).toHaveCount(0);` to the Manager test.

- [ ] **Step 5: Visual check.** Run `npm run dev` and let Luke look at the Settings screen before committing (project rule 7).

- [ ] **Step 6: Keep the rules true, version, changelog, commit**

Add to `CLAUDE.md` under "Rules":

```markdown
- **The rules page stays true.** A change to how Forge behaves updates `src/rules.ts`
  (the Settings screen, #467) in the same pull request as its changelog entry.
```

Bump to 2.155.0 in all four places. Add this to `CHANGELOG.md`:

```markdown
## [2.155.0] - 2026-09-29

### Added

- A Settings page under Insight, for administrators, listing every rule Forge follows in plain English: what happens, and why.
```

```bash
git add src/ tests/e2e/ CLAUDE.md package.json blueworx-forge.php client/blueworx-forge-client.php CHANGELOG.md assets/
git commit -m "Settings page listing Forge's rules (#467)"
```

- [ ] **Step 7:** Push, and open a draft PR "Add a Settings page listing Forge's rules", closing #467. Its description lists any disagreements between the design records and the code, and asks Luke to review the wording.

---

## Final checks (each PR)

`npm run lint`, `composer lint` (the only failure on a Windows checkout should be the CRLF line-ending sniff on untouched files; CI runs on LF), `vendor/bin/phpunit`, and the specs named above. Present any lint findings to Luke rather than fixing them in a loop.
