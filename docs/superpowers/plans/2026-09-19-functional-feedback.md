# Functional feedback batch — plan

**Spec:** docs/superpowers/specs/2026-09-19-functional-feedback-design.md

Four pull requests, stacked, each with its version bump and changelog entry.

## 1. `workflow-rules` (2.122.0)

- Gates: two people before triage; parent, Technical Audit write-ins,
  accessibility and the three dependency picks removed; approvals REV;
  checklist auto-resolves In Development; How to test a field; design link
  a field.
- Fields: `design_url`, `test_description`, `test_steps`, `links`, `images`
  columns (schema 29); `remaining_estimate` retired; `Validate::dates_in_order`.
- Controller: reviewer-only records (`not_their_seat`), dates checked on
  create and edit, image upload and removal routes, ticks refuse an open
  checklist and land at Released.
- Standup lists gate-unmet work at every open stage.
- Panel: gates first, one Actions card, Review and testing card, Dependencies
  card with a switch, links repeater, image drop zone, design link box,
  admin may answer any seat.
- Tests: `WorkGatesTest`, `StandupRulesTest`, `workflow-rules.spec.js`, and
  the walkers filling the seats before triage.

## 2. `recurring-flow` (2.123.0)

- Sources carry a checklist; description is rich text and required, so are
  the start and the hours each; tasks made carry the checklist.
- Subscription check-ins are chores through the connection's primary seat.
- `Recurring\Load` projects running schedules into `Commitments::live`.
- Meetings: `attendee_ids` on the series; every attendee carries the hours;
  the form is a staff picker and the hours box is gone.
- Tests: `RecurringValidateTest`, `RecurringLoadTest`, `MeetingLoadTest`,
  the recurring and meetings specs.

## 3. `packages-admin` (2.124.0)

- Package terms gain `hours_per` (month or year) and a computed price per
  hour; the assignment grants the term's total.
- The Forge admin page leaves the menu; Sync health is first and default.
- Profile: History pages at five, Time off at ten; a time off's last day
  cannot be before its first.

## 4. Visual batch

Follows once the three above are merged and released.
