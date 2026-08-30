///////////////////////////////////
// Zero-dependency assertion helper for the tests/unit/ suites — the same
// tiny eq() convention the in-box harnesses used to prove the Debt view
// (see the worktree-tub-moving arc), committed so the suites run outside
// the agent's box. No test framework: a suite is a plain node run whose
// exit code is the verdict.
//
// eq() compares JSON with object keys SORTED, so {a,b} vs {b,a} is equal —
// plain JSON.stringify would make cell-object compares flaky on key order.
// Arrays stay order-sensitive (order is meaningful in row lists). A failing
// eq() prints the label + both sides and marks the run failed
// (process.exitCode = 1); it never throws, so one red assertion doesn't
// hide the rest of the run.
//////////////////////////////////

let count  = 0;
let failed = 0;

function stable(value) {
  if (Array.isArray(value)) return value.map(stable);
  if (value && typeof value === 'object') {
    return Object.fromEntries(
      Object.keys(value).sort().map(k => [k, stable(value[k])]),
    );
  }
  return value;
}

export function eq(actual, expected, label) {
  count++;
  const a = JSON.stringify(stable(actual));
  const b = JSON.stringify(stable(expected));
  if (a === b) {
    console.log(`  ok  ${label}`);
    return true;
  }
  failed++;
  console.error(`FAIL  ${label}\n      expected: ${b}\n      actual:   ${a}`);
  return false;
}

export function section(title) {
  console.log(`\n== ${title}`);
}

export function finish(suite) {
  const verdict = failed ? `${failed} FAILED` : 'all ok';
  console.log(`\n${suite}: ${count} assertions, ${verdict}`);
  if (failed) process.exitCode = 1;
}
