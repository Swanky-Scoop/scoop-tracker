// CI-stack override config: bundled Chromium instead of the system-Edge channel
// (this box has no Edge; the repo config documents that exact fallback for
// environments without Edge — see playwright.config.js's project comment).
const base = require('./playwright.config.js');
module.exports = {
  ...base,
  projects: [
    {
      name: 'chromium',
      use: { ...base.use, browserName: 'chromium' },
    },
  ],
};
