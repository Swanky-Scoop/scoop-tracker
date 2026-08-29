<?php
if (!defined('ABSPATH')) exit;

/**
 * Diffs the hand-authored schema (includes/pods-schema/_schema.php) against
 * whichever environment this code is currently running on. Everything here
 * is read-only and environment-local — it never reaches another site.
 */

/**
 * Tolerant unwrap for whatever pods_api() hands back for a single pod.
 * Pods ≤2.7 returns a plain array; 2.8+/3.x (this site runs 3.3.9) returns a
 * Pods\Whatsit\Pod object. Same defensive pattern already used in
 * includes/_pods_helpers.php (scoop_pods_field_def) and
 * includes/rcc-import/_config.php (scoop_rcc_load_pod_fields).
 */
function scoop_schema_pod_to_array($pod): array {
  if (empty($pod)) return [];
  if (is_array($pod)) return $pod;
  if (is_object($pod)) {
    if (method_exists($pod, 'export')) {
      $arr = $pod->export();
      if (is_array($arr)) return $arr;
    }
    if (method_exists($pod, 'to_array')) {
      $arr = $pod->to_array();
      if (is_array($arr)) return $arr;
    }
    return (array) $pod;
  }
  return [];
}

/** Same idea, for a single field definition (Pods\Whatsit\Field or array). */
function scoop_schema_field_to_array($field): array {
  if (empty($field)) return [];
  if (is_array($field)) return $field;
  if (is_object($field)) {
    if (method_exists($field, 'export')) {
      $arr = $field->export();
      if (is_array($arr)) return $arr;
    }
    if (method_exists($field, 'to_array')) {
      $arr = $field->to_array();
      if (is_array($arr)) return $arr;
    }
    return (array) $field;
  }
  return [];
}

/**
 * Loads one live pod by name, normalized to a plain array with a flat
 * 'fields' map (field name => field attrs array). Returns null if the pod
 * doesn't exist on this environment.
 */
function scoop_schema_load_live_pod(string $pod_name): ?array {
  if (!scoop_pods_ready()) return null;

  try {
    $pod = pods_api()->load_pod(['name' => $pod_name]);
  } catch (\Throwable $e) {
    return null;
  }
  if (empty($pod)) return null;

  $arr = scoop_schema_pod_to_array($pod);

  if (is_object($pod) && method_exists($pod, 'get_fields')) {
    $raw_fields = $pod->get_fields();
    if ($raw_fields instanceof Traversable) $raw_fields = iterator_to_array($raw_fields);
  } else {
    $raw_fields = $arr['fields'] ?? [];
  }

  $fields = [];
  if (is_array($raw_fields)) {
    foreach ($raw_fields as $fname => $fdef) {
      $fields[(string) $fname] = scoop_schema_field_to_array($fdef);
    }
  }
  $arr['fields'] = $fields;

  return $arr;
}

/** Names of every pod that exists live on this environment. */
function scoop_schema_live_pod_names(): array {
  if (!scoop_pods_ready()) return [];

  try {
    $pods = pods_api()->load_pods();
  } catch (\Throwable $e) {
    return [];
  }
  if (!is_array($pods)) return [];

  $names = [];
  foreach ($pods as $p) {
    $a = scoop_schema_pod_to_array($p);
    $name = (string) ($a['name'] ?? '');
    if ($name !== '') $names[] = $name;
  }
  return $names;
}

/**
 * Comparable string form of a pod/field attribute value. Some attrs (e.g. a
 * relationship field's 'options', or 'pick_custom' split into lines) come
 * back as arrays on one or both sides — a plain (string) cast on those
 * throws PHP's "Array to string conversion" warning AND is wrong besides:
 * every array collapses to the literal string "Array", so two DIFFERENT
 * arrays would compare equal (a missed diff) while an array vs. a scalar
 * would compare not-equal for the wrong reason. wp_json_encode gives each
 * distinct value its own distinct string instead. Mirrors
 * scoop_schema_display_val() in ui.php, which renders these same values —
 * kept separate since this one's for comparison, not display.
 */
function scoop_schema_comparable_val($val): string {
  if (is_array($val)) return wp_json_encode($val);
  return (string) $val;
}

/**
 * 'group' schema values are ['name' => slug, 'pod' => pod_name] (see the
 * authoring note in _schema.php) — save_field() resolves that shape to a
 * pod-scoped group id unambiguously, unlike a bare slug string, which isn't
 * unique across the install (confirmed: 25+ pods share the literal slug
 * 'more_fields'). Comparing that array shape directly against a live
 * field's plain numeric group id would never match — wp_json_encode(array)
 * vs. a string int — so every field using this shape would show as
 * permanently "changed" even when correct. Resolves it to the SAME numeric
 * id save_field() itself would land on, once, so the comparison is
 * apples-to-apples. Returns $val unchanged for a plain int/string 'group'
 * (the older shape, still valid) or any other key.
 */
function scoop_schema_resolve_group_id($val) {
  if (!is_array($val) || empty($val['name'])) return $val;
  if (!function_exists('pods_api')) return $val;

  try {
    $group = pods_api()->load_group($val, false);
  } catch (\Throwable $e) {
    return $val;
  }

  return is_object($group) ? $group->get_id() : $val;
}

/**
 * Compares $schema (from scoop_schema_definition()) against this
 * environment's live Pods config. Only keys present in $schema are ever
 * compared — see the authoring note in _schema.php.
 *
 * Returns:
 *   [
 *     'error' => string|null,
 *     'missing_pods' => [pod_name, ...],           // in schema, not live
 *     'extra_pods'   => [pod_name, ...],            // live, not in schema (report-only)
 *     'pods' => [
 *       pod_name => [
 *         'changed_pod_attrs' => [key => ['expected'=>.., 'actual'=>..], ...],
 *         'missing_fields'    => [field_name, ...],
 *         'extra_fields'      => [field_name, ...],  // candidates for GC
 *         'changed_fields'    => [field_name => [key => ['expected'=>.., 'actual'=>..], ...], ...],
 *       ],
 *     ],
 *   ]
 */
function scoop_schema_diff(array $schema): array {
  $result = [
    'error' => null,
    'missing_pods' => [],
    'extra_pods' => [],
    'pods' => [],
  ];

  if (!scoop_pods_ready()) {
    $result['error'] = 'Pods API is not available on this environment.';
    return $result;
  }

  $live_names = scoop_schema_live_pod_names();
  $result['extra_pods'] = array_values(array_diff($live_names, array_keys($schema)));

  foreach ($schema as $pod_name => $pod_schema) {
    $live = scoop_schema_load_live_pod($pod_name);
    if ($live === null) {
      $result['missing_pods'][] = $pod_name;
      continue;
    }

    $entry = [
      'changed_pod_attrs' => [],
      'missing_fields' => [],
      'extra_fields' => [],
      'changed_fields' => [],
    ];

    foreach ($pod_schema as $key => $expected) {
      if ($key === 'fields') continue;
      $actual = $live[$key] ?? null;
      if (scoop_schema_comparable_val($actual) !== scoop_schema_comparable_val($expected)) {
        $entry['changed_pod_attrs'][$key] = ['expected' => $expected, 'actual' => $actual];
      }
    }

    $schema_fields = $pod_schema['fields'] ?? [];
    $live_fields = $live['fields'] ?? [];

    foreach (array_keys($live_fields) as $fname) {
      if (!array_key_exists($fname, $schema_fields)) {
        $entry['extra_fields'][] = $fname;
      }
    }

    foreach ($schema_fields as $fname => $expected_field) {
      if (!array_key_exists($fname, $live_fields)) {
        $entry['missing_fields'][] = $fname;
        continue;
      }
      $actual_field = $live_fields[$fname];
      $changed = [];
      foreach ($expected_field as $key => $expected_val) {
        $actual_val = $actual_field[$key] ?? null;
        // See scoop_schema_resolve_group_id()'s own comment — only affects
        // comparison, the reported 'expected' below still shows the
        // original ['name'=>..,'pod'=>..] shape for a meaningful report.
        $compare_expected = $key === 'group' ? scoop_schema_resolve_group_id($expected_val) : $expected_val;
        if (scoop_schema_comparable_val($actual_val) !== scoop_schema_comparable_val($compare_expected)) {
          $changed[$key] = ['expected' => $expected_val, 'actual' => $actual_val];
        }
      }
      if (!empty($changed)) {
        $entry['changed_fields'][$fname] = $changed;
      }
    }

    $result['pods'][$pod_name] = $entry;
  }

  return $result;
}

/** True if there's anything scoop_schema_apply_additive() could fix. */
function scoop_schema_diff_has_additive_work(array $diff): bool {
  if (!empty($diff['missing_pods'])) return true;
  foreach ($diff['pods'] as $entry) {
    if (!empty($entry['missing_fields'])) return true;
    if (!empty($entry['changed_fields'])) return true;
    if (!empty($entry['changed_pod_attrs'])) return true;
  }
  return false;
}

/** True if there's anything scoop_schema_gc_fields() could remove. */
function scoop_schema_diff_has_gc_work(array $diff): bool {
  foreach ($diff['pods'] as $entry) {
    if (!empty($entry['extra_fields'])) return true;
  }
  return false;
}
