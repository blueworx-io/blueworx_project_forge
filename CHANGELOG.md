# Changelog

All notable changes to Forge Project Management are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Releases before 1.37.0 predate this file; their history is in the repository's
commits and pull requests.

## [2.130.0] - 2026-09-24

### Added

- Time estimates: 5 minutes, 4 hours and 5 hours can now be picked.

## [2.129.2] - 2026-09-21

### Fixed

- Packages: a price can now be per month as well as per year. The hourly rate, what an assignment charges, pro-rata sums and the price a client sees all use the whole term's price, so a 24-hour package at �100 a month reads �50 an hour, not �4.17. Existing packages stay per year until revised.
- Client site: package prices show in pounds as the studio set them, not divided by a hundred — £100 no longer reads £1.00.

## [2.129.1] - 2026-09-20

### Fixed

- People: each person's card now takes the full width, one per row, instead of two side by side.

## [2.129.0] - 2026-09-20

### Changed

- No site needs a token to update any more: the repository is public, so both plugins read its releases directly. The Updates screen on the studio and the Updates panel on a client site's Connection screen now only say whether a release can be seen and which version the site runs; the token fields, the "remove the stored token" buttons and the wp-config setting are gone, and any token a site still held is forgotten.

## [2.128.0] - 2026-09-20

### Changed

- The Gantt is redrawn on the kit: a month band over the weeks, a grid behind the rows, today marked and named, a legend for the colours, rows in date order, bars that say "21 Sep – 16 Oct" and carry the full detail on hover, and an empty state and "Without dates" tray in the same cards as everywhere else.

## [2.127.0] - 2026-09-20

### Fixed

- The people pickers — who is doing, reviewing and delivering work, who does a recurring task, who comes to a meeting, who a date is about — offer our own people only, never a client's administrator or viewer. Somebody added on People but not yet given access still appears.
- Staff members see names in those pickers and on the board's cards; before, only an administrator did.

## [2.126.2] - 2026-09-20

### Fixed

- Each line on a site's hours ledger says which meeting or task it was for — "Weekly check-in on 2026-09-26" — instead of a column of identical "Meeting reserved" lines.

## [2.126.1] - 2026-09-20

### Fixed

- `npm run wp:seed` fills a fresh local site with clients, people, packages, meetings, work at several stages, chores and diary dates, so a review site is one command away.
- The diary spec's staff test makes its own date rather than leaning on an earlier test's.

## [2.126.0] - 2026-09-20

### Changed

- Standup cards keep their outstanding list folded behind "3 things outstanding · Show", so the page is a list of work rather than every requirement on every item.
- A busy calendar day shows its first three entries and "+N more"; opening it shows them all, with "Show less" to fold it back.

### Fixed

- Recurring tasks no longer appear on the standup as "Pending" with seats and dates to fill; they move by their ticks, not by a gate.

## [2.125.2] - 2026-09-20

### Fixed

- Each "Go to" on a before-row says where it goes, for screen readers; the planned-hours row has one too.

## [2.125.1] - 2026-09-19

### Fixed

- My tasks loads in one read instead of one per client site, so it opens in a moment on a studio with hundreds of sites.
- A "before" row whose answer is a field lower down, or a piece of evidence, now has a "Go to" that opens the right section and lands on the field or the comment form.
- The count beside a checklist says how many lines are ticked, and the counts beside Links and Images only show once there is something to count.
- The numbers on the "Where work is sitting" report line up in a column at the right, whatever the length of the bar.
- A recurring task ticked for all seven days reads "Every day".
- Turning a request into work no longer asks what it sits under; new work stands alone.
- The workflow document's gate tables match what ships again.
- The Gantt's first week names its month, so a short schedule no longer reads as bare numbers.
- People are listed by name everywhere — pickers, Capacity, Who does it, Who else comes — rather than newest first.

## [2.125.0] - 2026-09-19

### Changed

- Banners mean what their colour says: green confirms and clears itself after five seconds, yellow warns and red says something failed, and both of those stay until closed. Every banner has a close button.
- Screen titles are 26px everywhere, with the header trimmed to match and "Refreshed" in its top right corner. The three pills in the top bar are one height, "Lately" is now "Updates", and a short rule sits before your name.
- Every side panel opens the same way: the close in the top right corner, a body that scrolls, and its buttons in a footer that stays put.
- The task panel: every section folds; Comments (now their own card), Comments and evidence, and History start folded. Spacing and alignment brought into line throughout.
- Cards carry the priority as a coloured tag in the top right corner.
- Dropdown arrows have room to their right. "Go to" on the calendar stays on one line.
- The standup: "Pending" and "Needs review" replace the old wording; Hide sits beside Open in each card's top right corner; Today's diary is redrawn with a picture, a chip and the details for each entry, in columns where there is room.
- The calendar's List range is a panel a day on the site's own components, with Add a date opening as a side panel.
- My tasks: the due column is red more than a day late, yellow a day late, plain for today and green ahead; your tick and the checklist's state sit beneath the task's title.
- Clients: "Start onboarding" is "Onboard", and a site's actions stay on one line.
- The report tables are the site's standard tables, and the count always sits to the right of its bar.
- Your profile: proper spacing, Slack last, tables using the full width, and two sentences dropped.
## [2.124.0] - 2026-09-19

### Added

- A package says whether its hours are per year (the whole term, as before) or per month, and shows what an hour costs. Per-month hours are granted for the whole term when the package is assigned.

### Changed

- The WordPress menu opens on Sync health, which is now its first entry; the old Forge client-sites page is off the menu (still reachable by its address).
- On your profile, History shows five rows a page and Time off ten.
- A time off's last day cannot be before its first; the same day is fine.
## [2.123.0] - 2026-09-19

### Changed

- A recurring task needs a title, what to do (now formatted text), a type, how often, a start date, at least one person and the hours each. It can carry a checklist, which every task it makes starts with.
- The days ahead of a recurring task count against each person's capacity before the tasks exist, the same as standing meetings.
- Subscription check-ins are ticked off like recurring tasks and go straight to Released.
- Meetings: "How long" is the hours for everyone attending. "Who else comes" is a list of staff to tick, and each of them carries the meeting's hours; the client is charged it once. The separate hours box has gone.
## [2.122.0] - 2026-09-19

### Changed

- The task panel: the "before" checklist comes first, then one Actions card with Send back, Block, End it and the move forward, each shown only when it applies.
- Somebody doing the work and somebody reviewing it have to be named before an item leaves Captured. The parent question at triage has gone.
- Documentation approval, technical approval and design approval are the reviewer's to give; the panel says so and the server refuses anyone else. An administrator can act for anyone, which also fixes In Development being impossible to pass.
- Technical Audit is the reviewer signing off the documentation: its write-in assessments (architecture, data and sync, security and privacy, test approach, estimate range) have gone. Accessibility considerations has gone from the design stage.
- Dates keep their order: start, due, review by, release by. Any can be in the past.
- An item's checklist has to be finished before it leaves In Development. "Hours still to do" has gone.
- Work with something outstanding stays on the daily standup from the day it is captured until it is released.
- Recurring tasks and subscription check-ins go straight to Released once everyone has ticked them, and cannot be ticked while their checklist has lines open.

### Added

- Review and testing on the task: how to test it (required before In Review) and optional test steps, shown to the reviewer.
- A design link on the task, required to leave Design Process, instead of a comment.
- Links (up to ten) and images (dropped or chosen, kept in the media library) on the task from the documentation period on. Reference material is now optional.
- A Dependencies card with a switch, off by default: on, it connects the item to the work it waits on.

## [2.121.0] - 2026-09-18

### Added

- Calendar dates: company days, birthdays, campaign days and the like, for all staff or for named people. Added and removed from the calendar's new List range (last in the switcher), which lists the next thirty days day by day.
- The calendar and the daily standup now show recurring chores, calendar dates, meetings, subscription renewals and who is away, alongside the work. The standup opens with Today's diary.

## [2.120.0] - 2026-09-18

### Changed

- Recurring tasks are for one or more people, with the hours each of them spends — no reviewer or deliverer. Each day's task is one card on the board ("1 of 3 done"); each person ticks their own from My tasks or the task itself, and when everyone has, it is Completed. Each person's hours count against their capacity. Repeats offers Every weekday.

## [2.119.0] - 2026-09-18

### Added

- Your profile: click your name in the top-right corner to see your details (with a link to edit them), connect Slack and choose what it tells you about, set your own working week and time off, and log out. Anyone on staff can set their own hours from here; only an administrator can set somebody else's.

## [2.118.0] - 2026-09-18

### Added

- A working week can be set until a date as well as from one. After the last day, the week that was in force before it applies again — so reduced hours for a month hand back to the usual week without a second entry.

### Changed

- No day may hold more than 12 hours; the form and the server both refuse it.

## [2.117.0] - 2026-09-18

### Changed

- Nothing on a task's "before" list asks you to type a record any more. Each check is either worked out from the task (the site, who pays, the hours, a ticked checklist, a comment with a link since the stage began, dependencies that are done), chosen from a dropdown on its row (source, parent, duplicate check, bug type, severity, approvals, done-or-not), or filled in a box on the task that appears only when the next stage wants it (reproduction steps, estimate range, release notes, dates). Picks and boxes save with Save changes. Three checks that asked for Scope or Requirements are gone with their boxes.
- The Block form picks the site's item that is in the way and who owns the blocker (a person, or the client) instead of taking typed names. End it → duplicate picks the surviving item from a list.

## [2.116.0] - 2026-09-18

### Added

- Item description and Completed when take bold, italics, underline, lists and links, from a small editor of Forge's own. Every task can carry a checklist of up to ten one-line items: tick them off, save, and the board card shows how many are done.

### Changed

- The short item codes are gone from the board cards, the list and My tasks; the panel still shows the code at the top.

## [2.115.0] - 2026-09-18

### Changed

- The task panel has a fixed header and footer — Delete on the left, Save changes on the right — with every block in its own card and its buttons on the right. Problem it solves is now Item description, Done when is Completed when, and Scope and Requirements are gone. Who pays for it offers To be confirmed, Site bug, Client, or No charge; Site bug means we delivered the thing that broke, so the separate tick is gone. History stays folded until opened, lists what actually happened with one line per save and who did it, and adding a comment never needed Save. New task asks which client it is for.

## [2.114.1] - 2026-09-18

### Changed

- A visual tidy-up across the app. Tables sit directly in their panel instead of a frame inside a frame; messages are green when something worked, yellow when something needs minding and red only when it failed; standing meetings take a full row each, and Move and Settle sit side by side under an Actions heading; the People cards lose the email and sign-in line, and show a "No account" mark only when someone cannot sign in; Availability's person picker sits at the top, its tables fill the width, and the working week is a row of tiles; Reports are cards; Intake sits above Delivery in the rail; the profile button is a pill like Lately and New task; List is last in the view switcher; Feature is no longer a task type; the days of a weekly recurring task are a row of pills; and the hours for a seat are picked from 10 minutes up to 3 hours.

## [2.114.0] - 2026-09-18

### Changed

- The six configuration screens — Clients, People, Availability, Packages, Support and Meetings — now live only in the app: Clients, Support and Meetings under Clients; People and Availability under Team; Packages under Insight. Their WordPress admin pages are gone; Sites, Connections, Onboarding templates, Sales, Sync health and Updates stay in WordPress admin.

## [2.113.0] - 2026-09-18

### Added

- Meetings are now in the app, under Clients: a site's standing meetings and the next twelve weeks of them; add a series, end one, move a meeting or settle it — without going to WordPress admin. The admin page stays for now.

## [2.112.0] - 2026-09-18

### Added

- Support is now in the app, under Clients: see what a site is on, its periods and its hours ledger; assign a package (the assign button shows the exact hours it will write), top up, adjust with a reason, suspend, resume or cancel — without going to WordPress admin. The admin page stays for now.

## [2.111.0] - 2026-09-18

### Added

- Clients are now in the app, first under Clients: add and edit clients and their sites, set who the contact is, start onboarding, issue or revoke a site's connection key, and rename the studio — without going to WordPress admin. The admin page stays for now.

## [2.110.0] - 2026-09-17

### Added

- People are now in the app, under Team: one card per person with every client they work with beneath it. Add somebody, give them an account, edit, offboard, set their access and grants — without going to WordPress admin. The admin page stays for now.

## [2.109.0] - 2026-09-17

### Added

- Support packages are now in the app, under Insight: see what is on offer, add a package, revise it as a new version with every earlier version still on record, retire or restore it, and put the catalogue in order — without going to WordPress admin. The admin page stays for now.

## [2.108.0] - 2026-09-16

### Added

- Availability is now in the app, under Team: pick a person, see their week, set their hours and record time off, without going to WordPress admin. The admin page stays for now.

## [2.107.0] - 2026-09-15

### Added

- The open BlueWorx work from ClickUp arrives on the studio board with this update, each item in the stage it was at. Runs once, on blueworx.io only.

## [2.106.2] - 2026-09-15

### Fixed

- The client site's Forge menu entry shows the Forge icon, not WordPress's hammer.

## [2.106.1] - 2026-09-15

### Changed

- The signed-in person's chip, top right, shows their name only.

## [2.106.0] - 2026-09-15

### Added

- The top right of every screen shows who is signed in — name and sign-in address — and opens their WordPress profile.
## [2.105.1] - 2026-09-15

### Fixed

- The Forge menu icon sits in line with the other menu icons on sites with a restyled admin menu, and follows the menu's hover and current colours the way every other icon does.

## [2.105.0] - 2026-09-15

### Added

- Staff can connect Slack on their WordPress profile by pasting an incoming webhook, and be told when work is assigned to them, when something is ready for their review or delivery, when a task of theirs is commented on, and each morning what is due today. Each of the four can be switched off. The morning time is set under Forge → Connections, and messages that could not be sent are listed on Sync health.

## [2.104.0] - 2026-09-15

### Added

- SureCart stores can be connected under Forge → Connections with an API token, choosing which staff look after each one. Active subscriptions appear under Insight › Subscriptions, and on each renewal day a "Subscription Renewal: Customer - (£Amount)" task lands on the studio's board for those staff, due that day — so My tasks and Daily standup carry it too.

## [2.103.0] - 2026-09-15

### Added

- A Recurring tasks screen for the studio's own site. Set a task to repeat daily, weekly on chosen days, or monthly, with the same seats and hours as any task. Each due day becomes a dated task in Up Next ("Weekly backups — 14 Sep"), and a missed day is still made the next time anyone opens Forge.

## [2.102.0] - 2026-09-14

### Added

- Switching between screens no longer reloads everything. Each screen shows what it already has at once and quietly checks for changes, updating in place if anything moved.
- Every page header says when its data was last refreshed, with a button to read everything again.

## [2.101.0] - 2026-09-14

### Added

- Forge has a client for the studio itself, created automatically and named after the site, so your own work can be added and tracked like any client's. Rename it at the top of the Clients screen.
- Administrators can delete a piece of work, and everything under it, from the item panel.
- The site picker has an "All clients" choice that shows every site's work together, each card saying whose it is. It opens on the studio's own site the first time and remembers your last choice.

### Changed

- The Forge menu uses an anvil icon.
- A client with one site is named once in the site picker, not twice.
- Daily standup sections start folded, showing their counts; open the ones you want and they stay open for the visit.

## [2.100.1] - 2026-09-14

### Fixed

- An offboarded person who was added before they had a WordPress account can be brought back again. Saving them used to be refused as a duplicate whenever any WordPress account held their address.

### Added

- An offboarded person with nothing under their name can be deleted from Forge on the People screen. Their WordPress account stays.

## [2.100.0] - 2026-09-13

### Added

- A "Forge: Manager" role on the client site. A manager can use every Forge
  screen, the workspace page and raise requests, but cannot see or change the
  site's connection to the studio. Administrators keep everything they had.
  Add someone through Users → Add New and pick the role.

### Fixed

- Someone signed in to the client site without Forge access who opens the
  workspace page is now told what they need, instead of being shown the
  sign-in form again.

## [2.99.1] - 2026-09-13

### Changed

- The client site's Forge menu links to the workspace the way the studio's
  links to the board: first in the menu, marked as leaving the admin, and
  opening in a new tab.

### Fixed

- The client workspace page asks a site plugin that dresses pages in the
  site's header and footer (Clubhouse) to leave it alone. It was coming out
  wrapped in an unstyled header and footer.

## [2.99.0] - 2026-09-12

### Changed

- Capacity opens on the next fourteen days, today first, with a day per
  column. A Days / Weeks switch brings the eight-week view back.
## [2.98.0] - 2026-09-12

### Changed

- The local test sites sign in as admin / admin, matching the shared harness.
- The client calendar is drawn from the design system's month grid: today is
  marked, days outside the month sit back, and each date is a tagged entry
  whose kind (starts, due, review, release) carries its own colour. On a phone
  the month reads as a list of the days that have something on them.
## [2.97.3] - 2026-09-12

### Changed

- The sentence under each studio screen's title is gone; the title and its
  eyebrow say enough.
## [2.97.2] - 2026-09-12

### Fixed

- Tests no longer fail at sign-in when the test server is slow: WordPress
  focuses the username box a moment after the login page loads, and the
  password could land there. Every spec now signs in through one helper that
  waits for that first.

## [2.97.1] - 2026-09-12

### Changed

- The Board / List / Schedule / Calendar switch on the studio's work screen
  is drawn as one pill track, the way the design draws its toggles.

## [2.97.0] - 2026-09-12

### Changed

- The studio's New task button is on every screen: it takes you to the
  Kanban and opens the form there.

## [2.96.0] - 2026-09-12

### Added

- The client workspace's strip across the top now counts the checklist
  steps waiting on you, on every screen, with a link to what needs you.

## [2.95.0] - 2026-09-12

### Added

- The client's Your work board has a Schedule view: each planned item as a
  bar on a time axis, with anything not yet scheduled listed underneath
  rather than hidden.

## [2.94.0] - 2026-09-12

### Added

- My tasks: every task that names you, on every client, in one list with
  Today, Next seven days, Further out and Everything — the counts always
  add up. Open a row to work on it. That completes the studio restyle.

## [2.93.0] - 2026-09-12

### Added

- The studio's request queue has saved views (Awaiting review, Decided,
  All), row selection and a bulk bar: pick several requests and decline
  them with one reason, recorded on every one of them word for word.

## [2.92.0] - 2026-09-12

### Changed

- The studio's Standup, Capacity and Onboarding board are on the new look:
  standup sections as panels with one row per thing, the capacity grid and
  every table in a card with a proper header and hairlines. Nothing behaves
  differently.

## [2.91.0] - 2026-09-12

### Changed

- The studio's task record is on the new look: wider, the stage as a chip
  with the id and due date beside the title, each section as its own panel,
  the checks as rows with a glyph for where each stands, and the history as
  a changelog. Every action works as before.

## [2.90.0] - 2026-09-11

### Changed

- The studio's Kanban, Gantt and Calendar are on the new look: 320px
  columns headed by the stage on its own wash, cards carrying the id,
  priority, what they sit under, their three seats and when they are due,
  and bars and calendar entries painted by phase. Nothing moves differently.

## [2.89.0] - 2026-09-11

### Changed

- The studio app has the new shell: a rail down the left with every screen
  in groups — Kanban, Gantt and Calendar as three entries — a count of
  requests waiting, links to Packages & hours and Sync health in WordPress
  admin, and a top bar with what has happened lately and a New task button.
  Every screen works as before.

## [2.88.0] - 2026-09-11

### Added

- The workspace page's Launch readiness is live: the checklist in its
  sections, each step's status, whose it is, what is launch-critical and
  what the reviewer said. Fill a step in, attach a file and send it to the
  studio from the page. That completes the five client screens.

## [2.87.0] - 2026-09-11

### Added

- The workspace page's Support & hours is live: your plan and the hours
  left on it, the packages on offer, what has been bought, and who to ask
  for more. Every figure is the studio's own; nothing on the page sells.

## [2.86.0] - 2026-09-11

### Added

- The workspace page's Bugs & requests is live: raise a bug, request, idea
  or suggestion with a screenshot, and see everything your organisation has
  sent with the studio's reply and the work it became. Views by state,
  search, and a "check again" that reads the studio afresh.

## [2.85.0] - 2026-09-11

### Added

- The workspace page's Your work board is live: every item in the stage the
  studio has it in, plus a Key dates list. Open a card to read the
  conversation, answer a question, leave a comment or link to evidence.
  Dragging a card is refused in words — moving work stays the studio's.

## [2.84.0] - 2026-09-11

### Added

- The workspace page's Dashboard is live: the checklist steps waiting on you,
  where you stand on hours, your point of contact, work that is blocked or
  late, what is coming up, and how launch is going. The same information as
  the WordPress overview, in the new look.

## [2.83.0] - 2026-09-11

### Added

- The client site now takes the workspace page's writes as well: a comment
  or evidence on an item, a new bug or request, and an answer or a file on a
  launch checklist step. Each goes through exactly what the WordPress form
  for it already does, so nothing can be sent from one that cannot be sent
  from the other.

## [2.82.0] - 2026-09-11

### Added

- The client site now answers the workspace page's reads — the board, the
  hours and packages, the launch checklist and the submissions — with exactly
  what its WordPress screens show. Only a signed-in administrator can ask.

## [2.81.0] - 2026-09-11

### Added

- Clients get a Forge workspace page on their own site, reached from the
  Open workspace link in their WordPress menu. It shows the new look's shell —
  the nav, who is signed in, and sign out — with each screen saying it is on
  its way. The existing WordPress screens are unchanged and still do the work
  until those screens land.

## [2.80.0] - 2026-09-11

### Added

- The shared component kit for the new look: buttons, tags, stage chips,
  cards, the list view with saved views, search, filters and bulk actions,
  dialogs, fields, the hours meter, people and toasts. Every piece is shown on
  one page (the app page with `#kit`) so it can be seen and checked in one
  place. Nothing on the working screens changes yet.

## [2.79.0] - 2026-09-11

### Added

- The groundwork for the new look, shared by both plugins: the design kit's
  token names now resolve everywhere, the two typefaces ship with the plugin
  instead of loading from Google, and the Lucide icon set is approved for use.

## [2.78.0] - 2026-09-11

### Changed

- Everybody on People is now somebody who can sign in. Add a person by picking
  them from the site's WordPress users, or type a name and address and they get
  an account here as a Subscriber. No email is sent either way.
- A person's name and address stay the same in both places: change them on
  People and the WordPress account follows, change them on the WordPress
  profile and the person follows.
- Deleting somebody's WordPress user offboards them in Forge — their access to
  every client ends, and their history stays.
- Anybody added before this change still works and their card says they have no
  account, with both ways of giving them one offered there.

## [2.77.2] - 2026-09-09

### Fixed

- The studio's admin screens started a gutter in from the left instead of
  filling the page.
- Updates now sits at the bottom of the Forge menu, where housekeeping belongs.
- On Clients, a client's details, contact, sites and people are four separate
  sections instead of one run-on column, and each site is its own card rather
  than a line of text with buttons trailing off it.
- The Edit panels on Clients are the design system's accordion rather than the
  browser's own disclosure triangle.
- Buttons and dropdowns that belong to one action now sit in a row with one
  spacing between them, on Clients, Availability and Support.
- A client's people are a table, so the names, roles and statuses line up.
- Availability is one narrow column with a panel per question, so a two-word
  label no longer sits beside a foot of empty input, and the buttons have room.
- On Sales, the client and site share one column and the Open button no longer
  ends up outside the table.
- On Support packages, a package's state and its Retire button sit beside its
  name, its version history is its own panel, and Save and Save order sit on a
  bar of their own instead of against the line above them.
- The same button bar on Add a package, Add a standing meeting, and the three
  forms on Onboarding template.
- Primary buttons on every studio screen were being drawn as secondary ones.

## [2.77.1] - 2026-09-08

### Fixed

- On the Timeline, work with no dates on it was listed with the cards touching,
  so several separate jobs read as one block. They are now spaced apart.

## [2.77.0] - 2026-09-08

### Added

- You can attach a screenshot to a new request. It is saved to your own site's
  media library and we are sent the link, so you no longer have to find
  somewhere to put a picture before you can show us what is wrong.

### Changed

- Support Details is no longer a separate page. Where you stand, what you have
  bought and what more is available are now on the Overview, with everything
  else about your account.
- Cards on the Work Board say what kind of work each item is and how big a piece
  it is, instead of showing only a title. Work with no dates or people on it no
  longer draws an empty box under the title.
- On Requests, what you sent is shown under the questions you answered, and a
  reply from us is clearly marked as ours rather than reading as one more line
  of your own request. Where a request became a piece of work, that now sits at
  the foot of the card.
- Links in a request are clickable, including the screenshot you attached.
- Your site's address on the Overview is a link, and its status reads as a
  label rather than as a raw system word.
- The standing notes on New Request and Requests are proper banners, so they are
  read rather than skipped as small print.

## [2.76.1] - 2026-09-08

### Changed

- The row of tabs across the top of the Forge screens is gone. Every page is
  still there in the Forge menu down the side, which is where you were already
  looking.
- The Forge menu has been renamed and reordered: Overview, New Request,
  Requests, Work Board, Timeline, Calendar, Onboarding, Support Details,
  Connection. Nothing has moved or been removed, only renamed and put in a more
  sensible order.
- Save buttons now sit on the right of a form, clear of the line above them.

### Fixed

- "Getting you live" and "Your hours" were showing as plain, unstyled pages.
  They now look like the rest of Forge.
- Work titles on the board are no longer underlined until you hover over them.
- The dates along the top of the timeline were too faint to read. They are now
  set in a darker grey.

## [2.76.0] - 2026-09-08

### Changed

- The Forge screens on your site have a new look, matching the rest of the
  Blueworx admin. Nothing about your work, your data or your permissions has
  changed.

### Fixed

- The hours and packages screen no longer breaks on a site that has not been
  connected to a studio yet.

## [2.75.0] - 2026-09-03

### Added

- Work can now be planned on the screen it is opened in. Who is doing it, who
  is reviewing it, who is delivering it, the hours against each of them, the
  dates, the priority, who pays for it and what was released are all set in the
  item itself, beside everything else about it. Each field says which stage it
  is holding up, once that stage is the one the work is in.

### Fixed

- An item could not be moved past Triage without calling the API by hand. The
  fields every stage after it asks for existed in the record and had no control
  anywhere, so the panel would say what was missing and offer nowhere to put
  it.
- A refused save now says which field was refused and why, instead of only
  that it could not be saved.

## [2.74.0] - 2026-09-03

### Added

- A **Board** link near the top of the Forge menu, so the board is reachable
  from the admin instead of being somewhere you had to know the address of. It
  opens in a new tab, and carries the arrow that says so before you click it.

## [2.73.0] - 2026-09-03

### Fixed

- A site being offered the wrong plugin when it updated itself. One release
  carries both the studio plugin and the client one, and each site was taking
  whichever of the two happened to be listed first — so a client site could
  have installed the studio plugin over itself and been switched off for it.
  Each now asks for its own file by name, and offers nothing at all rather than
  falling back to something else.

### Added

- A short note on moving work across from the old Forge by hand: what order to
  set things up in, what to copy, and what deliberately does not come with it.

## [2.72.0] - 2026-09-03

### Added

- The last of the commercial promises in the brief is now proved by a test on
  every change: a standing meeting holds a client’s hours before it happens,
  takes them only when the meeting is actually held, and gives them straight
  back when it is called off or nobody comes. With it, every promise the brief
  makes is a test rather than an intention.

## [2.71.0] - 2026-09-03

### Added

- Six more numbers on the reports screen: how full people are, how often we
  went past our own rules, where clients hours went, which sites are still
  getting live, what became of what clients asked for, and whether our email
  arrived. Like the others, none of them is stored — each is the records
  counted when you ask, so it cannot drift from what it is counting.

## [2.70.0] - 2026-09-03

### Added

- Four of the five commercial promises in the brief are now proved by tests
  that run against a real studio and a real client site on every change: a
  package granting exactly the hours it says, hours set aside and spent
  without drift, a client with no package being refused chargeable work while
  still able to report a bug and reach us, and both sides showing the same
  balance. The fifth, about meeting hours, arrives with the meetings screen.

## [2.69.0] - 2026-09-03

### Added

- The hour record is now checked against itself, and a disagreement fails the
  build rather than waiting to be found in an invoice query. The balance the
  studio shows and the one the client shows are compared at every step of a
  piece of work, and the entries behind each job have to hold together on
  their own — hours released twice, charged twice, or quietly handed back are
  each caught by name.
- Every hour entry now says what it was against, so a line on the ledger reads
  as the piece of work it came from rather than as a bare number.

## [2.68.0] - 2026-09-03

### Added

- Clients can now see their own hours on their own site: where they stand, how
  many are left, what they have been given or bought, and what else is
  available. The figure is the studio's — read from the same place, not kept
  in step — so the two can never quietly disagree. Nothing on the screen
  charges anybody for anything; asking for more hours starts a conversation.
  A balance that has never been read says so, rather than showing nought and
  telling a client with forty hours that they have none.

## [2.67.0] - 2026-09-03

### Added

- Sell a client more hours, or correct their balance by hand. A correction
  will not go through without a reason, and the reason is shown on the same
  screen the client's balance is queried from — a number nobody can account
  for is the one thing the hour record cannot survive.
- A Sales screen listing every client who needs something doing about their
  package: never bought one, let one lapse, running out of hours, or running
  out of term. Four different conversations, kept apart, because they are four
  different jobs. A site drops off the list when it no longer needs anything.

## [2.66.0] - 2026-09-02

### Added

- A screen for a client's standing meetings: set one up, see the next twelve
  weeks, move a single meeting without disturbing the rest, and say whether
  each one was held, cancelled or missed. Hours settle as you look, so the
  balance on screen is the balance the client has.

### Fixed

- Ending a standing meeting now gives back the hours its future meetings were
  holding. They stayed committed, against meetings that were never going to
  happen.

## [2.65.0] - 2026-09-02

### Added

- Standing meetings now count against the time their host has available. A
  meeting never appears on a board and nobody moves it through a workflow, so
  it was the easiest commitment to lose — and losing it made weeks look freer
  than they were. Two hours a fortnight is a working day a quarter. Cancelled
  meetings and no-shows take nobody's time; a meeting that was held still did.

## [2.64.0] - 2026-09-02

### Added

- Meetings now draw on the same hours as everything else, and only when they
  actually happen. Hours are set aside for meetings in the next twelve weeks
  and spent when the host says the meeting was held. A meeting that was
  cancelled, that nobody came to, or that everyone simply forgot all leave the
  balance untouched — there is no late-cancellation charge and no no-show
  charge. Meetings further out are shown but not held, so a client's balance
  is not spoken for by things a year away.
## [2.63.0] - 2026-09-02

### Added

- A single meeting can now be moved, cancelled or marked as held without
  touching the arrangement it came from. Moving one meeting moves one meeting:
  not the rule, not the ones after it. A cancelled meeting stays on the list
  saying it was cancelled rather than vanishing, and everything done to a
  meeting is kept — who moved it, when, and from what — so a question about
  something three months ago has an answer.

## [2.62.0] - 2026-09-02

### Added

- The standing meetings a client's package includes can now be described: how
  often, at what time, for how long, in the client's own clock, and who hosts
  them. Weekly, fortnightly, four-weekly or monthly on the same date. The
  arrangement is what gets stored, so the dates come out of it rather than
  being written down once and going stale — and a ten o'clock meeting stays at
  ten o'clock when the clocks change, which is the bug this was built to avoid.

## [2.61.0] - 2026-09-02

### Added

- A client with no support package is now told so on their own screen, and
  told what they can still do: report anything broken, ask for something, and
  talk to their contact about buying a package. Nothing is quietly hidden —
  the studio decides what the position allows and sends it, so a client site
  cannot show one thing while the studio believes another. New chargeable work
  is refused by the service itself, not just left off a menu.
- Clients can now report that something is broken, in those words. The intake
  offered a request, an idea or a suggestion, so a broken site had to be filed
  as a request and queued behind things people merely wanted.

## [2.60.0] - 2026-09-02

### Added

- Whether a client has the hours is now asked separately from whether anybody
  has the time, and both answers come back together. Work is refused if the
  site is short — and told by how many hours — or if its package has lapsed or
  was never bought, even though a lapsed client's hours are still sitting
  there. Free bugs are exempt. Agreeing to over-book a week no longer quietly
  authorises spending a client's hours as well; they are two decisions and
  they stay two decisions.

## [2.59.0] - 2026-09-02

### Added

- Hours now move on their own. Planning a piece of work sets its hours aside,
  starting it spends them, and stopping it hands back whatever had not been
  spent — including the paths nobody thinks about, like work sent back down the
  board or cancelled while it was blocked. Changing the plan writes the
  difference rather than the new total, so the record still adds up. Free bugs
  never touch a client's hours, and work a site has not got the hours for does
  not move at all rather than moving and leaving the sums short.

## [2.58.0] - 2026-09-02

### Added

- A site's commercial position, and every hour behind it, on one screen. Put a
  site on a package, suspend it, put it back, cancel it — each written as a
  dated period, so what a client was entitled to on any past date is a matter
  of looking rather than working out. Assigning grants the hours through the
  hour record, which is now visible with the reason on every entry; and a
  part-year assignment grants the pro-rated figure rather than a full year.
  Suspending and cancelling leave the remaining hours alone: hours a client
  paid for are theirs, and writing them off is a separate decision with a
  reason on it.

## [2.57.0] - 2026-09-02

### Added

- Part-year package assignments work out to exactly the right hours. Real
  calendar days rather than whole months, so February is however long it
  actually was and a leap day counts; hours to the nearest half hour and price
  by the same share, both rounded once at the end. The figure somebody is shown
  before agreeing is the figure the hour record receives — one sum, done once,
  carried forward. Upgrading mid-term credits what is left of the outgoing
  package, worked out the same way the charge was.

## [2.56.0] - 2026-09-02

### Added

- One record every support hour passes through. A site's balance is the sum of
  its entries and nothing else — there is no stored total to drift, and no way
  to change an entry once it is written. A correction is another entry with a
  reason on it, so "what did this client's balance look like in March" has an
  answer. Reserving work and then starting it charges the hours once rather
  than twice, hours about to lapse are spent before hours that never will, and
  a balance cannot go below nought without somebody saying why.

## [2.55.1] - 2026-09-02

### Fixed

- The PHP coding standard is checked again. The shared build had been skipping
  it since this repo was set up, so the code had drifted out of line with the
  standard it says it follows; everything it flagged is now fixed or has a
  written reason beside it. No behaviour changed.

## [2.55.0] - 2026-09-01

### Added

- The support packages you sell, set up in one place, with a rule the screen
  makes plain: editing a package writes a new version and leaves every earlier
  one exactly as it was. A client stays on the terms they were given, whatever
  the price does afterwards. Every version a package has ever had is listed
  underneath it. A save that changes nothing writes nothing, and retiring a
  package stops it being sold without touching anybody already on it.

## [2.54.1] - 2026-09-01

### Fixed

- The Reports screen was reading each client's work separately, which is fine
  on a handful of clients and slow on a lot of them. It now reads everything at
  once — around ten times less work for the database, and it no longer gets
  dearer as clients are added.

### Added

- Every screen that spans clients is now measured against a budget on each pull
  request, with eight clients' worth of data behind it, so this kind of thing
  fails the build rather than being noticed by whoever has the most clients.

## [2.54.0] - 2026-09-01

### Changed

- Quiet text and status labels are darker, so they can actually be read. The
  greys used for secondary text failed the readable-contrast standard against
  our own backgrounds, as did the green, red and pink used on status chips. The
  colours themselves are unchanged where they sit on white; only the versions
  that sit on a tinted background moved.

### Added

- Every screen of both plugins is now checked for accessibility on every pull
  request — readable contrast, real form labels, heading order, names on
  controls — plus checks that the whole app can be driven from the keyboard and
  that a client can complete a checklist step without a mouse. A screen added
  without being added to the check fails the build.

## [2.53.0] - 2026-09-01

### Added

- A Reports screen that answers whether delivery is working: how long work
  takes start to finish, how long reviews take, how long things sit blocked,
  where work is piling up, how long each stage takes, how often we hit the date
  we promised, and what shipped each week. Pick any window up to a year.
- The numbers are counted from the record of what actually happened, every time
  you open the screen, so a figure can never quietly disagree with the work
  behind it. That also means the reports already cover months of history rather
  than starting from today.
- Where there is not enough work behind a figure to mean anything, it says so
  instead of showing a number. A window with nothing in it says that too,
  rather than drawing a screen of zeroes.

## [2.52.0] - 2026-09-01

### Added

- The last six promises on the list are now tested too, so every one of them
  that can be tested today is: work appears on the day's list when it needs
  attention and goes when it no longer does, a client's answer to an onboarding
  step comes back to them with our decision and our reason, a site cannot go
  live while a step it needs is outstanding, a client is emailed once however
  many times their site asks, the same piece of work reads the same on every
  view of it, and a site that has stopped talking to us is visible with what to
  try and clears itself when it is fixed. Only the commercial promises are
  left, and they are waiting on the work that builds them.

## [2.51.0] - 2026-09-01

### Added

- The promises Forge is supposed to keep are now written down as a list, and the
  build fails if one of them stops being tested. Twelve of them are covered by
  new tests that run against a real studio and a real client site together:
  work cannot skip a step it is not ready for, every move is recorded with who
  made it, only the named reviewer approves and only the named deliverer
  releases, a failed review comes back with its feedback, blocked work returns
  to exactly where it paused, a client's request reaches us once however many
  times it is sent, our edits reach only that client's site, nobody else can see
  or guess at their work, a client cannot move work by any route, somebody's
  time is counted once across every client they work for, and overbooking
  somebody costs a written reason. The commercial promises are on the list too,
  marked as waiting on the work that builds them.

## [2.50.0] - 2026-08-31

### Added

- Forge now tells you what has happened lately, next to the wordmark on every
  screen. A colleague moving your work on, sending it back, blocking it, going
  through a gate or committing somebody past their hours, and a client asking
  for something — with a count of what is new since you last looked. Your own
  doing is left out, because a list of things you did an hour ago is a list you
  stop reading. Nothing is filed against you: the list is worked out each time
  from records you may read right now, so losing access to a client takes its
  history off your list with it.

## [2.49.0] - 2026-08-31

### Added

- A screen that says which client sites have stopped talking to us. The ones
  needing somebody come first, each saying what is wrong, how long it has been
  wrong and what to try; every site is listed underneath, so an empty queue
  reads as "all of them are fine" rather than "the check is broken". A site
  that has gone quiet for days, or is sitting on email it has not collected,
  now counts as needing somebody — not just one that is actively failing. The
  day's list reads the same answer, so the two cannot disagree.

## [2.48.0] - 2026-08-31

### Added

- You can fix the thing from the day's list. A card about a piece of work opens
  that work, and a card saying something is outstanding lists what is
  outstanding with a way to record it there and then. Nothing anybody may not do
  becomes possible: the day's list asks the same question of the same place as
  every other screen, so a refusal here is the refusal you would get anywhere,
  in the same words.

## [2.47.1] - 2026-08-31

### Fixed

- The day's list is readable again. "Waiting on a requirement" was appearing
  against nearly every piece of work, because work that is not finished usually
  has something it has not done yet. It now only appears on work somebody has
  committed to, and never on work that is already on the list as blocked, with a
  reviewer, waiting to go out or handed back — those already say what is holding
  them up. Nothing that needs a person has stopped appearing.

## [2.47.0] - 2026-08-31

### Added

- An email that does not go is tried again after five minutes, then thirty,
  then two hours. If it still has not gone, it stops being the product’s
  problem and appears on the Today list for somebody to deal with — carrying
  what the mail server actually said, so it is something you can act on.
- Every attempt is written on the work item’s own history, so “did the client
  ever hear about this” is answerable while looking at the work.
- An email with nobody to send it to is recorded as such rather than counted
  as a failure. No amount of retrying fixes a client with nobody set up.

## [2.46.0] - 2026-08-31

### Added

- A Today screen: everything needing attention, in four sections — work, what
  is sitting on a person, what clients are waiting on us for, and the studio’s
  own problems.
- Cards can be hidden to get them out of the way, and hiding one never hides
  the work. The count beside each section is always the real one and says how
  many are hidden, nothing is recorded anywhere, and a reload brings them all
  back. A card goes for good only when the thing that put it there is dealt
  with.

## [2.45.0] - 2026-08-31

### Added

- The rules behind a daily list of what needs attention: work that is late,
  due today, blocked, stuck at a requirement, waiting to be reviewed or
  released, or sent back; client requests nobody has answered; onboarding
  steps waiting on us or overdue; anybody over their hours this week; and
  emails or client sites that need somebody to step in.
- Nothing is marked as seen and nothing can be dismissed. An item is on the
  list because something is true about it, and it leaves when that stops
  being true — so the list can never quietly hide work that is still
  outstanding.

## [2.44.0] - 2026-08-31

### Added

- Clients now get emails, sent from their own site using their own mail
  settings — so it arrives from a domain they recognise, and we hold no mail
  passwords at all.
- Three of them: we have your request, your work is ready to go live, and your
  work is live. Ready and live are deliberately different messages.
- A client who has asked for something hears that we have it.
- The request queue now shows what each client has been told, and how it went.

## [2.43.0] - 2026-08-31

### Added

- Groundwork for client emails: each thing worth telling a client about now
  gets one record, and gets it once. A sync arriving twice, a send being
  retried, or work reaching the same point again after a failed review all
  produce the same single record, so none of them can turn into a second
  email.
- Work reopened and released a second time is a second thing worth telling
  them about, and is recorded as one.
- A work item now reports what the client has been told about it, and how each
  of those ended up.

## [2.42.0] - 2026-08-31

### Added

- An Onboarding screen showing every client we are onboarding at once: how far
  through each one is, whether it can go live yet, and what is waiting on us.
- It filters by client, checklist, point of contact, owner, step status, and
  whether something is overdue, blocked or ready to launch. Filtering changes
  what is listed and never what is counted, so a client's progress reads the
  same whatever is switched on.
- Steps a client has sent us are approved or sent back from here. Those
  decisions already worked and had nowhere to be made from.

## [2.41.0] - 2026-08-30

### Added

- A site cannot go live until the things its checklist marks as needed before
  launch are done. The refusal names them, so nobody has to go looking for what
  is in the way.
- Once a site is live, nothing after that is held up by its checklist. An
  unticked box should never stop a bug fix months later.

## [2.40.0] - 2026-08-30

### Added

- We can now approve a step a client has sent us, send it back saying what needs
  changing, or mark it as not applying to them. Sending one back without saying
  why is refused — the client sees whatever we write, and "have another look" on
  its own tells them nothing.
- Marking a step as not applicable needs a reason too, and only works where the
  checklist allows that step to be waived at all.

### Changed

- A returned step shows what we asked for on the client's own page, and stops
  showing it once the step moves on. Nobody sees last month's comment against
  work that has since been approved.

## [2.39.0] - 2026-08-30

### Added

- Clients now have a "Getting you live" page on their own site. It opens with
  the one thing waiting on them, groups the rest into when each happens, and
  lets them write what they did and attach something showing it.
- A step we send back shows what we asked to be changed, on their page, so
  nobody has to email to ask what we meant.

### Changed

- A client's page shows our steps as well as theirs, as text rather than
  something to fill in. Somebody waiting to go live wants to see that we are
  getting on with our half.

## [2.38.0] - 2026-08-30

### Added

- Clients can attach evidence to an onboarding step — a PDF, an image, or a text
  or CSV file. Files are kept where nobody outside that client can reach them,
  and they are never served from a web address anybody could guess.

### Security

- Anything that looks like a password, an access key or a card number is now
  refused when somebody tries to put it on an onboarding step, whichever side
  they are typing on. They are told to invite our account instead, which is how
  access was always meant to be handed over.
- Only a short list of file types is accepted, and a file has to be the kind of
  file it says it is. There is no promise of virus scanning: a plugin cannot
  keep that promise on ordinary hosting, so a host that has its own scanner can
  hook one in, and what we actually guarantee is not accepting the file at all.

## [2.37.1] - 2026-08-30

### Added

- A way to actually start a client's onboarding, from the clients screen. The
  checklist machinery shipped last release without anything to trigger it, so
  none of it was reachable. It is offered once per site and then shows how far
  along they are.

## [2.37.0] - 2026-08-30

### Added

- How far through a client's onboarding is, worked out from their own steps
  rather than typed in by anybody. There is no figure to edit, so it can never
  drift from the checklist it describes.
- Whether a site is ready to go live is asked separately from how far through
  it is. A client at 95% with their domain still unapproved is not nearly
  ready, and one number would say otherwise in exactly the case where being
  wrong costs the most. Steps that are in the way are named, not counted.

## [2.36.0] - 2026-08-29

### Added

- A launch checklist, written in one place and versioned. Correcting it is an
  edit rather than a release, so a checklist that turns out to be wrong gets
  fixed the same day.
- Once a version is issued it never changes again. A client working through
  theirs is never rewritten underneath them, and changing the checklist means
  opening a copy and issuing that as the next version.
- Giving a client their checklist fixes it there and then. What they see is
  what they were handed on the day, whatever the template does afterwards.
- Every change to a step records who made it and when, permanently, and a
  change with nobody's name on it is refused rather than stored.
- The checklist has nowhere to put a password or a key. Access is granted by
  inviting us to a provider, and what gets recorded is which account, what was
  asked for, and whether it was verified.

### Note

The starter checklist is not finished. Five of its twelve categories are
written — the ones that gate a launch — and it will not be issued to anybody
until the rest are supplied. Everything above works; only the starting content
is outstanding.

## [2.35.0] - 2026-08-29

### Added

- Work can no longer start when there is no room for it. Moving something into
  development now checks whether the people in its seats actually have the
  hours, week by week, and says who does not and which week — alongside
  everything else the move is still missing, so a plan is fixed in one pass
  rather than refused six times over.
- Over-booking somebody is still allowed. It is a call a manager makes on
  better information than a spreadsheet has, so it is not blocked — it costs a
  reason, it is limited to studio administrators, and the work says afterwards
  that it happened and why.
- The reason covers that one move and no more. Work sitting in the queue for a
  fortnight is weighed again when it finally starts, because by then the weeks
  have moved.
- Changing somebody's hours, booking their leave or cancelling it now leaves a
  note on every piece of live work it affects, so a week that has turned red
  can be traced to whatever turned it.

## [2.34.0] - 2026-08-28

### Added

- A Capacity screen, beside Work and Requests. People down the side, weeks
  across the top, and how much of each week is already spoken for. Every figure
  opens to the work behind it, so a number can always be taken apart.
- Somebody working for two clients now shows one combined commitment. Nobody
  can look free on one client while they are committed on another.
- Committed time is spread across the days somebody actually works, so a job
  running over a month reads as a month of steady load rather than a wall in
  week one, and a week of leave carries none of it.
- The reviewer's and deliverer's hours are filled in from the estimate when
  work is planned, and stay editable. A figure somebody has set is never
  overwritten, including a deliberate zero.
- A client site can now ask whether there is room. It gets an answer and, where
  there is none yet, the earliest date we expect some — and nothing at all
  about anybody else's work. The answer is the same whichever client asks.
- The client's own Ask screen shows that answer above the form, so somebody
  knows what is possible before they write, and is always told to ask anyway.

### Fixed

- Planned hours could not be saved. They were being checked against the rule
  that says a seat holds a person, so any figure was refused as "not a person",
  which meant no work could carry an estimate at all.

## [2.33.0] - 2026-08-27

### Added

- A new Availability screen under Forge, where each person's working week is
  set and their time off is recorded. This is the first half of capacity: what
  somebody's time actually is, before anything is committed against it.
- Hours are set from a date and leave everything before that date alone, so
  moving somebody to four days in March does not quietly rewrite what February
  was. Correcting a mistake records the correction rather than erasing what was
  believed at the time, and both are shown.
- Leave, public holidays and training take whole days out, counting the last
  day as time off — "away from the 3rd to the 7th" is five days. Two records
  covering the same day take it out once.
- A person nobody has set up says so, rather than showing no available time.
  Those are different things and only one of them needs acting on. The same
  goes for the days behind the total: a Saturday reads as a non-working day,
  not as leave.

## [2.32.1] - 2026-08-27

### Fixed

- The two-site test suite runs again from the two commands the project
  documents. It needed three settings that only the build server was passing,
  so on anyone's own machine every test failed before it did anything — which
  read as a run that started and then died partway through. The suite now
  defaults them from the same place it builds the sites.
- Stopping the two test sites now stops the right ones. It was reconciling
  against a port neither of them uses, so a server left over from an
  interrupted run survived being told to stop, and another project's test site
  was liable to be shut down instead.
- A test in that suite is no longer cut off while it is still working. The
  limit was close enough to how long these tests genuinely take that it was
  ending healthy runs, and which test it landed on varied.

### Added

- `npm run wp:pair:reset` rebuilds both test sites from empty databases. They
  are reused between runs on purpose, but what builds up in them makes runs
  slower over time, and there was no way back short of deleting directories by
  hand.

## [2.32.0] - 2026-08-27

### Added

- A site can now be given its update token in the dashboard instead of by
  editing `wp-config.php` on the server. The studio's is on a new Forge →
  Updates screen; a client site's is on the connection screen it already has.
  Setting it in `wp-config.php` still wins, and where that is done the field
  says so rather than quietly overriding it.
- Both screens now state whether updates can actually be fetched, and name the
  latest release when they can. A site without a token used to be
  indistinguishable from a site that was up to date — the releases are in a
  private repository, so it was told there was nothing to see rather than that
  it had been refused, and could sit months behind with nothing to notice. A
  token that has expired, or that was scoped to the wrong repository, now reads
  as a refusal instead of as silence.

## [2.31.0] - 2026-08-27

### Fixed

- A client site whose site record or client is closed can no longer read or
  write anything. Revoking its key already stopped it; ending the arrangement
  did not, because nothing on that path had ever read the status. What the site
  did while it was active is untouched and still attributed to it.

### Added

- The twenty-seven things a client must be refused are now each proved against
  the real client plugin on its own WordPress, rather than against a role set up
  on ours. Everything from reading another client's work to moving a card, and
  every route each could be tried through.
- The list of what must be refused now says which milestone proves each one, and
  a check fails the build if something this milestone owes has no test. A denial
  nobody has written a test for is now a failing count rather than nothing at
  all.

## [2.30.0] - 2026-08-26

### Changed

- Every client screen now says which kind of nothing it is showing. Not
  connected yet, cannot reach the studio, and turned away by the studio are
  three different problems with three different things to do about them, and
  they used to read as one.
- A screen with nothing to show because the site is not connected now says where
  to connect it, rather than leaving somebody looking at a blank section.
- The "ask for something" form is no longer drawn on a site with nowhere to send
  it. It used to accept three paragraphs and refuse them on send.
- "Check again" no longer appears where asking again gets the same answer.
- A refusal made to a client site is now recorded in the studio's security log —
  with the site, the item and the time — and a site asking for work that is not
  theirs is logged separately from one asking for work that does not exist, even
  though both are answered identically.

## [2.29.0] - 2026-08-26

### Added

- Clients can now open a piece of their work from their own board and say
  something about it — a comment, or a link to a page, a screenshot or an error
  message that helps.
- You can ask a client a question against a piece of work. It shows at the top
  of their screen as waiting for an answer, and stops asking once they give one.
- None of it moves anything. What a client adds reaches you without changing
  where the work sits, and there is no control on their site that could.
- A client never sees an internal note, and never sees another client's work —
  an id that is not theirs reads exactly like one that does not exist.

### Changed

- When the studio turns a client site away — a revoked key, or a record that is
  not theirs — their site now says so instead of reporting a connection problem,
  and stops showing the copy it had. Being refused and being offline were
  reading as the same thing.

## [2.28.0] - 2026-08-26

### Added

- A request you have accepted can now be turned into real work from the Requests
  screen, without retyping it into the board.
- Choose what it hangs under, or create a new parent on the way. Work that
  answers a request somebody has already started can be linked to it instead of
  making a second card for the same job.
- New work starts at Future Idea, or goes straight into Triage — where the three
  things the conversion has already decided are recorded against you, so nothing
  is waved through.
- What the client wrote stays exactly as they wrote it. The card can be called
  something else without touching their words, and they see what their request
  became on their own site.
- Work can only ever land in the pipeline of the client who asked. There is no
  way to point a conversion at another client's site, and anything belonging to
  one is answered as though it does not exist.

## [2.27.0] - 2026-08-26

### Added

- A Requests screen in Forge, listing everything every client has asked for in
  one place, so nothing sits waiting on somebody remembering to look.
- Each request opens to show exactly what the client sent, with two things you
  can do about it: say where it has got to, and write the reply they read back
  on their own site.
- Filter the queue by client, status, kind of request, or by searching what was
  written.
- Somebody who only works with certain clients sees only those clients'
  requests. Seeing all of them takes the cross-client access.

### Changed

- Forge now has two screens rather than four views of one. Work is the board,
  list, schedule and calendar as before; Requests is the new queue. The site
  picker and the work filters only appear where they mean something.
- A client's reply on their "What you asked for" page is now written by the
  studio rather than standing in as test data.

## [2.26.0] - 2026-08-23

### Added

- Clients can now see what happened to everything they have asked for, on a new
  "What you asked for" page on their own site.
- Each request shows the words it was sent in, how far it has got, the studio's
  reply once there is one, and a link through to the work it became.
- A client who has asked for nothing is told so, and shown where to ask. A site
  that cannot reach the studio says that instead, rather than showing an empty
  list that reads as though the requests had vanished.

## [2.25.0] - 2026-08-22

### Added

- Clients can now ask for things from their own site: a request, an idea or a
  suggestion, with what they want, what good would look like, and anything that
  helps.
- This works whether or not the client has a support package. Having one
  affects how quickly work can be scheduled, not whether they can ask.
- What a client sends is kept exactly as they wrote it and can never be edited
  afterwards — by them or by us. Changing your mind means sending another one,
  and both stay visible.
- A send that fails leaves the words in the boxes and says what went wrong, so
  nobody retypes three paragraphs because a connection dropped.

## [2.24.0] - 2026-08-22

### Added

- The client's first screen is now a landing view rather than a connection
  record: who their contact is, what needs attention, what is coming up, and
  where their support stands.
- Anything blocked or past its date is listed first, with the reason in words
  rather than left for the reader to work out by comparing dates.

### Changed

- A brand-new client with no work now reads as new rather than broken. Every
  section says which kind of empty it is — nobody assigned yet, nothing
  scheduled yet, no support package yet — instead of leaving a heading over a
  blank space.
- The connection details moved to the bottom of that screen. They matter to
  whoever set the site up and to nobody afterwards.

## [2.23.0] - 2026-08-22

### Added

- A client site now shows the work itself: a board, a timeline and a calendar,
  all read-only. It is the same work the studio sees, in the same stages, with
  the same dates.
- Work with no dates on it yet is listed on the timeline and the calendar
  rather than left off them, so a client is never shown a schedule that quietly
  omits half of what is going on.
- A card names who is working on an item, who is reviewing it and who is
  releasing it. It does not carry planned hours, priority or commercial class —
  each of those is a conversation to have with a client rather than something
  to spring on them from a screen.

### Changed

- When the studio cannot be reached, the client's views say so instead of
  drawing empty columns. Empty columns would tell a client their work had been
  deleted.

## [2.22.0] - 2026-08-21

### Added

- The plugin on a client site now has a frame of its own: it says whose
  workspace it is, and carries the navigation for the pages inside it. Somebody
  looking after several client sites can tell which one they are on before they
  act on it.
- Nothing in that frame can be pointed at another client. The links carry a
  page and nothing else, and a request that names somebody else is answered for
  the site that signed it — so a hand-edited address changes nothing.

## [2.21.0] - 2026-08-21

### Added

- A calendar, in month, week and day. Work appears on every date it carries —
  when it starts, when it is due, when it is meant to be reviewed and when it
  is meant to ship — and each entry says which of those it is, so a release day
  is visible as a release day rather than buried in an item.
- A busy day says how much it is not showing instead of quietly cutting the
  list short, and opens in full when asked.
- Work with no dates does not appear, which is the honest answer for a
  calendar. The schedule is where that work stays visible.

## [2.20.0] - 2026-08-21

### Added

- A third way of looking at the work, beside the board and the list: the
  schedule. It draws when each piece of work starts and is due, how far a
  parent has got, and what is already past its date.
- Work nobody has given dates to is kept in a tray under the schedule rather
  than dropped off it. It is open by default and says how much it holds even
  when closed, so unplanned work is visible instead of invisible.
- A parent with no dates of its own is drawn from the dates of the work
  beneath it, as an outline rather than a solid bar — so it is clear those
  dates were derived and not promised by anybody.
- Work that is waiting on other work is marked as waiting, and picking a bar
  shows what is immediately either side of it in the sequence.

## [2.19.1] - 2026-08-21

### Fixed

- An item history is shown in the order things actually happened. Anything
  recorded in the same second — the four entries one edit across four fields
  writes, or a move and the return that follows it — could previously come back
  in any order, so a history read as though events happened in an order they did
  not. Comments and the record of who did what at each gate were affected the
  same way.

## [2.19.0] - 2026-08-21

### Added

- A list view of the same work, alongside the board. It shows the columns a
  card cannot fit: when work starts, when it is due, and how far a parent's
  children have got.
- One filter bar above both views. Switching between them keeps what you were
  looking at, and the two can never show different totals for the same filters
  because they are two renderings of one answer.
- Saved views. Name the way you like to look at things and come back to it. A
  saved view can only change what is shown — never what you are allowed to do —
  and yours are not visible to anyone else.

## [2.18.0] - 2026-08-20

### Added

- Every change to a work item is now remembered as what changed, what it was
  before, what it became, who did it and from which side. One entry per field,
  so "when did the due date move" is a question with an answer.
- Work can be marked as waiting on other work. A dependency that nobody has
  scheduled, or that is itself blocked, is said out loud rather than left in a
  list of things you are waiting for.
- A parent now reads as what the work beneath it actually is — how far along,
  when it starts, when it is due — and none of it can be typed in by hand.
  A parent with nothing beneath it says "empty" rather than pretending to be
  work nobody has started.
- Planned hours for each of the three people named on a piece of work.

### Fixed

- Work that was cancelled no longer holds its parent open forever.
- Marking work as a duplicate of something on another client's site is
  refused, as is marking it a duplicate of itself.

## [2.17.0] - 2026-08-20

### Added

- People now see only the clients and sites they actually work with. Somebody
  brought in for one site of a two-site client gets that site and not its
  neighbour.
- Anything belonging to a client you have nothing to do with answers as though
  it does not exist, word for word — so nobody can work out which records are
  real by comparing refusals.
- Studio people who genuinely work across every client can be given that reach
  explicitly. Without it they are scoped exactly like a client's own people.
- The Principal and Approver authorities can be handed out on the people
  screen. Until now the column existed and nothing wrote to it, which meant
  nobody could approve their own work even when they should have been able to.
- Every client has a named point of contact, kept as history rather than
  overwritten. A contact who leaves is flagged for reassignment instead of
  quietly staying in place, and the client site shows their name and nothing
  else about them.

### Changed

- The board and the site picker no longer need a WordPress administrator
  account. A staff member signs in and sees their own work.

### Fixed

- A route that forgets to scope itself to a client can no longer be written: it
  refuses to register at all, so the mistake is a failed build rather than
  somebody else's data.

## [2.16.0] - 2026-08-20

### Added

- Who may do what is now written down and enforced, for every role, on both the
  studio and a client site. A refusal says which rule stopped it rather than
  only that something did.
- Work names the person doing it, the person checking it and the person
  shipping it. Only the named reviewer approves a review and only the named
  deliverer confirms a release — being an administrator is not a substitute for
  being the person.
- A stand-in can be named for a reviewer or a deliverer who is away, by you and
  nobody else, and anything they approve is recorded as having been done by a
  stand-in.
- Finished work can be picked up again. It starts a fresh round and keeps the
  record of having been finished the first time.
- You can move any item to any stage with a reason. The item says so
  permanently afterwards, and it still cannot be used to move a client's work
  or to put work in a stage its type has no business being in.

### Changed

- Client accounts are refused every way of moving work — nine routes, all
  refused, with the item untouched.
- Staff who are not WordPress administrators can now do their job. The screens
  that move work check what someone may do rather than whether they administer
  the site; reads stay administrator-only until they are properly scoped.

## [2.15.0] - 2026-08-20

### Added

- Every stage now has a written list of what must be true before work leaves it.
  Work does not move until those things are done.
- A refused move tells you everything that is missing, not the first thing, and
  the item stays exactly where it was.
- The item panel shows what a stage is waiting on before you try to move it, and
  each thing you tick off records who did it and when.
- Work can be sent back to a stage it has actually been in, and never without a
  reason. A failed review also has to say what was wrong, and keeps the earlier
  review attempt.
- Work can be blocked from wherever it is, with a reason, an owner, what it is
  waiting on, a target date and a next action. Resolving it puts the work back
  exactly where it was and remembers how long it waited.
- Work can be rejected, marked a duplicate, cancelled or deferred, and archived
  once it has ended. Ended work stops moving; archived work leaves the board and
  stays in the reports.
- Comments and evidence on a work item, with internal notes kept in a separate
  scope from anything a client can read.

### Changed

- Bug Tracking is now closed to anything that is not a bug by every route, not
  only the forward one.
- Loading, empty, broken and no-access each say which they are instead of
  showing a blank board.

## [2.14.0] - 2026-08-20

### Added

- The board. Every piece of work on a site, in a column for the stage it is at,
  grouped under whether it is still being captured, waiting on an approval, in
  delivery, or finished.
- Drag a card to move the work. A move the workflow does not allow puts the card
  straight back and says why, rather than leaving the board showing something
  that never happened.
- Click a card to open it: the same moves as buttons, the fields to fill in, and
  everything that has happened to it so far.
- Work can be added from the board.

## [2.13.0] - 2026-08-20

### Added

- Work is now a record: projects, milestones, features and sub-features, plus
  bugs, feedback and tasks that can hang anywhere or nowhere. A level can be
  skipped, and work can only ever belong to one site.
- The twelve stages, fixed. They cannot be renamed, reordered or added to,
  because everything else — the gates, the board, the reports — is written
  against them.
- Work moves one stage at a time, through one route that records every move and
  who made it. A move that fails leaves the item exactly as it was.

### Changed

- Settled where each screen lives: the ones you work in are the app, the ones
  that configure Forge stay in the WordPress admin. Nothing visible changes
  today — it stops the same screen being built twice.

## [2.12.0] - 2026-08-20

### Added

- People are now records of their own, on a new Forge → People screen. One
  person has one account however many clients they work with, so somebody can be
  staff on one client and a viewer on another without being two people — and
  offboarding them ends their access to every client in one action, keeping
  everything they ever did.
- Access to a client is given and ended from that client's row on Forge →
  Clients, either across the whole client or on one of its sites.
- A client that lists permitted email domains now has that enforced: its own
  people must use an address at one of them.

### Fixed

- The Clients screen was serving a very large page once a studio had a lot of
  clients, and became slow to use. It now loads in a fraction of the time.

## [2.11.0] - 2026-08-19

### Added

- Forge now shows whether each client site is actually connected, when it last
  called, and whether that site can send email. A site nobody has connected, one
  that has simply gone quiet, and one that has stopped working now read
  differently, so a quiet site is not mistaken for a broken one.
- Connection keys are issued, rotated and revoked from Forge → Clients, against
  the site they belong to. A new key is shown once, on the screen that issues it.

## [2.10.0] - 2026-08-19

### Added

- Clients and their sites are now records in their own right. A client can have
  more than one site, and each site is its own workspace — work, hours and
  onboarding will belong to the site rather than to the client. Add and manage
  them from Forge → Clients. Nothing is ever deleted: a client or site you
  finish with is made inactive and kept.

## [2.9.3] - 2026-08-19

### Changed

- The foundation decisions are signed off. Two changed in review: moving a job
  outside the normal rules, and booking someone past their capacity, can both be
  done by any administrator rather than by one named person.

## [2.9.2] - 2026-08-19

### Fixed

- The refused requests list no longer fills with replays that never happened.
  Every successful request from a client site was logging one, because
  WordPress asks a route's permission question twice and a signed request can
  only answer it once. A log of false alarms hides the real ones.

## [2.9.1] - 2026-08-19

### Changed

- The two plugins are now named "BlueWorx Labs | Forge Parent Site" and
  "BlueWorx Labs | Forge Client Site" in the WordPress plugins list, so it is
  obvious which is which on a site running one of them.

## [2.9.0] - 2026-08-19

### Added

- A Connection screen on the client plugin: paste in the site id and key the
  studio issued, see whether the studio accepts them, and disconnect. Setting a
  client site up no longer needs anyone to edit a file or call an API.
- Credentials set in wp-config.php are shown as such and left alone, so the
  safer way of storing them still works.

## [2.8.1] - 2026-08-19

### Fixed

- The studio plugin no longer ships a test configuration file to live sites.
  It reached the 2.8.0 release because the two workflows that decide what ships
  had drifted apart; a check now fails if they ever disagree again.

## [2.8.0] - 2026-08-18

### Added

- A Forge screen in the studio's dashboard for connecting a client site. It
  registers the site, shows its key once, issues a replacement key, and cuts a
  site off. Doing any of that previously meant hand-crafting an API call.
- The same screen lists recently refused requests, which is where you look when
  a client site says it cannot connect.

## [2.7.0] - 2026-08-18

### Fixed

- Forge's own screen now looks the same whatever theme a site uses. It was
  quietly picking up the theme's colours, fonts and spacing, which would have
  meant the app looking different on every site it was installed on.
- Forge no longer changes anything else on a site. Its styling loads on its own
  screens and nowhere else, and the admin bar no longer sits over the app.

## [2.6.0] - 2026-08-18

### Added

- Both interfaces now take their colours, type and spacing from one file. Change
  a colour once and it changes in the studio and on every client site — there is
  no second copy to keep in step, and a check fails the build if one ever
  appears.

### Changed

- The design system's token files moved out of the design intake folder, which
  never ships, to the top of the repository, which does. Re-imports from the
  design tool still arrive as a pull request rather than changing the product
  underneath it.

## [2.5.0] - 2026-08-18

### Added

- A client site now shows the details the studio holds for it, read from the
  studio each time rather than kept on the client's own site. Change them in
  one place and that is the only place they live.
- Every screen says when it last heard from the studio. If the studio cannot be
  reached, the site keeps showing what it last saw and says plainly that it is
  doing so — rather than an error page, or worse, an old page that looks
  current.
- A site that has been cut off says so rather than sitting there looking
  connected.
- A "check again" link, for when you have just fixed something and do not want
  to wait for the next refresh.

## [2.4.0] - 2026-08-18

### Added

- A client site can now prove which client it is. You register the site from
  the studio, which hands you a key once; the site uses it to sign every
  request it makes. Nothing else is accepted — a site you have not registered
  cannot connect, however it asks.
- You can cut a site off, from the studio, without touching the site itself.
  Revoking stops it immediately even though it still holds its key, which is
  the point: a site you want disconnected is not going to help you do it.
- You can also issue a site a fresh key, which stops the old one working the
  moment you do.
- Every refused attempt is recorded — which site it claimed to be, why it was
  turned away, and when — so a key being tried repeatedly is something you can
  see rather than something you find out about later.

## [2.3.0] - 2026-08-18

### Added

- One command now sets up both sides of Forge at once — the studio and a client
  site — as two separate, throwaway WordPress installs. Everything that follows
  needs the two talking to each other, and until now there was only ever one to
  test against.
- The checks on every change now prove the client site really is a client site:
  it has no command-centre code on it and cannot answer for one. That was
  previously only checked inside the zip file; it is now checked on a running
  site.

## [2.2.0] - 2026-08-17

### Added

- Forge now ships as two separate plugins: the one you run, and a smaller one
  that goes on a client's own site. A client's WordPress cannot contain the
  command-centre code at all — not "is set up not to show it", but the files
  are not there. The build refuses to produce a client plugin that has any of
  it, and that refusal is checked on every change.
- The client plugin installs, activates and updates itself independently. It
  does nothing yet; the client workspace follows once a site can prove which
  client it is.

## [2.1.0] - 2026-08-17

### Added

- The rules every part of the API follows from here on. Two of them you will
  notice: if somebody else changed an item since you opened it, your save is
  refused and you are shown what changed, instead of one of you quietly
  overwriting the other. And if a save is sent twice — a slow connection, a
  second click — it still only happens once.
- When an item cannot move to the next stage, the answer now lists everything
  that is missing, not just the first thing found. No more fixing one item,
  resubmitting, and being told about the next.

## [2.0.1] - 2026-08-17

### Fixed

- The server-side test suite can no longer report success without having run
  anything. A build where the tests fail to run at all now fails, instead of
  passing quietly — which is the failure that hides broken code for months.

## [2.0.0] - 2026-08-17

### Changed

- Forge has been rebuilt from the ground up and is now a separate plugin,
  **Blueworx Forge**. It installs alongside the old Forge Project Management
  rather than replacing it, so both can run on the same site while you move
  items across by hand. The old plugin is untouched and stays installable from
  its 1.37.2 release.
- This release is the new plugin's foundation: it installs, activates and
  updates itself, and nothing more. The screens follow, built from the new
  design.

## [1.39.0] - 2026-08-17

### Added

- The decision record: the forty-seven product and architecture questions the
  platform rebuild depends on, each with the question, what else was considered,
  the approved answer, and what would have to be rebuilt if it ever changes.
- A check that the record stays complete. Adding a new question to the list now
  fails the build until somebody answers it, so the rebuild cannot quietly start
  on a guess.
- The three documents the rebuild is built from: who is allowed to do what on
  each of the two sites, how a piece of work moves from idea to released and what
  it has to satisfy at each step, and what the system actually stores. Between
  them they say what gets built and, just as importantly, what must always be
  refused.

## [1.37.2] - 2026-08-17

### Changed

- The local test WordPress now runs on a port of its own, so it can no longer
  collide with another project's test site — which made every test fail at once
  while the site itself looked perfectly healthy.
- The seven long-standing linter warnings about the app's forms and search box
  are gone. Each was the linter objecting to a pattern that is correct here —
  a form field seeded from saved settings, a search box that has to follow a
  shared link — so each now says why it is deliberate. Nothing about the app
  behaves differently, and the linter is silent again, which means the next real
  warning will be noticed.

## [1.37.1] - 2026-08-17

### Fixed

- Four places on the Status screen printed diagnostic values without escaping
  them first, and the "Copy Status Report" button embedded the report in a way
  that could cut itself off mid-report. Both are fixed, and a test now proves the
  copied report is complete and the screen renders.

### Changed

- All of the plugin's PHP now follows the WordPress coding standards, checked on
  every pull request. Formatting only — nothing about how the plugin behaves
  changed, and the full test suite passes unchanged.

## [1.37.0] - 2026-08-17

### Added

- The plugin now updates itself on live sites. Once a release is published,
  WordPress offers it like any other plugin update — no more uploading a zip by
  hand. Each site needs a one-off access token in its `wp-config.php`.
- Automatic checks now run on every pull request: the code is linted and built,
  the version and changelog must be updated, no dependency can be added without
  being approved first, and a real WordPress site is created from scratch to test
  against.
- Tests that prove the plugin actually works after install — it activates
  cleanly, every item screen in the admin opens, the app page loads and the app
  itself starts up, and the public data endpoint answers.
- Uninstalling now clears the plugin's own settings, cached data and roles.
  Features, releases, bugs, feedback and the app page are deliberately left
  alone: they are the site's content, so a reinstall picks them straight back up.

### Changed

- Zips are now built by a script that lists exactly which files may be included
  and then checks the finished file before handing it over, so development files
  cannot reach a live site. `npm run zip` still works and does this.
