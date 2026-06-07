# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Intent

Build an app from a Figma Make design.

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

Follow the global deployment instructions in `~/.claude/CLAUDE.md` — `npm install`, `npm run build`, then remove `node_modules` to leave the folder ready for manual zipping.

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
