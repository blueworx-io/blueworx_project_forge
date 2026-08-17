# Forge Platform Milestone 0 — Foundation Decisions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Answer all 47 open product and architecture decisions from the brief, and produce the three documents the brief's ready-for-design gate requires, so no later milestone is built on a guess.

**Architecture:** This milestone ships documents, not features. Its deliverables are a machine-checked decision record (`docs/architecture/decisions.md`), three derived design documents, and a completeness checker wired into CI so Milestone 1 cannot start against an incomplete record. Each decision task drafts a proposed answer, puts it to Luke, records the approved answer verbatim, and commits.

**Tech Stack:** Markdown for the record; one dependency-free Node script (`bin/check-decisions.mjs`) as the completeness gate; the existing GitHub Actions CI caller runs it.

**Spec:** [`docs/superpowers/specs/2026-08-17-blueworx-forge-platform-programme-design.md`](../specs/2026-08-17-blueworx-forge-platform-programme-design.md)

**Source of the questions:** [`design/research/brief.txt`](../../../design/research/brief.txt) §17, plus four additions this plan identifies (`ARCH-3`, `ARCH-4`, `ARCH-6`, `AUTH-4`).

## Global Constraints

- The record is append-only in spirit: a reversed decision is recorded as a new
  dated revision under the same ID, never an edit that erases the original.
- Every decision entry has exactly four parts: **Question**, **Options
  considered**, **Decision**, **Consequence if reversed**. The checker enforces
  all four.
- Decision IDs are stable and are the citation used by later specs, plans, code
  comments and tests. Never renumber.
- Proposed answers in this plan are proposals only. Nothing is recorded as a
  Decision without Luke's explicit approval, per decision, in the issue thread.
- No decision may be recorded as "TBD", "to be confirmed", or deferred. If Luke
  wants one deferred, it is recorded as an explicit decision to defer, naming what
  cannot be built until it is settled.
- Node scripts use no new dependencies. `approved-deps.json` is not modified by
  this milestone.
- One issue per task, one branch per task, one pull request per task, matching the
  project's Issue → Implementation → Review → PR flow.
- Version bump and changelog entry on every pull request, per the shared
  guardrails. These are documentation changes, so patch bumps.

---

## File Structure

| File | Responsibility |
|---|---|
| `docs/architecture/decisions.md` | The decision record. One section per group, one subsection per decision ID. The single citable source. |
| `docs/architecture/decisions-manifest.json` | The list of decision IDs that must exist, with their group and one-line question. The checker reads this, not the prose. |
| `bin/check-decisions.mjs` | Verifies every manifest ID appears in the record with all four required parts and no deferral placeholder. Exits non-zero on failure. |
| `tests/unit/check-decisions.test.mjs` | Unit tests for the checker, including the failure cases. |
| `docs/architecture/permission-matrix.md` | Every access role against every capability, both interfaces, including explicit denials. Derived from the AUTH group. |
| `docs/architecture/workflow-state-machine.md` | The twelve stages, permitted transitions, each exit gate as structured requirements, exceptions, terminal outcomes. Derived from the WF group. |
| `docs/architecture/data-model.md` | The §12.2 entities, their relationships, and the field-ownership map. Derived from every group. |
| `.github/workflows/*` (modify) | Run the checker in CI. |

Splitting the manifest from the prose is deliberate: it means the checker tests
structure without parsing English, and adding a decision is a manifest edit that
immediately fails CI until it is answered.

---

## Task 1: The decision record scaffold and its completeness checker

This is the only task in the milestone with real code, and it comes first because
every later task is verified by it.

**Files:**
- Create: `docs/architecture/decisions-manifest.json`
- Create: `bin/check-decisions.mjs`
- Create: `tests/unit/check-decisions.test.mjs`
- Create: `docs/architecture/decisions.md`
- Modify: `package.json` (add the `check:decisions` script and include it in `lint`)

**Interfaces:**
- Consumes: nothing.
- Produces: `npm run check:decisions` — exits 0 when every manifest ID is fully
  answered, 1 otherwise, printing each missing ID and which of the four parts it
  lacks. Later tasks run this as their verification step.
- Produces: the heading convention `### <ID> — <slug>` inside
  `docs/architecture/decisions.md`, and the four bold labels `**Question:**`,
  `**Options considered:**`, `**Decision:**`, `**Consequence if reversed:**`.

- [ ] **Step 1: Write the failing tests for the checker**

Create `tests/unit/check-decisions.test.mjs`:

```javascript
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { checkDecisions } from '../../bin/check-decisions.mjs';

const manifest = [
	{ id: 'ARCH-1', group: 'Architecture', question: 'Client delivery model' },
];

const complete = `
### ARCH-1 — client-delivery-model

**Question:** Embedded module, plugin, subdomain or separate application?

**Options considered:** One plugin in two modes; one repo with two zips; two repos.

**Decision:** One repo, two zips.

**Consequence if reversed:** The client artifact would contain studio code.
`;

test( 'passes when every manifest id is fully answered', () => {
	const result = checkDecisions( manifest, complete );
	assert.equal( result.ok, true );
	assert.deepEqual( result.problems, [] );
} );

test( 'fails when a manifest id is missing entirely', () => {
	const result = checkDecisions( manifest, '# Decisions\n' );
	assert.equal( result.ok, false );
	assert.deepEqual( result.problems, [ 'ARCH-1: no section found' ] );
} );

test( 'fails when a required part is missing', () => {
	const missingConsequence = complete.replace(
		/\*\*Consequence if reversed:\*\*.*/s,
		''
	);
	const result = checkDecisions( manifest, missingConsequence );
	assert.equal( result.ok, false );
	assert.deepEqual( result.problems, [
		'ARCH-1: missing "Consequence if reversed"',
	] );
} );

test( 'fails when a decision is left unanswered', () => {
	const deferred = complete.replace(
		'**Decision:** One repo, two zips.',
		'**Decision:** TBD'
	);
	const result = checkDecisions( manifest, deferred );
	assert.equal( result.ok, false );
	assert.deepEqual( result.problems, [
		'ARCH-1: "Decision" is a placeholder ("TBD")',
	] );
} );

test( 'accepts an explicit, scoped decision to defer', () => {
	const explicitDefer = complete.replace(
		'**Decision:** One repo, two zips.',
		'**Decision:** Deferred until Milestone 7. Blocks: capacity gate design.'
	);
	const result = checkDecisions( manifest, explicitDefer );
	assert.equal( result.ok, true );
} );
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `node --test tests/unit/check-decisions.test.mjs`
Expected: FAIL — cannot resolve `../../bin/check-decisions.mjs`.

- [ ] **Step 3: Write the checker**

Create `bin/check-decisions.mjs`:

```javascript
#!/usr/bin/env node
/**
 * Verifies the decision record answers every decision in the manifest.
 *
 * The manifest is the list of questions that must be settled; the record is the
 * prose. Splitting them means adding a question fails CI until it is answered.
 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const PARTS = [
	'Question',
	'Options considered',
	'Decision',
	'Consequence if reversed',
];

const PLACEHOLDERS = [ 'TBD', 'TODO', 'to be confirmed', 'decision required' ];

/**
 * @param {Array<{id: string}>} manifest
 * @param {string} record
 * @returns {{ok: boolean, problems: string[]}}
 */
export function checkDecisions( manifest, record ) {
	const problems = [];
	const sections = splitSections( record );

	for ( const { id } of manifest ) {
		const body = sections.get( id );

		if ( undefined === body ) {
			problems.push( `${ id }: no section found` );
			continue;
		}

		for ( const part of PARTS ) {
			const value = extractPart( body, part );

			if ( null === value ) {
				problems.push( `${ id }: missing "${ part }"` );
				continue;
			}

			const hit = PLACEHOLDERS.find(
				( p ) => value.toLowerCase() === p.toLowerCase()
			);

			if ( hit ) {
				problems.push( `${ id }: "${ part }" is a placeholder ("${ hit }")` );
			}
		}
	}

	return { ok: 0 === problems.length, problems };
}

/**
 * @param {string} record
 * @returns {Map<string, string>}
 */
function splitSections( record ) {
	const sections = new Map();
	const pattern = /^### ([A-Z]+-\d+) — .*$/gm;
	const matches = [ ...record.matchAll( pattern ) ];

	matches.forEach( ( match, index ) => {
		const start = match.index + match[ 0 ].length;
		const end =
			index + 1 < matches.length ? matches[ index + 1 ].index : record.length;
		sections.set( match[ 1 ], record.slice( start, end ) );
	} );

	return sections;
}

/**
 * @param {string} body
 * @param {string} part
 * @returns {string|null}
 */
function extractPart( body, part ) {
	const pattern = new RegExp( `\\*\\*${ part }:\\*\\*([\\s\\S]*?)(?=\\n\\*\\*|$)` );
	const match = body.match( pattern );

	if ( ! match ) {
		return null;
	}

	const value = match[ 1 ].trim();

	return '' === value ? null : value;
}

const isDirectRun =
	process.argv[ 1 ] && process.argv[ 1 ] === fileURLToPath( import.meta.url );

if ( isDirectRun ) {
	const root = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
	const manifest = JSON.parse(
		readFileSync( join( root, 'docs/architecture/decisions-manifest.json' ), 'utf8' )
	);
	const record = readFileSync(
		join( root, 'docs/architecture/decisions.md' ),
		'utf8'
	);
	const { ok, problems } = checkDecisions( manifest.decisions, record );

	if ( ! ok ) {
		console.error( 'Decision record incomplete:\n' );
		problems.forEach( ( p ) => console.error( `  - ${ p }` ) );
		console.error(
			`\n${ problems.length } problem(s). Milestone 1 must not start until these are answered.`
		);
		process.exit( 1 );
	}

	console.log(
		`Decision record complete: ${ manifest.decisions.length } decisions answered.`
	);
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `node --test tests/unit/check-decisions.test.mjs`
Expected: PASS, 5 tests.

- [ ] **Step 5: Write the manifest**

Create `docs/architecture/decisions-manifest.json` with all 47 decisions. Exact content:

```json
{
	"decisions": [
		{ "id": "ARCH-1", "group": "Architecture and delivery", "question": "Client delivery model: embedded module, plugin, subdomain or separate application" },
		{ "id": "ARCH-2", "group": "Architecture and delivery", "question": "Canonical architecture: central shared service and database, or separate client data stores" },
		{ "id": "ARCH-3", "group": "Architecture and delivery", "question": "Whether one client may have multiple sites" },
		{ "id": "ARCH-4", "group": "Architecture and delivery", "question": "Whether client sites must function while the studio is unreachable" },
		{ "id": "ARCH-5", "group": "Architecture and delivery", "question": "Conflict rules: field authority, acceptable sync delay, who resolves conflicts" },
		{ "id": "ARCH-6", "group": "Architecture and delivery", "question": "How a client site authenticates to the studio, and how that trust is revoked" },

		{ "id": "WF-1", "group": "Workflow", "question": "Whether Bug Tracking is conditional and Blocked is an exception state" },
		{ "id": "WF-2", "group": "Workflow", "question": "The terminal states Rejected, Cancelled, Duplicate, Deferred and Archived, and their reporting behaviour" },
		{ "id": "WF-3", "group": "Workflow", "question": "Authorised return paths from each stage" },
		{ "id": "WF-4", "group": "Workflow", "question": "Reopen behaviour for Completed and Released work" },
		{ "id": "WF-5", "group": "Workflow", "question": "Whether an administrator override exists, and its constraints" },
		{ "id": "WF-6", "group": "Workflow", "question": "What Released means for software, content, design, infrastructure and non-deployment work" },

		{ "id": "AUTH-1", "group": "Authority and permissions", "question": "Who may approve the Triage, Documentation, Technical Audit and Design gates" },
		{ "id": "AUTH-2", "group": "Authority and permissions", "question": "The client contribution boundary: what client roles may create, edit, comment and answer" },
		{ "id": "AUTH-3", "group": "Authority and permissions", "question": "Whether Primary User, Reviewer and Deliverer must be different people" },
		{ "id": "AUTH-4", "group": "Authority and permissions", "question": "How substitutes for Reviewer and Deliverer are authorised" },
		{ "id": "AUTH-5", "group": "Authority and permissions", "question": "Which users, capacity indicators, reports and audit details a client may view" },
		{ "id": "AUTH-6", "group": "Authority and permissions", "question": "User invitation, account ownership, multi-client membership, SSO and offboarding" },

		{ "id": "WORK-1", "group": "Work structure", "question": "Whether Projects, Features and Milestones may contain each other" },
		{ "id": "WORK-2", "group": "Work structure", "question": "Whether parent status and progress are fully derived from Sub-items" },
		{ "id": "WORK-3", "group": "Work structure", "question": "Mandatory date rules and timezone handling" },

		{ "id": "CAP-1", "group": "Capacity", "question": "Base working hours and the source of leave and unavailable time" },
		{ "id": "CAP-2", "group": "Capacity", "question": "Whether hours are entered per role or derived from controlled defaults" },
		{ "id": "CAP-3", "group": "Capacity", "question": "Whether actual staff time is tracked, and how" },
		{ "id": "CAP-4", "group": "Capacity", "question": "Whether over-allocation blocks the move or requires a reasoned override" },

		{ "id": "COMM-1", "group": "Commercial", "question": "Whether package assignment starts a new twelve-month term or aligns to a shared renewal date" },
		{ "id": "COMM-2", "group": "Commercial", "question": "Pro-rata hours, pro-rata pricing, date basis and rounding" },
		{ "id": "COMM-3", "group": "Commercial", "question": "Hour consumption: reservation, usage, cancellation, adjustment and negative balance" },
		{ "id": "COMM-4", "group": "Commercial", "question": "Top-up expiry, consumption order, annual rollover and package-expiry treatment" },
		{ "id": "COMM-5", "group": "Commercial", "question": "Whether validated bug work for a no-package client is free, uses top-ups, or requires a package" },
		{ "id": "COMM-6", "group": "Commercial", "question": "Checkout route, immediate versus approved upgrade, tax, refund and failed payment" },

		{ "id": "MEET-1", "group": "Support meetings", "question": "Meeting schedule ownership and permitted client actions" },
		{ "id": "MEET-2", "group": "Support meetings", "question": "Recurrence and exception rules" },
		{ "id": "MEET-3", "group": "Support meetings", "question": "Planned billable-hour defaults per occurrence" },
		{ "id": "MEET-4", "group": "Support meetings", "question": "Reservation horizon and term-boundary forecasting" },
		{ "id": "MEET-5", "group": "Support meetings", "question": "Late-cancellation, no-show and insufficient-balance treatment" },
		{ "id": "MEET-6", "group": "Support meetings", "question": "Reminders, and whether an external calendar integration is required" },

		{ "id": "ONB-1", "group": "Onboarding", "question": "Approved templates, mandatory and launch-critical steps, dependencies and completion threshold" },
		{ "id": "ONB-2", "group": "Onboarding", "question": "Step ownership, reviewer authority, return and approval rights, authorised Not Applicable decisions" },
		{ "id": "ONB-3", "group": "Onboarding", "question": "Secure access-handover method, stored reference fields, verification and retention" },
		{ "id": "ONB-4", "group": "Onboarding", "question": "Onboarding notifications: assignment, due, overdue, submission, return, approval, blocker, launch-ready" },

		{ "id": "REQ-1", "group": "Requests", "question": "Permitted conversion stages, parent creation and linking rules, package gating after acceptance" },

		{ "id": "NOTIF-1", "group": "Notifications and retention", "question": "Client email recipient source" },
		{ "id": "NOTIF-2", "group": "Notifications and retention", "question": "Whether Completed or Released is the final client confirmation event" },
		{ "id": "NOTIF-3", "group": "Notifications and retention", "question": "Email templates, sender identity, retry and escalation rules" },
		{ "id": "NOTIF-4", "group": "Notifications and retention", "question": "The in-app notification event list" },
		{ "id": "NOTIF-5", "group": "Notifications and retention", "question": "Deletion, archive, export, attachment retention and audit-history requirements" }
	]
}
```

- [ ] **Step 6: Write the empty record with its group headings**

Create `docs/architecture/decisions.md`. It starts with the explanation below and
the ten group headings (`## Architecture and delivery`, `## Workflow`,
`## Authority and permissions`, `## Work structure`, `## Capacity`,
`## Commercial`, `## Support meetings`, `## Onboarding`, `## Requests`,
`## Notifications and retention`) and nothing under them yet.

```markdown
# Blueworx Forge — decision record

Every architecture and product decision the platform is built on. Later specs,
plans, code comments and tests cite these IDs, so the IDs are stable and are
never renumbered.

Each entry has four parts. `bin/check-decisions.mjs` verifies all four are
present and unanswered questions cannot pass CI:

- **Question** — what had to be decided.
- **Options considered** — what else was on the table.
- **Decision** — the approved answer.
- **Consequence if reversed** — what has to be rebuilt if this changes later.

A reversed decision is added as a new dated revision under the same ID. The
original is never edited away, because later work was built on it.

Source: `design/research/brief.txt` §17, plus ARCH-3, ARCH-4, ARCH-6 and AUTH-4,
which the programme plan identified as missing from the brief.
```

- [ ] **Step 7: Wire the checker into npm and CI**

In `package.json`, add to `scripts`:

```json
"check:decisions": "node bin/check-decisions.mjs",
"test:unit": "node --test tests/unit/"
```

Then modify the CI workflow to run `npm run check:decisions` and `npm run
test:unit`. Read the existing workflow file first to match how it invokes the
shared foundation caller — do not restructure it.

- [ ] **Step 8: Run the checker and confirm it fails loudly**

Run: `npm run check:decisions`
Expected: FAIL, exit 1, listing all 47 IDs as "no section found".

This failing state is correct and is the point: CI now blocks Milestone 1 until
the record is filled in.

- [ ] **Step 9: Bump the version and add the changelog entry**

Patch bump in `package.json`, the plugin header and the version constant. A
changelog entry saying the decision record and its completeness check were added.

- [ ] **Step 10: Commit**

```bash
git add bin/check-decisions.mjs tests/unit/check-decisions.test.mjs \
	docs/architecture/decisions.md docs/architecture/decisions-manifest.json \
	package.json CHANGELOG.md
git commit -m "Add the decision record and the check that it is complete (#<issue>)"
```

---

## Tasks 2–10: The nine decision groups

Every one of these tasks has the same shape. It is written out once here; each
task below states only its decision IDs and the proposed answers.

**Files (every decision task):**
- Modify: `docs/architecture/decisions.md` — add one `### <ID> — <slug>` section
  per decision ID in the group.

**Interfaces:**
- Consumes: the heading and four-part convention from Task 1, and
  `npm run check:decisions`.
- Produces: the recorded decisions its group owns, cited by ID from Tasks 11–13
  and by every later milestone's spec.

**Steps (every decision task):**

- [ ] **Step 1: Re-read the brief sections this group covers**

Named per task below. Read them before drafting — the proposed answers in this
plan were written from the brief, but the brief is the authority.

- [ ] **Step 2: Put the proposed answers to Luke in the issue thread**

One comment, listing each ID with its question, the options, and the proposed
answer. Ask for approval or amendment per ID, not for the group as a whole.

- [ ] **Step 3: Wait for approval. Do not draft the record before it arrives.**

A recorded decision Luke has not approved is worse than no record, because
later work will cite it.

- [ ] **Step 4: Write each approved decision into the record**

Under the group's `##` heading, one section per ID:

```markdown
### ARCH-1 — client-delivery-model

**Question:** [the question, in full]

**Options considered:** [what else was on the table, and why it lost]

**Decision:** [Luke's approved answer, in his terms where he amended]

**Consequence if reversed:** [what has to be rebuilt]
```

- [ ] **Step 5: Run the checker**

Run: `npm run check:decisions`
Expected: still exit 1 while other groups are outstanding, but **none of this
group's IDs may appear in the output**. If one does, a part is missing or a
placeholder survived.

- [ ] **Step 6: Bump the version, add the changelog entry, and commit**

```bash
git add docs/architecture/decisions.md package.json CHANGELOG.md
git commit -m "Record the <group> decisions (#<issue>)"
```

---

### Task 2: Architecture and delivery decisions

**Decision IDs:** ARCH-1 … ARCH-6
**Brief sections to re-read:** §12, §12.1, §14, §17 (P0 rows 1, 2, 24).

**Proposed answers:**

- **ARCH-1** — WordPress plugin on the client's own site. Not embedded, not a
  subdomain: the brief requires `wp_mail` delivery from the client's site (§13.3)
  and a dashboard inside their WordPress, both of which need code running there.
- **ARCH-2** — Central shared service and database on the studio site. Confirms
  the brief's own recommendation. The client plugin holds no canonical records.
- **ARCH-3** — Yes, a client may have multiple sites. The tenant is the client;
  the site is a child of it. Cheap to model now, expensive to retrofit, and the
  brief's §2.3 already says "site(s)".
- **ARCH-4** — No. Client sites require the studio to be reachable, and degrade to
  a cached read-only view with a stale-data banner when it is not. This is the
  decision that keeps §12's sync architecture out of scope.
- **ARCH-5** — With one canonical database there are no merge conflicts, only stale
  writes. Every write carries the record version it read; a mismatch is rejected
  with the current state returned, never merged. Acceptable staleness on a client
  site: 60 seconds. Rejected writes surface to the user, not to a queue.
- **ARCH-6** — The studio issues a per-site key on registration. Requests are
  signed, with a timestamp and nonce so a captured request cannot be replayed.
  Keys are revocable and rotatable from the studio, and a revoked key's use is
  logged as a security event.

---

### Task 3: Workflow decisions

**Decision IDs:** WF-1 … WF-6
**Brief sections to re-read:** §4, §4.1, §4.2, §17 (P0 rows 3, 4, 22; P1 row 2).

**Proposed answers:**

- **WF-1** — Approved as the brief proposes. Bug Tracking is entered only from
  Triage, when Triage classifies the item as a bug. Blocked is enterable from any
  active stage, stores the prior stage and returns to it. Neither is a mandatory
  linear step.
- **WF-2** — Five terminal outcomes, each reached only from a stage where it makes
  sense: **Rejected** (from Triage), **Duplicate** (from Triage, requires the link
  to the surviving item), **Cancelled** (from any active stage, requires a reason
  and releases any hour reservation), **Deferred** (from Triage or Up Next, returns
  to Future Idea with the deferral recorded), **Archived** (from any terminal
  state, hides from default views but never from reports). All five stay in
  reporting and in cycle-time calculations, flagged by outcome, so throughput is
  not flattered by deletion.
- **WF-3** — One rule rather than a table: any stage may return to any earlier
  stage it has actually occupied, with a named target and a reason. Returning to a
  stage the item never occupied is not a return, it is a correction, and requires
  the override in WF-5. The In Review to In Development return is the one path with
  its own affordance, because it is the common case.
- **WF-4** — Reopening creates a new work cycle on the same Sub-item rather than
  reverting it. The completion and release records are preserved as a closed cycle;
  the item re-enters at Documentation Period or In Development, chosen at reopen,
  with a reason. Hour treatment on reopen follows COMM-3.
- **WF-5** — Yes, one override, held by the Primary administrator only. It can move
  an item to any stage, requires a reason, is permanently marked on the item (not
  only in the log), and appears in the override report. It cannot bypass the client
  transition lock, because that is a security boundary rather than a workflow gate.
- **WF-6** — Released is per work type, recorded on the item: **software** — a
  version live in the named environment; **content** — published and visible at a
  URL; **design** — approved artifact handed over and acknowledged; **infrastructure**
  — the change applied and verified; **non-deployment** — the agreed deliverable
  received and confirmed by the named recipient. In every case the Deliverer
  records evidence and a post-release check result.

---

### Task 4: Authority and permission decisions

**Decision IDs:** AUTH-1 … AUTH-6
**Brief sections to re-read:** §3, §3.1, §4.1, §5.1, §14, §17 (P0 rows 5, 6, 8;
P1 rows 3, 4).

**Proposed answers:**

- **AUTH-1** — This is the brief's stated gap. Proposal: a single **Approver**
  capability per gate type (triage, documentation, technical, design), granted to
  named staff users independently of their Sub-item roles, so a small team can hold
  several and a larger one can separate them. An item's own Primary User may not
  approve its own Documentation, Technical Audit or Design gate.
- **AUTH-2** — Client administrators may create parent items and Sub-items when the
  client has an active package, and may always submit bugs and requests. They may
  edit definition fields (problem, scope, acceptance criteria, links, attachments)
  until the item leaves Documentation Period, after which those become
  comment-and-request-change only. They may comment, attach evidence and answer
  information requests at any stage. They may never touch workflow, accountability,
  planning or commercial fields. Client stakeholders are read-only plus comment.
- **AUTH-3** — Primary User and Reviewer must be different people, always: a
  self-review is not a review, and that is the gate the whole product exists to
  enforce. Deliverer may be the same person as either. Capacity counts each role's
  hours separately even when one person holds two.
- **AUTH-4** — A substitute is named per item by the Primary administrator, with a
  reason, and is recorded on the item and in the changelog. There is no standing
  delegation and no automatic fallback, because an automatic reviewer is how
  approval becomes a formality.
- **AUTH-5** — A client sees: their own work and its stage, their own people, their
  own hour ledger and balances, their onboarding, their meetings, their Point of
  Contact, and a privacy-safe availability result for planning. They do not see
  staff names against capacity figures, other clients' anything, internal notes, or
  gate approver identities beyond the fact of approval.
- **AUTH-6** — Users are invited by email from the studio, own their own account,
  and hold one global identity with per-client memberships. No SSO in the first
  release. Offboarding revokes every membership and deactivates the account while
  leaving all historical attribution intact.

---

### Task 5: Work structure decisions

**Decision IDs:** WORK-1 … WORK-3
**Brief sections to re-read:** §2.1, §2.2, §17 (P0 row 7; P1 row 1).

**Proposed answers:**

- **WORK-1** — No nesting between parents. A Milestone may *reference* Projects and
  Features it depends on, but containment is one level: parent, then Sub-items.
  Nesting makes derived progress ambiguous and Gantt roll-up recursive, for no
  requirement the brief actually states.
- **WORK-2** — Fully derived. A parent has no independently settable status. Its
  progress is the proportion of its Sub-items' planned hours in Completed or
  Released; its dates are the span of its Sub-items' dates; its state is derived
  from the distribution (not started / in progress / blocked / complete). A parent
  with no Sub-items is explicitly "empty", not "not started".
- **WORK-3** — Planned start and due date are mandatory at Up Next, not before.
  Review and release dates are optional targets throughout and never gate a
  transition. All dates are stored as UTC instants; every interface renders in the
  client's timezone from §2.3, and the studio renders in the studio timezone with
  the client's shown alongside where they differ.

---

### Task 6: Capacity decisions

**Decision IDs:** CAP-1 … CAP-4
**Brief sections to re-read:** §7, §7.1, §7.2, §7.3, §17 (P0 row 9).

**Proposed answers:**

- **CAP-1** — Base hours are a per-user weekly pattern by weekday, with an
  effective date so a change to someone's hours does not rewrite history. Leave is
  entered in Forge as dated unavailability records; no external HR source in the
  first release.
- **CAP-2** — Hours are entered separately per role, because the brief is right that
  one total is insufficient when three people are committed. Defaults are seeded
  from a configurable ratio of the Primary User's estimate (proposed: Reviewer 20%,
  Deliverer 10%) and are editable, so the common case is fast and the unusual case
  is possible.
- **CAP-3** — No actual time tracking in the first release. The brief excludes
  stopwatch tracking (§15.1) and charges by allocation, so "actual" hours would be
  a second number nobody maintains. Remaining estimate is captured at In
  Development instead, which is what forecasting needs.
- **CAP-4** — Over-allocation requires a reasoned override by the Primary
  administrator; it does not hard block. Hard blocking a delivery queue on a
  capacity model that has no actuals in it would be a model overruling a human on
  worse information. The override is recorded, reported and visible on the item.

---

### Task 7: Commercial decisions

**Decision IDs:** COMM-1 … COMM-6
**Brief sections to re-read:** §8 through §8.6, §17 (P0 rows 10, 11, 12, 14, 15,
16).

**Proposed answers:**

- **COMM-1** — Assignment starts a new twelve-month term from the effective date.
  Simpler, and it makes pro-rata the exception rather than the rule. Aligning to a
  shared renewal date is supported as an explicit option on assignment, for the
  case where a client wants everything renewing together — and that is the only
  case where COMM-2 applies.
- **COMM-2** — Pro-rata hours by the brief's formula, on exact dates including leap
  years, rounded to the nearest half hour, with a preview before assignment. Price
  is prorated by the same ratio, rounded to the nearest whole currency unit.
  Upgrades credit the unused pro-rata value of the outgoing package; downgrades
  mid-term are not permitted, only at renewal.
- **COMM-3** — Reservation at Up Next, usage at In Development, as the brief
  proposes. Cancellation before In Development releases the reservation in full;
  cancellation after it is an adjustment requiring a reason. Balances may not go
  negative without the Primary administrator's override, which is recorded. Every
  change is an appended ledger entry, never an edit.
- **COMM-4** — Top-ups have their own expiry date, default twelve months from
  purchase. Consumption order is expiring-soonest first, package hours before
  top-ups when both expire together. No automatic rollover of unused package
  hours at renewal; unused top-ups survive to their own expiry. Package expiry
  freezes the remaining balance rather than voiding it, pending renewal.
- **COMM-5** — Validated bug work on a client's own delivered site is free of
  support hours: charging a client to fix our defect is not defensible. It still
  requires the full workflow and still consumes staff capacity, so it is visible
  and costed internally. Bugs in work Forge did not deliver are ordinary work and
  need hours.
- **COMM-6** — No checkout in the first release. The Sales page requests an upgrade
  or top-up, which creates a task for the Point of Contact and is fulfilled by
  manual assignment. This is the brief's own required-regardless path, and it
  removes payment, tax, refund and failed-payment handling from the critical path
  without removing the client's ability to ask.

---

### Task 8: Support meeting decisions

**Decision IDs:** MEET-1 … MEET-6
**Brief sections to re-read:** §10.3, §7.1, §8.4, §17 (P0 row 13; P1 row 7).

**Proposed answers:**

- **MEET-1** — The Point of Contact or a Primary administrator owns the schedule. A
  client may request a change to a single occurrence, which creates a request the
  owner accepts or declines; the client can never edit the series.
- **MEET-2** — Weekly, fortnightly, four-weekly and monthly-by-date recurrence,
  with an optional end date. A single occurrence may be moved or cancelled as an
  exception without touching the rule. No arbitrary RRULE support in the first
  release.
- **MEET-3** — Planned billable hours default to the occurrence's calendar duration
  rounded up to the next half hour, and are separately editable per series and per
  occurrence.
- **MEET-4** — Reserve occurrences falling within the active package term, up to a
  rolling twelve-week horizon. Beyond that, occurrences are forecast only and
  display as such.
- **MEET-5** — Held converts the reservation to usage. Cancelled with 48 hours'
  notice or more releases it in full. Cancelled later, and No Show, both convert to
  usage — the time was committed and the staff capacity was spent. Insufficient
  balance blocks confirming a new occurrence and raises it on Standup; it never
  silently overdraws.
- **MEET-6** — Reminders to host and client attendees 24 hours before an
  occurrence, through the client site's `wp_mail`. No external calendar
  integration; §15.1 excludes it. Each occurrence carries a meeting link field the
  host pastes in.

---

### Task 9: Onboarding and request decisions

**Decision IDs:** ONB-1 … ONB-4, REQ-1
**Brief sections to re-read:** §9, §9.3, §11 through §11.5, §17 (P0 rows 17, 18,
19, 21; P1 row 9).

**Proposed answers:**

- **ONB-1** — One template, version 1, covering the twelve categories in §11.2. All
  steps mandatory except where the template marks a step optional. Launch-critical:
  domain and DNS, hosting, email and SMTP, legal and compliance, and review and
  launch. Completion is the proportion of approved required steps; launch readiness
  is a separate boolean over the launch-critical set, because a site at 95% with an
  unapproved DNS step is not nearly ready.
- **ONB-2** — Each step carries a client owner or an internal owner from the
  template. The assigned reviewer is the Point of Contact by default, overridable
  per step. Reviewers may approve, return with client-visible feedback, or record
  Not Applicable with a reason where the template permits it. Clients may never
  approve their own step, even a client-owned one.
- **ONB-3** — Provider delegation and invitation only: registrar, host, DNS and
  integration access is granted by inviting our named account, never by handing
  over credentials. Forge stores the provider, the account identifier, the account
  owner, the access role requested, invitation status, and the verification
  outcome. Where a provider genuinely cannot delegate, the credential is passed
  through a one-time secret link outside Forge and only the completion reference is
  stored. Field-level validation rejects anything shaped like a password, key or
  card number.
- **ONB-4** — Email on: template assignment, a step becoming the client's turn,
  three days before a step's due date, on overdue, on return with changes
  requested, on approval, and on launch-ready. Internal notification on submission
  for review and on blocked. Everything else in-app only.
- **REQ-1** — An accepted request converts to Future Idea or Triage, chosen at
  conversion. The converting user must pick an existing parent or create one in the
  same action; a Sub-item with no parent is never created. The submission stays
  immutable and two-way linked. After acceptance, a no-package client's work may
  sit in the pipeline but cannot pass the support-hours gate into Up Next — except
  where COMM-5 makes it a free bug.

---

### Task 10: Notification and retention decisions

**Decision IDs:** NOTIF-1 … NOTIF-5
**Brief sections to re-read:** §13.3, §13.1, §14, §17 (P0 row 20; P1 rows 5, 10).

**Proposed answers:**

- **NOTIF-1** — Both the original submitter and the client's nominated recipients
  from §2.3, de-duplicated. Recipients are resolved from verified client records
  only, never from free text on the item.
- **NOTIF-2** — **Released** is the final confirmation, not Completed. Completed is
  an internal readiness state; telling a client their work is done before it is
  live is the message that generates the support ticket. Completed still sends,
  worded as "approved and ready to release".
- **NOTIF-3** — Three templates matching the three events in §13.3, sent from the
  client site's own configured sender so it comes from a domain the client
  recognises. Three retries at 5, 30 and 120 minutes, then the failure is raised on
  Standup. Duplicate suppression is by the unique event ID, checked before send.
- **NOTIF-4** — In-app on: assignment to any of the three roles, gate approval,
  returned review, blocker created or updated, work due or overdue, capacity
  conflict, insufficient support hours, a new request awaiting review, release
  readiness, and any sync or delivery failure needing intervention.
- **NOTIF-5** — Nothing is hard-deleted while it carries audit history. Clients and
  users are deactivated, not removed. Archived work leaves default views and stays
  in reports and exports indefinitely. Attachments are retained for the life of the
  client relationship plus twelve months. A client may request an export of their
  own data at any time, and the studio may export everything. A deletion request
  under data-protection law is handled as a documented manual process, because an
  automated purge that walks immutable ledgers and changelogs is a foot-gun.

---

## Task 11: The permission matrix

**Files:**
- Create: `docs/architecture/permission-matrix.md`

**Interfaces:**
- Consumes: AUTH-1 … AUTH-6, ARCH-3, WF-5, and the client lock from §14.
- Produces: the authoritative capability list. Milestone 2's capability map is
  implemented from this file, and Milestone 2's negative test suite is written from
  its denial column.

- [ ] **Step 1: Write the matrix**

Rows are capabilities, grouped: read, create, edit, comment and evidence,
transition, approve, administer, commercial, onboarding. Columns are the four
access roles from §3.1 crossed with the two interfaces — eight columns. Every cell
is `yes`, `no`, or `yes, conditional on <named condition>`. No blank cells.

- [ ] **Step 2: Write the explicit-denial section**

Separately from the grid, list every action a client role must be refused
server-side, with the route it could be attempted by: UI control, direct REST
call, filter parameter, replayed signed request, and sync event. This list is the
Milestone 2 and Milestone 4 test manifest, so it must be exhaustive rather than
illustrative.

- [ ] **Step 3: Check the matrix against the record**

Every conditional cell must cite the decision ID that sets its condition. A
condition with no citation means either an undecided question or an invented rule.

- [ ] **Step 4: Put it to Luke for approval, then commit**

```bash
git add docs/architecture/permission-matrix.md package.json CHANGELOG.md
git commit -m "Write the permission matrix (#<issue>)"
```

---

## Task 12: The workflow state machine and gate specification

**Files:**
- Create: `docs/architecture/workflow-state-machine.md`

**Interfaces:**
- Consumes: WF-1 … WF-6, AUTH-1, AUTH-3, AUTH-4, CAP-4, COMM-3, COMM-5.
- Produces: the stage list, the transition table and the gate requirement lists
  that Milestone 4 implements directly. Milestone 4's stage registry is generated
  from this document's stage list, and its test suite is one test per row of the
  transition table.

- [ ] **Step 1: Write the stage list**

The twelve stages, each with its purpose and whether it is linear, conditional or
an exception state.

- [ ] **Step 2: Write the transition table**

One row per permitted transition: from stage, to stage, who may request it, who
may approve it, and the gate that must pass. Include the exception entries and
exits, the terminal outcomes from WF-2, the return paths from WF-3, the reopen
routes from WF-4 and the override from WF-5. A transition not in this table does
not exist.

- [ ] **Step 3: Write each exit gate as a numbered requirement list**

For all twelve stages, from §4.2, with every requirement expressed as a named
structured field or checklist record — never as "documented" or "reviewed". Each
requirement states its field, its type, whether evidence is required, and who may
mark it complete. This is the document that decides whether a gate is real or
decorative.

- [ ] **Step 4: Write the gate-failure contract**

The exact shape of a failed transition response: the item's unchanged stage, and
per unmet requirement its ID, its human-readable label and what would satisfy it.
Milestone 4 and Milestone 5 both build against this shape.

- [ ] **Step 5: Check every gate requirement against the data model**

Every requirement must name a field that Task 13's data model contains. A
requirement with no field is a requirement that cannot be enforced.

- [ ] **Step 6: Put it to Luke for approval, then commit**

```bash
git add docs/architecture/workflow-state-machine.md package.json CHANGELOG.md
git commit -m "Write the workflow state machine and gate specification (#<issue>)"
```

---

## Task 13: The canonical data model and field-ownership map

**Files:**
- Create: `docs/architecture/data-model.md`

**Interfaces:**
- Consumes: every decision, and the field lists from Tasks 11 and 12.
- Produces: the entity and field definitions that Milestones 2, 3, 7, 8 and 9
  implement. Milestone 3's Sub-item schema is this document's Sub-item entity.

- [ ] **Step 1: Write the entity list**

The twenty-two entities from §12.2, each with its purpose, its owning milestone,
and its tenant scoping rule — including the entities that are deliberately global
rather than tenant-scoped (User, Package Version, Onboarding Template Version).

- [ ] **Step 2: Write the fields for each entity**

Name, type, required or optional, default, and validation. Include every field the
gates in Task 12 name and every field the permission matrix in Task 11 references.

- [ ] **Step 3: Write the relationships and the identity rules**

Foreign keys, cardinality, cascade behaviour on deactivation (never on deletion,
per NOTIF-5), and how globally unique IDs are formed so a record's ID is
unambiguous across both interfaces.

- [ ] **Step 4: Write the field-ownership map**

For every field on every shared entity, which interface may author it: studio only,
client only, either, or system only. With one canonical database this is a
permission rule rather than a merge rule, but it is still the list Milestone 2
enforces and Milestone 6 renders read-only from.

- [ ] **Step 5: Write the immutability rules**

Which entities are append-only (Changelog Event, Hour Ledger Entry, Gate
Completion, Package Version, Onboarding Template Version, Onboarding Evidence),
and what a correction looks like for each — because "immutable" without a correction
path means someone will edit the table by hand eventually.

- [ ] **Step 6: Cross-check the three documents against each other**

Every gate requirement resolves to a field. Every conditional permission cell
resolves to a field or a decision. Every entity has a milestone. Fix anything that
does not.

- [ ] **Step 7: Run the full check**

Run: `npm run check:decisions && npm run test:unit && npm run lint`
Expected: the decision check now PASSES — all 47 answered. This is the gate that
opens Milestone 1.

- [ ] **Step 8: Put it to Luke for approval, then commit**

```bash
git add docs/architecture/data-model.md package.json CHANGELOG.md
git commit -m "Write the canonical data model and field-ownership map (#<issue>)"
```

---

## Milestone exit criteria

- `npm run check:decisions` passes: 47 decisions, each with all four parts, no
  placeholders.
- The permission matrix has no blank cells and every conditional cell cites a
  decision ID.
- Every gate requirement in the state machine names a field that exists in the data
  model.
- Every entity in §12.2 appears in the data model with an owning milestone.
- Luke has approved all four documents.

Only then does Milestone 1 start.

---

## Self-review notes

**Spec coverage.** All twelve issues from Milestone 0 of the programme design are
covered: the nine decision-group issues become Tasks 2–10, the three document
issues become Tasks 11–13, and Task 1 adds the scaffold and its check, which the
programme design implied but did not name as an issue. That is one more issue than
the spec listed, and the GitHub milestone reflects thirteen.

**Known gap this plan creates deliberately.** Tasks 2–10 cannot be executed by a
subagent unattended: each one blocks on Luke's per-decision approval. They are
written as single tasks with a hard wait rather than split into "draft" and
"record" tasks, because splitting would produce a commit of unapproved decisions.

**The proposed answers are the deliverable of writing this plan.** Forty-seven
questions answered as proposals turns Milestone 0 from authorship into review. Any
one of them may be wrong; none of them is a guess presented as settled.
