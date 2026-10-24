# Connections Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Connections panel in Settings; Cloudways server status with a standup flag (Part A, 2.210.0); creating a client's WordPress site on a multisite network from Forge (Part B, 2.211.0).

**Architecture:** Studio PHP classes per service (`includes/Cloudways/`, `includes/Network/`) behind one REST controller (`Rest/ConnectionsController.php`), React screens in the app (ARCH-7). Part B adds a signed, replay-protected route set to the client plugin (`client/includes/Network/`) that only answers on the main site of a network where the plugin is network-activated.

**Tech Stack:** PHP 7.4+/WordPress REST, libsodium (`Tenancy\Secrets`) for secrets at rest, React + the `src/kit` components, PHPUnit (stubbed WordPress), Playwright.

**Spec:** `docs/superpowers/specs/2026-10-23-connections-design.md`

## Global Constraints

- No secret (API key, network key, issued site key) is ever returned to the browser, logged, or shown except as its last four characters.
- No password is ever emailed or shown; new users get WordPress's own set-password email.
- Every route here is `Permissions::manage` (administrator) on the studio. The status page and the standup flag are administrators only.
- Nothing runs in the background: Cloudways is asked only when the status page opens or *Check now* is pressed.
- Client-plugin code stays under `client/`; `npm run check:artifacts` passes.
- No new npm or composer dependency.
- Versions: Part A 2.210.0, Part B 2.211.0 — package.json, both plugin headers and both version constants; changelog and `src/rules.ts` in the same PR.

## Review Focus

1. Cloudways rejects the key, or cannot be reached → connecting refuses to save with a plain message; the status page says Cloudways could not be asked and shows each server as unreachable, keeping the last known state for the standup flag. (Task A2, A3 tests.)
2. Opening the status page twice in a few seconds → one Cloudways call. (Task A3 throttle test.)
3. A replayed or stale signed request to the network → refused, with the same vague answer as a bad signature. (Task B2 tests.)
4. The studio asks to link or add people to a site the route did not create (e.g. the network's main site) → refused. (Task B3 test.)
5. Somebody who already has an account on the network → added to the new site, no second account, no email. (Task B3 test.)

## Rulings

- Ruling: Connections is a panel at the top of the Settings screen, not a separate rail entry; the server status page is its own admin-only screen, "Servers", under Insight — the design puts Connections "in Settings" and gives status "its own page" — cost if wrong: moving a component between screens.
- Ruling: Cloudways calls are `POST https://api.cloudways.com/api/v1/oauth/access_token` (email, api_key → access_token, ~1 h) and `GET /api/v1/server` (servers, each with `status` and `apps`). The token is kept sealed in a transient for 50 minutes — cost if wrong: one adapter class changes.
- Ruling: connecting asks Cloudways for a token first and refuses to save credentials it rejects — a typo is caught at once rather than on the status page — cost if wrong: none worth naming.
- Ruling: opening the page reuses a check under 5 minutes old; *Check now* forces one unless the last was under 15 seconds ago — cost if wrong: two constants.
- Ruling: a server is "running" only when Cloudways says `running`; every other state Cloudways reports is shown in its words and flagged. When Cloudways cannot be asked, servers show as "unreachable" and the standup keeps flagging by the last state Cloudways did report — so an outage at Cloudways neither hides a down server nor flags every server — cost if wrong: one condition.
- Ruling: an application matches a Forge client site when any of its addresses (`cname`, `app_fqdn`, `aliases`) equals the site's URL host, lowercased, ignoring scheme, `www.`, port and path — cost if wrong: one function.
- Ruling: the standup flag is a `needs-intervention` card with subject type `server`, added only when the caller has `manage_options`, with no client site so it shows under every client picked — cost if wrong: a Manager would not see it, which the design asks for.
- Ruling (B): the network key is made on the network, not the studio: the network-activated client plugin adds a Network Admin screen that generates it (shown once; `BWX_FORGE_NETWORK_KEY` in wp-config wins). Luke then enters only the network's address and that key in Connections — cost if wrong: the key could be generated studio-side instead.
- Ruling (B): signed calls to the network reuse the existing canonical form with the fixed site id `network` and the network key; the network side has its own nonce store and 300-second window. Connecting calls a signed `GET /network/hello` and saves only if it answers — cost if wrong: none; the form is already conformance-tested.
- Ruling (B): the network's address must be https, except loopback (127.0.0.1, localhost) so the pair suite can reach it — a signed body carries the new site's key — cost if wrong: one check.
- Ruling (B): three steps, three signed calls — create (`POST /network/sites`), link (`POST /network/sites/{blog}/link`), logins (`POST /network/sites/{blog}/people`) — so the app can show each step as it happens and retry from the one that failed. Link and logins only act on a site this route created (a blog option marks it). Create is idempotent for the same Forge site — cost if wrong: merge into one call.
- Ruling (B): linking issues (or, on a retry, rotates) the Forge site's key through the existing Registry/Integrations path, and sends it in the signed link call; the network stores it with the client plugin's own `Connection::store` on the new blog — cost if wrong: none; this is the existing path minus the copy-paste.
- Ruling (B): logins come from the client's active memberships with no site limit or a limit to this site: `client_admin` → Editor + Forge: Manager, `client_viewer` → Forge: Manager; staff, internal viewers and primary admins get nothing. Existing network accounts (by email) are added without an email; new ones get `wp_new_user_notification( $id, null, 'user' )` — cost if wrong: one mapping.
- Ruling (B): "Create site" is offered on a Forge site whose integration key is not active, or whose deploy is unfinished — cost if wrong: one condition.
- Ruling (B): the test harness cannot run a multisite network, so the network route's site and user creation is proven by PHPUnit with stubbed WordPress, the studio's whole flow by a single-instance e2e against a stubbed network, and the route's refusals by the pair suite against the real client plugin — cost if wrong: a live first deploy is the first real multisite run; Luke should try it on a throwaway address first.

---

## Part A — Cloudways status (2.210.0, Part of #621)

### Task A1: Cloudways credentials

**Files:** Create `includes/Cloudways/Settings.php`; Test `tests/php/CloudwaysSettingsTest.php`

**Interfaces — Produces:** `Settings::OPTION = 'bwx_forge_cloudways'`; `save( string $email, string $key ): void`; `email(): string`; `key(): ?string` (opened); `key_hint(): string` (`'••••' . last4` or `''`); `connected(): bool`; `connected_at(): int`; `forget(): void` (also deletes the token transient and the status snapshot).

- [ ] Test: saving seals the key (`wp_json_encode(get_option(OPTION))` does not contain it), `key()` opens it, hint ends in the last four, `forget()` clears everything.
- [ ] Run, see it fail; implement on the pattern of `Google\Settings`; run, see it pass.

### Task A2: Cloudways API client

**Files:** Create `includes/Cloudways/Client.php`; Test `tests/php/CloudwaysClientTest.php`

**Interfaces — Produces:** `Client::API = 'https://api.cloudways.com/api/v1'`; `token( string $email, string $key ): string|WP_Error` (no cache); `servers(): array|WP_Error` — list of `array{id:string,label:string,status:string,apps:array<int,array{id:string,label:string,domains:string[]}>}`, using a cached sealed token (transient `bwx_forge_cloudways_token`, 50 min), re-fetching once on a 401. Error codes `Client::DENIED` (401/403 or `invalid` credentials) and `Client::FAILED`.

- [ ] Tests: the token call posts email + api_key; servers sends `Authorization: Bearer`; a 401 on servers refetches the token once; a WP_Error answer is `FAILED`; apps' domains collect `cname`, `app_fqdn`, `aliases`; the key never appears in an error message.
- [ ] Fail, implement, pass.

### Task A3: Status — check, throttle, match, flag

**Files:** Create `includes/Cloudways/Status.php`, `includes/Cloudways/Address.php`; Test `tests/php/CloudwaysStatusTest.php`

**Interfaces — Produces:** `Address::host( string $url ): string`; `Status::OPTION = 'bwx_forge_cloudways_status'`; `Status::read( bool $force ): array` → `array{checked_at:int, error:string, servers: array<int, array{id,label,state,last_state,apps: array<int,array{label,domains,client_site_id,site_name}>}>}`; `Status::problems(): array` (interventions for the standup: `id 'server-<id>'`, `subject_type 'server'`, `title`, `kind 'server-down'`, `detail`, `client_site_id ''`, `since`).

- [ ] Tests: `read(false)` within 300 s returns the snapshot without an HTTP call; `read(true)` within 15 s also does; a failed check marks servers `unreachable` keeping `last_state` and sets `error`; matching by host ignores scheme/www/port/path; `problems()` lists a stopped server and not a running one, and uses `last_state` while unreachable.
- [ ] Fail, implement, pass.

### Task A4: REST and the standup flag

**Files:** Create `includes/Rest/ConnectionsController.php`; Modify `includes/Rest/Server.php`, `includes/Standup/Board.php` (`state()`); Test `tests/php/ConnectionsRestTest.php` (route registration, admin only) and the e2e in A6.

**Interfaces — Produces:** `GET /connections` → `{services: [{id:'cloudways', name, connected, last_checked, hint, email}, {id:'surecart'|'slack', link}]}`; `POST /connections/cloudways {email, api_key}` (400 on blanks, 422 when Cloudways refuses); `DELETE /connections/cloudways`; `GET /servers?check=now|''` → the `Status::read` answer. All `Permissions::manage`, scope open with a reason.

- [ ] Test: routes register with `Permissions::manage`; no response field holds the key.
- [ ] Board: append `Status::problems()` to interventions when `current_user_can('manage_options')`.

### Task A5: The screens

**Files:** Create `src/components/ConnectionsPanel.tsx`, `src/components/ServersScreen.tsx`; Modify `src/components/SettingsScreen.tsx`, `src/App.tsx` (screen `servers`, admin, Insight), `src/types.ts`, `src/standup.ts` (`server` cards show their detail), `src/rules.ts`, `src/shell.css` if needed.

- [ ] Connections panel: one row per service (name, Connected/Not connected, last checked, Connect/Disconnect); Cloudways' connect form (email, API key, password field, hint once saved); SureCart and Slack rows link to `admin.php?page=blueworx-forge-connections`; a link to the Servers screen.
- [ ] Servers screen: checks on open, *Check now*, each server with its state chip and checked time; under it the applications with their client or "Not matched".

### Task A6: e2e, version, changelog

**Files:** Create `tests/support/cloudways-stub.php`, `tests/e2e/helpers/cloudways.js`, `tests/e2e/connections.spec.js`; Modify versions, `CHANGELOG.md`.

- [ ] Spec: an admin connects Cloudways in Settings (a wrong key is refused); the Servers screen lists a running and a stopped server with the matched client; the stopped server is on the standup for the admin and not for a Manager; `/connections` never contains the key.
- [ ] Security review; build; lint once; draft PR "Part of #621".

## Part B — Multisite deploys (2.211.0, Closes #621)

### Task B1: Network connection on the studio

**Files:** Create `includes/Network/Settings.php`, `includes/Network/Client.php`; Test `tests/php/NetworkSettingsTest.php`, `tests/php/NetworkClientTest.php`

**Interfaces — Produces:** `Settings::save( string $url, string $key )`, `url()`, `key(): ?string`, `key_hint()`, `connected()`, `last_ok_at()`, `note_ok()`, `forget()`; `Settings::valid_url( string $url ): bool` (https or loopback http); `Client::call( string $method, string $route, array $body = [] ): array|WP_Error` — signs with `Rest\Signature::sign( $key, 'network', ... )`, headers `X-BWX-Site: network`, timestamp, nonce, signature; route under `/wp-json/blueworx-forge-client/v1`.

### Task B2: The network gate (client plugin)

**Files:** Create `client/includes/Network/Gate.php`, `client/includes/Network/Key.php`; Test `tests/php/NetworkGateTest.php`

**Interfaces — Produces:** `Key::get(): string` (constant, then site option `bwx_forge_network_key`), `Key::generate(): string`; `Gate::verify( string $method, string $path, string $body, array $headers ): bool` — refuses no key, missing headers, site ≠ `network`, outside 300 s, bad signature (`hash_equals`), replayed nonce (site transient, claimed last).

### Task B3: Network routes (client plugin)

**Files:** Create `client/includes/Network/Sites.php`, `client/includes/Rest/NetworkController.php`, `client/includes/Admin/NetworkKeyScreen.php`; Modify `client/includes/Plugin.php`; Test `tests/php/NetworkSitesTest.php`

**Interfaces — Produces:** routes `GET /network/hello`, `POST /network/sites {slug,title,site_id}`, `POST /network/sites/{blog}/link {studio_url,site_id,key}`, `POST /network/sites/{blog}/people {people:[{email,name,access:'editor'|'manager'}]}`; `Sites::valid_slug()`, `Sites::roles_for( string $access ): string[]` (`editor` → `['editor','forge_manager']`, `manager` → `['forge_manager']`, anything else → `[]`).

### Task B4: Deploys on the studio

**Files:** Create `includes/Network/Deploys.php`; Modify `includes/Rest/ConnectionsController.php`; Test `tests/php/NetworkDeploysTest.php`

**Interfaces — Produces:** `POST /connections/network`, `DELETE /connections/network`; `GET /client-sites/{site_id}/deploy`; `POST /client-sites/{site_id}/deploy {step:'create'|'link'|'people', slug?, title?}`; `Deploys::people_for( string $client_id, string $site_id ): array`.

### Task B5: Screens, e2e, pair spec, version

**Files:** Modify `ConnectionsPanel.tsx` (network row), `ClientProfileScreen.tsx` (Create site per site); Create `src/components/CreateSite.tsx`, `tests/support/network-stub.php`, `tests/e2e/create-site.spec.js`, `tests/pair/network-route.spec.js`.
