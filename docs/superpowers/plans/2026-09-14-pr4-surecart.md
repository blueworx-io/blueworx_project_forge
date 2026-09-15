# PR 4 — SureCart subscriptions: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Forge reads active subscriptions from any SureCart store by API token, shows them under Insight › Subscriptions, and on each renewal day puts a "check the payment" task on the studio's board for the staff chosen per store.

**Architecture:** A `Connections` admin page holds stores (token encrypted with libsodium under a key derived from `AUTH_KEY`). `Commerce\SureCart\Client` talks to `api.surecart.com`; `Sync` upserts subscriptions into a table and keeps one PR 3 recurring **source** of kind `subscription` per active subscription (`source_ref` = subscription id, `next_due` = renewal date). PR 3's engine makes the task. Refresh happens when the Subscriptions screen opens and, at most hourly, alongside the recurring engine's own run — no cron.

**Tech Stack:** PHP, `wp_remote_get`, sodium (PHP core), React + TypeScript, Playwright with a stub SureCart served by the test WordPress itself.

**Spec:** `docs/superpowers/specs/2026-09-14-studio-work-recurring-surecart-slack-design.md` (section "PR 4").

## Global Constraints

- Branch `surecart-subscriptions`, off `recurring-tasks`. Draft PR against `recurring-tasks`.
- Version `2.104.0`; changelog. Schema `VERSION` +1; two tables.
- The SureCart base URL is filterable (`bwx_forge_surecart_base_url`) so tests point it at a stub route on the same WordPress. The stub lives in `tests/` support code loaded only when `BWX_FORGE_TEST_STUBS` is defined by the harness (a tiny mu-plugin the spec writes into `.wp-test/wp/wp-content/mu-plugins/` before it runs and removes after) — never in the shipped plugin.
- Tokens are never returned by any route or printed on any screen after saving.

---

### Task 1: `Tenancy\Secrets` (encrypt at rest)

**Files:** `includes/Tenancy/Secrets.php`, `tests/php/SecretsTest.php`.

**Interfaces:** `Secrets::seal( string $plain ): string` (base64 of nonce + ciphertext, `sodium_crypto_secretbox`), `Secrets::open( string $sealed ): ?string` (null when tampered or the key changed), key = `sodium_crypto_generichash( AUTH_KEY . '|bwx-forge', '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES )`. Define `AUTH_KEY` in the PHPUnit bootstrap if absent.

- [ ] Tests: round trip; different nonces for the same input; a flipped byte opens to null; empty string seals and opens. Implement. Commit.

### Task 2: Tables and `Connections`

**Files:** `includes/Data/Schema.php`, `includes/Commerce/SureCart/Connections.php`, `includes/Commerce/SureCart/Subscriptions.php`.

- `bwx_forge_connections`: `id` (`con`), `kind` ('surecart'), `name`, `secret` text, `settings` text (JSON: `primary_user_id`, `reviewer_id`, `deliverer_id`, `hours_primary`, `hours_review`, `hours_delivery`), `status` ('active'|'disabled'), `last_ok_at`, `last_error` varchar(191), `last_count` int, record columns.
- `bwx_forge_subscriptions`: `id` varchar(64) (SureCart id), `connection_id`, `customer_name`, `customer_email`, `product_name`, `amount` int (minor units), `currency` varchar(3), `interval` varchar(20), `status` varchar(20), `renews_on` varchar(10), `fetched_at` bigint, `raw` text (the subscription as received, for "what did SureCart say"). `PRIMARY KEY  (id)`, `KEY connection (connection_id)`, `KEY renews_on (renews_on)`.

**Interfaces:** `Connections::create( name, token, settings, author )`, `get`, `all()`, `update_settings( id, settings, version )`, `set_token( id, token )`, `remove( id )` (hard delete — a connection is plumbing, not a record; its subscriptions rows go with it and their recurring sources are ended), `mark( id, ok: bool, error: string, count: int )`, `token( id ): ?string` (opens the secret; PHP-side only). `Subscriptions::upsert( connection_id, array $rows )` (replace that connection's rows in one transaction), `all()`, `for_connection( id )`.

- [ ] Implement; PHPUnit for the settings JSON round trip via a pure `Connections::settings_from( array $input )` validator (seats as `usr_` ids, hours ≥ 0, Primary required). Commit.

### Task 3: `Client` and `Sync`

**Files:** `includes/Commerce/SureCart/Client.php`, `includes/Commerce/SureCart/Sync.php`, `tests/php/SureCartSyncTest.php`, `tests/php/fixtures/surecart-subscriptions.json`.

- `Client::subscriptions( string $token ): array|WP_Error` — `GET {base}/v1/subscriptions?status[]=active&expand[]=customer&expand[]=price&expand[]=price.product&limit=100&page=N`, bearer token, 20 s timeout, pages until `pagination.count` reached (cap 20 pages). `Client::test( $token )` — page 1 only; returns the count or the error.
- `Sync::normalise( array $subscription ): array` (pure) — picks `id`, `status`, customer name/email from `customer.name`/`customer.email` (fallback `customer.first_name + last_name`), product from `price.product.name`, `amount` from `price.amount`, `currency` from `price.currency`, `interval` from `price.recurring_interval` + `recurring_interval_count`, `renews_on` from `current_period_end_at` (unix → `Y-m-d` in the site timezone). Missing fields become '' / 0, never an error.
- `Sync::refresh( array $connection ): array{ok: bool, count: int, error: string}` — fetch, normalise, `Subscriptions::upsert`, `Connections::mark`, then reconcile sources: for each active subscription with a `renews_on` today or later, `Sources::by_source_ref( id )` → create (kind `subscription`, title `Subscription Renewal: {customer} - ({amount})`, description "{product}, {interval}, from {store}", seats/hours from settings, rule `{ every: 'month' }` placeholder with `next_due` forced to `renews_on`) or update `next_due` to `renews_on`; every source whose subscription is gone or not active → `Sources::end`. `Sync::maybe()` — once an hour (transient), all active connections; called from `Materialise::maybe()` so renewals are fresh before the engine runs.
- `Sync::title( customer, amount, currency ): string` — `Subscription Renewal: Acme Ltd - (£120.00)`; symbols for GBP/USD/EUR, else the ISO code.

- [ ] Unit tests for `normalise` and `title` from the fixture. Implement. Commit.

### Task 4: Connections admin page

**Files:** `includes/Admin/ConnectionsScreen.php`, `includes/Admin/ConnectionActions.php`, `includes/Plugin.php`.

- Under Forge: "Connections". Panel "SureCart stores": a table of stores (name, staff, hours, last refreshed, last error or count, buttons: Test, Refresh now, Remove) and an "Add a store" form (name, API token, Primary select of active people (required), Reviewer, Deliverer, hours ×3). Each store row has an "Edit staff" accordion with the same seats form (no token field; a separate "Replace token" field that only writes when filled).
- Actions: `bwx_forge_add_connection`, `bwx_forge_edit_connection`, `bwx_forge_test_connection`, `bwx_forge_refresh_connection`, `bwx_forge_remove_connection`; nonce per action; results via `bwx-result`.

- [ ] Playwright `tests/e2e/connections-screen.spec.js` (with the stub): add a store, see "Connected — 2 active subscriptions" after Test, see the rows after Refresh. Commit.

### Task 5: REST + Subscriptions screen

**Files:** `includes/Rest/SubscriptionsController.php` (`GET /subscriptions` — `SCOPE_LIST`, studio reach like `/recurring`; runs `Sync::maybe()` then returns `{ connections: [{id,name,last_ok_at,last_error,last_count}], subscriptions: [...with reminder: { work_item_id, stage } from the source's latest occurrence] }`; `POST /subscriptions/refresh` — `manage`, forces `Sync::refresh` on every connection), `includes/Rest/Server.php`, `src/components/SubscriptionsScreen.tsx`, `src/App.tsx` (Insight › Subscriptions, icon `CreditCard`), `src/types.ts`.
- Screen: per-store status line; DataView columns customer, product, amount + interval, status, renews on, store, reminder (— / stage chip linking to the item panel). Refresh button (admin) → `/subscriptions/refresh`.

- [ ] Playwright `tests/e2e/subscriptions.spec.js`: with the stub returning one subscription renewing today, refresh, see it listed; `POST /recurring/run`; see the reminder task on the studio board in Up Next titled `Subscription Renewal: … - (£…)` with the Primary from the store; standup for that Primary lists it (due today). Commit.

### Task 6: The stub

**Files:** `tests/support/surecart-stub.php` (an mu-plugin: registers `bwx-forge-test/v1/surecart/subscriptions` returning what `tests/support/surecart-fixture.json` holds, and `add_filter( 'bwx_forge_surecart_base_url', fn () => rest_url( 'bwx-forge-test/v1/surecart' ) )`; the fixture's `current_period_end_at` is rewritten to today at request time), `tests/e2e/helpers/surecart.js` (copies the stub into the instance's `mu-plugins` before the spec and removes it after; `PLAYWRIGHT_BASE_URL` gives the instance dir via `.wp-test/`), both listed in `bin/artifacts.json`'s excludes so nothing under `tests/` ships (already the case; confirm with `npm run check:artifacts`).

### Task 7: Version, changelog, checks, PR

- [ ] `2.104.0`; changelog "Added — SureCart stores can be connected under Forge → Connections. Active subscriptions appear under Insight › Subscriptions, and on each renewal day a 'Subscription Renewal' task lands on the studio's board for the staff chosen for that store — so My tasks and Daily standup carry it too." Lint once, build, PHPUnit, Playwright on a fresh instance. Push; draft PR against `recurring-tasks`.
