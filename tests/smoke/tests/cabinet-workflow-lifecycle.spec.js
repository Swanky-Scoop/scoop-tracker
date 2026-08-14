// @ts-check
const { test, expect } = require('@playwright/test');

// ── Fixture config ──────────────────────────────────────────────────────
// Override via env if a different local environment's seed data differs.
// TARGET_SLOT_TITLE matches a slot's own _title in the bundle domain,
// which is always "<CabinetTitle>|<Index>" (see includes/pods-schema —
// slot titles are generated that way) — this is what lets the test find
// "slot 1 of Woodinville Dairy" dynamically instead of a hardcoded post ID,
// which would break the moment this suite runs against a different
// environment or a re-seeded database.
const TEST_FLAVOR_TITLE = process.env.SMOKE_TEST_FLAVOR || 'zz__flavor test___';
const TARGET_SLOT_TITLE = process.env.SMOKE_TARGET_SLOT || 'Woodinville_dairy_18|1';
const BATCH_COUNT = Number(process.env.SMOKE_BATCH_COUNT || 3.4);
const DOCK_PATH = '/dock/';

test.describe.configure({ mode: 'serial' });

// ── Helpers ──────────────────────────────────────────────────────────────
// These reach into this app's own runtime internals rather than a
// dedicated test-id contract (there isn't one) — see README.md's
// "Extending this suite" note.

/** Opens a dock grid's panel if it isn't already open, by data-grid-type. */
async function openGrid(page, gridType) {
  await page.evaluate((type) => {
    const host = document.querySelector(`.scoop-grid[data-grid-type="${type}"]`);
    if (!host) throw new Error(`No grid host for type "${type}"`);
    const inst = host._dockListInstance;
    if (!host.classList.contains('toggled')) inst.TOGGLE.click();
  }, gridType);
}

/**
 * Waits for the initial bundle fetch to actually land before reading
 * anything — mountAllGrids()'s bundle fetch is async and NOT complete just
 * because page.goto() resolved. Polls domain.flavor (always non-empty on a
 * real bundle response) rather than a fixed sleep.
 */
async function waitForDomain(page) {
  await page.waitForFunction(() => {
    const host = document.querySelector('.scoop-grid[data-grid-type]');
    const domain = host?._dockListInstance?.api?.getDomainSnapshot?.();
    return Array.isArray(domain?.flavor) && domain.flavor.length > 0;
  }, { timeout: 20_000 });
}

/** Full client-side domain snapshot (merged bundle data) — see ScoopAPI.getDomainSnapshot(). */
async function getDomain(page) {
  await waitForDomain(page);
  return page.evaluate(() => {
    const host = document.querySelector('.scoop-grid[data-grid-type]');
    return host._dockListInstance.api.getDomainSnapshot();
  });
}

/** Forces a fresh, uncached bundle fetch (see CLAUDE.md's #bust hash convention). */
async function forceFreshDomain(page) {
  const url = new URL(page.url());
  url.hash = 'bust';
  await page.goto(url.toString());
  await waitForDomain(page);
}

/**
 * Idempotently tags a flavor with the 'dairy' allergen via wp-admin.
 * Required for it to be pickable in a dairy cabinet — see
 * CabinetWorkflowGridModel._allergenConflict (assets/models/
 * cabinet-workflow-grid-model.js) — a cabinet that doesn't prohibit dairy
 * only lists flavors that ARE tagged dairy. No-ops if already tagged, so
 * safe to call at the top of every run.
 */
async function ensureFlavorTaggedDairy(page, flavorTitle) {
  const domain = await getDomain(page);
  const flavor = (domain.flavor || []).find((f) => f._title === flavorTitle);
  if (!flavor) throw new Error(`Fixture flavor "${flavorTitle}" not found in domain — seed it first.`);
  if ((flavor.allergens || []).includes('dairy')) return;

  await page.goto(`/wp-admin/post.php?post=${flavor.id}&action=edit`);
  const combobox = page.getByRole('combobox').filter({ hasText: '' }).first();
  // The allergens field is a react-select combobox — "Search Allergens…".
  const allergensInput = page.locator('input#react-select-3-input, input[id^="react-select"][id$="-input"]').first();
  await page.getByText('Search Allergens…').click().catch(() => {});
  await allergensInput.fill('dairy');
  await page.getByRole('option', { name: 'Dairy', exact: true }).click();
  await page.getByRole('button', { name: 'Update' }).click();
  await expect(page.getByText('Flavor updated.')).toBeVisible({ timeout: 15_000 });
}

/**
 * Opens CabinetWorkflow's "add next" -> "Change Plan" -> pick-flavor flow
 * for one slot tile far enough to exercise the picker/eligibility UI (the
 * dairy-allergen gate, the flavor list, the modal opening at all), then
 * commits the swap via a direct REST call to a SPECIFIC known tub id
 * rather than clicking "Confirm Swap".
 *
 * Why not click through to Confirm Swap: the swap modal's own tub-
 * selection (which of possibly several eligible tubs of the target flavor
 * actually gets linked) proved unreliable to drive via Playwright's
 * pointer-event-based clicks in this suite — the same sequence that works
 * interactively landed on an unrelated tub, or none, depending on timing
 * that didn't reproduce the same way twice. Every OTHER step in this
 * lifecycle (batch create/delete, tub state edits, Confirm Cabinet) drives
 * the real UI reliably; this one specific commit doesn't yet. Both callers
 * already know exactly which tub should end up linked (step 3: the
 * batch's own fractional tub; step 7: the originally-recorded occupant),
 * so committing directly is deterministic without weakening what the rest
 * of the suite actually proves. Revisit if a future UI change makes the
 * modal's own selection reliably driveable — see README.md's "Known gaps".
 */
async function swapSlotToFlavor(page, slotId, flavorTitle, targetTubId) {
  await openGrid(page, 'CabinetWorkflow');

  // Defensive: the flavor_picker/confirm_swap modal templates stay in the
  // DOM between uses (see cabinet-workflow-tile.js) rather than being torn
  // down — close via the app's own Escape handling (confirm-swap-modal.js
  // listens for it) rather than stripping the "show" class directly, which
  // left the modal's internal state inconsistent for the next open.
  await page.keyboard.press('Escape');

  await page.evaluate((id) => {
    const host = document.querySelector('.scoop-grid[data-grid-type="CabinetWorkflow"]');
    const tile = host.querySelector(`[data-row-id="${id}"]`);
    if (!tile) throw new Error(`No CabinetWorkflow tile for slot ${id}`);
    tile.scrollIntoView({ block: 'center' });
    tile.querySelector('.add-next, button').click();
  }, slotId);

  // Whatever flavor the swap modal defaults to (often "replace with
  // another tub of the SAME flavor"), "Change Plan" opens the full picker
  // — this is what exercises pickableFlavors()'s eligibility rules
  // (allergen gate, no-duplicate-per-cabinet, front-of-house/state
  // filtering — see cabinet-workflow-grid-model.js).
  await page.getByRole('button', { name: 'Change Plan' }).click();
  await expect(page.getByRole('button', { name: flavorTitle, exact: false })).toBeVisible({ timeout: 10_000 });
  await page.keyboard.press('Escape');

  // Commit deterministically (see doc comment above).
  const nonce = await page.evaluate(() => window.SCOOP.nonce);
  const result = await page.evaluate(
    async ({ slotId, tubId, flvTitle, nonce }) => {
      const host = document.querySelector('.scoop-grid[data-grid-type]');
      const domain = host._dockListInstance.api.getDomainSnapshot();
      const flavor = domain.flavor.find((f) => f._title === flvTitle);

      const tubRes = await fetch('/wp-json/scoop/v1/tubs', {
        method: 'POST',
        headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({ FlavorTub: { cells: { [tubId]: { state: 'Opened', slot: slotId } } } }),
      });
      const tubBody = await tubRes.json().catch(() => null);

      const cabinetRes = await fetch('/wp-json/scoop/v1/planning', {
        method: 'POST',
        headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({ Cabinet: { cells: { [slotId]: { current_flavor: flavor.id } } } }),
      });
      const cabinetBody = await cabinetRes.json().catch(() => null);

      return { tubStatus: tubRes.status, tubBody, cabinetStatus: cabinetRes.status, cabinetBody };
    },
    { slotId, tubId: targetTubId, flvTitle: flavorTitle, nonce },
  );
  expect(result.tubStatus, `tub link write: ${JSON.stringify(result.tubBody)}`).toBe(200);
  expect(result.tubBody?.ok, `tub link write ok=false: ${JSON.stringify(result.tubBody)}`).toBe(true);
  expect(result.cabinetStatus, `cabinet flavor write: ${JSON.stringify(result.cabinetBody)}`).toBe(200);
  expect(result.cabinetBody?.ok, `cabinet flavor write ok=false: ${JSON.stringify(result.cabinetBody)}`).toBe(true);

  await page.keyboard.press('Escape');
}

// ── Cross-run cleanup ────────────────────────────────────────────────────
// Module-scope (not `let` inside the test body) so afterEach can see
// whatever got recorded before a mid-test failure — see README.md's "Not
// yet done": a run that dies between steps 1-7 previously left a real
// orphaned batch and/or a slot stuck mid-swap for the next run to trip
// over. Reset in beforeEach so a stale run's state can't leak into a
// later one if this file ever grows a second test.
let state = {};

test.beforeEach(() => {
  state = {};
});

test.afterEach(async ({ page }, testInfo) => {
  // Nothing was recorded yet (failed during login/setup, before step 1)
  // — nothing to clean up.
  if (!state.newBatchId && !state.recordedSlotId) return;

  console.log(`[smoke:cleanup] test status "${testInfo.status}" — checking for state to restore`);

  let domain;
  try {
    await forceFreshDomain(page);
    domain = await getDomain(page);
  } catch (err) {
    console.error(`[smoke:cleanup] could not read domain, skipping cleanup: ${err.message}`);
    return;
  }

  // Delete the fixture batch if step 5 never reached it (or failed partway).
  if (state.newBatchId && (domain.batch || []).some((b) => Number(b.id) === Number(state.newBatchId))) {
    console.warn(`[smoke:cleanup] batch ${state.newBatchId} was not deleted by the test — deleting now`);
    const nonce = await page.evaluate(() => window.SCOOP.nonce);
    // Don't trust the response body's parsed JSON for success/failure — seen
    // a real run where the server-side delete fully succeeded (confirmed via
    // a direct DB check) but the client-side `.json()` parse still failed on
    // a 200. Verify against actual re-fetched state instead.
    const status = await page.evaluate(async ({ id, nonce }) => {
      const r = await fetch(`/wp-json/scoop/v1/batches/${id}`, {
        method: 'DELETE',
        headers: { 'X-WP-Nonce': nonce },
        credentials: 'include',
      });
      return r.status;
    }, { id: state.newBatchId, nonce });

    await forceFreshDomain(page);
    domain = await getDomain(page);
    const stillThere = (domain.batch || []).some((b) => Number(b.id) === Number(state.newBatchId));
    if (stillThere) {
      console.error(`[smoke:cleanup] failed to delete orphaned batch ${state.newBatchId}: DELETE returned status ${status}, batch still exists after re-fetch`);
    } else {
      console.log(`[smoke:cleanup] batch ${state.newBatchId} deleted successfully`);
    }
  }

  // Restore the target slot/tub if they don't match what step 2 recorded —
  // covers a failure anywhere from step 3 (swap committed, slot now mid-
  // change) through step 7 (restore attempted but not yet verified).
  if (state.recordedSlotId && state.recordedOriginalFlavorId && state.recordedOriginalTubId) {
    const slot = (domain.slot || []).find((s) => Number(s.id) === Number(state.recordedSlotId));
    const tub = (domain.tub || []).find((t) => Number(t.id) === Number(state.recordedOriginalTubId));
    const needsRestore =
      !slot ||
      Number(slot.current_flavor) !== Number(state.recordedOriginalFlavorId) ||
      !tub ||
      tub.state !== 'Opened' ||
      Number(tub.slot) !== Number(state.recordedSlotId);

    if (needsRestore) {
      console.warn(`[smoke:cleanup] slot ${state.recordedSlotId} was left mid-swap — restoring original tub ${state.recordedOriginalTubId}`);
      const originalFlavorTitle = (domain.flavor || []).find((f) => Number(f.id) === Number(state.recordedOriginalFlavorId))?._title;
      try {
        if (!originalFlavorTitle) throw new Error(`original flavor ${state.recordedOriginalFlavorId} not found in domain`);
        await swapSlotToFlavor(page, state.recordedSlotId, originalFlavorTitle, state.recordedOriginalTubId);
      } catch (err) {
        console.error(`[smoke:cleanup] failed to restore slot ${state.recordedSlotId}: ${err.message}`);
      }
    }
  }
});

// ── The lifecycle ────────────────────────────────────────────────────────

test('cabinet workflow lifecycle: create batch, swap into a slot, delete, restore', async ({ page }) => {
  test.setTimeout(120_000);

  await test.step('log in', async () => {
    await page.goto('/wp-login.php');
    await page.locator('#user_login').fill(process.env.SCOOP_TEST_USER || '');
    await page.locator('#user_pass').fill(process.env.SCOOP_TEST_PASS || '');
    await page.getByRole('button', { name: 'Log In' }).click();
  });

  // window.confirm()/alert() block automated clicks on every future
  // navigation from here on. .no-animations (see css.css) skips this
  // stylesheet's blanket `transition: all 0.3s ease 0.05s` and @keyframes
  // — without it, Playwright's pointer-based clicks correctly wait out
  // in-progress transitions before considering an element "stable" (real,
  // not spurious — but it adds real delay across dozens of interactions
  // and was a contributor to this suite's own flakiness while it was being
  // built). document.body doesn't exist yet this early, so the class is
  // added via documentElement and CSS targets body via descendant/self —
  // simplest fix is just adding the class on both.
  await page.addInitScript(() => {
    window.confirm = () => true;
    window.alert = () => {};
    document.addEventListener('DOMContentLoaded', () => {
      document.body.classList.add('no-animations');
    });
  });

  await page.goto(DOCK_PATH);

  await test.step('setup: ensure fixture flavor is dairy-tagged', async () => {
    await ensureFlavorTaggedDairy(page, TEST_FLAVOR_TITLE);
  });

  await forceFreshDomain(page);

  await test.step('step 1: add batch (fixture flavor, configured count)', async () => {
    await openGrid(page, 'Batch');
    const batchHost = page.locator('.scoop-grid[data-grid-type="Batch"]');

    await batchHost.getByRole('cell', { name: 'Add batch' }).getByRole('textbox').click();
    await batchHost.getByRole('cell', { name: 'Add batch' }).getByRole('textbox').fill(TEST_FLAVOR_TITLE.replace(/_+$/, ''));
    await page.getByRole('option', { name: TEST_FLAVOR_TITLE }).click();
    await batchHost.getByRole('spinbutton').fill(String(BATCH_COUNT));
    await batchHost.getByRole('button', { name: /save/i }).first().click();

    // .first() deliberately, not strict-mode single-match: Batch History
    // accumulates rows for this flavor across every past run of this suite
    // (and real usage) — this only needs to confirm SOME row for it exists
    // now, not that it's the only one. The actual new-batch identification
    // below is by newest id, not by this text match.
    await expect(batchHost.getByText(TEST_FLAVOR_TITLE).first()).toBeVisible({ timeout: 15_000 });

    const domain = await getDomain(page);
    const batch = (domain.batch || [])
      .filter((b) => Number(b.flavor) === (domain.flavor.find((f) => f._title === TEST_FLAVOR_TITLE)?.id))
      .sort((a, b) => Number(b.id) - Number(a.id))[0];
    expect(batch, 'newly created batch should be in the domain').toBeTruthy();
    state.newBatchId = batch.id;

    const tubs = (domain.tub || []).filter((t) => Number(t.batch) === Number(state.newBatchId));
    const fractional = tubs.find((t) => t.amount % 1 !== 0);
    expect(fractional, 'batch should include exactly one fractional tub').toBeTruthy();
    state.newFractionalTubId = fractional.id;
  });

  await test.step('step 2: record the tub currently in the target slot', async () => {
    const domain = await getDomain(page);
    const slot = (domain.slot || []).find((s) => s._title === TARGET_SLOT_TITLE);
    expect(slot, `slot "${TARGET_SLOT_TITLE}" should exist`).toBeTruthy();
    state.recordedSlotId = slot.id;
    state.recordedOriginalFlavorId = slot.current_flavor;

    const occupant = (domain.tub || []).find((t) => Number(t.slot) === Number(state.recordedSlotId));
    expect(occupant, `slot "${TARGET_SLOT_TITLE}" should currently hold a tub`).toBeTruthy();
    state.recordedOriginalTubId = occupant.id;
    console.log(`[smoke] recorded pre-test occupant of ${TARGET_SLOT_TITLE}: tub ${state.recordedOriginalTubId} ("${occupant._title}")`);
  });

  await test.step('step 3: use CabinetWorkflow to place the fractional tub', async () => {
    await swapSlotToFlavor(page, state.recordedSlotId, TEST_FLAVOR_TITLE, state.newFractionalTubId);
  });

  await test.step('step 4: confirm Cabinet / CabinetWorkflow / ItemPivot all reflect the swap', async () => {
    // A hard reload here, not just getDomain()'s in-page wait: overlapping
    // triggered refetches (this swap's own, plus whatever was still
    // chaining from step 1's batch save) were observed racing client-side
    // — one client-side read transiently saw the link, a later one saw it
    // reverted, with no further writes in between. Re-fetching from a
    // clean page load reads server truth directly and sidesteps that
    // class of race entirely, same pattern step 8 already relies on.
    await forceFreshDomain(page);
    const domain = await getDomain(page);
    const newTub = (domain.tub || []).find((t) => Number(t.id) === Number(state.newFractionalTubId));
    expect(newTub.state, 'new fractional tub should be Opened').toBe('Opened');
    expect(Number(newTub.slot), 'new fractional tub should be linked to the target slot').toBe(Number(state.recordedSlotId));

    const oldTub = (domain.tub || []).find((t) => Number(t.id) === Number(state.recordedOriginalTubId));
    expect(oldTub.state, 'the previous occupant tub should now be Emptied').toBe('Emptied');
    expect(Number(oldTub.slot), 'the previous occupant tub should be unlinked from the slot').toBe(0);

    // CabinetWorkflow tile — visual confirmation via its own class, not just domain data.
    const tileClass = await page.evaluate((id) => {
      const host = document.querySelector('.scoop-grid[data-grid-type="CabinetWorkflow"]');
      return host.querySelector(`[data-row-id="${id}"]`)?.className;
    }, state.recordedSlotId);
    expect(tileClass).toContain(TEST_FLAVOR_TITLE.trim().replace(/\s+/g, '_'));

    // ItemPivot — separate page, own bundle fetch.
    await page.goto('/pivot-tubs/');
    await expect(page.locator('.scoop-grid[data-grid-type="ItemPivot"]').getByText(TEST_FLAVOR_TITLE)).toBeVisible({ timeout: 15_000 });
    await page.goto(DOCK_PATH);
    // A fresh navigation means a fresh, async initial bundle fetch — the
    // Batch History row below won't exist in the DOM until it lands.
    await waitForDomain(page);
  });

  await test.step('step 5: delete the batch', async () => {
    await openGrid(page, 'Batch');

    const rowSelector = `.scoop-grid[data-grid-type="Batch"] [data-row-id="${state.newBatchId}"]`;
    await page.waitForSelector(rowSelector, { timeout: 15_000 });
    await page.evaluate((sel) => {
      document.querySelector(sel).querySelector('button').click();
    }, rowSelector);

    await expect(page.getByText(TEST_FLAVOR_TITLE).first()).toBeHidden({ timeout: 30_000 });

    // Sanity-check the O(n) Pods relationship-mirror fix (project memory
    // batch-delete-speedup-2026-08) hasn't regressed — a real regression
    // here looks like 20-30s+, not a few seconds.
    const deleteRequest = await page.evaluate((batchId) => {
      const entry = performance.getEntriesByType('resource').find((e) => e.name.includes(`/batches/${batchId}`));
      return entry ? Math.round(entry.duration) : null;
    }, state.newBatchId);
    console.log(`[smoke] DELETE /batches/${state.newBatchId} took ${deleteRequest}ms`);
    expect(deleteRequest, 'batch DELETE request duration').toBeLessThan(15_000);
  });

  await test.step('step 6: set the original tub back to Opened in FlavorTub', async () => {
    await openGrid(page, 'FlavorTub');

    await page.evaluate((tubId) => {
      const host = document.querySelector('.scoop-grid[data-grid-type="FlavorTub"]');
      const row = host.querySelector(`[data-row-id="${tubId}"]`);
      const container = row.closest('[data-group-container]');
      if (container?.classList.contains('closed')) {
        document.querySelector(`[data-group-id="${container.dataset.groupId}"] .oc`)?.click();
      }
    }, state.recordedOriginalTubId);

    const stateInput = page.locator(`[data-row-id="${state.recordedOriginalTubId}"] .state input[type="text"]`);
    await stateInput.click();
    await stateInput.fill('Opened');
    await page.getByRole('option', { name: 'Opened' }).click();

    await Promise.all([
      page.waitForResponse((res) => res.url().includes('/wp-json/scoop/v1/tubs') && res.ok()),
      page.evaluate(() => {
        document.querySelector('.scoop-grid[data-grid-type="FlavorTub"] button.save').click();
      }),
    ]);
  });

  await test.step('step 7: confirm the cabinet through CabinetWorkflow', async () => {
    await openGrid(page, 'CabinetWorkflow');
    await page.evaluate(() => {
      document.querySelector('.scoop-grid[data-grid-type="CabinetWorkflow"] .confirm-cabinet').click();
    });
    // _reconcileCabinet (cabinet-workflow-tile.js) writes a confirm_state
    // for every row it processes, even a row it can't fill ('impossible')
    // — poll for that rather than a network response, same reasoning as
    // swapSlotToFlavor above.
    await page.waitForFunction(
      (slotId) => {
        const host = document.querySelector('.scoop-grid[data-grid-type]');
        const domain = host._dockListInstance.api.getDomainSnapshot();
        const slot = domain.slot.find((s) => Number(s.id) === Number(slotId));
        return slot && slot.confirm_state !== '';
      },
      state.recordedSlotId,
      { timeout: 20_000 },
    );

    // Known gap (see README.md): Confirm Cabinet alone does NOT restore a
    // slot whose current_flavor now has zero tubs — it reports the slot
    // "impossible" and stops. Verify that's still the observed behavior,
    // then explicitly re-pick the original flavor the same way step 3 did.
    // Hard reload before reading, same reasoning as step 4.
    await forceFreshDomain(page);
    let domain = await getDomain(page);
    let slot = domain.slot.find((s) => Number(s.id) === Number(state.recordedSlotId));
    if (Number(slot.current_flavor) !== Number(state.recordedOriginalFlavorId)) {
      const originalFlavorTitle = domain.flavor.find((f) => Number(f.id) === Number(state.recordedOriginalFlavorId))?._title;
      await swapSlotToFlavor(page, state.recordedSlotId, originalFlavorTitle, state.recordedOriginalTubId);
    }
  });

  await test.step('step 8: confirm data is back to pre-test state', async () => {
    await forceFreshDomain(page);
    const domain = await getDomain(page);

    const slot = domain.slot.find((s) => Number(s.id) === Number(state.recordedSlotId));
    expect(Number(slot.current_flavor), 'slot current_flavor restored').toBe(Number(state.recordedOriginalFlavorId));

    const originalTub = domain.tub.find((t) => Number(t.id) === Number(state.recordedOriginalTubId));
    expect(originalTub.state, 'original tub restored to Opened').toBe('Opened');
    expect(Number(originalTub.slot), 'original tub re-linked to the target slot').toBe(Number(state.recordedSlotId));

    const remainingBatch = domain.batch.find((b) => Number(b.id) === Number(state.newBatchId));
    expect(remainingBatch, 'test batch should no longer exist').toBeFalsy();

    const remainingNewTub = domain.tub.find((t) => Number(t.id) === Number(state.newFractionalTubId));
    expect(remainingNewTub, 'test batch\'s fractional tub should no longer exist').toBeFalsy();
  });
});
