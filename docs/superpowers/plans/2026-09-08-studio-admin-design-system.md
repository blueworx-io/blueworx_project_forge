# The studio's backend on the shared admin design system — implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every screen the Blueworx Forge studio plugin puts in wp-admin is built from the foundation's shared admin design system, and the Updates screen is built by the shared page editor library.

**Architecture:** #285 already vendored the design system, built the sync step that lays it into `assets/`, and widened the artifact list. This adds a shared page shell — `Admin\Page`, the studio's counterpart to the client's — and rebuilds eleven screens on it, one task each, holding the eleven existing Playwright specs steady as the regression net. Then a second branch ships the page editor library inside the studio zip and moves the Updates screen onto it.

**Tech Stack:** PHP 8.2+, WordPress 6.5+ (plugin, `Blueworx\Forge` namespace), Node 24 tooling, Vite 5, Playwright, PHPUnit 9, PHPCS (WordPress standard).

**Spec:** `docs/superpowers/specs/2026-09-08-studio-admin-design-system-design.md`

## Global Constraints

- **Two branches, in order.** `studio-admin-design-system` is PR one (the look). `studio-admin-page-editor`, branched from PR one's head, is PR two (Updates on the library). Never one branch for both.
- **Never merge to main and never push a tag.** Both PRs open as drafts. Releases are a separate, asked-for decision.
- **Nothing under `assets/` or `blueworx-page-editor/` is ever hand-edited.** Both are produced by `bin/sync-design-system.mjs` from the vendored skill folder at `.claude/skills/blueworx-admin-design`, which is itself byte-compared against the foundation on every PR.
- **Every `data-bwx-*` attribute and `id="bwx-*"` survives.** They are the contract the eleven specs hold — 167 distinct hooks. Each task below lists its screen's. Adding new ones is fine; removing or renaming one is not, unless the same commit updates the spec that reads it.
- **PR one changes no save path.** Every `$_POST` handler, nonce, capability check and redirect stays exactly as it is. If a task finds itself editing an `*Actions.php` file, it has gone wrong.
- **The look is the foundation's.** Use its `--bw-*` variables and its `bw-` classes. Do not build a mapping layer onto Forge's `--color-*` / `--text-*` / `--surface-*` tokens, and do not add a stylesheet of the studio's own.
- **Submit controls stay `<input type="submit">`.** Forty-six assertions across thirteen specs click `input[type="submit"]`, so the element is as much part of the contract as a `data-bwx` hook is. Keep `submit_button()` and pass it the class: `submit_button( __( 'Save', 'blueworx-forge' ), 'bw-btn bw-btn--primary', 'submit', false )`. Never swap one for a `<button>`.
- **Whole class names, never assembled from a variable.** `'bw-notice bw-notice--danger'`, not `'bw-notice bw-notice--' . $tone`. The client's `Page::notice()` does this deliberately and the reason is written there.
- **Both guardrails stay at `error`.** Never add `design_system_sync: warn` or `admin_ui_adherence: warn` to `.github/workflows/ci.yml`.
- **Version and changelog** are bumped on each pull request — minor bump, new behaviour. `package.json`, `blueworx-forge.php` and `client/blueworx-forge-client.php` must all agree, and CI fails if they do not. `package-lock.json`'s own `version` field is deliberately left alone.
- **Run before each commit:** `vendor/bin/phpunit` and `composer lint`. Run `npm run lint` and `npm run build` once per branch, at its closeout task.
- **Linting is not a loop.** Run it once at the closeout task, record what it says, and leave the findings for Luke to approve. Never lint → auto-fix → re-lint.

## The class vocabulary

Every screen is built from these. They are the design system's, extracted from `.claude/skills/blueworx-admin-design/styles.css`; if a screen seems to need one that is not here, read `styles.css` before inventing a class.

| Need | Classes |
|---|---|
| Page shell | `wrap bw-wrap`, `bw-admin bw-page`, `bw-panels` |
| Page header | `bw-pagehead`, `bw-pagehead__titles`, `bw-pagehead__eyebrow`, `bw-pagehead__h1`, `bw-pagehead__lede` |
| Card / panel | `bw-card`, `bw-card__head`, `bw-card__titles`, `bw-card__title`, `bw-card__eyebrow`, `bw-card__body`, `bw-card__foot`, `bw-card__actions`, `bw-card__note` |
| Table | `bw-tablescroll` (wrapper), `bw-table`, `bw-table__check`, `bw-table__num`, `bw-table__actions`, `bw-table__primary`, `bw-table__sub`, `bw-table__group`, `bw-table__group-title`, `bw-table__total`, `bw-table__total-label`, `bw-table__note`, `bw-tablefoot` |
| Row actions | `bw-rowactions`, `bw-rowactions__link`, `bw-rowactions__link--danger`, `bw-rowactions__sep` |
| Forms | `bw-formrow`, `bw-formrow__label`, `bw-formrow__control`, `bw-formrow__help`, `bw-formrow__req`, `bw-input`, `bw-input--mono`, `bw-textarea`, `bw-select`, `bw-select__el`, `bw-select__arrow`, `bw-check`, `bw-check__text`, `bw-check__help`, `bw-radiogroup`, `bw-switch`, `bw-switch__track`, `bw-switch__thumb`, `bw-savebar`, `bw-fieldnote` |
| Buttons | `bw-btn`, `bw-btn--primary`, `bw-btn--secondary`, `bw-btn--danger`, `bw-btn--link` |
| Status | `bw-badge`, `bw-badge--neutral`, `bw-badge--danger`, `bw-chip`, `bw-chip--plain`, `bw-chips` |
| Notices | `bw-notice`, `bw-notice--success`, `bw-notice--warning`, `bw-notice--danger`, `bw-notice--info`, `bw-notice__icon`, `bw-notice__body`, `bw-notice__text`, `bw-notice__title` |
| Empty | `bw-empty`, `bw-empty__icon`, `bw-empty__title`, `bw-empty__text` |
| Stats | `bw-stats`, `bw-stat`, `bw-stat__label`, `bw-stat__value`, `bw-stat__foot`, `bw-summary`, `bw-summary__cell`, `bw-summary__label`, `bw-summary__value` |
| Detail lists | `bw-dl`, `bw-dl--stack` |
| Activity | `bw-activity`, `bw-activity__item`, `bw-activity__dot`, `bw-activity__body`, `bw-activity__text`, `bw-activity__meta` |
| Progress | `bw-progress`, `bw-progress__row`, `bw-progress__label`, `bw-progress__track`, `bw-progress__bar`, `bw-progress__pct` |
| Accordion | `bw-accordion`, `bw-accordion__head`, `bw-accordion__title`, `bw-accordion__sub`, `bw-accordion__chev`, `bw-accordion__body` |
| Toolbar | `bw-toolbar`, `bw-toolbar--card`, `bw-toolbar__group`, `bw-toolbar__search`, `bw-toolbar__spacer` |
| Icons | `<i class="bw-icon" data-lucide="name"></i>` |

## The WordPress classes being replaced

Delete on sight, in this order of frequency: `regular-text` → `bw-input`; `description` → `bw-formrow__help` or `bw-fieldnote`; `form-table` → `bw-formrow` rows; `widefat striped` → `bw-table`; `wrap` → the shell's `wrap bw-wrap`; `notice notice-*` → `Page::notice()`; `button` / `button-small` → `bw-btn bw-btn--secondary`; `button-link` → `bw-btn bw-btn--link`; `small-text` / `large-text` → `bw-input`; `card` → `bw-card`. Keep `screen-reader-text` — it is WordPress's accessibility utility, not styling, and the design system has no counterpart.

## File structure

**New files**

| File | Responsibility |
|---|---|
| `includes/Admin/Page.php` | The shared page shell every studio screen renders inside — header, panels, notices. The studio's counterpart to `client/includes/Admin/Page.php`. |
| `tests/php/AdminPageTest.php` | Proves `Page`'s markup: the shell order, the tone classes, and that a notice escapes what it is given. |
| `tests/e2e/updates-editor.spec.js` | PR two. Covers the Updates screen's save path once the library owns it. |

**Modified files**

| File | Change |
|---|---|
| `includes/Admin/*Screen.php` (eleven) | Rebuilt on `Page` and the design system. |
| `includes/Admin/SitesScreen.php:62-79` | Its `enqueue()` stops loading `tokens/forge.css`; the shell's enqueue replaces it. |
| `includes/Plugin.php` | Hooks the shell's `admin_enqueue_scripts`, once, for all studio screens. |
| `bin/artifacts.json` | PR two: `blueworx-page-editor` joins the studio `include` list. |
| `.github/workflows/ci.yml:38-41`, `.github/workflows/release.yml:31` | PR two: the `/blueworx-page-editor` exclusion is removed from both. |
| `docs/architecture/decisions.md`, `docs/architecture/decisions-manifest.json` | PR two: ARCH-9. |
| `CHANGELOG.md`, `package.json`, `blueworx-forge.php`, `client/blueworx-forge-client.php` | Version and changelog, once per PR. |

## Sequencing

Task 1 builds the shell and is the only task with a genuinely new unit test. Tasks 2–12 are one screen each, smallest first, so the pattern is established on a six-hook screen before it reaches a forty-four-hook one. Task 13 closes PR one. Tasks 14–17 are PR two.

**Baseline first.** Before Task 2, and once only, bring up WordPress and record which of the eleven specs pass today:

```bash
npm run wp:up
npx playwright test tests/e2e/updates-screen.spec.js tests/e2e/sites-screen.spec.js \
  tests/e2e/sync-health.spec.js tests/e2e/sales-admin.spec.js \
  tests/e2e/package-catalogue.spec.js tests/e2e/people-screen.spec.js \
  tests/e2e/availability-screen.spec.js tests/e2e/capacity-screen.spec.js \
  tests/e2e/onboarding-template.spec.js tests/e2e/meetings.spec.js \
  tests/e2e/support-assignment.spec.js tests/e2e/support-hours-gate.spec.js \
  tests/e2e/clients-screen.spec.js tests/e2e/style-isolation.spec.js \
  tests/e2e/accessibility.spec.js --workers=1
```

A spec that is red before a task starts is red for a reason that is not this work. Record it; do not fix it inside a screen task.

---

### Task 1: The shared page shell

**Files:**
- Create: `includes/Admin/Page.php`
- Create: `tests/php/AdminPageTest.php`
- Modify: `includes/Plugin.php`
- Modify: `includes/Admin/SitesScreen.php:62-79`

**Interfaces:**
- Produces: `Blueworx\Forge\Admin\Page::open( string $title, string $eyebrow = '', string $lede = '' ): void`
- Produces: `Blueworx\Forge\Admin\Page::close(): void`
- Produces: `Blueworx\Forge\Admin\Page::panel_open( string $heading, string $name ): void`
- Produces: `Blueworx\Forge\Admin\Page::panel_close(): void`
- Produces: `Blueworx\Forge\Admin\Page::notice( string $tone, string $text, array $attributes = array(), bool $html = false ): void`
- Produces: `Blueworx\Forge\Admin\Page::enqueue( string $hook ): void`
- Produces: `Blueworx\Forge\Admin\Page::ours( string $hook ): bool`
- Every task after this one consumes all seven.

- [ ] **Step 1: Read the client's shell**

`client/includes/Admin/Page.php` is the model and is 150 lines. Read it whole before writing anything. The studio's differs in exactly three ways: the namespace is `Blueworx\Forge\Admin`, the constants are `BWX_FORGE_PATH` / `BWX_FORGE_URL`, and the eyebrow carries no `data-testid` (the studio's specs do not read one).

- [ ] **Step 2: Write the failing test**

Create `tests/php/AdminPageTest.php`:

```php
<?php

declare( strict_types = 1 );

use Blueworx\Forge\Admin\Page;
use PHPUnit\Framework\TestCase;

/**
 * The shell's markup, which eleven screens depend on being the same.
 */
final class AdminPageTest extends TestCase {

	/** Captures what a Page call echoes. */
	private function render( callable $fn ): string {
		ob_start();
		$fn();
		return (string) ob_get_clean();
	}

	public function test_open_writes_the_shell_in_the_order_the_system_fixes(): void {
		$html = $this->render(
			static function () {
				Page::open( 'Client sites', 'Studio', 'Every site we look after.' );
			}
		);

		$this->assertStringContainsString( '<div class="wrap bw-wrap">', $html );
		$this->assertStringContainsString( '<div class="bw-admin bw-page">', $html );
		$this->assertStringContainsString( 'bw-pagehead__eyebrow', $html );
		$this->assertStringContainsString( '<h1 class="bw-pagehead__h1">Client sites</h1>', $html );
		$this->assertStringContainsString( 'bw-pagehead__lede', $html );
		$this->assertStringContainsString( '<div class="bw-panels">', $html );

		// The header closes before the panel column opens.
		$this->assertLessThan(
			strpos( $html, 'bw-panels' ),
			strpos( $html, '</header>' ),
			'the panel column must open after the header closes'
		);
	}

	public function test_open_omits_the_eyebrow_and_lede_when_it_has_none(): void {
		$html = $this->render(
			static function () {
				Page::open( 'Updates' );
			}
		);

		$this->assertStringNotContainsString( 'bw-pagehead__eyebrow', $html );
		$this->assertStringNotContainsString( 'bw-pagehead__lede', $html );
	}

	public function test_a_panel_carries_its_name_for_tests_to_hold(): void {
		$html = $this->render(
			static function () {
				Page::panel_open( 'Connected sites', 'sites' );
			}
		);

		$this->assertStringContainsString( '<section class="bw-card"', $html );
		$this->assertStringContainsString( 'data-bwx-panel="sites"', $html );
		$this->assertStringContainsString( '<h2 class="bw-card__title">Connected sites</h2>', $html );
		$this->assertStringContainsString( '<div class="bw-card__body">', $html );
	}

	/**
	 * Whole class names, never a stem with the tone appended: the admin UI
	 * check reads the classes a screen writes, and one assembled from a
	 * variable is one it cannot see.
	 */
	public function test_each_tone_writes_its_whole_class_name(): void {
		foreach ( array( 'success', 'warning', 'danger', 'info' ) as $tone ) {
			$html = $this->render(
				static function () use ( $tone ) {
					Page::notice( $tone, 'Saved.' );
				}
			);

			$this->assertStringContainsString( 'class="bw-notice bw-notice--' . $tone . '"', $html );
		}
	}

	public function test_a_danger_notice_is_an_alert_and_the_rest_are_status(): void {
		$danger = $this->render(
			static function () {
				Page::notice( 'danger', 'That did not work.' );
			}
		);
		$info = $this->render(
			static function () {
				Page::notice( 'info', 'For information.' );
			}
		);

		$this->assertStringContainsString( 'role="alert"', $danger );
		$this->assertStringContainsString( 'role="status"', $info );
	}

	public function test_an_unknown_tone_falls_back_to_info_rather_than_writing_a_broken_class(): void {
		$html = $this->render(
			static function () {
				Page::notice( 'purple', 'Hello.' );
			}
		);

		$this->assertStringContainsString( 'class="bw-notice bw-notice--info"', $html );
	}

	public function test_notice_text_is_escaped_unless_the_caller_says_it_is_markup(): void {
		$escaped = $this->render(
			static function () {
				Page::notice( 'info', '<script>alert(1)</script>' );
			}
		);

		$this->assertStringNotContainsString( '<script>', $escaped );
	}

	public function test_ours_matches_studio_screens_only(): void {
		$this->assertTrue( Page::ours( 'toplevel_page_blueworx-forge-sites' ) );
		$this->assertTrue( Page::ours( 'forge_page_blueworx-forge-clients' ) );
		$this->assertFalse( Page::ours( 'edit.php' ) );
		$this->assertFalse( Page::ours( 'toplevel_page_some-other-plugin' ) );
	}
}
```

- [ ] **Step 3: Run it and watch it fail**

```bash
vendor/bin/phpunit --filter AdminPageTest
```

Expected: FAIL — `Class "Blueworx\Forge\Admin\Page" not found`.

- [ ] **Step 4: Write the shell**

Create `includes/Admin/Page.php` in the `Blueworx\Forge\Admin` namespace. Copy the client's structure exactly for `open`, `close`, `panel_open`, `panel_close` and `notice` — the same markup, the same icon map (`success` → `circle-check`, `warning` → `triangle-alert`, `danger` → `circle-alert`, `info` → `info`), the same whole-class-name array, the same `wp_kses_post` / `esc_html` fork on `$html`. Drop the client's `data-testid="bwx-client-scope"` from the eyebrow; the studio has no such hook.

Then add the two the client does not have:

```php
	/**
	 * Whether a hook belongs to one of the studio's own screens.
	 *
	 * Matched on the slug prefix rather than a list, because every studio
	 * screen's slug already begins with it and a list is one more thing to
	 * forget to add a screen to. The React application's pages are matched
	 * too and that is correct — they are studio screens, and the design
	 * system is scoped to `.bw-admin`, which they do not render.
	 *
	 * @param string $hook The screen being loaded.
	 */
	public static function ours( string $hook ): bool {
		return false !== strpos( $hook, '_page_blueworx-forge' );
	}

	/**
	 * Loads the design system on the studio's own screens, and nowhere else.
	 *
	 * Not on every admin screen: the stylesheet is a whole design system, and
	 * a plugin that paints the rest of somebody's wp-admin is a plugin they
	 * uninstall. style-isolation.spec.js is the spec that holds this.
	 *
	 * @param string $hook The screen being loaded.
	 */
	public static function enqueue( string $hook ): void {
		if ( ! self::ours( $hook ) ) {
			return;
		}

		$design = BWX_FORGE_PATH . 'assets/blueworx-admin-design.css';

		if ( ! file_exists( $design ) ) {
			return;
		}

		wp_enqueue_style(
			'blueworx-admin-design',
			BWX_FORGE_URL . 'assets/blueworx-admin-design.css',
			array(),
			(string) filemtime( $design )
		);

		$icons = BWX_FORGE_PATH . 'assets/blueworx-admin-icons.js';

		if ( file_exists( $icons ) ) {
			wp_enqueue_script_module(
				'blueworx-admin-icons',
				BWX_FORGE_URL . 'assets/blueworx-admin-icons.js',
				array(),
				(string) filemtime( $icons )
			);
		}
	}
```

- [ ] **Step 5: Run it and watch it pass**

```bash
vendor/bin/phpunit --filter AdminPageTest
```

Expected: PASS, 8 tests.

If `wp_enqueue_script_module` or `filemtime` is undefined in the stub bootstrap, add a stub to `tests/php/bootstrap.php` beside the existing `esc_html` — do not weaken the test to avoid it.

- [ ] **Step 6: Hook the enqueue once, and stop Sites doing its own**

In `includes/Plugin.php`, beside the other admin wiring:

```php
		add_action( 'admin_enqueue_scripts', array( Admin\Page::class, 'enqueue' ) );
```

In `includes/Admin/SitesScreen.php`, delete the whole `enqueue()` method and the `add_action` that registers it, and delete the now-unused `STYLE` constant. `tokens/forge.css` keeps shipping — the React application still loads it — but no admin screen pulls it in.

- [ ] **Step 7: Check nothing else referenced what was deleted**

```bash
grep -rn "SitesScreen::STYLE\|SitesScreen::enqueue" includes/ tests/
```

Expected: no output.

- [ ] **Step 8: Commit**

```bash
git add includes/Admin/Page.php tests/php/AdminPageTest.php includes/Plugin.php includes/Admin/SitesScreen.php
git commit -m "The shared page shell for the studio's admin screens

The foundation's page skeleton in one file, and the enqueue that loads
the design system on studio screens only. No screen uses it yet."
```

---

### Tasks 2–12: the screens

Every screen task has the same six steps. They are written out once here; each task below states only what is particular to it — its file, its hooks, its spec, and the shapes it is built from. **Repeat these six steps in full for every screen task.**

- [ ] **Step 1: Read the screen whole.** Do not start editing from a grep. Note every `data-bwx-*` and `id="bwx-*"` it writes; the task lists them, and the list is what you check against at the end.
- [ ] **Step 2: Run its spec and confirm it is green before you touch it.** `npx playwright test <its spec> --workers=1`. If it is red, stop and report — a red baseline is not this task's to fix.
- [ ] **Step 3: Rebuild the render path.** Replace the `<div class="wrap">` and `<h1>` with `Page::open()` / `Page::close()`; each section becomes `Page::panel_open()` / `Page::panel_close()`; each WordPress class becomes its counterpart from the vocabulary table; each `notice notice-*` becomes `Page::notice()`. **Touch the render path only** — no `$_POST` handler, no nonce, no capability check, no redirect.
- [ ] **Step 4: Check every hook survived.**

```bash
git show HEAD:includes/Admin/<Screen>.php | grep -o 'data-bwx-[a-z-]*\|id="bwx-[a-z-]*"' | sort -u > /tmp/before.txt
grep -o 'data-bwx-[a-z-]*\|id="bwx-[a-z-]*"' includes/Admin/<Screen>.php | sort -u > /tmp/after.txt
diff /tmp/before.txt /tmp/after.txt
```

Expected: no lines removed. Added lines are fine.

- [ ] **Step 5: Run its spec again.** Same command as Step 2. Expected: PASS, unchanged. A failure here is a hook you moved, renamed, or nested differently — fix the screen, never the spec.
- [ ] **Step 6: Commit.** `git commit -m "Rebuild the <name> screen on the design system"`.

---

### Task 2: Updates

**Files:** Modify `includes/Admin/UpdatesScreen.php` (187 lines)
**Spec:** `npx playwright test tests/e2e/updates-screen.spec.js --workers=1`
**Hooks that must survive:** `data-bwx-action`, `data-bwx-fixed`, `data-bwx-latest-release`, `data-bwx-result`, `data-bwx-update-token`, `data-bwx-updates`, `id="bwx-update-token"`

**Built from:** one `bw-card` panel. The token field is a `bw-formrow` with a `bw-input bw-input--mono`. The "can we currently fetch updates" statement is a `Page::notice()` — `success` when it works, `warning` when the token is missing, `danger` when it is set and refused. The forget-token control is `bw-btn bw-btn--danger`. The latest release line is a `bw-fieldnote`.

The smallest screen, and first on purpose: it establishes the pattern the other ten follow.

---

### Task 3: Sales

**Files:** Modify `includes/Admin/SalesScreen.php` (150 lines)
**Spec:** `npx playwright test tests/e2e/sales-admin.spec.js --workers=1`
**Hooks that must survive:** `data-bwx-attention`, `data-bwx-balance`, `data-bwx-reason`, `data-bwx-site`

**Built from:** `bw-tablescroll` around a `bw-table`. One row per site needing a conversation; the balance is a `bw-table__num`; each reason is a `bw-chip bw-chip--plain` inside a `bw-chips`. When nothing needs attention, a `bw-empty` with `bw-empty__icon` (`circle-check`), `bw-empty__title` and `bw-empty__text` — an empty sales screen is good news and should read as good news, not as a blank panel.

---

### Task 4: Sync

**Files:** Modify `includes/Admin/SyncScreen.php` (293 lines)
**Spec:** `npx playwright test tests/e2e/sync-health.spec.js --workers=1`
**Hooks that must survive:** `data-bwx-sync-all`, `data-bwx-sync-queue`, `data-bwx-sync-reasons`, `data-bwx-sync-row`, `data-bwx-sync-site`, `data-bwx-sync-state`, `data-bwx-sync-waiting`

**Built from:** two `bw-card` panels, in the order the screen already fixes — the queue first, then every site. Both are `bw-table`s. The state cell is a `bw-badge`: `bw-badge--danger` for a site that has stopped talking, `bw-badge--neutral` for one that is waiting, no modifier for one that is fine. The reasons list is `bw-chips`. The empty queue keeps its existing wording and becomes a `bw-empty` — the file's own comment explains why the empty case matters here, and it still applies.

---

### Task 5: Packages

**Files:** Modify `includes/Admin/PackagesScreen.php` (346 lines)
**Spec:** `npx playwright test tests/e2e/package-catalogue.spec.js --workers=1`
**Hooks that must survive:** `data-bwx-history`, `data-bwx-hours`, `data-bwx-package`, `data-bwx-package-version`, `data-bwx-packages`, `data-bwx-price`, `data-bwx-result`, `data-bwx-status`, `data-bwx-version`, `id="bwx-new-terms"`

**Built from:** one `bw-card` per package. The current version's hours and price are a `bw-summary` with two `bw-summary__cell`s. Every past version is a `bw-activity__item` inside a `bw-activity` — the version number in `bw-activity__text`, the hours and price in `bw-activity__meta`. That shape is the point of the screen: the file's comment says somebody using it should come away knowing that editing a package writes a new version rather than changing the old one, and a list that reads as history says that better than a table does.

---

### Task 6: Sites

**Files:** Modify `includes/Admin/SitesScreen.php` (289 lines, minus the `enqueue()` Task 1 removed)
**Spec:** `npx playwright test tests/e2e/sites-screen.spec.js --workers=1`
**Hooks that must survive:** `data-bwx-action`, `data-bwx-issued-key`, `data-bwx-key`, `data-bwx-no-refusals`, `data-bwx-no-sites`, `data-bwx-refusals`, `data-bwx-register`, `data-bwx-result`, `data-bwx-site`, `data-bwx-site-id`, `data-bwx-sites`, `data-bwx-status`, `id="bwx-site-name"`, `id="bwx-site-url"`

**Built from:** a `bw-card` holding the connect form (two `bw-formrow`s and a `bw-btn bw-btn--primary`), then a `bw-card` holding the sites `bw-table` with `bw-rowactions` per row — disconnect is `bw-rowactions__link--danger`. The issued key, shown once after connecting, is a `Page::notice( 'success', … )` and the key itself a `bw-input bw-input--mono` set `readonly`. Refusals are a `bw-table` in their own panel, or a `bw-empty` when there are none.

---

### Task 7: People

**Files:** Modify `includes/Admin/PeopleScreen.php` (395 lines)
**Spec:** `npx playwright test tests/e2e/people-screen.spec.js --workers=1`
**Hooks that must survive:** `data-bwx-add-person`, `data-bwx-edit-person`, `data-bwx-grant`, `data-bwx-membership`, `data-bwx-membership-client`, `data-bwx-membership-grant`, `data-bwx-membership-grants`, `data-bwx-membership-role`, `data-bwx-membership-role-label`, `data-bwx-membership-scope`, `data-bwx-memberships`, `data-bwx-no-memberships`, `data-bwx-no-people`, `data-bwx-notice`, `data-bwx-offboard`, `data-bwx-people`, `data-bwx-person`, `data-bwx-person-email`, `data-bwx-person-name`, `data-bwx-status`, `data-bwx-status-toggle`, `id="bwx-person-email"`, `id="bwx-person-name"`

**Built from:** the add-person form in a `bw-card`, then one `bw-card` per person. Each person's memberships are a nested `bw-table`; each grant is a `bw-chip`. The status toggle is a `bw-switch` (`bw-switch__track`, `bw-switch__thumb`). Keep the one-row-per-person shape exactly — the file's comment says a person appearing twice is the failure this screen exists to show, and a rebuild that groups differently would hide it.

---

### Task 8: Onboarding templates

**Files:** Modify `includes/Admin/OnboardingTemplateScreen.php` (401 lines)
**Spec:** `npx playwright test tests/e2e/onboarding-template.spec.js --workers=1`
**Hooks that must survive:** `data-bwx-add-step`, `data-bwx-copy-template`, `data-bwx-launch-critical`, `data-bwx-no-steps`, `data-bwx-no-template`, `data-bwx-publish-template`, `data-bwx-remove-step`, `data-bwx-result`, `data-bwx-start-draft`, `data-bwx-state`, `data-bwx-step`, `data-bwx-steps`, `data-bwx-template-name`, `data-bwx-version`, `data-bwx-versions`, `id="bwx-step-allows-na"`, `id="bwx-step-category"`, `id="bwx-step-description"`, `id="bwx-step-launch-critical"`, `id="bwx-step-owner"`, `id="bwx-step-position"`, `id="bwx-step-section"`, `id="bwx-step-title"`, `id="bwx-template-name"`

**Built from:** a `bw-card` per version, the draft first. The state is a `bw-badge`. Steps are a `bw-table` grouped by section with `bw-table__group` / `bw-table__group-title`; launch-critical steps carry a `bw-badge--danger`. The add-step form is `bw-formrow`s with a `bw-savebar`.

**Watch this one.** A published version has no editing controls at all, deliberately — ONB-E2, and the file says so. Whatever the rebuild does, a published version must still render no form, no button and no input. Check it by eye as well as by spec.

---

### Task 9: Availability

**Files:** Modify `includes/Admin/AvailabilityScreen.php` (466 lines)
**Spec:** `npx playwright test tests/e2e/availability-screen.spec.js tests/e2e/capacity-screen.spec.js --workers=1`
**Hooks that must survive:** `data-bwx-add-leave`, `data-bwx-availability`, `data-bwx-availability-days`, `data-bwx-available-hours`, `data-bwx-day`, `data-bwx-day-hours`, `data-bwx-day-reason`, `data-bwx-leave`, `data-bwx-leave-kind`, `data-bwx-leave-record`, `data-bwx-no-leave`, `data-bwx-no-people`, `data-bwx-pattern`, `data-bwx-pattern-history`, `data-bwx-pattern-week`, `data-bwx-person-name`, `data-bwx-person-picker`, `data-bwx-remove-leave`, `data-bwx-result`, `data-bwx-set-hours`, `id="bwx-effective-from"`, `id="bwx-leave-from"`, `id="bwx-leave-kind"`, `id="bwx-leave-note"`, `id="bwx-leave-to"`, `id="bwx-person"`

**Built from:** the person picker in a `bw-toolbar bw-toolbar--card` with a `bw-select`. The working week is a `bw-table`, one row per day, hours in `bw-table__num`. Pattern history is a `bw-activity`. Time off is a `bw-table` with `bw-rowactions__link--danger` to remove an entry, or a `bw-empty` when there is none. Two `bw-card` panels: the week, then time off.

---

### Task 10: Support

**Files:** Modify `includes/Admin/SupportScreen.php` (524 lines)
**Spec:** `npx playwright test tests/e2e/support-assignment.spec.js tests/e2e/support-hours-gate.spec.js --workers=1`
**Hooks that must survive:** `data-bwx-assignable`, `data-bwx-balance`, `data-bwx-entry`, `data-bwx-entry-hours`, `data-bwx-entry-source`, `data-bwx-ledger`, `data-bwx-may-use-hours`, `data-bwx-period`, `data-bwx-period-state`, `data-bwx-periods`, `data-bwx-preview`, `data-bwx-preview-days`, `data-bwx-preview-hours`, `data-bwx-result`, `data-bwx-site-picker`, `data-bwx-support`, `data-bwx-support-state`, `id="bwx-adjust-hours"`, `id="bwx-adjust-reason"`, `id="bwx-assign-from"`, `id="bwx-assign-note"`, `id="bwx-assign-package"`, `id="bwx-assign-until"`, `id="bwx-site-pick"`, `id="bwx-top-up-hours"`, `id="bwx-top-up-note"`

**Built from:** the site picker in a `bw-toolbar bw-toolbar--card`. The balance is a `bw-stats` row of `bw-stat`s — hours remaining as the `bw-stat__value`, the period as `bw-stat__foot`. The ledger is a `bw-table` with `bw-table__num` for hours and a `bw-table__total` row. The pro-rata preview is a `Page::notice( 'info', … )` — it is a statement about what a save would do, not a field. Assignment, top-up and adjustment are three `bw-card` panels of `bw-formrow`s, each with its own `bw-savebar`.

---

### Task 11: Meetings

**Files:** Modify `includes/Admin/MeetingsScreen.php` (539 lines)
**Spec:** `npx playwright test tests/e2e/meetings.spec.js --workers=1`
**Hooks that must survive:** `data-bwx-ledger-state`, `data-bwx-meeting`, `data-bwx-meeting-status`, `data-bwx-meetings`, `data-bwx-moved-from`, `data-bwx-result`, `data-bwx-series`, `data-bwx-series-hours`, `data-bwx-series-row`, `data-bwx-series-state`, `data-bwx-settle`, `data-bwx-site-picker`, `id="bwx-frequency"`, `id="bwx-host"`, `id="bwx-site-pick"`

**Built from:** the site picker in a `bw-toolbar bw-toolbar--card`. Series are a `bw-table`, each with a `bw-badge` state and `bw-rowactions`. Occurrences are a `bw-table` below, the settle control a `bw-btn bw-btn--secondary`, and a moved occurrence keeps its "moved from" line as a `bw-table__sub` under the date. The ledger state is a `bw-badge`.

---

### Task 12: Clients

**Files:** Modify `includes/Admin/ClientsScreen.php` (889 lines)
**Spec:** `npx playwright test tests/e2e/clients-screen.spec.js --workers=1`
**Hooks that must survive:** `data-bwx-action`, `data-bwx-add-client`, `data-bwx-add-membership`, `data-bwx-add-site`, `data-bwx-assign-contact`, `data-bwx-assign-onboarding`, `data-bwx-client`, `data-bwx-client-name`, `data-bwx-client-people`, `data-bwx-clients`, `data-bwx-connection`, `data-bwx-contact`, `data-bwx-contact-name`, `data-bwx-contact-needs-reassignment`, `data-bwx-contact-none`, `data-bwx-deactivate-client`, `data-bwx-deactivate-site`, `data-bwx-edit-client`, `data-bwx-edit-site`, `data-bwx-end-membership`, `data-bwx-issue-key`, `data-bwx-issued-key`, `data-bwx-key`, `data-bwx-last-seen`, `data-bwx-mail`, `data-bwx-membership`, `data-bwx-membership-person`, `data-bwx-membership-role`, `data-bwx-membership-role-label`, `data-bwx-no-client-people`, `data-bwx-no-clients`, `data-bwx-no-people-yet`, `data-bwx-notice`, `data-bwx-onboarding`, `data-bwx-onboarding-blocking`, `data-bwx-onboarding-ready`, `data-bwx-onboarding-unavailable`, `data-bwx-revoke-key`, `data-bwx-site`, `data-bwx-site-id`, `data-bwx-site-name`, `data-bwx-sites`, `data-bwx-status`, `data-bwx-status-toggle`, `id="bwx-client-domains"`, `id="bwx-client-name"`, `id="bwx-client-timezone"`

**Built from:** the add-client form in a `bw-card`, then one `bw-card` per client with four `bw-accordion` sections inside it — sites, people, contact, onboarding. Sites and people are `bw-table`s with `bw-rowactions`. Onboarding readiness is a `bw-progress` with `bw-progress__label` and `bw-progress__pct`; a blocking step is a `bw-badge--danger`. The issued key is a `Page::notice( 'success', … )` with a readonly `bw-input--mono`, exactly as on Sites. Status toggles are `bw-switch`es.

**The biggest screen and the last one.** Forty-four hooks in 889 lines. Do it in two commits if that reads better — the add-client form and the client list first, the four accordion sections second — running the spec after each.

---

### Task 13: Close PR one

**Files:** Modify `CHANGELOG.md`, `package.json`, `blueworx-forge.php`, `client/blueworx-forge-client.php`

- [ ] **Step 1: Run the whole net**

```bash
npm run wp:up
npx playwright test tests/e2e/updates-screen.spec.js tests/e2e/sites-screen.spec.js \
  tests/e2e/sync-health.spec.js tests/e2e/sales-admin.spec.js \
  tests/e2e/package-catalogue.spec.js tests/e2e/people-screen.spec.js \
  tests/e2e/availability-screen.spec.js tests/e2e/capacity-screen.spec.js \
  tests/e2e/onboarding-template.spec.js tests/e2e/meetings.spec.js \
  tests/e2e/support-assignment.spec.js tests/e2e/support-hours-gate.spec.js \
  tests/e2e/clients-screen.spec.js tests/e2e/style-isolation.spec.js \
  tests/e2e/accessibility.spec.js --workers=1
```

Expected: the same result as the baseline recorded before Task 2. `style-isolation.spec.js` and `accessibility.spec.js` matter most — the first because the design system is now loading next to the React application, the second because a rebuild is exactly when heading order and form labels get lost.

- [ ] **Step 2: Prove no save path moved**

```bash
git diff main --stat -- includes/
```

Expected: `includes/Admin/*Screen.php`, `includes/Admin/Page.php` and `includes/Plugin.php` only. **No `*Actions.php` file may appear.** If one does, that change does not belong in this PR — take it out.

- [ ] **Step 3: PHP checks**

```bash
vendor/bin/phpunit
composer lint
```

Expected: both pass.

- [ ] **Step 4: Build and lint, once**

```bash
npm run build
npm run lint
```

Record what the linter says. **Do not fix anything it reports** — that is Luke's call, per the standing rule. Put the findings in the PR description.

- [ ] **Step 5: Version and changelog**

Minor bump from 2.77.1 to 2.78.0, in all four files: `package.json`, `blueworx-forge.php` (header and version constant), `client/blueworx-forge-client.php` (header and version constant). CI fails if they disagree.

`CHANGELOG.md`, written for whoever uses it rather than whoever built it:

```markdown
## 2.78.0

- The studio's admin screens now look like the rest of Blueworx. Every screen —
  sites, clients, people, packages, onboarding, availability, meetings, support,
  sync, sales and updates — is built from the shared design system instead of
  WordPress's default admin styling. Nothing about what they do has changed.
```

- [ ] **Step 6: Commit and open the draft PR**

```bash
git add -A
git commit -m "The studio's admin screens, on the shared design system"
git push -u origin studio-admin-design-system
gh pr create --draft --base main --title "The studio's backend on the shared admin design system" --body "..."
```

The body says what changed, what was tested with real output, the linter findings from Step 4, and — stated plainly — that the in-browser visual check has not been done and is waiting on a human. Never `--fill`, never auto-merge.

---

### Task 14: Ship the page editor library in the studio zip

**Files:** Modify `bin/artifacts.json`, `.github/workflows/ci.yml:38-41`, `.github/workflows/release.yml:31`, `docs/architecture/decisions.md`, `docs/architecture/decisions-manifest.json`

Branch first: `git checkout -b studio-admin-page-editor`.

- [ ] **Step 1: Write ARCH-9**

In `docs/architecture/decisions.md`, after ARCH-8, matching the house format of the entries above it:

```markdown
### ARCH-9 — studio-page-editor

**Decision:** The studio artifact ships `blueworx-page-editor`, and the studio's
configuration screens are built by it.

Both CI and the release build previously excluded the library from the studio zip,
on the grounds that "the studio's screens are the React application (ARCH-7)". That
reads only half of ARCH-7. The screens people use to do the work are the React
application; the screens that *configure* the system — clients, sites, people,
memberships, connection keys, update tokens — are plain WordPress admin pages, and
the foundation's rule is that a settings screen is built by the library and never
by hand.

Nothing about the artifact boundary moves. `blueworx-page-editor` sits at the repo
root, which is studio territory under ARCH-1's geography rule, so it is a studio
`include` rather than a `shared` path and ARCH-8's closed list is untouched.

**Consequence if reversed:** the studio goes back to hand-building settings screens,
and is the only plugin in the estate doing so.
```

Add the matching entry to `docs/architecture/decisions-manifest.json` in the shape the entries beside it use.

- [ ] **Step 2: Run the decision checker and watch it accept**

```bash
npm run check:decisions
```

Expected: PASS. If it fails, the manifest entry does not match the document — fix the manifest, not the document.

- [ ] **Step 3: Add the library to the studio's files**

In `bin/artifacts.json`, the studio's `include` list gains one entry:

```json
        "plugin-update-checker",
        "blueworx-page-editor"
```

- [ ] **Step 4: Remove the exclusion from both workflows**

In `.github/workflows/ci.yml`, delete the `/blueworx-page-editor` line and the three comment lines above it that give the old reason. Same in `.github/workflows/release.yml`. Replace with one line in each: `# The studio registers its own page editor screens (ARCH-9).` The two files must stay identical about what ships; that is stated in `ci.yml` already.

- [ ] **Step 5: Check the artifact rules still hold**

```bash
npm run check:artifacts
```

Expected: PASS. The client's `shared` list is unchanged, so ARCH-8's closed set is not being widened.

- [ ] **Step 6: Commit**

```bash
git add bin/artifacts.json .github/workflows/ci.yml .github/workflows/release.yml docs/architecture/
git commit -m "The studio ships the page editor library (ARCH-9)"
```

---

### Task 15: The Updates screen, on the library

**Files:** Modify `includes/Admin/UpdatesScreen.php`, `includes/Plugin.php`; Create `tests/e2e/updates-editor.spec.js`

**Interfaces:**
- Consumes: `Blueworx\PageEditor\v1\Editor::register( array $screen ): void`
- The library registers the admin page itself, from `Screen::menu()`. `UpdatesScreen` stops calling `add_submenu_page` for its own slug.

- [ ] **Step 1: Read the library's schema before writing a screen definition**

`blueworx-page-editor/v1/Schema.php` is what validates a definition, and a definition it refuses does not throw — it registers a screen that says it is broken when somebody opens it. Read `Schema::validate()` and the field kinds it accepts before writing anything.

- [ ] **Step 2: Write the failing spec**

Create `tests/e2e/updates-editor.spec.js`, modelled on `tests/e2e/updates-screen.spec.js` — reuse its sign-in helper from `tests/e2e/helpers`. It asserts the three things that prove the library is actually driving the screen:

```javascript
// The library mounts here and nowhere else. If this is missing the screen
// definition was refused and the page is showing the library's own error.
await expect(page.locator('#bw-page-editor')).toBeVisible();

// The token saves through the library's save bar, and comes back on reload.
await page.fill('input[name="update_token"]', 'test-token-value');
await page.click('.bw-savebar button[type="submit"]');
await expect(page.locator('.bw-notice--success')).toBeVisible();
await page.reload();
await expect(page.locator('input[name="update_token"]')).toHaveValue('test-token-value');
```

The exact selectors for the save bar and the field come from `blueworx-page-editor/v1/Screen.php` and the editor's own markup — read them rather than guessing, and correct the snippet above to match what the library actually renders.

- [ ] **Step 3: Run it and watch it fail**

```bash
npx playwright test tests/e2e/updates-editor.spec.js --workers=1
```

Expected: FAIL — `#bw-page-editor` is not on the page, because nothing registers a screen yet.

- [ ] **Step 4: Register the screen**

In `includes/Admin/UpdatesScreen.php`, replace the render path and the menu registration with an `Editor::register()` call on `plugins_loaded`: `store` is `option`, the option name is the one `Blueworx\Forge\Updates` already reads, `parent` is `SitesScreen::SLUG`, `capability` is the one the screen already requires, and the three values become fields with the kinds the schema names. Keep the slug `blueworx-forge-updates` — `updates-screen.spec.js` navigates to it by name.

Keep whatever `UpdatesScreen` does that is not the form. The "can we currently fetch updates" statement is a fact about the world rather than a field; if the schema has no place for it, leave that part of the screen where it is and say so in the PR rather than dropping it.

- [ ] **Step 5: Run both specs**

```bash
npx playwright test tests/e2e/updates-editor.spec.js tests/e2e/updates-screen.spec.js --workers=1
```

Expected: the new spec passes. `updates-screen.spec.js` will need updating where it reads markup the library no longer renders — that is legitimate here, because the save path genuinely changed. Update it; do not delete a case. Every behaviour it asserted must still be asserted somewhere.

- [ ] **Step 6: Commit**

```bash
git add includes/Admin/UpdatesScreen.php includes/Plugin.php tests/e2e/
git commit -m "The Updates screen is built by the page editor library"
```

---

### Task 16: Write up the eight deferred forms

**Files:** Create `docs/architecture/open-questions/studio-settings-screens.md`

- [ ] **Step 1: Write it**

One page, no more. What the library does (registers an admin page per screen, opened with `?id=`); what that means for the eight remaining forms (eight new entries in the studio admin menu, and each existing listing becomes links into them); the three shapes it could take — a page per form, one page per record type reached from its listing, or leaving the complex screens hand-built — and what each costs. It ends with the question, not a recommendation dressed as one: **is a settings page per record type the shape you want the studio's admin menu to have?**

- [ ] **Step 2: Commit**

```bash
git add docs/architecture/open-questions/
git commit -m "Write up the eight settings screens still to move"
```

---

### Task 17: Close PR two

- [ ] **Step 1: The checks**

```bash
vendor/bin/phpunit
composer lint
npm run lint
npm run build
npm run check:artifacts
npm run check:decisions
```

Record the linter's findings; fix nothing it reports.

- [ ] **Step 2: Prove the zip is right**

```bash
npm run build:zip:studio
unzip -l ../blueworx-forge-2.79.0.zip | grep blueworx-page-editor | head
unzip -l ../blueworx-forge-2.79.0.zip | grep -c '\\\\'
```

Expected: the library's files are present, every entry uses forward slashes, and the backslash count is 0.

- [ ] **Step 3: Version and changelog**

Minor bump to 2.79.0 in all four files.

```markdown
## 2.79.0

- The update token screen is now the standard Blueworx settings screen, the same
  one used everywhere else. It saves the same token to the same place.
```

- [ ] **Step 4: Open the draft PR**

```bash
git push -u origin studio-admin-page-editor
gh pr create --draft --base studio-admin-design-system --title "The Updates screen on the page editor library" --body "..."
```

**Based on PR one's branch, not `main`.** The body links the open-questions document from Task 16 and says the eight remaining forms are a decision, not an omission.

Note for whoever merges: merging PR one closes this PR's base. Retarget this PR to `main` before deleting PR one's branch.

## Self-review

**Spec coverage.** The shell (Task 1), all eleven screens (Tasks 2–12), dropping `tokens/forge.css` from admin screens (Task 1, Step 6), the enqueue scoped to studio screens (Task 1, Step 4), the design system's look with no mapping layer (Global Constraints), test hooks held (every screen task, Step 4), the library shipped and ARCH-9 (Task 14), Updates on the library (Task 15), the eight deferred forms written up (Task 16), version and changelog (Tasks 13 and 17), draft PRs and no merge (Global Constraints). The spec's "how we know it is done" list maps item for item onto Tasks 13 and 17.

**Placeholders.** Two steps deliberately say "read the source rather than guessing" — Task 15's Steps 1 and 2, about the page editor's schema and its rendered markup. That is not a placeholder standing in for work; it is the work, because the library has never been used in this repo and a snippet written from memory would be wrong. Everything else carries the actual content.

**Type consistency.** `Page::open`, `Page::close`, `Page::panel_open`, `Page::panel_close`, `Page::notice`, `Page::enqueue` and `Page::ours` are defined once in Task 1 and used with those exact names throughout. `data-bwx-panel` is written by `panel_open` and by nothing else. The version numbers are consistent: 2.78.0 in Task 13, 2.79.0 in Task 17.
