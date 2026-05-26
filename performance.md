# Performance Audit

**Date:** 2026-05-26
**Scope:** Server (PHP/Pods) and client (ES module) hot paths.

A read-through of `analytics.php`, `bundle.php`, `bundle-fetch.php`, `_cache.php`, the `hooks/`, `scoop-api.js`'s mounting flow, and `grid.js`'s render path. Concrete findings only — no theoretical micro-optimizations. Items are prioritized by likely impact on a real flame graph or slow request.

## How to read this

Each finding has:
- **Location** with a file:line link
- **What** — the inefficient pattern
- **Why it's slow** — the mechanical cause
- **Proposed fix** — specific enough to act on

## High severity

### 1. Analytics endpoint has no transient cache

**Status (2026-05-26):** *Addressed.* `scoop_analytics_cache_key()` lives in [includes/_cache.php](includes/_cache.php) and is keyed by `version | days | location | grid_type`. [includes/analytics.php](includes/analytics.php) reads the transient at the top of `scoop_analytics_handler()` and writes on both success paths (empty-flavors and main). Cache hits re-stamp `trace_id` so each response still has a unique debug ID; `_cache: 'hit'|'miss'` distinguishes the path. Invalidation rides on the same version-bump as the bundle cache.

**Location:** [includes/analytics.php](includes/analytics.php) (whole file)

**What:** Per-flavor aggregates are deterministic given `(days, location, cache_version)`, but every call recomputes from scratch. The client also cache-busts with `_ts=<now>` on every GET, so reloading the Popular or Flavors page always hits the heavy path.

**Why it's slow:** The handler runs three full tub-table scans, a batch scan, and a flavor scan on every request — none of which is cached at the response level.

**Fix:** Reuse the bundle's transient + version-bump pattern from [includes/_cache.php](includes/_cache.php). Cache key = `'scoop_a_' . md5($version . '|' . $days . '|' . $location)`, TTL = `SCOOP_CACHE_TTL`. Single biggest win available.

### 2. Three full `tub` table scans per analytics request

**Status (2026-05-26):** *Partially addressed* by the stage-map optimization — narrow grids (Flavors, Popular) now skip scans they don't need. Merging the remaining duplicate scans for the `Analytics` grid (which still needs all three) is still open.

**Location:** [scoop_analytics_aggregate_tubs](includes/analytics.php#L304), [scoop_analytics_sellthrough](includes/analytics.php#L467), [scoop_analytics_current_stock](includes/analytics.php#L572)

**What:** Three separate `pods('tub')->find()` calls. The first two iterate the same set (Emptied tubs in the period) but compute different aggregates.

**Why it's slow:** Two redundant full-table scans on the largest table in the schema.

**Fix:** Merge `aggregate_tubs` and `sellthrough` into one pass — a single `find()` over Emptied-in-period tubs, both aggregates built in one `while ($pod->fetch())` loop. `current_stock` (non-Emptied) is the only one that legitimately needs a separate scan.

### 3. Pods N+1 inside analytics tub loops

**Location:** [scoop_analytics_aggregate_tubs](includes/analytics.php#L337), [scoop_analytics_sellthrough](includes/analytics.php#L499), [scoop_analytics_current_stock](includes/analytics.php#L593)

**What:** Each `while ($pod->fetch())` calls `$pod->field('flavor')`, `$pod->field('location')`, `$pod->field('use')`, `$pod->field('batch')`. That's 4 Pods resolver invocations per row.

**Why it's slow:** Pods caches internally but each `field()` still hits the resolver. The sellthrough function alone hits these on every emptied tub in the window — 500 tubs × 4 = 2000 resolver calls.

**Fix:** Where the field is stored as a plain ID column on `wp_pods_tub`, read it directly from `$pod->row[$field]` (with the same `scoop_rel_id()` coercion). [bundle-fetch.php:245](includes/bundle-fetch.php#L245) already classifies fields this way — port the pattern.

### 4. Cache version bumps on every site-wide `save_post`

**Location:** [includes/_cache.php:76-79](includes/_cache.php#L76)

**What:** Global `save_post` / `trashed_post` / `untrashed_post` / `deleted_post` actions bump the cache version. Editing a blog post, menu item, or any unrelated CPT invalidates the scoop bundle cache.

**Why it's slow:** Cache hit rate on a busy WP site is much lower than the code implies.

**Fix:** Convert the `inventory_change` exclusion into a **whitelist**: only bump for `tub`, `batch`, `slot`, `cabinet`, `closeout`, `flavor`, `location`, `use`.

### 5. Tub save cascades into flavor save + cache bust

**Location:** [scoop_bump_flavor_modified_date_on_tub_save](includes/hooks/batch-tub.php#L253)

**What:** Fires on every tub save. Inside it, `pods_api()->save_pod_item({ pod: 'flavor', ... })` writes the flavor, which fires `save_post`, which fires `scoop_cache_bust`. So a single tub state change → 1 flavor write → 1 full cache invalidation.

**Why it's slow:** Every tub edit forces an extra DB write plus a cache wipeout. Compounds dramatically during batch creation (see #6).

**Fix:** Audit whether `flavor.modified_date` is actually consumed anywhere — grep didn't surface a reader. If unused, delete the hook. If used, gate it: only bump when `state` actually transitioned, not on every amount edit.

## Medium severity

### 6. Batch creation serializes N tub writes

**Location:** [scoop_create_tubs_for_new_batch](includes/hooks/batch-tub.php#L221)

**What:** `for ($i = 1; $i <= $count; $i++) { pods_api()->save_pod_item({ pod: 'tub', ... }); }`. Plus a trailing `wp_update_post()` at [line 240](includes/hooks/batch-tub.php#L240).

**Why it's slow:** Combined with finding #5, a batch of 10 produces ~10 tub writes + 10 flavor writes + 10 cache busts + 1 redundant post update.

**Fix:** (a) The trailing `wp_update_post` is unnecessary if the batch is already `publish` — check first. (b) Bulk-create the tubs via a single transaction or `wpdb` insert, then fire `pods_api_post_save_pod_item_tub` manually per row.

### 7. Analytics grids mount serially

**Location:** [assets/data/scoop-api.js:376](assets/data/scoop-api.js#L376)

**What:** `for (const dom of analyticsHosts) { ... await model.fetch(); ... }`. Two analytics grids on one page = waterfalled requests.

**Why it's slow:** Each fetch blocks the next, even though they're independent.

**Fix:** Replace with `await Promise.all(analyticsHosts.map(async dom => { ... }))`. The bundle-path branch already parallelizes correctly after the bundle resolves; the analytics branch is the laggard.

### 8. `scoop_pods_dropdown_options` re-read per closeout save

**Location:** [includes/hooks/closeout.php:289](includes/hooks/closeout.php#L289)

**What:** `scoop_pods_dropdown_options('tub', 'state')` is called inside `scoop_closeout_tub_where()`, invoked once per closeout save.

**Why it's slow:** The state list is static. Re-reading Pods option configuration on every save is wasted work.

**Fix:** Memoize with a `static $cache = null;` inside the helper, or use `wp_cache_*`.

## Low severity

### 9. `get_the_author_meta` per row in bundle fetch

**Location:** [includes/bundle-fetch.php:388](includes/bundle-fetch.php#L388)

**What:** Called per fetched row.

**Why it's slow:** WP caches per-author, so this is cheap for a small staff list. Flag only if you onboard a larger user base.

**Fix:** Pre-fetch all unique `post_author` IDs in one query before the row loop.

### 10. `_buildRows` per-cell `<td>` append

**Location:** [assets/ui/grid.js:210-247](assets/ui/grid.js#L210-L247)

**What:** Each `<tr>` is appended directly to its `<tbody>` as it's built.

**Why it's slow:** For tables >200 rows you'll see reflow stalls.

**Fix:** Build rows into a `DocumentFragment` and append once per tbody.

---

## Recommended order

Tackle in this sequence — each step makes the next easier to measure:

1. **#4** — Whitelist the cache-bust post types. Lowest-risk change, immediately raises hit rate.
2. **#1** — Add the analytics transient cache. Single biggest latency win.
3. **#2** — Merge the duplicate tub scans. Halves the cold-path cost behind the cache.
4. **#5** — Audit/remove the flavor-bump hook. Removes write amplification.

Those four together should cut analytics latency by 3-5× on warm requests and dramatically raise the bundle cache hit rate. The medium items (#6, #7, #8) are worth doing afterward; the low items are micro-optimizations to defer until profiling shows them mattering.
