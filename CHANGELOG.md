# Changelog

Curated, reverse-chronological log of notable changes — what changed and why. For commit-level detail see `git log`.

## 2026-05-26

### Feature: nightly sales defaults and weather enrichment

**What:** Added a `nightly_sales` Pods pre-save hook in [includes/hooks/nightly-sales.php](includes/hooks/nightly-sales.php). New records default their title/slug to the sale date, using incoming `sale_date` when present and today's WordPress-local date otherwise. The same hook fetches Woodinville daily weather from Open-Meteo and fills matching Pods fields such as `temperature_2m_max`, `temperature_2m_min`, and `weathercode` when those fields exist.

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
