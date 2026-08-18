# Blueworx Forge — permission matrix

Every access role against every capability, on both interfaces, including the
explicit denials. Milestone 2's capability map is implemented from this file, and
the negative test suites of Milestones 2, 4 and 6 are written from the denial
list at the end.

**No cell is blank.** Every conditional cell cites the decision that sets its
condition.

Cites: AUTH-1 … AUTH-6, ARCH-3, WF-5, WORK-1, COMM-2, COMM-5, MEET-1, MEET-5,
ONB-2, NOTIF-5, and the client lock from brief §14.

## The roles

| Role | Who it is |
|---|---|
| **Primary admin** | Primary administrator. Cross-client access, the WF-5 override, all administration. |
| **Staff** | Internal user with access to permitted sites. What they may transition depends on their assigned Sub-item role. |
| **Staff + Principal** | A staff user holding the AUTH-3 Principal grant. Not a separate account type — identical in every other respect. |
| **Client admin** | Client administrator for a site. |
| **Viewer** | Stakeholder or viewer, on either side. |

## The interfaces

**Studio** is the command centre. **Client** is the workspace on the client's own
WordPress. Staff roles have no presence on the client interface at all — the
client artifact does not contain studio code (ARCH-1), so those cells are `no` by
construction rather than by permission.

Per ARCH-3, the scoping unit is the **site**. "Own site" below means the site the
membership grants, not every site the client owns.

## Capability grid

`yes` / `no` / `yes*` = conditional, condition stated beneath each block.

### Read

| Capability | Primary admin (studio) | Staff (studio) | Principal (studio) | Client admin (studio) | Viewer (studio) | Primary admin (client) | Staff (client) | Principal (client) | Client admin (client) | Viewer (client) |
|---|---|---|---|---|---|---|---|---|---|---|
| View work on any site | yes | yes\* | yes\* | no | yes\* | no | no | no | yes\* | yes\* |
| View another client's work | yes | no | no | no | no | no | no | no | no | no |
| View internal notes | yes | yes | yes | no | yes\* | no | no | no | no | no |
| View gate approver identity | yes | yes | yes | no | yes | no | no | no | no | no |
| View staff names against capacity | yes | yes | yes | no | yes | no | no | no | no | no |
| View availability result only | yes | yes | yes | yes | yes | no | no | no | yes | yes |
| View own hour ledger and balance | yes | yes | yes | yes | yes | no | no | no | yes | yes |
| View changelog | yes | yes | yes | yes\* | yes\* | no | no | no | yes\* | yes\* |
| View reports | yes | yes\* | yes\* | no | yes\* | no | no | no | no | no |

Conditions: staff and viewer reads are scoped to sites their membership grants
(AUTH-6). Viewer internal notes require an internal viewer membership; a client
viewer never sees them (AUTH-5). Client changelog views exclude internal notes
and approver identities (AUTH-5). Client-side reads are limited to the current
site (ARCH-3).

### Create

| Capability | Primary admin (studio) | Staff (studio) | Principal (studio) | Client admin (studio) | Viewer (studio) | Primary admin (client) | Staff (client) | Principal (client) | Client admin (client) | Viewer (client) |
|---|---|---|---|---|---|---|---|---|---|---|
| Create a parent item | yes | yes | yes | no | no | no | no | no | yes\* | no |
| Create a lowest-level work item | yes | yes | yes | no | no | no | no | no | yes\* | no |
| Submit a bug | yes | yes | yes | yes | no | no | no | no | yes | no |
| Submit a request, idea or suggestion | yes | yes | yes | yes | no | no | no | no | yes | no |
| Create a support meeting series | yes | yes\* | yes\* | no | no | no | no | no | no | no |
| Request a meeting change | yes | yes | yes | yes | no | no | no | no | yes | no |

Conditions: client creation of work requires an active package on that site
(AUTH-2); bugs and requests are always available, with or without a package
(COMM-5, §8.2). Meeting series creation is limited to the site's Point of Contact
or a Primary administrator (MEET-1).

### Edit

| Capability | Primary admin (studio) | Staff (studio) | Principal (studio) | Client admin (studio) | Viewer (studio) | Primary admin (client) | Staff (client) | Principal (client) | Client admin (client) | Viewer (client) |
|---|---|---|---|---|---|---|---|---|---|---|
| Edit definition fields | yes | yes | yes | no | no | no | no | no | yes\* | no |
| Edit accountability fields | yes | yes | yes | no | no | no | no | no | no | no |
| Edit planning fields | yes | yes | yes | no | no | no | no | no | no | no |
| Edit workflow fields | yes | yes | yes | no | no | no | no | no | no | no |
| Edit commercial fields | yes | no | no | no | no | no | no | no | no | no |
| Edit a parent's derived status | no | no | no | no | no | no | no | no | no | no |
| Edit a changelog or ledger entry | no | no | no | no | no | no | no | no | no | no |

Conditions: client editing of definition fields ends when the item leaves
Documentation Period, after which it is comment-and-request-change only (AUTH-2).
Derived status and append-only records are editable by nobody, including the
Primary administrator (WORK-2, COMM-3, NOTIF-5).

### Comment and evidence

| Capability | Primary admin (studio) | Staff (studio) | Principal (studio) | Client admin (studio) | Viewer (studio) | Primary admin (client) | Staff (client) | Principal (client) | Client admin (client) | Viewer (client) |
|---|---|---|---|---|---|---|---|---|---|---|
| Comment on permitted work | yes | yes | yes | yes | yes | no | no | no | yes | yes |
| Write an internal-only note | yes | yes | yes | no | no | no | no | no | no | no |
| Attach evidence | yes | yes | yes | yes | no | no | no | no | yes | no |
| Answer an information request | yes | yes | yes | yes | no | no | no | no | yes | no |

None of these change stage (AUTH-2, §14).

### Transition

**Every client-interface cell in this block is `no`, on every route.** See the
denial list.

| Capability | Primary admin (studio) | Staff (studio) | Principal (studio) | Client admin (studio) | Viewer (studio) | Any role (client) |
|---|---|---|---|---|---|---|
| Move an item forward | yes | yes\* | yes\* | no | no | no |
| Return an item to an earlier stage | yes | yes | yes | no | no | no |
| Enter or exit Blocked | yes | yes | yes | no | no | no |
| Record a terminal outcome | yes | yes | yes | no | no | no |
| Approve In Review → Completed | yes\* | yes\* | yes\* | no | no | no |
| Confirm Completed → Released | yes\* | yes\* | yes\* | no | no | no |
| Reopen completed or released work | yes | yes | yes | no | no | no |
| Override to any stage | yes | no | no | no | no | no |

Conditions: forward moves require the stage's gate to pass, and the Approver
capability where the gate names one (AUTH-1). Review approval is the assigned
Reviewer or an AUTH-4 substitute only; release confirmation is the assigned
Deliverer or substitute only — a Primary administrator holds these only when
assigned, or through the WF-5 override, which is marked on the item. A Principal
may hold all three roles on one item and still performs each action separately
(AUTH-3). The override cannot bypass the client lock (WF-5).

### Approve

| Capability | Primary admin (studio) | Staff (studio) | Principal (studio) | Client admin (studio) | Viewer (studio) | Any role (client) |
|---|---|---|---|---|---|---|
| Approve a triage gate | yes\* | yes\* | yes\* | no | no | no |
| Approve a documentation gate | yes\* | yes\* | yes\* | no | no | no |
| Approve a technical gate | yes\* | yes\* | yes\* | no | no | no |
| Approve a design gate | yes\* | yes\* | yes\* | no | no | no |
| Approve one's own item's gate | no | no | yes\* | no | no | no |

Conditions: each requires the matching Approver capability, granted per gate type
independently of Sub-item roles (AUTH-1). Self-approval of Documentation,
Technical Audit and Design gates is refused unless the user holds the Principal
grant, and where it is used the item is permanently marked self-reviewed
(AUTH-3).

### Administer

| Capability | Primary admin (studio) | Staff (studio) | Principal (studio) | Client admin (studio) | Viewer (studio) | Any role (client) |
|---|---|---|---|---|---|---|
| Create or deactivate a client or site | yes | no | no | no | no | no |
| Register or revoke a client site key | yes | no | no | no | no | no |
| Invite a user | yes | no | no | yes\* | no | no |
| Grant or revoke Principal | yes | no | no | no | no | no |
| Grant or revoke an Approver capability | yes | no | no | no | no | no |
| Assign a substitute | yes | no | no | no | no | no |
| Assign the Point of Contact | yes | no | no | no | no | no |
| Record a reasoned over-allocation override | yes | no | no | no | no | no |
| Grant cross-client access | yes | no | no | no | no | no |

Conditions: a client administrator may invite users into their own site's
membership only (AUTH-6). Site registration is a manual studio action (ARCH-6).

### Commercial

| Capability | Primary admin (studio) | Staff (studio) | Principal (studio) | Client admin (studio) | Viewer (studio) | Client admin (client) | Viewer (client) |
|---|---|---|---|---|---|---|---|
| Create or retire a package | yes | no | no | no | no | no | no |
| Assign a package to a site | yes | no | no | no | no | no | no |
| Add a top-up | yes | no | no | no | no | no | no |
| Record a reasoned ledger adjustment | yes | no | no | no | no | no | no |
| Record a post-review hours adjustment | yes | yes\* | yes\* | no | no | no | no |
| Permit a negative balance | yes | no | no | no | no | no | no |
| Request an upgrade or top-up | yes | yes | yes | yes | no | yes | no |
| View a pro-rata preview | yes | yes | yes | yes\* | no | yes\* | no |
| Mark a meeting held | yes | yes\* | yes\* | no | no | no | no |

Conditions: the post-review adjustment is made by the item's Reviewer or Primary
User, with a reason (CAP-3, COMM-3). Clients see a pro-rata preview only for
packages offered to them (COMM-2). Only the site's host — our Point of Contact —
marks a meeting held, and that is the sole trigger for meeting hour usage
(MEET-5).

### Onboarding

| Capability | Primary admin (studio) | Staff (studio) | Principal (studio) | Client admin (studio) | Viewer (studio) | Client admin (client) | Viewer (client) |
|---|---|---|---|---|---|---|---|
| Create or version a template | yes | no | no | no | no | no | no |
| Assign a template to a site | yes | yes | yes | no | no | no | no |
| Create, delete or reorder a step | no\* | no | no | no | no | no | no |
| Respond to a step | yes | yes | yes | yes\* | no | yes\* | no |
| Attach step evidence | yes | yes | yes | yes | no | yes | no |
| Submit a step for review | yes | yes | yes | yes | no | yes | no |
| Approve or return a step | yes | yes\* | yes\* | no | no | no | no |
| Record Not Applicable | yes | yes\* | yes\* | no | no | no | no |
| Edit derived completion | no | no | no | no | no | no | no |

Conditions: steps come from the assigned template version and cannot be created,
deleted or reordered on an assigned checklist by anyone — a change means a new
template version (ONB-1). Clients respond to client-owned steps only, and may
never approve any step, including their own (ONB-2). Approval and Not Applicable
require the step's assigned reviewer, the Point of Contact by default (ONB-2).

## Explicit denial list

Every action a client role must be refused **server-side**, with the route it
could be attempted by. This list is the test manifest for Milestones 2, 4 and 6.
It is exhaustive, not illustrative — an action missing from here is an untested
hole.

Routes tested for each: **(a)** UI control, **(b)** direct REST call, **(c)**
filter or ID parameter, **(d)** replayed signed request, **(e)** sync or webhook
event.

### Tenant and site boundary

| # | Must be refused | Routes |
|---|---|---|
| D-1 | Read a record belonging to another site | b, c, d |
| D-2 | Read a record belonging to another client | b, c, d |
| D-3 | Enumerate records by sequential or guessed ID | b, c |
| D-4 | Widen a filter beyond the current site | a, b, c |
| D-5 | Retrieve another site's record through search | a, b, c |
| D-6 | Infer another client's data from counts, totals or availability | a, b |
| D-7 | Act after deactivation, while past attribution stays intact | b, d |
| D-8 | Reach any record using a revoked or rotated site key | b, d |
| D-9 | Replay a captured signed request | d |

### Workflow

| # | Must be refused | Routes |
|---|---|---|
| D-10 | Move an item forward a stage | a, b, d, e |
| D-11 | Return an item to an earlier stage | a, b, d, e |
| D-12 | Enter or exit Blocked | a, b, d |
| D-13 | Record any terminal outcome | a, b, d |
| D-14 | Approve In Review → Completed | a, b, d |
| D-15 | Confirm Completed → Released | a, b, d |
| D-16 | Reopen completed or released work | a, b, d |
| D-17 | Write a workflow-stage field directly, bypassing the transition service | b, c, e |
| D-18 | Complete or mark a gate requirement | a, b, d |
| D-19 | Invoke the WF-5 override | a, b, d |

### Data integrity

| # | Must be refused | Routes |
|---|---|---|
| D-20 | Edit accountability, planning or commercial fields | a, b, e |
| D-21 | Edit definition fields after the item leaves Documentation Period | a, b, e |
| D-22 | Edit or delete a changelog entry | b, e |
| D-23 | Edit or delete an hour ledger entry | b, e |
| D-24 | Edit a parent's derived status or progress | b, e |
| D-25 | Write a stale record version over a newer one | b, d, e |
| D-26 | Create a duplicate through a replayed write | b, d |

### Commercial and meetings

| # | Must be refused | Routes |
|---|---|---|
| D-27 | Assign, upgrade or retire a package | a, b |
| D-28 | Add a top-up or adjust a balance | a, b |
| D-29 | Mark a meeting held, or otherwise trigger meeting hour usage | a, b, d |
| D-30 | Edit a meeting series | a, b |
| D-31 | Confirm an occurrence with insufficient balance | a, b |
| D-32 | Take a balance negative | b |

### Onboarding and requests

| # | Must be refused | Routes |
|---|---|---|
| D-33 | Create, delete or reorder a checklist step | a, b |
| D-34 | Approve any step, including a client-owned one | a, b, d |
| D-35 | Record a Not Applicable decision | a, b |
| D-36 | Edit derived onboarding completion or launch readiness | b |
| D-37 | Mark a site Released with a launch-critical step outstanding | a, b |
| D-38 | Store a credential in an onboarding step field | a, b |
| D-39 | Edit a submitted request after submission | a, b |
| D-40 | Convert a request into another site's pipeline | a, b |

Every refusal is logged with user, item, source interface and time, and an
unresolved pattern of refusals surfaces to the studio.
