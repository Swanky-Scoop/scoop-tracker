// @ts-check
const { test, expect } = require('@playwright/test');
const path = require('node:path');

const FIXTURE = 'file://' + path.resolve(__dirname, '../fixtures/batch-popup.html');

// desktop: --dimension-batch-grid-width sits at its flat 30rem ceiling.
// phone-narrow: crosses the calc(100vw - 1rem) branch of that same
// variable — this is the exact width where a real bug shipped once
// before (the popup overflowing past the screen edge on a 380px phone).
const VIEWPORTS = {
  desktop: { width: 1920, height: 1080 },
  'phone-narrow': { width: 350, height: 800 },
};

for (const [name, size] of Object.entries(VIEWPORTS)) {
  test(`batch popup — ${name}`, async ({ page }) => {
    await page.setViewportSize(size);
    await page.goto(FIXTURE);
    await expect(page).toHaveScreenshot(`batch-popup-${name}.png`);
  });
}
