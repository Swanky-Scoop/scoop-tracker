# assets/ — Client

The browser-side half of the plugin. Vanilla ES modules, no build step, no framework. Loaded as `<script type="module">` by `includes/enqueue.php` only on pages that contain a `[scoop_grid]` shortcode.

## Bootstrap and request lifecycle

```
PHP enqueues app.js  →  app.js  →  new ScoopAPI()  →  api.mountAllGrids()
                                                          │
                          ┌───────────────────────────────┴────────────────────────────────┐
                          ▼                                                                ▼
              Bundle pattern hosts                                          Analytics pattern hosts
              (Cabinet, FlavorTub, Batch,                                   (Analytics, Popular, Flavors)
               Closeout, DateActivity)
                          │                                                                │
              GET /scoop/v1/bundle?types=...                                GET /scoop/v1/analytics?days=...
                          │                                                                │
              ScoopAPI.refreshPageDomain()                                  Model.fetch() directly
                          │                                                                │
              For each host:                                                For each host:
                model = new <Type>GridModel(domain)                           model = new <Type>GridModel()
                grid  = new Grid(host, ...)                                   await model.fetch()
                grid.init(model)                                              grid = new Grid(host, ...) | PopularPlot
                                                                              grid.init(model)
```

Edits flow back via `ScoopAPI.postJson(payload, type)` which wraps the cells in a `{ "<EnvelopeKey>": { cells: [...] } }` envelope and POSTs to the per-type route.

## File map

### Root

| File | Role |
|---|---|
| `app.js` | Entry point. Reads the `SCOOP` global (nonce, routes, metaData, user) injected by `includes/enqueue.php`, constructs `ScoopAPI`, gates on `userHelper()`, then `mountAllGrids()`. |
| `css.css` | All styles. Includes grid layout, popular plot, allergen filter chips, group headers, alert-case color coding (`supply-critical`, `trend-rising`, etc.), and the read-only save-button-hide rules for analytics-pattern grids. |
| `Main.js` | **Scratch file**, not part of the runtime. Safe to ignore. |
| `snippets/` | Standalone PHP/JS snippets used in WordPress admin / mu-plugin contexts, not loaded by the plugin. `sync-tracker-to-webiste.php` syncs flavor availability categories to the storefront site based on cabinet/slot state. |

### `data/` — API, transport, domain shaping

| File | Role |
|---|---|
| `scoop-api.js` | The central client. Owns: route map, nonce, bundle URL composition, in-memory bundle cache, host discovery, `mountAllGrids()`, `getModelsBom()` (model registry by grid type), `refreshPageDomain()`, `postJson()`. **Touched whenever a new grid type is added.** |
| `form-codec.js` | Encodes/decodes grid edits between the DOM (`<input name="Cabinet[cells][12][current_flavor]">` hidden inputs) and the JSON envelope sent to POST. Parses bracket-named inputs, normalizes scalars, builds the `{ cells: { rowId: { colKey: value } } }` payload. |
| `domain-codec.js` | Decodes the raw bundle response into a canonical client-side domain. Coerces relationship fields into scalar IDs, computes `_title` strings from WP-shaped titles, normalizes embedded `tub` records inside `flavor`. Centralizes all "WP shape quirks". |
| `indexer.js` | Tiny utility: `byId(list)` → `Map<id, item>` and `groupBy(list, keyFn)` → `Map<key, item[]>`. Used by grid models to assemble lookup tables from bundle arrays. |

### `models/` — Per-grid-type data shaping

Each grid type has one `*GridModel` class. Its job: take the domain (or analytics response), build the column list (`buildCols()`), and produce the rows (`buildRows()` or `buildGroupedRows()`).

| File | Role |
|---|---|
| `_base-grid-model.js` | Base class for every grid model. Owns `columns`, `rows`, `rowGroups`, `setDomain()`, default `buildRows()`, and the `buildGroupedRows({ groupsMap, getGroupLabel, fillRow, ... })` helper used for collapsible row groups. |
| `_flavor.js` | Flavor-specific helpers shared across models: `flavorsById` index, badge generation (`badges(id, spec)`), slot/cabinet allergen lookups. Used by any model whose rows reference a flavor. |
| `_column-provider.js` | Reads `SCOOP.metaData` (server-shipped per-user-per-entity column specs) and produces concrete column definitions for a grid type. Filters columns by `visible` and applies per-user `write` flags. |
| `analytics-grid-model.js` | Base class for **read-only analytics-pattern grids**. Constructs the standalone fetch (`GET /scoop/v1/analytics`), parses the response into rows. Subclass and override `buildCols()` / `buildRows()` to project the same response into a different view. |
| `cabinet-grid-model.js` | Slot rows grouped by cabinet. Bundle pattern. |
| `batch-grid-model.js` | Batch creation rows. Bundle pattern, `mode: 'create'`. |
| `flavor-tub-grid-model.js` | Tub rows with use/state/amount editing. Bundle pattern. The grid most users spend the most time in. |
| `closeout-grid-model.js` | Closeout entry rows. Bundle pattern, `mode: 'create'`. |
| `date-activity-grid-model.js` | Inventory-change activity grouped by flavor, scoped to a date window. Bundle pattern, read-mostly. |
| `analytics-grid-model.js` (as concrete) | Per-flavor sales velocity dashboard. |
| `popular-grid-model.js` | Subset of analytics for the popularity scatter plot — sorts by `total_sold` × `avg_sellthrough_days` and exposes an allergen filter. Paired with `ui/popular-plot.js`. |
| `flavors-grid-model.js` | All flavors, grouped by dairy/non-dairy, columns: Flavor, Tubs (`current_stock`), Days Since Served (from `last_batch_date`). |

### `ui/` — View components and widgets

| File | Role |
|---|---|
| `grid.js` | The table renderer. Builds `<thead>` / `<tbody>` from the model's columns + rows, mounts edit widgets (`TextIt`, `FindIt`), handles sort, group collapse, save form, and the "find in grid" widget. The largest single file in the client. |
| `_el.js` | `El` base class — a small `el(tag, { text, classes, attrs, data, on, ... })` factory for DOM construction. Inherited by widgets that need element creation. |
| `_find.js` | Static utility (`Find`) for fuzzy text matching against options. Consumed by both `FindIt` (in-cell typeahead) and `FindInGrid` (table-wide filter). |
| `find-it.js` | `FindIt` — type-to-complete input rendered inside a grid cell. Used wherever a cell value is one option from a list (a flavor, a use, etc.). |
| `text-it.js` | `TextIt` — basic text/number input rendered inside a grid cell. The default editor for non-relationship fields. |
| `find-in-grid.js` | The grid's built-in text filter widget. Hides rows / row groups whose label or content doesn't match. Enabled per-model by setting `this.filter = true`. |
| `toast.js` | Notification host. POST success/failure messages bubble up here. |
| `popular-plot.js` | Custom SVG scatter-plot view for `data-grid-type="Popular"`. Replaces the standard table render; paired with `PopularGridModel` and includes its own allergen filter chip row. |

## Conventions

- **Underscored files** (`_base-grid-model.js`, `_flavor.js`, `_column-provider.js`, `_el.js`, `_find.js`) are abstract bases or shared helpers — never instantiated as concrete grid types or widgets.
- **Cell shape**: every grid row cell is `{ display, value, alertCase? }`. `display` is the string the grid renders, `value` is what the sorter compares, `alertCase` (optional) becomes a CSS class on the `<td>`.
- **Cache-busting on GET**: `ScoopAPI._fetch` appends `_ts=<now>` and sets `Cache-Control: no-cache` on every GET. The WP transient layer on the server is what actually saves the round trip.
- **Read-only grids**: `Analytics`, `Popular`, and `Flavors` bypass the bundle pattern. The save button is hidden via a CSS selector on `data-grid-type`.
- **Mounting branch table**: in `ScoopAPI.mountAllGrids()`, analytics-pattern types are in an `analyticsTypes` Set; each gets its own construction branch. Bundle-pattern types are constructed in a single loop using `getModelsBom()`.

## Adding a new grid view

See the project [README.md](../README.md) for the high-level pattern. The short version:

- **Read-only over analytics data already in the response**: 1 new file in `models/` + 3 edits to `data/scoop-api.js` + 1 CSS line.
- **CRUD over a Pods CPT**: 1 new file in `models/` + 1 edit to `data/scoop-api.js` (registry only) + server-side changes in `includes/` (route config, specs, allowed-fields callback).
