<?php
/**
 * tests/unit/debt-requests.php — unit tests for scoop_parse_debt_requests()
 * (includes/rest.php), the pure parser behind the /debt-requests REST route
 * the Debt grid's Wanted column posts to. Ported from the in-box php-cli
 * harness that proved the route's input contract when the Debt view shipped
 * (worktree-tub-moving). Run: php tests/unit/debt-requests.php — exit code
 * 0 = green, 1 = red.
 *
 * Zero-dependency: needs NO WordPress stubs at all. scoop_parse_debt_requests()
 * is deliberately pure (the handler owns all Pods/WP persistence), and
 * includes/rest.php declares every scoop_* function unconditionally at file
 * scope with no namespace and no side effects beyond the ABSPATH guard — so
 * a fresh process can just define ABSPATH and require it.
 */

define('ABSPATH', '/tmp/fake/');
require __DIR__ . '/../../includes/rest.php';

$GLOBALS['__count'] = 0;
$GLOBALS['__fail']  = 0;

function eq($actual, $expected, string $label): bool {
    $GLOBALS['__count']++;
    // Same stable-compare rule as tests/unit/helpers.mjs: object/array
    // comparisons sort recursively by key so {a,b} vs {b,a} is equal.
    $a = json_encode($actual);
    $b = json_encode($expected);
    if (is_array($actual) && is_array($expected)) {
        $sortDeep = function ($v) use (&$sortDeep) {
            if (is_array($v)) {
                foreach ($v as $k => $sub) { $v[$k] = $sortDeep($sub); }
                if (array_is_list($v)) { return $v; }
                ksort($v);
            }
            return $v;
        };
        $a = json_encode($sortDeep($actual));
        $b = json_encode($sortDeep($expected));
    }
    if ($a === $b) {
        echo "  ok  {$label}\n";
        return true;
    }
    $GLOBALS['__fail']++;
    fwrite(STDERR, "FAIL  {$label}\n      expected: {$b}\n      actual:   {$a}\n");
    return false;
}

function section(string $title): void {
    echo "\n== {$title}\n";
}

function finish(string $suite): void {
    $fail = $GLOBALS['__fail'];
    $verb = $fail ? "{$fail} FAILED" : 'all ok';
    echo "\n{$suite}: {$GLOBALS['__count']} assertions, {$verb}\n";
    exit($fail ? 1 : 0);
}

// Parse helper: returns [ops, errors] for a Debt envelope.
function parse(array $payload): array {
    return scoop_parse_debt_requests($payload);
}

// ---- happy path: upsert + delete decode ------------------------------------
section('valid upsert + delete');
{
    [$ops, $errors] = parse(['cells' => [
        (string) (1010 * 100000 + 600) => ['wanted' => 3],
        (string) (1020 * 100000 + 700) => ['wanted' => 0],
    ]]);
    eq($errors, [], 'valid envelope yields no errors');
    eq(count($ops), 2, 'two cells -> two ops');
    eq($ops[0], ['type' => 'upsert', 'location' => 1010, 'flavor' => 600, 'wanted' => 3], 'upsert op decodes location/flavor/wanted');
    eq($ops[1], ['type' => 'delete', 'location' => 1020, 'flavor' => 700], 'wanted 0 -> delete op (no wanted key on deletes)');
}

// ---- field-name seam: 'demand' (browser) vs 'wanted' (route shape) ----------
// The Debt board's autosave posts Debt[cells][<rowId>][demand] (List builds
// the input name from the cell's colKey 'demand'); this parser reads
// 'wanted'. Both must be accepted — this seam is what the smoke spec found.
section('demand/wanted field-name seam');
{
    // The EXACT browser payload shape (TextIt colKey 'demand'):
    [$ops, $errors] = parse(['cells' => [(string) (1010 * 100000 + 600) => ['demand' => 3]]]);
    eq($errors, [], 'browser shape (demand) accepted — DebtGridModel colKey demand');
    eq($ops, [['type' => 'upsert', 'location' => 1010, 'flavor' => 600, 'wanted' => 3]], "demand -> upsert op (wanted key)");

    [$ops, $errors] = parse(['cells' => [(string) (1010 * 100000 + 600) => ['demand' => 0]]]);
    eq($ops, [['type' => 'delete', 'location' => 1010, 'flavor' => 600]], 'demand 0 -> delete op');

    // Both names present:
    [$ops, $errors] = parse(['cells' => [(string) (1010 * 100000 + 600) => ['wanted' => 3, 'demand' => 3]]]);
    eq($errors, [], 'wanted+demand agreeing is valid');
    eq($ops[0]['wanted'], 3, 'wanted+demand agreeing -> one op');

    [$ops, $errors] = parse(['cells' => [(string) (1010 * 100000 + 600) => ['wanted' => 3, 'demand' => 5]]]);
    eq($ops, [], 'wanted+demand disagreeing refused (ambiguous)');
    eq(preg_match("/disagree/", $errors[0] ?? '') === 1, true, 'disagreement error names the conflict');

    // The seam is symmetric: every validation below runs against either
    // name — prove it once with the same value via both shapes.
    [$ops, $errors] = parse(['cells' => [(string) (1010 * 100000 + 600) => ['wanted' => 99]]]);
    [$ops2, $errors2] = parse(['cells' => [(string) (1010 * 100000 + 600) => ['demand' => 99]]]);
    eq([$ops, $errors], [$ops2, $errors2], 'boundary 99 behaves identically via wanted and demand');
}

// ---- envelope guards --------------------------------------------------------
section('envelope guards');
{
    [$ops, $errors] = parse([]);
    eq($ops, [], 'missing cells envelope -> no ops');
    eq($errors, ['Missing Debt[cells].'], 'missing cells envelope -> one error');

    [$ops, $errors] = parse(['cells' => 'nope']);
    eq($ops, [], 'non-array cells -> no ops');
    eq($errors, ['Missing Debt[cells].'], 'non-array cells -> same error');

    [$ops, $errors] = parse(['cells' => []]);
    eq($ops, [], 'empty cells -> no ops');
    eq($errors, [], 'empty cells is VALID (no error) — handler turns it into "No cells to save."');
}

// ---- malformed row ids ------------------------------------------------------
section('malformed row ids');
{
    $cases = [
        'abc'    => 'non-numeric',
        '0'      => 'zero pair id',
        '-5'     => 'negative pair id',
        '1010.5' => 'decimal id',
        ' 12'    => 'leading-space id',
        ''       => 'empty id',
    ];
    foreach ($cases as $key => $why) {
        [$ops, $errors] = parse(['cells' => [$key => ['wanted' => 2]]]);
        eq($ops, [], "malformed id ({$why}) refused");
        eq(count($errors), 1, "malformed id ({$why}) -> one per-cell error");
        $needle = "Cell {$key}";
        $hit = !empty($errors) && str_contains($errors[0], $needle);
        eq($hit, true, "malformed id ({$why}) error names the cell");
    }

    // location*100000+flavor arithmetic: decode-to-zero halves are refused.
    [$ops, $errors] = parse(['cells' => ['600' => ['wanted' => 2]]]);
    eq($ops, [], 'pair id 600 decodes to location 0 -> refused (flavor-only id)');
    [$ops, $errors] = parse(['cells' => [(string) (1010 * 100000) => ['wanted' => 2]]]);
    eq($ops, [], 'pair id 1010*100000 decodes to flavor 0 -> refused (location-only id)');

    // Just inside the flavor ceiling is fine; flavor is id%100000 so it can
    // never exceed 99999 — the guard is the decode invariant, not a clamp.
    [$ops, $errors] = parse(['cells' => [(string) (1010 * 100000 + 99999) => ['wanted' => 1]]]);
    eq($errors, [], 'flavor 99999 (the ceiling) is valid');
    eq($ops, [['type' => 'upsert', 'location' => 1010, 'flavor' => 99999, 'wanted' => 1]], 'ceiling decode is a real op');

    // A pair id that overflows 32-bit int is refused as non-integer, not
    // silently truncated (PHP int is 64-bit here, but FILTER_VALIDATE_INT
    // with the default limits is the parse contract — document it).
    [$ops, $errors] = parse(['cells' => ['99999999999999999999' => ['wanted' => 2]]]);
    eq($ops, [], 'non-32-bit oversized id refused');
}

// ---- wanted validation ------------------------------------------------------
section('wanted validation');
{
    $bad = [
        [-1, 'negative'],
        [100, 'over 99'],
        ['ten', 'non-numeric string'],
        [2.5, 'float with fraction'],
        [null, 'null'],
        [[3], 'array'],
    ];
    foreach ($bad as [$wanted, $why]) {
        [$ops, $errors] = parse(['cells' => [(string) (1010 * 100000 + 600) => ['wanted' => $wanted]]]);
        eq($ops, [], "wanted {$why} refused");
        $needle = "'wanted'";
        $hit = !empty($errors) && str_contains($errors[0], $needle);
        eq($hit, true, "wanted {$why} error names 'wanted'");
    }

    // filter_var(FILTER_VALIDATE_INT) COERCES booleans: true -> 1 (PHP's
    // documented loose behavior). A hand-crafted JSON `true` therefore
    // upserts wanted=1 — harmless (inside 0..99), and the parser's real
    // contract, asserted here so a future FILTER_FLAG change is noticed.
    [$ops, $errors] = parse(['cells' => [(string) (1010 * 100000 + 600) => ['wanted' => true]]]);
    eq($errors, [], 'wanted boolean true is accepted (FILTER_VALIDATE_INT coerces true -> 1)');
    eq($ops[0], ['type' => 'upsert', 'location' => 1010, 'flavor' => 600, 'wanted' => 1], 'wanted true -> upsert 1');

    [$ops, $errors] = parse(['cells' => [(string) (1010 * 100000 + 600) => ['nope' => 1]]]);
    eq($ops, [], 'missing wanted refused');
    eq($errors, ['Cell ' . (1010 * 100000 + 600) . ": missing 'wanted'."], 'missing wanted -> its own error');

    // Coercions the parser accepts.
    [$ops, $errors] = parse(['cells' => [(string) (1010 * 100000 + 600) => ['wanted' => '7']]]);
    eq($errors, [], "numeric string '7' accepted");
    eq($ops[0]['wanted'], 7, "numeric string '7' -> int 7");

    [$ops, $errors] = parse(['cells' => [(string) (1010 * 100000 + 600) => ['wanted' => 7.0]]]);
    eq($errors, [], 'integer-valued float 7.0 accepted');
    eq($ops[0]['wanted'], 7, 'float 7.0 -> int 7');

    [$ops, $errors] = parse(['cells' => [(string) (1010 * 100000 + 600) => ['wanted' => '  12  ']]]);
    eq($errors, [], 'whitespace-padded numeric string accepted (FILTER_VALIDATE_INT trims)');
    eq($ops[0]['wanted'], 12, 'padded string -> int 12');

    // Boundary: 99 is the last valid upsert.
    [$ops, $errors] = parse(['cells' => [(string) (1010 * 100000 + 600) => ['wanted' => 99]]]);
    eq($errors, [], 'boundary 99 is valid');
    eq($ops[0], ['type' => 'upsert', 'location' => 1010, 'flavor' => 600, 'wanted' => 99], 'boundary 99 -> upsert 99');

    // Boundary: 0 deletes.
    [$ops, $errors] = parse(['cells' => [(string) (1010 * 100000 + 600) => ['wanted' => 0]]]);
    eq($errors, [], 'boundary 0 is valid');
    eq($ops[0], ['type' => 'delete', 'location' => 1010, 'flavor' => 600], 'boundary 0 -> delete op');
}

// ---- non-array cell values --------------------------------------------------
section('cell-level guards');
{
    [$ops, $errors] = parse(['cells' => [(string) (1010 * 100000 + 600) => 'wanted=3']]);
    eq($ops, [], 'non-array cell refused');
    eq($errors, ['Cell ' . (1010 * 100000 + 600) . ': not an object.'], 'non-array cell -> "not an object" error');

    // Mixed batch: one good op survives alongside one bad cell (per-cell
    // errors, never a whole-envelope reject).
    [$ops, $errors] = parse(['cells' => [
        (string) (1010 * 100000 + 600) => ['wanted' => 3],
        (string) (1020 * 100000 + 700) => ['wanted' => 'oops'],
    ]]);
    eq($ops, [['type' => 'upsert', 'location' => 1010, 'flavor' => 600, 'wanted' => 3]], 'mixed batch: valid cell still produces its op');
    eq(count($errors), 1, 'mixed batch: bad cell reported on its own');
    eq(str_contains($errors[0], (string) (1020 * 100000 + 700)), true, 'mixed batch: error names the bad cell');
}

finish('tests/unit/debt-requests.php');
