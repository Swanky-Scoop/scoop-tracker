// @ts-check
// Small, focused scenario carved out of "Mother Script"
// (cabinet-workflow-lifecycle.spec.js) — covers batch deletion only: does
// deleting a batch remove it and every tub it created, and does the
// DELETE request stay fast (regression guard for the O(n) Pods
// relationship-mirror bug — see project memory
// batch-delete-speedup-2026-08)? Same mechanism as Mother Script's step 5
// (marked "Reliable" there), standalone: creates its own throwaway batch
// as setup rather than depending on any other test.
const { test, expect } = require('@playwright/test');
const {
  DOCK_PATH, TEST_FLAVOR_TITLE,
  login, forceFreshDomain, createFixtureBatch, deleteBatch, getDomain, openGrid,
} = require('./_shared');

test('batch delete: removes the batch and its tubs, stays fast', async ({ page }) => {
  test.setTimeout(60_000);

  await login(page);
  await page.goto(DOCK_PATH);
  await forceFreshDomain(page);

  const { batchId, tubs } = await createFixtureBatch(page);
  const tubIds = tubs.map((t) => t.id);

  try {
    await openGrid(page, 'Batch');
    const rowSelector = `.scoop-grid[data-grid-type="Batch"] [data-row-id="${batchId}"]`;
    await page.waitForSelector(rowSelector, { timeout: 15_000 });
    await page.evaluate((sel) => {
      document.querySelector(sel).querySelector('button').click();
    }, rowSelector);

    await expect(page.getByText(TEST_FLAVOR_TITLE).first()).toBeHidden({ timeout: 30_000 });

    const deleteRequestMs = await page.evaluate((id) => {
      const entry = performance.getEntriesByType('resource').find((e) => e.name.includes(`/batches/${id}`));
      return entry ? Math.round(entry.duration) : null;
    }, batchId);
    expect(deleteRequestMs, 'batch DELETE request duration').toBeLessThan(15_000);

    await forceFreshDomain(page);
    const domain = await getDomain(page);
    expect(domain.batch.find((b) => Number(b.id) === Number(batchId)), 'batch should no longer exist').toBeFalsy();
    for (const id of tubIds) {
      expect(domain.tub.find((t) => Number(t.id) === Number(id)), `tub ${id} should no longer exist`).toBeFalsy();
    }
  } finally {
    // Safety net: if the delete assertion failed partway (click didn't
    // register, response was slow, etc.), don't leave the batch orphaned
    // for the next run to trip over — same lesson as Mother Script's own
    // afterEach hook. Best-effort: swallow errors so a broken page here
    // doesn't mask the real assertion failure above.
    try {
      const domain = await getDomain(page);
      if (domain?.batch?.some((b) => Number(b.id) === Number(batchId))) {
        await deleteBatch(page, batchId);
      }
    } catch (err) {
      console.error(`[cleanup] could not verify/clean up batch ${batchId}: ${err.message}`);
    }
  }
});
