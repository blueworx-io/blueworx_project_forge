# External API Connectors — Design

**Issue:** [#29 — External API Connectors](https://github.com/blueworx-io/blueworx_project_forge/issues/29)
**Date:** 2026-07-15
**Status:** Approved for planning

## Goal

Let an administrator configure outbound **connections** that push a JSON payload to an external
HTTP endpoint whenever a new item is created in Forge. Delivery is asynchronous and retried, so a
slow or unavailable endpoint never blocks item creation.

## Scope

- **Trigger:** item creation only.
- **Item types:** feature, sub-item, bug, feedback, release — selectable per connection.
- **Transport:** `POST` of a stable JSON envelope to a configurable URL with an optional bearer token.
- **Delivery:** async via WP-Cron, retried on failure, with a capped activity log.
- **Configuration:** a new **Connections** section in Settings, admin-only.

### Out of scope

- Inbound sync — nothing is pulled *from* external systems.
- Per-vendor adapters (GitHub Issues, ClickUp tasks, Slack Blocks). Every connection is a generic
  webhook; vendor-shaped payloads are a later concern.
- Push on update, stage change, or delete.
- Backfill of items created before a connection existed.
- Field-level mapping or payload templating in the UI.

## Context: why generic, not Foundry-specific

This work was prompted by a request to connect [Foundry by Gitwork](https://foundry.gitwork.co.uk/api-docs).
Investigation found Foundry has **no endpoint capable of receiving a Forge item** — no ingest, webhook,
import, task, or work-item route. Its closest resource, `POST /api/proposals`, mandates
`title`, `clientName`, `productName`, and `templateId`; Forge has no concept of a client, product, or
proposal template, and a Bug is not a proposal.

The blocker is therefore on the Foundry side, not the Forge side. Building the generic connector
framework delivers issue #29 in full and reduces Foundry to a configuration row the moment they expose
an ingest endpoint — with no Forge rework.

Two findings worth carrying forward:

- The documented base URL `https://foundry.gitwork.co` **does not resolve**. The live host is
  `https://foundry.gitwork.co.uk` (`GET /api/health` → `200`, `foundry-by-gitwork v0.1.0`).
- `GET /api/openapi.json` returns `401`, not `404` — a machine-readable spec exists behind the API key.
  Worth retrieving before writing any Foundry-specific mapping.

## Architecture

```
POST /wp-json/forge/v1/items/{type}
        │
        ├─ item saved ─────────────────► HTTP response returns immediately
        │
        └─ do_action( 'forge_pm_item_created', $type, $id )
                    │
                    ▼
        Forge_PM_Connectors::on_item_created()
          - load enabled connections matching $type
          - wp_schedule_single_event( now, 'forge_pm_push_item', [ $conn_id, $type, $id, 1 ] )
                    │
                    ▼
        Forge_PM_Connectors::deliver()
          - wp_remote_post( url, bearer, JSON envelope )
          - 2xx  → log success
          - else → log failure; reschedule at 60s / 300s / 1800s;
                   give up after attempt 4 (1 immediate + 3 retries)
```

Connectors subscribe to a `forge_pm_item_created` action rather than being called from the REST
callback directly. The REST layer stays unaware of connectors, and the hook is reusable if other
triggers are added later.

## Data model

### Connection (`forge_pm_connections` option)

```ts
export interface Connection {
  id: string;              // uuid
  name: string;            // "Foundry", "Slack #product"
  url: string;             // https:// endpoint
  authTokenHint?: string;  // "••••a1b2" — read-only, derived
  itemTypes: ItemType[];   // which creations fire this connection
  enabled: boolean;
  createdAt: string;
}
```

The bearer token is stored server-side under `authToken` and is **never** included in any REST
response. The client sends `authToken` to set or replace it, and omits the field to leave it
unchanged. Reads receive `authTokenHint` (last 4 characters) purely so the UI can show a key exists.

### Delivery log (`forge_pm_connection_log` option)

```ts
export interface ConnectionDelivery {
  id: string;
  connectionId: string;
  itemType: string;
  itemId: string;
  status: 'success' | 'failed' | 'retrying';
  httpCode?: number;
  error?: string;
  attempt: number;         // 1–4
  timestamp: string;
}
```

Capped at the **50 most recent entries**, trimmed on write, so the option cannot grow unbounded.

## Security

This is the highest-risk part of the feature and drives several decisions.

**Connections are a separate option, not part of `forge_pm_settings`.** App settings are injected into
page HTML — `getInitialSettings()` reads `window.forgePMData.settings`
([`src/app/api/wordpress.ts`](../../../src/app/api/wordpress.ts)) and
[`includes/class-enqueue.php`](../../../includes/class-enqueue.php) prints them on every page load.
A bearer token stored there would be published in page source to every visitor, authenticated or not.
`forge_pm_connections` is never injected and is reachable only over REST.

Further controls:

- **Admin-only.** All connection routes use `manage_options`, not the broader `can_edit_items` used by
  item routes — a Forge Manager can create items but must not read or write API credentials.
- **Write-only token.** Enforced server-side by stripping `authToken` from every response, mirroring the
  existing [`strip_sensitive()`](../../../includes/class-rest-api.php) approach.
- **HTTPS required.** Connection URLs must use `https://`; validated on save.
- **Idempotency.** Each request sends `Idempotency-Key: forge:{type}:{id}` so a retry after an ambiguous
  failure does not create a duplicate on a well-behaved receiver.

**The payload is *not* passed through `strip_sensitive()`.** That method removes `description`, `notes`,
`urls`, and `changeLog`, and exists to sanitise responses for *unauthenticated* visitors. A connector is
a different trust context: an administrator has explicitly nominated the URL and supplied a bearer token,
making it an authenticated destination equivalent to a logged-in REST read. Stripping would also remove
`description` — the single field a receiving system most needs — leaving the payload near-useless.

**`changeLog` is the one field withheld.** It is internal edit history — who changed what and when — of
no use to a receiving system and not something to hand to a third party. `payload_for()` unsets it, and
that accessor is the single choke point for every outbound payload, so no call site can reintroduce it.
`description`, `notes`, and `urls` are still sent.

The control here is that **only an administrator can create a connection**. An admin who configures a
destination is choosing to send full item data there; that is the feature working as intended, and the
Connections UI states plainly that full item content is transmitted.

## Payload

A stable envelope, versioned by `event`, so partners map once:

```json
{
  "source": "forge",
  "event": "item.created",
  "sentAt": "2026-07-15T14:30:00Z",
  "item": {
    "type": "bug",
    "id": "123",
    "name": "Login crash",
    "description": "...",
    "workflowStage": "triage",
    "priority": "high",
    "timeEstimate": 4,
    "links": []
  }
}
```

`item` is the output of the existing `shape_*()` methods in
[`includes/class-rest-api.php`](../../../includes/class-rest-api.php) — the same shape the app itself
consumes. No second mapping layer is introduced, so the payload cannot drift from the internal model.

Headers: `Content-Type: application/json`, `Idempotency-Key`, and `Authorization: Bearer <token>` when
a token is configured.

### Required accessor (targeted change to `class-rest-api.php`)

`shape_feature()`, `shape_bug()`, … and `read_single_item()` are all **`private static`**, so
`Forge_PM_Connectors` cannot call them. Rather than widen those internals — which would expose the
whole shaping layer as public API — add one narrow accessor:

```php
/** Public, connector-facing: the shaped payload for a single item, or null. */
public static function payload_for( int $post_id ): ?array {
    $item = self::read_single_item( $post_id );
    if ( ! $item ) return null;

    unset( $item['changeLog'] );  // internal edit history — never leaves the site
    return $item;
}
```

`read_single_item()` already resolves the post, dispatches on `post_type`, and returns the same shape
the app consumes, so the accessor is a thin delegation that drops `changeLog`. The private methods stay
private; exactly one new public entry point is added, and the connector depends on that contract alone.

## REST API

New routes in `includes/class-connectors.php`, namespace `forge/v1`, all `manage_options`:

| Method   | Route                      | Purpose                                |
| -------- | -------------------------- | -------------------------------------- |
| `GET`    | `/connections`             | List connections (tokens stripped)     |
| `POST`   | `/connections`             | Create connection                      |
| `PUT`    | `/connections/{id}`        | Update connection                      |
| `DELETE` | `/connections/{id}`        | Delete connection                      |
| `POST`   | `/connections/{id}/test`   | Send a sample payload, return result   |
| `GET`    | `/connections/log`         | Recent deliveries (most recent first)  |

`/test` delivers **synchronously** and returns the HTTP status and any error, so the admin gets
immediate feedback. It is the one intentional exception to async delivery, and it sends a sample
payload with `"event": "item.test"` rather than a real item.

## Settings UI

[`src/app/components/Settings.tsx`](../../../src/app/components/Settings.tsx) is already 1160 lines.
Rather than grow it further, the panel lives in a new file:

```
src/app/components/settings/ConnectionsSection.tsx
```

This introduces a `settings/` subfolder. Existing sections stay where they are — this design does not
move them; the folder simply gives new section components a home, and sections can migrate
opportunistically later.

Registered as `{ id: 'connections', label: 'Connections', Icon: Plug }` in `SECTION_NAV`, and gated on
`isAdmin()` so it is hidden from Managers.

The panel reuses established patterns from `Settings.tsx` (`Card`, `useFeedback`, `SaveFeedback`,
`useSaveApi`) and provides:

- A list of connections: name, host, enabled toggle, item-type badges.
- Add/edit form: name, URL, bearer token (masked; blank means "leave unchanged"), item-type checkboxes.
- A **Test** button per connection, surfacing the status returned by `/test`.
- An **Activity** list of recent deliveries with status, HTTP code, and relative time.

## Standalone dev

`npm run dev` runs without WordPress, so [`src/app/api/mockBackend.ts`](../../../src/app/api/mockBackend.ts)
gains in-memory connection CRUD and log stubs, following the existing `isStandalone()` pattern in
[`src/app/api/wordpress.ts`](../../../src/app/api/wordpress.ts). Mock mode **simulates** delivery and
never makes real network calls — the Test button returns a synthetic success. Without this the panel is
untestable locally and cannot be visually confirmed before commit.

## Error handling

| Failure                         | Behaviour                                                       |
| ------------------------------- | --------------------------------------------------------------- |
| Endpoint returns non-2xx        | Log failure with code; retry at 60s / 300s / 1800s; stop after 4 |
| Network error / timeout         | Same retry path; 10s request timeout                             |
| Connection deleted mid-retry    | Scheduled job exits quietly; no log entry                        |
| Connection disabled mid-retry   | Scheduled job exits quietly                                      |
| Invalid URL on save             | `400` with a validation message; nothing persisted               |
| WP-Cron unavailable             | Documented limitation — delivery waits for the next cron trigger |

Item creation never fails because of a connector. Delivery errors surface only in the activity log.

## Testing

The repo has no automated test framework, so verification is manual and follows the existing workflow:

1. `npm run lint` and `npm run build` must pass.
2. `npm run dev` — create a connection against a request-capture endpoint, create an item of each
   selected type, confirm the payload arrives and the activity log records it.
3. Confirm a disabled connection and an unselected item type do **not** fire.
4. Confirm the token never appears in any REST response or in page source.
5. Confirm the Connections section is hidden for a non-admin role.
6. Visual confirmation by the user in the browser before commit, per `CLAUDE.md`.

## Files

**New**

- `includes/class-connectors.php` — option storage, REST routes, cron delivery, retry, logging
- `src/app/components/settings/ConnectionsSection.tsx` — settings panel

**Modified**

- `forge-project-management.php` — require the class; register `rest_api_init` + cron hooks
- `includes/class-rest-api.php` — `do_action( 'forge_pm_item_created', $type, $id )` on create; add
  public `payload_for( $type, $id )` accessor
- `src/app/types.ts` — `Connection`, `ConnectionDelivery`
- `src/app/api/wordpress.ts` — connection API functions
- `src/app/api/mockBackend.ts` — standalone stubs
- `src/app/components/Settings.tsx` — register the section in `SECTION_NAV`

## Open questions

- **Foundry endpoint.** Foundry cannot receive a push today. Confirm with its owner whether an ingest
  endpoint is planned, and which host is canonical (`foundry.gitwork.co.uk` is live; the documented
  `foundry.gitwork.co` does not resolve).
