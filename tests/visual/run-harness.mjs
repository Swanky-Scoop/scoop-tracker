import { chromium } from '@playwright/test';

const browser = await chromium.launch();
const page = await browser.newPage();
const errors = [];
page.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
page.on('pageerror', e => errors.push(String(e)));

await page.goto('http://localhost:8123/harness.html');
await page.waitForFunction(() => window.__HARNESS, { timeout: 20000 });
const h = await page.evaluate(() => window.__HARNESS);
console.log('HARNESS RESULT:', JSON.stringify(h, null, 2));
if (errors.length) console.log('CONSOLE ERRORS:\n' + errors.slice(0, 12).join('\n'));
await browser.close();
