# Global user identity and client memberships — plan

Issue #90. Design:
[`2026-08-19-users-and-memberships-design.md`](../specs/2026-08-19-users-and-memberships-design.md).

Each step is test-first: the test named goes in and fails for the right reason
before the code beneath it is written.

## 1. The rules, decided without a database

- `tests/php/ValidateIdentityTest.php` — the email is the identity and is
  lower-cased; a role outside the matrix is refused; the client's permitted
  domains bind the client's own people and not ours; a lookalike domain does not
  pass.
- `includes/Tenancy/Roles.php` — the five roles, and which side each is on.
- `includes/Tenancy/Validate.php` — `user()`, `membership()`, `domain_error()`.

## 2. Storage

- `tests/php/SchemaTest.php` — the unique index on the address, and the one
  across user, client and site.
- `includes/Data/Schema.php` — version 3, the two tables.

## 3. Repositories

- `tests/php/TenancyNoDeleteTest.php` — neither can delete; both can deactivate.
- `includes/Tenancy/Users.php`, `includes/Tenancy/Memberships.php`.
- The cascades: offboarding ends every membership; closing a client ends its
  memberships; closing a site ends the ones scoped to it and leaves the
  client-wide ones.

## 4. Say what type each column is

Found while building step 3, and the reason this step exists at all: a column
called `user_id` holding a string id was silently stored as `0`, because core
maps that column *name* to `%d` for every `$wpdb` write. #89 hit the same thing
with `site_id` and dodged it by renaming.

- `tests/php/DataFormatsTest.php` — a string id stays a string whatever the
  column is called.
- `includes/Data/Formats.php`, used by every repository's insert and update.

## 5. REST

- `tests/e2e/people-rest.spec.js` — one person, two clients, two roles, one row;
  the same address refused twice; a membership naming another client's site
  refused; the domain rule; all three cascades; a stale edit refused.
- `includes/Rest/UsersController.php`, `includes/Rest/MembershipsController.php`.

## 6. Admin

- `tests/e2e/people-screen.spec.js` — adding somebody and giving them access, in
  the browser; the same person on two clients showing as one person; offboarding
  ending access everywhere and keeping the record.
- `includes/Admin/PeopleScreen.php`, `includes/Admin/PeopleActions.php`, and the
  people block on `includes/Admin/ClientsScreen.php`.

## 7. Make the clients screen load again

Also found by the tests: with eighty clients the screen was serving 2.4 MB and
36,000 `<option>` tags, because each client's edit form carried its own copy of
the timezone list, and each client cost three queries of its own.

- One shared `<datalist>` for timezones; people, memberships and integrations
  read once per page rather than per client.

## 8. Ship

Version bump and changelog on both plugin headers, `npm run lint`,
`composer lint`, both Playwright suites, draft PR against #90.
