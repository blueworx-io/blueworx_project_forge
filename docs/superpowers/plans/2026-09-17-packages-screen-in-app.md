# Packages Screen in the App — Implementation Plan (PR 2 of 7)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The support package catalogue — add, revise (always a new version), retire or restore, reorder — becomes a screen in the React studio app under Insight, backed by REST; the WordPress admin page stays untouched until PR 7.

**Architecture:** A new `Rest\PackagesController` exposes five routes over the existing `Commerce\Packages` and `Commerce\Terms` classes (no domain code changes). A new `PackagesScreen` React component, registered in `App.tsx` in place of the "Packages & hours" link out, reads and writes those routes; its add/revise form sits in the kit's `Modal`. The specs that create packages through the admin form switch to a shared REST helper so the eventual cutover has nothing left to move there.

**Tech Stack:** PHP 7.4+ (WordPress REST API, WPCS), React 18 + TypeScript (Vite), the shared kit in `src/kit/`, Playwright against the local WordPress harness.

**Spec:** `docs/superpowers/specs/2026-09-16-admin-screens-to-app-design.md` — sections "What every PR does" and "PR 2 — Packages".

## Global Constraints

- Branch off `availability-in-app` (PR 1, not yet merged), never `main`. Branch name: `packages-in-app`. The draft PR targets `availability-in-app`; when PR 1 merges, retarget it to `main` before PR 1's branch is deleted (a merged parent otherwise closes its child).
- Version: `2.108.0` → `2.109.0` in `package.json`, `blueworx-forge.php` (header and `BWX_FORGE_VERSION`), `client/blueworx-forge-client.php` (header and `BWX_FORGE_CLIENT_VERSION`). All five must agree or CI fails.
- Changelog entry in `CHANGELOG.md` under a new `## [2.109.0] - <today>` heading, written for the person using it.
- No new dependencies (`approved-deps.json` is closed). Ordering is up/down buttons, not drag: the kit has no drag piece and one is not worth a dependency.
- Nothing under `client/` changes except the version lines.
- Every route registers through `Server::register_route()` with a `scope` array carrying `kind` and `reason`, or `Boundary::apply()` refuses it.
- Every route uses `Permissions::manage` (administrator only), the GET included: the admin page requires `manage_options` and the spec says permissions do not change.
- Errors through `Errors::rest( code, message, status, data )` — the code lands as `bwx_forge_<code>`.
- A write whose replay would leave a second record takes an `Idempotency-Key` header the way `AvailabilityController::add_leave()` does. Adding a package is that write. Revising is not: `Packages::revise()` writes nothing when the terms are unchanged, so a replay is already a no-op — say so in the docblock.
- The controller does no validation the domain class does not do. The only validation is `Terms::refuse( Terms::sanitise( … ) )`, and it is called to get the sentence to show, not to decide anything the domain class would not.
- Every write answers with the whole catalogue as the screen will show it, so the screen never re-reads after a write.
- Every interactive element in the screen carries a `data-testid` starting `bwx-`.
- Nothing styled outside the kit; the one screen-specific layout class goes in `src/styles.css` next to `.bwx-availability` (line ~2579).
- Results are shown in place: the write's answer replaces the list, a refusal shows in the form. `useToast` is not used — the app does not mount a `ToastProvider`.
- PHP: tabs, WPCS, `declare( strict_types = 1 )`, `final class`, docblocks on every method. Run `composer lint` before each PHP commit.
- TS: match the surrounding style (spaces inside parens and braces, single quotes). Run `npm run lint` once at the end, not in a loop.
- The admin page (`includes/Admin/PackagesScreen.php`, `PackageActions.php`) and its spec (`tests/e2e/package-catalogue.spec.js`) are NOT touched.
- The local harness: `npm run wp:up` once (WordPress on http://127.0.0.1:8892, `admin`/`admin`); the studio site links this repo, so a `npm run build` is live immediately. Never build while a Playwright run is in progress. Set `WP_ADMIN_USER=admin WP_ADMIN_PASS=admin` for full-suite runs (the activation spec needs them).
- Playwright specs create everything they need with a run id; the instance is reused between runs, so the catalogue already holds many packages from earlier runs. Find rows by the run-id name, never by position.

---

## File map

| File | Responsibility |
|------|----------------|
| `includes/Rest/PackagesController.php` (create) | Five routes; shapes the answer; calls `Commerce\Packages` and `Commerce\Terms` |
| `includes/Rest/Server.php` (modify, line 94) | Registers the controller |
| `tests/e2e/packages-rest.spec.js` (create) | Route behaviour |
| `tests/e2e/helpers/forge.js` (modify) | `put` on the caller; `makePackage()` over REST; `onSupport()` uses it |
| `tests/e2e/support-assignment.spec.js`, `tests/pair/acceptance-commercial.spec.js` (modify) | Create their package over REST |
| `src/types.ts` (modify) | `'packages'` in `ScreenName`; `PackageVersion`, `SupportPackage`, `PackagesAnswer` |
| `src/kit/overlays.tsx` (modify) | `Modal` takes an optional `testId` |
| `src/App.tsx` (modify) | Rail entry replaces the link out; `TITLES`, `OPENINGS`, mount |
| `src/components/PackagesScreen.tsx` (create) | The screen: catalogue, versions, form, status, order |
| `src/styles.css` (modify) | `.bwx-packages` root rule |
| `tests/e2e/packages-app.spec.js` (create) | The screen, driven as a person would |
| `tests/e2e/shell.spec.js`, `tests/e2e/accessibility.spec.js` (modify) | Rail assertion; screen in the accessibility walk |
| `docs/superpowers/specs/2026-09-16-admin-screens-to-app-design.md` (modify) | One line: results in place, not `useToast` |
| `package.json`, `blueworx-forge.php`, `client/blueworx-forge-client.php`, `CHANGELOG.md` | Version and changelog |

## Answer shape (every route)

```json
{
  "ok": true,
  "packages": [
    {
      "id": "pkg_…", "name": "Standard", "status": "active", "position": 10,
      "retired_at": 0, "created_at": 1758000000, "updated_at": 1758000000,
      "created_by": 1, "record_version": 1,
      "current": { "id": "pkv_…", "package_id": "pkg_…", "version": 1, "name": "Standard", "hours": 12, "price": 1200, "currency": "GBP", "validity_months": 12, "terms": "", "created_at": 1758000000, "created_by": 1 },
      "versions": [ { "…newest first…" } ]
    }
  ]
}
```

Writes add `package` (the one written, in the same shape) and, for a revision, `changed` (whether a new version was minted). `packages` is the whole catalogue in its own order, every status, as `Packages::all()` returns it.

---

### Task 1: Branch, the controller, and the read route

**Files:**
- Create: `includes/Rest/PackagesController.php`
- Modify: `includes/Rest/Server.php:94`
- Create: `tests/e2e/packages-rest.spec.js`

**Interfaces:**
- Consumes: `Packages::all()`, `Packages::current_versions( ids )`, `Packages::versions_for( id )`, `Packages::get( id )` — see `includes/Commerce/Packages.php`.
- Produces: `GET /packages` → the answer shape above. `PackagesController::answer(): array` and `PackagesController::shape( array $package ): array` for later tasks.

- [ ] **Step 1: Branch**

```bash
git checkout availability-in-app
git pull
git checkout -b packages-in-app
```

- [ ] **Step 2: Write the failing test**

Create `tests/e2e/packages-rest.spec.js`:

```js
import { test, expect } from '@playwright/test';
import { signedIn, makeSite, makePerson, PASSWORD } from './helpers/forge.js';

// PR 2 of the move out of WordPress admin: the package catalogue over REST.
// Nothing here is deleted and the instance is reused, so every name carries
// a run id and every assertion finds its own package by id.
const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';
const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const STAMP = RUN_ID.replace('-', '');
const BASE = '/wp-json/blueworx-forge/v1';

const TERMS = { name: `Standard ${RUN_ID}`, hours: 12, price: 1200, currency: 'GBP', validity_months: 12, terms: 'Twelve hours a month.' };

test.describe.configure({ mode: 'serial' });

let api;
let context;
let person;

test.beforeAll(async ({ browser, baseURL }) => {
  ({ context, api } = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS));
  const where = await makeSite(api, 'Packages REST', RUN_ID);
  person = await makePerson(api, where.client.id, 'staff', `pkg${STAMP}`);
});

test.afterAll(async () => {
  await context?.close();
});

test('the catalogue reads in its own order, each package with its current version and history', async () => {
  const answer = await api.get('/packages');

  expect(answer.ok).toBe(true);
  expect(Array.isArray(answer.packages)).toBe(true);

  const positions = answer.packages.map((one) => one.position);
  expect(positions).toEqual([...positions].sort((a, b) => a - b));

  for (const one of answer.packages) {
    expect(one.current === null || one.current.package_id === one.id).toBe(true);
    expect(Array.isArray(one.versions)).toBe(true);
  }
});

test('somebody who is not an administrator cannot read the catalogue', async ({ browser, baseURL }) => {
  const other = await signedIn(browser, baseURL, person.login, PASSWORD);

  const read = await other.api.request.get(`${BASE}/packages`, { headers: other.api.headers });
  expect(read.status()).toBe(403);

  await other.context.close();
});
```

- [ ] **Step 3: Run it to see it fail**

Run: `npx playwright test tests/e2e/packages-rest.spec.js --workers=1`
Expected: the first test fails (404 from WordPress: no such route).

- [ ] **Step 4: The controller**

Create `includes/Rest/PackagesController.php`:

```php
<?php
/**
 * The support package catalogue, over REST.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Commerce\Packages;
use Blueworx\Forge\Commerce\Terms;
use WP_REST_Request;

/**
 * What the package catalogue admin screen does, as routes, so the studio app
 * can be the one place it is done (PR 2 of spec 2026-09-16). The writes are
 * the ones `Admin\PackageActions` makes; the rules are `Commerce\Packages`'
 * and `Commerce\Terms`' own. Every answer is the whole catalogue, so a
 * screen that has just written never has to read again.
 *
 * COMM-1 is visible in the shape: a package is revised by posting a new
 * version, and no route edits a version that exists.
 *
 * Administrators only, reads included: the admin page it replaces requires
 * the same, and the catalogue is configuration, not work.
 */
final class PackagesController {

	/**
	 * The idempotency operation for adding a package.
	 */
	private const CREATE_OPERATION = 'packages.create';

	/**
	 * Registers this controller's routes.
	 *
	 * @param string $route_namespace REST namespace.
	 */
	public static function register_routes( string $route_namespace ): void {
		$scope = array(
			'kind'   => Boundary::SCOPE_OPEN,
			'reason' => 'The catalogue is the studio\'s, offered to every site alike. Administrator-only configuration.',
		);

		Server::register_route(
			$route_namespace,
			'/packages',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'read' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => $scope,
			)
		);
	}

	/**
	 * The whole catalogue.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function read( WP_REST_Request $request ) {
		unset( $request );

		return rest_ensure_response( self::answer() );
	}

	/**
	 * What every route answers with: every package, in the catalogue's own
	 * order, each with the version in force and every version there has been.
	 *
	 * @return array<string, mixed>
	 */
	private static function answer(): array {
		$packages = Packages::all();
		$current  = Packages::current_versions( array_column( $packages, 'id' ) );
		$shaped   = array();

		foreach ( $packages as $package ) {
			$shaped[] = self::shape( $package, $current[ (string) $package['id'] ] ?? null );
		}

		return array(
			'ok'       => true,
			'packages' => $shaped,
		);
	}

	/**
	 * One package as the screen shows it.
	 *
	 * @param array<string, mixed>      $package The catalogue row.
	 * @param array<string, mixed>|null $current Its current version, when the caller already has it.
	 * @return array<string, mixed>
	 */
	private static function shape( array $package, ?array $current = null ): array {
		$id = (string) $package['id'];

		return array_merge(
			$package,
			array(
				'current'  => $current ?? Packages::current_version( $id ),
				'versions' => Packages::versions_for( $id ),
			)
		);
	}
}
```

- [ ] **Step 5: Register it**

In `includes/Rest/Server.php`, after line 94 (`AvailabilityController::register_routes( self::NAMESPACE );`), add:

```php
		PackagesController::register_routes( self::NAMESPACE );
```

- [ ] **Step 6: Run the test to see it pass**

Run: `composer lint` — expected clean for the new file (27 pre-existing CRLF findings in `client/` and `includes/Work/Transition.php` are not yours).
Run: `npx playwright test tests/e2e/packages-rest.spec.js --workers=1`
Expected: 2 passed.

- [ ] **Step 7: Commit**

```bash
git add includes/Rest/PackagesController.php includes/Rest/Server.php tests/e2e/packages-rest.spec.js
git commit -m "Packages over REST: the catalogue reads with every version

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Adding a package

**Files:**
- Modify: `includes/Rest/PackagesController.php`
- Modify: `tests/e2e/packages-rest.spec.js`

**Interfaces:**
- Consumes: `Packages::create( array $values, int $author ): ?array`, `Terms::sanitise()`, `Terms::refuse()`, `Idempotency::HEADER`, `Idempotency::is_valid_key()`, `Idempotency::replay( op, key )`, `Idempotency::remember( op, key, response )` (see `includes/Rest/AvailabilityController.php::add_leave()` for the exact shape).
- Produces: `POST /packages` with body `{ name, hours, price, currency, validity_months, terms }` → `{ package, ok, packages }`; `400 bwx_forge_invalid_package` with the domain's sentence as `message`; replay-safe under `Idempotency-Key`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/e2e/packages-rest.spec.js`:

```js
let made;

test('adding a package writes version 1 and puts it at the end of the catalogue', async () => {
  const wrote = await api.post('/packages', TERMS);
  expect(wrote.status(), await wrote.text()).toBe(200);

  const answer = await wrote.json();
  made = answer.package;

  expect(made.id).toMatch(/^pkg_/);
  expect(made.status).toBe('active');
  expect(made.current.version).toBe(1);
  expect(made.current.hours).toBe(12);
  expect(made.current.price).toBe(1200);
  expect(made.versions).toHaveLength(1);

  const last = answer.packages[answer.packages.length - 1];
  expect(last.id).toBe(made.id);
});

test('a package with no name, or no hours, is refused with the reason', async () => {
  const noName = await api.post('/packages', { ...TERMS, name: '' });
  expect(noName.status()).toBe(400);
  const noNameBody = await noName.json();
  expect(noNameBody.code).toBe('bwx_forge_invalid_package');
  expect(noNameBody.message).toContain('needs a name');

  const noHours = await api.post('/packages', { ...TERMS, name: `Empty ${RUN_ID}`, hours: 0 });
  expect(noHours.status()).toBe(400);
  expect((await noHours.json()).message).toContain('some hours');
});

test('a replayed add under one retry key makes one package, not two', async () => {
  const key = `package-${RUN_ID}`;
  const body = { ...TERMS, name: `Replayed ${RUN_ID}` };
  const headers = { ...api.headers, 'Idempotency-Key': key };

  const first = await api.request.post(`${BASE}/packages`, { headers, data: body });
  const again = await api.request.post(`${BASE}/packages`, { headers, data: body });

  expect(first.status()).toBe(200);
  expect(again.status()).toBe(200);
  expect((await again.json()).package.id).toBe((await first.json()).package.id);

  const answer = await api.get('/packages');
  expect(answer.packages.filter((one) => one.name === body.name)).toHaveLength(1);
});

test('somebody who is not an administrator cannot add a package', async ({ browser, baseURL }) => {
  const other = await signedIn(browser, baseURL, person.login, PASSWORD);

  const wrote = await other.api.post('/packages', { ...TERMS, name: `Denied ${RUN_ID}` });
  expect(wrote.status()).toBe(403);

  await other.context.close();
});
```

- [ ] **Step 2: Run to see them fail**

Run: `npx playwright test tests/e2e/packages-rest.spec.js --workers=1`
Expected: the three new non-403 tests fail with 404.

- [ ] **Step 3: The route**

In `register_routes()`, after the GET registration, add:

```php
		Server::register_route(
			$route_namespace,
			'/packages',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'add' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => $scope,
			)
		);
```

After `read()`, add:

```php
	/**
	 * Adds a package, and its first version.
	 *
	 * Replay-safe under an idempotency key: a resend that made a second
	 * package would put two identical offers on the shelf.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function add( WP_REST_Request $request ) {
		$key = (string) $request->get_header( Idempotency::HEADER );

		if ( '' !== $key ) {
			if ( ! Idempotency::is_valid_key( $key ) ) {
				return Errors::rest( 'invalid_idempotency_key', __( 'That retry key cannot be used.', 'blueworx-forge' ), 400 );
			}

			$replay = Idempotency::replay( self::CREATE_OPERATION, $key );

			if ( null !== $replay ) {
				return rest_ensure_response( $replay );
			}
		}

		$terms  = self::submitted( $request );
		$reason = Terms::refuse( Terms::sanitise( $terms ) );

		if ( '' !== $reason ) {
			return Errors::rest( 'invalid_package', $reason, 400 );
		}

		$package = Packages::create( $terms, get_current_user_id() );

		if ( null === $package ) {
			return Errors::rest( 'write_failed', __( 'That package could not be saved.', 'blueworx-forge' ), 500 );
		}

		$response = array_merge( array( 'package' => self::shape( $package ) ), self::answer() );

		if ( '' !== $key ) {
			Idempotency::remember( self::CREATE_OPERATION, $key, $response );
		}

		return rest_ensure_response( $response );
	}

	/**
	 * The terms as posted, untouched beyond what WordPress does to text.
	 *
	 * Cleaning up is {@see Terms::sanitise()}'s job and is not repeated here,
	 * for the reason the admin actions give: two places that tidy the same
	 * values are two places that can disagree about what a valid one is.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private static function submitted( WP_REST_Request $request ): array {
		$body = (array) $request->get_json_params();

		return array(
			'name'            => sanitize_text_field( (string) ( $body['name'] ?? '' ) ),
			'hours'           => (float) ( $body['hours'] ?? 0 ),
			'price'           => (float) ( $body['price'] ?? 0 ),
			'currency'        => sanitize_text_field( (string) ( $body['currency'] ?? 'GBP' ) ),
			'validity_months' => (int) ( $body['validity_months'] ?? Terms::DEFAULT_VALIDITY_MONTHS ),
			'terms'           => sanitize_textarea_field( (string) ( $body['terms'] ?? '' ) ),
		);
	}
```

- [ ] **Step 4: Run to see them pass**

Run: `composer lint`
Run: `npx playwright test tests/e2e/packages-rest.spec.js --workers=1`
Expected: 6 passed.

- [ ] **Step 5: Commit**

```bash
git add includes/Rest/PackagesController.php tests/e2e/packages-rest.spec.js
git commit -m "Packages over REST: adding one writes version 1, replay-safe

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Revising, retiring, restoring and reordering

**Files:**
- Modify: `includes/Rest/PackagesController.php`
- Modify: `tests/e2e/packages-rest.spec.js`

**Interfaces:**
- Consumes: `Packages::revise( id, values, author ): ?array` (returns the package unchanged when the terms are the same), `Packages::current_version( id )`, `Packages::set_status( id, status ): ?array`, `Terms::is_status()`, `Packages::reorder( ids ): int`, `Packages::get( id )`.
- Produces:
  - `POST /packages/<id>/versions` body as Task 2 → `{ package, changed: bool, ok, packages }`; `404 bwx_forge_unknown_package`; `400 bwx_forge_invalid_package`.
  - `PATCH /packages/<id>` body `{ status }` → `{ package, ok, packages }`; `400 bwx_forge_invalid_status`; `404 bwx_forge_unknown_package`.
  - `PUT /packages/order` body `{ order: [ids] }` → `{ ok, packages }`; `400 bwx_forge_invalid_order` when `order` is not a list.

- [ ] **Step 1: Write the failing tests**

Append to `tests/e2e/packages-rest.spec.js`:

```js
test('revising writes version 2 and leaves version 1 exactly as it was', async () => {
  const wrote = await api.post(`/packages/${made.id}/versions`, { ...TERMS, hours: 15, price: 1500 });
  expect(wrote.status(), await wrote.text()).toBe(200);

  const answer = await wrote.json();
  expect(answer.changed).toBe(true);
  expect(answer.package.current.version).toBe(2);
  expect(answer.package.current.hours).toBe(15);
  expect(answer.package.versions).toHaveLength(2);

  const first = answer.package.versions.find((one) => one.version === 1);
  expect(first.hours).toBe(12);
  expect(first.price).toBe(1200);
});

test('revising with the same terms writes nothing and says so', async () => {
  const wrote = await api.post(`/packages/${made.id}/versions`, { ...TERMS, hours: 15, price: 1500 });
  expect(wrote.status(), await wrote.text()).toBe(200);

  const answer = await wrote.json();
  expect(answer.changed).toBe(false);
  expect(answer.package.current.version).toBe(2);
  expect(answer.package.versions).toHaveLength(2);
});

test('a revision that is not an offer is refused, and a package that is not there is a 404', async () => {
  const refused = await api.post(`/packages/${made.id}/versions`, { ...TERMS, hours: 0 });
  expect(refused.status()).toBe(400);
  expect((await refused.json()).code).toBe('bwx_forge_invalid_package');

  const missing = await api.post(`/packages/pkg_nobody${STAMP}/versions`, TERMS);
  expect(missing.status()).toBe(404);
  expect((await missing.json()).code).toBe('bwx_forge_unknown_package');
});

test('a package can be retired and put back, and only those two', async () => {
  const retired = await api.patch(`/packages/${made.id}`, { status: 'retired' });
  expect(retired.status(), await retired.text()).toBe(200);
  const gone = (await retired.json()).package;
  expect(gone.status).toBe('retired');
  expect(gone.retired_at).toBeGreaterThan(0);
  expect(gone.current.version).toBe(2);

  const restored = await api.patch(`/packages/${made.id}`, { status: 'active' });
  expect(restored.status()).toBe(200);
  const back = (await restored.json()).package;
  expect(back.status).toBe('active');
  expect(back.retired_at).toBe(0);

  const nonsense = await api.patch(`/packages/${made.id}`, { status: 'deleted' });
  expect(nonsense.status()).toBe(400);
  expect((await nonsense.json()).code).toBe('bwx_forge_invalid_status');

  const missing = await api.patch(`/packages/pkg_nobody${STAMP}`, { status: 'retired' });
  expect(missing.status()).toBe(404);
});

test('the catalogue takes the order it is given, and a package left out keeps its place after the rest', async () => {
  const second = (await (await api.post('/packages', { ...TERMS, name: `Second ${RUN_ID}` })).json()).package;
  const third = (await (await api.post('/packages', { ...TERMS, name: `Third ${RUN_ID}` })).json()).package;

  const before = (await api.get('/packages')).packages.map((one) => one.id);
  const others = before.filter((id) => ![made.id, second.id, third.id].includes(id));

  // Everything else first, in the order it had; then third, then made; second left out.
  const wrote = await api.put('/packages/order', { order: [...others, third.id, made.id] });
  expect(wrote.status(), await wrote.text()).toBe(200);

  const after = (await wrote.json()).packages.map((one) => one.id);
  expect(after.slice(-3)).toEqual([third.id, made.id, second.id]);

  const junk = await api.put('/packages/order', { order: 'first' });
  expect(junk.status()).toBe(400);
  expect((await junk.json()).code).toBe('bwx_forge_invalid_order');
});
```

`api.put` does not exist yet: in `tests/e2e/helpers/forge.js`, in `forge()`, after the `patch:` line add:

```js
    put: (path, data) => request.put(`${BASE}${path}`, { headers, data }),
```

- [ ] **Step 2: Run to see them fail**

Run: `npx playwright test tests/e2e/packages-rest.spec.js --workers=1`
Expected: the five new tests fail with 404.

- [ ] **Step 3: The routes**

In `register_routes()`, after the POST registration, add:

```php
		Server::register_route(
			$route_namespace,
			'/packages/order',
			array(
				'methods'             => 'PUT',
				'callback'            => array( self::class, 'reorder' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => $scope,
			)
		);

		Server::register_route(
			$route_namespace,
			'/packages/(?P<package_id>[A-Za-z0-9_\-]+)',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( self::class, 'set_status' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => $scope,
			)
		);

		Server::register_route(
			$route_namespace,
			'/packages/(?P<package_id>[A-Za-z0-9_\-]+)/versions',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'revise' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => $scope,
			)
		);
```

`/packages/order` is registered before the `<package_id>` pattern so that "order" is never read as a package id.

After `add()`, add:

```php
	/**
	 * Writes the next version of a package.
	 *
	 * Always an append, as {@see Packages::revise()} is: no route edits a
	 * version that exists, which is COMM-1 made visible. Not idempotency-keyed
	 * because a replay is already a no-op — the same terms twice write nothing
	 * the second time, and the answer says so in `changed`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function revise( WP_REST_Request $request ) {
		$package_id = (string) $request['package_id'];
		$before     = Packages::current_version( $package_id );

		if ( null === Packages::get( $package_id ) || null === $before ) {
			return self::unknown_package();
		}

		$terms  = self::submitted( $request );
		$reason = Terms::refuse( Terms::sanitise( $terms ) );

		if ( '' !== $reason ) {
			return Errors::rest( 'invalid_package', $reason, 400 );
		}

		$package = Packages::revise( $package_id, $terms, get_current_user_id() );

		if ( null === $package ) {
			return Errors::rest( 'write_failed', __( 'That version could not be saved.', 'blueworx-forge' ), 500 );
		}

		$shaped = self::shape( $package );

		return rest_ensure_response(
			array_merge(
				array(
					'package' => $shaped,
					'changed' => null !== $shaped['current'] && (int) $shaped['current']['version'] > (int) $before['version'],
				),
				self::answer()
			)
		);
	}

	/**
	 * Takes a package off the shelf, or puts it back. Nothing else about a
	 * package is edited in place; the rest is a version.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function set_status( WP_REST_Request $request ) {
		$package_id = (string) $request['package_id'];

		if ( null === Packages::get( $package_id ) ) {
			return self::unknown_package();
		}

		$body   = (array) $request->get_json_params();
		$status = sanitize_key( (string) ( $body['status'] ?? '' ) );

		if ( ! Terms::is_status( $status ) ) {
			return Errors::rest( 'invalid_status', __( 'A package is on the shelf or retired; nothing else.', 'blueworx-forge' ), 400 );
		}

		$package = Packages::set_status( $package_id, $status );

		if ( null === $package ) {
			return Errors::rest( 'write_failed', __( 'That package could not be changed.', 'blueworx-forge' ), 500 );
		}

		return rest_ensure_response( array_merge( array( 'package' => self::shape( $package ) ), self::answer() ) );
	}

	/**
	 * Puts the catalogue in the given order. Anything left out keeps its place
	 * after everything named ({@see Packages::reorder()}).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function reorder( WP_REST_Request $request ) {
		$body  = (array) $request->get_json_params();
		$order = $body['order'] ?? null;

		if ( ! is_array( $order ) ) {
			return Errors::rest( 'invalid_order', __( 'Say which packages go in which order.', 'blueworx-forge' ), 400 );
		}

		Packages::reorder( array_map( 'sanitize_text_field', array_map( 'strval', $order ) ) );

		return rest_ensure_response( self::answer() );
	}

	/**
	 * The refusal for a package that is not there.
	 *
	 * @return \WP_Error
	 */
	private static function unknown_package() {
		return Errors::rest( 'unknown_package', __( 'There is no such package.', 'blueworx-forge' ), 404 );
	}
```

- [ ] **Step 4: Run to see them pass**

Run: `composer lint`
Run: `npx playwright test tests/e2e/packages-rest.spec.js --workers=1`
Expected: 11 passed.

- [ ] **Step 5: Commit**

```bash
git add includes/Rest/PackagesController.php tests/e2e/packages-rest.spec.js tests/e2e/helpers/forge.js
git commit -m "Packages over REST: revise as a new version, retire, restore, reorder

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: The test helper, and the specs that add packages through the admin form

**Files:**
- Modify: `tests/e2e/helpers/forge.js` (`onSupport()`, ~line 288; new `makePackage()`)
- Modify: `tests/e2e/support-assignment.spec.js:40-56`
- Modify: `tests/pair/acceptance-commercial.spec.js:76-90`

**Interfaces:**
- Consumes: `POST /packages` from Task 2.
- Produces: `makePackage(api, label, { hours = 12, price = 1200, validity_months = 12 } = {})` → the `package` from the answer. `api` is anything with `.post` — `admin.api` from `signedIn()`, or `pair.studio` from the pair helper, which is itself a caller.

The support-assignment half of `onSupport()` (the assign form on the Support admin page) stays on the admin form: it is PR 5's route.

- [ ] **Step 1: The helper**

In `tests/e2e/helpers/forge.js`, above `onSupport()`, add:

```js
/**
 * Adds a package to the catalogue over REST, and returns it.
 *
 * A name of its own each time, because the instance is shared between runs
 * and a name reused across specs leaves several identical rows with no way
 * to say which is this one's. `api` is any caller with `.post` — a
 * `signedIn()` admin's `api`, or the pair helper's `studio`.
 */
export async function makePackage(api, label, { hours = 12, price = 1200, validity_months = 12 } = {}) {
  const wrote = await api.post('/packages', { name: label, hours, price, currency: 'GBP', validity_months, terms: '' });
  expect(wrote.status(), await wrote.text()).toBe(200);

  return (await wrote.json()).package;
}
```

Replace the package-form block in `onSupport()` — from `const page = await admin.context.newPage();` through `await expect(page.locator('[data-bwx-result="added"]')).toBeVisible();` — with:

```js
  await makePackage(admin.api ?? admin, label, { hours, price: 1000 });

  const page = await admin.context.newPage();
```

Keep everything from `await page.goto(\`/wp-admin/admin.php?page=blueworx-forge-support&site=${siteId}\`);` onwards unchanged. Update the helper's docblock: replace "A package of its own each time, because …" paragraph's first sentence with "The package is added over REST; the assignment still goes through the Support admin page until that screen moves (PR 5)." and keep the rest.

- [ ] **Step 2: `support-assignment.spec.js`**

Replace lines 44–55 (from `const page = await admin.context.newPage();` through `await expect(page.locator('[data-bwx-result="added"]')).toBeVisible();`) with:

```js
  await Forge.makePackage(admin.api, label, { hours: 12, price: 1200 });

  const page = await admin.context.newPage();
```

If `PACKAGES` (line 24) is now unused, remove it.

- [ ] **Step 3: `acceptance-commercial.spec.js` AC-13**

Replace lines 77–90 (from the `// A package whose terms are unambiguous` comment through `await expect(page.locator('[data-bwx-result="added"]')).toBeVisible();`) with:

```js
    // A package whose terms are unambiguous: forty hours, twelve months.
    await Forge.makePackage(pair.studio, label, { hours: 40, price: 1200, validity_months: 12 });
```

If `PACKAGES` (line 22) is now unused, remove it.

- [ ] **Step 4: Run the three**

Run: `npx playwright test tests/e2e/support-assignment.spec.js tests/e2e/support-hours-gate.spec.js --workers=1`
Expected: pass.

Run: `npm run wp:pair:up` then `npx playwright test -c playwright.pair.config.js tests/pair/acceptance-commercial.spec.js --workers=1`
Expected: pass. (If the pair harness cannot be brought up, say so in the report; do not skip silently.)

- [ ] **Step 5: Commit**

```bash
git add tests/e2e/helpers/forge.js tests/e2e/support-assignment.spec.js tests/pair/acceptance-commercial.spec.js
git commit -m "Specs add packages over REST, not through the admin form

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: The screen exists on the rail, and lists the catalogue

**Files:**
- Modify: `src/types.ts:145` and after the `AvailabilityAnswer` interface (~line 770)
- Modify: `src/kit/overlays.tsx` (`Modal`)
- Modify: `src/App.tsx` (imports, header comment lines 36–37, `RAIL` line 62, `TITLES` ~137, `OPENINGS` ~167, mount ~363)
- Create: `src/components/PackagesScreen.tsx`
- Modify: `src/styles.css` after the Availability block (~line 2579+)
- Modify: `tests/e2e/shell.spec.js:53-56`, `tests/e2e/accessibility.spec.js:35-43`
- Create: `tests/e2e/packages-app.spec.js`

**Interfaces:**
- Produces: `ScreenName` gains `'packages'`; types `PackageStatus`, `PackageVersion`, `SupportPackage`, `PackagesAnswer`; `Modal` accepts `testId?: string`; `PackagesScreen` exported from `src/components/PackagesScreen.tsx`; test ids `bwx-screen-packages`, `bwx-packages`, `bwx-packages-state`, `bwx-packages-list`, `bwx-packages-count`.

- [ ] **Step 1: Types**

In `src/types.ts` line 145, add `| 'packages'` to `ScreenName`. After the `AvailabilityAnswer` interface, add:

```ts
/* ---- Packages (PR 2 of spec 2026-09-16) ---- */

export type PackageStatus = 'active' | 'retired';

/** One frozen version of a package. Written once; never edited (COMM-1). */
export interface PackageVersion {
  id: string;
  package_id: string;
  version: number;
  name: string;
  hours: number;
  price: number;
  currency: string;
  validity_months: number;
  terms: string;
  created_at: number;
  created_by: number;
}

export interface SupportPackage {
  id: string;
  name: string;
  status: PackageStatus;
  position: number;
  retired_at: number;
  created_at: number;
  updated_at: number;
  created_by: number;
  record_version: number;
  current: PackageVersion | null;
  versions: PackageVersion[];
}

export interface PackagesAnswer {
  ok: true;
  packages: SupportPackage[];
  package?: SupportPackage;
  changed?: boolean;
}
```

- [ ] **Step 2: `Modal` takes a test id**

In `src/kit/overlays.tsx`, add `testId` to `Modal`'s props (`testId?: string;` after `onClose: () => void;`, and `testId,` in the destructuring), and put `data-testid={ testId }` on the `fk-dialog` div. Nothing else changes.

- [ ] **Step 3: Failing tests**

`tests/e2e/shell.spec.js` lines 53–56: replace the two-link block with:

```js
  // Sync health is the one screen still in WordPress admin; it is a link away.
  await expect( rail.getByTestId( 'bwx-link-sync' ) ).toHaveAttribute( 'href', /wp-admin\/admin\.php\?page=blueworx-forge-sync$/ );
```

`tests/e2e/accessibility.spec.js`: add `[ 'Packages', 'bwx-screen-packages' ],` after the Availability entry.

Create `tests/e2e/packages-app.spec.js`:

```js
import { test, expect } from '@playwright/test';
import { signIn } from '../helpers/sign-in.js';
import { signedIn, makePackage } from './helpers/forge.js';

// The package catalogue in the app: see what is on offer, add one, revise
// it as a new version, retire and restore, reorder. Names carry a run id
// because the instance is reused between runs and already holds packages.
const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';
const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;

test.describe.configure({ mode: 'serial' });

let admin;
let seeded;

test.beforeAll(async ({ browser, baseURL }) => {
  admin = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  seeded = await makePackage(admin.api, `Seeded ${RUN_ID}`, { hours: 10, price: 900 });
});

test.afterAll(async () => {
  await admin?.context.close();
});

test.beforeEach(async ({ page }) => {
  await signIn(page, ADMIN_USER, ADMIN_PASS);
  await page.goto('/blueworx-forge/');
  await page.getByTestId('bwx-screen-packages').click();
  await expect(page.getByTestId('bwx-packages')).toBeVisible({ timeout: 30_000 });
  await expect(page.getByTestId('bwx-packages-count')).toBeVisible({ timeout: 30_000 });
});

/** The catalogue row for one package, found by its name. */
function rowFor(page, name) {
  return page.getByTestId('bwx-packages-list').locator('tbody tr', { hasText: name });
}

test('the rail offers Packages under Insight, and the catalogue lists what is on offer', async ({ page }) => {
  await expect(page.locator('h1')).toHaveText('Support packages');

  const row = rowFor(page, seeded.name);
  await expect(row).toBeVisible();
  await expect(row).toContainText('On the shelf');
  await expect(row).toContainText('10h');
  await expect(row).toContainText('v1');
});
```

Run: `npx playwright test tests/e2e/packages-app.spec.js tests/e2e/shell.spec.js --workers=1`
Expected: both fail (no rail entry; the packages link assertion is gone but the screen is not there).

- [ ] **Step 4: The rail**

In `src/App.tsx`:

- Import `PackagesScreen` from `./components/PackagesScreen` next to the `AvailabilityScreen` import. `Receipt` is already imported.
- Header comment: replace the two lines "Packages & hours and Sync health are still WordPress admin screens; the rail links to them so nothing is further away than it was." with "Sync health is still a WordPress admin screen; the rail links to it so it is no further away than it was."
- `RAIL`: delete the `{ href: 'admin.php?page=blueworx-forge-packages', … }` entry. Under `{ group: 'Insight' }`, after the Subscriptions entry, add:

```ts
  { key: 'packages', label: 'Packages', icon: Receipt, testId: 'bwx-screen-packages' },
```

- `TITLES`: add `packages: 'Support packages',`.
- `OPENINGS`: add `packages: { crumbs: [ 'Insight', 'Packages' ], eyebrow: 'What is on offer, and every version of it', tile: Receipt, hue: 'emerald' },`.
- Mount, next to the Availability line: `{ 'packages' === screen && <PackagesScreen key={ generation } /> }`.

- [ ] **Step 5: The screen, list only**

Create `src/components/PackagesScreen.tsx`:

```tsx
import { useEffect, useState } from 'react';
import { Receipt } from 'lucide-react';
import type { PackagesAnswer, PackageVersion, SupportPackage } from '../types';
import { api, isDenied, messageFor } from '../api';
import { useLiveReload } from '../live';
import { DataView, EmptyState, Panel, Tag } from '../kit';
import type { Column } from '../kit';
import { Screen } from './States';

/**
 * The support package catalogue (#145), in the app.
 *
 * The second configuration screen to leave WordPress admin. Reads and writes
 * `/packages` and nothing else; the admin page stays beside it until every
 * screen has moved.
 *
 * COMM-1 is what the screen is for: a package is never edited, it is revised,
 * and the revision is a new version with every earlier one still in the
 * list. The form says so in words before anybody presses Save.
 */

/** 12 → "12h", 7.5 → "7.5h". */
export function hoursLabel( value: number ): string {
  return `${ String( Number( value.toFixed( 2 ) ) ) }h`;
}

/** 1200, 'GBP' → "GBP 1,200". The code rather than a symbol: it is what the record holds. */
export function priceLabel( price: number, currency: string ): string {
  return `${ currency } ${ price.toLocaleString( 'en-GB' ) }`;
}

function dateOf( seconds: number ): string {
  return new Date( seconds * 1000 ).toISOString().slice( 0, 10 );
}

export function PackagesScreen() {
  const [ packages, setPackages ] = useState< SupportPackage[] >( [] );
  const [ state, setState ] = useState< 'loading' | 'ready' | 'denied' | 'error' >( 'loading' );
  const [ notice, setNotice ] = useState( '' );

  async function load() {
    setNotice( '' );

    try {
      const fresh = await api< PackagesAnswer >( '/packages' );

      setPackages( fresh.packages );
      setState( 'ready' );
    } catch ( error ) {
      setState( isDenied( error ) ? 'denied' : 'error' );
      setNotice( messageFor( error, 'The catalogue could not be read.' ) );
    }
  }

  useEffect( () => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load();
  }, [] );

  useLiveReload( () => load() );

  const columns: Column< SupportPackage >[] = [
    { key: 'name', label: 'Name', wrap: true, render: ( p ) => p.name },
    {
      key: 'status',
      label: 'Status',
      width: 120,
      render: ( p ) => ( 'retired' === p.status ? <Tag tone="neutral">Retired</Tag> : <Tag tone="ok">On the shelf</Tag> ),
    },
    { key: 'hours', label: 'Hours', mono: true, align: 'right', width: 80, render: ( p ) => ( p.current ? hoursLabel( p.current.hours ) : '—' ) },
    { key: 'price', label: 'Price', mono: true, align: 'right', width: 120, render: ( p ) => ( p.current ? priceLabel( p.current.price, p.current.currency ) : '—' ) },
    { key: 'months', label: 'Runs for', mono: true, align: 'right', width: 100, render: ( p ) => ( p.current ? `${ p.current.validity_months } months` : '—' ) },
    { key: 'version', label: 'Version', mono: true, align: 'right', width: 80, render: ( p ) => ( p.current ? `v${ p.current.version }` : '—' ) },
  ];

  return (
    <div className="bwx-packages" data-testid="bwx-packages">
      { 'loading' === state && <Screen state="loading" testId="bwx-packages-state" /> }
      { 'denied' === state && <Screen state="denied" testId="bwx-packages-state" detail="The catalogue is configuration, and configuration is the administrator's." /> }
      { 'error' === state && <Screen state="error" testId="bwx-packages-state" detail={ notice } /> }

      { 'ready' === state && (
        <>
          { '' !== notice && (
            <p className="bwx-notice" data-testid="bwx-packages-notice" role="status">
              { notice }
            </p>
          ) }

          <Panel title="The catalogue">
            <DataView< SupportPackage >
              columns={ columns }
              rows={ packages }
              sortable={ false }
              empty={ <EmptyState icon={ Receipt } dense title="No packages yet" body="Add the first one. It becomes version 1, and every change after that is a new version." /> }
              footer={ `${ packages.length } in the catalogue, in the order clients see them` }
              testId="bwx-packages-list"
            />
            <span data-testid="bwx-packages-count" data-count={ packages.length } className="bwx-visually-hidden">
              { `${ packages.length } packages` }
            </span>
          </Panel>
        </>
      ) }
    </div>
  );
}

// Referenced by later tasks; kept here so the file compiles with the unused import rule.
export type { PackageVersion };
```

(The `PackageVersion` re-export is scaffolding: Task 6 uses the type and removes the re-export line.)

In `src/styles.css`, after the Availability block, add:

```css
/* ---- Packages (#145) ---- */

.bwx-packages {
  flex: 1;
  min-height: 0;
  padding: 20px;
  overflow: auto;
  display: grid;
  gap: 16px;
  align-content: start;
}
```

Copy the exact property set from `.bwx-availability` (line ~2580) if it differs from the above — the two screens are laid out the same.

- [ ] **Step 6: Build, run**

Run: `npm run build`
Run: `npx playwright test tests/e2e/packages-app.spec.js tests/e2e/shell.spec.js tests/e2e/accessibility.spec.js --workers=1`
Expected: all pass.

- [ ] **Step 7: Commit**

```bash
git add src/types.ts src/kit/overlays.tsx src/App.tsx src/components/PackagesScreen.tsx src/styles.css tests/e2e/shell.spec.js tests/e2e/accessibility.spec.js tests/e2e/packages-app.spec.js
git commit -m "Packages screen in the app: the catalogue, under Insight

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

Do not commit `assets/` until Task 8.

---

### Task 6: Versions, add, revise, retire and restore

**Files:**
- Modify: `src/components/PackagesScreen.tsx`
- Modify: `tests/e2e/packages-app.spec.js`

**Interfaces:**
- Consumes: routes from Tasks 2–3; `Modal` with `testId` from Task 5; kit `Field`, `TextInput`, `TextArea`, `Button`.
- Produces test ids: `bwx-packages-add`, `bwx-packages-revise`, `bwx-packages-status`, `bwx-packages-history`, `bwx-packages-selected` (the versions panel root, `data-package=<id>`), form `bwx-packages-form`, `-form-name`, `-form-hours`, `-form-price`, `-form-currency`, `-form-months`, `-form-terms`, `-form-save`, `-form-cancel`, `-form-notice`, `-form-hint`.

- [ ] **Step 1: Failing tests**

Append to `tests/e2e/packages-app.spec.js`:

```js
test('a package is added from a panel and appears on the shelf as version 1', async ({ page }) => {
  const name = `Added ${RUN_ID}`;

  await page.getByTestId('bwx-packages-add').click();
  const form = page.getByTestId('bwx-packages-form');
  await expect(form).toBeVisible();

  await form.getByTestId('bwx-packages-form-name').fill(name);
  await form.getByTestId('bwx-packages-form-hours').fill('20');
  await form.getByTestId('bwx-packages-form-price').fill('2000');
  await form.getByTestId('bwx-packages-form-months').fill('6');
  await form.getByTestId('bwx-packages-form-terms').fill('Twenty hours over six months.');
  await form.getByTestId('bwx-packages-form-save').click();

  await expect(form).toBeHidden();
  const row = rowFor(page, name);
  await expect(row).toBeVisible();
  await expect(row).toContainText('20h');
  await expect(row).toContainText('GBP 2,000');
  await expect(row).toContainText('6 months');
  await expect(row).toContainText('v1');
});

test('a package with no name is refused in the form, with the reason', async ({ page }) => {
  await page.getByTestId('bwx-packages-add').click();
  const form = page.getByTestId('bwx-packages-form');

  await form.getByTestId('bwx-packages-form-hours').fill('5');
  await form.getByTestId('bwx-packages-form-save').click();

  await expect(form.getByTestId('bwx-packages-form-notice')).toContainText('needs a name');
  await form.getByTestId('bwx-packages-form-cancel').click();
  await expect(form).toBeHidden();
});

test('picking a package shows every version; revising writes the next one and keeps the last', async ({ page }) => {
  await rowFor(page, seeded.name).click();

  const selected = page.getByTestId('bwx-packages-selected');
  await expect(selected).toHaveAttribute('data-package', seeded.id);
  await expect(selected.getByTestId('bwx-packages-history').locator('tbody tr')).toHaveCount(1);

  await page.getByTestId('bwx-packages-revise').click();
  const form = page.getByTestId('bwx-packages-form');
  await expect(form.getByTestId('bwx-packages-form-hint')).toContainText('version 2');
  await expect(form.getByTestId('bwx-packages-form-hours')).toHaveValue('10');

  await form.getByTestId('bwx-packages-form-hours').fill('12');
  await form.getByTestId('bwx-packages-form-save').click();

  await expect(form).toBeHidden();
  await expect(rowFor(page, seeded.name)).toContainText('v2');
  await expect(rowFor(page, seeded.name)).toContainText('12h');

  const history = selected.getByTestId('bwx-packages-history').locator('tbody tr');
  await expect(history).toHaveCount(2);
  await expect(history.filter({ hasText: 'v1' })).toContainText('10h');
  await expect(history.filter({ hasText: 'v2' })).toContainText('12h');
});

test('revising without changing anything writes no version, and says so', async ({ page }) => {
  await rowFor(page, seeded.name).click();
  await page.getByTestId('bwx-packages-revise').click();
  await page.getByTestId('bwx-packages-form-save').click();

  await expect(page.getByTestId('bwx-packages-form')).toBeHidden();
  await expect(page.getByTestId('bwx-packages-notice')).toContainText('Nothing changed');
  await expect(rowFor(page, seeded.name)).toContainText('v2');
});

test('a package is retired, and put back on the shelf', async ({ page }) => {
  await rowFor(page, seeded.name).click();

  page.once('dialog', (dialog) => dialog.accept());
  await page.getByTestId('bwx-packages-status').click();
  await expect(rowFor(page, seeded.name)).toContainText('Retired');
  await expect(page.getByTestId('bwx-packages-status')).toHaveText('Put back on the shelf');

  page.once('dialog', (dialog) => dialog.accept());
  await page.getByTestId('bwx-packages-status').click();
  await expect(rowFor(page, seeded.name)).toContainText('On the shelf');
  await expect(page.getByTestId('bwx-packages-status')).toHaveText('Retire');
});
```

Run: `npx playwright test tests/e2e/packages-app.spec.js --workers=1`
Expected: the five new tests fail (no `bwx-packages-add`).

- [ ] **Step 2: The screen**

In `src/components/PackagesScreen.tsx`:

Imports become:

```tsx
import { useEffect, useState } from 'react';
import { Receipt } from 'lucide-react';
import type { PackagesAnswer, PackageVersion, SupportPackage } from '../types';
import { api, isDenied, messageFor } from '../api';
import { useLiveReload } from '../live';
import { Button, DataView, EmptyState, Field, Modal, Panel, Tag, TextArea, TextInput } from '../kit';
import type { Column } from '../kit';
import { Screen } from './States';
```

Delete the `export type { PackageVersion };` scaffolding line.

Add state inside `PackagesScreen()` after `notice`:

```tsx
  const [ selectedId, setSelectedId ] = useState< string | null >( null );
  const [ panel, setPanel ] = useState< 'add' | 'revise' | null >( null );
  const [ busy, setBusy ] = useState( false );

  const selected = packages.find( ( one ) => one.id === selectedId ) ?? null;

  /** A write's answer is the whole catalogue, so it is shown rather than re-read. */
  function landed( fresh: PackagesAnswer, said = '' ) {
    setPackages( fresh.packages );
    setPanel( null );
    setNotice( said );

    if ( fresh.package ) {
      setSelectedId( fresh.package.id );
    }
  }

  async function setStatus( target: SupportPackage ) {
    const retiring = 'active' === target.status;
    const question = retiring
      ? `Retire ${ target.name }? Nobody new can be put on it; everybody already on it keeps it.`
      : `Put ${ target.name } back on the shelf?`;

    if ( ! window.confirm( question ) ) {
      return;
    }

    setBusy( true );
    setNotice( '' );

    try {
      landed( await api< PackagesAnswer >( `/packages/${ target.id }`, { method: 'PATCH', body: { status: retiring ? 'retired' : 'active' } } ) );
    } catch ( error ) {
      setNotice( messageFor( error, 'That package could not be changed.' ) );
    } finally {
      setBusy( false );
    }
  }
```

Add a versions column set after `columns`:

```tsx
  const versionColumns: Column< PackageVersion >[] = [
    { key: 'version', label: 'Version', mono: true, width: 80, render: ( v ) => `v${ v.version }` },
    { key: 'name', label: 'Name', wrap: true, render: ( v ) => v.name },
    { key: 'hours', label: 'Hours', mono: true, align: 'right', width: 80, render: ( v ) => hoursLabel( v.hours ) },
    { key: 'price', label: 'Price', mono: true, align: 'right', width: 120, render: ( v ) => priceLabel( v.price, v.currency ) },
    { key: 'months', label: 'Runs for', mono: true, align: 'right', width: 100, render: ( v ) => `${ v.validity_months } months` },
    { key: 'from', label: 'From', mono: true, width: 120, render: ( v ) => dateOf( v.created_at ) },
    { key: 'terms', label: 'Terms', wrap: true, clamp: 2, render: ( v ) => v.terms || '—' },
  ];
```

In the JSX: give the catalogue `Panel` a `right` of

```tsx
<Button size="sm" data-testid="bwx-packages-add" disabled={ busy } onClick={ () => setPanel( 'add' ) }>Add a package</Button>
```

and the catalogue `DataView` `selectedId={ selectedId }` and `onRowClick={ ( p ) => setSelectedId( p.id ) }`.

After the catalogue `Panel`, inside the ready fragment, add:

```tsx
          { selected && (
            <div data-testid="bwx-packages-selected" data-package={ selected.id }>
              <Panel
                title={ `Every version of ${ selected.name }` }
                right={
                  <div className="bwx-moves">
                    <Button size="sm" data-testid="bwx-packages-revise" disabled={ busy } onClick={ () => setPanel( 'revise' ) }>
                      Revise
                    </Button>
                    <Button size="sm" variant="ghost" data-testid="bwx-packages-status" disabled={ busy } onClick={ () => void setStatus( selected ) }>
                      { 'active' === selected.status ? 'Retire' : 'Put back on the shelf' }
                    </Button>
                  </div>
                }
              >
                <DataView< PackageVersion >
                  columns={ versionColumns }
                  rows={ selected.versions }
                  sortable={ false }
                  footer={ `${ selected.versions.length } ${ 1 === selected.versions.length ? 'version' : 'versions' }. Each one was written once and never changed.` }
                  testId="bwx-packages-history"
                />
              </Panel>
            </div>
          ) }

          { 'add' === panel && <PackageForm onClose={ () => setPanel( null ) } onSaved={ landed } /> }
          { 'revise' === panel && selected && <PackageForm revising={ selected } onClose={ () => setPanel( null ) } onSaved={ landed } /> }
```

Below `PackagesScreen`, add the form:

```tsx
/** Adding a package, or revising one. Revising starts from the version in force, so a small change is a small edit. */
function PackageForm( {
  revising,
  onClose,
  onSaved,
}: {
  revising?: SupportPackage;
  onClose: () => void;
  onSaved: ( answer: PackagesAnswer, said?: string ) => void;
} ) {
  const from = revising?.current ?? null;
  const next = ( from?.version ?? 0 ) + 1;
  const [ name, setName ] = useState( from?.name ?? '' );
  const [ hours, setHours ] = useState( from ? String( from.hours ) : '' );
  const [ price, setPrice ] = useState( from ? String( from.price ) : '' );
  const [ currency, setCurrency ] = useState( from?.currency ?? 'GBP' );
  const [ months, setMonths ] = useState( from ? String( from.validity_months ) : '12' );
  const [ terms, setTerms ] = useState( from?.terms ?? '' );
  const [ notice, setNotice ] = useState( '' );
  const [ busy, setBusy ] = useState( false );

  async function save() {
    setBusy( true );
    setNotice( '' );

    const body = { name, hours: Number( hours ) || 0, price: Number( price ) || 0, currency, validity_months: Number( months ) || 0, terms };

    try {
      if ( revising ) {
        const answer = await api< PackagesAnswer >( `/packages/${ revising.id }/versions`, { method: 'POST', body } );

        onSaved( answer, answer.changed ? '' : 'Nothing changed, so no new version was written.' );
      } else {
        onSaved( await api< PackagesAnswer >( '/packages', { method: 'POST', body } ) );
      }
    } catch ( error ) {
      setNotice( messageFor( error, 'That package could not be saved.' ) );
    } finally {
      setBusy( false );
    }
  }

  return (
    <Modal
      title={ revising ? `Revise ${ revising.name }` : 'Add a package' }
      width={ 560 }
      testId="bwx-packages-form"
      onClose={ onClose }
      footer={
        <div className="bwx-moves">
          <Button data-testid="bwx-packages-form-save" disabled={ busy } onClick={ () => void save() }>
            { revising ? `Save as version ${ next }` : 'Add package' }
          </Button>
          <Button variant="ghost" data-testid="bwx-packages-form-cancel" onClick={ onClose }>
            Cancel
          </Button>
        </div>
      }
    >
      { '' !== notice && (
        <p className="bwx-notice" data-testid="bwx-packages-form-notice" role="status">
          { notice }
        </p>
      ) }

      { revising && (
        <p className="bwx-hint" data-testid="bwx-packages-form-hint">
          { `Saving writes version ${ next }. Version ${ from?.version ?? 0 } and every earlier one stay exactly as they are, and anybody on them keeps them.` }
        </p>
      ) }

      <Field label="Name" required>
        { ( id ) => <TextInput id={ id } autoFocus maxLength={ 191 } data-testid="bwx-packages-form-name" value={ name } onChange={ ( event ) => setName( event.target.value ) } /> }
      </Field>
      <Field label="Hours" required help="How many hours the package holds. More than nought.">
        { ( id ) => <TextInput id={ id } type="number" min="0" step="0.25" inputMode="decimal" data-testid="bwx-packages-form-hours" value={ hours } onChange={ ( event ) => setHours( event.target.value ) } /> }
      </Field>
      <Field label="Price">
        { ( id ) => <TextInput id={ id } type="number" min="0" step="1" inputMode="numeric" data-testid="bwx-packages-form-price" value={ price } onChange={ ( event ) => setPrice( event.target.value ) } /> }
      </Field>
      <Field label="Currency" help="A three-letter code, such as GBP.">
        { ( id ) => <TextInput id={ id } maxLength={ 3 } data-testid="bwx-packages-form-currency" value={ currency } onChange={ ( event ) => setCurrency( event.target.value.toUpperCase() ) } /> }
      </Field>
      <Field label="Runs for (months)">
        { ( id ) => <TextInput id={ id } type="number" min="1" max="120" step="1" inputMode="numeric" data-testid="bwx-packages-form-months" value={ months } onChange={ ( event ) => setMonths( event.target.value ) } /> }
      </Field>
      <Field label="Terms">
        { ( id ) => <TextArea id={ id } rows={ 4 } maxLength={ 5000 } data-testid="bwx-packages-form-terms" value={ terms } onChange={ ( event ) => setTerms( event.target.value ) } /> }
      </Field>
    </Modal>
  );
}
```

- [ ] **Step 3: Build, run**

Run: `npm run build`
Run: `npx playwright test tests/e2e/packages-app.spec.js --workers=1`
Expected: 6 passed. If the form's `Modal` steals the Escape key from the screen or `autoFocus` fights the Modal's own focus-on-close-button, keep the Modal's behaviour and drop `autoFocus` — the Modal already lands focus inside the dialog.

- [ ] **Step 4: Commit**

```bash
git add src/components/PackagesScreen.tsx tests/e2e/packages-app.spec.js
git commit -m "Packages screen: every version, add, revise as a new version, retire and restore

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: Ordering

**Files:**
- Modify: `src/components/PackagesScreen.tsx`
- Modify: `tests/e2e/packages-app.spec.js`

**Interfaces:**
- Consumes: `PUT /packages/order` from Task 3.
- Produces test ids: `bwx-packages-up`, `bwx-packages-down` (one each per row).

- [ ] **Step 1: Failing test**

Append to `tests/e2e/packages-app.spec.js`:

```js
test('a package moves up and down the catalogue, and the order is kept', async ({ page }) => {
  // Two of this run's own, added last, so they are neighbours at the end.
  const a = await makePackage(admin.api, `Order A ${RUN_ID}`);
  const b = await makePackage(admin.api, `Order B ${RUN_ID}`);
  await page.reload();
  await expect(page.getByTestId('bwx-packages-count')).toBeVisible({ timeout: 30_000 });

  const names = () => page.getByTestId('bwx-packages-list').locator('tbody tr td:first-child + td').allTextContents();
  const indexOf = async (name) => (await names()).findIndex((text) => text.includes(name));

  expect(await indexOf(a.name)).toBeLessThan(await indexOf(b.name));

  await rowFor(page, b.name).getByTestId('bwx-packages-up').click();
  await expect.poll(async () => (await indexOf(b.name)) < (await indexOf(a.name))).toBe(true);

  await page.reload();
  await expect(page.getByTestId('bwx-packages-count')).toBeVisible({ timeout: 30_000 });
  expect(await indexOf(b.name)).toBeLessThan(await indexOf(a.name));

  await rowFor(page, b.name).getByTestId('bwx-packages-down').click();
  await expect.poll(async () => (await indexOf(a.name)) < (await indexOf(b.name))).toBe(true);

  // The last row cannot go further down.
  await expect(rowFor(page, b.name).getByTestId('bwx-packages-down')).toBeDisabled();
});
```

The `names()` selector assumes the Order column is first and Name second (Step 2 makes it so). If the DataView adds a leading cell of its own, adjust the selector to the Name cell and say so in the report.

Run: `npx playwright test tests/e2e/packages-app.spec.js --workers=1`
Expected: the new test fails (no `bwx-packages-up`).

- [ ] **Step 2: The buttons**

In `PackagesScreen()`, add after `setStatus()`:

```tsx
  /** Swaps a package with its neighbour and sends the whole order, which is what the route takes. */
  async function move( target: SupportPackage, by: -1 | 1 ) {
    const ids = packages.map( ( one ) => one.id );
    const at = ids.indexOf( target.id );
    const to = at + by;

    if ( at < 0 || to < 0 || to >= ids.length ) {
      return;
    }

    [ ids[ at ], ids[ to ] ] = [ ids[ to ], ids[ at ] ];

    setBusy( true );
    setNotice( '' );

    try {
      landed( await api< PackagesAnswer >( '/packages/order', { method: 'PUT', body: { order: ids } } ) );
    } catch ( error ) {
      setNotice( messageFor( error, 'The order could not be saved.' ) );
    } finally {
      setBusy( false );
    }
  }
```

Note `landed()` clears the panel and selects `fresh.package` when present; the order answer carries no `package`, so the selection stays.

Add as the first entry of `columns`:

```tsx
    {
      key: 'order',
      label: 'Order',
      width: 88,
      render: ( p ) => {
        const at = packages.findIndex( ( one ) => one.id === p.id );

        return (
          <span className="bwx-packages-order">
            <Button variant="ghost" size="sm" aria-label={ `Move ${ p.name } up` } data-testid="bwx-packages-up" disabled={ busy || 0 === at } onClick={ ( event ) => { event.stopPropagation(); void move( p, -1 ); } }>
              ↑
            </Button>
            <Button variant="ghost" size="sm" aria-label={ `Move ${ p.name } down` } data-testid="bwx-packages-down" disabled={ busy || at === packages.length - 1 } onClick={ ( event ) => { event.stopPropagation(); void move( p, 1 ); } }>
              ↓
            </Button>
          </span>
        );
      },
    },
```

Check `Button`'s `onClick` type in `src/kit/primitives.tsx:15` — if it does not pass the event, wrap the two buttons in a `<span onClick={ ( event ) => event.stopPropagation() }>` instead, so a click on an arrow does not also select the row.

In `src/styles.css`, under the Packages block:

```css
.bwx-packages-order {
  display: inline-flex;
  gap: 2px;
}
```

- [ ] **Step 3: Build, run**

Run: `npm run build`
Run: `npx playwright test tests/e2e/packages-app.spec.js --workers=1`
Expected: 7 passed.

- [ ] **Step 4: Commit**

```bash
git add src/components/PackagesScreen.tsx src/styles.css tests/e2e/packages-app.spec.js
git commit -m "Packages screen: up and down the catalogue

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 8: Spec correction, version, changelog, lint, full suite, PR

**Files:**
- Modify: `docs/superpowers/specs/2026-09-16-admin-screens-to-app-design.md` (line ~64)
- Modify: `package.json`, `blueworx-forge.php`, `client/blueworx-forge-client.php`, `CHANGELOG.md`
- Commit: `assets/`

- [ ] **Step 1: Spec correction**

In the spec's "Then the screen" list, the line

```
- Lists on the kit's `DataView`; forms in a kit `Panel` opened from the list,
  never inline in a row; confirmations that carry a reason through
  `ReasonAction`; results through `useToast`.
```

becomes

```
- Lists on the kit's `DataView`; forms in a kit `Panel` or `Modal` opened from
  the list, never inline in a row; confirmations that carry a reason through
  `ReasonAction`; results shown in place — the write's answer replaces the
  list, a refusal shows in the form. (`useToast` needs a `ToastProvider` the
  app does not mount; corrected in PR 2.)
```

- [ ] **Step 2: Version and changelog**

Change `2.108.0` to `2.109.0` in all five places listed in Global Constraints.

At the top of `CHANGELOG.md`, directly above `## [2.108.0] - 2026-09-16`, add (with today's date):

```markdown
## [2.109.0] - 2026-09-17

### Added

- Support packages are now in the app, under Insight: see what is on offer, add a package, revise it as a new version with every earlier version still on record, retire or restore it, and put the catalogue in order — without going to WordPress admin. The admin page stays for now.
```

- [ ] **Step 3: Lint once, build once**

Run: `npm run lint`
Run: `composer lint`
Run: `npm run build`

Expected: clean apart from the pre-existing CRLF findings. If `npm run lint` reports findings, do not fix them in a loop — note them in the report and stop.

- [ ] **Step 4: Full studio suite**

Run: `WP_ADMIN_USER=admin WP_ADMIN_PASS=admin npm test -- --workers=1`
Expected: green apart from the three failures already known on `availability-in-app` (admin-page `.bw-stat__foot` contrast in `accessibility.spec.js`'s admin walk, `calendar.spec.js:169`, an occasional `signals.spec.js` count). Any other red is this branch's until shown otherwise; if an aged `.wp-test` is the likely cause, `npm run wp:down && npm run wp:up` and rerun that spec before concluding.

- [ ] **Step 5: Commit the built assets and the bump**

```bash
git add assets package.json blueworx-forge.php client/blueworx-forge-client.php CHANGELOG.md docs/superpowers/specs/2026-09-16-admin-screens-to-app-design.md
git commit -m "Forge 2.109.0: Support packages are in the app

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

- [ ] **Step 6: Push and open a draft PR against PR 1's branch**

```bash
git push -u origin packages-in-app
gh pr create --draft --base availability-in-app --title "Forge 2.109.0: Support packages are in the app" --body "$(cat <<'EOF'
Support packages move from WordPress admin into the app, under Insight, replacing the link out. Five REST routes back it; revising is always a new version, and the form says so. The admin page stays until every screen has moved (PR 7 of the spec).

Specs that add packages now do it over REST.

Stacked on #350: retarget to main once that merges, before its branch is deleted.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

Do not merge.

---

## Self-review

- **Spec coverage.** Five routes (Tasks 1–3); screen with catalogue, expandable-to-versions (selection + versions panel, Task 6), add/revise in a panel with the "version N+1" sentence (Task 6), ordering (Task 7); rail entry under Insight replacing the link out (Task 5); rest/screen specs (Tasks 1–3, 5–7); helper moved to REST (Task 4); admin page and its spec untouched; version and changelog (Task 8). `#screen=packages` works through the landing code PR 1 added; no `&site=`/`&person=` needed.
- **Type consistency.** `PackagesAnswer.package` optional, `changed` optional — the same type covers every route's answer; `landed( fresh, said )` signature matches both `PackageForm.onSaved` and the direct calls. `hoursLabel`/`priceLabel` exported for the spec's readability only.
- **Known trade.** "Expandable row" is selection plus a panel below, because the kit's `DataView` has no row expansion and adding one for a single screen is against the spec's own rule.
