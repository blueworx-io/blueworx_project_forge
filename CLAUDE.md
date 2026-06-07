# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Intent

Build an app from a Figma Make design.

## GitHub Issue Workflow (source of truth)

GitHub Issues are the source of truth for all work. Follow this Issue → Implementation → Review → Pull Request flow for every assigned issue:

1. **Read the issue first.** Read the assigned GitHub Issue before making any changes.
2. **Confirm understanding.** Confirm the issue goal, scope, acceptance criteria, and out-of-scope items.
3. **Branch.** Create a new branch for the issue.
4. **Implement only the requested scope.** Build exactly what the issue asks for — nothing more.
5. **No unrelated changes.** Avoid unrelated refactors, styling changes, or cleanup.
6. **Run required checks** before committing:
   - `npm run lint`
   - `npm run build`
7. **Review your own changes** before committing.
8. **Confirm acceptance criteria.** Verify the implementation matches the issue's acceptance criteria.
9. **Commit** with a clear message that references the issue number (e.g. `Fix sidebar overflow (#42)`).
10. **Open a draft pull request** linked to the issue.
11. **PR description must include:**
    - issue link
    - summary of changes
    - files changed
    - test results
    - review notes
    - anything still requiring human review
12. **Leave the PR for human review.** Mark it as a draft / ready for the user to review and action — never auto-merge.

## Token Efficiency

- Do not paste large files unless necessary.
- Prefer reading only the relevant files.
- Use diffs and summaries where possible.
- Keep status updates concise.
- Ask before expanding scope.
- Do not re-read unchanged files unnecessarily.

## Rules

- Use the `frontend-design` skill for all UI work.
- When a Figma URL is provided, use the Figma MCP tools before writing any code.
- As the project grows, reuse patterns already established in the repo — don't reinvent what exists.
- Keep code clean, simple, and maintainable. Avoid over-engineering.
- Do not create files that aren't needed for the current task.
- Do not modify files unrelated to the current task.
- Before creating a new directory or file structure, explain the proposed layout and get confirmation.
- Before choosing a framework or making a major architectural decision, ask the user first.

## Plugin Deployment

This repo **overrides** the global "remove `node_modules`" rule in `~/.claude/CLAUDE.md`. Keep `node_modules` in place — it is intentionally retained so `npm run dev` (localhost) and `npm run zip` stay instant with no reinstall.

To produce the installable plugin zip:

1. `npm install` (first time / after dependency changes only)
2. `npm run zip` — builds fresh assets, then stages **only** the runtime files (`forge-project-management.php`, `includes/`, `templates/`, `assets/`) into `dist-zip/` and compresses them to `forge-project-management.zip` at the repo root.

The zip never contains `node_modules`, `src/`, or build/lint configs — those are physically never staged (see `scripts/zip-plugin.ps1`). The zip wraps the plugin in a `forge-project-management/` folder so it unzips straight into `wp-content/plugins/`. Both `dist-zip/` and the zip are git-ignored.

Local dev runs standalone: `npm run dev` serves the app at `localhost:5173` with sample data (no WordPress needed).

## Version Bumping

Bump the version on every session that produces deployable changes. Two files must always stay in sync:
- `forge-project-management.php` — plugin header (`Version:`) and `FORGE_PM_VERSION` constant
- `package.json` — `"version"` field

Use patch bumps (1.0.x) for fixes, minor bumps (1.x.0) for new features.

## State Management (Zustand v5)

- The store hook does **not** accept a second equality-function argument (removed in v5). Calling `useDataStore(selector, shallow)` silently ignores `shallow`, so a selector that returns a new array/object every call (e.g. `selectAllItems`) causes an infinite render loop → React error #185 ("Maximum update depth exceeded").
- For any derived/array/object selector, wrap it: `useDataStore( useShallow( selector ) )` from `zustand/react/shallow`. Single-field selectors like `s => s.features` are fine as-is.

## Environment Notes

- `tsc --noEmit` reports hundreds of pre-existing errors (missing React type declarations) — use `npm run build` to check for real errors instead.
- `rm -rf node_modules` can fail on Windows (busy files) — always use PowerShell `Remove-Item -Recurse -Force node_modules`.
