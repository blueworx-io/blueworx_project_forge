# One Client picker for the whole app — design

Issue #402. Luke, 2026-09-26: "only one Client dropdown, it lives in the top bar and loads for the whole site, so I don't have to keep re-selecting All, clients or the studio." Design approved in chat the same day, including leaving Capacity whole.

Studio only; the client plugin is untouched. Version 2.138.0.

## The picker

- One "Client" dropdown in the top bar (`fs-topbar` in `src/App.tsx`), beside New task. Options: All clients, then the sites the person reaches, labelled with `siteLabel` (the studio's own site among them).
- The choice lives in `App` and reaches screens through a small React context (`src/ClientChoice.tsx`: provider, `useClientChoice()` → `{ siteId, sites, setSiteId }`). It is remembered in the existing `bwx-forge-site` browser key, so a reload keeps it. The first time (nothing remembered) it opens on All clients.
- A link that names a site (`&site=`) opens on that site and sets the top bar to it.
- The pickers inside Work (`bwx-site`), Meetings (`bwx-meetings-site`) and Support (`bwx-support-site`) are removed. `SitePicker.tsx` is deleted if nothing else uses it.
- The picker's test id is `bwx-client-choice`. Specs that used the old per-screen pickers change to it.

## What follows it

"All" means what the screen shows today. A single site narrows the screen to that site.

- **Work** (board, list, calendar, schedule): as today — `/work-items?client_site_id=` or `/work-items-all`. New work opens on the picked site; with All it asks which client, as the form does now.
- **Calendar diary**: `/calendar` entries gain `site_id` — the site of the chore, reminder, meeting or subscription, and `''` for things that belong to no client (company dates, leave, birthdays). With a single site picked, entries show if their `site_id` is that site or `''`.
- **Meetings**: as today, including All clients.
- **Support**: one site is as today. All clients shows a new table, one row per site the person reaches: client, package, hours this period, used, left, and status. A row opens that site (sets the top bar). New route `GET /support-summary`, administrator-only like the rest of Support, reach-filtered, read in one batched query rather than one per site.
- **My Tasks**: filtered in the browser by `client_site_id` (rows carry it). The Today's diary on My Tasks follows the same rule as the calendar.
- **Standup**: filtered in the browser by each row's `site_id`. Rows without a site (company-wide) always show.
- **Recurring tasks, Reminders, Requests (Queue)**: filtered in the browser by `client_site_id`.
- **Reports**: `/reports` accepts an optional `client_site_id` that narrows the rows before they are added up. The site must be one the person reaches, or the answer is 403.
- **Capacity**: unchanged. It shows everyone's whole load whatever is picked, and says "Capacity counts all clients." near the top. A person's load across every client is what the screen is for.
- **Clients, People, Packages, Availability, Settings, Profile**: unaffected.

## Forms

The client fields in forms stay, because they say which client a thing belongs to: new work, recurring tasks, reminders, the task panel's client, people memberships. When a single site is picked, a new recurring task, reminder or piece of work defaults to it.

## Empty and edge states

- A screen narrowed to a client with nothing to show says so: "Nothing for <client> here." — not the all-clients empty text.
- If the remembered site is no longer reachable (access removed, site deleted), the picker falls back to All clients.
- A person who reaches only one site sees the picker with All and that site, as today's pickers do.

## Tests

- e2e `global-client-picker.spec.js`: pick a client in the top bar; the board, My Tasks, Recurring tasks, Reminders, Meetings and Standup show only that client; reload and it is still picked; Support with All shows the summary table and a row opens that client; Capacity shows everyone and the note; a `&site=` link sets the picker.
- e2e for `/support-summary` (admin only, reach-filtered) and `/reports?client_site_id=` (narrows; unreachable site is 403).
- `/calendar` entries carry `site_id`; the calendar diary narrows with a site picked.
- Existing specs that drove the old pickers move to `bwx-client-choice` and keep passing, including performance.
