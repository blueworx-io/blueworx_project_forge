# Reminders Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Reminders page: a task on a fixed day or period, for one or more people, on a chosen client, that shows in My Tasks and on the calendar. Recurring tasks gain the same client choice.

**Architecture:** A reminder is a row in the existing recurring table with `kind = 'reminder'`, an id prefixed `rem_`, and no next due date, so the recurring engine never touches it. Saving one makes one ordinary work item per person at once (`Recurring\Reminders`). The calendar feed and My Tasks tell reminder copies apart by the `rem_` prefix on the item's `recurring_id`.

**Tech Stack:** PHP 8.2 (WordPress REST, custom tables), React + TypeScript + Vite, Playwright, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-09-25-reminders-design.md`

## Global Constraints

- Version 2.131.0 in `package.json`, `blueworx-forge.php` (header and `BWX_FORGE_VERSION`), `client/blueworx-forge-client.php` (header and `BWX_FORGE_CLIENT_VERSION`); CHANGELOG entry dated 2026-09-25.
- No new dependency. Icons from `lucide-react` (already installed).
- No schema change; `Schema::VERSION` stays 32.
- Reminder copies: `commercial_class = 'free-general'`, `hours_each = 0`, one assignee each, title = the reminder's title with no date suffix, `planned_start = starts_on`, `planned_due = ends_on ?: starts_on`, placed at Up Next.
- Error words, verbatim: "Choose a client.", "Choose at least one person.", "The end date is before the start date.", "A reminder needs a title."
- Recurring tasks stay administrator-only to write; reminders are writable by any signed-in person who reaches the site, edit/delete by author (`created_by`) or administrator.
- Run lint once at the end only; report findings, don't loop.
- The studio's `.wp-test` site is live-linked to this checkout: never build or edit PHP while a Playwright run is going.

## Review Focus

1. A reminder edited after one person ticked it — the ticked copy must keep its title and dates; only unticked copies change. (Task 2 e2e covers.)
2. Editing a reminder's dates through `Sources::update` must not give it a `next_due`, or the recurring engine will start making daily copies. (Task 2 e2e covers: edit, run the engine, still two copies.)
3. A person who does not reach a client passes that client's site id — refused with "Choose a client.", not a 500. (Task 2 e2e covers.)
4. A single-day reminder on the calendar has `ends_on = ''`, not the same date twice. (Task 4 e2e covers.)
5. Existing recurring tests posting without `client_site_id` now fail with 400 — every caller must be updated. (Task 3 step covers.)

---

### Task 1: Sources knows the reminder kind; Reminders validates and shapes copies

**Files:**
- Modify: `includes/Recurring/Sources.php`
- Create: `includes/Recurring/Reminders.php` (validation and pure shaping only in this task)
- Test: `tests/php/RemindersTest.php`

**Interfaces:**
- Produces: `Sources::REMINDER = 'reminder'`, `Sources::REMINDER_PREFIX = 'rem'`, `Sources::for_sites( array $site_ids, array $kinds ): array`; `Reminders::validate( array $input, bool $partial ): array{values, errors}`, `Reminders::values( array $source, string $person ): array`, `Reminders::is_reminder( string $recurring_id ): bool`, `Reminders::ENDS_EARLY` (the message string).

- [ ] **Step 1: Write the failing test** — `tests/php/RemindersTest.php`

```php
<?php
/**
 * What a reminder may say, and what its copies are made of.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Recurring\Reminders;
use PHPUnit\Framework\TestCase;

final class RemindersTest extends TestCase {

	private function good(): array {
		return array(
			'title'     => 'Renew the domain',
			'assignees' => array( 'usr_abc123', 'usr_def456' ),
			'starts_on' => '2026-10-01',
			'ends_on'   => '2026-10-05',
		);
	}

	public function test_a_good_reminder_passes(): void {
		$checked = Reminders::validate( $this->good(), false );

		self::assertSame( array(), $checked['errors'] );
		self::assertSame( array( 'usr_abc123', 'usr_def456' ), $checked['values']['assignees'] );
		self::assertSame( '2026-10-05', $checked['values']['ends_on'] );
	}

	public function test_title_people_and_start_are_required_notes_and_end_are_not(): void {
		$checked = Reminders::validate( array(), false );

		self::assertSame( 'A reminder needs a title.', $checked['errors']['title'] );
		self::assertSame( 'Choose at least one person.', $checked['errors']['assignees'] );
		self::assertArrayHasKey( 'starts_on', $checked['errors'] );
		self::assertArrayNotHasKey( 'ends_on', $checked['errors'] );
		self::assertArrayNotHasKey( 'description', $checked['errors'] );
	}

	public function test_it_cannot_end_before_it_starts(): void {
		$checked = Reminders::validate( array_merge( $this->good(), array( 'ends_on' => '2026-09-30' ) ), false );

		self::assertSame( 'The end date is before the start date.', $checked['errors']['ends_on'] );
	}

	public function test_a_copy_is_one_persons_free_task_over_the_period(): void {
		$source = array(
			'id'          => 'rem_1',
			'title'       => 'Renew the domain',
			'description' => '',
			'starts_on'   => '2026-10-01',
			'ends_on'     => '2026-10-05',
		);

		$values = Reminders::values( $source, 'usr_abc123' );

		self::assertSame( 'Renew the domain', $values['title'] );
		self::assertSame( 'Renew the domain', $values['problem'] );
		self::assertSame( '2026-10-01', $values['planned_start'] );
		self::assertSame( '2026-10-05', $values['planned_due'] );
		self::assertSame( 'free-general', $values['commercial_class'] );
		self::assertSame( array( 'usr_abc123' ), $values['assignees'] );
		self::assertSame( 'rem_1', $values['recurring_id'] );
	}

	public function test_a_one_day_reminder_is_due_the_day_it_starts(): void {
		$values = Reminders::values(
			array(
				'id'          => 'rem_1',
				'title'       => 'Call back',
				'description' => '<p>About the invoice.</p>',
				'starts_on'   => '2026-10-01',
				'ends_on'     => '',
			),
			'usr_abc123'
		);

		self::assertSame( '2026-10-01', $values['planned_due'] );
		self::assertSame( '<p>About the invoice.</p>', $values['problem'] );
	}

	public function test_reminder_copies_are_told_apart_by_their_source_id(): void {
		self::assertTrue( Reminders::is_reminder( 'rem_0001abc' ) );
		self::assertFalse( Reminders::is_reminder( 'rec_0001abc' ) );
		self::assertFalse( Reminders::is_reminder( '' ) );
	}
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `vendor/bin/phpunit --filter RemindersTest`
Expected: FAIL — class `Blueworx\Forge\Recurring\Reminders` not found.

- [ ] **Step 3: Add the reminder kind to `Sources`**

In `includes/Recurring/Sources.php`, after `SUBSCRIPTION`:

```php
	/**
	 * A task on a fixed day or period (2026-09-25): no rule, made once.
	 */
	public const REMINDER = 'reminder';

	/**
	 * Id prefix for a reminder, so its copies are known by their source id.
	 */
	public const REMINDER_PREFIX = 'rem';
```

Add `'client_site_id'` and `'client_id'` to the end of `WRITABLE` (Task 3 moves recurring tasks between clients through `update`).

In `create()`, replace the id and next-due lines:

```php
		$row['id']              = Ids::create( self::REMINDER === $kind ? self::REMINDER_PREFIX : self::PREFIX );
		$row['kind']            = $kind;
		$row['client_site_id']  = $client_site_id;
		$row['client_id']       = $client_id;
		// A reminder has no next day: Reminders makes its copies when it is saved.
		$row['next_due']        = self::REMINDER === $kind ? '' : Rule::next_on_or_after( (array) json_decode( (string) $row['rule'], true ), (string) $row['starts_on'] );
```

In `update()`, guard the next-due recalculation so a reminder never gains one:

```php
		if ( self::REMINDER !== (string) $current['kind'] && ( isset( $changes['rule'] ) || isset( $changes['starts_on'] ) ) ) {
```

Add after `for_site()`:

```php
	/**
	 * The sources of some kinds on some sites, newest first.
	 *
	 * @param array<int, string> $site_ids Sites.
	 * @param array<int, string> $kinds    Kinds.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_sites( array $site_ids, array $kinds ): array {
		global $wpdb;

		$site_ids = array_values( array_unique( array_filter( array_map( 'strval', $site_ids ) ) ) );
		$kinds    = array_values( array_unique( array_filter( array_map( 'strval', $kinds ) ) ) );

		if ( array() === $site_ids || array() === $kinds ) {
			return array();
		}

		$table = Schema::recurring_table();
		$sites = implode( ', ', array_fill( 0, count( $site_ids ), '%s' ) );
		$slots = implode( ', ', array_fill( 0, count( $kinds ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table name cannot be a placeholder; the slots are counted above.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE client_site_id IN ({$sites}) AND kind IN ({$slots}) AND status <> %s ORDER BY created_at DESC", array_merge( $site_ids, $kinds, array( self::ENDED ) ) ), ARRAY_A );

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}
```

- [ ] **Step 4: Create `includes/Recurring/Reminders.php` with validation and shaping**

```php
<?php
/**
 * Reminders: a task on a fixed day or period.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Recurring;

use Blueworx\Forge\Work\Fields;

/**
 * Luke, 2026-09-25: "recurring tasks that don't have a recurrence, they are
 * for a fixed date or period." A reminder is a source of its own kind in the
 * recurring table, with no rule and no next day. Saving it makes one task
 * per person there and then, so each sees it ahead of time; those tasks are
 * ticked off exactly as a recurring task's are.
 */
final class Reminders {

	/**
	 * Longest title.
	 */
	private const MAX_TITLE = 160;

	/**
	 * What an end before the start is called.
	 */
	public const ENDS_EARLY = 'The end date is before the start date.';

	/**
	 * Checks a reminder. The client is checked by the route, which knows the
	 * caller's reach.
	 *
	 * @param array<string, mixed> $input   Raw input.
	 * @param bool                 $partial True for an edit.
	 * @return array{values: array<string, mixed>, errors: array<string, string>}
	 */
	public static function validate( array $input, bool $partial ): array {
		$values = array();
		$errors = array();

		if ( ! $partial || array_key_exists( 'title', $input ) ) {
			$title = trim( (string) ( $input['title'] ?? '' ) );

			if ( '' === $title ) {
				$errors['title'] = 'A reminder needs a title.';
			} elseif ( mb_strlen( $title ) > self::MAX_TITLE ) {
				$errors['title'] = 'That title is too long.';
			} else {
				$values['title'] = $title;
			}
		}

		// Notes are optional.
		if ( array_key_exists( 'description', $input ) ) {
			$values['description'] = trim( wp_kses( (string) $input['description'], Fields::ALLOWED_HTML ) );
		}

		if ( ! $partial || array_key_exists( 'assignees', $input ) ) {
			$people = array();
			$bad    = false;

			foreach ( (array) ( $input['assignees'] ?? array() ) as $id ) {
				$id = trim( (string) $id );

				if ( '' === $id ) {
					continue;
				}

				if ( 1 !== preg_match( '/^usr_[A-Za-z0-9]+$/', $id ) ) {
					$bad = true;
					break;
				}

				$people[ $id ] = $id;
			}

			if ( $bad ) {
				$errors['assignees'] = 'That is not a person.';
			} elseif ( array() === $people ) {
				$errors['assignees'] = 'Choose at least one person.';
			} else {
				$values['assignees'] = array_values( $people );
			}
		}

		foreach ( array( 'starts_on', 'ends_on' ) as $date ) {
			if ( ! array_key_exists( $date, $input ) && ( $partial || 'ends_on' === $date ) ) {
				continue;
			}

			$value = trim( (string) ( $input[ $date ] ?? '' ) );

			if ( '' === $value ) {
				if ( 'ends_on' === $date ) {
					$values['ends_on'] = '';
				} else {
					$errors['starts_on'] = 'Say the day.';
				}
				continue;
			}

			if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) || false === strtotime( $value ) ) {
				$errors[ $date ] = 'That is not a date.';
			} else {
				$values[ $date ] = $value;
			}
		}

		if ( isset( $values['starts_on'], $values['ends_on'] ) && '' !== $values['ends_on'] && $values['ends_on'] < $values['starts_on'] ) {
			$errors['ends_on'] = self::ENDS_EARLY;
		}

		return array(
			'values' => $values,
			'errors' => $errors,
		);
	}

	/**
	 * Whether a work item's source id is a reminder's.
	 *
	 * @param string $recurring_id The item's recurring_id.
	 * @return bool
	 */
	public static function is_reminder( string $recurring_id ): bool {
		return 0 === strpos( $recurring_id, Sources::REMINDER_PREFIX . '_' );
	}

	/**
	 * One person's copy. Pure, so the shape can be tested.
	 *
	 * @param array<string, mixed> $source The reminder.
	 * @param string               $person Person id.
	 * @return array<string, mixed>
	 */
	public static function values( array $source, string $person ): array {
		$description = trim( (string) $source['description'] );
		$starts      = (string) $source['starts_on'];
		$ends        = '' === (string) $source['ends_on'] ? $starts : (string) $source['ends_on'];

		return array(
			'title'            => (string) $source['title'],
			'problem'          => '' === $description ? (string) $source['title'] : $description,
			'level'            => 'sub-feature',
			'work_type'        => 'task',
			'priority'         => 'normal',
			'planned_start'    => $starts,
			'planned_due'      => $ends,
			// Free, like a recurring task: nobody pays for a reminder.
			'commercial_class' => 'free-general',
			'recurring_id'     => (string) $source['id'],
			'assignees'        => array( $person ),
			'hours_each'       => 0.0,
			'checklist'        => '[]',
		);
	}
}
```

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/phpunit --filter RemindersTest` → PASS. Then `vendor/bin/phpunit` → all PASS.

- [ ] **Step 6: Commit**

```bash
git add includes/Recurring/Sources.php includes/Recurring/Reminders.php tests/php/RemindersTest.php
git commit -m "Reminders: the kind, its checks and its copies' shape"
```

---

### Task 2: Reminders make, edit and delete their copies; the `/reminders` routes

**Files:**
- Modify: `includes/Recurring/Reminders.php` (add `make`, `copies_for`, `sync`, `remove`)
- Create: `includes/Rest/RemindersController.php`
- Modify: `includes/Rest/Server.php` (register after `RecurringController`)
- Modify: `includes/Rest/RecurringController.php` (add public `site_for()` — used here and in Task 3)
- Modify: `tests/e2e/helpers/forge.js` (add `del`)
- Test: `tests/e2e/reminders.spec.js`

**Interfaces:**
- Consumes: Task 1's `Sources::REMINDER`, `Sources::for_sites`, `Reminders::validate/values`.
- Produces: `Reminders::make( array $source ): int`, `Reminders::copies_for( array $ids ): array<string, array<int, array>>`, `Reminders::sync( array $source ): void`, `Reminders::remove( array $source ): void`, `RecurringController::site_for( string $site_id ): ?array`. REST: `GET /reminders` → `{ ok, denied, reminders: Reminder[] }`; `POST /reminders` → `{ ok, reminder }`; `PATCH|DELETE /reminders/<id>`. A `Reminder` is `{ id, client_site_id, client_id, title, description, assignees, starts_on, ends_on, record_version, created_by, can_edit, copies: [{ item_id, person, done }] }`.

- [ ] **Step 1: Add `del` to the test helper** — in `forge()` in `tests/e2e/helpers/forge.js`, after `put`:

```js
    del: (path) => request.delete(`${BASE}${path}`, { headers }),
```

- [ ] **Step 2: Write the failing e2e test** — `tests/e2e/reminders.spec.js`

```js
import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// Reminders (2026-09-25): a task on a day or over a few, for one or more
// people on a client, one copy each, on the calendar and in My tasks.

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';

function plus(date, days) {
  return new Date(Date.parse(`${date}T00:00:00Z`) + days * 86400000).toISOString().slice(0, 10);
}

test('a three-day reminder gives each person a copy, and edits reach only the unticked one', async ({ browser, baseURL }) => {
  test.slow();

  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { client, site } = await Forge.makeSite(admin.api, 'Remind', RUN_ID);
  const today = (await admin.api.get('/standup')).today;
  const one = await Forge.makePerson(admin.api, client.id, 'staff', 'remindera');
  const two = await Forge.makePerson(admin.api, client.id, 'staff', 'reminderb');
  const title = `Renew the domain ${RUN_ID}`;

  const made = await admin.api.post('/reminders', {
    client_site_id: site.id,
    title,
    assignees: [one.id, two.id],
    starts_on: today,
    ends_on: plus(today, 2),
  });
  expect(made.status(), await made.text()).toBe(200);
  const reminder = (await made.json()).reminder;
  expect(reminder.id.startsWith('rem_')).toBe(true);
  expect(reminder.copies).toHaveLength(2);

  const work = await admin.api.get(`/work-items?client_site_id=${site.id}`);
  const tasks = work.items.filter((item) => item.title === title);
  expect(tasks).toHaveLength(2);
  for (const task of tasks) {
    expect(task.assignees).toHaveLength(1);
    expect(task.planned_start).toBe(today);
    expect(task.planned_due).toBe(plus(today, 2));
    expect(task.commercial_class).toBe('free-general');
    expect(task.stage).toBe('up-next');
  }
  const mine = tasks.find((task) => task.assignees[0] === one.id);
  const theirs = tasks.find((task) => task.assignees[0] === two.id);

  // Person one ticks theirs; it is done, the other is untouched.
  const asOne = await Forge.signedIn(browser, baseURL, one.login, Forge.PASSWORD);
  const ticked = await asOne.api.post(`/work-items/${mine.id}/tick`, { done: true });
  expect(ticked.status(), await ticked.text()).toBe(200);
  expect((await admin.api.get(`/work-items/${theirs.id}`)).item.stage).toBe('up-next');

  // An edit renames and re-dates only the copy nobody ticked, and never makes more.
  const edited = await admin.api.patch(`/reminders/${reminder.id}`, {
    title: `${title} (moved)`,
    starts_on: plus(today, 1),
    ends_on: plus(today, 3),
    record_version: reminder.record_version,
  });
  expect(edited.status(), await edited.text()).toBe(200);
  expect((await admin.api.get(`/work-items/${mine.id}`)).item.title).toBe(title);
  const moved = (await admin.api.get(`/work-items/${theirs.id}`)).item;
  expect(moved.title).toBe(`${title} (moved)`);
  expect(moved.planned_start).toBe(plus(today, 1));
  await admin.api.post('/recurring/run', {});
  const after = await admin.api.get(`/work-items?client_site_id=${site.id}`);
  expect(after.items.filter((item) => item.title.startsWith(title))).toHaveLength(2);

  // Deleting removes the unticked copy and keeps the ticked one.
  const removed = await admin.api.del(`/reminders/${reminder.id}`);
  expect(removed.status(), await removed.text()).toBe(200);
  const left = (await admin.api.get(`/work-items?client_site_id=${site.id}`)).items.filter((item) => item.title.startsWith(title));
  expect(left.map((item) => item.id)).toEqual([mine.id]);
});

test('only the author or an administrator changes a reminder, and only on a client they reach', async ({ browser, baseURL }) => {
  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { client, site } = await Forge.makeSite(admin.api, 'RemindAuth', RUN_ID);
  const elsewhere = await Forge.makeSite(admin.api, 'RemindElse', RUN_ID);
  const today = (await admin.api.get('/standup')).today;
  const author = await Forge.makePerson(admin.api, client.id, 'staff', 'remauthor');
  const other = await Forge.makePerson(admin.api, client.id, 'staff', 'remother');

  const asAuthor = await Forge.signedIn(browser, baseURL, author.login, Forge.PASSWORD);
  const made = await asAuthor.api.post('/reminders', { client_site_id: site.id, title: `Theirs ${RUN_ID}`, assignees: [other.id], starts_on: today });
  expect(made.status(), await made.text()).toBe(200);
  const reminder = (await made.json()).reminder;
  expect(reminder.can_edit).toBe(true);

  const outside = await asAuthor.api.post('/reminders', { client_site_id: elsewhere.site.id, title: 'No', assignees: [other.id], starts_on: today });
  expect(outside.status()).toBe(400);
  expect((await outside.json()).data.fields.client_site_id).toBe('Choose a client.');

  const asOther = await Forge.signedIn(browser, baseURL, other.login, Forge.PASSWORD);
  const listed = (await asOther.api.get('/reminders')).reminders.find((one) => one.id === reminder.id);
  expect(listed.can_edit).toBe(false);
  const refused = await asOther.api.patch(`/reminders/${reminder.id}`, { title: 'Mine now', record_version: reminder.record_version });
  expect(refused.status()).toBe(403);

  const early = await asAuthor.api.patch(`/reminders/${reminder.id}`, { ends_on: plus(today, -1), record_version: reminder.record_version });
  expect(early.status()).toBe(400);
  expect((await early.json()).data.fields.ends_on).toBe('The end date is before the start date.');
});
```

Check the error body shape against an existing 400 assertion in `tests/e2e/recurring-rest.spec.js` (search `fields`) and use the same path (`data.fields` or `fields`) in both assertions above.

- [ ] **Step 3: Run it to see it fail**

Run (with `npm run wp:up` already up): `npx playwright test tests/e2e/reminders.spec.js --workers=1`
Expected: FAIL — `/reminders` answers 404.

- [ ] **Step 4: Add the copy-making to `Reminders`** — add these `use` lines and methods:

```php
use Blueworx\Forge\Data\Schema;
use Blueworx\Forge\Work\Items;
use Blueworx\Forge\Work\Stages;
use Blueworx\Forge\Work\Transition;
```

```php
	/**
	 * Makes a new reminder's copies, one per person, once. The start day is
	 * claimed first, so two saves racing make one set.
	 *
	 * @param array<string, mixed> $source The reminder.
	 * @return int How many copies were made.
	 */
	public static function make( array $source ): int {
		if ( ! Occurrences::claim( (string) $source['id'], (string) $source['starts_on'] ) ) {
			return 0;
		}

		$made = 0;

		foreach ( (array) $source['assignees'] as $person ) {
			$item = self::add( $source, (string) $person );

			if ( null === $item ) {
				continue;
			}

			if ( 0 === $made ) {
				Occurrences::record_item( (string) $source['id'], (string) $source['starts_on'], (string) $item['id'] );
			}

			++$made;
		}

		return $made;
	}

	/**
	 * Each reminder's copies still on the board, keyed by reminder id.
	 *
	 * @param array<int, string> $ids Reminder ids.
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function copies_for( array $ids ): array {
		global $wpdb;

		$wanted = array_values( array_unique( array_filter( array_map( 'strval', $ids ) ) ) );

		if ( array() === $wanted ) {
			return array();
		}

		$table = Schema::work_items_table();
		$slots = implode( ', ', array_fill( 0, count( $wanted ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table name cannot be a placeholder; the slots are counted above.
		$found = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE archived = 0 AND recurring_id IN ({$slots}) ORDER BY created_at ASC", $wanted ) );
		$out   = array_fill_keys( $wanted, array() );

		foreach ( is_array( $found ) ? $found : array() as $id ) {
			$item = Items::get( (string) $id );

			if ( null !== $item ) {
				$out[ (string) $item['recurring_id'] ][] = $item;
			}
		}

		return $out;
	}

	/**
	 * Brings the copies nobody has ticked in line with an edited reminder: new
	 * words and dates, a copy for anyone added, none for anyone removed.
	 * Ticked copies are the record of what was done and are left alone.
	 *
	 * @param array<string, mixed> $source The reminder as it now stands.
	 */
	public static function sync( array $source ): void {
		$people = array_map( 'strval', (array) $source['assignees'] );
		$have   = array();

		foreach ( self::copies_for( array( (string) $source['id'] ) )[ (string) $source['id'] ] ?? array() as $item ) {
			$person = (string) ( ( (array) $item['assignees'] )[0] ?? '' );
			$have[] = $person;

			if ( array() !== (array) $item['ticks'] ) {
				continue;
			}

			if ( ! in_array( $person, $people, true ) ) {
				Items::delete( (string) $item['id'] );
				continue;
			}

			$values = self::values( $source, $person );

			Items::update(
				(string) $item['id'],
				array_intersect_key( $values, array_flip( array( 'title', 'problem', 'planned_start', 'planned_due' ) ) ),
				(int) $item['record_version']
			);
		}

		foreach ( array_diff( $people, $have ) as $person ) {
			self::add( $source, $person );
		}
	}

	/**
	 * Deletes a reminder: its unticked copies go, ticked ones stay, and the
	 * reminder itself is ended rather than removed so they keep a source.
	 *
	 * @param array<string, mixed> $source The reminder.
	 */
	public static function remove( array $source ): void {
		foreach ( self::copies_for( array( (string) $source['id'] ) )[ (string) $source['id'] ] ?? array() as $item ) {
			if ( array() === (array) $item['ticks'] ) {
				Items::delete( (string) $item['id'] );
			}
		}

		Sources::end( (string) $source['id'] );
	}

	/**
	 * One person's copy, made and placed at Up Next.
	 *
	 * @param array<string, mixed> $source The reminder.
	 * @param string               $person Person id.
	 * @return array<string, mixed>|null
	 */
	private static function add( array $source, string $person ): ?array {
		$author = (int) ( $source['created_by'] ?? 0 );
		$item   = Items::create( (string) $source['client_site_id'], (string) $source['client_id'], self::values( $source, $person ), $author );

		if ( null === $item ) {
			return null;
		}

		Transition::record_creation( $item, $author );
		/* translators: %s: a date */
		Transition::place( $item, Stages::UP_NEXT, $author, sprintf( __( 'Reminder for %s', 'blueworx-forge' ), (string) $source['starts_on'] ) );

		return $item;
	}
```

- [ ] **Step 5: Add `site_for()` to `RecurringController`** (public, near `studio_site()`):

```php
	/**
	 * A client site the caller reaches, or null (2026-09-25): where a
	 * recurring task or a reminder may be put.
	 *
	 * @param string $site_id Site id.
	 * @return array<string, mixed>|null
	 */
	public static function site_for( string $site_id ): ?array {
		if ( '' === $site_id ) {
			return null;
		}

		$site = ClientSites::get( $site_id );

		return null !== $site && Reach::reaches_site( Boundary::current(), (string) $site['client_id'], (string) $site['id'] ) ? $site : null;
	}
```

- [ ] **Step 6: Create `includes/Rest/RemindersController.php`**

```php
<?php
/**
 * The reminder routes.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Recurring\Reminders;
use Blueworx\Forge\Recurring\Sources;
use Blueworx\Forge\Tenancy\ClientSites;
use Blueworx\Forge\Tenancy\Reach;
use WP_REST_Request;

/**
 * Reminders (2026-09-25): a task on a day or over a few, for one or more
 * people, on a client's site. Anyone on the team adds one on a site they
 * reach; its author or an administrator changes or deletes it.
 */
final class RemindersController {

	/**
	 * Registers this controller's routes.
	 *
	 * @param string $route_namespace REST namespace.
	 */
	public static function register_routes( string $route_namespace ): void {
		Server::register_route(
			$route_namespace,
			'/reminders',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'index' ),
				'permission_callback' => array( Permissions::class, 'signed_in' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_LIST,
					'reason' => 'Lists only the reminders on sites the caller reaches.',
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/reminders',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'create' ),
				'permission_callback' => array( Permissions::class, 'signed_in' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_OPEN,
					'reason' => 'The callback refuses a site the caller does not reach.',
				),
			)
		);

		foreach ( array( 'PATCH' => 'update', 'DELETE' => 'remove' ) as $method => $callback ) {
			Server::register_route(
				$route_namespace,
				'/reminders/(?P<reminder_id>[A-Za-z0-9_\-]+)',
				array(
					'methods'             => $method,
					'callback'            => array( self::class, $callback ),
					'permission_callback' => array( Permissions::class, 'signed_in' ),
					'scope'               => array(
						'kind'   => Boundary::SCOPE_OPEN,
						'reason' => 'The callback refuses a reminder on a site the caller does not reach, and anyone but its author or an administrator.',
					),
				)
			);
		}
	}

	/**
	 * Every reminder on the sites the caller reaches.
	 *
	 * @return \WP_REST_Response
	 */
	public static function index() {
		$reach = Boundary::current();

		if ( Reach::is_nothing( $reach ) ) {
			return rest_ensure_response(
				array(
					'ok'        => true,
					'denied'    => true,
					'reminders' => array(),
				)
			);
		}

		$sites   = array_column( Reach::keep_sites( $reach, ClientSites::all( 'active' ), 'id' ), 'id' );
		$sources = Sources::for_sites( $sites, array( Sources::REMINDER ) );
		$copies  = Reminders::copies_for( array_column( $sources, 'id' ) );

		return rest_ensure_response(
			array(
				'ok'        => true,
				'denied'    => false,
				'reminders' => array_map( static fn( array $source ): array => self::shape( $source, $copies[ (string) $source['id'] ] ?? array() ), $sources ),
			)
		);
	}

	/**
	 * Adds a reminder and makes its copies.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function create( WP_REST_Request $request ) {
		$input   = (array) $request->get_json_params();
		$site    = RecurringController::site_for( (string) ( $input['client_site_id'] ?? '' ) );
		$checked = Reminders::validate( $input, false );

		if ( null === $site ) {
			$checked['errors']['client_site_id'] = 'Choose a client.';
		}

		if ( array() !== $checked['errors'] || null === $site ) {
			return self::invalid( $checked['errors'] );
		}

		$source = Sources::create( (string) $site['id'], (string) $site['client_id'], Sources::REMINDER, $checked['values'], get_current_user_id() );

		if ( null === $source ) {
			return Errors::rest( 'write_failed', __( 'That reminder could not be saved.', 'blueworx-forge' ), 500 );
		}

		Reminders::make( $source );

		return self::answer( $source );
	}

	/**
	 * Changes a reminder and the copies nobody has ticked.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function update( WP_REST_Request $request ) {
		$source = self::editable( (string) $request['reminder_id'] );

		if ( ! is_array( $source ) ) {
			return $source;
		}

		$input   = (array) $request->get_json_params();
		$checked = Reminders::validate( $input, true );

		// An edit that moves one date is still checked against the other.
		$starts = (string) ( $checked['values']['starts_on'] ?? $source['starts_on'] );
		$ends   = (string) ( $checked['values']['ends_on'] ?? $source['ends_on'] );

		if ( '' !== $ends && $ends < $starts ) {
			$checked['errors']['ends_on'] = Reminders::ENDS_EARLY;
		}

		if ( array() !== $checked['errors'] ) {
			return self::invalid( $checked['errors'] );
		}

		$version = (int) ( $input[ Versioning::PARAM ] ?? $source['record_version'] );
		$updated = Sources::update( (string) $source['id'], $checked['values'], $version );

		if ( null === $updated ) {
			return Errors::rest( 'stale_version', __( 'That reminder changed elsewhere first — reload and try again.', 'blueworx-forge' ), 409 );
		}

		Reminders::sync( $updated );

		return self::answer( $updated );
	}

	/**
	 * Deletes a reminder. Ticked copies stay.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function remove( WP_REST_Request $request ) {
		$source = self::editable( (string) $request['reminder_id'] );

		if ( ! is_array( $source ) ) {
			return $source;
		}

		Reminders::remove( $source );

		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * The reminder, if the caller may change it; otherwise the refusal.
	 *
	 * @param string $id Reminder id.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function editable( string $id ) {
		$source = Sources::get( $id );

		if ( null === $source || Sources::REMINDER !== (string) $source['kind'] || Sources::ENDED === (string) $source['status'] ) {
			return Boundary::absent( 'reminder' );
		}

		if ( null === RecurringController::site_for( (string) $source['client_site_id'] ) ) {
			return Boundary::hidden( 'reminder' );
		}

		if ( ! self::may_edit( $source ) ) {
			return Errors::rest( 'not_yours', __( 'Only whoever added this reminder, or an administrator, can change it.', 'blueworx-forge' ), 403 );
		}

		return $source;
	}

	/**
	 * The author or an administrator.
	 *
	 * @param array<string, mixed> $source The reminder.
	 * @return bool
	 */
	private static function may_edit( array $source ): bool {
		$me = get_current_user_id();

		return Permissions::manage() || ( 0 !== $me && (int) $source['created_by'] === $me );
	}

	/**
	 * A reminder as the page reads it.
	 *
	 * @param array<string, mixed>             $source The reminder.
	 * @param array<int, array<string, mixed>> $copies Its copies.
	 * @return array<string, mixed>
	 */
	private static function shape( array $source, array $copies ): array {
		return array(
			'id'             => (string) $source['id'],
			'client_site_id' => (string) $source['client_site_id'],
			'client_id'      => (string) $source['client_id'],
			'title'          => (string) $source['title'],
			'description'    => (string) $source['description'],
			'assignees'      => (array) $source['assignees'],
			'starts_on'      => (string) $source['starts_on'],
			'ends_on'        => (string) $source['ends_on'],
			'record_version' => (int) $source['record_version'],
			'created_by'     => (int) $source['created_by'],
			'can_edit'       => self::may_edit( $source ),
			'copies'         => array_map(
				static fn( array $item ): array => array(
					'item_id' => (string) $item['id'],
					'person'  => (string) ( ( (array) $item['assignees'] )[0] ?? '' ),
					'done'    => array() !== (array) $item['ticks'],
				),
				$copies
			),
		);
	}

	/**
	 * The reminder, read back with its copies.
	 *
	 * @param array<string, mixed> $source The reminder.
	 * @return \WP_REST_Response
	 */
	private static function answer( array $source ) {
		return rest_ensure_response(
			array(
				'ok'       => true,
				'reminder' => self::shape( $source, Reminders::copies_for( array( (string) $source['id'] ) )[ (string) $source['id'] ] ?? array() ),
			)
		);
	}

	/**
	 * A refusal naming the fields at fault.
	 *
	 * @param array<string, string> $fields Field to message.
	 * @return \WP_Error
	 */
	private static function invalid( array $fields ) {
		return Errors::rest( 'invalid_reminder', __( 'That reminder could not be saved.', 'blueworx-forge' ), 400, array( 'fields' => $fields ) );
	}
}
```

If `Boundary::hidden` or `Boundary::absent` refuse an unknown record name, use the name the nearest existing call uses (`'recurring'`).

- [ ] **Step 7: Register it** — in `includes/Rest/Server.php`, after `RecurringController::register_routes( self::NAMESPACE );`:

```php
		RemindersController::register_routes( self::NAMESPACE );
```

- [ ] **Step 8: Run the tests**

Run: `npx playwright test tests/e2e/reminders.spec.js tests/e2e/rest-conventions.spec.js --workers=1` → PASS. If `rest-conventions` lists every route, add `/reminders` where it expects.
Run: `vendor/bin/phpunit` → PASS; `vendor/bin/phpcs includes/Recurring includes/Rest/RemindersController.php` → clean.

- [ ] **Step 9: Commit**

```bash
git add includes tests/e2e/reminders.spec.js tests/e2e/helpers/forge.js
git commit -m "Reminders: routes that make, edit and delete each person's copy"
```

---

### Task 3: Recurring tasks choose a client

**Files:**
- Modify: `includes/Rest/RecurringController.php`
- Modify: `src/types.ts` (`RecurringSource.kind` gains `'reminder'`)
- Modify: `src/components/RecurringScreen.tsx`
- Modify: `tests/e2e/recurring-rest.spec.js`, `tests/e2e/recurring-ticks.spec.js`, `tests/e2e/calendar-feed.spec.js` (every `post('/recurring', …)` body gains `client_site_id`)
- Test: `tests/e2e/recurring-rest.spec.js` (new test), `tests/e2e/recurring-screen.spec.js` (form picks a client)

**Interfaces:**
- Consumes: `RecurringController::site_for()`, `Sources::for_sites()` (Tasks 1–2).
- Produces: `GET /recurring` → `{ ok, denied, studio_site_id, sources }` across reachable sites, schedules and subscriptions only; `POST /recurring` requires `client_site_id`; `PATCH /recurring/<id>` accepts it.

- [ ] **Step 1: Write the failing test** — append to `tests/e2e/recurring-rest.spec.js`:

```js
test('a recurring task is set up for a chosen client, and needs one', async ({ browser, baseURL }) => {
  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { client, site } = await Forge.makeSite(admin.api, 'RecurClient', RUN_ID);
  const person = await Forge.makePerson(admin.api, client.id, 'staff', 'recurclient');
  const today = (await admin.api.get('/standup')).today;
  const body = {
    title: `Client chore ${RUN_ID}`,
    description: '<p>Check the forms.</p>',
    rule: { every: 'day' },
    starts_on: today,
    assignees: [person.id],
    hours_each: '0.5',
  };

  const none = await admin.api.post('/recurring', body);
  expect(none.status()).toBe(400);

  const made = await admin.api.post('/recurring', { ...body, client_site_id: site.id });
  expect(made.status(), await made.text()).toBe(200);
  expect((await made.json()).source.client_site_id).toBe(site.id);

  await admin.api.post('/recurring/run', {});
  const work = await admin.api.get(`/work-items?client_site_id=${site.id}`);
  expect(work.items.some((item) => item.title.startsWith(`Client chore ${RUN_ID}`))).toBe(true);

  const listed = await admin.api.get('/recurring');
  expect(listed.sources.some((source) => source.client_site_id === site.id)).toBe(true);
});
```

Use the file's own constant names for `RUN_ID` / admin credentials if they differ. Assert the 400's `fields.client_site_id` is `'Choose a client.'` using the same error-body path as the existing 400 assertions in that file.

- [ ] **Step 2: Run it to see it fail**

Run: `npx playwright test tests/e2e/recurring-rest.spec.js --workers=1`
Expected: the new test FAILS (the task is made on the studio's site).

- [ ] **Step 3: Change `RecurringController`**

Replace the class docblock's first paragraph with: "Recurring tasks sit on a client's site the administrator chooses (2026-09-25); the studio's own site is one of the choices. Reading is limited to the sites the caller reaches; writing is the administrator's." Update the GET route's scope reason to `'Lists only the recurring tasks on sites the caller reaches.'` and the write routes' reasons to `'Configuration, administrators only; the callback refuses a site the caller does not reach.'`

Replace `index()`:

```php
	public static function index() {
		$reach = Boundary::current();

		if ( Reach::is_nothing( $reach ) ) {
			return rest_ensure_response(
				array(
					'ok'      => true,
					'denied'  => true,
					'sources' => array(),
				)
			);
		}

		// Anything due gets made before the list is read, so "last created"
		// is true as of now rather than as of the last time somebody looked.
		Materialise::maybe();

		$sites   = array_column( Reach::keep_sites( $reach, ClientSites::all( 'active' ), 'id' ), 'id' );
		$sources = Sources::for_sites( $sites, array( Sources::SCHEDULE, Sources::SUBSCRIPTION ) );
		$latest  = Occurrences::latest_for( array_column( $sources, 'id' ) );

		foreach ( $sources as $index => $source ) {
			$sources[ $index ]['last'] = $latest[ (string) $source['id'] ] ?? null;
		}

		return rest_ensure_response(
			array(
				'ok'             => true,
				'denied'         => false,
				// The form's first choice.
				'studio_site_id' => Studio::site_id(),
				'sources'        => $sources,
			)
		);
	}
```

In `create()`, replace the `$site = self::studio_site(); if ( null === $site ) {…}` block and the validation with:

```php
		$input   = (array) $request->get_json_params();
		$site    = self::site_for( (string) ( $input['client_site_id'] ?? '' ) );
		$checked = Validate::source( $input, false );

		if ( null === $site ) {
			$checked['errors']['client_site_id'] = 'Choose a client.';
		}

		if ( array() !== $checked['errors'] || null === $site ) {
```

(keeping the existing `Errors::rest( 'invalid_recurring', … )` body inside that `if`).

In `update()`, after `$checked = Validate::source( $input, true );` add:

```php
		if ( array_key_exists( 'client_site_id', $input ) ) {
			$site = self::site_for( (string) $input['client_site_id'] );

			if ( null === $site ) {
				$checked['errors']['client_site_id'] = 'Choose a client.';
			} else {
				$checked['values']['client_site_id'] = (string) $site['id'];
				$checked['values']['client_id']      = (string) $site['client_id'];
			}
		}
```

Delete `studio_site()` if nothing else calls it (grep first).

- [ ] **Step 4: Update the existing recurring callers** — run `grep -n "post('/recurring'," tests/e2e/*.js`. In each body, add `client_site_id: studio.id` (each of those files already reads `studio` from `/client-sites`; where one doesn't, add `const studio = (await admin.api.get('/client-sites')).sites.find((one) => one.studio);`). Leave the staff-refused POST as it is (403 comes first).

- [ ] **Step 5: Add the client picker to `RecurringScreen.tsx`**

- `Listing`: replace `site?: …` with `studio_site_id?: string;`.
- `Draft`: add `client_site_id: string;`. `blank()` takes `( studio: string )` and sets `client_site_id: studio`; `fromSource` sets `client_site_id: source.client_site_id`.
- `complete()`: add `'' !== draft.client_site_id &&`.
- Load sites alongside people: `const [ sites, setSites ] = useState< Array< ClientSite & { client_name: string } > >( [] );` and in the mount effect `void api< { sites: Array< ClientSite & { client_name: string } > } >( '/client-sites' ).then( ( answer ) => setSites( answer.sites ) ).catch( () => undefined );` (import `ClientSite` from `../types`).
- `const siteName = ( id: string ) => { const site = sites.find( ( one ) => one.id === id ); return site ? site.client_name || site.name : '—'; };`
- Add a column after `title`: `{ key: 'client', label: 'Client', width: 180, sortBy: ( r ) => siteName( r.client_site_id ), render: ( r ) => siteName( r.client_site_id ) }`.
- DataView `title="Recurring tasks"`; footer ``${ listing.sources.length } recurring · each due day becomes a task on its client's site the first time anyone opens Forge``.
- `SourceForm` gets `sites` and `studio` props (`studio={ listing?.studio_site_id ?? '' }`); `useState( () => ( source ? fromSource( source ) : blank( studio ) ) )`; `body` gains `client_site_id: draft.client_site_id`.
- In the form, after the Title field:

```tsx
        <div className="bwx-field">
          <label htmlFor="bwx-recurring-client">Client</label>
          <select id="bwx-recurring-client" className="bwx-select" data-testid="bwx-recurring-client" value={ draft.client_site_id } onChange={ ( event ) => set( 'client_site_id', event.target.value ) }>
            <option value="">Choose a client</option>
            { sites.map( ( site ) => (
              <option key={ site.id } value={ site.id }>
                { site.client_name && site.client_name !== site.name ? `${ site.client_name } · ${ site.name }` : site.name }
              </option>
            ) ) }
          </select>
        </div>
```

- Update the file's top comment: "in Up Next on the studio's own site" → "in Up Next on the client's site it names".
- Denied text: `"You are signed in, but do not reach any client's site."`

In `src/types.ts`, `RecurringSource.kind` becomes `'schedule' | 'subscription' | 'reminder';`.

- [ ] **Step 6: Extend `tests/e2e/recurring-screen.spec.js`** — in its add-a-task test, after the title is filled, `await page.getByTestId('bwx-recurring-client').selectOption(site.id);` for a site made with `Forge.makeSite`, and after saving assert the table row `toContainText(site's client display name)`. Follow the file's existing structure for creating fixtures.

- [ ] **Step 7: Build and run**

Run: `npm run build` → succeeds. Then `npx playwright test tests/e2e/recurring-rest.spec.js tests/e2e/recurring-ticks.spec.js tests/e2e/recurring-screen.spec.js tests/e2e/calendar-feed.spec.js --workers=1` → PASS.

- [ ] **Step 8: Commit**

```bash
git add includes/Rest/RecurringController.php src tests/e2e assets
git commit -m "Recurring tasks choose their client"
```

---

### Task 4: Reminders on the calendar

**Files:**
- Modify: `includes/Calendar/Feed.php`
- Modify: `src/types.ts` (`DiaryEntry.kind` gains `'reminder'`)
- Modify: `src/components/Diary.tsx`
- Modify: `src/styles.css`
- Test: `tests/e2e/reminders.spec.js` (new test)

**Interfaces:**
- Consumes: `Reminders::is_reminder()`.
- Produces: diary entries `{ kind: 'reminder', label: 'Reminder', date: planned_start, ends_on: planned_due or '' , detail: 'Done' | 'To do' }`.

- [ ] **Step 1: Write the failing test** — append to `tests/e2e/reminders.spec.js`:

```js
test('the calendar shows a reminder as a Reminder, over its days', async ({ browser, baseURL }) => {
  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { client, site } = await Forge.makeSite(admin.api, 'RemindCal', RUN_ID);
  const today = (await admin.api.get('/standup')).today;
  const person = await Forge.makePerson(admin.api, client.id, 'staff', 'remcal');

  for (const [title, ends] of [[`Span ${RUN_ID}`, plus(today, 2)], [`One day ${RUN_ID}`, '']]) {
    const made = await admin.api.post('/reminders', { client_site_id: site.id, title, assignees: [person.id], starts_on: plus(today, 1), ends_on: ends });
    expect(made.status(), await made.text()).toBe(200);
  }

  // A window that starts inside the span still finds it.
  const feed = await admin.api.get(`/calendar?from=${plus(today, 2)}&to=${plus(today, 5)}`);
  const span = feed.entries.filter((entry) => entry.title === `Span ${RUN_ID}`);
  expect(span).toHaveLength(1);
  expect(span[0].kind).toBe('reminder');
  expect(span[0].label).toBe('Reminder');
  expect(span[0].date).toBe(plus(today, 1));
  expect(span[0].ends_on).toBe(plus(today, 2));
  expect(span[0].detail).toBe('To do');

  const whole = await admin.api.get(`/calendar?from=${today}&to=${plus(today, 5)}`);
  const single = whole.entries.find((entry) => entry.title === `One day ${RUN_ID}`);
  expect(single.kind).toBe('reminder');
  expect(single.ends_on).toBe('');
  expect(whole.entries.some((entry) => 'recurring' === entry.kind && entry.title.includes(RUN_ID))).toBe(false);
});
```

- [ ] **Step 2: Run it to see it fail**

Run: `npx playwright test tests/e2e/reminders.spec.js -g calendar --workers=1`
Expected: FAIL — kind is `'recurring'`, and the span is missing from the later window.

- [ ] **Step 3: Change `Feed.php`**

Add `use Blueworx\Forge\Recurring\Reminders;`. In `KINDS`, after `'recurring' => 'Chore',` add `'reminder' => 'Reminder',`. Update the class comment's "Five things" sentence to add "and reminders (2026-09-25)".

In `recurring()`, change the query so an entry that started before the window but runs into it is found:

```php
				"SELECT * FROM {$table} WHERE recurring_id <> '' AND archived = 0 AND client_site_id IN ({$slots}) AND planned_due >= %s AND COALESCE(NULLIF(planned_start, ''), planned_due) <= %s ORDER BY planned_due ASC, title ASC",
				array_merge( array_values( $site_ids ), array( $from, $to ) )
```

In the loop, before the existing `$out[] = self::entry( 'recurring', … )`:

```php
			if ( Reminders::is_reminder( (string) $row['recurring_id'] ) ) {
				$due   = (string) $row['planned_due'];
				$start = '' === (string) $row['planned_start'] ? $due : (string) $row['planned_start'];

				$out[] = self::entry(
					'reminder',
					(string) $row['id'],
					$start,
					$start === $due ? '' : $due,
					(string) $row['title'],
					0 < $ticked ? 'Done' : 'To do',
					$people,
					(string) $row['id']
				);
				continue;
			}
```

Rename the method's docblock to "Recurring chores and reminders in the window, on the sites in reach."

- [ ] **Step 4: Front end**

`src/types.ts`: `kind: 'recurring' | 'reminder' | 'date' | 'meeting' | 'subscription' | 'leave';`

`src/components/Diary.tsx`: import `Bell` from `lucide-react`; in `DIARY_KINDS` after `recurring` add `reminder: { label: 'Reminder', tone: 'sky' },`; in `DIARY_ICONS` add `reminder: Bell,`; add "a reminder," to the top comment's list.

`src/styles.css`: after the `.bwx-diary-line[ data-tone='amber' ]` line add

```css
.bwx-diary-line[ data-tone='sky' ] { --entry-ink: var( --state-info ); --entry-wash: var( --state-info-wash ); --entry-edge: var( --state-info-edge ); }
```

and after the `.bwx-calendar-diary[ data-tone='amber' ]` line add

```css
.bwx-calendar-diary[ data-tone='sky' ] { --entry-ink: var( --state-info ); --entry-wash: var( --state-info-wash ); --entry-edge: var( --state-info-edge ); }
```

- [ ] **Step 5: Build and run**

Run: `npm run build` → succeeds (fix any other `Record< DiaryEntry[ 'kind' ], … >` map the compiler names). Then `npx playwright test tests/e2e/reminders.spec.js tests/e2e/calendar-feed.spec.js tests/e2e/calendar.spec.js --workers=1` → PASS.

- [ ] **Step 6: Commit**

```bash
git add includes/Calendar/Feed.php src assets tests/e2e/reminders.spec.js
git commit -m "Reminders show on the calendar, over every day they cover"
```

---

### Task 5: My tasks sorts reminders by their start

**Files:**
- Modify: `src/components/MyTasksScreen.tsx`
- Test: `tests/e2e/reminders.spec.js` (new test)

**Interfaces:**
- Consumes: reminder copies have `recurring_id` starting `rem_` (Task 1).

- [ ] **Step 1: Write the failing test** — append to `tests/e2e/reminders.spec.js`:

```js
test('in My tasks a reminder waits by its start, then sits in Today until ticked', async ({ browser, baseURL }) => {
  test.slow();

  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { client, site } = await Forge.makeSite(admin.api, 'RemindMine', RUN_ID);
  const today = (await admin.api.get('/standup')).today;
  const person = await Forge.makePerson(admin.api, client.id, 'staff', 'remmine');

  const reminders = [
    [`Started ${RUN_ID}`, plus(today, -2), plus(today, 20)],
    [`In five ${RUN_ID}`, plus(today, 5), ''],
    [`In ten ${RUN_ID}`, plus(today, 10), plus(today, 12)],
  ];
  for (const [title, starts, ends] of reminders) {
    const made = await admin.api.post('/reminders', { client_site_id: site.id, title, assignees: [person.id], starts_on: starts, ends_on: ends });
    expect(made.status(), await made.text()).toBe(200);
  }

  const me = await Forge.signedIn(browser, baseURL, person.login, Forge.PASSWORD);
  const page = await me.context.newPage();
  await page.goto('/blueworx-forge/#screen=mytasks');
  const table = page.getByTestId('bwx-mytasks-table');
  await expect(table).toBeVisible({ timeout: 60_000 });

  // Today (the default): the one that has started, though it is not due for weeks.
  await expect(table.locator('tbody tr', { hasText: `Started ${RUN_ID}` })).toHaveCount(1);
  await expect(table).not.toContainText(`In five ${RUN_ID}`);
  await expect(table).not.toContainText(`In ten ${RUN_ID}`);

  await table.getByRole('button', { name: /^Next seven days/ }).click();
  await expect(table.locator('tbody tr', { hasText: `In five ${RUN_ID}` })).toHaveCount(1);

  await table.getByRole('button', { name: /^Further out/ }).click();
  await expect(table.locator('tbody tr', { hasText: `In ten ${RUN_ID}` })).toHaveCount(1);

  await table.getByRole('button', { name: /^Everything/ }).click();
  for (const [title] of reminders) {
    await expect(table.locator('tbody tr', { hasText: title })).toHaveCount(1);
  }
  await page.close();
});
```

- [ ] **Step 2: Run it to see it fail**

Run: `npx playwright test tests/e2e/reminders.spec.js -g "My tasks" --workers=1`
Expected: FAIL — "Started" is under Further out (it is sorted by its due date, 20 days away).

- [ ] **Step 3: Change `MyTasksScreen.tsx`**

`Mine` gains:

```ts
  /** A reminder's copy (2026-09-25): sorted by when it starts, not when it is due. */
  reminder: boolean;
  /** Days until a reminder starts; null otherwise. */
  starts: number | null;
```

In `load()`, the assignee branch becomes:

```ts
        if ( 0 < ( item.assignees?.length ?? 0 ) ) {
          if ( item.assignees.includes( person.id ) ) {
            const reminder = ( item.recurring_id ?? '' ).startsWith( 'rem_' );
            found.push( { id: `${ item.id }:assignee`, item, role: 'assignee', site, hours: item.hours_each, due, reminder, starts: reminder ? daysUntil( item.planned_start || '' ) : null } );
          }
          continue;
        }
```

and the seat push gains `reminder: false, starts: null`.

In `viewOf()`, after the `finished` line:

```ts
  // A reminder is Today from its first day until it is ticked (2026-09-25).
  if ( one.reminder && null !== one.starts ) {
    if ( one.starts <= 0 ) return 'today';
    return one.starts <= 7 ? 'week' : 'later';
  }
```

- [ ] **Step 4: Build and run**

Run: `npm run build`, then `npx playwright test tests/e2e/reminders.spec.js tests/e2e/my-tasks.spec.js tests/e2e/recurring-ticks.spec.js --workers=1` → PASS.

- [ ] **Step 5: Commit**

```bash
git add src assets tests/e2e/reminders.spec.js
git commit -m "My tasks sorts reminders by the day they start"
```

---

### Task 6: The Reminders page

**Files:**
- Create: `src/components/RemindersScreen.tsx`
- Modify: `src/types.ts` (`ScreenName` gains `'reminders'`; new `Reminder` interface)
- Modify: `src/App.tsx`
- Test: `tests/e2e/reminders.spec.js` (new test)

**Interfaces:**
- Consumes: `GET/POST/PATCH/DELETE /reminders` (Task 2).

- [ ] **Step 1: Write the failing test** — append to `tests/e2e/reminders.spec.js`:

```js
test('anyone on the team adds a reminder from its page', async ({ browser, baseURL }) => {
  test.slow();

  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { client, site } = await Forge.makeSite(admin.api, 'RemindPage', RUN_ID);
  const today = (await admin.api.get('/standup')).today;
  const person = await Forge.makePerson(admin.api, client.id, 'staff', 'rempage');
  const title = `From the page ${RUN_ID}`;

  const me = await Forge.signedIn(browser, baseURL, person.login, Forge.PASSWORD);
  const page = await me.context.newPage();
  await page.goto('/blueworx-forge/');
  await page.getByTestId('bwx-screen-reminders').click();
  await expect(page.getByTestId('bwx-reminders')).toBeVisible({ timeout: 60_000 });

  await page.getByTestId('bwx-reminders-add').click();
  await page.getByTestId('bwx-reminder-title').fill(title);
  await page.getByTestId('bwx-reminder-client').selectOption(site.id);
  await page.getByTestId(`bwx-reminder-person-${person.id}`).check();
  await page.getByTestId('bwx-reminder-starts').fill(plus(today, 1));
  await page.getByTestId('bwx-reminder-ends').fill(plus(today, 3));
  await page.getByTestId('bwx-reminder-save').click();

  const row = page.getByTestId('bwx-reminders-table').locator('tbody tr', { hasText: title });
  await expect(row).toHaveCount(1, { timeout: 30_000 });
  await expect(row).toContainText(client.display_name);
  await expect(row).toContainText('0 of 1 done');

  // Its author may edit it.
  await expect(row.getByTestId('bwx-reminder-edit')).toBeVisible();
  await page.close();
});
```

- [ ] **Step 2: Run it to see it fail**

Run: `npx playwright test tests/e2e/reminders.spec.js -g "its page" --workers=1`
Expected: FAIL — no `bwx-screen-reminders`.

- [ ] **Step 3: Types** — in `src/types.ts`, add `'reminders'` to `ScreenName` after `'recurring'`, and after `RecurringSource`:

```ts
/** A task on a fixed day or period, for one or more people (2026-09-25). */
export interface Reminder {
  id: string;
  client_site_id: string;
  client_id: string;
  title: string;
  description: string;
  assignees: string[];
  starts_on: string;
  /** '' for a one-day reminder. */
  ends_on: string;
  record_version: number;
  created_by: number;
  /** Whether the signed-in person may change it: its author, or an administrator. */
  can_edit: boolean;
  copies: Array< { item_id: string; person: string; done: boolean } >;
}
```

- [ ] **Step 4: Create `src/components/RemindersScreen.tsx`**

```tsx
import { useEffect, useState } from 'react';
import { Bell } from 'lucide-react';
import type { ClientSite, Person, Reminder, Stage } from '../types';
import { api, ApiError, isDenied, messageFor } from '../api';
import { useLiveReload } from '../live';
import { Aside, DataView, EmptyState, RichText } from '../kit';
import type { Column } from '../kit';
import { everybody, ItemPanel } from './ItemPanel';
import { Screen } from './States';

/**
 * Reminders (2026-09-25): a task on a fixed day or over a few, for one or
 * more people, on a client's site. Saving one makes each person their own
 * task straight away, which they see in My tasks and tick off; the calendar
 * shows it on every day it covers. Anyone adds one; its author or an
 * administrator changes it, and a change reaches only the copies nobody has
 * ticked.
 */

type Site = ClientSite & { client_name: string };

interface Draft {
  title: string;
  description: string;
  client_site_id: string;
  assignees: string[];
  starts_on: string;
  ends_on: string;
}

function today(): string {
  const now = new Date();

  return `${ now.getFullYear() }-${ String( now.getMonth() + 1 ).padStart( 2, '0' ) }-${ String( now.getDate() ).padStart( 2, '0' ) }`;
}

function blank(): Draft {
  return { title: '', description: '', client_site_id: '', assignees: [], starts_on: today(), ends_on: '' };
}

function fromReminder( reminder: Reminder ): Draft {
  return {
    title: reminder.title,
    description: reminder.description,
    client_site_id: reminder.client_site_id,
    assignees: reminder.assignees,
    starts_on: reminder.starts_on,
    ends_on: reminder.ends_on,
  };
}

function complete( draft: Draft ): boolean {
  return '' !== draft.title.trim() && '' !== draft.client_site_id && 0 < draft.assignees.length && '' !== draft.starts_on && ( '' === draft.ends_on || draft.ends_on >= draft.starts_on );
}

/** "3 Oct", or "1 Oct – 5 Oct" for a period. */
function when( reminder: Reminder ): string {
  const day = ( date: string ) => new Date( `${ date }T00:00:00Z` ).toLocaleDateString( 'en-GB', { day: 'numeric', month: 'short', timeZone: 'UTC' } );

  return '' === reminder.ends_on || reminder.ends_on === reminder.starts_on ? day( reminder.starts_on ) : `${ day( reminder.starts_on ) } – ${ day( reminder.ends_on ) }`;
}

export function siteLabel( site: Site ): string {
  return site.client_name && site.client_name !== site.name ? `${ site.client_name } · ${ site.name }` : site.client_name || site.name;
}

export function RemindersScreen() {
  const [ reminders, setReminders ] = useState< Reminder[] >( [] );
  const [ state, setState ] = useState< 'loading' | 'ready' | 'denied' | 'error' >( 'loading' );
  const [ notice, setNotice ] = useState( '' );
  const [ people, setPeople ] = useState< Person[] >( [] );
  const [ sites, setSites ] = useState< Site[] >( [] );
  const [ stages, setStages ] = useState< Stage[] >( [] );
  const [ editing, setEditing ] = useState< Reminder | 'new' | null >( null );
  const [ opened, setOpened ] = useState( '' );
  const [ busy, setBusy ] = useState( false );

  async function load() {
    try {
      const answer = await api< { denied: boolean; reminders: Reminder[] } >( '/reminders' );

      setReminders( answer.reminders );
      setState( answer.denied ? 'denied' : 'ready' );
    } catch ( error ) {
      setState( isDenied( error ) ? 'denied' : 'error' );
      setNotice( messageFor( error, 'Reminders could not be read.' ) );
    }
  }

  useEffect( () => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load();
    void everybody().then( setPeople );
    void api< { sites: Site[] } >( '/client-sites' ).then( ( answer ) => setSites( answer.sites ) ).catch( () => undefined );
    void api< { stages: Stage[] } >( '/stages' ).then( ( answer ) => setStages( answer.stages ) ).catch( () => undefined );
  }, [] );

  useLiveReload( load );

  const name = ( id: string ) => people.find( ( one ) => one.id === id )?.display_name ?? '?';
  const client = ( id: string ) => {
    const site = sites.find( ( one ) => one.id === id );

    return site ? siteLabel( site ) : '—';
  };

  async function remove( reminder: Reminder ) {
    if ( ! window.confirm( `Delete "${ reminder.title }"? Anyone who has ticked it keeps their record of it.` ) ) {
      return;
    }

    setBusy( true );
    setNotice( '' );

    try {
      await api( `/reminders/${ reminder.id }`, { method: 'DELETE' } );
      await load();
    } catch ( error ) {
      setNotice( messageFor( error, 'That did not work.' ) );
    } finally {
      setBusy( false );
    }
  }

  const columns: Column< Reminder >[] = [
    {
      key: 'title',
      label: 'Reminder',
      wrap: true,
      sortBy: ( r ) => r.title,
      // Opens the first copy, as Recurring tasks' "Last made" does.
      render: ( r ) =>
        r.copies[ 0 ] ? (
          <button type="button" className="bwx-row-open" data-testid="bwx-reminder-open" onClick={ () => setOpened( r.copies[ 0 ].item_id ) }>
            { r.title }
          </button>
        ) : (
          r.title
        ),
    },
    { key: 'client', label: 'Client', width: 200, sortBy: ( r ) => client( r.client_site_id ), render: ( r ) => client( r.client_site_id ) },
    { key: 'who', label: 'Who', width: 200, wrap: true, render: ( r ) => r.assignees.map( name ).join( ', ' ) },
    { key: 'when', label: 'When', width: 150, sortBy: ( r ) => r.starts_on, render: ( r ) => when( r ) },
    {
      key: 'done',
      label: 'Done',
      width: 110,
      render: ( r ) => `${ r.copies.filter( ( copy ) => copy.done ).length } of ${ r.copies.length } done`,
    },
    {
      key: 'actions',
      label: '',
      width: 170,
      render: ( r ) =>
        r.can_edit ? (
          <span className="bwx-recurring-actions">
            <button type="button" className="bwx-button" data-variant="quiet" data-testid="bwx-reminder-edit" disabled={ busy } onClick={ () => setEditing( r ) }>
              Edit
            </button>
            <button type="button" className="bwx-button" data-variant="quiet" data-testid="bwx-reminder-delete" disabled={ busy } onClick={ () => void remove( r ) }>
              Delete
            </button>
          </span>
        ) : null,
    },
  ];

  return (
    <>
      { 'loading' === state && <Screen state="loading" testId="bwx-reminders-state" /> }
      { 'denied' === state && <Screen state="denied" testId="bwx-reminders-state" detail="You are signed in, but do not reach any client's site." /> }
      { 'error' === state && <Screen state="error" testId="bwx-reminders-state" detail={ notice } /> }

      { 'ready' === state && (
        <div className="bwx-recurring" data-testid="bwx-reminders">
          { '' !== notice && (
            <p className="bwx-notice" role="status">
              { notice }
            </p>
          ) }
          <DataView< Reminder >
            title="Reminders"
            titleRight={
              <button type="button" className="bwx-button" data-testid="bwx-reminders-add" disabled={ busy } onClick={ () => setEditing( 'new' ) }>
                Add reminder
              </button>
            }
            columns={ columns }
            rows={ reminders }
            sortable
            empty={ <EmptyState icon={ Bell } dense title="No reminders yet" body="Add one for a day or a few, and each person gets their own task to tick off." /> }
            footer={ `${ reminders.length } reminders · each person gets their own task as soon as a reminder is saved` }
            testId="bwx-reminders-table"
          />
        </div>
      ) }

      { null !== editing && (
        <ReminderForm
          reminder={ 'new' === editing ? null : editing }
          people={ people }
          sites={ sites }
          onClose={ () => setEditing( null ) }
          onSaved={ () => {
            setEditing( null );
            void load();
          } }
        />
      ) }

      { '' !== opened && <ItemPanel itemId={ opened } stages={ stages } onClose={ () => setOpened( '' ) } onChanged={ () => void load() } /> }
    </>
  );
}

function ReminderForm( {
  reminder,
  people,
  sites,
  onClose,
  onSaved,
}: {
  reminder: Reminder | null;
  people: Person[];
  sites: Site[];
  onClose: () => void;
  onSaved: () => void;
} ) {
  const [ draft, setDraft ] = useState< Draft >( () => ( reminder ? fromReminder( reminder ) : blank() ) );
  const [ notice, setNotice ] = useState( '' );
  const [ busy, setBusy ] = useState( false );

  const set = < K extends keyof Draft >( key: K, value: Draft[ K ] ) => setDraft( { ...draft, [ key ]: value } );

  async function save() {
    setBusy( true );
    setNotice( '' );

    const body = {
      title: draft.title,
      description: draft.description,
      assignees: draft.assignees,
      starts_on: draft.starts_on,
      ends_on: draft.ends_on,
    };

    try {
      if ( reminder ) {
        await api( `/reminders/${ reminder.id }`, { method: 'PATCH', body: { ...body, record_version: reminder.record_version } } );
      } else {
        await api( '/reminders', { method: 'POST', body: { ...body, client_site_id: draft.client_site_id } } );
      }

      onSaved();
    } catch ( error ) {
      const fields = error instanceof ApiError ? ( error.data.fields as Record< string, string > | undefined ) : undefined;

      setNotice( fields ? Object.values( fields ).join( ' ' ) : messageFor( error, 'That could not be saved.' ) );
    } finally {
      setBusy( false );
    }
  }

  return (
    <Aside
      label={ reminder ? 'Edit reminder' : 'Add reminder' }
      testId="bwx-reminder-form"
      width={ 560 }
      onClose={ onClose }
      footer={
        <div className="bwx-moves">
          <button type="button" className="bwx-button" data-testid="bwx-reminder-save" disabled={ busy || ! complete( draft ) } onClick={ () => void save() }>
            { reminder ? 'Save' : 'Add' }
          </button>
          <button type="button" className="bwx-button" data-variant="quiet" onClick={ onClose }>
            Cancel
          </button>
        </div>
      }
    >
      { '' !== notice && (
        <p className="bwx-notice" data-testid="bwx-reminder-form-notice" role="status">
          { notice }
        </p>
      ) }

      <div className="bwx-field">
        <label htmlFor="bwx-reminder-title">Title</label>
        <input id="bwx-reminder-title" className="bwx-input" data-testid="bwx-reminder-title" autoFocus value={ draft.title } onChange={ ( event ) => set( 'title', event.target.value ) } />
      </div>

      <div className="bwx-field">
        <label htmlFor="bwx-reminder-client">Client</label>
        { /* A reminder stays with the client it was made for; edits change the rest. */ }
        <select id="bwx-reminder-client" className="bwx-select" data-testid="bwx-reminder-client" value={ draft.client_site_id } disabled={ null !== reminder } onChange={ ( event ) => set( 'client_site_id', event.target.value ) }>
          <option value="">Choose a client</option>
          { sites.map( ( site ) => (
            <option key={ site.id } value={ site.id }>
              { siteLabel( site ) }
            </option>
          ) ) }
        </select>
      </div>

      <div className="bwx-field">
        <label htmlFor="bwx-reminder-description">Notes (optional)</label>
        <RichText id="bwx-reminder-description" testId="bwx-reminder-description" label="Notes" value={ draft.description } onChange={ ( html ) => set( 'description', html ) } />
      </div>

      <div className="bwx-field bwx-recurring-dates">
        <span>
          <label htmlFor="bwx-reminder-starts">On, or from</label>
          <input id="bwx-reminder-starts" className="bwx-input" type="date" data-testid="bwx-reminder-starts" value={ draft.starts_on } onChange={ ( event ) => set( 'starts_on', event.target.value ) } />
        </span>
        <span>
          <label htmlFor="bwx-reminder-ends">Until (optional)</label>
          <input id="bwx-reminder-ends" className="bwx-input" type="date" data-testid="bwx-reminder-ends" min={ draft.starts_on } value={ draft.ends_on } onChange={ ( event ) => set( 'ends_on', event.target.value ) } />
        </span>
      </div>

      <fieldset className="bwx-field bwx-recurring-people" data-testid="bwx-reminder-people">
        <legend>Who</legend>
        { people.map( ( person ) => (
          <label key={ person.id } className="bwx-recurring-person">
            <input
              type="checkbox"
              data-testid={ `bwx-reminder-person-${ person.id }` }
              checked={ draft.assignees.includes( person.id ) }
              onChange={ ( event ) => set( 'assignees', event.target.checked ? [ ...draft.assignees, person.id ] : draft.assignees.filter( ( one ) => one !== person.id ) ) }
            />
            { person.display_name }
          </label>
        ) ) }
        { 0 === people.length && <span className="bwx-hint">Nobody on People yet.</span> }
      </fieldset>
    </Aside>
  );
}
```

- [ ] **Step 5: Wire it into `src/App.tsx`**

- Add `Bell` to the `lucide-react` import; `import { RemindersScreen } from './components/RemindersScreen';` after `RecurringScreen`.
- `RAIL`, after the recurring entry: `{ key: 'reminders', label: 'Reminders', icon: Bell, testId: 'bwx-screen-reminders' },`
- `TITLES`: `reminders: 'Reminders',`
- `OPENINGS`: `reminders: { crumbs: [ 'Delivery', 'Reminders' ], eyebrow: 'On a day, or over a few', tile: Bell, hue: 'teal' },`
- Render list, after recurring: `{ 'reminders' === screen && <RemindersScreen key={ generation } /> }`

- [ ] **Step 6: Build and run**

Run: `npm run build` → succeeds. Then `npx playwright test tests/e2e/reminders.spec.js tests/e2e/shell.spec.js tests/e2e/accessibility.spec.js --workers=1` → PASS (if `shell.spec.js` counts rail entries, add Reminders to its list).

- [ ] **Step 7: Check it in the browser** — `npm run dev`, open the Reminders page and the calendar, and ask Luke to confirm before committing (project rule: visible changes are confirmed by eye).

- [ ] **Step 8: Commit**

```bash
git add src assets tests/e2e
git commit -m "The Reminders page"
```

---

### Task 7: Version, changelog, checks, PR

**Files:**
- Modify: `package.json`, `blueworx-forge.php`, `client/blueworx-forge-client.php`, `CHANGELOG.md`

- [ ] **Step 1: Bump to 2.131.0** in the five places listed in Global Constraints (leave `package-lock.json` alone).

- [ ] **Step 2: Changelog** — under the header, above `## [2.130.0]`:

```markdown
## [2.131.0] - 2026-09-25

### Added

- Reminders: a task for a day or a few days, for one or more people on a client. Each person gets their own copy to tick off, it shows on the calendar across its days, and in My tasks it moves into Today when it starts.

### Changed

- Recurring tasks now ask which client they are for.
```

- [ ] **Step 3: Full checks, once**

Run: `npm run build`, `npm run lint`, `composer lint`, `vendor/bin/phpunit`, then the full Playwright suite on a fresh instance (`npm run wp:down; npm run wp:up; npm test`). Record the real output. Report lint findings to Luke rather than fixing them in a loop.

- [ ] **Step 4: Commit and open a draft PR**

```bash
git add package.json blueworx-forge.php client/blueworx-forge-client.php CHANGELOG.md
git commit -m "2.131.0"
git push -u origin add-reminders
gh pr create --draft --title "Reminders, and a client for recurring tasks" --body "Adds a Reminders page: a task for a day or a few days, for one or more people on a client. Each person gets their own copy; it shows on the calendar and in My tasks from its start day. Recurring tasks now ask which client they are for.

Tested: PHPUnit, the new reminders Playwright spec, and the full suite.

🤖 Generated with [Claude Code](https://claude.com/claude-code)"
```
