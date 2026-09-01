// @ts-check
// Behavior suite for Find.selectOnFocus() (assets/ui/_find.js) — the
// committed version of the scratch harness that drove the real FindIt /
// FindInList classes in headless Chromium while the select-on-focus change
// was built. It exists because this helper's entire job is fighting browser
// default behavior that unit tests can't see: a focus-gaining click runs
// mousedown → focus → mouseup and the default mouseup caret placement
// collapses a selection made at focus time, and a select() issued while a
// drag gesture is still in progress makes Chromium abandon that gesture.
// Those are control-flow facts of the browser, so they are asserted against
// real controls in a real browser, with each carve-out pinned against a
// no-helper control input (#raw in the fixture) that demonstrates the
// default being fought.
//
// Unlike the screenshot specs in this directory, this spec asserts
// selection/focus STATE, not pixels, and its fixture mounts real ES modules.
// ES modules don't load from file://, so the fixture is served from the repo
// root over 127.0.0.1 by a spec-local server (no port is hardcoded — the OS
// assigns one). No stylesheet is loaded on purpose: state assertions must
// not depend on font/layout metrics, which is also why CI can run this on
// plain ubuntu-latest (the behavior-tests job in unit-tests.yml) instead of
// the digest-pinned Playwright image the screenshot specs need.
const { test, expect } = require('@playwright/test');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');

const REPO_ROOT = path.resolve(__dirname, '..', '..', '..'); // tests/visual/tests -> repo root
const FIXTURE_PATH = '/tests/visual/fixtures/find-select-on-focus.html';
const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript',
};

// Minimal static server over the repo root, so the fixture's
// /assets/ui/*.js imports resolve. Path traversal is refused (normalize +
// prefix check against the root AND its separator — plain startsWith would
// accept a sibling directory named "repo-secret").
function startServer() {
  return new Promise((resolve) => {
    const server = http.createServer((req, res) => {
      const url = new URL(req.url, 'http://127.0.0.1');
      const file = path.normalize(path.join(REPO_ROOT, decodeURIComponent(url.pathname)));
      if (file !== REPO_ROOT && !file.startsWith(REPO_ROOT + path.sep)) {
        res.writeHead(403).end();
        return;
      }
      fs.readFile(file, (err, data) => {
        if (err) { res.writeHead(404).end('nf ' + file + ' [' + err.code + '] root=' + REPO_ROOT); return; }
        res.writeHead(200, { 'Content-Type': MIME[path.extname(file)] || 'application/octet-stream' });
        res.end(data);
      });
    });
    server.listen(0, '127.0.0.1', () => resolve(server));
  });
}

let server;
let base;
test.beforeAll(async () => {
  server = await startServer();
  base = `http://127.0.0.1:${server.address().port}`;
});
test.afterAll(async () => {
  await new Promise((resolve) => server.close(resolve));
});

// The helper's mouse path defers to a setTimeout(0) after mouseup, so a
// selection assertion made synchronously after a click reads the pre-defer
// state. Every mouse-path test settles through that window first.
const SETTLE_MS = 60;

async function openFixture(page) {
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  const resp = await page.goto(`${base}${FIXTURE_PATH}`);
  try {
    await page.waitForFunction(() => window.ready === true, undefined, { timeout: 8000 });
  } catch (e) {
    // Module-load failures (a broken /assets import) never set window.ready
    // and usually surface only as a console error, so say WHY the fixture
    // never became ready instead of timing out opaquely.
    throw new Error(
      `fixture never became ready (base=${base}, goto=${resp && resp.status()}, ready=${await page.evaluate(() => String(window.ready)).catch((er) => 'evalfail ' + er)})`
    );
  }
  return errors;
}

// [selectionStart, selectionEnd, valueLength, isFocused] of the control
// under test. `which` is the fixture's global: findit / fil, or "raw" (the
// plain control input). Asserting focus LANDED next to every selection
// assertion is deliberate — a stale selection on an unfocused input once
// faked a pass in the scratch harness (blur via body.focus() doesn't move
// focus in Chromium).
const selState = (page, which) => page.evaluate((w) => {
  const el = w === 'fil' ? window.fil.inp : (window[w].INP ?? window[w]);
  return [el.selectionStart, el.selectionEnd, el.value.length, document.activeElement === el];
}, which);

const blurAll = (page) => page.evaluate(() => document.activeElement?.blur?.());

// A drag across a SUB-range of the value — geometry chosen so the same
// gesture on "Vanilla" (7 chars) or "Chocolate" (9 chars) cannot span the
// whole input, keeping "user's range" distinguishable from "select-all stomp".
async function dragSubRange(page, locator) {
  const box = await locator.boundingBox();
  await page.mouse.move(box.x + 3, box.y + box.height / 2);
  await page.mouse.down();
  await page.mouse.move(box.x + 20, box.y + box.height / 2, { steps: 5 });
  await page.mouse.up();
  await page.waitForTimeout(SETTLE_MS);
}

test.describe('Find.selectOnFocus @behavior', () => {
  test('control input shows the browser defaults the helper exists to defeat', async ({ page }) => {
    const errors = await openFixture(page);

    // Default 1: a focus-gaining click collapses the selection to a caret —
    // the race the helper's deferred mouseup re-select overcomes.
    await blurAll(page);
    await page.locator('#raw').click();
    await page.waitForTimeout(SETTLE_MS);
    let s = await selState(page, 'raw');
    expect(s[3], 'click focuses the control input').toBe(true);
    expect(s[0], `browser default collapses selection (${s})`).toBe(s[1]);

    // Default 2: a drag across the value selects the user's sub-range —
    // the gesture the helper must NOT stomp with a select-all.
    await blurAll(page);
    await dragSubRange(page, page.locator('#raw'));
    s = await selState(page, 'raw');
    expect(s[0], `default drag selects a sub-range (${s})`).not.toBe(s[1]);
    expect(s[1], `default drag range is partial, not the whole value (${s})`).toBeLessThan(s[2]);

    expect(errors, errors.join('; ')).toEqual([]);
  });

  test('FindIt: click selects all, second click keeps caret placement, typing replaces the value', async ({ page }) => {
    const errors = await openFixture(page);
    const inp = page.locator('#a input[type=text]');

    // The headline flow: click the cell, type — the whole value is replaced.
    await blurAll(page);
    await inp.click();
    await page.waitForTimeout(SETTLE_MS);
    let s = await selState(page, 'findit');
    expect(s[3], 'click focuses the FindIt input').toBe(true);
    expect(s[0], `click selects all ("Vanilla" got ${s[0]}..${s[1]})`).toBe(0);
    expect(s[1], `click selects all ("Vanilla" got ${s[0]}..${s[1]})`).toBe(s[2]);

    // A click on an ALREADY-focused input is an editing gesture — the
    // browser's caret placement must win, no select-all stomp.
    await inp.click({ position: { x: 20, y: 6 } });
    await page.waitForTimeout(SETTLE_MS);
    s = await selState(page, 'findit');
    expect(s[0], `second click keeps the caret (got ${s[0]}..${s[1]})`).toBe(s[1]);

    // The headline flow, on a fresh focus-gaining click (select-all state):
    // click the cell, type — the whole value is replaced.
    await blurAll(page);
    await inp.click();
    await page.waitForTimeout(SETTLE_MS);
    await page.keyboard.type('Str');
    await expect(inp).toHaveValue('Str');

    expect(errors, errors.join('; ')).toEqual([]);
  });

  test('FindIt: dragging out a sub-range on an unfocused input keeps the user range', async ({ page }) => {
    const errors = await openFixture(page);
    const inp = page.locator('#a input[type=text]');

    await blurAll(page);
    await dragSubRange(page, inp);
    const s = await selState(page, 'findit');
    expect(s[3], 'drag focuses the FindIt input').toBe(true);
    expect(s[0], `drag keeps the user's sub-range, not select-all (got ${s[0]}..${s[1]} of ${s[2]})`).not.toBe(s[1]);
    expect(s[1], `drag keeps the user's sub-range, not select-all (got ${s[0]}..${s[1]} of ${s[2]})`).toBeLessThan(s[2]);

    expect(errors, errors.join('; ')).toEqual([]);
  });

  test('FindIt: Tab and programmatic focus select all', async ({ page }) => {
    const errors = await openFixture(page);

    // Tab is driven from the fixture's #first anchor: Chromium resumes
    // sequential navigation from the last CLICKED element after a blur(),
    // so tabbing "from the body" is nondeterministic. Asserting focus
    // landed (selState[3]) keeps a stale selection from faking the pass.
    await blurAll(page);
    await page.evaluate(() => document.getElementById('first').focus());
    await page.keyboard.press('Tab'); // -> FindIt INP (next tabbable in DOM order)
    await page.waitForTimeout(SETTLE_MS);
    let s = await selState(page, 'findit');
    expect(s[3], 'Tab focuses the FindIt input').toBe(true);
    expect(s[0], `Tab selects all (got ${s[0]}..${s[1]})`).toBe(0);
    expect(s[1], `Tab selects all (got ${s[0]}..${s[1]})`).toBe(s[2]);

    // Programmatic .focus() — the post-save refocus-to-filter path.
    await blurAll(page);
    await page.evaluate(() => window.findit.INP.focus());
    await page.waitForTimeout(SETTLE_MS);
    s = await selState(page, 'findit');
    expect(s[3], '.focus() focuses the FindIt input').toBe(true);
    expect(s[0], `.focus() selects all (got ${s[0]}..${s[1]})`).toBe(0);
    expect(s[1], `.focus() selects all (got ${s[0]}..${s[1]})`).toBe(s[2]);

    expect(errors, errors.join('; ')).toEqual([]);
  });

  test('FindIt: double-click on an unfocused input still grabs one word', async ({ page }) => {
    const errors = await openFixture(page);

    // Multi-word value so "one word" is distinguishable from "all": the
    // first mouseup of the double-click queues a re-select; if the helper
    // fails to cancel it on the second press, it fires after the dblclick
    // and stomps the word selection with select-all.
    await page.evaluate(() => { window.findit.INP.value = 'Vanilla Bean'; });
    await blurAll(page);
    await page.locator('#a input[type=text]').dblclick({ position: { x: 14, y: 6 } });
    await page.waitForTimeout(SETTLE_MS);
    const s = await selState(page, 'findit');
    expect(s[0], `dblclick word selection starts at 0 (got ${s[0]}..${s[1]} of ${s[2]})`).toBe(0);
    expect(s[1], `dblclick keeps the word selection, not select-all (got ${s[0]}..${s[1]} of ${s[2]})`).toBeGreaterThan(0);
    expect(s[1], `dblclick keeps the word selection, not select-all (got ${s[0]}..${s[1]} of ${s[2]})`).toBeLessThan(s[2]);

    expect(errors, errors.join('; ')).toEqual([]);
  });

  test('FindInList: click/Tab select all, second click keeps caret, focus after clear() is a no-op', async ({ page }) => {
    const errors = await openFixture(page);
    const inp = page.locator('#b input.gridFilterInput');

    await blurAll(page);
    await inp.click();
    await page.waitForTimeout(SETTLE_MS);
    let s = await selState(page, 'fil');
    expect(s[3], 'click focuses the FindInList input').toBe(true);
    expect(s[0], `click selects all ("choco" got ${s[0]}..${s[1]})`).toBe(0);
    expect(s[1], `click selects all ("choco" got ${s[0]}..${s[1]})`).toBe(s[2]);

    await inp.click({ position: { x: 15, y: 6 } });
    await page.waitForTimeout(SETTLE_MS);
    s = await selState(page, 'fil');
    expect(s[0], `second click keeps the caret (got ${s[0]}..${s[1]})`).toBe(s[1]);

    // Tab from the FindIt input (already focused from nothing — drive the
    // keyboard from the anchor and step through both controls) so the
    // keyboard path is covered for the second consumer too.
    await blurAll(page);
    await page.evaluate(() => document.getElementById('first').focus());
    await page.keyboard.press('Tab'); // -> FindIt INP
    await page.keyboard.press('Tab'); // -> FindInList inp
    await page.waitForTimeout(SETTLE_MS);
    s = await selState(page, 'fil');
    expect(s[3], 'Tab focuses the FindInList input').toBe(true);
    expect(s[0], `Tab selects all (got ${s[0]}..${s[1]})`).toBe(0);
    expect(s[1], `Tab selects all (got ${s[0]}..${s[1]})`).toBe(s[2]);

    // The _list.js post-save path: clear() then .focus() on the empty input
    // — select-all must be a clean no-op there, and the filter keeps all
    // rows visible.
    await page.evaluate(() => { window.fil.clear(); window.fil.inp.focus(); });
    await page.waitForTimeout(SETTLE_MS);
    s = await selState(page, 'fil');
    expect(s[0], `focus after clear() is a clean no-op (got ${s[0]}..${s[1]})`).toBe(0);
    expect(s[1], `focus after clear() is a clean no-op (got ${s[0]}..${s[1]})`).toBe(0);

    expect(errors, errors.join('; ')).toEqual([]);
  });
});
