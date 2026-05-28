# Shortcodes

All grid views are emitted by a single WordPress shortcode, **`[scoop_grid]`**, with a `type=` attribute selecting which grid renders. There is no separate shortcode per grid — adding a new `type` value (see [EZ-TYPE-2-GRID.md](EZ-TYPE-2-GRID.md)) makes a new variant available immediately.

## Common attributes

| Attribute | Required | Default | Purpose |
|---|---|---|---|
| `type` | yes | — | Grid type identifier — case-sensitive. See list below. |
| `location` | no | — | Location ID (e.g. `935` = Woodinville) to scope the grid. Omit for global / multi-location. |
| `days` | no | `30` | Analysis window for **analytics-pattern** grids (`Analytics`, `Popular`, `Flavors`). |
| `date_filters` | no | — | Comma-separated date-filter keys (e.g. `created`, `activity`). Triggers a server-side WHERE on the bundle. |
| `filter_<key>` | no | preset default | Value for a date filter — one of `last_24_hours`, `last_48_hours`, `last_7_days`, `last_30_days`. |

The user must be logged in for any grid to render (`shortcode.php` short-circuits otherwise).

---

## Grids by workflow

### Daily ops — most-used surfaces

#### `FlavorTub` — the central tub-state editor
Lists tubs with inline editing of `state` / `use` / `amount`. Filterable by designation, allergens, and use. The grid most staff spend the most time in.

```
[scoop_grid type="FlavorTub" location="935"]
```

#### `Batch` — create a new batch
Single-row form: pick flavor, enter count, save. Creates the batch + N child tubs. The synchronous create path is what the perf work in [performance.md](performance.md) targets.

```
[scoop_grid type="Batch"]
```

#### `Closeout` — record a closeout
Single-row form to enter end-of-shift emptied-tub counts. Auto-matches against open tubs at the given location.

```
[scoop_grid type="Closeout"]
```

#### `BatchHistory` — past batches, filterable by date
Read-only listing with columns Created / Flavor / Tubs / Author. Default window is 7 days; widget at the top of the grid switches between `last_24_hours` / `last_48_hours` / `last_7_days` / `last_30_days`.

```
[scoop_grid type="BatchHistory" date_filters="created" filter_created="last_7_days"]
```

### Planning

#### `Cabinet` — slot planning per cabinet
Lists slots grouped by cabinet, with inline editing of `current_flavor` / `immediate_flavor` / `next_flavor`. The view ops uses to plan what flavors live where.

```
[scoop_grid type="Cabinet" location="935"]
```

### Insight / analytics (read-only, self-fetching)

#### `Analytics` — sales velocity dashboard
Per-flavor metrics: total sold, sell rate, average days to empty, days of supply, trend. Read-only.

```
[scoop_grid type="Analytics" location="935" days="30"]
```

#### `Popular` — popularity scatter plot
Custom SVG visualization plotting flavors by `total_sold` × `avg_sellthrough_days`, with allergen filter chips. Includes a sortable key-grid alongside.

```
[scoop_grid type="Popular" location="935" days="30"]
```

#### `Flavors` — all flavors, grouped by diet
All flavors grouped Dairy / Non-Dairy (from the `dairy` allergen slug), with columns Flavor / Tubs / Days Since Served. Useful for surfacing stale flavors.

```
[scoop_grid type="Flavors" location="935"]
```

### Audit / history

#### `DateActivity` — inventory-change activity
Inventory-change events grouped by flavor over a date window. Default window is 48 hours; same `filter_activity=last_*` preset list as BatchHistory.

```
[scoop_grid type="DateActivity" location="935" date_filters="activity" filter_activity="last_48_hours"]
```

---

## Patterns to remember

- **`type` is case-sensitive.** `type="flavors"` will silently no-op (the JS prints a console warning and skips the host); use `type="Flavors"`.
- **Multiple grids on one page** share a single bundle fetch, so adding a second grid is nearly free server-side. Adding two grids of the same type is fine — each host gets its own model instance.
- **Analytics-pattern grids** (`Analytics`, `Popular`, `Flavors`) bypass the bundle and call `GET /scoop/v1/analytics` directly. They don't share the bundle cache, but they have their own transient cache keyed by `(version, days, location, grid_type)` — see [performance.md](performance.md) finding #1.
- **`location` defaults to multi-location** when omitted. Bundle and analytics layers both honor it — but a few code paths still hard-code Woodinville (`935`) as the default location; see [README.md](README.md) "Schema-related active issues" for the historical context.
- **Date filters** are server-side. Changing the select widget on the grid triggers a fresh bundle fetch — no client-side filtering.
- **Read-only grids hide the save button** via a CSS rule on `[data-grid-type="<type>"]`. See [assets/css.css](assets/css.css).

## Adding a new shortcode variant

You don't add a new shortcode — you add a new `type` value. The recipe is in [EZ-TYPE-2-GRID.md](EZ-TYPE-2-GRID.md).
