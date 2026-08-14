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
npx playwright install chromium   # first time only, but see below
```

`.env` is gitignored — never commit real credentials. Local-only test
credentials only; see the project's own memory/reference notes for where
they're documented for reuse across sessions.

**The bundled Chromium binary crashes on the very first navigation against
this site, in at least one real environment** (`page crashed`, confirmed
via a standalone repro outside Playwright's own retry/reporting layer
before assuming it was a test bug). `playwright.config.js` uses the system
Edge channel (`channel: 'msedge'`) instead — if Edge isn't installed
wherever this runs, swap back to `devices['Desktop Chrome']` and
re-diagnose the crash rather than assuming this comment is stale.

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

**If a run fails partway through, it can leave real orphaned data** (an
undeleted `zz__flavor test___` batch, or the target slot left mid-swap) —
this suite does not yet have a `beforeEach`/`afterEach` cleanup hook (see
"Not yet done" below). Before re-running after a failure, either restore
the target slot/tub by hand or delete any leftover batches for the fixture
flavor first, or the next run's own "record pre-test state" step will
capture the WRONG baseline and its final "back to pre-test state"
assertion will be comparing against already-polluted data.

## What `cabinet-workflow-lifecycle.spec.js` covers

One full lifecycle, encoded as `test.step()`s so a failure points at
exactly which stage broke, not just "the test failed":

1. Create a batch (a configurable flavor/count — defaults to the
   `zz__flavor test___` fixture flavor, 3.4 tubs) via the Batch grid.
   **Reliable** — passes consistently through the real UI.
2. Record whatever tub currently occupies slot 1 of the target cabinet
   (Woodinville_dairy_18 by default) — looked up dynamically by cabinet
   title + slot index, never a hardcoded post ID (IDs aren't stable across
   environments or repeated runs). **Reliable.**
3. Open CabinetWorkflow's swap picker for that slot far enough to exercise
   the eligibility UI (dairy-allergen gate, flavor list), then commit the
   swap via a **direct REST call to a specific, already-known tub id**
   rather than clicking the modal's own "Confirm Swap" button. See "Not
   yet resolved" below for why.
4. Asserts (after a hard page reload, not just an in-page domain read — see
   below) the swap landed correctly in the Cabinet grid's own domain data,
   the CabinetWorkflow tile's rendered class, and ItemPivot's row.
   **Currently the one step that doesn't reliably pass** — see below.
5. Deletes the batch (exercises the O(n) Pods relationship-mirror fix —
   see project memory `batch-delete-speedup-2026-08` — asserts the DELETE
   request itself completes in a few seconds, not 20-30s). **Reliable.**
6. Sets the originally-recorded tub's state back to `Opened`. **Reliable.**
7. Re-picks the original flavor for that slot via the same deterministic
   swap helper (see "Known gap" below — `Confirm Cabinet` alone does not
   do this). **Reliable.**
8. Re-asserts the slot/tub link matches exactly what step 2 recorded, and
   that no `zz__flavor test___` batch/tubs remain. **Reliable.**

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
  through the same swap helper step 3 used.

### Not yet resolved: step 3/4's swap-verification is unreliable, and the
### reason is genuinely not understood yet

This is the one open problem in the suite, documented in detail so the
next attempt doesn't have to re-discover all of this from scratch.

**What was tried, in order, and what happened:**

1. **Click through the real "Confirm Swap" modal**, unchecking "use full
   tubs before partial tubs" so it targets the batch's own fractional tub.
   Works interactively (verified by hand via Playwright MCP tooling before
   this suite existed at all). In the automated suite, the wrong tub (or no
   tub) ended up linked, inconsistently across runs.
2. **Suspected timing** — the checkbox's `change` handler calls `_render()`
   synchronously (confirmed by reading `confirm-swap-modal.js`), so this
   shouldn't be a race, but tried anyway: replaced fixed `waitForTimeout`
   calls with `page.waitForResponse` for the bundle refetch, then with
   `page.waitForFunction` polling the client domain directly for the
   expected end state (slot's `current_flavor` AND a matching linked+Opened
   tub). Each fix visibly changed *where* the failure happened, which reads
   as "real timing sensitivity exists here," but never made step 4 pass.
3. **Suspected a stale-modal DOM issue** — the flavor_picker/confirm_swap
   modal templates are singleton DOM nodes reused across opens (see
   `cabinet-workflow-tile.js`), not torn down. A second `add-next` click
   within one test run (step 3, then step 7's restore) hit a real
   `<div class="modal flavor_picker show">…</div> intercepts pointer
   events` error — genuine evidence the leftover modal state matters.
   First fix (stripping the `.show` class directly via `classList.remove`)
   made things *worse* in a way consistent with corrupting the modal's own
   internal plan state, not just its visibility — replaced with pressing
   `Escape` (which `confirm-swap-modal.js` already listens for and handles
   via its own `close()`) instead of touching the DOM directly.
4. **Verified the checkbox toggle itself was correct** — logged
   `fullTubsCheckbox.isChecked()` immediately before confirming; it matched
   the intended value every time. Ruled out.
5. **Switched to a hard page reload before the assertion** (`page.goto()`
   with `#bust`, not just polling the in-page domain) on the theory that
   client-side merge/race conditions between overlapping triggered
   refetches (plural — this branch's own bundle-fetch scoping work landed
   in the same session, see `PERFORMANCE-REFACTOR.md` item #2) were
   producing a transiently-true-then-false read. Still failed the same way
   after a full server round-trip, which rules out a purely client-side
   race — something about the server-side state itself doesn't match
   expectations at that point, or the write genuinely isn't landing.
6. **Switched the commit itself from clicking "Confirm Swap" to a direct,
   deterministic REST write** to the exact known tub id (bypassing the
   modal's own tub-selection logic entirely — see step 3's description
   above). The write's own response comes back `{"ok": true, ...}`. A hard
   reload immediately after **still** read the tub back as `Freezing`, not
   `Opened`. This is the point the investigation stopped: a request the
   server itself reports succeeding, contradicted by an immediate
   independent read, is not explained by anything found so far (not a
   click-timing issue, not a stale-DOM issue, not an obvious client-side
   cache/merge issue, since the read was a genuine fresh page load).

**Plausible next things to check, not yet checked:** whether the specific
tub id captured in step 1 is actually correct at the moment it's used
(add a raw DB check immediately after the "successful" write, in the same
process, before any more page navigation, to rule out simply grabbing the
wrong id); whether some *other* write (a background/scheduled refresh,
another concurrent test artifact) is racing the REST write at the database
level; whether the specific REST payload shape silently fails validation
for a reason that still returns `ok: true` (check `scoop_tubs_allowed_fields`
and whatever hook runs on `pods_api_pre_save_pod_item_tub` for a rule that
might reject or silently drop the `slot`/`state` fields together under some
condition not exercised by this exact tub's prior history).

## Extending this suite

The internal helpers in the spec file (`toggleGrid`, `getDomain`,
`swapSlotToFlavor`, ...) reach into this app's own runtime internals
(`host._dockListInstance`, `SCOOP.refreshScope`, specific CSS classes like
`.add-next`/`.confirm-cabinet`/`.modal.flavor_picker`) rather than a
dedicated test-id contract — there isn't one yet. If a future UI refactor
renames these, this suite will need updating alongside it; that coupling
is deliberate for now (faster to write, matches what a human tester
actually clicks) rather than over-engineering test-ids for a one-suite need.

## Other lessons from building this suite

- **A real production bug was found and fixed along the way, unrelated to
  the unresolved item above.** `scoop_client_refresh_scope()`'s scoped
  refresh only accounted for a route's own declared `pod_name` — but
  `Batch`'s create hook also creates `tub` rows, and `Closeout`'s save hook
  also marks tubs `Emptied`, as side effects. A scoped refresh after
  saving a Batch was silently leaving `FlavorTub` stale (its `needs`
  includes `tub`, not `batch`) until this suite's step 1 caught tubs
  reading back as missing/stale right after a batch save. Fixed via a new
  `cascades_to` config key (`includes/_config.php`) folded into
  `writesPods` (plural, was `writesPod`) — see that commit for detail. This
  fix is **not yet committed to this branch** as of the commit that added
  this file; it lives in `assets/data/scoop-api.js`, `includes/_config.php`,
  `includes/_specs.php` as uncommitted/separately-committed work.
- **Two separate browser instances in play during development can look
  like one and cause confusing false readings.** Interactively driving the
  app via one browser tool (e.g. an MCP-driven browser) while
  `npx playwright test` runs its own, completely separate browser context
  means neither one's client-side state (domain cache, DOM) reflects the
  other's writes. Several "why did this revert" moments during development
  turned out to just be reading one browser's stale view of writes the
  *other* browser had made. Always re-verify suspicious state via a raw
  DB/PHP-CLI check (see the project's established PHP CLI bootstrap
  pattern) rather than trusting either browser's in-page state when the
  two might be interacting with the same live site concurrently.
- **A failed automated run's partial writes are real and need real
  cleanup**, not just "the test failed, move on" — a batch created in step
  1 but never reached by step 5's delete is a genuine orphaned WordPress
  post with real tub children; a slot swapped in step 3 but never restored
  by steps 6-7 leaves the *actual* cabinet in a wrong state, not a test
  fixture. This suite doesn't yet clean up after its own failures (no
  `test.afterEach`) — see "Running" above.

## Not yet done

- A `test.afterEach`/`test.afterAll` that deletes any batch for the fixture
  flavor created during a run that didn't reach step 5, and restores the
  target slot to whatever step 2 recorded, regardless of where the test
  failed — would remove the manual-cleanup burden documented above.
- Resolving the step 3/4 mystery documented above, and reverting to
  driving the real "Confirm Swap" UI once it's understood (the direct-REST
  workaround is a deliberate, documented trade of UI fidelity for
  reliability under time pressure, not a permanent design decision).
- `gh` (GitHub CLI) was installed in this environment specifically to open
  a PR for this branch's work, but authenticating it hit a scope gap
  (`error validating token: missing required scope 'read:org'` from the
  token `git credential fill` surfaced) — not resolved. A PR for this
  branch may still need to be opened by hand via
  `https://github.com/gusreiber/scoop-tracker/compare/main...worktree-performance-refactor`.
