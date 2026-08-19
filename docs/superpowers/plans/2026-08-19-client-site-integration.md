# Client Site Integration record — plan

Issue #89. Design:
[`2026-08-19-client-site-integration-design.md`](../specs/2026-08-19-client-site-integration-design.md).

Each step is test-first: the test named goes in and fails for the right reason
before the code beneath it is written.

## 1. Health, decided in one place

- `tests/php/IntegrationHealthTest.php` — every row of the design's health table,
  and the two the issue turns on: an error recorded after the last success reads
  `broken`; an old success with no error since reads `idle`.
- `includes/Tenancy/Health.php` — one pure `state( array $record ): string`, plus
  the window constant and its filter.

## 2. Storage

- `tests/php/SchemaTest.php` — the new table is defined, is dbDelta-shaped, and
  carries the unique index on `client_site_id`; the schema version has moved.
- `includes/Data/Schema.php` — version 2, `integrations_table()`, the definition.

## 3. Repository

- `tests/php/IntegrationsWriteTest.php` — the record hydrates, key state moves
  through unissued → active → revoked, and there is no delete.
- `includes/Tenancy/Integrations.php` — `ensure()` (one per site, unique index
  enforced), `for_site()`, `for_client()`, `note_key_issued()`,
  `note_key_rotated()`, `note_key_revoked()`, `note_seen()`, `note_error()`,
  `note_report()`.

## 4. Freshness from the authentication path

- `tests/php/SignatureTest.php` — a verified request fires
  `bwx_forge_site_verified`; a refused one does not.
- `includes/Rest/Signature.php` — fire the action on success. Refusals already
  fire `bwx_forge_security_event`, so nothing new is needed there.
- `includes/Tenancy/IntegrationEvents.php` — subscribes to both and writes the
  timestamps, so the database write stays out of the signature check and
  `Signature` stays unit-testable without a database.

## 5. Studio REST

- `tests/e2e/site-integration-rest.spec.js` — issue, rotate, revoke; the key
  appears in the issuing response and in no read; health moves with the key;
  a site under another client cannot be reached through the wrong client.
- `includes/Rest/IntegrationsController.php` — the four routes from the design.
- `includes/Rest/ClientSitesController.php` — `integration` block on the index.

## 6. The site describes itself

- `tests/php/MailCapabilityTest.php` — `yes` when `wp_mail` is callable and the
  last attempt did not fail, `no` with a reason otherwise; nothing is sent.
- `client/includes/Mail.php` — the capability check.
- `client/includes/Report.php` — builds and posts the report; hooked to
  activation, to the connection screen's check, and to a daily cron.
- `client/includes/Connection.php` — a signed `post()` beside the existing
  `get()`.
- `includes/Rest/ClientController.php` — `POST /client/report`, writing only to
  the site that signed the request.

## 7. Admin

- `tests/e2e/clients-screen.spec.js` — the connection column reads in words, and
  issuing a key shows it once.
- `includes/Admin/ClientsScreen.php` and `ClientActions.php` — the column and the
  three actions, reusing `Admin\IssuedKey`.

## 8. Both sides together

- `tests/pair/site-integration.spec.js` — a real client site reports itself and
  the studio moves from `never_connected` to `connected` with mail capability
  filled in.

## 9. Ship

Version bump and changelog on both plugin headers, `npm run lint`,
`npm run build`, `composer lint`, both Playwright suites, draft PR against #89.
