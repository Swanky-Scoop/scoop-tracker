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

*(#11-13 below were diagnosed 2026-07-30, after #1-10 already existed — numbered to avoid disturbing existing cross-references in "Recommended order", not chronological within this section. They're the most acutely felt findings in this doc: the direct cause of a 10-15s wait after every single manual FlavorTub save.)*

### 11. Bundle fetch N+1 via `$pod->field()` dominates cold-cache request time

**Status (2026-08-10):** *Fixed for `tub` (committed) and `inventory_change` (implemented, verified locally, not yet committed) via a `wp_podsrel` bulk-read bypass — see the new section below for the full equivalence verification and real before/after numbers.* Permanent timing instrumentation added to `scoop_fetch_entities()`/`scoop_bundle_get()` (gated behind `SCOOP_DEBUG_LOG`, zero cost when off) — reusable to measure any fix attempt.

**Location:** [scoop_fetch_entities](includes/bundle-fetch.php#L311)

**What:** Every relationship/int/unknown-typed field, for every row, in every entity a bundle needs, is resolved via `$pod->field($field)` — a real Pods relationship-resolution call, not a plain array read. `bundle-fetch.php`'s own `row_fields` bucket already avoids this for scalar columns (reads `$pod->row` directly); its `needs_field` bucket (every int/relationship/unknown-typed field) does not. Same bug class as #3 below, in the bundle path instead of analytics.

**Why it's slow:** Confirmed via a live probe against real local data (bootstrapped WP via PHP CLI against the Local/Flywheel `swank-tracker` mirror, calling `scoop_bundle_get()` directly with `force_bust=1` to simulate a real post-save cold fetch — 333 tub rows, 226 flavor rows):

```
tub:    333 rows | find=86.6ms  | needs_field=2266.2ms | post_fields=507.9ms | TOTAL=3219.0ms
flavor: 226 rows | find=15.9ms  | needs_field=1927.6ms |                     | TOTAL=2199.8ms
use:      6 rows | find=7.9ms   | needs_field=21.6ms   |                     | TOTAL=79.1ms
slot:    32 rows | find=9.3ms   | needs_field=229.4ms  | slot_enrich=9.1ms   | TOTAL=280.9ms
                                                                    GRAND TOTAL=5961.9ms
```

The SQL `find()` itself is trivial everywhere (86.6ms for 333 rows). ~83% of total time is per-row `$pod->field()` resolution. Matches the local machine's ~6s; the real 10-15s on TEST/OPS is plausibly the same pattern compounded by real network latency per DB round-trip (many more, smaller round-trips vs. localhost's near-zero latency).

**Fix:** See #12 and #13 above — both landed and target the *avoidable* cost without touching how Pods resolves anything.

**`get_post_meta()` bypass — investigated 2026-08-07, disqualified.** The idea (bulk-prime the meta cache once per entity fetch, then read `tub`'s six relationship fields via `get_post_meta()` instead of six `$pod->field()` calls per row) was previously recorded as "verified as data-compatible... confirmed identical across 5 real tub rows." That 5-row sample was not representative. A full equivalence pass against all 2,427 real local `tub` rows (`update_meta_cache('post', $ids)` then compare `get_post_meta($id, $field, true)` against `$pod->field($field)` for `use`/`flavor`/`location`/`batch`/`closeout`/`slot`) found **3,951 of 14,562 checked values (~27%) mismatched — every mismatch was postmeta silently returning empty where Pods' own resolution had a real value.**

Root cause, confirmed via direct `wp_podsrel`/`wp_postmeta` inspection on tub 11754: `wp_podsrel` reliably has all 4 relationship rows (`batch`, `flavor`, `location`, `use`). `wp_postmeta` has exactly **one** mirrored row — `meta_key = '_pods_use'` (note the `_pods_` prefix — not a bare `meta_key = 'flavor'` as previously assumed) — and nothing at all for `batch`/`flavor`/`location`/`slot`/`closeout`. So the "auto-mirror into postmeta" behavior is real for at most one of `tub`'s six relationship fields, not all six, and the key naming isn't even the plain field name. Whatever produced the original 5-row confirmation didn't catch this, most likely by chance (5 rows all happened to only be checked on a field/case that matched, or the comparison logic had the same wrong assumption on both sides).

**Conclusion: this bypass is unsafe as conceived and must not be implemented as a plain `get_post_meta()` swap.** It would silently blank out `batch`/`flavor`/`location`/`slot`/`closeout` on the large majority of tub rows. `wp_podsrel` itself (not `wp_postmeta`) does reliably hold the data — a bulk single-query read from `wp_podsrel` (`WHERE item_id IN (...) AND field_id IN (...)`, mapping `field_id` back to field name once) is a theoretically safer bypass target, but it wasn't attempted here and would need its own from-scratch equivalence pass (field_id stability across environments, multi-value fields, etc.) before being trusted. The stale "confirmed identical" memory this was based on has been corrected.

**`wp_podsrel` bulk-read bypass — implemented 2026-08-10, verified via full equivalence passes (this time genuinely full, not a small sample).** Two new functions in `bundle-fetch.php`: `scoop_bulk_tub_relationships()` (tub's `batch`/`flavor`/`location`/`use`/`slot`/`closeout`, all single-value) and `scoop_bulk_inventory_change_relationships()` (`inventory_change`'s `tubs`/`flavors`, both multi-value). Both replace their entity's per-row `$pod->field()` calls with one bulk `wp_podsrel` query per entity fetch. Field IDs (`wp_podsrel.field_id`) are resolved dynamically per environment via `scoop_tub_relationship_field_ids()`/`scoop_inventory_change_relationship_field_ids()` (querying `_pods_field` posts, cached in a transient) — **never hardcoded**, since they're auto-increment IDs specific to each database and will differ between local/TEST/OPS.

**Critical correctness gap found and fixed before trusting this:** `$pod->field()` silently excludes relationship targets whose own `post_status` isn't `publish` — confirmed as an *explicit* per-field Pods setting (`pick_post_status = publish`, found in each field's own postmeta), not a hidden framework default. This shop's practice of unpublishing (`draft`) tubs once `Emptied` means **86% of all `tub` posts locally are `draft`** — so this isn't an edge case, it's the majority state. The bulk read now joins `wp_posts` and requires `post_status = 'publish'` on the related item, exactly reproducing `$pod->field()`'s existing behavior (not a new rule). Without that join: 18 real mismatches on `tub`'s fields (all `draft`-status batch/closeout targets), and item 13028 on `inventory_change.tubs` alone dropped from 23 raw `podsrel` rows to 2 once the same filter was verified.

**Equivalence verification (full, not sampled):**
- `tub`'s six fields: 14,562 (row, field) checks across all 2,427 real local tub rows — 0 mismatches, once the `publish` join was added.
- `inventory_change`'s two fields: 3,092 (row, field) checks across all 1,546 real local rows — 0 set mismatches AND 0 order mismatches (weight-ordered bulk read exactly matches `$pod->field()`'s array order too).

**Real before/after, `DateActivity` bundle, 30-day activity window (461 tubs, 207 inventory_change rows):**

```
                    BEFORE (tub fix only)              AFTER (both fixes)
tub:                needs_field=24.8ms  TOTAL=840.2ms   needs_field=31.9ms  TOTAL=962.1ms
inventory_change:    needs_field=1168.7ms TOTAL=1481.4ms  needs_field=0.0ms   TOTAL=509.4ms
slot:               needs_field=243.9ms TOTAL=315.9ms    needs_field=464.0ms TOTAL=568.4ms  (unrelated variance, not yet optimized)
                                        GRAND=2692.4ms                        GRAND=2130.4ms
```

(For reference, before *either* fix `tub`'s own `needs_field` alone measured 2266.2ms on a smaller 333-row sample — see the original numbers above.)

**`tub.index` — a smaller, different-shaped fix, 2026-08-10.** Noticed while implementing the above: `tub.index` sits in the `needs_field` bucket (classified `int`, and `scoop_classify_fetch_fields()`'s whitelist treats every `int` as a possible relationship) but isn't a relationship at all — it's a plain scalar column in `{$wpdb->prefix}pods_tub`, the same table `amount`/`state` already read directly via `$pod->row` with zero relationship resolution. Verified `$pod->row['index'] === $pod->field('index')` across all 2,427 real local tub rows (draft and published), 0 mismatches — no `wp_podsrel` involved at all for this one, so no `post_status` concern either. Fixed the same way as the row_fields bucket: `unset()` from `needs_field`, read `$pod->row['index']` directly. Result: `tub`'s `needs_field` cost, already reduced by the relationship bypass above, drops from ~25-32ms to ~0.1ms — essentially eliminated.

**Not yet done:** `slot`'s own `needs_field` (its `cabinet` relationship) is now visibly the largest remaining per-entity cost in a `DateActivity` bundle. Same N+1 shape, different pod — would need its own from-scratch equivalence pass before bypassing, same as both fixes above. Not attempted yet.

**Separate, non-performance finding surfaced by this investigation:** the "unpublish tubs once Emptied" habit is worth reconsidering independent of this fix — `state = 'Emptied'` already achieves "out of the way" via every existing app-level filter (`NON_PROMOTABLE_STATES`, `DISPLAY_EXCLUDED_STATES`, etc.), and unpublishing on top of that silently degrades relationship resolution anywhere a field points at that tub (confirmed concretely on `inventory_change.tubs`). This bypass reproduces that behavior faithfully rather than fixing it — republishing affected tubs is a separate, deliberately deferred task.

### 12. `flavor.tubs`/`current_slots` resolved but unused by the grid causing the slow save

**Status (2026-07-30):** *Implemented and verified locally, not yet on TEST/OPS.* Confirmed correct both ways with the local probe: `FlavorTub` requests now skip the two fields (flavor `needs_field` 1927.6ms → 1271.5ms, a genuine but *partial* cut — see the corrected "Why it's slow" below); `InstockFlavor` requests still resolve them fully (flavor `needs_field` measured at 2149.6ms, consistent with baseline). Two other grid types share `flavor` with `InstockFlavor` in their bundle spec (`Cabinet`, `CabinetWorkflow` — see `scoop_bundle_specs()`) and were traced the same way as `FlavorTub`/`Batch` — confirmed they don't read `tubs`/`current_slots` either, so this only ever resolves fully when `InstockFlavor` itself is genuinely on the page.

**Location:** [flavor entity spec](includes/_specs.php#L230), [scoop_fetch_entities](includes/bundle-fetch.php#L311)

**What:** `flavor`'s `tubs`/`current_slots` fields (`data_type: 'ids'`, multi-value relationships — "which tubs/slots currently belong to this flavor") are unconditionally resolved for every bundle request that needs `flavor`. Only `InstockFlavorGridModel` ([assets/models/instock-flavor-grid-model.js:97](assets/models/instock-flavor-grid-model.js#L97)) reads them. `FlavorTubGridModel` — the grid type behind the 10-15s save-wait — builds its own flavor grouping straight from `domain.tub`'s own `flavor` field and never touches `flavor.tubs`/`flavor.current_slots` at all (confirmed by tracing `_base-grid-model.js`/`_flavor.js`/`flavor-tub-grid-model.js`/`batch-grid-model.js` — none reference either field; the Cabinet/Batch flavor dropdowns source from `flavorMeta.optionsAll`, built from title alone).

**Why it's slow — corrected after measuring:** originally assumed `tubs`/`current_slots` were the *dominant* cost. Measured after implementing: they account for ~656ms of flavor's ~1928ms `needs_field` time (226 rows) — a real ~35% cut, not a majority. The remaining ~1272ms is `menu_board`/`photo`/`allergens`/`web_id` — still worth addressing, and a good candidate for #13's cache-scope idea below, since those are exactly the genuinely-static fields that fix targets.

**Fix (implemented):** Skip resolving `tubs`/`current_slots` unless `InstockFlavor` is actually among the request's `requesting_types` — the same conditional-on-`requesting_types` pattern `bundle-fetch.php` already uses for `tub`'s DateActivity-only date filtering (see `$has_date_activity` there). No Pods-API risk — just not asking for fields nothing in the response consumes. No cross-contamination risk with the existing whole-bundle transient cache either: it's already keyed by the full `types` param, so a `FlavorTub`-only request and an `InstockFlavor`-only request were already landing in separate cache entries before this change.

### 13. Global cache-version bump busts flavor's near-static data on every unrelated save

**Status (2026-08-29 note, superseding the 2026-07-30 line below):** *Implemented* — `flavor`, `use`, and `location` are in [scoop_slow_changing_entity_types()](includes/_cache.php#L37) (joined by `cabinet` via #14) and get their own entity-scoped cache version, bumped only by that post type's own save. Found already in place while working #4 on `worktree-repaint-sync`; this doc hadn't been updated to say so.

**Status (2026-07-30):** *Diagnosed, not yet fixed. Extends #4's already-recommended fix.*

**Location:** [scoop_cache_bust()](includes/_cache.php#L20)

**What:** `flavor`'s own editable properties (title, photo, menu_board, allergens, web_id) change roughly 3x/year per the user — but the whole bundle cache is invalidated by one global `scoop_cache_version`, bumped on every Pods save. A plain `tub` state change (which happens constantly) busts `flavor`'s cache too, even though nothing about `flavor` changed.

**Fix:** Once #12 lands (so `flavor`'s per-request shape no longer includes tub-derived fields), the remaining `flavor` fields are safe to cache far more durably than `SCOOP_CACHE_TTL`'s 5-minute safety net — invalidated only by an actual `flavor` save, not the global version. This is a more targeted version of #4's already-recommended whitelist fix: rather than (or in addition to) whitelisting *which post types* bump the cache at all, give slow-changing entity types (`flavor`, `use`, `location`) their own invalidation scope, separate from fast-changing ones (`tub`, `inventory_change`).

### 14. `DateActivity`'s `slot`/`cabinet` needs carried avoidable N+1 and cache-bust cost

**Status (2026-08-07):** *Implemented.* Two low-risk fixes, same class as #12/#13, found while investigating why `DateActivity` (the widest bundle spec — `tub`, `inventory_change`, `flavor`, `use`, `location`, `slot`, `cabinet`, see [scoop_bundle_specs](includes/_specs.php#L11)) is the slowest grid to load.

**What (a):** [scoop_fetch_entities](includes/bundle-fetch.php#L311) resolved `slot.location` via `$pod->field()` per row, but [scoop_enrich_slots_with_location](includes/bundle-fetch.php#L613) unconditionally overwrites `$slot['location']` for every row right after — the first resolution's result was never read. Pure waste on every request needing `slot` (`Cabinet`, `CabinetWorkflow`, `InstockFlavor`, `ItemPivot`, `DateActivity`).

**Fix (a):** `unset($needs_field['location'])` for `key === 'slot'` before the per-row loop, mirroring the existing `flavor.tubs`/`current_slots` skip pattern from #12.

**What (b):** `cabinet` posts are only saved during physical fridge setup ([includes/hooks/cabinet-slot.php](includes/hooks/cabinet-slot.php) — cabinet save creates that cabinet's slots once) — never touched by routine tub/slot operations — but was invalidated by the global `scoop_cache_version`, same pattern as #13's `flavor`/`use`/`location`.

**Fix (b):** Added `cabinet` to [scoop_slow_changing_entity_types()](includes/_cache.php#L37), giving it its own entity-level cache scope bumped only by a real cabinet save.

**Not done — biggest remaining lever for `DateActivity` specifically:** `tub`'s own `$pod->field()` N+1 (#11) is still the dominant cost, and `DateActivity` always needs the full `tub` fetch (client-side legacy fallback in [date-activity-grid-model.js](assets/models/date-activity-grid-model.js#L219) re-includes any tub not already represented by an `inventory_change` audit row). Unlike `FlavorTub`, `DateActivity`'s bundle also can't lean on the 5-minute transient much during business hours — normal tub-save traffic busts the whole-bundle cache continuously, which is exactly when staff are looking at this grid. #11's `get_post_meta()` bypass (deferred pending equivalence verification per its own note) is the fix that would actually move `DateActivity`'s cold-path number; not attempted here.

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

**Status (2026-08-29):** *Implemented* on `worktree-repaint-sync`. See [scoop_relevant_post_types()](includes/_cache.php#L59), called from `scoop_cache_bust()`.

**Location:** [includes/_cache.php](includes/_cache.php)

**What:** Global `save_post` / `trashed_post` / `untrashed_post` / `deleted_post` actions bump the cache version. Editing a blog post, menu item, or any unrelated CPT invalidates the scoop bundle cache.

**Why it's slow:** Cache hit rate on a busy WP site is much lower than the code implies.

**Fix:** Converted the `inventory_change` exclusion into a **whitelist**, derived from the union of every `scoop_bundle_specs()` `'needs'` list plus `closeout` (a write-only CPT no grid reads back through the bundle) — rather than the hand-written list this note originally suggested (`tub`, `batch`, `slot`, `cabinet`, `closeout`, `flavor`, `location`, `use`), which was already stale: it predates the `Task`/`Tasks`/`ShiftReport` specs and was missing `task`, `recipe`, `ingredient`, `unit`, `recipe_count`, `prep`, `allergen`, `supply`. Deriving it from `scoop_bundle_specs()` means it can't drift out of sync with the specs again.

### 5. Tub save cascades into flavor save + cache bust

**Status (2026-05-26):** *Partially addressed for batch creation.* [includes/hooks/batch-tub.php](includes/hooks/batch-tub.php) now suppresses repeated `flavor.modified_date` bumps while a batch is creating its child tubs, then updates the flavor once after all tubs are created. Standalone tub edits still use the existing hook.

**Location:** [scoop_bump_flavor_modified_date_on_tub_save](includes/hooks/batch-tub.php#L253)

**What:** Fires on every tub save. Inside it, `pods_api()->save_pod_item({ pod: 'flavor', ... })` writes the flavor, which fires `save_post`, which fires `scoop_cache_bust`. So a single tub state change → 1 flavor write → 1 full cache invalidation.

**Why it's slow:** Every tub edit forces an extra DB write plus a cache wipeout. Compounds dramatically during batch creation (see #6).

**Fix:** Audit whether `flavor.modified_date` is actually consumed anywhere — grep didn't surface a reader. If unused, delete the hook. If used, gate it: only bump when `state` actually transitioned, not on every amount edit.

## Medium severity

### 6. Batch creation serializes N tub writes and audit logging

**Status (2026-05-27, late):** *⚠ Direct-write path is broken — recommend reverting via `define('SCOOP_DIRECT_TUB_CREATE', false);` in `wp-config.php` until further investigation.*

Live test of an 11.4 batch (`batch 8368`) produced these observations:

```
batch 8368: wp_insert_post x12 in 389ms        ← reported 12 successes
batch 8368: wp_podsrel bulk insert 24 rows in 6ms
batch 8368: scalar saves x12 in 67732ms        ← still ~5.6s/tub
batch 8368: created 12 tub rows in 68131ms (direct)
```

**Data integrity problem:** The user reports that after the run, the batch record contains references to 12 tubs in its `tubs` relationship, but the **actual tub posts do not exist in `wp_posts`**. Same symptom observed on a prior "Birthday Cake" run. The `wp_insert_post()` calls returned post IDs that we trusted, the `wp_podsrel` bulk INSERT succeeded against those IDs, but the underlying tub posts are gone (or never persisted) by the time the user checks.

**Actual root cause (revised 2026-05-27 late, after DB inspection):** The earlier "no `wp_pods_tub` row" diagnosis was wrong. A direct SQL audit found **zero orphan rows** across all three integrity checks (tub posts without pod-table rows, pod-table rows without posts, podsrel rows pointing at dead items). The wp_pods_tub rows DID exist for the failed Dad Bod batch (8368). The issue was different:

- `pods('tub', $tub['id'])->save($scalar_args)` on a post that already has an ID is treated by Pods as an **UPDATE, not a CREATE**. Pods's field defaults (state='Freezing', etc.) are applied on creates, not updates.
- The result: track_pods_tub rows landed with **only the fields we explicitly passed** (`index`, `created_on`, `changed_on`, plus `amount` for the fractional tub). `state` was left NULL, and `amount` was left NULL for whole tubs.
- The FlavorTub grid's `WHERE state != 'Emptied'` filter ([includes/bundle-fetch.php:299](includes/bundle-fetch.php#L299)) treats NULL as falsy and silently excludes those rows. So the tubs were in the DB but invisible to the grid — which is what the user perceived as "the tubs aren't in the DB."

The fix (landed 2026-05-27 late) abandons `pods('tub', $id)->save()` entirely and writes wp_pods_tub via a single multi-row `$wpdb->query()` INSERT with all required columns explicitly set, including `state='Freezing'`. The Pods-API path remains the fallback via `define('SCOOP_DIRECT_TUB_CREATE', false)` and is the canonical safeguard for any case where the direct path's schema assumptions feel risky.

The 12 Dad Bod tubs from batch 8368 (IDs 8369-8380) have been backfilled via `UPDATE track_pods_tub SET state='Freezing', amount=COALESCE(amount, 1.00) WHERE id BETWEEN 8369 AND 8380` plus a `scoop_cache_version` bump.

**Performance also disappointing:** Scalar saves via `pods('tub', $id)->save()` still took ~5.6s each — Pods's save path apparently doesn't skip relationship work even when the changeset contains only scalar fields. Total dropped from 113s to 68s (~40%), not the ~10× hoped for.

**Decision:** The user's earlier instinct about the Pods API providing safeguards is the right read. The direct path was attempting to bypass exactly the machinery that keeps Pods records well-formed for wp-admin and Pods queries. Until we can either (a) identify why the posts disappear or (b) write a path that's truly faster AND produces well-formed records, the Pods-API loop is the safer default.

**Action items (resolved or in progress):**
1. ✅ Done — `define('SCOOP_DIRECT_TUB_CREATE', false)` is still the documented fallback. Default is `true` again now that the direct path is corrected.
2. ✅ Done — Dad Bod tubs from batch 8368 backfilled via SQL UPDATE.
3. ✅ Done — corrected direct path now bulk-INSERTs into `wp_pods_tub` with all columns explicit. See [includes/hooks/batch-tub.php](includes/hooks/batch-tub.php) `scoop_create_batch_tubs_direct()`.
4. Pending — verify with a real batch on dev that the new path produces tubs visible in the FlavorTub grid (same observable state as the Birthday Cake batch 8354).
5. Still on the table for later — async response (`fastcgi_finish_request()` after the batch post is saved) if even the corrected direct path feels too slow for the GUI's toast loop. Lower priority now that the direct path is correct AND fast.

**Status (2026-05-27, earlier):** *Largely addressed (turned out to be wrong — see late status above).* Diagnostics from the 2026-05-27 logs confirmed the bottleneck was `pods_api()->save_pod_item()` per tub (~9.4s/tub on dev, dominated by `PodsField_Pick->save` → `PodsAPI->save_relationships`). [includes/hooks/batch-tub.php](includes/hooks/batch-tub.php) now ships a `scoop_create_batch_tubs_direct()` path that uses `wp_insert_post()` + bulk `wp_podsrel` INSERT + per-tub scalar `pods()->save()`, gated behind the `SCOOP_DIRECT_TUB_CREATE` constant (defaults to true). The Pods-API loop is preserved as `scoop_create_batch_tubs_via_pods_api()` for fallback via `define('SCOOP_DIRECT_TUB_CREATE', false)`. Audit logging stays synchronous (only ~0.4s, no longer worth moving). Cache-bust is now suppressed during the loop and fired once at the end via the new `$GLOBALS['scoop_suppress_cache_bust']` flag honored in [includes/_cache.php](includes/_cache.php).

**Status (2026-05-26):** *Partially addressed and instrumented.* The redundant per-tub flavor update is suppressed during batch-created tub saves, the final batch publish update now checks whether the batch is already published, and timing diagnostics were added around both the batch post-save phases and the REST create/audit phases. The create path is still synchronous.

**Location:** [scoop_create_tubs_for_new_batch](includes/hooks/batch-tub.php), [scoop_handle_create_post](includes/rest.php), [scoop_log_post](includes/rest.php)

**What:** Creating a batch is one synchronous REST request. The batch save creates all related tub rows, then the REST handler writes an `inventory_change` audit row before returning to the browser. A fractional batch such as `9.25` creates ten tub rows, because it creates one fractional tub plus nine whole tubs.

**Why it's slow:** Tub creation fans out through Pods relationships and hooks, then audit logging may query back across those relationships to discover affected tubs/flavors. Before the partial fix, a 10-row batch also triggered 10 redundant flavor saves and many cache-version bumps. The user-facing symptom is a gateway timeout/503 even though the tubs were created, because the mutation can finish before all post-create work completes.

**Fix:** (a) The trailing `wp_update_post` is unnecessary if the batch is already `publish` — check first. (b) Bulk-create the tubs via a single transaction or `wpdb` insert, then fire `pods_api_post_save_pod_item_tub` manually per row.

**Additional fix steps:**
1. Keep the timing diagnostics long enough to capture a real `9.25+` batch and determine whether tub creation, cache invalidation, or audit logging dominates.
2. Suppress cache busting during batch-created tub writes and bump the cache version once after the batch is complete.
3. Move inventory-change audit logging out of the user-facing request using Action Scheduler, WP-Cron, or a small queue table/option. Return success once the batch and tubs are accepted/created.
4. If audit logging stays synchronous, pass known batch/flavor/created-tub IDs forward instead of rediscovering them with Pods relationship queries.
5. Consider a specialized lower-level tub creation path only after profiling proves Pods tub creation itself is the bottleneck; this is higher risk because it may bypass useful Pods hook behavior.

**User-facing requirement if split async:** The client should immediately show that the batch/tubs were accepted or created, then handle audit/log completion separately. Staff need a clear "tubs are being made" confirmation instead of a timeout-shaped failure.

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

**Update 2026-07-30:** #11-13 are now the top priority — they're the direct, measured cause of the 10-15s wait after every manual FlavorTub save, the most acutely-felt latency in the whole app. Sequence:

1. **#12** — *Done, verified locally.* Stop resolving `flavor.tubs`/`current_slots` unless `InstockFlavor` is requesting them. Zero Pods-API risk. Measured impact was more modest than first estimated: ~656ms off flavor's ~1928ms `needs_field` cost (~11% off the whole `FlavorTub` bundle's ~6s total), not the majority share originally assumed — see #12's corrected "Why it's slow."
2. **#13** — Still the bigger remaining lever for `flavor`: give it (and other slow-changing entities) their own cache-invalidation scope, separate from `tub`, so its *whole* fetch — including the `menu_board`/`photo`/`allergens`/`web_id` fields #12 didn't touch — can be served warm on nearly every `FlavorTub`-triggered request instead of just the two fields #12 removed. Also zero Pods-API risk. Not yet sized with real numbers; do that before estimating further.
3. `tub`'s own remaining `$pod->field()` cost: *Done (2026-08-10).* The `get_post_meta()` bypass was investigated and disqualified (see #11's 2026-08-07 update); a `wp_podsrel`-direct bulk read was implemented instead, with a full equivalence pass first (not a sample) — see #11's 2026-08-10 update for the verification and real before/after numbers. Also extended to `inventory_change.tubs`/`.flavors` (same pattern, multi-value) once that turned out to be the next-dominant cost on `DateActivity` specifically. `slot`'s own `cabinet` relationship is now the largest remaining per-entity `needs_field` cost seen — same shape, not yet attempted, would need its own equivalence pass first.

Tackle in this sequence for the rest — each step makes the next easier to measure:

1. **#4** — Whitelist the cache-bust post types. Lowest-risk change, immediately raises hit rate.
2. **#1** — Add the analytics transient cache. Single biggest latency win.
3. **#2** — Merge the duplicate tub scans. Halves the cold-path cost behind the cache.
4. **#5** — Audit/remove the flavor-bump hook. Removes write amplification.

Those four together should cut analytics latency by 3-5× on warm requests and dramatically raise the bundle cache hit rate. The medium items (#6, #7, #8) are worth doing afterward; the low items are micro-optimizations to defer until profiling shows them mattering.
 
Update after the batch-create 503 diagnosis: treat **#6** as the next operational performance fix after the cache/flavor-bump work. The concrete next step is one cache bust per batch, async audit logging, and immediate user confirmation that tubs were accepted or created.
