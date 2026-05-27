# scoop-tracker

A WordPress plugin that tracks ice cream tub and flavor inventory at Swanky Scoop. WordPress renders a page shell + a `[scoop_grid type="..."]` shortcode; an ES-module JavaScript client takes over and talks to a custom REST API backed by [Pods](https://podsfoundation.org/) custom post types.

The repository root maps to `wp-content/plugins/scoop_rest/` on the server. There is **no build step, no package manager, no test suite** — JS modules are loaded directly by the browser, and deploys happen by SFTP-on-save via the VS Code SFTP extension.

---

## Architecture in one picture

```
┌─ WordPress page ──────────────────────────────────────┐
│ [scoop_grid type="FlavorTub" location="935"]          │
│   ↓ shortcode.php emits                               │
│ <div class="scoop-grid" data-grid-type="FlavorTub"    │
│      data-location="935"></div>                       │
│   ↓ enqueue.php loads app.js as <script type=module>  │
└───────────────────────────────────────────────────────┘
                       │
                       ↓
┌─ assets/app.js ──────────────────────────────────────┐
│ new ScoopAPI().mountAllGrids()                        │
│   ↓ scans DOM for .scoop-grid[data-grid-type]         │
│   ↓ routes each host to one of two patterns:          │
└───────────────────────────────────────────────────────┘
     │                                  │
     ▼ BUNDLE pattern                   ▼ ANALYTICS pattern
┌───────────────────────────┐   ┌───────────────────────────┐
│ GET /scoop/v1/bundle      │   │ GET /scoop/v1/analytics   │
│   ?types=Cabinet,FlavorTub│   │   ?days=30&location=935   │
│ Returns: all entities the │   │ Returns: per-flavor       │
│   page's grids need       │   │   aggregates              │
│ Cached in WP transient    │   │ (computed each call)      │
└───────────┬───────────────┘   └────────────┬──────────────┘
            ▼                                 ▼
   *GridModel.setDomain()          AnalyticsGridModel.fetch()
            ↓                                 ↓
        Grid renders                      Grid renders
```

---

## The two patterns

Every grid view is one of:

### 1. Bundle pattern — read/write CRUD over Pods CPTs

Used for `Cabinet`, `FlavorTub`, `Batch`, `Closeout`, `DateActivity`. The grid edits rows and POSTs them back.

**Server (PHP) — adding a new bundle grid touches these files:**

| File | What you add |
|---|---|
| `includes/_config.php` | Register the route in `scoop_routes_config()`: path, methods, write `mode` (`create` vs `update`), pod name, and an `allowed_fields_cb`. |
| `includes/_specs.php` | Declare which entities the grid needs in `scoop_bundle_specs()` and the per-field column metadata in `scoop_field_specs()`. |
| `includes/_write_fields.php` | The `allowed_fields_cb` named above — returns the whitelist of fields this route may write. |
| `includes/_policy.php` *(if new role gating needed)* | Adjust `scoop_user_can_route()` / `scoop_user_writeable_fields()`. |
| `includes/hooks/*.php` *(if business rules)* | `pods_api_pre_save_pod_item_*` filters enforce rules at the data layer regardless of write path. |

Once configured, the rest is automatic: `_routes.php` reads `scoop_routes_config()` to register REST routes, `rest.php`'s `scoop_handle_request()` dispatches the writes, and `enqueue.php` ships the per-user write-field intersection to the client as `SCOOP.routes` + `SCOOP.metaData`.

**Client (JS) — adding a new bundle grid touches:**

| File | What you add |
|---|---|
| `assets/models/<type>-grid-model.js` *(new)* | Extends `BaseGridModel`. Implements `buildCols()` and `buildRows()` (or `buildGroupedRows()` for grouped views). Consumes `this.domain.<entity>` arrays. |
| `assets/data/scoop-api.js` | Import the new model and add it to `getModelsBom()` so `mountAllGrids()` can construct it. |

**Write envelope convention**: every POST body is `{ "<EnvelopeKey>": { cells: [...] } }` where `EnvelopeKey` matches the route key in `scoop_routes_config()`. `ScoopAPI.postJson(payload, type)` wraps this for you.

**Caching**: bundle responses are cached in a WP transient keyed by a global integer version (`scoop_cache_version`). Any `save_post`/`trashed_post`/`untrashed_post`/`deleted_post` bumps that version, invalidating every cached key. `inventory_change` saves are excluded (they fire too often and don't change grid data the client cares about).

### 2. Analytics pattern — read-only aggregates

Used for `Analytics`, `Popular`, `Flavors`. The grid is read-only (save button hidden via CSS) and bypasses the bundle entirely.

**Server**: one file, [`includes/analytics.php`](includes/analytics.php), self-registers `GET /scoop/v1/analytics` and emits per-flavor aggregates (sales velocity, current stock, last batch date, sellthrough, trend, allergens). To add a new aggregate, add a `Step N` block in `scoop_analytics_handler()` and surface it in the per-flavor row.

**Client**: extend `AnalyticsGridModel` and override `buildCols()` / `buildRows()` to project the existing analytics response into your view. No new server work needed if the data is already in the response.

**Adding a new analytics-pattern grid touches just:**

| File | What you add |
|---|---|
| `assets/models/<type>-grid-model.js` *(new)* | Extends `AnalyticsGridModel`. |
| `assets/data/scoop-api.js` | Import, register in `getModelsBom()`, add the type to the `analyticsTypes` Set inside `mountAllGrids()`, and add a branch that constructs the model + a plain `Grid` (or a custom view like `PopularPlot`). |
| `assets/css.css` | Extend the read-only save-button-hide rule to cover the new `data-grid-type`. |

No shortcode changes are ever needed — `shortcode.php` passes any `type=` value through as `data-grid-type`.

---

## Key conventions

- **Underscored PHP files** (`_config.php`, `_specs.php`, `_policy.php`, `_write_fields.php`) are the configuration/contract layer; non-prefixed files are runtime. Underscored JS files (`_base-grid-model.js`, `_flavor.js`, `_column-provider.js`) are abstract bases / helpers.
- **Grouped rows**: `BaseGridModel.buildGroupedRows({ groupsMap, getGroupLabel, fillRow, ... })` produces collapsible row groups (e.g. by cabinet, by flavor, by diet). See `cabinet-grid-model.js`, `date-activity-grid-model.js`, `flavors-grid-model.js`.
- **Permissions are two-layer**: route-level (`scoop_user_can_route()`) gates the request; field-level intersects `spec.writeable` with `scoop_user_writeable_fields()` and ships per-column `write` flags so the JS knows what's editable for the current user.
- **Cache-busting on GET**: `ScoopAPI._fetch` appends `_ts=<now>` and sets `Cache-Control: no-cache` on every GET. The WP transient layer is what actually saves the round trip.
- **Debug**: set `define('SCOOP_DEBUG_LOG', true)` in `wp-config.php` to enable `scoop_debug_log()` output to the PHP error log.
- **Prettier**: `.prittierrc` (note the typo in the filename) is `tabWidth: 2`, `useTabs: false`, `singleQuote: true`.

---

## File map

```
scoop_rest.php              ← Plugin entry; requires the includes/* files
includes/
  _config.php               ← Single source of truth for routes (bundle pattern)
  _specs.php                ← Entity needs per grid type + field column defs
  _routes.php               ← Registers REST routes from _config.php
  _policy.php               ← Role/field permission gates
  _write_fields.php         ← Per-route allowed-field callbacks
  _cache.php                ← Transient versioning
  rest.php                  ← Bundle write dispatcher
  bundle.php                ← Bundle endpoint
  bundle-fetch.php          ← Bulk Pods fetch + relationship resolution
  analytics.php             ← Self-registered analytics endpoint (read-only)
  shortcode.php             ← [scoop_grid] → <div data-grid-type=...>
  enqueue.php               ← Ships SCOOP.routes / SCOOP.metaData / nonce / user
  hooks/*.php               ← pods_api_pre_save_pod_item_* business rules
assets/
  app.js                    ← Bootstraps ScoopAPI + mountAllGrids
  data/
    scoop-api.js            ← Mounting, bundle fetch, model registry
    form-codec.js           ← Encodes/decodes grid edits to wire format
  models/
    _base-grid-model.js     ← Base class: columns, rows, grouped rows
    _flavor.js              ← Flavor metadata + badges
    _column-provider.js     ← Column metadata accessor
    analytics-grid-model.js ← Base for read-only analytics-derived grids
    *-grid-model.js         ← One per grid type
  ui/
    grid.js                 ← The table renderer + sort/filter/edit UI
    popular-plot.js         ← Custom SVG view for the Popular type
    find-in-grid.js         ← Text filter widget
  css.css                   ← All styles
```

---

## Data sources

Files in `data-exports/` are not part of the runtime — they are external reference data that informs forecasting and UI design.

### [data-exports/Waffle Cone (in store) Sales.csv](data-exports/Waffle%20Cone%20(in%20store)%20Sales.csv)

Three years (2023–2025, with a tail of Dec 2022) of daily in-store cone sales. The basis for forecasting expected cone demand — and by extension, expected tubs of ice cream needed in production, since cone sales correlate tightly with tub consumption.

**Layout:**
- Pivot table — each year occupies its own block, stacked vertically.
- Days of the week run **vertically** (Monday … Sunday + Totals row) so day-of-week trends are easy to read at a glance.
- Columns are pairs across the weeks of the year: each week has **two numeric values**:
  - first number = **regular waffle cone** sales (usually the larger)
  - second number = **gluten-free waffle cone** sales
- The `Totals` row carries both per-week sums and a per-year grand total in column 2, making year-over-year growth easy to see.

**Intended use:** drives the "expected tubs/cones needed this week" widget in the planned kitchen production dashboard — see [GUI-planning.md](GUI-planning.md). Translating cones-sold to tubs-needed requires a scoops-per-tub × cones-per-scoop conversion factor that has not yet been pinned down.

---

See [CLAUDE.md](CLAUDE.md) for deeper architectural detail, [INTEGRATION.md](INTEGRATION.md) for the historical reference on how the Analytics pattern was added, [CHANGELOG.md](CHANGELOG.md) for a curated log of notable changes with the "why" behind each, [performance.md](performance.md) for the standing performance punch list, and [GUI-planning.md](GUI-planning.md) for in-flight UI direction and the use-case backlog.
