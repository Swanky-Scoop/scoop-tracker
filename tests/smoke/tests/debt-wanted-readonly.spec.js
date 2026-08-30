// @ts-check
const { test, expect } = require('@playwright/test');

// ── Fixture config ──────────────────────────────────────────────────────
// The SAME fixture flavor + destination as debt-wanted-edit.spec.js (the
// two specs' overrides collide on one (location, flavor) pair, so they
// must never run concurrently — workers:1 in playwright.config.js covers
// this, and each spec's `finally` cleanup leaves no override behind for
// the other). Mirror constants are an orphan-safe copy of that spec's —
// see its header comment for why these are hand-kept mirrors, not shared
// imports (tests/smoke has no shared-module convention and both files are
// self-contained by design).
const FIXTURE_FLAVOR_TITLE = process.env.SMOKE_DEBT_FLAVOR || 'zz__flavor debt test___';
const DESTINATION_TITLE = process.env.SMOKE_DEBT_LOCATION || 'Mountlake Terrace';
const DOCK_PATH = '/dock/';

const WHOLE_TUB_THRESHOLD = 0.8;
const DEAD_STATES = new Set(['Emptied', '!Lost']);
const OPEN_STATE = 'Opened';
const FRONT_OF_HOUSE_USE_ID = 1863;

// The second login — a LOW-PRIVILEGE account whose role resolves to Debt
// GET:true but NO Debt POST key (ice_cream_maker as of the 2026-08-30
// policy change; see .env.example + includes/_policy.php). Absent creds
// skip the whole spec: the negative branch is a real-roles capability,
// there is nothing to stub it with (that's the point of exercising it in
// a browser at all).
const LOW_USER = process.env.SCOOP_TEST_USER_2 || '';
const LOW_PASS = process.env.SCOOP_TEST_PASS_2 || '';

test.describe.configure({ mode: 'serial' });

test.skip(!LOW_USER || !LOW_PASS, 'SCOOP_TEST_USER_2 / SCOOP_TEST_PASS_2 not set — a low-privilege (Debt view-only) login is required; see .env.example for the one-time wp user create.');

/** The Debt board's synthetic numeric row id — mirrors debtRowId() (location*100000+flavor). */
const debtRowId = (locationId, flavorId) => Number(locationId) * 100000 + Number(flavorId);

/**
 * Direct REST call to /debt-requests on the GIVEN page's session. Unlike
 * debt-wanted-edit.spec.js's postDebtRequests (which asserts ok:true,
 * because that spec only ever writes as a user who is ALLOWED to), this
 * returns the raw { status, body } — this spec's whole subject is a user
 * the route must REFUSE, so the assertions live at the call sites.
 */
async function debtRequest(page, cells) {
  const nonce = await page.evaluate(() => window.SCOOP.nonce);
  return page.evaluate(
    async ({ cells, nonce }) => {
      const res = await fetch('/wp-json/scoop/v1/debt-requests', {
        method: 'POST',
        headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({ Debt: { cells, source: 'smoke-test-readonly' } }),
      });
      const body = await res.json().catch(() => null);
      return { status: res.status, body };
    },
    { cells, nonce },
  );
}

/**
 * Full domain via a direct, cache-bypassing REST call — NOT the browser's
 * in-page domain snapshot (documented transient stale-read race after a
 * write — see debt-wanted-edit.spec.js's fetchFreshBundle). Uses the same
 * CONFIG type 'Debt' expansion (slot, tub, flavor, use, location,
 * flavor_request).
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
 * flavor) pair — the test oracle (same in-spec mirror as
 * debt-wanted-edit.spec.js's supplyForPair, kept in sync by hand).
 */
function supplyForPair(data, destId, flavorId, isFoh) {
  const tubs = Array.isArray(data.tub) ? data.tub : [];
  let onHand = 0;
  let inbound = 0;
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
  }
  return { onHand, inbound };
}

test('debt: a canPost:false user sees the Wanted column read-only and the route refuses their write', async ({ browser }) => {
  test.setTimeout(120_000);

  // Loud precondition instead of a 20s login timeout: the admin login is
  // what drives fixture setup — a missing admin credential is a setup
  // gap, not a test failure.
  expect(process.env.SCOOP_TEST_USER, 'SCOOP_TEST_USER not set — the admin login drives fixture setup (see .env.example)').toBeTruthy();
  expect(process.env.SCOOP_TEST_PASS, 'SCOOP_TEST_PASS not set — the admin login drives fixture setup (see .env.example)').toBeTruthy();

  // ── Admin session (context #1): fixture setup/cleanup rides the same
  //    direct-REST pattern as debt-wanted-edit — setup and assertions ride
  //    server truth; the NEGATIVE branch under test happens entirely in
  //    the low-privilege session. ───────────────────────────────────────
  const adminContext = await browser.newContext();
  const adminPage = await adminContext.newPage();
  await adminPage.goto('/wp-login.php');
  await adminPage.locator('#user_login').fill(process.env.SCOOP_TEST_USER || '');
  await adminPage.locator('#user_pass').fill(process.env.SCOOP_TEST_PASS || '');
  await adminPage.getByRole('button', { name: 'Log In' }).click();
  await adminPage.addInitScript(() => {
    window.confirm = () => true;
    window.alert = () => {};
  });
  await adminPage.goto(DOCK_PATH);
  await adminPage.waitForFunction(() => {
    const host = document.querySelector('.scoop-grid[data-grid-type]');
    return Array.isArray(host?._dockListInstance?.api?.getDomainSnapshot?.()?.flavor);
  }, { timeout: 20_000 });

  // ── Fixture lookup + preconditions (read once from the admin session;
  //    both sessions then work from these ids) ──────────────────────────
  const adminData = await fetchFreshBundle(adminPage);

  const flavor = (adminData.flavor || []).find((f) => f._title === FIXTURE_FLAVOR_TITLE);
  expect(
    flavor,
    `Fixture flavor "${FIXTURE_FLAVOR_TITLE}" not found — seed it first (pods_api()->save_pod_item(['pod'=>'flavor','data'=>['post_title'=>'${FIXTURE_FLAVOR_TITLE}']])).`,
  ).toBeTruthy();

  const dest = (adminData.location || []).find((l) => l._title === DESTINATION_TITLE);
  expect(dest, `Destination location "${DESTINATION_TITLE}" not found.`).toBeTruthy();

  // FOH classification mirror — isFrontOfHouseUse(): id 1863, absent use
  // (defaults FOH), or the normalized-label fallback.
  const useTitleById = new Map(
    (adminData.use || []).map((u) => [Number(u.id), u._title || u.title?.rendered || '']),
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

  // Precondition — same loud failure as debt-wanted-edit: a slot's
  // current/immediate_flavor creates demand the override can't delete
  // (wanted=0 just deletes the OVERRIDE; slot demand remains), which would
  // leave the pair permanently on the board and muddle the assertions.
  const slotImplied = (adminData.slot || []).filter(
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
  const pairRows = (d) =>
    (d.flavor_request || []).filter(
      (r) => Number(r.location) === Number(dest.id) && Number(r.flavor) === Number(flavor.id),
    );

  // Choose Wanted from live supply (same arithmetic as debt-wanted-edit):
  // one with gap ≥ 1 — a row that is VISIBLE on every account, which is
  // what the read-only render assertions need.
  const supply = supplyForPair(adminData, dest.id, flavor.id, isFoh);
  const WANTED_VISIBLE = supply.onHand + supply.inbound + 1;
  // The low-privilege probe writes a DIFFERENT value than the minted one,
  // so a probe that (regressively) SUCCEEDS is visible in server truth.
  const WANTED_PROBE = WANTED_VISIBLE + 1;

  // ── Low-privilege session (context #2): the subject of this spec. ────
  const lowContext = await browser.newContext();
  const lowPage = await lowContext.newPage();

  try {
    await test.step('self-heal + setup (admin): mint the demand override via /debt-requests', async () => {
      // Self-heal: a prior run that died mid-test (either debt spec — same
      // fixture pair) leaves its override behind. wanted=0 deletes
      // idempotently.
      if (pairRows(adminData).length > 0) {
        const heal = await debtRequest(adminPage, { [rowIdKey]: { wanted: 0 } });
        expect(heal.status, `stale-override delete: ${JSON.stringify(heal.body)}`).toBe(200);
        const healed = await pollBundleUntil(adminPage, (d) => pairRows(d).length === 0);
        expect(healed, 'stale override row from a prior run could not be deleted').toBeTruthy();
      }

      const body = await debtRequest(adminPage, { [rowIdKey]: { wanted: WANTED_VISIBLE } });
      expect(body.status, `POST /debt-requests: ${JSON.stringify(body.body)}`).toBe(200);
      expect(body.body?.ok, `POST /debt-requests ok=false: ${JSON.stringify(body.body)}`).toBe(true);
      expect(
        body.body?.updated?.[rowIdKey]?.wanted,
        `route should report the pair updated: ${JSON.stringify(body.body?.updated)}`,
      ).toBe(WANTED_VISIBLE);

      const landed = await pollBundleUntil(
        adminPage,
        (d) => pairRows(d).some((r) => Number(r.wanted) === WANTED_VISIBLE),
      );
      expect(landed, 'minted override never read back from a fresh bundle').toBeTruthy();
    });

    await test.step('log in as the low-privilege user; server metadata says canPost:false', async () => {
      await lowPage.goto('/wp-login.php');
      await lowPage.locator('#user_login').fill(LOW_USER);
      await lowPage.locator('#user_pass').fill(LOW_PASS);
      await lowPage.getByRole('button', { name: 'Log In' }).click();
      await lowPage.addInitScript(() => {
        window.confirm = () => true;
        window.alert = () => {};
      });
      await lowPage.goto(DOCK_PATH);
      await lowPage.waitForFunction(() => {
        const host = document.querySelector('.scoop-grid[data-grid-type]');
        return Array.isArray(host?._dockListInstance?.api?.getDomainSnapshot?.()?.flavor);
      }, { timeout: 20_000 });

      // metaData.Debt is resolved SERVER-side per current user
      // (scoop_client_metadata()'s canPost — the same scoop_user_can_route
      // check the route's permission callback runs) and inlined into the
      // page. canPost:false here is the premise the rest of the test
      // pins: if a policy change regresses this, fail HERE, at the cause,
      // not downstream at the render assertions.
      const md = await lowPage.evaluate(() => window.SCOOP?.metaData?.Debt ?? null);
      expect(md, 'Debt metadata missing for the low-privilege user — is SCOOP.metaData served at all?').toBeTruthy();
      expect(
        md.canPost,
        `metaData.Debt.canPost should be false for this login (got ${JSON.stringify(md.canPost)}) — the account's role has a Debt POST grant, so this is not the negative branch. Fix the .env login's role or the policy.`,
      ).toBe(false);
    });

    await test.step('the Wanted cell renders read-only: number visible, no write control', async () => {
      // True reload is unnecessary here (the mint happened in the OTHER
      // session, and this page loaded after it) — but the grid itself is
      // toggled shut by default; open the Debt board.
      await lowPage.evaluate((type) => {
        const host = document.querySelector(`.scoop-grid[data-grid-type="${type}"]`);
        if (!host) throw new Error(`No grid host for type "${type}"`);
        const inst = host._dockListInstance;
        if (!host.classList.contains('toggled')) inst.TOGGLE.click();
      }, 'Debt');

      // The row renders once the grid's own bundle fetch lands. Anchor by
      // the group row (data-row-id = the destination, set by buildGroupDom)
      // — the row locator can NOT use the hidden-input anchor
      // debt-wanted-edit uses, because the hidden input's ABSENCE is
      // precisely what this spec asserts.
      const groupRow = lowPage.locator(
        `.scoop-grid[data-grid-type="Debt"] tr.group[data-row-id="${dest.id}"]`,
      );
      const row = lowPage
        .locator(`.scoop-grid[data-grid-type="Debt"] tr[data-row-id]:not(.group)`)
        .filter({ hasText: FIXTURE_FLAVOR_TITLE });
      await expect(groupRow).toBeVisible({ timeout: 20_000 });
      await expect(row).toBeVisible({ timeout: 20_000 });

      // Read-only render (see _renderFieldValue's `col.write && d.write !==
      // false` branch — d.write is false here, so the cell falls to the
      // read-only branch): the number STILL displays (model pins this —
      // tests/unit/debt-class.test.mjs "the number still displays"), but
      // there is no TextIt control — no number input, and no hidden input
      // carrying the autosave field name.
      const demandCell = row.locator('td.col-demand');
      await expect(demandCell).toContainText(String(WANTED_VISIBLE));
      await expect(row.locator('td.col-demand input[type="number"]')).toHaveCount(0);
      await expect(
        row.locator(`input[type="hidden"][name='Debt[cells][${rowId}][demand]']`),
      ).toHaveCount(0);
      await expect(demandCell).toHaveClass(/read-only/);
    });

    await test.step('the server refuses the low-privilege write (403, nothing written)', async () => {
      // Direct route probe on the low-privilege session. This is the
      // browser-side half the unit suite cannot pin: the metadata the
      // cell rendered from is not merely advisory — the route itself
      // refuses (scoop_write_permission('Debt') runs the same
      // scoop_user_can_route check that produced canPost:false, so REST's
      // permission_callback answers 403 rest_forbidden before the
      // handler runs).
      const result = await debtRequest(lowPage, { [rowIdKey]: { wanted: WANTED_PROBE } });
      expect(
        result.status,
        `/debt-requests should refuse a canPost:false user; got ${JSON.stringify(result.body)}`,
      ).toBe(403);
      expect(result.body?.code, `refusal should be WP's rest_forbidden: ${JSON.stringify(result.body)}`).toBe(
        'rest_forbidden',
      );

      // The probe value must NOT have landed. Probed at WANTED_VISIBLE+1
      // so a regressively-successful write is distinguishable in server
      // truth from the minted override (and — belt and suspenders — the
      // finally cleanup deletes the pair regardless).
      const untouched = await pollBundleUntil(
        adminPage,
        (d) => !pairRows(d).some((r) => Number(r.wanted) === WANTED_PROBE),
      );
      expect(untouched, 'a refused write must not change the override row').toBeTruthy();
    });

    await test.step('cross-user truth: the admin session still sees exactly the minted override', async () => {
      const data = await fetchFreshBundle(adminPage);
      const rows = pairRows(data);
      expect(rows).toHaveLength(1);
      expect(Number(rows[0].wanted)).toBe(WANTED_VISIBLE);
    });
  } finally {
    await test.step('cleanup (admin): delete the override (wanted=0), poll until gone', async () => {
      // Idempotent on purpose — route delete of a missing pair is a no-op.
      // Runs no matter where the test failed, so a crashed run never
      // leaves phantom demand behind (same discipline as debt-wanted-edit).
      const body = await debtRequest(adminPage, { [rowIdKey]: { wanted: 0 } });
      expect(body.status, `cleanup delete: ${JSON.stringify(body.body)}`).toBe(200);
      expect(body.body?.ok, `cleanup delete ok=false: ${JSON.stringify(body.body)}`).toBe(true);
      await pollBundleUntil(adminPage, (d) => pairRows(d).length === 0);
      await lowContext.close();
      await adminContext.close();
    });
  }
});
