# Workflow tidy (#544–#550, #520) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eight stacked draft PRs, one per issue, each with a version bump, changelog line, rules-page update and a Playwright test. Nothing merges.

**Architecture:** The gates (`includes/Work/Gates.php`), the forward table (`includes/Work/Transitions.php`) and the stage list (`includes/Work/Stages.php`) are the source of truth; the task panel (`src/components/ItemPanel.tsx`) and the board read from them over REST, so most changes are one PHP edit plus the copy on the rules page. The e2e helper `tests/e2e/helpers/forge.js` answers whatever a gate asks, so a new requirement needs an answer there too.

**Tech Stack:** PHP 8 (WordPress REST), React + Vite, Playwright, PHPUnit.

**Spec:** issues #544–#550 and #520 on GitHub.

## Global Constraints

- Version bumps are minor, one per PR, in `package.json`, `blueworx-forge.php` and `client/blueworx-forge-client.php` (header and constant). Changelog entry per PR.
- `src/rules.ts` changes with the behaviour, in the same PR.
- Every PR gets a Playwright spec. PHP unit tests cover the gate and table changes.
- Branches stack: each off the one before. Order below.
- Lint once at the very end.

## Review Focus

1. A task with no designer must never be offered Design Process, forwards or backwards.
2. Tasks sitting in Technical Audit on an existing site end up in Up Next with a history line, once.
3. An auto-saved date out of order is refused and the old value is kept.
4. A send-back with an empty box is refused and nothing moves.
5. The e2e helper still walks every spec's path after the stages change.

---

### Task 1: #545 Drop Affected sites and data (branch `drop-affected-sites`, 2.185.0)

- Remove `G-DOCUMENTATION-7` and `AFFECTED` from `Gates.php`.
- `rules.ts` line "To leave Documentation Period…" drops "which sites it affects".
- PHPUnit: WorkGatesTest asserts G-DOCUMENTATION only has description and approval.
- Playwright `documentation-period.spec.js`: walk to Documentation Period, record the approval, move to the next stage without any pick.

### Task 2: #550 How to test it before review (branch `test-description-required`, 2.186.0)

- `Gates.php` G-IN-DEVELOPMENT adds `field('G-IN-DEVELOPMENT-2', 'How to test it', 'text', ['test_description'], …, PU)`.
- `ItemPanel.tsx` NEEDED_FROM adds `test_description: 'in-development'`.
- `rules.ts` "To go to In Review…" says How to test it is needed; Test steps optional.
- PHPUnit + Playwright `test-description-gate.spec.js` (refused empty; moves when filled).

### Task 3: #547 All dates before leaving Up Next (branch `all-dates-up-next`, 2.187.0)

- `Gates.php` G-UP-NEXT adds review_target (G-UP-NEXT-10), release_target (G-UP-NEXT-11), design_target (G-UP-NEXT-12, `'unless' => 'no-designer'`). `evaluate()` skips `unless` rows when the item has no designer.
- `Fields::REQUIRED_FROM` and panel `NEEDED_FROM` add the three dates at `up-next`.
- `DATES` in the panel: `needed: 'up-next'` on all.
- Helper `satisfy()`: when a date is asked for, answer relative to the item's Deliver by (design −1 day, review +1, release +2).
- `rules.ts` "To leave Up Next…" lists the dates.
- PHPUnit + Playwright `up-next-dates.spec.js` (designer and no designer).

### Task 4: #548 Skip Design Process with no designer (branch `skip-design-no-designer`, 2.188.0)

- `Transitions.php`: FORWARD `up-next` has both `design-process` (`needs: designer`) and `in-development` (`needs: no-designer`), gate G-UP-NEXT. `allowed()`/`next_from()` take `bool $has_designer = true`; every caller passes `'' !== designer_id`.
- `Returns::targets()` leaves out `design-process` when no designer.
- Helper `walkTo()` skips `design-process` for an item with no designer.
- `rules.ts` journey line and the Up Next line say so.
- PHPUnit (WorkTransitionsTest, WorkReturnsTest) + Playwright `skip-design.spec.js`.

### Task 5: #546 Remove Technical Audit (branch `remove-technical-audit`, 2.189.0)

- `Stages.php` ALL/label; `Transitions.php` docs→up-next with G-DOCUMENTATION; `Gates.php` drop G-TECHNICAL-AUDIT.
- `Schema::VERSION` 41 and `Work\TechnicalAuditUpgrade::run()`: stage and prior_stage `technical-audit` → `up-next`, one history line each.
- Front end: `phases.ts`, `ReportsScreen.tsx`, `kit/primitives.tsx`, `kit/Gallery.tsx`, `rules.ts` (journey, technical approval lines 46/558/570).
- Tests: strip `'technical-audit'` from every e2e/pair/PHP list; hand-fix the specs that assert on it; seed script.
- Playwright `technical-audit-gone.spec.js`: stages route has no such stage; docs → up-next in one move.

### Task 6: #549 One feedback box (branch `one-feedback-box`, 2.190.0)

- Panel send-back form: Back to + one textarea "Feedback" (`bwx-return-feedback`), always shown, required; sends `reason` and `feedback` both as that text.
- Server message for an empty reason reads "Say what needs to change before sending it back."
- `rules.ts` send-back line.
- Playwright `send-back-feedback.spec.js`.

### Task 7: #544 Auto-save picks, dates and lists (branch `auto-save-panel`, 2.191.0)

- Panel: `saveNow(patch)` queued like the checklist; selects (who pays, priority, seats, hours), dates (change when set, blur when cleared), test steps and gate picks save on their own. A refusal shows under the field (`bwx-field-error`) and the saved value comes back.
- `rules.ts` line 62.
- Playwright `auto-save.spec.js`: a drop-down, a date, a checklist line, and a refused date.

### Task 8: #520 Capacity panel headers (branch `capacity-panel-headers`, 2.192.0)

- `.bwx-capacity-section` becomes an eyebrow header with a rule and the hours on the right; `WorkList` renders `data-testid="<id>-head"`.
- Playwright `capacity-panel-headers.spec.js`.
- Built without Claude Design: flagged in the PR.
