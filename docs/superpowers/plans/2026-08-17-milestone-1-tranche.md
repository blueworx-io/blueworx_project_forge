# Milestone 1 — the first tranche

Date: 2026-08-17
Status: queued, not started
Sized for: roughly ten hours of execution
Spec: [`2026-08-17-blueworx-forge-platform-programme-design.md`](../specs/2026-08-17-blueworx-forge-platform-programme-design.md), Milestone 1
Depends on: Milestone 0, approved — `docs/architecture/decisions.md` and the three
documents beside it.

## What this tranche is

Four of Milestone 1's eight issues, in the only order they can be done in. It
stops deliberately at the point where the next issue needs a second WordPress
instance and a decision about how CI provisions it, because that is where
unattended work should stop and a human should look.

Each item is one branch and one draft pull request. Nothing merges. Nothing is
tagged.

| # | Issue | What it is | Rough size |
|---|---|---|---|
| 1 | #80 | Reboot the plugin as Blueworx Forge | 5–6 hours |
| 2 | #87 | PHPUnit and PHPCS from the first commit | 1 hour |
| 3 | #82 | The canonical REST namespace and route conventions | 2 hours |
| 4 | #81 | Split the build into two zips | 1–2 hours |

Not in this tranche, and why: #83 client-site authentication and #84 the
read-through layer both need two instances talking to each other, which is #86,
which needs a decision about CI provisioning. #85 the token layer is safe but
pointless before the two artifacts exist.

## Rules for running this unattended

- **Never merge, never tag, never push to `main`.** Draft pull requests only.
- **Stop on a blocker rather than guessing.** Leave the branch, leave the draft
  PR, and write what blocked it in the PR description. A stopped item with an
  honest note is worth more than a finished item built on a guess.
- **Every claim needs its command output.** Run
  `superpowers:verification-before-completion` before saying anything passes.
- **The decision record is the authority.** Where this plan and
  `docs/architecture/decisions.md` disagree, the record wins and this plan is
  wrong. Cite the decision ID in the code comment.
- **Do not touch the 38 pre-existing lint errors.** They are in the imported kit
  files and are Luke's call, not a cleanup to slip into a PR.
- Version bump and changelog entry on every pull request, per the shared
  guardrails.

---

## Item 1 — Reboot the plugin as Blueworx Forge (#80)

Execute [`2026-08-17-blueworx-forge-skeleton.md`](2026-08-17-blueworx-forge-skeleton.md)
exactly as written: tasks 1 to 7, in order. It is a complete, step-by-step plan
with its tests specified, and it does not need re-planning.

**Two amendments from Milestone 0, both of which override the skeleton plan:**

1. The REST namespace convention now comes from item 3 below. If task 5 lands
   first, its routes are revisited in item 3 rather than left as they are.
2. Nothing in the skeleton is client-facing yet, so ARCH-1's two-artifact split
   does not apply until item 4. Build one plugin, then split it.

**Done when.** A clean WordPress installs and activates the plugin with no PHP
notice, the app page renders, the REST namespace answers with its permission
callback, uninstall leaves nothing behind, and the four skeleton Playwright specs
pass. Version 2.0.0.

**Most likely blocker.** The removal pass. If deleting the old feature code
breaks something that turns out to be load-bearing, stop and note what — do not
reinstate it quietly.

---

## Item 2 — PHPUnit and PHPCS from the first commit (#87)

**Why now.** Before there is server-side code worth testing, so neither is
retrofitted. `composer lint` already runs PHPCS; PHPUnit does not exist yet.

**In scope.** PHPUnit wired into the project and into CI, with at least one real
passing unit test against the rebooted plugin's own code — not a placeholder
assertion. PHPCS clean against the WordPress coding standards.

**Done when.** `composer lint` and the PHPUnit suite both run in CI on every pull
request and both fail the build when they fail. A run that executes zero tests
fails, per the guardrails — a suite that skips itself is not a passing suite.

---

## Item 3 — The canonical REST namespace and route conventions (#82)

**Why it matters more than it looks.** Every later endpoint inherits whatever
this establishes, so a missing permission callback here becomes a class of bug
rather than one bug.

**In scope.**

- A versioned namespace.
- An explicit permission callback required on **every** route, enforced by a test
  that fails when a route is registered without one.
- One shared error envelope.
- The gate-failure response shape, exactly as specified in
  `docs/architecture/workflow-state-machine.md` under "Gate-failure contract" —
  including the rule that every unmet requirement is returned, not the first.
- Request versioning and idempotency keys, per ARCH-5 and the M3 requirement that
  a replayed write cannot duplicate and a stale write cannot silently win.

**Done when.** Registering a route without a permission callback fails a test,
a stale-version write is rejected with the current state returned, a replayed
write with the same idempotency key produces one record, and the conventions are
written down for later milestones to build against.

---

## Item 4 — Split the build into two zips (#81)

**In scope.** Separate build allowlists producing `blueworx-forge` and
`blueworx-forge-client` from this one repo, both published by the release
workflow, and a CI check that the client allowlist cannot admit studio code.

**Done when.** Both zips build, install and activate independently on a clean
WordPress, and adding a studio file to the client allowlist fails CI.

**Watch for.** The existing zip rules still apply and are not negotiable: build
one level above the repo, forward slashes only, bsdtar rather than
`Compress-Archive`, version in the filename and never in the folder inside. Run
the repo's own zip script rather than hand-building. Verify with `unzip -l`
before claiming either artifact works.

---

## When the tranche is done

Leave four draft pull requests open and a short note on each saying what was
verified and what still needs a human. Do not merge any of them, and do not start
#83 or #86 — they need Luke's answer on how CI provisions a second WordPress
instance, which is the first thing to put to him.
