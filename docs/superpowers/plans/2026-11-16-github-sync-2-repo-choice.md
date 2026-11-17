# GitHub sync, part 2: repo choice and issue creation — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every feature and sub-feature must have a repo chosen, or "No repo needed", before it leaves Documentation period. Choosing a repo creates the GitHub issue and links it.

**Architecture:** The repo choice is the work item column `github_repo` (added in part 1), written like any other field and checked by a new field-type requirement on the Documentation period gate. Anything Forge sends to GitHub goes through a small outbox table, so a slow or broken GitHub never stops a save: jobs run straight after the request and again every five minutes until they succeed or run out of tries.

**Tech Stack:** PHP 8 WordPress plugin, React + Vite app, PHPUnit, Playwright.

**Spec:** `docs/superpowers/specs/2026-11-16-github-issue-sync-design.md` (sections Repo choice in Documentation period, Creating the issue, the link comment under From GitHub into Forge). Issues #681 (this part) and #608. Builds on part 1 (`docs/superpowers/plans/2026-11-16-github-sync-1-connect.md`), which must be merged first.

## Global Constraints

- Branch: `github-issue-sync-2`, from `main` after part 1 is merged. Draft pull request linked to #681 (part of #608). Never auto-merge.
- Minor version bump from whatever part 1 shipped (expected 2.221.0 → 2.222.0) in `package.json`, both plugin headers and `BWX_FORGE_VERSION`. Changelog entry for the person using Forge.
- `src/rules.ts` updated in the same pull request.
- No new dependencies.
- Only features and sub-features are asked. Projects and releases (level `milestone`) never get an issue.
- If GitHub is not connected, the only choice is "No repo needed".
- Run before committing: `npm run lint`, `npm run build`, `composer lint`, `vendor/bin/phpunit`, `npm test`. Lint once, report findings.

## Review Focus

1. GitHub is down when a repo is chosen → the save succeeds, the issue appears once GitHub is back, and only one issue is ever made. Test in Task 3.
2. GitHub's "opened" message for an issue Forge just created arrives *before* Forge's own create call has returned → it links to the item, no second item in Future Idea. Test in Task 4.
3. Somebody saves the repo twice, or two people pick it at once → one issue. Test in Task 3.
4. Trying to change the repo after the issue exists → refused in plain words; "No repo needed" before the issue exists is fine. Test in Task 2.
5. A task created before this ships, already in Documentation period → it now needs the repo answer to move on; one already past it is never asked. Test in Task 1.

---

### Task 1: The Repo check on Documentation period

**Files:**
- Modify: `includes/Work/Gates.php` (new requirement and a `work_only` flag honoured in `evaluate()`), `includes/Work/Fields.php` (new group `GITHUB = array( 'github_repo' )`, included in `writable()`; not in `DEFINITION`, so clients cannot set it), `includes/Work/Validate.php` (`github_repo` rules), `tests/e2e/helpers/forge.js` (`answerFor('github_repo')` returns `'none'`), `tests/e2e/documentation-period.spec.js` (expected rows)
- Test: `tests/php/WorkGatesTest.php`, `tests/php/GitHubRepoChoiceTest.php`

**Interfaces:**
- Produces: requirement `G-DOCUMENTATION-10`, label "Repo", `self::field( 'G-DOCUMENTATION-10', 'Repo', 'enum', array( 'github_repo' ), 'Choose the repo this work belongs in, or No repo needed.' )` with `work_only => true`, listed before `G-DOCUMENTATION-9`. In `evaluate()`, skip a `work_only` requirement when the item's level is `project` or `milestone` (next to the existing `not_for_projects` / `releases_only` skips).
- Produces: `GitHub\RepoChoice::check( string $value, bool $connected, array $org_repos ): ?string` — pure; returns an error message or null. `''` and `'none'` always pass; otherwise the normalised value must be in `$org_repos` and GitHub must be connected. Messages: "Connect GitHub before choosing a repo, or choose No repo needed." and "That repo isn't in the organisation GitHub is connected to."
- `Validate::item()` calls `RepoChoice::check()` for `github_repo` with `Settings::connected()` and `Client::org_repos( Settings::org() )` (empty array on error), and normalises with `Repos::normalise()` (keeping `'none'` as is).

- [ ] **Step 1: Write failing PHP tests**:
  - `WorkGatesTest::test_documentation_asks_for_a_repo_on_work()` — `Gates::evaluate('G-DOCUMENTATION', item(level 'feature', github_repo ''), [])['unmet']` contains id `G-DOCUMENTATION-10`; with `github_repo` `'none'` it does not.
  - `WorkGatesTest::test_projects_and_releases_are_not_asked_for_a_repo()` — levels `project` and `milestone` with `github_repo` `''` → `G-DOCUMENTATION-10` absent from `all`.
  - `GitHubRepoChoiceTest::test_no_repo_needed_always_passes()` — `check('none', false, [])` null.
  - `GitHubRepoChoiceTest::test_a_repo_needs_github_connected()` — `check('blueworx-io/alpha', false, [])` returns the "Connect GitHub…" message.
  - `GitHubRepoChoiceTest::test_a_repo_outside_the_organisation_is_refused()` — `check('someone/else', true, ['blueworx-io/alpha'])` returns the second message; `check('blueworx-io/alpha', true, ['blueworx-io/alpha'])` null.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** the requirement, the flag, the field group, `RepoChoice`, the validation.
- [ ] **Step 4: Run** → PASS. Update `answerFor()` and the expected rows in `documentation-period.spec.js` to `['G-DOCUMENTATION-1', 'G-DOCUMENTATION-10', 'G-DOCUMENTATION-9']`. Run the whole Playwright suite: any spec walking work past Documentation period should now pass through `satisfy()` answering `'none'`.
- [ ] **Step 5: Write and run the failing spec** in `tests/e2e/github-repo-choice.spec.js`: `a feature cannot reach Up Next without a repo answer` — walk a feature to Documentation period, approve the documentation and fill the description only; the move to `up-next` is refused naming "Repo"; set `github_repo: 'none'`; the move succeeds. And `a release is never asked` — a `milestone` item's readiness for `up-next` has no `G-DOCUMENTATION-10`.
- [ ] **Step 6: Commit** — `Ask for a repo before work leaves Documentation period (#681)`.

### Task 2: The repo picker on the item

**Files:**
- Modify: `src/components/ItemPanel.tsx`, `includes/Rest/WorkItemsController.php` (item detail answer gains `github: {connected, link: {repo, number, url} | null, site_repos: string[], repos: string[]}` for staff; the update route refuses a locked change)
- Test: `tests/e2e/github-repo-choice.spec.js`

**Interfaces:**
- Consumes: `Links::for_item()`, `Links::url()`, `Repos::for_site()`, `Client::org_repos()`.
- Produces: update refusal `bwx_forge_github_repo_locked` (409) — "This task already has a GitHub issue, so its repo can't change. Move the issue in GitHub if it needs to go elsewhere." — when the item has a link and the sent `github_repo` differs from the link's repo.
- The picker: staff only, features and sub-features only, in the task panel next to its links (`data-testid="bwx-item-repo"`). A select with three groups in this order: the site's linked repos, every other organisation repo, then "No repo needed" (`value="none"`). Not connected → only "No repo needed". Once a link exists, the select is replaced by the issue link, `blueworx-io/alpha#12`, opening in a new tab (`data-testid="bwx-item-issue"`).

- [ ] **Step 1: Write the failing specs**:
  - `the picker lists the site's repos first` — site linked to `alpha`; org has `alpha`, `beta`; the options read `alpha`, then `beta`, then "No repo needed".
  - `without GitHub only No repo needed is offered` — disconnect; the select has exactly one option.
  - `a linked task shows its issue and its repo cannot change` — give the item a link (through Task 3's flow or a direct `Links::add` via a test route in the stub); the panel shows `blueworx-io/alpha#12`; a PATCH with `github_repo: 'blueworx-io/beta'` → 409 with the locked message.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** the detail answer, the lock and the picker (follow the `frontend-design` skill and the kit).
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** — `Choose a task's repo from its panel (#681)`.

### Task 3: The outbox, and creating the issue

**Files:**
- Modify: `includes/Data/Schema.php` (VERSION +1; table `github_jobs`), `includes/GitHub/Module.php` (five-minute schedule, `shutdown` runner)
- Create: `includes/GitHub/Jobs.php`, `includes/GitHub/Outbound.php`
- Modify: `includes/GitHub/IssueText.php` (issue body), `includes/Rest/WorkItemsController.php` (queue after a successful update), `includes/Rest/ConnectionsController.php` (GitHub row shows failures), `src/components/ConnectionsPanel.tsx`
- Test: `tests/php/GitHubJobsTest.php`, `tests/php/GitHubIssueTextTest.php`, `tests/e2e/github-create-issue.spec.js`

**Interfaces:**
- Table `github_jobs`: `id varchar(32)` PK, `item_id varchar(32)`, `kind varchar(20)`, `payload text NOT NULL`, `status varchar(10) DEFAULT 'pending'`, `attempts int(11) unsigned DEFAULT 0`, `last_error varchar(191) DEFAULT ''`, `run_after bigint(20) unsigned DEFAULT 0`, `created_at`, `updated_at`; `KEY status_run (status, run_after)`, `KEY item_id (item_id)`.
- `Jobs::id_for( string $kind, string $item_id ): string` — pure; `'ghj_' . substr( hash( 'sha256', $kind . '|' . $item_id ), 0, 28 )`. One job per kind per item: a newer request replaces the payload of a pending one.
- `Jobs::queue( string $kind, string $item_id, array $payload = array(), bool $once = false ): void` — `$once` (used for `create_issue` and `link_comment`) never re-opens a job that is already `done`; otherwise upsert to `pending`, attempts 0, `run_after` now. Also sets a flag so `shutdown` runs the queue for this request.
- `Jobs::backoff( int $attempts ): int` — pure; seconds until the next try: `min( 3600, 60 * 2 ** $attempts )`.
- `Jobs::MAX_ATTEMPTS = 8`. `Jobs::run( int $limit = 20 ): int` — takes due `pending` jobs oldest first, calls `Outbound::perform( $job )`; `true` → `done`; `WP_Error` → attempts + 1, `last_error`, `run_after = now + backoff()`, and `failed` at `MAX_ATTEMPTS`. Does nothing when GitHub is not connected.
- `Jobs::failures(): array{count:int, last_error:string}` — failed jobs, for the Connections row ("2 changes couldn't be sent to GitHub: …").
- `Outbound::perform( array $job ): true|WP_Error` — dispatches on `kind`. This part: `create_issue`, `link_comment`.
  - `create_issue`: read the item; already linked → true; item's `github_repo` is `''`/`'none'` → true (choice changed before it ran); else `POST /repos/{repo}/issues` with `title` and `IssueText::issue_body( $item )`; on success `Links::add( item, repo, number, id, 'forge', title, sha256(body) )` (a false return means the inbound hook got there first — still true).
  - `link_comment`: `POST /repos/{repo}/issues/{number}/comments` with body "Tracked in Forge: {Notify::link( $item )}".
- `IssueText::issue_body( array $item, string $forge_url ): string` — pure; the caller passes `Notify::link( $item )`. Sections in this order, each only when not empty, each `## Heading` then the text converted by `IssueText::to_markdown()`: Description (`problem`), Scope (`scope`), Requirements (`requirements`), Acceptance criteria (`acceptance_criteria`). Then a line "Tracked in Forge: {url}", then the marker `<!-- forge:{item id} -->`.
- `IssueText::to_markdown( string $html ): string` — pure; `<p>` → paragraphs, `<br>` → newline, `<li>` → `- ` lines, `<strong>`/`<b>` → `**…**`, `<a href>` → `[text](href)`, everything else stripped, entities decoded.
- `IssueText::marker_in( string $body ): string` — pure; the item id from a `<!-- forge:wrk_… -->` marker, or `''`.
- Queueing: after a successful item update in `WorkItemsController` where `github_repo` changed to a repo (not `''`/`'none'`) and `Links::for_item()` is null → `Jobs::queue( 'create_issue', id, array(), true )`.
- Module: a `bwx_forge_five_minutes` cron interval (add via `cron_schedules` if not already defined) running `Jobs::run()`; and a `shutdown` action that runs `Jobs::run( 5 )` when the request queued anything.

- [ ] **Step 1: Write failing PHP tests**:
  - `GitHubJobsTest::test_one_job_per_kind_per_item()` — `id_for('create_issue','wrk_1') === id_for('create_issue','wrk_1')`, differs from `id_for('set_label','wrk_1')`.
  - `GitHubJobsTest::test_tries_back_off_to_an_hour()` — `backoff(1) === 120`, `backoff(5) === 1920`, `backoff(6) === 3600`, `backoff(10) === 3600`.
  - `GitHubIssueTextTest::test_the_issue_body_has_its_sections_and_marker()` — item with problem `<p>Fix it</p>`, scope `''`, acceptance criteria `<ul><li>Works</li></ul>` → body contains `## Description\n\nFix it`, no `## Scope`, `## Acceptance criteria\n\n- Works`, `Tracked in Forge: https://x/#item=wrk_1`, and ends with `<!-- forge:wrk_1 -->`.
  - `GitHubIssueTextTest::test_html_becomes_markdown()` — `<p>A <strong>b</strong> <a href="https://c">d</a></p><p>E<br>F</p>` → `A **b** [d](https://c)\n\nE\nF`.
  - `GitHubIssueTextTest::test_the_marker_is_found()` — `marker_in("x\n<!-- forge:wrk_abc -->")` → `wrk_abc`; plain text → `''`.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** the table, `Jobs`, `Outbound` (two kinds), `IssueText` additions, the queueing, the schedules.
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Write the failing spec** `github-create-issue.spec.js` (stub connected; site linked to `alpha`; extend the stub so `POST /repos/{repo}/issues` answers `{number, id, html_url}` with increasing numbers and logs the call):
  - `choosing a repo creates one issue that links back` — set `github_repo` to `blueworx-io/alpha`; the stub logged one `POST /repos/blueworx-io/alpha/issues` whose body contains the item's description and its Forge link; the item panel shows `blueworx-io/alpha#1`.
  - `saving the repo again makes no second issue` — PATCH the same value again, and a different field → still one create call.
  - `No repo needed makes no issue` — stub logs no create call.
  - `a GitHub that is down still saves, and catches up` — stub `down: true`; the PATCH answers 200; no link yet; the Connections row shows a failure after the job tries; stub back up; run the queue (a test route in the stub calling `Jobs::run()`, standing in for cron) → one issue, linked.
- [ ] **Step 6: Run** → PASS.
- [ ] **Step 7: Commit** — `Create the GitHub issue when a task's repo is chosen (#681)`.

### Task 4: Issues Forge made, and issues people made, link up properly

**Files:**
- Modify: `includes/GitHub/Inbound.php`
- Test: `tests/e2e/github-create-issue.spec.js`, `tests/e2e/github-inbound.spec.js`

**Interfaces:**
- Consumes: `IssueText::marker_in()`, `Links`, `Jobs::queue()`.
- Changes to `opened`, checked in this order, before the part 1 rules:
  1. The body carries a Forge marker for an item that exists → if that item has no link, `Links::add( …, 'forge', … )` and answer `linked`; if it has one, answer `duplicate`. Never create an item. (This is the race where GitHub's message beats Forge's own create call.)
  2. Otherwise the part 1 rules. After an item is `created`, queue `Jobs::queue( 'link_comment', item id, array(), true )`.
- Forge never edits the body of an issue a person wrote; the link comment is the only comment Forge ever posts.

- [ ] **Step 1: Write the failing specs**:
  - In `github-create-issue.spec.js`: `GitHub's message about our own issue links it, never a new task` — stub set to delay recording the link (or: send the `opened` hook with the marker for the item *before* running the queue); outcome `linked`; then run the queue → the create job sees the link, no second issue; Future Idea has no new item.
  - In `github-inbound.spec.js`: `an issue raised in GitHub gets one comment linking to Forge` — after `created`, run the queue → the stub logged exactly one `POST …/issues/7/comments` containing the item link, and no `PATCH` to the issue.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement**.
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** — `Link Forge's own issues back, and comment once on issues raised in GitHub (#681)`.

### Task 5: Rules, version and changelog

**Files:**
- Modify: `src/rules.ts`, `CHANGELOG.md`, `package.json`, `blueworx-forge.php`, `client/blueworx-forge-client.php`

- [ ] **Step 1: Add rules** to the `github` section: a feature or sub-feature needs a repo, or No repo needed, before it leaves Documentation period (why: every build starts from a GitHub issue, and not every task is a build); choosing a repo creates the issue straight away (why: so the work can start from it); once the issue exists the repo can't change (why: moving issues is GitHub's job); Forge comments once on an issue raised in GitHub with a link back, and never edits what the person wrote (why: their words stay theirs); if GitHub is down, changes wait and are retried, and the Connections page shows any that failed (why: a slow GitHub must never stop work being saved).
- [ ] **Step 2: Bump the minor version** in all four places; changelog under `### Added`: "Tasks now ask which GitHub repo they belong in before they leave Documentation period, or you can choose No repo needed. Choosing a repo creates the GitHub issue and links it to the task."
- [ ] **Step 3: Run every check** → all pass.
- [ ] **Step 4: Commit** — `Version for repo choice (#681)`. Draft pull request linked to #681 (part of #608), saying what wasn't tested (a real GitHub; the stub stands in).
