# Client and Client Site entities — implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Store Clients and Client Sites as first-class records, so every later
entity can scope to a site (ARCH-3).

**Architecture:** Two custom database tables behind a schema class that upgrades
on activation and on version change; two repositories that never delete and
always check the record version; two REST controllers following the existing
conventions; one plain WordPress admin screen to drive it.

**Tech Stack:** PHP 7.4+ / WordPress, `$wpdb` and `dbDelta`, PHPUnit 11 for
units without a WordPress runtime, Playwright against a real WordPress.

**Spec:** [`docs/superpowers/specs/2026-08-19-client-and-client-site-entities-design.md`](../specs/2026-08-19-client-and-client-site-entities-design.md)

**Issue:** #88. **Branch:** `m2/client-and-site-entities` (already created).

## Global Constraints

- Namespace `Blueworx\Forge`, PSR-4 under `includes/`, one class per file,
  `declare( strict_types = 1 );` at the top of every file.
- WordPress Coding Standards: `composer lint` must pass. Every class, method,
  property and file needs a docblock; use tabs.
- Every REST route registers through `Rest\Server::register_route()` and
  declares a `permission_callback`. Never call `register_rest_token()` or
  `register_rest_route()` directly.
- Write order inside every write endpoint: **replay, then version, then work**
  (`docs/architecture/rest-conventions.md`).
- Error codes use `Rest\Errors::rest()`, which prefixes `bwx_forge_`.
- Times come from `bwx_forge_now()`, never `time()`, so tests control the clock.
- No new npm or Composer dependency.
- Nothing is ever deleted: deactivation only (NOTIF-5).
- Unit tests run with no WordPress. Anything needing a database is a Playwright
  spec.
- Run once at the end, not in a loop: `npm run lint`, `npm run build`,
  `composer lint`, `vendor/bin/phpunit`, `npm test`.

## File structure

| File | Responsibility |
|---|---|
| `includes/Data/Schema.php` | Table definitions, schema version, `maybe_upgrade()`. |
| `includes/Tenancy/Ids.php` | Prefixed random record ids. |
| `includes/Tenancy/Validate.php` | Pure validation of client and site input. |
| `includes/Tenancy/Clients.php` | Client rows: create, read, update, deactivate. |
| `includes/Tenancy/ClientSites.php` | Client site rows, same shape, plus the client cascade. |
| `includes/Rest/ClientsController.php` | `/clients` routes. |
| `includes/Rest/ClientSitesController.php` | `/clients/{id}/sites` and `/client-sites/{id}` routes. |
| `includes/Admin/ClientsScreen.php` | Renders the Clients admin page. |
| `includes/Admin/ClientActions.php` | The screen's four `admin_post` handlers. |
| `includes/Plugin.php` (modify) | Run the schema upgrade; hook the new screen and actions. |
| `includes/Rest/Server.php` (modify) | Register the two new controllers. |
| `tests/php/SchemaTest.php` | Table definitions and upgrade decision. |
| `tests/php/TenancyValidateTest.php` | Every validation rule. |
| `tests/php/TenancyIdsTest.php` | Id shape and uniqueness. |
| `tests/php/TenancyNoDeleteTest.php` | Neither repository exposes a delete. |
| `tests/e2e/clients-rest.spec.js` | The endpoints against real WordPress. |
| `tests/e2e/clients-screen.spec.js` | The admin screen, walked as a person. |

---

### Task 1: Schema and tables

**Files:**
- Create: `includes/Data/Schema.php`
- Modify: `includes/Plugin.php`
- Test: `tests/php/SchemaTest.php`, `tests/e2e/activation.spec.js`

**Interfaces:**
- Consumes: nothing.
- Produces: `Blueworx\Forge\Data\Schema::VERSION` (int),
  `Schema::OPTION` (string), `Schema::clients_table(): string`,
  `Schema::sites_table(): string`, `Schema::definitions(): array<string,string>`,
  `Schema::maybe_upgrade(): void`, `Schema::needs_upgrade( ?int $installed ): bool`.

- [ ] **Step 1: Write the failing test**

`tests/php/SchemaTest.php`:

```php
<?php
/**
 * The tables, and when they are (re)built.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Data\Schema;
use PHPUnit\Framework\TestCase;

/**
 * A plugin updated in place never re-runs activation, so "create the tables on
 * activation" is not enough on its own. These tests pin the decision that says
 * so, and the columns every later milestone will index against.
 */
final class SchemaTest extends TestCase {

	/**
	 * A site that has never seen the plugin needs the tables.
	 */
	public function test_a_site_with_no_schema_needs_an_upgrade(): void {
		$this->assertTrue( Schema::needs_upgrade( null ) );
	}

	/**
	 * A site behind the code needs them again — this is the in-place update case.
	 */
	public function test_a_site_behind_the_code_needs_an_upgrade(): void {
		$this->assertTrue( Schema::needs_upgrade( Schema::VERSION - 1 ) );
	}

	/**
	 * A site already at the current version does no work on every page load.
	 */
	public function test_a_current_site_needs_nothing(): void {
		$this->assertFalse( Schema::needs_upgrade( Schema::VERSION ) );
	}

	/**
	 * Both tables are defined, and each carries the columns everything later
	 * depends on: the id, the author and time stamps, and the record version
	 * that ARCH-5 refuses stale writes against.
	 */
	public function test_both_tables_carry_the_common_columns(): void {
		$definitions = Schema::definitions();

		$this->assertCount( 2, $definitions );

		foreach ( $definitions as $sql ) {
			$this->assertStringContainsString( 'id varchar(32) NOT NULL', $sql );
			$this->assertStringContainsString( 'record_version', $sql );
			$this->assertStringContainsString( 'created_at', $sql );
			$this->assertStringContainsString( 'updated_at', $sql );
			$this->assertStringContainsString( 'created_by', $sql );
			$this->assertStringContainsString( 'PRIMARY KEY  (id)', $sql );
		}
	}

	/**
	 * A site row names its client, and that column is indexed: every scoped
	 * query in Milestone 2 and after reaches a site through its client.
	 */
	public function test_a_site_is_indexed_by_its_client(): void {
		$sites = Schema::definitions()[ Schema::sites_table() ];

		$this->assertStringContainsString( 'client_id varchar(32) NOT NULL', $sites );
		$this->assertStringContainsString( 'KEY client_id (client_id)', $sites );
	}
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `vendor/bin/phpunit --filter SchemaTest`
Expected: FAIL — `Class "Blueworx\Forge\Data\Schema" not found`.

- [ ] **Step 3: Write `includes/Data/Schema.php`**

```php
<?php
/**
 * The plugin's own tables.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Data;

/**
 * Forge holds canonical records (ARCH-2), so it owns tables rather than option
 * blobs: scoping, the hour ledger and every later report need columns, indexes
 * and a WHERE clause.
 *
 * The version below is the schema's, not the plugin's. It is bumped whenever a
 * definition here changes, and that bump is what makes an existing site rebuild
 * its tables — activation alone would not, because a plugin updated in place
 * never runs it.
 */
final class Schema {

	/**
	 * The schema's own version. Bump on any change to definitions().
	 */
	public const VERSION = 1;

	/**
	 * Option holding the version a site has actually built.
	 */
	public const OPTION = 'bwx_forge_schema_version';

	/**
	 * The clients table's full name.
	 *
	 * @return string
	 */
	public static function clients_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'bwx_forge_clients';
	}

	/**
	 * The client sites table's full name.
	 *
	 * @return string
	 */
	public static function sites_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'bwx_forge_client_sites';
	}

	/**
	 * Every table this plugin owns, as dbDelta-shaped CREATE statements.
	 *
	 * dbDelta is fussy in ways that are silent when broken: two spaces after
	 * PRIMARY KEY, one field per line, no backticks around field names. Changing
	 * the whitespace here changes whether an upgrade happens at all.
	 *
	 * @return array<string, string> Table name to statement.
	 */
	public static function definitions(): array {
		global $wpdb;

		$collate = $wpdb->get_charset_collate();

		$clients = self::clients_table();
		$sites   = self::sites_table();

		return array(
			$clients => "CREATE TABLE {$clients} (
	id varchar(32) NOT NULL,
	display_name varchar(191) NOT NULL,
	legal_name varchar(191) NOT NULL DEFAULT '',
	status varchar(20) NOT NULL DEFAULT 'active',
	timezone varchar(64) NOT NULL DEFAULT 'UTC',
	email_domains text NOT NULL,
	created_at bigint(20) unsigned NOT NULL DEFAULT 0,
	updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
	created_by bigint(20) unsigned NOT NULL DEFAULT 0,
	record_version int(11) unsigned NOT NULL DEFAULT 1,
	PRIMARY KEY  (id),
	KEY status (status)
) {$collate};",
			$sites   => "CREATE TABLE {$sites} (
	id varchar(32) NOT NULL,
	client_id varchar(32) NOT NULL,
	name varchar(191) NOT NULL,
	url varchar(255) NOT NULL DEFAULT '',
	status varchar(20) NOT NULL DEFAULT 'active',
	created_at bigint(20) unsigned NOT NULL DEFAULT 0,
	updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
	created_by bigint(20) unsigned NOT NULL DEFAULT 0,
	record_version int(11) unsigned NOT NULL DEFAULT 1,
	PRIMARY KEY  (id),
	KEY client_id (client_id),
	KEY status (status)
) {$collate};",
		);
	}

	/**
	 * Whether a site's built schema is behind the code's.
	 *
	 * @param int|null $installed The version a site has built, or null if none.
	 * @return bool
	 */
	public static function needs_upgrade( ?int $installed ): bool {
		return null === $installed || $installed < self::VERSION;
	}

	/**
	 * Builds or updates the tables when the site is behind.
	 *
	 * Safe to call on every request: the ordinary case is one option read.
	 */
	public static function maybe_upgrade(): void {
		$installed = get_option( self::OPTION, null );
		$installed = null === $installed ? null : (int) $installed;

		if ( ! self::needs_upgrade( $installed ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( self::definitions() as $sql ) {
			dbDelta( $sql );
		}

		update_option( self::OPTION, self::VERSION );
	}
}
```

- [ ] **Step 4: Run the test again**

Run: `vendor/bin/phpunit --filter SchemaTest`
Expected: PASS. `definitions()` reads `$wpdb`; add this to
`tests/php/bootstrap.php` if it is not already there, above the class stubs:

```php
/**
 * Stub of the two things this plugin's schema asks $wpdb for.
 */
class BWX_Forge_Test_Wpdb {

	/**
	 * Table prefix.
	 *
	 * @var string
	 */
	public string $prefix = 'wp_';

	/**
	 * Charset and collation clause.
	 *
	 * @return string
	 */
	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4';
	}
}

$GLOBALS['wpdb'] = new BWX_Forge_Test_Wpdb();
```

- [ ] **Step 5: Hook it up in `includes/Plugin.php`**

In `boot()`, as the first line:

```php
		Data\Schema::maybe_upgrade();
```

In `activate()`, before `create_app_page()`:

```php
		Data\Schema::maybe_upgrade();
```

- [ ] **Step 6: Prove the tables exist on a real WordPress**

Append to `tests/e2e/activation.spec.js`, following the file's existing style:

```javascript
test('activation builds the plugin tables', async ({ page }) => {
  await signIn(page);
  await page.goto('/wp-admin/admin.php?page=blueworx-forge-sites');

  // The screen only renders when the plugin booted; a fatal from a bad CREATE
  // would show here instead.
  await expect(page.locator('h1')).toContainText('Forge');
});
```

- [ ] **Step 7: Commit**

```bash
git add includes/Data/Schema.php includes/Plugin.php tests/php/SchemaTest.php tests/php/bootstrap.php tests/e2e/activation.spec.js
git commit -m "Add the plugin's own tables (#88)"
```

---

### Task 2: Ids and validation

**Files:**
- Create: `includes/Tenancy/Ids.php`, `includes/Tenancy/Validate.php`
- Test: `tests/php/TenancyIdsTest.php`, `tests/php/TenancyValidateTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `Tenancy\Ids::create( string $prefix ): string`;
  `Tenancy\Validate::STATUSES` (array), `Validate::client( array $input, bool $partial ): array`,
  `Validate::site( array $input, bool $partial ): array`. Both return
  `array{ values: array<string,mixed>, errors: array<string,string> }` — field
  name to message, empty when the input is good.

- [ ] **Step 1: Write the failing tests**

`tests/php/TenancyIdsTest.php`:

```php
<?php
/**
 * Record ids.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Tenancy\Ids;
use PHPUnit\Framework\TestCase;

/**
 * Ids are random rather than sequential for the reason Sites\Registry gives:
 * they appear in URLs and logs, and a sequential one advertises how many
 * clients exist and lets a caller guess the next.
 */
final class TenancyIdsTest extends TestCase {

	/**
	 * The shape: a prefix that says what the record is, then random hex.
	 */
	public function test_an_id_is_its_prefix_and_random_hex(): void {
		$this->assertMatchesRegularExpression( '/^cli_[0-9a-f]{16}$/', Ids::create( 'cli' ) );
	}

	/**
	 * Two ids never collide.
	 */
	public function test_ids_do_not_repeat(): void {
		$ids = array();

		for ( $i = 0; $i < 200; $i++ ) {
			$ids[] = Ids::create( 'cst' );
		}

		$this->assertCount( 200, array_unique( $ids ) );
	}
}
```

`tests/php/TenancyValidateTest.php`:

```php
<?php
/**
 * Client and client site input rules.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Tenancy\Validate;
use PHPUnit\Framework\TestCase;

/**
 * Validation is separated from storage so the rules can be proven without a
 * database — and so both doors into the data, REST and the admin screen, get
 * the same answer to the same input.
 */
final class TenancyValidateTest extends TestCase {

	/**
	 * The happy path, with the values cleaned.
	 */
	public function test_a_complete_client_is_accepted_and_trimmed(): void {
		$result = Validate::client(
			array(
				'display_name'  => '  Acme  ',
				'legal_name'    => 'Acme Limited',
				'timezone'      => 'Europe/London',
				'email_domains' => array( 'ACME.co.uk', 'acme.co.uk' ),
			),
			false
		);

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( 'Acme', $result['values']['display_name'] );
		$this->assertSame( 'Europe/London', $result['values']['timezone'] );
		// Lower-cased and de-duplicated: two spellings of one domain are one rule.
		$this->assertSame( array( 'acme.co.uk' ), $result['values']['email_domains'] );
		$this->assertSame( 'active', $result['values']['status'] );
	}

	/**
	 * A client with no name is refused: it is the only thing the studio has to
	 * call them by.
	 */
	public function test_a_client_without_a_display_name_is_refused(): void {
		$result = Validate::client( array( 'display_name' => '   ' ), false );

		$this->assertArrayHasKey( 'display_name', $result['errors'] );
	}

	/**
	 * An invented timezone is refused rather than stored and puzzled over later.
	 */
	public function test_an_unknown_timezone_is_refused(): void {
		$result = Validate::client(
			array(
				'display_name' => 'Acme',
				'timezone'     => 'Middle/Earth',
			),
			false
		);

		$this->assertArrayHasKey( 'timezone', $result['errors'] );
	}

	/**
	 * Permitted domains are domains. An email address in the field is the
	 * mistake this catches.
	 */
	public function test_a_malformed_email_domain_is_refused(): void {
		$result = Validate::client(
			array(
				'display_name'  => 'Acme',
				'email_domains' => array( 'someone@acme.co.uk' ),
			),
			false
		);

		$this->assertArrayHasKey( 'email_domains', $result['errors'] );
	}

	/**
	 * Status is a closed list. Deletion is not one of its values (NOTIF-5).
	 */
	public function test_an_unknown_status_is_refused(): void {
		$result = Validate::client(
			array(
				'display_name' => 'Acme',
				'status'       => 'deleted',
			),
			false
		);

		$this->assertArrayHasKey( 'status', $result['errors'] );
	}

	/**
	 * A partial edit says nothing about the fields it does not mention, so a
	 * required field missing from a PATCH is not an error.
	 */
	public function test_a_partial_client_edit_may_omit_everything(): void {
		$result = Validate::client( array( 'legal_name' => 'Acme Limited' ), true );

		$this->assertSame( array(), $result['errors'] );
		$this->assertArrayNotHasKey( 'display_name', $result['values'] );
	}

	/**
	 * A site needs a name and, if given, a real URL.
	 */
	public function test_a_site_needs_a_name_and_a_real_url(): void {
		$named = Validate::site( array( 'name' => 'Acme Main' ), false );
		$this->assertSame( array(), $named['errors'] );

		$unnamed = Validate::site( array( 'name' => '' ), false );
		$this->assertArrayHasKey( 'name', $unnamed['errors'] );

		$bad_url = Validate::site(
			array(
				'name' => 'Acme Main',
				'url'  => 'not a url',
			),
			false
		);
		$this->assertArrayHasKey( 'url', $bad_url['errors'] );
	}
}
```

- [ ] **Step 2: Run them and watch them fail**

Run: `vendor/bin/phpunit --filter "TenancyIdsTest|TenancyValidateTest"`
Expected: FAIL — both classes not found.

- [ ] **Step 3: Write `includes/Tenancy/Ids.php`**

```php
<?php
/**
 * Record ids.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Tenancy;

/**
 * One id shape for every canonical record: a short prefix saying what the
 * record is, then 64 bits of randomness.
 *
 * Random rather than sequential, for the reason Sites\Registry gives about site
 * ids: an id appears in URLs and logs, and a sequential one advertises how many
 * clients exist and lets a caller guess the next. The prefix is there so an id
 * in a log line or a support message says what it belongs to without a lookup.
 */
final class Ids {

	/**
	 * A new id under a prefix.
	 *
	 * @param string $prefix Short record-type prefix, e.g. 'cli'.
	 * @return string
	 */
	public static function create( string $prefix ): string {
		return $prefix . '_' . bin2hex( random_bytes( 8 ) );
	}
}
```

- [ ] **Step 4: Write `includes/Tenancy/Validate.php`**

```php
<?php
/**
 * Input rules for clients and client sites.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Tenancy;

/**
 * Plain PHP, no WordPress. Two doors reach these records — the REST routes and
 * the admin screen — and both have to refuse the same input for the same
 * reason, so the rules live in one place that can be tested without a site
 * around it.
 *
 * Every method answers the same shape: the cleaned values, and a map of field
 * name to message for anything refused. An empty error map means the input is
 * usable as it stands.
 */
final class Validate {

	/**
	 * The only statuses a record may hold. There is no deleted state: records
	 * are deactivated and kept, so history and reporting still resolve (NOTIF-5).
	 */
	public const STATUSES = array( 'active', 'inactive' );

	/**
	 * Longest a name may be, matching the varchar(191) it lands in.
	 */
	public const MAX_NAME = 191;

	/**
	 * Checks a client.
	 *
	 * @param array<string, mixed> $input   Raw input.
	 * @param bool                 $partial True for an edit, which may mention
	 *                                      only the fields it changes.
	 * @return array{values: array<string, mixed>, errors: array<string, string>}
	 */
	public static function client( array $input, bool $partial ): array {
		$values = array();
		$errors = array();

		if ( ! $partial || array_key_exists( 'display_name', $input ) ) {
			$name = trim( (string) ( $input['display_name'] ?? '' ) );

			if ( '' === $name ) {
				$errors['display_name'] = 'A client needs a name.';
			} elseif ( strlen( $name ) > self::MAX_NAME ) {
				$errors['display_name'] = 'That name is too long.';
			} else {
				$values['display_name'] = $name;
			}
		}

		if ( array_key_exists( 'legal_name', $input ) ) {
			$values['legal_name'] = trim( (string) $input['legal_name'] );
		}

		if ( array_key_exists( 'timezone', $input ) ) {
			$timezone = trim( (string) $input['timezone'] );

			if ( ! in_array( $timezone, timezone_identifiers_list(), true ) ) {
				$errors['timezone'] = 'That is not a timezone.';
			} else {
				$values['timezone'] = $timezone;
			}
		} elseif ( ! $partial ) {
			$values['timezone'] = 'UTC';
		}

		if ( array_key_exists( 'email_domains', $input ) ) {
			$domains = self::domains( $input['email_domains'] );

			if ( null === $domains ) {
				$errors['email_domains'] = 'Each permitted domain is a domain on its own, such as acme.co.uk.';
			} else {
				$values['email_domains'] = $domains;
			}
		} elseif ( ! $partial ) {
			$values['email_domains'] = array();
		}

		$status = self::status( $input, $partial );

		if ( null === $status['error'] ) {
			if ( null !== $status['value'] ) {
				$values['status'] = $status['value'];
			}
		} else {
			$errors['status'] = $status['error'];
		}

		return array(
			'values' => $values,
			'errors' => $errors,
		);
	}

	/**
	 * Checks a client site.
	 *
	 * @param array<string, mixed> $input   Raw input.
	 * @param bool                 $partial True for an edit.
	 * @return array{values: array<string, mixed>, errors: array<string, string>}
	 */
	public static function site( array $input, bool $partial ): array {
		$values = array();
		$errors = array();

		if ( ! $partial || array_key_exists( 'name', $input ) ) {
			$name = trim( (string) ( $input['name'] ?? '' ) );

			if ( '' === $name ) {
				$errors['name'] = 'A site needs a name.';
			} elseif ( strlen( $name ) > self::MAX_NAME ) {
				$errors['name'] = 'That name is too long.';
			} else {
				$values['name'] = $name;
			}
		}

		if ( array_key_exists( 'url', $input ) ) {
			$url = trim( (string) $input['url'] );

			if ( '' !== $url && ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
				$errors['url'] = 'That is not a web address.';
			} else {
				$values['url'] = $url;
			}
		} elseif ( ! $partial ) {
			$values['url'] = '';
		}

		$status = self::status( $input, $partial );

		if ( null === $status['error'] ) {
			if ( null !== $status['value'] ) {
				$values['status'] = $status['value'];
			}
		} else {
			$errors['status'] = $status['error'];
		}

		return array(
			'values' => $values,
			'errors' => $errors,
		);
	}

	/**
	 * The status rule, which is the same for both records.
	 *
	 * @param array<string, mixed> $input   Raw input.
	 * @param bool                 $partial True for an edit.
	 * @return array{value: string|null, error: string|null}
	 */
	private static function status( array $input, bool $partial ): array {
		if ( ! array_key_exists( 'status', $input ) ) {
			return array(
				'value' => $partial ? null : 'active',
				'error' => null,
			);
		}

		$status = trim( (string) $input['status'] );

		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return array(
				'value' => null,
				'error' => 'A record is either active or inactive.',
			);
		}

		return array(
			'value' => $status,
			'error' => null,
		);
	}

	/**
	 * Cleans a list of permitted email domains.
	 *
	 * Lower-cased and de-duplicated, because two spellings of one domain are one
	 * rule and storing both would let a later membership check pass on the
	 * spelling nobody remembered to remove.
	 *
	 * @param mixed $raw Whatever arrived in the field.
	 * @return array<int, string>|null Null when any entry is not a domain.
	 */
	private static function domains( $raw ): ?array {
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
		}

		if ( ! is_array( $raw ) ) {
			return null;
		}

		$clean = array();

		foreach ( $raw as $entry ) {
			$domain = strtolower( trim( (string) $entry ) );

			if ( '' === $domain ) {
				continue;
			}

			if ( 1 !== preg_match( '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $domain ) ) {
				return null;
			}

			$clean[] = $domain;
		}

		return array_values( array_unique( $clean ) );
	}
}
```

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/phpunit --filter "TenancyIdsTest|TenancyValidateTest"`
Expected: PASS, all ten.

- [ ] **Step 6: Commit**

```bash
git add includes/Tenancy/Ids.php includes/Tenancy/Validate.php tests/php/TenancyIdsTest.php tests/php/TenancyValidateTest.php
git commit -m "Add record ids and tenancy validation rules (#88)"
```

---

### Task 3: The two repositories

**Files:**
- Create: `includes/Tenancy/Clients.php`, `includes/Tenancy/ClientSites.php`
- Test: `tests/php/TenancyNoDeleteTest.php`

**Interfaces:**
- Consumes: `Data\Schema`, `Tenancy\Ids`, `bwx_forge_now()`.
- Produces:
  - `Clients::PREFIX = 'cli'`, `ClientSites::PREFIX = 'cst'`
  - `Clients::create( array $values, int $author ): array` — the stored row
  - `Clients::get( string $id ): ?array`
  - `Clients::all( ?string $status = 'active' ): array<int, array>`
  - `Clients::update( string $id, array $values, int $sent_version ): ?array` —
    null when the version did not match or the row is gone
  - `Clients::deactivate( string $id, int $sent_version ): ?array`
  - `ClientSites::create( string $client_id, array $values, int $author ): array`
  - `ClientSites::get( string $id ): ?array`
  - `ClientSites::for_client( string $client_id, ?string $status = 'active' ): array<int, array>`
  - `ClientSites::update( string $id, array $values, int $sent_version ): ?array`
  - `ClientSites::deactivate( string $id, int $sent_version ): ?array`
  - `ClientSites::deactivate_for_client( string $client_id ): int` — rows changed

  Every returned row has string keys matching the columns, with `email_domains`
  decoded to an array and `record_version`, `created_at`, `updated_at`,
  `created_by` as ints.

- [ ] **Step 1: Write the failing test**

`tests/php/TenancyNoDeleteTest.php`:

```php
<?php
/**
 * Deactivation, never deletion.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Tenancy\ClientSites;
use Blueworx\Forge\Tenancy\Clients;
use PHPUnit\Framework\TestCase;

/**
 * NOTIF-5. A deleted client takes its sites', its work's and its ledger's
 * meaning with it, so there is no delete to call by accident — this asserts the
 * absence rather than trusting a comment saying so.
 *
 * The rest of these repositories talk to a database and are proven in the
 * Playwright suite, where there is one.
 */
final class TenancyNoDeleteTest extends TestCase {

	/**
	 * Neither repository exposes anything that removes a row.
	 */
	public function test_neither_repository_can_delete(): void {
		foreach ( array( Clients::class, ClientSites::class ) as $class ) {
			$methods = get_class_methods( $class );

			foreach ( array( 'delete', 'remove', 'drop', 'purge' ) as $forbidden ) {
				$this->assertNotContains( $forbidden, $methods, $class . ' must not be able to delete a record.' );
			}
		}

		$this->assertContains( 'deactivate', get_class_methods( Clients::class ) );
		$this->assertContains( 'deactivate', get_class_methods( ClientSites::class ) );
	}
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `vendor/bin/phpunit --filter TenancyNoDeleteTest`
Expected: FAIL — classes not found.

- [ ] **Step 3: Write `includes/Tenancy/Clients.php`**

```php
<?php
/**
 * Client records.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Tenancy;

use Blueworx\Forge\Data\Schema;

/**
 * The client: identity, people and memberships (ARCH-3). It groups sites and
 * owns nothing that is scoped — no work, no hours, no packages, no onboarding.
 * Those belong to the site beneath it, and a field for one of them appearing
 * here later is the mistake ARCH-3 exists to prevent.
 *
 * Two rules live here rather than in callers. There is no delete: a client is
 * deactivated and kept (NOTIF-5). And every update quotes the version it was
 * made against, refused in the UPDATE's own WHERE so two writes racing cannot
 * both believe they were current (ARCH-5).
 */
final class Clients {

	/**
	 * Id prefix for a client.
	 */
	public const PREFIX = 'cli';

	/**
	 * Stores a new client.
	 *
	 * @param array<string, mixed> $values Validated values.
	 * @param int                  $author WordPress user id of the author.
	 * @return array<string, mixed> The stored row.
	 */
	public static function create( array $values, int $author ): array {
		global $wpdb;

		$now = bwx_forge_now();
		$id  = Ids::create( self::PREFIX );

		$row = array(
			'id'             => $id,
			'display_name'   => (string) ( $values['display_name'] ?? '' ),
			'legal_name'     => (string) ( $values['legal_name'] ?? '' ),
			'status'         => (string) ( $values['status'] ?? 'active' ),
			'timezone'       => (string) ( $values['timezone'] ?? 'UTC' ),
			'email_domains'  => wp_json_encode( $values['email_domains'] ?? array() ),
			'created_at'     => $now,
			'updated_at'     => $now,
			'created_by'     => $author,
			'record_version' => 1,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; there is no core API for it.
		$wpdb->insert( Schema::clients_table(), $row );

		return self::hydrate( $row );
	}

	/**
	 * One client.
	 *
	 * @param string $id Client id.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $id ): ?array {
		global $wpdb;

		$table = Schema::clients_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %s", $id ), ARRAY_A );

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Every client, newest first.
	 *
	 * @param string|null $status Status to filter by, or null for all of them.
	 * @return array<int, array<string, mixed>>
	 */
	public static function all( ?string $status = 'active' ): array {
		global $wpdb;

		$table = Schema::clients_table();

		if ( null === $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
			$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC", ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY created_at DESC", $status ), ARRAY_A );
		}

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Applies an edit, refusing one made against a version that has moved.
	 *
	 * @param string               $id           Client id.
	 * @param array<string, mixed> $values       Validated values.
	 * @param int                  $sent_version Version the edit was made against.
	 * @return array<string, mixed>|null Null when the version did not match, or
	 *                                   there is no such client.
	 */
	public static function update( string $id, array $values, int $sent_version ): ?array {
		global $wpdb;

		$changes = self::writable( $values );

		$changes['updated_at']     = bwx_forge_now();
		$changes['record_version'] = $sent_version + 1;

		/*
		 * The version is in the WHERE, not checked and then written: two writes
		 * arriving together would both read the same current version and both
		 * believe themselves current. Here the second changes no rows.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$changed = $wpdb->update(
			Schema::clients_table(),
			$changes,
			array(
				'id'             => $id,
				'record_version' => $sent_version,
			)
		);

		if ( ! $changed ) {
			return null;
		}

		return self::get( $id );
	}

	/**
	 * Deactivates a client, and every site under it.
	 *
	 * @param string $id           Client id.
	 * @param int    $sent_version Version the change was made against.
	 * @return array<string, mixed>|null Null when the version did not match.
	 */
	public static function deactivate( string $id, int $sent_version ): ?array {
		$client = self::update( $id, array( 'status' => 'inactive' ), $sent_version );

		if ( null === $client ) {
			return null;
		}

		// A client nobody works for has no site anybody works on. Leaving the
		// sites active would leave their work in default views.
		ClientSites::deactivate_for_client( $id );

		return $client;
	}

	/**
	 * The columns an edit may set.
	 *
	 * @param array<string, mixed> $values Validated values.
	 * @return array<string, mixed>
	 */
	private static function writable( array $values ): array {
		$changes = array();

		foreach ( array( 'display_name', 'legal_name', 'status', 'timezone' ) as $field ) {
			if ( array_key_exists( $field, $values ) ) {
				$changes[ $field ] = (string) $values[ $field ];
			}
		}

		if ( array_key_exists( 'email_domains', $values ) ) {
			$changes['email_domains'] = wp_json_encode( $values['email_domains'] );
		}

		return $changes;
	}

	/**
	 * Turns a database row into the record the rest of the plugin uses.
	 *
	 * @param array<string, mixed> $row Row as stored.
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		$domains = json_decode( (string) ( $row['email_domains'] ?? '[]' ), true );

		return array(
			'id'             => (string) $row['id'],
			'display_name'   => (string) $row['display_name'],
			'legal_name'     => (string) $row['legal_name'],
			'status'         => (string) $row['status'],
			'timezone'       => (string) $row['timezone'],
			'email_domains'  => is_array( $domains ) ? $domains : array(),
			'created_at'     => (int) $row['created_at'],
			'updated_at'     => (int) $row['updated_at'],
			'created_by'     => (int) $row['created_by'],
			'record_version' => (int) $row['record_version'],
		);
	}
}
```

- [ ] **Step 4: Write `includes/Tenancy/ClientSites.php`**

The same shape, against `Schema::sites_table()`, with these differences:

```php
	/**
	 * Id prefix for a client site.
	 */
	public const PREFIX = 'cst';
```

- `create( string $client_id, array $values, int $author ): array` sets
  `client_id` from its own argument, never from `$values`, so a caller cannot
  move a site between clients by posting a field.
- `for_client( string $client_id, ?string $status = 'active' ): array` replaces
  `all()`, and always has `client_id = %s` in its WHERE.
- `writable()` covers `name`, `url` and `status` only — never `client_id`.
- `hydrate()` returns `id`, `client_id`, `name`, `url`, `status`, the stamps and
  `record_version`.
- `deactivate( string $id, int $sent_version ): ?array` sets status inactive.
- Plus the cascade, which takes no version because it is not a person's edit:

```php
	/**
	 * Deactivates every site under a client.
	 *
	 * No version is quoted: this is not somebody's edit of a site, it is the
	 * consequence of their edit of the client, and it must not be refusable
	 * because a site moved underneath it.
	 *
	 * @param string $client_id Client id.
	 * @return int Rows changed.
	 */
	public static function deactivate_for_client( string $client_id ): int {
		global $wpdb;

		$table = Schema::sites_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
		$changed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'inactive', updated_at = %d, record_version = record_version + 1 WHERE client_id = %s AND status = 'active'",
				bwx_forge_now(),
				$client_id
			)
		);

		return (int) $changed;
	}
```

- [ ] **Step 5: Run the test**

Run: `vendor/bin/phpunit --filter TenancyNoDeleteTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add includes/Tenancy/Clients.php includes/Tenancy/ClientSites.php tests/php/TenancyNoDeleteTest.php
git commit -m "Store clients and client sites (#88)"
```

---

### Task 4: The REST endpoints

**Files:**
- Create: `includes/Rest/ClientsController.php`, `includes/Rest/ClientSitesController.php`
- Modify: `includes/Rest/Server.php`
- Test: `tests/e2e/clients-rest.spec.js`

**Interfaces:**
- Consumes: `Tenancy\Clients`, `Tenancy\ClientSites`, `Tenancy\Validate`,
  `Rest\Errors`, `Rest\Versioning`, `Rest\Idempotency`, `Rest\Permissions`.
- Produces: `ClientsController::register_routes( string $namespace ): void`,
  `ClientSitesController::register_routes( string $namespace ): void`.
  Success bodies are `{ ok: true, client: {...} }`, `{ ok: true, clients: [...] }`,
  `{ ok: true, site: {...} }`, `{ ok: true, sites: [...] }`.

- [ ] **Step 1: Write the failing test**

`tests/e2e/clients-rest.spec.js`:

```javascript
import { test, expect } from '@playwright/test';

// The endpoints assembled against a real WordPress: unit tests can prove the
// rules, but only a real site proves the routes are registered, the tables are
// there, and the conventions are actually applied rather than merely available.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw';

async function signedInContext(browser, baseURL) {
  const context = await browser.newContext({ baseURL });
  const page = await context.newPage();

  await page.goto('/wp-login.php');
  await page.fill('#user_login', ADMIN_USER);
  await page.fill('#user_pass', ADMIN_PASS);
  await page.click('#wp-submit');
  await page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php'));

  await page.goto('/blueworx-forge/');
  const nonce = await page.evaluate(() => window.bwxForgeData?.nonce);
  expect(nonce, 'no REST nonce was localised for the signed-in user').toBeTruthy();

  await page.close();
  return { context, nonce };
}

async function createClient(request, nonce, name) {
  const response = await request.post('/wp-json/blueworx-forge/v1/clients', {
    headers: { 'X-WP-Nonce': nonce },
    data: { display_name: name, timezone: 'Europe/London' },
  });

  expect(response.status()).toBe(200);
  return (await response.json()).client;
}

test('a stranger cannot list or create clients', async ({ request }) => {
  expect([401, 403]).toContain((await request.get('/wp-json/blueworx-forge/v1/clients')).status());

  const created = await request.post('/wp-json/blueworx-forge/v1/clients', {
    data: { display_name: 'Trespass' },
  });
  expect([401, 403]).toContain(created.status());
});

test('a client with two sites has two independent workspaces', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const client = await createClient(context.request, nonce, 'Acme Ltd');

  const sites = [];
  for (const name of ['Acme Main', 'Acme Shop']) {
    const response = await context.request.post(
      `/wp-json/blueworx-forge/v1/clients/${client.id}/sites`,
      { headers: { 'X-WP-Nonce': nonce }, data: { name, url: 'https://example.test' } },
    );
    expect(response.status()).toBe(200);
    sites.push((await response.json()).site);
  }

  expect(sites[0].id).not.toEqual(sites[1].id);

  // Each site answers for itself and never for its sibling.
  for (const site of sites) {
    const response = await context.request.get(
      `/wp-json/blueworx-forge/v1/client-sites/${site.id}`,
      { headers: { 'X-WP-Nonce': nonce } },
    );
    const body = await response.json();
    expect(body.site.id).toBe(site.id);
    expect(body.site.client_id).toBe(client.id);
  }

  await context.close();
});

test('an edit made against an old version is refused, not merged', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const client = await createClient(context.request, nonce, 'Stale Ltd');

  const first = await context.request.patch(`/wp-json/blueworx-forge/v1/clients/${client.id}`, {
    headers: { 'X-WP-Nonce': nonce },
    data: { legal_name: 'Stale Limited', record_version: client.record_version },
  });
  expect(first.status()).toBe(200);

  const second = await context.request.patch(`/wp-json/blueworx-forge/v1/clients/${client.id}`, {
    headers: { 'X-WP-Nonce': nonce },
    data: { legal_name: 'Something else', record_version: client.record_version },
  });
  expect(second.status()).toBe(409);

  const body = await second.json();
  expect(body.code).toBe('bwx_forge_stale_write');
  // The rejection carries the current state, so the person can see what moved.
  expect(body.data.current.legal_name).toBe('Stale Limited');

  await context.close();
});

test('a write with no version at all is refused', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const client = await createClient(context.request, nonce, 'Versionless Ltd');

  const response = await context.request.patch(`/wp-json/blueworx-forge/v1/clients/${client.id}`, {
    headers: { 'X-WP-Nonce': nonce },
    data: { legal_name: 'No version' },
  });

  expect(response.status()).toBe(400);
  expect((await response.json()).code).toBe('bwx_forge_missing_version');

  await context.close();
});

test('a retried create produces one client, not two', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);

  const send = () =>
    context.request.post('/wp-json/blueworx-forge/v1/clients', {
      headers: { 'X-WP-Nonce': nonce, 'Idempotency-Key': 'clients-retry-1' },
      data: { display_name: 'Retry Ltd', timezone: 'UTC' },
    });

  const first = await send();
  const second = await send();

  expect((await first.json()).client.id).toBe((await second.json()).client.id);

  const listed = await context.request.get('/wp-json/blueworx-forge/v1/clients', {
    headers: { 'X-WP-Nonce': nonce },
  });
  const named = (await listed.json()).clients.filter((c) => c.display_name === 'Retry Ltd');
  expect(named).toHaveLength(1);

  await context.close();
});

test('deactivating a client deactivates its sites', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const client = await createClient(context.request, nonce, 'Closing Ltd');

  const created = await context.request.post(
    `/wp-json/blueworx-forge/v1/clients/${client.id}/sites`,
    { headers: { 'X-WP-Nonce': nonce }, data: { name: 'Closing Main' } },
  );
  const site = (await created.json()).site;

  const closed = await context.request.patch(`/wp-json/blueworx-forge/v1/clients/${client.id}`, {
    headers: { 'X-WP-Nonce': nonce },
    data: { status: 'inactive', record_version: client.record_version },
  });
  expect(closed.status()).toBe(200);

  const after = await context.request.get(`/wp-json/blueworx-forge/v1/client-sites/${site.id}`, {
    headers: { 'X-WP-Nonce': nonce },
  });
  expect((await after.json()).site.status).toBe('inactive');

  await context.close();
});
```

- [ ] **Step 2: Run it and watch it fail**

Run:
```bash
npm run wp:up
PLAYWRIGHT_BASE_URL=http://127.0.0.1:8892 npx playwright test tests/e2e/clients-rest.spec.js --workers=1
```
Expected: FAIL — the routes 404.

- [ ] **Step 3: Write `includes/Rest/ClientsController.php`**

Follow `SitesController` for shape and `rest-conventions.md` for order. The
write path, in every write method, is exactly this:

```php
	/**
	 * Creates a client.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create( WP_REST_Request $request ) {
		$key = (string) $request->get_header( Idempotency::HEADER );

		// Replay first. A retry costs nothing and, crucially, cannot be refused
		// for being stale against a version its own first attempt moved.
		if ( '' !== $key ) {
			if ( ! Idempotency::is_valid_key( $key ) ) {
				return Errors::rest(
					'invalid_idempotency_key',
					__( 'That retry key cannot be used.', 'blueworx-forge' ),
					400
				);
			}

			$replay = Idempotency::replay( 'create_client', $key );

			if ( null !== $replay ) {
				return rest_ensure_response( $replay );
			}
		}

		$checked = Validate::client( (array) $request->get_json_params(), false );

		if ( array() !== $checked['errors'] ) {
			return Errors::rest(
				'invalid_client',
				__( 'That client could not be saved.', 'blueworx-forge' ),
				400,
				array( 'fields' => $checked['errors'] )
			);
		}

		$client = Clients::create( $checked['values'], get_current_user_id() );

		$response = array(
			'ok'     => true,
			'client' => $client,
		);

		if ( '' !== $key ) {
			Idempotency::remember( 'create_client', $key, $response );
		}

		return rest_ensure_response( $response );
	}
```

And the edit, which checks the version before doing anything:

```php
	/**
	 * Edits a client, including deactivating it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update( WP_REST_Request $request ) {
		$client = Clients::get( (string) $request['client_id'] );

		if ( null === $client ) {
			return Errors::rest( 'unknown_client', __( 'There is no such client.', 'blueworx-forge' ), 404 );
		}

		$sent  = $request->get_param( Versioning::PARAM );
		$stale = Versioning::check( null === $sent ? null : (int) $sent, $client['record_version'], $client );

		if ( null !== $stale ) {
			return $stale;
		}

		$checked = Validate::client( (array) $request->get_json_params(), true );

		if ( array() !== $checked['errors'] ) {
			return Errors::rest(
				'invalid_client',
				__( 'That change could not be saved.', 'blueworx-forge' ),
				400,
				array( 'fields' => $checked['errors'] )
			);
		}

		$updated = 'inactive' === ( $checked['values']['status'] ?? '' )
			? Clients::deactivate( $client['id'], (int) $sent )
			: Clients::update( $client['id'], $checked['values'], (int) $sent );

		if ( null === $updated ) {
			// The row moved between the check above and the write. Same answer as
			// a stale write, because that is what it is.
			$current = Clients::get( $client['id'] );

			return Versioning::check(
				(int) $sent,
				null === $current ? 0 : $current['record_version'],
				null === $current ? array() : $current
			);
		}

		return rest_ensure_response(
			array(
				'ok'     => true,
				'client' => $updated,
			)
		);
	}
```

Routes, all with `'permission_callback' => array( Permissions::class, 'manage' )`:

| Route | Method | Callback |
|---|---|---|
| `/clients` | `GET` | `index` — `status` param, default `active`, `all` permitted |
| `/clients` | `POST` | `create` |
| `/clients/(?P<client_id>[A-Za-z0-9_\-]+)` | `GET` | `show` |
| `/clients/(?P<client_id>[A-Za-z0-9_\-]+)` | `PATCH` | `update` |

- [ ] **Step 4: Write `includes/Rest/ClientSitesController.php`**

The same four handlers against `ClientSites`, with `create_client_site` as the
idempotency operation name and these routes:

| Route | Method | Callback |
|---|---|---|
| `/clients/(?P<client_id>[A-Za-z0-9_\-]+)/sites` | `GET` | `index` |
| `/clients/(?P<client_id>[A-Za-z0-9_\-]+)/sites` | `POST` | `create` |
| `/client-sites/(?P<site_id>[A-Za-z0-9_\-]+)` | `GET` | `show` |
| `/client-sites/(?P<site_id>[A-Za-z0-9_\-]+)` | `PATCH` | `update` |

`create` returns `404 bwx_forge_unknown_client` when the client in the path does
not exist, before it validates anything: a site under no client is not a site.

- [ ] **Step 5: Register both in `includes/Rest/Server.php`**

```php
		ClientsController::register_routes( self::NAMESPACE );
		ClientSitesController::register_routes( self::NAMESPACE );
```

- [ ] **Step 6: Run the specs**

Run: `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8892 npx playwright test tests/e2e/clients-rest.spec.js --workers=1`
Expected: PASS, all six.

Also run `vendor/bin/phpunit --filter RestConventionsTest` — it walks every
registered route and fails any without a permission callback.

- [ ] **Step 7: Commit**

```bash
git add includes/Rest/ClientsController.php includes/Rest/ClientSitesController.php includes/Rest/Server.php tests/e2e/clients-rest.spec.js
git commit -m "Add the clients and client sites endpoints (#88)"
```

---

### Task 5: The admin screen

**Files:**
- Create: `includes/Admin/ClientsScreen.php`, `includes/Admin/ClientActions.php`
- Modify: `includes/Plugin.php`
- Test: `tests/e2e/clients-screen.spec.js`

**Interfaces:**
- Consumes: `Tenancy\Clients`, `Tenancy\ClientSites`, `Tenancy\Validate`.
- Produces: `ClientsScreen::SLUG = 'blueworx-forge-clients'`,
  `ClientsScreen::register(): void`, `ClientsScreen::render(): void`,
  `ClientActions::boot(): void`.

- [ ] **Step 1: Write the failing test**

`tests/e2e/clients-screen.spec.js`:

```javascript
import { test, expect } from '@playwright/test';

// Adding a client should be an administrator's job in a browser, not a
// developer's job with a signed API call — the same reason the sites screen
// exists. Walked as a person walks it, because that is where the failures are.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw';

const SCREEN = '/wp-admin/admin.php?page=blueworx-forge-clients';

async function signIn(page) {
  await page.goto('/wp-login.php');
  await page.fill('#user_login', ADMIN_USER);
  await page.fill('#user_pass', ADMIN_PASS);
  await page.click('#wp-submit');
  await page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php'));
}

async function addClient(page, name) {
  await page.goto(SCREEN);
  await page.fill('#bwx-client-name', name);
  await page.click('form[data-bwx-add-client] input[type="submit"]');
  await expect(page.locator(`[data-bwx-client-name]:text-is("${name}")`)).toBeVisible();

  return page
    .locator(`[data-bwx-client]:has([data-bwx-client-name]:text-is("${name}"))`)
    .getAttribute('data-bwx-client');
}

test('an administrator can add a client with two sites', async ({ page }) => {
  await signIn(page);
  const clientId = await addClient(page, 'Acme Ltd');

  for (const site of ['Acme Main', 'Acme Shop']) {
    await page.fill(`[data-bwx-client="${clientId}"] input[name="name"]`, site);
    await page.click(`[data-bwx-client="${clientId}"] form[data-bwx-add-site] input[type="submit"]`);
    await expect(page.locator(`[data-bwx-site-name]:text-is("${site}")`)).toBeVisible();
  }

  const sites = page.locator(`[data-bwx-client="${clientId}"] [data-bwx-site]`);
  await expect(sites).toHaveCount(2);
});

test('deactivating a client hides it and its sites from the default list', async ({ page }) => {
  await signIn(page);
  const clientId = await addClient(page, 'Closing Ltd');

  page.once('dialog', (dialog) => dialog.accept());
  await page.click(`[data-bwx-client="${clientId}"] [data-bwx-deactivate-client]`);

  await expect(page.locator(`[data-bwx-client="${clientId}"]`)).toHaveCount(0);

  await page.goto(`${SCREEN}&status=all`);
  await expect(page.locator(`[data-bwx-client="${clientId}"] [data-bwx-status]`)).toContainText(
    'Inactive',
  );
});

test('the screen is not reachable without the capability', async ({ page }) => {
  await page.goto(SCREEN);
  await expect(page.locator('body')).not.toContainText('Add a client');
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8892 npx playwright test tests/e2e/clients-screen.spec.js --workers=1`
Expected: FAIL — the page does not exist.

- [ ] **Step 3: Write `includes/Admin/ClientsScreen.php`**

A submenu under the existing Forge menu, rendering with `SitesScreen` as the
model — `wrap` div, `h1`, escaped output everywhere, and the `data-bwx-*`
attributes the spec above selects on:

```php
	/**
	 * The admin page slug.
	 */
	public const SLUG = 'blueworx-forge-clients';

	/**
	 * Adds the menu entry, beneath the Forge menu the sites screen creates.
	 */
	public static function register(): void {
		add_submenu_page(
			SitesScreen::SLUG,
			__( 'Clients', 'blueworx-forge' ),
			__( 'Clients', 'blueworx-forge' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render' )
		);
	}
```

`render()`:
- reads `status` from the query (`active` by default, `all` permitted) and lists
  `Clients::all()` accordingly, each in
  `<li data-bwx-client="{id}">` with `<span data-bwx-client-name>`,
  `<span data-bwx-status>` and its sites from `ClientSites::for_client()` in
  `<li data-bwx-site="{id}"><span data-bwx-site-name>`;
- renders `<form data-bwx-add-client>` posting to `admin-post.php` with
  `action=bwx_forge_add_client`, `wp_nonce_field( 'bwx_forge_add_client' )`,
  a `#bwx-client-name` text input, a timezone `<select>` built from
  `timezone_identifiers_list()` and a comma-separated permitted-domains field;
- renders one `<form data-bwx-add-site>` per client with a `name` and `url`
  input and the client's id in a hidden field;
- renders the deactivate buttons as forms carrying
  `onsubmit="return confirm(...)"` and the record's current
  `record_version` in a hidden field, so the screen obeys ARCH-5 exactly as the
  API does;
- prints a notice from the `bwx_notice` query argument.

- [ ] **Step 4: Write `includes/Admin/ClientActions.php`**

`SiteActions` is the model, including its `require_admin()` and `back()`
helpers. Four handlers, each doing capability, then nonce, then work:

```php
	/**
	 * Hooks the handlers up.
	 */
	public static function boot(): void {
		add_action( 'admin_post_bwx_forge_add_client', array( self::class, 'add_client' ) );
		add_action( 'admin_post_bwx_forge_add_client_site', array( self::class, 'add_client_site' ) );
		add_action( 'admin_post_bwx_forge_deactivate_client', array( self::class, 'deactivate_client' ) );
		add_action( 'admin_post_bwx_forge_deactivate_client_site', array( self::class, 'deactivate_client_site' ) );
	}
```

Each validates through `Tenancy\Validate` — the same rules the API applies, so
the two doors cannot disagree — and redirects back with `bwx_notice` set to
`added`, `invalid`, `stale` or `unknown`.

- [ ] **Step 5: Hook it up in `includes/Plugin.php`**

In `boot()`, beside the existing screen wiring:

```php
		add_action( 'admin_menu', array( Admin\ClientsScreen::class, 'register' ) );

		Admin\ClientActions::boot();
```

- [ ] **Step 6: Run the specs**

Run: `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8892 npx playwright test tests/e2e/clients-screen.spec.js --workers=1`
Expected: PASS, all three.

- [ ] **Step 7: Commit**

```bash
git add includes/Admin/ClientsScreen.php includes/Admin/ClientActions.php includes/Plugin.php tests/e2e/clients-screen.spec.js
git commit -m "Add the clients admin screen (#88)"
```

---

### Task 6: Version, changelog, and the full check

**Files:**
- Modify: `package.json`, `blueworx-forge.php`, `client/blueworx-forge-client.php`, `CHANGELOG.md`

- [ ] **Step 1: Bump the version to 2.10.0 in all four places**

New feature, so a minor bump. Both plugin headers must match or CI fails.

- `package.json`: `"version": "2.10.0"`
- `blueworx-forge.php`: the `Version:` header and `BWX_FORGE_VERSION`
- `client/blueworx-forge-client.php`: the `Version:` header and
  `BWX_FORGE_CLIENT_VERSION`

Leave `package-lock.json`'s own `version` field alone — that is a settled
decision in `CLAUDE.md`.

- [ ] **Step 2: Add the changelog entry**

At the top of `CHANGELOG.md`, in the file's existing plain style:

```markdown
## [2.10.0] - 2026-08-19

### Added

- Clients and their sites are now records in their own right. A client can have
  more than one site, and each site is its own workspace — work, hours and
  onboarding will belong to the site rather than to the client. Add and manage
  them from Forge → Clients. Nothing is ever deleted: a client or site you
  finish with is made inactive and kept.
```

- [ ] **Step 3: Run every check, once**

```bash
npm run lint
npm run build
composer lint
vendor/bin/phpunit
npm test
```

Expected: all pass. Present any lint findings to Luke rather than fixing them in
a loop.

- [ ] **Step 4: Commit and open the pull request**

```bash
git add package.json blueworx-forge.php client/blueworx-forge-client.php CHANGELOG.md
git commit -m "Release 2.10.0 (#88)"
git push -u origin m2/client-and-site-entities
```

Open a **draft** pull request linked to #88, saying what it does and what still
needs a human eye. Never auto-merge.

## Self-review

- **Spec coverage.** Storage → Task 1. Repositories, deactivation and version
  rules → Task 3. REST → Task 4. Admin screen → Task 5. Testing → the test steps
  in each task. Version and changelog → Task 6.
- **Deliberately not here**, each with its owner: the key and health record
  (#89), users and memberships (#90), roles (#91), the shared scoping layer
  (#92), studio views (M5).
- **Naming.** `Sites\Registry` is untouched; the new records live under
  `Tenancy\`. `Rest\ClientController` (the client site's read-through endpoint)
  is untouched and unrelated to `Rest\ClientsController`.
