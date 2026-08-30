# Changelog

Curated, reverse-chronological log of notable changes — what changed and why. For commit-level detail see `git log`.

## 2026-08-30

### Chore: remove stale `schema.sql` / `dump.txt` from the repo root

**What:** Deleted `schema.sql` — a `mysqldump --no-data` of the predecessor `track_`-prefixed site, schema-only with zero `INSERT` statements — and `dump.txt`, a pasted wp-admin taxonomy list with a garbage header line, from the repo root. Updated the references: [README.md](README.md)'s Database schema section (which claimed the dump was "complete … for reference") and [CLAUDE.md](CLAUDE.md)'s scratch-artifacts convention list.

**Why:** Both files read like a restorable mirror dump of this install and are not — `schema.sql` has no data rows (it cannot stand up a dev mirror) and was three months stale: no `tub.moving_to` column (added 2026-08-29) and none of the `shift_report` / `supply` / `cake_order` pods (added 2026-08-11). A session burned a loop attempting to reproduce the mirror DB from them before discovering the truth. The authoritative schema knowledge lives in the README's schema section (inline, curated) and `includes/pods-schema/_schema.php` (code-managed Pods definitions); the live schema lives on the mirror DB itself. Both deleted files remain recoverable from git history (`c3b322b` / `a9af915`). The `.gitignore` entry and deploy rsync excludes for `dump.txt` are kept so a future scratch dump can't be committed or deployed by accident.

**Follow-up (same day):** deploys use `rsync` **without `--delete`** (by design — matches the previous VS Code SFTP workflow), so deleting the files from the repo did NOT remove them from the hosts: all three targets kept serving the stale `schema.sql` (never excluded) and ops.swankyscoop.net kept serving `dump.txt`. Added [cleanup-retired-artifacts.yml](.github/workflows/cleanup-retired-artifacts.yml) — a manual workflow that purges the retired artifacts from every target and fails if any path still exists afterwards. Run it once after merging; its `RETIRED` list is the going-forward home for "deleted from repo, must also die on the hosts" files.

## 2026-05-27

### Fix: add `closeout` entity to `scoop_entity_specs()` to silence per-page-load warning

**What:** Added a `closeout` entry to the `$cache` in [includes/_specs.php](includes/_specs.php) alongside the other Pod entities (tub, inventory_change, slot, cabinet, flavor, batch, use, location). Fields mirror `scoop_closeouts_allowed_fields()` — `tubs_emptied`, `flavor`, `use`, `location`, `order` — with `data_type` + `control` set so the column metadata shipped to the client is correct. Removed the diagnostic `error_log("scoop_entity_specs: Fetching specs for key: …")` on every lookup (noisy) and the TODO `error_log` about closeout being missing (now done).

**Why:** The user observed `scoop_entity_specs: WARNING - key not found: closeout` firing on every page load — even on pages that didn't include `[scoop_grid type="Closeout"]`. Cause: `scoop_client_metadata()` in [includes/enqueue.php](includes/enqueue.php) iterates the entire `scoop_routes_config()` to build the per-user metadata package shipped as `SCOOP.metaData`. Since the `Closeout` route exists, the metadata builder always asks for its entity spec, regardless of which grids are on the current page. With the spec missing, every page that loaded `app.js` logged the warning. Adding the spec silences it and also lets the metadata system serve correct column defs if the JS ever wants to consume them.

### Fix: BatchHistory default reverted to 48 hours; shortcode example minimized

**What:** Reverted `BatchHistoryGridModel`'s default date window from `last_7_days` (a transient change earlier today) back to `last_48_hours` per the operational note that 48hr is the typical view and a week is the upper-typical, with 30 days the outside. The four select options (24h / 48h / 7d / 30d) remain — user can still extend the window via the widget. Updated [SHORTCODES.md](SHORTCODES.md) to lead with the minimal form `[scoop_grid type="BatchHistory"]` and show the `filter_created` override as a secondary example. Rewrote the model's docblock to make the optional-attribute behavior explicit (both `date_filters` and `filter_created` are optional; server and client each default independently to `created` / `last_48_hours`).

**Also fixed:** the earlier global rename of `last_7_days` → `last_48_hours` had over-replaced into the model's `options` array and `allowed` list, which would have made `last_7_days` reject as an invalid preset. Both occurrences restored to `last_7_days`. (This was an unintended side effect of a `replace_all` edit; the affected lines were only briefly broken inside the same conversational turn.)

### Docs: `SHORTCODES.md` — catalog of grid variants

**What:** New top-level [SHORTCODES.md](SHORTCODES.md) listing every `[scoop_grid type="..."]` variant currently available, grouped by workflow (Daily ops, Planning, Insight, Audit). Each entry has a one-line description, a copy-paste shortcode example, and a note on filterable attributes. Also documents the common attributes (`type`, `location`, `days`, `date_filters`, `filter_<key>`) and the patterns to remember (case-sensitivity, bundle sharing, location defaults).

The doc is intentionally short — workflow grouping + one line per grid + one example each. Anyone landing in the repo for the first time should be able to read it in under two minutes and know what each grid does. Linked from [README.md](README.md)'s footer.

**Why:** The grid catalog had been spreading across `assets/README.md`, `CLAUDE.md`, and CHANGELOG entries. A single reference list makes adding a new variant easy to keep up to date and gives non-developer users (e.g. shop managers configuring WordPress pages) one place to look for "what's available."

### Docs: `EZ-TYPE-2-GRID.md` cookbook + BatchHistory default tweak

**What:** New top-level [EZ-TYPE-2-GRID.md](EZ-TYPE-2-GRID.md) — a focused cookbook for spinning up a new grid from a Pods content type in minimal steps. Two paths documented: read-only listing (4 files) and read+write CRUD (Path A + 3 more things). Includes a complete copy-paste-ready JS model template, the `data_type` reference table, common gotchas (tables-mode integrity rule, bidirectional relationships, Pods cache), and a final checklist. Cross-linked from [README.md](README.md)'s footer.

Also bumped the `BatchHistory` grid's default date window from `last_48_hours` to `last_7_days` per the operational note that "a week is the typical scope; a month is the outside." The select still offers 24h / 48h / 7d / 30d.

**Why:** Even with the per-directory READMEs and CLAUDE.md, finding the minimum steps for "I just want a grid over this CPT" required reading three docs and one example file. The cookbook condenses that to a single page with concrete tokens to replace. It's also the right place to capture the gotchas that have repeatedly bitten direct-write work (tables-mode invariants, bidirectional rows, Pods cache) without burying them in the architecture docs.

### Feature: `BatchHistory` grid — read-only list of past batches

**What:** New bundle-pattern grid type `[scoop_grid type="BatchHistory" date_filters="created" filter_created="last_30_days"]`. Read-only listing of `batch` posts with four columns: **Created** (post_date), **Flavor**, **Tubs** (count), **Author**. Newest-first by post_date. Date-range filter offers presets `last_24_hours` / `last_48_hours` / `last_7_days` / `last_30_days` via a `mode: 'server'` select widget — changing it triggers a bundle refresh so the SQL window slides.

Files touched:
- New: [assets/models/batch-history-grid-model.js](assets/models/batch-history-grid-model.js) — extends `BaseGridModel`, hardcodes columns, sorts by post_date desc, implements the `getFilterDefs() / getServerFilterParams()` pattern matching `DateActivityGridModel`.
- Edited: [assets/data/scoop-api.js](assets/data/scoop-api.js) — imports the new model and registers it in `getModelsBom()`.
- Edited: [includes/_specs.php](includes/_specs.php) — added `BatchHistory` to `scoop_bundle_specs()` (`needs: ['batch','flavor']`) and a new `batch` entity to `scoop_entity_specs()` with the `count` + `flavor` fields plus `author_name` + `post_date` from post_fields.
- Edited: [includes/bundle-fetch.php](includes/bundle-fetch.php) — defaults the `created` date_filter key when BatchHistory is requested without one, and adds a `t.post_date` WHERE clause in `scoop_fetch_entities('batch', ...)` so the bundle never ships the entire batch history.

**Why:** Spec'd by ops as "show me batches filterable by date range with author, count, flavor, created date." The bundle pattern fits naturally — same shortcut JS authors use for other grids, server-side SQL windowing for scalability, and the read-only nature means no write route or `_config.php` change. The model is also a useful reference template for any future "read-only, filterable, listing" grid.

### Feature: 2-hour periodic Pods cache refresh + `wp scoop cache-refresh`

**What:** New file [includes/cron.php](includes/cron.php) registers a WP-Cron event `scoop_periodic_cache_refresh` on a 2-hour schedule. Each tick calls `pods_cache_clear(false, 'pods_items_<pod>')` for `tub`, `batch`, `flavor`, `slot`, `cabinet`, `closeout`, `location`, `use`, `nightly_sales`, `inventory_change`, then bumps the bundle cache version. The Pod list lives in `scoop_cron_pods_to_refresh()` — add new CPTs there as they ship.

Paired CLI command `wp scoop cache-refresh` (in [includes/cli.php](includes/cli.php)) calls the same callback for manual triggering — useful when Admin Columns is showing stale data right now and you don't want to wait for the next tick.

**Why:** Confirmed today that the user's earlier observation ("Admin Columns doesn't show tubs on direct-write batches even though Pods edit GUI does") was a Pods per-item cache that the direct-write paths don't invalidate. Manually re-saving the batch from wp-admin fixed it because Pods's full save flow clears caches as a side effect. Decision: rather than inline cache-clear calls on every direct-write (which would couple the persistence layer to a particular caching plugin's quirks), use a periodic flush. Cadence chosen to keep cache hit rate high while bounding staleness; the cadence is a tunable.

**Caveat:** WP-Cron only fires on inbound traffic. On a low-traffic site the actual cadence drifts. README documents the system-cron fallback (`wp scoop cache-refresh` from an OS crontab) if hard cadence is needed.

Docs: README "Periodic Pods cache refresh" subsection added under "Integrity audit queries"; includes/README.md per-file table now lists `cron.php`.

### Feature: `wp scoop audit` WP-CLI command

**What:** New file [includes/cli.php](includes/cli.php) registers a `wp scoop audit` command that runs the integrity checks documented in the project [README.md](README.md) and exits non-zero if any issue is found. Two checks today:

1. Orphan tubs — `track_posts.tub` rows with no companion `track_pods_tub` row.
2. Bidirectional podsrel drift — batches/flavors/locations where the forward (tub→owner) and reverse (owner→tubs) podsrel counts disagree.

Field IDs are resolved at runtime via the Pods config so the command survives reinstalls or field-ID renumbering. Flags: `--skip-orphans`, `--skip-bidir`, `--limit=<n>`. File early-returns when `WP_CLI` isn't defined → zero overhead in normal HTTP/REST requests.

Wired into the plugin loader at the bottom of [scoop_rest.php](scoop_rest.php), and listed in the [includes/README.md](includes/README.md) "Misc / admin / scratch" table for discoverability.

**Why:** Both bug classes we just resolved (orphan tubs and missing reverse podsrel rows) were silent — wp-admin and the FlavorTub grid hid the symptom rather than erroring. A standing audit command that exits non-zero makes either regression detectable from CI, a post-deploy hook, or a periodic cron, instead of waiting for someone to notice tubs aren't showing up.

**Execution:** On Local (Windows), use "Open Site Shell" → `wp scoop audit`. On production, just `wp scoop audit` from the site root. Without WP-CLI, the README also documents the raw SQL queries the command runs internally — paste into Adminer / phpMyAdmin / the mysql CLI.

### Fix: direct-write tub creation now writes bidirectional `wp_podsrel` rows

**What:** Pods stores each bidirectional relationship as TWO rows in `wp_podsrel` — one per direction. The earlier corrected direct path only wrote the **forward** direction (tub→batch, tub→flavor, tub→location), so wp-admin showed batches with empty `tubs` fields even though every tub linked back to its batch. [includes/hooks/batch-tub.php](includes/hooks/batch-tub.php) `scoop_create_batch_tubs_direct()` now writes both directions: for each tub, the bulk podsrel INSERT produces up to 6 rows (3 forward + 3 reverse for `batch.tubs`, `flavor.tubs`, `location.tubs`). Reverse-field IDs are resolved from each related Pod's `tubs` field via `pods_api()->load_pod()`. The audit log writes its own `inventory_change.tubs` reverse, so we don't have to.

Backfilled the two affected batches via idempotent `INSERT ... WHERE NOT EXISTS`:
- Batch 8368 (Dad Bod) — added missing `tub.location` forward rows for tubs 8370-8380 plus all three reverse directions for 8369-8380
- Batch 8394 (Tomato Sorbet) — added all three reverse directions for tubs 8395-8406

Post-backfill verification: both batches now have **25 podsrel rows each (13 forward + 12 reverse)**, matching the Pods-API baseline (Birthday Cake batch 8354).

**Why:** Symptom reported on 2026-05-27 — batch 8394 created via the new direct path showed no tubs in wp-admin despite all 12 tubs linking back to the batch. Diagnosed by comparing podsrel coverage to a known-good batch: forward direction was complete (12 rows where `related_item_id = batch_id`), reverse direction was empty (only 1 row where `item_id = batch_id`, the auto-generated batch→flavor). The Pods-API path produces reverse rows automatically via `PodsField_Pick->save`; the direct path has to write them explicitly.

**Docs:** Extended [README.md](README.md) "The integrity rule, restated" to add the bidirectional invariant (every Pods relationship = two podsrel rows). Added an "Integrity audit queries" subsection with two self-contained SQL queries that should return zero rows on a healthy DB — one for orphan tub posts, one for forward/reverse podsrel drift. Added schema-issue #7 marking this regression class resolved.

### Retraction + hardening: `nightly_sales.location` exists and is set

**What:** Retracted README schema-issue #4 ("no location relationship on nightly_sales"). DB inspection showed the Pod **does** have a location field (Pods field ID 7285) and all 1000 imported rows have `location = 935` (Woodinville). The earlier diagnosis was based on a schema dump that — by design — doesn't surface Pods relationships, since those live in `track_podsrel`, not on the per-Pod table.

Hardened `scoop_nightly_sales_import_data()` in [includes/hooks/nightly-sales.php](includes/hooks/nightly-sales.php) so the location cascade ends in a hard-coded `935` fallback. Previously, if `SCOOP_DEFAULT_LOCATION_ID` was undefined and `scoop_get_default_location_id()` wasn't loaded, the location field would silently not be set. The new three-tier cascade guarantees the field is always populated regardless of include order or constant availability.

**Why:** The user reported "I suspect the import did not add a location field." The data showed otherwise, but the original code had a real latent risk (silent skip if both the constant and function were unavailable). Defensive fallback removes that risk class. README issue #4 has been rewritten as a "Retracted" note with a methodology lesson (schema-only diagnostics will miss every relationship; always cross-check `track_podsrel`).

### Fix: rename `tempature` → `temperature` on `track_pods_nightly_sales`

**What:** User renamed the Pods field/column from `tempature` to `temperature` via Pods admin. Updated the three code references in [includes/hooks/nightly-sales.php](includes/hooks/nightly-sales.php) — the `scoop_nightly_sales_weather_field_map()` entry and the two sites that round Open-Meteo's `temperature_2m_max` into the stored field. Re-dumped [schema.sql](schema.sql) to capture the corrected column. Marked README schema-issue #3 as resolved.

**Why:** Issue #3 from the 2026-05-27 schema audit. Cheapest to fix while the field was young — only three call sites in code, no third-party integrations to coordinate with. Historical CHANGELOG mentions of the original spelling are intentionally left in place as a record of the typo's existence.

### Fix: direct-write tub creation now bulk-INSERTs the per-Pod table

**What:** Rewrote `scoop_create_batch_tubs_direct()` in [includes/hooks/batch-tub.php](includes/hooks/batch-tub.php) to bypass `pods('tub', $id)->save()` entirely. The new flow does three explicit wpdb writes plus a manual hook fire:
1. `wp_insert_post()` per tub (posts only)
2. One multi-row `INSERT INTO wp_pods_tub` covering every tub's `id`, `state` (hard-coded `'Freezing'` to match the Pods field default), `index`, `amount` (`1.00` for whole tubs, fractional for the partial one), `created_on`, `changed_on`
3. One multi-row `INSERT INTO wp_podsrel` covering batch + flavor + location relationships across all tubs
4. `do_action('pods_api_post_save_pod_item_tub', …)` per tub so downstream listeners still fire

The Pods-API path remains the documented fallback via `define('SCOOP_DIRECT_TUB_CREATE', false)` in `wp-config.php` — slow but canonical, with full validation and hook coverage. The function docblock spells out when to reach for the fallback.

**Why:** Earlier today's direct-write attempt left tubs with `state = NULL` in `wp_pods_tub` because Pods treated the call as an UPDATE (post already exists by ID), and Pods only applies field defaults on creates. The FlavorTub grid's `state != 'Emptied'` filter silently excluded them, which looked like "no tubs in the DB." A SQL audit confirmed no orphan rows at any layer — the data was there, just incomplete. Writing every required column ourselves removes the reliance on Pods's create-vs-update default semantics. The 12 Dad Bod tubs from batch 8368 were backfilled via `UPDATE track_pods_tub SET state='Freezing', amount=COALESCE(amount, 1.00) WHERE id BETWEEN 8369 AND 8380` plus a `scoop_cache_version` bump.

### ⚠ Regression: direct-write tub creation produces orphan relationships

**What:** Live test of the direct-write path (`SCOOP_DIRECT_TUB_CREATE=true`) produced `wp_podsrel` rows referencing tub IDs that don't exist in `wp_posts` afterward. The user's batch record shows 12 tubs in its `tubs` relationship, but the underlying tub posts are missing. Reproduced on two batches (Birthday Cake earlier, Dad Bod at `batch 8368`). Recommend reverting via `define('SCOOP_DIRECT_TUB_CREATE', false);` in `wp-config.php` until further investigation. See [performance.md](performance.md) finding #6 for hypotheses and action items.

**Why captured:** The earlier 2026-05-27 entry below claimed this path was "largely addressed." It wasn't — the timing was better (113s → 68s) but the data was wrong, and the scalar `pods('tub', $id)->save()` calls still took ~5.6s each, so the perf gain was modest anyway. Recording this so future passes don't repeat the bypass-Pods approach without first solving why the posts disappear.

### Performance: direct-write tub creation

**What:** Replaced the per-tub `pods_api()->save_pod_item()` loop in [includes/hooks/batch-tub.php](includes/hooks/batch-tub.php) with a direct path:
- `wp_insert_post()` once per tub (post row only — no Pods overhead)
- A single bulk INSERT into `wp_podsrel` for batch + flavor relationships across all tubs
- A per-tub `pods('tub', $id)->save()` for scalar fields (`index`, `amount`, `location`, `created_on`, `changed_on`)

The post-save hook chain still fires correctly — `pods('tub', $id)->save()` invokes `pods_api_post_save_pod_item_tub`, which the state-machine hooks listen for. The Pods-API loop is preserved as `scoop_create_batch_tubs_via_pods_api()`; set `define('SCOOP_DIRECT_TUB_CREATE', false)` in `wp-config.php` to fall back. Cache-bust during the per-tub loop is also suppressed via a new `$GLOBALS['scoop_suppress_cache_bust']` flag honored by [includes/_cache.php](includes/_cache.php); a single `scoop_cache_bust()` fires after the loop completes.

**Why:** Diagnostics from the 2026-05-27 log of a 12-tub batch showed `pods_api()->save_pod_item()` taking ~9.4 seconds per tub (113s total), entirely inside `PodsField_Pick->save` → `PodsAPI->save_relationships` — Pods does a separate DB round-trip per relationship field per tub. Bypassing that machinery and writing the relationships as one multi-row INSERT removes the dominant cost. Estimated drop: ~9.4s/tub to a low-hundreds-of-milliseconds-total range. The UI toast / form release should now complete in roughly the time it takes to write N posts plus one bulk-relationship INSERT, not N × (relationship-save overhead).

**Follow-up if dev still feels slow:** The next lever is true async — return the REST response immediately after the batch post is created, and run tub creation in a deferred job (Action Scheduler or `wp_schedule_single_event` + a spawned cron ping). That gives the GUI an instant toast regardless of how many tubs are queued.

## 2026-05-26

### Diagnosis/Fix: batch creation 503 after large fractional batches

**What:** Diagnosed the `Batch` shortcode path for a case where creating `9.25` tubs returned a 503 even though the tubs were created. The create path is synchronous: one batch save creates all related tub rows, then writes the inventory-change audit before the REST response returns. Added timing diagnostics around the create/audit phases in [includes/rest.php](includes/rest.php), plus per-phase batch post-save timing in [includes/hooks/batch-tub.php](includes/hooks/batch-tub.php). During batch-created tub saves, the repeated `flavor.modified_date` bump is now suppressed and replaced by one flavor update after all tubs are created. The final batch publish update now only runs if the batch is not already published.

**Why:** A large fractional batch can create many Pods records in one request. Before this change, `9.25` tubs meant ten tub saves plus ten redundant flavor saves, followed by audit logging, all before the client received a response. That explains the symptom: the mutation can complete, but the HTTP request still times out or receives a gateway 503 during post-create work. The new logging separates tub creation time from audit logging time, and the redundant flavor-save reduction lowers the chance of hitting the timeout in normal use.

**Follow-up note:** Creation and audit logging are not split into separate jobs yet. If that becomes the next fix, the client should get an immediate confirmation that the batch/tubs were accepted or created, then handle audit/log completion separately so staff see a clear "tubs are being made" message instead of a timeout-shaped failure.

### Feature: nightly sales defaults and weather enrichment

**What:** Added a `nightly_sales` Pods hook in [includes/hooks/nightly-sales.php](includes/hooks/nightly-sales.php). New records default their title/slug to the sales date, using incoming `sales_date`/`sale_date` when present and today's WordPress-local date otherwise. The Add New wp-admin form now pre-fills only the title and sales date visually before save. On creation, the hook fetches Woodinville daily weather from Open-Meteo and fills hidden/system-managed fields such as `tempature` and `weather_quality`. When a CSV is attached to the `upload` field, the post-save hook parses the weekly pivot export, upserts one record per non-zero sales day, and batches Open-Meteo lookups for the imported dates.

**Why:** Cone sales entries need stable date-based labels for both daily entry and historical import, and the weather data is useful context for demand/forecast work without requiring staff to enter it manually.

### Docs: GUI planning document

**What:** Added [GUI-planning.md](GUI-planning.md) — a working document for the client-side UI evolution. Currently holds the two framing decisions from today's discussion (stay vanilla / lean on CSS tokens; tabs via wrapper shortcode with eager mount + CSS toggle) and a structured backlog ready for use-case dumps.

**Why:** Use cases are arriving faster than the architecture can absorb them, so we wanted a single place to dump raw use cases and incrementally cluster them into functional areas → workflows → tasks without locking in premature structure.

### Performance: analytics transient cache

**What:** The `/scoop/v1/analytics` endpoint now caches responses in a WP transient. New `scoop_analytics_cache_key()` in [includes/_cache.php](includes/_cache.php) keys on `version | days | location | grid_type`; [includes/analytics.php](includes/analytics.php) checks at the top of the handler and writes on both success paths. Cache hits re-stamp `trace_id` so log correlation still works per-request, and `_cache: 'hit'|'miss'` is added to the response so cache behavior is visible in devtools.

**Why:** This is finding #1 from [performance.md](performance.md) — the single biggest perf win available. Invalidation rides on the existing version-bump (`save_post` etc. in [_cache.php](includes/_cache.php)), so the analytics cache stays consistent with the bundle cache for free. Combined with the stage map landed earlier today, a warm Flavors page now serves analytics from the transient and never touches Pods.

### Performance: analytics stage map

**What:** The `/scoop/v1/analytics` endpoint now accepts a `grid_type` query param and only runs the aggregate stages each grid actually consumes. New helper `scoop_analytics_stages_for_grid_type()` in [includes/analytics.php](includes/analytics.php) declares the mapping:
- `Analytics` → all four stages (full dashboard)
- `Popular` → `aggregate_tubs`, `sellthrough`, `current_stock` (skips `last_batch`)
- `Flavors` → `current_stock`, `last_batch` (skips both tub-table scans for sales/sellthrough)

Client side, [assets/models/analytics-grid-model.js](assets/models/analytics-grid-model.js) sends `grid_type` automatically based on `this.name`, so every analytics-pattern model picks it up without further work.

**Why:** The analytics handler previously ran every stage for every caller, even though Flavors only reads `current_stock` and `last_batch_date`. Each skipped stage is a full scan of the `tub` table, so for the Flavors grid this cuts the cold path by ~2-3×. Unknown/missing `grid_type` falls through to all stages, so the change is backwards-compatible. See [performance.md](performance.md) finding #2 for the related (still-open) merge-duplicate-scans work.

### Performance audit

**What:** Added [performance.md](performance.md) — a prioritized punch list of concrete performance issues across the server and client hot paths, each with `file:line`, root cause, and a specific proposed fix. Ten findings total, grouped High / Medium / Low.

**Why:** Before optimizing anything we wanted a single artifact capturing where the real cost lives, so future work can be sequenced by impact rather than by hunch. The top three findings (uncached analytics endpoint, three full tub scans per call, site-wide cache-bust on every `save_post`) are the ones to fix first.

### Documentation: per-directory READMEs

**What:** Replaced the 3-line root [README.md](README.md) with a high-level guide covering the two coding patterns (bundle CRUD vs. analytics read-only), an architecture diagram, and a file-by-file map. Added [assets/README.md](assets/README.md) for the client side and [includes/README.md](includes/README.md) for the server side; each is a per-file role table organized by subdirectory.

**Why:** The original README didn't give a UI/coding agent enough context to add a new view without spelunking. Both patterns now have explicit "files to touch" tables and small recipes for adding a new grid type. CLAUDE.md remains the deeper architectural reference; the READMEs are the on-ramp.

### Feature: "Flavors" grid view

**What:** New read-only grid type `[scoop_grid type="Flavors" location="..."]` that lists every flavor grouped by **Dairy** and **Non-Dairy** (based on the `dairy` allergen slug), with three columns:
- **Flavor** — flavor name
- **Tubs** — count of tubs not in `Emptied` state (from `current_stock`)
- **Days Since Served** — days since the most recent batch's `post_date`, or `Never` if the flavor has never been produced

Files touched:
- New: [assets/models/flavors-grid-model.js](assets/models/flavors-grid-model.js)
- Edited: [assets/data/scoop-api.js](assets/data/scoop-api.js) — import, registry, analytics mount branch
- Edited: [assets/css.css](assets/css.css) — extended the read-only save-button-hide rule

**Why:** Ops wanted a one-glance answer to "which flavors are overdue to make?" while also seeing current stock per flavor in the same view. The analytics endpoint already computed `last_batch_date` and `current_stock` per flavor (the server-side half had been written but never surfaced — see [includes/analytics.php:617](includes/analytics.php#L617)), so the new view is a pure client-side projection over the existing response. No PHP changes, no new endpoint. The grid follows the analytics pattern (extends `AnalyticsGridModel`, read-only) rather than the bundle pattern.
