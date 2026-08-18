# REST conventions

Every endpoint in Blueworx Forge follows what is written here. The point is that
correctness is structural rather than remembered: a route that breaks one of
these conventions fails a test, rather than passing review because nobody
recalled the rule.

The reference implementation is `includes/Rest/StatusController.php`. It is the
file to copy when adding an endpoint.

## The namespace

`blueworx-forge/v1`, held in `Rest\Server::NAMESPACE`.

The version is in the path, not a header. A breaking change ships as `/v2`
alongside `/v1` rather than by changing what `/v1` means underneath a client that
cannot be redeployed at the same moment — client sites update on their own
schedule.

## Every route says who may call it

Routes are registered through `Rest\Server::register_route()`, never
`register_rest_route()` directly.

WordPress treats a missing or `null` `permission_callback` as "anyone", warns,
and carries on. That default is how one forgotten line becomes an open endpoint.
`register_route()` refuses instead: a route with no answer to "who may call
this" does not register at all, so the mistake is a failed test rather than a
live hole.

A deliberately public route says so explicitly, with `Permissions::read()`, and
carries a comment saying why.

`RestConventionsTest` walks everything `Server::register_routes()` actually
registers and asserts each one declares a callback, so a controller added later
cannot slip a bare route past the suite.

## One error envelope

Ordinary refusals are `WP_Error`, built by `Rest\Errors::rest()`. WordPress
renders those in its own `{ code, message, data: { status } }` shape — the same
shape core uses, so a client parses one thing rather than two.

Every code carries the `bwx_forge_` prefix, so our refusals are distinguishable
from core's. Anything the client needs in order to recover goes in `data`.

## Gate failures are not errors

A gate failure means the request was well-formed and the caller was allowed to
make it — the item simply is not ready to move. It has its own body, built by
`Rest\Errors::gate_failure()` and fixed by the "Gate-failure contract" section of
[`workflow-state-machine.md`](workflow-state-machine.md):

```json
{
  "ok": false,
  "item_id": "...",
  "stage": "up-next",
  "attempted": "in-development",
  "unmet": [
    {
      "id": "G-UP-NEXT-4",
      "label": "Planned hours per role",
      "satisfied_by": "Enter planned hours for Primary User, Reviewer and Deliverer"
    }
  ]
}
```

Two things about it are load-bearing. `stage` is the item's current stage,
unchanged — a failed transition changes nothing. And `unmet` carries **every**
unmet requirement, never just the first one found: a person told about one
missing thing at a time fixes it, resubmits, and is refused again for the next.

## Every write carries the version it was made against

ARCH-5. There is one canonical database, so there are no merge conflicts — only
stale writes.

A write sends `record_version`, the name held in `Rest\Versioning::PARAM`.
`Versioning::check()` compares it with the record's current version and returns:

| Sent | Result |
|---|---|
| The current version | The write proceeds |
| An older version | `409 bwx_forge_stale_write`, with `current_version` and the record's current state |
| A version ahead of current | The same refusal — it was made against something this server never issued |
| Nothing at all | `400 bwx_forge_missing_version` |

A rejection is never merged, and it returns the current state so the person who
made it can see what moved underneath them. The rejection surfaces to that
person rather than into a queue.

## Retried writes produce one record

A write may be retried — a flaky connection, an impatient second click, a client
that resends on timeout. The client sends a key in the `Idempotency-Key` header;
the first attempt under that key does the work and its answer is remembered, and
every later attempt gets that same answer back without the work running again.

Keys are scoped per operation, so two different writes reusing a key cannot
answer each other's replays. A key that is empty, longer than 255 characters, or
outside `[A-Za-z0-9_.:-]` is refused with `400
bwx_forge_invalid_idempotency_key` rather than escaped — there is no reason for
a client to send a key that needs escaping.

Answers are held in transients for 24 hours: long enough to cover a client
retrying after a timeout, short enough that the same key reused next week is a
new write rather than a stale answer. They are a safety net, not an audit trail.

## The order inside a write

Replay, then version, then work:

1. **Replay first.** A retry costs nothing and, crucially, cannot be refused for
   being stale against a version its own first attempt moved. Check the version
   first and every successful retry is a 409.
2. **Then the version check.** A stale write is refused before anything changes.
3. **Then the work**, and remember the answer under the key.

## A client site authenticating itself

ARCH-6. A client WordPress is a machine, not a logged-in user, so it proves
which client it is with a per-site key rather than an account. Routes it may
call use `Permissions::client_site()`; nothing else does.

The studio issues the key at registration, and registration is a manual studio
action — `POST /sites` requires the administrator capability, matching "register
or revoke a client site key" in [`permission-matrix.md`](permission-matrix.md).
There is no route by which a site enrols itself.

Each request carries four headers:

| Header | What it is |
|---|---|
| `X-BWX-Site` | The site id the studio issued |
| `X-BWX-Timestamp` | Unix time the request was signed |
| `X-BWX-Nonce` | Single-use value, never repeated |
| `X-BWX-Signature` | HMAC-SHA256 over the canonical string |

The signature covers the site, the method, the path, the timestamp, the nonce
and a hash of the body. Every part earns its place: without method and path a
signature captured from a read could be replayed onto a write; without the body
hash a request could be edited in flight; without the timestamp a captured
request is valid forever; without the nonce it is valid for the whole five-minute
window, which is plenty.

**Refusals all look the same from outside** — one code, one message, 401 —
whether the site is unknown, revoked, badly signed or replayed. Anything more
specific would let an unauthenticated caller sort real site ids from invented
ones, and tell somebody holding a stolen key that it was genuine and has since
been revoked. The precise reason goes to `Sites\SecurityLog` instead, which is
readable only by an administrator.

**The client signs with its own copy of the canonical form**, in
`client/includes/Signer.php`, because a client site contains no studio code
(ARCH-1) and there is no file both can load. `tests/php/SignerConformanceTest.php`
signs the same inputs with both and fails if they ever differ — which matters,
because drift would surface as "bad signature" on every site at once and send
you looking at keys rather than at code.

## A client site reading canonical records

ARCH-2. The studio holds the records; a client site renders them and keeps no
canonical copy. `GET /client/workspace` returns the record for **the site that
signed the request** — never one named in a parameter, because the signature is
what proves which site is calling and that is the only site it may read.

The client half is a read-through cache, not a replica:

| State | What the client site is showing |
|---|---|
| `live` | Just read from the studio |
| `cached` | A copy less than 60 seconds old (ARCH-5) |
| `stale` | An older copy, because the studio could not be reached (ARCH-4) |
| `unreachable` | Nothing — the studio is unreachable and there is no copy |
| `not_configured` | Nothing — this site has never been connected |

The last two are deliberately different states. "You have nothing" and "we
cannot see your things right now" are different sentences, and only one of them
is ever true.

A failed refresh is recorded next to the copy it failed to replace, so the next
page view still reports the site as out of date rather than going back to
calling itself current — and does not retry on every view, because a studio that
is down does not want a request from every page view on every client site.

## Where each piece lives

| File | What it owns |
|---|---|
| `includes/Rest/Server.php` | The namespace, the registration door, the controller list |
| `includes/Rest/Permissions.php` | Every permission callback, as testable static methods |
| `includes/Rest/Errors.php` | The error envelope and the gate-failure body |
| `includes/Rest/Versioning.php` | Stale-write rejection |
| `includes/Rest/Idempotency.php` | Idempotency keys |
| `includes/Rest/Signature.php` | Signed-request verification for client sites |
| `includes/Sites/Registry.php` | Registered sites, and their keys |
| `includes/Sites/SecurityLog.php` | Every refused client-site request |
| `includes/Rest/StatusController.php` | The reference implementation |

Tests: `tests/php/RestConventionsTest.php` for each convention in isolation,
`tests/e2e/rest-conventions.spec.js` for all of them assembled against a real
WordPress — because the failure that matters is a helper that is correct and
never actually called.
