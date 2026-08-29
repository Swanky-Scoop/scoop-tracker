// @ts-check
/**
 * DIAGNOSTIC spec — swap-write mystery (tests/smoke README "Not yet resolved").
 *
 * Reproduces the lifecycle's step-3 commit (POST /wp-json/scoop/v1/tubs with
 * { state, slot } for a KNOWN tub id, which reports 200/ok:true) and captures
 * the evidence the README said was missing, in one process, in order:
 *
 *   A. the FULL JSON body of the write response (not just ok)
 *   B. an immediate in-page GET of the same tub (cache-busted) — did the
 *      server's own read path see the write?
 *   C. a hard reload (#bust) + domain read — what the UI actually gets
 *   D. a field bisect: state-only write vs slot-only write vs both,
 *      each followed by its own immediate read-back
 *
 * Runs against the slot's CURRENT occupant (looked up dynamically, same as
 * lifecycle step 2), records its original state/slot/current_flavor, and
 * RESTORES them in a finally block via the same REST shape. No batch is
 * created — nothing is deleted — the only mutations are to one tub's own
 * state/slot and the slot's current_flavor, restored afterward.
 *
 * Output: console.log lines prefixed [diag] — paste them back to the agent.
 */
const { test, expect } = require('@playwright/test');

const TARGET_SLOT_TITLE = process.env.SMOKE_TARGET_SLOT || 'Woodinville_dairy_18|1';
const DOCK_PATH = '/dock/';

test.describe.configure({ mode: 'serial' });

async function login(page) {
  await page.goto('/wp-login.php');
  await page.locator('#user_login').fill(process.env.SCOOP_TEST_USER || '');
  await page.locator('#user_pass').fill(process.env.SCOOP_TEST_PASS || '');
  await page.getByRole('button', { name: 'Log In' }).click();
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

async function freshDomain(page) {
  const url = new URL(page.url());
  url.hash = 'bust' + Date.now();
  await page.goto(url.toString());
  await waitForDomain(page);
  return getDomain(page);
}

/**
 * The core instrumented write, parameterized by which fields to send.
 * Returns { status, body } of the RAW response — full body captured, ok
 * flag NOT trusted, nothing asserted inside.
 */
async function writeTub(page, tubId, fields) {
  return page.evaluate(async ({ tubId, fields }) => {
    const host = document.querySelector('.scoop-grid[data-grid-type]');
    const nonce = window.SCOOP.nonce;
    const res = await fetch('/wp-json/scoop/v1/tubs', {
      method: 'POST',
      headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify({ FlavorTub: { cells: { [tubId]: fields } } }),
    });
    let body = null;
    const text = await res.text();
    try { body = JSON.parse(text); } catch { body = text.slice(0, 2000); }
    return { status: res.status, body };
  }, { tubId, fields });
}

/** Immediate in-page GET of one tub's row, cache-busted. */
async function readTubRaw(page, tubId) {
  return page.evaluate(async (tubId) => {
    const res = await fetch(`/wp-json/scoop/v1/tubs?cachebust=${Date.now()}`, {
      headers: { 'X-WP-Nonce': window.SCOOP.nonce },
      credentials: 'include',
    });
    const text = await res.text();
    let body = null;
    try { body = JSON.parse(text); } catch { return { status: res.status, parseError: text.slice(0, 500) }; }
    // Route shape unknown-ish: search every plausible container for our id.
    const found = JSON.stringify(body).includes(`"${tubId}"`);
    return { status: res.status, bodyTopKeys: Object.keys(body || {}), idPresent: found, body };
  }, tubId);
}

test('swap-write diagnosis: instrumented bisect of the silent write loss', async ({ page }) => {
  test.setTimeout(180_000);
  const log = (...a) => console.log('[diag]', ...a);

  await login(page);
  await page.addInitScript(() => {
    window.confirm = () => true;
    window.alert = () => {};
    document.addEventListener('DOMContentLoaded', () => {
      document.body.classList.add('no-animations');
    });
  });
  await page.goto(DOCK_PATH);

  // ── Record the current occupant + original values ──────────────────────
  const domain0 = await getDomain(page);
  const slot = (domain0.slot || domain0.planning || []).find((s) => s._title === TARGET_SLOT_TITLE)
    || Object.values(domain0).flat().find((x) => x && x._title === TARGET_SLOT_TITLE);
  expect(slot, `slot "${TARGET_SLOT_TITLE}" found in domain (top-level keys: ${Object.keys(domain0).join(',')})`).toBeTruthy();
  const slotId = slot.id;
  const origFlavor = slot.current_flavor;

  const occ = (domain0.tub || []).find((t) => String(t.slot) === String(slotId));
  expect(occ, `slot ${slotId} has an occupant tub`).toBeTruthy();
  const occId = occ.id;
  const origState = occ.state;
  log(`target slot ${slotId} (${TARGET_SLOT_TITLE}), occupant tub ${occId}, original state=${origState}, slot.current_flavor=${origFlavor}`);

  let restored = false;
  const restore = async () => {
    if (restored) return;
    restored = true;
    const r1 = await writeTub(page, occId, { state: origState, slot: slotId });
    const r2 = await page.evaluate(async ({ slotId, origFlavor }) => {
      const res = await fetch('/wp-json/scoop/v1/planning', {
        method: 'POST',
        headers: { 'X-WP-Nonce': window.SCOOP.nonce, 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({ Cabinet: { cells: { [slotId]: { current_flavor: origFlavor } } } }),
      });
      return { status: res.status, body: await res.json().catch(() => null) };
    }, { slotId, origFlavor });
    log(`RESTORE tub write: ${r1.status}`, JSON.stringify(r1.body)?.slice(0, 300));
    log(`RESTORE cabinet write: ${r2.status}`, JSON.stringify(r2.body)?.slice(0, 300));
  };
  test.onDidFail && test.afterEach(restore);

  // ── A. exact reproduction of the lifecycle's step-3 write ─────────────
  log('── A. exact step-3 write: state=Opened + slot ──');
  const wA = await writeTub(page, occId, { state: 'Opened', slot: slotId });
  log('A write status:', wA.status, 'FULL BODY:', JSON.stringify(wA.body));

  // ── B. immediate in-page GET ────────────────────────────────────────────
  log('── B. immediate raw GET ──');
  const gB = await readTubRaw(page, occId);
  log('B GET status:', gB.status, 'top keys:', JSON.stringify(gB.bodyTopKeys), 'idPresent:', gB.idPresent);
  if (gB.body) log('B GET body (truncated):', JSON.stringify(gB.body).slice(0, 1200));

  // ── C. hard-reload domain read ──────────────────────────────────────────
  log('── C. hard reload (#bust) domain read ──');
  const dC = await freshDomain(page);
  const tubC = (dC.tub || []).find((t) => Number(t.id) === Number(occId));
  const slotC = (dC.slot || dC.planning || []).find((s) => Number(s.id) === Number(slotId));
  log(`C domain: tub.state=${tubC?.state} tub.slot=${tubC?.slot} slot.current_flavor=${slotC?.current_flavor}`);
  log(`C EXPECTED: tub.state=Opened tub.slot=${slotId}`);

  // ── D. field bisect (each write gets its own fresh reload read) ────────
  const probe = async (label, fields) => {
    const w = await writeTub(page, occId, fields);
    const d = await freshDomain(page);
    const t = (d.tub || []).find((x) => Number(x.id) === Number(occId));
    log(`── D/${label}: sent ${JSON.stringify(fields)}`);
    log(`D/${label} write status: ${w.status} body: ${JSON.stringify(w.body)?.slice(0, 300)}`);
    log(`D/${label} after reload: state=${t?.state} slot=${t?.slot}`);
    return { w, state: t?.state, slot: t?.slot };
  };
  await probe('state-only', { state: 'Opened' });
  await probe('slot-only', { slot: slotId });
  await probe('both-again', { state: 'Opened', slot: slotId });

  // Restore no matter what.
  await restore();

  // Final confirmation read of the restored state.
  const dFinal = await freshDomain(page);
  const tF = (dFinal.tub || []).find((x) => Number(x.id) === Number(occId));
  const sF = (dFinal.slot || dFinal.planning || []).find((s) => Number(s.id) === Number(slotId));
  log(`FINAL after restore: tub.state=${tF?.state} (want ${origState}) tub.slot=${tF?.slot} slot.current_flavor=${sF?.current_flavor} (want ${origFlavor})`);
});
