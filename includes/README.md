# includes/ — Server

The PHP half of the plugin. Loaded by `scoop_rest.php` (the plugin entry one level up), which requires each of these files in order. Runs inside WordPress, using the [Pods](https://podsfoundation.org/) plugin's API for all CPT reads/writes — no raw SQL.

## Request lifecycle

```
WordPress page renders
   ↓ [scoop_grid type="..."] hits → shortcode.php
   ↓ <div class="scoop-grid" data-grid-type="..."> emitted
   ↓ enqueue.php enqueues app.js + injects SCOOP global (routes, metaData, nonce, user)

Browser issues requests
   │
   ├──→ GET /wp-json/scoop/v1/bundle?types=...
   │       _routes.php (registered) → bundle.php → bundle-fetch.php (Pods find())
   │       cached in WP transient, keyed by version stamp in _cache.php
   │
   ├──→ POST /wp-json/scoop/v1/<route>
   │       _routes.php → rest.php → Pods API create/update
   │       hooks/* fire on pods_api_pre_save_pod_item_* to enforce business rules
   │       save_post bumps cache version → next bundle GET is a miss
   │
   └──→ GET /wp-json/scoop/v1/analytics?days=&location=
           analytics.php (self-registered, bypasses bundle entirely)
```

## File map

### Contract / configuration layer (underscored)

These files are the source of truth that everything else reads from. Adding a new grid type starts here.

| File | Role |
|---|---|
| `_config.php` | `scoop_routes_config()` — the single source of truth mapping grid types to URL paths, methods, write `mode` (`create` vs `update`), Pod name, envelope key, and the `allowed_fields_cb` name. Also defines `scoop_debug_log()`. |
| `_specs.php` | `scoop_bundle_specs()` — declares which raw entities each grid type needs in the bundle. `scoop_field_specs()` — per-field data type / relationship / column metadata used by both the bundle decoder and the client column provider. |
| `_policy.php` | `scoop_access_policy()` — role-keyed permission matrix (`administrator`, `editor`, `author`, `_default`). `scoop_can_route()` / `scoop_user_can_route()` and `scoop_user_writeable_fields()` gate every request. Also defines the custom role registration helpers run at activation. |
| `_write_fields.php` | The `allowed_fields_cb` callbacks listed in `_config.php` (`scoop_planning_allowed_slot_fields`, `scoop_batches_allowed_fields`, `scoop_tubs_allowed_fields`, `scoop_closeouts_allowed_fields`). Each returns the whitelist of Pods field slugs the route may write. Also defines `scoop_save_pod_field()` and `scoop_coerce_value()`. |
| `_fields.php` | Lower-level field-list helpers and coercion utilities used by the write layer. |
| `_auth.php` | `scoop_validate_basic_auth()` — Basic Auth (read-only only). Combined with WordPress session/cookie auth (required for writes). Used by `permission_callback`s in `_routes.php`. |
| `_cache.php` | `scoop_cache_version()` (the global integer version stamp) and the hooks (`save_post`, `trashed_post`, `untrashed_post`, `deleted_post`) that bump it. Bumping the version invalidates every cached bundle key at once. `inventory_change` post saves are excluded — they fire too often and don't change grid data the client cares about. |
| `_pods_helpers.php` | Shared Pods utilities: `scoop_nodate()`, `scoop_rel_id()`, `scoop_post_names_out()`, plus the `scoop_pods_api_save()` wrapper that recursion-guards and consistently fires the right Pods hooks. |

### Routing / request handling

| File | Role |
|---|---|
| `_routes.php` | `rest_api_init` action — reads `scoop_routes_config()` and registers one `register_rest_route()` per grid type, with `methods`, a `callback` that dispatches into `rest.php`, and a `permission_callback` from `_policy.php`. |
| `rest.php` | `scoop_handle_request()` — the write dispatcher. Reads the `{ "<EnvelopeKey>": { cells: [...] } }` body, applies the route's `allowed_fields_cb`, intersects with `scoop_user_writeable_fields()`, then dispatches each cell to `scoop_pods_api_save()` (update mode) or `pods_api()->save_pod_item()` (create mode). Returns per-row results so the client can show success/failure per cell. |
| `enqueue.php` | `scoop_enqueue_assets()` — registers `assets/app.js` (rewritten to `type="module"` via the `script_loader_tag` filter), `assets/css.css`, and injects the `SCOOP` global with: `scoop_client_routes()` (route map), `scoop_client_metadata()` (per-field column defs + per-user `write` flags), `nonce`, and `user`. Only fires on pages whose content contains `[scoop_grid]`. |
| `shortcode.php` | `[scoop_grid type="..." location="..."]` handler. Emits a single `<div class="scoop-grid <type>" data-grid-type="<type>">` host element. Generic — passes any `type` value through, so a new grid type needs no shortcode changes. |

### Read paths

| File | Role |
|---|---|
| `bundle.php` | `GET /scoop/v1/bundle?types=...` handler. Reads the transient cache (keyed by version + types + filters), or on miss delegates to `bundle-fetch.php`, stores the result, and returns it. Stamps the response `_cache: hit | miss`. |
| `bundle-fetch.php` | Bulk-fetches each requested entity with `pods()->find()` (one query per entity type, not per row), reads scalar columns from `$pod->row`, and resolves relationships via `$pod->field()`. Includes per-entity decoders that map Pods quirks (zero-dates, embedded relationships, post_name slug arrays) into a stable wire shape the client's `DomainCodec` then digests. |
| `analytics.php` | Self-registers `GET /scoop/v1/analytics`. Computes per-flavor aggregates (sales velocity, sellthrough, current stock, last batch date, allergens, trend) over a `days` window, optionally scoped by `location`. Each computation step is independently fault-isolated — a Pods schema quirk in one step degrades that section to `null` and surfaces the failure in a `degraded[]` array rather than blanking the whole response. Includes a per-request `trace_id` echoed in both error_log output and the JSON response. |

### Business rules (`hooks/`)

These files register `pods_api_pre_save_pod_item_*` filters that fire on **every save path** (REST, WP admin, direct Pods API). Prefer adding rules here over patching `rest.php` so the rule holds regardless of save mechanism.

| File | Role |
|---|---|
| `batch-tub.php` | When a batch is saved, generate a stable title/slug, then deterministically create the N child tubs (`count` field) using the Pods API so downstream tub hooks fire correctly. Recursion-guarded. |
| `cabinet-slot.php` | Derives the cabinet title/slug from `location` + dairy/non-dairy + `max_tubs`. After a cabinet save, creates the slot child rows once (if none exist). |
| `tub-state.php` | Auto-sets `created_on` / `changed_on` on tub creation. Enforces the tub state machine (`Hardening` → `Opened` → `Emptied`), and when a tub flips to `Emptied`, demotes it to `post_status='draft'` so it falls out of the "active" queries cleanly. |
| `closeout.php` | When a closeout is created with `tubs_emptied` + `flavor` + `location` + `use`, finds matching tubs (partial within ±0.2 if needed, then whole tubs preferring Opened/oldest) and flips them to `Emptied`. The closeout record is the human-entered summary; the tub-state side-effect is the authoritative inventory update. |
| `legacy.php` | TODO/remove — historical helpers, mostly commented out. Safe to ignore. |

### Misc / admin / scratch

| File | Role |
|---|---|
| `pods.php` | Custom role definitions registered with WordPress at plugin activation. |
| `admin-page.php` | Adds a wp-admin command-test page for poking at the REST endpoints from inside the dashboard. Restricted to `edit_posts` capability. |
| `dump.php` | Debug helper for dumping the bundle JSON to disk. Used during development; not wired into the runtime. |
| `log.json` | Captured error log artifact from a failed request. Scratch, not part of the runtime. |

## Conventions

- **Underscored PHP files** (`_config.php`, `_specs.php`, `_policy.php`, `_write_fields.php`, `_cache.php`, `_auth.php`, `_fields.php`, `_pods_helpers.php`) are the configuration/contract layer. Non-prefixed files are runtime (handlers, hooks, endpoints).
- **Two-layer permissions**: route-level (`scoop_user_can_route()` decides GET/POST per route) and field-level (`spec.writeable ∩ scoop_user_writeable_fields()` decides what columns are editable). The field-level intersection is computed in `scoop_client_metadata()` and shipped to the JS as a per-column `write` flag.
- **Write envelope**: every POST body is `{ "<EnvelopeKey>": { cells: [...] } }`. `EnvelopeKey` matches the route key in `scoop_routes_config()`.
- **Pods API over raw SQL**: relationship storage varies by Pods version (meta vs. table storage), so `$pod->field()` is used for relationships and `$pod->row[...]` only for confirmed direct columns. The same rule lets the analytics handlers filter by `location` in PHP rather than pushing it into a WHERE clause that would 500 with "Unknown column".
- **Cache strategy**: bundle responses go in WP transients keyed by `(version, types, filters)`. Version is a global integer bumped by `save_post`/`trashed_post`/`untrashed_post`/`deleted_post`, so any write invalidates every cached key at once. `inventory_change` saves are excluded (high frequency, irrelevant to grid data).
- **Trace IDs**: analytics responses include a `trace_id` echoed in `error_log`. Copy the ID from devtools and grep the PHP error log for the full stack — the single thing that makes "Database error surfaces in the browser" debuggable without live access.
- **Debug logging**: `define('SCOOP_DEBUG_LOG', true)` in `wp-config.php` enables `scoop_debug_log()` output.

## Adding a new grid type (server side)

See the project [README.md](../README.md) for the high-level pattern. The short version for a bundle-pattern (CRUD) grid:

1. Add a route entry in `_config.php` (`scoop_routes_config()`).
2. Declare entity needs + field definitions in `_specs.php`.
3. Add the `allowed_fields_cb` to `_write_fields.php`.
4. If the route has business rules (e.g. "when X saves, derive Y"), add them as `pods_api_pre_save_pod_item_*` filters in `hooks/`.
5. (Optional) Adjust `_policy.php` if a new role needs gating.

Then on the client, add the model (`assets/models/<type>-grid-model.js`) and register it in `assets/data/scoop-api.js`.

For an analytics-pattern (read-only) grid, no server changes are needed if the data is already in the analytics response.
