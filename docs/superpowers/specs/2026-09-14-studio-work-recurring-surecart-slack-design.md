# Studio work, recurring tasks, SureCart renewals and Slack

Approved 2026-09-14. Five pull requests, each a minor version, stacked in this
order because each one builds on the last.

| PR | What | Version |
|----|------|---------|
| 1 | The studio's own client, admin delete, anvil icon, picker names, "All clients", standup collapsed | 2.101.0 |
| 2 | Session cache and "Last refreshed" | 2.102.0 |
| 3 | Recurring engine and Recurring Tasks screen | 2.103.0 |
| 4 | SureCart connections and Subscriptions screen | 2.104.0 |
| 5 | Slack for staff | 2.105.0 |

Nothing is merged or tagged by Claude. Every PR is a draft against the branch
before it (PR 1 against `main`); merging in order keeps each one small.

---

## PR 1 — Studio work and tidy-ups

### The studio's own client

Every work item belongs to a client site, and the studio itself is not a
client — so there is nowhere to put the studio's own work. Forge creates one.

- `Tenancy\Studio` owns it. `Studio::ensure()` runs from `Plugin::boot()`
  after `Schema::maybe_upgrade()`, guarded by one autoloaded option
  (`bwx_forge_studio`) holding `{ client_id, site_id }`. With the option set,
  nothing else happens on the request. Without it, a client and one site are
  created and the option written. If the recorded client has gone (table
  emptied), it is created again.
- Name: the WordPress site title at creation (`get_bloginfo( 'name' )`), site
  URL `home_url()`, email domain from the admin email. Status `active`.
- Renaming: a "Studio" panel at the top of the Clients admin screen with one
  field, the studio's display name, saved through the existing client update.
  The panel also says which client is the studio's own.
- It can be deactivated like any other client; nothing special.
- `Studio::client_id()` / `Studio::site_id()` are read by later PRs. The
  `/client-sites` answer marks the studio's site with `studio: true`, and the
  app uses that to pick the default and to find "the Parent site".

### Deleting work (administrators only)

- Route `DELETE /work-items/{id}`, permission `Permissions::manage`
  (`manage_options`). Anything else gets 403 — the same answer as today for a
  route that does not exist for them.
- `Items::delete( $id )` removes the item and everything under it: child
  items (recursively), comments, work events, changelog entries, gate records,
  dependencies in either direction, contributions, saved-view references. All
  in one transaction where the engine supports it; otherwise children first,
  the item last, so a half-finished delete leaves a parent that can be tried
  again. The response says how many items went.
- The app shows "Delete" in the item panel's footer only when `forgeData`
  says the user is an administrator (`canManage`, already exposed for the
  admin links). A confirm names the count of items under it. After deletion
  the panel closes and the board refreshes.

### Anvil menu icon

- `SitesScreen::register()` passes a base64 SVG data URI of the lucide anvil
  instead of `dashicons-hammer`. Lucide is stroke-based and WordPress's menu
  painter only recolours fills, so the SVG carries `stroke="#a7aaad"` (the
  admin menu's icon grey) and `Admin\Page` prints a few lines of CSS on every
  admin screen giving `.toplevel_page_blueworx-forge-sites` its hover/current
  colour via `mask-image` with the same SVG. Admin colour schemes fall back to
  the fixed grey; the current-item white is what matters and is covered.

### Site picker names

- One `siteLabel( site, sites )` helper in `src/sites.ts`, used by every
  picker (Work, Onboarding, My tasks, New work, Capacity where it names a
  site). A client with one site shows the client name only; a client with
  several shows `Client — Site`.

### "All clients" in the site picker

- First option, value `all`. `GET /work-items` without `client_site_id`
  (or with `client_site_id=all`) returns every item within the caller's
  reach, with the same filters. `Items::for_sites( array $ids, $filters )`
  is the query; the controller resolves the id list from `Reach`.
- Cards and list rows show a small client chip when the picker is on
  "All clients". Gantt and calendar take the same items unchanged.
- The picker opens on the studio's site the first time, and afterwards on
  whatever was last picked (`localStorage` key `bwx-forge-site`). A
  remembered site that no longer exists falls back to the studio's.
- New work under "All clients" asks for the site in the form (a site select
  at the top of `NewWork`, defaulting to the studio's).

### Daily standup starts collapsed

- Each section renders its head (title, count, blurb) and hides its cards
  until opened. Opened sections are remembered for the session
  (`sessionStorage` key `bwx-forge-standup-open`). The dismiss/show-hidden
  behaviour is unchanged.

### Tests (PR 1)

- PHPUnit: `Studio` option logic (ensure is a no-op when set; recreates when
  the client is gone), `Items::delete` cascade counts.
- Playwright: studio site appears in the picker and is the default; a task
  can be added to it; admin sees Delete, a subscriber does not, and the
  DELETE route refuses the subscriber; "All clients" shows items from two
  sites with client chips; standup sections start collapsed and open on click;
  picker labels follow the one-site/many-site rule.

---

## PR 2 — Session cache and "Last refreshed"

### The cache

- Lives in `src/api.ts`. Every successful GET is stored by full path in
  memory and mirrored to `sessionStorage` (`bwx-forge-cache`), so it survives
  a hop out to a WordPress admin page and back but not a new tab.
- `api()` gains an option `{ cache: 'fresh' | 'stale-ok' }` (default
  `stale-ok`). `stale-ok` resolves immediately with the cached answer if one
  exists and starts a background fetch; when the fresh answer differs it is
  written to the cache and any subscriber is told. `useForgeQuery( path )`
  is the hook screens use: returns `{ data, refreshedAt, loading, refresh }`
  and re-renders when the background answer lands.
- Any non-GET call clears the whole cache. Simple and safe: the next screen
  fetches fresh. Writes are rare next to reads.
- Entries older than 30 minutes are treated as absent (a fetch is awaited
  rather than shown stale).

### "Last refreshed"

- `PageHeader` gains a right-hand slot: "Refreshed 14:32" (clock time,
  WordPress timezone) and a refresh button. The time is the newest
  `refreshedAt` among the queries the screen registered. The button calls
  every registered query's `refresh()` with `cache: 'fresh'`.
- Registration is a small context (`RefreshContext`) that `useForgeQuery`
  joins on mount and leaves on unmount, so no screen has to wire it by hand.

### Migration

Every screen's `useEffect` + `api()` loading pair is moved to
`useForgeQuery`. Behaviour of loading/denied/error states stays the same; the
only visible change is that a screen already seen appears at once.

### Tests (PR 2)

- Unit (Vitest is not in the repo; use Playwright's `page.evaluate` against
  the dev server or a PHPUnit-free JS test via `node --test` on `src/cache.ts`
  extracted as a pure module): stale-ok serves cached then fresh; a write
  clears; expiry.
- Playwright: switching Kanban → Standup → Kanban makes no second
  `/work-items` request before paint (network assertion); the header shows a
  time; the refresh button triggers a fetch and updates the time.

---

## PR 3 — Recurring tasks

### The engine

One idea shared with PR 4: a **source** says when its next occurrence is
due; `Recurring\Materialise::run()` turns every due occurrence into an
ordinary work item, once. No cron. It runs on the studio's REST index
(`/status`) and on `GET /work-items`, cheap when nothing is due: one query for
sources with `next_due <= today`.

- Table `bwx_forge_recurring`: `id` (`rec_`), `kind` (`schedule` |
  `subscription`), `client_site_id`, `title`, `description`, `work_type`,
  `primary_user_id`, `reviewer_id`, `deliverer_id`, `hours_primary`,
  `hours_review`, `hours_delivery`, `rule` (JSON), `starts_on`, `ends_on`,
  `next_due` (date), `last_created_at`, `status` (`active` | `paused` |
  `ended`), `source_ref` (PR 4's subscription id, else empty), record
  columns as every other table.
- Table `bwx_forge_recurring_occurrences`: `recurring_id`, `due_on`,
  `work_item_id`, primary key (`recurring_id`, `due_on`). The insert is the
  claim, the same way `Notifications\Register` claims an event: two callers
  materialising the same day both insert, the database gives one the row, and
  only that one creates the item. Never twice.
- Work items get a nullable `recurring_id` column so an occurrence knows its
  source and the screen can show "last created".
- Rules: `{ every: 'day' }`, `{ every: 'week', days: [1,4] }` (ISO weekdays),
  `{ every: 'month', day: 15 }` (clamped to the month's last day).
  `Recurring\Rule::next_after( rule, date )` is pure and unit tested.
  Missed days stack: materialise walks from `next_due` up to today, creating
  one item per due date, then sets `next_due` to the first future date.
- Each occurrence: title `"{title} — {d Mon}"`, description copied, type
  from the source, seats and hours from the source, `planned_due` = due
  date, stage **Up Next**. Creation goes through `Items::create` then a
  recorded transition to `up-next` by the system actor, so the changelog is
  honest about how it got there. If the transition is refused (a gate), the
  item stays at the first stage with a comment saying why.
- Editing a source changes future occurrences only. Pausing stops
  materialising and keeps `next_due` moving forward without creating (a
  paused week is skipped, not stacked). Deleting a source ends it; items
  already created stay.

### The screen

- Rail: **Delivery › Recurring tasks**, shown only when the studio's site is
  within reach. Lists sources: title, cadence in words ("Every Monday and
  Thursday"), seats, hours, next due, last created, status. Add/edit in a
  side panel with the same seat/hours fields the item panel uses. Pause,
  resume, delete.
- Routes under `/recurring`: list, create, show, update, delete, plus
  `POST /recurring/{id}/run` for tests and for "Create today's now".
  Administrators only for writes; anyone with studio reach reads.

### Tests (PR 3)

- PHPUnit: `Rule::next_after` for each cadence including month-end clamp;
  stacking across missed days; paused skips.
- Playwright: create a weekly source due today, open the board, the item is
  in Up Next with the seats set and appears in My tasks for the Primary;
  opening the board again creates nothing more.

---

## PR 4 — SureCart subscriptions

### Connections (admin page)

- **Forge › Connections** (`Admin\ConnectionsScreen`). Panel "SureCart
  stores": name, API token, Primary (required), Reviewer, Deliverer, hours
  per seat (default 0.25 for Primary). Add, test, edit seats, remove. The
  token is encrypted at rest with `Tenancy\Secrets` (libsodium secretbox,
  key derived from `AUTH_KEY`), never displayed after saving, and the test
  button reports "Connected — 14 active subscriptions" or the API's refusal.
- Table `bwx_forge_connections`: `id` (`con_`), `kind` (`surecart`), `name`,
  `secret` (ciphertext), `settings` (JSON: seats and hours), `status`,
  `last_ok_at`, `last_error`, record columns.

### Reading subscriptions

- `Commerce\SureCart\Client` calls `https://api.surecart.com/v1/subscriptions`
  with the bearer token, paging, `status=active`, expanding `customer` and
  `price.product`. `Commerce\SureCart\Sync::refresh( $connection )` upserts
  into `bwx_forge_subscriptions`: `id` (SureCart's id), `connection_id`,
  `customer_name`, `customer_email`, `product_name`, `amount` (minor units),
  `currency`, `interval`, `status`, `current_period_end` (date),
  `fetched_at`. A store that fails keeps its last rows and shows the error.
- When: on opening the Subscriptions screen, and at most once an hour
  otherwise, piggybacking on the same `/status` request the recurring engine
  uses (a `fetched_at` older than an hour triggers it). No cron.

### Reminders

- Each active subscription is a recurring **source** of kind `subscription`
  on the studio's site, `source_ref` = subscription id, seats from the
  connection, `next_due` = `current_period_end`. Sync creates/updates the
  source; a subscription that leaves `active` ends its source. The existing
  engine then makes the task on renewal day: title
  `Subscription Renewal: {Customer} - ({£Amount})` (currency symbol from the
  subscription's currency), description naming the product, store and
  interval, type `task`, due that day, Up Next. From there it is an ordinary
  task, so My tasks and Daily standup show it to whoever holds a seat.

### Subscriptions screen

- Rail: **Insight › Subscriptions**. Table: customer, product, amount and
  interval, status, next renewal, store, reminder (none / the task's stage,
  linking to it). A per-store status line (last refreshed, or the error). A
  refresh button forces a sync. Read-only.

### Tests (PR 4)

- PHPUnit: `Secrets` round trip; sync upsert from a fixture response; source
  creation and ending; title formatting with GBP/USD/EUR.
- Playwright: with the SureCart API stubbed by a route intercept on the
  test WordPress (a `bwx_forge_surecart_base_url` filter pointing at a
  local fixture route), add a connection, see subscriptions, run
  materialise, see the reminder in Up Next and in standup for the Primary.

---

## PR 5 — Slack for staff

### Connecting

- My tasks gains a "Slack" panel: paste an Incoming Webhook URL, "Send
  test", then "Connected · change · remove". Stored encrypted with
  `Tenancy\Secrets` in `bwx_forge_people_slack` (`user_id`, `secret`,
  `prefs` JSON, `connected_at`, `last_ok_at`, `last_error`). Each person
  reads and writes only their own row (`/me/slack`); administrators may
  remove anyone's from the People admin screen but never see the URL.
- Preferences, all on by default: assigned, arriving, ready-for-me,
  comments, morning.

### Events

- `Slack\Events` is a closed list, ids derived from what happened as
  `Notifications\Events` does, claimed through `Notifications\Register`
  (kind prefix `slk`) so nothing sends twice:
  - `assigned` — a seat on an item set to me (create or update), one per
    item/seat/person.
  - `arrived` — a new item or converted request with me as Primary.
  - `ready` — item enters In review and I am Reviewer; enters Completed and I
    am Deliverer. Keyed by cycle like the client emails.
  - `comment` — a comment on an item where I hold a seat, by someone else.
  - `morning` — one per person per day.
- Delivery: `Slack\Webhook::send( $secret, $blocks )` via `wp_remote_post`,
  Block Kit with a title line, the client/site, due date, and a link to the
  item (`#item=<id>` on the app page). Failures settle `retrying` and are
  retried on the next event for that person; three failures settle `failed`
  and show on Sync health with the person's name.

### The morning job

- One WP-Cron event, `bwx_forge_slack_morning`, daily at the time on the
  Connections admin page ("Slack" panel: hour and minute, WordPress
  timezone, default 08:00). Rescheduled when the time is saved. It runs the
  `morning` event for every connected person with the preference on: due
  today and overdue items where they hold a seat, grouped, or "Nothing due
  today". The single documented exception to Forge's no-cron rule: it sends a
  message and decides no state, so a missed run is a missed ping, never wrong
  data.

### Tests (PR 5)

- PHPUnit: event ids stable across callers; preference gating; morning
  message assembly from fixture items.
- Playwright: connect a webhook pointing at a local capture route on the
  test WordPress, assign a task, assert one message arrived with the item
  link; assign again, assert still one; run the morning job via WP-CLI-free
  trigger (`POST /slack/morning` administrators only, also used by "Send
  now" on the Connections page) and assert the digest.

---

## Cross-cutting

- Every PR: version bump in `package.json`, both plugin headers and the
  constant; changelog entry in the user's words; `npm run lint`,
  `npm run build`, `composer lint`, PHPUnit, Playwright.
- No new npm or composer dependency. Sodium is in PHP core.
- Visual confirmation (project rule 7) could not happen in this session;
  each PR says so and asks for it before merge.
