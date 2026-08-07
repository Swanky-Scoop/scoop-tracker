// @ts-check
const { defineConfig, devices } = require('@playwright/test');

// Visual regression tests for assets/css.css, run against static HTML
// fixtures (see fixtures/*.html) rather than a live WordPress instance —
// see tests/visual/README.md for why. Intentionally lives outside the
// plugin's own build-step-free world (CLAUDE.md): this whole tests/visual
// directory is the only place Node/npm show up in this repo, and none of
// it is deployed (see .github/workflows/deploy.yml's own file list).
module.exports = defineConfig({
  testDir: './tests',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  // Local retries hide flakiness you actually want to see; CI gets a couple
  // to absorb rare font/GPU-rasterization jitter without masking real diffs.
  retries: process.env.CI ? 2 : 0,
  reporter: process.env.CI
    ? [['html', { open: 'never' }], ['github']]
    : [['html', { open: 'never' }], ['list']],

  use: {
    trace: 'retain-on-failure',
  },

  expect: {
    toHaveScreenshot: {
      // Freezes CSS animations/transitions (this stylesheet has a blanket
      // `* { transition: all 0.3s ease 0.05s; }` plus several @keyframes)
      // so a screenshot never lands mid-motion depending on how fast the
      // runner happened to be that run.
      animations: 'disabled',
      // Deliberately tight — the whole point of running inside the official
      // Playwright Docker image (see the GitHub Actions workflow) is
      // deterministic font/subpixel rendering, so a real regression should
      // produce a diff far above this, not hide near it.
      maxDiffPixelRatio: 0.01,
    },
  },

  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
