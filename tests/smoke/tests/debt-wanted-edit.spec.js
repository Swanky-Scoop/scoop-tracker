// @ts-check
const { test, expect } = require('@playwright/test');

// ── Fixture config ──────────────────────────────────────────────────────
// Third fixture flavor, deliberately separate from cabinet-workflow's
// 'zz__flavor test___' and tub-moving's 'zz__flavor moving test___' — each
// suite asserts hard preconditions (stock, slot designations) against its
// own flavor, so a shared one would couple their setups. Seed it once via
// the Pods API/CLI before running this suite (the test does NOT create the
// flavor itself, same as its siblings):
//   pods_api()->save_pod_item(['pod'=>'flavor','data'=>['post_title'=>'zz__flavor debt test___']])
const FIXTURE_FLAVOR_TITLE = process.env.SMOKE_DEBT_FLAVOR || 'zz__flavor debt test___';
// Destination location, by title (post IDs aren't stable across
// environments — same convention as the sibling specs).
const DESTINATION_TITLE = process.env.SMOKE_DEBT_LOCATION || 'Mountlake Terrace';
const DOCK_PATH = '/dock/';

// Mirrors of debt-grid-model.js's supply-bucket constants (kept in sync by
// hand, same as the three copies of WHOLE_TUB_THRESHOLD in the app — this
// is a test oracle, not a fourth production copy).
const WHOLE_TUB_THRESHOLD = 0.8;
const DEAD_STATES = new Set(['Emptied', '!Lost']);
const OPEN_STATE = 'Opened';
const FRONT_OF_HOUSE_USE_ID = 1863;

test.describe.configure({ mode: 'serial' });

/** The Debt board's synthetic numeric row id — mirrors debtRowId() (location*100000+flavor). */
const debtRowId = (locationId, flavorId) => Number(locationId) * 100000 + Number(flavorId);

/**
 * Full domain via a direct, cache-bypassing REST call — NOT the browser's
 * in-page domain snapshot, which has a documented transient stale-read race
 * after a write (see tub-moving-auto-mark.spec.js's fetchFreshBundle for
 * the full investigation). Requesting the CONFIG type 'Debt' expands to its
 * declared needs (includes/_specs.php: slot, tub, flavor, use, location,
 * flavor_request) — flavor_request itself is not a route key and cannot be
 * requested directly.
 */
async function fetchFreshBundle(page) {
  const nonce = await page.evaluate(() => window.SCOOP.nonce);
  const result = await page.evaluate(
    async ({ nonce }) => {
      const res = await fetch(`/wp-json/scoop/v1/bundle?types=Debt&force_bust=1`, {
        method: 'GET',
        headers: { 'X-WP-Nonce': nonce },
        credentials: 'include',
      });
      const body = await res.json().catch(() => null);
      return { status: res.status, body };
    },
    { nonce },
  );
  expect(result.status, `bundle fetch: ${JSON.stringify(result.body)}`).toBe(200);
  expect(result.body?.ok, `bundle response not ok: ${JSON.stringify(result.body)}`).toBe(true);
  return result.body.data;
}

/**
 * Direct write to the /debt-requests route — the same endpoint the Debt
 * board's own autosave posts to, used here for setup/cleanup fixture
 * management (same direct-REST-write pattern as
 * tub-moving-auto-mark.spec.js's writeCells / cabinet-workflow's
 * swapSlotToFlavor: setup and assertions ride server truth, only the EDIT
 * under test goes through the real UI).
 */
async function postDebtRequests(page, cells) {
  const nonce = await page.evaluate(() => window.SCOOP.nonce);
  const result = await page.evaluate(
    async ({ cells, nonce }) => {
      const res = await fetch('/wp-json/scoop/v1/debt-requests', {
        method: 'POST',
        headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({ Debt: { cells, source: 'smoke-test' } }),
      });
      const body = await res.json().catch(() => null);
      return { status: res.status, body };
    },
    { cells, nonce },
  );
  expect(result.status, `POST /debt-requests: ${JSON.stringify(result.body)}`).toBe(200);
  expect(result.body?.ok, `POST /debt-requests ok=false: ${JSON.stringify(result.body)}`).toBe(true);
  return result.body;
}

/** Poll fetchFreshBundle() until fn(data) is true (or attempts run out). */
async function pollBundleUntil(page, fn, { attempts = 12, delayMs = 500 } = {}) {
  for (let i = 0; i < attempts; i++) {
    const data = await fetchFreshBundle(page);
    if (fn(data)) return data;
    await page.waitForTimeout(delayMs);
  }
  return null;
}

/**
 * Mirror of computeDebtRows()'s supply buckets for ONE (destination,
 * flavor) pair — the test oracle for choosing Wanted values that produce a
 * known gap/status regardless of how the mirror's real tubs have drifted:
 *   onHand  — FOH whole tubs AT the destination (state not dead, amount ≥
 *             threshold, not earmarked elsewhere).
 *   inbound — whole non-dead tubs with moving_to = destination (from any
 *             source; the model's inbound bucket does NOT check use).
 *   availableElsewhere — FOH, non-Opened, unearmarked whole tubs of the
 *             flavor located anywhere ELSE (the sendable pool).
 */
function supplyForPair(data, destId, flavorId, isFoh) {
  const tubs = Array.isArray(data.tub) ? data.tub : [];
  let onHand = 0;
  let inbound = 0;
  let availableElsewhere = 0;
  for (const t of tubs) {
    if (Number(t.flavor) !== Number(flavorId)) continue;
    const state = String(t.state ?? '');
    if (DEAD_STATES.has(state)) continue;
    if (Number(t.amount ?? 1) < WHOLE_TUB_THRESHOLD) continue;
    const movingTo = Number(t.moving_to ?? 0);
    if (movingTo) {
      if (movingTo === Number(destId)) inbound += 1;
      continue; // earmarked tubs are claimed — never also available
    }
    if (!isFoh(t)) continue;
    if (Number(t.location) === Number(destId)) onHand += 1;
    else if (state !== OPEN_STATE) availableElsewhere += 1;
  }
  return { onHand, inbound, availableElsewhere };
}

test('debt: editing a Wanted cell autosaves through /debt-requests and the board follows', async ({ page }) => {
  test.setTimeout(90_000);

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

  // ── Fixture lookup + preconditions (reads and self-heal only — the mint
  //    itself happens inside the try, so the finally cleanup always has a
  //    login to work with). ──────────────────────────────────────────────
  let data = await fetchFreshBundle(page);

  const flavor = (data.flavor || []).find((f) => f._title === FIXTURE_FLAVOR_TITLE);
  expect(
    flavor,
    `Fixture flavor "${FIXTURE_FLAVOR_TITLE}" not found — seed it first (pods_api()->save_pod_item(['pod'=>'flavor','data'=>['post_title'=>'${FIXTURE_FLAVOR_TITLE}']])).`,
  ).toBeTruthy();

  const dest = (data.location || []).find((l) => l._title === DESTINATION_TITLE);
  expect(dest, `Destination location "${DESTINATION_TITLE}" not found.`).toBeTruthy();

  // FOH classification mirror — isFrontOfHouseUse(): id 1863, absent use
  // (defaults FOH), or the normalized-label fallback.
  const useTitleById = new Map(
    (data.use || []).map((u) => [Number(u.id), u._title || u.title?.rendered || '']),
  );
  const isFoh = (t) => {
    const id = Number(t?.use ?? 0);
    if (id && id === FRONT_OF_HOUSE_USE_ID) return true;
    const normalized = String(useTitleById.get(id) ?? '')
      .toLowerCase()
      .replace(/&amp;/g, '&')
      .replace(/[-_]+/g, ' ')
      .replace(/\s+/g, ' ')
      .trim();
    return !id || normalized === 'front of house';
  };

  // Precondition: no slot at the destination already designates the fixture
  // flavor. A slot's current/immediate_flavor also creates demand for the
  // pair (slots are the floor, requests the override), which would make the
  // "covered → row leaves the board" step wrong — demand can never drop
  // below what the slots imply. Fail loudly instead of guessing.
  const slotImplied = (data.slot || []).filter(
    (s) =>
      Number(s.location) === Number(dest.id) &&
      [s.current_flavor, s.immediate_flavor].some((f) => Number(f || 0) === Number(flavor.id)),
  );
  expect(
    slotImplied,
    `Fixture flavor should have NO slot designations at ${DESTINATION_TITLE} before this test runs — found ${slotImplied.length}. Clear them (or use a fresh fixture flavor) before re-running.`,
  ).toHaveLength(0);

  const rowId = debtRowId(dest.id, flavor.id);
  const rowIdKey = String(rowId);

  // Self-heal: a prior run that died mid-test leaves its override row
  // behind (this suite has no afterEach — same caveat the README documents
  // for the sibling specs). wanted=0 deletes the row idempotently.
  let stale = (data.flavor_request || []).find(
    (r) => Number(r.location) === Number(dest.id) && Number(r.flavor) === Number(flavor.id),
  );
  if (stale) {
    await postDebtRequests(page, { [rowIdKey]: { wanted: 0 } });
    const cleaned = await pollBundleUntil(
      page,
      (d) =>
        !(d.flavor_request || []).some(
          (r) => Number(r.location) === Number(dest.id) && Number(r.flavor) === Number(flavor.id),
        ),
    );
    expect(cleaned, 'stale override row from a prior run could not be deleted').toBeTruthy();
    data = cleaned;
  }

  // Choose the two Wanted values from live supply so the test is robust to
  // mirror drift: one with gap ≥ 1 (row visible, action exists), then one
  // at exactly on_hand+inbound (gap 0). With no slot-implied demand (the
  // precondition above) the pair's demand IS the override, so these are the
  // exact numbers computeDebtRows() will compute.
  const supply = supplyForPair(data, dest.id, flavor.id, isFoh);
  const WANTED_VISIBLE = supply.onHand + supply.inbound + 1;
  const WANTED_COVERED = supply.onHand + supply.inbound;
  // gap ≥ 1 rows are fillable when the sendable pool is non-empty,
  // unfillable when it isn't (the churn queue).
  const expectedVisibleStatus = supply.availableElsewhere > 0 ? 'fillable' : 'unfillable';

  const rowHiddenInput = page.locator(
    `.scoop-grid[data-grid-type="Debt"] input[type="hidden"][name='Debt[cells][${rowId}][demand]']`,
  );
  const row = page.locator(
    `.scoop-grid[data-grid-type="Debt"] tr:has(input[type="hidden"][name='Debt[cells][${rowId}][demand]'])`,
  );
  const wantedNumberInput = page.locator(
    `.scoop-grid[data-grid-type="Debt"] .textIt.col-demand:has(input[type="hidden"][name='Debt[cells][${rowId}][demand]']) input[type="number"]`,
  );

  try {
    await test.step('setup: mint the demand override through the real /debt-requests route', async () => {
      const body = await postDebtRequests(page, { [rowIdKey]: { wanted: WANTED_VISIBLE } });
      expect(
        body.updated?.[rowIdKey]?.wanted,
        `route should report the pair updated: ${JSON.stringify(body.updated)}`,
      ).toBe(WANTED_VISIBLE);
      // Read-back poll — flavor_request reads showed the same class of
      // transient stale-window after writes as tub reads (see
      // tub-moving-auto-mark.spec.js's poll rationale).
      const landed = await pollBundleUntil(
        page,
        (d) =>
          (d.flavor_request || []).some(
            (r) =>
              Number(r.location) === Number(dest.id) &&
              Number(r.flavor) === Number(flavor.id) &&
              Number(r.wanted) === WANTED_VISIBLE,
          ),
      );
      expect(landed, 'minted override never read back from a fresh bundle').toBeTruthy();
    });

    await test.step('open the Debt board and find the pair row', async () => {
      // True reload (page.reload, NOT a hash-only goto — a fragment-only
      // navigation from /dock/ is same-document and would keep the
      // pre-mint in-page domain): the mint was a direct REST write from
      // this test's own context, invisible to the page.
      await page.reload();
      await page.waitForFunction(() => {
        const host = document.querySelector('.scoop-grid[data-grid-type]');
        return Array.isArray(host?._dockListInstance?.api?.getDomainSnapshot?.()?.flavor);
      }, { timeout: 20_000 });
      await page.evaluate((type) => {
        const host = document.querySelector(`.scoop-grid[data-grid-type="${type}"]`);
        if (!host) throw new Error(`No grid host for type "${type}"`);
        const inst = host._dockListInstance;
        if (!host.classList.contains('toggled')) inst.TOGGLE.click();
      }, 'Debt');

      // The row renders once the grid's own bundle fetch lands — poll via
      // the locator rather than assuming an instant render.
      await expect(rowHiddenInput).toHaveValue(String(WANTED_VISIBLE), { timeout: 20_000 });
      await expect(row).toContainText(expectedVisibleStatus);
      await expect(wantedNumberInput).toHaveValue(String(WANTED_VISIBLE));
    });

    await test.step('edit the Wanted cell down to on_hand+inbound (gap 0) and catch the autosave', async () => {
      // The real UI path: TextIt input → change event → List's 250ms
      // autosave debounce → POST /debt-requests. waitForResponse catches
      // the actual wire payload — the browser-only contract no unit test
      // can see: envelope key Debt, synthetic numeric row-id cell key,
      // 'demand' field name (the client's column key, mapped to the
      // flavor_request row's wanted by the server).
      const responsePromise = page.waitForResponse(
        (r) => r.url().includes('/wp-json/scoop/v1/debt-requests') && r.request().method() === 'POST',
      );
      await wantedNumberInput.fill(String(WANTED_COVERED));
      const resp = await responsePromise;

      const posted = resp.request().postDataJSON();
      expect(
        posted?.Debt?.cells?.[rowIdKey]?.demand,
        `autosave body should carry Debt[cells][${rowId}][demand]=${WANTED_COVERED}: ${JSON.stringify(posted)}`,
      ).toBe(WANTED_COVERED);

      const body = await resp.json();
      expect(body?.ok, `autosave response not ok: ${JSON.stringify(body)}`).toBe(true);
      expect(
        body?.updated?.[rowIdKey]?.wanted,
        `response should report the pair updated: ${JSON.stringify(body.updated)}`,
      ).toBe(WANTED_COVERED);

      // The visible UI feedback — the cell flashes cell-saved for 800ms
      // after a successful autosave (List._flashCells).
      await page.waitForFunction(
        (sel) => {
          const h = document.querySelector(sel);
          const field = h?.closest('td, [data-field]') ?? h?.parentElement;
          return !!field?.classList.contains('cell-saved');
        },
        `input[type="hidden"][name="Debt[cells][${rowId}][demand]"]`,
        { timeout: 3_000 },
      );
      // Blur so List's focusout flush can patch this group later —
      // _patchItems SKIPS the group holding DOM focus (see
      // _pendingGroupIds), and fill() leaves focus in the cell; without
      // this the row-removal assertion below can stall on an unpatched DOM.
      await page.evaluate(() => document.activeElement?.blur?.());
    });

    await test.step('assert: server truth AND the board consequence', async () => {
      // Persistence half — what tests/unit cannot cover: the flavor_request
      // row now reflects the edit (poll, stale-window rationale as above).
      // The branch MATTERS: with the fixture flavor's (expected) zero
      // supply, "covered" is reached at wanted=0 — which the route treats
      // as DELETE the override (slots rule again; with no slot
      // designations the pair then has no demand at all and leaves the
      // board via the recompute, not via hide_covered). With any supply,
      // covered/pending is reached at a POSITIVE wanted — an upsert that
      // replaces the row's value in place.
      const pairRows = (d) =>
        (d.flavor_request || []).filter(
          (r) => Number(r.location) === Number(dest.id) && Number(r.flavor) === Number(flavor.id),
        );

      if (WANTED_COVERED === 0) {
        const gone = await pollBundleUntil(page, (d) => pairRows(d).length === 0);
        expect(gone, 'wanted=0 edit should DELETE the override row server-side').toBeTruthy();

        // Board consequence: the pair has no demand left — the row must
        // leave the board once the grid's background domain refresh
        // (scheduled by the autosave) lands.
        await expect
          .poll(() => rowHiddenInput.count(), { timeout: 20_000 })
          .toBe(0);
        await expect(row).toHaveCount(0);
      } else {
        const landed = await pollBundleUntil(
          page,
          (d) => pairRows(d).some((r) => Number(r.wanted) === WANTED_COVERED),
        );
        expect(landed, 'edited wanted never read back from a fresh bundle').toBeTruthy();

        if (supply.inbound === 0) {
          // gap 0 via LOCAL stock alone = covered, and hide_covered defaults
          // ON — the row must leave the board once the autosave's background
          // refresh lands. The override row still exists server-side
          // (asserted above), so absence here is the FILTER working, not
          // data loss.
          await expect
            .poll(() => rowHiddenInput.count(), { timeout: 20_000 })
            .toBe(0);
          await expect(row).toHaveCount(0);
        } else {
          // Inbound earmarks keep the pair owed-0 but pending — visible
          // regardless of hide_covered.
          await expect(row).toContainText('pending', { timeout: 20_000 });
          await expect(wantedNumberInput).toHaveValue(String(WANTED_COVERED));
        }
      }
    });
  } finally {
    await test.step('cleanup: delete the override (wanted=0), poll until gone', async () => {
      // Idempotent on purpose — route delete of a missing pair is a no-op.
      // Runs no matter where the test failed, so a crashed run never leaves
      // phantom demand behind (unlike the sibling suites' batch orphans,
      // a leftover override changes what the board says).
      await postDebtRequests(page, { [rowIdKey]: { wanted: 0 } });
      await pollBundleUntil(
        page,
        (d) =>
          !(d.flavor_request || []).some(
            (r) => Number(r.location) === Number(dest.id) && Number(r.flavor) === Number(flavor.id),
          ),
      );
    });
  }
});

// The negative branch (a canPost:false user gets a non-writeable Wanted
// cell — no TextIt input, number still displayed) is deliberately NOT
// duplicated here: it's pinned model-side in tests/unit/debt-class.test.mjs
// ("Wanted writeability vs server metadata"), and its browser half has its
// own spec — tests/smoke/tests/debt-wanted-readonly.spec.js — which uses
// the suite's second, low-privilege login (SCOOP_TEST_USER_2; the
// ice_cream_maker Debt-view grant that makes it possible is documented in
// includes/_policy.php). This spec covers what only a browser can see of
// the POSITIVE branch: the wire payload, the response handling, the
// flash, and the filtered-board consequence.
