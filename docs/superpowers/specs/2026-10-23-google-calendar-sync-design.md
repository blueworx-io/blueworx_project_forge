# Google Calendar meetings in Forge

Date: 2026-10-23. Issue #606.

## Why

Most of the studio's meetings live in Google Calendar, not in Forge. Forge only
knows about standing meetings an administrator sets up by hand, so capacity,
My tasks and client support hours miss most of the time people actually spend
in meetings. Each staff member should connect their own Google work calendar
and have their meetings come into Forge on their own, then be settled against
a client the same way existing meetings are.

## Goals

- Each staff member connects and disconnects their own Google **work** account,
  themselves.
- Every event on that calendar comes into Forge and stays in step with Google:
  new, moved, edited and deleted events all follow.
- A synced meeting is settled in Forge: pick the client, and say whether its
  time comes off that client's support hours.
- Once settled it behaves like today's meetings (see "Settling").

## Non-goals

- Forge never writes to Google. The sync is one way, Google into Forge.
  *(Claude's call: Luke did not ask for writing back.)*
- No automatic matching of meetings to clients by attendee email. A person
  settles every meeting by hand (Luke, 2026-10-23).
- Personal Google accounts are not a use case; only work accounts are
  connected. Nothing in the product has to stop a personal one, but nothing is
  designed around it.
- The day planner (#620) and the Connections page (#621) are separate pieces of
  work. This design must not depend on either.

## Anti-goals

- A holiday or out-of-office day must not fill somebody's capacity.
- One meeting with two staff invited must not charge the client twice.
- Nobody else's calendar, tokens or private event details are exposed: a
  person's Google connection is theirs alone.

## What comes across

Everything on the connected person's primary calendar, including events they
declined and all-day events (Luke, 2026-10-23). Changes in Google update Forge;
an event deleted or cancelled in Google is removed from Forge, unless it was
already settled as held, in which case it stays and the client stays charged
(the same rule as today: hours charged for held meetings stay charged).

## Standing meetings that are also in Google

Standing meetings stay as they are. A Google event that matches a standing
meeting is skipped, so the meeting counts and charges once (Luke,
2026-10-23). It matches when it is on the same day, overlaps the standing
meeting's time, and the connected person is that meeting's host or one of
its attendees. Matching is checked again on every sync, so if either one
moves, the Google event appears or disappears to suit. Luke accepted that
this is imperfect when the two drift apart. The builder sets the exact
overlap rule.

## Your time

- A synced meeting counts against its people's capacity like any meeting,
  from the moment it arrives, before it is settled.
- **Declined events and all-day events show but do not count against
  anybody's time.** *(Claude's call, to meet the first anti-goal.)*
- "Its people" means the staff members whose connected calendars hold the
  event and who have not declined it.

## Shared meetings

When the same Google event (same event id, or same iCal UID for invitations
across calendars) is on more than one connected calendar, Forge shows **one**
meeting with all of those staff on it. It is settled once and the client is
charged once. *(Claude's call.)*

## Settling

- A synced meeting arrives with **no client**. It appears in "meetings to
  settle" on the standup, like an unsettled standing meeting today.
- Settling a meeting means choosing:
  1. the client it was for, or "no client" for internal meetings, and
  2. whether its time comes off that client's support hours.
- Then the existing meeting rules apply unchanged: it can be marked held,
  cancelled or "nobody came"; its hours are its length rounded up to the next
  half hour; each person carries those hours in their capacity; the client is
  charged once, and only for a meeting marked held with "comes off support"
  chosen.
- Settling picks a client **site**, because support hours belong to a site.
  A client with one site has it picked for them. "No client" means the
  studio's own site, so internal meetings stay inside the existing access
  rules. *(Claude's call.)*
- The client can be picked before the meeting happens. From then on it holds
  that site's hours exactly as a standing meeting does (inside the twelve-week
  window and the current term), and is charged only when marked held, as
  today. So settling is two steps, as for standing meetings: pick the client
  any time, mark held / cancelled / nobody came on or after the day.
  *(Claude's call.)*
- Declined and all-day events never need settling and never appear in
  "meetings to settle" or the sidebar count. *(Claude's call.)*
- Who can settle: an administrator, or any staff member the meeting belongs to.
  *(Claude's call, matching "an administrator or the meeting's host" today.)*
- If Google later moves or re-times a settled meeting, the move is applied and
  the settlement kept; the hours follow the new length.

## Connecting

- Each staff member has a Google section on their own page in the Forge app
  (ARCH-7: the screen lives in the React app, not WordPress admin) with
  *Connect Google Calendar*, the connected address, when it last synced, and
  *Disconnect*.
- Disconnecting stops the sync and removes that person's **unsettled** synced
  meetings. Settled ones stay, because their hours may already be charged.
- The studio needs a Google OAuth client (a Google Cloud project, consent
  screen set to "Internal" for the studio's Google Workspace). An
  administrator enters its client id and secret once in Forge Settings.
  *(Claude's call on where; it moves into the Connections page when #621 is
  built.)* Luke has to create that Google Cloud project; the build should come
  with short setup steps.
- Only read access to calendars is requested.

## Safety

- Refresh and access tokens are stored encrypted at rest and never sent to the
  browser or shown back. The OAuth client secret is never shown back in full.
- A person can only connect, see or disconnect their own Google connection;
  an administrator can see who is connected and disconnect anyone, but never
  sees their tokens.
- Colleagues see a synced meeting's title, time and which staff are on it.
  Its description and outside attendees are shown only to the staff it
  belongs to and administrators. An event marked private in Google shows to
  everyone else as "Busy", with no title. *(Claude's call.)*
- This is security-sensitive work (OAuth, secrets): run the `security-review`
  skill before committing.

## Where synced meetings show

The same places standing meetings show today: Today's diary on My tasks, the
calendar, capacity, the standup's "meetings to settle", reports, and the
client's hours list once charged. They are marked as coming from Google so
nobody tries to edit their time in Forge.

## Left to the builder

- How the sync runs. Forge deliberately has no cron (see
  `includes/Recurring/Materialise.php`): the expected shape is an incremental
  sync (Google sync tokens) triggered when Forge is opened, throttled per
  person, plus optional Google push notifications if they fit.
- How far back and ahead to sync (a sensible window, e.g. four weeks back and
  twelve ahead, matching how far client hours are held).
- Storage: a new table for synced meetings and one for connections, or reuse
  of the meeting occurrence tables, whichever keeps the existing meeting rules
  in one place.
- Recurring Google events: each instance arrives as its own meeting.
- Exact wording on screens, following the existing meeting screens.

## Done when

- Two staff members each connect their own calendar and see their meetings in
  Forge.
- A meeting on both their calendars shows once, with both of them on it.
- A synced meeting can be settled to a client with time off support, marked
  held, and the client is charged once.
- A meeting moved or deleted in Google is updated or removed in Forge.
- A declined or all-day event shows but adds nothing to capacity.
- `src/rules.ts`, the changelog and the version are updated in the same PR.
