# Availability patterns and the Profile page — design

Block 4 of Luke's feedback of 2026-09-17, in his words:

- "I need to be able to set regular work hours for a person, not just a week at
  a time. When setting hours, we need to add an end date as well."
- "When setting hours, there must be a max 12 hours per day."
- "We need to create a profile page for all staff to use. This is specifically
  for the parent people. From the profile they can configure their slack, their
  availability, their leave. They should also be able to edit their details
  (linked directly to wordpress profile). They can also logout from here. They
  can access their profile by clicking the profile button in the top right."

Two studio PRs, each its own branch and draft PR, stacked on the task panel
work (`task-panel-gates`):

| PR | Version | What |
|---|---|---|
| D — Hours until a date | 2.118.0 | A working week may end; no day above 12 hours |
| E — Profile page | 2.119.0 | `#screen=profile`: your details, Slack, working week and time off, log out |

## PR D — Hours until a date, 12 hours a day

Today a working week is recorded from a date and runs until the next one
recorded (`Capacity\Patterns`, effective-dated rows). That already is
"regular hours, not a week at a time"; what is missing is an end.

- The pattern row gains `effective_to` (date, nullable). `Patterns::record()`
  takes it; `Patterns::pick()` ignores a row whose `effective_to` is before the
  date asked about, so after the end the earlier row (or nothing) is in force
  again. Schema version +1.
- The route refuses a day above 12 hours (`400`, field error on that day:
  "At most 12 hours in a day.") and an end before the start ("The end has to be
  on or after the start."). `Patterns::record()` also clamps to 12 as the
  backstop.
- The hours form gains "Until" (optional, "Leave empty for ongoing hours") and
  the inputs take `max="12"`. The history table shows From and Until.
- The availability answer's `current` is the pattern in force today, as now.

Tests: unit — a pattern with an end is not in force after it, and the earlier
one is again; hours over 12 are clamped. e2e — hours until a date show as
unrecorded the day after; a 13-hour day is refused with the field's words.

## PR E — Profile page

A screen for the signed-in studio person, reached from the top-right pill
(which today links to the WordPress profile). Route `#screen=profile`; not in
the rail.

- `/me` (GET, `signed_in`, refuses anyone who is not a Forge person with the
  standard denied shape): the person (id, name), the account (display name,
  email, login), the WordPress profile URL, the log-out URL, and Slack
  (connected, prefs, last error).
- `/me/slack` (POST: `url` optional, `prefs` map; connects when a url is given,
  tests it, saves prefs; DELETE: disconnects). The rules are
  `Admin\ProfileSlack`'s, moved into `Slack\People`-level helpers so both the
  WordPress profile section and the app call the same code.
- The availability and leave routes accept the person themself as well as an
  administrator: `Permissions::manage_or_self( $request )` — manage, or the
  route's `user_id` is the caller's own Forge person.
- Screen cards, in order: **Your details** (name, email, login; "Edit your
  details" → WordPress profile, "Log out" → log-out URL). **Slack** (status,
  webhook box that is always empty on the way out, the four preferences,
  Save; Disconnect when connected). **Working week** and **Time off** — the
  `AvailabilityScreen` with `person` fixed to the signed-in person and the
  person picker hidden (a `fixed` prop), so the forms and tables are the same
  ones the administrator uses.
- Someone signed in without a Forge person sees the denied screen with "Your
  account is not a person in Forge yet."

Tests: e2e `profile.spec.js` — a staff person (not an administrator) opens the
pill, sees their name, saves Slack preferences, records a working week and a
day off; the administrator's Availability screen shows what they recorded;
a second staff person cannot write the first one's hours (403).

## Not in this work

Recurring tasks (block 5), calendar dates and feeds (block 6).
