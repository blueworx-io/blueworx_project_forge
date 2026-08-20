# Client Site Integration record — design

Issue #89, Milestone 2 issue 2. Per ARCH-1, ARCH-2, ARCH-6 and
[`data-model.md`](../../architecture/data-model.md).

## Goal

The studio knows which WordPress belongs to which client site, and whether that
site is healthy. A broken connection has to be distinguishable from an idle one:
today the studio can only say a site was registered, not whether it has ever
called, when it last called, or whether it could send the client an email if a
work item moved.

## Scope

In scope: the Integration record, the reconciliation of M1's `Sites\Registry`
with the Client Site from #88, sync health, mail capability, the REST routes on
both sides, and the studio admin screen showing the result.

Out of scope: users and memberships (#90), roles and capabilities (#91), the
shared scoping layer (#92), retries and the Sync Event entity (M10), and any
React work — the acceptance test is demonstrated through the WordPress admin.

## The reconciliation

M1 registered sites into an option (`Sites\Registry`), keyed by a `site_` id,
with no idea a client existed. #88 created Client Sites in a table, with no idea
a key existed. The Integration record is the join, and it is where the key state
now lives as far as the rest of the plugin is concerned.

`Sites\Registry` stays as it is. It is what `Rest\Signature` verifies against on
every signed request, it is proven, and rewriting the authentication path to read
from a new table is not what this issue asks for. The Integration record holds
the `site_id` the registry issued and mirrors the key's state; the registry
remains the single place a key is stored and checked.

One integration per client site, enforced by a unique index rather than by
callers remembering: a second row would mean two keys both able to sign as one
site, and no way to say which one the studio meant.

## Storage

`{$wpdb->prefix}bwx_forge_site_integrations`, schema version 2.

| Column | Type | Notes |
|---|---|---|
| `id` | `varchar(32)`, primary key | `int_` + 16 hex. |
| `client_site_id` | `varchar(32)`, unique | The site this describes. One integration per site. |
| `client_id` | `varchar(32)`, indexed | Denormalised from the site so a health query for a client is one read. Set at creation and never edited — a site does not move between clients. |
| `registry_site_id` | `varchar(32)`, indexed | The `Sites\Registry` id, empty until a key is issued. Deliberately not called `site_id`: WordPress core maps that column name to an integer format for every `$wpdb` write, so a column called `site_id` silently stores 0 and reports success. |
| `key_state` | `varchar(20)` | `unissued`, `active` or `revoked`. Mirrors the registry. |
| `key_issued_at`, `key_rotated_at`, `key_revoked_at` | `bigint` | Unix time, 0 when it has not happened. |
| `last_seen_at` | `bigint` | The last signed request the studio accepted from this site. |
| `last_report_at` | `bigint` | The last time the site described itself. |
| `last_error_code` | `varchar(64)` | What went wrong last, `''` when nothing has. |
| `last_error_at` | `bigint` | When. |
| `mail_capable` | `varchar(20)` | `unknown`, `yes` or `no`. The site's own answer. |
| `mail_checked_at` | `bigint` | When it answered. |
| `mail_detail` | `varchar(191)` | Why it said no, or which mailer said yes. For support, not for logic. |
| `home_url` | `varchar(255)` | What the site says its address is. Compared against the Client Site's `url`; a mismatch is shown, not corrected. |
| `wp_version`, `php_version`, `plugin_version` | `varchar(32)` | The site's identity, so "it is on an old client plugin" is answerable without asking. |
| `created_at`, `updated_at`, `created_by`, `record_version` | as #88 | |

The key itself is **not** here. ARCH-6's storage posture is unchanged by this
issue: it stays in the registry, issued once and never read back.

## Health, derived not stored

A stored status is a status that goes stale the moment nothing writes it. Health
is therefore computed from the timestamps above, by one pure function in
`Tenancy\Health`, and every screen and endpoint asks that function.

| State | When | What it means |
|---|---|---|
| `unconfigured` | No key ever issued | Nobody has connected this site yet. |
| `revoked` | Key revoked | Deliberately cut off. Not a fault. |
| `never_connected` | Key issued, never seen | Installed at our end, not at theirs. |
| `connected` | Seen inside the window, no error since | Working. |
| `broken` | An error recorded after the last success | It tried and failed. |
| `idle` | Last seen outside the window, no error since | It has not called recently, and did not fail — a quiet site, not a broken one. |

The window is 24 hours, one constant, overridable by filter. `idle` and `broken`
being separate states is the issue's acceptance criterion: a site that simply has
nobody using it must not read as a fault, and a site whose key stopped working
must not read as quiet.

## How the record gets its facts

- **Key state** is written by the studio, when it issues, rotates or revokes.
- **`last_seen_at`** is stamped by `Rest\Signature` accepting a signed request —
  every route a client site calls, not a special one, or the freshness would only
  ever reflect whichever endpoint we remembered to instrument.
- **`last_error_*`** is stamped where a signed request is refused for a reason
  that belongs to a known site (bad signature, revoked key, replay, drift). An
  unknown site id records nothing: there is no record to write to, and inventing
  one would let anybody create rows by guessing.
- **Mail capability and identity** come from the site itself, on
  `POST /client/report` — a signed route the client plugin calls on activation,
  when its connection screen is used, and daily on cron.

## Mail capability

The client site answers `yes` unless it can see a reason to say `no`: `wp_mail`
missing (a plugin can unhook it), or a `wp_mail_failed` recorded on its own last
attempt. It does **not** send a probe email. A probe would either go to a real
address — sending mail nobody asked for — or to a fake one, which teaches the
site's SMTP provider to treat us as a source of bounces. NOTIF-3 puts delivery on
the client's own configuration, so what the studio needs to know is whether that
path exists at all, and that is answerable without sending.

## REST

Studio, `Permissions::manage()` for all four:

- `GET /client-sites/{id}/integration` — the record, with derived health.
- `POST /client-sites/{id}/integration/key` — registers the site with
  `Sites\Registry` and issues the first key, or rotates an existing one. The key
  is in this response and in no other, as #83 established.
- `DELETE /client-sites/{id}/integration/key` — revokes.
- `GET /clients/{id}/sites` gains an `integration` block per site, so the studio
  can see every site's health in one read rather than N.

Client site, `Permissions::client_site()`:

- `POST /client/report` — the site describes itself. It names no site id: the one
  it is allowed to write is the one that signed the request.

## Admin

The Clients screen gains a connection column per site: the health state in words,
when it was last seen, and whether it can send mail. The actions are Issue key,
Rotate key and Revoke, reusing `Admin\IssuedKey` so a new key is shown once and
never travels in a URL.

## Tests

- Unit, no database: every row of the health table, including the two that the
  issue turns on — an error after a success is `broken`, an old success with no
  error is `idle`.
- Unit: mail capability decided from what the site can see.
- REST e2e: issue, rotate, revoke, and the key appearing exactly once.
- Pair: a real client site reports itself, and the studio moves from
  `never_connected` to `connected` with mail capability filled in — the whole
  point of the two-instance harness.
