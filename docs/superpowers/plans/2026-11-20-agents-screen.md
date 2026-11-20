# Agents Screen Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the agent's work off My tasks onto a new Agents screen, remove the day planner, and narrow Today's diary to meetings.

**Architecture:** `src/mytasks.ts` keeps the row and tab rules; `mineFor` stops producing agent rows and a new `agentWork` produces them for the whole studio. A new `AgentsScreen` reuses those rules and the kit's `DataView`. The day planner is deleted end to end (React, sort module, REST route, PHP class, tests).

**Tech Stack:** React + TypeScript (Vite), PHP REST API, Playwright e2e, `node --test` unit tests, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-11-20-agents-screen-design.md`

## Global Constraints

- Branch `add-agents-screen`. Commits reference the GitHub issue (Task 0).
- Version 2.225.0 → **2.226.0** in `package.json`, `blueworx-forge.php` (header + constant) and `client/blueworx-forge-client.php`; changelog entry to match.
- Copy: screen name "Agents"; row tag "Agent"; column "Looked after by".
- `src/rules.ts` updated in the same PR as the changelog.
- No new dependencies. Icon: `Bot` from `lucide-react` (already a dependency).
- Local testing stays targeted: run only the specs touched; CI runs the full suite. Never build or edit PHP while a local e2e run is going.

## Review Focus

- An agent task whose deliverer is someone else: the Agents screen still lists it (studio-wide, not "mine"). Pinned in Task 1's spec.
- An agent task that is released, cancelled or with the client for review: not on Agents, same as My tasks. Covered by sharing `viewOf`/the skip rules in `agentWork`.
- The top-bar client picker narrows Agents like My tasks (`listed( rows, siteId )`).
- Someone with no Forge person record can still open Agents (it does not need `me`).
- A day with chores but no meetings: Today's diary does not render at all. Pinned in Task 3.

---

### Task 0: Issue

- [ ] Create the GitHub issue "Add an Agents screen" via `gh api repos/blueworx-io/blueworx_project_forge/issues -f title=... -f body=...` (body: the spec's "What changes" list). Note its number as `#N` for commit messages.

### Task 1: Agent work leaves My tasks and gets its own screen

**Files:**
- Modify: `src/mytasks.ts`
- Create: `src/components/AgentsScreen.tsx`
- Modify: `src/App.tsx` (RAIL after `standup`, `TITLES`, `OPENINGS`, render), `src/types.ts:214` (`ScreenName` gains `'agents'`), `src/sidebarCounts.tsx`
- Modify: `src/components/MyTasksScreen.tsx` (drop `"Agent's work"` from the role filter options; `ROLE_LABEL.agent` becomes `'Agent'`)
- Test: `tests/e2e/agent-seat.spec.js` (the test around line 150)

**Interfaces:**
- Produces: `agentWork( siteList: Site[], items: WorkItem[] ): Mine[]` in `src/mytasks.ts` — one row per item where `agentsTurn( item )`, `role: 'agent'`, `hours: 0`, same skip rules as `mineFor` (terminal outcome, client review). `mineFor` no longer pushes agent rows (it still `continue`s past them).
- Produces: screen key `'agents'`, rail testId `bwx-screen-agents`, screen root testId `bwx-agents`, table testId `bwx-agents-table`, row open button testId `bwx-agents-open`.

- [ ] **Step 1: Rewrite the e2e assertion** in `agent-seat.spec.js` where it says "While the agent builds it, it is in the deliverer's My tasks": the deliverer's My tasks (`bwx-mytasks`, Everything tab) does **not** contain the title; then click `bwx-screen-agents`, open the "Everything" view, and expect `bwx-agents` to contain the title, a row with the tag text `Agent`, and the deliverer's display name. Replace the `bwx-day-block` locator there with a table row locator. Add a second check signed in as an admin who is **not** the deliverer: the task is still on their Agents screen.
- [ ] **Step 2: Run it, expect FAIL** — `npx playwright test tests/e2e/agent-seat.spec.js --workers=1` (needs `npm run wp:up` and `npm run build` first). Fails on the missing `bwx-screen-agents`.
- [ ] **Step 3: Implement** `agentWork` and the `mineFor` change. Build `AgentsScreen` from `MyTasksScreen`'s shape: same load (`/client-sites`, `/stages`, `/work-items-all`), same six views with "Everything with the agent" for `all`, same sort, search, `ItemPanel`, `useLiveReload`, `useClientChoice`. No diary, no planner, no hours column, no "nobody" state. Columns: Task, Client, Stage, Agent tag (`<Tag tone="neutral">Agent</Tag>`), Looked after by (deliverer's `display_name`, read from `/people` in the same `Promise.all` — any staff member may read it, see `ItemPanel.tsx:261`; "Somebody" if not found), Priority, Due. Filter: "Looked after by", options from the rows' deliverers. Rail entry `{ key: 'agents', label: 'Agents', icon: Bot, testId: 'bwx-screen-agents', primary: true }` directly after standup; opening `{ crumbs: [ 'My day', 'Agents' ], eyebrow: "The agent's work", tile: Bot, hue: 'blue' }`. Sidebar: `AgentsBadge` showing `todayCount( agentWork(...), siteId )`, said "for today".
- [ ] **Step 4: `npm run build`, rerun the spec, expect PASS.**
- [ ] **Step 5: Commit** — `Move the agent's work to its own screen (#N)`.

### Task 2: Remove the day planner

**Files:**
- Delete: `src/components/DayPlanner.tsx`, `src/dayplan.mjs`, `tests/unit/dayplan.test.mjs`, `tests/e2e/day-planner.spec.js`, `includes/Rest/DayPlanController.php`, `includes/Work/DayPlan.php`, `tests/php/DayPlanTest.php`
- Modify: `includes/Rest/Server.php:97` (drop the registration), `src/components/MyTasksScreen.tsx` (drop `body=`, `below`/`setBelow`, `onToday`, the `bwx-day-below` div, the import), `src/styles.css` (every `.bwx-day*` rule)
- Modify specs that locate rows by `bwx-day-*`: `my-tasks.spec.js:127`, `global-client-picker.spec.js`, `google-calendar-app.spec.js`, `my-tasks-client-review.spec.js`, `priority-order.spec.js`, `recurring-ticks.spec.js`, `reminders.spec.js` — switch each to table rows in `bwx-mytasks-table`; delete an assertion only where it tested the planner itself.

Saved plans in user meta are left in place (harmless, nothing reads them) — Claude's call.

- [ ] **Step 1:** Delete and edit as above. `grep -rn "bwx-day\|DayPlan\|dayplan" src includes tests` returns nothing.
- [ ] **Step 2:** `npm run build`, `npm run test:unit`, `vendor/bin/phpunit` — all pass.
- [ ] **Step 3:** Run the edited specs (one command, listed files) — all pass.
- [ ] **Step 4: Commit** — `Remove the day planner from My tasks (#N)`.

### Task 3: Today's diary shows meetings only

**Files:**
- Modify: `src/components/MyTasksScreen.tsx` (`myDiary` keeps `'meeting' === entry.kind` only; the section renders only when there is one)
- Test: `tests/e2e/my-tasks.spec.js` (diary tests from line 143)

- [ ] **Step 1: Rewrite the diary tests:** "two people with chores today" → each person's chore is in their `bwx-mytasks-table` Today view and `bwx-mytasks-diary` does not contain it. "company date for everyone" → the date is not in the diary. The #412 chore/reminder tests → assert on the list instead of the diary, or delete where the list already covers it (recurring-ticks.spec). Keep the held-meeting test (line 375) as is — it is the positive case.
- [ ] **Step 2: Run, expect FAIL** on the chore-not-in-diary assertions.
- [ ] **Step 3: Implement** the filter.
- [ ] **Step 4: Build, rerun, expect PASS.**
- [ ] **Step 5: Commit** — `Show only meetings in Today's diary (#N)`.

### Task 4: Rules page, changelog, version

**Files:** `src/rules.ts`, `CHANGELOG.md`, `package.json`, `blueworx-forge.php`, `client/blueworx-forge-client.php`

- [ ] **Step 1:** In `src/rules.ts`: delete the planner rules (the "Today tab on My tasks is a planned day…" entry through "Overlapping meetings… counted at the top of the day"); line 458's diary sentence and line 402 become "Today's diary on My tasks shows only your meetings"; line 764's "shows in their My tasks marked as the Agent's work" becomes "shows on the Agents screen, tagged Agent, with them as who looks after it". Add one rule: what: "The Agents screen lists every task where it is the agent's turn, for the whole studio, with who looks after each. The agent's work is never on anyone's My tasks." why: "My tasks is what you do yourself; the agent's work is watched in one place."
- [ ] **Step 2:** Bump to 2.226.0 everywhere listed; changelog: "Added an Agents screen for the agent's work, which no longer appears on My tasks. Removed the day planner. Today's diary now shows meetings only."
- [ ] **Step 3:** `npm run lint` once, `npm run build`, `composer lint` — report lint findings to Luke rather than looping on them.
- [ ] **Step 4: Commit** — `Update the rules page and bump to 2.226.0 (#N)`.

### Task 5: Hand-off

- [ ] `npm run dev` and let Luke look at My tasks and Agents before the PR.
- [ ] Open a draft PR linked to `#N`: what changed, what was tested, what wasn't.
