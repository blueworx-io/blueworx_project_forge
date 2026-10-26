# Google meetings replace manual meetings

Date: 2026-10-26. Follows #606 (Google Calendar meetings in Forge).

## Why

Luke connected Google Calendar on the live site on 26 October and found three
problems, plus a gap:

- Standup asked about seventeen meetings from the past four weeks. Every one of
  them was already accounted for by hand, through Forge's manual meetings.
- A recurring meeting, such as the weekly Sprint Meeting, asks for its client
  again every week.
- A meeting with only BlueWorx staff on it still asks for a client, when it is
  plainly studio time.
- The Meetings page only shows manual meetings. The studio is moving its
  meetings to Google and removing the manual ones, so that page would go empty
  and there would be nowhere to see how much meeting time each client has had.

The wrong meeting times Luke saw on standup were not a code fault. The site
clock was set to UTC+0 and has been changed to Johannesburg (Cape Town time).
Nothing in this design covers that.

## Goals

1. **A start date for each person.** The day a person first connects Google is
   their start date. Meetings before it are never brought in or shown. From
   then on, a meeting nobody has dealt with stays on standup until somebody
   does, as now.
2. **Recurring meetings remember their client.** Once one meeting in a series
   is given a client and a support-hours choice, the later meetings in that
   series arrive with both already filled in. Each one is still marked held,
   cancelled or nobody came. Changing the client on a later one makes that the
   choice for every meeting after it.
3. **Internal meetings fill themselves in.** A meeting where everyone invited is
   BlueWorx staff is given the studio (BlueWorx) automatically. It charges no
   client, never comes off support hours, and still counts in each person's
   capacity. If anyone from outside is invited, the client is picked as now.
4. **The Meetings page shows Google meetings.** It opens on a list of every
   client that has meetings, with the all-time hours spent in meetings with
   them. Clicking a client opens their page, showing past and upcoming meetings
   from Google and manual meetings together, with each meeting's details.
   Adding a manual meeting stays, for anything that happens outside Google.

## Non-goals

- No change to how a meeting is charged. A client is charged a meeting's length
  once, however many staff attend, and each staff member who has not declined
  has that time counted against their own capacity (Luke confirmed, 26 Oct).
- No change to the rule that Forge only reads Google and never writes to it.
- Matching a meeting to a client by guessing from the client's email addresses
  is still out, as #606 decided. The only automatic choice is the studio, for a
  meeting where everyone invited is BlueWorx staff.
- Manual meetings are not removed or migrated. Luke removes the ones he no
  longer wants himself.

## Anti-goals

- A meeting before a person's start date must never charge a client or appear
  on standup. That time was already accounted for by hand, and it would be
  counted twice.
- A pre-filled client must never charge hours on its own. Hours are drawn only
  when someone marks the meeting held, as now.
- Nothing already marked held, or already charged, may change or disappear
  because of this work.

## How it should behave

### Start date

- A person's start date is the day of their first Google connection, in studio
  time. Luke's is 26 October 2026.
- Disconnecting and reconnecting does not move it. *(Claude's call: otherwise a
  reconnect would quietly hide meetings that happened while disconnected, which
  is the opposite of "stays until someone deals with it".)*
- A meeting is kept if it is on or after the start date of at least one person
  whose calendar holds it.
- When this ships, any meeting already brought in before its people's start
  dates, and not yet dealt with, is removed. Anything already dealt with stays.
- A shared meeting kept because of someone else's start date does not count
  against the time of a person whose own start date is later. *(Claude's call:
  their time then was already accounted for by hand.)*
- Disconnecting deletes the connection record, so the start date has to be kept
  somewhere that survives a disconnect.

### Recurring meetings

- Google marks which meetings belong to the same series. Forge keeps that link.
- Pre-filling only touches later meetings in the series that nobody has dealt
  with yet. A meeting someone has already settled is never overwritten.
- "Later" means the same meeting and every one after it in the series, by date.
- Changing the support-hours tick on one meeting applies to that meeting only
  (Luke, 26 Oct: "just that one ... things can change"). Only a change of client
  carries forward. Later meetings keep taking the tick from the series' first
  pick. *(Claude's call: Luke did not object when this was proposed.)*
- If a single meeting in the series is moved, it is still part of the series.
- Only a person's pick is remembered for the series. An automatic studio choice
  is not. *(Claude's call: otherwise a later meeting in the series with an
  outside guest would stay on the studio without anyone being asked.)*
- A pre-filled meeting sets its hours aside ahead of time, exactly as a manual
  standing meeting does today and as a Google meeting given a client by hand
  already does. Setting aside is not charging: hours are only spent when the
  meeting is marked held. *(Claude's call: Google meetings replace the manual
  ones like for like.)*
- A pre-filled or automatic choice must be told apart from a person's choice.
  Today, having a client is what makes a meeting count as "dealt with", and
  several parts of the code rely on that. A pre-filled meeting is not dealt
  with until someone marks what happened.

### Internal meetings

- "BlueWorx staff" means the people Forge already treats as studio staff. An
  invited email counts as staff if it belongs to one of them, or if it is on the
  studio client's own email domains. *(Claude's call.)*
- A meeting with no other guests counts as internal. *(Claude's call: such a
  meeting can be given a client by hand, and studio time never spends a
  client's hours, so a wrong guess costs nothing.)*
- The automatic choice can be changed by hand like any other.
- Internal meetings still wait on standup to be marked held, cancelled or nobody
  came (Luke, 26 Oct: "you only mark it as held"). Only held ones count towards
  the BlueWorx total on the client list.
- A Google meeting that duplicates a manual standing meeting stays hidden after
  an automatic or pre-filled choice. Only a person's pick unhides it, as now.
  *(Claude's call: otherwise both copies would count while manual and Google
  meetings overlap, which is exactly the changeover period.)*
- If a recurring meeting's remembered client and the internal rule disagree, the
  remembered client wins: someone chose it on purpose.

### Meetings page

- **Client list.** One row per client site that has at least one meeting,
  Google or manual, named by client (and site, when a client has more than
  one). Clicking a row opens that site's page, as the top bar does. *(Claude's
  call: meetings and hours belong to a site, and today's All view is already
  one row per site.)* Each row shows the all-time hours of meetings marked held. BlueWorx
  appears too, so studio time is visible.
- A Google meeting nobody has given a client yet does not appear here. It is on
  standup until somebody deals with it.
- **Client page: keep today's page exactly as it is** (Luke, 26 Oct). That is the
  page a client gets when picked in the top bar: Standing meetings with "Add a
  standing meeting", The next twelve weeks grouped by week, and Past meetings
  with Newer / Older. Same columns: When, What, Hours, What happened, Hours
  held, Actions.
- Google meetings given to that client join the same week groups and the same
  past list, in date order among the manual ones. Each is marked as coming from
  Google, and its Actions are the Google choices (held, cancelled, nobody came,
  client and support hours) instead of Move, since its time belongs to Google.
- The client list replaces today's "Standing meetings, every client" view, which
  is what shows when All clients is picked in the top bar.

## Also in this change

- The rules page (`src/rules.ts`) is updated to describe all four behaviours.
- Version bump and changelog, as every change requires.

## Left to the builder

- Where the start date and the series link are stored.
- How the client list and the client page get their data, provided the existing
  hours rules are reused and not rewritten.
- Layout of the client list and client page, within the existing design kit.
