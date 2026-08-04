# Foundry Connector — Design

**Date:** 2026-08-04
**Status:** Approved, awaiting Foundry API key

## Goal

When an item is added in Forge, send it to Foundry automatically instead of re-typing it.

This is a **temporary connector**. Keep it to one self-contained file so it can be deleted in one step.

## Foundry

- Base URL: `https://foundry.gitwork.co.uk`
- Auth: `Authorization: Bearer <API_KEY>` on every request
- Docs: `https://foundry.gitwork.co.uk/api-docs` (public, no key needed)
- Health: `GET /api/health` — confirmed live, `foundry-by-gitwork` v0.1.0

The key lives as `API_KEY` in Foundry's Vercel project, which belongs to Gitwork, not us. Gitwork
must issue one. Every `/api/*` path returns 401 without it, so endpoint existence and field shapes
cannot be verified until the key arrives.

## Mapping

Forge has Features, Sub-Items, Bugs, Feedback, Releases and Company Dates. Foundry's create
endpoints are proposals, clients, rate-card people and codeclear candidates. Only proposals fit a
work item, so **every Forge item type POSTs to `/api/proposals`**.

| Foundry field | Source |
|---|---|
| `title` | Forge item title |
| `clientName` | Fixed value from connector settings |
| `productName` | Fixed value from connector settings |
| `templateId` | Fixed value from connector settings |

Forge has no client or product concept, so those three are set once and reused. Only the four
documented fields are sent — Foundry's validation behaviour on unknown keys is unverified, so
nothing extra is included until the key lets us test.

## Behaviour

- Fires on first publish of a `forge_*` item. Edits never re-push.
- The push is queued as a one-off cron event rather than run inline, so post meta is fully written
  before the payload is built, and saving an item is never slowed or blocked by Foundry.
- On success the returned Foundry id is stored on the item. That id is both the receipt and the
  "already pushed" marker.
- On failure the error is stored on the item and the Forge item saves normally regardless. No retry
  queue — re-push by hand from the item screen.

## Configuration

- API key: `FORGE_FOUNDRY_API_KEY` constant in `wp-config.php`. Never in the database, never
  committed.
- Client name, product name, template id and the on/off switch: a **Foundry** screen under the
  Forge PM admin menu.
- Off by default. Nothing pushes until it is switched on.

## Admin surface

- **Foundry settings screen** — the four settings above, plus a Test Connection button that calls
  `GET /api/proposals` with the key and reports pass or fail.
- **Foundry box on each item edit screen** — shows pushed / failed / not pushed, the Foundry id or
  the error text, and a Push now button.

## Out of scope

- Pushing to clients, rate-card people or candidates.
- Updating a Foundry record when the Forge item changes.
- Bulk backfill of existing items.
- Retry queues and rate limiting.

## Open until the key arrives

- Whether `/api/proposals` accepts extra fields such as description.
- What a valid `templateId` looks like and where to get one.
- The shape of the create response, so the id is read from the right place.
