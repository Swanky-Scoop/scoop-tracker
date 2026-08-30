# Unit tests

The repo's first committed unit-test layer — zero-dependency, no framework.
Each suite is a plain script whose **exit code is the verdict** (0 = green,
1 = red) and whose stdout is a human-readable assertion log. Ported from the
in-box harnesses that proved the **Debt view** (debt board / `/debt-requests`,
worktree-tub-moving); the intent is that the Debt arithmetic and the
request parser stay protected by tests that run outside any one agent's box.

## Run

```sh
# everything (PHP suite auto-skips with a note if php-cli is absent)
node tests/unit/run-all.mjs

# individually — on node < 21.3 (no --disable-warning) you may see a harmless
# MODULE_TYPELESS_PACKAGE_JSON warning on these single-file runs; the run-all
# entry point suppresses it itself.
node --disable-warning=MODULE_TYPELESS_PACKAGE_JSON tests/unit/debt-model.test.mjs     # pure computeDebtRows() arithmetic
node --disable-warning=MODULE_TYPELESS_PACKAGE_JSON tests/unit/debt-class.test.mjs     # DebtGridModel class behavior
php  tests/unit/debt-requests.php       # scoop_parse_debt_requests() parser

# or from tests/smoke (also wired there)
cd tests/smoke && npm run test:unit
```

## The suites

| File | Target | What it pins |
|---|---|---|
| `debt-model.test.mjs` | `computeDebtRows()` in `assets/models/debt-grid-model.js` | demand aggregation (current+immediate, **next_flavor excluded**), on_hand/inbound/gap/available, all four statuses, the 0.8 whole-tub threshold (same number as `scoop_find_whole_tubs()`), FOH id-first + label fallback, flavor_request **replace-not-add** semantics (max wins, slots are the floor), numeric pair row ids |
| `debt-class.test.mjs` | `DebtGridModel` (same file) | column defs, the `{id,rowId,display}` flavor cells (the display-title bug), the full TextIt `demand` cell (colKey/min/max/step, `write` vs `window.SCOOP.metaData.Debt.canPost`), owed-desc group order + badges, status-rank row order, `hide_covered` + location filters (HashState `loc.Debt` persistence) |
| `debt-requests.php` | `scoop_parse_debt_requests()` in `includes/rest.php` | upsert/delete decode of `location*100000+flavor` row ids, malformed-id/`wanted` refusals (0–99 bounds), string/float coercion, per-cell errors on a mixed batch |

## Conventions (why the files look like this)

- **No test framework.** `tests/unit/helpers.mjs` is the whole harness: an
  `eq()` that compares JSON with object keys sorted (so `{a,b}` vs `{b,a}`
  is equal; arrays stay order-sensitive), plus `section()`/`finish()`.
  A failing `eq()` logs and sets the exit code without throwing, so one red
  assertion doesn't hide the rest of the run.
- **Browser globals are stubbed before the model import** in both `.mjs`
  files: `BaseGridModel` reads `window.SCOOP`, `HashState` reads
  `location.hash` and calls `history.replaceState` on
  `setFilterValue(location)`. The stubbed `replaceState` mirrors the hash
  back into `location` so hash round-trips work under node.
- **PHP needs no WP stubs**: `scoop_parse_debt_requests()` is deliberately
  pure (the REST handler owns all Pods persistence), and `includes/rest.php`
  has no namespace/requires and no top-level side effects beyond the
  `ABSPATH` guard — so the suite just `define('ABSPATH', …)` + `require`.
- Filter changes need an explicit `buildRows()` re-call in tests — the GUI
  re-queries after a filter interaction; the model does not self-rebuild.

## Known gaps (honest list)

- The **persistence half** of `/debt-requests` (the Pods upsert/delete calls
  in `scoop_handle_debt_requests_post`) is syntax-checked only — it needs a
  live WP stack; the smoke suite against `ops.swanky.local` is its real
  validation.
- The Debt UI's browser behavior has no `tests/smoke` spec yet.
