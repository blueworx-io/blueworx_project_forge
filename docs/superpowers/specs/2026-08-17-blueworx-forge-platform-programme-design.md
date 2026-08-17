# Blueworx Forge Platform — programme design

Date: 2026-08-17
Status: approved
Supersedes nothing. Extends `2026-08-17-blueworx-forge-rebuild-design.md`, which
covers only the empty skeleton.

## What this document is

The product brief (`design/research/brief.txt`, v0.3) describes roughly eight
subsystems over a shared tenant model. That is too large for one spec and one
implementation plan. This document is the decomposition: the build order, the
milestones, and the issues inside each milestone.

Each milestone gets its own spec and its own implementation plan when it starts.
This document does not replace those. It decides what gets built, in what order,
and why that order.

## Decisions taken to write this plan

| Question | Decision |
|---|---|
| Delivery across the two sites | One repo, one canonical REST contract, **two zips**. |
| The client workspace's data | Reads through the studio API. Holds no canonical records. |
| The brief's ~35 open P0 decisions | Answered first, as Milestone 0, grouped by subsystem. |
| MVP boundary | Milestones 0–6. Milestones 7–10 are the second release. |
| Sales checkout | Deferred behind manual package assignment. |

### Why two zips

A client's WordPress must not physically contain command-centre code. With one
artifact in two modes, tenant isolation rests on a runtime mode check, and the
failure shape of a bug in that check is cross-client data exposure — the exact
thing §14 forbids. Two build targets from one allowlist each means the client
artifact cannot leak what it does not contain, while the contract, the types and
the review process stay single-source.

The studio artifact is `blueworx-forge`. The client artifact is
`blueworx-forge-client`. Both are built from this repo by `npm run build:zip`,
which already stages from an explicit allowlist.

### Why the client workspace holds no canonical data

§12 recommends one canonical service with both sites as permission-scoped
interfaces, and lists what a local client database would additionally require: a
field-ownership matrix, a conflict queue, ordered transition events, dead-letter
handling. All of that disappears when the client site is a read-through client of
one database.

What survives, and is still built: globally unique IDs, record versioning,
idempotent writes so a retry cannot duplicate, and a visible last-sync status.

**This reverses if client sites must work while the studio is unreachable.** That
is a Milestone 0 decision, not an assumption. If the answer is yes, §12 returns in
full and the programme grows by roughly one milestone.

### Why the MVP stops at milestone 6

§15's "minimum product scope" is about a year of work. Milestones 0–6 deliver one
complete, gated, audited delivery cycle across both interfaces — which is the
thing that has to work before anything commercial is worth building. Capacity,
packages, onboarding and reporting all assume a working workflow engine and a
working client workspace; none of them makes the workflow more correct.

---

## Build order

The spine is: decisions → skeleton → tenant isolation → work records → workflow
engine → studio views → client workspace. Everything else hangs off it.

Tenant isolation comes before any feature because retrofitting it is how leaks
happen. The workflow engine comes before any view because the views are windows
onto it. The client workspace comes after the studio views because the client
workspace is the same views with authority removed, and removing authority from
something that exists is verifiable in a way that never granting it is not.

```
M0 Decisions
   └── M1 Skeleton (two zips, canonical API, site auth)
       └── M2 Tenants, identity, permissions
           └── M3 Work records
               └── M4 Workflow engine
                   ├── M5 Studio views ──── M6 Client workspace   ← MVP ends
                   ├── M7 Capacity
                   │   └── M8 Commercial & support meetings
                   ├── M9 Onboarding
                   └── M10 Operations

M11 MVP acceptance & release  ← depends on all of the above
```

M7, M9 and M10 can run in parallel with each other once M4 lands. M8 depends on
M7 because the support-hours gate and the capacity gate are evaluated together at
Up Next. M11 depends on everything, because it is the brief's acceptance criteria
turned into tests.

---

## Milestone 0 — Foundation decisions

**Goal.** Every P0 decision in brief §17 has a written, approved answer, and the
three documents §18.3 requires exist. Nothing is built on a guess.

**Done when.** The decision record is complete with no "Decision required" left
unanswered, and the permission matrix, state machine and data model documents are
approved.

Each issue below produces a section of `docs/architecture/decisions.md`, in the
form: the question, the options considered, the decision, and the consequence if
it is later reversed. I propose an answer in each; you approve or amend.

**Issues**

1. **Architecture and delivery decisions** — canonical architecture confirmed;
   client delivery model; whether a client may have multiple sites; whether client
   sites must function while the studio is unreachable; conflict rules and
   acceptable sync delay; how a client site authenticates to the studio.
2. **Workflow decisions** — Bug Tracking as conditional and Blocked as an
   exception state, or an alternative; the missing terminal states (Rejected,
   Cancelled, Duplicate, Deferred, Archived) and their reporting behaviour;
   authorised return paths from each stage; reopen behaviour; whether an
   administrator override exists at all, and if so its constraints; what
   "Released" means for software, content, design, infrastructure and
   non-deployment work.
3. **Authority and permission decisions** — who may approve Triage, Documentation,
   Technical Audit and Design gates; the client contribution boundary (which
   client roles may create parents and Sub-items, edit which fields, comment,
   attach evidence, answer information requests); whether Primary User, Reviewer
   and Deliverer must be different people; how substitutes work; which users,
   capacity indicators, reports and audit details a client may see.
4. **Work structure decisions** — whether Projects, Features and Milestones may
   contain each other; whether parent status is fully derived; mandatory date
   rules and timezone handling.
5. **Capacity decisions** — base working hours and their source; where leave comes
   from; whether hours are entered per role or derived from controlled defaults;
   whether actuals are tracked; whether over-allocation blocks the move or
   requires a reasoned override.
6. **Commercial decisions** — whether assignment starts a new twelve-month term or
   aligns to a shared renewal date; pro-rata hours, pro-rata pricing, date basis
   and rounding; reservation in Up Next and usage in In Development confirmed,
   plus cancellation, adjustment and negative-balance rules; top-up expiry,
   consumption order and annual rollover; whether validated bug work for a
   no-package client is free, consumes top-ups, or requires a package; the
   checkout route and whether upgrades are immediate or approved.
7. **Support meeting decisions** — schedule ownership; recurrence and exception
   rules; permitted client change requests; planned billable-hour defaults;
   reservation horizon; late-cancellation and no-show charging; insufficient
   balance handling; reminders; whether an external calendar integration is
   required.
8. **Onboarding decisions** — the template(s) and their versions; which steps are
   mandatory and which are launch-critical; step dependencies; client versus
   internal ownership; reviewer authority and return/approval rights; authorised
   Not Applicable decisions; the derived completion threshold; the approved secure
   access-handover method and what reference fields Forge stores.
9. **Notification and retention decisions** — email recipient source (submitter,
   nominated contact, both, configurable); whether Completed or Released is the
   final client confirmation; email templates and sender identity; retry and
   escalation rules; the additional in-app events; deletion, archive, export,
   attachment retention and audit-history requirements.
10. **Write the permission matrix** — every access role against every capability,
    for both interfaces, including the explicit denials. This is the document the
    M2 test suite is written from.
11. **Write the workflow state machine and gate specification** — the twelve
    stages, permitted transitions, each exit gate as a list of structured
    requirements, exception entry and exit, terminal outcomes.
12. **Write the canonical data model and field-ownership map** — the entities from
    §12.2, their relationships, and which interface may author each field.

---

## Milestone 1 — Platform skeleton

**Goal.** Two artifacts boot, ship and test green with no features in them, and a
client site can authenticate to the studio and read one canonical record.

**Done when.** Both zips install and activate on a clean WordPress with no PHP
notice; a registered client site reads a record from the studio over the API; an
unauthenticated write is refused; CI runs lint, build, PHPCS, PHPUnit and
Playwright against real WordPress instances.

This milestone absorbs the existing skeleton plan
(`docs/superpowers/plans/2026-08-17-blueworx-forge-skeleton.md`) as its first
issue and extends it to two artifacts.

**Issues**

1. **Reboot the plugin as Blueworx Forge** — the existing skeleton plan: new slug,
   namespace, constants, text domain, autoloader, `Plugin`, `Rest\Server`,
   `Frontend`, the removal pass, version 2.0.0, and the four skeleton Playwright
   specs.
2. **Split the build into two zips** — separate allowlists for
   `blueworx-forge` and `blueworx-forge-client`; a CI check that the client
   allowlist cannot admit studio code; both artifacts published by the release
   workflow.
3. **Establish the canonical REST namespace and route conventions** — versioned
   namespace, explicit permission callback on every route, error envelope, the
   convention for a gate-failure response, request versioning and idempotency
   keys.
4. **Authenticate a client site to the studio** — site registration from the
   studio, key issue and rotation, signed requests, revocation, and a client site
   that presents an invalid or revoked key being refused with the attempt logged.
5. **Read-through data layer and last-sync status** — the client artifact's
   fetch, cache and staleness handling, and a visible last-sync indicator.
6. **Wire the design token and theme layer into both builds** — the imported v3
   tokens and theme aliases available to both front ends from one source.
7. **Two-instance test harness** — provision a studio WordPress and a client
   WordPress, registered to each other, in one command, for local use and CI.
8. **PHPUnit and PHPCS from the first commit** — a passing unit test so the data
   layer has somewhere to go, WordPress Coding Standards clean.

---

## Milestone 2 — Tenants, identity and permissions

**Goal.** The security spine. Every read is tenant-scoped server-side before any
feature exists to scope.

**Done when.** A user of client A cannot retrieve, enumerate or infer any record
of client B through the UI, the REST API, a filter parameter, a search query or a
replayed request — proved by a negative test suite, not by inspection.

**Issues**

1. **Client/tenant entity** — identity, display and legal name, status, timezone,
   permitted domains.
2. **Client site integration record** — WordPress site identity, authorised
   connection, sync health, mail capability status.
3. **Global user identity and client memberships** — one person, many client
   memberships, each with its own access role.
4. **Access roles and capability map** — implemented from the M0 permission
   matrix, including the explicit denials.
5. **Server-side tenant scoping** — a single enforcement point every query passes
   through, so scoping cannot be forgotten per-endpoint.
6. **Cross-client access for authorised studio users** — the explicit grant, and
   its absence being the default.
7. **Tenant isolation test suite** — the negative tests: enumeration, filter
   injection, direct ID access, search leakage, replayed requests, and a
   deactivated user's loss of access with attribution intact.
8. **Point of Contact assignment** — one current internal contact per client,
   history preserved, deactivated contact flagged for reassignment with a defined
   fallback, no private staff data exposed.

---

## Milestone 3 — Work records

**Goal.** The canonical work data model, with immutable history, and nothing that
moves it yet.

**Done when.** A parent and its Sub-items can be created and edited through the
API with full field coverage; every material change appends a changelog entry that
cannot be edited or deleted; derived parent progress reflects the work beneath it.

**Issues**

1. **Parent work entity** — Project, Feature and Milestone, with nesting per the
   M0 decision.
2. **Sub-item entity** — the field groups from §2.2: identity, definition,
   accountability, planning, workflow, delivery evidence, history, commercial
   entitlement.
3. **Role assignment** — Primary User, Reviewer, Deliverer, with planned hours by
   role, visible on every card.
4. **Immutable changelog** — the event writer, its entry shape (actor, action,
   previous and new values, time and timezone, source interface, reason), and the
   guarantee that entries are append-only.
5. **Comments, evidence and attachments** — inheriting the parent record's tenant
   permissions, with internal-only notes in a separate permission scope from
   client-visible ones.
6. **Derived parent progress** — computed from Sub-items, not editable directly.
7. **Global IDs, record versioning and idempotent writes** — so a retried write
   cannot duplicate and a stale write cannot silently win.
8. **Sub-item dependencies** — the relationship, and unscheduled or blocked
   dependency states surfaced rather than hidden.

---

## Milestone 4 — Workflow engine

**Goal.** The state machine, its gates and its exceptions, enforced at the
canonical service. This is the heart of the product.

**Done when.** A Sub-item cannot advance with an unmet gate requirement; a failed
gate names every missing requirement and leaves the stage unchanged; a client role
is refused every transition path — UI, direct API and replayed event — with the
attempt logged and the item unchanged.

**Issues**

1. **Stage registry** — the twelve fixed stages, with creation, renaming,
   reordering and deletion impossible by design rather than by permission.
2. **Gate definitions as structured records** — each requirement a field or
   checklist record, not free text, each completion carrying actor and time.
3. **Transition service** — single-step forward movement, gate validation, atomic
   commit of stage change plus gate records plus changelog.
4. **Gate failure response** — every missing requirement listed, in a shape the
   UI can render against the item.
5. **Authorised returns** — target stage and reason required; In Review returns to
   In Development with recorded feedback and the prior review attempt preserved.
6. **Blocked as an exception state** — entry from any active stage, reason, owner,
   dependency, target date and next action required; prior stage stored and
   restored on resolution without losing elapsed blocked time.
7. **Bug Tracking as a conditional stage** — entered only when Triage classifies
   the item as a bug.
8. **Terminal outcomes** — Rejected, Cancelled, Duplicate, Deferred and Archived
   per M0, each with a defined destination and reporting behaviour.
9. **Reviewer and Deliverer authority** — only the assigned Reviewer or an
   authorised substitute approves In Review to Completed; only the assigned
   Deliverer or substitute confirms Completed to Released.
10. **Reopen route** — never erases the earlier completion or release record.
11. **Administrator override** — if M0 permits one: reason required, permanently
    identified in the audit log.
12. **Client transition lock** — server-side denial of every workflow mutation
    under a client role, covering board moves, direct field updates, transition
    endpoints and replayed requests; each denial logged with user, item, source
    and time.
13. **Workflow engine test suite** — every stage, every gate, every exception,
    every denial path.

---

## Milestone 5 — Studio views

**Goal.** The command centre. First point at which a full delivery cycle can be
run by a human.

**Done when.** A Sub-item can be taken from Future Idea to Released entirely
through the interface, with every gate satisfied through the UI; and Kanban,
Gantt, Calendar and detail all reconcile to the same records and counts.

**Issues**

1. **App shell and navigation** — built on the imported kit's DataView grammar and
   the twelve-stage vocabulary.
2. **Kanban** — fixed workflow columns, parent and Sub-item grouping, gate
   readiness, blocked state, age, assignees and due dates on cards.
3. **Drag as a transition request** — validation invoked on drop, gate failure
   surfaced against the card, stage unchanged on failure.
4. **Gantt** — parent and Sub-item dates, dependencies, milestones, sequencing,
   overdue work, progress roll-up, and unscheduled work shown separately.
5. **Calendar** — planned start, due, review and release dates across day, week
   and month, under the same filters and permissions.
6. **Sub-item detail** — one consistent detail experience from every view, with
   the gate panel, evidence, comments and the Changelog tab.
7. **Shared filter and saved-view layer** — the §5.1 filter set; saved views may
   change filters and grouping but never workflow rules.
8. **Cross-view reconciliation test** — counts and totals agree across every view.
9. **Loading, empty, error and permission-denied states** — on every view.

---

## Milestone 6 — Client workspace — *MVP ends here*

**Goal.** The client's own site shows the same data with no authority to move it,
and can submit intake.

**Done when.** A client user sees only their own work, cannot move a card or reach
a transition endpoint by any route, can submit a request and see its status; and a
studio user can convert an accepted request into pipeline work linked back to the
immutable submission.

**Issues**

1. **Client workspace shell** — permission-scoped navigation, filters permanently
   restricted to the current client and site.
2. **Client dashboard** — Point of Contact, support summary, client-visible
   upcoming work, attention items, with a clear empty state for each section.
3. **Read-only boards** — Kanban, Gantt and Calendar with no drag, no stage-edit
   and no transition controls rendered at all.
4. **Requests, Ideas and Suggestions submission** — controlled type, title,
   description, desired outcome, evidence; available with no support package.
5. **Client submission status view** — own submissions only, intake status,
   response, Point of Contact, converted work link.
6. **Studio request review queue** — cross-client aggregation with the §9.2 filter
   set and intake states.
7. **Controlled pipeline conversion** — choose or create a parent, create or link
   the Sub-item for the same client, enter at Future Idea or Triage; submission
   stays immutable and linked; never converted into another client's pipeline.
8. **Permitted non-transition client actions** — comment, attach evidence, answer
   an information request, without changing stage.
9. **Denial states and their messaging** — what a client sees when an action is not
   theirs to take.
10. **Client isolation and denial test suite** — run against the client artifact
    on its own WordPress, not simulated.

---

## Milestone 7 — Capacity

**Goal.** One global staff identity, so a person cannot look available on one
client while committed on another.

**Done when.** A user's commitments from every client appear once in their
capacity; Up Next cannot be reached without hours, dates and all three roles; the
capacity check is revalidated on entry to In Development; and a client site shows
a privacy-safe availability result with no other client's identity, title or
commercial data.

**Issues**

1. **Base availability** — working hours by day and week, part-time patterns,
   leave and other unavailable time.
2. **Planned allocations by role** — execution, review and delivery hours within a
   date range, per the M0 decision on how hours are entered.
3. **Cross-client capacity calculation** — every authorised assignment counted
   once.
4. **Capacity view** — available, committed and remaining by person and period,
   with status thresholds and studio drill-down.
5. **Privacy-safe client availability** — the client-site view, and a test that it
   leaks nothing.
6. **Capacity gate at Up Next** — hours, dates and all three roles required.
7. **Capacity revalidation on In Development** — commitments may have changed
   since planning.
8. **Over-allocation enforcement** — block or reasoned override, per M0, with the
   result audited.
9. **Recalculation and audit on change** — an hours or dates change recalculates
   both interfaces and records an event.

---

## Milestone 8 — Commercial and support meetings

**Goal.** One hour ledger, shared by development work and recurring meetings, that
reconciles identically on both interfaces.

**Done when.** A package assigned on an effective date adds the exact annual or
pro-rata hours to the ledger; Up Next reserves hours and In Development converts
them to usage; a held meeting converts its reservation to usage and a valid
cancellation releases it; and the studio and client show the same balance.

**Issues**

1. **Package catalogue** — create, edit, retire, reorder, with immutable version
   snapshots so catalogue edits never rewrite client history.
2. **Client package assignment** — effective dates, the four support states,
   scheduling, replacement, renewal, suspension and cancellation.
3. **Pro-rata calculation** — exact dates including leap years, with a preview
   before assignment or purchase.
4. **Immutable hour ledger** — the nine event types from §8.4, each linked to its
   source.
5. **Work reservation and usage lifecycle** — reserve at Up Next, convert at In
   Development, release on cancellation, adjust by appended entry only, atomic
   with the Sub-item's allocation so balances cannot drift.
6. **Support-hours gate** — independent of the capacity gate; both results stay
   visible when one passes and the other fails.
7. **No Support Package state** — enforced by the service, not by hidden
   navigation; bug intake, request intake, Sales and Point of Contact stay
   available.
8. **Recurring support meeting series** — zero to many per client, recurrence rule,
   timezone, host, attendees, location, planned billable hours, lifecycle state.
9. **Meeting occurrences** — calculated and excepted dates, Scheduled / Held /
   Cancelled / No Show, single-occurrence reschedule without rewriting the series
   rule, all audited.
10. **Meeting hour lifecycle** — reservation within the active term, usage on
    held, release on valid cancellation, transfer on reschedule, forecast beyond
    the term, per the M0 no-show and late-cancellation policy.
11. **Meeting impact on staff capacity** — occurrences reduce assigned staff
    availability without exposing other-client commitments.
12. **Client Sales page** — current entitlement, balances, available packages and
    top-ups, pro-rata preview, upgrade or top-up request, purchase history.
13. **Studio package and sales administration** — assignment, top-ups, reasoned
    manual adjustments, and the cross-client view of clients with no package,
    insufficient hours or upcoming expiry.
14. **Balance reconciliation tests** — the same totals on both interfaces, and per
    Sub-item and per occurrence drill-down agreeing with the ledger.

Checkout and payment are out of scope here. Manual studio assignment is required
regardless of the eventual route, so it is what gets built.

---

## Milestone 9 — Client onboarding

**Goal.** A predetermined, centrally managed launch-readiness checklist, tracked on
both interfaces, gating go-live.

**Done when.** A client can complete, evidence and submit assigned steps but
cannot create, delete, reorder or approve them; internal review appears on the same
step on the client site with immutable history; and a site cannot be marked
Released while a launch-critical step is neither Approved nor authorised Not
Applicable.

**Issues**

1. **Versioned onboarding template** — the §11.2 categories, step definitions,
   defaults, dependencies and launch-critical flags.
2. **Template assignment with snapshot** — later template edits never rewrite
   completed work.
3. **Onboarding step entity** — the §11.3 field set, the eight statuses, and full
   change history.
4. **Client checklist page** — respond, attach evidence, comment, submit for
   review; no step creation, deletion, reordering or approval.
5. **Internal review** — approve, return with client-visible feedback, or record a
   reasoned Not Applicable where the template permits.
6. **Derived completion** — from approved required steps; not manually editable.
7. **Studio onboarding board** — cross-client, filtered by client, template,
   Point of Contact, owner, status, overdue, blocked and launch readiness.
8. **Launch gate** — Released blocked until every launch-critical step clears.
9. **Credential safety** — prohibited field content enforced, not merely
   documented; provider links, account identifiers, access role, invitation status
   and verification outcome stored instead.
10. **Evidence storage** — tenant-scoped metadata, malware checking, retention
    rules, immutable submission history.

---

## Milestone 10 — Operations

**Goal.** The daily working surface, the client emails, the reports and the sync
health view.

**Done when.** Standup surfaces every item matching its rules and removes them only
when the underlying condition resolves; exactly one confirmation email is sent per
qualifying event regardless of retries or repeated loads; and a failed or delayed
event is visible and recoverable.

**Issues**

1. **Standup inclusion-rule engine** — the twelve rules from §6, evaluated from
   current state rather than stored on the item.
2. **Standup board** — sections, card content, and the rule that dismissing a card
   cannot hide unresolved work.
3. **Standup actions** — open and complete the missing requirement, subject to
   permission.
4. **Notification event model** — unique event IDs so sync, retries and repeated
   loads cannot duplicate a confirmation.
5. **WordPress mail delivery** — sent through `wp_mail` on the correct client site
   so the site's own SMTP configuration handles it; Forge stores no SMTP
   credentials.
6. **Delivery logging and retry** — queued, sent, failed, retried and suppressed
   outcomes recorded on the item changelog; unresolved failures surfaced to
   Standup.
7. **In-app notifications** — the §13.3 event list.
8. **Operational reports** — the §13.4 set: stage distribution, time in stage,
   cycle time, blocked time, review turnaround, planned versus actual, capacity
   utilisation, throughput, overrides, package and meeting hours, onboarding
   readiness, request funnel, email delivery.
9. **Sync health monitoring** — delayed and failed events, last-sync status per
   client site, and an intervention queue.

---

## Milestone 11 — MVP acceptance and release

**Goal.** The brief's §16 acceptance criteria pass as automated tests, and the two
artifacts ship.

**Done when.** Every §16 criterion has a passing Playwright spec running against a
real studio-and-client pair, and both zips are published by the release workflow.

**Issues**

1. **Acceptance specs — workflow and gates** — blocked advancement on an unmet
   gate, transition recording, Reviewer-only approval, Deliverer-only release,
   failed review returning with feedback, Blocked restoring its prior stage.
2. **Acceptance specs — tenant and client lock** — client-created work appearing
   once on the studio, studio edits reaching the correct client site, another
   client's user unable to access or infer the record, and the client transition
   lock across UI, direct API and replayed event.
3. **Acceptance specs — capacity and commercial** — cross-client capacity counted
   once, package assignment producing exact annual or pro-rata hours, reservation
   and usage lifecycle, meeting-hour reservation and release, the no-package
   restriction and what stays available under it, and balances reconciling on both
   interfaces.
4. **Acceptance specs — onboarding and operations** — Standup inclusion and
   removal, onboarding submission and review round-trip, the launch-critical gate,
   exactly-once email delivery, cross-view reconciliation, and a failed or delayed
   event being visible and recoverable.
5. **Accessibility pass** — alt text, form labels, contrast, keyboard access,
   heading order, on every view of both interfaces.
6. **Performance and query-count pass** — the cross-client views are the risk;
   Kanban and Capacity across every client are where a naive query model fails.
7. **Release** — version, changelog, both zips, update checker wiring, and the
   migration note for moving items across from Forge Project Management by hand.

---

## Risks

**The decision milestone stalls.** Twelve issues of unanswered product questions
is the critical path for the entire programme, and nine of them need your judgement
rather than mine. Each issue proposes an answer, so the work is approval rather
than authorship, but nothing after M1 can start on guesses.

**The client-workspace assumption.** If client sites must work while the studio is
unreachable, the read-through model is wrong and §12's full sync architecture
returns. Decided in M0, issue 1 — deliberately the first thing settled.

**The hour ledger and capacity are the hardest correctness problems.** Two
independent gates, reservations that convert and release, meetings drawing from the
same pool, and both interfaces obliged to agree. It sits behind the MVP boundary
for that reason: get the workflow right first.

**Seventeen design screens stay in the design project.** They are read when the
screen is built rather than copied now, so what gets built follows the current
design rather than a stale snapshot. The cost is that a design change between
milestones is invisible until the screen is opened.

**Scope.** The brief's §15 is a year of work. The MVP boundary at milestone 6 is
what makes the first release reachable, and it is a recommendation you have
approved rather than a constraint the brief imposes.
