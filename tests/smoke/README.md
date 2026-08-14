# Smoke tests (live local dev site)

End-to-end lifecycle tests against a **real, running WordPress + Pods
stack** — the Local dev mirror at `https://ops.swanky.local` (see
CLAUDE.md's "Local development mirror"). This is deliberately different
from `tests/visual/` (CI-safe, static HTML fixtures, no login, no writes):
this suite logs in, creates real batches/tubs, links/unlinks cabinet slots,
and deletes data. It exercises the full stack `tests/visual/README.md`
explicitly said was out of scope for that suite.

## Why this only ever targets the local dev mirror

- It writes real data — batches, tubs, slot links — and deletes some of it.
  Never point `SCOOP_BASE_URL` at `test.swankyscoop.net` or
  `ops.swankyscoop.net` (see CLAUDE.md's data repair policy and "Do not
  suggest: connecting directly to remote servers").
- It requires the Local by Flywheel site actually running, plus a real
  login — nothing here is stubbed or mocked.
- **Do not wire this into CI.** Standing up a real WordPress+Pods instance
  in CI is explicitly flagged as future work in `tests/visual/README.md`,
  not something this suite attempts.

## Setup

```sh
cd tests/smoke
npm install
cp .env.example .env   # fill in SCOOP_TEST_USER / SCOOP_TEST_PASS
npx playwright install chromium   # first time only
```

`.env` is gitignored — never commit real credentials. Local-only test
credentials only; see the project's own memory/reference notes for where
they're documented for reuse across sessions.

## Running

```sh
cd tests/smoke
npx playwright test              # headless
npx playwright test --headed     # watch it click through the UI
npx playwright test --debug      # step through interactively
```

`workers: 1` / `fullyParallel: false` is deliberate (see
`playwright.config.js`) — these tests mutate the same shared dev-site rows
sequentially; running them in parallel would race.

## What `cabinet-workflow-lifecycle.spec.js` covers

One full lifecycle, encoded as `test.step()`s so a failure points at
exactly which stage broke, not just "the test failed":

1. Create a batch (a configurable flavor/count — defaults to the
   `zz__flavor test___` fixture flavor, 3.4 tubs) via the Batch grid.
2. Record whatever tub currently occupies slot 1 of the target cabinet
   (Woodinville_dairy_18 by default) — looked up dynamically by cabinet
   title + slot index, never a hardcoded post ID (IDs aren't stable across
   environments or repeated runs).
3. Use CabinetWorkflow to swap that slot to the batch's fractional tub
   specifically (unchecks "use full tubs before partial tubs").
4. Asserts the swap landed correctly in the Cabinet grid's own domain data,
   the CabinetWorkflow tile's rendered class, and ItemPivot's row.
5. Deletes the batch (exercises the O(n) Pods relationship-mirror fix —
   see project memory `batch-delete-speedup-2026-08` — asserts the DELETE
   request itself completes in a few seconds, not 20-30s).
6. Sets the originally-recorded tub's state back to `Opened`.
7. Re-picks the original flavor for that slot via CabinetWorkflow (see
   "Known gap" below — `Confirm Cabinet` alone does not do this).
8. Re-asserts the slot/tub link matches exactly what step 2 recorded, and
   that no `zz__flavor test___` batch/tubs remain.

### Known gaps this suite works around (not bugs — verified against the
### actual model code, see the spec file's own comments for the exact
### lines)

- **Dairy-cabinet allergen gate.** `CabinetWorkflowGridModel._allergenConflict`
  only lists a flavor as pickable in a cabinet that doesn't prohibit dairy
  if the flavor itself is tagged `dairy`. The fixture flavor
  (`zz__flavor test___`) ships untagged in some environments — the test's
  setup step idempotently tags it `dairy` via `wp-admin` before running, so
  this never blocks a future run once it's tagged once per environment.
- **`Confirm Cabinet` does not resurrect an emptied slot on its own.**
  `_reconcileCabinet` only promotes/links tubs for a slot's *current*
  `current_flavor` — if that flavor has zero tubs left (as it does right
  after deleting the batch), the slot is reported `impossible` and left
  alone. Restoring the original flavor requires explicitly re-picking it
  through the same swap flow step 3 used, which the spec does before
  re-checking `Confirm Cabinet`.

## Extending this suite

The internal helpers in the spec file (`toggleGrid`, `getDomain`,
`swapSlotToFlavor`, ...) reach into this app's own runtime internals
(`host._dockListInstance`, `SCOOP.refreshScope`, specific CSS classes like
`.add-next`/`.confirm-cabinet`/`.modal.flavor_picker`) rather than a
dedicated test-id contract — there isn't one yet. If a future UI refactor
renames these, this suite will need updating alongside it; that coupling
is deliberate for now (faster to write, matches what a human tester
actually clicks) rather than over-engineering test-ids for a one-suite need.
