// @ts-check
// Shared helpers for tests/smoke's smaller, focused scenario scripts (see
// each file's own header for what it covers individually). Deliberately
// NOT used by cabinet-workflow-lifecycle.spec.js ("Mother Script" — see
// README.md) — that file keeps its own inline copies so it stays
// untouched while these smaller, carved-out tests get built up around it.
const { expect } = require('@playwright/test');

const TEST_FLAVOR_TITLE = process.env.SMOKE_TEST_FLAVOR || 'zz__flavor test___';
const BATCH_COUNT = Number(process.env.SMOKE_BATCH_COUNT || 3.4);
const DOCK_PATH = '/dock/';

async function login(page) {
  await page.goto('/wp-login.php');
  await page.locator('#user_login').fill(process.env.SCOOP_TEST_USER || '');
  await page.locator('#user_pass').fill(process.env.SCOOP_TEST_PASS || '');
  await page.getByRole('button', { name: 'Log In' }).click();

  // window.confirm()/alert() block automated clicks on every future
  // navigation — see Mother Script's own copy of this comment for the
  // .no-animations rationale (assets/css.css).
  await page.addInitScript(() => {
    window.confirm = () => true;
    window.alert = () => {};
    document.addEventListener('DOMContentLoaded', () => {
      document.body.classList.add('no-animations');
    });
  });
}

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

// Waits for ScoopAPI's own in-flight-fetch promise to clear — see
// diagnostic-swap-write-revert.spec.js's header for the "Fetching status /
// empty flavor tile" finding this guards against.
async function waitForFetchIdle(page, { timeout = 15_000 } = {}) {
  await page.waitForFunction(() => {
    const host = document.querySelector('.scoop-grid[data-grid-type]');
    return !host?._dockListInstance?.api?._domainInflight;
  }, { timeout });
}

/**
 * Creates a batch of the fixture flavor via the real Batch grid UI (same
 * mechanism as Mother Script's step 1) and returns its id plus every tub
 * it created. Caller must already be logged in and navigated to a page
 * with a mounted grid (DOCK_PATH).
 */
async function createFixtureBatch(page, { count = BATCH_COUNT } = {}) {
  await openGrid(page, 'Batch');
  const batchHost = page.locator('.scoop-grid[data-grid-type="Batch"]');
  await batchHost.getByRole('cell', { name: 'Add batch' }).getByRole('textbox').click();
  await batchHost.getByRole('cell', { name: 'Add batch' }).getByRole('textbox').fill(TEST_FLAVOR_TITLE.replace(/_+$/, ''));
  await page.getByRole('option', { name: TEST_FLAVOR_TITLE }).click();
  await batchHost.getByRole('spinbutton').fill(String(count));
  await batchHost.getByRole('button', { name: /save/i }).first().click();
  await expect(batchHost.getByText(TEST_FLAVOR_TITLE).first()).toBeVisible({ timeout: 15_000 });

  const domain = await getDomain(page);
  const flavorId = domain.flavor.find((f) => f._title === TEST_FLAVOR_TITLE)?.id;
  const batch = (domain.batch || [])
    .filter((b) => Number(b.flavor) === Number(flavorId))
    .sort((a, b) => Number(b.id) - Number(a.id))[0];
  const batchId = batch.id;
  const tubs = (domain.tub || []).filter((t) => Number(t.batch) === Number(batchId));

  return { batchId, tubs, domain };
}

/** Deletes a batch via a direct REST call — fast and reliable (this exact route is what Mother Script's step 5 drives through the UI instead). */
async function deleteBatch(page, batchId) {
  const nonce = await page.evaluate(() => window.SCOOP.nonce);
  return page.evaluate(async ({ id, nonce }) => {
    const r = await fetch(`/wp-json/scoop/v1/batches/${id}`, {
      method: 'DELETE',
      headers: { 'X-WP-Nonce': nonce },
      credentials: 'include',
    });
    return { status: r.status };
  }, { id: batchId, nonce });
}

module.exports = {
  TEST_FLAVOR_TITLE,
  BATCH_COUNT,
  DOCK_PATH,
  login,
  openGrid,
  waitForDomain,
  waitForFetchIdle,
  getDomain,
  forceFreshDomain,
  createFixtureBatch,
  deleteBatch,
};
