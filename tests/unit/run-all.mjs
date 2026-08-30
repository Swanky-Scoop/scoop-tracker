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

let failed = 0;

for (const suite of jsSuites) {
  const r = spawnSync(process.execPath, [path.join(here, suite)], { stdio: 'inherit' });
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
