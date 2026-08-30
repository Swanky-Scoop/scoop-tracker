#!/usr/bin/env node
///////////////////////////////////
// tests/unit/run-all.mjs — run every tests/unit suite, zero-dependency.
// Node runs the .mjs suites; the PHP suite runs under php-cli when a php
// binary is present (this repo's unit tests are the ONLY thing that needs
// it — no php on the machine means the PHP suite is SKIPPED with a visible
// note, not silently green). Exit code is non-zero if any executed suite
// failed. Run: node tests/unit/run-all.mjs
///////////////////////////////////

import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const here = path.dirname(fileURLToPath(import.meta.url));

const jsSuites = ['debt-model.test.mjs', 'debt-class.test.mjs'];
const phpSuites = ['debt-requests.php'];

// The .mjs suites import ESM-syntax files under assets/ (debt-grid-model.js and
// its transitive imports), and root package.json deliberately has NO "type"
// field — tests/smoke's Playwright specs are CommonJS and Playwright resolves
// spec files with its own loader, so type:module would make node reparse them
// as ESM and break the smoke runner. Instead, silence only the resulting
// MODULE_TYPELESS_PACKAGE_JSON warning (the flag exists on node ≥ 20.12) for the child suites, and
// degrade to the noisy-but-working behavior on nodes without the flag — an
// unknown node option would otherwise kill every suite with "bad option".
// (The suites themselves need node ≥ 21.3 regardless — module-syntax detection
// of assets/*.js; on node 20 they fail with a CJS named-export error before any
// flag matters — verified on 20.18.1, pre-existing at e1b5699.)
const disableTypeless = '--disable-warning=MODULE_TYPELESS_PACKAGE_JSON';
const flagProbe = spawnSync(process.execPath, [disableTypeless, '-e', ''], { encoding: 'utf8' });
const jsNodeArgs = !flagProbe.error && flagProbe.status === 0 ? [disableTypeless] : [];

let failed = 0;

for (const suite of jsSuites) {
  const r = spawnSync(process.execPath, [...jsNodeArgs, path.join(here, suite)], { stdio: 'inherit' });
  if (r.status !== 0) failed++;
}

const php = spawnSync('php', ['-v'], { encoding: 'utf8' });
if (php.error || php.status !== 0) {
  console.log(`\nSKIP tests/unit php suite — no php binary on PATH (install php-cli to run it):\n  ${phpSuites.join(' ')}`);
} else {
  for (const suite of phpSuites) {
    const r = spawnSync('php', [path.join(here, suite)], { stdio: 'inherit' });
    if (r.status !== 0) failed++;
  }
}

if (failed) {
  console.error(`\ntests/unit: ${failed} suite(s) FAILED`);
  process.exit(1);
}
console.log('\ntests/unit: all suites green');
