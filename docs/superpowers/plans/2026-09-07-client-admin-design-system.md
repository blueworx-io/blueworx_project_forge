# The client site's backend on the shared admin design system — implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every screen the Blueworx Forge client plugin puts in wp-admin is built from the foundation's shared admin design system, and the two CI guardrails that watch that stop passing by finding nothing.

**Architecture:** The foundation's design system is vendored into this repo at the path its drift check expects. A build step lays the stylesheet, fonts, icons and page editor script into `assets/` after Vite has emptied it. The client artifact borrows those through a widened — and newly documented — shareable list. Then all ten client admin screens are rebuilt on the system, one task each, with the existing pair suite held steady as the regression net.

**Tech Stack:** PHP 8.2+, WordPress 6.5+ (plugin, `Blueworx\Forge\Client` namespace), Node 24 tooling (`node --test`), Vite 5, Playwright, PHPUnit 9, PHPCS (WordPress standard). Both plugin headers say `Requires at least: 6.5` and `Requires PHP: 8.2` — `wp_enqueue_script_module()` needs 6.5, which is why that floor matters here.

**Spec:** `docs/superpowers/specs/2026-09-07-client-admin-design-system-design.md`

## Global Constraints

- **The foundation's paths are not negotiable.** `ci-wordpress.yml` sets only `DESIGN_SYSTEM_SYNC`, `FOUNDATION_DIR` and `FOUNDATION_REF`, so `check-design-system-sync.mjs` uses its defaults. Vendored system at `.claude/skills/blueworx-admin-design`; shipped copies at `assets/blueworx-admin-design.css`, `assets/fonts`, `assets/blueworx-admin-icons.js`, `assets/blueworx-page-editor.js`; page editor PHP at `blueworx-page-editor`.
- **Nothing under `assets/` or `blueworx-page-editor/` is ever hand-edited.** Both are produced by `bin/sync-design-system.mjs` from the vendored skill folder. The vendored folder is itself byte-compared against the foundation on every PR.
- **Both guardrails stay at `error`.** Never add `design_system_sync: warn` or `admin_ui_adherence: warn` to `.github/workflows/ci.yml`. A plugin parked on `warn` is the exact failure this work exists to fix.
- **Every screen keeps its existing `data-testid` attributes.** They are the contract the pair suite holds. Adding new ones is fine; removing or renaming one is not, unless the same commit updates the spec that reads it.
- **The look is the foundation's.** Use its `--bw-*` variables. Do not build a mapping layer onto Forge's `--color-*` / `--text-*` / `--surface-*` tokens. Forge's token layer keeps loading for the studio's sake; the client's screens stop drawing on it.
- **Version and changelog** are bumped on the pull request, per the foundation rules — minor bump, since this is new behaviour. `package.json`, `blueworx-forge.php` and `client/blueworx-forge-client.php` must all agree.
- **Never merge to main and never push a tag.** Releases are a separate, asked-for decision.
- **Run the checks before each commit:** `npm run lint`, `npm run build`, `composer lint`, `npm run test:unit`.
- **Testing cadence.** The pair suite is 29 specs and 128 cases on a single worker, and it slows as the sites age. Running all of it per task would cost more than the work. So: a task runs **only the specs it names**, with `npx playwright test -c playwright.pair.config.js <spec>`. The **full** `npm run test:pair` runs at four checkpoints only — after Task 5 (the net must be green before anything moves), after Task 8 (the three shared pieces are in every screen), after Task 15 (the bulk of the screens), and in Task 19. Reset the sites with `npm run wp:pair:reset` before each full run; a slow run usually means stale state, not a real regression.

## File structure

**New files**

| File | Responsibility |
|---|---|
| `bin/sync-design-system.mjs` | The only thing that writes the shipped copies. Exports a pure `plan()` so the copy list is unit-testable without touching disk. |
| `tests/unit/sync-design-system.test.mjs` | Proves `plan()` names every path the foundation's drift check compares. |
| `.claude/skills/blueworx-admin-design/**` | The vendored design system. Copied verbatim from the foundation; never edited here. |
| `blueworx-page-editor/**` | The vendored page editor PHP library. Written by the sync script; never edited here. |
| `client/includes/Admin/Page.php` | The shared page shell every client screen renders inside — the foundation's `wrap bw-wrap` / `bw-admin bw-page` skeleton, header and tab strip. |

**Modified files**

| File | Change |
|---|---|
| `package.json` | `build` and the two zip scripts run the sync step; new `assets:design-system` script. |
| `bin/build-zip.sh:120-124` | Shared paths keep their directory structure. |
| `bin/check-artifacts.mjs:34` | `SHAREABLE` grows by five. |
| `bin/artifacts.json` | The client's `shared` list grows by five. |
| `docs/architecture/decisions.md`, `docs/architecture/decisions-manifest.json` | ARCH-8, recording what may cross the artifact boundary. |
| `client/includes/Admin/Screen.php` | Enqueues the design system; page wrapper moves to `Page.php`. |
| `client/includes/Admin/Nav.php` | Renders the system's tabs. |
| `client/includes/Admin/Card.php` | Rebuilt on the system's card. |
| `client/includes/Admin/*Screen.php` (nine) | Rebuilt on the system. |
| `client/includes/Admin/Styles.php` | Shrinks to whatever the system genuinely has no pattern for. |
| `.github/workflows/ci.yml` | `/blueworx-page-editor` excluded from the studio zip content check if it must not ship there. |

## Sequencing

Tasks 1–4 are plumbing and change nothing anyone can see. Task 5 is the safety net. Tasks 6–8 are the three things every screen goes through. Tasks 9–18 are one screen each, ten of them. Task 19 closes it out.

**Task 18 (calendar) depends on a foundation release** — see its note. It is last so it cannot hold up the other nine screens.

---

### Task 1: Vendor the design system, and produce the shipped copies from it

**Files:**
- Create: `.claude/skills/blueworx-admin-design/**` (copied from the foundation)
- Create: `bin/sync-design-system.mjs`
- Create: `tests/unit/sync-design-system.test.mjs`
- Modify: `package.json` (scripts)

**Interfaces:**
- Produces: `plan(): Array<{from: string, to: string, kind: 'file'|'dir'}>` — the copy list, exported from `bin/sync-design-system.mjs`. Task 3's test reads it to prove the allowlist and the copy list agree.
- Produces: `bin/sync-design-system.mjs` as a CLI (`node bin/sync-design-system.mjs`) writing every path in `plan()`.

- [ ] **Step 1: Copy the design system in**

The foundation is a sibling directory, or `.foundation/`, or `BWX_FOUNDATION_DIR` — same rule `bin/wp-pair.mjs` already uses.

```bash
mkdir -p .claude/skills
cp -R ../bluegroup_core_foundation/.claude/skills/blueworx-admin-design .claude/skills/
```

Confirm it arrived whole — the drift check hashes the entire tree:

```bash
ls .claude/skills/blueworx-admin-design
# expect: SKILL.md _adherence.oxlintrc.json _ds_bundle.js _ds_manifest.json
#         assets components editor fonts github.md guidelines readme.md
#         styles.css templates thumbnail.html ui_kits
```

- [ ] **Step 2: Write the failing test**

Create `tests/unit/sync-design-system.test.mjs`:

```javascript
// House style, matching tests/unit/check-artifacts.test.mjs: test is the
// default import, assert is the strict build.
import test from 'node:test';
import assert from 'node:assert/strict';
import { plan } from '../../bin/sync-design-system.mjs';

// The paths check-design-system-sync.mjs compares when the workflow passes it
// no overrides — which ci-wordpress.yml does not. If this list and that script
// disagree, the guardrail silently compares nothing.
const REQUIRED_DESTINATIONS = [
  'assets/blueworx-admin-design.css',
  'assets/fonts',
  'assets/blueworx-admin-icons.js',
  'assets/blueworx-page-editor.js',
  'blueworx-page-editor',
];

test('every path the drift check compares is produced by the sync step', () => {
  const produced = plan().map((entry) => entry.to);
  for (const required of REQUIRED_DESTINATIONS) {
    assert.ok(
      produced.includes(required),
      `${required} is compared by check-design-system-sync.mjs but nothing produces it`
    );
  }
});

test('every source sits inside the vendored design system', () => {
  for (const { from } of plan()) {
    assert.ok(
      from.startsWith('.claude/skills/blueworx-admin-design/'),
      `${from} is not in the vendored system — the vendored copy is the only source`
    );
  }
});

test('each entry says whether it is a file or a directory', () => {
  for (const entry of plan()) {
    assert.ok(['file', 'dir'].includes(entry.kind), `${entry.to} has no valid kind`);
  }
});
```

- [ ] **Step 3: Run it to make sure it fails**

Run: `npm run test:unit`
Expected: FAIL — `Cannot find module '../../bin/sync-design-system.mjs'`

- [ ] **Step 4: Write the sync script**

Create `bin/sync-design-system.mjs`:

```javascript
#!/usr/bin/env node
// Lays the shipped copies of the shared design system down from the vendored
// one. The vendored folder at .claude/skills/blueworx-admin-design is the only
// source, and it is itself byte-compared against the foundation on every pull
// request — so nothing here is ever hand-edited, and a difference is always
// fixed by re-pulling the foundation rather than by editing assets/.
//
// This runs after Vite, not before: vite.config.js builds into assets/ with
// emptyOutDir, so anything laid down first is deleted.

import process from 'node:process';
import { cpSync, mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = fileURLToPath(new URL('..', import.meta.url));
const SKILL = '.claude/skills/blueworx-admin-design';

/**
 * What gets copied where. Pure, so the unit test can check it against the
 * paths check-design-system-sync.mjs compares without touching the disk.
 *
 * The destinations are not ours to choose: ci-wordpress.yml passes that script
 * no path overrides, so its defaults are binding.
 *
 * @returns {Array<{from: string, to: string, kind: 'file'|'dir'}>}
 */
export function plan() {
  return [
    { from: `${SKILL}/styles.css`, to: 'assets/blueworx-admin-design.css', kind: 'file' },
    { from: `${SKILL}/fonts`, to: 'assets/fonts', kind: 'dir' },
    { from: `${SKILL}/assets/icons/lucide-icons.js`, to: 'assets/blueworx-admin-icons.js', kind: 'file' },
    { from: `${SKILL}/editor/blueworx-page-editor.js`, to: 'assets/blueworx-page-editor.js', kind: 'file' },
    { from: `${SKILL}/editor/php`, to: 'blueworx-page-editor', kind: 'dir' },
  ];
}

/** Writes every entry in the plan. */
export function sync() {
  for (const { from, to, kind } of plan()) {
    const source = resolve(ROOT, from);
    const target = resolve(ROOT, to);
    mkdirSync(dirname(target), { recursive: true });
    cpSync(source, target, { recursive: kind === 'dir' });
  }
}

if (process.argv[1] && resolve(process.argv[1]) === resolve(ROOT, 'bin/sync-design-system.mjs')) {
  sync();
  console.log(`Design system: wrote ${plan().length} path(s) from ${SKILL}.`);
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `npm run test:unit`
Expected: PASS — all three new tests green, and the existing unit tests unaffected.

- [ ] **Step 6: Wire it into the build**

In `package.json`, add the script and make every build path run it. `build:zip:client` gets it too — it does not run Vite, but it must not stage a stale copy either.

```json
"assets:design-system": "node bin/sync-design-system.mjs",
"build": "vite build && npm run assets:design-system",
"build:zip": "npm run build && bash bin/build-zip.sh all",
"build:zip:studio": "npm run build && bash bin/build-zip.sh studio",
"build:zip:client": "npm run assets:design-system && bash bin/build-zip.sh client",
```

- [ ] **Step 7: Prove the build produces the files, and that Vite no longer eats them**

```bash
npm run build
ls assets/blueworx-admin-design.css assets/blueworx-admin-icons.js assets/blueworx-page-editor.js
ls assets/fonts
ls blueworx-page-editor/v1
```

Expected: all present. Run `npm run build` a second time and confirm they are still there — that is the `emptyOutDir` trap this step exists to close.

- [ ] **Step 8: Commit**

```bash
git add .claude/skills/blueworx-admin-design bin/sync-design-system.mjs \
        tests/unit/sync-design-system.test.mjs package.json assets blueworx-page-editor
git commit -m "Vendor the shared admin design system, and build its shipped copies"
```

---

### Task 2: Make the zip builder keep a shared path's directory structure

**Files:**
- Modify: `bin/build-zip.sh:120-124`
- Test: `tests/unit/build-zip-shared.test.mjs` (create)

**Interfaces:**
- Consumes: nothing from Task 1.
- Produces: a zip in which a shared path `assets/fonts` lands at `<slug>/assets/fonts`, not `<slug>/fonts`.

- [ ] **Step 1: Write the failing test**

Create `tests/unit/build-zip-shared.test.mjs`:

```javascript
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

// bin/build-zip.sh used to stage a shared path with `cp -R "$ROOT/$item"
// "$STAGE/$SLUG/"`, which keeps only the last segment. Every shared path then
// sat at the repo root, so there was no structure to lose. assets/fonts has
// structure, and losing it puts the stylesheet and its fonts in different
// places on a client site.
//
// This asserts on the script's text rather than its behaviour, and says so:
// build-zip.sh is a shell script with no seam to call, and copying its loop
// into the test would only prove the copy works. The behavioural proof is
// Task 3 Step 8, which builds a real client zip and lists the entries — that
// is the check that would actually catch a regression here.
test('build-zip.sh stages a shared path at its own relative path', () => {
  const script = readFileSync('bin/build-zip.sh', 'utf8');

  assert.match(
    script,
    /mkdir -p "\$STAGE\/\$SLUG\/\$\(dirname "\$item"\)"/,
    'the staging loop must create the shared path\'s parent directory'
  );
  assert.match(
    script,
    /cp -R "\$ROOT\/\$item" "\$STAGE\/\$SLUG\/\$item"/,
    'the staging loop must copy to the full relative path, not to the slug root'
  );
  assert.doesNotMatch(
    script,
    /cp -R "\$ROOT\/\$item" "\$STAGE\/\$SLUG\/"$/m,
    'the flattening form must be gone, not merely joined by the new one'
  );
});
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `node --test tests/unit/build-zip-shared.test.mjs`
Expected: FAIL on the first assertion — `bin/build-zip.sh` still has the flattening form.

Confirm the behaviour the test stands in for, so you have seen the bug rather than trusted a description of it:

```bash
rm -rf /tmp/f && mkdir -p /tmp/f/assets/fonts /tmp/f/stage && touch /tmp/f/assets/fonts/a.woff2
cp -R /tmp/f/assets/fonts /tmp/f/stage/ && find /tmp/f/stage -type f
# expect: /tmp/f/stage/fonts/a.woff2  — the assets/ level is gone
```

- [ ] **Step 3: Fix the staging loop**

In `bin/build-zip.sh`, replace lines 120–124:

```bash
# The few paths both artifacts take from the repo root. Kept to a closed list in
# bin/check-artifacts.mjs, because this is the one door out of the artifact's
# own directory and widening it silently is how studio code reaches a client.
#
# The destination keeps the source's directory structure. Every shared path used
# to sit at the repo root, where `cp -R src dest/` and "keep the structure" are
# the same thing; assets/blueworx-admin-design.css is not, and it has to land
# beside assets/fonts or the stylesheet cannot find its own fonts.
for item in "${SHARED[@]}"; do
	[ -n "$item" ] || continue
	[ -e "$ROOT/$item" ] || die "shared path is missing from the repo: $item"
	mkdir -p "$STAGE/$SLUG/$(dirname "$item")"
	cp -R "$ROOT/$item" "$STAGE/$SLUG/$item"
done
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `node --test tests/unit/build-zip-shared.test.mjs`
Expected: PASS

- [ ] **Step 5: Prove the existing top-level shares are unharmed**

```bash
npm run build:zip:client
unzip -l ../blueworx-forge-client-*.zip | head -30
```

Expected: `blueworx-forge-client/tokens/...`, `blueworx-forge-client/plugin-update-checker/...` and `blueworx-forge-client/CHANGELOG.md` exactly as before. Every entry uses forward slashes and nests one level under the slug.

- [ ] **Step 6: Commit**

```bash
git add bin/build-zip.sh tests/unit/build-zip-shared.test.mjs
git commit -m "Keep a shared path's directory structure when staging a zip"
```

---

### Task 3: Widen the shareable list, and write down why

**Files:**
- Modify: `bin/check-artifacts.mjs:28-34`
- Modify: `bin/artifacts.json` (client `shared`)
- Modify: `docs/architecture/decisions-manifest.json`
- Modify: `docs/architecture/decisions.md`
- Test: `tests/unit/check-artifacts.test.mjs`

**Interfaces:**
- Consumes: `plan()` from Task 1.
- Produces: a client artifact containing the design system and the page editor.

- [ ] **Step 1: Write the failing test**

Append to `tests/unit/check-artifacts.test.mjs`. That file currently imports only `test`, `assert` and `checkArtifacts` — add the two new imports at the top with the others, not beside the test:

```javascript
// at the top of the file, with the existing imports
import { readFileSync } from 'node:fs';
import { plan } from '../../bin/sync-design-system.mjs';

// The two lists have to agree. A path the build produces but the allowlist
// refuses never reaches a client site; a path the allowlist admits but nothing
// produces fails the build with "shared path is missing from the repo".
test('every design system path the build produces is shareable to the client', () => {
  const config = JSON.parse(readFileSync('bin/artifacts.json', 'utf8'));
  const shared = config.artifacts.client.shared;
  for (const { to } of plan()) {
    assert.ok(
      shared.includes(to),
      `${to} is produced for the client but is not in its shared list`
    );
  }
  assert.deepEqual(
    checkArtifacts(config, { checkExistence: false }),
    [],
    'the widened shared list must still satisfy the checker'
  );
});
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `node --test tests/unit/check-artifacts.test.mjs`
Expected: FAIL — `assets/blueworx-admin-design.css is produced for the client but is not in its shared list`

- [ ] **Step 3: Widen the closed list**

In `bin/check-artifacts.mjs`, replace the `SHAREABLE` declaration and its comment:

```javascript
// The only paths an artifact may take from outside its own root. Deliberately
// short, deliberately not a pattern: plugin-update-checker is here because a
// client site without it could never receive a fix, and tokens because the two
// interfaces have to be able to drift apart before they can look different, and
// a second copy of the token layer is how that starts (#85).
//
// The five design system paths are here on the same argument, recorded as
// ARCH-8: appearance, and the shared machinery for editing settings. None of
// them knows anything about clients, capacity or anybody else's data, which is
// the boundary ARCH-1 actually draws — command-centre code off a client site,
// not colours off it. They are produced by bin/sync-design-system.mjs from a
// vendored copy the foundation's drift check compares byte for byte, so none of
// them can grow studio code without CI failing.
//
// Adding a sixth kind of entry is a decision, not a build change. Read ARCH-8
// first.
const SHAREABLE = new Set([
  'plugin-update-checker',
  'CHANGELOG.md',
  'tokens',
  'assets/blueworx-admin-design.css',
  'assets/fonts',
  'assets/blueworx-admin-icons.js',
  'assets/blueworx-page-editor.js',
  'blueworx-page-editor',
]);
```

- [ ] **Step 4: Widen the client allowlist**

In `bin/artifacts.json`, the client artifact's `shared`:

```json
      "shared": [
        "CHANGELOG.md",
        "plugin-update-checker",
        "tokens",
        "assets/blueworx-admin-design.css",
        "assets/fonts",
        "assets/blueworx-admin-icons.js",
        "assets/blueworx-page-editor.js",
        "blueworx-page-editor"
      ]
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `node --test tests/unit/check-artifacts.test.mjs && npm run check:artifacts`
Expected: PASS, and `check:artifacts` reports no problems.

- [ ] **Step 6: Record ARCH-8**

In `docs/architecture/decisions-manifest.json`, after the ARCH-7 line:

```json
		{ "id": "ARCH-8", "group": "Architecture and delivery", "question": "What may cross the artifact boundary from the repo root into the client plugin" },
```

In `docs/architecture/decisions.md`, after the ARCH-7 block and before `## Workflow`:

```markdown
### ARCH-8 — shareable-paths

**Question:** What may cross the artifact boundary from the repo root into the client plugin?

**Options considered:** Keep the list at three and give the client its own copy of the design system under `client/`, which honours the geography rule literally but leaves two copies to drift and only one of them watched by the foundation's check; drop the closed list for a rule such as "anything not under `includes/`", which is the kind of rule that reads as safe right up to the merge that makes it wrong; widen the closed list entry by entry, each one argued.

**Decision:** The list stays closed and entry-by-entry. A path may join it only if it is appearance or shared machinery that knows nothing about clients, capacity, or any other client's data — the boundary ARCH-1 draws is command-centre code off a client site, not colours off it. A path that is a vendored copy of a foundation library, kept byte-identical by the foundation's drift check, cannot grow command-centre code without CI failing, and that is what makes it safe to share rather than a judgement about its contents today. Admitted on this basis: `assets/blueworx-admin-design.css`, `assets/fonts`, `assets/blueworx-admin-icons.js`, `assets/blueworx-page-editor.js`, `blueworx-page-editor`. Approved by Luke on 7 September 2026.

**Consequence if reversed:** The client plugin keeps a second copy of the design system, the foundation's drift check watches only the studio's, and the two interfaces diverge without anything failing.
```

- [ ] **Step 7: Run the decisions check**

Run: `npm run lint`
Expected: PASS — `check:decisions` finds ARCH-8 in both the manifest and `decisions.md`.

- [ ] **Step 8: Prove the client zip now carries the system**

```bash
npm run build:zip:client
unzip -l ../blueworx-forge-client-*.zip | grep -E 'blueworx-admin-design|fonts/|page-editor' | head -20
```

Expected: `blueworx-forge-client/assets/blueworx-admin-design.css`, `blueworx-forge-client/assets/fonts/inter-400.woff2` and friends, `blueworx-forge-client/blueworx-page-editor/v1/Editor.php`. All forward slashes.

- [ ] **Step 9: Commit**

```bash
git add bin/check-artifacts.mjs bin/artifacts.json tests/unit/check-artifacts.test.mjs \
        docs/architecture/decisions.md docs/architecture/decisions-manifest.json
git commit -m "Let the client artifact carry the shared design system (ARCH-8)"
```

---

### Task 4: Keep the page editor out of the studio zip, and confirm CI still agrees

**Files:**
- Modify: `.github/workflows/ci.yml` (`exclude_paths`)
- Modify: `.github/workflows/release.yml` (the same list — the two must not disagree)

**Interfaces:**
- Consumes: Task 3's allowlists.
- Produces: a studio zip whose content check passes with the new root directory present.

- [ ] **Step 1: Find out whether the studio zip wants the page editor**

The studio's `include` list in `bin/artifacts.json` does not name `blueworx-page-editor`, so the zip will not contain it. The PR-time content check compares the repo against the zip and flags a root path that ships in neither direction.

```bash
npm run build:zip:studio
node bin/check-artifacts.mjs
```

If the check reports `blueworx-page-editor` as unaccounted for, add it to `exclude_paths` in **both** workflows, keeping them identical:

```yaml
        # Vendored for the client plugin, which registers the shared page editor.
        # The studio's screens are the React application (ARCH-7), so this has no
        # business in the studio artifact. Keep identical to release.yml.
        /blueworx-page-editor
```

If the check passes untouched, make no change and record that in the commit message.

- [ ] **Step 2: Verify both workflows carry the same list**

```bash
diff <(sed -n '/exclude_paths/,/secrets\|^  [a-z]/p' .github/workflows/ci.yml) \
     <(sed -n '/exclude_paths/,/secrets\|^  [a-z]/p' .github/workflows/release.yml)
```

Expected: no differences in the excluded paths. `tests/unit/workflow-excludes.test.mjs` already exists to hold this — run it.

Run: `node --test tests/unit/workflow-excludes.test.mjs`
Expected: PASS

- [ ] **Step 3: Commit**

```bash
git add .github/workflows/ci.yml .github/workflows/release.yml
git commit -m "Keep the vendored page editor out of the studio artifact"
```

---

### Task 5: Harden the pair suite before any screen moves

**Files:**
- Modify: `tests/pair/client-*.spec.js` (only those asserting on markup)

**Interfaces:**
- Produces: a pair suite that asserts only on `data-testid`, roles and visible text — never on `bwx-*` class names or tag structure.

This task exists because the net has to be proven against the **old** screens before it is asked to catch the new ones.

The only change permitted to `client/includes/` here is **adding** a `data-testid` attribute where a spec needs one and none exists. No markup is restructured, no class is renamed, no rule leaves `Styles::css()`. If a screen seems to need more than an added attribute to be testable, note it and leave it — that is the rebuild task's problem, not this one's.

- [ ] **Step 1: Find every assertion that would break on a rebuild**

```bash
grep -rn "locator('\.\|locator(\"\.\|\.bwx-\|toHaveClass\|locator('div\|locator('section" tests/pair/*.spec.js
```

Each hit is a spec reaching for markup rather than meaning. List them before changing anything.

- [ ] **Step 2: For each hit, move the assertion onto a test id**

Where the screen already exposes a suitable `data-testid`, switch the locator to it. Where it does not, add one to the screen **in this task** — adding a test id is not a rebuild, and it keeps this task's diff reviewable on its own.

Example of the shape (a real one will differ):

```javascript
// Before — breaks the moment the panel becomes a bw-card
await expect(page.locator('.bwx-panel')).toHaveCount(3);

// After — says what it means, and survives the rebuild
await expect(page.getByTestId('bwx-panel')).toHaveCount(3);
```

- [ ] **Step 3: Run the pair suite against the current, unchanged screens**

```bash
npm run wp:pair:reset
npm run wp:pair:up
npm run test:pair
```

Expected: PASS. This is the green run that makes the rest of the plan safe — if it is not green here, stop and fix the suite before going on.

Note: `count()` does not wait. Anywhere a poll predicate uses it, guard it with a visibility wait first.

- [ ] **Step 4: Commit**

```bash
git add tests/pair client/includes/Admin
git commit -m "Anchor the client pair specs on test ids, not markup"
```

---

### Task 6: The shared page shell

**Files:**
- Create: `client/includes/Admin/Page.php`
- Modify: `client/includes/Admin/Screen.php:102-129` (enqueue), `:398-410` (`open`/`close`)

**Interfaces:**
- Produces:
  - `Page::open( string $title, string $eyebrow = '', string $lede = '' ): void` — opens `wrap bw-wrap` → `bw-admin bw-page` → `bw-pagehead`.
  - `Page::close(): void` — closes them.
  - `Page::panel_open( string $heading, string $name ): void` — a `bw-card`, carrying `data-testid="bwx-panel"` and `data-bwx-panel="$name"` exactly as `Screen::open()` does today.
  - `Page::panel_close(): void`

- [ ] **Step 1: Write the failing test**

Add to `tests/pair/client-shell.spec.js`:

```javascript
test('the client screens are built on the shared design system shell', async ({ page }) => {
  await page.goto('/wp-admin/admin.php?page=blueworx-forge-client');
  await expect(page.locator('.bw-admin.bw-page')).toBeVisible();
  await expect(page.locator('.bw-pagehead')).toBeVisible();
  // The design system's stylesheet is actually on the page, not just referenced.
  const loaded = await page.evaluate(() =>
    [...document.styleSheets].some((s) => (s.href || '').includes('blueworx-admin-design.css'))
  );
  expect(loaded, 'the design system stylesheet is enqueued').toBe(true);
});
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `npx playwright test -c playwright.pair.config.js tests/pair/client-shell.spec.js`
Expected: FAIL — `.bw-admin.bw-page` not found.

- [ ] **Step 3: Enqueue the design system**

In `client/includes/Admin/Screen.php`, extend `enqueue()`. Keep the tokens enqueue: the studio still compiles from it and removing it is not this task's business.

```php
		$design = BWX_FORGE_CLIENT_PATH . 'assets/blueworx-admin-design.css';

		if ( file_exists( $design ) ) {
			wp_enqueue_style(
				'blueworx-admin-design',
				BWX_FORGE_CLIENT_URL . 'assets/blueworx-admin-design.css',
				array(),
				(string) filemtime( $design )
			);

			$icons = BWX_FORGE_CLIENT_PATH . 'assets/blueworx-admin-icons.js';

			if ( file_exists( $icons ) ) {
				wp_enqueue_script_module(
					'blueworx-admin-icons',
					BWX_FORGE_CLIENT_URL . 'assets/blueworx-admin-icons.js',
					array(),
					(string) filemtime( $icons )
				);
			}
		}
```

- [ ] **Step 4: Write `Page.php`**

Create `client/includes/Admin/Page.php`. It carries the foundation's skeleton and the wp-admin chrome reset the readme specifies, and nothing else.

```php
<?php
/**
 * The shell every client screen renders inside.
 *
 * The foundation's page skeleton, in the order the design system fixes it:
 * wrap → page → header → tabs → panels. Nothing here decides what a screen
 * says; it decides only the shape all of them share, so that shape is in one
 * file rather than repeated across ten.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client\Admin;

/**
 * The shared page shell.
 */
final class Page {

	/**
	 * Opens the page and its header.
	 *
	 * @param string $title   The page title.
	 * @param string $eyebrow Small label above the title. Optional.
	 * @param string $lede    A sentence under the title. Optional.
	 */
	public static function open( string $title, string $eyebrow = '', string $lede = '' ): void {
		echo '<div class="wrap bw-wrap"><div class="bw-admin bw-page">';
		echo '<header class="bw-pagehead" data-testid="bwx-pagehead">';

		if ( '' !== $eyebrow ) {
			printf( '<p class="bw-pagehead-eyebrow">%s</p>', esc_html( $eyebrow ) );
		}

		printf( '<h1 class="bw-pagehead-title">%s</h1>', esc_html( $title ) );

		if ( '' !== $lede ) {
			printf( '<p class="bw-pagehead-lede">%s</p>', esc_html( $lede ) );
		}

		echo '</header>';
	}

	/**
	 * Closes the page.
	 */
	public static function close(): void {
		echo '</div></div>';
	}

	/**
	 * Opens a panel.
	 *
	 * The test id and the panel name are carried over from Screen::open()
	 * unchanged: the pair suite holds on to both, and a rebuild that renames
	 * them is a rebuild that cannot be checked.
	 *
	 * @param string $heading The panel heading.
	 * @param string $name    A name for tests and styling to hold on to.
	 */
	public static function panel_open( string $heading, string $name ): void {
		printf(
			'<section class="bw-card" data-testid="bwx-panel" data-bwx-panel="%s">',
			esc_attr( $name )
		);
		printf( '<h2 class="bw-card-title">%s</h2>', esc_html( $heading ) );
	}

	/**
	 * Closes a panel.
	 */
	public static function panel_close(): void {
		echo '</section>';
	}
}
```

- [ ] **Step 5: Drop wp-admin's chrome on these screens**

The readme requires this, scoped to the plugin's own screens. Add to `Styles::css()`, replacing nothing else yet:

```css
.wrap.bw-wrap { margin: 0; }
body.toplevel_page_blueworx-forge-client #wpcontent { padding-left: 0; }
body.toplevel_page_blueworx-forge-client #wpbody-content { padding-bottom: 0; }
body.toplevel_page_blueworx-forge-client #wpfooter { display: none; }
```

- [ ] **Step 6: Point `Screen::render()` at the shell**

Replace `Screen::open()` / `Screen::close()` call sites with `Page::panel_open()` / `Page::panel_close()`, and wrap `render()` in `Page::open()` / `Page::close()`. Delete the now-unused private `Screen::open()` and `Screen::close()`.

- [ ] **Step 7: Run the tests**

```bash
npm run test:pair
composer lint
```

Expected: the new shell test passes, and every existing client spec still passes.

- [ ] **Step 8: Commit**

```bash
git add client/includes/Admin/Page.php client/includes/Admin/Screen.php \
        client/includes/Admin/Styles.php tests/pair/client-shell.spec.js
git commit -m "Give the client screens the shared page shell"
```

---

### Task 7: The nav becomes the system's tabs

**Files:**
- Modify: `client/includes/Admin/Nav.php:92-131`
- Test: `tests/pair/client-shell.spec.js`

**Interfaces:**
- Consumes: `Page` from Task 6.
- Produces: `Nav::render()` emitting `bw-tabs`, keeping `data-testid="bwx-client-nav"`, `data-testid="bwx-client-nav-item"` and `data-testid="bwx-client-scope"`.

- [ ] **Step 1: Write the failing test**

Add to `tests/pair/client-shell.spec.js`:

```javascript
test('the workspace nav is the design system tab strip', async ({ page }) => {
  await page.goto('/wp-admin/admin.php?page=blueworx-forge-client');
  const nav = page.locator('[data-testid="bwx-client-nav"]');
  await expect(nav).toBeVisible();
  await expect(nav).toHaveClass(/bw-tabs/);
  await expect(nav.locator('[data-testid="bwx-client-nav-item"]')).not.toHaveCount(0);
  // Still a tab strip, still not a second navigation column.
  await expect(page.locator('.bw-sectionnav')).toHaveCount(0);
});
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `npx playwright test -c playwright.pair.config.js tests/pair/client-shell.spec.js -g "tab strip"`
Expected: FAIL — class `bw-tabs` not present.

- [ ] **Step 3: Rewrite the render**

In `client/includes/Admin/Nav.php`, replace the markup inside `render()`. The scope line becomes the page header's eyebrow rather than a paragraph of its own, and the links become tabs.

```php
		printf(
			'<nav class="bw-tabs" data-testid="bwx-client-nav" aria-label="%s">',
			esc_attr__( 'Workspace', 'blueworx-forge' )
		);

		// A page and nothing else in each href. Nothing here names a client or a
		// site, so there is nothing here to edit into somebody else's.
		foreach ( self::pages() as $page ) {
			$current_page = $page['slug'] === $current;

			printf(
				'<a class="bw-tab%s" data-testid="bwx-client-nav-item" href="%s"%s>%s</a>',
				$current_page ? ' is-current' : '',
				esc_url( admin_url( 'admin.php?page=' . $page['slug'] ) ),
				$current_page ? ' aria-current="page"' : '',
				esc_html( $page['label'] )
			);
		}

		echo '</nav>';
```

Keep the `bwx-client-scope` element and its test id — move it into the header, not out of existence.

- [ ] **Step 4: Delete the nav's own styling**

Remove `.bwx-client-nav`, `.bwx-client-nav-item` and `.bwx-client-frame` from `Styles::css()`. The system draws tabs now.

- [ ] **Step 5: Run the tests**

Run: `npm run test:pair && composer lint`
Expected: PASS, including the existing `client-shell.spec.js` assertions on nav text and hrefs.

- [ ] **Step 6: Commit**

```bash
git add client/includes/Admin/Nav.php client/includes/Admin/Styles.php tests/pair/client-shell.spec.js
git commit -m "Render the workspace nav as design system tabs"
```

---

### Task 8: The work item card

**Files:**
- Modify: `client/includes/Admin/Card.php:67-186`
- Test: `tests/pair/client-boards.spec.js`

**Interfaces:**
- Consumes: `Page` from Task 6.
- Produces: `Card::render()` emitting `bw-card` with `bw-badge` for stage and `bw-chip` for people, keeping every existing `data-testid`.

- [ ] **Step 1: Write the failing test**

Add to `tests/pair/client-boards.spec.js`:

```javascript
test('a work item card is built from the design system', async ({ page }) => {
  await page.goto('/wp-admin/admin.php?page=blueworx-forge-client-board');
  const card = page.locator('[data-testid="bwx-column"] .bw-card').first();
  await expect(card).toBeVisible();
  await expect(card.locator('.bw-badge').first()).toBeVisible();
});
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `npx playwright test -c playwright.pair.config.js tests/pair/client-boards.spec.js -g "design system"`
Expected: FAIL — no `.bw-card` inside a column.

- [ ] **Step 3: Rebuild the card**

Rewrite `Card::render()`, `Card::dates()` and `Card::people()` onto the system: `bw-card` for the card, `bw-badge` for the stage, `bw-chip` for each person, `bw-fieldnote` for the date line. Every `data-testid` and `data-bwx-*` attribute currently emitted stays exactly as it is.

Read the component prompts before writing the markup:

```bash
cat .claude/skills/blueworx-admin-design/components/core/Badge.prompt.md
cat .claude/skills/blueworx-admin-design/components/core/Chip.prompt.md
cat .claude/skills/blueworx-admin-design/components/layout/Card.prompt.md
```

- [ ] **Step 4: Delete the card's own styling**

Remove the card, badge and person rules from `Styles::css()`.

- [ ] **Step 5: Run the tests**

Run: `npm run test:pair && composer lint`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add client/includes/Admin/Card.php client/includes/Admin/Styles.php tests/pair/client-boards.spec.js
git commit -m "Build the work item card from the design system"
```

---

### Tasks 9–15: One screen each

Each of these follows the identical five-step shape. They are listed separately because each is its own reviewable deliverable and its own commit, and because the components differ.

**Before writing markup for any of them**, read the relevant component prompt files under `.claude/skills/blueworx-admin-design/components/`. The prompt file is the contract for what a component's markup must look like; the adherence check reads its vocabulary from the same system.

**The shape, for every one:**

1. Write a failing pair test asserting the screen's panels are `bw-card` inside `.bw-admin.bw-page`, and that the screen's own key component is present.
2. Run it; confirm it fails for the right reason.
3. Rebuild the screen's render methods on the named components, wrapping in `Page::open()` / `Page::close()` and using `Page::panel_open()` / `Page::panel_close()`. Keep every `data-testid` exactly as it is.
4. Delete that screen's rules from `Styles::css()`.
5. Run `npm run test:pair && composer lint`, then commit.

| Task | Screen | File | Built from | Existing spec that must stay green |
|---|---|---|---|---|
| 9 | Work (top level) | `Admin/Screen.php` | `Card`, `SummaryStrip`, `EmptyState`, `DescriptionList` | `client-dashboard.spec.js` |
| 10 | Board | `Admin/BoardScreen.php` | `Card`, `Badge`, `EmptyState` | `client-boards.spec.js` |
| 11 | Asked | `Admin/AskedScreen.php` | `Card`, `ActivityLog`, `EmptyState` | `client-submissions.spec.js`, `client-submission-status.spec.js` |
| 12 | Ask | `Admin/AskScreen.php` | `Field`, `FormRow`, `Textarea`, `Select`, `SaveBar` | `client-submissions.spec.js` |
| 13 | Checklist | `Admin/ChecklistScreen.php` | `Field`, `FormRow`, `Radio`, `Checkbox`, `ProgressBar` | `client-checklist.spec.js` |
| 14 | Sales | `Admin/SalesScreen.php` | `DataTable`, `Pagination`, `EmptyState` | `client-sales.spec.js` |
| 15 | Item (hidden) | `Admin/ItemScreen.php` | `PageHeader`, `Card`, `DescriptionList`, `Tabs` | `client-contributions.spec.js`, `client-denials.spec.js` |

**Task 15 note:** the item screen is the largest at 487 lines and is reached only from a card link. Check `client-isolation.spec.js` still passes — it asserts a client cannot reach another client's item, and that is a REST-level guarantee this task must not disturb.

---

### Task 16: The timeline, on the system's Gantt

**Files:**
- Modify: `client/includes/Admin/TimelineScreen.php`
- Test: `tests/pair/client-boards.spec.js` or a new `tests/pair/client-timeline.spec.js`

**Interfaces:**
- Consumes: `Page` from Task 6, `Card` from Task 8.
- Produces: a timeline rendered as `bw-gantt`.

- [ ] **Step 1: Read the component before writing anything**

```bash
cat .claude/skills/blueworx-admin-design/components/data/Gantt.prompt.md
cat .claude/skills/blueworx-admin-design/components/data/Gantt.d.ts
```

The `.d.ts` is the shape the markup has to produce. The client's timeline data comes from `Workspace::view()`; map it to that shape rather than reshaping the component.

- [ ] **Step 2: Write the failing test**

```javascript
test('the timeline is the design system Gantt', async ({ page }) => {
  await page.goto('/wp-admin/admin.php?page=blueworx-forge-client-timeline');
  await expect(page.locator('.bw-gantt')).toBeVisible();
});
```

- [ ] **Step 3: Run it to make sure it fails**

Run: `npx playwright test -c playwright.pair.config.js -g "design system Gantt"`
Expected: FAIL — `.bw-gantt` not found.

- [ ] **Step 4: Rebuild the render on the Gantt, delete the timeline rules from `Styles::css()`.**

- [ ] **Step 5: Run the tests**

Run: `npm run test:pair && composer lint`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add client/includes/Admin/TimelineScreen.php client/includes/Admin/Styles.php tests/pair
git commit -m "Build the client timeline on the design system Gantt"
```

---

### Task 17: The connection screen, on the shared page editor

**Files:**
- Modify: `client/includes/Admin/ConnectionScreen.php`
- Modify: `client/includes/Admin/ConnectionActions.php`
- Modify: `client/includes/Plugin.php` (require the page editor loader)
- Test: `tests/pair/authentication.spec.js`, plus a new `tests/pair/client-connection.spec.js`

**Interfaces:**
- Consumes: `blueworx-page-editor/blueworx-page-editor.php` (vendored in Task 1), `Connection::store()`, `Connection::studio_url()`, `Connection::site_id()`, `Connection::key()`.
- Produces: an editor screen registered under slug `bwx-forge-client-connection`, storing through its own `read`/`write` callables.

**This is the highest-risk task in the plan. Read this before starting.**

The connection screen is not a plain settings form. It has four distinct POST actions today — connect, disconnect, save update token, forget update token — and the page editor's rule is *one save bar per screen; nothing saves on its own*. The library does model this case: a screen that supplies `read` and `write` callables owns its own storage, and `Schema::checkOwnStorage()` allows that for a settings screen (`store` other than `post`) provided **both** are supplied and both are callable.

So: credentials and the update token become editor fields behind one save bar. Disconnect and forget-token are **not** saves — they are destructive actions, and they stay as their own `admin-post` actions rendered in a panel below the editor, confirmed through the system's `Modal`.

If that split turns out not to fit the library, **stop and report** rather than bending either side. Falling back to design-system markup with the existing save handling is a legitimate outcome and is written into the spec as the alternative.

- [ ] **Step 1: Load the library**

In `client/includes/Plugin.php`, require the loader once, before any screen registers:

```php
		$editor = BWX_FORGE_CLIENT_PATH . 'blueworx-page-editor/blueworx-page-editor.php';

		if ( file_exists( $editor ) ) {
			require_once $editor;
		}
```

- [ ] **Step 2: Write the failing test**

Create `tests/pair/client-connection.spec.js`:

```javascript
import { test, expect } from '@playwright/test';

test('the connection screen is the shared page editor', async ({ page }) => {
  await page.goto('/wp-admin/admin.php?page=blueworx-forge-client-connection');
  await expect(page.locator('.bw-admin.bw-page')).toBeVisible();
  await expect(page.locator('.bw-savebar')).toHaveCount(1);
});

test('saving the connection through the editor keeps the credentials', async ({ page }) => {
  await page.goto('/wp-admin/admin.php?page=blueworx-forge-client-connection');
  await page.getByLabel('Studio URL').fill('https://studio.example/');
  await page.getByLabel('Site ID').fill('site-abc');
  await page.getByLabel('Connection key').fill('key-xyz');
  await page.locator('.bw-savebar button[type="submit"]').click();
  await expect(page.getByText('Saved')).toBeVisible();

  await page.reload();
  // The trailing slash is stripped on the way in, so this proves it went
  // through Connection::store() rather than straight to the option.
  await expect(page.getByLabel('Studio URL')).toHaveValue('https://studio.example');
});
```

- [ ] **Step 3: Run it to make sure it fails**

Run: `npx playwright test -c playwright.pair.config.js tests/pair/client-connection.spec.js`
Expected: FAIL — no `.bw-savebar`.

- [ ] **Step 4: Register the screen**

Replace `ConnectionScreen::render()`'s hand-built form with a registration. The `read`/`write` callables are what keep `Connection::store()` — and therefore the cache flush and the `bwx_forge_client_connected` action — the single door credentials go through.

```php
		\Blueworx\PageEditor\v1\Editor::register(
			array(
				'slug'       => 'bwx-forge-client-connection',
				'title'      => __( 'Studio connection', 'blueworx-forge' ),
				'lede'       => __( 'How this site reaches the studio. Nothing syncs until all three are set.', 'blueworx-forge' ),
				'store'      => 'option',
				'capability' => 'manage_options',
				// Own storage, because the three values are three separate
				// options with their own sanitising, and because Connection::store()
				// is where the cache flush and the connected action live. A plain
				// option_name would write past all of that.
				'read'       => static function (): array {
					return array(
						'studio_url' => Connection::studio_url(),
						'site_id'    => Connection::site_id(),
						'key'        => Connection::key(),
					);
				},
				'write'      => static function ( array $values ): void {
					Connection::store(
						(string) ( $values['studio_url'] ?? '' ),
						(string) ( $values['site_id'] ?? '' ),
						(string) ( $values['key'] ?? '' )
					);
				},
				'tabs'       => array(
					array(
						'id'     => 'connection',
						'label'  => __( 'Connection', 'blueworx-forge' ),
						'panels' => array(
							array(
								'id'     => 'credentials',
								'title'  => __( 'Credentials', 'blueworx-forge' ),
								'note'   => __( 'Issued by the studio when this site was added.', 'blueworx-forge' ),
								'fields' => array(
									array( 'id' => 'studio_url', 'kind' => 'text', 'label' => __( 'Studio URL', 'blueworx-forge' ), 'required' => true ),
									array( 'id' => 'site_id', 'kind' => 'text', 'label' => __( 'Site ID', 'blueworx-forge' ), 'required' => true ),
									array( 'id' => 'key', 'kind' => 'text', 'label' => __( 'Connection key', 'blueworx-forge' ), 'required' => true ),
								),
							),
						),
					),
				),
			)
		);
```

- [ ] **Step 5: Keep the destructive actions where they belong**

Disconnect and forget-token stay as `admin-post` actions in `ConnectionActions`, rendered below the editor in their own `bw-card`, each behind a `bw-modal` confirmation. They do not go through the save bar.

- [ ] **Step 6: Run the tests**

```bash
npm run test:pair
composer lint
vendor/bin/phpunit
```

Expected: PASS, including the existing `authentication.spec.js` and any PHP test covering `Connection::store()`.

- [ ] **Step 7: Commit**

```bash
git add client/includes/Admin/ConnectionScreen.php client/includes/Admin/ConnectionActions.php \
        client/includes/Plugin.php tests/pair/client-connection.spec.js
git commit -m "Build the connection screen on the shared page editor"
```

---

### Task 18: The calendar

**Blocked on a foundation decision.** The design system has 53 components and none is a calendar. The spec's recommendation, approved with it, is to add a `Calendar` component to the foundation rather than hand-roll one here.

**Files:**
- Modify: `client/includes/Admin/CalendarScreen.php`

- [ ] **Step 1: Confirm the component exists before starting**

```bash
ls ../bluegroup_core_foundation/.claude/skills/blueworx-admin-design/components/data/Calendar.jsx
```

If it is missing, this task is not ready. The foundation work is: add the component, open a pull request there, get it merged, cut a release and move `v1`. Then re-pull the vendored copy here:

```bash
node bin/sync-design-system.mjs   # after re-copying .claude/skills/blueworx-admin-design
npm run test:unit
```

Do not start the screen until `npm run lint` and the drift check agree the vendored copy is current.

- [ ] **Step 2: Write the failing test**

```javascript
test('the calendar is the design system calendar', async ({ page }) => {
  await page.goto('/wp-admin/admin.php?page=blueworx-forge-client-calendar');
  await expect(page.locator('.bw-calendar')).toBeVisible();
});
```

- [ ] **Step 3: Run it to make sure it fails**

Run: `npx playwright test -c playwright.pair.config.js -g "design system calendar"`
Expected: FAIL

- [ ] **Step 4: Rebuild the render on it, delete the calendar rules from `Styles::css()`.**

- [ ] **Step 5: Run the tests**

Run: `npm run test:pair && composer lint`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add client/includes/Admin/CalendarScreen.php client/includes/Admin/Styles.php tests/pair
git commit -m "Build the client calendar on the design system"
```

---

### Task 19: Close it out

**Files:**
- Modify: `client/includes/Admin/Styles.php`
- Modify: `package.json`, `blueworx-forge.php`, `client/blueworx-forge-client.php` (version)
- Modify: `CHANGELOG.md` or `changelog.d/`

- [ ] **Step 1: Reduce the stylesheet to what the system genuinely lacks**

Read what is left of `Styles::css()`. Every rule still there must be either the wp-admin chrome reset from Task 6, or something the design system has no pattern for. Anything that *should* have a pattern goes to the foundation as its own pull request — not reinvented here.

Run: `grep -c '{' client/includes/Admin/Styles.php` before and after, and say in the commit message what is left and why.

- [ ] **Step 2: Prove the guardrails now bite**

The adherence check only judges changed files, so run it against the full branch diff:

```bash
BASE_REF=main ADMIN_UI_ADHERENCE=error \
  node ../bluegroup_core_foundation/scripts/check-admin-ui-adherence.mjs
```

Expected: `Admin UI adherence: N admin screen(s) changed, all built from the design system.` — not the "this plugin has no blueworx-admin-design system" skip message. If it still says that, the vendored system is not where the check looks.

```bash
FOUNDATION_DIR=../bluegroup_core_foundation DESIGN_SYSTEM_SYNC=error \
  node ../bluegroup_core_foundation/scripts/check-design-system-sync.mjs
```

Expected: a real comparison reporting no drift.

- [ ] **Step 3: Bump the version and write the changelog**

Minor bump — new behaviour. `package.json`, both plugin headers and the version constants must agree, or CI fails.

The changelog entry is for the site owner, in their words. Something in the shape of:

```markdown
- The Forge screens on your site have a new look, matching the rest of the Blueworx admin. Nothing about your work, your data or your permissions has changed.
```

- [ ] **Step 4: Run everything**

```bash
npm run lint
npm run build
npm run test:unit
composer lint
vendor/bin/phpunit
npm run wp:pair:reset && npm run wp:pair:up && npm run test:pair
```

Expected: all green. Show the output — do not claim this from memory.

- [ ] **Step 5: Commit and open a draft pull request**

```bash
git add -A
git commit -m "Reduce the client stylesheet to what the design system lacks"
```

Open a **draft** PR against `main`. Say what changed, that client sites will look different after the next update, and that ARCH-8 is a decision recorded in this PR. Never auto-merge.

---

## Self-review notes

**Spec coverage.** Every section of the spec maps to a task: the plumbing (1–4), the test net (5), the three shared pieces (6–8), the ten screens (9–16, 17, 18), the stylesheet reduction and guardrail proof (19). The calendar's open question is carried into Task 18 with its blocking condition stated rather than assumed away.

**Known gap, deliberately left open.** Task 17's split — credentials behind the save bar, destructive actions outside it — is the plan's one genuine unknown. It has an explicit stop-and-report instruction rather than a guess dressed as a step.

**Type consistency.** `Page::open` / `Page::close` / `Page::panel_open` / `Page::panel_close` are used under those names in Tasks 6, 9–16 and 18. `plan()` is defined in Task 1 and consumed by name in Task 3.
