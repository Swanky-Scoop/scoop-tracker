// @ts-check
// Small, focused scenario carved out of "Mother Script"
// (cabinet-workflow-lifecycle.spec.js) — covers batch creation only: does
// adding a batch via the Batch grid create the expected mix of whole +
// fractional tubs, all Freezing, all for the right flavor? Same mechanism
// as Mother Script's step 1 (marked "Reliable" there), standalone so it
// can run — and be debugged — on its own, with self-contained setup and
// teardown, no dependency on the rest of the lifecycle.
const { test, expect } = require('@playwright/test');
const {
  DOCK_PATH, TEST_FLAVOR_TITLE, BATCH_COUNT,
  login, forceFreshDomain, createFixtureBatch, deleteBatch,
} = require('./_shared');

test('batch create: produces the expected whole + fractional tubs', async ({ page }) => {
  test.setTimeout(60_000);

  await login(page);
  await page.goto(DOCK_PATH);
  await forceFreshDomain(page);

  let batchId;
  try {
    const { batchId: id, tubs, domain } = await createFixtureBatch(page);
    batchId = id;

    const flavorId = domain.flavor.find((f) => f._title === TEST_FLAVOR_TITLE)?.id;
    const wholeCount = Math.floor(BATCH_COUNT);
    const hasFraction = BATCH_COUNT % 1 !== 0;
    const expectedTotal = wholeCount + (hasFraction ? 1 : 0);

    expect(tubs.length, `batch of ${BATCH_COUNT} should create ${expectedTotal} tubs`).toBe(expectedTotal);

    for (const t of tubs) {
      expect(Number(t.flavor), `tub ${t.id} should be the fixture flavor`).toBe(Number(flavorId));
      expect(t.state, `tub ${t.id} should start Freezing`).toBe('Freezing');
    }

    const fractionalTubs = tubs.filter((t) => t.amount % 1 !== 0);
    const wholeTubs = tubs.filter((t) => t.amount % 1 === 0);
    expect(fractionalTubs.length, 'exactly one fractional tub when the count has a fraction').toBe(hasFraction ? 1 : 0);
    expect(wholeTubs.length, 'remaining tubs are whole').toBe(wholeCount);
    for (const t of wholeTubs) expect(Number(t.amount), `whole tub ${t.id} amount`).toBe(1);
    if (hasFraction) {
      const expectedFraction = Math.round((BATCH_COUNT % 1) * 100) / 100;
      expect(Number(fractionalTubs[0].amount), 'fractional tub amount matches the leftover fraction').toBeCloseTo(expectedFraction, 2);
    }
  } finally {
    if (batchId) await deleteBatch(page, batchId);
  }
});
