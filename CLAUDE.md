# Global CLAUDE.md

Global rules that apply to every project. Lives at `~/.claude/CLAUDE.md`. Full detail and all copy-paste prompts live in the `bluegroup_core_foundation` repo and the Team Guidelines doc — this file is the condensed version Claude Code needs every session, and should never contradict them. The Recipe Book is `docs/recipe-book.md` in that repo.

## How Projects Are Structured

- Every project is its own standalone repo — there is no monorepo
- Every project points at `bluegroup_core_foundation` for shared CI guardrails, permissions, and skills — never repeat those rules inside a project repo
- Projects don't have to share components or look alike — only the process is shared, not the design
- New projects are set up by pasting the matching Starter Prompt (standalone / WordPress plugin / headless) into Claude Code — there are no starter template repos to create from. All three live in `docs/` in `bluegroup_core_foundation`
- **Standalone means the content is the code.** If a non-developer edits the content, it is not standalone — it wants WordPress, so it is a plugin or a headless project

## The Flow

Design System → Figma/Lovable/Claude Design → Claude Design (single source of truth) → handoff (export, or Claude Design's direct GitHub sync) → Claude Code builds → branch → pull request → automatic checks → review → merge → deploy

Every build or change starts from an approved GitHub Issue.

## Hard Guardrails (enforced by CI on every project, every type)

- Lint passes
- Build passes
- Version bumped on the pull request
- Changelog updated alongside the version bump
- No new dependency without prior approval (`approved-deps.json`)
- New functionality or a real bug fix has a Playwright test

## Testing (WordPress plugins)

- Test against the **local WordPress harness**, not a hosted staging site. One command,
  no Docker, uses your own PHP:
  `node ../bluegroup_core_foundation/scripts/wp-test-env.mjs up --plugin .`
  then `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8881 WP_ADMIN_USER=admin WP_ADMIN_PASS=wptest-admin-pw npx playwright test --workers=1`
- In CI, pass `use_local_wordpress: true` instead of `preview_url`. Add `.wp-test/` to `.gitignore`
- **A skipped test is not a passing test.** CI fails a run that executes zero tests, because
  a placeholder URL once let a whole suite skip itself while reporting green for months
- Prefer tests that create what they need over tests that assume ambient site state
- Full guide, including why each setting exists: `docs/wordpress-test-harness.md` in the foundation

## Golden Rules

- Always work on a branch, never main
- Every change goes through a pull request
- CI guardrails must pass — never bypassed, except a rare, written, Luke-approved emergency override
- Anyone with repo access can review and merge — no second sign-off required

## Versioning

- Patch bump for fixes, minor bump for new features
- Bump automatically alongside the change, and update the changelog to match — never wait to be asked
- The version lives in `package.json`, and for a plugin also in the header and version constant of the main `.php` — CI fails if those disagree
- **`package-lock.json`'s own `version` field is not part of that, and is deliberately left alone.** It is already years out of date in most repos and nothing reads it: `npm ci` resolves the dependency tree, not the root version. Don't "fix" it, don't add a check for it — this was looked at and decided

## Linting

- Run the linter once, as a final check — never loop lint, auto-fix, re-lint during a task
- Present any findings to the user at the end of the session and let them decide whether to action them
- Only fix lint issues after the user approves

## Deployment

Do this proactively at the end of any session with deployable changes — never wait to be asked.

- Standalone: `npm install`, `npm run build`, then remove `node_modules` to leave the folder clean for manual zipping
- WordPress plugin: **updates ship as GitHub Releases, not zips.** At session end, do the part that belongs to the change: bump the plugin version and update the changelog, on the branch, in the PR. Nothing else
  - **Tagging is not a session-end step.** Releases are cut only after the PR is reviewed and merged, and only when asked: `git tag v1.2.0 && git push origin v1.2.0`. Never merge to main or push a tag on your own initiative — that is a release decision, not a build step
  - Once tagged, CI verifies the tag matches the plugin header, builds the zip, and publishes the Release; sites running the vendored update checker install it themselves
  - A hand-built zip is only for a plugin's **first** install on a site, or a repo not yet on the release workflow
- When a hand-built zip is genuinely needed: build it **one level up from the repo** at `<plugin-parent-dir>/<plugin-slug>-<version>.zip` — never inside the repo working tree. Remove any older `<slug>-*.zip` in that parent folder first. The zip is the deployment artifact, never copy individual files
  - **The filename carries the version, the folder inside never does.** `my-plugin-1.4.2.zip` containing `my-plugin/my-plugin.php` — the version comes from the plugin header, so the file on disk says which build it is. WordPress installs to the folder name inside the archive, so a versioned folder would install a second copy of the plugin on every update instead of replacing the first
  - **If the repo ships a zip build script, run it** (e.g. `npm run build:zip`) and skip the manual recipe below. A repo that has one uses it to declare exactly which files may enter the artifact and to verify the result, and CI checks the same thing on every PR. Building the zip by hand bypasses that — zipping the folder is how development-only files reach a live site. If the wrong files ship, fix the script's allowlist; never hand-edit the zip
  - The archive **must use forward slashes** (`<slug>/<slug>.php`, nested one level) — WordPress hosts are Linux and a backslash zip mis-extracts, reporting "Plugin file does not exist." on activate
  - **Never use PowerShell `Compress-Archive`** — on Windows PS 5.1 it writes backslash entries (the exact bug above). Build with **bsdtar**: `/c/Windows/System32/tar.exe -a -c -f ../<slug>-<version>.zip -C dist <slug>` (Git Bash) or `& "$env:WINDIR\System32\tar.exe" -a -c -f "..\<slug>-<version>.zip" -C dist <slug>` (PowerShell); GNU `tar` can't write zip, so call System32 `tar.exe` explicitly
  - Verify before handing off: `unzip -l ../<slug>-<version>.zip` — every entry must read `<slug>/...` with `/`, and the version must appear in the filename only. Any `\` means the zip is broken; rebuild. Don't deliver a zip you haven't listed
- Headless: nothing manual — CI and Netlify handle install, build, and deploy once merged

## Approved Tools & Styles

- Framework (headless projects): Next.js (App Router) + TypeScript — scaffolded via create-next-app
- Component base: Radix Themes
- Icons: lucide-react
- Styling: Tailwind CSS
- Design tokens: styles.refero.design
- Animation: tailwindcss-animate for simple cases, GSAP for complex cases, across every project type including WordPress
- Inspiration only, never copied directly: 21st.dev
- No page builders (Elementor etc.) — WordPress sites are built as a plugin, in code, never straight into WordPress core or a loose theme

## Skill Usage Policy

These skills load automatically from the shared `bluegroup_core_foundation` settings — nobody enables them by hand (graphify is the one per-machine exception, below). **You MUST invoke each one the moment its trigger applies, before doing the work — no human will remind you.** Say "Using [skill] because [trigger]" out loud so the choice is visible and correctable.

| When this happens | You MUST use | How |
|---|---|---|
| Starting any feature, component, or behaviour change | brainstorming → writing-plans | Explore intent with `superpowers:brainstorming` before entering plan mode, then capture the plan with `superpowers:writing-plans` before touching code |
| Executing an approved written plan | executing-plans | Drive it with `superpowers:executing-plans` and honour its review checkpoints |
| Implementing any feature or bug fix | test-driven-development | Write the failing test first with `superpowers:test-driven-development`, before implementation code |
| Any bug, test failure, or unexpected behaviour | systematic-debugging | Find root cause with `superpowers:systematic-debugging` before proposing or writing a fix |
| A security-sensitive change (auth, secrets, input handling, uploads, payments, access control) | security-review | Run `security-review` before committing |
| About to claim work is done / before any commit or PR | verification-before-completion | Run `superpowers:verification-before-completion` and show real command output — evidence before claims |
| Work complete, before merge | requesting-code-review → finishing-a-development-branch | Get review via `superpowers:requesting-code-review`, then integrate with `superpowers:finishing-a-development-branch` |
| Any question about this codebase's architecture, file relationships, or content | graphify | Treat it as a graph query first (see below) |
| A brand-new repo that has no `CLAUDE.md` yet | init | Generate the project's `CLAUDE.md` with `init` |
| Repeated permission prompts for safe, read-only commands | fewer-permission-prompts | Run `fewer-permission-prompts` to add a scoped allowlist to the project's `.claude/settings.json` |

### graphify — per-machine install + usage

- **Install once per machine:** `uv tool install graphifyy && graphify install`. It's a Python CLI (PyPI `graphifyy`), not a config-enabled plugin — the shared settings only mark it approved.
- **PATH gotcha:** the CLI installs to `~/.local/bin` (Windows: `%USERPROFILE%\.local\bin`), which may not be on PATH. If `graphify` isn't found, add that directory to PATH — don't reinstall.
- **Usage:** for any question about this project's architecture, how files relate, or where something lives, treat it as a graphify query first. If `graphify-out/` exists, query the existing graph; if none exists yet, build it, then query.

### Enforced vs model-driven — know the difference

- **Deterministic (enforced every time by CI):** lint, build, version bump, changelog, approved-deps, Playwright test — the Hard Guardrails above. Never bypass these; the triggers below never override them.
- **Model-driven (this policy + each skill's own description):** every trigger in the table. Strong, but they fire on *your* judgement, not a guarantee — which is exactly why the "say it out loud" rule exists. There are deliberately no per-skill hooks: these skills fire on the *kind* of change (a bug, a security-sensitive edit, a feature), which a tool/event hook can't detect without misfiring, and the truly must-happen-every-time checks already live in CI.

## Model Guidance

- Default for building, Issues, Milestones: Claude Sonnet
- A genuinely hard bug or architecture decision: Claude Opus
- A very large or complex build (major migration, multi-day build): Claude Fable
- Quick, mechanical, high-volume work: Claude Haiku
- Claude Design: the same tiers, picked per project in-app

## Naming Conventions

- Repos: `blueworx_project_projectname` or `blueworx_client_clientname`
- Claude Design: `Project | ProjectName` or `Client | ClientName`
- Netlify: `blueworx-project-projectname` or `blueworx-client-clientname`
- Branches: short and descriptive — e.g. `add-contact-form`, `fix-header-bug`
- GitHub Issues: short, action-oriented title matching the branch; type set with a label, not in the title
- GitHub Milestones: short, descriptive phase name

## Recipe Book

Before building anything that solves a common, recurring problem (contact form, login, file upload, payment, search, error/loading states, WordPress shortcodes on a headless site), check the Recipe Book first and follow the standard approach if one exists. It lives at [`docs/recipe-book.md`](https://github.com/blueworx-io/bluegroup_core_foundation/blob/main/docs/recipe-book.md) in `bluegroup_core_foundation`. Most topics are still unwritten — an unwritten topic means propose a recipe for Luke's approval, not invent one per project.

The one written recipe carries standing guidance worth knowing before a project starts: **if a site leans on third-party WordPress shortcodes, that is an argument against headless.**

## Secrets

Stored as environment variables in Netlify. Never committed to a repo or shared any other way.

## Accessibility

Meaningful alt text, real form labels, readable contrast, full keyboard access, and heading order used correctly — on every screen, every project type. Not a blocking CI check today, just how things get built.

---

# Project-specific — Forge Project Management

Everything above is the shared foundation `CLAUDE.md.template`, carried in verbatim.
This section is the only part that is Forge's own, and it must never contradict the
rules above — where they overlap, the foundation wins.

## Project Intent

Product planning and release management for WordPress, built from a Figma Make design
and shipped as a WordPress plugin (React + Vite front end, PHP REST API).

## GitHub Issue Workflow (source of truth)

GitHub Issues are the source of truth for all work. Follow this Issue → Implementation
→ Review → Pull Request flow for every assigned issue:

1. **Read the issue first**, before making any changes.
2. **Confirm** the goal, scope, acceptance criteria, and out-of-scope items.
3. **Branch.**
4. **Implement only the requested scope** — nothing more, and no unrelated refactors,
   styling changes, or cleanup.
5. **Run the checks** before committing: `npm run lint`, `npm run build`,
   `composer lint`, and the Playwright suite.
6. **Review your own changes**, then confirm they meet the acceptance criteria.
7. **Visual confirmation.** For any change with a visible effect, run `npm run dev` and
   let the user confirm it in the browser before committing or opening the PR.
8. **Commit** referencing the issue number (e.g. `Fix sidebar overflow (#42)`).
9. **Open a draft pull request** linked to the issue, saying what changed, what was
   tested, and anything still needing human review. Never auto-merge.

## Local development

- `npm run dev` serves the app standalone at `localhost:5173` with sample data — no
  WordPress needed. This is the fastest way to check front-end work.
- **`node_modules` stays in place.** This repo overrides the foundation's "remove
  `node_modules` at session end" rule: keeping it makes `npm run dev` and
  `npm run build:zip` instant with no reinstall.
- `npm run build` writes the app bundle into `assets/` (committed — WordPress serves it).

## Testing against real WordPress

```bash
npm run wp:up      # disposable WordPress on http://127.0.0.1:8892 (PHP + SQLite)
npm test           # Playwright, against that instance
npm run wp:down
```

The instance lives in `.wp-test/` (git-ignored) and ships its own `admin` /
`wptest-admin-pw` account. CI provisions the same thing.

## Zip builds

`npm run build:zip` builds fresh assets and stages **only** the runtime files from an
explicit allowlist, writing `forge-project-management-<version>.zip` one level above the
repo. Hand-built zips are for a site's *first* install only — updates ship as GitHub
Releases (see the foundation rules above).

## State Management (Zustand v5)

- The store hook does **not** accept a second equality-function argument (removed in
  v5). Calling `useDataStore( selector, shallow )` silently ignores `shallow`, so a
  selector returning a new array/object every call (e.g. `selectAllItems`) causes an
  infinite render loop → React error #185 ("Maximum update depth exceeded").
- For any derived/array/object selector, wrap it:
  `useDataStore( useShallow( selector ) )` from `zustand/react/shallow`. Single-field
  selectors like `s => s.features` are fine as-is.

## Rules

- Use the `frontend-design` skill for all UI work.
- When a Figma URL is provided, use the Figma MCP tools before writing any code.
- Reuse the patterns already established in the repo — don't reinvent what exists.
- Keep code clean and simple; don't create files the current task doesn't need, and
  don't modify unrelated ones.
- Ask before adding a new directory structure, framework, or major architectural change.

## Token Efficiency

Read only the relevant files, prefer diffs and summaries over pasting large files, keep
status updates short, and don't re-read unchanged files.

## Environment Notes

- `tsc --noEmit` reports hundreds of pre-existing errors (missing React type
  declarations) — use `npm run build` to check for real errors instead.
- `rm -rf node_modules` can fail on Windows (busy files) — use PowerShell
  `Remove-Item -Recurse -Force node_modules`.
- PHP follows the WordPress coding standards (`composer lint`). Three sniff groups
  are excluded, each with its reason written into `phpcs.xml.dist`; nothing
  security-related is among them.
