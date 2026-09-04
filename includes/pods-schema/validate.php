<?php
if (!defined('ABSPATH')) exit;

/**
 * Post-apply validation: after scoop_schema_apply_additive() runs, re-read
 * the live Pods config and assert each schema-declared field actually landed
 * with the declared type and pick/relationship config.
 *
 * Why this exists (the 2026-09 flavor_request incident): Schema Sync's apply
 * loop records save_field() errors it can see, but a drifted field — declared
 * 'pick' in _schema.php, actually provisioned as a plain number on an
 * environment built from the fixture recipe — applies as a SUCCESSFUL no-op
 * (the pod/field pair already exists; additive apply never touches it) and
 * nothing afterwards checks what actually landed. That drifted field then
 * broke every tub swap touching a demanded flavor with a Pods exception
 * inside a save hook — a failure class that dies() through the REST error
 * path and leaves no error_log trace. The missing layer was exactly this:
 * validate what apply claims against what's live, and fail loudly on the
 * Sync page's own error list when they disagree.
 *
 * Validation is deliberately stricter than diff: diff.php reports drift for
 * triage (and its report doubles as the apply plan); validate.php is the
 * pass/fail assertion that runs AFTER an apply, so any drift it finds means
 * the apply failed to do what it claimed. Same comparison rule
 * (scoop_schema_comparable_val) and same volatile-key exclusions, so a
 * clean diff can never disagree with a clean validation.
 */

/**
 * Pure validation core — no Pods API, no WP beyond helpers that exist
 * already. Takes the schema definition and the live config as data so it can
 * run under php-cli with no WordPress at all (see tests/unit/schema-validate.php).
 *
 * $schema          scoop_schema_definition() — pod_name => ['fields' => [field => attrs], ...]
 * $live_pods       pod_name => live pod array (scoop_schema_load_live_pod() shape,
 *                  flat 'fields' map), or null / absent = pod doesn't exist live
 * $opts            [
 *                    'group_ids'  => name => numeric id  (resolved 'group' slugs; a group
 *                                    absent from this map is SKIPPED, not a failure —
 *                                    same semantics as scoop_schema_resolve_group_id())
 *                    'builtin_pods' => [name, ...]  (WP built-in post types a pick may
 *                                    legitimately point at — 'post', 'page', ...; supplied
 *                                    by the wrapper via get_post_types() so the pure core
 *                                    doesn't need WP to tell a broken relation from a
 *                                    built-in one)
 *                  ]
 *
 * Returns [
 *   'ok'             => bool  — false when ANY failure is present
 *   'checked_pods'   => int
 *   'checked_fields' => int
 *   'failures'       => [ ['kind' => ..., 'pod' => .., 'field' => .., 'attr' => ..,
 *                          'expected' => .., 'actual' => .., 'message' => human string ], ... ]
 * ]
 *
 * Failure kinds:
 *   pods_unavailable  — live map was the explicit "Pods is down" marker; nothing could be checked
 *   missing_pod       — declared pod doesn't exist live
 *   missing_field     — declared field doesn't exist live on its pod
 *   attr_mismatch     — field exists but a declared attr (type, pick_*, ...) doesn't match live
 *   broken_relation   — LIVE pick field's pick_val names a pod that isn't
 *                       live, isn't declared, and isn't a built-in post type
 *                       — the exact class the incident needed caught
 */
function scoop_schema_validate_live(array $schema, array $live_pods, array $opts = []): array {
  $group_ids = $opts['group_ids'] ?? [];
  $builtin_pods = array_flip($opts['builtin_pods'] ?? []);
  $volatile = array_flip(scoop_schema_volatile_keys());

  $result = [
    'ok' => true,
    'checked_pods' => 0,
    'checked_fields' => 0,
    'failures' => [],
  ];

  $fail = function (string $kind, string $pod, string $field, string $attr, $expected, $actual, string $message) use (&$result) {
    $result['ok'] = false;
    $result['failures'][] = [
      'kind' => $kind,
      'pod' => $pod,
      'field' => $field,
      'attr' => $attr,
      'expected' => $expected,
      'actual' => $actual,
      'message' => $message,
    ];
  };

  // The explicit "Pods API isn't there" marker — validation can't assert
  // anything, which is itself a loud failure rather than a silent pass.
  if (($opts['pods_unavailable'] ?? false) === true) {
    $fail('pods_unavailable', '', '', '', null, null, 'Pods API is not available on this environment — nothing could be validated.');
    return $result;
  }

  foreach ($schema as $pod_name => $pod_schema) {
    $result['checked_pods']++;

    $live = $live_pods[$pod_name] ?? null;
    if ($live === null || !is_array($live)) {
      $fail('missing_pod', (string) $pod_name, '', '', '(declared)', '(absent)', "Pod '{$pod_name}' is declared in the schema but does not exist on this environment.");
      continue;
    }

    $schema_fields = $pod_schema['fields'] ?? [];
    $live_fields = is_array($live['fields'] ?? null) ? $live['fields'] : [];

    foreach ($schema_fields as $field_name => $field_def) {
      $result['checked_fields']++;

      if (!array_key_exists((string) $field_name, $live_fields)) {
        $fail('missing_field', (string) $pod_name, (string) $field_name, '', '(declared)', '(absent)', "Field '{$pod_name}.{$field_name}' is declared in the schema but does not exist on this environment.");
        continue;
      }
      $actual_field = $live_fields[(string) $field_name];

      $changed = [];
      foreach ($field_def as $key => $expected_val) {
        if (isset($volatile[$key])) continue;

        if ($key === 'group') {
          // Resolve the declared ['name'=>slug,'pod'=>pod] shape to the same
          // numeric id diff.php compares against. A slug that didn't resolve
          // is SKIPPED here (apply/diff both tolerate that — the group may
          // legitimately not exist yet on a first-run environment), so a
          // missing group can never read as a failed field validation.
          if (is_array($expected_val) && !empty($expected_val['name'])) {
            $gid = $group_ids[$expected_val['name']] ?? null;
            if ($gid === null) continue;
            $expected_cmp = $gid;
          } else {
            $expected_cmp = $expected_val;
          }
        } else {
          $expected_cmp = $expected_val;
        }

        $actual_val = $actual_field[$key] ?? null;
        if (scoop_schema_comparable_val($actual_val) !== scoop_schema_comparable_val($expected_cmp)) {
          $changed[$key] = ['expected' => $expected_val, 'actual' => $actual_val];
        }
      }

      if (!empty($changed)) {
        foreach ($changed as $key => $vals) {
          $fail(
            'attr_mismatch',
            (string) $pod_name,
            (string) $field_name,
            (string) $key,
            $vals['expected'],
            $vals['actual'],
            "Field '{$pod_name}.{$field_name}' attr '{$key}': schema declares " . scoop_schema_display_val($vals['expected']) . ", live is " . scoop_schema_display_val($vals['actual']) . "."
          );
        }
      }

      // Broken-relation check, on top of attr comparison: the LIVE field's
      // pick target must exist (live pod, declared pod, or built-in post
      // type) — that is the join pods('tub', ['where' =>
      // "flavor_request.ID = …"]) resolves through at runtime, so it's the
      // live value, not the declaration, whose brokenness breaks requests.
      // Reading the live side also keeps this check noise-free: a target pod
      // that's declared-but-missing is already reported once by the
      // pod-level missing_pod failure above (target_declared suppresses the
      // per-field repeat), so a missing pod reports once, not per field.
      $type = (string) ($actual_field['type'] ?? '');
      if ($type === 'pick') {
        $pick_object = (string) ($actual_field['pick_object'] ?? '');
        $pick_val = (string) ($actual_field['pick_val'] ?? '');
        if ($pick_object === 'post_type' && $pick_val !== '') {
          $target_live = isset($live_pods[$pick_val]) && is_array($live_pods[$pick_val]);
          $target_declared = isset($schema[$pick_val]);
          $target_builtin = isset($builtin_pods[$pick_val]);
          if (!$target_live && !$target_declared && !$target_builtin) {
            $fail('broken_relation', (string) $pod_name, (string) $field_name, 'pick_val', $pick_val, '(target not found)', "Pick field '{$pod_name}.{$field_name}' points at '{$pick_val}', which is not a live pod, not declared in the schema, and not a built-in post type — its relationship joins cannot resolve.");
          }
        }
      }
    }
  }

  return $result;
}

/**
 * WP wrapper: loads this environment's live Pods config and validates the
 * schema against it. Call this AFTER scoop_schema_apply_additive() (or
 * standalone — it's equally valid as an on-demand "verify now" check).
 */
function scoop_schema_validate_after_apply(array $schema): array {
  $live_pods = [];
  $opts = ['group_ids' => [], 'builtin_pods' => []];

  if (!scoop_pods_ready()) {
    $opts['pods_unavailable'] = true;
    return scoop_schema_validate_live($schema, $live_pods, $opts);
  }

  foreach (array_keys($schema) as $pod_name) {
    $live = scoop_schema_load_live_pod($pod_name);
    if ($live !== null) $live_pods[$pod_name] = $live;
  }

  // The schema file only tracks the pods it enforces — a declared pick field
  // can legitimately point at a pod that exists live but isn't in the schema
  // (e.g. tub.flavor pointing at 'flavor'). Backfill liveness for every
  // referenced pick target before validating, or the broken-relation check
  // below would false-alarm on exactly the healthy cross-pod relations the
  // schema deliberately doesn't track. A target that fails to load here
  // stays absent => correctly reported by the check.
  $pick_targets = [];
  foreach ($schema as $pod_schema) {
    foreach ($pod_schema['fields'] ?? [] as $field_def) {
      if (($field_def['type'] ?? '') !== 'pick') continue;
      if ((string) ($field_def['pick_object'] ?? '') !== 'post_type') continue;
      $val = (string) ($field_def['pick_val'] ?? '');
      if ($val !== '') $pick_targets[$val] = true;
    }
  }
  foreach (array_keys($pick_targets) as $target) {
    if (isset($live_pods[$target])) continue;
    $live = scoop_schema_load_live_pod($target);
    if ($live !== null) $live_pods[$target] = $live;
  }

  if (function_exists('get_post_types')) {
    $opts['builtin_pods'] = array_values((array) get_post_types([], 'names'));
  }

  // Resolve every declared 'group' slug to its id ONCE (diff.php resolves
  // per comparison; here one pass over all declared groups is enough).
  if (function_exists('pods_api')) {
    $needed = [];
    foreach ($schema as $pod_name => $pod_schema) {
      foreach ($pod_schema['fields'] ?? [] as $field_def) {
        $g = $field_def['group'] ?? null;
        if (is_array($g) && !empty($g['name'])) $needed[$g['name']] = true;
      }
    }
    foreach (array_keys($needed) as $group_name) {
      try {
        $group = pods_api()->load_group(['name' => $group_name], false);
      } catch (\Throwable $e) {
        continue; // unresolvable slug => skipped, per scoop_schema_validate_live's contract
      }
      if (is_object($group)) $opts['group_ids'][$group_name] = (int) $group->get_id();
    }
  }

  return scoop_schema_validate_live($schema, $live_pods, $opts);
}