# Changelog

Curated, reverse-chronological log of notable changes — what changed and why. For commit-level detail see `git log`.

## 2026-05-26

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
