# Blueworx Forge — canonical data model and field-ownership map

The entities from brief §12.2, their fields and relationships, which interface
may author each field, and which records are append-only. Milestones 2, 3, 7, 8
and 9 implement this document; Milestone 3's Sub-item schema is this document's
Work Item entity.

Cites: every decision, plus the gate fields in
[`workflow-state-machine.md`](workflow-state-machine.md) and the capabilities in
[`permission-matrix.md`](permission-matrix.md).

## Scoping

Per ARCH-3, the **site** is the scoping unit. Work, hours, packages and
onboarding belong to a Client Site. The Client exists above it as the grouping
for identity, people and memberships, so one person reaches several sites with
one login.

Three entities are deliberately **global** rather than scoped: User, Support
Package Version and Onboarding Template Version. Everything else is scoped to a
site, or to a client where the entity is about identity rather than work.

Every query passes through one server-side scoping layer (M2), so scoping cannot
be forgotten on a new endpoint.

## Entities

| Entity | Scope | Milestone | Purpose |
|---|---|---|---|
| Client | Global grouping | M2 | Client identity, legal and display name, status, timezone, permitted domains. |
| Client Site | Client | M2 | A site: its own workspace, work, hours, packages and onboarding. |
| Client Site Integration | Site | M2 | WordPress identity, authorised connection, key state, sync health, mail capability. |
| User | **Global** | M2 | One person, one account, one identity across every client. |
| Membership | Client + Site | M2 | A user's access role on a site, plus grants (Principal, Approver, cross-client). |
| Point of Contact Assignment | Site | M2 | Current and historical internal contact, with a defined fallback. |
| Work Item | Site | M3 | Every level of the WORK-1 chain and every work type. |
| Role Assignment | Work Item | M3 | Primary User, Reviewer, Deliverer, with planned hours per role. |
| Gate Completion | Work Item | M4 | A structured requirement's result, evidence, approver and time. |
| Changelog Event | Work Item | M3 | Immutable actor / action / time record. |
| Comment | Work Item | M3 | Client-visible or internal-only, in separate permission scopes. |
| Attachment | Work Item | M3 | Evidence and reference files, tenant-scoped. |
| Dependency | Work Item | M3 | Between work items, including unscheduled and blocked states. |
| Availability Pattern | User | M7 | Effective-dated weekly working hours by weekday. |
| Unavailability | User | M7 | Leave and other dated unavailable time. |
| Allocation | Work Item + User | M7 | Planned hours by role within a date range. |
| Support Package Version | **Global** | M8 | Immutable snapshot of hours, price, validity and terms. |
| Site Package Assignment | Site | M8 | Effective dates, pro-rata result, state, renewal, source. |
| Hour Ledger Entry | Site | M8 | Append-only allocation, reservation, usage, release, adjustment, expiry event. |
| Support Meeting Series | Site | M8 | Recurrence rule, timezone, host, attendees, planned hours, lifecycle state. |
| Support Meeting Occurrence | Series | M8 | Calculated or excepted date, status, planned hours, ledger state, link. |
| Onboarding Template Version | **Global** | M9 | Immutable checklist definition, sections, defaults, dependencies, launch-critical flags. |
| Site Onboarding | Site | M9 | Assigned template version snapshot, owners, derived progress, launch readiness. |
| Onboarding Step | Site Onboarding | M9 | Response, controlled status, due date, reviewer, feedback, approval history. |
| Onboarding Evidence | Onboarding Step | M9 | File or link metadata, submitter, version, safety scan result. |
| Request Submission | Site | M6 | Immutable client Request, Idea or Suggestion, intake state, conversion link. |
| Notification Event | Site | M10 | Trigger, recipients, unique event ID, delivery outcome. |
| Sync Event | Site | M10 | Versioned change, delivery, retry and resolution state. |

## Key entity fields

Only the fields that carry a decision or a gate are listed. Each entity also
carries `id`, `created_at`, `created_by`, `updated_at`, `version`.

### Work Item (M3)

The single entity for every level of the WORK-1 chain and every work type. One
table rather than five, because the hierarchy is a parent reference and the type
is a field — which is what lets levels be skipped.

| Field | Type | Required | Notes |
|---|---|---|---|
| `level` | Enum: project, milestone, feature, sub-feature | Yes | The WORK-1 rung. |
| `work_type` | Enum: feature, bug, feedback, task | Yes | Bug and Feedback attach at any level (WORK-1). |
| `parent_id` | Reference to Work Item | No | Must be a **higher** level than this item; levels may be skipped; null is permitted. |
| `site_id` | Reference to Client Site | Yes | The scoping unit (ARCH-3). Must match the parent's. |
| `stage` | Enum: the twelve stages | Yes | Written only by the transition service (M4). |
| `prior_stage` | Enum | No | Set on entry to Blocked, cleared on exit (WF-1). |
| `blocked_elapsed` | Duration | No | Accumulates across blocked periods (WF-1). |
| `terminal_outcome` | Enum: rejected, duplicate, cancelled, deferred, archived | No | Per WF-2. |
| `duplicate_of` | Reference to Work Item | No | Required when outcome is duplicate. |
| `cycle` | Integer | Yes | Increments on reopen; earlier cycles preserved (WF-4). |
| `self_reviewed` | Boolean | Yes | Permanently true where a Principal held every role (AUTH-3). |
| `override_used` | Boolean + reason | No | Permanently marked where WF-5 was used. |
| `commercial_class` | Enum: chargeable, free-bug | Yes | Set at Triage; free-bug per COMM-5. |
| `delivered_by_forge` | Boolean | No | Set at Bug Tracking; drives the COMM-5 result. |
| `planned_start`, `planned_due` | Date | At Up Next | Mandatory from Up Next only (WORK-3). |
| `review_target`, `release_target` | Date | No | Targets; never gate a transition (WORK-3). |
| `remaining_estimate` | Numeric hours | At In Development | Per CAP-3. |
| `release_method` | Enum per WF-6 | At Completed | software / content / design / infrastructure / non-deployment. |
| Definition fields | Problem, scope, non-goals, requirements, acceptance criteria, dependencies, references | Per gate | Client-editable until the item leaves Documentation Period (AUTH-2). |

Derived, never stored as authored values: `progress`, `derived_state`,
`derived_dates` — computed recursively from children (WORK-2). A parent reaches
Completed only when every child is Completed.

### Hour Ledger Entry (M8)

Append-only. A correction is a new entry, never an edit.

| Field | Type | Notes |
|---|---|---|
| `site_id` | Reference | The balance this affects (ARCH-3). |
| `event_type` | Enum | allocation, top-up, work-reservation, work-usage, work-release, meeting-reservation, meeting-usage, meeting-release, adjustment, expiry, rollover. |
| `hours` | Signed numeric | Positive adds, negative consumes. |
| `source_id` | Reference | The work item, occurrence, assignment or top-up it came from. |
| `reason` | Text | Required on adjustment, on the CAP-3 post-review adjustment, and on any negative-balance override. |
| `expires_at` | Date | Top-ups only; twelve months by default (COMM-4). |
| `actor_id` | Reference to User | Never system-anonymous. |

Consumption order is expiring-soonest first, package hours before top-ups where
both expire together (COMM-4).

### Support Meeting Occurrence (M8)

| Field | Type | Notes |
|---|---|---|
| `status` | Enum: scheduled, held, cancelled, no-show | Only **held** consumes hours (MEET-5). |
| `held_marked_by` | Reference to User | The host — our Point of Contact. Sole trigger for usage. |
| `planned_hours` | Numeric | Defaults to duration rounded up to the next half hour (MEET-3). |
| `ledger_state` | Enum: forecast, reserved, used, released | Reserved within the term and a rolling twelve weeks (MEET-4); released automatically if the occurrence passes unheld. |
| `excepted_from` | Date | A single moved occurrence, leaving the series rule intact (MEET-2). |
| `meeting_link` | URL | Pasted by the host; no calendar integration (MEET-6). |

No reminder fields: MEET-6 removes reminders entirely.

### Onboarding Step (M9)

| Field | Type | Notes |
|---|---|---|
| `section` | Enum: foundations, build-reviews, launch | Every step belongs to one (ONB-1). |
| `status` | Enum, eight values | Not started, in progress, submitted, returned, approved, not applicable, blocked, overdue. |
| `owner_side` | Enum: client, internal | From the template. |
| `reviewer_id` | Reference to User | Point of Contact by default, overridable per step (ONB-2). |
| `launch_critical` | Boolean | From the template; gates Released (ONB-1). |
| `provider`, `account_identifier`, `access_role`, `invitation_status`, `verification_outcome` | Text / enum | The ONB-3 handover fields. |
| — | — | **No credential field exists.** Validation rejects anything shaped like a password, key or card number (ONB-3). |

### Changelog Event, Gate Completion, Notification Event

| Entity | Fields |
|---|---|
| Changelog Event | `actor_id` or System, `action`, `previous_value`, `new_value`, `occurred_at` with timezone, `source_interface`, `reason`. |
| Gate Completion | `requirement_id` from the state machine, `result`, `evidence_ref`, `approver_id`, `completed_at`. |
| Notification Event | `event_id` (unique, the duplicate-suppression key), `recipients` resolved from verified client records only (NOTIF-1), `channel`, `delivery_outcome`, `attempt_count`. |

## Relationships and identity

- Work Item → Work Item (`parent_id`): many-to-one, must point at a higher level,
  may be null, may skip levels (WORK-1).
- Client → Client Site: one-to-many. Site owns work, hours, packages, onboarding.
- User → Membership → Client Site: many-to-many, one global user (AUTH-6).
- Site Package Assignment → Support Package Version: many-to-one against an
  immutable snapshot, so catalogue edits never rewrite history (COMM-1).
- Site Onboarding → Onboarding Template Version: the same snapshot rule (ONB-1).
- Hour Ledger Entry → its source: many-to-one, never null.

**Identity.** Every record carries a globally unique ID that is unambiguous
across both interfaces, so a client-site reference and a studio reference are the
same string. Every record carries a `version`; a write quoting a stale version is
rejected with the current state returned, never merged (ARCH-5).

**Cascade.** Deactivation only, never deletion (NOTIF-5). Deactivating a user
revokes memberships and leaves attribution intact. Deactivating a site hides its
work from default views and retains everything for reporting and export.

## Field-ownership map

Which interface may author each field. With one canonical database this is a
permission rule rather than a merge rule, but it is still what Milestone 2
enforces and what Milestone 6 renders read-only from.

| Field group | Studio | Client | System |
|---|---|---|---|
| Definition (problem, scope, requirements, acceptance criteria, references) | Author | Author until the item leaves Documentation Period, then read-only (AUTH-2) | — |
| Accountability (Primary User, Reviewer, Deliverer, substitutes) | Author | Read | — |
| Planning (hours, dates, priority, dependencies) | Author | Read | — |
| Workflow (stage, prior stage, terminal outcome, cycle) | Request only — the transition service writes | **Never** (§14, D-10 … D-19) | Author |
| Gate completions | Author, per the gate's named approver | Never | Timestamps and actor |
| Commercial (packages, ledger, balances) | Primary administrator only | Request an upgrade or top-up (COMM-6) | Reservation, usage, release, expiry |
| Meetings (series, occurrences, held) | Point of Contact or Primary administrator (MEET-1, MEET-5) | Request a change to one occurrence | Occurrence generation |
| Onboarding steps | Reviewer approves, returns, marks Not Applicable (ONB-2) | Respond, evidence, submit — on client-owned steps only | Derived completion and launch readiness |
| Comments and evidence | Author, including internal-only | Author, client-visible only | — |
| Derived (progress, parent state, balances, completion) | **Nobody** | **Nobody** | Author |
| Changelog, ledger, gate completions, sync events | **Append only** | **Append only** | Author |

## Immutability rules

Append-only entities, and what a correction looks like for each — because
"immutable" with no correction path means somebody eventually edits the table by
hand.

| Entity | Correction |
|---|---|
| Changelog Event | None. A mistaken action is corrected by a further action, which appends its own entry. |
| Hour Ledger Entry | A new `adjustment` entry with a reason, referencing the entry it corrects. |
| Gate Completion | Returning the item and completing the gate again appends a new completion; the prior attempt is preserved (WF-3). |
| Support Package Version | A new version. Assignments keep the snapshot they were made against. |
| Onboarding Template Version | A new version. Assigned checklists keep their snapshot (ONB-1). |
| Onboarding Evidence | A new submission; the prior submission stays in history (ONB-2). |
| Request Submission | None. The submission is fixed at submission; conversion links to it (REQ-1). |
| Notification Event | None. A retry updates the delivery outcome on the same event ID, which is what makes exactly-once possible (NOTIF-3). |

## Cross-check

- Every gate requirement in `workflow-state-machine.md` resolves to a field here.
- Every conditional cell in `permission-matrix.md` resolves to a field here or to
  a decision ID in `decisions.md`.
- Every entity in brief §12.2 appears above with an owning milestone. Comment,
  Attachment, Dependency, Availability Pattern, Unavailability and Allocation are
  additions this model makes explicit, each required by a decision or a gate.
