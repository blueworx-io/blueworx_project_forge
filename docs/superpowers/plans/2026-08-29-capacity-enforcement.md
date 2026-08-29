# Capacity Enforcement Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the capacity engine refuse — a gate at Up Next, a recheck at In Development, a reasoned override for over-booking, and a trail when the picture changes.

**Architecture:** One new class, `Capacity\Impact`, answers "would this move over-book anybody?" and is the only thing that does. `Work\Transition` computes that answer and hands it to `Work\Gates` through the existing `$context` channel, exactly as it already hands `children`. The capacity override travels with the move request rather than persisting as gate satisfaction, which is what makes the recheck at In Development ask again.

**Tech Stack:** PHP 7.4+, WordPress plugin, PHPUnit (no WordPress runtime — stubs in `tests/php/bootstrap.php`), Playwright, React + TypeScript front end.

**Spec:** `docs/superpowers/specs/2026-08-29-capacity-enforcement-design.md`

## Global Constraints

- **PHP coding standard:** WordPress Coding Standards, verified by `composer lint` (`vendor/bin/phpcs`). Yoda conditions, `array()` not `[]`, tabs, full docblocks on every class, method and property.
- **Namespace:** `Blueworx\Forge`. Constants prefixed `BWX_FORGE_`. Text domain `blueworx-forge`.
- **`declare( strict_types = 1 );` on every new PHP file**, after the file docblock.
- **Comments explain why, not what.** The surrounding code's comments argue for decisions and name the issue numbers. Match that register — see `includes/Capacity/Allocations.php` for the house style.
- **Version bump and changelog** on the PR, per the foundation rules. Minor bump: this is new behaviour. Both plugin headers must carry the same version (`blueworx-forge.php` and `client/blueworx-forge-client.php`), plus `package.json`.
- **Every figure comes from `Capacity\Impact`.** No task may answer "is this person over-booked?" any other way.
- **A person with no availability pattern recorded is never over-booked.** `Position::UNRECORDED` is not `Position::OVER`, and no refusal may treat it as one.
- **Tests run:** `vendor/bin/phpunit` (unit), `npm run wp:up && npm test` (Playwright), `npm run wp:pair:up && npm run test:pair` (two-site suite).

---

## File Structure

**Created:**

- `includes/Capacity/Impact.php` — the sole owner of "would this move over-book anybody?". Pure arithmetic plus one read; no knowledge of gates or stages beyond what `Allocations` already gives it.
- `includes/Work/CapacityOverride.php` — what an over-allocation override is worth, what it writes on the item, and its bounds. Mirrors `includes/Work/Override.php` deliberately, so the two sit side by side and read as siblings.
- `tests/php/CapacityImpactTest.php`
- `tests/php/CapacityOverrideTest.php`
- `tests/php/CapacityGateTest.php`
- `tests/e2e/capacity-gate.spec.js`

**Modified:**

- `includes/Capacity/Allocations.php` — add `proposed()`, the same reading of a row without the "is it at a committing stage yet" test.
- `includes/Work/Gates.php` — `G-UP-NEXT-8` stops being deferred; a new `G-IN-DEVELOPMENT-7` capacity check; `check()` learns the `capacity` case.
- `includes/Work/Transition.php` — compute the impact, put it in `$context`, carry the override reason through `move()` and `readiness()`.
- `includes/Work/Events.php` — one new action, `OVER_ALLOCATED`.
- `includes/Data/Schema.php` — two columns on `bwx_forge_work_items`, schema version 11 → 12.
- `includes/Work/Fields.php` — the two new columns readable, and not writable by an ordinary edit.
- `includes/Rest/WorkItemsController.php` — accept and pass the override reason.
- `includes/Capacity/Patterns.php`, `includes/Capacity/Unavailability.php` — record the trail on change (#144).
- `src/components/ItemPanel.tsx` — render the capacity refusal and the override control.

---

## Task 1: `Capacity\Impact` — the owner

**Files:**
- Create: `includes/Capacity/Impact.php`
- Modify: `includes/Capacity/Allocations.php` (add `proposed()`)
- Test: `tests/php/CapacityImpactTest.php`

**Interfaces:**
- Consumes: `Capacity\Allocations::from_item()`, `Allocations::spread()`, `Allocations::SEATS` (private — add `proposed()` inside the class rather than reaching in), `Capacity\Commitments::live()`, `Capacity\Commitments::gather()`, `Capacity\Availability::for_people()`, `Capacity\Periods::weeks()`, `Capacity\Position::over()`, `Position::OVER`.
- Produces:
  - `Allocations::proposed( array $item ): array` — same shape as `from_item()`, ignoring the committing-stage test.
  - `Impact::of( array $item ): array` — reads the database.
  - `Impact::assess( array $proposed, array $existing, array $days_by_user, string $from, string $to ): array` — pure, no database.
  - Both return `array{ over: array<int, array{user_id: string, week_from: string, week_to: string, available: float, committed: float, excess: float}>, window: array{from: string, to: string} }`.
  - `Impact::clear( array $impact ): bool` — true when nobody goes over.

### Why `proposed()` and not `from_item()`

`Allocations::COMMITTING` begins at `up-next`. An item **entering** Up Next is still at an earlier stage, so `from_item()` returns nothing for it and the check would permit every move. An item entering In Development is already at `up-next`, so it *is* in `Commitments::live()` and must not be counted twice. `Impact` therefore always removes the item's own allocations from the existing set and always adds `proposed()` back.

- [ ] **Step 1: Write the failing test for `Allocations::proposed()`**

Add to a new file `tests/php/CapacityImpactTest.php`:

```php
<?php
/**
 * Whether a move would over-book anybody.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Capacity\Allocations;
use Blueworx\Forge\Capacity\Impact;
use Blueworx\Forge\Capacity\Position;
use PHPUnit\Framework\TestCase;

/**
 * CAP-E1 and CAP-E2 (#141, #142). The engine answers questions; this is the
 * thing that turns an answer into a refusal, and it is the only one.
 */
final class CapacityImpactTest extends TestCase {

	/**
	 * An item as the database hands it over.
	 *
	 * @param array<string, mixed> $overrides Anything to change.
	 * @return array<string, mixed>
	 */
	private function item( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'              => 'wrk_one',
				'title'           => 'A job',
				'client_id'       => 'cli_one',
				'client_site_id'  => 'sit_one',
				'stage'           => 'documentation',
				'prior_stage'     => '',
				'archived'        => 0,
				'terminal_outcome' => '',
				'planned_start'   => '2026-09-07',
				'planned_due'     => '2026-09-11',
				'primary_user_id' => 'usr_a',
				'reviewer_id'     => 'usr_b',
				'deliverer_id'    => '',
				'reviewer_substitute_id'  => '',
				'deliverer_substitute_id' => '',
				'hours_primary'   => 10.0,
				'hours_review'    => 2.0,
				'hours_delivery'  => 0.0,
			),
			$overrides
		);
	}

	public function test_an_item_not_yet_committing_still_proposes_its_allocations(): void {
		// The whole reason proposed() exists: from_item() reads the stage and
		// returns nothing, which would let every move through.
		$this->assertSame( array(), Allocations::from_item( $this->item() ) );

		$proposed = Allocations::proposed( $this->item() );

		$this->assertCount( 2, $proposed );
		$this->assertSame( 'usr_a', $proposed[0]['user_id'] );
		$this->assertSame( 10.0, $proposed[0]['hours'] );
		$this->assertSame( 'usr_b', $proposed[1]['user_id'] );
	}

	public function test_archived_or_finished_work_proposes_nothing(): void {
		$this->assertSame( array(), Allocations::proposed( $this->item( array( 'archived' => 1 ) ) ) );
		$this->assertSame( array(), Allocations::proposed( $this->item( array( 'terminal_outcome' => 'cancelled' ) ) ) );
	}
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `vendor/bin/phpunit --filter CapacityImpactTest`
Expected: FAIL — `Call to undefined method ...Allocations::proposed()`.

- [ ] **Step 3: Add `proposed()` to `Allocations`**

In `includes/Capacity/Allocations.php`, refactor `from_item()` so the seat-reading half is shared, and add the new entry point. Replace the existing `from_item()` with these three methods:

```php
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

		return self::seats_of( $item );
	}

	/**
	 * The commitments an item *would* make, asked before it makes them.
	 *
	 * The stage test is deliberately not run. COMMITTING begins at Up Next, so
	 * an item on its way there is not committing anything yet — which is
	 * exactly the move #141 has to weigh. Asking from_item() would return
	 * nothing and permit everything.
	 *
	 * Archived and finished work is still refused: neither is a move anybody
	 * is making.
	 *
	 * @param array<string, mixed> $item A hydrated work item.
	 * @return array<int, array<string, mixed>>
	 */
	public static function proposed( array $item ): array {
		if ( ! empty( $item['archived'] ) || '' !== (string) ( $item['terminal_outcome'] ?? '' ) ) {
			return array();
		}

		return self::seats_of( $item );
	}

	/**
	 * Each filled seat on an item, as an allocation.
	 *
	 * @param array<string, mixed> $item A hydrated work item.
	 * @return array<int, array<string, mixed>>
	 */
	private static function seats_of( array $item ): array {
		$window = self::window( $item );

		if ( array() === $window ) {
			/*
			 * Hours with no dates are a plan nobody has finished making. #141
			 * makes both mandatory before Up Next, as its own requirement with
			 * its own message — so an item like this is left out here rather
			 * than guessed at, and the person is told about the missing dates
			 * rather than about a capacity figure derived from a guess.
			 */
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
				/*
				 * Who is being covered, where somebody is standing in. The
				 * commitment follows whoever is doing the work (AUTH-4 records
				 * the seat); this says so on the face of it, so a capacity view
				 * can explain why somebody is carrying a review that is not
				 * theirs.
				 */
				'covering'       => '' !== $cover ? $seat : '',
				'hours'          => $hours,
				'from'           => $window[0],
				'to'             => $window[1],
			);
		}

		return $out;
	}
```

- [ ] **Step 4: Run the test and the existing allocation tests**

Run: `vendor/bin/phpunit --filter "CapacityImpactTest|CapacityAllocationsTest"`
Expected: PASS. `CapacityAllocationsTest` must still pass untouched — `from_item()`'s behaviour has not changed, only where its body lives.

- [ ] **Step 5: Commit**

```bash
git add includes/Capacity/Allocations.php tests/php/CapacityImpactTest.php
git commit -m "Read an item's seats before it commits them (#141)"
```

- [ ] **Step 6: Write the failing tests for `Impact::assess()`**

Append to `tests/php/CapacityImpactTest.php`, inside the class:

```php
	/**
	 * A working week of eight-hour days, Monday to Friday.
	 *
	 * @param string $from    YYYY-MM-DD Monday.
	 * @param int    $days    How many days to produce.
	 * @param float  $hours   Hours on each working day.
	 * @return array<int, array{date: string, hours: float, base_hours: float, reason: string}>
	 */
	private function days( string $from, int $days, float $hours = 8.0 ): array {
		$out  = array();
		$time = (int) strtotime( $from . ' 00:00:00 UTC' );

		for ( $i = 0; $i < $days; $i++ ) {
			$date    = gmdate( 'Y-m-d', $time + ( $i * DAY_IN_SECONDS ) );
			$weekend = (int) gmdate( 'N', $time + ( $i * DAY_IN_SECONDS ) ) > 5;

			$out[] = array(
				'date'       => $date,
				'hours'      => $weekend ? 0.0 : $hours,
				'base_hours' => $weekend ? 0.0 : $hours,
				'reason'     => $weekend ? 'non-working-day' : '',
			);
		}

		return $out;
	}

	public function test_a_job_that_fits_over_books_nobody(): void {
		$item = $this->item();

		$impact = Impact::assess(
			Allocations::proposed( $item ),
			array(),
			array(
				'usr_a' => $this->days( '2026-09-07', 5 ),
				'usr_b' => $this->days( '2026-09-07', 5 ),
			),
			'2026-09-07',
			'2026-09-11'
		);

		$this->assertSame( array(), $impact['over'] );
		$this->assertTrue( Impact::clear( $impact ) );
	}

	public function test_a_job_bigger_than_the_week_over_books_the_person_doing_it(): void {
		$item = $this->item( array( 'hours_primary' => 50.0 ) );

		$impact = Impact::assess(
			Allocations::proposed( $item ),
			array(),
			array(
				'usr_a' => $this->days( '2026-09-07', 5 ),
				'usr_b' => $this->days( '2026-09-07', 5 ),
			),
			'2026-09-07',
			'2026-09-11'
		);

		$this->assertFalse( Impact::clear( $impact ) );
		$this->assertCount( 1, $impact['over'] );
		$this->assertSame( 'usr_a', $impact['over'][0]['user_id'] );
		$this->assertSame( '2026-09-07', $impact['over'][0]['week_from'] );
		$this->assertSame( 10.0, $impact['over'][0]['excess'] );
	}

	public function test_comfortable_overall_and_impossible_in_one_week_is_over_booked(): void {
		// CAP-E2. Two weeks, 60 hours available, 60 hours committed — but the
		// second week is leave, so all 60 land in the first. An across-the-job
		// total would call this fine.
		$days = array_merge(
			$this->days( '2026-09-07', 7 ),
			array_map(
				static function ( array $day ): array {
					return array_merge( $day, array( 'hours' => 0.0, 'reason' => 'leave' ) );
				},
				$this->days( '2026-09-14', 7 )
			)
		);

		$item = $this->item(
			array(
				'planned_due'   => '2026-09-18',
				'hours_primary' => 60.0,
				'hours_review'  => 0.0,
			)
		);

		$impact = Impact::assess(
			Allocations::proposed( $item ),
			array(),
			array( 'usr_a' => $days ),
			'2026-09-07',
			'2026-09-18'
		);

		$this->assertFalse( Impact::clear( $impact ) );
		$this->assertSame( '2026-09-07', $impact['over'][0]['week_from'] );
	}

	public function test_a_person_nobody_has_set_up_is_not_over_booked(): void {
		// The gate must not refuse a move because an admin screen is unfilled.
		$blank = array_map(
			static function ( array $day ): array {
				return array_merge( $day, array( 'hours' => 0.0, 'base_hours' => 0.0, 'reason' => 'no-pattern' ) );
			},
			$this->days( '2026-09-07', 5 )
		);

		$impact = Impact::assess(
			Allocations::proposed( $this->item() ),
			array(),
			array(
				'usr_a' => $blank,
				'usr_b' => $blank,
			),
			'2026-09-07',
			'2026-09-11'
		);

		$this->assertTrue( Impact::clear( $impact ) );
	}

	public function test_the_item_is_not_counted_twice_when_it_is_already_committing(): void {
		// CAP-E1. Entering In Development, the item is already at Up Next and
		// therefore already in the live set. Counted twice, a job that exactly
		// fills a week would refuse itself.
		$item     = $this->item( array( 'stage' => 'up-next', 'hours_primary' => 40.0, 'hours_review' => 0.0 ) );
		$existing = Allocations::proposed( $item );

		$impact = Impact::assess(
			Allocations::proposed( $item ),
			$existing,
			array( 'usr_a' => $this->days( '2026-09-07', 5 ) ),
			'2026-09-07',
			'2026-09-11'
		);

		$this->assertTrue( Impact::clear( $impact ) );
	}

	public function test_somebody_else_s_work_still_counts_against_the_same_person(): void {
		$mine   = $this->item( array( 'hours_primary' => 20.0, 'hours_review' => 0.0 ) );
		$theirs = $this->item(
			array(
				'id'            => 'wrk_two',
				'stage'         => 'up-next',
				'client_id'     => 'cli_two',
				'hours_primary' => 30.0,
				'hours_review'  => 0.0,
			)
		);

		$impact = Impact::assess(
			Allocations::proposed( $mine ),
			Allocations::proposed( $theirs ),
			array( 'usr_a' => $this->days( '2026-09-07', 5 ) ),
			'2026-09-07',
			'2026-09-11'
		);

		$this->assertFalse( Impact::clear( $impact ) );
		$this->assertSame( 10.0, $impact['over'][0]['excess'] );
	}

	public function test_a_substitute_carries_the_commitment_rather_than_the_seat(): void {
		$item = $this->item(
			array(
				'hours_primary'          => 0.0,
				'hours_review'           => 50.0,
				'reviewer_substitute_id' => 'usr_c',
			)
		);

		$impact = Impact::assess(
			Allocations::proposed( $item ),
			array(),
			array(
				'usr_b' => $this->days( '2026-09-07', 5 ),
				'usr_c' => $this->days( '2026-09-07', 5 ),
			),
			'2026-09-07',
			'2026-09-11'
		);

		$this->assertSame( 'usr_c', $impact['over'][0]['user_id'] );
	}

	public function test_an_item_with_no_dates_reaches_no_conclusion(): void {
		// The missing dates are their own unmet requirement at Up Next. A
		// capacity verdict guessed from nothing would refuse for the wrong
		// reason and send somebody looking in the wrong place.
		$impact = Impact::assess(
			Allocations::proposed( $this->item( array( 'planned_start' => '', 'planned_due' => '' ) ) ),
			array(),
			array(),
			'',
			''
		);

		$this->assertTrue( Impact::clear( $impact ) );
	}
```

- [ ] **Step 7: Run them and watch them fail**

Run: `vendor/bin/phpunit --filter CapacityImpactTest`
Expected: FAIL — `Class "Blueworx\Forge\Capacity\Impact" not found`.

- [ ] **Step 8: Write `Capacity\Impact`**

Create `includes/Capacity/Impact.php`:

```php
<?php
/**
 * Whether a move would over-book anybody.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Capacity;

/**
 * CAP-E1: the one place that answers "would this over-book anybody?" (#141).
 *
 * Everything that refuses on capacity asks this — the gate at Up Next, the
 * recheck at In Development, and the screen that shows why. That is the point
 * of it being one class rather than three call sites: the gate and the
 * Capacity screen have to agree about the same person in the same week, and
 * three implementations of the same arithmetic do not stay agreed.
 *
 * Two things make it more than a call to Position.
 *
 * **The item is not counted yet.** Allocations::COMMITTING begins at Up Next,
 * so an item on its way there commits nothing and Position would report a world
 * without it. So the item's own allocations are always added on top, and always
 * removed from the existing set first — because an item entering In Development
 * is already at Up Next and already in that set, and counted twice a job that
 * exactly fills a week would refuse itself.
 *
 * **Week by week (CAP-E2).** Any single week that tips somebody over counts.
 * A total across the whole job hides the two-month piece of work that is
 * comfortable on average and leaves somebody with nothing free next week —
 * which is the case worth catching, and the one the Capacity screen already
 * shows in red.
 */
final class Impact {

	/**
	 * Whether a move leaves everybody inside their hours.
	 *
	 * @param array<string, mixed> $impact An assessment.
	 * @return bool
	 */
	public static function clear( array $impact ): bool {
		return array() === ( $impact['over'] ?? array() );
	}

	/**
	 * What this item would do to the people in its seats.
	 *
	 * @param array<string, mixed> $item A hydrated work item.
	 * @return array{over: array<int, array<string, mixed>>, window: array{from: string, to: string}}
	 */
	public static function of( array $item ): array {
		$proposed = Allocations::proposed( $item );

		if ( array() === $proposed ) {
			return self::nothing();
		}

		$from = (string) $proposed[0]['from'];
		$to   = (string) $proposed[0]['to'];

		$user_ids = array_values( array_unique( array_column( $proposed, 'user_id' ) ) );

		return self::assess(
			$proposed,
			Commitments::live( $from, $to ),
			Availability::for_people( $user_ids, $from, $to ),
			$from,
			$to
		);
	}

	/**
	 * The same answer from figures already in hand.
	 *
	 * No database in it, so every rule above can be stated in a test rather
	 * than inferred from a site.
	 *
	 * @param array<int, array<string, mixed>>                              $proposed     What this item would commit.
	 * @param array<int, array<string, mixed>>                              $existing     Every live allocation, this item's included.
	 * @param array<string, array<int, array<string, mixed>>>               $days_by_user Availability::by_day per person.
	 * @param string                                                        $from         YYYY-MM-DD, inclusive.
	 * @param string                                                        $to           YYYY-MM-DD, inclusive.
	 * @return array{over: array<int, array<string, mixed>>, window: array{from: string, to: string}}
	 */
	public static function assess( array $proposed, array $existing, array $days_by_user, string $from, string $to ): array {
		if ( array() === $proposed || '' === $from || '' === $to ) {
			// No seats filled, or no dates to weigh them over. Both are their
			// own unmet requirement at Up Next, with their own message.
			return self::nothing();
		}

		$item_id = (string) ( $proposed[0]['item_id'] ?? '' );

		// This item's own allocations, wherever they already are. Removed and
		// then added back, so the same code path serves a move into Up Next
		// (not there yet) and one into In Development (there already).
		$others = array_values(
			array_filter(
				$existing,
				static function ( array $allocation ) use ( $item_id ): bool {
					return (string) ( $allocation['item_id'] ?? '' ) !== $item_id;
				}
			)
		);

		$committed = Commitments::gather( array_merge( $others, $proposed ), $days_by_user );
		$weeks     = Periods::weeks( $from, $to );
		$over      = array();
		$seen      = array();

		foreach ( $proposed as $allocation ) {
			$user_id = (string) $allocation['user_id'];

			if ( isset( $seen[ $user_id ] ) ) {
				continue;
			}

			$seen[ $user_id ] = true;
			$days             = (array) ( $days_by_user[ $user_id ] ?? array() );
			$by_day           = (array) ( $committed[ $user_id ]['by_day'] ?? array() );

			foreach ( $weeks as $week ) {
				$position = Position::over( $days, $by_day, $week['from'], $week['to'] );

				if ( Position::OVER !== $position['band'] ) {
					continue;
				}

				$over[] = array(
					'user_id'   => $user_id,
					'week_from' => $week['from'],
					'week_to'   => $week['to'],
					'available' => $position['available'],
					'committed' => $position['committed'],
					'excess'    => round( $position['committed'] - $position['available'], 2 ),
				);
			}
		}

		return array(
			'over'   => $over,
			'window' => array(
				'from' => $from,
				'to'   => $to,
			),
		);
	}

	/**
	 * No conclusion, which is not the same as a pass — it is a question that
	 * could not be asked, and something else is already refusing the move for
	 * the reason it could not.
	 *
	 * @return array{over: array<int, array<string, mixed>>, window: array{from: string, to: string}}
	 */
	private static function nothing(): array {
		return array(
			'over'   => array(),
			'window' => array(
				'from' => '',
				'to'   => '',
			),
		);
	}
}
```

Note the `$seen` guard: an item can put the same person in two seats, and reporting them over twice for one week would show a person a duplicate row.

- [ ] **Step 9: Run the tests**

Run: `vendor/bin/phpunit --filter CapacityImpactTest`
Expected: PASS, all nine.

- [ ] **Step 10: Run the linter over the new file**

Run: `vendor/bin/phpcs includes/Capacity/Impact.php includes/Capacity/Allocations.php`
Expected: no errors. Fix anything reported before committing — this is the one-off check, not a loop.

- [ ] **Step 11: Commit**

```bash
git add includes/Capacity/Impact.php tests/php/CapacityImpactTest.php
git commit -m "Answer whether a move would over-book anybody (#141)"
```

---

## Task 2: The gate at Up Next (#141)

**Files:**
- Modify: `includes/Work/Gates.php`
- Modify: `includes/Work/Transition.php`
- Test: `tests/php/CapacityGateTest.php`

**Interfaces:**
- Consumes: `Impact::of()`, `Impact::clear()` from Task 1.
- Produces:
  - `Gates` `check()` handles `'capacity'`, reading `$context['capacity']`.
  - `Transition::readiness( array $item, string $to, array $children = array(), string $capacity_reason = '' )` — a fourth parameter, defaulted so no existing caller changes.
  - `$context['capacity']` shape: `array{ over: array<int, array<string,mixed>>, reason: string }`.

### The pattern to follow

`Gates` does not touch the database. `children_completed` is answered by `Work\Derived` from data `Transition::evaluate()` put in `$context`. The capacity check works the same way: `Transition` calls `Impact::of()` and puts the result in `$context['capacity']`, and `Gates` reads it. Keep it that way — a `Gates` class that queries is a `Gates` class that cannot be unit-tested without a database.

- [ ] **Step 1: Write the failing test**

Create `tests/php/CapacityGateTest.php`:

```php
<?php
/**
 * What the capacity check does to a gate.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Work\Gates;
use PHPUnit\Framework\TestCase;

/**
 * #141 and #142. The placeholder left by #105 becomes a real refusal, and the
 * gate keeps its promise to report everything at once rather than one thing at
 * a time.
 */
final class CapacityGateTest extends TestCase {

	/**
	 * An item with every Up Next requirement met except what a test changes.
	 *
	 * @param array<string, mixed> $overrides Anything to change.
	 * @return array<string, mixed>
	 */
	private function ready_item( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'              => 'wrk_one',
				'created_by'      => 1,
				'primary_user_id' => 'usr_a',
				'reviewer_id'     => 'usr_b',
				'deliverer_id'    => 'usr_c',
				'planned_start'   => '2026-09-07',
				'planned_due'     => '2026-09-11',
				'priority'        => 'high',
			),
			$overrides
		);
	}

	/**
	 * Records satisfying every by-record requirement of G-UP-NEXT.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function records(): array {
		return array(
			'G-UP-NEXT-4' => array( 'requirement' => 'G-UP-NEXT-4' ),
			'G-UP-NEXT-7' => array( 'requirement' => 'G-UP-NEXT-7' ),
		);
	}

	public function test_room_for_the_work_passes_the_check(): void {
		$result = Gates::evaluate(
			'G-UP-NEXT',
			$this->ready_item(),
			$this->records(),
			array( 'capacity' => array( 'over' => array(), 'reason' => '' ) )
		);

		$this->assertSame( array(), $result['unmet'] );
	}

	public function test_no_room_refuses_the_move(): void {
		$result = Gates::evaluate(
			'G-UP-NEXT',
			$this->ready_item(),
			$this->records(),
			array(
				'capacity' => array(
					'over'   => array(
						array(
							'user_id'   => 'usr_a',
							'week_from' => '2026-09-07',
							'week_to'   => '2026-09-13',
							'available' => 40.0,
							'committed' => 50.0,
							'excess'    => 10.0,
						),
					),
					'reason' => '',
				),
			)
		);

		$this->assertSame( array( 'G-UP-NEXT-8' ), array_column( $result['unmet'], 'id' ) );
	}

	public function test_a_reason_permits_the_over_allocation(): void {
		// CAP-4: over-allocation does not hard block. It costs a reason.
		$result = Gates::evaluate(
			'G-UP-NEXT',
			$this->ready_item(),
			$this->records(),
			array(
				'capacity' => array(
					'over'   => array( array( 'user_id' => 'usr_a', 'week_from' => '2026-09-07', 'week_to' => '2026-09-13', 'available' => 40.0, 'committed' => 50.0, 'excess' => 10.0 ) ),
					'reason' => 'Client has agreed the overtime.',
				),
			)
		);

		$this->assertSame( array(), $result['unmet'] );
	}

	public function test_capacity_is_reported_alongside_everything_else_missing(): void {
		// #107. Somebody missing dates and over-booking a reviewer is told
		// both, not told one, told the other, and refused twice.
		$result = Gates::evaluate(
			'G-UP-NEXT',
			$this->ready_item( array( 'planned_start' => '', 'planned_due' => '' ) ),
			$this->records(),
			array(
				'capacity' => array(
					'over'   => array( array( 'user_id' => 'usr_a', 'week_from' => '2026-09-07', 'week_to' => '2026-09-13', 'available' => 40.0, 'committed' => 50.0, 'excess' => 10.0 ) ),
					'reason' => '',
				),
			)
		);

		$ids = array_column( $result['unmet'], 'id' );

		$this->assertContains( 'G-UP-NEXT-5', $ids );
		$this->assertContains( 'G-UP-NEXT-8', $ids );
	}

	public function test_the_refusal_says_who_is_over_and_when(): void {
		$result = Gates::evaluate(
			'G-UP-NEXT',
			$this->ready_item(),
			$this->records(),
			array(
				'capacity' => array(
					'over'   => array( array( 'user_id' => 'usr_a', 'week_from' => '2026-09-07', 'week_to' => '2026-09-13', 'available' => 40.0, 'committed' => 50.0, 'excess' => 10.0 ) ),
					'reason' => '',
				),
			)
		);

		$this->assertSame( 'usr_a', $result['unmet'][0]['over'][0]['user_id'] );
		$this->assertSame( '2026-09-07', $result['unmet'][0]['over'][0]['week_from'] );
	}

	public function test_support_hours_is_still_a_placeholder(): void {
		// M8 owns it. Until then it reports and refuses nothing, and #141 must
		// not have quietly made it real.
		$result = Gates::evaluate(
			'G-UP-NEXT',
			$this->ready_item(),
			$this->records(),
			array( 'capacity' => array( 'over' => array(), 'reason' => '' ) )
		);

		$ids = array_column( $result['checks'], 'id' );

		$this->assertContains( 'G-UP-NEXT-9', $ids );
		$this->assertSame( 'pass', $result['checks'][ array_search( 'G-UP-NEXT-9', $ids, true ) ]['result'] );
	}
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `vendor/bin/phpunit --filter CapacityGateTest`
Expected: FAIL — `test_no_room_refuses_the_move` reports `array()` because `G-UP-NEXT-8` is still deferred and refuses nothing.

- [ ] **Step 3: Make `G-UP-NEXT-8` real**

In `includes/Work/Gates.php`, replace the `G-UP-NEXT-8` line:

```php
				self::deferred( 'G-UP-NEXT-8', 'Capacity check', 'capacity', 'The capacity model arrives with CAP-4; until it does this reports as passed.' ),
```

with:

```php
				self::system( 'G-UP-NEXT-8', 'Capacity check', 'capacity', 'Nobody in a seat may be over-booked in any week of the planned dates, unless an over-allocation is given a reason.' ),
```

- [ ] **Step 4: Teach `check()` the capacity case**

In `includes/Work/Gates.php`, in the `check()` switch, add before `default:`:

```php
			case 'capacity':
				/*
				 * CAP-4: over-allocation does not block, it costs a reason.
				 * The figures are worked out by Capacity\Impact and handed in
				 * through the context, the same way children are — this class
				 * stays free of the database, which is what lets every gate
				 * rule above be stated in a test.
				 */
				$capacity = (array) ( $context['capacity'] ?? array() );

				if ( array() === (array) ( $capacity['over'] ?? array() ) ) {
					return true;
				}

				return '' !== trim( (string) ( $capacity['reason'] ?? '' ) );
```

- [ ] **Step 5: Carry the detail into the refusal**

In `includes/Work/Gates.php`, `evaluate()` currently builds `$unmet[] = self::as_unmet( $requirement );` for a failed system check. Change that one line, in the `BY_SYSTEM` branch only, to attach the figures:

```php
				$failure = self::as_unmet( $requirement );

				if ( 'capacity' === (string) $requirement['check'] ) {
					// Who and when, so the screen can name them rather than
					// saying "no room" and leaving somebody to go looking.
					$failure['over'] = array_values( (array) ( ( (array) ( $context['capacity'] ?? array() ) )['over'] ?? array() ) );
				}

				$unmet[] = $failure;
				continue;
```

- [ ] **Step 6: Run the gate tests**

Run: `vendor/bin/phpunit --filter "CapacityGateTest|WorkGatesTest"`
Expected: PASS. `WorkGatesTest` may have a case asserting `G-UP-NEXT-8` is deferred — if it does, update it to assert the new behaviour rather than deleting it, and say so in the commit.

- [ ] **Step 7: Wire the context in `Transition`**

In `includes/Work/Transition.php`, change `evaluate()` and `readiness()`:

```php
	/**
	 * What stands between an item and a stage, without moving it.
	 *
	 * @param array<string, mixed>             $item     The item, as read.
	 * @param string                           $to       Where it would go.
	 * @param array<int, array<string, mixed>> $children Its children.
	 * @param string                           $reason   An over-allocation reason offered with the move.
	 * @return array{unmet: array<int, array<string, mixed>>, checks: array<int, array<string, mixed>>}
	 */
	public static function readiness( array $item, string $to, array $children = array(), string $reason = '' ): array {
		$gates = array( Transitions::gate_for( (string) $item['stage'], $to ), Transitions::entry_gate_for( $to ) );

		return self::evaluate( $item, $gates, $children, $reason );
	}

	/**
	 * Evaluates a list of gates and merges the results.
	 *
	 * @param array<string, mixed>             $item     The item, as read.
	 * @param array<int, string>               $gates    Gate names; blanks skipped.
	 * @param array<int, array<string, mixed>> $children Its children.
	 * @param string                           $reason   An over-allocation reason offered with the move.
	 * @return array{unmet: array<int, array<string, mixed>>, checks: array<int, array<string, mixed>>}
	 */
	private static function evaluate( array $item, array $gates, array $children, string $reason = '' ): array {
		$records = GateRecords::current_for( $item );
		$unmet   = array();
		$checks  = array();

		$gates = array_unique( array_filter( $gates ) );

		/*
		 * Worked out once for the whole evaluation rather than per gate. A move
		 * runs an exit gate and an entry gate, both of which may ask, and the
		 * answer cannot differ between them — it is the same item and the same
		 * week.
		 */
		$context = array(
			'children' => $children,
			'capacity' => array(
				'over'   => array(),
				'reason' => $reason,
			),
		);

		if ( self::asks_about_capacity( $gates ) ) {
			$context['capacity']['over'] = Impact::of( $item )['over'];
		}

		foreach ( $gates as $gate ) {
			$result = Gates::evaluate( $gate, $item, $records, $context );
			$unmet  = array_merge( $unmet, $result['unmet'] );
			$checks = array_merge( $checks, $result['checks'] );
		}

		return array(
			'unmet'  => $unmet,
			'checks' => $checks,
		);
	}

	/**
	 * Whether any gate in this move runs the capacity check.
	 *
	 * Asked before the reading rather than after, because Impact::of() is two
	 * queries and most moves in the workflow have nothing to do with capacity.
	 *
	 * @param array<int, string> $gates Gate names.
	 * @return bool
	 */
	private static function asks_about_capacity( array $gates ): bool {
		foreach ( $gates as $gate ) {
			foreach ( Gates::requirements( $gate ) as $requirement ) {
				if ( 'capacity' === (string) ( $requirement['check'] ?? '' ) ) {
					return true;
				}
			}
		}

		return false;
	}
```

Add `use Blueworx\Forge\Capacity\Impact;` to the file's imports.

- [ ] **Step 8: Carry the reason through `gate_refusal()` and `move()`**

Change the two signatures in `includes/Work/Transition.php`:

```php
	private static function gate_refusal( array $item, string $to, array $gates, string $reason = '' ): ?WP_Error {
		$result = self::evaluate( $item, $gates, Items::children( (string) $item['id'] ), $reason );
```

and in `move()`, add the parameter and pass it to the `gate_refusal()` call inside:

```php
	public static function move( array $item, string $to, int $sent_version, int $actor, string $via = '', string $reason = '' ) {
```

Find the `gate_refusal(` call in `move()` and add `, $reason` as its fourth argument. Leave every other caller alone — the default keeps them working.

- [ ] **Step 9: Run the whole PHP suite**

Run: `vendor/bin/phpunit`
Expected: PASS. Any failure here is a caller whose gate now asks a question it did not before — fix the caller, not the check.

- [ ] **Step 10: Lint and commit**

```bash
vendor/bin/phpcs includes/Work/Gates.php includes/Work/Transition.php
git add includes/Work/Gates.php includes/Work/Transition.php tests/php/CapacityGateTest.php
git commit -m "Refuse Up Next when there is no room for the work (#141)"
```

---

## Task 3: The over-allocation override (#143)

**Files:**
- Create: `includes/Work/CapacityOverride.php`
- Modify: `includes/Data/Schema.php`, `includes/Work/Events.php`, `includes/Work/Fields.php`, `includes/Work/Transition.php`, `includes/Rest/WorkItemsController.php`
- Test: `tests/php/CapacityOverrideTest.php`, and a case added to `tests/php/SchemaTest.php`

**Interfaces:**
- Consumes: `Impact` from Task 1, the `$reason` parameter from Task 2.
- Produces:
  - `CapacityOverride::MAX_REASON` (int, 191)
  - `CapacityOverride::mark( string $reason ): array` — `array{capacity_override_used: int, capacity_override_reason: string}`
  - `Events::OVER_ALLOCATED` (string, `'over-allocated'`)
  - Columns `capacity_override_used tinyint(1)` and `capacity_override_reason varchar(191)` on `bwx_forge_work_items`.

### Why a second override class rather than a flag on the first

CAP-E3. `Work\Override`'s mark means "somebody moved this by hand and its history has a hole in it", and the override report exists to surface exactly that. Over-booking somebody is a normal call made on better information than the model has — which is why CAP-4 chose it over a hard block. Folding it into the WF-5 mark would make the report mostly routine and therefore unread.

- [ ] **Step 1: Write the failing test**

Create `tests/php/CapacityOverrideTest.php`:

```php
<?php
/**
 * What over-booking somebody costs.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Work\CapacityOverride;
use Blueworx\Forge\Work\Override;
use PHPUnit\Framework\TestCase;

/**
 * CAP-E3 (#143). Over-allocation is permitted, with a reason, and recorded —
 * and it is deliberately not the WF-5 override, whose mark means something
 * else and whose report would be useless full of routine capacity calls.
 */
final class CapacityOverrideTest extends TestCase {

	public function test_the_reason_is_written_onto_the_item(): void {
		$mark = CapacityOverride::mark( 'Client has agreed the overtime.' );

		$this->assertSame( 1, $mark['capacity_override_used'] );
		$this->assertSame( 'Client has agreed the overtime.', $mark['capacity_override_reason'] );
	}

	public function test_a_long_reason_is_cut_to_the_column(): void {
		$mark = CapacityOverride::mark( str_repeat( 'a', 400 ) );

		$this->assertSame( CapacityOverride::MAX_REASON, strlen( $mark['capacity_override_reason'] ) );
	}

	public function test_it_does_not_touch_the_workflow_override(): void {
		$mark = CapacityOverride::mark( 'Client has agreed the overtime.' );

		$this->assertArrayNotHasKey( 'override_used', $mark );
		$this->assertArrayNotHasKey( 'override_reason', $mark );
	}

	public function test_the_two_overrides_are_separate_marks(): void {
		// If these ever collide, the override report stops being able to tell a
		// workflow correction from a busy week.
		$this->assertNotSame(
			array_keys( CapacityOverride::mark( 'a' ) ),
			array_keys( Override::mark( 'a' ) )
		);
	}
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `vendor/bin/phpunit --filter CapacityOverrideTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write `Work\CapacityOverride`**

Create `includes/Work/CapacityOverride.php`:

```php
<?php
/**
 * The way round the capacity check, and what it costs.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Work;

/**
 * CAP-4, and CAP-E3 in the enforcement design (#143): over-allocating somebody
 * requires a reason from a studio administrator, and does not hard block.
 *
 * It is not the WF-5 override, and the difference matters more than the
 * similarity. Work\Override's mark says an item's history has a hole in it —
 * somebody put it where the workflow would not have. That is rare, and the
 * override report exists to surface it. Over-booking somebody is not that: it
 * is a manager deciding, on information the model does not have, that a week
 * will take more than the arithmetic says. CAP-4 chose that over a hard block
 * precisely because a capacity model with no actuals in it should not overrule
 * a person. Marking those with the WF-5 flag would fill the report with routine
 * decisions and bury the ones it was built to show.
 *
 * What the two do share is who: any studio administrator, so a real week is
 * never waiting on one person.
 *
 * Two things it cannot do:
 *
 * - **It cannot be given by a client.** The transition lock is a security
 *   boundary rather than a workflow gate, and Tenancy\Capabilities refuses a
 *   client role before this class is reached.
 * - **It cannot be given once and cover everything after.** The permission is
 *   for one crossing (CAP-E4). The recheck at In Development asks again,
 *   because the picture it is checking is not the picture Up Next checked.
 */
final class CapacityOverride {

	/**
	 * Longest a reason may be, matching the column it lands in.
	 */
	public const MAX_REASON = 191;

	/**
	 * What the override writes onto the item, so that it says so afterwards.
	 *
	 * The column carries the most recent reason. Every reason ever given is
	 * kept as its own history entry, so the item reads as current while the
	 * trail stays complete.
	 *
	 * @param string $reason Why the week will take it.
	 * @return array<string, mixed>
	 */
	public static function mark( string $reason ): array {
		return array(
			'capacity_override_used'   => 1,
			'capacity_override_reason' => mb_substr( trim( $reason ), 0, self::MAX_REASON ),
		);
	}
}
```

- [ ] **Step 4: Run the test**

Run: `vendor/bin/phpunit --filter CapacityOverrideTest`
Expected: PASS.

- [ ] **Step 5: Add the columns**

In `includes/Data/Schema.php`, bump the version:

```php
	public const VERSION = 12;
```

In the `$work_items` CREATE statement, immediately after the `override_reason` line, add:

```sql
			capacity_override_used tinyint(1) NOT NULL DEFAULT 0,
			capacity_override_reason varchar(191) NOT NULL DEFAULT '',
```

Both are `NOT NULL` with a default, which is what the class docblock in that file requires of any column added by `dbDelta` — a `NOT NULL` column with no default fails on an existing table.

- [ ] **Step 6: Add the history action**

In `includes/Work/Events.php`, after the `OVERRIDDEN` constant:

```php
	/**
	 * Somebody was over-booked, deliberately and with a reason (CAP-4, #143).
	 *
	 * Its own action rather than an OVERRIDDEN with a note, so "how often are
	 * we over-committing people" is a query. It is the question the capacity
	 * report is for, and a report that has to read reasons to answer it is a
	 * report nobody runs.
	 */
	public const OVER_ALLOCATED = 'over-allocated';
```

Add `self::OVER_ALLOCATED,` to the `ACTIONS` array.

- [ ] **Step 7: Make the columns readable and not casually writable**

In `includes/Work/Fields.php`, add `capacity_override_used` and `capacity_override_reason` wherever `override_used` and `override_reason` appear in a readable/exposed list, and **not** to any editable list. Read that file first and follow whatever shape it uses — the point is that the two pairs are treated identically.

- [ ] **Step 8: Write the mark and the history entry when the move goes through**

In `includes/Work/Transition.php`, inside `move()`, after the gate refusal has passed and before `commit()`, add:

```php
		$also = array();

		if ( '' !== trim( $reason ) ) {
			/*
			 * The reason only reaches here when the gate actually needed it —
			 * an over-allocation refused without one is refused above. So a
			 * reason present at this point means somebody was over-booked and
			 * said why, and the item should say so afterwards.
			 */
			$also = CapacityOverride::mark( $reason );

			Events::append(
				array(
					'item_id'        => (string) $item['id'],
					'client_site_id' => (string) $item['client_site_id'],
					'action'         => Events::OVER_ALLOCATED,
					'from_stage'     => (string) $item['stage'],
					'to_stage'       => $to,
					'reason'         => $reason,
					'actor'          => $actor,
				)
			);
		}
```

and pass `$also` into the existing `commit()` call as its `$also` argument, merged with whatever it already passes.

Add `use` imports if the file does not already have them in scope — both classes are in the same namespace, so no import is needed for `CapacityOverride`.

- [ ] **Step 9: Accept the reason at the route**

In `includes/Rest/WorkItemsController.php`, find the move route's args and add:

```php
				'capacity_reason' => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
```

and pass `(string) $request->get_param( 'capacity_reason' )` as the sixth argument to `Transition::move()`. Follow the file's existing shape for the other args rather than the sketch above — read the surrounding route registration first.

- [ ] **Step 10: Run everything**

Run: `vendor/bin/phpunit`
Expected: PASS. `SchemaTest` may assert the version or the column list — update it to expect 12 and the two new columns.

- [ ] **Step 11: Lint and commit**

```bash
vendor/bin/phpcs includes/Work/CapacityOverride.php includes/Work/Transition.php includes/Work/Events.php includes/Data/Schema.php includes/Rest/WorkItemsController.php
git add includes/ tests/php/CapacityOverrideTest.php
git commit -m "Let a studio administrator over-book somebody, with a reason (#143)"
```

---

## Task 4: The recheck at In Development (#142)

**Files:**
- Modify: `includes/Work/Gates.php`
- Test: `tests/php/CapacityGateTest.php` (add cases)

**Interfaces:**
- Consumes: everything from Tasks 1–3. Nothing new is produced — this is the same check in a second gate, which is the point.

### Where it goes

`G-IN-DEVELOPMENT` is the **exit** gate for In Development, run on the way out. #142 wants the check on the way **in**. Read `includes/Work/Transitions.php` first and confirm which gate `entry_gate_for( 'in-development' )` returns; add the requirement to that gate, not to `G-IN-DEVELOPMENT`. If no entry gate exists for the stage, add the requirement to `G-UP-NEXT`'s exit gate — which is the same crossing — and say so in the commit message.

- [ ] **Step 1: Confirm which gate the crossing runs**

Run: `grep -n "entry_gate_for\|in-development" includes/Work/Transitions.php`
Note the gate name. Every step below says `<ENTRY-GATE>`; substitute it.

- [ ] **Step 2: Write the failing tests**

Add to `tests/php/CapacityGateTest.php`:

```php
	public function test_the_recheck_refuses_when_the_room_has_gone(): void {
		// #142. Weeks have moved since planning, and the plan was made against
		// a picture that no longer exists.
		$result = Gates::evaluate(
			'<ENTRY-GATE>',
			$this->ready_item( array( 'capacity_override_used' => 1, 'capacity_override_reason' => 'Agreed at planning.' ) ),
			$this->records(),
			array(
				'capacity' => array(
					'over'   => array( array( 'user_id' => 'usr_a', 'week_from' => '2026-09-07', 'week_to' => '2026-09-13', 'available' => 40.0, 'committed' => 50.0, 'excess' => 10.0 ) ),
					'reason' => '',
				),
			)
		);

		$this->assertContains( 'capacity', array_column( $result['unmet'], 'check' ) );
	}

	public function test_the_earlier_reason_does_not_satisfy_the_recheck(): void {
		// CAP-E4. The permission covered one crossing. The mark stays on the
		// item; what does not carry forward is the satisfaction of the gate.
		$item = $this->ready_item(
			array(
				'capacity_override_used'   => 1,
				'capacity_override_reason' => 'Agreed at planning.',
			)
		);

		$result = Gates::evaluate(
			'<ENTRY-GATE>',
			$item,
			$this->records(),
			array(
				'capacity' => array(
					'over'   => array( array( 'user_id' => 'usr_a', 'week_from' => '2026-09-07', 'week_to' => '2026-09-13', 'available' => 40.0, 'committed' => 50.0, 'excess' => 10.0 ) ),
					'reason' => '',
				),
			)
		);

		$this->assertNotSame( array(), $result['unmet'] );
	}

	public function test_a_fresh_reason_permits_the_recheck(): void {
		$result = Gates::evaluate(
			'<ENTRY-GATE>',
			$this->ready_item(),
			$this->records(),
			array(
				'capacity' => array(
					'over'   => array( array( 'user_id' => 'usr_a', 'week_from' => '2026-09-07', 'week_to' => '2026-09-13', 'available' => 40.0, 'committed' => 50.0, 'excess' => 10.0 ) ),
					'reason' => 'Still going ahead; the client knows.',
				),
			)
		);

		$this->assertSame( array(), $result['unmet'] );
	}
```

Note that CAP-E4 needs no code to hold: the check reads `$context['capacity']['reason']`, which comes from the request, and never reads `capacity_override_used` off the item. The tests exist to make that a fact somebody cannot accidentally undo.

- [ ] **Step 3: Run them and watch them fail**

Run: `vendor/bin/phpunit --filter CapacityGateTest`
Expected: FAIL — the entry gate has no capacity requirement yet.

- [ ] **Step 4: Add the requirement**

In `includes/Work/Gates.php`, in the `<ENTRY-GATE>` array, append:

```php
				self::system( '<ENTRY-GATE>-N', 'Capacity recheck', 'capacity', 'The plan was made weeks ago. Nobody in a seat may be over-booked now, unless the over-allocation is given a reason again.' ),
```

Number it after the gate's existing last requirement.

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/phpunit --filter CapacityGateTest`
Expected: PASS.

- [ ] **Step 6: Run everything, lint, commit**

```bash
vendor/bin/phpunit
vendor/bin/phpcs includes/Work/Gates.php
git add includes/Work/Gates.php tests/php/CapacityGateTest.php
git commit -m "Recheck there is still room before work starts (#142)"
```

---

## Task 5: The trail when the picture changes (#144)

**Files:**
- Modify: `includes/Capacity/Patterns.php`, `includes/Capacity/Unavailability.php`
- Test: `tests/php/CapacityImpactTest.php` (add), `tests/pair/` (add a case)

**Interfaces:**
- Consumes: `Events::append()`, `Commitments::live()`.
- Produces: nothing new. This task adds records, not capability.

### What this task is not

CAP-E5: there is no recalculation to write. Every figure in the engine is read from work items and availability records when it is asked for, so nothing can be stale. The issue's "recalculation across both interfaces" is already true and needs proving, not building. What is genuinely missing is the trail.

- [ ] **Step 1: Write the failing test for the trail**

Add a new file `tests/php/CapacityTrailTest.php`:

```php
<?php
/**
 * What a change to somebody's time leaves behind.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Capacity\Impact;
use PHPUnit\Framework\TestCase;

/**
 * #144, CAP-E5. Nothing is recalculated because nothing is stored. What was
 * missing is the record: a change to somebody's hours or leave has to be
 * findable afterwards from the work it affected.
 */
final class CapacityTrailTest extends TestCase {

	public function test_the_work_a_change_touches_is_the_work_in_the_window(): void {
		$allocations = array(
			array( 'item_id' => 'wrk_one', 'client_site_id' => 'sit_one', 'user_id' => 'usr_a', 'from' => '2026-09-07', 'to' => '2026-09-11' ),
			array( 'item_id' => 'wrk_two', 'client_site_id' => 'sit_two', 'user_id' => 'usr_b', 'from' => '2026-09-07', 'to' => '2026-09-11' ),
			array( 'item_id' => 'wrk_one', 'client_site_id' => 'sit_one', 'user_id' => 'usr_a', 'from' => '2026-09-07', 'to' => '2026-09-11' ),
		);

		$touched = Impact::work_touching( $allocations, 'usr_a' );

		// One entry per item, not one per seat: an item where somebody holds
		// two seats is still one piece of work whose picture changed.
		$this->assertSame( array( 'wrk_one' => 'sit_one' ), $touched );
	}

	public function test_a_change_for_somebody_with_no_live_work_touches_nothing(): void {
		$this->assertSame( array(), Impact::work_touching( array(), 'usr_a' ) );
	}
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `vendor/bin/phpunit --filter CapacityTrailTest`
Expected: FAIL — `Impact::work_touching()` not defined.

- [ ] **Step 3: Add `work_touching()` to `Impact`**

In `includes/Capacity/Impact.php`, add:

```php
	/**
	 * The live work one person's time change affects.
	 *
	 * Keyed by item so an item where somebody holds two seats is recorded once.
	 * Its picture changed once, and two entries would read as two events.
	 *
	 * @param array<int, array<string, mixed>> $allocations Live allocations over the window.
	 * @param string                           $user_id     Whose time changed.
	 * @return array<string, string> Item id to client site id.
	 */
	public static function work_touching( array $allocations, string $user_id ): array {
		$out = array();

		foreach ( $allocations as $allocation ) {
			if ( (string) ( $allocation['user_id'] ?? '' ) !== $user_id ) {
				continue;
			}

			$out[ (string) ( $allocation['item_id'] ?? '' ) ] = (string) ( $allocation['client_site_id'] ?? '' );
		}

		return $out;
	}
```

- [ ] **Step 4: Run the test**

Run: `vendor/bin/phpunit --filter CapacityTrailTest`
Expected: PASS.

- [ ] **Step 5: Record the trail on a pattern change**

In `includes/Capacity/Patterns.php`, find where a pattern is written. After a successful write, add:

```php
		self::record_effect( $user_id, $effective_from );
```

and add the method to the class:

```php
	/**
	 * Notes, against the work it affects, that somebody's hours changed.
	 *
	 * Nothing is recalculated — every capacity figure is read when it is asked
	 * for, so none of them can be stale (CAP-E5). What was missing is the
	 * record: somebody looking at a week that turned red needs to find out why
	 * without guessing, and nothing else in the item's history says a person's
	 * hours moved underneath it.
	 *
	 * @param string $user_id        Whose hours changed.
	 * @param string $effective_from YYYY-MM-DD the change starts.
	 * @return void
	 */
	private static function record_effect( string $user_id, string $effective_from ): void {
		$to = gmdate( 'Y-m-d', (int) strtotime( $effective_from . ' +1 year' ) );

		foreach ( Impact::work_touching( Commitments::live( $effective_from, $to ), $user_id ) as $item_id => $site_id ) {
			Events::append(
				array(
					'item_id'        => $item_id,
					'client_site_id' => $site_id,
					'action'         => Events::EDITED,
					'field'          => 'capacity',
					'reason'         => __( 'Somebody in a seat had their working hours changed.', 'blueworx-forge' ),
					'actor'          => get_current_user_id(),
				)
			);
		}
	}
```

Add the imports `use Blueworx\Forge\Work\Events;` and `use Blueworx\Forge\Capacity\Commitments;` as the file needs.

- [ ] **Step 6: Do the same for leave**

In `includes/Capacity/Unavailability.php`, add the same method — text changed to "had time off recorded against them", and the window taken from the record's own `from` and `to` rather than a year forward. Read the file first for how a record is written and where its dates live.

- [ ] **Step 7: Prove the client answer moves (CAP-E5)**

In `tests/pair/`, add a case to the existing capacity spec, or a new spec, following the shape of what is already there:

1. Ask the client site whether there is room, note the answer.
2. On the studio, commit enough work to fill the same window.
3. Ask the client site again.
4. Assert the answer changed, and assert the response body still contains no staff name, item title or client name — the #140 privacy assertions already written are the model; reuse them rather than writing new ones.

- [ ] **Step 8: Run the suites**

```bash
vendor/bin/phpunit
npm run wp:pair:up
npm run test:pair
```

Expected: PASS. If the pair sites are slow, `npm run wp:pair:reset` first.

- [ ] **Step 9: Lint and commit**

```bash
vendor/bin/phpcs includes/Capacity/
git add includes/Capacity/ tests/
git commit -m "Say so on the work when somebody's time changes underneath it (#144)"
```

---

## Task 6: The refusal and the override on screen

**Files:**
- Modify: `src/components/ItemPanel.tsx`
- Test: `tests/e2e/capacity-gate.spec.js`

**Interfaces:**
- Consumes: the `unmet` entry with `id: 'G-UP-NEXT-8'` and its `over` array from Task 2; the `capacity_reason` request parameter from Task 3.

### What is already there

`ItemPanel.tsx` already lists unmet requirements from a refused move. The capacity failure arrives in that same list and will render with no change at all. Two things are new: the `over` detail underneath it, and a control to give a reason and try again.

Use the `frontend-design` skill before writing the component work, per the project rules. Follow the panel's existing patterns rather than introducing new ones.

- [ ] **Step 1: Read the existing refusal rendering**

Run: `grep -n "unmet" src/components/ItemPanel.tsx`
Read the surrounding component. Note how a requirement's `satisfied_by` and `label` are rendered, and how the move request is sent.

- [ ] **Step 2: Write the failing Playwright test**

Create `tests/e2e/capacity-gate.spec.js`, following the shape of the existing specs in that directory (read one first for the login helper and fixtures):

```js
// #141, #143. A refusal somebody can act on: who is over-booked, in which
// week, and a way through that costs a reason.
test( 'over-booking is refused by name and permitted with a reason', async ( { page } ) => {
	// Set a person to a 40-hour week, give them 50 hours of work in one week,
	// then try to move a second item into Up Next against the same week.
	// Assert the refusal names the person and the week.
	// Fill the reason, submit, assert the move went through.
	// Assert the item afterwards says it was over-booked, with the reason.
} );
```

Fill the body in against the real fixtures — do not leave the comments as the test.

- [ ] **Step 3: Run it and watch it fail**

```bash
npm run wp:up
npx playwright test tests/e2e/capacity-gate.spec.js
```

Expected: FAIL — the refusal renders without the detail and there is no reason control.

- [ ] **Step 4: Render the detail and the control**

In `ItemPanel.tsx`, where an unmet requirement is rendered, add the capacity case: when the entry carries `over`, list each person and week beneath the requirement's own line, and offer a reason field plus a "move anyway" action that resends the move with `capacity_reason`.

Show the control only where the viewer may use it — a client interface must never render it. The panel already knows the viewer's capabilities; use what is there rather than adding a new check.

- [ ] **Step 5: Run the test**

```bash
npx playwright test tests/e2e/capacity-gate.spec.js
```
Expected: PASS.

- [ ] **Step 6: Confirm it visually**

Run `npm run dev` and have the user look at the refusal and the override control in the browser before committing. This is a change with a visible effect, and the project rules require the confirmation.

- [ ] **Step 7: Build, lint, commit**

```bash
npm run build
npm run lint
git add src/ assets/ tests/e2e/capacity-gate.spec.js
git commit -m "Show who is over-booked, and offer the way through (#141, #143)"
```

---

## Task 7: Version, changelog, pull request

**Files:**
- Modify: `package.json`, `blueworx-forge.php`, `client/blueworx-forge-client.php`, `CHANGELOG.md`

- [ ] **Step 1: Bump the version**

Minor bump — this is new behaviour. From 2.34.0 to 2.35.0, in all three places. Both plugin headers must match or CI fails; `package-lock.json`'s own version field is deliberately left alone.

- [ ] **Step 2: Write the changelog entry**

Under a new `## [2.35.0]` heading with today's date, in the voice the file already uses — what changed for the person using it, not what was built:

```markdown
### Added

- Work can no longer reach Up Next without a real plan behind it. Everything
  missing is named at once, capacity included, so a plan is fixed in one pass
  rather than refused six times.
- Over-booking somebody is now a decision rather than an accident. It is still
  allowed — the model does not know everything a manager knows — but it costs a
  reason, and the item says so afterwards.
- The plan is checked again before work starts. Weeks move between planning and
  starting, and a plan made against a picture that has since changed is now
  caught rather than assumed.
- A change to somebody's hours or leave now leaves a note on the work it
  affects, so a week that turns red can be traced to what turned it.
```

- [ ] **Step 3: Run every check**

```bash
npm run lint
npm run build
composer lint
vendor/bin/phpunit
npm run wp:up && npm test
npm run wp:pair:up && npm run test:pair
```

All must pass. Per the project rules, present lint findings rather than looping on them.

- [ ] **Step 4: Open the draft pull request**

```bash
git push -u origin capacity-enforcement
gh pr create --draft --base capacity-engine-and-views \
  --title "Refuse work there is no room for, unless somebody says why (#141, #142, #143, #144)" \
  --body "..."
```

Base it on `capacity-engine-and-views`, not `main` — that branch carries the engine this work calls and is still open as PR #233. If #233 has merged by then, rebase onto `main` and retarget.

The body says what it does and what needs deciding. Not a walkthrough.

---

## Self-Review Notes

**Spec coverage.** CAP-E1 → Task 1. CAP-E2 → Task 1 Step 6, `test_comfortable_overall_and_impossible_in_one_week_is_over_booked`. CAP-E3 → Task 3. CAP-E4 → Task 4. CAP-E5 → Task 5. CAP-E6 → nothing to build, which is the decision; no task, correctly.

**Known gaps handed to the implementer, deliberately:** the entry gate name in Task 4 (Step 1 resolves it), the exact shape of `Fields.php`'s lists in Task 3 Step 7, and the fixture details of the Playwright specs. Each is a read-first instruction rather than a placeholder, because the file must be read to be edited correctly and guessing its shape here would produce a wrong diff.

**Type consistency.** `Impact::assess()` and `Impact::of()` return the same shape throughout. `$context['capacity']` is `array{over, reason}` in Tasks 2, 3 and 4. `CapacityOverride::mark()` returns the two `capacity_override_*` keys used in Task 3 Step 8 and asserted in Task 4's tests.
