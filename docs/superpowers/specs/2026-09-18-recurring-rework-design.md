# Recurring tasks rework — design

Block 5 of Luke's feedback of 2026-09-17, in his words: "add a recurring task,
and then select 1 or more people that will be assigned to that task. Each one
of them will see it in their individual views, but in any team views it will
appear as one task … we don't need a reviewer or a deliverer. But each person
assigned needs to achieve it (tick it off) and we need the time assignments as
well so it goes towards capacity. We also need an option in the repeats for
Every weekday."

One studio PR, stacked on the profile page (`profile-page`): version 2.120.0.

## Shape

A recurring task stays what it is — an arrangement that makes one work item per
due day, placed at Up Next (`Recurring\Materialise`). What changes is who it is
for and how it is done:

- **Assignees, not seats.** A schedule source holds a list of people
  (`assignees`) and the hours each of them spends (`hours_each`). The three
  seats and their hours stay on the record for subscription sources, which the
  SureCart sync fills; the Add/Edit form no longer shows them.
- **One task, ticked by each.** The item made for a due day carries the same
  `assignees` and `hours_each`, plus `ticks`: who has ticked it and when. Team
  views (board, list, calendar) show the one card, with "1 of 3 done" on it.
  Individual views (My tasks, the panel) show the signed-in person's own tick.
  A person ticks their own; an administrator may tick for anyone. When everyone
  has ticked, the item is placed at Completed with the reason "Everyone ticked
  it off" — there is no review or delivery for a chore.
- **Capacity.** Each assignee carries `hours_each` against the item's day, as
  a fourth kind of allocation (`assignee`), alongside the seats.
- **Every weekday.** A fourth cadence, normalised to Monday–Friday of the
  weekly rule and described as "Every weekday".

## Data

- `bwx_forge_recurring`: `assignees text NULL` (JSON list of person ids),
  `hours_each decimal(8,2) NOT NULL DEFAULT 0`.
- `bwx_forge_work_items`: `assignees text NULL`, `ticks text NULL` (JSON map of
  person id to unix time), `hours_each decimal(8,2) NOT NULL DEFAULT 0`.
- Schema version 26 → 27.

## Routes

- `POST /recurring` and `PATCH /recurring/<id>` accept `assignees` (list of
  person ids, each real) and `hours_each` (0 or more); a schedule needs at
  least one assignee ("Choose at least one person.").
- `POST /work-items/<id>/tick` `{ done: bool, user_id?: id }` — the signed-in
  person's own tick (any assignee), or another's for an administrator. Answers
  the item as it now stands. Refused for anyone not assigned (403) and for an
  item without assignees (409).

## Screens

- Recurring tasks: the form has a "Who does it" list of people with a tick
  each and one "Hours each" pick; Repeats offers Every weekday. The table's
  Who column lists the assignees; Hours shows hours each × people.
- Board card and list row: "n of m done" tag on a recurring item.
- My tasks: an assignee's rows carry a Done tick that posts the tick; the row
  reads "you and 2 others".
- Panel: a "Who does it" card on an item with assignees — each person with a
  tick, the signed-in assignee's tick a control — replacing the seats block
  for that item.

## Tests

`recurring-ticks.spec.js`: a weekday source with two assignees makes one task
today; on the board it is one card reading 0 of 2; each assignee ticks from
My tasks; after the first tick the card reads 1 of 2 and the item is still at
Up Next; after the second it is Completed; capacity for the week shows each
assignee's hours. `recurring-rest.spec.js` and `recurring-screen.spec.js`
updated to assignees. Unit: `Rule` weekday normalise and describe.
