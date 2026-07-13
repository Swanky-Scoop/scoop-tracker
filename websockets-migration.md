# REST/POST → WebSockets — what it would take

Analysis only, no code changes. Grounded in the actual current wiring, not
generic WebSocket advice.

## What problem would this actually solve?

Worth naming before scoping the work: **today the client never polls.**
`assets/data/scoop-api.js`'s `refreshPageDomain()` fetches the bundle once
on mount and again after a successful save — nothing refetches on a timer.
So the only real gap WebSockets would close is: *staff member A changes a
slot/tub and staff member B, looking at the same cabinet on another
device, doesn't see it until they reload.* That's a real scenario for
`CabinetWorkflow` specifically (shared physical cabinets, multiple staff),
less so for the reporting-style grids. If that cross-device staleness
isn't actually causing problems day to day, a cheaper fix is a short
`setInterval` re-fetch (still plain REST) before reaching for WebSockets
at all. Said that once; rest of this doc assumes you want the real thing
anyway.

## Current architecture (what's being replaced)

- **Transport**: plain `fetch()` in `ScoopAPI._fetch()` — GET with
  cache-busting `_ts` param + `Cache-Control: no-cache`, POST with a JSON
  body wrapped `{ [envelope_key]: payload }` (see `postJson()`).
- **Auth**: WP session cookie + `X-WP-Nonce` header for writes
  (`scoop_write_permission()` in `includes/_auth.php`); optional HTTP
  Basic Auth for GET-only routes. Nonces are short-lived and tied to the
  logged-in session — see "Auth" below for why this doesn't map cleanly
  onto a long-lived connection.
- **Read path**: one `GET /bundle?types=...` per page load, resolved
  server-side by `scoop_bundle_get()` (`includes/bundle.php`) which
  bulk-fetches every entity every requested grid type "needs"
  (`scoop_bundle_specs()`), cached in a WP transient keyed by an integer
  `scoop_cache_version` (`includes/_cache.php`) that increments on
  `save_post`/`trashed_post`/`untrashed_post`/`deleted_post` — i.e. cache
  invalidation is already global-version, not per-row.
- **Write path**: one `POST` per grid type's route
  (`scoop_routes_config()` in `includes/_config.php`), dispatched by
  `scoop_handle_request()`/`scoop_handle_cells_post()`
  (`includes/rest.php`) into `pods_api()->save_pod_item()`.
- **Business rules live below the REST layer**, in Pods filters
  (`includes/hooks/*.php` — `pods_api_pre_save_pod_item_*`), specifically
  so they apply "regardless of which write path was used" (CLAUDE.md).
  This is the single most important constraint on any transport change:
  whatever replaces REST for writes must still funnel through
  `pods_api()->save_pod_item()`, not touch MySQL directly, or every rule
  in `tub-state.php`/`cabinet-slot.php`/`closeout.php`/`batch-tub.php`
  has to be reimplemented in the new layer and kept in sync by hand.
- **Deployment**: SFTP-on-save to a conventional WP host (`test.` /
  `ops.swankyscoop.net`), no build step, no long-running process today —
  every request is a fresh PHP-FPM/Apache process that starts, handles
  one HTTP request, and exits.

## The one big blocker: hosting

A WebSocket **server** is a long-running process that holds open TCP
connections and reacts to events on its own schedule. Standard PHP
request-response hosting (PHP-FPM behind Apache/Nginx, which is what
"SFTP-on-save to a shared WP host" implies) has no mechanism to keep a
process alive between requests — there's nothing in stock WordPress/PHP
hosting to "add WebSockets to." This isn't a code problem, it's an infra
one, and it's the first thing to resolve before anything else in this doc
matters:

| Option | What it means here |
|---|---|
| **VPS/dedicated box you control** | Run a small Node (`ws`/`socket.io`) or PHP (ReactPHP/Swoole) process yourself, reverse-proxied through Nginx at `wss://`. Needs process supervision (systemd/pm2), its own deploy step (breaks "no build step, SFTP-on-save" — this alone is a philosophy change, not just an addition), and someone to keep it running/patched. |
| **Managed pub/sub service** (Pusher, Ably, soketi-as-a-service, WP-specific plugins that wrap one of these) | WP stays exactly as it is; PHP calls the service's HTTP API to broadcast an event, browser holds a WS connection to *their* servers, not yours. Lowest ops burden, recurring cost, third-party dependency for a business-critical operational tool. |
| **Upgrade current hosting** to something that supports a persistent process (many managed WP hosts explicitly don't) | Unknown without checking what test/ops.swankyscoop.net's host actually allows — flag this as the first thing to verify, not assume. |

Nothing below matters until one of these is picked.

## Auth doesn't carry over as-is

`X-WP-Nonce` is designed for short-lived request/response calls tied to
the current cookie session — WordPress rotates/expires nonces, and a WS
connection can sit open for hours. Needed:

- A handshake step: client connects, presents the nonce/cookie (or a
  purpose-built short-lived token minted by a small new REST endpoint),
  server validates once at connect time.
- A re-auth story for connections that outlive the nonce/session — either
  periodic silent re-handshake or accept the connection drops and the
  client reconnects (simpler, and reconnect-with-backoff is needed anyway
  for normal network blips).
- Whatever validates the handshake needs to ask WordPress "is this a
  valid, logged-in user" — either the WS process runs inside PHP (Swoole)
  and can call WP functions directly, or it's a separate process that has
  to hit a small WP REST endpoint to validate, adding a network hop to
  every connect.

## Bridging WP's write hooks to the WS layer

Cache invalidation today is a single global version bump on `save_post`
(`includes/_cache.php`) — deliberately coarse, not per-row. Real-time push
needs a hook into the same event, but has choices about granularity:

- **Coarse (matches today's model)**: on the same `save_post`/Pods
  post-save hooks, tell the WS layer "something changed, invalidate" —
  clients receive a signal and re-fetch the bundle over the existing REST
  path. Smallest change, keeps 100% of the existing read path, WS is
  *only* a push notification, not a data channel. This is the realistic
  Phase 1.
- **Fine-grained (real payload over the wire)**: broadcast the actual
  changed row/fields, client merges into its in-memory domain instead of
  refetching. Bigger lift — means duplicating (or extracting into a
  shared function) the row-shaping logic that today lives in
  `bundle-fetch.php`'s per-entity fetch/cast functions, so a single
  changed Pod item can be reshaped into the same JSON the bundle would
  have produced, without re-running the whole bundle query.
- Either way, the hook needs a way to reach the WS process from PHP:
  write to a small queue table/Redis the WS process polls, an HTTP call
  from PHP to the WS server's internal API, or (Swoole-in-PHP option)
  literally the same process space. This is new infrastructure regardless
  of which granularity is chosen.

## Client-side changes

- Native `WebSocket` API needs no new dependency (consistent with "no
  build step, no package manager") — but the *reconnect/backoff/heartbeat*
  logic that a bare `WebSocket` doesn't give you for free is real code to
  write and test (network drop, laptop sleep/wake, WP host restart).
- `ScoopAPI` would gain a persistent-connection sibling to `_fetch()` —
  on a push event, either call `refreshPageDomain({force:true})` (coarse
  option above — almost no client change) or merge a delta into
  `this._domain` and re-run each mounted grid's `setDomain()` (fine-grained
  option — touches `mountAllGrids()`'s domain-refresh contract and every
  model's assumption that `setDomain()` receives the *whole* domain, not a
  patch).
- Writes (`postJson`) can stay on REST indefinitely — there's no
  requirement to move POST traffic onto the socket just because reads got
  push notifications. Moving writes to WS too is a separate, larger, and
  much lower-value step (see below).

## If someone asks "should writes go over WebSockets too"

Not recommended. The write path's value is entirely in
`pods_api()->save_pod_item()` and its `pods_api_pre_save_pod_item_*`
filters — WS doesn't remove any of that, it would just be a different
transport carrying the same call, with none of REST's benefits (simple
debugging via Network tab, works with the existing nonce model, cacheable
GETs) and a nontrivial amount of new request/response correlation logic
you get for free with HTTP. The MyISAM-no-transactions caveat in
CLAUDE.md's data-repair policy doesn't change either way — same failure
mode, same "order writes defensively" mitigation, regardless of what
carried the request in.

## Suggested phasing, if this is pursued

1. **Confirm hosting can run a persistent process** (or budget for a
   managed pub/sub service). Everything else is moot until this is a yes.
2. **Coarse push-to-invalidate only**: WS/managed-service tells connected
   clients "bundle changed," client calls the existing
   `refreshPageDomain({force:true})`. No change to bundle shape, cache
   model, or write path. This alone fixes the staleness problem named at
   the top.
3. *(Optional, only if #2's extra refetch traffic actually becomes a
   problem)* Fine-grained payloads over the socket, per above — real
   effort, touches `bundle-fetch.php`'s row-shaping and every model's
   `setDomain()` contract.
4. *(Not recommended — see above)* Moving writes onto the socket.

## Open questions before starting

- Does test/ops.swankyscoop.net's host allow a persistent process at all?
- Self-hosted (VPS + your own WS server, ongoing ops burden) vs. managed
  service (recurring cost, third-party in the loop for an operational
  tool)?
- Is #2 (push-to-invalidate) actually enough, or is the real ask "show me
  someone else's edit as it happens" (needs #3)?
- Who maintains the new always-on process day to day — this is the first
  piece of infrastructure in the project that isn't "a file on the WP
  host," and that's a standing operational commitment, not a one-time
  build.
