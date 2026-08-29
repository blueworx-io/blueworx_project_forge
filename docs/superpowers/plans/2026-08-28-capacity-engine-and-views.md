# Capacity engine and views — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Record what each person is committed to, count it once across every client, show the studio the picture, and let a client ask whether there is room without learning anything about anybody else.

**Architecture:** Five new pure-rule classes in `Blueworx\Forge\Capacity`, sitting on top of the existing `Availability` (#136). No new tables — `bwx_forge_work_items` already carries the three role seats, the two substitute seats, the planned dates and the three hours figures. One new studio REST controller, one new route on the existing client controller, one new React screen.

**Tech Stack:** PHP 7.4+ (WordPress coding standards, PHPCS), PHPUnit without a WordPress runtime, React 18 + TypeScript + Vite, Playwright.

**Spec:** `docs/superpowers/specs/2026-08-28-capacity-engine-and-views-design.md`

## Global Constraints

- **PHP style.** WordPress Coding Standards via `vendor/bin/phpcs`. Every file starts `<?php`, a docblock with `@package Blueworx\Forge`, then `declare( strict_types = 1 );`. Yoda conditions. Spaces inside parentheses. `array()` not `[]`.
- **Comment voice.** The codebase's comments say *why*, in plain English, and name the decision or issue they come from. Match `includes/Capacity/Availability.php`. Do not write comments that restate the code.
- **No new dependencies.** Adding one requires prior approval in `approved-deps.json`.
- **PHPUnit runs with no WordPress.** Anything needing a real site goes in Playwright. `DAY_IN_SECONDS` is defined in `tests/php/bootstrap.php`.
- **Playwright must not skip.** `phpunit.xml.dist` sets `failOnSkipped`; a skipped test is not a passing test.
- **Version and changelog.** Minor bump (new feature) from `2.33.0` to `2.34.0` in `package.json`, `blueworx-forge.php` (header + `BWX_FORGE_VERSION`), `client/blueworx-forge-client.php` (header + `BWX_FORGE_CLIENT_VERSION`), and a `CHANGELOG.md` entry. All four version strings must agree or CI fails. Do this once, in Task 9.
- **Lint runs once, at the end.** Do not loop lint → fix → lint. Report findings to the user.
- **Never merge, never tag.** Open a draft PR and stop.

---

### Task 1: Allocations — turning a work item into commitments

**Files:**
- Create: `includes/Capacity/Allocations.php`
- Test: `tests/php/CapacityAllocationsTest.php`

**Interfaces:**
- Consumes: `Blueworx\Forge\Work\Stages` (`Stages::BLOCKED`, `Stages::ALL`).
- Produces:
  - `Allocations::PRIMARY`, `Allocations::REVIEW`, `Allocations::DELIVERY` — role constants (`'primary'`, `'review'`, `'delivery'`).
  - `Allocations::COMMITTING` — `array<int,string>` of stages at which time is committed.
  - `Allocations::counts( array $item ): bool`
  - `Allocations::from_item( array $item ): array<int, array{item_id: string, title: string, client_id: string, client_site_id: string, role: string, user_id: string, covering: string, hours: float, from: string, to: string}>`
  - `Allocations::spread( array $allocation, array $days ): array<string, float>` — date to hours.

- [ ] **Step 1: Write the failing test**

Create `tests/php/CapacityAllocationsTest.php`:

```php
<?php
/**
 * What a work item commits, and when.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Capacity\Allocations;
use PHPUnit\Framework\TestCase;

/**
 * CAP-2's per-role hours read as dated commitments (#137).
 *
 * The cases below are the ones that would be wrong quietly: an idea counted as
 * a commitment, a substitute's work charged to the person they are covering,
 * and a fortnight's hours landing in one day because nobody checked which days
 * the person actually works.
 */
final class CapacityAllocationsTest extends TestCase {

	/**
	 * A work item row, with only what these rules read.
	 *
	 * @param array<string, mixed> $values Overrides.
	 * @return array<string, mixed>
	 */
	private function item( array $values = array() ): array {
		return array_merge(
			array(
				'id'                      => 'itm_1',
				'title'                   => 'A thing',
				'client_id'               => 'cli_1',
				'client_site_id'          => 'cst_1',
				'stage'                   => 'up-next',
				'prior_stage'             => '',
				'archived'                => 0,
				'terminal_outcome'        => '',
				'planned_start'           => '2026-09-07',
				'planned_due'             => '2026-09-11',
				'primary_user_id'         => 'usr_a',
				'reviewer_id'             => 'usr_b',
				'deliverer_id'            => '',
				'reviewer_substitute_id'  => '',
				'deliverer_substitute_id' => '',
				'hours_primary'           => 10.0,
				'hours_review'            => 2.0,
				'hours_delivery'          => 0.0,
			),
			$values
		);
	}

	/**
	 * Five weekdays a person works, as Availability reports them.
	 *
	 * @param array<string, float> $hours Date to hours, overriding the default 8.
	 * @return array<int, array<string, mixed>>
	 */
	private function week( array $hours = array() ): array {
		$days = array();

		foreach ( array( '2026-09-07', '2026-09-08', '2026-09-09', '2026-09-10', '2026-09-11' ) as $date ) {
			$days[] = array(
				'date'       => $date,
				'hours'      => array_key_exists( $date, $hours ) ? $hours[ $date ] : 8.0,
				'base_hours' => 8.0,
				'reason'     => '',
			);
		}

		return $days;
	}

	public function test_an_item_at_up_next_commits_its_filled_seats(): void {
		$allocations = Allocations::from_item( $this->item() );

		$this->assertCount( 2, $allocations, 'the empty deliverer seat is not a commitment' );
		$this->assertSame( Allocations::PRIMARY, $allocations[0]['role'] );
		$this->assertSame( 'usr_a', $allocations[0]['user_id'] );
		$this->assertSame( 10.0, $allocations[0]['hours'] );
		$this->assertSame( 'usr_b', $allocations[1]['user_id'] );
	}

	public function test_an_idea_commits_nothing(): void {
		$this->assertSame( array(), Allocations::from_item( $this->item( array( 'stage' => 'triage' ) ) ) );
	}

	public function test_finished_and_abandoned_work_commits_nothing(): void {
		$this->assertSame( array(), Allocations::from_item( $this->item( array( 'stage' => 'released' ) ) ) );
		$this->assertSame( array(), Allocations::from_item( $this->item( array( 'terminal_outcome' => 'cancelled' ) ) ) );
		$this->assertSame( array(), Allocations::from_item( $this->item( array( 'archived' => 1 ) ) ) );
	}

	public function test_blocked_work_still_counts_when_it_was_already_committed(): void {
		$blocked = $this->item(
			array(
				'stage'       => 'blocked',
				'prior_stage' => 'in-development',
			)
		);

		$this->assertCount( 2, Allocations::from_item( $blocked ) );

		$never_committed = $this->item(
			array(
				'stage'       => 'blocked',
				'prior_stage' => 'triage',
			)
		);

		$this->assertSame( array(), Allocations::from_item( $never_committed ) );
	}

	public function test_a_substitute_carries_the_commitment(): void {
		$allocations = Allocations::from_item( $this->item( array( 'reviewer_substitute_id' => 'usr_c' ) ) );

		$review = $allocations[1];

		$this->assertSame( 'usr_c', $review['user_id'], 'the substitute is the one doing the work' );
		$this->assertSame( 'usr_b', $review['covering'], 'and the record says who they are covering' );
	}

	public function test_a_seat_with_no_hours_is_not_a_commitment(): void {
		$allocations = Allocations::from_item( $this->item( array( 'hours_review' => 0.0 ) ) );

		$this->assertCount( 1, $allocations );
	}

	public function test_work_with_no_dates_commits_nothing_yet(): void {
		$undated = $this->item(
			array(
				'planned_start' => '',
				'planned_due'   => '',
			)
		);

		$this->assertSame( array(), Allocations::from_item( $undated ) );
	}

	public function test_hours_spread_evenly_across_working_days(): void {
		$allocation = Allocations::from_item( $this->item() )[0];

		$spread = Allocations::spread( $allocation, $this->week() );

		$this->assertSame( 2.0, $spread['2026-09-07'] );
		$this->assertSame( 10.0, round( array_sum( $spread ), 2 ) );
	}

	public function test_a_day_off_carries_none_of_it(): void {
		$allocation = Allocations::from_item( $this->item() )[0];

		$spread = Allocations::spread( $allocation, $this->week( array( '2026-09-09' => 0.0 ) ) );

		$this->assertArrayNotHasKey( '2026-09-09', $spread, 'a day they are away carries no work' );
		$this->assertSame( 2.5, $spread['2026-09-07'] );
		$this->assertSame( 10.0, round( array_sum( $spread ), 2 ) );
	}

	public function test_hours_that_do_not_divide_still_add_up(): void {
		$allocation = Allocations::from_item( $this->item( array( 'hours_primary' => 10.0 ) ) )[0];

		$spread = Allocations::spread( $allocation, $this->week( array( '2026-09-11' => 0.0 ) ) );

		$this->assertSame( 10.0, round( array_sum( $spread ), 2 ), 'the total reconciles whatever the rounding does' );
	}

	public function test_a_window_with_no_working_days_keeps_its_hours(): void {
		$allocation = Allocations::from_item( $this->item() )[0];

		$spread = Allocations::spread(
			$allocation,
			$this->week(
				array(
					'2026-09-07' => 0.0,
					'2026-09-08' => 0.0,
					'2026-09-09' => 0.0,
					'2026-09-10' => 0.0,
					'2026-09-11' => 0.0,
				)
			)
		);

		$this->assertSame( 10.0, round( array_sum( $spread ), 2 ), 'committed hours are never silently dropped' );
		$this->assertSame( 10.0, $spread['2026-09-07'] );
	}

	public function test_days_outside_the_window_carry_nothing(): void {
		$allocation = Allocations::from_item(
			$this->item(
				array(
					'planned_start' => '2026-09-08',
					'planned_due'   => '2026-09-09',
				)
			)
		)[0];

		$spread = Allocations::spread( $allocation, $this->week() );

		$this->assertSame( array( '2026-09-08', '2026-09-09' ), array_keys( $spread ) );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter CapacityAllocationsTest`
Expected: FAIL — `Class "Blueworx\Forge\Capacity\Allocations" not found`.

- [ ] **Step 3: Write the implementation**

Create `includes/Capacity/Allocations.php`:

```php
<?php
/**
 * What work commits of somebody's time, and over which days.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Capacity;

use Blueworx\Forge\Work\Stages;

/**
 * CAP-2's per-role hours, read as dated commitments (#137).
 *
 * An allocation is one person, committed for some hours, between two dates,
 * because of one piece of work. Defining it that way round rather than as a
 * total on an item is the whole point: every consumer in M7 — the cross-client
 * figure, the capacity view, the gate at Up Next — needs to know *when*, and if
 * each worked the dates out for itself they would disagree about the same item.
 *
 * There is no table behind this. The three seats, the two substitute seats, the
 * three hours figures and the two dates are already columns on the work item,
 * so a commitment is a reading of a row rather than a second record that can
 * drift from it.
 */
final class Allocations {

	/**
	 * The person who does the work.
	 */
	public const PRIMARY = 'primary';

	/**
	 * The person who reviews it.
	 */
	public const REVIEW = 'review';

	/**
	 * The person who ships it.
	 */
	public const DELIVERY = 'delivery';

	/**
	 * The stages at which time is committed.
	 *
	 * Up Next is where hours are reserved (COMM-2), so it is where a person
	 * starts being busy. Counting earlier stages would have everybody
	 * permanently full of ideas that will never be built, and a capacity view
	 * nobody believes is a capacity view nobody opens. Counting later ones —
	 * Completed, Released — would have finished work still taking up next
	 * month.
	 */
	public const COMMITTING = array(
		'up-next',
		'in-development',
		'in-review',
	);

	/**
	 * Each seat: the hours column, whose seat it is, and who may be covering.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const SEATS = array(
		self::PRIMARY  => array( 'hours_primary', 'primary_user_id', '' ),
		self::REVIEW   => array( 'hours_review', 'reviewer_id', 'reviewer_substitute_id' ),
		self::DELIVERY => array( 'hours_delivery', 'deliverer_id', 'deliverer_substitute_id' ),
	);

	/**
	 * Whether an item commits anybody's time at all.
	 *
	 * @param array<string, mixed> $item A hydrated work item.
	 * @return bool
	 */
	public static function counts( array $item ): bool {
		if ( ! empty( $item['archived'] ) ) {
			return false;
		}

		if ( '' !== (string) ( $item['terminal_outcome'] ?? '' ) ) {
			return false;
		}

		$stage = (string) ( $item['stage'] ?? '' );

		/*
		 * Blocked work is still on somebody's plate. It stopped for a reason
		 * outside the person's control, and the day it unblocks it has to fit
		 * somewhere — so dropping it would show room that is already spoken
		 * for. Where it was blocked before it was ever committed, it is still
		 * only an idea.
		 */
		if ( Stages::BLOCKED === $stage ) {
			$stage = (string) ( $item['prior_stage'] ?? '' );
		}

		return in_array( $stage, self::COMMITTING, true );
	}

	/**
	 * The commitments one item makes.
	 *
	 * @param array<string, mixed> $item A hydrated work item.
	 * @return array<int, array<string, mixed>>
	 */
	public static function from_item( array $item ): array {
		if ( ! self::counts( $item ) ) {
			return array();
		}

		$window = self::window( $item );

		if ( array() === $window ) {
			// Hours with no dates are a plan nobody has finished making. #141
			// makes both mandatory before Up Next; until then, an item like
			// this is left out rather than guessed at, because a guessed date
			// puts somebody's week in the wrong month.
			return array();
		}

		$out = array();

		foreach ( self::SEATS as $role => $columns ) {
			$hours = round( (float) ( $item[ $columns[0] ] ?? 0 ), 2 );
			$seat  = (string) ( $item[ $columns[1] ] ?? '' );
			$cover = '' === $columns[2] ? '' : (string) ( $item[ $columns[2] ] ?? '' );
			$who   = '' !== $cover ? $cover : $seat;

			if ( $hours <= 0 || '' === $who ) {
				continue;
			}

			$out[] = array(
				'item_id'        => (string) ( $item['id'] ?? '' ),
				'title'          => (string) ( $item['title'] ?? '' ),
				'client_id'      => (string) ( $item['client_id'] ?? '' ),
				'client_site_id' => (string) ( $item['client_site_id'] ?? '' ),
				'role'           => $role,
				'user_id'        => $who,
				// Who is being covered, where somebody is standing in. The
				// commitment follows whoever is doing the work (AUTH-4 records
				// the seat); this says so on the face of it, so a capacity view
				// can explain why somebody is carrying a review that is not
				// theirs.
				'covering'       => '' !== $cover ? $seat : '',
				'hours'          => $hours,
				'from'           => $window[0],
				'to'             => $window[1],
			);
		}

		return $out;
	}

	/**
	 * One allocation's hours, day by day.
	 *
	 * Evenly across the days the person actually works, so a fortnight's job
	 * reads as a fortnight's load. The days come from Availability, which is
	 * why a day of leave carries none of it and the rest of the window absorbs
	 * it.
	 *
	 * @param array<string, mixed>             $allocation One allocation.
	 * @param array<int, array<string, mixed>> $days       Availability::by_day for the person.
	 * @return array<string, float> Date to hours.
	 */
	public static function spread( array $allocation, array $days ): array {
		$from  = (string) ( $allocation['from'] ?? '' );
		$to    = (string) ( $allocation['to'] ?? '' );
		$hours = round( (float) ( $allocation['hours'] ?? 0 ), 2 );

		if ( '' === $from || '' === $to || $hours <= 0 ) {
			return array();
		}

		$within  = array();
		$working = array();

		foreach ( $days as $day ) {
			$date = (string) ( $day['date'] ?? '' );

			if ( $date < $from || $date > $to ) {
				continue;
			}

			$within[] = $date;

			if ( (float) ( $day['hours'] ?? 0 ) > 0 ) {
				$working[] = $date;
			}
		}

		if ( array() === $working ) {
			/*
			 * Nobody works a day in this window — all leave, or their hours
			 * were never set up. The hours still exist and somebody still owes
			 * them, so they land on the first day rather than vanishing. A
			 * total that reconciles to nothing is worse than a total that looks
			 * awkward, because the awkward one gets fixed.
			 */
			$first = array() !== $within ? $within[0] : $from;

			return array( $first => $hours );
		}

		$each   = round( $hours / count( $working ), 2 );
		$spread = array();
		$run    = 0.0;

		foreach ( $working as $index => $date ) {
			// The last day takes whatever the rounding left, so the days always
			// add back up to the hours that were committed.
			$value = count( $working ) - 1 === $index ? round( $hours - $run, 2 ) : $each;

			$spread[ $date ] = $value;
			$run            += $value;
		}

		return $spread;
	}

	/**
	 * An item's window, where it has one.
	 *
	 * @param array<string, mixed> $item A hydrated work item.
	 * @return array<int, string> Empty when there are no dates.
	 */
	private static function window( array $item ): array {
		$from = (string) ( $item['planned_start'] ?? '' );
		$to   = (string) ( $item['planned_due'] ?? '' );

		if ( '' === $from && '' === $to ) {
			return array();
		}

		// One date is a plan for a day. Dates the wrong way round are a typo,
		// and reading them literally would produce an empty window that
		// silently drops the hours.
		$from = '' === $from ? $to : $from;
		$to   = '' === $to || $to < $from ? $from : $to;

		return array( $from, $to );
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter CapacityAllocationsTest`
Expected: PASS, 12 tests.

- [ ] **Step 5: Commit**

```bash
git add includes/Capacity/Allocations.php tests/php/CapacityAllocationsTest.php
git commit -m "Read a work item's role hours as dated commitments (#137)"
```

---

### Task 2: Seeding the reviewer and deliverer hours

**Files:**
- Create: `includes/Work/RoleHours.php`
- Modify: `includes/Work/Items.php` (in `create()` and `update()`)
- Test: `tests/php/WorkRoleHoursTest.php`

**Interfaces:**
- Consumes: nothing from Task 1.
- Produces: `RoleHours::REVIEW_RATIO` (float `0.2`), `RoleHours::DELIVERY_RATIO` (float `0.1`), `RoleHours::seed( array $changes, array $current = array() ): array`.

- [ ] **Step 1: Write the failing test**

Create `tests/php/WorkRoleHoursTest.php`:

```php
<?php
/**
 * Where the reviewer's and deliverer's hours come from.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Work\RoleHours;
use PHPUnit\Framework\TestCase;

/**
 * CAP-2's seeded defaults (#137).
 *
 * The rule is only useful if it is quiet in the common case and absent in the
 * unusual one, so the cases that matter are the ones where it should do
 * nothing: a figure somebody typed, and a figure somebody deliberately set to
 * zero.
 */
final class WorkRoleHoursTest extends TestCase {

	public function test_a_primary_estimate_seeds_the_other_two(): void {
		$seeded = RoleHours::seed( array( 'hours_primary' => 10.0 ) );

		$this->assertSame( 2.0, $seeded['hours_review'] );
		$this->assertSame( 1.0, $seeded['hours_delivery'] );
	}

	public function test_it_does_not_overwrite_what_somebody_typed(): void {
		$seeded = RoleHours::seed(
			array(
				'hours_primary' => 10.0,
				'hours_review'  => 4.0,
			)
		);

		$this->assertSame( 4.0, $seeded['hours_review'], 'a figure that was set stays set' );
		$this->assertSame( 1.0, $seeded['hours_delivery'] );
	}

	public function test_it_does_not_overwrite_a_deliberate_zero(): void {
		$seeded = RoleHours::seed(
			array(
				'hours_primary' => 10.0,
				'hours_review'  => 0.0,
			)
		);

		$this->assertArrayNotHasKey( 'hours_review', $seeded, 'saying zero is saying something' );
	}

	public function test_it_leaves_an_item_that_already_has_hours_alone(): void {
		$seeded = RoleHours::seed(
			array( 'hours_primary' => 12.0 ),
			array(
				'hours_review'   => 3.0,
				'hours_delivery' => 1.5,
			)
		);

		$this->assertArrayNotHasKey( 'hours_review', $seeded, 're-estimating the work does not undo an edit' );
		$this->assertArrayNotHasKey( 'hours_delivery', $seeded );
	}

	public function test_it_does_nothing_without_a_primary_estimate(): void {
		$this->assertSame( array( 'title' => 'A thing' ), RoleHours::seed( array( 'title' => 'A thing' ) ) );
	}

	public function test_it_does_nothing_for_a_primary_estimate_of_zero(): void {
		$seeded = RoleHours::seed( array( 'hours_primary' => 0.0 ) );

		$this->assertArrayNotHasKey( 'hours_review', $seeded );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter WorkRoleHoursTest`
Expected: FAIL — `Class "Blueworx\Forge\Work\RoleHours" not found`.

- [ ] **Step 3: Write the implementation**

Create `includes/Work/RoleHours.php`:

```php
<?php
/**
 * Where the reviewer's and deliverer's hours start from.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Work;

/**
 * CAP-2's seeded defaults (#137).
 *
 * Hours are entered per role, because one total cannot say who is committed for
 * how long when three people are involved. That is right and it is also three
 * boxes to fill in for every piece of work, most of which follow the same
 * proportions — so the two supporting figures are seeded from the estimate and
 * left editable.
 *
 * Seeding, never forcing. A figure somebody has already set is left alone, and
 * that includes a zero: "this needs no review time" is something a person can
 * decide, and a default that overwrote it would quietly re-decide it every time
 * the estimate changed.
 */
final class RoleHours {

	/**
	 * The reviewer's share of the estimate.
	 */
	public const REVIEW_RATIO = 0.2;

	/**
	 * The deliverer's share of the estimate.
	 */
	public const DELIVERY_RATIO = 0.1;

	/**
	 * Fills in the two supporting figures where nobody has spoken about them.
	 *
	 * @param array<string, mixed> $changes What is being written.
	 * @param array<string, mixed> $current The item as it stands, when there is one.
	 * @return array<string, mixed> The changes, possibly with two more.
	 */
	public static function seed( array $changes, array $current = array() ): array {
		if ( ! array_key_exists( 'hours_primary', $changes ) ) {
			return $changes;
		}

		$primary = round( (float) $changes['hours_primary'], 2 );

		if ( $primary <= 0 ) {
			return $changes;
		}

		foreach ( array( 'hours_review' => self::REVIEW_RATIO, 'hours_delivery' => self::DELIVERY_RATIO ) as $column => $ratio ) {
			// Named in this write, or already carrying a figure on the item:
			// either way somebody has spoken, and this is not the place to
			// argue with them.
			if ( array_key_exists( $column, $changes ) ) {
				continue;
			}

			if ( round( (float) ( $current[ $column ] ?? 0 ), 2 ) > 0 ) {
				continue;
			}

			$changes[ $column ] = round( $primary * $ratio, 2 );
		}

		return $changes;
	}
}
```

- [ ] **Step 4: Wire it into writes**

In `includes/Work/Items.php`, add `use` for nothing new (same namespace), then in `create()` change the `self::writable( $values )` argument of the `array_merge` to `RoleHours::seed( self::writable( $values ) )`.

In `update()`, replace the first two statements of the method body with:

```php
		$changes = RoleHours::seed( self::writable( $values ), (array) self::get( $id ) );
```

Add this comment immediately above it:

```php
		/*
		 * The item is read first so a seeded default cannot overwrite a figure
		 * already on it (CAP-2). One extra read on the rare write that changes
		 * an estimate, against silently re-deciding somebody's review time
		 * every time the estimate moves.
		 */
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit`
Expected: PASS, no regressions in the existing suites.

- [ ] **Step 6: Commit**

```bash
git add includes/Work/RoleHours.php includes/Work/Items.php tests/php/WorkRoleHoursTest.php
git commit -m "Seed the reviewer and deliverer hours from the estimate (#137)"
```

---

### Task 3: Commitments — the cross-client total

**Files:**
- Create: `includes/Capacity/Commitments.php`
- Test: `tests/php/CapacityCommitmentsTest.php`

**Interfaces:**
- Consumes: `Allocations::from_item()`, `Allocations::spread()` from Task 1; `Capacity\Availability::by_day()`; `Data\Schema::work_items_table()`; `Work\Items::hydrate()` is private, so this class runs rows through `Allocations::from_item()` directly — the columns it reads are all present on a raw row.
- Produces:
  - `Commitments::live( string $from, string $to ): array<int, array<string, mixed>>` — every allocation across every client overlapping the window.
  - `Commitments::gather( array $allocations, array $days_by_user ): array<string, array{hours: float, by_day: array<string,float>, allocations: array<int, array<string,mixed>>}>` — the pure rule.
  - `Commitments::for_people( array $user_ids, string $from, string $to ): array<string, array{hours: float, by_day: array<string,float>, allocations: array<int, array<string,mixed>>}>`
  - `Commitments::hours( string $user_id, string $from, string $to ): float`

- [ ] **Step 1: Write the failing test**

Create `tests/php/CapacityCommitmentsTest.php`:

```php
<?php
/**
 * One person, every client, counted once.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Capacity\Commitments;
use PHPUnit\Framework\TestCase;

/**
 * The cross-client figure (#138).
 *
 * The failure this exists to stop is a person looking free on one client while
 * committed on another, so the case that matters is the one where the same
 * person appears on two clients' work at once.
 */
final class CapacityCommitmentsTest extends TestCase {

	/**
	 * One allocation, as Allocations::from_item produces them.
	 *
	 * @param string $user_id Person.
	 * @param float  $hours   Hours.
	 * @param string $client  Client id.
	 * @return array<string, mixed>
	 */
	private function allocation( string $user_id, float $hours, string $client ): array {
		return array(
			'item_id'        => 'itm_' . $client,
			'title'          => 'Work for ' . $client,
			'client_id'      => $client,
			'client_site_id' => 'cst_' . $client,
			'role'           => 'primary',
			'user_id'        => $user_id,
			'covering'       => '',
			'hours'          => $hours,
			'from'           => '2026-09-07',
			'to'             => '2026-09-08',
		);
	}

	/**
	 * Two working days, as Availability reports them.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function days(): array {
		return array(
			array(
				'date'       => '2026-09-07',
				'hours'      => 8.0,
				'base_hours' => 8.0,
				'reason'     => '',
			),
			array(
				'date'       => '2026-09-08',
				'hours'      => 8.0,
				'base_hours' => 8.0,
				'reason'     => '',
			),
		);
	}

	public function test_two_clients_make_one_combined_commitment(): void {
		$gathered = Commitments::gather(
			array(
				$this->allocation( 'usr_a', 6.0, 'cli_1' ),
				$this->allocation( 'usr_a', 4.0, 'cli_2' ),
			),
			array( 'usr_a' => $this->days() )
		);

		$this->assertSame( 10.0, $gathered['usr_a']['hours'], 'one person, one total' );
		$this->assertCount( 2, $gathered['usr_a']['allocations'], 'and both pieces of work behind it' );
		$this->assertSame( 5.0, $gathered['usr_a']['by_day']['2026-09-07'] );
	}

	public function test_people_are_kept_apart(): void {
		$gathered = Commitments::gather(
			array(
				$this->allocation( 'usr_a', 6.0, 'cli_1' ),
				$this->allocation( 'usr_b', 4.0, 'cli_1' ),
			),
			array(
				'usr_a' => $this->days(),
				'usr_b' => $this->days(),
			)
		);

		$this->assertSame( 6.0, $gathered['usr_a']['hours'] );
		$this->assertSame( 4.0, $gathered['usr_b']['hours'] );
	}

	public function test_two_seats_on_one_item_are_two_commitments(): void {
		$primary          = $this->allocation( 'usr_a', 6.0, 'cli_1' );
		$review           = $this->allocation( 'usr_a', 2.0, 'cli_1' );
		$review['role']   = 'review';

		$gathered = Commitments::gather( array( $primary, $review ), array( 'usr_a' => $this->days() ) );

		$this->assertSame( 8.0, $gathered['usr_a']['hours'], 'doing it and reviewing it are both real time' );
	}

	public function test_somebody_with_nothing_on_is_still_answered(): void {
		$gathered = Commitments::gather( array(), array( 'usr_a' => $this->days() ) );

		$this->assertSame( 0.0, $gathered['usr_a']['hours'] );
		$this->assertSame( array(), $gathered['usr_a']['allocations'] );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter CapacityCommitmentsTest`
Expected: FAIL — `Class "Blueworx\Forge\Capacity\Commitments" not found`.

- [ ] **Step 3: Write the implementation**

Create `includes/Capacity/Commitments.php`:

```php
<?php
/**
 * What is already spoken for, across every client at once.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Capacity;

use Blueworx\Forge\Data\Schema;
use Blueworx\Forge\Work\Stages;

/**
 * The cross-client commitment figure (#138).
 *
 * A person cannot look free on one client while committed on another, which
 * means this is the one read in Forge whose whole purpose is to span tenants.
 * Everywhere else, a read scoped to a client is the safe answer; here it is the
 * wrong one, and a per-client capacity figure would be actively misleading —
 * three clients each shown a person with plenty of room, and the person with
 * none.
 *
 * People are already global records (AUTH-6), so counting once is a matter of
 * summing across clients rather than reconciling three identities. What has to
 * be got right is the boundary: this read is reachable from the studio only,
 * and the route that exposes it says so.
 */
final class Commitments {

	/**
	 * Every allocation across every client overlapping a window.
	 *
	 * Deliberately unscoped. The stages and the window do the narrowing, which
	 * keeps the result to live work rather than to the whole history.
	 *
	 * @param string $from YYYY-MM-DD, inclusive.
	 * @param string $to   YYYY-MM-DD, inclusive.
	 * @return array<int, array<string, mixed>>
	 */
	public static function live( string $from, string $to ): array {
		global $wpdb;

		if ( '' === $from || '' === $to || $to < $from ) {
			return array();
		}

		$table  = Schema::work_items_table();
		$stages = array_merge( Allocations::COMMITTING, array( Stages::BLOCKED ) );
		$slots  = implode( ', ', array_fill( 0, count( $stages ), '%s' ) );

		$values = $stages;
		// An item overlaps the window when it starts before the window ends and
		// ends after the window starts. Written this way round so a job that
		// spans the whole period is included rather than missed for starting
		// before it.
		$values[] = $to;
		$values[] = $from;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table name and stage list are this class's own literals; the placeholders are counted above.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE archived = 0
				AND terminal_outcome = ''
				AND stage IN ({$slots})
				AND planned_start <> ''
				AND planned_due <> ''
				AND planned_start <= %s
				AND planned_due >= %s",
				$values
			),
			ARRAY_A
		);

		$out = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			// Allocations decides whether a Blocked item counts, from the stage
			// it was blocked out of. The query cannot ask that question, so it
			// fetches Blocked and lets the rule refuse it.
			foreach ( Allocations::from_item( $row ) as $allocation ) {
				$out[] = $allocation;
			}
		}

		return $out;
	}

	/**
	 * The same answer, worked out from allocations already in hand.
	 *
	 * No database in it, so "one person, one total, however many clients" can
	 * be stated in a test rather than inferred from a site.
	 *
	 * @param array<int, array<string, mixed>>       $allocations  Every allocation to consider.
	 * @param array<string, array<int, array<string, mixed>>> $days_by_user Availability::by_day per person.
	 * @return array<string, array<string, mixed>>
	 */
	public static function gather( array $allocations, array $days_by_user ): array {
		$out = array();

		foreach ( array_keys( $days_by_user ) as $user_id ) {
			$out[ $user_id ] = array(
				'hours'       => 0.0,
				'by_day'      => array(),
				'allocations' => array(),
			);
		}

		foreach ( $allocations as $allocation ) {
			$user_id = (string) ( $allocation['user_id'] ?? '' );

			if ( ! isset( $out[ $user_id ] ) ) {
				continue;
			}

			$spread = Allocations::spread( $allocation, $days_by_user[ $user_id ] );

			foreach ( $spread as $date => $hours ) {
				$out[ $user_id ]['by_day'][ $date ] = round( ( $out[ $user_id ]['by_day'][ $date ] ?? 0.0 ) + $hours, 2 );
			}

			$out[ $user_id ]['allocations'][] = array_merge( $allocation, array( 'by_day' => $spread ) );
			$out[ $user_id ]['hours']         = round( $out[ $user_id ]['hours'] + array_sum( $spread ), 2 );
		}

		foreach ( array_keys( $out ) as $user_id ) {
			ksort( $out[ $user_id ]['by_day'] );
		}

		return $out;
	}

	/**
	 * What a set of people are committed to over a window.
	 *
	 * @param array<int, string> $user_ids The people.
	 * @param string             $from     YYYY-MM-DD, inclusive.
	 * @param string             $to       YYYY-MM-DD, inclusive.
	 * @return array<string, array<string, mixed>>
	 */
	public static function for_people( array $user_ids, string $from, string $to ): array {
		$days = array();

		foreach ( $user_ids as $user_id ) {
			$days[ $user_id ] = Availability::by_day( $user_id, $from, $to );
		}

		// One query for everybody, not one per person: the capacity view asks
		// this for the whole studio at once, and a query per person over eight
		// weeks was the difference between a page and a wait.
		return self::gather( self::live( $from, $to ), $days );
	}

	/**
	 * One person's committed hours over a window.
	 *
	 * @param string $user_id The person.
	 * @param string $from    YYYY-MM-DD, inclusive.
	 * @param string $to      YYYY-MM-DD, inclusive.
	 * @return float
	 */
	public static function hours( string $user_id, string $from, string $to ): float {
		$gathered = self::for_people( array( $user_id ), $from, $to );

		return (float) ( $gathered[ $user_id ]['hours'] ?? 0.0 );
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter CapacityCommitmentsTest`
Expected: PASS, 4 tests.

- [ ] **Step 5: Commit**

```bash
git add includes/Capacity/Commitments.php tests/php/CapacityCommitmentsTest.php
git commit -m "Count a person's commitments once across every client (#138)"
```

---

### Task 4: Position and Periods — available against committed

**Files:**
- Create: `includes/Capacity/Position.php`
- Create: `includes/Capacity/Periods.php`
- Test: `tests/php/CapacityPositionTest.php`

**Interfaces:**
- Consumes: `Availability::by_day()`, `Availability::is_recorded()`; `Commitments::for_people()` from Task 3.
- Produces:
  - `Position::CLEAR` (`'clear'`), `Position::TIGHT` (`'tight'`), `Position::OVER` (`'over'`), `Position::UNRECORDED` (`'unrecorded'`), `Position::TIGHT_AT` (float `0.8`).
  - `Position::calculate( float $available, float $committed, bool $recorded ): array{available: float, committed: float, remaining: float, band: string}`
  - `Position::for_people( array $user_ids, string $from, string $to ): array<string, array<string, mixed>>`
  - `Periods::weeks( string $from, string $to ): array<int, array{from: string, to: string}>`

- [ ] **Step 1: Write the failing test**

Create `tests/php/CapacityPositionTest.php`:

```php
<?php
/**
 * Time against commitment, and what to call the result.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Capacity\Periods;
use Blueworx\Forge\Capacity\Position;
use PHPUnit\Framework\TestCase;

/**
 * The two sides of a capacity figure, in one place (#139).
 *
 * "They had no time" and "their time was already spoken for" are different
 * facts, and a view that showed both as a red cell would have people chasing
 * the wrong thing. So would a person nobody has set up reading as a person with
 * no room.
 */
final class CapacityPositionTest extends TestCase {

	public function test_room_to_spare_reads_as_clear(): void {
		$position = Position::calculate( 40.0, 10.0, true );

		$this->assertSame( 30.0, $position['remaining'] );
		$this->assertSame( Position::CLEAR, $position['band'] );
	}

	public function test_nearly_full_reads_as_tight(): void {
		$this->assertSame( Position::TIGHT, Position::calculate( 40.0, 32.0, true )['band'] );
		$this->assertSame( Position::TIGHT, Position::calculate( 40.0, 40.0, true )['band'] );
	}

	public function test_more_committed_than_available_reads_as_over(): void {
		$position = Position::calculate( 40.0, 44.0, true );

		$this->assertSame( -4.0, $position['remaining'] );
		$this->assertSame( Position::OVER, $position['band'] );
	}

	public function test_a_person_nobody_has_set_up_is_not_a_person_with_no_room(): void {
		$position = Position::calculate( 0.0, 0.0, false );

		$this->assertSame( Position::UNRECORDED, $position['band'] );
	}

	public function test_a_full_week_of_leave_is_not_over_committed(): void {
		$this->assertSame( Position::CLEAR, Position::calculate( 0.0, 0.0, true )['band'] );
		$this->assertSame( Position::OVER, Position::calculate( 0.0, 3.0, true )['band'] );
	}

	public function test_a_range_splits_into_weeks_starting_monday(): void {
		$weeks = Periods::weeks( '2026-09-09', '2026-09-22' );

		$this->assertSame( '2026-09-09', $weeks[0]['from'], 'the first week starts where the range does' );
		$this->assertSame( '2026-09-13', $weeks[0]['to'], 'and ends on the Sunday' );
		$this->assertSame( '2026-09-14', $weeks[1]['from'] );
		$this->assertSame( '2026-09-20', $weeks[1]['to'] );
		$this->assertSame( '2026-09-22', $weeks[2]['to'], 'the last week stops where the range does' );
		$this->assertCount( 3, $weeks );
	}

	public function test_a_backwards_range_has_no_weeks(): void {
		$this->assertSame( array(), Periods::weeks( '2026-09-22', '2026-09-09' ) );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter CapacityPositionTest`
Expected: FAIL — `Class "Blueworx\Forge\Capacity\Periods" not found`.

- [ ] **Step 3: Write Periods**

Create `includes/Capacity/Periods.php`:

```php
<?php
/**
 * The weeks a capacity range is read in.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Capacity;

/**
 * A date range, cut into weeks (#139).
 *
 * Weeks rather than days because that is how somebody plans, and rather than
 * months because a month hides the week where three things land together. They
 * start on Monday, so a week reads as a working week rather than as a fortnight
 * split down the middle.
 *
 * The first and last weeks are clipped to the range asked for. A view that
 * quietly widened the range would report commitments outside the period
 * somebody is looking at, and the total would not match the cells.
 */
final class Periods {

	/**
	 * The most weeks one call will produce, matching Availability's own guard
	 * against a mistyped year.
	 */
	public const MAX_WEEKS = 160;

	/**
	 * The weeks a range covers.
	 *
	 * @param string $from YYYY-MM-DD, inclusive.
	 * @param string $to   YYYY-MM-DD, inclusive.
	 * @return array<int, array{from: string, to: string}>
	 */
	public static function weeks( string $from, string $to ): array {
		if ( '' === $from || '' === $to || $to < $from ) {
			return array();
		}

		$weeks = array();
		$start = $from;

		while ( $start <= $to && count( $weeks ) < self::MAX_WEEKS ) {
			$end = self::sunday_of( $start );

			$weeks[] = array(
				'from' => $start,
				'to'   => $end > $to ? $to : $end,
			);

			$start = self::next_day( $end );
		}

		return $weeks;
	}

	/**
	 * The Sunday ending the week a date falls in.
	 *
	 * @param string $date YYYY-MM-DD.
	 * @return string
	 */
	private static function sunday_of( string $date ): string {
		$weekday = (int) gmdate( 'N', (int) strtotime( $date . ' 00:00:00 UTC' ) );

		return gmdate( 'Y-m-d', (int) strtotime( $date . ' 00:00:00 UTC' ) + ( ( 7 - $weekday ) * DAY_IN_SECONDS ) );
	}

	/**
	 * The day after a date.
	 *
	 * @param string $date YYYY-MM-DD.
	 * @return string
	 */
	private static function next_day( string $date ): string {
		return gmdate( 'Y-m-d', (int) strtotime( $date . ' 00:00:00 UTC' ) + DAY_IN_SECONDS );
	}
}
```

- [ ] **Step 4: Write Position**

Create `includes/Capacity/Position.php`:

```php
<?php
/**
 * Time against commitment, in one place.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Capacity;

/**
 * Where somebody stands (#139).
 *
 * The one place available hours and committed hours meet. Everything that has
 * an opinion about whether there is room — the capacity view, the gates in #141
 * and #142, the answer a client gets in #140 — asks this, so that a figure
 * somebody is looking at is the same figure a gate refused on.
 *
 * The band matters as much as the numbers. A cell showing 39 of 40 hours is
 * technically fine and practically full, and a person nobody has set up is not
 * a person with no room — they are a person to go and set up, which is a
 * different thing to do about it.
 */
final class Position {

	/**
	 * Room to take something on.
	 */
	public const CLEAR = 'clear';

	/**
	 * Nearly full. Not a refusal, a warning.
	 */
	public const TIGHT = 'tight';

	/**
	 * More committed than there is time for.
	 */
	public const OVER = 'over';

	/**
	 * Nobody has said what this person's hours are. Not a capacity state.
	 */
	public const UNRECORDED = 'unrecorded';

	/**
	 * The share of somebody's time at which "fine" becomes "careful".
	 */
	public const TIGHT_AT = 0.8;

	/**
	 * Available against committed, and what to call it.
	 *
	 * No database in it, so the thresholds can be stated in a test.
	 *
	 * @param float $available Hours the person has.
	 * @param float $committed Hours already spoken for.
	 * @param bool  $recorded  Whether anybody has set their hours up at all.
	 * @return array{available: float, committed: float, remaining: float, band: string}
	 */
	public static function calculate( float $available, float $committed, bool $recorded ): array {
		$available = round( $available, 2 );
		$committed = round( $committed, 2 );

		return array(
			'available' => $available,
			'committed' => $committed,
			'remaining' => round( $available - $committed, 2 ),
			'band'      => self::band( $available, $committed, $recorded ),
		);
	}

	/**
	 * A whole studio's position over a window, person by person.
	 *
	 * @param array<int, string> $user_ids The people.
	 * @param string             $from     YYYY-MM-DD, inclusive.
	 * @param string             $to       YYYY-MM-DD, inclusive.
	 * @return array<string, array<string, mixed>>
	 */
	public static function for_people( array $user_ids, string $from, string $to ): array {
		$committed = Commitments::for_people( $user_ids, $from, $to );
		$out       = array();

		foreach ( $user_ids as $user_id ) {
			$out[ $user_id ] = self::calculate(
				Availability::hours( $user_id, $from, $to ),
				(float) ( $committed[ $user_id ]['hours'] ?? 0.0 ),
				Availability::is_recorded( $user_id, $from )
			);
		}

		return $out;
	}

	/**
	 * What to call a position.
	 *
	 * @param float $available Hours the person has.
	 * @param float $committed Hours already spoken for.
	 * @param bool  $recorded  Whether their hours are set up.
	 * @return string
	 */
	private static function band( float $available, float $committed, bool $recorded ): string {
		if ( ! $recorded ) {
			return self::UNRECORDED;
		}

		if ( $committed > $available ) {
			return self::OVER;
		}

		if ( $available <= 0 ) {
			// No time and nothing on it: a week of leave, which is a plan
			// rather than a problem.
			return self::CLEAR;
		}

		return $committed / $available >= self::TIGHT_AT ? self::TIGHT : self::CLEAR;
	}
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter CapacityPositionTest`
Expected: PASS, 7 tests.

- [ ] **Step 6: Commit**

```bash
git add includes/Capacity/Position.php includes/Capacity/Periods.php tests/php/CapacityPositionTest.php
git commit -m "Say where somebody stands, week by week (#139)"
```

---

### Task 5: The capacity routes

**Files:**
- Create: `includes/Rest/CapacityController.php`
- Modify: `includes/Rest/Server.php` (add to `register_routes()`)
- Modify: `includes/Rest/Access.php` (add `allows_anywhere()`)
- Test: `tests/e2e/capacity-rest.spec.js`

**Interfaces:**
- Consumes: `Position::for_people()`, `Periods::weeks()`, `Commitments::for_people()`, `Availability::by_day()`, `Tenancy\Users::all()`, `Tenancy\Memberships::for_user()`, `Tenancy\Capabilities`, `Rest\Errors::rest()`.
- Produces:
  - `Access::allows_anywhere( string $capability ): bool`
  - `GET /blueworx-forge/v1/capacity?from=&to=` → `{ from, to, weeks: [{from,to}], people: [{ user_id, display_name, weeks: [{from,to,available,committed,remaining,band}], total: {available,committed,remaining,band} }] }`
  - `GET /blueworx-forge/v1/capacity/person/{user_id}?from=&to=` → `{ user_id, from, to, days: [{date,hours,base_hours,reason}], committed_by_day: {date: hours}, allocations: [...] }`

- [ ] **Step 1: Write the failing test**

Create `tests/e2e/capacity-rest.spec.js`:

```javascript
import { test, expect } from '@playwright/test';
import { signedIn, makeSite, makeItem, signInPage } from './helpers/forge.js';

// #138's acceptance in one spec: a person on two clients shows one combined
// commitment. Nothing here is deleted and the instance is reused between runs,
// so every name carries a run id or it passes once and fails for ever after.
const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw';
const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const PERSON = `Capacity ${RUN_ID}`;
const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

// A fixed window in the future, so the figures do not depend on the day the
// suite runs. Monday to Friday, and hours set on all seven days, which makes
// the arithmetic the same whichever way the week falls.
const FROM = '2026-09-07';
const TO = '2026-09-11';

// Patterns are written from the availability screen and nowhere else — #136
// deliberately gave them no REST route, since setting somebody up is
// configuration (ARCH-7). So this walks the screen.
async function setHoursThroughTheScreen(page, name, hours) {
  await page.goto('/wp-admin/admin.php?page=blueworx-forge-availability');
  await page.selectOption('#bwx-person', { label: name });
  await page.click('form[data-bwx-person-picker] input[type="submit"]');

  await page.fill('#bwx-effective-from', '2020-01-01');

  for (const day of DAYS) {
    await page.fill(`#bwx-hours_${day}`, String(hours));
  }

  await page.click('form[data-bwx-set-hours] input[type="submit"]');
  await expect(page.locator('[data-bwx-result="hours-set"]')).toBeVisible();
}

test.describe.configure({ mode: 'serial' });

test('a person on two clients shows one combined commitment', async ({ browser, baseURL }) => {
  const { context, api } = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);

  const created = await api.post('/users', {
    email: `capacity.${RUN_ID}@example.test`,
    display_name: PERSON,
  });
  expect(created.status(), await created.text()).toBe(200);

  const person = (await created.json()).user;

  const page = await context.newPage();
  await setHoursThroughTheScreen(page, PERSON, 8);
  await page.close();

  for (const label of ['one', 'two']) {
    const { site } = await makeSite(api, `Capacity ${label}`, RUN_ID);

    const item = await makeItem(api, site.id, {
      title: `Capacity work ${label} ${RUN_ID}`,
      planned_start: FROM,
      planned_due: TO,
      primary_user_id: person.id,
      hours_primary: 6,
    });

    expect(item.status(), await item.text()).toBe(200);

    // Only work at Up Next or beyond is a commitment, so the item is moved
    // there rather than left as an idea.
    const id = (await item.json()).item.id;

    await api.patch(`/work-items/${id}`, { stage: 'up-next' });
  }

  const capacity = await api.get(`/capacity?from=${FROM}&to=${TO}`);
  const row = capacity.people.find((entry) => entry.user_id === person.id);

  expect(row, 'the person appears in the capacity read').toBeTruthy();
  expect(row.total.committed, 'both clients, one figure').toBe(12);
  expect(row.total.available).toBe(40);
  expect(row.total.remaining).toBe(28);

  const drill = await api.get(`/capacity/person/${person.id}?from=${FROM}&to=${TO}`);

  expect(drill.allocations, 'both pieces of work are behind the number').toHaveLength(2);
  expect(new Set(drill.allocations.map((entry) => entry.client_id)).size).toBe(2);

  const summed = drill.allocations.reduce((total, entry) => total + entry.hours, 0);

  expect(summed, 'the drill-down reconciles to the total').toBe(row.total.committed);

  await context.close();
});

test('a signed-out caller gets nothing', async ({ request }) => {
  const response = await request.get(`/wp-json/blueworx-forge/v1/capacity?from=${FROM}&to=${TO}`);

  expect([401, 403]).toContain(response.status());
});
```

Two things to check against the repo before running it, because both are easy
to get subtly wrong and neither shows up as an obvious failure:

1. `api.post` and `api.patch` return a Playwright `Response`; only `api.get`
   returns parsed JSON. The bodies are wrapped — `{ user }`, `{ item }`,
   `{ client }`, `{ site }` — which is why the spec unwraps them.
2. `PATCH /work-items/{id}` may refuse a bare `stage` change, since the stage is
   written only by the transition service. Read `tests/e2e/workflow-rest.spec.js`
   and use whatever route that suite uses to move an item to Up Next, satisfying
   the gate the same way it does. Do not add a back door to set a stage.

- [ ] **Step 2: Run the test to verify it fails**

Run: `npm run wp:up` then `npx playwright test tests/e2e/capacity-rest.spec.js --workers=1`
Expected: FAIL — the `/capacity` route returns 404.

- [ ] **Step 3: Add the cross-client capability check**

In `includes/Rest/Access.php`, add this method after `allows()`:

```php
	/**
	 * Whether the current user holds a capability anywhere at all.
	 *
	 * For the one read whose subject is not a client's records (#138). Capacity
	 * spans every client by definition, so asking "do they hold this for client
	 * X" has no X to name — and asking it of no client at all would refuse
	 * every member of staff who is not a WordPress administrator.
	 *
	 * Holding it under any active membership is enough. A person who may see
	 * staff capacity for one client is not being shown anything new by seeing
	 * it for all of them: the answer is about the studio's own people, not
	 * about anybody's work.
	 *
	 * @param string $capability Capability.
	 * @return bool
	 */
	public static function allows_anywhere( string $capability ): bool {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$wp_user = get_current_user_id();
		$user    = $wp_user > 0 ? Users::by_wp_user( $wp_user ) : null;

		if ( null === $user ) {
			return false;
		}

		foreach ( Memberships::for_user( (string) $user['id'] ) as $membership ) {
			if ( Capabilities::allows( $capability, self::build( (string) $membership['role'], (string) $user['id'], null, true, $membership ) ) ) {
				return true;
			}
		}

		return false;
	}
```

Add `use Blueworx\Forge\Tenancy\Memberships;` to the file's imports if it is not already there. Check `self::build()`'s signature at the top of the class and match the argument order exactly.

- [ ] **Step 4: Write the controller**

Create `includes/Rest/CapacityController.php`:

```php
<?php
/**
 * The capacity routes.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Capacity\Availability;
use Blueworx\Forge\Capacity\Commitments;
use Blueworx\Forge\Capacity\Periods;
use Blueworx\Forge\Capacity\Position;
use Blueworx\Forge\Tenancy\Capabilities;
use Blueworx\Forge\Tenancy\Users;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Who has room (#139), counted across every client (#138).
 *
 * Both routes are deliberately outside the tenant boundary, which is the one
 * thing about them that needed a decision. Every other read in Forge is scoped
 * to a client because showing one client another client's records is the whole
 * thing ARCH-3 exists to prevent. This read is about the studio's own people
 * and their own hours, and scoping it per client would produce three clients
 * each shown a person with plenty of room and a person with none.
 *
 * Nothing here names a client, so there is no client in the answer to leak. The
 * drill-down does name work, which is why it asks for the staff capacity
 * capability rather than for a login.
 */
final class CapacityController {

	/**
	 * The longest window a caller may ask for, in days.
	 *
	 * A guard against a typo rather than a policy, matching Availability's own.
	 */
	private const MAX_DAYS = 370;

	/**
	 * Registers this controller's routes.
	 *
	 * @param string $route_namespace REST namespace.
	 */
	public static function register_routes( string $route_namespace ): void {
		$scope = array(
			'kind'   => Boundary::SCOPE_OPEN,
			'reason' => 'Capacity spans every client by definition (#138): a person committed on one client must not look free on another. The answer names the studio\'s own people and no client, and the capability is checked in the callback.',
		);

		Server::register_route(
			$route_namespace,
			'/capacity',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'index' ),
				'permission_callback' => array( Permissions::class, 'signed_in' ),
				'scope'               => $scope,
				'args'                => array(
					'from' => array(
						'type'     => 'string',
						'required' => true,
					),
					'to'   => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/capacity/person/(?P<user_id>[A-Za-z0-9_\-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'person' ),
				'permission_callback' => array( Permissions::class, 'signed_in' ),
				'scope'               => $scope,
				'args'                => array(
					'from' => array(
						'type'     => 'string',
						'required' => true,
					),
					'to'   => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Everybody, week by week.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function index( WP_REST_Request $request ) {
		$refusal = self::refuse_unless_permitted();

		if ( null !== $refusal ) {
			return $refusal;
		}

		$window = self::window( $request );

		if ( ! is_array( $window ) ) {
			return $window;
		}

		list( $from, $to ) = $window;

		$people = Users::all( 'active' );
		$ids    = array_map(
			static fn( array $person ): string => (string) $person['id'],
			$people
		);

		$weeks = Periods::weeks( $from, $to );
		$rows  = array();

		foreach ( $people as $person ) {
			$id = (string) $person['id'];

			$rows[] = array(
				'user_id'      => $id,
				'display_name' => (string) $person['display_name'],
				'weeks'        => array(),
				'total'        => array(),
			);
		}

		/*
		 * A pass per week rather than a pass per person, because the commitment
		 * read is one query for everybody and doing it the other way round runs
		 * it once per person per week.
		 */
		foreach ( $weeks as $week ) {
			$positions = Position::for_people( $ids, $week['from'], $week['to'] );

			foreach ( $rows as $row_index => $row ) {
				$rows[ $row_index ]['weeks'][] = array_merge(
					array(
						'from' => $week['from'],
						'to'   => $week['to'],
					),
					$positions[ $row['user_id'] ]
				);
			}
		}

		$totals = Position::for_people( $ids, $from, $to );

		foreach ( $rows as $row_index => $row ) {
			$rows[ $row_index ]['total'] = $totals[ $row['user_id'] ];
		}

		return new WP_REST_Response(
			array(
				'from'   => $from,
				'to'     => $to,
				'weeks'  => $weeks,
				'people' => $rows,
			),
			200
		);
	}

	/**
	 * One person, and the work behind every figure.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function person( WP_REST_Request $request ) {
		$refusal = self::refuse_unless_permitted();

		if ( null !== $refusal ) {
			return $refusal;
		}

		$window = self::window( $request );

		if ( ! is_array( $window ) ) {
			return $window;
		}

		list( $from, $to ) = $window;

		$user_id = (string) $request->get_param( 'user_id' );
		$person  = Users::get( $user_id );

		if ( null === $person ) {
			return Errors::rest( 'not_found', __( 'There is no such person.', 'blueworx-forge' ), 404 );
		}

		$gathered = Commitments::for_people( array( $user_id ), $from, $to );

		return new WP_REST_Response(
			array(
				'user_id'          => $user_id,
				'display_name'     => (string) $person['display_name'],
				'from'             => $from,
				'to'               => $to,
				'days'             => Availability::by_day( $user_id, $from, $to ),
				'committed_by_day' => $gathered[ $user_id ]['by_day'] ?? array(),
				'allocations'      => $gathered[ $user_id ]['allocations'] ?? array(),
				'position'         => Position::for_people( array( $user_id ), $from, $to )[ $user_id ],
			),
			200
		);
	}

	/**
	 * Refuses anybody who may not see staff against capacity.
	 *
	 * @return \WP_Error|null Null when it is allowed.
	 */
	private static function refuse_unless_permitted() {
		if ( Access::allows_anywhere( Capabilities::VIEW_STAFF_CAPACITY ) ) {
			return null;
		}

		return Errors::rest(
			'not_permitted',
			__( 'You cannot see staff capacity.', 'blueworx-forge' ),
			403,
			array( 'capability' => Capabilities::VIEW_STAFF_CAPACITY )
		);
	}

	/**
	 * The window a request asks for, refused when it makes no sense.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<int, string>|\WP_Error
	 */
	private static function window( WP_REST_Request $request ) {
		$from = (string) $request->get_param( 'from' );
		$to   = (string) $request->get_param( 'to' );

		$valid = static fn( string $date ): bool => 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date );

		if ( ! $valid( $from ) || ! $valid( $to ) || $to < $from ) {
			return Errors::rest( 'bad_window', __( 'Give a start date and an end date, in that order.', 'blueworx-forge' ), 400 );
		}

		$days = ( (int) strtotime( $to . ' 00:00:00 UTC' ) - (int) strtotime( $from . ' 00:00:00 UTC' ) ) / DAY_IN_SECONDS;

		if ( $days > self::MAX_DAYS ) {
			return Errors::rest( 'window_too_long', __( 'That is longer than a year. Ask for a shorter period.', 'blueworx-forge' ), 400 );
		}

		return array( $from, $to );
	}
}
```

- [ ] **Step 5: Register the controller**

In `includes/Rest/Server.php`, add `CapacityController::register_routes( self::NAMESPACE );` to `register_routes()`, after `SubmissionsController::register_routes( self::NAMESPACE );`.

- [ ] **Step 6: Run the test to verify it passes**

Run: `npx playwright test tests/e2e/capacity-rest.spec.js --workers=1`
Expected: PASS, 2 tests.

- [ ] **Step 7: Commit**

```bash
git add includes/Rest/CapacityController.php includes/Rest/Server.php includes/Rest/Access.php tests/e2e/capacity-rest.spec.js
git commit -m "Answer who has room, across every client (#138, #139)"
```

---

### Task 6: The capacity screen

**Files:**
- Create: `src/components/CapacityScreen.tsx`
- Modify: `src/App.tsx` (a third screen button and mount)
- Modify: `src/types.ts` (`ScreenName`, and the capacity response types)
- Modify: `src/styles.css` (the grid and the band colours)
- Test: `tests/e2e/capacity-screen.spec.js`

**Interfaces:**
- Consumes: `api()` from `src/api.ts`; the `/capacity` and `/capacity/person/{id}` responses from Task 5.
- Produces: `CapacityScreen` component; `ScreenName` gains `'capacity'`; types `CapacityResponse`, `CapacityPerson`, `CapacityCell`, `CapacityDrilldown`, `CapacityAllocation`.

- [ ] **Step 1: Write the failing test**

Create `tests/e2e/capacity-screen.spec.js`:

```javascript
import { test, expect } from '@playwright/test';
import { signInPage } from './helpers/forge.js';

/**
 * The studio's picture of who has room (#139).
 *
 * The acceptance is that the view reconciles to what is behind it, so the test
 * reads a cell and then opens it and adds up what it says.
 */
test.describe( 'capacity screen', () => {
  test( 'shows people against weeks and explains every figure', async ( { page } ) => {
    await signInPage( page, 'admin', 'wptest-admin-pw' );
    await page.goto( '/blueworx-forge/' );

    await page.getByTestId( 'bwx-screen-capacity' ).click();

    const grid = page.getByTestId( 'bwx-capacity-grid' );

    await expect( grid ).toBeVisible();
    await expect( grid.getByRole( 'columnheader' ).first() ).toBeVisible();

    const cell = grid.getByTestId( /^bwx-capacity-cell-/ ).first();

    await expect( cell ).toBeVisible();

    await cell.click();

    await expect( page.getByTestId( 'bwx-capacity-drilldown' ) ).toBeVisible();
  } );

  test( 'says so when nobody has set a person up', async ( { page } ) => {
    await signInPage( page, 'admin', 'wptest-admin-pw' );
    await page.goto( '/blueworx-forge/' );

    await page.getByTestId( 'bwx-screen-capacity' ).click();

    const unrecorded = page.getByTestId( 'bwx-capacity-grid' ).locator( '[data-band="unrecorded"]' ).first();

    if ( await unrecorded.count() ) {
      await expect( unrecorded ).toContainText( /hours not set/i );
    }
  } );
} );
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npx playwright test tests/e2e/capacity-screen.spec.js --workers=1`
Expected: FAIL — no element with test id `bwx-screen-capacity`.

- [ ] **Step 3: Add the types**

In `src/types.ts`, change the `ScreenName` line to:

```typescript
export type ScreenName = 'work' | 'requests' | 'capacity';
```

and append:

```typescript
/** One person's position over one period. */
export interface CapacityCell {
  from: string;
  to: string;
  available: number;
  committed: number;
  remaining: number;
  band: 'clear' | 'tight' | 'over' | 'unrecorded';
}

/** One row of the capacity grid. */
export interface CapacityPerson {
  user_id: string;
  display_name: string;
  weeks: CapacityCell[];
  total: Omit< CapacityCell, 'from' | 'to' >;
}

/** The capacity read. */
export interface CapacityResponse {
  from: string;
  to: string;
  weeks: { from: string; to: string }[];
  people: CapacityPerson[];
}

/** One piece of work behind a committed figure. */
export interface CapacityAllocation {
  item_id: string;
  title: string;
  client_id: string;
  role: string;
  covering: string;
  hours: number;
  from: string;
  to: string;
}

/** What is behind one person's numbers. */
export interface CapacityDrilldown {
  user_id: string;
  display_name: string;
  from: string;
  to: string;
  days: { date: string; hours: number; base_hours: number; reason: string }[];
  allocations: CapacityAllocation[];
  position: Omit< CapacityCell, 'from' | 'to' >;
}
```

- [ ] **Step 4: Write the screen**

Create `src/components/CapacityScreen.tsx`:

```tsx
import { useEffect, useMemo, useState } from 'react';
import type { CapacityDrilldown, CapacityResponse } from '../types';
import { api, messageFor } from '../api';
import { Screen } from './States';

/**
 * The studio's picture of who has room (#139).
 *
 * People down the side, weeks across the top. It draws what the server says and
 * works nothing out for itself — the moment a screen recalculates a total, it
 * is showing a number no gate ever refused on, and the two disagree in front of
 * whoever is trying to plan.
 *
 * Every cell opens. A capacity figure nobody can take apart is a figure people
 * work around rather than with, so the drill-down names the work behind it.
 */
export function CapacityScreen() {
  const [ range, setRange ] = useState( () => defaultRange() );
  const [ data, setData ] = useState< CapacityResponse | undefined >();
  const [ open, setOpen ] = useState< CapacityDrilldown | undefined >();
  const [ error, setError ] = useState( '' );
  const [ loading, setLoading ] = useState( true );

  useEffect( () => {
    let live = true;

    setLoading( true );
    setError( '' );

    api< CapacityResponse >( `/capacity?from=${ range.from }&to=${ range.to }` )
      .then( ( next ) => {
        if ( live ) {
          setData( next );
        }
      } )
      .catch( ( failure ) => {
        if ( live ) {
          setError( messageFor( failure, 'The capacity picture could not be read.' ) );
        }
      } )
      .finally( () => {
        if ( live ) {
          setLoading( false );
        }
      } );

    return () => {
      live = false;
    };
  }, [ range.from, range.to ] );

  const weeks = useMemo( () => data?.weeks ?? [], [ data ] );

  async function openCell( userId: string, from: string, to: string ) {
    setOpen( undefined );

    try {
      setOpen( await api< CapacityDrilldown >( `/capacity/person/${ userId }?from=${ from }&to=${ to }` ) );
    } catch ( failure ) {
      setError( messageFor( failure, 'That could not be opened.' ) );
    }
  }

  if ( loading ) {
    return <Screen state="loading" title="Reading capacity" detail="Working out who has room." />;
  }

  if ( '' !== error ) {
    return <Screen state="error" title="Capacity could not be read" detail={ error } />;
  }

  if ( ! data || 0 === data.people.length ) {
    return (
      <Screen
        state="empty"
        title="Nobody to show"
        detail="Add people, and set their working hours, before capacity means anything."
      />
    );
  }

  return (
    <div className="bwx-capacity">
      <div className="bwx-capacity-controls">
        <label>
          From
          <input
            type="date"
            value={ range.from }
            onChange={ ( event ) => setRange( { ...range, from: event.target.value } ) }
          />
        </label>
        <label>
          To
          <input
            type="date"
            value={ range.to }
            onChange={ ( event ) => setRange( { ...range, to: event.target.value } ) }
          />
        </label>
      </div>

      <table className="bwx-capacity-grid" data-testid="bwx-capacity-grid">
        <thead>
          <tr>
            <th scope="col">Person</th>
            { weeks.map( ( week ) => (
              <th key={ week.from } scope="col">
                { weekLabel( week.from ) }
              </th>
            ) ) }
            <th scope="col">Total</th>
          </tr>
        </thead>
        <tbody>
          { data.people.map( ( person ) => (
            <tr key={ person.user_id }>
              <th scope="row">{ person.display_name }</th>
              { person.weeks.map( ( cell ) => (
                <td key={ cell.from } data-band={ cell.band }>
                  <button
                    type="button"
                    className="bwx-capacity-cell"
                    data-testid={ `bwx-capacity-cell-${ person.user_id }-${ cell.from }` }
                    onClick={ () => openCell( person.user_id, cell.from, cell.to ) }
                  >
                    { cellText( cell.band, cell.committed, cell.available ) }
                  </button>
                </td>
              ) ) }
              <td data-band={ person.total.band }>
                { cellText( person.total.band, person.total.committed, person.total.available ) }
              </td>
            </tr>
          ) ) }
        </tbody>
      </table>

      { open ? <Drilldown drilldown={ open } onClose={ () => setOpen( undefined ) } /> : null }
    </div>
  );
}

/** What is behind one person's week. */
function Drilldown( { drilldown, onClose }: { drilldown: CapacityDrilldown; onClose: () => void } ) {
  const away = drilldown.days.filter( ( day ) => '' !== day.reason );

  return (
    <aside className="bwx-capacity-drilldown" data-testid="bwx-capacity-drilldown">
      <div className="bwx-capacity-drilldown-head">
        <h2>
          { drilldown.display_name }, { drilldown.from } to { drilldown.to }
        </h2>
        <button type="button" className="bwx-button" data-variant="quiet" onClick={ onClose }>
          Close
        </button>
      </div>

      <p>
        { drilldown.position.committed } of { drilldown.position.available } hours committed,
        { ' ' }
        { drilldown.position.remaining } remaining.
      </p>

      { 0 === drilldown.allocations.length ? (
        <p>Nothing is committed in this period.</p>
      ) : (
        <ul className="bwx-capacity-work">
          { drilldown.allocations.map( ( allocation ) => (
            <li key={ `${ allocation.item_id }-${ allocation.role }` }>
              <strong>{ allocation.title }</strong>
              { ' — ' }
              { roleName( allocation.role ) }, { allocation.hours } hours
              { '' !== allocation.covering ? ' (covering)' : '' }
            </li>
          ) ) }
        </ul>
      ) }

      { 0 === away.length ? null : (
        <ul className="bwx-capacity-away">
          { away.map( ( day ) => (
            <li key={ day.date }>
              { day.date }: { reasonName( day.reason ) }
            </li>
          ) ) }
        </ul>
      ) }
    </aside>
  );
}

/** Eight weeks from the Monday of this week, which is the question people ask. */
function defaultRange(): { from: string; to: string } {
  const today = new Date();
  const monday = new Date( today );

  monday.setDate( today.getDate() - ( ( today.getDay() + 6 ) % 7 ) );

  const end = new Date( monday );

  end.setDate( monday.getDate() + 55 );

  return { from: iso( monday ), to: iso( end ) };
}

function iso( date: Date ): string {
  return date.toISOString().slice( 0, 10 );
}

function weekLabel( from: string ): string {
  return from.slice( 5 ).replace( '-', '/' );
}

/**
 * What a cell says.
 *
 * A person nobody has set up says so rather than showing zero. Zero hours and
 * nothing recorded look identical in a number and mean opposite things — one is
 * "they have no room", the other is "go and set them up".
 */
function cellText( band: string, committed: number, available: number ): string {
  if ( 'unrecorded' === band ) {
    return 'Hours not set';
  }

  return `${ committed } / ${ available }`;
}

function roleName( role: string ): string {
  if ( 'review' === role ) {
    return 'Reviewing';
  }

  return 'delivery' === role ? 'Delivering' : 'Doing the work';
}

function reasonName( reason: string ): string {
  if ( 'no-pattern' === reason ) {
    return 'hours not set';
  }

  return 'non-working-day' === reason ? 'not a working day' : reason;
}
```

Before writing this, read `src/components/States.tsx` and confirm the `Screen` component's props are `state`, `title` and `detail` as used in `src/App.tsx`; match whatever it actually takes.

- [ ] **Step 5: Add the screen to the shell**

In `src/App.tsx`, import `CapacityScreen`, add a third button beside the two existing ones following exactly the same markup — `data-testid="bwx-screen-capacity"`, `aria-pressed={ 'capacity' === screen }`, label `Capacity` — and change the mount line to:

```tsx
      { 'work' === screen ? <WorkScreen /> : null }
      { 'requests' === screen ? <QueueScreen /> : null }
      { 'capacity' === screen ? <CapacityScreen /> : null }
```

- [ ] **Step 6: Style the grid**

Append to `src/styles.css`, matching the file's existing custom-property names — read the top of the file first and use its own tokens rather than raw hex where they exist:

```css
/*
 * The capacity grid (#139). The band is a colour and also a word in the cell:
 * a picture that only speaks in colour says nothing to somebody who cannot
 * separate red from green, and this one is used to decide who takes the next
 * job.
 */
.bwx-capacity-grid {
  width: 100%;
  border-collapse: collapse;
}

.bwx-capacity-grid th,
.bwx-capacity-grid td {
  border: 1px solid var( --bwx-line, #d9d9de );
  padding: 0.35rem 0.5rem;
  text-align: left;
  font-variant-numeric: tabular-nums;
}

.bwx-capacity-grid td[data-band='tight'] {
  background: #fdf3d8;
}

.bwx-capacity-grid td[data-band='over'] {
  background: #fbe0e0;
}

.bwx-capacity-grid td[data-band='unrecorded'] {
  background: #f2f2f4;
  font-style: italic;
}

.bwx-capacity-cell {
  background: none;
  border: 0;
  padding: 0;
  font: inherit;
  color: inherit;
  cursor: pointer;
  text-decoration: underline;
}

.bwx-capacity-drilldown {
  margin-top: 1rem;
  padding: 1rem;
  border: 1px solid var( --bwx-line, #d9d9de );
}

.bwx-capacity-drilldown-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 1rem;
}
```

- [ ] **Step 7: Build and run the test**

Run: `npm run build` then `npx playwright test tests/e2e/capacity-screen.spec.js --workers=1`
Expected: build succeeds, both tests PASS.

- [ ] **Step 8: Show it to the user**

Run `npm run dev` and ask the user to confirm the screen in the browser before committing. Per the project's issue workflow, a change with a visible effect gets a visual confirmation.

- [ ] **Step 9: Commit**

```bash
git add src/components/CapacityScreen.tsx src/App.tsx src/types.ts src/styles.css assets tests/e2e/capacity-screen.spec.js
git commit -m "Show the studio who has room (#139)"
```

---

### Task 7: The client's availability answer

**Files:**
- Create: `includes/Capacity/ClientAnswer.php`
- Modify: `includes/Rest/ClientController.php` (a fifth route)
- Test: `tests/php/CapacityClientAnswerTest.php`

**Interfaces:**
- Consumes: `Position::CLEAR`/`TIGHT`/`OVER`/`UNRECORDED`, `Position::calculate()`, `Position::for_people()`, `Periods::weeks()`, `Users::all()`.
- Produces:
  - `ClientAnswer::ROOM` (`'room'`), `ClientAnswer::TIGHT` (`'tight'`), `ClientAnswer::NONE` (`'none'`).
  - `ClientAnswer::band( array $positions ): string` — pure, over a set of per-person positions.
  - `ClientAnswer::for_window( string $from, string $to ): array{availability: string, earliest: string}`
  - `GET /blueworx-forge/v1/client/availability?from=&to=` → `{ availability, earliest, from, to }`

- [ ] **Step 1: Write the failing test**

Create `tests/php/CapacityClientAnswerTest.php`:

```php
<?php
/**
 * What a client is told about capacity.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Capacity\ClientAnswer;
use Blueworx\Forge\Capacity\Position;
use PHPUnit\Framework\TestCase;

/**
 * The privacy-safe availability result (#140).
 *
 * Two things have to be true at once: it has to be useful enough for a client
 * to plan around, and it has to contain nothing traceable to anybody else. The
 * assertions below are as much about what is absent as about what is there.
 */
final class CapacityClientAnswerTest extends TestCase {

	/**
	 * One person's position.
	 *
	 * @param float $available Hours.
	 * @param float $committed Hours.
	 * @return array<string, mixed>
	 */
	private function position( float $available, float $committed ): array {
		return Position::calculate( $available, $committed, true );
	}

	public function test_a_quiet_studio_has_room(): void {
		$band = ClientAnswer::band(
			array(
				'usr_a' => $this->position( 40.0, 4.0 ),
				'usr_b' => $this->position( 40.0, 8.0 ),
			)
		);

		$this->assertSame( ClientAnswer::ROOM, $band );
	}

	public function test_a_nearly_full_studio_is_tight(): void {
		$band = ClientAnswer::band(
			array(
				'usr_a' => $this->position( 40.0, 34.0 ),
				'usr_b' => $this->position( 40.0, 34.0 ),
			)
		);

		$this->assertSame( ClientAnswer::TIGHT, $band );
	}

	public function test_a_full_studio_has_none(): void {
		$band = ClientAnswer::band(
			array(
				'usr_a' => $this->position( 40.0, 44.0 ),
				'usr_b' => $this->position( 40.0, 40.0 ),
			)
		);

		$this->assertSame( ClientAnswer::NONE, $band );
	}

	public function test_a_studio_nobody_has_set_up_promises_nothing(): void {
		$band = ClientAnswer::band(
			array(
				'usr_a' => Position::calculate( 0.0, 0.0, false ),
			)
		);

		$this->assertSame( ClientAnswer::NONE, $band, 'no hours recorded is not a promise of room' );
	}

	public function test_nobody_at_all_promises_nothing(): void {
		$this->assertSame( ClientAnswer::NONE, ClientAnswer::band( array() ) );
	}

	public function test_the_answer_carries_nothing_but_a_band_and_a_date(): void {
		$answer = ClientAnswer::compose( ClientAnswer::ROOM, '2026-09-14', '2026-09-07', '2026-10-04' );

		$this->assertSame(
			array( 'availability', 'earliest', 'from', 'to' ),
			array_keys( $answer ),
			'nothing else may appear in a client answer, ever'
		);
		$this->assertSame( ClientAnswer::ROOM, $answer['availability'] );
		$this->assertSame( '2026-09-14', $answer['earliest'] );
	}

	public function test_the_earliest_date_is_the_first_week_with_room(): void {
		$earliest = ClientAnswer::earliest(
			array(
				array(
					'from' => '2026-09-07',
					'band' => ClientAnswer::NONE,
				),
				array(
					'from' => '2026-09-14',
					'band' => ClientAnswer::TIGHT,
				),
				array(
					'from' => '2026-09-21',
					'band' => ClientAnswer::ROOM,
				),
			)
		);

		$this->assertSame( '2026-09-21', $earliest );
	}

	public function test_no_room_in_the_window_gives_no_date(): void {
		$earliest = ClientAnswer::earliest(
			array(
				array(
					'from' => '2026-09-07',
					'band' => ClientAnswer::NONE,
				),
			)
		);

		$this->assertSame( '', $earliest, 'a date invented outside the window would be a promise' );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter CapacityClientAnswerTest`
Expected: FAIL — `Class "Blueworx\Forge\Capacity\ClientAnswer" not found`.

- [ ] **Step 3: Write the implementation**

Create `includes/Capacity/ClientAnswer.php`:

```php
<?php
/**
 * What a client is told about whether there is room.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Capacity;

use Blueworx\Forge\Tenancy\Users;

/**
 * The privacy-safe availability result (#140).
 *
 * A client asking "have you got room in September" is asking a fair question
 * with a dangerous answer. Remaining hours move week to week for reasons that
 * are entirely about other clients, so a number tells them what everybody else
 * is doing by inference — slowly, but permanently, and there is no taking it
 * back once they have watched it for a month.
 *
 * So the answer is a band and a date. Enough to plan around, and nothing to
 * reverse-engineer.
 *
 * **The answer does not depend on which client is asking.** That is deliberate
 * and it is the strongest privacy property here: two clients asking the same
 * question on the same day get the same sentence, so nothing in the answer can
 * be about either of them. It is also why nothing in this file takes a client
 * id.
 *
 * Written as an explicit construction rather than as a filtered position, the
 * same direction Work\ClientView is written in. A field added to Position
 * cannot appear here by accident; somebody has to name it, in a diff.
 */
final class ClientAnswer {

	/**
	 * There is room to take something on.
	 */
	public const ROOM = 'room';

	/**
	 * There is some, but not much.
	 */
	public const TIGHT = 'tight';

	/**
	 * There is none in the period asked about.
	 */
	public const NONE = 'none';

	/**
	 * The studio's position over a window, as one word.
	 *
	 * The aggregate, not anybody's. One person being free does not mean there
	 * is room — the work needs whoever can do it — and one person being full
	 * does not mean there is none.
	 *
	 * @param array<string, array<string, mixed>> $positions Position::calculate results.
	 * @return string
	 */
	public static function band( array $positions ): string {
		$available = 0.0;
		$committed = 0.0;
		$recorded  = false;

		foreach ( $positions as $position ) {
			if ( Position::UNRECORDED === (string) ( $position['band'] ?? '' ) ) {
				// Somebody nobody has set up contributes nothing either way.
				// Counting their zero hours as zero capacity would make the
				// studio look full; counting them as free would promise time
				// nobody has said exists.
				continue;
			}

			$recorded   = true;
			$available += (float) ( $position['available'] ?? 0 );
			$committed += (float) ( $position['committed'] ?? 0 );
		}

		if ( ! $recorded ) {
			// Nothing is known about anybody's hours, so there is nothing to
			// promise. "None" is the honest answer to a question the studio
			// cannot yet answer, and it fails towards a conversation.
			return self::NONE;
		}

		$aggregate = Position::calculate( $available, $committed, true );

		if ( Position::OVER === $aggregate['band'] ) {
			return self::NONE;
		}

		return Position::TIGHT === $aggregate['band'] ? self::TIGHT : self::ROOM;
	}

	/**
	 * The first week in a window with room in it.
	 *
	 * @param array<int, array<string, mixed>> $weeks Each with `from` and `band`.
	 * @return string YYYY-MM-DD, or empty when there is none in the window.
	 */
	public static function earliest( array $weeks ): string {
		foreach ( $weeks as $week ) {
			if ( self::ROOM === (string) ( $week['band'] ?? '' ) ) {
				return (string) ( $week['from'] ?? '' );
			}
		}

		// Nothing invented beyond the window. A date the studio has not looked
		// at is a promise it has not made.
		return '';
	}

	/**
	 * The answer, and only the answer.
	 *
	 * Four keys, listed here and nowhere else, so that what a client site
	 * receives is a decision in this file rather than a consequence of what
	 * some other file happens to return.
	 *
	 * @param string $availability One of the three bands.
	 * @param string $earliest     YYYY-MM-DD, or empty.
	 * @param string $from         Window start, as asked for.
	 * @param string $to           Window end, as asked for.
	 * @return array<string, string>
	 */
	public static function compose( string $availability, string $earliest, string $from, string $to ): array {
		return array(
			'availability' => $availability,
			'earliest'     => $earliest,
			'from'         => $from,
			'to'           => $to,
		);
	}

	/**
	 * The studio's answer for a window.
	 *
	 * @param string $from YYYY-MM-DD, inclusive.
	 * @param string $to   YYYY-MM-DD, inclusive.
	 * @return array<string, string>
	 */
	public static function for_window( string $from, string $to ): array {
		$ids = array_map(
			static fn( array $person ): string => (string) $person['id'],
			Users::all( 'active' )
		);

		$weeks = array();

		foreach ( Periods::weeks( $from, $to ) as $week ) {
			$weeks[] = array(
				'from' => $week['from'],
				'band' => self::band( Position::for_people( $ids, $week['from'], $week['to'] ) ),
			);
		}

		return self::compose(
			self::band( Position::for_people( $ids, $from, $to ) ),
			self::earliest( $weeks ),
			$from,
			$to
		);
	}
}
```

- [ ] **Step 4: Add the route**

In `includes/Rest/ClientController.php`, add a `use Blueworx\Forge\Capacity\ClientAnswer;` import, register a fifth route in `register_routes()` following the exact shape of the `/client/board` registration:

```php
		Server::register_route(
			$route_namespace,
			'/client/availability',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'availability' ),
				'permission_callback' => array( Permissions::class, 'client_site' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_OPEN,
					'reason' => 'Authenticated by the client site\'s own key, not by a person: the signature names which site is calling, so the boundary is the signature (ARCH-6).',
				),
			)
		);
```

and add the callback, placed next to `board()`:

```php
	/**
	 * Whether there is room, in a form that says nothing about anybody else.
	 *
	 * The signature says which site is calling and the answer ignores it, which
	 * is the point (#140): two clients asking on the same day get the same
	 * sentence, so there is nothing in it about either of them. The window is
	 * clamped rather than refused — a client site asking for something odd gets
	 * a sensible answer instead of an error it cannot act on.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function availability( WP_REST_Request $request ): WP_REST_Response {
		$from = (string) $request->get_param( 'from' );
		$to   = (string) $request->get_param( 'to' );

		$valid = static fn( string $date ): bool => 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date );

		if ( ! $valid( $from ) ) {
			$from = gmdate( 'Y-m-d' );
		}

		if ( ! $valid( $to ) || $to < $from ) {
			$to = gmdate( 'Y-m-d', (int) strtotime( $from . ' 00:00:00 UTC' ) + ( 55 * DAY_IN_SECONDS ) );
		}

		return new WP_REST_Response( ClientAnswer::for_window( $from, $to ), 200 );
	}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter CapacityClientAnswerTest`
Expected: PASS, 8 tests.

- [ ] **Step 6: Commit**

```bash
git add includes/Capacity/ClientAnswer.php includes/Rest/ClientController.php tests/php/CapacityClientAnswerTest.php
git commit -m "Tell a client whether there is room, and nothing else (#140)"
```

---

### Task 8: The client site shows it, and proves it leaks nothing

**Files:**
- Modify: `client/includes/Admin/AskScreen.php` (show the answer above the form)
- Test: `tests/pair/client-availability.spec.js`

**Interfaces:**
- Consumes: `ClientAnswer`'s response shape from Task 7; `Blueworx\Forge\Forge_Client\ReadThrough::view()` — confirm the exact namespace by reading the top of `client/includes/ReadThrough.php`.
- Produces: nothing other tasks depend on.

- [ ] **Step 1: Write the failing test**

Create `tests/pair/client-availability.spec.js`:

```javascript
import { test, expect } from '@playwright/test';
import { asClientSite, connectedPair, makeItem, studioSite, requireEnvironment } from './helpers/pair.js';

// #140's acceptance is negative, and that is the point: the response has to
// carry an availability result and nothing traceable to another client. So the
// spec puts a distinctive marker on a *second* client's work and then asserts
// that none of it comes back.
const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const MARKER = `Confidential Marker ${RUN_ID}`;

requireEnvironment();

test('a client is told whether there is room, and nothing else', async ({ browser, request }) => {
  const pair = await connectedPair(browser, 'Availability', RUN_ID);

  // Somebody else's work, with a title nothing may repeat back.
  const other = await studioSite(pair.studio, `Other ${MARKER}`, RUN_ID);

  await makeItem(pair.studio, other.site.id, {
    title: MARKER,
    planned_start: '2026-09-07',
    planned_due: '2026-09-11',
    hours_primary: 6,
  });

  const signed = asClientSite(request, pair.issued);
  const response = await signed.get('/client/availability?from=2026-09-07&to=2026-10-04');

  expect(response.status(), await response.text()).toBe(200);

  const answer = await response.json();

  expect(['room', 'tight', 'none']).toContain(answer.availability);
  expect(Object.keys(answer).sort()).toEqual(['availability', 'earliest', 'from', 'to']);

  const body = JSON.stringify(answer);

  expect(body, 'no other client name').not.toContain(MARKER);
  expect(body, 'no work title').not.toContain('Confidential');
  expect(body, 'no ids of any kind').not.toMatch(/(cli|cst|itm|usr)_/);
  expect(body, 'no hours figure').not.toMatch(/"(hours|available|committed|remaining)"/);

  await pair.close();
});

test('the client site shows the answer where somebody is about to ask', async ({ browser }) => {
  const pair = await connectedPair(browser, 'AvailabilityScreen', RUN_ID);

  const page = await pair.clientSite.context.newPage();

  await page.goto('/wp-admin/admin.php?page=blueworx-forge-client-ask');

  await expect(page.locator('[data-testid="bwx-client-availability"]')).toBeVisible();

  await page.close();
  await pair.close();
});
```

Confirm the client Ask screen's admin page slug before running the second test —
read `client/includes/Admin/AskScreen.php`'s `SLUG` constant and use it, rather
than the guess above.

- [ ] **Step 2: Run the test to verify it fails**

Run: `npm run wp:pair:up` then `npm run test:pair -- tests/pair/client-availability.spec.js`
Expected: FAIL — the route is not reachable from the client site, or the fixtures do not exist yet.

- [ ] **Step 3: Show the answer on the client's Ask screen**

In `client/includes/Admin/AskScreen.php`, inside `render()`, after `self::result_notice();` and before the `Connection::is_configured()` check, add `self::availability();`. Then add this private method after `result_notice()`:

```php
	/**
	 * Whether the studio has room, said plainly (#140).
	 *
	 * Above the form rather than after it, because it changes what somebody
	 * writes. "There is room from mid-September" is the difference between
	 * asking for something now and asking for it with a date on it.
	 *
	 * Read through the same cache every other client screen reads through, so a
	 * page load is not a call to the studio.
	 */
	private static function availability(): void {
		$answer = ReadThrough::view( '/client/availability' );

		$band = isset( $answer['availability'] ) ? (string) $answer['availability'] : '';

		if ( '' === $band ) {
			return;
		}

		$earliest = isset( $answer['earliest'] ) ? (string) $answer['earliest'] : '';

		if ( 'room' === $band ) {
			$sentence = __( 'There is room for new work at the moment.', 'blueworx-forge' );
		} elseif ( 'tight' === $band ) {
			$sentence = __( 'The next few weeks are tight, but ask and we will tell you what is possible.', 'blueworx-forge' );
		} else {
			$sentence = '' === $earliest
				? __( 'There is no room in the next couple of months. Ask anyway and we will talk about timing.', 'blueworx-forge' )
				: sprintf(
					/* translators: %s: a date. */
					__( 'There is no room right now. The earliest we expect room is %s.', 'blueworx-forge' ),
					$earliest
				);
		}

		echo '<div class="notice notice-info inline" data-testid="bwx-client-availability"><p>' . esc_html( $sentence ) . '</p></div>';
	}
```

Add the `ReadThrough` import to the file's `use` statements, matching the namespace at the top of `client/includes/ReadThrough.php`.

- [ ] **Step 4: Check the client artifact still builds**

Run: `npm run lint` — this runs `bin/check-artifacts.mjs`, which refuses a client allowlist that admits studio code. No new client file was added, so nothing in `bin/artifacts.json` should need changing. If the check fails, the fix is the allowlist, never a copied file.

Run: `npm run build:zip:client`
Expected: the client zip builds.

- [ ] **Step 5: Run the test to verify it passes**

Run: `npm run test:pair -- tests/pair/client-availability.spec.js`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add client/includes/Admin/AskScreen.php tests/pair/client-availability.spec.js
git commit -m "Show a client whether there is room, without showing them anybody else (#140)"
```

---

### Task 9: Version, changelog, checks and the pull request

**Files:**
- Modify: `package.json`, `blueworx-forge.php`, `client/blueworx-forge-client.php`, `CHANGELOG.md`

- [ ] **Step 1: Bump the version everywhere**

Set `2.34.0` in all four places: `package.json`'s `version`, the `Version:` header and `BWX_FORGE_VERSION` in `blueworx-forge.php`, and the `Version:` header and `BWX_FORGE_CLIENT_VERSION` in `client/blueworx-forge-client.php`. Leave `package-lock.json`'s `version` field alone — that is a deliberate project decision.

- [ ] **Step 2: Write the changelog entry**

Add above the `## [2.33.0]` entry in `CHANGELOG.md`, matching the file's existing heading and bullet style:

```markdown
## [2.34.0] - 2026-08-28

### Added

- Capacity. Forge now works out who is committed to what, counts a person once
  however many clients they work across, and shows the studio who has room week
  by week. Every figure opens to the work behind it.
- Reviewer and deliverer hours are filled in from the estimate when a piece of
  work is created, and stay editable.
- A client site can ask whether there is room. It gets an answer and a date, and
  nothing about anybody else.
```

- [ ] **Step 3: Run every check**

```bash
npm run lint
npm run build
composer lint
vendor/bin/phpunit
npx playwright test --workers=1
npm run test:pair
```

Expected: all pass. Do not loop on lint findings — collect them and report them to the user at the end.

- [ ] **Step 4: Commit and open a draft pull request**

```bash
git add package.json blueworx-forge.php client/blueworx-forge-client.php CHANGELOG.md assets
git commit -m "Release 2.34.0 — capacity engine and views"
git push -u origin capacity-engine-and-views
gh pr create --draft --title "Show who has room, and let a client ask (#137, #138, #139, #140)" --body "..."
```

The body says what it does and what still needs a human eye. Closes #137, #138, #139 and #140. Never merge and never tag.
