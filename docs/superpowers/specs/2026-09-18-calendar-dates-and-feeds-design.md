# Calendar dates and feeds — design

Block 6 of Luke's feedback of 2026-09-17, in his words: "the list … needs to act
as an entry point for calendar dates. I want to be able to add company dates,
birthdays, campaign days etc. I should be able to add individuals to these
items, and I should be able to select All Staff … These just need be basic
entries that then sit on all the views so people can see them." And: "ensure
the following all show in the Calendar and Daily Standup: Recurring tasks,
Calendar Dates, Meetings, Subscriptions, Leave Dates."

One studio PR, stacked on the recurring rework (`recurring-assignees`):
version 2.121.0.

## Calendar dates

A basic entry: what it is, when, and who it is for.

- Table `bwx_forge_calendar_dates`: id, `kind` (company-day, birthday,
  campaign, other), `title`, `on_date`, `ends_on` (optional, a campaign runs
  for days), `people` (`all`, or a JSON list of person ids), `note`,
  created_by/at, updated_at, record_version. Schema 27 → 28.
- Routes: `GET /calendar-dates?from&to` (signed in), `POST /calendar-dates`,
  `PATCH /calendar-dates/<id>`, `DELETE /calendar-dates/<id>` (administrators).
  Validation by field: a title, a real date, an end on or after the start, a
  known kind, real people or `all`.

## The feed

`GET /calendar?from&to` — everything on the studio's diary for the caller's
reach in a window, in one shape: `{ id, kind, date, ends_on, title, detail,
people, item_id? }` where kind is `recurring`, `date`, `meeting`,
`subscription` or `leave`.

- **Recurring**: work items with a `recurring_id` on sites in reach, on their
  planned due day, with "n of m done".
- **Dates**: calendar dates whose day (or span) touches the window.
- **Meetings**: `Meetings\Diary::for_site()` for each site in reach; the
  series title and the time.
- **Subscriptions**: active SureCart subscriptions renewing in the window;
  the customer and the product.
- **Leave**: `Capacity\Unavailability` for every active person; who, and what
  kind of time off.

`Calendar\Feed::for_reach( $reach, $from, $to )` builds it, so the calendar
and the standup read the same list.

## Screens

- **Calendar**: draws the feed's entries beside the work entries, each kind
  with its own colour and word (Chore, Date, Meeting, Renewal, Away). A
  recurring task opens its panel; the rest are read. A fourth range, **List**,
  last in the switcher: the next thirty days as a list, day by day, with an
  "Add a date" form above it for administrators (title, kind, date, until,
  All staff or people, note) and Remove on each date. The existing "List" view
  of the work switcher is untouched — this is the calendar's own list.
- **Daily standup**: a "Today's diary" panel at the top — the feed for today,
  one line each — so the day's meetings, renewals, who is away and what is
  due to be ticked are read before the cards.

## Tests

`calendar-feed.spec.js`: an administrator adds a company day for all staff
and a birthday for one person from the calendar's list; both are on the
month view and in the list; a person's leave, a meeting on a site in reach, a
subscription renewing today and a recurring chore all appear in the feed for
today and on the standup's diary; a staff person sees the same, and cannot
add a date (403).
