// @ts-check
// Diagnostic-only, NOT part of the regular suite (see the RUN_DIAGNOSTIC
// guard below) — a minimal, isolated, repeatable repro of the step 3/4
// mystery documented in README.md: a cabinet-slot swap's two writes
// (FlavorTub state+slot, then Cabinet current_flavor) both report
// `{"ok": true, ...}`, but a hard-reload read immediately after shows the
// tub reverted to its pre-swap state (`Freezing`, unlinked). Built
// 2026-08-14 (GUI-SMOKE branch) after the main lifecycle test's own step
// 3/4 proved too entangled with the rest of the lifecycle to iterate on
// quickly. Targets a REAL, currently-occupied slot (not the disposable
// fixture slot) so `add-next` takes the normal "confirm swap / Change
// Plan" path — restores that slot to its exact pre-test state in a
// `finally` block, verified via a final domain read, regardless of
// pass/fail/throw.
//
// Session findings baked into this script (see README.md's item 7 for the
// full writeup):
//   - Every server-side hook/coercion/cache-invalidation step was cleared
//     via direct in-process PHP checks — not reproducible outside the
//     browser layer.
//   - Clicking through the swap UI while a background bundle refetch is
//     still in flight can leave the flavor picker computed from a stale
//     domain (visually confirmed: an empty flavor tile + "Fetching"
//     status). waitForFetchIdle() below guards against that specific
//     failure mode so this script can reliably reach the actual write.
//   - The mystery itself (write reports ok:true, reload reads stale) still
//     reproduces even after that fix — this script is what finally
//     captured it cleanly, with full network logs, for the next session.
//
// Run explicitly (never auto-included in a bare `npx playwright test`,
// since it briefly displaces a real tub from a real cabinet slot):
//   RUN_DIAGNOSTIC=1 npx playwright test diagnostic-swap-write-revert.spec.js
const { test, expect } = require('@playwright/test');

test.skip(!process.env.RUN_DIAGNOSTIC, 'diagnostic-only — run explicitly with RUN_DIAGNOSTIC=1 (see file header)');

const TEST_FLAVOR_TITLE = process.env.SMOKE_TEST_FLAVOR || 'zz__flavor test___';
// A slot with a REAL occupant right now, unlike the main suite's target
// slot — verify this still holds before relying on it; a slot that's
// empty (current_flavor set, no tub) makes add-next skip straight to the
// full picker instead of the normal confirm-swap screen this script needs.
const TARGET_SLOT_TITLE = process.env.SMOKE_DIAGNOSTIC_SLOT || 'Woodinville_dairy_18|2';
const BATCH_COUNT = Number(process.env.SMOKE_BATCH_COUNT || 3.4);
const DOCK_PATH = '/dock/';

async function openGrid(page, gridType) {
  await page.evaluate((type) => {
    const host = document.querySelector(`.scoop-grid[data-grid-type="${type}"]`);
    const inst = host._dockListInstance;
    if (!host.classList.contains('toggled')) inst.TOGGLE.click();
  }, gridType);
}
async function waitForDomain(page) {
  await page.waitForFunction(() => {
    const host = document.querySelector('.scoop-grid[data-grid-type]');
    const domain = host?._dockListInstance?.api?.getDomainSnapshot?.();
    return Array.isArray(domain?.flavor) && domain.flavor.length > 0;
  }, { timeout: 20_000 });
}
async function getDomain(page) {
  await waitForDomain(page);
  return page.evaluate(() => {
    const host = document.querySelector('.scoop-grid[data-grid-type]');
    return host._dockListInstance.api.getDomainSnapshot();
  });
}
async function forceFreshDomain(page) {
  const url = new URL(page.url());
  url.hash = 'bust';
  await page.goto(url.toString());
  await waitForDomain(page);
}

// Waits for ScoopAPI's own in-flight-fetch promise to clear — see the
// "Fetching status" finding in this file's header comment.
async function waitForFetchIdle(page, { timeout = 15_000 } = {}) {
  await page.waitForFunction(() => {
    const host = document.querySelector('.scoop-grid[data-grid-type]');
    return !host?._dockListInstance?.api?._domainInflight;
  }, { timeout });
}

async function directSwap(page, slotId, flavorTitle, tubId, log) {
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
    { slotId, tubId, flvTitle: flavorTitle, nonce },
  );
  log.push('DIRECT WRITE: ' + JSON.stringify(result));
  return result;
}

test('diagnostic: swap write reports ok:true but reload reads stale state', async ({ page }) => {
  test.setTimeout(120_000);

  const log = [];
  page.on('request', (req) => {
    const url = req.url();
    if (url.includes('/tubs') || url.includes('/planning') || url.includes('/batches')) {
      log.push(`--> ${req.method()} ${url}  body=${req.postData()}`);
    }
  });
  page.on('response', (res) => {
    const url = res.url();
    if (url.includes('/tubs') || url.includes('/planning') || url.includes('/batches')) {
      log.push(`<-- ${res.status()} ${url}`);
    }
  });

  await page.goto('/wp-login.php');
  await page.locator('#user_login').fill(process.env.SCOOP_TEST_USER || '');
  await page.locator('#user_pass').fill(process.env.SCOOP_TEST_PASS || '');
  await page.getByRole('button', { name: 'Log In' }).click();

  await page.addInitScript(() => {
    window.confirm = () => true;
    window.alert = () => {};
    document.addEventListener('DOMContentLoaded', () => {
      document.body.classList.add('no-animations');
    });
  });

  await page.goto(DOCK_PATH);
  await waitForDomain(page);
  await forceFreshDomain(page);

  log.push('=== CREATE BATCH ===');
  await openGrid(page, 'Batch');
  const batchHost = page.locator('.scoop-grid[data-grid-type="Batch"]');
  await batchHost.getByRole('cell', { name: 'Add batch' }).getByRole('textbox').click();
  await batchHost.getByRole('cell', { name: 'Add batch' }).getByRole('textbox').fill(TEST_FLAVOR_TITLE.replace(/_+$/, ''));
  await page.getByRole('option', { name: TEST_FLAVOR_TITLE }).click();
  await batchHost.getByRole('spinbutton').fill(String(BATCH_COUNT));
  await batchHost.getByRole('button', { name: /save/i }).first().click();
  await expect(batchHost.getByText(TEST_FLAVOR_TITLE).first()).toBeVisible({ timeout: 15_000 });

  const domain1 = await getDomain(page);
  const batch = (domain1.batch || [])
    .filter((b) => Number(b.flavor) === (domain1.flavor.find((f) => f._title === TEST_FLAVOR_TITLE)?.id))
    .sort((a, b) => Number(b.id) - Number(a.id))[0];
  const newBatchId = batch.id;
  const tubs = (domain1.tub || []).filter((t) => Number(t.batch) === Number(newBatchId));
  const fractional = tubs.find((t) => t.amount % 1 !== 0);
  const newFractionalTubId = fractional.id;
  console.log(`[diag] batch=${newBatchId} fractionalTub=${newFractionalTubId}`);

  log.push('=== RECORD SLOT ===');
  const slot = (domain1.slot || []).find((s) => s._title === TARGET_SLOT_TITLE);
  const recordedSlotId = slot.id;
  const recordedOriginalFlavorId = slot.current_flavor;
  const occupant = (domain1.tub || []).find((t) => Number(t.slot) === Number(recordedSlotId));
  expect(occupant, `target slot "${TARGET_SLOT_TITLE}" should currently hold a real tub — see header comment`).toBeTruthy();
  const recordedOriginalTubId = occupant.id;
  console.log(`[diag] slot=${recordedSlotId} originalFlavor=${recordedOriginalFlavorId} originalTub=${recordedOriginalTubId}`);

  let passed = false;
  let newTub;
  try {
    log.push('=== WAITING FOR ANY IN-FLIGHT FETCH TO SETTLE ===');
    await waitForFetchIdle(page);

    log.push('=== SWAP (UI click-through) ===');
    await openGrid(page, 'CabinetWorkflow');
    await page.keyboard.press('Escape');
    await page.evaluate((id) => {
      const host = document.querySelector('.scoop-grid[data-grid-type="CabinetWorkflow"]');
      const tile = host.querySelector(`[data-row-id="${id}"]`);
      tile.scrollIntoView({ block: 'center' });
      tile.querySelector('.add-next, button').click();
    }, recordedSlotId);
    await page.getByRole('button', { name: 'Change Plan' }).click();
    await expect(page.getByRole('button', { name: TEST_FLAVOR_TITLE, exact: false })).toBeVisible({ timeout: 10_000 });
    await page.keyboard.press('Escape');

    log.push('=== DIRECT WRITES START ===');
    const result = await directSwap(page, recordedSlotId, TEST_FLAVOR_TITLE, newFractionalTubId, log);
    console.log('[diag] direct write result:', JSON.stringify(result));
    await page.keyboard.press('Escape');

    log.push('=== RELOAD AND CHECK ===');
    await forceFreshDomain(page);
    const domain2 = await getDomain(page);
    newTub = (domain2.tub || []).find((t) => Number(t.id) === Number(newFractionalTubId));
    console.log('[diag] AFTER RELOAD tub.state=', newTub?.state, 'tub.slot=', newTub?.slot);
    log.push(`=== AFTER RELOAD: tub.state=${newTub?.state} tub.slot=${newTub?.slot} ===`);

    passed = newTub?.state === 'Opened' && Number(newTub?.slot) === Number(recordedSlotId);
  } finally {
    console.log('\n\n===== FULL NETWORK LOG =====\n' + log.join('\n'));

    // Restore regardless of pass/fail/throw — an assertion throw before
    // this point in an earlier iteration of this script skipped cleanup
    // entirely and left orphaned batches; don't repeat that.
    log.push('=== RESTORE ===');
    const nonceDel = await page.evaluate(() => window.SCOOP.nonce);
    await page.evaluate(async ({ id, nonce }) => {
      await fetch(`/wp-json/scoop/v1/batches/${id}`, {
        method: 'DELETE',
        headers: { 'X-WP-Nonce': nonce },
        credentials: 'include',
      });
    }, { id: newBatchId, nonce: nonceDel });

    await forceFreshDomain(page);
    const domain3 = await getDomain(page);
    const originalFlavorTitle = domain3.flavor.find((f) => Number(f.id) === Number(recordedOriginalFlavorId))?._title;
    await directSwap(page, recordedSlotId, originalFlavorTitle, recordedOriginalTubId, log);

    await forceFreshDomain(page);
    const domain4 = await getDomain(page);
    const restoredSlot = domain4.slot.find((s) => Number(s.id) === Number(recordedSlotId));
    const restoredTub = domain4.tub.find((t) => Number(t.id) === Number(recordedOriginalTubId));
    console.log('[diag] RESTORE CHECK: slot.current_flavor=', restoredSlot?.current_flavor, 'expected', recordedOriginalFlavorId,
      ' tub.state=', restoredTub?.state, ' tub.slot=', restoredTub?.slot, 'expected', recordedSlotId);

    expect(Number(restoredSlot?.current_flavor), 'slot restored to original flavor').toBe(Number(recordedOriginalFlavorId));
    expect(restoredTub?.state, 'original tub restored to Opened').toBe('Opened');
    expect(Number(restoredTub?.slot), 'original tub re-linked to slot').toBe(Number(recordedSlotId));
  }

  expect(passed, 'the actual mystery under investigation: did the swap verify correctly on first read?').toBe(true);
});
