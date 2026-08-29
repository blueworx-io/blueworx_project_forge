# Onboarding: the checklist and its records — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A launch checklist the studio manages centrally and versions, fixed for a client the moment it is assigned, with every step's history attributable and permanent.

**Architecture:** Five new tables. A template version is global and, once published, frozen — editing one opens a draft that becomes the next version. Assigning a version to a site writes one real step row per template step there and then, so every step has somewhere to keep its own status and history from the moment it exists.

**Tech Stack:** PHP 7.4+, WordPress plugin, PHPUnit (no WordPress runtime — stubs in `tests/php/bootstrap.php`), Playwright.

**Spec:** `docs/superpowers/specs/2026-08-29-onboarding-checklist-and-records-design.md`

## Global Constraints

- **PHP coding standard:** WordPress Coding Standards, `composer lint`. Yoda conditions, `array()` not `[]`, tabs, full docblocks on every class, method and constant.
- **Namespace:** `Blueworx\Forge\Onboarding`. Text domain `blueworx-forge`.
- **`declare( strict_types = 1 );` on every new PHP file**, after the file docblock.
- **Comments argue for decisions and cite the issue or decision id.** Match `includes/Capacity/Allocations.php` for register.
- **Ids** come from `Blueworx\Forge\Tenancy\Ids::create( PREFIX )`, each class declaring its own three-letter `PREFIX` constant. New prefixes: `otv` template version, `ots` template step, `sob` site onboarding, `obs` onboarding step, `obe` onboarding step event. None collide with the sixteen already in use.
- **Schema:** every new column is `NOT NULL` with a default. `dbDelta` adds columns by `ALTER TABLE`, and a `NOT NULL` column with no default fails on an existing table. Bump `Schema::VERSION` once, in the task that adds the tables.
- **No credential column exists anywhere in these tables.** ONB-3 is enforced by there being nowhere to put one. A task that adds a field for a password, key, token or card number is wrong.
- **Version bump and changelog** on the PR. Minor. Both plugin headers plus `package.json` must agree.

---

## File Structure

**Created:**

- `includes/Onboarding/Sections.php` — the three sections, and what a section is called on screen. A closed list, like `Work\Stages`.
- `includes/Onboarding/Statuses.php` — the seven recorded statuses, plus the rule that decides overdue. Overdue lives here rather than on the step so the question is answered in one place.
- `includes/Onboarding/Templates.php` — template versions: create a draft, read, publish, and the refusal that makes a published version permanent.
- `includes/Onboarding/TemplateSteps.php` — the step definitions inside a version.
- `includes/Onboarding/Assignment.php` — giving a site a version, and writing its steps.
- `includes/Onboarding/Steps.php` — the live step record.
- `includes/Onboarding/StepEvents.php` — a step's history.
- `includes/Onboarding/Version1.php` — the seed definition for version 1.
- `tests/php/OnboardingSectionsTest.php`, `OnboardingStatusesTest.php`, `OnboardingTemplatesTest.php`, `OnboardingAssignmentTest.php`

**Modified:**

- `includes/Data/Schema.php` — five tables, `VERSION` 12 → 13.
- `includes/Plugin.php` — seed version 1 on activation.

Deliberately **not** created in this part: a REST controller, and any React component. Nothing outside the studio reads these yet; part two adds the client's page and the studio's review, and giving them routes now would mean guessing at their shape.

---

## Task 1: The two closed lists

**Files:**
- Create: `includes/Onboarding/Sections.php`, `includes/Onboarding/Statuses.php`
- Test: `tests/php/OnboardingSectionsTest.php`, `tests/php/OnboardingStatusesTest.php`

**Interfaces:**
- Produces:
  - `Sections::FOUNDATIONS` / `BUILD_REVIEWS` / `LAUNCH` (string constants `'foundations'`, `'build-reviews'`, `'launch'`)
  - `Sections::ALL` (array, in order), `Sections::exists( string $section ): bool`, `Sections::label( string $section ): string`
  - `Statuses::NOT_STARTED` / `IN_PROGRESS` / `SUBMITTED` / `RETURNED` / `APPROVED` / `NOT_APPLICABLE` / `BLOCKED`
  - `Statuses::ALL` (array), `Statuses::exists( string $status ): bool`
  - `Statuses::SETTLED` (array: approved and not applicable — the two that end a step)
  - `Statuses::is_overdue( string $status, string $due_on, string $today ): bool`

### Why overdue is a function and not a constant

The data model lists eight statuses. Seven are recorded — somebody puts the step into them. Overdue is the eighth and is not like the others: it is a fact about today's date, not about the step. Stored, it would need something sweeping every step nightly and would be wrong in between. So `Statuses::ALL` has seven, and overdue is asked.

- [ ] **Step 1: Write the failing tests**

Create `tests/php/OnboardingSectionsTest.php`:

```php
<?php
/**
 * The three sections every onboarding step belongs to.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Onboarding\Sections;
use PHPUnit\Framework\TestCase;

/**
 * ONB-1 (#159). Three sections, in the order somebody works through them, so a
 * client has a sense of when their turn is rather than one flat list.
 */
final class OnboardingSectionsTest extends TestCase {

	public function test_there_are_three_sections_in_working_order(): void {
		$this->assertSame(
			array( Sections::FOUNDATIONS, Sections::BUILD_REVIEWS, Sections::LAUNCH ),
			Sections::ALL
		);
	}

	public function test_a_section_outside_the_list_does_not_exist(): void {
		$this->assertTrue( Sections::exists( Sections::LAUNCH ) );
		$this->assertFalse( Sections::exists( 'whatever' ) );
		$this->assertFalse( Sections::exists( '' ) );
	}

	public function test_every_section_reads_as_words(): void {
		foreach ( Sections::ALL as $section ) {
			$this->assertNotSame( '', Sections::label( $section ) );
			$this->assertNotSame( $section, Sections::label( $section ) );
		}
	}
}
```

Create `tests/php/OnboardingStatusesTest.php`:

```php
<?php
/**
 * What state an onboarding step is in, and what only looks like one.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Onboarding\Statuses;
use PHPUnit\Framework\TestCase;

/**
 * #161. Seven statuses somebody records, and overdue — which nobody records,
 * because it is a fact about today rather than about the step.
 */
final class OnboardingStatusesTest extends TestCase {

	public function test_seven_statuses_are_recorded(): void {
		$this->assertCount( 7, Statuses::ALL );
		$this->assertNotContains( 'overdue', Statuses::ALL );
	}

	public function test_a_status_outside_the_list_does_not_exist(): void {
		$this->assertTrue( Statuses::exists( Statuses::SUBMITTED ) );
		$this->assertFalse( Statuses::exists( 'overdue' ) );
		$this->assertFalse( Statuses::exists( '' ) );
	}

	public function test_a_step_past_its_date_is_overdue(): void {
		$this->assertTrue( Statuses::is_overdue( Statuses::IN_PROGRESS, '2026-09-01', '2026-09-02' ) );
	}

	public function test_a_step_on_its_date_is_not_yet_overdue(): void {
		// The day it is due is a day somebody still has.
		$this->assertFalse( Statuses::is_overdue( Statuses::IN_PROGRESS, '2026-09-01', '2026-09-01' ) );
	}

	public function test_finished_work_is_never_overdue(): void {
		// Approved late is late, but it is done, and a board that keeps
		// shouting about it is a board people stop reading.
		$this->assertFalse( Statuses::is_overdue( Statuses::APPROVED, '2026-09-01', '2026-12-01' ) );
		$this->assertFalse( Statuses::is_overdue( Statuses::NOT_APPLICABLE, '2026-09-01', '2026-12-01' ) );
	}

	public function test_a_step_with_no_date_is_never_overdue(): void {
		$this->assertFalse( Statuses::is_overdue( Statuses::IN_PROGRESS, '', '2026-12-01' ) );
	}

	public function test_blocked_work_can_still_be_overdue(): void {
		// Blocked says why it is not moving. It does not stop the date passing,
		// and a blocker nobody clears is exactly what the board should surface.
		$this->assertTrue( Statuses::is_overdue( Statuses::BLOCKED, '2026-09-01', '2026-09-02' ) );
	}
}
```

- [ ] **Step 2: Run them and watch them fail**

Run: `vendor/bin/phpunit --filter "OnboardingSectionsTest|OnboardingStatusesTest"`
Expected: FAIL — `Class "Blueworx\Forge\Onboarding\Sections" not found`.

- [ ] **Step 3: Write `Sections`**

Create `includes/Onboarding/Sections.php`:

```php
<?php
/**
 * The three parts of getting a site launched.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Onboarding;

/**
 * ONB-1 (#159). Every step belongs to exactly one of three sections.
 *
 * Sections rather than a flat list, because a client looking at forty
 * outstanding things has no idea which are theirs to do now and which are
 * months away. Three rather than twelve, because the twelve categories are what
 * the steps are about and the sections are when they happen — grouping by
 * category would tell somebody what a step concerns and not when their turn is.
 */
final class Sections {

	/**
	 * Getting the ground ready: access, accounts, the things everything else
	 * needs before it can start.
	 */
	public const FOUNDATIONS = 'foundations';

	/**
	 * The client seeing the work and saying what they think, while it is still
	 * cheap to change.
	 */
	public const BUILD_REVIEWS = 'build-reviews';

	/**
	 * Going live, and everything that has to be true first.
	 */
	public const LAUNCH = 'launch';

	/**
	 * Every section, in the order they happen.
	 *
	 * The order is the point: it is what a checklist screen renders down, and
	 * what tells somebody how far along they are.
	 *
	 * @var array<int, string>
	 */
	public const ALL = array(
		self::FOUNDATIONS,
		self::BUILD_REVIEWS,
		self::LAUNCH,
	);

	/**
	 * How each section reads on screen.
	 *
	 * @var array<string, string>
	 */
	private const LABELS = array(
		self::FOUNDATIONS   => 'Foundations',
		self::BUILD_REVIEWS => 'Build reviews',
		self::LAUNCH        => 'Launch',
	);

	/**
	 * Whether this is one of the three.
	 *
	 * @param string $section Section name.
	 * @return bool
	 */
	public static function exists( string $section ): bool {
		return in_array( $section, self::ALL, true );
	}

	/**
	 * What to call a section on screen.
	 *
	 * @param string $section Section name.
	 * @return string Empty when it is not a section.
	 */
	public static function label( string $section ): string {
		return self::LABELS[ $section ] ?? '';
	}
}
```

- [ ] **Step 4: Write `Statuses`**

Create `includes/Onboarding/Statuses.php`:

```php
<?php
/**
 * What state an onboarding step is in.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Onboarding;

/**
 * #161's statuses, and the one that is not a status.
 *
 * The data model lists eight. Seven of them are recorded: somebody moves the
 * step into them, and the move is an entry in its history. **Overdue is the
 * eighth and is not stored**, because it is a fact about today's date rather
 * than about the step. A stored one would need something sweeping every step
 * every night, and would be wrong for up to a day at a time — which is the same
 * mistake #164 exists to stop being made about completion.
 *
 * So it is asked instead, here, in the one place, so the client's page and the
 * studio's board cannot disagree about whether the same step is late.
 */
final class Statuses {

	/**
	 * Nobody has begun it.
	 */
	public const NOT_STARTED = 'not-started';

	/**
	 * Somebody is doing it.
	 */
	public const IN_PROGRESS = 'in-progress';

	/**
	 * Handed over, waiting to be looked at.
	 */
	public const SUBMITTED = 'submitted';

	/**
	 * Looked at, and sent back with something to change (ONB-2).
	 */
	public const RETURNED = 'returned';

	/**
	 * Done, and somebody said so (ONB-2).
	 */
	public const APPROVED = 'approved';

	/**
	 * It does not apply to this client, decided with a reason where the
	 * template permits it (ONB-2).
	 */
	public const NOT_APPLICABLE = 'not-applicable';

	/**
	 * Stopped on something outside the owner's control, with a reason.
	 */
	public const BLOCKED = 'blocked';

	/**
	 * Every status a step can be put into.
	 *
	 * @var array<int, string>
	 */
	public const ALL = array(
		self::NOT_STARTED,
		self::IN_PROGRESS,
		self::SUBMITTED,
		self::RETURNED,
		self::APPROVED,
		self::NOT_APPLICABLE,
		self::BLOCKED,
	);

	/**
	 * The two that finish a step.
	 *
	 * Both mean there is nothing left to do, and neither counts against
	 * anybody afterwards. Kept as a list rather than two comparisons because
	 * completion (#164) and the launch gate (#166) both ask the same question
	 * and must ask it the same way.
	 *
	 * @var array<int, string>
	 */
	public const SETTLED = array(
		self::APPROVED,
		self::NOT_APPLICABLE,
	);

	/**
	 * Whether this is one of the seven.
	 *
	 * @param string $status Status name.
	 * @return bool
	 */
	public static function exists( string $status ): bool {
		return in_array( $status, self::ALL, true );
	}

	/**
	 * Whether a step is late.
	 *
	 * Late is "the day has been and gone", not "the day is today" — somebody
	 * with a step due today still has today. Finished work is never late,
	 * however long it took: it is done, and a board still shouting about it is
	 * a board people stop reading. Blocked work still goes overdue, because a
	 * blocker nobody has cleared is the thing worth surfacing.
	 *
	 * @param string $status Where the step is.
	 * @param string $due_on YYYY-MM-DD, or '' where it has no date.
	 * @param string $today  YYYY-MM-DD.
	 * @return bool
	 */
	public static function is_overdue( string $status, string $due_on, string $today ): bool {
		if ( '' === $due_on || in_array( $status, self::SETTLED, true ) ) {
			return false;
		}

		return $due_on < $today;
	}
}
```

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/phpunit --filter "OnboardingSectionsTest|OnboardingStatusesTest"`
Expected: PASS.

- [ ] **Step 6: Lint and commit**

```bash
vendor/bin/phpcs includes/Onboarding/
git add includes/Onboarding tests/php/OnboardingSectionsTest.php tests/php/OnboardingStatusesTest.php
git commit -m "Name the three sections and the seven states a step can be in (#161)"
```

---

## Task 2: The tables

**Files:**
- Modify: `includes/Data/Schema.php`
- Test: `tests/php/SchemaTest.php` (add a case)

**Interfaces:**
- Produces: `Schema::onboarding_templates_table()`, `onboarding_template_steps_table()`, `site_onboarding_table()`, `onboarding_steps_table()`, `onboarding_step_events_table()` — each `(): string`.

- [ ] **Step 1: Read how the existing table accessors are written**

Run: `sed -n '170,190p' includes/Data/Schema.php`
Each is a one-line static returning `$wpdb->prefix . 'bwx_forge_<name>'` with a docblock. Follow it exactly.

- [ ] **Step 2: Add the five accessors**

In `includes/Data/Schema.php`, after `unavailability_table()`:

```php
	/**
	 * Onboarding template versions (#159).
	 *
	 * Global, and the only table here that is. A template is the studio's, not
	 * a client's — every site is assigned one of these, and none of them owns
	 * it. So there is deliberately no client or site column, and every reader
	 * of this table is crossing the tenant boundary knowingly.
	 *
	 * @return string
	 */
	public static function onboarding_templates_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'bwx_forge_onboarding_templates';
	}

	/**
	 * The step definitions inside a template version (#159).
	 *
	 * @return string
	 */
	public static function onboarding_template_steps_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'bwx_forge_onboarding_template_steps';
	}

	/**
	 * A site's onboarding: which version it was given, and when (#160).
	 *
	 * @return string
	 */
	public static function site_onboarding_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'bwx_forge_site_onboarding';
	}

	/**
	 * A site's live steps (#161).
	 *
	 * @return string
	 */
	public static function onboarding_steps_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'bwx_forge_onboarding_steps';
	}

	/**
	 * What happened to a step, and who did it (#161).
	 *
	 * @return string
	 */
	public static function onboarding_step_events_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'bwx_forge_onboarding_step_events';
	}
```

- [ ] **Step 3: Bump the schema version**

In `includes/Data/Schema.php`: `public const VERSION = 13;`

- [ ] **Step 4: Add the five CREATE statements**

In the array returned by the tables method, after `$unavailable`, first adding the five local variables alongside the existing ones at the top of that method following its established shape:

```php
			/*
			 * #159, ONB-1. One version of the checklist.
			 *
			 * A version is draft or published, and **published is for ever**.
			 * That is what makes a site's assignment (#160) worth anything: a
			 * client's checklist points at a version, and a version that could
			 * be edited underneath them would make the pointer a lie. Editing a
			 * published version opens a draft copy which becomes the next one.
			 */
			$templates      => "CREATE TABLE {$templates} (
	id varchar(32) NOT NULL,
	version smallint(5) unsigned NOT NULL DEFAULT 0,
	name varchar(191) NOT NULL DEFAULT '',
	status varchar(20) NOT NULL DEFAULT 'draft',
	published_at bigint(20) unsigned NOT NULL DEFAULT 0,
	published_by bigint(20) unsigned NOT NULL DEFAULT 0,
	created_at bigint(20) unsigned NOT NULL DEFAULT 0,
	created_by bigint(20) unsigned NOT NULL DEFAULT 0,
	PRIMARY KEY  (id),
	KEY status_version (status, version)
) {$collate};",

			/*
			 * #159. One step, as the template defines it.
			 *
			 * `depends_on` names template steps within the same version, comma
			 * separated. A join table would be tidier and would buy nothing: a
			 * version is immutable once published, so these references can
			 * never dangle, and nothing queries backwards from a dependency.
			 */
			$template_steps => "CREATE TABLE {$template_steps} (
	id varchar(32) NOT NULL,
	template_id varchar(32) NOT NULL,
	section varchar(20) NOT NULL DEFAULT 'foundations',
	category varchar(100) NOT NULL DEFAULT '',
	title varchar(191) NOT NULL DEFAULT '',
	description text NOT NULL,
	owner_side varchar(10) NOT NULL DEFAULT 'client',
	optional tinyint(1) NOT NULL DEFAULT 0,
	launch_critical tinyint(1) NOT NULL DEFAULT 0,
	allows_not_applicable tinyint(1) NOT NULL DEFAULT 0,
	depends_on text NOT NULL,
	position smallint(5) unsigned NOT NULL DEFAULT 0,
	PRIMARY KEY  (id),
	KEY template_position (template_id, position)
) {$collate};",

			/*
			 * #160. Which version a site was given.
			 *
			 * The version is recorded here as well as being copied into steps,
			 * so \"which checklist did this client actually get\" is still
			 * answerable long after their steps have diverged from it.
			 */
			$site_onboarding => \"CREATE TABLE {$site_onboarding} (
	id varchar(32) NOT NULL,
	client_site_id varchar(32) NOT NULL,
	client_id varchar(32) NOT NULL,
	template_id varchar(32) NOT NULL,
	template_version smallint(5) unsigned NOT NULL DEFAULT 0,
	assigned_at bigint(20) unsigned NOT NULL DEFAULT 0,
	assigned_by bigint(20) unsigned NOT NULL DEFAULT 0,
	PRIMARY KEY  (id),
	UNIQUE KEY one_per_site (client_site_id)
) {$collate};\",

			/*
			 * #161, ONB-3. A live step.
			 *
			 * **There is no credential column, and that is the enforcement.**
			 * ONB-3 says Forge stores who the provider is, which account, what
			 * access was asked for and whether it was verified — never the
			 * secret itself. A rule in a controller can be forgotten by the
			 * next caller; a column that does not exist cannot be written to.
			 *
			 * No `overdue` column either: it is worked out from `due_on`
			 * against today (Onboarding\\Statuses), because a stored one needs a
			 * nightly sweep and is wrong in between.
			 */
			$steps          => \"CREATE TABLE {$steps} (
	id varchar(32) NOT NULL,
	site_onboarding_id varchar(32) NOT NULL,
	client_site_id varchar(32) NOT NULL,
	template_step_id varchar(32) NOT NULL,
	section varchar(20) NOT NULL DEFAULT 'foundations',
	title varchar(191) NOT NULL DEFAULT '',
	status varchar(20) NOT NULL DEFAULT 'not-started',
	owner_side varchar(10) NOT NULL DEFAULT 'client',
	owner_id varchar(32) NOT NULL DEFAULT '',
	reviewer_id varchar(32) NOT NULL DEFAULT '',
	launch_critical tinyint(1) NOT NULL DEFAULT 0,
	optional tinyint(1) NOT NULL DEFAULT 0,
	allows_not_applicable tinyint(1) NOT NULL DEFAULT 0,
	due_on varchar(10) NOT NULL DEFAULT '',
	response text NOT NULL,
	provider varchar(191) NOT NULL DEFAULT '',
	account_identifier varchar(191) NOT NULL DEFAULT '',
	account_owner varchar(191) NOT NULL DEFAULT '',
	access_role varchar(100) NOT NULL DEFAULT '',
	invitation_status varchar(20) NOT NULL DEFAULT '',
	verification_outcome varchar(20) NOT NULL DEFAULT '',
	position smallint(5) unsigned NOT NULL DEFAULT 0,
	created_at bigint(20) unsigned NOT NULL DEFAULT 0,
	updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
	record_version bigint(20) unsigned NOT NULL DEFAULT 1,
	PRIMARY KEY  (id),
	KEY site_position (client_site_id, position),
	KEY site_status (client_site_id, status)
) {$collate};\",

			/*
			 * #161. What happened to a step.
			 *
			 * Its own table rather than the work changelog: an onboarding step
			 * is not a work item, has no cycle or review attempt, and putting
			 * it in the work events table would leave an `item_id` that
			 * sometimes means a work item and sometimes does not — which every
			 * reader of that table would then have to know about.
			 *
			 * Nothing here is ever edited or deleted. A correction is a further
			 * entry, as everywhere else in this product.
			 */
			$step_events    => \"CREATE TABLE {$step_events} (
	id varchar(32) NOT NULL,
	step_id varchar(32) NOT NULL,
	client_site_id varchar(32) NOT NULL,
	action varchar(30) NOT NULL DEFAULT '',
	from_status varchar(20) NOT NULL DEFAULT '',
	to_status varchar(20) NOT NULL DEFAULT '',
	reason text NOT NULL,
	actor bigint(20) unsigned NOT NULL DEFAULT 0,
	source_interface varchar(20) NOT NULL DEFAULT '',
	occurred_at bigint(20) unsigned NOT NULL DEFAULT 0,
	PRIMARY KEY  (id),
	KEY step_time (step_id, occurred_at)
) {$collate};\",
```

Note the escaped quotes above are an artefact of this document; write plain double quotes in the file, matching the surrounding statements exactly.

- [ ] **Step 5: Add the test**

In `tests/php/SchemaTest.php`, following whatever shape its existing cases use, assert that the five new tables appear in the schema and that the steps table has no column whose name contains `password`, `secret`, `token`, `credential` or `api_key`. That second assertion is the one that matters — it is ONB-3 written as a test rather than as a comment.

- [ ] **Step 6: Run everything, lint, commit**

```bash
vendor/bin/phpunit
vendor/bin/phpcs includes/Data/Schema.php
git add includes/Data/Schema.php tests/php/SchemaTest.php
git commit -m "Make room for the checklist, its versions and its history (#159, #160, #161)"
```

---

## Task 3: Template versions

**Files:**
- Create: `includes/Onboarding/Templates.php`, `includes/Onboarding/TemplateSteps.php`
- Test: `tests/php/OnboardingTemplatesTest.php`

**Interfaces:**
- Consumes: `Sections` from Task 1, the tables from Task 2, `Tenancy\Ids::create()`, `Data\Formats::for_row()`.
- Produces:
  - `Templates::PREFIX` = `'otv'`, `TemplateSteps::PREFIX` = `'ots'`
  - `Templates::DRAFT` = `'draft'`, `Templates::PUBLISHED` = `'published'`
  - `Templates::create_draft( string $name, int $author, string $from_id = '' ): ?array`
  - `Templates::publish( string $id, int $actor ): ?array`
  - `Templates::get( string $id ): ?array`
  - `Templates::current(): ?array` — the highest published version
  - `Templates::may_edit( array $template ): bool`
  - `TemplateSteps::add( string $template_id, array $values ): ?array`
  - `TemplateSteps::for_template( string $template_id ): array` — ordered by `position`

- [ ] **Step 1: Write the failing tests**

Create `tests/php/OnboardingTemplatesTest.php`. These are the pure rules, so they take no database — test the ones that can be stated without one:

```php
<?php
/**
 * When a checklist may change, and when it may never change again.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Onboarding\Templates;
use PHPUnit\Framework\TestCase;

/**
 * ONB-E2 (#159). A published version is frozen, and that is the whole reason a
 * client's assignment (#160) means anything — a checklist that could be edited
 * underneath somebody part-way through is not a checklist.
 */
final class OnboardingTemplatesTest extends TestCase {

	public function test_a_draft_may_be_edited(): void {
		$this->assertTrue( Templates::may_edit( array( 'status' => Templates::DRAFT ) ) );
	}

	public function test_a_published_version_may_never_be_edited_again(): void {
		$this->assertFalse( Templates::may_edit( array( 'status' => Templates::PUBLISHED ) ) );
	}

	public function test_something_that_is_not_a_template_may_not_be_edited(): void {
		$this->assertFalse( Templates::may_edit( array() ) );
		$this->assertFalse( Templates::may_edit( array( 'status' => 'whatever' ) ) );
	}

	public function test_the_next_version_follows_the_highest_there_is(): void {
		$this->assertSame( 1, Templates::next_version( 0 ) );
		$this->assertSame( 3, Templates::next_version( 2 ) );
	}
}
```

- [ ] **Step 2: Run and watch it fail**

Run: `vendor/bin/phpunit --filter OnboardingTemplatesTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write `Templates`**

Create `includes/Onboarding/Templates.php` with the constants and methods in the Interfaces block. `may_edit()` and `next_version()` are pure and hold the rules; `create_draft()`, `publish()`, `get()` and `current()` do the `$wpdb` work, following `includes/Capacity/Patterns.php` for the insert-and-hydrate shape and its `phpcs:ignore` comments for direct queries.

`create_draft( $name, $author, $from_id )` with a `$from_id` copies that version's steps into the new draft — which is how editing a published version works, per ONB-E2. Without one it starts empty.

`publish()` refuses a template that is already published, sets `version` to `next_version( highest published )`, stamps `published_at` and `published_by`.

The class docblock must argue ONB-E2: published is frozen, editing opens a draft, and that is what makes a site's snapshot trustworthy without copying the definition defensively.

- [ ] **Step 4: Write `TemplateSteps`**

Create `includes/Onboarding/TemplateSteps.php`. `add()` refuses a section that is not one of the three (`Sections::exists`) and refuses to add to a published template (`Templates::may_edit`), because a step added to a frozen version would be exactly the drift ONB-E2 forbids. `for_template()` reads them ordered by `position`.

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/phpunit --filter OnboardingTemplatesTest`
Expected: PASS.

- [ ] **Step 6: Run everything, lint, commit**

```bash
vendor/bin/phpunit
vendor/bin/phpcs includes/Onboarding/
git add includes/Onboarding tests/php/OnboardingTemplatesTest.php
git commit -m "Version the checklist, and freeze a version once it is published (#159)"
```

---

## Task 4: Assignment, and the steps it writes

**Files:**
- Create: `includes/Onboarding/Assignment.php`, `includes/Onboarding/Steps.php`, `includes/Onboarding/StepEvents.php`
- Test: `tests/php/OnboardingAssignmentTest.php`

**Interfaces:**
- Consumes: everything from Tasks 1–3.
- Produces:
  - `Assignment::PREFIX` = `'sob'`, `Steps::PREFIX` = `'obs'`, `StepEvents::PREFIX` = `'obe'`
  - `Assignment::assign( string $client_site_id, string $client_id, string $template_id, int $actor ): ?array`
  - `Assignment::for_site( string $client_site_id ): ?array`
  - `Assignment::step_from( array $template_step, array $onboarding ): array` — pure; the row one template step becomes
  - `Steps::for_site( string $client_site_id ): array`
  - `Steps::get( string $id ): ?array`
  - `StepEvents::append( array $entry ): bool`, `StepEvents::for_step( string $step_id ): array`

- [ ] **Step 1: Write the failing tests**

Create `tests/php/OnboardingAssignmentTest.php`, testing the pure translation — one template step becoming one live step — which is where the rules are:

```php
<?php
/**
 * What a client's checklist is made of, and when it is fixed.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Onboarding\Assignment;
use Blueworx\Forge\Onboarding\Statuses;
use PHPUnit\Framework\TestCase;

/**
 * ONB-E3 (#160). Assigning a version writes real step rows there and then,
 * carrying what the template said at that moment. A later template edit has
 * nothing to reach.
 */
final class OnboardingAssignmentTest extends TestCase {

	/**
	 * A template step as the definition holds it.
	 *
	 * @param array<string, mixed> $overrides Anything to change.
	 * @return array<string, mixed>
	 */
	private function template_step( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'                    => 'ots_one',
				'section'               => 'foundations',
				'title'                 => 'Domain and DNS',
				'owner_side'            => 'client',
				'optional'              => 0,
				'launch_critical'       => 1,
				'allows_not_applicable' => 0,
				'position'              => 3,
			),
			$overrides
		);
	}

	/**
	 * A site's onboarding record.
	 *
	 * @return array<string, mixed>
	 */
	private function onboarding(): array {
		return array(
			'id'             => 'sob_one',
			'client_site_id' => 'cst_one',
		);
	}

	public function test_a_new_step_starts_where_nobody_has_touched_it(): void {
		$step = Assignment::step_from( $this->template_step(), $this->onboarding() );

		$this->assertSame( Statuses::NOT_STARTED, $step['status'] );
	}

	public function test_the_step_carries_what_the_template_said(): void {
		$step = Assignment::step_from( $this->template_step(), $this->onboarding() );

		$this->assertSame( 'Domain and DNS', $step['title'] );
		$this->assertSame( 'foundations', $step['section'] );
		$this->assertSame( 'client', $step['owner_side'] );
		$this->assertSame( 1, $step['launch_critical'] );
		$this->assertSame( 3, $step['position'] );
		$this->assertSame( 'ots_one', $step['template_step_id'] );
	}

	public function test_the_step_belongs_to_the_site_it_was_assigned_to(): void {
		$step = Assignment::step_from( $this->template_step(), $this->onboarding() );

		$this->assertSame( 'sob_one', $step['site_onboarding_id'] );
		$this->assertSame( 'cst_one', $step['client_site_id'] );
	}

	public function test_a_step_has_nowhere_to_put_a_credential(): void {
		// ONB-3, asserted rather than trusted. If a field for a secret ever
		// appears, this is what says so.
		$step = Assignment::step_from( $this->template_step(), $this->onboarding() );

		foreach ( array_keys( $step ) as $field ) {
			foreach ( array( 'password', 'secret', 'token', 'credential', 'api_key' ) as $forbidden ) {
				$this->assertStringNotContainsString( $forbidden, $field );
			}
		}
	}

	public function test_the_handover_fields_start_empty_rather_than_absent(): void {
		// ONB-3 stores what access was asked for and whether it was verified.
		// Present and blank, so the shape of a step never depends on how far
		// through it is.
		$step = Assignment::step_from( $this->template_step(), $this->onboarding() );

		foreach ( array( 'provider', 'account_identifier', 'access_role', 'invitation_status', 'verification_outcome' ) as $field ) {
			$this->assertArrayHasKey( $field, $step );
			$this->assertSame( '', $step[ $field ] );
		}
	}
}
```

- [ ] **Step 2: Run and watch it fail**

Run: `vendor/bin/phpunit --filter OnboardingAssignmentTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write `Steps` and `StepEvents`**

`Steps` reads and writes the live step rows. `StepEvents::append()` refuses an entry with no actor, exactly as `Work\GateRecords::complete()` does and for the same reason: a change with nobody's name on it is indistinguishable from no change, and #161 says every status change must be attributable.

- [ ] **Step 4: Write `Assignment`**

`step_from()` is pure and does the translation the tests above pin down. `assign()` writes the site onboarding row and then one step row per template step, and refuses a site that already has one (the unique key on `client_site_id` backs this up).

The class docblock must argue ONB-E3: rows now rather than a reference resolved later, because every step needs somewhere to keep its own status and history from the moment it exists, and a board cannot query what does not exist.

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/phpunit --filter OnboardingAssignmentTest`
Expected: PASS.

- [ ] **Step 6: Run everything, lint, commit**

```bash
vendor/bin/phpunit
vendor/bin/phpcs includes/Onboarding/
git add includes/Onboarding tests/php/OnboardingAssignmentTest.php
git commit -m "Fix a client's checklist the moment it is assigned (#160, #161)"
```

---

## Task 5: Version 1, seeded on activation

**Files:**
- Create: `includes/Onboarding/Version1.php`
- Modify: `includes/Plugin.php:77-81`

**Interfaces:**
- Consumes: `Templates`, `TemplateSteps`, `Sections` from Tasks 1 and 3.
- Produces: `Version1::seed(): bool` — true when it created it, false when one already existed.

### The open input

The twelve categories are in the brief's §11.2, which is not in this repository. Five are known from ONB-1 because they are launch-critical: domain and DNS, hosting, email and SMTP, legal and compliance, and review and launch. **The remaining seven must be supplied before this task is finished** — ask rather than invent them, because an invented checklist is worse than none: somebody would work through it believing it was the agreed one.

Build the seeding machinery and the five known categories. Leave the file's step list ending with a clearly marked comment naming what is missing, and do not fabricate the rest.

- [ ] **Step 1: Write `Version1`**

Create `includes/Onboarding/Version1.php`. It holds the definition as a plain array and a `seed()` that creates a draft, adds each step, and publishes it — reusing `Templates` and `TemplateSteps` rather than writing rows itself, so the seed cannot produce something the editor could not have.

`seed()` returns false without doing anything when `Templates::current()` already answers, so activation is safe to run repeatedly.

- [ ] **Step 2: Seed on activation**

In `includes/Plugin.php`, in `activate()`:

```php
	public function activate(): void {
		Data\Schema::maybe_upgrade();

		// A fresh install has a working checklist without anybody building one
		// (ONB-E1). It does nothing when a version already exists, so this is
		// safe on every activation rather than only the first.
		Onboarding\Version1::seed();

		Frontend::instance()->create_app_page();
		flush_rewrite_rules();
	}
```

- [ ] **Step 3: Verify against real WordPress**

```bash
npm run wp:down && npm run wp:up
```

Then confirm from the database that one published version exists with the seeded steps. A Playwright spec covering this belongs with the admin screen in the next task, since there is nothing to look at until then.

- [ ] **Step 4: Lint and commit**

```bash
vendor/bin/phpunit
vendor/bin/phpcs includes/
git add includes/
git commit -m "Ship a working checklist on a fresh install (#159)"
```

---

## Task 6: The template screen

**Files:**
- Create: `includes/Admin/OnboardingTemplatePage.php`
- Modify: wherever the existing admin pages are registered
- Test: `tests/e2e/onboarding-template.spec.js`

**Interfaces:**
- Consumes: `Templates`, `TemplateSteps`.

ARCH-7: this is configuration, so it is a plain WordPress admin page and not part of the React application. Read `includes/Admin/` first and follow whichever page is closest in shape — the availability screen is the most recent and the best model.

- [ ] **Step 1: Write the failing Playwright spec**

Create `tests/e2e/onboarding-template.spec.js`, following the shape of `tests/e2e/availability-screen.spec.js`. It must cover:

1. The seeded version 1 is listed, published, with its steps.
2. Opening it for editing produces a draft, and version 1 still reads as published.
3. Publishing the draft makes version 2, and version 1 is still there.
4. A published version offers no way to edit its steps in place.

Fill the body against the real page — do not leave the list above as the test.

- [ ] **Step 2: Run and watch it fail**

```bash
npm run wp:up
PLAYWRIGHT_BASE_URL=http://127.0.0.1:8892 WP_ADMIN_USER=admin WP_ADMIN_PASS=wptest-admin-pw npx playwright test tests/e2e/onboarding-template.spec.js --workers=1
```
Expected: FAIL — no such page.

- [ ] **Step 3: Build the page**

Read the version, list its steps by section, and offer editing only on a draft. Nonce-check every write, and capability-check it against the studio administrator — a template is studio configuration and no client role reaches it.

- [ ] **Step 4: Run the spec, then the suite**

```bash
npx playwright test tests/e2e/onboarding-template.spec.js --workers=1
npx playwright test --workers=1
```

Run the whole suite on its own. Never run two suites against one instance at once: they share a login, and the second run's sign-in invalidates the first's session, which fails tests that have nothing wrong with them.

- [ ] **Step 5: Confirm it visually**

`npm run dev` is the React app and will not show this. Look at the real admin page on the test instance and have the user confirm before committing.

- [ ] **Step 6: Lint and commit**

```bash
npm run lint
vendor/bin/phpcs includes/Admin/
git add includes/Admin tests/e2e/onboarding-template.spec.js
git commit -m "Let the studio read and version the checklist (#159)"
```

---

## Task 7: Version, changelog, pull request

- [ ] **Step 1: Bump the version**

Minor: 2.35.0 → 2.36.0, in `package.json`, `blueworx-forge.php` (header and `BWX_FORGE_VERSION`) and `client/blueworx-forge-client.php` (header and `BWX_FORGE_CLIENT_VERSION`). Leave `package-lock.json`'s own version field alone.

- [ ] **Step 2: Write the changelog entry**

Under `## [2.36.0]`, in the voice the file uses — what changed for the person using it:

```markdown
### Added

- A launch checklist, managed in one place and versioned. Correcting it is an
  edit rather than a release, and a client already working through theirs is
  never rewritten underneath them.
- Assigning a checklist fixes it. What a client sees is what they were given on
  the day, whatever the template does afterwards.
- Every change to a step records who made it and when, permanently.
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

Present lint findings rather than looping on them.

- [ ] **Step 4: Open the draft pull request**

Base `main`. Say what it does, and name the outstanding input: the seven category names still needed from the brief.

---

## Self-Review Notes

**Spec coverage.** ONB-E1 → Tasks 5 and 6. ONB-E2 → Task 3. ONB-E3 → Task 4. ONB-E4 → Task 1 (`Statuses::is_overdue`). ONB-E5 → Tasks 2 and 4 (`StepEvents`). The five tables → Task 2. The "no credential column" rule is asserted twice, in Task 2's schema test and Task 4's step test, deliberately: it is the one rule where a silent regression would matter most.

**Known open input, not a placeholder.** The seven unknown category names in Task 5. The machinery is fully specified and none of it changes with the list; the task says explicitly to ask rather than invent, because a fabricated checklist would be worked through as though it were the agreed one.

**Deliberately deferred to the implementer, with read-first instructions:** the exact shape of `SchemaTest`'s existing cases (Task 2 Step 5), which admin page to model (Task 6 Step 3), and the Playwright fixtures (Task 6 Step 1). Each must be read to be matched, and guessing here would produce a wrong diff.

**Type consistency.** `Assignment::step_from( array $template_step, array $onboarding ): array` returns the keys asserted in Task 4's tests and written by Task 2's `onboarding_steps` table. `Templates::may_edit()` and `next_version()` are used in Task 3's tests and by `TemplateSteps::add()`. `Statuses::SETTLED` is consumed by `is_overdue()` here and by #164 and #166 in part three.
