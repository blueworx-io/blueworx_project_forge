# Blueworx Forge — rebuild design

Date: 2026-08-17
Status: approved

Forge has been redesigned in Claude Design. This spec covers **preparing the
repo to receive that rebuild**: `main` becomes a new plugin, Blueworx Forge,
that boots, ships, and tests green with no features in it. The features
themselves are built from the design afterwards, each in its own issue and pull
request.

## Decisions made before this spec

| Question | Decision |
|---|---|
| How the design arrives | Claude Design GitHub sync to a `design-sync` branch, never merged. An export in `design/` is the fallback. |
| Data and features | Full rebuild. Nothing is ported. Items are moved from the old plugin by hand, when needed. |
| Old plugin | Stays installable from the existing `v1.37.2` release, and stays in history. Not modified. |
| Where the new plugin lives | This repo. `main` becomes the new plugin. |
| Identity | Blueworx Forge, slug `blueworx-forge`, namespace `Blueworx\Forge`, constants `BWX_FORGE_*`, text domain `blueworx-forge`. |
| Scope of this pass | A working empty skeleton. No features, no data model, no migration tooling. |

## Why a new slug

The slug is the folder WordPress installs into, so a new slug is what lets both
plugins be active on one site at the same time. That is the whole migration
plan: install Blueworx Forge alongside Forge Project Management, move the items
that matter across by hand, then deactivate the old one. Reusing the slug would
make the rebuild an *update* that overwrites the plugin holding the data being
migrated.

Every global name the new plugin owns — post types, options, transients, roles,
REST namespace — is distinct from the old plugin's, so the two cannot collide on
a shared site.

## Version: 2.0.0, not 0.1.0

The repo continues, so the version continues with it. A rebuild that keeps
nothing is a breaking change, which is a major bump. Starting again at `0.1.0`
would also fail the shared version-bump guardrail, which reads a version that
goes backwards as no bump at all.

## Architecture

### PHP

Namespaced and autoloaded, following Blueworx Ledger:

```
blueworx-forge.php          Plugin header, constants, update checker, boot
uninstall.php               Removes only this plugin's own options
includes/autoload.php       Prefix-based autoloader for Blueworx\Forge
includes/Plugin.php         Singleton: activate, deactivate, boot
includes/Rest/Server.php    Registers the REST namespace and its controllers
includes/Frontend.php       The app page, its template, and asset enqueuing
```

- One class per file, class name matching the file name.
- Full WordPress Coding Standards from the first commit — a new codebase has no
  legacy style to accommodate, so the tree is clean before the first pull
  request rather than after.
- Boots on `plugins_loaded`. Activation registers the app page; deactivation
  flushes rewrite rules. Nothing runs at file scope except the update checker,
  which cannot be wrapped.
- REST namespace `blueworx-forge/v1`. Every route carries an explicit
  permission callback — no route defaults to public without that being a written
  decision.

### Front end

Vite + React + TypeScript + Tailwind, built into `assets/` and committed,
because WordPress serves the built bundle. That is the pipeline the old plugin
used and it works; Claude Design emits React and Tailwind, so the intake fits it
without a translation layer.

The skeleton ships exactly one screen: a mount point that renders and reports
that it is alive. `npm run dev` continues to serve the app standalone on
localhost with no WordPress, which stays the fastest way to look at front-end
work.

One localised data object, `bwxForgeData` — REST URL, nonce, capabilities of the
current user, site URLs, so no component ever has to guess what the user may do.

### Design intake

`design-sync` is reference, not source. It is never merged into `main` and its
contents never reach the zip. Components are read from it and built into the
plugin through normal pull requests, which is what keeps the shipped plugin to
code that has been reviewed. An export instead of a sync lands in `design/`,
excluded from the zip the same way.

## What the transition removes

One pull request deletes, from `main`:

- `forge-project-management.php`, `includes/class-*.php`, `templates/`
- `src/` (the old React app) and the built `assets/` bundle
- The old plugin's Playwright specs
- The old zip build script's slug, workflow inputs, and update-checker wiring

and keeps, unchanged in purpose:

- The shared foundation files: CI and release callers, PR and issue templates,
  `.claude/settings.json`, `CLAUDE.md`, `approved-deps.json`
- The vendored `plugin-update-checker/`
- `bin/build-zip.sh`, `phpcs.xml.dist`, `playwright.config.js`, `CHANGELOG.md`
  — each updated for the new identity
- `docs/`, including this spec and the old plugin's design docs, which stay as
  the record of what the old plugin did

The old plugin is not modified in this pass. A fix to it would be a branch off
the `v1.37.2` tag.

## Testing

The skeleton is not finished until it proves itself on a real WordPress. Its
Playwright specs, running against the disposable instance the harness
provisions:

1. The plugin installs and activates with no PHP error.
2. The app page exists, and the React app mounts into it.
3. The REST namespace answers, and an unauthenticated write is refused.
4. Uninstall removes the plugin's own options and leaves site content alone.

Point 3 exists from the start deliberately: a permission callback that was never
wired is invisible until someone finds it, and the shape of the failure is the
whole API being public.

PHPUnit is configured with a passing test from the first commit, so the data
layer arriving next has somewhere to go. PHPCS runs on the WordPress standard.

## Out of scope

- Any feature. The design decides those, and each gets its own issue and PR.
- The data model — post types or custom tables — which the design's data
  requirements decide, not a guess made before it lands.
- Migration tooling. Items move by hand, which is what was asked for.
- The old plugin, and the Foundry connector work in PR #61. That PR targets code
  this pass deletes, so it cannot merge afterwards; it is closed with a pointer
  to the rebuild rather than left looking mergeable.

## Risks

**The design may not fit the pipeline.** If Claude Design's output assumes a
framework this plugin does not use, the intake becomes a translation job. The
skeleton is deliberately thin so that discovery is cheap — nothing built on the
wrong assumption yet.

**Two plugins on one site.** Both will be active during migration. The
distinct-names rule above is what makes that safe, and the activation spec is
what proves the new plugin does not break the old one's site.

**`main` stops being the old plugin.** Anyone reading the repo after this pass
sees the new plugin only. The `v1.37.2` release and tag are the old plugin's
home, and the changelog entry for 2.0.0 says so plainly.
