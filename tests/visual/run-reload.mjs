import { chromium } from '@playwright/test';
const browser = await chromium.launch();
const page = await browser.newPage();
let navigations = [];
page.on('framenavigated', f => { if (f === page.mainFrame()) navigations.push(f.url()); });
await page.goto('http://localhost:8123/harness-reload.html');
await page.waitForTimeout(3000);
console.log('NAVIGATIONS:', JSON.stringify(navigations, null, 2));
await browser.close();
