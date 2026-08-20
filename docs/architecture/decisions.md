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

Approved by Luke on 2026-08-17.

## Signed off

Every decision below was reviewed and signed off by Luke on 19 August 2026, one
at a time. Two were changed in that review, both the same change: an
administrator override and a capacity override are held by **any user with the
studio administrator role**, not by the Primary administrator alone (WF-5,
CAP-4). The reason is the same in both cases — real work should never wait on
one person being at a keyboard. Everything else stands as proposed.

They are decisions, not preferences: reversing one has the consequence written
under it, and that consequence is the reason to think twice rather than a reason
never to change course.

## Architecture and delivery


### ARCH-1 — client-delivery-model

**Question:** How does the client-facing part of Forge reach a client — an embedded module, a WordPress plugin on their own site, a subdomain we host, or a separate application?

**Options considered:** A hosted subdomain per client, which removes any code on their site but cannot send mail as them or appear inside their dashboard; a separate application, which duplicates identity and navigation; an embedded module inside the studio app, which never reaches their site at all.

**Decision:** A WordPress plugin installed on the client's own site. The brief requires email delivered through the client site's own configuration (§13.3) and a workspace inside their WordPress dashboard, and both need code running there.

**Consequence if reversed:** The client interface, its authentication and its mail path are all rebuilt, and `wp_mail` delivery from the client's domain is lost along with the in-dashboard workspace.

### ARCH-2 — canonical-architecture

**Question:** Is there one canonical service and database, or does each client site hold its own data store that synchronises with the studio?

**Options considered:** Per-client data stores with bidirectional sync, which the brief's §12 costs at a field-ownership matrix, a conflict queue, ordered transition events and dead-letter handling.

**Decision:** One canonical service and database on the studio site, confirming the brief's own recommendation. The client plugin holds no canonical records and reads through the studio API. It caches what it fetches so ordinary browsing is served locally and only changes cross the network, which is what keeps client-site performance acceptable under a read-through model.

**Consequence if reversed:** The whole of §12's sync architecture returns — field ownership as merge rules rather than permissions, a conflict queue, ordered event delivery and dead-letter handling — adding roughly one milestone.

### ARCH-3 — multiple-sites-per-client

**Question:** May one client have more than one website, and if so what does that client see?

**Options considered:** One site per client, which is simplest but wrong the first time a client asks for a second build; one workspace spanning all of a client's sites, with work tagged by site and hours pooled across them.

**Decision:** A client may have multiple sites, and each site is its own workspace. Work, hours, packages and onboarding belong to the site rather than the client. The client record remains the grouping for identity, people and memberships, so one person reaches several sites with one login.

**Consequence if reversed:** The scoping unit changes throughout — tenant scoping, the hour ledger, package assignment and onboarding all move from the site to the client, and every balance and checklist has to be merged or split.

### ARCH-4 — offline-client-sites

**Question:** Must a client site keep working while the studio is unreachable?

**Options considered:** Yes, which requires the client site to hold canonical records and reconcile them later, and reinstates the whole of §12's sync architecture.

**Decision:** No. Client sites require the studio to be reachable, and degrade to a cached read-only view carrying a visible stale-data notice when it is not.

**Consequence if reversed:** ARCH-2 falls with it. This is the decision that keeps the sync architecture out of scope, and reversing it grows the programme by roughly one milestone.

### ARCH-5 — conflict-and-staleness

**Question:** What happens when two people change the same record, and how stale may a client site's view be?

**Options considered:** Field-level merge, which needs an ownership matrix and still produces records nobody authored; last-write-wins, which loses work silently.

**Decision:** With one canonical database there are no merge conflicts, only stale writes. Every write carries the record version it was made against; a mismatch is rejected and the current state returned, never merged. The rejection surfaces to the person who made it rather than into a queue. Acceptable staleness on a client site is 60 seconds.

**Consequence if reversed:** Merge rules, a conflict queue and a resolution interface all have to be built, and record authorship stops being attributable to one person.

### ARCH-6 — client-site-authentication

**Question:** How does a client site prove which client it is, and how is that trust withdrawn?

**Options considered:** Shared secret in configuration, which cannot be rotated per site or revoked centrally; user-level credentials, which tie a site's access to one person's account.

**Decision:** The studio issues a per-site key at registration. Requests are signed and carry a timestamp and nonce so a captured request cannot be replayed. Keys are rotatable and revocable from the studio, and use of a revoked key is logged as a security event. Registration is a manual studio action — sites are installed and connected by us, so there is no self-service site enrolment.

**Consequence if reversed:** The authentication layer, its revocation path and every signed-request test are rebuilt, and the client artifact's entire data access changes shape.

### ARCH-7 — interface-boundary

**Question:** Which screens are built as the React application, and which stay as plain WordPress admin pages?

**Options considered:** Everything in React, which spends designed-interface effort on screens used a few times a year and blocks operational work on the design being ready; everything as plain admin pages, which leaves the screens people live in all day looking like WordPress; no rule at all, which was the position that had the clients and people screens built twice — once as an operational tool and again in the studio views milestone.

**Decision:** Every screen somebody uses to do the work is the React application — the board, work items, and every view of work. Every screen that configures the system is a plain WordPress admin page — clients, sites, people, memberships, connection keys. A screen is built once, in whichever of the two it belongs to, and is never rebuilt in the other. Approved by Luke on 20 August 2026.

**Consequence if reversed:** Screens are built twice, and the studio views milestone grows by the whole of the configuration surface.

## Workflow

### WF-1 — conditional-and-exception-stages

**Question:** Is Bug Tracking a conditional stage and Blocked an exception state, or are both ordinary linear stages?

**Options considered:** Both as mandatory linear steps, which forces every item through a bug stage it has no business in; Blocked as a status flag rather than a state, which loses the elapsed blocked time.

**Decision:** As the brief proposes. Bug Tracking is entered only from Triage, and only when Triage classifies the item as a bug. Blocked is enterable from any active stage, stores the stage it came from, returns to it on resolution, and retains the elapsed blocked time for reporting. Neither is a mandatory linear step.

**Consequence if reversed:** The stage registry, the transition table and every gate ordering test change, and blocked-time reporting is lost.

### WF-2 — terminal-outcomes

**Question:** What are the terminal states for work that ends without being released, and how do they behave in reporting?

**Options considered:** Deletion, which flatters throughput and destroys history; a single "closed" state, which cannot distinguish a rejected idea from a duplicate.

**Decision:** Five terminal outcomes, each reachable only where it makes sense. **Rejected** from Triage. **Duplicate** from Triage, requiring a link to the surviving item. **Cancelled** from any active stage, requiring a reason and releasing any hour reservation. **Deferred** from Triage or Up Next, returning the item to Future Idea with the deferral recorded. **Archived** from any terminal state, hiding the item from default views but never from reports. All five remain in reporting and in cycle-time calculations, flagged by outcome.

**Consequence if reversed:** Reporting history becomes incomparable across the change, and any outcome removed has to be migrated to a surviving one.

### WF-3 — return-paths

**Question:** Which backwards moves are permitted, and what do they require?

**Options considered:** A fixed table of permitted return pairs, which is long and needs editing whenever a stage's meaning shifts; free movement in both directions, which makes the gates decorative.

**Decision:** One rule rather than a table: any stage may return to any earlier stage the item has actually occupied, and **a reason is required on every backwards move without exception** — including the routine In Review to In Development return, which is the one path with its own affordance because it is the common case. Returning an item to a stage it has never occupied is not a return but a correction, and requires the WF-5 override.

**Consequence if reversed:** The transition service, its reason capture and the review-return affordance are rebuilt, and historical returns lose the reason that explains them.

### WF-4 — reopen-behaviour

**Question:** What happens when Completed or Released work is reopened?

**Options considered:** Reverting the item to an earlier stage, which erases the fact that it was ever finished; creating a new Sub-item, which breaks the link to the work's history.

**Decision:** Reopening creates a new work cycle on the same Sub-item rather than reverting it. The completion and release records are preserved as a closed cycle. The item re-enters at Documentation Period or In Development, chosen at reopen, with a reason. Hour treatment on reopen follows COMM-3.

**Consequence if reversed:** Cycle-time reporting changes meaning, and any previously reopened item's history has to be reconstructed.

### WF-5 — administrator-override

**Question:** Does an administrator override exist, and if so what may it do?

**Options considered:** No override at all, which means a genuinely stuck item can only be fixed in the database; an override available to all staff, which makes the gates advisory.

**Decision:** Yes, one override, held by any user with the studio administrator role — not the Primary administrator alone, so the studio is never waiting on one person to unstick real work. It can move an item to any stage, requires a reason, is permanently marked on the item rather than only in the log, and appears in an override report. It cannot bypass the client transition lock, because that is a security boundary rather than a workflow gate.

**Consequence if reversed:** Removing it leaves no route out of a stuck item; widening it makes every gate optional and invalidates the workflow test suite's assumptions.

### WF-6 — meaning-of-released

**Question:** What does Released mean for work that is not a software deployment?

**Options considered:** A single definition tied to deployment, which leaves content, design and advisory work unable to complete honestly.

**Decision:** Released is defined per work type and recorded on the item. **Software** — a version live in the named environment. **Content** — published and visible at a URL. **Design** — the approved artifact handed over and acknowledged. **Infrastructure** — the change applied and verified. **Non-deployment** — the agreed deliverable received and confirmed by the named recipient. In every case the Deliverer records evidence and a post-release check result.

**Consequence if reversed:** The release gate's evidence requirements change per work type, and past releases become unverifiable against the new definition.

## Authority and permissions

### AUTH-1 — gate-approvers

**Question:** Who may approve the Triage, Documentation, Technical Audit and Design gates?

**Options considered:** Approval tied to the Sub-item roles, which collapses in a small team where one person holds them all; approval by any staff user, which makes the gates a formality.

**Decision:** A distinct **Approver** capability per gate type — triage, documentation, technical, design — granted to named staff users independently of their Sub-item roles, so a small team can hold several and a larger one can separate them. An item's own Primary User may not approve its own Documentation, Technical Audit or Design gate, unless they hold the Principal grant from AUTH-3, which is the single exception and is marked on the item wherever it is used.

**Consequence if reversed:** The capability map, the gate approval checks and the permission matrix's approve rows are rebuilt.

### AUTH-2 — client-contribution-boundary

**Question:** What may client roles create, edit, comment on and answer?

**Options considered:** Read-only clients, which pushes all intake into email; full editing, which puts client hands on planning and commercial fields.

**Decision:** Client administrators may create parent items and Sub-items when the site has an active package, and may always submit bugs and requests. They may edit definition fields — problem, scope, acceptance criteria, links and attachments — until the item leaves Documentation Period, after which those become comment-and-request-change only. They may comment, attach evidence and answer information requests at any stage. They may never touch workflow, accountability, planning or commercial fields. Client stakeholders are read-only plus comment.

**Consequence if reversed:** The permission matrix, the client artifact's editable surface and the Milestone 2 and 4 denial suites all change.

### AUTH-3 — role-separation-and-principal

**Question:** Must Primary User, Reviewer and Deliverer be different people?

**Options considered:** Strict separation with no exception, which makes a one-person job impossible and drives the work outside the tool; free assignment, which makes review meaningless.

**Decision:** Primary User and Reviewer must be different people by default, and the service refuses the assignment. The exception is a named **Principal** grant, held by a small set of staff users, who may hold all three roles on one Sub-item. Deliverer may be the same person as either for anyone. Three guardrails keep the exception a capability rather than a shortcut: each role's actions are still performed separately, with their own timestamps and no combined approve-and-release control; capacity counts each role's hours separately, so a Principal carrying a job alone shows the full committed time; and the item is permanently marked as self-reviewed, recorded in the changelog and listed in a report by person and period. Principal is an additional grant on a staff user rather than a fifth kind of account, granted and revoked by the Primary administrator, with each grant recorded.

**Consequence if reversed:** Removing Principal blocks one-person delivery entirely; widening it removes the only guarantee that review was independent, and the self-reviewed report loses its meaning.

### AUTH-4 — substitutes

**Question:** How is a stand-in authorised when the assigned Reviewer or Deliverer is unavailable?

**Options considered:** Standing delegation, which quietly becomes the norm; automatic fallback to another staff user, which is how approval becomes a rubber stamp.

**Decision:** A substitute is named per item by the Primary administrator, with a reason, and is recorded on the item and in the changelog. There is no standing delegation and no automatic fallback. A Principal does not need a substitute for their own work — the Principal grant is the mechanism — and the two are never combined to route around a reviewer who is merely unavailable.

**Consequence if reversed:** Every substitution's attribution changes, and approval independence stops being demonstrable from the record.

### AUTH-5 — client-visibility

**Question:** Which users, capacity indicators, reports and audit details may a client see?

**Options considered:** Full transparency, which exposes staff workload and other clients' pressure on shared people; minimal visibility, which makes the client workspace pointless.

**Decision:** A client sees their own work and its stage, their own people, their own hour ledger and balances, their onboarding, their meetings, their Point of Contact, and a privacy-safe availability result for planning. They do not see staff names against capacity figures, any other client's anything, internal notes, or the identity of gate approvers beyond the fact of approval.

**Consequence if reversed:** The client artifact's read surface and the privacy tests change, and any widening has to be re-checked against every cross-client view.

### AUTH-6 — accounts-and-offboarding

**Question:** How are users invited, who owns the account, how does multi-client membership work, is there SSO, and what happens at offboarding?

**Options considered:** Per-client accounts, which duplicate a person who works with several clients and break cross-client capacity; SSO in the first release, which adds an identity provider dependency before there is a product.

**Decision:** Users are invited by email from the studio, own their own account, and hold one global identity with per-client memberships. No SSO in the first release. Offboarding revokes every membership and deactivates the account while leaving all historical attribution intact.

**Consequence if reversed:** Identity, capacity counting and attribution all change together, and historical records may lose their author.

## Work structure

### WORK-1 — work-hierarchy

**Question:** May Projects, Features and Milestones contain each other, and what is the shape of the work hierarchy?

**Options considered:** A flat parent-and-Sub-item model with no nesting, which keeps derived progress simple but cannot express a milestone made of features; free nesting of any type inside any other, which makes progress roll-up ambiguous.

**Decision:** A named hierarchy, top to bottom: **Project > Milestone > Feature > Sub-Feature**. **Bug** and **Feedback** are work types rather than rungs on the ladder — each attaches to any level or stands alone. Levels may be skipped: an item may sit under any higher level, or under none, so a one-line bug does not require three parents first. Only the lowest item in a chain runs the twelve workflow stages and their gates; everything above it derives its state from what is underneath, which means an item with nothing below it is itself the lowest and moves through the stages in its own right.

**Consequence if reversed:** The data model's parent chain, the derived-progress calculation, the Gantt roll-up and every view's grouping are rebuilt, and existing work has to be re-parented.

### WORK-2 — derived-parent-status

**Question:** Is a parent's status and progress derived from the work beneath it, or independently settable?

**Options considered:** Manual parent status, which lets a parent claim to be finished while its children are not.

**Decision:** Fully derived, and recursively so up the WORK-1 chain. A parent has no independently settable status. Its progress is the proportion of the planned hours beneath it that are Completed or Released; its dates are the span of its children's dates; its state is derived from the distribution — not started, in progress, blocked or complete. **A parent may only reach Completed when everything beneath it is Completed.** A parent with no children reads as "empty" rather than "not started".

**Consequence if reversed:** Every parent's historical progress figure becomes unreliable, and reports spanning the change cannot be compared.

### WORK-3 — dates-and-timezones

**Question:** Which dates are mandatory and when, and how are timezones handled?

**Options considered:** Dates mandatory at creation, which blocks capturing a rough idea; no mandatory dates at all, which makes the capacity gate unenforceable.

**Decision:** Planned start and due date become mandatory at Up Next, not before. Review and release dates are optional targets throughout and never gate a transition. All dates are stored as UTC instants; every interface renders in the site's timezone, and the studio renders in the studio timezone with the site's shown alongside where they differ.

**Consequence if reversed:** The Up Next gate changes, and stored dates may need reinterpreting against a different timezone basis.

## Capacity

### CAP-1 — base-hours-and-leave

**Question:** What are a person's base working hours, and where does leave come from?

**Options considered:** A single organisation-wide default, which cannot express part-time patterns; an external HR integration, which adds a dependency before there is a product.

**Decision:** Base hours are a per-user weekly pattern by weekday, carrying an effective date so a change to someone's hours does not rewrite history. Leave and other unavailable time are entered in Forge as dated unavailability records. No external HR source in the first release.

**Consequence if reversed:** Historical capacity figures change meaning, and an imported source has to be reconciled with hours already entered by hand.

### CAP-2 — hours-per-role

**Question:** Are hours entered per role, or as one total for the item?

**Options considered:** A single total, which cannot say who is committed for how long when three people are involved.

**Decision:** Hours are entered separately per role. Defaults are seeded from a configurable ratio of the Primary User's estimate — Reviewer 20%, Deliverer 10% — and remain editable, so the common case is fast and the unusual case is possible.

**Consequence if reversed:** Every existing allocation has to be split or merged, and cross-client capacity figures change.

### CAP-3 — actual-time-tracking

**Question:** Is actual staff time tracked, and how?

**Options considered:** Full stopwatch time tracking, which §15.1 excludes and which produces a second set of numbers nobody maintains; no adjustment at all, which leaves rework invisible.

**Decision:** No stopwatch time tracking. A remaining estimate is captured at In Development, which is what forecasting needs. In addition, **a post-review hours adjustment records extra time spent fixing or updating work after review**. The adjustment is an appended entry carrying its reason, never an edit, and it draws on the site's hour balance under COMM-3 so the client can see what the extra time was for.

**Consequence if reversed:** Introducing full time tracking makes allocation-based charging inconsistent with recorded effort; removing the post-review adjustment hides rework from both capacity and the ledger.

### CAP-4 — over-allocation

**Question:** Does over-allocating someone block the move, or require a reasoned override?

**Options considered:** A hard block, which lets a capacity model with no actuals in it overrule a human on worse information; a silent warning, which nobody reads.

**Decision:** Over-allocation requires a reasoned override by any user with the studio administrator role — as with WF-5, so a real week is never waiting on one person — and does not hard block. The override is recorded, reported and visible on the item.

**Consequence if reversed:** A hard block changes the delivery queue's behaviour at Up Next and In Development, and previously overridden items become invalid under the new rule.

## Commercial

### COMM-1 — term-start

**Question:** Does assigning a package start a new twelve-month term, or align to a shared renewal date?

**Options considered:** A single shared renewal date for every client, which makes every new client a part-year calculation and gives them a short first year, in exchange for renewals landing together.

**Decision:** Assignment starts a new twelve-month term from the effective date, so the anniversary is the client's own and pro-rata is the exception rather than the rule. Aligning to a shared renewal date remains available as an explicit option chosen at assignment, for a client who wants everything renewing together — and that is the only case where COMM-2's pro-rata applies.

**Consequence if reversed:** Every active term has to be re-cut to the shared date, with pro-rata credits or debits for the difference.

### COMM-2 — pro-rata

**Question:** How are pro-rata hours and pricing calculated, on what date basis, and with what rounding?

**Options considered:** Whole-month approximation, which is wrong by up to a month's hours; no pro-rata at all, which means a part-year client either overpays or gets a full year's hours.

**Decision:** Pro-rata hours by the brief's formula, on exact dates including leap years, rounded to the nearest half hour, with a preview shown before assignment or purchase. Price is prorated by the same ratio, rounded to the nearest whole currency unit. Upgrades credit the unused pro-rata value of the outgoing package. Downgrades mid-term are not permitted, only at renewal.

**Consequence if reversed:** Every pro-rata figure already issued has to be recalculated, and invoices raised against the old basis no longer reconcile.

### COMM-3 — hour-consumption

**Question:** When are hours reserved, when are they spent, and what happens on cancellation, adjustment or a negative balance?

**Options considered:** Consumption only at completion, which lets a site commit far more work than it has hours for; consumption at scheduling, which charges for work that may never start.

**Decision:** Reservation at Up Next, usage at In Development. Cancellation before In Development releases the reservation in full; cancellation after it is an adjustment requiring a reason. The CAP-3 post-review adjustment is charged to the site's balance as an appended entry carrying its reason, and is visible to the client. Balances may not go negative without the Primary administrator's override, which is recorded. Every change is an appended ledger entry, never an edit.

**Consequence if reversed:** The ledger's event types change, and balances at any past date can no longer be reconstructed consistently.

### COMM-4 — top-ups-and-rollover

**Question:** When do top-ups expire, in what order are hours consumed, and what happens to unused hours at renewal or expiry?

**Options considered:** Unlimited rollover, which turns unused hours into an open-ended liability; immediate forfeiture at expiry, which is hard to defend on hours already paid for.

**Decision:** Top-ups carry their own expiry date, twelve months from purchase by default. Consumption order is expiring-soonest first, with package hours taken before top-ups when both expire together. No automatic rollover of unused package hours at renewal; unused top-ups survive to their own expiry. Package expiry freezes the remaining balance rather than voiding it, pending renewal.

**Consequence if reversed:** Balances already frozen or forfeited have to be restated, and the consumption order changes which hours past work drew on.

### COMM-5 — bug-work-without-a-package

**Question:** Is validated bug work for a site with no support package free, funded by top-ups, or blocked until they buy a package?

**Options considered:** Charging support hours for defects in our own delivery, which is not defensible; free bug fixing regardless of who built the thing, which turns Forge into unpaid support for other people's work.

**Decision:** Validated bug work on a site Forge delivered is free of support hours. It still requires the full workflow and still consumes staff capacity, so it remains visible and internally costed. Bugs in work Forge did not deliver are ordinary chargeable work and need hours.

**Consequence if reversed:** The support-hours gate's exemption changes, and previously free work has to be reclassified.

### COMM-6 — checkout

**Question:** How does a client buy an upgrade or a top-up, and is it immediate or approved?

**Options considered:** Online checkout in the first release, which brings payment, tax, refund and failed-payment handling onto the critical path.

**Decision:** No checkout in the first release. The Sales page raises an upgrade or top-up request, which creates a task for the Point of Contact and is fulfilled by manual assignment. Manual assignment is required regardless of the eventual route, so it is what gets built.

**Consequence if reversed:** Adding checkout later adds payment handling but does not invalidate anything built here, since manual assignment remains the fulfilment path.

## Support meetings

### MEET-1 — schedule-ownership

**Question:** Who owns a support meeting schedule, and what may a client change?

**Options considered:** Client-owned scheduling, which puts a commercial commitment in their hands; fully fixed scheduling with no change route, which forces rescheduling into email.

**Decision:** The Point of Contact for the site owns the schedule, and a support meeting is a standing reminder rather than a fixed commitment. A client may request a change to a single occurrence, which creates a request the owner accepts or declines. The client never edits the series.

**Consequence if reversed:** The meeting permission model and the client-side request flow change, and the ownership of hour consumption moves with them.

### MEET-2 — recurrence

**Question:** What recurrence patterns are supported, and how are exceptions handled?

**Options considered:** Full RRULE support, which is a large surface for patterns nobody has asked for.

**Decision:** Weekly, fortnightly, four-weekly and monthly-by-date, with an optional end date. A single occurrence may be moved or cancelled as an exception without touching the rule. No arbitrary recurrence rules in the first release.

**Consequence if reversed:** Existing series have to be re-expressed under the new rule format, and generated occurrences re-derived.

### MEET-3 — planned-hours-per-occurrence

**Question:** How many billable hours does an occurrence plan for by default?

**Options considered:** A fixed figure per package, which ignores meeting length; no default at all, which means every occurrence needs setting by hand.

**Decision:** Planned billable hours default to the occurrence's calendar duration rounded up to the next half hour, and are separately editable per series and per occurrence.

**Consequence if reversed:** Forecast balances change for every scheduled occurrence.

### MEET-4 — reservation-horizon

**Question:** How far ahead are meeting hours reserved, and what happens beyond the term?

**Options considered:** Reserving the full term at once, which locks up a balance against meetings a year away; reserving nothing, which hides committed time from the balance.

**Decision:** Occurrences within the active package term and inside a rolling twelve-week horizon reserve their hours, so the balance shows what is coming. Beyond that, occurrences are forecast only and display as such. A reservation converts to usage only when the meeting is marked held under MEET-5, and releases automatically when an occurrence passes without being marked held.

**Consequence if reversed:** Displayed balances change for every client with a meeting series, and forecasts stop matching reservations.

### MEET-5 — cancellation-and-no-show

**Question:** What happens to the hours when a meeting is cancelled late, or nobody attends?

**Options considered:** Charging late cancellations and no-shows, on the basis that the time was blocked out; charging everything scheduled regardless of attendance, which overdraws balances on meetings that never happened.

**Decision:** Only held meetings consume hours. The host — our Point of Contact for that site — marks the meeting held, and that is what draws the hours from the balance. There is no late-cancellation charge and no no-show charge, and clients are not required to cancel. Insufficient balance still blocks confirming a new occurrence and is raised on Standup rather than overdrawing silently.

**Consequence if reversed:** Charging policy changes for every past occurrence, and the mark-as-held action stops being the sole trigger for meeting usage.

### MEET-6 — reminders-and-calendar

**Question:** What reminders are sent for meetings, and is an external calendar integration required?

**Options considered:** Email reminders 24 hours ahead to host and attendees; in-app reminders; a calendar integration, which §15.1 excludes.

**Decision:** No reminders of any kind — no email, no in-app. Each occurrence carries a meeting link field the host pastes in. No external calendar integration.

**Consequence if reversed:** A reminder path and its delivery logging have to be built, and the notification event model extended.

## Onboarding

### ONB-1 — template-and-launch-critical-steps

**Question:** Which onboarding templates exist, which steps are mandatory and launch-critical, and how is completion measured?

**Options considered:** A template per client type, which multiplies maintenance before there is evidence anyone needs it; a single flat list with no grouping, which gives a client no sense of when their turn is.

**Decision:** One template, version 1, covering the twelve categories in §11.2, organised into three sections that every step belongs to: **Foundations**, **Build reviews** and **Launch**. All steps are mandatory except where the template marks a step optional. Launch-critical steps are domain and DNS, hosting, email and SMTP, legal and compliance, and review and launch. Completion is the proportion of approved required steps; launch readiness is a separate flag over the launch-critical set, because a site at 95% with an unapproved DNS step is not nearly ready.

**Consequence if reversed:** Assigned checklists keep their snapshot, so a change applies only to new assignments — but the three sections are structural, and removing them re-groups every step.

### ONB-2 — step-ownership-and-review

**Question:** Who owns each step, who reviews it, and who may mark it not applicable?

**Options considered:** Client self-certification, which makes the launch gate meaningless.

**Decision:** Each step carries a client owner or an internal owner from the template. The assigned reviewer is the Point of Contact by default, overridable per step. Reviewers may approve, return with client-visible feedback, or record Not Applicable with a reason where the template permits it. Clients may never approve their own step, even a client-owned one.

**Consequence if reversed:** The onboarding permission rules and the launch gate's guarantees change together.

### ONB-3 — access-handover

**Question:** How is access to a client's providers handed over, and what does Forge store?

**Options considered:** Storing credentials in Forge, which makes it a target and a liability; storing nothing, which leaves no record that access was ever granted or verified.

**Decision:** Provider delegation and invitation only — registrar, host, DNS and integration access is granted by inviting our named account, never by handing over credentials. Forge stores the provider, the account identifier, the account owner, the access role requested, invitation status and the verification outcome. Where a provider genuinely cannot delegate, the credential passes through a one-time secret link outside Forge and only the completion reference is stored. Field-level validation rejects anything shaped like a password, key or card number.

**Consequence if reversed:** Storing credentials would make Forge a credential store, requiring encryption at rest, key management, access logging and a breach process that nothing here currently assumes.

### ONB-4 — onboarding-notifications

**Question:** Which onboarding events notify, and by which channel?

**Options considered:** Notifying every status change, which trains people to ignore the emails.

**Decision:** Email on template assignment, on a step becoming the client's turn, three days before a step's due date, on overdue, on return with changes requested, on approval, and on launch-ready. Internal notification on submission for review and on blocked. Everything else in-app only.

**Consequence if reversed:** The notification event list and its delivery tests change; no stored data is affected.

## Requests

### REQ-1 — request-conversion

**Question:** At which stage does an accepted request enter the pipeline, what parent rules apply, and what does package status gate?

**Options considered:** Automatic conversion on acceptance, which creates unparented work nobody owns; conversion straight to Up Next, which skips triage.

**Decision:** An accepted request converts to Future Idea or Triage, chosen at conversion. The converting user must pick an existing parent or create one in the same action, so a Sub-item with no parent is never created this way. The submission stays immutable and two-way linked. After acceptance, work for a site with no package may sit in the pipeline but cannot pass the support-hours gate into Up Next — except where COMM-5 makes it a free bug.

**Consequence if reversed:** The conversion flow and the intake-to-pipeline link change, and previously converted work may need re-parenting.

## Notifications and retention

### NOTIF-1 — email-recipients

**Question:** Who receives client-facing email?

**Options considered:** The submitter alone, which leaves the client's nominated contact uninformed; a free-text address on the item, which is unverified and impersonatable.

**Decision:** Both the original submitter and the site's nominated recipients, de-duplicated. Recipients are resolved from verified client records only, never from free text on the item.

**Consequence if reversed:** The recipient resolution and its tests change; delivery history remains valid.

### NOTIF-2 — final-confirmation-event

**Question:** Is Completed or Released the final confirmation to the client?

**Options considered:** Completed as the final event, which tells a client their work is done before it is live.

**Decision:** **Released** is the final confirmation. Completed is an internal readiness state and still sends, worded as approved and ready to release.

**Consequence if reversed:** The client's expectation of what "done" means changes mid-relationship, and the two templates swap roles.

### NOTIF-3 — delivery-and-retries

**Question:** What templates exist, who is the sender, and what happens when delivery fails?

**Options considered:** Sending from a studio domain, which arrives from a sender the client does not recognise; storing SMTP credentials in Forge, which makes it a credential store.

**Decision:** Three templates matching the three events in §13.3, sent through `wp_mail` on the client's own site so the site's own SMTP configuration handles delivery and mail comes from a domain the client recognises. Forge stores no SMTP credentials. Three retries at 5, 30 and 120 minutes, after which the failure is raised on Standup. Duplicate suppression is by unique event ID, checked before send.

**Consequence if reversed:** The delivery path, the sender identity and the credential posture all change together.

### NOTIF-4 — in-app-events

**Question:** Which events raise an in-app notification?

**Options considered:** In-app notification on every changelog event, which makes the list unreadable.

**Decision:** In-app on assignment to any of the three roles, gate approval, returned review, blocker created or updated, work due or overdue, capacity conflict, insufficient support hours, a new request awaiting review, release readiness, and any sync or delivery failure needing intervention.

**Consequence if reversed:** The event list and its permission scoping change; no stored data is affected.

### NOTIF-5 — retention-and-deletion

**Question:** What may be deleted, what is archived, what is exportable, and how long are attachments and audit history kept?

**Options considered:** Hard deletion on request, which walks immutable ledgers and changelogs and breaks the audit guarantees the rest of the product depends on.

**Decision:** Nothing is hard-deleted while it carries audit history. Clients and users are deactivated rather than removed. Archived work leaves default views and remains in reports and exports indefinitely. Attachments are retained for the life of the client relationship plus twelve months. A client may request an export of their own data at any time, and the studio may export everything. A deletion request under data-protection law is handled as a documented manual process, because an automated purge running through immutable ledgers and changelogs is a foot-gun.

**Consequence if reversed:** Automated deletion would have to define what a hole in an immutable ledger means, and every balance and cycle-time figure derived from it becomes unreliable.
