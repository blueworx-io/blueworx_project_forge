# Availability Screen in the App — Implementation Plan (PR 1 of 7)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The Availability screen — a person's working week and time off — becomes a screen in the React studio app, reached from the rail, backed by REST; the WordPress admin page stays untouched until PR 7.

**Architecture:** A new `Rest\AvailabilityController` exposes four routes over the existing `Capacity\Patterns`, `Capacity\Unavailability` and `Capacity\Availability` classes (no domain code changes). A new `AvailabilityScreen` React component, registered in `App.tsx` under a new "Team" rail group, reads and writes those routes. The four Playwright specs that set hours through the admin page switch to a shared REST helper so the eventual cutover has nothing left to move.

**Tech Stack:** PHP 7.4+ (WordPress REST API, WPCS), React 18 + TypeScript (Vite), the shared kit in `src/kit/`, Playwright against the local WordPress harness.

**Spec:** `docs/superpowers/specs/2026-09-16-admin-screens-to-app-design.md` — sections "What every PR does" and "PR 1 — Availability".

## Global Constraints

- Branch off `main`, never work on `main`. Branch name: `availability-in-app`.
- Version: `2.107.0` → `2.108.0` in `package.json`, `blueworx-forge.php` (header line 6 and `BWX_FORGE_VERSION` line 28), `client/blueworx-forge-client.php` (header line 6 and `BWX_FORGE_CLIENT_VERSION` line 42). All five must agree or CI fails.
- Changelog entry in `CHANGELOG.md` under a new `## [2.108.0] - <today>` heading, written for the person using it.
- No new dependencies (`approved-deps.json` is closed).
- Nothing under `client/` changes except the version lines.
- Every route registers through `Server::register_route()` with a `scope` array carrying `kind` and `reason`, or `Boundary::apply()` refuses it.
- Every write route uses `Permissions::manage` (administrator only). The GET does too: the admin page requires `manage_options` and the spec says permissions do not change.
- Every interactive element in the screen carries a `data-testid` starting `bwx-`.
- Nothing styled outside the kit; screen-specific layout classes go in `src/styles.css` next to `.bwx-recurring` (line ~1401).
- PHP: tabs, WPCS, `declare( strict_types = 1 )`, `final class`, docblocks on every method. Run `composer lint` before each PHP commit.
- TS: match the surrounding style (spaces inside parens and braces, single quotes). Run `npm run lint` once at the end, not in a loop.
- The admin page (`includes/Admin/AvailabilityScreen.php`, `AvailabilityActions.php`) and its spec (`tests/e2e/availability-screen.spec.js`) are NOT touched.
- The local harness: `npm run wp:up` once (WordPress on http://127.0.0.1:8892, `admin`/`admin`); the studio site links this repo, so a `npm run build` is live immediately. Never build while a Playwright run is in progress.
- Playwright specs create everything they need with a run id; the instance is reused between runs.

---

## File map

| File | Responsibility |
|------|----------------|
| `includes/Rest/AvailabilityController.php` (create) | Four routes; shapes the answer; validates dates; calls Capacity classes |
| `includes/Rest/Server.php` (modify, ~line 93) | Registers the controller |
| `tests/e2e/availability-rest.spec.js` (create) | Route behaviour |
| `tests/e2e/helpers/forge.js` (modify) | `setHours()` helper over REST |
| `tests/e2e/capacity-gate.spec.js`, `capacity-rest.spec.js`, `support-hours-gate.spec.js`, `tests/pair/acceptance-capacity.spec.js` (modify) | Use the helper instead of the admin page |
| `src/types.ts` (modify) | `ScreenName` gains `'availability'`; new answer types |
| `src/App.tsx` (modify) | Rail group "Team", title, opening, mount, hash landing |
| `src/components/AvailabilityScreen.tsx` (create) | The screen |
| `src/styles.css` (modify) | Layout for the week grid and toolbar |
| `tests/e2e/availability-app.spec.js` (create) | Screen behaviour |
| `package.json`, `blueworx-forge.php`, `client/blueworx-forge-client.php`, `CHANGELOG.md` (modify) | Version and changelog |

---

### Task 1: Branch, and the read route

**Files:**
- Create: `includes/Rest/AvailabilityController.php`
- Modify: `includes/Rest/Server.php:93`
- Test: `tests/e2e/availability-rest.spec.js`

**Interfaces:**
- Consumes: `Capacity\Patterns::in_force( string $user_id, string $date ): ?array`, `Patterns::history( string $user_id ): array`, `Capacity\Unavailability::overlapping( string $user_id, string $from, string $to ): array`, `Capacity\Availability::by_day( string $user_id, string $from, string $to ): array`, `Availability::hours( string $user_id, string $from, string $to ): float`, `Availability::is_recorded( string $user_id, string $date ): bool`, `Tenancy\Users::get( string $id ): ?array`.
- Produces: `AvailabilityController::answer( array $user ): array` — the shape every route returns:
  ```
  {
    ok: true,
    person: { id, display_name },
    recorded: bool,            // whether a pattern is in force today
    current: pattern | null,   // Patterns::hydrate shape: id, user_id, effective_from, note, hours_sun..hours_sat, hours_week, created_at, created_by
    history: pattern[],        // newest first, as Patterns::history returns
    leave: leave[],            // Unavailability::hydrate shape: id, user_id, starts_on, ends_on, kind, note, created_at, created_by
    week: { from, to, hours: number, days: [{ date, hours, base_hours, reason }] }
  }
  ```

- [ ] **Step 1: Branch**

```bash
git checkout main && git pull && git checkout -b availability-in-app
```

- [ ] **Step 2: Write the failing REST spec for the read**

Create `tests/e2e/availability-rest.spec.js`:

```js
import { test, expect } from '@playwright/test';
import { signedIn, makeSite, makePerson } from './helpers/forge.js';

// PR 1 of the move out of WordPress admin: a person's working week and time
// off over REST. Nothing here is deleted and the instance is reused, so every
// name carries a run id.
const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';
const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const STAMP = RUN_ID.replace('-', '');
const BASE = '/wp-json/blueworx-forge/v1';

const WEEK = { hours_sun: 0, hours_mon: 8, hours_tue: 8, hours_wed: 8, hours_thu: 8, hours_fri: 4, hours_sat: 0 };

test.describe.configure({ mode: 'serial' });

let api;
let context;
let person;

test.beforeAll(async ({ browser, baseURL }) => {
  ({ context, api } = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS));
  const where = await makeSite(api, 'Availability REST', RUN_ID);
  person = await makePerson(api, where.client.id, 'staff', `avail${STAMP}`);
});

test.afterAll(async () => {
  await context?.close();
});

test('a person nobody has set up reads as unrecorded, not as no hours', async () => {
  const answer = await api.get(`/users/${person.id}/availability`);

  expect(answer.ok).toBe(true);
  expect(answer.person.id).toBe(person.id);
  expect(answer.recorded).toBe(false);
  expect(answer.current).toBeNull();
  expect(answer.history).toEqual([]);
  expect(answer.leave).toEqual([]);
  expect(answer.week.days).toHaveLength(7);
});

test('an unknown person is a 404, not an empty answer', async () => {
  const response = await api.request.get(`${BASE}/users/usr_nobody${STAMP}/availability`, { headers: api.headers });

  expect(response.status()).toBe(404);
  expect((await response.json()).code).toBe('bwx_forge_unknown_user');
});
```

- [ ] **Step 3: Run it to see it fail**

Run: `npx playwright test tests/e2e/availability-rest.spec.js --workers=1`
Expected: both tests FAIL — the first because `answer.ok` is undefined (WordPress answers `rest_no_route`), the second because the status is 404 with code `rest_no_route`, not `bwx_forge_unknown_user`.

- [ ] **Step 4: Create the controller with the read route**

Create `includes/Rest/AvailabilityController.php`:

```php
<?php
/**
 * A person's working week and time off, over REST.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Capacity\Availability;
use Blueworx\Forge\Capacity\Patterns;
use Blueworx\Forge\Capacity\Unavailability;
use Blueworx\Forge\Tenancy\Users;
use WP_REST_Request;

/**
 * What the availability admin screen does, as routes, so the studio app can
 * be the one place this is done. The reads and writes are the ones
 * `Admin\AvailabilityActions` makes; the shapes are the Capacity classes'
 * own. Every answer is the whole picture for one person, so a screen that
 * has just written never has to read again.
 *
 * Administrators only, reads included: the admin page it replaces requires
 * the same, and a person's hours are configuration, not work.
 */
final class AvailabilityController {

	/**
	 * Registers this controller's routes.
	 *
	 * @param string $route_namespace REST namespace.
	 */
	public static function register_routes( string $route_namespace ): void {
		$scope = array(
			'kind'   => Boundary::SCOPE_OPEN,
			'reason' => 'A person\'s hours are a global record (AUTH-6), not a client\'s. Administrator-only configuration.',
		);

		Server::register_route(
			$route_namespace,
			'/users/(?P<user_id>[A-Za-z0-9_\-]+)/availability',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'read' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => $scope,
			)
		);
	}

	/**
	 * The whole picture for one person.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function read( WP_REST_Request $request ) {
		$user = Users::get( (string) $request['user_id'] );

		if ( null === $user ) {
			return self::unknown_user();
		}

		return rest_ensure_response( self::answer( $user ) );
	}

	/**
	 * What every route answers with: the pattern in force, its history, the
	 * time off a year either side of today, and the next seven days.
	 *
	 * The windows are the admin screen's, so the two show the same thing
	 * while both exist.
	 *
	 * @param array<string, mixed> $user The person.
	 * @return array<string, mixed>
	 */
	private static function answer( array $user ): array {
		$id    = (string) $user['id'];
		$today = gmdate( 'Y-m-d' );
		$start = (int) strtotime( $today . ' 00:00:00 UTC' );

		$week_to    = gmdate( 'Y-m-d', $start + ( 6 * DAY_IN_SECONDS ) );
		$leave_from = gmdate( 'Y-m-d', $start - ( 365 * DAY_IN_SECONDS ) );
		$leave_to   = gmdate( 'Y-m-d', $start + ( 365 * DAY_IN_SECONDS ) );

		return array(
			'ok'       => true,
			'person'   => array(
				'id'           => $id,
				'display_name' => (string) $user['display_name'],
			),
			'recorded' => Availability::is_recorded( $id, $today ),
			'current'  => Patterns::in_force( $id, $today ),
			'history'  => Patterns::history( $id ),
			'leave'    => Unavailability::overlapping( $id, $leave_from, $leave_to ),
			'week'     => array(
				'from'  => $today,
				'to'    => $week_to,
				'hours' => Availability::hours( $id, $today, $week_to ),
				'days'  => Availability::by_day( $id, $today, $week_to ),
			),
		);
	}

	/**
	 * The refusal for a person who is not there.
	 *
	 * @return \WP_Error
	 */
	private static function unknown_user() {
		return Errors::rest( 'unknown_user', __( 'There is no such person.', 'blueworx-forge' ), 404 );
	}
}
```

- [ ] **Step 5: Register it**

In `includes/Rest/Server.php`, after line 93 (`RecurringController::register_routes( self::NAMESPACE );`), add:

```php
		AvailabilityController::register_routes( self::NAMESPACE );
```

- [ ] **Step 6: Lint PHP, run the spec**

Run: `composer lint`
Expected: no errors in `AvailabilityController.php`.

Run: `npx playwright test tests/e2e/availability-rest.spec.js --workers=1`
Expected: 2 passed.

- [ ] **Step 7: Commit**

```bash
git add includes/Rest/AvailabilityController.php includes/Rest/Server.php tests/e2e/availability-rest.spec.js
git commit -m "Availability over REST: read a person's week and time off

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Recording a working week

**Files:**
- Modify: `includes/Rest/AvailabilityController.php`
- Test: `tests/e2e/availability-rest.spec.js`

**Interfaces:**
- Consumes: `Patterns::record( string $user_id, string $effective_from, array $hours, int $author, string $note = '' ): ?array`, `Patterns::day_columns(): array`.
- Produces: `POST /users/<user_id>/availability/hours` with body `{ effective_from: 'YYYY-MM-DD', hours_sun..hours_sat: number, note?: string }`. Answers `{ ok: true, pattern, ...answer }` — the full answer from Task 1 plus the row just written under `pattern`. A bad date is `400 bwx_forge_invalid_availability` with `data.fields.effective_from`.

- [ ] **Step 1: Add the failing tests**

Append to `tests/e2e/availability-rest.spec.js`:

```js
test('recording a week makes it the pattern in force and totals the next seven days', async () => {
  const wrote = await api.post(`/users/${person.id}/availability/hours`, { effective_from: '2020-01-01', ...WEEK });
  expect(wrote.status(), await wrote.text()).toBe(200);

  const answer = await wrote.json();
  expect(answer.pattern.hours_week).toBe(36);
  expect(answer.recorded).toBe(true);
  expect(answer.current.id).toBe(answer.pattern.id);
  expect(answer.history).toHaveLength(1);
  // Seven days from any day of the week cover each weekday exactly once.
  expect(answer.week.hours).toBe(36);
});

test('a second week from a later date wins, and the first stays in the history', async () => {
  const wrote = await api.post(`/users/${person.id}/availability/hours`, { effective_from: '2021-01-01', ...WEEK, hours_fri: 8 });
  expect(wrote.status(), await wrote.text()).toBe(200);

  const answer = await wrote.json();
  expect(answer.current.hours_week).toBe(40);
  expect(answer.history).toHaveLength(2);
});

test('a week without a real date is refused by field', async () => {
  const wrote = await api.post(`/users/${person.id}/availability/hours`, { effective_from: '2021-02-30', ...WEEK });

  expect(wrote.status()).toBe(400);
  const body = await wrote.json();
  expect(body.code).toBe('bwx_forge_invalid_availability');
  expect(body.data.fields.effective_from).toBeTruthy();
});

test('somebody who is not an administrator cannot read or write hours', async ({ browser, baseURL }) => {
  const other = await signedIn(browser, baseURL, person.login, PASSWORD);

  const read = await other.api.request.get(`${BASE}/users/${person.id}/availability`, { headers: other.api.headers });
  expect(read.status()).toBe(403);

  const wrote = await other.api.post(`/users/${person.id}/availability/hours`, { effective_from: '2020-01-01', ...WEEK });
  expect(wrote.status()).toBe(403);

  await other.context.close();
});
```

And change the import at the top to:

```js
import { signedIn, makeSite, makePerson, PASSWORD } from './helpers/forge.js';
```

- [ ] **Step 2: Run to see them fail**

Run: `npx playwright test tests/e2e/availability-rest.spec.js --workers=1`
Expected: the three new write tests FAIL with status 404 (`rest_no_route`); the 403 test FAILS on the write (404, not 403). The two Task 1 tests still pass.

- [ ] **Step 3: Add the route and handler**

In `register_routes()`, after the GET registration, add:

```php
		Server::register_route(
			$route_namespace,
			'/users/(?P<user_id>[A-Za-z0-9_\-]+)/availability/hours',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'set_hours' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => $scope,
			)
		);
```

Add these methods to the class, before `answer()`:

```php
	/**
	 * Records a working week from a date.
	 *
	 * Always an append, as {@see Patterns::record()} is: a correction is a
	 * new row that wins from its date, and the old one stays in the history.
	 * That is why this is a POST and not a PUT.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function set_hours( WP_REST_Request $request ) {
		$user = Users::get( (string) $request['user_id'] );

		if ( null === $user ) {
			return self::unknown_user();
		}

		$body           = (array) $request->get_json_params();
		$effective_from = sanitize_text_field( (string) ( $body['effective_from'] ?? '' ) );

		if ( ! self::is_date( $effective_from ) ) {
			return self::invalid( array( 'effective_from' => __( 'Say the date these hours start from.', 'blueworx-forge' ) ) );
		}

		$hours = array();

		foreach ( Patterns::day_columns() as $column ) {
			$hours[ $column ] = (float) ( $body[ $column ] ?? 0 );
		}

		$note    = sanitize_text_field( (string) ( $body['note'] ?? '' ) );
		$pattern = Patterns::record( (string) $user['id'], $effective_from, $hours, get_current_user_id(), $note );

		if ( null === $pattern ) {
			return Errors::rest( 'write_failed', __( 'Those hours could not be saved.', 'blueworx-forge' ), 500 );
		}

		return rest_ensure_response( array_merge( array( 'pattern' => $pattern ), self::answer( $user ) ) );
	}

	/**
	 * Whether a value is a date this can store.
	 *
	 * Checked rather than trusted, for the reason the admin screen checks:
	 * a malformed date stored here sorts wrong against every other date.
	 *
	 * @param string $value Candidate.
	 * @return bool
	 */
	private static function is_date( string $value ): bool {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return false;
		}

		list( $year, $month, $day ) = array_map( 'intval', explode( '-', $value ) );

		return checkdate( $month, $day, $year );
	}

	/**
	 * The refusal for input that cannot be stored, by field.
	 *
	 * @param array<string, string> $fields Field to what is wrong with it.
	 * @return \WP_Error
	 */
	private static function invalid( array $fields ) {
		return Errors::rest(
			'invalid_availability',
			__( 'That could not be saved.', 'blueworx-forge' ),
			400,
			array( 'fields' => $fields )
		);
	}
```

- [ ] **Step 4: Lint and run**

Run: `composer lint`
Expected: clean.

Run: `npx playwright test tests/e2e/availability-rest.spec.js --workers=1`
Expected: 6 passed.

- [ ] **Step 5: Commit**

```bash
git add includes/Rest/AvailabilityController.php tests/e2e/availability-rest.spec.js
git commit -m "Availability over REST: record a working week

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Time off — add and remove

**Files:**
- Modify: `includes/Rest/AvailabilityController.php`
- Test: `tests/e2e/availability-rest.spec.js`

**Interfaces:**
- Consumes: `Unavailability::add( string $user_id, string $starts_on, string $ends_on, string $kind, int $author, string $note = '' ): ?array`, `Unavailability::remove( string $id ): bool`, `Unavailability::KINDS`, `Idempotency::HEADER`, `Idempotency::is_valid_key()`, `::replay()`, `::remember()`.
- Produces: `POST /users/<user_id>/leave` body `{ starts_on, ends_on, kind?, note? }` → `{ ok: true, leave: record, ...answer }`. `DELETE /users/<user_id>/leave/<leave_id>` → `{ ok: true, ...answer }`; a record that is not this person's, or not there, is `404 bwx_forge_unknown_leave`.

- [ ] **Step 1: Add the failing tests**

Append to `tests/e2e/availability-rest.spec.js`:

```js
test('time off is recorded, listed, and taken out of the week', async () => {
  const added = await api.post(`/users/${person.id}/leave`, {
    starts_on: '2020-01-06',
    ends_on: '2020-01-10',
    kind: 'leave',
    note: `Away ${RUN_ID}`,
  });
  expect(added.status(), await added.text()).toBe(200);

  const answer = await added.json();
  expect(answer.record.id).toMatch(/^una_/);
  expect(answer.record.kind).toBe('leave');
  expect(answer.record.starts_on).toBe('2020-01-06');
  // Outside the year-either-side window the listing shows, so not listed —
  // which is the admin page's behaviour too.
  expect(answer.leave.find((one) => one.id === answer.record.id)).toBeUndefined();
});

test('time off that overlaps this week reduces the hours available', async () => {
  const from = new Date();
  const to = new Date();
  to.setUTCDate(to.getUTCDate() + 6);
  const day = (d) => d.toISOString().slice(0, 10);

  const added = await api.post(`/users/${person.id}/leave`, { starts_on: day(from), ends_on: day(to), kind: 'training' });
  expect(added.status(), await added.text()).toBe(200);

  const answer = await added.json();
  expect(answer.record.kind).toBe('training');
  expect(answer.week.hours).toBe(0);
  expect(answer.leave.some((one) => one.id === answer.record.id)).toBe(true);
});

test('removing time off gives the hours back', async () => {
  const before = await api.get(`/users/${person.id}/availability`);
  const thisWeek = before.leave.find((one) => one.kind === 'training');
  expect(thisWeek).toBeTruthy();

  const removed = await api.request.delete(`${BASE}/users/${person.id}/leave/${thisWeek.id}`, { headers: api.headers });
  expect(removed.status(), await removed.text()).toBe(200);

  const answer = await removed.json();
  expect(answer.week.hours).toBe(40);
  expect(answer.leave.find((one) => one.id === thisWeek.id)).toBeUndefined();
});

test('removing somebody else\'s record through this person is refused', async () => {
  const removed = await api.request.delete(`${BASE}/users/${person.id}/leave/una_nothere${STAMP}`, { headers: api.headers });

  expect(removed.status()).toBe(404);
  expect((await removed.json()).code).toBe('bwx_forge_unknown_leave');
});

test('a replayed leave under one retry key makes one record, not two', async () => {
  const key = `leave-${RUN_ID}`;
  const body = { starts_on: '2019-06-01', ends_on: '2019-06-02', kind: 'other' };
  const headers = { ...api.headers, 'Idempotency-Key': key };

  const first = await api.request.post(`${BASE}/users/${person.id}/leave`, { headers, data: body });
  const again = await api.request.post(`${BASE}/users/${person.id}/leave`, { headers, data: body });

  expect(first.status()).toBe(200);
  expect(again.status()).toBe(200);
  expect((await again.json()).record.id).toBe((await first.json()).record.id);
});

test('a leave with its dates the wrong way round is stored the right way round', async () => {
  const added = await api.post(`/users/${person.id}/leave`, { starts_on: '2019-03-10', ends_on: '2019-03-08', kind: 'leave' });
  expect(added.status(), await added.text()).toBe(200);

  const { record } = await added.json();
  expect(record.starts_on).toBe('2019-03-08');
  expect(record.ends_on).toBe('2019-03-10');
});

test('a leave with no real dates is refused by field', async () => {
  const added = await api.post(`/users/${person.id}/leave`, { starts_on: 'soon', ends_on: '', kind: 'leave' });

  expect(added.status()).toBe(400);
  const body = await added.json();
  expect(body.code).toBe('bwx_forge_invalid_availability');
  expect(body.data.fields.starts_on).toBeTruthy();
  expect(body.data.fields.ends_on).toBeTruthy();
});
```

The written record is under `record`, not `leave`: the answer already carries the `leave` list, and the written pattern in Task 2 is under `pattern` for the same reason.

- [ ] **Step 2: Run to see them fail**

Run: `npx playwright test tests/e2e/availability-rest.spec.js --workers=1`
Expected: the seven new tests FAIL with 404 `rest_no_route`; the six earlier ones pass.

- [ ] **Step 3: Add the routes and handlers**

In `register_routes()`, after the hours registration, add:

```php
		Server::register_route(
			$route_namespace,
			'/users/(?P<user_id>[A-Za-z0-9_\-]+)/leave',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'add_leave' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => $scope,
			)
		);

		Server::register_route(
			$route_namespace,
			'/users/(?P<user_id>[A-Za-z0-9_\-]+)/leave/(?P<leave_id>[A-Za-z0-9_\-]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( self::class, 'remove_leave' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => $scope,
			)
		);
```

Add a constant at the top of the class:

```php
	/**
	 * The idempotency operation for adding time off. Scoped by person when
	 * used, so one retry key cannot answer another person's replay.
	 */
	private const LEAVE_OPERATION = 'availability.leave.create';
```

Add these methods after `set_hours()`:

```php
	/**
	 * Records time somebody is not available for.
	 *
	 * Replay-safe under an idempotency key: a resend that made a second row
	 * would show two identical periods, and somebody would delete one.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function add_leave( WP_REST_Request $request ) {
		$user = Users::get( (string) $request['user_id'] );

		if ( null === $user ) {
			return self::unknown_user();
		}

		$key       = (string) $request->get_header( Idempotency::HEADER );
		$operation = self::LEAVE_OPERATION . ':' . (string) $user['id'];

		if ( '' !== $key ) {
			if ( ! Idempotency::is_valid_key( $key ) ) {
				return Errors::rest( 'invalid_idempotency_key', __( 'That retry key cannot be used.', 'blueworx-forge' ), 400 );
			}

			$replay = Idempotency::replay( $operation, $key );

			if ( null !== $replay ) {
				return rest_ensure_response( $replay );
			}
		}

		$body      = (array) $request->get_json_params();
		$starts_on = sanitize_text_field( (string) ( $body['starts_on'] ?? '' ) );
		$ends_on   = sanitize_text_field( (string) ( $body['ends_on'] ?? '' ) );
		$fields    = array();

		if ( ! self::is_date( $starts_on ) ) {
			$fields['starts_on'] = __( 'Say the first day away.', 'blueworx-forge' );
		}

		if ( ! self::is_date( $ends_on ) ) {
			$fields['ends_on'] = __( 'Say the last day away.', 'blueworx-forge' );
		}

		if ( array() !== $fields ) {
			return self::invalid( $fields );
		}

		$kind = sanitize_key( (string) ( $body['kind'] ?? 'leave' ) );
		$note = sanitize_text_field( (string) ( $body['note'] ?? '' ) );

		$record = Unavailability::add( (string) $user['id'], $starts_on, $ends_on, $kind, get_current_user_id(), $note );

		if ( null === $record ) {
			return Errors::rest( 'write_failed', __( 'That time off could not be saved.', 'blueworx-forge' ), 500 );
		}

		$response = array_merge( array( 'record' => $record ), self::answer( $user ) );

		if ( '' !== $key ) {
			Idempotency::remember( $operation, $key, $response );
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Removes one record, if it is this person's.
	 *
	 * Checked against the person in the path rather than deleted by id
	 * alone, so a route about one person cannot be used to change another.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function remove_leave( WP_REST_Request $request ) {
		$user = Users::get( (string) $request['user_id'] );

		if ( null === $user ) {
			return self::unknown_user();
		}

		$leave_id = (string) $request['leave_id'];
		$answer   = self::answer( $user );
		$owned    = false;

		foreach ( $answer['leave'] as $record ) {
			if ( (string) $record['id'] === $leave_id ) {
				$owned = true;
				break;
			}
		}

		if ( ! $owned || ! Unavailability::remove( $leave_id ) ) {
			return Errors::rest( 'unknown_leave', __( 'There is no such time off.', 'blueworx-forge' ), 404 );
		}

		return rest_ensure_response( self::answer( $user ) );
	}
```

Note: `$answer['leave']` is the year-either-side window, so a record older than a year cannot be removed through this route. That matches the admin page, which only offers a remove button for what it lists.

- [ ] **Step 4: Lint and run**

Run: `composer lint`
Expected: clean.

Run: `npx playwright test tests/e2e/availability-rest.spec.js --workers=1`
Expected: 13 passed.

- [ ] **Step 5: Commit**

```bash
git add includes/Rest/AvailabilityController.php tests/e2e/availability-rest.spec.js
git commit -m "Availability over REST: add and remove time off

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: The test helper, and the four specs that set hours through the admin page

**Files:**
- Modify: `tests/e2e/helpers/forge.js` (append)
- Modify: `tests/e2e/capacity-gate.spec.js:24-48, 72-74, 157-159`
- Modify: `tests/e2e/capacity-rest.spec.js:21-45, 79-81`
- Modify: `tests/e2e/support-hours-gate.spec.js:58-81, 108-110`
- Modify: `tests/pair/acceptance-capacity.spec.js:38-61, 97-99, 164-166`

**Interfaces:**
- Produces: `setHours( api, personId, perDay, from = '2020-01-01' )` in `forge.js`. Works with both the e2e `forge()` caller and the pair helper's studio caller, since both expose `post( path, data )`.

- [ ] **Step 1: Add the helper**

Append to `tests/e2e/helpers/forge.js`:

```js
/**
 * Somebody's working week, the same hours every day, from a date far enough
 * back to cover any window a spec uses.
 *
 * Works with the pair suite's studio caller too: both expose `post`.
 */
export async function setHours(api, personId, perDay, from = '2020-01-01') {
  const wrote = await api.post(`/users/${personId}/availability/hours`, {
    effective_from: from,
    hours_sun: perDay,
    hours_mon: perDay,
    hours_tue: perDay,
    hours_wed: perDay,
    hours_thu: perDay,
    hours_fri: perDay,
    hours_sat: perDay,
  });
  expect(wrote.status(), await wrote.text()).toBe(200);

  return (await wrote.json()).pattern;
}
```

- [ ] **Step 2: Switch `capacity-gate.spec.js`**

Delete the `setHoursThroughTheScreen` function and its comment block (lines 24–48, from `// Patterns are written from the availability screen` to the closing `}`), and delete `const DAYS = [...]` on line 21 if nothing else uses it (check with a search for `DAYS` in the file first).

Change the import on line 2 to:

```js
import { signedIn, makeSite, makePerson, makeItem, walkTo, satisfy, onSupport, setHours } from './helpers/forge.js';
```

Replace lines 72–74:

```js
  const page = await context.newPage();
  await setHoursThroughTheScreen(page, PERSON, 8);
  await page.close();
```

with:

```js
  await setHours(api, person.id, 8);
```

Replace lines 157–159 the same way:

```js
  await setHours(api, person.id, 8);
```

- [ ] **Step 3: Switch `capacity-rest.spec.js`**

Delete `setHoursThroughTheScreen` and its comment (lines 21–45), and `DAYS` on line 18 if unused elsewhere in the file. Change the import to add `setHours`. Replace lines 79–81 with:

```js
  await setHours(api, person.id, 8);
```

- [ ] **Step 4: Switch `support-hours-gate.spec.js`**

Delete `giveWorkingHours` and its comment (lines 58–81) and `DAYS` if unused. Replace lines 108–110:

```js
  const page = await admin.context.newPage();

  await giveWorkingHours(page, names.primary, 8);
  await page.close();
```

with:

```js
  await Forge.setHours(api, people.primary.id, 8);
```

(`people.primary` is the created person; `names.primary` was only needed to pick from the admin page's dropdown. Check `withSite` at line ~29 still returns `people`; leave `names` in place if the other test in the file uses it.)

- [ ] **Step 5: Switch `tests/pair/acceptance-capacity.spec.js`**

Delete `setHoursThroughTheScreen` and its docblock (lines 38–61) and `DAYS` if unused. Replace lines 97–99 and 164–166 with:

```js
    await Forge.setHours(pair.studio, person.id, 8);
```

- [ ] **Step 6: Run the three e2e specs**

Run: `npx playwright test tests/e2e/capacity-gate.spec.js tests/e2e/capacity-rest.spec.js tests/e2e/support-hours-gate.spec.js --workers=1`
Expected: all pass. These are slow (several minutes) — that is their nature, not a regression.

The pair spec needs the pair environment: `npm run wp:pair:up` then `npx playwright test -c playwright.pair.config.js tests/pair/acceptance-capacity.spec.js`, then `npm run wp:pair:down`. Run it if the foundation sibling is present; if not, say so in the PR.

- [ ] **Step 7: Commit**

```bash
git add tests/e2e/helpers/forge.js tests/e2e/capacity-gate.spec.js tests/e2e/capacity-rest.spec.js tests/e2e/support-hours-gate.spec.js tests/pair/acceptance-capacity.spec.js
git commit -m "Specs set working hours over REST instead of the admin page

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: The screen exists on the rail

**Files:**
- Modify: `src/types.ts:145`
- Modify: `src/App.tsx` (imports, RAIL ~line 44-63, TITLES ~121, OPENINGS ~148, mount ~344-352)
- Create: `src/components/AvailabilityScreen.tsx` (minimal)
- Test: `tests/e2e/availability-app.spec.js`

**Interfaces:**
- Produces: `ScreenName` includes `'availability'`; `AvailabilityScreen( { person }: { person: string } )` where `person` is a user id to open on, or `''`.

- [ ] **Step 1: Write the failing screen spec**

Create `tests/e2e/availability-app.spec.js`:

```js
import { test, expect } from '@playwright/test';
import { signIn } from '../helpers/sign-in.js';
import { signedIn, makeSite, makePerson } from './helpers/forge.js';

// The availability screen in the app: pick a person, see their week, set
// their hours, record time off. Names carry a run id because the instance is
// reused between runs.
const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';
const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const STAMP = RUN_ID.replace('-', '');
const NAME = `Avail ${STAMP}`;

test.describe.configure({ mode: 'serial' });

let admin;
let person;

test.beforeAll(async ({ browser, baseURL }) => {
  admin = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const where = await makeSite(admin.api, 'Availability app', RUN_ID);
  person = await makePerson(admin.api, where.client.id, 'staff', NAME);
});

test.afterAll(async () => {
  await admin?.context.close();
});

test.beforeEach(async ({ page }) => {
  await signIn(page, ADMIN_USER, ADMIN_PASS);
  await page.goto('/blueworx-forge/');
});

test('the rail offers Availability under Team, and it opens', async ({ page }) => {
  const entry = page.getByTestId('bwx-screen-availability');
  await expect(entry).toBeVisible();

  await entry.click();
  await expect(page.getByTestId('bwx-availability')).toBeVisible({ timeout: 30_000 });
  await expect(page.locator('h1')).toHaveText('Availability');
});
```

- [ ] **Step 2: Run to see it fail**

Run: `npx playwright test tests/e2e/availability-app.spec.js --workers=1`
Expected: FAIL — `bwx-screen-availability` is not visible.

- [ ] **Step 3: Add the screen name**

In `src/types.ts` line 145, change:

```ts
export type ScreenName = 'mytasks' | 'work' | 'requests' | 'capacity' | 'onboarding' | 'standup' | 'reports' | 'recurring' | 'subscriptions';
```

to:

```ts
export type ScreenName = 'mytasks' | 'work' | 'requests' | 'capacity' | 'onboarding' | 'standup' | 'reports' | 'recurring' | 'subscriptions' | 'availability';
```

- [ ] **Step 4: The minimal screen**

Create `src/components/AvailabilityScreen.tsx`:

```tsx
/**
 * A person's working week and time off (#136), in the app.
 *
 * The first of the configuration screens to leave WordPress admin. Reads and
 * writes `/users/<id>/availability` and nothing else; the admin page stays
 * beside it until every screen has moved.
 */
export function AvailabilityScreen( { person }: { person: string } ) {
  return (
    <div className="bwx-availability" data-testid="bwx-availability" data-person={ person }>
      { /* Filled in by the next task. */ }
    </div>
  );
}
```

- [ ] **Step 5: Register it in the shell**

In `src/App.tsx`:

Add `CalendarCheck` to the `lucide-react` import list (it is alphabetical; place accordingly), and add the component import next to the other screen imports:

```ts
import { AvailabilityScreen } from './components/AvailabilityScreen';
```

In `RAIL`, after the `{ href: 'admin.php?page=blueworx-forge-sync', ... }` entry and before `{ group: 'Insight' }`, insert:

```ts
  { group: 'Team' },
  { key: 'availability', label: 'Availability', icon: CalendarCheck, testId: 'bwx-screen-availability' },
```

In `TITLES`, add:

```ts
  availability: 'Availability',
```

In `OPENINGS`, add:

```ts
  availability: { crumbs: [ 'Team', 'Availability' ], eyebrow: 'Working weeks and time off', tile: CalendarCheck, hue: 'blue' },
```

In the mount block (after `{ 'subscriptions' === screen && <SubscriptionsScreen key={ generation } /> }`), add:

```tsx
        { 'availability' === screen && <AvailabilityScreen key={ generation } person="" /> }
```

- [ ] **Step 6: Build, run the spec**

Run: `npm run build`
Expected: builds clean (ignore `tsc --noEmit`, it reports pre-existing errors).

Run: `npx playwright test tests/e2e/availability-app.spec.js --workers=1`
Expected: 1 passed.

- [ ] **Step 7: Commit**

```bash
git add src/types.ts src/App.tsx src/components/AvailabilityScreen.tsx tests/e2e/availability-app.spec.js
git commit -m "Availability is on the rail under Team

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: Reading a person — picker, this week, the working week, time off

**Files:**
- Modify: `src/types.ts` (append types)
- Modify: `src/components/AvailabilityScreen.tsx` (replace)
- Modify: `src/styles.css` (append after the `.bwx-recurring-*` rules, ~line 1420)
- Test: `tests/e2e/availability-app.spec.js`

**Interfaces:**
- Consumes: `api< T >( path, options )`, `messageFor`, `isDenied` from `src/api.ts`; `useLiveReload` from `src/live.ts`; `everybody()` from `./ItemPanel` (returns `Promise< Person[] >` of active people); `Screen` from `./States`; `DataView`, `Column`, `Panel`, `Stat`, `EmptyState`, `Select` from `../kit`.
- Produces: types `AvailabilityPattern`, `LeaveRecord`, `AvailabilityAnswer` in `src/types.ts`; the screen reads `/users/<id>/availability` and shows it. Writes come in Task 7.

- [ ] **Step 1: Add the failing tests**

Append to `tests/e2e/availability-app.spec.js`:

```js
test('a person nobody has set up says so, rather than showing no time', async ({ page }) => {
  await page.getByTestId('bwx-screen-availability').click();
  await page.getByTestId('bwx-availability-person').selectOption(person.id);

  const state = page.getByTestId('bwx-availability-recorded');
  await expect(state).toHaveAttribute('data-recorded', 'no');
  await expect(state).toContainText('Nobody has said what');
  await expect(page.getByTestId('bwx-availability-no-leave')).toBeVisible();
});

test('a person with hours shows the week, the pattern, and the history', async ({ page }) => {
  // Set through REST; the screen's own form is the next test.
  await admin.api.post(`/users/${person.id}/availability/hours`, {
    effective_from: '2020-01-01',
    hours_sun: 0, hours_mon: 8, hours_tue: 8, hours_wed: 8, hours_thu: 8, hours_fri: 4, hours_sat: 0,
  });

  await page.getByTestId('bwx-screen-availability').click();
  await page.getByTestId('bwx-availability-person').selectOption(person.id);

  await expect(page.getByTestId('bwx-availability-recorded')).toHaveAttribute('data-recorded', 'yes');
  await expect(page.getByTestId('bwx-availability-week-hours')).toHaveText('36h');
  await expect(page.getByTestId('bwx-availability-day-hours_fri')).toHaveText('4');
  await expect(page.locator('[data-testid="bwx-availability-history"] tbody tr')).toHaveCount(1);
});
```

- [ ] **Step 2: Run to see them fail**

Run: `npx playwright test tests/e2e/availability-app.spec.js --workers=1`
Expected: the two new tests FAIL (no `bwx-availability-person`); the first passes.

- [ ] **Step 3: Types**

Append to `src/types.ts`:

```ts
/** One working-week pattern, from a date (#136). Sunday first, as PHP numbers weekdays. */
export interface AvailabilityPattern {
  id: string;
  user_id: string;
  effective_from: string;
  note: string;
  hours_sun: number;
  hours_mon: number;
  hours_tue: number;
  hours_wed: number;
  hours_thu: number;
  hours_fri: number;
  hours_sat: number;
  hours_week: number;
  created_at: number;
  created_by: number;
}

export type LeaveKind = 'leave' | 'public-holiday' | 'training' | 'other';

/** A period somebody is not available for, inclusive of both ends. */
export interface LeaveRecord {
  id: string;
  user_id: string;
  starts_on: string;
  ends_on: string;
  kind: LeaveKind;
  note: string;
  created_at: number;
  created_by: number;
}

/** What `/users/<id>/availability` answers, and what every write there answers too. */
export interface AvailabilityAnswer {
  ok: true;
  person: { id: string; display_name: string };
  recorded: boolean;
  current: AvailabilityPattern | null;
  history: AvailabilityPattern[];
  leave: LeaveRecord[];
  week: {
    from: string;
    to: string;
    hours: number;
    days: Array< { date: string; hours: number; base_hours: number; reason: string } >;
  };
}
```

- [ ] **Step 4: The screen, reading only**

Replace `src/components/AvailabilityScreen.tsx` with:

```tsx
import { useEffect, useState } from 'react';
import { CalendarCheck } from 'lucide-react';
import type { AvailabilityAnswer, AvailabilityPattern, LeaveRecord, Person } from '../types';
import { api, isDenied, messageFor } from '../api';
import { useLiveReload } from '../live';
import { DataView, EmptyState, Panel, Select, Stat } from '../kit';
import type { Column } from '../kit';
import { everybody } from './ItemPanel';
import { Screen } from './States';

/**
 * A person's working week and time off (#136), in the app.
 *
 * The first of the configuration screens to leave WordPress admin. Reads and
 * writes `/users/<id>/availability` and nothing else; the admin page stays
 * beside it until every screen has moved.
 *
 * The distinction the whole screen turns on is kept from the admin page:
 * nothing recorded is not the same as no hours, and a zero would read as the
 * second. So an unrecorded person is told in words, not shown 0h.
 */

/** The seven columns, Monday first for reading, keyed as the server keys them. */
export const DAYS: Array< [ keyof AvailabilityPattern & `hours_${ string }`, string ] > = [
  [ 'hours_mon', 'Mon' ],
  [ 'hours_tue', 'Tue' ],
  [ 'hours_wed', 'Wed' ],
  [ 'hours_thu', 'Thu' ],
  [ 'hours_fri', 'Fri' ],
  [ 'hours_sat', 'Sat' ],
  [ 'hours_sun', 'Sun' ],
];

export const KINDS: Array< { value: LeaveRecord[ 'kind' ]; label: string } > = [
  { value: 'leave', label: 'Leave' },
  { value: 'public-holiday', label: 'Public holiday' },
  { value: 'training', label: 'Training' },
  { value: 'other', label: 'Other' },
];

function kindLabel( kind: string ): string {
  return KINDS.find( ( one ) => one.value === kind )?.label ?? kind;
}

/** 8 → "8", 7.5 → "7.5". */
function hours( value: number ): string {
  return Number.isInteger( value ) ? String( value ) : value.toFixed( 2 ).replace( /0+$/, '' );
}

export function AvailabilityScreen( { person }: { person: string } ) {
  const [ people, setPeople ] = useState< Person[] >( [] );
  const [ personId, setPersonId ] = useState( person );
  const [ answer, setAnswer ] = useState< AvailabilityAnswer | null >( null );
  const [ state, setState ] = useState< 'idle' | 'loading' | 'ready' | 'denied' | 'error' >( person ? 'loading' : 'idle' );
  const [ notice, setNotice ] = useState( '' );

  async function load( id: string = personId ) {
    if ( '' === id ) {
      setAnswer( null );
      setState( 'idle' );

      return;
    }

    try {
      const fresh = await api< AvailabilityAnswer >( `/users/${ id }/availability` );

      setAnswer( fresh );
      setState( 'ready' );
    } catch ( error ) {
      setState( isDenied( error ) ? 'denied' : 'error' );
      setNotice( messageFor( error, 'Availability could not be read.' ) );
    }
  }

  useEffect( () => {
    void everybody().then( setPeople );
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load( person );
    // The person prop is a landing, read once; picking is the select's job.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [] );

  useLiveReload( () => load() );

  function pick( id: string ) {
    setPersonId( id );
    setState( id ? 'loading' : 'idle' );
    void load( id );
  }

  const historyColumns: Column< AvailabilityPattern >[] = [
    { key: 'from', label: 'From', mono: true, width: 120, sortBy: ( p ) => p.effective_from, render: ( p ) => p.effective_from },
    ...DAYS.map( ( [ key, label ] ): Column< AvailabilityPattern > => ( {
      key,
      label,
      mono: true,
      align: 'right',
      width: 56,
      render: ( p ) => hours( p[ key ] ),
    } ) ),
    { key: 'week', label: 'Week', mono: true, align: 'right', width: 72, render: ( p ) => `${ hours( p.hours_week ) }h` },
    { key: 'note', label: 'Note', wrap: true, render: ( p ) => p.note || '—' },
  ];

  const leaveColumns: Column< LeaveRecord >[] = [
    { key: 'starts', label: 'From', mono: true, width: 120, sortBy: ( l ) => l.starts_on, render: ( l ) => l.starts_on },
    { key: 'ends', label: 'To', mono: true, width: 120, sortBy: ( l ) => l.ends_on, render: ( l ) => l.ends_on },
    { key: 'kind', label: 'Kind', width: 140, render: ( l ) => kindLabel( l.kind ) },
    { key: 'note', label: 'Note', wrap: true, render: ( l ) => l.note || '—' },
  ];

  return (
    <div className="bwx-availability" data-testid="bwx-availability">
      <div className="bwx-availability-picker">
        <label htmlFor="bwx-availability-person">Person</label>
        <Select
          id="bwx-availability-person"
          data-testid="bwx-availability-person"
          value={ personId }
          onChange={ ( event ) => pick( event.target.value ) }
          options={ [ { value: '', label: 'Pick a person' }, ...people.map( ( one ) => ( { value: one.id, label: one.display_name } ) ) ] }
        />
      </div>

      { 'idle' === state && (
        <EmptyState icon={ CalendarCheck } title="Pick a person" body="Their working week and time off show here, and can be changed here." />
      ) }
      { 'loading' === state && <Screen state="loading" testId="bwx-availability-state" /> }
      { 'denied' === state && <Screen state="denied" testId="bwx-availability-state" detail="Working hours are configuration, and configuration is the administrator's." /> }
      { 'error' === state && <Screen state="error" testId="bwx-availability-state" detail={ notice } /> }

      { 'ready' === state && answer && (
        <>
          { '' !== notice && (
            <p className="bwx-notice" data-testid="bwx-availability-notice" role="status">
              { notice }
            </p>
          ) }

          <Panel title="This week">
            { answer.recorded ? (
              <div data-testid="bwx-availability-recorded" data-recorded="yes">
                <Stat
                  label="Available hours"
                  value={ <span data-testid="bwx-availability-week-hours">{ `${ hours( answer.week.hours ) }h` }</span> }
                  sub={ `Across the next seven days, ${ answer.week.from } to ${ answer.week.to }.` }
                />
              </div>
            ) : (
              <p className="bwx-notice" data-testid="bwx-availability-recorded" data-recorded="no" role="status">
                Nobody has said what this person&apos;s hours are, so nothing can be planned against them yet. That is different from having no time.
              </p>
            ) }
          </Panel>

          <Panel title="Working week">
            { answer.current ? (
              <dl className="bwx-availability-week" data-testid="bwx-availability-current">
                { DAYS.map( ( [ key, label ] ) => (
                  <div key={ key }>
                    <dt>{ label }</dt>
                    <dd data-testid={ `bwx-availability-day-${ key }` }>{ hours( answer.current?.[ key ] ?? 0 ) }</dd>
                  </div>
                ) ) }
                <div>
                  <dt>Week</dt>
                  <dd>{ `${ hours( answer.current.hours_week ) }h` }</dd>
                </div>
              </dl>
            ) : (
              <p className="bwx-hint">No working week recorded yet.</p>
            ) }

            <DataView< AvailabilityPattern >
              title="History"
              columns={ historyColumns }
              rows={ answer.history }
              sortable
              defaultSort={ { key: 'from', dir: 'desc' } }
              empty={ <p className="bwx-hint">Nothing recorded yet.</p> }
              testId="bwx-availability-history"
            />
          </Panel>

          <Panel title="Time off">
            <DataView< LeaveRecord >
              columns={ leaveColumns }
              rows={ answer.leave }
              sortable
              defaultSort={ { key: 'starts', dir: 'desc' } }
              empty={ <EmptyState icon={ CalendarCheck } dense title="No time off recorded" body="Nothing recorded in the year either side of today." /> }
              footer={ `${ answer.leave.length } recorded · a year either side of today` }
              testId="bwx-availability-leave"
            />
            { 0 === answer.leave.length && <span data-testid="bwx-availability-no-leave" hidden /> }
          </Panel>
        </>
      ) }
    </div>
  );
}
```

`Sort` is `{ key, dir }` (`src/kit/DataView.tsx:43`). If `Select` does not spread `data-testid` (it takes `SelectHTMLAttributes`, so it should), put the testid on a wrapping span and adjust the spec's locator.

The `bwx-availability-no-leave` marker: `EmptyState` inside `DataView` has no testid of its own. If `DataView` renders `empty` in an element that can carry a test id, prefer that and drop the hidden span. Otherwise keep it — `hidden` elements are still found by `toBeVisible`? No: `toBeVisible` fails on `hidden`. Use `toBeAttached()` in the spec instead of `toBeVisible()` for that one assertion, or drop `hidden` and give the span `className="screen-reader-text"`. Do the second: `<span data-testid="bwx-availability-no-leave" className="screen-reader-text">No time off recorded</span>`.

- [ ] **Step 5: Styles**

Append to `src/styles.css` after the `.bwx-recurring-*` block:

```css
/* Availability (#136 in the app). */
.bwx-availability {
  display: grid;
  gap: var( --space-4, 16px );
}

.bwx-availability-picker {
  display: flex;
  align-items: center;
  gap: var( --space-3, 12px );
  max-width: 420px;
}

.bwx-availability-picker label {
  font-size: var( --text-small );
  color: var( --ink-600 );
}

.bwx-availability-week {
  display: grid;
  grid-template-columns: repeat( 8, minmax( 0, 1fr ) );
  gap: var( --space-2, 8px );
  margin: 0 0 var( --space-4, 16px );
}

.bwx-availability-week dt {
  font-size: var( --text-small );
  color: var( --ink-600 );
}

.bwx-availability-week dd {
  margin: 0;
  font-family: var( --font-mono );
  font-size: var( --text-subheading );
}

@media ( max-width: 640px ) {
  .bwx-availability-week {
    grid-template-columns: repeat( 4, minmax( 0, 1fr ) );
  }
}
```

Use the token names that exist in `tokens/` — check `src/styles.css` for the `--space-`, `--text-` and `--ink-` names already in use nearby and match them exactly; the fallbacks above are only there if the token is absent.

- [ ] **Step 6: Build and run**

Run: `npm run build` then `npx playwright test tests/e2e/availability-app.spec.js --workers=1`
Expected: 3 passed.

- [ ] **Step 7: Commit**

```bash
git add src/types.ts src/components/AvailabilityScreen.tsx src/styles.css tests/e2e/availability-app.spec.js
git commit -m "Availability screen reads a person's week, pattern and time off

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: Writing — set hours, add time off, remove time off

**Files:**
- Modify: `src/components/AvailabilityScreen.tsx`
- Modify: `src/styles.css`
- Test: `tests/e2e/availability-app.spec.js`

**Interfaces:**
- Consumes: `POST /users/<id>/availability/hours`, `POST /users/<id>/leave`, `DELETE /users/<id>/leave/<leave_id>` (Tasks 2–3); `ApiError` from `../api` for field errors; `Field`, `TextInput`, `Button` from `../kit`.
- Produces: `HoursForm` and `LeaveForm` panels inside the screen file; a remove button per leave row.

- [ ] **Step 1: Add the failing tests**

Append to `tests/e2e/availability-app.spec.js`:

```js
test('setting the week from the screen changes the total without a reload', async ({ page }) => {
  await page.getByTestId('bwx-screen-availability').click();
  await page.getByTestId('bwx-availability-person').selectOption(person.id);
  await expect(page.getByTestId('bwx-availability-week-hours')).toHaveText('36h');

  await page.getByTestId('bwx-availability-set-hours').click();
  const form = page.getByTestId('bwx-availability-hours-form');
  await form.getByTestId('bwx-availability-effective-from').fill('2021-01-01');
  for (const day of ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun']) {
    await form.getByTestId(`bwx-availability-hours-hours_${day}`).fill('5');
  }
  await form.getByTestId('bwx-availability-hours-save').click();

  await expect(form).toBeHidden();
  await expect(page.getByTestId('bwx-availability-week-hours')).toHaveText('35h');
  await expect(page.locator('[data-testid="bwx-availability-history"] tbody tr')).toHaveCount(2);
});

test('a week with no date is refused on the form, in words', async ({ page }) => {
  await page.getByTestId('bwx-screen-availability').click();
  await page.getByTestId('bwx-availability-person').selectOption(person.id);

  await page.getByTestId('bwx-availability-set-hours').click();
  const form = page.getByTestId('bwx-availability-hours-form');
  await form.getByTestId('bwx-availability-effective-from').fill('');
  await form.getByTestId('bwx-availability-hours-save').click();

  await expect(form.getByTestId('bwx-availability-hours-notice')).toContainText('date');
});

test('time off is added from the screen, listed, and taken out of the week', async ({ page }) => {
  const day = (offset) => {
    const d = new Date();
    d.setUTCDate(d.getUTCDate() + offset);

    return d.toISOString().slice(0, 10);
  };

  await page.getByTestId('bwx-screen-availability').click();
  await page.getByTestId('bwx-availability-person').selectOption(person.id);
  await expect(page.getByTestId('bwx-availability-week-hours')).toHaveText('35h');

  await page.getByTestId('bwx-availability-add-leave').click();
  const form = page.getByTestId('bwx-availability-leave-form');
  await form.getByTestId('bwx-availability-leave-starts').fill(day(0));
  await form.getByTestId('bwx-availability-leave-ends').fill(day(6));
  await form.getByTestId('bwx-availability-leave-kind').selectOption('training');
  await form.getByTestId('bwx-availability-leave-note').fill(`Course ${STAMP}`);
  await form.getByTestId('bwx-availability-leave-save').click();

  await expect(form).toBeHidden();
  await expect(page.getByTestId('bwx-availability-week-hours')).toHaveText('0h');
  const row = page.locator('[data-testid="bwx-availability-leave"] tbody tr', { hasText: `Course ${STAMP}` });
  await expect(row).toBeVisible();
  await expect(row).toContainText('Training');
});

test('removing time off gives the week back', async ({ page }) => {
  await page.getByTestId('bwx-screen-availability').click();
  await page.getByTestId('bwx-availability-person').selectOption(person.id);

  const row = page.locator('[data-testid="bwx-availability-leave"] tbody tr', { hasText: `Course ${STAMP}` });
  page.once('dialog', (dialog) => dialog.accept());
  await row.getByTestId('bwx-availability-leave-remove').click();

  await expect(row).toHaveCount(0);
  await expect(page.getByTestId('bwx-availability-week-hours')).toHaveText('35h');
});
```

- [ ] **Step 2: Run to see them fail**

Run: `npx playwright test tests/e2e/availability-app.spec.js --workers=1`
Expected: the four new tests FAIL (no `bwx-availability-set-hours`); the first three pass.

- [ ] **Step 3: Add writing to the screen**

In `src/components/AvailabilityScreen.tsx`:

Change the imports:

```tsx
import { api, ApiError, isDenied, messageFor } from '../api';
import { Button, DataView, EmptyState, Field, Panel, Select, Stat, TextInput } from '../kit';
```

Inside `AvailabilityScreen`, after the `notice` state, add:

```tsx
  const [ panel, setPanel ] = useState< 'hours' | 'leave' | null >( null );
  const [ busy, setBusy ] = useState( false );

  /** A write's answer is the whole picture, so it is shown rather than re-read. */
  function landed( fresh: AvailabilityAnswer ) {
    setAnswer( fresh );
    setPanel( null );
  }

  async function removeLeave( record: LeaveRecord ) {
    if ( ! window.confirm( `Remove ${ kindLabel( record.kind ).toLowerCase() } from ${ record.starts_on } to ${ record.ends_on }?` ) ) {
      return;
    }

    setBusy( true );
    setNotice( '' );

    try {
      landed( await api< AvailabilityAnswer >( `/users/${ personId }/leave/${ record.id }`, { method: 'DELETE' } ) );
    } catch ( error ) {
      setNotice( messageFor( error, 'That time off could not be removed.' ) );
    } finally {
      setBusy( false );
    }
  }
```

Add a remove column to `leaveColumns`:

```tsx
    {
      key: 'actions',
      label: '',
      width: 100,
      render: ( l ) => (
        <Button variant="ghost" size="sm" data-testid="bwx-availability-leave-remove" disabled={ busy } onClick={ () => void removeLeave( l ) }>
          Remove
        </Button>
      ),
    },
```

Give the two panels their buttons — change `<Panel title="Working week">` to:

```tsx
          <Panel
            title="Working week"
            right={
              <Button size="sm" data-testid="bwx-availability-set-hours" disabled={ busy } onClick={ () => setPanel( 'hours' ) }>
                Set hours
              </Button>
            }
          >
```

and `<Panel title="Time off">` to:

```tsx
          <Panel
            title="Time off"
            right={
              <Button size="sm" data-testid="bwx-availability-add-leave" disabled={ busy } onClick={ () => setPanel( 'leave' ) }>
                Add time off
              </Button>
            }
          >
```

At the end of the ready fragment (after the Time off panel, inside the `<>...</>`), add:

```tsx
          { 'hours' === panel && <HoursForm personId={ personId } current={ answer.current } onClose={ () => setPanel( null ) } onSaved={ landed } /> }
          { 'leave' === panel && <LeaveForm personId={ personId } onClose={ () => setPanel( null ) } onSaved={ landed } /> }
```

Then add the two forms at the bottom of the file:

```tsx
/** What a form shows when the server refuses by field, or otherwise. */
function refusal( error: unknown, fallback: string ): string {
  const fields = error instanceof ApiError ? ( error.data.fields as Record< string, string > | undefined ) : undefined;

  return fields ? Object.values( fields ).join( ' ' ) : messageFor( error, fallback );
}

/** The side panel both forms sit in, shaped as the recurring form is. */
function Aside( { label, testId, onClose, children }: { label: string; testId: string; onClose: () => void; children: React.ReactNode } ) {
  return (
    <div className="bwx-panel-scrim" onClick={ ( event ) => event.target === event.currentTarget && onClose() }>
      <aside className="bwx-panel" role="dialog" aria-modal="true" aria-label={ label } data-testid={ testId } onKeyDown={ ( event ) => 'Escape' === event.key && onClose() }>
        <header className="bwx-panel-head">
          <h2 className="bwx-panel-title">{ label }</h2>
          <button type="button" className="bwx-icon-button" onClick={ onClose } aria-label="Close">
            ✕
          </button>
        </header>
        { children }
      </aside>
    </div>
  );
}

function today(): string {
  return new Date().toISOString().slice( 0, 10 );
}

/** Recording a working week from a date. Starts from the week in force, so a small change is a small edit. */
function HoursForm( {
  personId,
  current,
  onClose,
  onSaved,
}: {
  personId: string;
  current: AvailabilityPattern | null;
  onClose: () => void;
  onSaved: ( answer: AvailabilityAnswer ) => void;
} ) {
  const [ from, setFrom ] = useState( today() );
  const [ week, setWeek ] = useState< Record< string, string > >( () =>
    Object.fromEntries( DAYS.map( ( [ key ] ) => [ key, current ? hours( current[ key ] ) : '' ] ) )
  );
  const [ note, setNote ] = useState( '' );
  const [ notice, setNotice ] = useState( '' );
  const [ busy, setBusy ] = useState( false );

  async function save() {
    setBusy( true );
    setNotice( '' );

    const body: Record< string, unknown > = { effective_from: from, note };

    for ( const [ key ] of DAYS ) {
      body[ key ] = Number( week[ key ] ) || 0;
    }

    try {
      onSaved( await api< AvailabilityAnswer >( `/users/${ personId }/availability/hours`, { method: 'POST', body } ) );
    } catch ( error ) {
      setNotice( refusal( error, 'Those hours could not be saved.' ) );
    } finally {
      setBusy( false );
    }
  }

  return (
    <Aside label="Set working hours" testId="bwx-availability-hours-form" onClose={ onClose }>
      { '' !== notice && (
        <p className="bwx-notice" data-testid="bwx-availability-hours-notice" role="status">
          { notice }
        </p>
      ) }

      <Field label="From" required help="A new week from this date. Earlier weeks keep the hours they had.">
        { ( id ) => <TextInput id={ id } type="date" data-testid="bwx-availability-effective-from" value={ from } onChange={ ( event ) => setFrom( event.target.value ) } /> }
      </Field>

      <div className="bwx-availability-hours-grid">
        { DAYS.map( ( [ key, label ] ) => (
          <Field key={ key } label={ label }>
            { ( id ) => (
              <TextInput
                id={ id }
                type="number"
                min="0"
                step="0.25"
                inputMode="decimal"
                data-testid={ `bwx-availability-hours-${ key }` }
                value={ week[ key ] }
                onChange={ ( event ) => setWeek( { ...week, [ key ]: event.target.value } ) }
              />
            ) }
          </Field>
        ) ) }
      </div>

      <Field label="Note">
        { ( id ) => <TextInput id={ id } data-testid="bwx-availability-hours-note" value={ note } onChange={ ( event ) => setNote( event.target.value ) } /> }
      </Field>

      <div className="bwx-moves">
        <Button data-testid="bwx-availability-hours-save" disabled={ busy } onClick={ () => void save() }>
          Save
        </Button>
        <Button variant="ghost" onClick={ onClose }>
          Cancel
        </Button>
      </div>
    </Aside>
  );
}

/** Recording time somebody is away. */
function LeaveForm( { personId, onClose, onSaved }: { personId: string; onClose: () => void; onSaved: ( answer: AvailabilityAnswer ) => void } ) {
  const [ starts, setStarts ] = useState( today() );
  const [ ends, setEnds ] = useState( today() );
  const [ kind, setKind ] = useState< LeaveRecord[ 'kind' ] >( 'leave' );
  const [ note, setNote ] = useState( '' );
  const [ notice, setNotice ] = useState( '' );
  const [ busy, setBusy ] = useState( false );

  async function save() {
    setBusy( true );
    setNotice( '' );

    try {
      onSaved(
        await api< AvailabilityAnswer >( `/users/${ personId }/leave`, {
          method: 'POST',
          body: { starts_on: starts, ends_on: ends, kind, note },
        } )
      );
    } catch ( error ) {
      setNotice( refusal( error, 'That time off could not be saved.' ) );
    } finally {
      setBusy( false );
    }
  }

  return (
    <Aside label="Add time off" testId="bwx-availability-leave-form" onClose={ onClose }>
      { '' !== notice && (
        <p className="bwx-notice" data-testid="bwx-availability-leave-notice" role="status">
          { notice }
        </p>
      ) }

      <Field label="First day" required>
        { ( id ) => <TextInput id={ id } type="date" data-testid="bwx-availability-leave-starts" value={ starts } onChange={ ( event ) => setStarts( event.target.value ) } /> }
      </Field>
      <Field label="Last day" required help="Both days are included.">
        { ( id ) => <TextInput id={ id } type="date" data-testid="bwx-availability-leave-ends" value={ ends } onChange={ ( event ) => setEnds( event.target.value ) } /> }
      </Field>
      <Field label="Kind">
        { ( id ) => <Select id={ id } data-testid="bwx-availability-leave-kind" value={ kind } onChange={ ( event ) => setKind( event.target.value as LeaveRecord[ 'kind' ] ) } options={ KINDS } /> }
      </Field>
      <Field label="Note">
        { ( id ) => <TextInput id={ id } data-testid="bwx-availability-leave-note" value={ note } onChange={ ( event ) => setNote( event.target.value ) } /> }
      </Field>

      <div className="bwx-moves">
        <Button data-testid="bwx-availability-leave-save" disabled={ busy } onClick={ () => void save() }>
          Add
        </Button>
        <Button variant="ghost" onClick={ onClose }>
          Cancel
        </Button>
      </div>
    </Aside>
  );
}
```

Add `import type { ReactNode } from 'react';` and use `ReactNode` in `Aside`'s props instead of `React.ReactNode`.

`.bwx-panel-title`: check `src/styles.css` ~line 547 for what the recurring form's `h2` uses; the recurring form inlines a style. Add a class rule instead:

```css
.bwx-panel-title {
  flex: 1;
  margin: 0;
  font-size: var( --text-subheading );
  font-weight: 500;
}

.bwx-availability-hours-grid {
  display: grid;
  grid-template-columns: repeat( 4, minmax( 0, 1fr ) );
  gap: var( --space-2, 8px );
}
```

Append both to `src/styles.css` under the availability block.

- [ ] **Step 4: Build and run**

Run: `npm run build` then `npx playwright test tests/e2e/availability-app.spec.js --workers=1`
Expected: 7 passed.

If the "no date" test fails because the browser's date input refuses `fill('')`: the input is `type="date"`, and Playwright's `fill('')` clears it. If the server receives `''` it answers 400 with `fields.effective_from` = "Say the date these hours start from." which contains "date". If it does not, check what the form sent.

- [ ] **Step 5: Commit**

```bash
git add src/components/AvailabilityScreen.tsx src/styles.css tests/e2e/availability-app.spec.js
git commit -m "Availability screen sets hours and records time off

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 8: Landing on a screen from a link

**Files:**
- Modify: `src/App.tsx:187-202, 345, mount line`
- Test: `tests/e2e/availability-app.spec.js`

**Interfaces:**
- Produces: the app page opens on `#screen=<name>` and, for availability, `&person=<id>`; the hash is cleared after reading, as `#item=` already is.

- [ ] **Step 1: Add the failing test**

Append to `tests/e2e/availability-app.spec.js`:

```js
test('a link with the screen and person in the hash lands on them', async ({ page }) => {
  await page.goto(`/blueworx-forge/#screen=availability&person=${person.id}`);

  await expect(page.getByTestId('bwx-availability')).toBeVisible({ timeout: 30_000 });
  await expect(page.getByTestId('bwx-availability-person')).toHaveValue(person.id);
  await expect(page.getByTestId('bwx-availability-week-hours')).toHaveText('35h');
  // Read once and cleared, so a reload is a plain reload.
  expect(new URL(page.url()).hash).toBe('');
});
```

- [ ] **Step 2: Run to see it fail**

Run: `npx playwright test tests/e2e/availability-app.spec.js --workers=1`
Expected: FAIL — the board opens, not availability.

- [ ] **Step 3: Read the landing from the hash**

In `src/App.tsx`, replace the `linkedItem` state (lines ~187–202, from `const [ screen, setScreen ] = useState< ScreenName >( 'work' );` through the closing `} );` of the `linkedItem` initializer) with:

```tsx
  /**
   * A link can land on a screen: from Slack on the task in the hash (PR 5),
   * or from anywhere on a screen and, for availability, a person. Read once
   * and cleared, so a reload is a plain reload.
   */
  const [ landing ] = useState( () => {
    const hash = window.location.hash;
    const item = /(?:^|[#&])item=([A-Za-z0-9_-]+)/.exec( hash )?.[ 1 ] ?? '';
    const screen = /(?:^|[#&])screen=([a-z]+)/.exec( hash )?.[ 1 ] ?? '';
    const person = /(?:^|[#&])person=([A-Za-z0-9_-]+)/.exec( hash )?.[ 1 ] ?? '';

    if ( '' !== item || '' !== screen ) {
      window.history.replaceState( null, '', window.location.pathname + window.location.search );
    }

    return { item, screen: screen in TITLES ? ( screen as ScreenName ) : null, person };
  } );
  const [ screen, setScreen ] = useState< ScreenName >( landing.screen ?? 'work' );
```

`TITLES` is a `Record< ScreenName, string >` declared above the component, so `screen in TITLES` is the check that the name is a real screen.

Change the work mount from `openItem={ linkedItem }` to `openItem={ landing.item }`, and the availability mount to:

```tsx
        { 'availability' === screen && <AvailabilityScreen key={ generation } person={ landing.person } /> }
```

- [ ] **Step 4: Build and run everything this PR touched**

Run: `npm run build`

Run: `npx playwright test tests/e2e/availability-app.spec.js tests/e2e/availability-rest.spec.js tests/e2e/shell.spec.js tests/e2e/app-page.spec.js tests/e2e/slack.spec.js --workers=1`
Expected: all pass. `shell.spec.js` and `app-page.spec.js` cover the rail and the landing; `slack.spec.js` covers `#item=` — if it does not exist under that name, find the spec that asserts on `item=` in the hash (`grep -l "item=" tests/e2e/*.spec.js`) and run that.

- [ ] **Step 5: Commit**

```bash
git add src/App.tsx tests/e2e/availability-app.spec.js
git commit -m "The app opens on a screen and person named in the link

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 9: Accessibility check, version, changelog, lint, PR

**Files:**
- Modify: `package.json:4`, `blueworx-forge.php:6,28`, `client/blueworx-forge-client.php:6,42`, `CHANGELOG.md`
- Modify: `src/App.tsx` header comment (lines ~35–36)

- [ ] **Step 1: Accessibility on the new screen**

Look at `tests/e2e/accessibility.spec.js` — it walks the app's screens through the shared `tests/helpers/accessibility.js`. Add to `APP_SCREENS` (line 35), after the Reports entry:

```js
  [ 'Availability', 'bwx-screen-availability' ],
```

 Run: `npx playwright test tests/e2e/accessibility.spec.js --workers=1`. Expected: pass, including the new screen. Fix any violation it names in `AvailabilityScreen.tsx` (a missing label is the likely one).

- [ ] **Step 2: Fix the shell's stale comment**

In `src/App.tsx` header, the lines:

```
 * Packages & hours and Sync health are still WordPress admin screens; the
 * rail links to them so nothing is further away than it was.
```

stay true (Packages moves in PR 2). Add one line after them:

```
 * Availability is the first configuration screen to move in (spec
 * 2026-09-16); the rest follow it, one pull request each.
```

- [ ] **Step 3: Version and changelog**

Change `2.107.0` to `2.108.0` in all five places listed in Global Constraints.

At the top of `CHANGELOG.md`, directly above `## [2.107.0] - 2026-09-15`, add (with today's date):

```markdown
## [2.108.0] - 2026-09-16

### Added

- Availability is now in the app, under Team: pick a person, see their week, set their hours and record time off, without going to WordPress admin. The admin page stays for now.
```

- [ ] **Step 4: Lint once, build once**

Run: `npm run lint`
Run: `composer lint`
Run: `npm run build`

Expected: all clean. If `npm run lint` reports findings, do not fix them in a loop — note them for the user in the PR description and stop.

- [ ] **Step 5: Full studio suite**

Run: `npm test -- --workers=1`
Expected: green. If a red spec is unrelated to this PR and an aged `.wp-test` is the likely cause (clients/people/accessibility), run `npm run wp:down && npm run wp:up` and rerun that spec before concluding anything.

- [ ] **Step 6: Commit the built assets and the bump**

`npm run build` writes `assets/js/blueworx-forge.js` and `assets/css/blueworx-forge.css`; they are committed.

```bash
git add assets package.json blueworx-forge.php client/blueworx-forge-client.php CHANGELOG.md src/App.tsx tests/e2e/accessibility.spec.js
git commit -m "Forge 2.108.0: Availability is in the app

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

- [ ] **Step 7: Push and open a draft PR**

```bash
git push -u origin availability-in-app
gh pr create --draft --title "Forge 2.108.0: Availability is in the app" --body "$(cat <<'EOF'
Availability moves from WordPress admin into the app, under a new Team group on the rail. Four REST routes back it; the admin page stays until every screen has moved (PR 7 of the spec).

Specs that set working hours now do it over REST, so the cutover has nothing left to move there.

Not run here: <pair suite, if it was not run — say so, or delete this line>.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

Do not merge.

---

## Self-review against the spec

- "GET /users/<id>/availability … pattern in force, history, leave a year either side, next seven days" — Task 1.
- "POST hours … append-only" — Task 2.
- "POST leave … replay-safe" — Task 3. "DELETE leave" — Task 3.
- "Every write returns the same answer the GET gives" — Tasks 2–3 return `answer()`; the screen uses it (Task 7 `landed`).
- "Administrator-only" — every route uses `Permissions::manage`; Task 2 tests the 403.
- "scope with kind and reason" — Task 1's `$scope`.
- "Person picker … a screen can be opened by hash with &person=" — Tasks 6 and 8.
- "Rail: Team → Availability" — Task 5.
- "REST spec and screen spec" — Tasks 1–3 and 5–8.
- "Shared helpers move to REST" — Task 4 (the hours calls are inline in four specs, not in a helper; the plan adds the helper and switches them).
- "Admin page and its spec untouched" — nothing in the plan edits them.
- "Version and changelog" — Task 9.
- Not in this PR, by the spec: no bulk actions, no export, nothing beyond what the admin page does.
