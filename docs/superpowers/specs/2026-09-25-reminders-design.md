# Reminders — design

Luke, 2026-09-25: "a new page for Reminders. These are essentially just
recurring tasks that don't have a recurrence, they are for a fixed date or
period. They should show up in the calendar and in My Tasks. I should be able
to assign them to a client." Recurring tasks gain the same client choice.

One studio PR, branch `add-reminders`: version 2.131.0.

## Shape

A reminder is a recurring source of a third kind, `reminder`, with no repeat
rule: a start date and an optional end date. Saving it makes its tasks at once
— one work item per person, as a recurring task does (`Materialise::copies`) —
so each person sees it ahead of time. Like a recurring task it is free
(`free-general`), carries no hours, has no reviewer or deliverer, and is
finished by the person's tick.

- **A date or a period.** `starts_on` is required; `ends_on` is optional and,
  when given, on or after it. Each copy has `planned_start = starts_on` and
  `planned_due = ends_on ?: starts_on`. The copy's title is the reminder's
  title, with no date suffix.
- **Client required.** Every reminder and every recurring task names a client
  site. The picker lists the client sites the person reaches, the studio's own
  site among them. Existing recurring tasks already hold the studio's site and
  are left as they are.
- **Who may do what.** Anyone on the team may add a reminder, for themselves
  or others. Its author or an administrator may edit or delete it. Recurring
  tasks stay administrator-only to write.
- **Editing and deleting.** An edit rewrites the title, notes, dates and
  people on the copies nobody has ticked; a person removed loses their unticked
  copy, a person added gets one. Deleting a reminder deletes its unticked
  copies; ticked ones stay as the record of what was done.
- **Reach.** Reading recurring tasks and reminders is limited to the client
  sites the person reaches, the same rule as other work, instead of "reaches
  the studio's site".

## Data

No schema change. `bwx_forge_recurring` already has `client_site_id`,
`client_id`, `kind`, `starts_on`, `ends_on` and `rule`; a reminder is
`kind = 'reminder'` with an empty rule. Its one occurrence is recorded at
`starts_on` in `bwx_forge_recurring_occurrences`, so it is never made twice.

## Routes

- `GET /reminders` — the reminders on sites the person reaches, newest first,
  each with its copies' tick state.
- `POST /reminders` `{ client_site_id, title, description?, assignees,
  starts_on, ends_on? }` — needs a real client site the person reaches ("Choose
  a client."), at least one person ("Choose at least one person."), and a valid
  date order ("The end date is before the start date."). Answers the reminder
  and makes its copies.
- `PATCH /reminders/<id>`, `DELETE /reminders/<id>` — author or administrator,
  otherwise 403.
- `POST /recurring` and `PATCH /recurring/<id>` accept and require
  `client_site_id`, checked the same way; `GET /recurring` filters by reach.

## Screens

- **Reminders page.** A rail entry beside Recurring tasks, its own screen
  (`reminders`). A table of reminders: title, client, who, when ("3 Oct" or
  "1–5 Oct"), and "n of m done". An Add button opens the form: title, notes,
  client, people, start date, end date (optional).
- **Recurring tasks.** The form gains a required Client picker; the table
  gains a Client column.
- **My Tasks.** A reminder copy is sorted into a tab by its start date, not its
  end date: Today once the start date has arrived (and until it is ticked),
  Next seven days if it starts within seven days, Further out otherwise.
  Everything assigned to you lists it throughout. It carries the same Done tick
  as a recurring copy.
- **Calendar.** A sixth kind of entry, "Reminder", on every day from start to
  end. Reminder copies are kept out of the "Chore" entries and the ordinary
  date entries so none shows twice.

## Tests

`reminders.spec.js`: a person adds a reminder for a client with two people
over a three-day period starting today; each sees it in My Tasks under Today
with the client's name; the calendar shows "Reminder" on each of the three
days; one ticks it and it leaves their list but not the other's. A reminder
starting in ten days is under Further out and not Today. A non-author,
non-administrator cannot edit it. `recurring-screen.spec.js`: a recurring task
is added for a chosen client and its task appears under that client.

## Addendum, 2026-09-25: reminder types

Luke: "give the option to select General, Campaign, Marketing, Deadline,
Other." Every reminder has a type, one of those five, General by default. It
is chosen on the form, shown in its own column on the Reminders page, and
leads the calendar entry's detail ("Campaign · To do"). Stored in a new
`category` column on `bwx_forge_recurring` (schema 32 → 33); other kinds
leave it empty.
