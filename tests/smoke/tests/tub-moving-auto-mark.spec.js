// @ts-check
const { test, expect } = require('@playwright/test');

// ── Fixture config ──────────────────────────────────────────────────────
// Deliberately a SEPARATE fixture flavor from cabinet-workflow-lifecycle's
// 'zz__flavor test___' — that one already has real tubs at BOTH Woodinville
// and Mountlake Terrace (from unrelated earlier local sample-data work),
// which would break this test's "no local stock at the destination"
// precondition. Seed it once via Pods API/CLI before running this suite —
// see the setup step below for the exact check/error this test throws if
// it's missing (it does NOT create the flavor itself, same as
// cabinet-workflow-lifecycle's own fixture flavor).
const FIXTURE_FLAVOR_TITLE = process.env.SMOKE_MOVING_FLAVOR || 'zz__flavor moving test___';
// Mountlake Terrace's "restricted" cabinet only prohibits dairy — this
// fixture flavor ships with NO allergens tagged, so it's eligible there
// without needing the (currently broken, see README) dairy-tagging UI step
// cabinet-workflow-lifecycle.spec.js relies on for its own dairy-cabinet
// fixture.
const TARGET_SLOT_TITLE = process.env.SMOKE_MOVING_SLOT || 'Mountlake Terrace_restricted_12|1';
const MOUNTLAKE_LOCATION_TITLE = process.env.SMOKE_MOVING_LOCATION || 'Mountlake Terrace';
const DOCK_PATH = '/dock/';

test.describe.configure({ mode: 'serial' });

/**
 * Full domain via a direct, cache-bypassing REST call — NOT the browser's
 * in-page domain snapshot (host._dockListInstance.api.getDomainSnapshot()),
 * which cabinet-workflow-lifecycle.spec.js's own README documents as
 * subject to an unresolved, transient server-side bundle-cache race after a
 * write (confirmed independently while building this test: the write
 * itself lands correctly and immediately in the DB, but a client read —
 * even after a hard reload — can still observe a stale cached bundle for
 * some window afterward). This test's whole point is verifying server-side
 * hook behavior (does moving_to get set correctly), so it verifies against
 * server truth directly instead of being gated by that separate, unrelated
 * bug — same reasoning as this suite's own swapSlotToFlavor() using a
 * direct REST write instead of the modal UI for its commit step.
 */
async function fetchFreshBundle(page, types) {
  const nonce = await page.evaluate(() => window.SCOOP.nonce);
  const result = await page.evaluate(
    async ({ types, nonce }) => {
      const res = await fetch(`/wp-json/scoop/v1/bundle?types=${encodeURIComponent(types)}&force_bust=1`, {
        method: 'GET',
        headers: { 'X-WP-Nonce': nonce },
        credentials: 'include',
      });
      const body = await res.json().catch(() => null);
      return { status: res.status, body };
    },
    { types, nonce },
  );
  expect(result.status, `bundle fetch for ${types}: ${JSON.stringify(result.body)}`).toBe(200);
  expect(result.body?.ok, `bundle response not ok: ${JSON.stringify(result.body)}`).toBe(true);
  return result.body.data;
}

/** Same direct-REST-write pattern as swapSlotToFlavor() in cabinet-workflow-lifecycle.spec.js. */
async function writeCells(page, path, envelopeKey, cells) {
  const nonce = await page.evaluate(() => window.SCOOP.nonce);
  const result = await page.evaluate(
    async ({ path, envelopeKey, cells, nonce }) => {
      const res = await fetch(path, {
        method: 'POST',
        headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({ [envelopeKey]: { cells, source: 'smoke-test' } }),
      });
      const body = await res.json().catch(() => null);
      return { status: res.status, body };
    },
    { path, envelopeKey, cells, nonce },
  );
  expect(result.status, `POST ${path}: ${JSON.stringify(result.body)}`).toBe(200);
  expect(result.body?.ok, `POST ${path} ok=false: ${JSON.stringify(result.body)}`).toBe(true);
  return result.body;
}

test('tub-moving: scheduling a flavor at a location with no local stock earmarks a tub elsewhere', async ({ page }) => {
  test.setTimeout(60_000);

  await test.step('log in', async () => {
    await page.goto('/wp-login.php');
    await page.locator('#user_login').fill(process.env.SCOOP_TEST_USER || '');
    await page.locator('#user_pass').fill(process.env.SCOOP_TEST_PASS || '');
    await page.getByRole('button', { name: 'Log In' }).click();
  });

  await page.addInitScript(() => {
    window.confirm = () => true;
    window.alert = () => {};
  });

  await page.goto(DOCK_PATH);
  await page.waitForFunction(() => {
    const host = document.querySelector('.scoop-grid[data-grid-type]');
    return Array.isArray(host?._dockListInstance?.api?.getDomainSnapshot?.()?.flavor);
  }, { timeout: 20_000 });

  let data = await fetchFreshBundle(page, 'BatchHistory,CabinetWorkflow');

  const flavor = (data.flavor || []).find((f) => f._title === FIXTURE_FLAVOR_TITLE);
  expect(flavor, `Fixture flavor "${FIXTURE_FLAVOR_TITLE}" not found — seed it first (pods_api()->save_pod_item(['pod'=>'flavor','data'=>['post_title'=>'${FIXTURE_FLAVOR_TITLE}']])).`).toBeTruthy();

  const mountlake = (data.location || []).find((l) => l._title === MOUNTLAKE_LOCATION_TITLE);
  expect(mountlake, `Location "${MOUNTLAKE_LOCATION_TITLE}" not found.`).toBeTruthy();

  const targetSlot = (data.slot || []).find((s) => s._title === TARGET_SLOT_TITLE);
  expect(targetSlot, `Target slot "${TARGET_SLOT_TITLE}" not found.`).toBeTruthy();

  // Preconditions this test's premise depends on — fail loudly here rather
  // than get a confusing false pass/fail deeper in if local data drifted.
  const mountlakeStockOfFlavor = (data.tub || []).filter(
    (t) => Number(t.flavor) === Number(flavor.id) && Number(t.location) === Number(mountlake.id) && t.state !== 'Emptied',
  );
  expect(mountlakeStockOfFlavor, `Fixture flavor should have NO stock at ${MOUNTLAKE_LOCATION_TITLE} before this test runs — found ${mountlakeStockOfFlavor.length}. Clear it (or use a fresh fixture flavor) before re-running.`).toHaveLength(0);

  const originalSlotFlavor = Number(targetSlot.current_flavor || 0);
  let newBatchId;
  let newTubId;

  try {
    await test.step('setup: create one Woodinville tub of the fixture flavor via the real Batch UI', async () => {
      await page.evaluate((type) => {
        const host = document.querySelector(`.scoop-grid[data-grid-type="${type}"]`);
        if (!host) throw new Error(`No grid host for type "${type}"`);
        const inst = host._dockListInstance;
        if (!host.classList.contains('toggled')) inst.TOGGLE.click();
      }, 'Batch');

      const batchHost = page.locator('.scoop-grid[data-grid-type="Batch"]');
      await batchHost.getByRole('cell', { name: 'Add batch' }).getByRole('textbox').click();
      await batchHost.getByRole('cell', { name: 'Add batch' }).getByRole('textbox').fill(FIXTURE_FLAVOR_TITLE.replace(/_+$/, ''));
      await page.getByRole('option', { name: FIXTURE_FLAVOR_TITLE }).click();
      await batchHost.getByRole('spinbutton').fill('1');
      await batchHost.getByRole('button', { name: /save/i }).first().click();
      await expect(batchHost.getByText(FIXTURE_FLAVOR_TITLE).first()).toBeVisible({ timeout: 15_000 });
    });

    await test.step('verify setup landed (direct bundle read, not the in-page domain)', async () => {
      data = await fetchFreshBundle(page, 'BatchHistory,CabinetWorkflow');
      const batch = (data.batch || [])
        .filter((b) => Number(b.flavor) === Number(flavor.id))
        .sort((a, b) => Number(b.id) - Number(a.id))[0];
      expect(batch, 'new batch not found in a fresh bundle read').toBeTruthy();
      newBatchId = batch.id;

      const newTub = (data.tub || []).find((t) => Number(t.batch) === Number(newBatchId));
      expect(newTub, 'new tub not found in a fresh bundle read').toBeTruthy();
      newTubId = newTub.id;
      expect(newTub.state).toBe('Freezing');
      expect(Number(newTub.location)).toBe(Number(935)); // scoop_get_default_location_id() — Woodinville
      expect(Number(newTub.moving_to || 0), 'freshly created tub should not already be marked moving').toBe(0);
    });

    await test.step('action: schedule the fixture flavor at a Mountlake slot with no local stock', async () => {
      await writeCells(page, '/wp-json/scoop/v1/planning', 'Cabinet', {
        [targetSlot.id]: { current_flavor: flavor.id },
      });
    });

    await test.step('assert: the Woodinville tub got earmarked to move to Mountlake Terrace', async () => {
      // Polls rather than a single read: found while building this test
      // that even a force_bust=1 (fully cache-bypassing) fetch can briefly
      // read a tub's just-written field as stale — confirmed the write
      // itself lands correctly and immediately in the DB (direct PHP/Pods
      // check), and a later fetch is correct, but the first fetch
      // immediately after the write can still miss it. This is NOT the
      // known scoop_slow_changing_entity_types() cache gap (that's
      // flavor/use/location/cabinet only, never tub) — a real, sharper,
      // still-unexplained propagation delay on tub reads specifically. See
      // README.md's "Not yet resolved" section, which documents the same
      // class of symptom for the CabinetWorkflow swap. Retrying here is the
      // pragmatic choice given that's a separate, deeper investigation —
      // not papering over a bug in the feature THIS test is actually
      // checking (the hook's own write is correct every time it's been
      // checked directly).
      let tub = null;
      for (let attempt = 0; attempt < 10; attempt++) {
        data = await fetchFreshBundle(page, 'BatchHistory,CabinetWorkflow');
        tub = (data.tub || []).find((t) => Number(t.id) === Number(newTubId));
        if (Number(tub?.moving_to || 0) === Number(mountlake.id)) break;
        await page.waitForTimeout(500);
      }
      expect(tub, 'fixture tub disappeared from the bundle').toBeTruthy();
      expect(Number(tub.moving_to), `tub.moving_to should be ${MOUNTLAKE_LOCATION_TITLE} (${mountlake.id})`).toBe(Number(mountlake.id));
    });
  } finally {
    await test.step('cleanup: restore the slot, delete the fixture batch/tub', async () => {
      await writeCells(page, '/wp-json/scoop/v1/planning', 'Cabinet', {
        [targetSlot.id]: { current_flavor: originalSlotFlavor },
      });

      if (newBatchId) {
        const nonce = await page.evaluate(() => window.SCOOP.nonce);
        await page.evaluate(
          async ({ batchId, nonce }) => {
            await fetch(`/wp-json/scoop/v1/batches/${batchId}`, {
              method: 'DELETE',
              headers: { 'X-WP-Nonce': nonce },
              credentials: 'include',
            });
          },
          { batchId: newBatchId, nonce },
        );
      }
    });
  }
});
