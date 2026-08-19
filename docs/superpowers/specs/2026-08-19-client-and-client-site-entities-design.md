# Client and Client Site entities — design

Issue #88, Milestone 2 issue 1. Per ARCH-3 and
[`data-model.md`](../../architecture/data-model.md).

## Goal

The scoping unit, correctly placed. A Client groups identity, people and
memberships; a Client Site beneath it is the workspace that owns work, hours,
packages and onboarding. A client with two sites has two independent
workspaces, and nothing that will later be scoped is scoped to the client.

## Scope

In scope: the two entities, their storage, their REST endpoints, and a studio
admin screen for creating and editing them.

Out of scope, each with the issue that owns it: the connection key and health
record (#89, which reconciles `Sites\Registry` with the Client Site), users and
memberships (#90), roles and capabilities (#91), the shared scoping layer (#92).
Studio views are M5. No React work: the acceptance test is demonstrated through
the WordPress admin.

## Storage

Two custom tables. The plugin has stored everything in options until now, which
suits a handful of registered sites and suits nothing after it: the hour ledger,
work items and every M2 scoping query need columns, indexes and a `WHERE`.

`{$wpdb->prefix}bwx_forge_clients`

| Column | Type | Notes |
|---|---|---|
| `id` | `varchar(32)`, primary key | `cli_` + 16 hex. Random, not sequential, for the reason `Sites\Registry` gives: an id appears in logs and URLs and should not advertise how many clients exist. |
| `display_name` | `varchar(191)` | Required. What the studio calls them. |
| `legal_name` | `varchar(191)` | Optional. What contracts and invoices call them. |
| `status` | `varchar(20)` | `active` or `inactive`. Never deleted (NOTIF-5). |
| `timezone` | `varchar(64)` | A PHP timezone identifier; validated against `timezone_identifiers_list()`. |
| `email_domains` | `text` | JSON array of permitted email domains for that client's people. Stored now; enforced when memberships land (#90). |
| `created_at`, `updated_at` | `bigint` | Unix time, from `bwx_forge_now()` so tests control the clock. |
| `created_by` | `bigint` | WordPress user id of the author. |
| `record_version` | `int` | Starts at 1, increments on every write (ARCH-5). |

`{$wpdb->prefix}bwx_forge_client_sites`

| Column | Type | Notes |
|---|---|---|
| `id` | `varchar(32)`, primary key | `cst_` + 16 hex. |
| `client_id` | `varchar(32)`, indexed | The owning client. Required, never null. |
| `name` | `varchar(191)` | Required. |
| `url` | `varchar(255)` | The site's address. |
| `status` | `varchar(20)` | `active` or `inactive`. |
| `created_at`, `updated_at`, `created_by`, `record_version` | as above | |

A `Data\Schema` class owns both table definitions, a schema version number held
in one option, and a single `maybe_upgrade()` that runs `dbDelta` on activation
and whenever the stored number is behind the code's. Activation alone is not
enough: a plugin updated in place does not re-run activation, so a table added
in a later release would never appear.

Uninstall drops nothing. `uninstall.php` already leaves content alone, and
client records are the site owner's data, not the plugin's.

## Repositories

`Tenancy\Clients` and `Tenancy\ClientSites`, each a small class over `$wpdb`
with `create`, `update`, `get`, `all`, and `deactivate`. Two rules live in them
rather than in callers:

- **Deactivation, never deletion.** There is no `delete` method. Deactivating a
  client deactivates its sites; the rows stay, so history and reporting still
  resolve.
- **Version on every write.** `update` takes the version the caller wrote
  against and refuses a mismatch, returning the current record. The check is in
  the repository, not only in the REST layer, so a future internal caller cannot
  route around it.

Validation is separated from persistence — a `validate()` that returns the
cleaned values or an error list — so the rules are unit-testable without a
database.

Namespace note: `Sites\Registry` keeps its name and its meaning (a connected
WordPress and its key). The new entities live under `Tenancy\` so the two are
never confused before #89 joins them up.

## REST

Namespace `blueworx-forge/v1`, following
[`rest-conventions.md`](../../architecture/rest-conventions.md) exactly:
registration through `Rest\Server::register_route()`, `Errors::rest()` for
refusals, `Versioning::check()` before any write, `Idempotency` around every
write, replay-then-version-then-work.

| Route | Method | Purpose |
|---|---|---|
| `/clients` | GET | List clients. `status` filter, default active only. |
| `/clients` | POST | Create a client. |
| `/clients/{id}` | GET | One client. |
| `/clients/{id}` | PATCH | Edit, including deactivation. |
| `/clients/{id}/sites` | GET | That client's sites. |
| `/clients/{id}/sites` | POST | Create a site under that client. |
| `/client-sites/{id}` | GET | One site. |
| `/client-sites/{id}` | PATCH | Edit, including deactivation. |

`Rest\ClientsController` and `Rest\ClientSitesController`, both added to
`Server::register_routes()`. Named to avoid the existing `ClientController`,
which is the client site's read-through endpoint and unrelated.

Permissions: the studio administrator capability, through `Rest\Permissions`,
matching how site registration is already gated. Access roles replace this in
#91; the endpoints are written so that swap is one callback each.

## Admin screen

A **Clients** page beside the existing Sites screen, built the same way
(`Admin\ClientsScreen` for rendering, `Admin\ClientActions` for the POST
handlers, nonce-checked). It lists clients with their sites beneath them, and
offers add client, add site, edit, and deactivate. Deliberately plain: it exists
to prove the acceptance criterion and to run a real client before M5's studio
views exist, not to be the eventual interface.

## Testing

Unit (`tests/php`, no WordPress runtime):

- Id format and uniqueness.
- Validation: required names, unknown timezone refused, malformed email domain
  refused, unknown status refused.
- Version conflict: an update quoting an old version is refused and the current
  record returned; a matching version increments to the next.
- Deactivating a client deactivates its sites.
- No `delete` method exists on either repository.

End-to-end (`tests/e2e`, real WordPress):

- Tables exist after activation, and after an in-place upgrade with a bumped
  schema version.
- The acceptance test: one client, two sites, each addressable on its own and
  neither returning the other's record.
- Each REST route: create, read, edit, stale-write 409, idempotent retry.
- The admin screen: add a client, add two sites, deactivate one, and see the
  list reflect it.

## Done when

A client with two sites has two independent site records that everything later
scopes to, and no field on the client holds work, hours, packages or onboarding.
