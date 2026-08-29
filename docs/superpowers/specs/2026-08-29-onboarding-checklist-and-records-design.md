# Onboarding: the checklist and its records (#159, #160, #161)

**Status.** Drafted 2026-08-29. First of three parts of M9.

## Where this sits

M9 is ten issues, which is more than one piece of work. It is being built in
three parts, with the safety rules built into the flows rather than added to
them afterwards:

1. **The checklist and its records** — this document. The template, its
   versions, what assignment fixes, and the step record itself (#159, #160,
   #161).
2. **Doing it and reviewing it** — the client's checklist page, the studio's
   review, and the credential and evidence rules enforced as part of both
   (#162, #163, #167, #168).
3. **Readiness** — derived completion, the studio board, and the launch gate
   (#164, #165, #166).

Part two builds forms and an upload path. Building those first and adding the
rules after would mean building the part that has to be exactly right twice, so
#167 and #168 travel with the flows they constrain.

## The goal

A launch checklist the studio manages centrally, fixed for a client the moment
it is assigned, with every step's history attributable and permanent.

## What already exists

Nothing. There is no onboarding code in the plugin. What exists is the
decisions, which settle more than usual:

**ONB-1.** One template, version 1, covering the twelve categories in the
brief's §11.2, in three sections every step belongs to — Foundations, Build
reviews, Launch. Everything mandatory unless the template marks it optional.
Launch-critical: domain and DNS, hosting, email and SMTP, legal and compliance,
review and launch. Completion is the proportion of approved required steps;
launch readiness is a separate flag over the launch-critical set.

**ONB-2.** Each step carries a client or internal owner from the template. The
reviewer is the Point of Contact by default, overridable per step. Reviewers
approve, return with client-visible feedback, or record Not Applicable with a
reason where the template permits it. **A client may never approve their own
step**, even one they own.

**ONB-3.** Provider delegation and invitation only. Forge stores the provider,
account identifier, account owner, access role requested, invitation status and
verification outcome — and never a credential.

The data model already fixes the step's fields and its eight statuses, and
records that an Onboarding Template Version is **global** while everything below
it is scoped to a site.

## Open input

The twelve category names are in the brief's §11.2, which is not in this
repository — every document here cites the section without reproducing it. Five
are known from ONB-1 because they are the launch-critical ones. The remaining
seven are needed to seed version 1.

This does not block the work. The template is data, so the categories are one
seed file written at the end. Everything in this document is the machinery that
holds them, and none of it changes with the list.

## Why this is architectural

**A global entity in a product where everything is tenant-scoped.** Every read
in Forge so far is scoped to a client or a site, and the tenancy layer assumes
it. The template version is the first record that deliberately is not, and that
has to be declared once rather than discovered by each consumer.

**Immutability is the whole product promise here.** A checklist that can be
rewritten under a client part-way through is not a checklist, it is a moving
target. Where the immutability boundary sits — what is frozen, when, and what a
later edit may touch — is the decision every later part inherits.

**Eight statuses, two of which are not facts anybody records.** Overdue and
blocked look like the other six and are not like them at all. Getting that wrong
puts a stored value where a derived one belongs, and it is the same mistake
#164 exists to prevent for completion.

## Decisions

### ONB-E1 — the template is data, seeded, not code

**Decision.** Template versions and their steps are rows. Version 1 is seeded
when the plugin activates, from a definition kept in the source, so a fresh
install has a working checklist without anybody building one. Every version
after that is made in the product.

Keeping it in code would make "publish a new version" a release, which is the
thing #159 exists to avoid — a checklist that needs a deployment to correct is
a checklist that stays wrong.

The editing screen is a plain WordPress admin page under Forge, not part of the
React application. ARCH-7: screens that configure the system are WordPress admin
pages, and a template nobody does daily work in is configuration.

**Consequence if reversed.** Seeded rows have to be reconciled with a
code-defined list that has since diverged.

### ONB-E2 — a published version is frozen; editing makes the next one

**Decision.** A template version is either draft or published. A draft may be
changed freely. A published version may never be changed again — editing one
opens a new draft copied from it, which becomes the next version when published.

This is what makes the snapshot in ONB-E3 trustworthy without copying anything
defensively: a site pointing at version 2 is pointing at something that cannot
move.

**Consequence if reversed.** Every assigned checklist needs its own full copy of
the definition to be safe, and the version number stops meaning anything.

### ONB-E3 — assignment writes real step rows, immediately

**Decision.** Assigning a template to a site records which version was assigned
and creates one step row per template step, there and then.

Not a reference resolved when read. Every step needs its own status, owner,
reviewer, due date, responses and history the moment it exists — #161 is that
record — so the rows have to exist anyway. Creating them lazily would mean a
step with no history until somebody touched it, and a studio board (#165) that
cannot query what it cannot see.

The assigned version is recorded on the site's onboarding as well, so "which
checklist did this client actually get" is answerable after the fact even though
the steps have since diverged from it.

**Consequence if reversed.** The board becomes a join against a template rather
than a query, and a step's history has no row to hang on.

### ONB-E4 — overdue and blocked are not the same kind of thing

**Decision.** Six of the eight statuses are recorded: not started, in progress,
submitted, returned, approved, not applicable. **Blocked** is a seventh, also
recorded — somebody decides a step is blocked and says why.

**Overdue is derived**, from the due date against today, and is never stored.
It is a fact about the calendar rather than about the step, and storing it would
need something to sweep every step every night and would be wrong between
sweeps. A step is overdue when its due date has passed and it is not approved or
not applicable — which is a question, not a state anybody enters.

The step therefore carries a status of the seven, and overdue is reported
alongside it.

**Consequence if reversed.** A stored overdue flag needs a scheduled job, and
the board disagrees with the calendar for up to a day.

### ONB-E5 — every status change is an entry, and the entry is the record

**Decision.** A step's own history table, one row per change, carrying who,
when, from what, to what, and the reason or feedback where there is one. The
step row holds current state only.

Its own table rather than the work item changelog: an onboarding step is not a
work item, has no cycle or attempt, and putting it in `bwx_forge_work_events`
would mean a table whose `item_id` sometimes means a work item and sometimes
does not — which every consumer of that table would then have to know.

Nothing is ever edited or deleted, matching how the rest of the product treats
history. A correction is a further entry.

**Consequence if reversed.** Merging the two histories later means backfilling a
discriminator onto every existing work event.

## What gets built

**Four tables.**

- `bwx_forge_onboarding_templates` — a version: number, status (draft or
  published), name, when it was published and by whom. Global; no client or site
  column, deliberately.
- `bwx_forge_onboarding_template_steps` — the definition of one step within a
  version: section, title, description, owner side, whether it is optional,
  whether it is launch-critical, whether Not Applicable is permitted, its
  ordering, and which steps it depends on.
- `bwx_forge_site_onboarding` — one per client site: which template version was
  assigned, when, and by whom.
- `bwx_forge_onboarding_steps` — the live step: its site onboarding, which
  template step it came from, its status, owner, reviewer, due date, response,
  and the ONB-3 handover fields. **No credential column exists**, which is
  enforced by there being nowhere to put one rather than by a rule somebody
  remembers.

**A step's history**, in a fifth table, per ONB-E5.

**Seeding**, on activation: version 1, published, from a definition in the
source — awaiting the category list.

**A WordPress admin screen** to read the template, open a draft, edit it and
publish it as the next version.

## Not in this part

The client's checklist page and the studio's review (#162, #163). Credential and
evidence rules (#167, #168) — enforced where the submissions arrive, which is
part two. Derived completion, the board and the launch gate (#164, #165, #166).
Notifications (ONB-4), which belong with M10.

Re-assigning a site to a newer template version is deliberately not built. A
client onboards once, ONB-1 says an assigned checklist keeps its snapshot, and a
migration path is a feature nobody has asked for.

## Testing

**PHP unit** — the version lifecycle: a published version refusing every edit,
an edit opening a draft, publishing incrementing the number. Assignment creating
one row per template step and recording the version. Overdue derived correctly
either side of a due date, and never for an approved or not-applicable step.
A step that a client owns still not being approvable by them (ONB-2), asserted
here even though the flow arrives in part two, so the rule cannot be built
wrongly later.

**Playwright** — seeding on activation producing a readable version 1;
publishing a second version leaving an already-assigned site on the first.
