# CI evaluation — standing up WP+Pods for the smoke suite

**Task:** Evaluate standing up a real WordPress+Pods stack in CI for
`tests/smoke` (task `013e13df00000025`). The suite's README said "Do not
wire this into CI" and `tests/visual/README.md` flagged a real stack as
"future work … a real project of its own." This document replaces theory
with a working stack and measured results.

## Verdict

**Feasible, and already built — with two caveats that decide how far to
take it.** A complete, reproducible stack (WordPress + MariaDB + Pods 2.8 +
this plugin + derived fixtures) was stood up in the Webel sandbox and the
PR #36 smoke suite was run against it for real:

| Spec | Result on the CI stack | Notes |
| --- | --- | --- |
| `tub-moving-auto-mark` | **PASS** (5.5–8.2 s) | Clean end-to-end pass, incl. the moving_to earmark assertion. |
| `debt-wanted-edit` | **FAIL** at the last assertion | Everything up to and including the autosave wire-catch passes; the failure is a **genuine app-side bug the CI run surfaced** (see below). |
| `cabinet-workflow-lifecycle` | **FAIL** at step 4 | Reproduces the suite's own documented, still-unresolved step 3/4 mystery (README: "Not yet resolved") — a REST write that reports `ok:true` contradicted by a fresh read. This is a pre-existing known issue, not a stack gap. |

## What was built (all committed on this branch)

- `.github/workflows/smoke-tests.yml` — a manual-dispatch workflow that
  provisions the whole stack on an ubuntu runner (MariaDB service, wp-cli,
  WordPress, Pods 2.8.23, this plugin), generates the pod surface, seeds
  fixtures, boots `php -S` and runs Playwright. ~4–5 minutes end to end.
- `tests/smoke/ci/` — the provisioning scripts, each derived from the repo:
  - Pod surface **generated from `scoop_entity_specs()`** (all 18 pods
    including `flavor_request`), not mirrored.
  - Fixture data **derived from the specs' own preconditions and
    hardcoded-ID contracts** (`use #1863`, `location #935`, cabinet/slot
    titles, the three `zz__flavor` fixtures, the occupied-slot seed).
  - A `php -S` router shim replicating the mirror's Apache behaviors.
- `scoop_rest.php` — removed a dangling
  `register_activation_hook(__FILE__, 'scoop_readonly')`. The function was
  deleted from the codebase in 20ed07a but the hook registration survived;
  PHP 7 warned, **PHP 8 fatals plugin activation**. Any CI bring-up hits
  this first.

## What the evaluation surfaced (new findings)

1. **PHP 8 activation fatal** (fixed on this branch, see above).
2. **Pods 3.x incompatibility with the direct tub-write path.**
   `scoop_create_batch_tubs_direct()` inserts `wp_pods_tub` rows with
   explicit post IDs; Pods 3.x auto-creates an empty table row on
   `save_post`, so the insert collides ("Duplicate entry") and batch-created
   tubs are invisible to every Pods consumer. The CI workflow pins Pods
   2.8.23 (matching the mirror's behavior). Longer-term, the direct writer
   could upsert (`ON DUPLICATE KEY UPDATE`) to be version-tolerant.
3. **Debt board: edited-away rows can linger in the DOM.** After a Wanted
   edit to 0 (row leaves the board server-side — verified via bundle
   reads), the grid's additive patch path can leave the stale `<tr>` in the
   DOM: the group-focus flush that removes stale rows races the 800 ms
   background domain refresh, and a flush that lands before the refresh has
   dropped the row is never retried. Model state is correct; only the DOM
   lags. Reproducible 3/3 on the CI stack via the spec's exact sequence.
   This is precisely the class of browser-only bug the debt spec exists to
   catch — the CI stack caught a real one on its first day.
4. **`force_bust` does not bypass the slow-entity cache.**
   `scoop_bundle_get`'s `force_bust` skips the bundle-cache read, but
   `flavor`/`allergen`/`use`/`location`/`cabinet` are served from a separate
   slow-entity cache (`scoop_slow_changing_entity_types()`) with its own
   version bump. Out-of-band writes that don't bump that version (e.g.
   direct `pods()->save()` calls, or anything bypassing `save_post`) leave
   `force_bust` reads stale. The mirror masks this because wp-admin edits
   always fire `save_post`. Seed scripts must purge the entity cache
   explicitly (the CI seed does).

## Costs and frictions (honest accounting)

- **Bring-up took ~90 minutes of real work**, most of it on parity details
  that only show up against a genuinely fresh stack: the PHP 8 fatal, the
  Pods 3 collision, the Basic-auth/MIME/router behaviors of the web server,
  the reserved field names (`tub.date`, `tub.post_modified` cannot be
  created through the Pods API — the mirror's copies predate Pods' guard),
  and the bidirectional `tub.slot` ⇄ `slot.tub` sister configuration.
- **Per-run cost:** ~4–5 minutes of runner time (MariaDB service + WP
  install + Pods generation + seeds + Playwright). Playwright's Chromium
  install with system deps is the slowest single step (~1–2 min).
- **Seeding is deterministic but brittle to fixture drift by design:** the
  specs assert hard preconditions (stock counts, slot designations), so the
  seeds encode those exact shapes. Any spec that gains a new precondition
  needs a matching seed line — that coupling is the same one the specs
  already have with the Local mirror, just made explicit and versioned.
- **The step 3/4 mystery still blocks the lifecycle spec** on CI exactly as
  it does locally. Until it is resolved (or the spec drops/skips that
  step), a green CI run is impossible on that file.

## Recommendation

1. **Keep the workflow as a manual job now** (`workflow_dispatch`). It
   reproduces the mirror's environment faithfully enough to run the suite,
   and it already found a real bug.
2. **Fix the two app-side issues** the evaluation surfaced (Debt row-removal
   flush; optionally the direct-writer upsert for Pods 3 tolerance), then
   wire `debt-wanted-edit` + `tub-moving-auto-mark` into PR checks. Those
   two are stable and fast (~15 s combined on this box).
3. **Keep `cabinet-workflow-lifecycle` manual-only** until its documented
   step 3/4 mystery is resolved; wiring a known-red test into PRs would
   train everyone to ignore red.
4. **Do not delete the Local-mirror doctrine.** The suite's README rule
   "never point `SCOOP_BASE_URL` at TEST/OPS" stands — the CI stack is a
   third, disposable environment, not a replacement for the mirror and not
   a license to point the suite at shared data.
