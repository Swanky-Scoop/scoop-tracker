// @ts-check
const { test, expect } = require('@playwright/test');
const path = require('node:path');

const FIXTURE = 'file://' + path.resolve(__dirname, '../fixtures/docked-view.html');

// Viewport widths chosen to straddle every breakpoint this fixture's CSS
// actually keys off, not just arbitrary device sizes — see css.css for
// each:
//   1920 — desktop: canvas control grows toward its 40rem ceiling, aside
//          sits at --dock-aside-width (37.5%).
//   900  — canvas control near/at its 30rem floor; still well above every
//          container-query column-drop threshold.
//   500  — crosses FlavorTub's 38rem (Updated) and 32rem (Amount/Editor)
//          @container drops, and Cabinet's 28rem (Next Flavor) drop.
//   350  — crosses .canvas's own 34rem gutter-collapse and <aside>'s
//          32rem take-over-100%-width breakpoint.
const VIEWPORTS = {
  desktop: { width: 1920, height: 1080 },
  tablet: { width: 900, height: 900 },
  phone: { width: 500, height: 900 },
  'phone-narrow': { width: 350, height: 800 },
};

for (const [name, size] of Object.entries(VIEWPORTS)) {
  test(`docked view — ${name}`, async ({ page }) => {
    await page.setViewportSize(size);
    await page.goto(FIXTURE);
    await expect(page).toHaveScreenshot(`docked-view-${name}.png`);
  });
}
