# Global user identity and client memberships — design

Issue #90, Milestone 2 issue 3. Per AUTH-3, AUTH-5, AUTH-6, ARCH-3 and
[`data-model.md`](../../architecture/data-model.md).

## Goal

One person, one account, whatever number of clients they work with. A person who
works with three clients is one row, not three — otherwise capacity counts them
three times, attribution splits across duplicates, and offboarding has to be done
once per client and will eventually be done twice.

## Scope

In scope: the User entity, the Membership that links a user to a client (and
optionally to one site beneath it), the access role held per membership, the
cascades, and the studio screens to work all of it.

Out of scope, each with the issue that owns it: what a role may actually *do*
(#91 — this issue stores the role, it does not enforce capabilities), the shared
scoping layer (#92), cross-client access grants (#93), the Point of Contact
assignment (#95), and email invitations (M9 territory; a user is created here,
not invited).

The AUTH-3 **Principal** grant is deliberately not here. It is a capability held
by a staff user, and it means nothing until capabilities exist, so it belongs
with #91.

## The shape

A **User** is global (`data-model.md` marks it so explicitly). A **Membership**
is the join, and it carries the role. Nothing about a person's access lives on
the user row — that is the whole point: two clients, two roles, one person.

```
User ──< Membership >── Client
              └──────── Client Site (optional)
```

A membership names a client always, and a site sometimes. A membership with no
site named grants every site under that client; one that names a site grants only
that site. Both are needed: ARCH-3 makes the site the scoping unit, and AUTH-6
describes memberships per client — a client admin over a two-site client should
not need two rows that can drift apart, and a person brought in for one site
should not silently gain the other.

## Storage

Schema version 3.

`{$wpdb->prefix}bwx_forge_users`

| Column | Type | Notes |
|---|---|---|
| `id` | `varchar(32)`, primary key | `usr_` + 16 hex. |
| `email` | `varchar(191)`, unique | Lower-cased. This is the identity: AUTH-6 has people invited by email, and a unique index is what makes "one person, one account" true rather than intended. |
| `display_name` | `varchar(191)` | What we call them. |
| `status` | `varchar(20)` | `active` or `inactive`. Offboarding deactivates; nothing is deleted (NOTIF-5). |
| `wp_user_id` | `bigint`, indexed | The WordPress account on the studio site, or 0. A Forge user is not a WordPress user — most client people will never have one — but our own staff do, and something has to join them. |
| `created_at`, `updated_at`, `created_by`, `record_version` | as #88 | |

`{$wpdb->prefix}bwx_forge_memberships`

| Column | Type | Notes |
|---|---|---|
| `id` | `varchar(32)`, primary key | `mem_` + 16 hex. |
| `user_id` | `varchar(32)`, indexed | The person. |
| `client_id` | `varchar(32)`, indexed | The client. |
| `client_site_id` | `varchar(32)` | One site, or `''` for every site under the client. |
| `role` | `varchar(32)` | One of the five below. |
| `status` | `varchar(20)` | `active` or `inactive`. |
| `created_at`, `updated_at`, `created_by`, `record_version` | as #88 | |

`UNIQUE KEY user_client_site (user_id, client_id, client_site_id)` — one person
holds one role in one place. Two rows would mean two answers to "what may they
do here", and #91 would have to pick one.

## The roles

Five, taken from the columns of
[`permission-matrix.md`](../../architecture/permission-matrix.md):

| Role | Who |
|---|---|
| `primary_admin` | Primary administrator. |
| `staff` | Our own people. |
| `client_admin` | The client's administrator for a site. |
| `client_viewer` | A stakeholder on the client's side. |
| `internal_viewer` | A viewer on our side. |

Viewer is split by side because AUTH-5 requires it: an internal viewer sees
internal notes and a client viewer never does. One `viewer` role would leave #91
guessing which kind it had from somewhere else.

## Rules that live in validation

- **Email is the identity.** Lower-cased, must be an address, unique across
  users. A second attempt with the same address is refused rather than quietly
  creating a duplicate person.
- **The role must be one of the five.** Anything else is refused, not stored and
  ignored later.
- **A site-scoped membership's site must belong to the named client.** This is
  the cross-tenant guard M2 exists for: without it a membership row could grant
  one client's person access to another client's site, and every later scoped
  query would faithfully honour it.
- **Permitted email domains are enforced here** (#88 stored them for this). If
  the client lists domains, a `client_admin` or `client_viewer` membership
  requires an address in one of them. Studio-side roles are exempt: they are our
  people on our own domain, and the client's list is about the client's people.

## Cascades

Deactivation only, never deletion (NOTIF-5). Attribution survives all of it.

- **Deactivating a user** deactivates every membership they hold. That is AUTH-6
  offboarding: one action, every client.
- **Deactivating a client** deactivates its memberships as well as its sites
  (#88 already cascades the sites).
- **Deactivating a site** deactivates memberships scoped to that site alone.
  Client-wide memberships are untouched: they were never about that site.

Like the site cascade in #88, none of these quote a record version. They are the
consequence of somebody's edit elsewhere, not an edit of their own, and must not
be refusable because a row moved underneath them.

## REST

All gated to `Permissions::manage()` for now; #91 swaps each one for a
capability check.

- `GET /users`, `POST /users`, `GET /users/{id}`, `PATCH /users/{id}`
- `GET /users/{id}/memberships` — the same person across every client, which is
  the query this issue exists to make possible.
- `GET /clients/{id}/memberships`, `POST /clients/{id}/memberships`
- `PATCH /memberships/{id}` — including setting `status` to `inactive`.

## Admin

A **Forge → People** screen: add a user, edit one, deactivate one, and see every
membership they hold across clients. Memberships are added and ended from the
client's own row on the Clients screen, because that is where somebody is
thinking about who works with that client.

## Tests

- Unit: the validation rules above, including the cross-client site refusal and
  the domain rule.
- Unit: no repository can delete.
- REST e2e: one person, two clients, two different roles, one user row; the
  cross-client site refusal; each of the three cascades.
- Screen e2e: adding a person and giving them a membership, in the browser.
