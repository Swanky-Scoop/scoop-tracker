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
---

## Database schema (Pods, tables mode)

Pods is configured in **tables mode**, not the default postmeta mode. This is the single most important schema fact to internalize before touching the persistence layer.

Implication: for a Pod item to be visible to any Pods query (wp-admin, the bundle endpoint, the analytics endpoint, every grid model), **two rows must exist in lockstep** — one in `wp_posts` and one in the per-Pod table `wp_pods_<podname>`. If only one exists, the item is functionally invisible even though raw SQL can see it. Skipping the per-Pod table row is exactly what broke the 2026-05-27 direct-write attempt (see [performance.md](performance.md) finding #6).

The DB prefix on this install is `track_` (not `wp_`). A complete `mysqldump --no-data` lives at [schema.sql](schema.sql) for reference. Key Pods-specific tables and their purpose:

### `track_pods_<podname>` — per-Pod scalar storage

One table per Pod that has at least one non-relationship field. Each row's `id` is the same as the corresponding `track_posts.ID`. Relationship fields are NOT stored here — they all go through `track_podsrel`.

**`track_pods_tub`** — the hottest table in the schema:
```sql
CREATE TABLE `track_pods_tub` (
  `id`         bigint unsigned NOT NULL AUTO_INCREMENT,  -- = track_posts.ID
  `opened_on`  datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `emptied_at` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `state`      longtext,             -- enum: Hardening, Freezing, Tempering, Opened, Emptied, __override__
  `index`      decimal(2,0),         -- tub position within its batch
  `amount`     decimal(3,2),         -- fractional amount, 0.00–1.00 (NULL = full)
  `created_on` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `changed_on` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (`id`)
) ENGINE=MyISAM;
```

Notice: no `batch`, `flavor`, `location`, `use`, `closeout`, or `cabinet` columns. All of those are relationships and live in `track_podsrel`.

**Other Pod tables and their notable scalar fields:**
- `track_pods_batch` — `count` decimal(6,2) (number of tubs to create)
- `track_pods_flavor` — `unit_price`, `web_id`
- `track_pods_cabinet` — `max_tubs`
- `track_pods_closeout` — `tubs_emptied` decimal(5,3)
- `track_pods_slot` — `index`, `reload`
- `track_pods_nightly_sales` — `waffle_cones`, `waffle_cones_gf`, `sales_date`, `temperature`, `weather_quality`
- `track_pods_inventory_change` — `entity`, `mode`, `phase`, `source`, `problem`, `change_count`, `details`, `envelope`, `success`, `errors`
- `track_pods_recipe` — many scalar fields (yields, ingredient list strings, allergens, cost)
- `track_pods_ingredient` — purchase-side scalars (case, pack, yield, price, supplier)
- `track_pods_allergen`, `track_pods_location`, `track_pods_use`, `track_pods_profile`, `track_pods_base` — id only (these Pods have no non-relationship fields, so the table is effectively a placeholder for Pods's record-existence check)

### `track_podsrel` — all relationships

Every relationship between Pod items lives here as a single row. There is no foreign key — Pods writes both sides if the relationship is bidirectional.

```sql
CREATE TABLE `track_podsrel` (
  `id`               bigint unsigned NOT NULL AUTO_INCREMENT,
  `pod_id`           int unsigned,    -- ID of the Pod owning this side (e.g. tub pod's ID)
  `field_id`         int unsigned,    -- ID of the field (e.g. tub.batch field)
  `item_id`          bigint unsigned, -- track_posts.ID on this side (e.g. the tub)
  `related_pod_id`   int unsigned,    -- ID of the Pod on the other side (e.g. batch pod)
  `related_field_id` int unsigned,    -- field on the other side, NULL for one-way
  `related_item_id`  bigint unsigned, -- track_posts.ID on the other side (e.g. the batch)
  `weight`           smallint unsigned DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `field_item_idx`         (`field_id`,`item_id`),
  KEY `rel_field_rel_item_idx` (`related_field_id`,`related_item_id`),
  KEY `field_rel_item_idx`     (`field_id`,`related_item_id`),
  KEY `rel_field_item_idx`     (`related_field_id`,`item_id`)
) ENGINE=MyISAM;
```

Resolve the `pod_id` and `field_id` values via `pods_api()->load_pod(['name' => 'tub'])` at runtime — they are stable per install but not portable across installs.

### `track_posts` — standard WordPress

Every Pod item is also a WP post with `post_type` matching the Pod name (`tub`, `batch`, `flavor`, etc.). The `post_title` is whatever the hook chain generates (e.g. batches get `<FlavorName> <Y-m-d H:i>_<count>`, tubs get `<batch_title>|<index>`). `post_status='draft'` is used by `tub-state.php` to hide emptied tubs from active queries.

### The integrity rule, restated

A correctly-created tub means **all of these** rows exist:

1. `track_posts` row with `post_type='tub'`, `post_status='publish'`.
2. `track_pods_tub` row with the same `id`, populated with `index`, `amount`, `state`, the two `*_on` timestamps.
3. `track_podsrel` **forward** rows for `batch`, `flavor`, and `location` relationships — `item_id = tub_id`, `related_item_id = batch_id / flavor_id / location_id`.
4. `track_podsrel` **reverse** rows for the same three relationships — `item_id = batch_id / flavor_id / location_id`, `related_item_id = tub_id`. Pods stores every bidirectional relationship as **two rows**, one per direction. Skipping the reverse rows is what caused batch 8394 to display no tubs in wp-admin on 2026-05-27 even though the forward rows were correct.

Skipping any of these produces an orphan or a one-sided relationship that's invisible to part of the stack.

### Integrity audit queries

Both should return **zero rows** on a healthy database. Worth running after any direct schema work or any change to the batch-create path.

**1. Orphan tub posts (post-table-only with no per-Pod row):**

```sql
SELECT p.ID, p.post_title
FROM track_posts p
LEFT JOIN track_pods_tub t ON t.id = p.ID
WHERE p.post_type = 'tub' AND t.id IS NULL;
```

**2. Bidirectional drift between batches and their tubs** — the forward count (tubs pointing at batch) and reverse count (batch pointing at tubs) must match for every batch:

```sql
-- Resolve field IDs via the Pods config (don't hardcode):
SELECT b.ID AS batch_id, b.post_title,
  (SELECT COUNT(*) FROM track_podsrel
   WHERE related_item_id = b.ID
     AND field_id = (SELECT ID FROM track_posts
                     WHERE post_type='_pods_field' AND post_name='batch'
                       AND post_parent = (SELECT ID FROM track_posts
                                          WHERE post_type='_pods_pod' AND post_name='tub'))
  ) AS forward_tub_to_batch,
  (SELECT COUNT(*) FROM track_podsrel
   WHERE item_id = b.ID
     AND field_id = (SELECT ID FROM track_posts
                     WHERE post_type='_pods_field' AND post_name='tubs'
                       AND post_parent = (SELECT ID FROM track_posts
                                          WHERE post_type='_pods_pod' AND post_name='batch'))
  ) AS reverse_batch_to_tubs
FROM track_posts b
WHERE b.post_type = 'batch'
HAVING forward_tub_to_batch <> reverse_batch_to_tubs;
```

The same shape applies to flavor↔tub and location↔tub — swap the Pod names in the inner subqueries (`flavor`/`tubs` for flavor; `location`/`tubs` for location).

### Running the audit: `wp scoop audit`

The plugin registers a WP-CLI command that runs both checks above (plus the flavor↔tub and location↔tub variants) and exits non-zero if any issue is found. Lives at [includes/cli.php](includes/cli.php) and is loaded only under WP-CLI (zero overhead in normal requests).

**On Local (Windows):** right-click the site in the Local app → **Open Site Shell**. That sets up the per-site MySQL socket / port and PHP environment so wp-cli connects out of the box. Then:

```bash
wp scoop audit
```

Sample output on a healthy DB:

```
[1] Orphan tubs — track_posts.tub rows without a track_pods_tub companion
  OK — 0 orphans.

[2] Bidirectional drift — owners whose forward/reverse podsrel counts differ
  - batch.tubs  ↔  tub.batch
    OK — 0 drift.
  - flavor.tubs  ↔  tub.flavor
    OK — 0 drift.
  - location.tubs  ↔  tub.location
    OK — 0 drift.

Success: All audits passed.
```

If anything is off, the command prints a table of the affected rows and exits with code 1 — safe to wire into CI, a post-deploy hook, or a periodic cron.

**Flags:**

| Flag | Default | Purpose |
|---|---|---|
| `--skip-orphans` | off | Skip the orphan-tub check. |
| `--skip-bidir` | off | Skip the bidirectional drift check. |
| `--limit=<n>` | 100 | Cap on rows reported per check. |

**On production (or any environment where wp-cli is on PATH):** same command from the site root, no Site Shell needed.

**Without wp-cli:** paste the two SQL queries above into Adminer / phpMyAdmin / the `mysql` CLI. Same checks, same expected zero-row result.

### Periodic Pods cache refresh

The direct-write paths (e.g. `scoop_create_batch_tubs_direct`) bypass Pods's normal save flow, so Pods's per-item relationship cache for the affected batches/flavors/locations is not invalidated when a row is written. Most consumers refresh on next read, but a few (notably **Admin Columns**) read through a layer that caches Pods's cached value — so they keep displaying pre-write state until someone re-saves the affected record by hand.

[includes/cron.php](includes/cron.php) registers a WP-Cron event `scoop_periodic_cache_refresh` that runs **every 2 hours** and clears `pods_items_<pod>` for every Pod listed in `scoop_cron_pods_to_refresh()`. Pods covered today: `tub`, `batch`, `flavor`, `slot`, `cabinet`, `closeout`, `location`, `use`, `nightly_sales`, `inventory_change`. Add new CPTs to that list when they ship.

**Manual trigger** (no waiting for the next cron tick):

```bash
wp scoop cache-refresh
```

Output:
```
Cleared Pods caches for: tub, batch, flavor, slot, cabinet, closeout, location, use, nightly_sales, inventory_change
Bumped scoop_cache_version (bundle + analytics cache invalidated).
Success: Cache refresh complete.
```

**Caveat:** WP-Cron only fires when there's inbound traffic to the site. On a low-traffic install the actual cadence drifts. If a hard 2-hour cadence matters operationally, replace WP-Cron with a real system cron that hits `wp-cron.php` from the OS — or run `wp scoop cache-refresh` from a normal Unix `crontab` entry instead.

---

## Schema-related active issues

Issues uncovered while documenting the schema (2026-05-27). Listed roughly in order of how much they matter right now. Address before they compound or before adding new code that touches the affected areas.

### 1. `location` filter SQL on `tub` may be silently broken

[includes/bundle-fetch.php:320-323](includes/bundle-fetch.php#L320-L323) pushes `location = {$loc_id}` directly into the WHERE clause, with a comment claiming `location` is "a plain int column here". The schema dump shows **no `location` column on `track_pods_tub`** — location is a relationship stored in `track_podsrel`. Either Pods is transparently rewriting the WHERE into a relationship join (in which case the comment is misleading), or the filter has been failing silently and the FlavorTub grid has been ignoring the `location=` shortcode attribute. Worth verifying before relying on per-location FlavorTub views.
- **Action:** load a `[scoop_grid type="FlavorTub" location="935"]` page, enable Query Monitor, and inspect the actual SQL emitted. If it errors or includes all locations, rewrite to use an explicit `track_podsrel` join.

### 2. Resolved 2026-05-27 — Dad Bod batch 8368 incomplete-data fix

This item was originally written under the assumption that the failed direct-write runs produced orphan `track_posts` rows with no companion `track_pods_tub` rows. A SQL audit found **no orphans** — every tub post had its companion row. The real bug was different: the old direct path's `pods('tub', $id)->save()` step was treated by Pods as an UPDATE (because the post already had an ID), so **field defaults didn't apply** and the `state` column was left NULL on every Dad Bod tub. The FlavorTub grid's `state != 'Emptied'` filter then silently excluded them.

Fix landed via `UPDATE track_pods_tub SET state='Freezing', amount=COALESCE(amount, 1.00) WHERE id BETWEEN 8369 AND 8380;` plus a `scoop_cache_version` bump. The direct path in [includes/hooks/batch-tub.php](includes/hooks/batch-tub.php) has been rewritten to INSERT every required column explicitly, so this regression class is closed. See [performance.md](performance.md) finding #6 for the full diagnosis.

**Audit query, kept for future use** — should always return zero rows on a healthy DB:
```sql
SELECT p.ID, p.post_title
FROM track_posts p
LEFT JOIN track_pods_tub t ON t.id = p.ID
WHERE p.post_type = 'tub' AND t.id IS NULL;
```

### 3. Resolved 2026-05-27 — `tempature` → `temperature` on `track_pods_nightly_sales`

The column was originally named `tempature` (missing the second `e`). Renamed via Pods admin on 2026-05-27; the three code references in [includes/hooks/nightly-sales.php](includes/hooks/nightly-sales.php) (the weather field map and the two Open-Meteo round/assign sites) were updated to match. `schema.sql` was re-dumped to capture the corrected column name. Historical mentions in [CHANGELOG.md](CHANGELOG.md) remain unchanged as a record of the original spelling.

### 4. Retracted 2026-05-27 — `nightly_sales.location` exists as a relationship

Originally written as "no location field." Correction after DB inspection: the `nightly_sales` Pod **does** have a `location` field (field ID 7285 in the Pods config). It just doesn't appear in the `track_pods_nightly_sales` schema dump because it's a *relationship*, stored in `track_podsrel` like every other Pods relationship. All 1000 historical rows from the CSV import already have `location = 935` (Woodinville).

The schema-dump-only inspection that produced this entry was misleading because per-Pod tables in tables mode hold only scalar columns; relationship presence has to be checked against `track_podsrel` and the `_pods_field` posts. Lesson logged: schema-only diagnostics will miss every relationship — always cross-check by inspecting Pods config fields and `track_podsrel` rows.

Defensive hardening of `scoop_nightly_sales_import_data()` landed alongside this retraction: the location cascade now ends in a hard-coded `935` fallback so the field cannot silently fail to be set, regardless of include order or constant availability.

The `nightly_sales` upsert semantics from [GUI-planning.md](GUI-planning.md) still need a unique `(sales_date, location)` constraint at the DB level if you want upsert protection enforced there — currently it's only enforced in code via `scoop_nightly_sales_find_existing_id()` (which today checks date alone, not date + location). That's a separate item worth tracking if multi-location nightly_sales ever happens.

### 5. MyISAM means no transactions for multi-step writes

Every `track_pods_*` table except `nightly_sales` is `ENGINE=MyISAM`, which does not support transactions or foreign keys. A write sequence that needs to insert into `track_posts` + `track_pods_<podname>` + `track_podsrel` atomically cannot be rolled back if a later step fails — and that's exactly how the direct-write produced orphans (issue #2).
- **Action:** any future direct-write code must (a) order the inserts so the per-Pod-table row immediately follows the post row, before relationships, and (b) explicitly delete prior rows on failure. Long-term, converting Pods tables to InnoDB would enable real transactional safety, but that's a bigger change with its own risk surface.

### 6. No secondary indexes on `track_pods_tub`

The schema has only `PRIMARY KEY (id)`. Analytics queries filter heavily by `state`, `emptied_at`, and `state + emptied_at` (see [includes/analytics.php](includes/analytics.php) `scoop_analytics_aggregate_tubs`, `scoop_analytics_sellthrough`, `scoop_analytics_current_stock`) — all of which currently do full table scans. As the tub table grows, analytics latency will degrade roughly linearly.
- **Action:** add indexes via Pods admin (Pods can manage indexes on per-Pod tables) or a direct migration. Candidate set: `KEY (state)`, `KEY (emptied_at)`, `KEY (state, emptied_at)`. Pairs well with the still-open audit-merging work in [performance.md](performance.md) finding #2.

### 7. Resolved 2026-05-27 — direct-write missing bidirectional `wp_podsrel` reverse rows

The first cut of `scoop_create_batch_tubs_direct()` wrote only the forward direction of each tub relationship (tub→batch, tub→flavor, tub→location). Pods stores every bidirectional relationship as **two rows in `wp_podsrel`** — one per direction — so wp-admin's "Batch" edit screen showed empty `tubs` lists for direct-path batches even though the tubs all had a back-reference. Batch 8394 (Tomato Sorbet) was the trigger case.

Fix: the direct-write loop in [includes/hooks/batch-tub.php](includes/hooks/batch-tub.php) now writes up to **6 podsrel rows per tub** (3 forward + 3 reverse for `batch.tubs`, `flavor.tubs`, `location.tubs`), resolving the reverse-field IDs at runtime via `pods_api()->load_pod()` on each related Pod. Affected batches (8368, 8394) were backfilled with an idempotent `INSERT ... WHERE NOT EXISTS` against `track_podsrel`; post-fix verification showed all three batches (including the Pods-API baseline 8354) at the canonical 25 rows = 13 forward + 12 reverse.

The bidirectional drift detection query in the [Integrity audit queries](#integrity-audit-queries) section above is the standing safeguard against this class of regression.

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
    find-in-list.js         ← Text filter widget (Grid + Tile)
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

See [CLAUDE.md](CLAUDE.md) for deeper architectural detail, [INTEGRATION.md](INTEGRATION.md) for the historical reference on how the Analytics pattern was added, [CHANGELOG.md](CHANGELOG.md) for a curated log of notable changes with the "why" behind each, [performance.md](performance.md) for the standing performance punch list, [GUI-planning.md](GUI-planning.md) for in-flight UI direction and the use-case backlog, [EZ-TYPE-2-GRID.md](EZ-TYPE-2-GRID.md) for a minimal-steps cookbook for spinning up a new grid from a Pods content type, and [SHORTCODES.md](SHORTCODES.md) for the list of available `[scoop_grid]` types and their attributes.
