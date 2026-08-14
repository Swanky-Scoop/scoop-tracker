// @ts-check
const { defineConfig, devices } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');

// Tiny inline .env loader — avoids adding a `dotenv` dependency for two
// lines of config (see tests/visual/package.json's own "only Node/npm in
// this repo" note; keep the dependency surface as small as that precedent
// set). Silently no-ops if .env doesn't exist yet (see .env.example).
function loadDotEnv(file) {
  if (!fs.existsSync(file)) return;
  for (const line of fs.readFileSync(file, 'utf8').split('\n')) {
    const m = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*?)\s*$/i);
    if (m && !(m[1] in process.env)) process.env[m[1]] = m[2];
  }
}
loadDotEnv(path.join(__dirname, '.env'));

// Live-instance smoke tests — deliberately separate from tests/visual
// (fixture-based, CI-safe, no login/data mutation). This suite logs into a
// REAL WordPress + Pods stack and writes real data (batches, tubs, slot
// links), so it only ever targets the local dev mirror, never CI, never
// TEST/OPS. See README.md for the full rationale and CLAUDE.md's "Do not
// suggest: connecting directly to remote servers" note — this suite must
// never be pointed at test.swankyscoop.net or ops.swankyscoop.net.
module.exports = defineConfig({
  testDir: './tests',
  // These tests mutate shared local dev data sequentially (create a batch,
  // swap it into a cabinet slot, delete it, restore the slot) — running two
  // workers against the same dev site would race on the same rows.
  workers: 1,
  fullyParallel: false,
  retries: 0,
  reporter: [['html', { open: 'never' }], ['list']],

  use: {
    baseURL: process.env.SCOOP_BASE_URL || 'https://ops.swanky.local',
    // Local site's cert is self-signed (see CLAUDE.md).
    ignoreHTTPSErrors: true,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },

  projects: [
    {
      // The bundled Chromium binary crashes on load against this site in
      // this environment (`page crashed` on the very first navigation,
      // confirmed via a standalone repro before this was added) — system
      // Edge (also Chromium-based) doesn't. If this ever needs to run
      // somewhere without Edge installed, swap back to
      // `devices['Desktop Chrome']` and re-diagnose the crash instead of
      // assuming this comment still applies.
      name: 'edge',
      use: { ...devices['Desktop Edge'], channel: 'msedge' },
    },
  ],
});
