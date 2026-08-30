# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A WordPress plugin (`scoop_rest.php`) for tracking ice cream tub/flavor inventory at Swanky Scoop. It is "nearly headless": WordPress renders the page shell and a `[scoop_grid type="..."]` shortcode, then a vanilla-ESM JavaScript client takes over and talks to a custom REST API backed by [Pods](https://podsfoundation.org/) custom post types.

The repository root maps directly to `wp-content/plugins/scoop_rest/` on the server.

## Build / run / test

There is **no build step, no package manager, no test suite**. JS files are loaded directly as ES modules by the browser (`script_loader_tag` filter rewrites the `scoop-grid` handle to `type="module"`).

Deployment is by GitHub Actions (`.github/workflows/deploy.yml`), via `rsync` over SSH — no SFTP-on-save, no VS Code SFTP extension. A push to `main` auto-deploys to `test.swanky.ink` (the `deploy-test-ink` job; it is the ONLY auto target). Deploying to `ops.swankyscoop.net` or `ops.swanky.ink` (production) requires a manual `workflow_dispatch` run with `target: ops` / `target: ops-ink`, gated behind the `ops` GitHub environment. The SSH deploy key lives only in the `SFTP_SSH_KEY` Actions secret; the workflow explicitly excludes any `scoop-deploy-key*`/`*.pem`/`*_rsa`/`*_ed25519`/`id_*` files from the rsync payload so a stray key file in the working tree can't get shipped.

To exercise changes, commit and push to `main` (deploys to TEST) and reload a page that contains a `[scoop_grid ...]` shortcode while logged in. When debugging server-side, set `define('SCOOP_DEBUG_LOG', true)` in `wp-config.php` to enable `scoop_debug_log()` output to the PHP error log.

### Local development mirror (fastest loop)

There is a [Local](https://localwp.com/) site, `swank-tracker`, served at `https://ops.swanky.local`, whose plugin directory is a **symbolic link to this repository**. This is the free-standing Local app only — no Flywheel/WP Engine hosting account or remote-fetch/sync feature is in use, so local and production data don't sync automatically (see below):

```
…/Local Sites/swank-tracker/app/public/wp-content/plugins/scoop_rest  →  <repo root>
```

Because it's a symlink, **edits to the repo are live on the local site immediately** — no SFTP, no copy. Reload for PHP; hard-refresh for JS (ES module cache). This is the fastest way to validate a change. Reach it with `curl -k` (self-signed cert). The local site has its own database and users, and its Pods config/content can differ from TEST and OPS — don't assume parity (see "Do not suggest" notes on environment drift).

The GitHub Actions deploy workflow still governs the **real** servers (`test.swanky.ink`, `ops.swankyscoop.net`, `ops.swanky.ink`); commit and push to ship once a change is validated locally. The local mirror is for iteration only.

## Architecture

### Request lifecycle for a grid page

1. WordPress renders a post containing `[scoop_grid type="FlavorTub" location="935"]`. `includes/shortcode.php` emits a `<div class="scoop-grid" data-grid-type="FlavorTub" data-location="935">` and `includes/enqueue.php` enqueues `assets/app.js` as a module along with a `SCOOP` global containing `routes`, `metaData`, `nonce`, and `user`.
2. `assets/app.js` constructs `ScoopAPI` and calls `mountAllGrids()`, which scans the DOM for all `.scoop-grid[data-grid-type]` hosts.
3. `ScoopAPI.refreshPageDomain()` requests **one** bundle from `GET /wp-json/scoop/v1/bundle?types=Cabinet,FlavorTub,...` that returns every entity any grid on the page needs.
4. Each host gets a `Grid` (`assets/ui/grid.js`) + a per-type `*GridModel` (`assets/models/*-grid-model.js`). The model receives the full domain via `setDomain()` and builds its own rows.
5. Edits POST to a per-type write endpoint (e.g. `POST /wp-json/scoop/v1/tubs`) with a `{ "<envelope_key>": { cells: [...] } }` payload — see `ScoopAPI.postJson` and `scoop_handle_request`.

### Bundle: one request, many entities

`scoop_bundle_specs()` (in `includes/_specs.php`) declares which raw entities each grid type "needs":

```
Cabinet      => cabinet, slot, flavor
FlavorTub    => tub, flavor, use, slot
Batch        => flavor
Closeout     => flavor, use
DateActivity => tub, inventory_change, flavor, use, location, slot, cabinet
```

`includes/bundle-fetch.php` bulk-fetches each entity with `pods()->find()` and resolves relationships via `$pod->field()`. The bundle is cached in a WP transient keyed by a global integer version (`scoop_cache_version` option). Any `save_post`/`trashed_post`/`untrashed_post`/`deleted_post` increments that version, instantly invalidating every cached key (`includes/_cache.php`). `inventory_change` post saves are excluded — they fire too often and don't change grid data the client cares about.

### Route configuration is single-sourced

`scoop_routes_config()` in `includes/_config.php` is the one place that maps a grid type to its URL path, allowed methods, write mode (`create` vs `update`), pod name, and an `allowed_fields_cb`. From this one config:

- `includes/_routes.php` registers the REST routes
- `includes/enqueue.php`'s `scoop_client_routes()` builds the JS `SCOOP.routes` map
- `includes/rest.php`'s `scoop_handle_request()` dispatches creates vs updates
- `scoop_client_metadata()` builds the per-field column definitions sent to the JS

When adding a new grid type, you typically need to touch: `_config.php`, `_specs.php` (entity needs + field defs), an `allowed_fields_cb` (often in `_write_fields.php`), and add a new `*GridModel` class registered in `ScoopAPI.getModelsBom()`.

### Permissions are two-layer

- **Route-level**: `scoop_user_can_route()` in `includes/_policy.php` — can this role GET/POST this route at all?
- **Field-level**: the writable set for a row is `spec.writeable ∩ scoop_user_writeable_fields(user, entity)`. `scoop_client_metadata()` in `enqueue.php` computes this intersection per-user-per-entity and ships it to the client as the `write` flag on each column, so the JS already knows what's editable for the current user.

Roles checked in priority order: `administrator` → `kitchen_manager` → `shift_lead` → `ice_cream_maker` → `kiosk` → `editor` → `author` → `_default` (deny-everything fallback for any unrecognized role). `kitchen_manager`/`shift_lead`/`ice_cream_maker`/`kiosk` are real WP role slugs created via the User Role Editor plugin, not registered by this codebase — see `includes/_policy.php`'s top-of-file comment for what each role can do.

### Pods hooks enforce write rules

The files in `includes/hooks/` register `pods_api_pre_save_pod_item_*` filters that enforce business rules at the data layer regardless of which write path was used (REST API, WP admin, direct Pods call). The activation hook registers `scoop_enforce_tub_rules` on `pods_api_pre_save_pod_item`. When changing tub/slot/batch/closeout write behavior, prefer adding to these hooks over patching the REST layer, so the rule holds for all save mechanisms.

### Analytics is the odd one out

The `Analytics` grid bypasses the bundle pattern entirely. `mountAllGrids()` separates analytics hosts and lets `AnalyticsGridModel` fetch its own data from `scoop/v1/analytics`. The grid is read-only (no save button — hidden via CSS). See `INTEGRATION.md` for the rationale and the registration steps.

## Conventions

- **Write envelopes**: every POST body is `{ "<EnvelopeKey>": payload }` where `EnvelopeKey` matches the route key in `scoop_routes_config()` (`Cabinet`, `FlavorTub`, etc.). `ScoopAPI.postJson(payload, type)` does this wrapping automatically.
- **Cache-busting on GET**: `ScoopAPI._fetch` appends `_ts=<now>` and sets `Cache-Control: no-cache` on every GET. Don't try to "fix" what looks like over-fetching here — the WP transient layer is what actually saves the round trip.
- **Underscored PHP files** (`_config.php`, `_specs.php`, `_policy.php`, etc.) are the configuration/contract layer; non-prefixed files are runtime. Underscored JS files (`_base-grid-model.js`, `_flavor.js`, `_column-provider.js`) are abstract bases / helpers, not concrete grid types.
- **Prettier**: `.prettierrc` (root of repo) is `tabWidth: 2`, `useTabs: false`, `singleQuote: true`.
- `assets/Main.js`, `dump.txt`, `_domain.json`, `response.json`, `1_response.json`, `fast_response.json`, `post_response.json` at the root are scratch/debug artifacts, not part of the runtime.

## Business domain knowledge

### Ice cream product structure

- **Base types**: dairy, oat, coconut, pea. Sorbet has no base — do not assign or expect a base ingredient for sorbet flavors.
- **Flavors** are linked to **recipes**, which reference **ingredients**. These three are the core cost chain.
- **Vanilla** for several flavors is purchased directly from the vendor (not through a distributor).
- **Cocoa** sourcing alternates between Webstaurant and Chef's Warehouse at negotiated prices — do not assume a single fixed price.

### Vendors

Primary vendors, in order of frequency:
1. Webstaurant (online)
2. Chef's Warehouse (negotiated pricing)
3. US Foods CHEFSTORE (local, Bothell WA)

No vendor APIs exist. All pricing is manual entry. Do not suggest automated price scraping or API-based price feeds as a solution.

### Ingredient pricing — known data quality issues

Ingredient and recipe cost data was largely entered manually in a single pass and is known to be unreliable. Treat all pricing data as suspect until verified. Key failure modes:

- **Unknown or wrong units** are the most common cause of wildly wrong price/unit figures (e.g. price entered per ounce but unit stored as pound).
- **Order-of-magnitude errors** are a strong signal of a unit mismatch, not a value error.
- **Sanity bounds for ice cream**: a finished gallon of ice cream costs roughly $10–$30. A price/gallon below $1 or above $100 is almost certainly a data error.
- Price fluctuations, unit conversions, and summed purchase prices make extracting a true price/unit from purchase history unreliable without a dedicated data entry workflow.

When working on pricing features, lead with detection and reporting of bad data before attempting correction. Never silently correct pricing data — surface the issue for human review.

## Data repair policy

**Any task that writes, updates, or connects live Pods/WordPress records must be validated on TEST before OPS.**

- Use `test.swanky.ink` (the auto-deploy target on push to `main`) for all exploratory and repair work.
- Produce a report or dry-run output first; get explicit approval before writing to OPS (`ops.swankyscoop.net` / `ops.swanky.ink`).
- For multi-step writes (e.g. connecting ingredients → recipes → flavors), remember that all `track_pods_*` tables except `nightly_sales` are `ENGINE=MyISAM` — no transactions, no rollback. Order inserts defensively and plan explicit cleanup on failure.

## Do not suggest

These approaches have been evaluated and decided against — do not re-propose them:

- Connecting directly to remote servers (test.swanky.ink, ops.swankyscoop.net, or ops.swanky.ink)
  via SSH, WP-CLI, or any other remote access method. Deployment is by the GitHub
  Actions workflow (`.github/workflows/deploy.yml`) and cross-environment coordination
  is handled by the developer.
- However: DO flag any situation where local file state or query results suggest the
  local DB may be out of sync with TEST or OPS (e.g. missing records, unexpected schema,
  zero results where data is expected). Stop and ask the developer to verify environment
  alignment before proceeding.
- Adding a build step, package manager, or bundler (Webpack, Vite, etc.)
- Converting the JS client to React, Vue, or any other framework
- Automated vendor price scraping or API-based price feeds (no vendor APIs exist)
- Converting Pods tables to InnoDB (valid long-term idea, but out of scope — do not suggest unless explicitly asked)

---

## Session Start Protocol ⚡

**MANDATORY** at start of each session:

```bash
# Load essential docs (~800 tokens - 2 min read)
✓ .claude/COMMON_MISTAKES.md      # ⚠️ CRITICAL - Read FIRST
✓ .claude/QUICK_START.md          # Essential commands
✓ .claude/ARCHITECTURE_MAP.md     # File locations
```

**At task completion:**
- Create completion doc in `.claude/completions/YYYY-MM-DD-task-name.md`
- Move session file to `.claude/sessions/archive/` (if created)

**⚠️ NEVER auto-load:**
- Files in `.claude/completions/` (0 token cost)
- Files in `.claude/sessions/` (0 token cost)
- Files in `docs/archive/` (0 token cost)

---

**Last Updated**: 2026-07-06
**Optimized with**: [Claude Token Optimizer](https://github.com/nadimtuhin/claude-token-optimizer)
