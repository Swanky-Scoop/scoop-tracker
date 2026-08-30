# CI stack recipes (tests/smoke/ci)

These files stand up a **fresh, real WordPress + Pods + MariaDB stack** and
seed it with everything the smoke specs' own preconditions demand. They
exist because the pod surface and fixtures are derived from this repo's own
code and spec contracts — nothing is mirrored from the Local-by-Flywheel
database.

| File | Purpose |
| --- | --- |
| `gen-pods.php` | Creates every pod (`tub`, `slot`, `flavor`, … including PR #36's `flavor_request`) from `scoop_entity_specs()` in `includes/_specs.php`. Idempotent: skips existing pods, tops up missing fields. |
| `fix-relations.php` | Converts int+`titleMap` spec fields to real Pods pick (relationship) fields, sets multi-picks, adds the mirror-parity reverse fields (`batch.tubs`, `location.tubs`), and links the `tub.slot` ⇄ `slot.tub` bidirectional sister pair. **Must run after `gen-pods.php` and before `seed-fixtures.php`** — field types must be final before values are written. |
| `seed-fixtures.php` | Seeds exactly what the three specs assert about the world: `use #1863` (Front-of-house, ID is hardcoded in debt-wanted-edit.spec.js), `location #935` (Woodinville, hardcoded in tub-moving-auto-mark.spec.js), Mountlake Terrace, the `Woodinville_dairy_18` + `Mountlake Terrace_restricted_12` cabinets and their slots, the three `zz__flavor *` fixture flavors, one pre-tagged dairy flavor for the occupied slot, and one Opened occupant tub in `Woodinville_dairy_18|1` (with its `slot.current_flavor` designation — CabinetWorkflow's tile state reads it). |
| `seed-pages-users.php` | Creates the `/dock/` page (with the grid shortcodes the specs toggle, including `Batch` with `history="1"` — the specs assert against the embedded BatchHistory grid) and the `smoke` login user. |
| `htshim.php` | Router for `php -S` (the CI web server): emulates Apache's `PHP_AUTH_USER` population from the Authorization header, executes existing `.php` files directly (routing `wp-login.php` through the front controller makes WP's `wp_redirect_admin_locations()` 302-loop it), and serves static assets with correct MIME types. |
| `../playwright.ci-stack.config.js` | Playwright config override: bundled Chromium instead of the `msedge` channel (no system Edge on CI runners; the repo config's own comment documents exactly this fallback). |

Known deviations from the live mirror, both documented in the seed itself:

- **Pods must be the 2.8 line** (workflow pins 2.8.23). Pods 3.x auto-creates
  an empty table-storage row for a new post on `save_post`, which collides
  with `scoop_create_batch_tubs_direct()`'s explicit-ID inserts in
  `includes/hooks/batch-tub.php` — on 3.x, batch-created tubs end up
  invisible to every Pods consumer. Either stay on 2.8 in CI or add an
  upsert to the direct writer.
- **The dairy tag on `zz__flavor test___` is pre-seeded.** On the Local
  mirror the lifecycle spec tags it through wp-admin's Pods react-select
  UI; under Pods 2.8 on a 6.x WP the admin field UI fatals its JS, so the
  seed applies the tag and the spec's own `ensureFlavorTaggedDairy()` skips
  the step (its first line returns early when the flavor is already tagged).
