<?php
/**
 * tests/unit/schema-validate.php — unit tests for scoop_schema_validate_live()
 * (includes/pods-schema/validate.php), the post-apply assertion layer behind
 * Schema Sync's Validate step. Run: php tests/unit/schema-validate.php —
 * exit 0 = green, 1 = red.
 *
 * Zero-dependency, same pattern as debt-requests.php: the validator's core is
 * deliberately pure (schema-in, live-config-in, failures-out — all Pods/WP
 * access lives in the scoop_schema_validate_after_apply() wrapper), so a
 * fresh process can define ABSPATH, require the three pods-schema files it
 * needs, and drive the core directly. The fixtures below encode the
 * 2026-09 flavor_request incident (declared pick, live plain number) as the
 * canonical regression case.
 */

define('ABSPATH', '/tmp/fake/');

// Minimal WP stubs — the ONLY WP surface the compare/validate core touches
// headlessly. wp_json_encode() is a thin json_encode wrapper for the values
// compared here (plain schema attrs); defining it before the requires keeps
// diff.php/validate.php unmodified for testability. debt-requests.php needs
// no stubs at all; this suite does because scoop_schema_comparable_val()
// (deliberately shared with diff.php, so validation can never disagree with
// the diff report's comparison rule) calls it for array-valued attrs.
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512) {
        return json_encode($data, $options, $depth);
    }
}

require __DIR__ . '/../../includes/pods-schema/_schema.php';
require __DIR__ . '/../../includes/pods-schema/diff.php';
require __DIR__ . '/../../includes/pods-schema/export.php';
require __DIR__ . '/../../includes/pods-schema/validate.php';

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

// Failure kinds are compared by (kind, pod, field, attr) tuples; expected/
// actual ride along for human-readable reporting only.
function kinds(array $validation): array {
    $out = [];
    foreach ($validation['failures'] as $f) {
        $out[] = [$f['kind'], $f['pod'], $f['field'], $f['attr']];
    }
    return $out;
}

function has_kind(array $validation, string $kind, string $pod, string $field, string $attr): bool {
    return in_array([$kind, $pod, $field, $attr], kinds($validation), true);
}

// ---------- fixtures -------------------------------------------------------

// The real schema file, trimmed to the pods the tests need — the point is to
// exercise scoop_schema_validate_live() against declarations that were
// WRITTEN BY THE EXPORTER, not hand-shaped ones, so any future _schema.php
// shape change surfaces here as a real failure instead of passing silently.
$real_schema = scoop_schema_definition();

/**
 * Trim the real definition down: keep only $pods, and for each pod only its
 * declared 'fields' keys' worth of attrs (plus pod-level attrs minus
 * 'fields') so fixture comparisons stay readable.
 */
function trim_schema(array $schema, array $pods): array {
    $out = [];
    foreach ($pods as $pod_name) {
        if (!isset($schema[$pod_name])) continue;
        $out[$pod_name] = $schema[$pod_name];
    }
    return $out;
}

// Live config helpers — arrays shaped exactly like scoop_schema_load_live_pod()
// returns (flat 'fields' map). Built FROM the schema fixture, then mutated,
// so a green case is genuinely green and each red case isolates one mutation.
function live_from_schema(array $schema, bool $strip_volatile = true): array {
    $out = [];
    foreach ($schema as $pod_name => $pod) {
        $pod = $strip_volatile ? scoop_schema_strip_volatile($pod) : $pod;
        $fields = $pod['fields'] ?? [];
        unset($pod['fields']);
        foreach ($fields as $fname => $fdef) {
            if ($strip_volatile) $fdef = scoop_schema_strip_volatile($fdef);
            $fields[$fname] = $fdef;
        }
        $pod['fields'] = $fields;
        $out[$pod_name] = $pod;
    }
    return $out;
}

// Mirror scoop_schema_validate_after_apply()'s liveness backfill: the schema
// only tracks the pods it enforces, so pick fields can legitimately point at
// pods the fixture doesn't carry (tub.moving_to -> 'location',
// flavor_request.flavor -> 'flavor'). The broken-relation check keys off
// what's in the live map, so the test supplies the same backfill the wrapper
// performs via scoop_schema_load_live_pod(). DECLARED pods are deliberately
// NOT stubbed here: the wrapper attempts a real load_pod() for each of those
// first, and a genuinely absent declared pod stays absent — resurrecting it
// here would erase exactly the missing_pod failure the validator must report.
function backfill_pick_targets(array $live, array $schema): array {
    $targets = [];
    foreach ($schema as $pod_name => $pod) {
        foreach ($pod['fields'] ?? [] as $fdef) {
            if (($fdef['type'] ?? '') !== 'pick') continue;
            if ((string) ($fdef['pick_object'] ?? '') !== 'post_type') continue;
            $val = (string) ($fdef['pick_val'] ?? '');
            if ($val !== '' && !isset($live[$val]) && !isset($schema[$val])) $targets[$val] = true;
        }
    }
    foreach (array_keys($targets) as $t) {
        $live[$t] = ['name' => $t, 'type' => 'post_type', 'storage' => 'table', 'fields' => []];
    }
    return $live;
}

// ---------- end fixtures ---------------------------------------------------

$TUB_SCHEMA = trim_schema($real_schema, ['tub', 'flavor_request']);

section('green path — schema exactly matches live');
$live = backfill_pick_targets(live_from_schema($TUB_SCHEMA), $TUB_SCHEMA);
$v = scoop_schema_validate_live($TUB_SCHEMA, $live);
eq($v['ok'], true, 'clean environment validates ok');
eq($v['failures'], [], 'no failures on a clean environment');
eq($v['checked_pods'], 2, 'both declared pods checked');
eq($v['checked_fields'] > 0, true, 'fields actually checked (real schema fixture)');

// The incident's failure message is the exact UI string — assert its format
// precisely, so a silent reword that unrecognizably changes the report fails
// here rather than confusing an operator later.
section('failure message contract');
$live = backfill_pick_targets(live_from_schema($TUB_SCHEMA), $TUB_SCHEMA);
$live['tub']['fields']['flavor_request'] = [
    'name' => 'flavor_request',
    'label' => 'Flavor request',
    'type' => 'number',
    'number_format' => '9999.99',
    'number_max_length' => '12',
];
$v = scoop_schema_validate_live($TUB_SCHEMA, $live);
eq($v['ok'], false, 'drifted type fails validation');
eq(has_kind($v, 'attr_mismatch', 'tub', 'flavor_request', 'type'), true, 'type mismatch reported');
eq(has_kind($v, 'broken_relation', 'tub', 'flavor_request', 'pick_val'), false, 'no false broken-relation alarm (pick_val target exists)');
$ty = null;
foreach ($v['failures'] as $f) {
    if ($f['kind'] === 'attr_mismatch' && $f['attr'] === 'type') $ty = $f;
}
eq($ty !== null, true, 'type failure found');
if ($ty !== null) {
    eq($ty['expected'], 'pick', 'expected side names the declared pick');
    eq($ty['actual'], 'number', 'actual side names the live number type');
    eq($ty['message'], "Field 'tub.flavor_request' attr 'type': schema declares pick, live is number.", 'failure message identifies the exact field and attr');
}

section('missing field');
$live = backfill_pick_targets(live_from_schema($TUB_SCHEMA), $TUB_SCHEMA);
unset($live['tub']['fields']['flavor_request']);
$v = scoop_schema_validate_live($TUB_SCHEMA, $live);
eq($v['ok'], false, 'missing declared field fails');
eq(has_kind($v, 'missing_field', 'tub', 'flavor_request', ''), true, 'missing_field kind reported');
// One declared field removed => exactly one failure. The validator checks
// DECLARED fields only (live-only extras are the diff/GC layers' concern),
// so nothing else in this fixture can fire.
eq(count($v['failures']), 1, 'exactly one failure for one missing field');

section('missing pod');
$live = backfill_pick_targets(live_from_schema($TUB_SCHEMA), $TUB_SCHEMA);
unset($live['flavor_request']);
// Backfill already ran, so 'location'/'flavor' stubs survive the unset —
// rebuild without the pod, then backfill from the remaining schema. The
// flavor_request pod is declared, so its own absence is the one failure;
// its fields are not re-reported (the pod-level failure is the report).
$live = live_from_schema($TUB_SCHEMA);
unset($live['flavor_request']);
$live = backfill_pick_targets($live, $TUB_SCHEMA);
$v = scoop_schema_validate_live($TUB_SCHEMA, $live);
eq($v['ok'], false, 'missing declared pod fails');
eq(has_kind($v, 'missing_pod', 'flavor_request', '', ''), true, 'missing_pod kind reported');
eq(count($v['failures']), 1, 'missing pod reports once, no per-field cascade');

section('pick config drift — same type, wrong target');
$live = backfill_pick_targets(live_from_schema($TUB_SCHEMA), $TUB_SCHEMA);
$live['tub']['fields']['flavor_request']['pick_val'] = 'location';
$v = scoop_schema_validate_live($TUB_SCHEMA, $live);
eq($v['ok'], false, 'wrong pick target fails');
eq(has_kind($v, 'attr_mismatch', 'tub', 'flavor_request', 'pick_val'), true, 'pick_val mismatch reported');

section('pick config drift — flag-only change (multi vs single)');
$live = backfill_pick_targets(live_from_schema($TUB_SCHEMA), $TUB_SCHEMA);
$orig_multi = $TUB_SCHEMA['tub']['fields']['flavor_request']['pick_format_type'] ?? null;
if ($orig_multi === null) {
    // Whatever the real declaration is, flip it to the opposite value.
    $live['tub']['fields']['flavor_request']['pick_format_type'] = 'multi';
} else {
    $live['tub']['fields']['flavor_request']['pick_format_type'] = $orig_multi === 'single' ? 'multi' : 'single';
}
$v = scoop_schema_validate_live($TUB_SCHEMA, $live);
eq($v['ok'], false, 'pick flag drift fails');
eq(has_kind($v, 'attr_mismatch', 'tub', 'flavor_request', 'pick_format_type'), true, 'pick_format_type mismatch reported');

section('broken relation — pick target pod absent everywhere');
$live = backfill_pick_targets(live_from_schema($TUB_SCHEMA), $TUB_SCHEMA);
// The validator's attr comparison covers DECLARED fields only (live-only
// extras belong to diff/GC), so the ghost target must ride on a declared
// field: repoint a real declared pick at a pod that is not live, not
// declared, not builtin. (tub.flavor_request -> flavor_request is genuinely
// live here, so first relocate its target.) This is the incident's
// downstream blast-radius check: the relation join cannot resolve.
$live['tub']['fields']['flavor_request']['pick_val'] = 'ghost_pod';
$v = scoop_schema_validate_live($TUB_SCHEMA, $live);
eq($v['ok'], false, 'pick target absent everywhere fails');
eq(has_kind($v, 'broken_relation', 'tub', 'flavor_request', 'pick_val'), true, 'broken_relation kind reported');
// pick_val mismatch fires too (declared 'flavor_request', live 'ghost_pod')
// — both lenses on the same drifted field is correct, not cascade spam.
eq(count($v['failures']), 2, 'broken relation co-reports the pick_val mismatch');

section('broken relation suppressed for builtin and cross-pod targets');
$live = backfill_pick_targets(live_from_schema($TUB_SCHEMA), $TUB_SCHEMA);
$live['tub']['fields']['builtin_ref'] = [
    'name' => 'builtin_ref', 'label' => 'Builtin ref', 'type' => 'pick',
    'pick_object' => 'post_type', 'pick_val' => 'post',
];
$live['tub']['fields']['cross_ref'] = [
    'name' => 'cross_ref', 'label' => 'Cross ref', 'type' => 'pick',
    'pick_object' => 'post_type', 'pick_val' => 'location',
];
$v = scoop_schema_validate_live($TUB_SCHEMA, $live, ['builtin_pods' => ['post']]);
eq(has_kind($v, 'broken_relation', 'tub', 'builtin_ref', 'pick_val'), false, 'builtin post-type target suppressed via builtin_pods');
eq(has_kind($v, 'broken_relation', 'tub', 'cross_ref', 'pick_val'), false, 'live-but-untracked cross-pod target suppressed (real flavor -> location shape)');
eq($v['ok'], true, 'healthy cross-pod picks stay green');

section('pick_object variants — user and custom-simple pick fields');
$live = backfill_pick_targets(live_from_schema($TUB_SCHEMA), $TUB_SCHEMA);
$live['tub']['fields']['made_by'] = [
    'name' => 'made_by', 'label' => 'Made by', 'type' => 'pick',
    'pick_object' => 'user', 'pick_val' => '',
];
$v = scoop_schema_validate_live($TUB_SCHEMA, $live);
eq(has_kind($v, 'broken_relation', 'tub', 'made_by', 'pick_val'), false, 'user pick never triggers broken_relation');

section('volatile keys are never asserted');
$live = backfill_pick_targets(live_from_schema($TUB_SCHEMA), $TUB_SCHEMA);
$live['tub']['fields']['flavor_request']['id'] = '99999999';
$live['tub']['fields']['flavor_request']['parent'] = '12345';
$live['tub']['fields']['flavor_request']['modified'] = '2020-01-01 00:00:00';
$v = scoop_schema_validate_live($TUB_SCHEMA, $live);
eq($v['ok'], true, 'per-environment volatile keys on live fields ignored');

section('group comparison via resolved id map');
$live = backfill_pick_targets(live_from_schema($TUB_SCHEMA), $TUB_SCHEMA);
$declared_group = $TUB_SCHEMA['tub']['fields']['flavor_request']['group'] ?? null;
$group_name = is_array($declared_group) ? ($declared_group['name'] ?? null) : null;
if (is_array($declared_group) && $group_name) {
    // Live pod/field shapes are what load_pod actually returns: a resolved
    // numeric group id on the live side (the exporter strips it; the schema
    // keeps the slug shape; the comparison runs after slug->id resolution).
    $live['tub']['fields']['flavor_request']['group'] = 42;
    $v = scoop_schema_validate_live($TUB_SCHEMA, $live, ['group_ids' => [$group_name => 42]]);
    eq(has_kind($v, 'attr_mismatch', 'tub', 'flavor_request', 'group'), false, 'resolved group id equal on both sides compares clean');
    // Wrong numeric id — red.
    $live['tub']['fields']['flavor_request']['group'] = 43;
    $v = scoop_schema_validate_live($TUB_SCHEMA, $live, ['group_ids' => [$group_name => 42]]);
    eq(has_kind($v, 'attr_mismatch', 'tub', 'flavor_request', 'group'), true, 'wrong live group id fails');
} else {
    echo "  SKIP group tests — tub.flavor_request declares no group array in this schema build\n";
}

section('group slug that did not resolve is skipped, not a failure');
$live = backfill_pick_targets(live_from_schema($TUB_SCHEMA), $TUB_SCHEMA);
$v = scoop_schema_validate_live($TUB_SCHEMA, $live, ['group_ids' => []]);
eq(has_kind($v, 'attr_mismatch', 'tub', 'flavor_request', 'group'), false, 'unresolved group slug skipped (no false alarm)');

section('pods_unavailable marker');
$v = scoop_schema_validate_live($TUB_SCHEMA, [], ['pods_unavailable' => true]);
eq($v['ok'], false, 'Pods-unavailable is a loud failure, not a silent pass');
eq($v['failures'][0]['kind'], 'pods_unavailable', 'pods_unavailable kind reported');
eq($v['checked_pods'], 0, 'nothing checked when Pods is down');

section('live shape tolerances — non-array live pod entry');
$live = live_from_schema($TUB_SCHEMA);
$live['tub'] = 'garbage';
$v = scoop_schema_validate_live($TUB_SCHEMA, $live);
eq($v['ok'], false, 'garbage live entry treated as missing pod');
eq(has_kind($v, 'missing_pod', 'tub', '', ''), true, 'non-array live pod reported as missing_pod');

section('live shape tolerances — live pod without a fields map');
$live = live_from_schema($TUB_SCHEMA);
$live['tub']['fields'] = null;
$v = scoop_schema_validate_live($TUB_SCHEMA, $live);
eq($v['ok'], false, 'missing live fields map fails declared fields');
eq(has_kind($v, 'missing_field', 'tub', 'flavor_request', ''), true, 'declared field of fieldless live pod reported missing');

section('failure payload contract');
$live = live_from_schema($TUB_SCHEMA);
unset($live['tub']['fields']['flavor_request']);
$v = scoop_schema_validate_live($TUB_SCHEMA, $live);
eq(array_keys($v['failures'][0]), ['kind', 'pod', 'field', 'attr', 'expected', 'actual', 'message'], 'failure records carry the full payload contract');

finish('schema-validate');