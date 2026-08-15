# EZ-TYPE-2-GRID

A pocket cookbook for spinning up a new grid from a Pods content type. Two paths — pick the one you need:

| Path | Use when | Files to touch |
|---|---|---|
| **Read-only listing** | You just want a viewer, possibly with date filtering. | ~4 |
| **Read+write CRUD** | Staff need inline editing, with hooks on save. | ~6 |

Worked example template throughout: a new grid `MyView` backed by Pod `my_pod`. Replace those tokens with your real names.

---

## Path A — Read-only listing (4 files, ~10 minutes)

Concrete reference: [assets/models/batch-history-grid-model.js](assets/models/batch-history-grid-model.js) (the BatchHistory grid). Open that alongside this cookbook if you want a complete example.

### 1. Declare bundle needs — `includes/_specs.php`

In `scoop_bundle_specs()`:

```php
'MyView' => ['needs' => ['my_pod']],
```

If columns reference relationships (e.g. you want to show a flavor name), add those entities: `['needs' => ['my_pod', 'flavor']]`.

### 2. Declare the Pod entity — `includes/_specs.php`

In the `$cache` array inside `scoop_entity_specs()`:

```php
'my_pod' => [
  'post_type' => 'my_pod',
  'pod'       => 'my_pod',
  'title'     => true,                  // include post_title in the response
  'fields' => [
    'some_count'   => ['data_type' => 'float'],
    'some_flavor'  => ['data_type' => 'int', 'control' => 'find', 'titleMap' => 'flavor'],
  ],
  'post_fields' => [                    // wp_posts columns to surface
    'author_name' => 'string',
    'post_date'   => 'datetime',
  ],
  'writeable' => []                     // empty = read-only
],
```

**Field `data_type` values:**

| Value | Server read path | Use for |
|---|---|---|
| `int` | `$pod->field()` | Integers AND single-value relationships |
| `float` | `$pod->row[…]` direct | Decimal numbers (amounts, prices) |
| `string` | `$pod->row[…]` direct | Plain text columns |
| `bool` | `$pod->row[…]` direct | Booleans |
| `datetime` | `$pod->row[…]` direct | Date columns |
| `post_names` | `$pod->field()` | Multi-relationship → slug strings |

The bundle-fetch layer routes scalars through `$pod->row` (cheap) and routes ints + post_names through `$pod->field()` (Pods resolves relationships). You don't have to think about it beyond picking the right `data_type`.

### 3. (Optional) Add a date window — `includes/bundle-fetch.php`

If you want the listing to be windowed by date (recommended — otherwise the bundle ships every row ever):

**3a.** In `scoop_bundle_date_filter_context()`, default the filter key when your grid is requested:

```php
if (empty($keys) && in_array('MyView', $requesting_types, true)) {
  $keys = ['created'];   // pick any key name; clients will see it as filter_created="…"
}
```

**3b.** In `scoop_fetch_entities()`, add a block that applies the WHERE clause:

```php
if ($key === 'my_pod') {
  $requesting_types = $ctx['requesting_types'] ?? [];
  $has_my_view      = in_array('MyView', $requesting_types, true);
  $date_filters     = $ctx['date_filters'] ?? [];
  $date_ranges      = $ctx['date_filter_ranges'] ?? [];

  if ($has_my_view) {
    if (in_array('created', $date_filters, true)) {
      $clause = scoop_date_filter_sql_clause('t.post_date', $date_ranges['created'] ?? []);
      $where_clauses[] = $clause !== '' ? $clause : '1=0';
    } else {
      $where_clauses[] = '1=0';   // never ship the entire history
    }
  }
}
```

### 4. Write the JS model — `assets/models/my-view-grid-model.js`

Minimum viable model:

```javascript
import BaseGridModel from "./_base-grid-model.js";
import Indexer       from "../data/indexer.js";

export default class MyViewGridModel extends BaseGridModel {
  constructor(name = 'MyView', domain, attrs = {}, metaData = null) {
    super(name, null, attrs, metaData);
    this.filter = true;            // enables text find-in-list
    this._build();
    if (domain) this.setDomain(domain);
  }

  buildCols() {
    this.columns = [
      { key: "post_date",   label: "Date",   type: "datetime" },
      { key: "some_flavor", label: "Flavor", type: "string", titleMap: "flavor" },
      { key: "some_count",  label: "Count",  type: "number" },
      { key: "author_name", label: "Author", type: "string" },
    ];
    return this.columns;
  }

  buildRows() {
    const flavorsById = Indexer.byId(this.domain?.flavor) || new Map();
    const items = Array.isArray(this.domain?.my_pod) ? this.domain.my_pod : [];

    this.rows = items
      .sort((a, b) => String(b.post_date ?? '').localeCompare(String(a.post_date ?? '')))
      .map(item => {
        const flavorName = flavorsById.get?.(Number(item.some_flavor ?? 0))?._title ?? '—';
        return {
          id: item.id,
          post_date:   { display: String(item.post_date ?? '').slice(0, 16), value: item.post_date ?? '' },
          some_flavor: { display: flavorName,                                 value: flavorName },
          some_count:  { display: String(item.some_count ?? ''),              value: Number(item.some_count ?? 0) },
          author_name: { display: item.author_name ?? '',                     value: item.author_name ?? '' },
        };
      });

    return this.rows;
  }
}
```

For a server-side date filter widget, copy the `getFilterDefs() / getServerFilterParams() / setFilterValue() / getFilterValue()` block from [batch-history-grid-model.js](assets/models/batch-history-grid-model.js).

### 5. Register the model — `assets/data/scoop-api.js`

Two small edits:

```javascript
import MyViewGridModel from "../models/my-view-grid-model.js";
```

And in `getModelsBom()`:

```javascript
"MyView" : MyViewGridModel,
```

### 6. Drop the shortcode

```
[scoop_grid type="MyView"]
```

With a date filter:

```
[scoop_grid type="MyView" date_filters="created" filter_created="last_7_days"]
```

Reload. You should see the grid.

---

## Path B — Read+write CRUD (Path A + 3 more things)

When staff need to edit cells inline.

### B1. Route entry — `includes/_config.php`

In `scoop_routes_config()`:

```php
'MyView' => [
  'path'         => '/myview',
  'methods'      => ['GET','POST'],
  'mode'         => 'update',   // or 'create' for single-row forms (like Batch)
  'envelope_key' => 'MyView',
  'post_type'    => 'my_pod',
  'pod_name'     => 'my_pod',
  'allowed_fields_cb' => 'scoop_myview_allowed_fields',
],
```

### B2. Allowed-fields callback — `includes/_write_fields.php`

```php
function scoop_myview_allowed_fields($user): array {
  return ['some_count', 'some_flavor'];   // whitelist of fields the route may write
}
```

### B3. Mark fields writeable in `_specs.php`

On the entity you defined in Path A step 2:

```php
'writeable' => ['some_count', 'some_flavor'],
```

That's it. The grid's cells become editable. The JS auto-renders the right widget (TextIt for `string` / `number`, FindIt for `int` / `find` relationships) based on the field's `data_type` and `control`.

### B4. (Optional) Business-rule hooks — `includes/hooks/`

If a save should cascade (e.g. saving a batch should create N tubs), add a `pods_api_pre_save_pod_item_<pod>` or `pods_api_post_save_pod_item_<pod>` filter. See [includes/hooks/batch-tub.php](includes/hooks/batch-tub.php) for an extensive example.

---

## Patterns & gotchas

- **In tables mode, every Pod item needs TWO rows in lockstep** — `track_posts` + `track_pods_<pod>`. Missing either makes the item invisible. See the [README "Integrity rule" section](README.md#the-integrity-rule-restated) for the full picture.
- **Bidirectional relationships are two `track_podsrel` rows** — one per direction. If you write any relationship directly via `$wpdb` instead of through Pods, you must write both directions or wp-admin will display blanks.
- **Run `wp scoop audit`** after any direct-write or schema change. It catches orphans and bidirectional drift.
- **The Pods relationship cache is plugin-local.** After direct-writes that bypass Pods's save flow, run `wp scoop cache-refresh` or wait for the 2-hour cron. See [includes/cron.php](includes/cron.php).
- **`data_type='int'` is almost always a relationship**, even though it looks like a number. Pair it with `control='find'` + `titleMap='<pod_name>'` so the JS renders a typeahead.
- **Don't build admin filters by adding columns to the per-Pod table** — relationships live in `track_podsrel`, not on `track_pods_<pod>`. Filter via `pods()->find(['where' => '...'])` which knows how to join.

---

## Checklist

- [ ] Bundle spec entry in [includes/_specs.php](includes/_specs.php) `scoop_bundle_specs()`
- [ ] Entity spec in [includes/_specs.php](includes/_specs.php) `scoop_entity_specs()`
- [ ] JS model file in `assets/models/`
- [ ] Model imported + registered in [assets/data/scoop-api.js](assets/data/scoop-api.js) `getModelsBom()`
- [ ] If date-filtered: defaults + SQL clause in [includes/bundle-fetch.php](includes/bundle-fetch.php)
- [ ] If read/write: route in `_config.php` + allowed-fields callback in `_write_fields.php` + `writeable` set on entity
- [ ] If business rules: hook file in `includes/hooks/`
- [ ] Drop the shortcode on a WP page and reload

---

## Related reading

- **[README.md](README.md)** — full architecture, database schema, integrity rules
- **[CLAUDE.md](CLAUDE.md)** — deeper architectural notes
- **[assets/README.md](assets/README.md)** — per-file client-side reference
- **[includes/README.md](includes/README.md)** — per-file server-side reference
