# Changelog page and Settings (rules) page — design

Luke, 2026-09-29: "In the backend, a Changelog that tracks all the changes/updates going in. In the frontend, a Settings page under Insights that shows admins all the Rules of Forge in plain English, so I am able to see how things work and why tasks behave the way they do." Design approved in chat the same day.

Studio only; the client plugin is untouched. Two issues, two pull requests, Changelog first. Each is a minor version bump with its changelog entry and a test.

## 1. Changelog — WordPress admin

Luke chose WordPress admin over the app. It sits beside Updates, the screen it belongs with, so it is treated as part of "updates" under ARCH-7.

- New submenu **Changelog** under the Forge menu, directly after Updates. `manage_options` only, like every other Forge admin screen. New class `includes/Admin/ChangelogScreen.php`, following `UpdatesScreen.php` (`register()`, `url()`, `render()`, `Page::open`).
- It reads `CHANGELOG.md` from the plugin folder. The file already ships in the studio zip (`bin/artifacts.json`), so nothing new crosses the artifact boundary.
- A small parser (`includes/Admin/Changelog.php`) turns the file into releases: version, date, and sections (`Added`, `Changed`, `Fixed`, `Removed`, anything else as written), each holding its bullet lines. It understands only the Keep a Changelog shape the file uses: `## [x.y.z] - YYYY-MM-DD`, `### Heading`, `- bullet`, with continuation lines joined to their bullet. The intro above the first release is skipped. Inline `**bold**`, `` `code` `` and `[text](url)` are rendered; everything else is escaped text.
- Releases are shown newest first, as they appear in the file. The installed version (`BWX_FORGE_VERSION`) is marked "Installed".
- A missing or unreadable file shows a one-line notice, not an error.
- It shows what is installed and earlier. Notes for a newer release stay where they are now: the update's "View details" box, fed by the update checker.

Tests: a PHPUnit test for the parser (headings, sections, continuation lines, inline formatting, escaping, an empty file) and a Playwright spec that opens the screen as admin and sees the current version marked Installed.

## 2. Settings — in the app, under Insight

- New screen key `settings`, in the **Insight** group after Packages, `admin: true` (hidden from Managers, and a Manager deep-linking to it lands on the board, as for the other admin screens). Wired the same way as every other screen in `src/App.tsx`: `ScreenName`, `RAIL`, `TITLES`, `OPENINGS` (crumbs `['Insight', 'Settings']`), import and render line.
- Read-only. Nothing on it changes how Forge behaves. It needs no REST route: the rules are static text that ships with the build.
- Content lives in one file, `src/rules.ts`: a list of sections, each `{ id, title, rules: { what, why }[] }`. `what` is one plain sentence saying what happens; `why` is one plain sentence saying why. No jargon, no field names, no decision codes in the text.
- The screen (`src/components/SettingsScreen.tsx`) shows a short intro, jump links to each section, then each section as a heading with its rules. Built from the shared kit in `src/kit/`, no new components unless the kit lacks one.
- Sections: the task journey (stages and what each needs to move on), triage, review and release, blocked work, going back and reopening, recurring tasks, meetings and the standup, capacity and hours, reminders, client requests, and who can do what (admins, managers, staff per client, designers, the client as reviewer).

### Keeping the rules true

A wrong rules page is worse than none.

- Each rule is written from `docs/architecture/decisions.md` and the later specs in `docs/superpowers/specs/`, then checked against how the code actually behaves. Where they disagree, the code wins and the rule says what the code does; the disagreement is noted in the pull request for Luke.
- Luke reviews the wording in the Settings pull request.
- From then on, a change to how Forge behaves updates `src/rules.ts` in the same pull request as its changelog entry. A line saying so is added to the project `CLAUDE.md`.
- Not done: generating the text from the decision register (written for developers, and partly superseded), or a CI check that guesses when rules need updating (it cannot tell reliably and would misfire).

Tests: a Playwright spec that an admin sees Settings under Insight, every section heading renders, and a jump link scrolls to its section; and that a Manager does not see it in the rail.
