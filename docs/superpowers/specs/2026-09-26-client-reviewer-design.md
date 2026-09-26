# The client as reviewer — design

Issue #391. Luke, 2026-09-25: "Some work needs the client to review and sign it off."
His rulings on 2026-09-25 night:
- The client reviews on their own site. The task shows on their Forge page with Approve and Send back buttons.
- "The client" can be chosen as the reviewer only from Up Next onwards.
- An admin can record an approval on the client's behalf, for example when the client approves by email.
- The review time counts against nobody's capacity and doesn't come out of the client's support allowance.

Studio version 2.137.0. Both plugins carry the same version.

## The decision it changes

The client transition lock is written in `docs/architecture/workflow-state-machine.md` (~323-330) and D-14 in `docs/architecture/permission-matrix.md` (~246). It says clients may never move work, by any route. `Capabilities::decide` refuses client actors every workflow capability, and `tests/php/ClientContributionRouteTest.php` fails if any client route's path contains transition words.

This feature makes one narrow exception. **A client may approve, or send back, an item in In Review whose reviewer is the client, and nothing else.** The exception is written into both documents with its date and reason, as a new decision entry. The route test gains one explicit, named allowance for that single route. Every other client refusal stays.

## Data

- `reviewer_id = 'client'`. It's a sentinel with no `usr_` prefix, so it can never match a person. No schema change.
- G-UP-NEXT-2 ("reviewer assigned") is satisfied unchanged.
- `Access::is_assigned` stays false for every staff member, so no staff member can approve as the reviewer. Approving on the client's behalf is its own admin action (below).
- `reviewer_substitute_id` is cleared when the reviewer is the client.
- When the reviewer is `'client'`, `hours_review` is forced to 0 and `RoleHours::seed` skips the review share. That one rule keeps capacity (Allocations maps hours_review to the reviewer) and the support allowance (WorkHours::planned adds up all seats) correct, with no change to either.
- Validation:
  - `Work\Validate` accepts `'client'` for `reviewer_id` only, and only when the item is at Up Next or a later stage. Otherwise: "The client can be the reviewer from Up Next onwards."
  - AUTH-3 (different from primary) holds trivially.
  - Recurring and SureCart keep refusing it: they validate `usr_` only.
- The seat access check from tonight's #393 (`PersonReach`) skips the `'client'` sentinel.

## Studio behaviour

`Transition::client_review( item, 'approve'|'send_back', note, actor_label, actor_wp_user )` requires `reviewer_id === 'client'` and stage `in-review`. Otherwise it refuses with a 409 and a clear message.

**Approve:**
- Evaluate the G-IN-REVIEW rows that don't belong to the reviewer. G-IN-REVIEW-3 (open client questions) still has to be clear.
- The reviewer-owned rows count as met because the client approved. Gate records need a studio user, so the approval is a changelog entry, "Approved by the client (<name>)", and the gate view treats that entry as meeting the reviewer rows.
- Commit to `completed` with actor 0, or the admin's id when recorded on the client's behalf.

**Send back:**
- A note is required.
- Uses the existing `send_back( …, 'in-development', reason, note, … )`, which starts a new review attempt.

The team UI:
- Reviewer options gain "The client". It's disabled before Up Next, with that message as a hint.
- The gate rows show "Waiting on the client to review".
- Labels in `Card.tsx`, `MyTasksScreen.tsx` and `Standup/Rules.php` read "The client" instead of a name.
- Slack skips `'client'` in both reviewer paths (`Notify.php`).
- Admins see "Client approved (recorded for them)" and "Client sent it back" buttons on an in-review task whose reviewer is the client. Sending back needs a note. Both are logged as "recorded by <admin> on the client's behalf".

## The client's side

**Studio route:**
- `POST /client/work-items/{id}/review` `{ decision: 'approve'|'send_back', note, author_name }`. Signed, like the other client routes.
- It uses the existing `their_item` / `refuse_client_unless` / `log_refusal` pattern (ClientController, around the answer and contribute routes).
- It re-reads the item and refuses anything that isn't In Review with the client as reviewer.
- `ClientView::item` gains `awaiting_review: bool`.

**Client plugin:**
- `client/includes/Review.php` posts the decision, then clears the Board cache, like `ChecklistAnswer.php`.
- A WriteController route for the client's own users. Use the same permission the client plugin uses for comments or answers: who on the client site may act.
- In `src/client/screens/Board.tsx`:
  - a "Needs your review" list at the top
  - on each item awaiting review, Approve and Send back, with a required note for Send back
  - after acting, the item leaves the list

**Email:**
- A new client notification event, "review requested", raised when an item enters In Review with the client as reviewer.
- Copy: "<Task> is ready for you to review. Open your Forge page to approve it or send it back."
- The duplicate guard key includes the review attempt number, so a second review after a send-back is emailed too.

## Risks handled

- Double clicks or a stale view: the route re-reads the stage and version, and a second decision gets a 409 "Already decided".
- Earlier-stage reviewer gates (G-DOCUMENTATION-9, G-TECHNICAL-AUDIT-8, G-DESIGN-5) are untouched, because the client can only be chosen from Up Next.
- Hours already spent before the reviewer switched aren't refunded. Spent hours only go up. That's accepted and noted in the PR.

## Tests

- PHP units:
  - validation, including the stage rule
  - hours_review forced to 0 and RoleHours skipped
  - client_review refuses when it doesn't apply
- Studio e2e:
  - set the reviewer to the client at Up Next, refused before Up Next
  - capacity shows no review hours
  - the support allowance holds no review hours
  - the admin records an approval → completed
  - an admin send-back → in-development, with the note
- Pair e2e (`playwright.pair.config.js`, `npm run test:pair`):
  - the client sees "Needs your review", approves, and the studio item is completed
  - a second item is sent back with a note, and the studio item is in development with a new review attempt
  - a client item not awaiting review can't be approved: 409
- The route test's allowance is named and limited to the one path.
