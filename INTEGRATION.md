# Integration Instructions

## 1. Load the PHP endpoint

In `scoop_rest.php`, add the require after the existing REST includes:

```php
scoop_require('includes/analytics.php');
```

The file self-registers its route via `add_action('rest_api_init', ...)`,
so no changes to `_routes.php` are needed.

## 2. Register the JS model in ScoopAPI

In `assets/data/scoop-api.js`, add the import and model registration:

```js
import AnalyticsGridModel from "../models/analytics-grid-model.js";
```

Add to `getModelsBom()`:

```js
getModelsBom() {
  return {
    "Cabinet"      : CabinetGridModel,
    "FlavorTub"    : FlavorTubGridModel,
    "Batch"        : BatchGridModel,
    "Closeout"     : CloseoutGridModel,
    "DateActivity" : DateActivityGridModel,
    "Analytics"    : AnalyticsGridModel,       // <-- add this
  };
}
```

## 3. Override mounting for Analytics grids

The Analytics model does not use the bundle/domain pattern. It fetches
its own data. In `mountAllGrids()` in `scoop-api.js`, add a special
case for Analytics grids, or handle it in a post-mount hook:

```js
// In mountAllGrids(), after creating grids from hosts:
for (const grid of grids) {
  if (grid.name === "Analytics") {
    // Analytics model fetches its own data — skip the bundle
    await grid.modelInstance.fetch();
    grid.init(grid.modelInstance);
    continue;
  }
  // ... existing domain-setting logic for other grids ...
}
```

Alternatively, mount analytics grids separately after `mountAllGrids`:

```js
// After mountAllGrids() in app.js:
const analyticsHosts = document.querySelectorAll('.scoop-grid[data-grid-type="Analytics"]');
for (const host of analyticsHosts) {
  const location = Number(host.dataset.location || 0);
  const model = new AnalyticsGridModel("Analytics", {
    location,
    nonce: SCOOP.nonce,
  });
  await model.fetch();
  const grid = new Grid(host, "Analytics", {
    modelInstance: model,
    columns: model.columns,
  });
  grid.init(model);
}
```

## 4. Add the shortcode

The existing `[scoop_grid type="Analytics"]` shortcode already works
via `shortcode.php` — it emits a `<div>` with `data-grid-type="Analytics"`,
which the JS model registry picks up.

Usage in a WordPress page or post:

```
[scoop_grid type="Analytics" location="935"]
```

## 5. Add CSS for supply alerts and trend indicators

Add to `assets/css.css`:

```css
/* Days of Supply color coding */
.zGRID td.days_of_supply.supply-critical {
  color: #d32f2f;
  font-weight: 600;
}
.zGRID td.days_of_supply.supply-warning {
  color: #f9a825;
  font-weight: 600;
}
.zGRID td.days_of_supply.supply-ok {
  color: #2e7d32;
}

/* Trend direction indicators */
.zGRID td.trend.trend-rising {
  color: #2e7d32;
}
.zGRID td.trend.trend-falling {
  color: #d32f2f;
}
.zGRID td.trend.trend-steady {
  color: #757575;
}

/* Analytics grid: hide the save button (read-only) */
.scoop-grid.Analytics .zGRID-form > button.save {
  display: none;
}
```

## 6. Expose the analytics route to the client (optional)

If you want the route available in `SCOOP.routes` for other JS consumers,
add to `scoop_client_routes()` in `includes/enqueue.php`:

```php
$out['Analytics'] = rest_url('scoop/v1/analytics');
```
