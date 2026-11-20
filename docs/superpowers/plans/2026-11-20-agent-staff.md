# Agent as a member of staff â€” Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An "Agent" person sits in task seats so its build hours land on its own capacity row, with the deliverer owning the task while it is the agent's turn.

**Architecture:** The agent is an ordinary Forge person (users table) with a new `is_agent` flag, created once by a schema step with a 12h Monâ€“Fri pattern. Capacity, pickers and the People screen already handle people. New code is one class, `Tenancy\Agents`, holding the pure rules (stand-in, seat refusals), plus small hooks where a seat is turned into "whose turn": `Rest\Access::build`, `Standup\Rules`, `Slack\Notify`, `PersonReach`, and `src/turn.ts`.

**Tech Stack:** PHP 8 / WordPress REST, PHPUnit (no WP runtime), React + TS, Playwright.

**Spec:** `docs/superpowers/specs/2026-11-20-agent-staff-design.md`

## Global Constraints

- Agent hours: 12 on Monâ€“Fri, 0 on Sat and Sun.
- Agent email: `agent@blueworx-forge.invalid` (`.invalid` can never deliver).
- Display name: `Agent`.
- Agent can never be the deliverer. Any agent seat needs a non-agent deliverer.
- Refusal copy, exactly:
  - deliverer is the agent: `The agent can't deliver work. Pick a person to deliver it.`
  - agent in a seat, no deliverer: `With the agent on this task, pick a person to deliver it. They look after it while the agent has it.`
- Schema version 56 â†’ 57. Plugin version 2.224.0 â†’ 2.225.0 (minor), in `package.json`, `blueworx-forge.php` header and version constant, and `client/blueworx-forge-client.php`. CHANGELOG entry and a line in `src/rules.ts`.
- No new dependencies.

## Review Focus

1. Agent in the reviewer seat with the client nowhere: the deliverer must be able to approve the review (Access hands them `assigned_reviewer`). Test in Task 3.
2. A partial edit that only sets `primary_user_id` to the agent on a task that already has a deliverer must save (refusals read the merged item). Test in Task 2.
3. A site upgraded twice, or where someone deleted the agent: `ensure()` must not create a second one when one exists, active or inactive. Test in Task 1.
4. Agent as a seat on a client the agent has no membership for: the save must not be refused for reach. Test in Task 3.
5. Comments and seat-assignment Slack messages must never be sent to the agent. Test in Task 4.

---

### Task 1: The agent person

**Files:**
- Create: `includes/Tenancy/Agents.php`
- Modify: `includes/Data/Schema.php` (users table: `is_agent tinyint(1) NOT NULL DEFAULT 0` after `wp_user_id`; `VERSION = 57`; in `maybe_upgrade`, `if ( null === $installed || $installed < 57 ) { \Blueworx\Forge\Tenancy\Agents::ensure(); }`)
- Modify: `includes/Tenancy/Users.php` (`create` accepts `is_agent`; `hydrate` returns `'is_agent' => (bool) (int) ( $row['is_agent'] ?? 0 )`)
- Modify: `includes/Rest/UsersController.php` (the People list rows and `people()` include `is_agent`)
- Modify: `src/types.ts` (`Person.is_agent?: boolean`)
- Test: `tests/php/AgentsTest.php`

**Interfaces:**
- Produces, all on `final class Agents` in `Blueworx\Forge\Tenancy`:
  - `const EMAIL = 'agent@blueworx-forge.invalid'`, `const NAME = 'Agent'`, `const HOURS_PER_DAY = 12.0`
  - `public static function default_hours(): array<string, float>`: pure, keyed by `Patterns::day_columns()`.
  - `public static function ensure(): void`: creates the agent person (`is_agent` 1, no login) and records `default_hours()` from today, only when no row has `is_agent = 1` in any status.
  - `public static function ids(): array<int, string>`: ids of every person with `is_agent` set, cached per request.
  - `public static function is( string $id ): bool`: in `ids()`.

- [ ] **Step 1: Write the failing test.** `test_default_hours_are_twelve_on_weekdays_and_none_at_weekends`: assert `hours_mon`..`hours_fri` are `12.0` and `hours_sat`/`hours_sun` are `0.0`, seven keys total.
- [ ] **Step 2:** `vendor/bin/phpunit --filter AgentsTest`. Expect FAIL, class not found.
- [ ] **Step 3:** Implement `Agents`, the column, the upgrade step, and `Users`/`UsersController`/`types.ts` changes.
- [ ] **Step 4:** `vendor/bin/phpunit --filter AgentsTest`. Expect PASS.
- [ ] **Step 5:** Commit `Add the Agent as a person (#689)`.

### Task 2: Seat rules and the stand-in

**Files:**
- Modify: `includes/Tenancy/Agents.php`
- Modify: `includes/Rest/WorkItemsController.php` (create ~line 1669 and update ~line 1798: merge `Agents::seat_refusals( <merged item>, Agents::ids() )` into the errors, using `$checked['values']` on create and `array_merge( $item, $checked['values'] )` on update)
- Test: `tests/php/AgentsTest.php`

**Interfaces:**
- Produces:
  - `public static function seat_refusals( array $item, array $agent_ids ): array<string, string>`: pure. Returns `['deliverer_id' => <copy>]` when the deliverer is an agent, or when any of `primary_user_id`, `designer_id`, `reviewer_id` is an agent and the deliverer is empty. Otherwise `[]`.
  - `public static function stand_in( array $item, string $field, array $agent_ids ): string`: pure. Returns `$item['deliverer_id']` when `$item[$field]` is an agent, else `$item[$field]`.

- [ ] **Step 1: Write the failing tests:**
  - `test_agent_cannot_deliver`: `seat_refusals(['deliverer_id'=>'usr_a'], ['usr_a'])` returns the deliverer copy exactly.
  - `test_agent_seat_needs_a_deliverer`: `['primary_user_id'=>'usr_a','deliverer_id'=>'']` returns the second copy exactly.
  - `test_agent_seat_with_a_deliverer_is_fine`: `['primary_user_id'=>'usr_a','deliverer_id'=>'usr_b']` returns `[]`.
  - `test_no_agent_no_refusal`: `['primary_user_id'=>'usr_x','deliverer_id'=>'']` returns `[]`.
  - `test_stand_in_hands_the_agent_seat_to_the_deliverer`: `stand_in(['primary_user_id'=>'usr_a','deliverer_id'=>'usr_b'], 'primary_user_id', ['usr_a'])` is `'usr_b'`; with `usr_x` in the seat, `'usr_x'`.
- [ ] **Step 2:** Run. Expect FAIL.
- [ ] **Step 3:** Implement both, and wire `seat_refusals` into the controller.
- [ ] **Step 4:** Run. Expect PASS.
- [ ] **Step 5:** Commit `Refuse an agent task with nobody to deliver it (#689)`.

### Task 3: The deliverer acts for the agent

**Files:**
- Modify: `includes/Rest/Access.php` (`build`: `assigned_primary_user` and `assigned_reviewer` also true when the seat holds an agent and `$user_id` is the deliverer; use `Agents::stand_in`)
- Modify: `includes/Tenancy/PersonReach.php` (`reaches()` returns true at the top for a person with `is_agent`, so seat saves and `staff_on_site` include the agent on every site)
- Modify: `includes/Standup/Rules.php` (lines ~278, 313: `waiting_on` uses `Agents::stand_in( $item, 'reviewer_id', Agents::ids() )`; same for any primary/designer `waiting_on`)
- Test: `tests/php/AgentsTest.php`, `tests/php/CapabilitiesTest.php` if Access is reachable there; otherwise covered by e2e in Task 5.

**Interfaces:**
- Consumes: `Agents::stand_in`, `Agents::ids`, `Agents::is` (Tasks 1â€“2).

- [ ] **Step 1: Write the failing test** for the reach rule: a pure helper on `PersonReach::reaches` given `['is_agent'=>true]` and no memberships returns true.
- [ ] **Step 2:** Run. Expect FAIL.
- [ ] **Step 3:** Implement Access, PersonReach and Standup changes.
- [ ] **Step 4:** Run `vendor/bin/phpunit`. Expect all PASS.
- [ ] **Step 5:** Commit `Let the deliverer act while the agent has the task (#689)`.

### Task 4: Notifications and the front end

**Files:**
- Modify: `includes/Slack/Notify.php` (`seat_changes` and `commented` skip agent ids; `moved()` at in-review sends to `Agents::stand_in( $item, 'reviewer_id', â€¦ )`)
- Modify: `includes/Frontend.php` (add `'agentIds' => Tenancy\Agents::ids()` to `$data`)
- Modify: `src/types.ts` (`ForgeData.agentIds?: string[]`)
- Modify: `src/turn.ts` (`responsible()`: when the chosen seat's holder is in `window.bwxForgeData?.agentIds`, return `'deliverer'`. `waitingOn` follows from it.)
- Modify: `src/rules.ts` (one rule: the agent's hours are its own; while it has a task, the deliverer looks after it)
- Test: `tests/php/AgentsTest.php` (`Notify::seat_changes` with `'primary_user_id' => <agent>` returns no change for that seat)

- [ ] **Step 1: Write the failing test** above.
- [ ] **Step 2:** Run. Expect FAIL.
- [ ] **Step 3:** Implement. `seat_changes` takes an optional `array $agent_ids = null` defaulting to `Agents::ids()` so the test can pass ids without a database.
- [ ] **Step 4:** `vendor/bin/phpunit` and `npm run build`. Expect PASS and a clean build.
- [ ] **Step 5:** Commit `Send the agent's turn to the deliverer (#689)`.

### Task 5: End-to-end, version and changelog

**Files:**
- Create: `tests/e2e/agent-seat.spec.js`
- Modify: `package.json`, `blueworx-forge.php`, `client/blueworx-forge-client.php`, `CHANGELOG.md`

- [ ] **Step 1: Write the spec** (one `test` per bullet, following `capacity-handed-over.spec.js`):
  - `GET /users` lists exactly one person with `is_agent: true`, named `Agent`.
  - `GET /capacity/person?user_id=<agent>` for a Monâ€“Sun week: 12 available on each weekday, 0 on Sat and Sun.
  - PATCH a task with `primary_user_id: <agent>` and no deliverer: 400, `deliverer_id` error carries the spec copy. With `deliverer_id: <agent>`: 400. With a staff deliverer: 200.
  - With the agent as primary (3h) and a staff deliverer, the deliverer's capacity commitments don't include those 3h, and the agent's do.
  - Signed in as that deliverer, My tasks lists the task.
- [ ] **Step 2:** `npm run wp:up`, then `npx playwright test tests/e2e/agent-seat.spec.js --workers=1`. Expect PASS. (A fresh `.wp-test` gets the agent from the upgrade step.)
- [ ] **Step 3:** Bump to 2.225.0 in all three places, add the CHANGELOG entry: "You can now put the Agent on a task. Its hours show on its own row in capacity, not yours, and the deliverer looks after the task while the agent has it."
- [ ] **Step 4:** Final checks once: `npm run lint`, `npm run build`, `composer lint`, `vendor/bin/phpunit`.
- [ ] **Step 5:** Commit `Test the agent end to end and bump to 2.225.0 (#689)`.
