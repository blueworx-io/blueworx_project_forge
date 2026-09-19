# Functional feedback, 19 September 2026 — design

The functional half of Luke's second review round. The visual half (spacing,
titles, banners, tags, dropdowns, pills, diary, list views, report tables)
follows in its own batch once this one has shipped.

## Workflow rules

- **Parent** is no longer asked at triage. The parent field stays; nothing
  requires it.
- **Technical Audit** is the reviewer signing off the documentation. Its
  write-in boxes (architecture, data and sync, security and privacy, test
  approach, estimate range) go. What remains: Risks, and Technical approval.
- **Approvals belong to the reviewer.** Documentation approval, Technical
  approval and Design approval are answered by the item's reviewer (or their
  substitute). The panel labels them "For the reviewer"; the server refuses
  anyone else. An administrator may act for anyone.
- **Administrators may answer any seat's requirement.** Before this, an
  administrator who was also a Forge person but not in the seat could not
  tick a "for the person doing the work" row, which is how In Development
  became impassable.
- **Doing the work and Reviewing it** are required before an item leaves
  Captured. Delivering it stays required at Up Next.
- **Dates keep their order**: start ≤ due ≤ review by ≤ release by, among
  those that are set. Any of them may be in the past; the overdue rules apply
  as they always did.
- **Checklist**: an item with a checklist cannot leave In Development until
  every line is ticked. "Requirements implemented" resolves itself from the
  checklist (empty counts as done); "Completion checklist" goes.
- **Hours still to do** goes: the field, the gate and the column on My tasks.
- **Review & Testing** is a section of the task, filled in during In
  Development: "How to test" (rich text, required before In Review) and up to
  ten test steps (optional). At In Review the reviewer sees it at the top of
  the task. It replaces the "Test evidence" comment.
- **Design link**: a URL on the item, required to leave Design Process
  (unless Design Not Applicable). Replaces the "approved design artifact"
  comment.
- **Accessibility considerations** goes.
- **Documentation**: Reference material is optional. Links (up to ten, label
  and URL) and Images (dropped or chosen, stored in the media library) are
  optional sections of the task.
- **Dependencies** are connected from a card on the task: a toggle, off by
  default, that opens a picker of the site's other items. Turning it off
  removes the connections. The three "Dependencies confirmed / None" picks
  go; "Dependencies ready" at Completed stays.
- **Standup** lists work with something outstanding at every stage before
  Released, not only from Up Next. Work is on the standup until it is done.

## Recurring, subscriptions, meetings

- A recurring task needs a title, description (rich text), type, repeats,
  start date, at least one person and hours each. It may carry a checklist,
  copied onto every task it makes.
- Each person ticks their own copy from My tasks. The task panel shows the
  signed-in person's tick (an administrator sees everyone's). A tick is
  refused while the task's checklist has unticked lines.
- Recurring tasks are born at Up Next and go straight to Released when
  everyone has ticked. Subscription check-ins behave the same: their person
  is the assignee.
- Recurring hours count against each person's capacity for the days ahead,
  not only for tasks already made.
- Meetings: "How long" is the hours for everyone attending. "Who else comes"
  is a staff picker; each attendee carries the meeting's hours. The client is
  charged the meeting once.

## Packages and admin

- A package says whether its hours are per month or per year, and shows its
  price per hour. Per-month hours are granted for the whole term when the
  package is assigned (hours × months); a monthly refresh is a later phase.
- The Forge admin page (client sites and keys) leaves the menu; Sync health
  is the top item and the default. The page stays reachable by URL for now,
  because the pair suite still sets up through it.
- Profile: History pages at five rows, Time off at ten; a time off's last
  day cannot be before its first (the same day is fine).
