# Blueworx Forge — workflow state machine and gate specification

The twelve stages, every permitted transition, and every exit gate expressed as
structured requirements. Milestone 4 implements this document directly: the stage
registry is this document's stage list, and the workflow test suite is one test
per row of the transition table.

**A transition that does not appear in the transition table does not exist.**

Cites: [WF-1](decisions.md) … WF-6, AUTH-1, AUTH-3, AUTH-4, CAP-4, COMM-3,
COMM-5, WORK-1, WORK-3.

## What runs this workflow

Per WORK-1, only the **lowest item in a work chain** runs the stages and gates.
A Sub-Feature with nothing beneath it runs the workflow; the Feature above it
derives its state from what is underneath. A Feature with no children is itself
the lowest and runs the workflow in its own right. Bug and Feedback items are
work types, not levels, and always run the workflow when they have nothing
beneath them.

Parent items above the lowest level never transition directly. Their state is
derived per WORK-2, and a parent reaches Completed only when everything beneath
it is Completed.

## The twelve stages

| # | Stage | Kind | Purpose |
|---|---|---|---|
| 1 | Future Idea | Linear | Capture an opportunity without committing delivery resources. |
| 2 | Triage | Linear | Decide whether and how the item proceeds. |
| 3 | Bug Tracking | Conditional | Capture and validate a bug before specification. Entered only from Triage, only when Triage classifies the item as a bug (WF-1). |
| 4 | Documentation Period | Linear | Create the approved definition of work. |
| 5 | Technical Audit | Linear | Confirm feasibility, approach and technical risk. |
| 6 | Design Process | Linear | Define the required user experience and states. |
| 7 | Blocked | Exception | Pause active work without hiding responsibility. Enterable from any active stage; stores and restores the prior stage (WF-1). |
| 8 | Up Next (Assign Hours) | Linear | Commit ready work to the delivery queue. |
| 9 | In Development | Linear | Complete the defined work. |
| 10 | In Review | Linear | Independently confirm the work is acceptable. |
| 11 | Completed | Linear | Mark approved work ready for delivery. |
| 12 | Released | Linear | Confirm delivery to the agreed destination. |

Stages cannot be created, renamed, reordered or deleted. That is a property of
the registry, not a permission anyone holds.

### Terminal outcomes

Per WF-2, five outcomes end an item without release. All five remain in reporting
and in cycle-time calculations, flagged by outcome.

| Outcome | Reachable from | Requires | Reporting behaviour |
|---|---|---|---|
| Rejected | Triage | Reason | Counted, flagged rejected |
| Duplicate | Triage | Link to the surviving item | Counted, excluded from throughput, attributed to the survivor |
| Cancelled | Any active stage | Reason; releases any hour reservation (COMM-3) | Counted, flagged cancelled |
| Deferred | Triage, Up Next | Reason; returns the item to Future Idea | Counted as deferred, remains an open item |
| Archived | Any terminal state | — | Hidden from default views, never from reports |

## Transition table

"Requester" is who may ask; "Approver" is who may commit it where those differ.
Every row commits atomically with its gate records and a changelog entry.

### Forward path

| From | To | Requester | Approver | Gate |
|---|---|---|---|---|
| — | Future Idea | Staff, Client administrator (AUTH-2) | — | G-CREATE |
| Future Idea | Triage | Staff | — | G-FUTURE-IDEA |
| Triage | Bug Tracking | Staff | Triage Approver (AUTH-1) | G-TRIAGE, work type = Bug |
| Triage | Documentation Period | Staff | Triage Approver | G-TRIAGE, work type ≠ Bug |
| Bug Tracking | Documentation Period | Staff | Triage Approver | G-BUG-TRACKING |
| Documentation Period | Technical Audit | Staff | Documentation Approver | G-DOCUMENTATION |
| Technical Audit | Design Process | Staff | Technical Approver | G-TECHNICAL-AUDIT |
| Design Process | Up Next | Staff | Design Approver | G-DESIGN |
| Up Next | In Development | Staff | — | G-UP-NEXT |
| In Development | In Review | Primary User | — | G-IN-DEVELOPMENT |
| In Review | Completed | Reviewer or authorised substitute (AUTH-3, AUTH-4) | — | G-IN-REVIEW |
| Completed | Released | Deliverer or authorised substitute | — | G-COMPLETED |
| Released | — | — | — | G-RELEASED recorded on entry |

Design Process may be exited with an approved Not Applicable decision for
non-UI work, recorded as a structured requirement rather than a skipped stage.

### Returns, exceptions and terminal moves

| From | To | Requester | Requires |
|---|---|---|---|
| Any active stage | Any earlier stage the item has occupied | Staff | Target stage and **a reason — mandatory on every backwards move without exception** (WF-3) |
| In Review | In Development | Reviewer | Reason plus recorded feedback; the prior review attempt is preserved |
| Any active stage | Blocked | Staff | G-BLOCKED-ENTRY; prior stage stored |
| Blocked | Stored prior stage | Staff | G-BLOCKED-EXIT; elapsed blocked time retained |
| Triage | Rejected / Duplicate / Deferred | Staff | Per the terminal outcomes table |
| Any active stage | Cancelled | Staff | Reason; releases hour reservation |
| Up Next | Deferred | Staff | Reason; returns to Future Idea |
| Any terminal state | Archived | Staff | — |
| Completed or Released | Documentation Period or In Development | Staff | Reopen reason; target chosen at reopen. Creates a **new work cycle**; the earlier completion and release records are preserved as a closed cycle (WF-4) |
| Any stage | Any stage | Primary administrator only | The WF-5 override: reason required, permanently marked on the item, listed in the override report. **Cannot bypass the client transition lock.** |

A return to a stage the item has never occupied is not a return. It is a
correction and requires the override.

## Exit gates

Every requirement below is a structured field or checklist record — never a free
text note — and every completion carries its actor and completion time. Each
requirement states what satisfies it and who may mark it complete.

Legend for "Who": **PU** Primary User, **REV** Reviewer, **DEL** Deliverer,
**APR** the gate's Approver capability (AUTH-1), **ANY** any staff user.

### G-CREATE — creating an item

| # | Requirement | Type | Evidence | Who |
|---|---|---|---|---|
| 1 | Title | Text, required | No | ANY |
| 2 | Site scope | Reference to Client Site | No | ANY |
| 3 | Work type | Enum: Feature, Bug, Feedback, Task | No | ANY |
| 4 | Creator and source | System-recorded | No | System |

### G-FUTURE-IDEA — Future Idea → Triage

| # | Requirement | Type | Evidence | Who |
|---|---|---|---|---|
| 1 | Item description | Text, required | No | ANY |
| 2 | Site confirmed | Reference | No | ANY |
| 3 | Source | Enum: client request, internal, bug report, meeting | No | ANY |
| 4 | Submitted for triage | Action record | No | System |
| 5 | Doing the work assigned | Reference (2026-09-19) | No | ANY |
| 6 | Reviewing it assigned, somebody other than the person doing the work | Reference (2026-09-19) | No | ANY |

Nothing here asks about a parent. The parent field stays on the item and
nothing requires it.

### G-TRIAGE — Triage → Bug Tracking / Documentation Period

| # | Requirement | Type | Evidence | Who |
|---|---|---|---|---|
| 1 | Work type confirmed | Enum | No | APR (triage) |
| 2 | Site confirmed | Reference | No | APR |
| 4 | Priority | Enum | No | ANY |
| 6 | Duplicate check | Pick: no duplicate found, or the item this duplicates | No | ANY |
| 7 | Triage outcome | Enum: proceed, rejected, duplicate, deferred | No | APR |
| 8 | Who pays | Enum: chargeable, free bug under COMM-5 | No | APR |

### G-BUG-TRACKING — Bug Tracking → Documentation Period

| # | Requirement | Type | Evidence | Who |
|---|---|---|---|---|
| 1 | Bug classification | Enum | No | ANY |
| 2 | Expected versus actual result | Text, required | No | ANY |
| 3 | Reproduction steps | Text, required | No | ANY |
| 4 | Environment and version | Text, required | No | ANY |
| 5 | Evidence attached | Comment with a link | **Yes** | ANY |
| 6 | Impact and severity | Enum | No | ANY |
| 7 | Initial diagnosis | Text, required | No | ANY |
| 8 | Delivered by Forge | Enum; sets the COMM-5 free-bug result | No | APR (triage) |

### G-DOCUMENTATION — Documentation Period → Technical Audit

| # | Requirement | Type | Evidence | Who |
|---|---|---|---|---|
| 1 | Item description | Text, required | No | ANY |
| 3 | Not covered | Text, required | No | ANY |
| 5 | Completed when | Text, required | No | ANY |
| 7 | Affected sites and data | Pick | No | ANY |
| 9 | Documentation approval | Approval record | No | REV — the item's reviewer or their AUTH-4 substitute; an administrator may act for anyone |

Reference material, links (up to ten) and images (stored in the media
library) are optional fields of the task, not requirements.

### G-TECHNICAL-AUDIT — Technical Audit → Design Process

The reviewer signing off the documentation. The write-in assessments
(architecture, data and sync, security and privacy, test approach, estimate
range) went on 2026-09-19.

| # | Requirement | Type | Evidence | Who |
|---|---|---|---|---|
| 7 | Risks | Checklist record | No | ANY |
| 8 | Technical approval | Approval record | No | REV |

### G-DESIGN — Design Process → Up Next

| # | Requirement | Type | Evidence | Who |
|---|---|---|---|---|
| 1 | Design link | URL field on the task | No | ANY |
| 2 | Responsive states | Checklist record | No | ANY |
| 3 | Empty, loading, error and permission-denied states | Checklist record | No | ANY |
| 5 | Design approval | Approval record | No | REV |
| — | **Or** an approved Not Applicable decision for non-UI work | Approval record with reason | No | REV |

### G-UP-NEXT — Up Next → In Development

| # | Requirement | Type | Evidence | Who |
|---|---|---|---|---|
| 1 | Primary User assigned | Reference | No | ANY |
| 2 | Reviewer assigned, different from Primary User unless Principal (AUTH-3) | Reference | No | ANY |
| 3 | Deliverer assigned | Reference | No | ANY |
| 4 | Planned hours per role | Numeric ×3, seeded per CAP-2 | No | ANY |
| 5 | Planned start and due date | Dates, mandatory here per WORK-3 | No | ANY |
| 6 | Priority confirmed | Enum | No | ANY |
| 8 | **Capacity check** passed, or a reasoned over-allocation override (CAP-4) | System result plus optional override record | No | System / Primary administrator |
| 9 | **Support-hours check** passed, or the item is a COMM-5 free bug | System result | No | System |

Requirements 8 and 9 are evaluated independently and **both results are always
reported**, so a pass on one and a failure on the other shows both.

Dependencies are connected from the task's Dependencies card, not asked here.
Every date on the item keeps its order — start ≤ due ≤ review by ≤ release by,
among those set — whenever any of them is written.

### G-IN-DEVELOPMENT — In Development → In Review

| # | Requirement | Type | Evidence | Who |
|---|---|---|---|---|
| 1 | Checklist complete | Worked out from the task's checklist; an empty checklist counts as complete | No | PU |
| 2 | Work evidence | Comment with a link | **Yes** | PU |
| 3 | How to test | Text, required, under Review and testing | No | PU |
| 6 | Submitted to Reviewer | Action record | No | System |

Capacity is revalidated on entry to In Development, and hours convert from
reservation to usage on entry (COMM-3). Recurring and subscription check-ins
do not run this gate: they are born at Up Next and go to Released when everyone
named has ticked, and a tick is refused while the checklist has unticked lines.

### G-IN-REVIEW — In Review → Completed

| # | Requirement | Type | Evidence | Who |
|---|---|---|---|---|
| 1 | Review checklist | Checklist record | No | REV or substitute |
| 2 | Every acceptance criterion confirmed | Checklist record | No | REV |
| 3 | All feedback resolved | Worked out from open client questions | No | REV |
| 4 | Review approval | Approval record | No | REV — the assigned Reviewer or an AUTH-4 substitute only |
| 5 | Post-review hours adjustment (0 for none) | Number (CAP-3, COMM-3) | No | REV |

A failed review returns to In Development with a reason and recorded feedback.
The prior review attempt is preserved.

### G-COMPLETED — Completed → Released

| # | Requirement | Type | Evidence | Who |
|---|---|---|---|---|
| 1 | Review approval preserved | System check | No | System |
| 2 | Release method | Enum per WF-6: software, content, design, infrastructure, non-deployment | No | DEL |
| 3 | Target environment, version or destination | Text, required | No | DEL |
| 4 | Release window | Date | No | DEL |
| 5 | Delivery checklist | Checklist record | No | DEL |
| 6 | Dependencies ready | Worked out: everything this waits on has reached Completed | No | DEL |
| 7 | Release notes | Text, required | No | DEL |
| 8 | Every child item Completed, where the item has children (WORK-2) | System check | No | System |

### G-RELEASED — recorded on entry to Released

| # | Requirement | Type | Evidence | Who |
|---|---|---|---|---|
| 1 | Release date and time | Timestamp | No | DEL or substitute |
| 2 | Environment and version, or handover destination | Text per WF-6 | No | DEL |
| 3 | Release evidence | Comment with a link | **Yes** | DEL |
| 4 | Client communication status | System result from the NOTIF-2 confirmation | No | System |
| 5 | Post-release check | Checklist record | No | DEL |

### G-BLOCKED-ENTRY — any active stage → Blocked

| # | Requirement | Type | Evidence | Who |
|---|---|---|---|---|
| 1 | What is blocking it | Pick: an item, or something else | No | ANY |
| 2 | Who owns the blocker | Pick | No | ANY |
| 3 | What it is waiting on | Pick: an item, or something else | No | ANY |
| 4 | Target resolution date | Date | No | ANY |
| 5 | Next action | Text, required | No | ANY |
| 6 | Prior stage stored | System-recorded | No | System |

### G-BLOCKED-EXIT — Blocked → stored prior stage

| # | Requirement | Type | Evidence | Who |
|---|---|---|---|---|
| 1 | Resolution note | Text, required | No | ANY |
| 2 | Return to the stored prior stage | System-enforced; no target choice | No | System |
| 3 | Elapsed blocked time retained | System-recorded | No | System |

Requirement numbers are the ids in `includes/Work/Gates.php` and stay put when
a requirement is retired, so a gap in a table is a requirement that went, not
one missing from the page.

## Gate-failure contract

A failed transition changes nothing. The response carries the item's unchanged
stage and **every** unmet requirement — not the first one found:

```
{
  "ok": false,
  "item_id": "...",
  "stage": "up-next",             // unchanged
  "attempted": "in-development",
  "unmet": [
    {
      "id": "G-UP-NEXT-4",
      "label": "Planned hours per role",
      "satisfied_by": "Enter planned hours for Primary User, Reviewer and Deliverer.",
      "type": "numeric",
      "evidence": false,
      "who": "ANY"
    }
  ],
  "checks": [
    { "id": "G-UP-NEXT-8", "label": "Capacity check", "result": "pass", "note": "" },
    { "id": "G-UP-NEXT-9", "label": "Support-hours check", "result": "pass", "note": "" }
  ]
}
```

`checks` appears only for a gate that has system results. `unmet` carries the
requirement's type, whether it needs evidence and who may complete it, because
the screen rendering it has to draw the right control and say who it is waiting
on.

Milestone 4 returns this shape and Milestone 5 renders it against the card. The
capacity and support-hours results (G-UP-NEXT #8 and #9) always both appear,
whichever failed.

## Client transition lock

Client roles have no transition permission at any stage, including the returns,
the exceptions and the terminal outcomes. The lock is enforced server-side
against every route — UI control, direct REST call, transition endpoint, and
replayed signed request — and each refusal is logged with user, item, source
interface and time. The WF-5 administrator override cannot bypass it, because it
is a security boundary rather than a workflow gate.

The complete denial list is in [`permission-matrix.md`](permission-matrix.md)
and is the test manifest for Milestones 2, 4 and 6.
