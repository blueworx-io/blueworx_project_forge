# External API Connectors — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an administrator configure outbound connections that push a JSON payload to an external HTTPS endpoint whenever a new item is created in Forge, delivered asynchronously with retries and a visible activity log.

**Architecture:** `Forge_PM_REST_API::create_item()` fires a new `forge_pm_item_created` action after a successful insert. `Forge_PM_Connectors` subscribes, finds enabled connections matching the item type, and schedules a WP-Cron single event per connection. The cron handler POSTs a stable JSON envelope via `wp_remote_post()`, retrying at 60s/300s/1800s over three attempts, and records each attempt in a capped log. Connections live in their own `forge_pm_connections` option — never in `forge_pm_settings`, which is injected into page HTML — and bearer tokens are write-only, stripped from every REST response.

**Tech Stack:** React 18 + TypeScript, Zustand v5, Vite, Tailwind v4, lucide-react icons, WordPress PHP REST API + WP-Cron. No unit-test runner is configured; the project's required checks are `npm run lint` and `npm run build`, with visual confirmation via `npm run dev`.

**Testing note:** This repo has no vitest/jest/PHPUnit harness, and CLAUDE.md forbids adding frameworks without asking. Each task is therefore verified with `npm run build` (type/compile correctness) + `npm run lint`, plus targeted manual verification. The feature is verified end-to-end with the manual checklist in Task 9. PHP is verified via `php -l` syntax checks and live REST calls. This mirrors the approach taken by `2026-06-11-dependency-tracking.md`.

**Spec:** [`docs/superpowers/specs/2026-07-15-external-api-connectors-design.md`](../specs/2026-07-15-external-api-connectors-design.md)

**Branch:** `feature/external-api-connectors-29` (already created; the design spec is committed here).

## Global Constraints

- **Trigger is item creation only.** No push on update, stage change, or delete.
- **Connections are stored in `forge_pm_connections`**, a separate `wp_option`. Never add them to `forge_pm_settings` — that option is injected into page HTML by `class-enqueue.php` and would publish bearer tokens in page source.
- **All connection REST routes use `manage_options`**, not `can_edit_items`.
- **`authToken` is never returned by any REST response.** Reads return `authTokenHint` only (last 4 chars, prefixed `••••`).
- **Connection URLs must use `https://`.** Validate on save; reject with `400` otherwise.
- **The payload is NOT passed through `strip_sensitive()`** — see spec. Full item content is sent by design.
- **Item creation must never fail because of a connector.** All connector work happens after the insert, and delivery is out-of-process.
- **Delivery log is capped at 50 entries**, trimmed on every write.
- **PHP style:** follow the existing `Forge_PM_*` static-class convention, tabs for indentation, `defined( 'ABSPATH' ) || exit;` at the top of every include.
- **TS style:** follow `Settings.tsx` conventions — inline `style={{}}` objects (not Tailwind classes) inside settings panels, spaces inside parens `( like this )`.
- **Retry schedule:** 4 attempts total — attempt 1 immediate, then retries at 60s, 300s, and 1800s. Give up after attempt 4 fails.

---

## File Structure

- `includes/class-connectors.php` — option storage, REST routes, cron delivery, retry, logging. *(create)*
- `includes/class-rest-api.php` — fire `forge_pm_item_created`; add public `payload_for()`. *(modify)*
- `forge-project-management.php` — require the class; register REST + cron hooks; version bump. *(modify)*
- `src/app/types.ts` — `Connection`, `ConnectionDelivery`. *(modify)*
- `src/app/api/wordpress.ts` — connection API functions. *(modify)*
- `src/app/api/mockBackend.ts` — standalone connection stubs. *(modify)*
- `src/app/components/settings/ConnectionsSection.tsx` — the settings panel. *(create)*
- `src/app/components/Settings.tsx` — register the section in `SECTION_NAV`. *(modify)*
- `package.json` — version bump. *(modify)*

---

## Task 1: Types — `Connection` and `ConnectionDelivery`

**Files:**
- Modify: `src/app/types.ts`

**Interfaces:**
- Consumes: nothing (first task).
- Produces: `Connection`, `ConnectionDelivery`, `ConnectionTestResult` — used by Tasks 5, 6, 7.

- [ ] **Step 1: Add the interfaces to `src/app/types.ts`**

Append at the end of the file, after `ArchivedItem`:

```ts
export interface Connection {
  id: string;
  name: string;
  url: string;
  /** Write-only. Send to set/replace the token; omit to leave unchanged. Never returned by the API. */
  authToken?: string;
  /** Read-only display hint, e.g. "••••a1b2". Present only if a token is stored. */
  authTokenHint?: string;
  /** Which item creations fire this connection. */
  itemTypes: ItemType[];
  enabled: boolean;
  createdAt: string;
}

export interface ConnectionDelivery {
  id: string;
  connectionId: string;
  itemType: string;
  itemId: string;
  status: 'success' | 'failed' | 'retrying';
  httpCode?: number;
  error?: string;
  /** 1–4: attempt 1 is immediate, then retries at 60s, 300s, 1800s. */
  attempt: number;
  timestamp: string;
}

export interface ConnectionTestResult {
  success: boolean;
  httpCode?: number;
  error?: string;
}
```

`ItemType` already exists at the top of this file (`'feature' | 'subitem' | 'bug' | 'feedback' | 'release'`), so no new union is needed.

- [ ] **Step 2: Verify it compiles**

Run: `npm run build`
Expected: build succeeds. (Types alone produce no runtime change.)

- [ ] **Step 3: Commit**

```bash
git add src/app/types.ts
git commit -m "Add Connection and ConnectionDelivery types (#29)"
```

---

## Task 2: PHP — `payload_for()` accessor + `forge_pm_item_created` action

**Files:**
- Modify: `includes/class-rest-api.php`

**Interfaces:**
- Consumes: existing private `read_single_item( int $post_id ): ?array`.
- Produces:
  - `Forge_PM_REST_API::payload_for( int $post_id ): ?array` — used by Task 4.
  - Action `do_action( 'forge_pm_item_created', string $type, int $post_id )` — subscribed to in Task 4.

- [ ] **Step 1: Add the public accessor**

In `includes/class-rest-api.php`, immediately **after** the existing `bust_cache()` method (around line 127) and **before** `private static function strip_sensitive(`, add:

```php
	/**
	 * Public, connector-facing accessor: the shaped payload for a single item.
	 *
	 * Deliberately does NOT call strip_sensitive() — that method sanitises
	 * responses for unauthenticated visitors, and would remove `description`,
	 * the field receiving systems most need. Connections are admin-configured
	 * destinations, so they receive full item content by design.
	 */
	public static function payload_for( int $post_id ): ?array {
		return self::read_single_item( $post_id );
	}
```

- [ ] **Step 2: Fire the action after a successful create**

In `create_item()`, find the `return` at the end of the method. It currently returns a success response after saving meta. Add the `do_action` call immediately **before** that final return, so it fires only after the insert and all meta writes have succeeded:

```php
		/**
		 * Fires after a new Forge item is created and its meta saved.
		 * Connectors subscribe to this; the REST layer stays unaware of them.
		 *
		 * @param string $type    Forge item type, e.g. 'feature', 'bug'.
		 * @param int    $post_id The new post ID.
		 */
		do_action( 'forge_pm_item_created', $type, (int) $post_id );
```

Place it after the existing `self::bust_cache();` call if present, so the cache is already invalidated when listeners run.

- [ ] **Step 3: Syntax check**

Run: `php -l includes/class-rest-api.php`
Expected: `No syntax errors detected in includes/class-rest-api.php`

If `php` is not on PATH, skip this step — Task 9 exercises the file in a live WordPress install.

- [ ] **Step 4: Commit**

```bash
git add includes/class-rest-api.php
git commit -m "Add payload_for accessor and forge_pm_item_created action (#29)"
```

---

## Task 3: PHP — connection storage + REST CRUD

**Files:**
- Create: `includes/class-connectors.php`
- Modify: `forge-project-management.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces:
  - `Forge_PM_Connectors::get_all(): array` — full records *including* `authToken`. Internal only.
  - `Forge_PM_Connectors::get( string $id ): ?array` — single record including token. Used by Task 4.
  - `Forge_PM_Connectors::register_routes(): void` — hooked in this task.
  - REST: `GET|POST /forge/v1/connections`, `PUT|DELETE /forge/v1/connections/{id}`.

- [ ] **Step 1: Create `includes/class-connectors.php` with storage + CRUD routes**

```php
<?php
defined( 'ABSPATH' ) || exit;

class Forge_PM_Connectors {

	const NS         = 'forge/v1';
	const OPTION_KEY = 'forge_pm_connections';
	const LOG_KEY    = 'forge_pm_connection_log';
	const LOG_MAX    = 50;

	const ITEM_TYPES = [ 'feature', 'subitem', 'bug', 'feedback', 'release' ];

	// ── Permissions ─────────────────────────────────────────────────────────

	/** Connections hold API credentials — admins only, not Forge Managers. */
	public static function is_admin(): bool {
		return current_user_can( 'manage_options' );
	}

	// ── Storage ─────────────────────────────────────────────────────────────

	/** Full records including authToken. Never send these to a client. */
	public static function get_all(): array {
		$rows = get_option( self::OPTION_KEY, [] );
		return is_array( $rows ) ? $rows : [];
	}

	public static function get( string $id ): ?array {
		foreach ( self::get_all() as $row ) {
			if ( ( $row['id'] ?? '' ) === $id ) return $row;
		}
		return null;
	}

	private static function save_all( array $rows ): void {
		update_option( self::OPTION_KEY, array_values( $rows ) );
	}

	/** Strip the token and add a display hint. Every response passes through this. */
	private static function to_public( array $row ): array {
		$token = $row['authToken'] ?? '';
		unset( $row['authToken'] );
		if ( $token !== '' ) {
			$row['authTokenHint'] = '••••' . substr( $token, -4 );
		}
		return $row;
	}

	// ── Validation ──────────────────────────────────────────────────────────

	/** Returns a WP_Error on invalid input, or null when valid. */
	private static function validate( array $data, bool $is_create ): ?WP_Error {
		if ( $is_create || isset( $data['name'] ) ) {
			if ( trim( (string) ( $data['name'] ?? '' ) ) === '' ) {
				return new WP_Error( 'invalid_name', 'Name is required.', [ 'status' => 400 ] );
			}
		}
		if ( $is_create || isset( $data['url'] ) ) {
			$url = (string) ( $data['url'] ?? '' );
			if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
				return new WP_Error( 'invalid_url', 'A valid URL is required.', [ 'status' => 400 ] );
			}
			if ( stripos( $url, 'https://' ) !== 0 ) {
				return new WP_Error( 'insecure_url', 'Connection URLs must use https://.', [ 'status' => 400 ] );
			}
		}
		if ( isset( $data['itemTypes'] ) ) {
			if ( ! is_array( $data['itemTypes'] ) ) {
				return new WP_Error( 'invalid_item_types', 'itemTypes must be an array.', [ 'status' => 400 ] );
			}
			foreach ( $data['itemTypes'] as $t ) {
				if ( ! in_array( $t, self::ITEM_TYPES, true ) ) {
					return new WP_Error( 'invalid_item_types', 'Unknown item type: ' . $t, [ 'status' => 400 ] );
				}
			}
		}
		return null;
	}

	// ── REST routes ─────────────────────────────────────────────────────────

	public static function register_routes() {
		register_rest_route( self::NS, '/connections', [
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'api_list' ],
				'permission_callback' => [ __CLASS__, 'is_admin' ],
			],
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'api_create' ],
				'permission_callback' => [ __CLASS__, 'is_admin' ],
			],
		] );

		register_rest_route( self::NS, '/connections/(?P<id>[a-z0-9\-]+)', [
			[
				'methods'             => 'PUT',
				'callback'            => [ __CLASS__, 'api_update' ],
				'permission_callback' => [ __CLASS__, 'is_admin' ],
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ __CLASS__, 'api_delete' ],
				'permission_callback' => [ __CLASS__, 'is_admin' ],
			],
		] );
	}

	public static function api_list() {
		return rest_ensure_response( array_map( [ __CLASS__, 'to_public' ], self::get_all() ) );
	}

	public static function api_create( WP_REST_Request $request ) {
		$data = $request->get_json_params() ?: [];

		$error = self::validate( $data, true );
		if ( $error ) return $error;

		$row = [
			'id'        => wp_generate_uuid4(),
			'name'      => sanitize_text_field( $data['name'] ),
			'url'       => esc_url_raw( $data['url'] ),
			'authToken' => (string) ( $data['authToken'] ?? '' ),
			'itemTypes' => array_values( array_intersect( self::ITEM_TYPES, $data['itemTypes'] ?? [] ) ),
			'enabled'   => ! empty( $data['enabled'] ),
			'createdAt' => current_time( 'c', true ),
		];

		$rows   = self::get_all();
		$rows[] = $row;
		self::save_all( $rows );

		return rest_ensure_response( self::to_public( $row ) );
	}

	public static function api_update( WP_REST_Request $request ) {
		$id   = $request->get_param( 'id' );
		$data = $request->get_json_params() ?: [];

		$error = self::validate( $data, false );
		if ( $error ) return $error;

		$rows  = self::get_all();
		$found = false;

		foreach ( $rows as &$row ) {
			if ( ( $row['id'] ?? '' ) !== $id ) continue;
			$found = true;

			if ( isset( $data['name'] ) )      $row['name']      = sanitize_text_field( $data['name'] );
			if ( isset( $data['url'] ) )       $row['url']       = esc_url_raw( $data['url'] );
			if ( isset( $data['itemTypes'] ) ) $row['itemTypes'] = array_values( array_intersect( self::ITEM_TYPES, $data['itemTypes'] ) );
			if ( isset( $data['enabled'] ) )   $row['enabled']   = (bool) $data['enabled'];

			// A blank/absent authToken means "leave unchanged" — never wipe a
			// stored token just because the UI sent an empty masked field.
			if ( ! empty( $data['authToken'] ) ) {
				$row['authToken'] = (string) $data['authToken'];
			}
			break;
		}
		unset( $row );

		if ( ! $found ) {
			return new WP_Error( 'not_found', 'Connection not found.', [ 'status' => 404 ] );
		}

		self::save_all( $rows );
		return rest_ensure_response( self::to_public( self::get( $id ) ) );
	}

	public static function api_delete( WP_REST_Request $request ) {
		$id   = $request->get_param( 'id' );
		$rows = self::get_all();
		$next = array_filter( $rows, fn( $r ) => ( $r['id'] ?? '' ) !== $id );

		if ( count( $next ) === count( $rows ) ) {
			return new WP_Error( 'not_found', 'Connection not found.', [ 'status' => 404 ] );
		}

		self::save_all( $next );
		return rest_ensure_response( [ 'success' => true ] );
	}
}
```

- [ ] **Step 2: Wire the class into the plugin**

In `forge-project-management.php`, add the require after the existing `class-settings.php` require:

```php
require_once FORGE_PM_DIR . 'includes/class-connectors.php';
```

And add the route registration next to the other `rest_api_init` hooks:

```php
add_action( 'rest_api_init',      [ 'Forge_PM_Connectors',      'register_routes' ] );
```

- [ ] **Step 3: Syntax check**

Run: `php -l includes/class-connectors.php && php -l forge-project-management.php`
Expected: `No syntax errors detected` for both. Skip if `php` is not on PATH.

- [ ] **Step 4: Commit**

```bash
git add includes/class-connectors.php forge-project-management.php
git commit -m "Add connection storage and admin-only REST CRUD (#29)"
```

---

## Task 4: PHP — async delivery, retry, and logging

**Files:**
- Modify: `includes/class-connectors.php`
- Modify: `forge-project-management.php`

**Interfaces:**
- Consumes: `Forge_PM_REST_API::payload_for( int $post_id ): ?array` (Task 2); action `forge_pm_item_created` (Task 2); `Forge_PM_Connectors::get()` / `get_all()` (Task 3).
- Produces:
  - `Forge_PM_Connectors::on_item_created( string $type, int $post_id ): void` — hooked to `forge_pm_item_created`.
  - `Forge_PM_Connectors::deliver( string $conn_id, string $type, int $post_id, int $attempt ): void` — hooked to `forge_pm_push_item`. `$attempt` is 1-based, max 4.
  - `Forge_PM_Connectors::build_envelope( string $event, array $item ): array` — used by Task 5.
  - `Forge_PM_Connectors::send( array $row, array $envelope, string $idem_key ): array` — returns `[ 'ok' => bool, 'code' => int, 'error' => string ]`. Used by Task 5.
  - `Forge_PM_Connectors::log( array $entry ): void`.

- [ ] **Step 1: Add the retry schedule constant**

In `includes/class-connectors.php`, add below the existing `const ITEM_TYPES` line:

```php
	/** Delay in seconds before attempts 2, 3, and 4. Attempt 1 is immediate. */
	const RETRY_DELAYS = [ 60, 300, 1800 ];
	/** 4 total: one immediate, then one per RETRY_DELAYS entry. */
	const MAX_ATTEMPTS = 4;
	const TIMEOUT      = 10;
```

- [ ] **Step 2: Add the trigger, envelope, send, deliver, and log methods**

Append these methods inside the `Forge_PM_Connectors` class, before the closing `}`:

```php
	// ── Trigger ─────────────────────────────────────────────────────────────

	/**
	 * Schedules a push for every enabled connection subscribed to $type.
	 * Never throws — item creation must not fail because of a connector.
	 */
	public static function on_item_created( string $type, int $post_id ): void {
		foreach ( self::get_all() as $row ) {
			if ( empty( $row['enabled'] ) ) continue;
			if ( ! in_array( $type, (array) ( $row['itemTypes'] ?? [] ), true ) ) continue;

			wp_schedule_single_event(
				time(),
				'forge_pm_push_item',
				[ (string) $row['id'], $type, $post_id, 1 ]
			);
		}
	}

	// ── Delivery ────────────────────────────────────────────────────────────

	public static function build_envelope( string $event, array $item ): array {
		return [
			'source' => 'forge',
			'event'  => $event,
			'sentAt' => current_time( 'c', true ),
			'item'   => $item,
		];
	}

	/** Performs one HTTP POST. Returns [ ok, code, error ]. */
	public static function send( array $row, array $envelope, string $idem_key ): array {
		$headers = [
			'Content-Type'    => 'application/json',
			'Idempotency-Key' => $idem_key,
		];
		if ( ! empty( $row['authToken'] ) ) {
			$headers['Authorization'] = 'Bearer ' . $row['authToken'];
		}

		$res = wp_remote_post( $row['url'], [
			'headers' => $headers,
			'body'    => wp_json_encode( $envelope ),
			'timeout' => self::TIMEOUT,
		] );

		if ( is_wp_error( $res ) ) {
			return [ 'ok' => false, 'code' => 0, 'error' => $res->get_error_message() ];
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$ok   = $code >= 200 && $code < 300;

		return [ 'ok' => $ok, 'code' => $code, 'error' => $ok ? '' : 'HTTP ' . $code ];
	}

	/**
	 * Cron handler. Delivers one item to one connection, rescheduling on failure.
	 */
	public static function deliver( string $conn_id, string $type, int $post_id, int $attempt ): void {
		$row = self::get( $conn_id );

		// Connection deleted or disabled mid-retry — exit quietly, no log entry.
		if ( ! $row || empty( $row['enabled'] ) ) return;

		$item = Forge_PM_REST_API::payload_for( $post_id );
		if ( ! $item ) return; // Item deleted before delivery — nothing to send.

		$result = self::send(
			$row,
			self::build_envelope( 'item.created', $item ),
			'forge:' . $type . ':' . $post_id
		);

		$is_last = $attempt >= self::MAX_ATTEMPTS;

		self::log( [
			'connectionId' => $conn_id,
			'itemType'     => $type,
			'itemId'       => (string) $post_id,
			'status'       => $result['ok'] ? 'success' : ( $is_last ? 'failed' : 'retrying' ),
			'httpCode'     => $result['code'],
			'error'        => $result['error'],
			'attempt'      => $attempt,
		] );

		if ( $result['ok'] || $is_last ) return;

		$delay = self::RETRY_DELAYS[ $attempt - 1 ] ?? end( self::RETRY_DELAYS );
		wp_schedule_single_event(
			time() + $delay,
			'forge_pm_push_item',
			[ $conn_id, $type, $post_id, $attempt + 1 ]
		);
	}

	// ── Log ─────────────────────────────────────────────────────────────────

	/** Prepends an entry and trims to LOG_MAX. Most recent first. */
	public static function log( array $entry ): void {
		$rows = get_option( self::LOG_KEY, [] );
		if ( ! is_array( $rows ) ) $rows = [];

		array_unshift( $rows, array_merge( [
			'id'        => wp_generate_uuid4(),
			'timestamp' => current_time( 'c', true ),
		], $entry ) );

		update_option( self::LOG_KEY, array_slice( $rows, 0, self::LOG_MAX ) );
	}

	public static function api_log() {
		$rows = get_option( self::LOG_KEY, [] );
		return rest_ensure_response( is_array( $rows ) ? $rows : [] );
	}
```

- [ ] **Step 3: Register the log route**

In `register_routes()`, add before the closing `}` of the method:

```php
		register_rest_route( self::NS, '/connections/log', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'api_log' ],
			'permission_callback' => [ __CLASS__, 'is_admin' ],
		] );
```

**Important:** register this **before** the `/connections/(?P<id>...)` route in the method body. WordPress matches routes in registration order, and `log` would otherwise be captured by the `[a-z0-9\-]+` id pattern.

- [ ] **Step 4: Hook the trigger and cron handler**

In `forge-project-management.php`, add next to the other `add_action` calls:

```php
add_action( 'forge_pm_item_created', [ 'Forge_PM_Connectors', 'on_item_created' ], 10, 2 );
add_action( 'forge_pm_push_item',    [ 'Forge_PM_Connectors', 'deliver' ],         10, 4 );
```

These must be registered on every request, not inside `rest_api_init` — WP-Cron fires outside the REST context and would not see the handler otherwise.

- [ ] **Step 5: Syntax check**

Run: `php -l includes/class-connectors.php && php -l forge-project-management.php`
Expected: `No syntax errors detected` for both. Skip if `php` is not on PATH.

- [ ] **Step 6: Commit**

```bash
git add includes/class-connectors.php forge-project-management.php
git commit -m "Add async connector delivery with retry and capped log (#29)"
```

---

## Task 5: PHP — the `/test` endpoint

**Files:**
- Modify: `includes/class-connectors.php`

**Interfaces:**
- Consumes: `build_envelope()`, `send()` (Task 4).
- Produces: `POST /forge/v1/connections/{id}/test` → `{ success: bool, httpCode?: int, error?: string }`, matching the `ConnectionTestResult` type from Task 1.

- [ ] **Step 1: Add the test route**

In `register_routes()`, add alongside the log route (order relative to the `{id}` route does not matter here — the path has a distinct `/test` suffix):

```php
		register_rest_route( self::NS, '/connections/(?P<id>[a-z0-9\-]+)/test', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'api_test' ],
			'permission_callback' => [ __CLASS__, 'is_admin' ],
		] );
```

- [ ] **Step 2: Add the handler**

Append inside the class, before the closing `}`:

```php
	/**
	 * Sends a sample payload synchronously so the admin gets immediate feedback.
	 * This is the one intentional exception to async delivery. It is NOT logged —
	 * the activity log is for real item deliveries only.
	 */
	public static function api_test( WP_REST_Request $request ) {
		$row = self::get( $request->get_param( 'id' ) );
		if ( ! $row ) {
			return new WP_Error( 'not_found', 'Connection not found.', [ 'status' => 404 ] );
		}

		$sample = [
			'type'          => 'feature',
			'id'            => '0',
			'name'          => 'Forge test payload',
			'description'   => 'This is a test delivery from Forge Project Management.',
			'workflowStage' => 'triage',
			'timeEstimate'  => 0,
			'links'         => [],
		];

		$result = self::send(
			$row,
			self::build_envelope( 'item.test', $sample ),
			'forge:test:' . $row['id']
		);

		return rest_ensure_response( [
			'success'  => $result['ok'],
			'httpCode' => $result['code'],
			'error'    => $result['error'],
		] );
	}
```

- [ ] **Step 3: Syntax check**

Run: `php -l includes/class-connectors.php`
Expected: `No syntax errors detected`. Skip if `php` is not on PATH.

- [ ] **Step 4: Commit**

```bash
git add includes/class-connectors.php
git commit -m "Add synchronous connection test endpoint (#29)"
```

---

## Task 6: Frontend API layer + standalone mock

**Files:**
- Modify: `src/app/api/wordpress.ts`
- Modify: `src/app/api/mockBackend.ts`

**Interfaces:**
- Consumes: `Connection`, `ConnectionDelivery`, `ConnectionTestResult` (Task 1); REST routes (Tasks 3–5).
- Produces, all used by Task 7:
  - `fetchConnections(): Promise<Connection[]>`
  - `createConnection( data: Partial<Connection> ): Promise<Connection>`
  - `updateConnection( id: string, data: Partial<Connection> ): Promise<Connection>`
  - `deleteConnection( id: string ): Promise<{ success: boolean }>`
  - `testConnection( id: string ): Promise<ConnectionTestResult>`
  - `fetchConnectionLog(): Promise<ConnectionDelivery[]>`

- [ ] **Step 1: Add mock-backend stubs**

In `src/app/api/mockBackend.ts`, append. Follow the module's existing in-memory pattern:

```ts
// ── Connections (standalone dev only) ────────────────────────────────────────

let mockConnections: Connection[] = [];
let mockConnectionLog: ConnectionDelivery[] = [];

function uuid(): string {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace( /[xy]/g, c => {
    const r = Math.random() * 16 | 0;
    return ( c === 'x' ? r : ( r & 0x3 | 0x8 ) ).toString( 16 );
  } );
}

/** Strips authToken exactly as the real API does, so the UI is exercised faithfully. */
function toPublicConnection( c: Connection ): Connection {
  const { authToken, ...rest } = c;
  return authToken ? { ...rest, authTokenHint: '••••' + authToken.slice( -4 ) } : rest;
}

export async function fetchConnections(): Promise<Connection[]> {
  return mockConnections.map( toPublicConnection );
}

export async function createConnection( data: Partial<Connection> ): Promise<Connection> {
  const row: Connection = {
    id:        uuid(),
    name:      data.name ?? '',
    url:       data.url ?? '',
    authToken: data.authToken ?? '',
    itemTypes: data.itemTypes ?? [],
    enabled:   data.enabled ?? false,
    createdAt: new Date().toISOString(),
  };
  mockConnections = [ ...mockConnections, row ];
  return toPublicConnection( row );
}

export async function updateConnection( id: string, data: Partial<Connection> ): Promise<Connection> {
  mockConnections = mockConnections.map( c => {
    if ( c.id !== id ) return c;
    const next = { ...c, ...data };
    // Blank token means "leave unchanged", matching the PHP behaviour.
    if ( ! data.authToken ) next.authToken = c.authToken;
    return next;
  } );
  const found = mockConnections.find( c => c.id === id );
  if ( ! found ) throw new Error( 'Connection not found' );
  return toPublicConnection( found );
}

export async function deleteConnection( id: string ): Promise<{ success: boolean }> {
  mockConnections = mockConnections.filter( c => c.id !== id );
  return { success: true };
}

/** Simulated — standalone dev never makes real outbound calls. */
export async function testConnection( _id: string ): Promise<ConnectionTestResult> {
  return { success: true, httpCode: 200 };
}

export async function fetchConnectionLog(): Promise<ConnectionDelivery[]> {
  return mockConnectionLog;
}
```

Add `Connection`, `ConnectionDelivery`, and `ConnectionTestResult` to this file's existing import from `../types`.

**Note:** `mockConnectionLog` is intentionally never written to — standalone mode simulates delivery rather than performing it, so there are no real attempts to record. The empty Activity list is the correct standalone behaviour. Declare it `let` (not `const`) so the export shape matches the live path.

- [ ] **Step 2: Add the real API functions**

In `src/app/api/wordpress.ts`, append at the end of the file:

```ts
// ── Connections ─────────────────────────────────────────────────────────────

export function fetchConnections(): Promise<Connection[]> {
  if ( isStandalone() ) return mock.fetchConnections();
  return apiFetch<Connection[]>( '/connections' );
}

export function createConnection( data: Partial<Connection> ): Promise<Connection> {
  if ( isStandalone() ) return mock.createConnection( data );
  return apiFetch<Connection>( '/connections', {
    method: 'POST',
    body: JSON.stringify( data ),
  } );
}

export function updateConnection( id: string, data: Partial<Connection> ): Promise<Connection> {
  if ( isStandalone() ) return mock.updateConnection( id, data );
  return apiFetch<Connection>( `/connections/${ id }`, {
    method: 'PUT',
    body: JSON.stringify( data ),
  } );
}

export function deleteConnection( id: string ): Promise<{ success: boolean }> {
  if ( isStandalone() ) return mock.deleteConnection( id );
  return apiFetch( `/connections/${ id }`, { method: 'DELETE' } );
}

export function testConnection( id: string ): Promise<ConnectionTestResult> {
  if ( isStandalone() ) return mock.testConnection( id );
  return apiFetch<ConnectionTestResult>( `/connections/${ id }/test`, { method: 'POST' } );
}

export function fetchConnectionLog(): Promise<ConnectionDelivery[]> {
  if ( isStandalone() ) return mock.fetchConnectionLog();
  return apiFetch<ConnectionDelivery[]>( '/connections/log' );
}
```

Add `Connection`, `ConnectionDelivery`, and `ConnectionTestResult` to the existing `../types` import on line 1.

- [ ] **Step 3: Verify**

Run: `npm run build && npm run lint`
Expected: both succeed.

- [ ] **Step 4: Commit**

```bash
git add src/app/api/wordpress.ts src/app/api/mockBackend.ts
git commit -m "Add connection API layer and standalone mocks (#29)"
```

---

## Task 7: The Connections settings panel

**Files:**
- Create: `src/app/components/settings/ConnectionsSection.tsx`

**Interfaces:**
- Consumes: API functions from Task 6; `Connection`, `ConnectionDelivery` from Task 1.
- Produces: `export default function ConnectionsSection()` — no props. Mounted by Task 8.

**Note on styling:** `Settings.tsx` uses inline `style={{}}` objects, not Tailwind classes. Match that. `Card`, `inputStyle`, `useFeedback`, and `SaveFeedback` are module-private to `Settings.tsx` and are **not** exported — this task deliberately defines its own local equivalents rather than refactoring `Settings.tsx` to export them, which would be out-of-scope churn. Keep them visually identical.

- [ ] **Step 1: Create the component**

```tsx
import React, { useState, useEffect, useCallback } from 'react';
import { Plus, X, Check, AlertCircle, Loader2, Send, Trash2 } from 'lucide-react';
import { Connection, ConnectionDelivery, ItemType } from '../../types';
import {
  fetchConnections, createConnection, updateConnection,
  deleteConnection, testConnection, fetchConnectionLog,
} from '../../api/wordpress';

const ITEM_TYPES: ItemType[] = [ 'feature', 'subitem', 'bug', 'feedback', 'release' ];

const TYPE_LABEL: Record<ItemType, string> = {
  feature:  'Feature',
  subitem:  'Sub-item',
  bug:      'Bug',
  feedback: 'Feedback',
  release:  'Release',
};

const inputStyle: React.CSSProperties = {
  width: '100%', padding: '7px 10px', borderRadius: 6,
  border: '1px solid #e2e8f0', fontSize: 14, color: '#1a1f36',
  outline: 'none', background: '#fff', boxSizing: 'border-box',
};

function Card( { children, style }: { children: React.ReactNode; style?: React.CSSProperties } ) {
  return <div style={{ background:'#fff',border:'1px solid #e2e8f0',borderRadius:8,padding:20,...style }}>{ children }</div>;
}

interface FormState {
  id?: string;
  name: string;
  url: string;
  authToken: string;
  itemTypes: ItemType[];
  enabled: boolean;
  hasToken: boolean;
}

const EMPTY_FORM: FormState = {
  name: '', url: '', authToken: '', itemTypes: [], enabled: true, hasToken: false,
};

export default function ConnectionsSection() {
  const [ connections, setConnections ] = useState<Connection[]>( [] );
  const [ log, setLog ]                 = useState<ConnectionDelivery[]>( [] );
  const [ loading, setLoading ]         = useState( true );
  const [ form, setForm ]               = useState<FormState | null>( null );
  const [ saving, setSaving ]           = useState( false );
  const [ error, setError ]             = useState<string | null>( null );
  const [ testing, setTesting ]         = useState<string | null>( null );
  const [ testResult, setTestResult ]   = useState<Record<string, string>>( {} );

  const load = useCallback( async () => {
    setLoading( true );
    try {
      const [ conns, entries ] = await Promise.all( [ fetchConnections(), fetchConnectionLog() ] );
      setConnections( conns );
      setLog( entries );
    } catch ( e ) {
      setError( e instanceof Error ? e.message : 'Failed to load connections' );
    }
    setLoading( false );
  }, [] );

  useEffect( () => { void load(); }, [ load ] );

  const save = async () => {
    if ( ! form ) return;
    setSaving( true );
    setError( null );
    try {
      // Omit authToken entirely when blank so the server leaves it unchanged.
      const payload: Partial<Connection> = {
        name: form.name,
        url: form.url,
        itemTypes: form.itemTypes,
        enabled: form.enabled,
      };
      if ( form.authToken ) payload.authToken = form.authToken;

      if ( form.id ) await updateConnection( form.id, payload );
      else           await createConnection( payload );

      setForm( null );
      await load();
    } catch ( e ) {
      setError( e instanceof Error ? e.message : 'Save failed' );
    }
    setSaving( false );
  };

  const remove = async ( id: string ) => {
    if ( ! window.confirm( 'Delete this connection? Items will stop being pushed to it.' ) ) return;
    try {
      await deleteConnection( id );
      await load();
    } catch ( e ) {
      setError( e instanceof Error ? e.message : 'Delete failed' );
    }
  };

  const runTest = async ( id: string ) => {
    setTesting( id );
    try {
      const r = await testConnection( id );
      setTestResult( prev => ( {
        ...prev,
        [ id ]: r.success ? `Success (${ r.httpCode })` : `Failed: ${ r.error || r.httpCode }`,
      } ) );
    } catch ( e ) {
      setTestResult( prev => ( { ...prev, [ id ]: e instanceof Error ? e.message : 'Test failed' } ) );
    }
    setTesting( null );
    void load();
  };

  const toggleType = ( t: ItemType ) => {
    if ( ! form ) return;
    setForm( {
      ...form,
      itemTypes: form.itemTypes.includes( t )
        ? form.itemTypes.filter( x => x !== t )
        : [ ...form.itemTypes, t ],
    } );
  };

  if ( loading ) {
    return <Card><Loader2 size={16} className="animate-spin" /> Loading connections…</Card>;
  }

  return (
    <div style={{ display:'flex', flexDirection:'column', gap:16 }}>
      <Card>
        <div style={{ display:'flex', justifyContent:'space-between', alignItems:'center', marginBottom:4 }}>
          <h3 style={{ margin:0, fontSize:15, color:'#1a1f36' }}>Connections</h3>
          { ! form && (
            <button onClick={ () => setForm( EMPTY_FORM ) }
              style={{ display:'inline-flex',alignItems:'center',gap:6,padding:'6px 12px',borderRadius:6,border:'1px solid #e2e8f0',background:'#fff',fontSize:13,cursor:'pointer' }}>
              <Plus size={14} /> Add connection
            </button>
          ) }
        </div>
        <p style={{ margin:'0 0 16px', fontSize:13, color:'#64748b' }}>
          When a new item is created, Forge sends its full details — including the description — to each
          enabled connection below.
        </p>

        { error && (
          <div style={{ display:'flex',alignItems:'center',gap:6,padding:'8px 10px',marginBottom:12,borderRadius:6,background:'#fff1f2',color:'#e11d48',fontSize:13 }}>
            <AlertCircle size={14} /> { error }
          </div>
        ) }

        { connections.length === 0 && ! form && (
          <p style={{ margin:0, fontSize:13, color:'#94a3b8' }}>No connections yet.</p>
        ) }

        { connections.map( c => (
          <div key={ c.id } style={{ display:'flex',alignItems:'center',gap:12,padding:'10px 0',borderTop:'1px solid #f1f5f9' }}>
            <div style={{ flex:1, minWidth:0 }}>
              <div style={{ fontSize:14, color:'#1a1f36' }}>
                { c.name }
                { ! c.enabled && <span style={{ marginLeft:8,fontSize:11,color:'#94a3b8' }}>Disabled</span> }
              </div>
              <div style={{ fontSize:12, color:'#64748b', overflow:'hidden', textOverflow:'ellipsis', whiteSpace:'nowrap' }}>
                { c.url }
              </div>
              <div style={{ display:'flex', gap:4, marginTop:4, flexWrap:'wrap' }}>
                { c.itemTypes.map( t => (
                  <span key={ t } style={{ fontSize:11,padding:'1px 6px',borderRadius:4,background:'#f1f5f9',color:'#475569' }}>
                    { TYPE_LABEL[ t ] }
                  </span>
                ) ) }
              </div>
              { testResult[ c.id ] && (
                <div style={{ fontSize:12, marginTop:4, color: testResult[ c.id ].startsWith( 'Success' ) ? '#16a34a' : '#e11d48' }}>
                  { testResult[ c.id ] }
                </div>
              ) }
            </div>
            <button onClick={ () => void runTest( c.id ) } disabled={ testing === c.id }
              style={{ display:'inline-flex',alignItems:'center',gap:4,padding:'5px 10px',borderRadius:6,border:'1px solid #e2e8f0',background:'#fff',fontSize:12,cursor:'pointer' }}>
              { testing === c.id ? <Loader2 size={12} /> : <Send size={12} /> } Test
            </button>
            <button onClick={ () => setForm( {
              id: c.id, name: c.name, url: c.url, authToken: '',
              itemTypes: c.itemTypes, enabled: c.enabled, hasToken: !! c.authTokenHint,
            } ) }
              style={{ padding:'5px 10px',borderRadius:6,border:'1px solid #e2e8f0',background:'#fff',fontSize:12,cursor:'pointer' }}>
              Edit
            </button>
            <button onClick={ () => void remove( c.id ) } aria-label="Delete connection"
              style={{ padding:5,borderRadius:6,border:'1px solid #e2e8f0',background:'#fff',cursor:'pointer',color:'#e11d48' }}>
              <Trash2 size={12} />
            </button>
          </div>
        ) ) }
      </Card>

      { form && (
        <Card>
          <div style={{ display:'flex', justifyContent:'space-between', alignItems:'center', marginBottom:12 }}>
            <h3 style={{ margin:0, fontSize:15, color:'#1a1f36' }}>{ form.id ? 'Edit' : 'New' } connection</h3>
            <button onClick={ () => setForm( null ) } aria-label="Close"
              style={{ border:'none', background:'none', cursor:'pointer', color:'#64748b' }}>
              <X size={16} />
            </button>
          </div>

          <div style={{ display:'flex', flexDirection:'column', gap:12 }}>
            <label style={{ fontSize:13, color:'#475569' }}>
              Name
              <input style={ inputStyle } value={ form.name }
                onChange={ e => setForm( { ...form, name: e.target.value } ) }
                placeholder="Foundry" />
            </label>

            <label style={{ fontSize:13, color:'#475569' }}>
              Endpoint URL
              <input style={ inputStyle } value={ form.url }
                onChange={ e => setForm( { ...form, url: e.target.value } ) }
                placeholder="https://example.com/api/ingest" />
              <span style={{ fontSize:12, color:'#94a3b8' }}>Must start with https://</span>
            </label>

            <label style={{ fontSize:13, color:'#475569' }}>
              Bearer token
              <input style={ inputStyle } type="password" value={ form.authToken }
                onChange={ e => setForm( { ...form, authToken: e.target.value } ) }
                placeholder={ form.hasToken ? 'Saved — leave blank to keep' : 'Optional' } />
            </label>

            <div style={{ fontSize:13, color:'#475569' }}>
              Push on creation of
              <div style={{ display:'flex', gap:12, flexWrap:'wrap', marginTop:6 }}>
                { ITEM_TYPES.map( t => (
                  <label key={ t } style={{ display:'inline-flex', alignItems:'center', gap:5, fontSize:13 }}>
                    <input type="checkbox" checked={ form.itemTypes.includes( t ) }
                      onChange={ () => toggleType( t ) } />
                    { TYPE_LABEL[ t ] }
                  </label>
                ) ) }
              </div>
            </div>

            <label style={{ display:'inline-flex', alignItems:'center', gap:6, fontSize:13, color:'#475569' }}>
              <input type="checkbox" checked={ form.enabled }
                onChange={ e => setForm( { ...form, enabled: e.target.checked } ) } />
              Enabled
            </label>

            <div style={{ display:'flex', gap:8 }}>
              <button onClick={ () => void save() } disabled={ saving }
                style={{ display:'inline-flex',alignItems:'center',gap:6,padding:'7px 14px',borderRadius:6,border:'none',background:'#1a1f36',color:'#fff',fontSize:13,cursor:'pointer' }}>
                { saving ? <Loader2 size={13} /> : <Check size={13} /> } Save
              </button>
              <button onClick={ () => setForm( null ) }
                style={{ padding:'7px 14px',borderRadius:6,border:'1px solid #e2e8f0',background:'#fff',fontSize:13,cursor:'pointer' }}>
                Cancel
              </button>
            </div>
          </div>
        </Card>
      ) }

      <Card>
        <h3 style={{ margin:'0 0 12px', fontSize:15, color:'#1a1f36' }}>Activity</h3>
        { log.length === 0 && (
          <p style={{ margin:0, fontSize:13, color:'#94a3b8' }}>No deliveries yet.</p>
        ) }
        { log.map( d => {
          const conn = connections.find( c => c.id === d.connectionId );
          const color = d.status === 'success' ? '#16a34a' : d.status === 'retrying' ? '#d97706' : '#e11d48';
          return (
            <div key={ d.id } style={{ display:'flex',alignItems:'center',gap:10,padding:'7px 0',borderTop:'1px solid #f1f5f9',fontSize:13 }}>
              <span style={{ color, fontSize:11, textTransform:'uppercase', width:64 }}>{ d.status }</span>
              <span style={{ flex:1, color:'#475569' }}>
                { conn?.name ?? 'Deleted connection' } · { d.itemType } #{ d.itemId }
                { d.attempt > 1 && <span style={{ color:'#94a3b8' }}> · attempt { d.attempt }</span> }
              </span>
              <span style={{ color:'#94a3b8', fontSize:12 }}>
                { d.error ? d.error : d.httpCode }
              </span>
            </div>
          );
        } ) }
      </Card>
    </div>
  );
}
```

- [ ] **Step 2: Verify**

Run: `npm run build && npm run lint`
Expected: both succeed. The component is not yet reachable in the UI — Task 8 mounts it.

- [ ] **Step 3: Commit**

```bash
git add src/app/components/settings/ConnectionsSection.tsx
git commit -m "Add Connections settings panel (#29)"
```

---

## Task 8: Mount the section in Settings

**Files:**
- Modify: `src/app/components/Settings.tsx`

**Interfaces:**
- Consumes: `ConnectionsSection` default export (Task 7); existing `isAdmin()` from `../api/wordpress`.
- Produces: a reachable `connections` section, visible to admins only.

- [ ] **Step 1: Import the component and the icon**

Add `Plug` to the existing `lucide-react` import list at the top of `Settings.tsx`, and add:

```tsx
import ConnectionsSection from './settings/ConnectionsSection';
```

`isAdmin` is already imported from `../api/wordpress` on line 17 — do not re-import it.

- [ ] **Step 2: Extend the `Section` union**

Change line 27 to include `'connections'`:

```tsx
type Section = 'config' | 'statuses' | 'brands' | 'categories' | 'releases' | 'connections' | 'archive' | 'export';
```

- [ ] **Step 3: Register in `SECTION_NAV`, admin-gated**

`SECTION_NAV` is a module-level `const`, so it cannot read `isAdmin()` per-render safely if the value changes. `isAdmin()` reads from `window.forgePMData`, which is fixed for the page lifetime, so a module-level filter is correct and matches how the rest of the file treats it.

Replace the `SECTION_NAV` declaration (lines 30–38) with:

```tsx
// Config first · alphabetical middle · Archive/Export always last
const SECTION_NAV: { id: Section; label: string; Icon: React.ComponentType<{ size?: number }> }[] = [
  { id: 'config',      label: 'Config',      Icon: SettingsIcon },
  { id: 'brands',      label: 'Brands',      Icon: Briefcase    },
  { id: 'categories',  label: 'Categories',  Icon: Tag          },
  // Connections hold API credentials — admins only.
  ...( isAdmin() ? [ { id: 'connections' as const, label: 'Connections', Icon: Plug } ] : [] ),
  { id: 'releases',    label: 'Releases',    Icon: PackageOpen  },
  { id: 'statuses',    label: 'Statuses',    Icon: ListOrdered  },
  { id: 'archive',     label: 'Archive',     Icon: ArchiveIcon  },
  { id: 'export',      label: 'Export',      Icon: Download     },
];
```

- [ ] **Step 4: Render the section**

Find the block that switches on the active section (where `ConfigSection`, `StatusesSection`, etc. are rendered conditionally). Add, following the exact style of the neighbouring lines:

```tsx
{ section === 'connections' && isAdmin() && <ConnectionsSection /> }
```

The redundant-looking `isAdmin()` guard is deliberate: it prevents the panel rendering if the section is ever reached via restored URL/tab state rather than the nav.

- [ ] **Step 5: Verify**

Run: `npm run build && npm run lint`
Expected: both succeed.

- [ ] **Step 6: Commit**

```bash
git add src/app/components/Settings.tsx
git commit -m "Register Connections section in Settings nav (#29)"
```

---

## Task 9: Version bump, verification, and zip

**Files:**
- Modify: `forge-project-management.php`
- Modify: `package.json`

**Interfaces:**
- Consumes: everything from Tasks 1–8.
- Produces: the deployable artifact.

- [ ] **Step 1: Bump the version**

This is a new feature, so it is a **minor** bump: `1.36.0` → `1.37.0`. Three places must stay in sync:

- `forge-project-management.php` line 6: ` * Version:     1.37.0`
- `forge-project-management.php` line 14: `define( 'FORGE_PM_VERSION',  '1.37.0' );`
- `package.json` line 4: `"version": "1.37.0",`

- [ ] **Step 2: Run the required checks**

Run: `npm run lint && npm run build`
Expected: both pass.

Per `CLAUDE.md`, do **not** iterate lint → auto-fix → re-lint. Run once; present any findings to the user and let them decide.

- [ ] **Step 3: Manual verification in standalone dev**

Run: `npm run dev` and open `localhost:5173`.

Confirm each of these:

1. **Settings → Connections** appears in the nav (standalone grants admin via `import.meta.env.DEV`).
2. **Add connection** — name `Test`, URL `https://example.com/hook`, tick Feature + Bug, Save. It appears in the list with two type badges.
3. **Validation** — edit it, set the URL to `http://example.com` (plain HTTP). Standalone mock does not validate, so this specific check must be done against live WordPress in Step 4. Note it and move on.
4. **Test button** returns `Success (200)` (simulated in standalone).
5. **Edit** — reopen; the token field shows the `Saved — leave blank to keep` placeholder, not the token itself.
6. **Delete** — confirm prompt appears; the connection disappears.
7. **Activity** shows `No deliveries yet.` — correct for standalone, which simulates rather than delivers.

- [ ] **Step 4: Manual verification against live WordPress**

This is the only way to exercise cron, retry, and real HTTP. Install the built plugin on a WordPress site, then:

1. Create a connection pointing at a request-capture endpoint (e.g. a webhook.site URL), token set, Feature ticked, enabled.
2. Create a **Feature** in Forge. Confirm the request arrives with `event: "item.created"`, an `item.description`, `Authorization: Bearer …`, and `Idempotency-Key: forge:feature:<id>`.
3. Confirm the item saved **instantly** — the UI must not wait on the push.
4. Create a **Bug** (not ticked). Confirm **nothing** is sent.
5. Disable the connection, create a Feature. Confirm nothing is sent.
6. Point the URL at an endpoint returning `500`. Create a Feature. Confirm Activity shows `retrying`, and that it retries rather than giving up. (WP-Cron needs traffic to fire — hit the site to trigger it.)
7. Set the URL to `http://…` and save. Confirm a `400` with "Connection URLs must use https://".
8. **Token leak check** — `curl` the site as a logged-out visitor and grep the page source for the token. Confirm it is absent. Then `GET /wp-json/forge/v1/connections` as admin and confirm the response has `authTokenHint` but **no** `authToken`.
9. **Role check** — log in as a Forge Manager. Confirm the Connections section is not in the nav, and that `GET /wp-json/forge/v1/connections` returns `401`/`403`.

- [ ] **Step 5: Visual confirmation by the user**

Per `CLAUDE.md` step 9: with `npm run dev` running, ask the user to confirm the Connections panel in the browser. **Wait for their confirmation before committing, pushing, or opening the PR.**

- [ ] **Step 6: Build the zip**

Run: `npm run zip`
Expected: `forge-project-management.zip` is written **one level above** the repo folder, containing only runtime files.

- [ ] **Step 7: Commit**

```bash
git add forge-project-management.php package.json
git commit -m "Bump version to 1.37.0 for external API connectors (#29)"
```

- [ ] **Step 8: Open a draft PR**

Push the branch and open a **draft** PR linked to issue #29. Per `CLAUDE.md`, the description must include: issue link, summary of changes, files changed, test results, review notes, and anything still requiring human review.

Under "requires human review", state explicitly:

- **Foundry cannot receive a push today** — it has no ingest endpoint. This PR delivers the generic framework; Foundry becomes a config row once an endpoint exists. Its documented base URL `foundry.gitwork.co` does not resolve; the live host is `foundry.gitwork.co.uk`.
- **Payloads are not sanitised by `strip_sensitive()`** — full item content, including `description`, is sent to admin-configured destinations by design. Confirm this matches expectations before merge.
- **WP-Cron dependency** — sites with `DISABLE_WP_CRON` and no system cron will delay delivery until the next cron run.

**Never auto-merge.** Leave the PR for human review.

---

## Notes for the implementer

- **Do not put connections in `forge_pm_settings`.** It is injected into page HTML; a token there is world-readable. This is the single most important constraint in this plan.
- **Route order matters** in `register_routes()`: `/connections/log` must be registered before `/connections/(?P<id>…)`, or `log` is swallowed by the id pattern.
- **`add_action` for cron must be top-level**, not inside `rest_api_init` — WP-Cron fires outside the REST context.
- **A blank `authToken` means "leave unchanged"**, in both PHP and the mock. Never wipe a stored token because the UI sent an empty masked field.
- The Foundry investigation is recorded in the spec's Context section — read it before adding any Foundry-specific mapping.
