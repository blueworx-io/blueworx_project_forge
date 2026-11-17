# GitHub sync, part 3: keeping them matching — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Once a task and an issue are linked, the issue shows the Forge stage as a label, closes and reopens with the task, and title and description edits cross over as the design says. Closing the issue in GitHub moves the task to In review when Forge allows it, or leaves a note when it doesn't.

**Architecture:** Two new WordPress actions fire after an item is saved and after it moves stage. `GitHub\Sync` listens and queues outbox jobs (part 2's `Jobs`). The inbound handler learns the `edited`, `closed` and `reopened` actions. Echoes are stopped by comparing content, not by who sent the message: a title Forge already has, a body Forge already wrote, a close on a task that is already closed, all do nothing. Senders can't be used because Luke and Claude raise issues from the same blueworx-io account Forge uses.

**Tech Stack:** PHP 8 WordPress plugin, PHPUnit, Playwright.

**Spec:** `docs/superpowers/specs/2026-11-16-github-issue-sync-design.md` (section Keeping them matching). Issues #682 (this part) and #608. Builds on parts 1 and 2, which must be merged first.

## Global Constraints

- Branch: `github-issue-sync-3`, from `main` after part 2 is merged. Draft pull request linked to #682 (part of #608). Never auto-merge.
- Minor version bump and changelog entry; `src/rules.ts` updated in the same pull request.
- No new dependencies.
- Nothing from GitHub ever moves a task past In review, and the reviewer rule is never worked around.
- Comments never sync. The only comments Forge writes in GitHub are part 2's link comment.
- Forge writes internal comments only (never client-visible) when it notes something from GitHub.
- Run before committing: `npm run lint`, `npm run build`, `composer lint`, `vendor/bin/phpunit`, `npm test`. Lint once, report findings.

## Review Focus

1. Forge changes the title, GitHub sends "edited" back → nothing changes, no new version of the task, no comment. Test in Task 3.
2. Forge releases a task and closes the issue, GitHub sends "closed" back → no comment, no move. Forge reopens and GitHub sends "reopened" back → no comment. Test in Task 4.
3. A task moves several stages quickly while GitHub is down → when GitHub is back, the issue carries only the latest stage label, not a pile of them. Test in Task 2.
4. Someone else's labels on the issue (`bug`, `help wanted`) survive every stage change. Test in Task 2.
5. The issue is closed in GitHub while the task is still in Up Next → the stage stays put and one internal comment explains. Test in Task 4.

---

### Task 1: Tell the rest of Forge when a task changes

**Files:**
- Modify: `includes/Work/Items.php` (`update()`), `includes/Work/Transition.php` (`commit()`)
- Test: `tests/php/WorkTransitionsTest.php` or a new `tests/php/ItemActionsTest.php` if the existing tests can't reach `commit()` without a database; otherwise Playwright in Task 2 covers it.

**Interfaces:**
- Produces: `do_action( 'bwx_forge_item_updated', array $before, array $after )` at the end of a successful `Items::update()` that wrote something (not when there was nothing to write, not on a version mismatch). `do_action( 'bwx_forge_item_moved', array $before, array $after )` in `Transition::commit()` right after the existing `Notify::moved( $moved_item )` — after `COMMIT`, so a slow listener never holds the move open. `$before` is the item as passed in.

- [ ] **Step 1: Add both actions** with a one-line comment each on why they sit where they do.
- [ ] **Step 2: Run the full PHPUnit suite and Playwright suite** → unchanged results.
- [ ] **Step 3: Commit** — `Announce task saves and moves to listeners (#682)`.

### Task 2: Stage labels, closing and reopening from Forge

**Files:**
- Create: `includes/GitHub/Sync.php`
- Modify: `includes/GitHub/Module.php` (register `Sync`), `includes/GitHub/Outbound.php` (kinds `set_label`, `set_state`), `tests/support/github-stub.php` (issue labels and state)
- Test: `tests/php/GitHubSyncTest.php`, `tests/e2e/github-matching.spec.js`

**Interfaces:**
- Consumes: `Links::for_item()`, `Jobs::queue()`, `Stages::label()`, `Outcomes::is_closed()`.
- Produces:
  - `Sync::LABEL_PREFIX = 'forge: '`; `Sync::label_for( string $stage ): string` — pure; `'forge: ' . Stages::label( $stage )`, e.g. `forge: Up next`.
  - `Sync::state_for( array $item ): array{state: string, reason: string}` — pure; Released → `closed`/`completed`; a closing outcome (`Outcomes::is_closed()` true: cancelled, rejected, duplicate, deferred) → `closed`/`not_planned`; anything else → `open`/`''`.
  - `Sync::moved( array $before, array $after ): void` — no link → nothing. Stage changed → `queue( 'set_label', id, ['stage' => after stage] )`. `state_for( $before ) !== state_for( $after )` → `queue( 'set_state', id, state_for( $after ) )`.
  - `Outbound` `set_label`: `GET /repos/{repo}/issues/{n}` for its labels; `DELETE …/labels/{name}` for each starting with `forge: ` that isn't the wanted one; `POST …/labels` with the wanted one if missing. Labels that don't exist yet are created by GitHub on first use. Reads the stage from the payload, which is always the latest because a pending job's payload is replaced.
  - `Outbound` `set_state`: `PATCH /repos/{repo}/issues/{n}` with `state` and, when closing, `state_reason`.

- [ ] **Step 1: Write failing PHP tests**:
  - `test_the_label_names_the_stage()` — `label_for('up-next') === 'forge: Up next'` (use whatever `Stages::label()` returns for `up-next`; assert the prefix and that it equals `'forge: ' . Stages::label('up-next')`).
  - `test_released_closes_as_completed()` — `state_for(['stage'=>'released','terminal_outcome'=>''])` → `['state'=>'closed','reason'=>'completed']`.
  - `test_a_closing_outcome_closes_as_not_planned()` — `terminal_outcome` `cancelled` → `closed`/`not_planned`.
  - `test_anything_else_is_open()` — `in-development`, no outcome → `open`/`''`.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** `Sync`, the two outbound kinds, registration.
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Write the failing spec** `github-matching.spec.js` (a linked task, from part 2's flow; the stub keeps each issue's labels and state, and seeds the issue with label `bug`):
  - `the issue shows the stage as one label` — move the task to Up Next, run the queue → issue labels are `bug` and `forge: Up next` only.
  - `quick moves while GitHub is down leave only the latest label` — stub down; move two stages; stub up; run the queue → one `forge:` label, the latest stage.
  - `releasing closes the issue as completed` — walk to Released, run the queue → state `closed`, reason `completed`.
  - `cancelling closes it as not planned` — end a task as cancelled → `closed`, `not_planned`.
  - `reopening the task reopens the issue` — reopen a released task → `open`.
- [ ] **Step 6: Run** → PASS.
- [ ] **Step 7: Commit** — `Show the Forge stage on the issue, and close and reopen it with the task (#682)`.

### Task 3: Titles and descriptions

**Files:**
- Modify: `includes/GitHub/Sync.php` (`updated()`), `includes/GitHub/Outbound.php` (kinds `set_title`, `set_body`), `includes/GitHub/Inbound.php` (`edited`)
- Test: `tests/php/GitHubSyncTest.php`, `tests/e2e/github-matching.spec.js`

**Interfaces:**
- Produces:
  - `Sync::BODY_FIELDS = array( 'problem', 'scope', 'requirements', 'acceptance_criteria' )`.
  - `Sync::updated( array $before, array $after ): void` — no link → nothing. Title changed and differs from `link.title_seen` → `queue( 'set_title', id )`. Link origin `forge` and any `BODY_FIELDS` changed → `queue( 'set_body', id )`. Origin `github` → never `set_body`.
  - `Outbound` `set_title`: `PATCH` with the item's current title; then `Links::seen( id, ['title_seen' => title] )`.
  - `Outbound` `set_body`: build `IssueText::issue_body( item, Notify::link( item ) )`; if `sha256` equals `link.body_hash` → true without calling; else `PATCH` body, then `Links::seen( id, ['body_hash' => hash] )`.
  - `Inbound` `edited` (find the item with `Links::for_issue( issue.id )`; no link → `ignored`):
    - `changes.title` present and `issue.title !== item title` → `Items::update( id, ['title' => IssueText::title( issue.title )], version )` and `Links::seen( title_seen )`; outcome `updated`. Equal → nothing.
    - `changes.body` present, origin `github`, `sha256( issue.body ) !== link.body_hash` → `Items::update( id, ['problem' => IssueText::to_html( body )] )`, `Links::seen( body_hash )`.
    - `changes.body` present, origin `forge`, hash differs from `link.body_hash` → internal comment (author `Settings::actor()`, `author_name` "GitHub", visibility internal): "The issue's description was changed in GitHub by {sender.login}. Forge kept its own; copy anything you need across." Then `Links::seen( body_hash )` so the same edit isn't noted twice.
    - A version mismatch on `Items::update` → read the item again and retry once; still failing → outcome `failed` (GitHub's retry will try again).

- [ ] **Step 1: Write failing PHP tests**:
  - `test_a_title_forge_already_sent_is_not_sent_again()` — with a `Links` stub returning `title_seen` "New", `updated( before title "Old", after title "New" )` queues nothing. (If `Links` needs the database, make `Sync::wanted( array $before, array $after, ?array $link ): array<int,string>` pure — the job kinds to queue — and test that instead; `updated()` calls it.)
  - `test_github_born_issues_never_get_their_body_written()` — `wanted( problem changed, link origin 'github' )` excludes `set_body`.
  - `test_forge_born_issues_get_their_body_rewritten()` — origin `forge`, `scope` changed → includes `set_body`.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement**.
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Write the failing specs** in `github-matching.spec.js`:
  - `renaming the task renames the issue, and the echo changes nothing` — rename in Forge, run the queue → stub issue title matches; send the `edited` hook GitHub would send back → outcome not `updated`; the task's `record_version` is unchanged.
  - `renaming the issue renames the task` — send `edited` with a new title → the task's title matches.
  - `editing a GitHub-raised issue's description updates the task` — on an item from part 1's flow → description matches.
  - `editing a Forge-made issue's description leaves a note, once` — send `edited` with a new body twice (two deliveries) → one internal comment, the task's description unchanged.
- [ ] **Step 6: Run** → PASS.
- [ ] **Step 7: Commit** — `Keep titles and descriptions matching (#682)`.

### Task 4: Closing and reopening in GitHub

**Files:**
- Modify: `includes/GitHub/Inbound.php` (`closed`, `reopened`), `includes/Work/Events.php` (if the history needs words for a GitHub move)
- Test: `tests/php/GitHubInboundTest.php`, `tests/e2e/github-matching.spec.js`

**Interfaces:**
- Consumes: `Transition::move()`, `Sync::state_for()`, `Comments::add()`, `Events::VIA_GITHUB`.
- Produces: `Inbound::on_close( array $item, string $reason ): string` — pure decision returning `move`, `note` or `nothing`:
  - `state_for( $item )['state'] === 'closed'` → `nothing` (Forge closed it; this is the echo).
  - Stage is `in-review` or later (`in-review`, `completed`, `released`) → `nothing`.
  - `$reason === 'not_planned'` → `note`.
  - Stage `in-development` → `move`.
  - Anything else → `note`.
- `closed` handling: `move` → `Transition::move( item, 'in-review', version, Settings::actor(), Events::VIA_GITHUB )`; a `WP_Error` (the In development check isn't met, or any other refusal) → `note` instead. `note` → internal comment: "The issue was closed in GitHub by {sender.login}{ as not planned}. Forge has left this task where it is." Outcome `moved`, `noted` or `nothing`.
- `reopened` handling: the task is closed or released in Forge → internal comment "The issue was reopened in GitHub by {sender.login}. Forge has left this task where it is." Otherwise `nothing` (Forge reopened it; this is the echo).

- [ ] **Step 1: Write failing PHP tests** for `on_close`:
  - `test_closing_in_development_moves_to_review()` — stage `in-development`, reason `completed` → `move`.
  - `test_closing_earlier_leaves_a_note()` — `up-next` → `note`.
  - `test_not_planned_only_ever_notes()` — `in-development`, `not_planned` → `note`.
  - `test_closing_after_forge_closed_does_nothing()` — `released` → `nothing`; `terminal_outcome` `cancelled` → `nothing`.
  - `test_closing_in_review_does_nothing()` — `in-review` → `nothing`.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement**.
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Write the failing specs** in `github-matching.spec.js`:
  - `closing the issue moves finished development to review` — walk a linked task to In development with its check met; send `closed` (reason `completed`) → stage `in-review`; its history shows the move came from GitHub.
  - `closing the issue early leaves one note` — task in Up Next; send `closed` → stage unchanged; one internal comment; the client's view of the task shows no such comment.
  - `the echo of Forge's own close says nothing` — release a task, run the queue, send the `closed` hook back → no new comment.
  - `reopening in GitHub after release leaves a note; Forge's own reopen doesn't` — send `reopened` on a released task → one comment; reopen a task in Forge, send `reopened` back → no comment.
- [ ] **Step 6: Run** → PASS.
- [ ] **Step 7: Commit** — `Closing an issue in GitHub moves finished work to review (#682)`.

### Task 5: Rules, version and changelog

**Files:**
- Modify: `src/rules.ts`, `CHANGELOG.md`, `package.json`, `blueworx-forge.php`, `client/blueworx-forge-client.php`, `docs/github-setup.md` (what each side changes)

- [ ] **Step 1: Add rules** to the `github` section: the issue carries one `forge:` label naming the stage (why: anyone in GitHub can see where the work is); releasing closes the issue, ending a task any other way closes it as not planned, and reopening the task reopens it (why: Forge decides the stage); closing the issue in GitHub moves work from In development to In review, never further, and otherwise leaves a note (why: only the reviewer can release work); title edits cross both ways; description edits from GitHub only reach tasks that came from GitHub (why: a Forge task's description is several fields, and one block of text can't safely overwrite them); comments never sync.
- [ ] **Step 2: Bump the minor version**; changelog under `### Added`: "Linked GitHub issues now stay in step with their tasks: the issue shows the Forge stage, closes when the work is released and reopens with it, and titles match on both sides. Closing an issue in GitHub moves finished development to In review."
- [ ] **Step 3: Run every check** → all pass.
- [ ] **Step 4: Commit** — `Version for keeping GitHub in step (#682)`. Draft pull request linked to #682 (part of #608), saying what wasn't tested (a real GitHub; the stub stands in).
