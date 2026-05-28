<?php
if (!defined('ABSPATH')) exit;

/**
 * Importer — turns a classified+chosen row into a field-level diff and,
 * on commit, writes via pods()->save(). See RCC_IMPORT_README.md §8.
 */

/**
 * Build the per-field diff for a single CSV row against the pod row it's
 * mapped to. Returns a list of:
 *
 *   ['field'=>..., 'csv_col'=>..., 'current'=>..., 'new'=>..., 'status'=>...]
 *
 * where status is one of: 'new', 'clobbered', 'skipped_empty',
 * 'skipped_placeholder', 'unchanged' (only when explicitly included).
 *
 * If $pod_row is null the row is being created — `current` is empty for every
 * field. Empty CSV cells are dropped from the diff entirely (no skipped_empty
 * row in that case).
 */
function scoop_rcc_build_field_diff(
  array $csv_row,
  ?array $pod_row,
  array $field_map,
  bool $placeholder,
  bool $override_placeholder
): array {

  $diffs = [];

  foreach ($field_map as $csv_col => $spec) {

    $field = $spec['field'];
    $new   = isset($csv_row[$csv_col]) ? trim((string) $csv_row[$csv_col]) : '';
    $cur   = $pod_row && isset($pod_row[$field]) ? trim((string) $pod_row[$field]) : '';

    // Apply per-column transform (e.g. extract "1.28" from "$1.28/oz").
    if ($new !== '' && !empty($spec['transform']) && is_callable($spec['transform'])) {
      $new = (string) call_user_func($spec['transform'], $new);
    }

    // Drop the placeholder-skip columns when the row is flagged a stub.
    if ($placeholder && !empty($spec['placeholder_skip']) && !$override_placeholder) {
      if ($new !== '' && $new !== $cur) {
        $diffs[] = [
          'field'   => $field,  'csv_col' => $csv_col,
          'current' => $cur,    'new'     => $new,
          'status'  => 'skipped_placeholder',
        ];
      }
      continue;
    }

    // Empty CSV → don't clobber. Surface the skip only when there's something
    // to clobber, so reviewers can see what they're not overwriting.
    if ($new === '') {
      if ($cur !== '') {
        $diffs[] = [
          'field'   => $field,  'csv_col' => $csv_col,
          'current' => $cur,    'new'     => $new,
          'status'  => 'skipped_empty',
        ];
      }
      continue;
    }

    if (scoop_rcc_values_equal($cur, $new, $field)) continue;

    $diffs[] = [
      'field'   => $field,  'csv_col' => $csv_col,
      'current' => $cur,    'new'     => $new,
      'status'  => $cur === '' ? 'new' : 'clobbered',
    ];
  }

  return $diffs;
}

/**
 * Compare two stringified field values. Numeric-aware so "16" == "16.00".
 */
function scoop_rcc_values_equal(string $a, string $b, string $field): bool {
  if ($a === $b) return true;
  if (is_numeric($a) && is_numeric($b)) {
    return (float) $a === (float) $b;
  }
  return false;
}

/**
 * Returns only the writable (non-skipped) entries from a diff.
 */
function scoop_rcc_writable_diff(array $diffs): array {
  return array_values(array_filter($diffs, function ($d) {
    return $d['status'] === 'new' || $d['status'] === 'clobbered';
  }));
}

/**
 * Update an existing pod row. $writes is the [<field> => <value>] map drawn
 * from scoop_rcc_writable_diff(). Optionally also rename the post title.
 *
 * Returns ['ok'=>bool, 'error'=>?string].
 */
function scoop_rcc_save_pod_row(string $pod_name, int $pod_id, array $writes, ?string $new_title = null): array {

  try {
    $pod = pods($pod_name, $pod_id);
    if (!$pod || !$pod->id()) {
      return ['ok' => false, 'error' => "pod row {$pod_name}#{$pod_id} not found"];
    }

    if ($new_title !== null && $new_title !== '') {
      wp_update_post([
        'ID'         => $pod_id,
        'post_title' => $new_title,
      ]);
    }

    if (!empty($writes)) {
      $r = $pod->save($writes);
      if ($r === false) {
        return ['ok' => false, 'error' => "pods()->save() returned false for {$pod_name}#{$pod_id}"];
      }
    }
    return ['ok' => true, 'error' => null];

  } catch (\Throwable $e) {
    return ['ok' => false, 'error' => $e->getMessage()];
  }
}

/**
 * Create a new pod row. Returns ['ok'=>bool, 'id'=>?int, 'error'=>?string].
 */
function scoop_rcc_create_pod_row(string $pod_name, string $title, array $writes): array {

  try {
    $pod = pods($pod_name);
    $writes['name'] = $title; // table-based pods accept `name` as post_title
    $new_id = $pod->add($writes);
    if (!$new_id) {
      return ['ok' => false, 'id' => null, 'error' => "pods()->add() returned no id for new {$pod_name}"];
    }
    return ['ok' => true, 'id' => (int) $new_id, 'error' => null];

  } catch (\Throwable $e) {
    return ['ok' => false, 'id' => null, 'error' => $e->getMessage()];
  }
}

/**
 * Execute one full import run. Given the classified rows + the choices from
 * the map-review screen, write everything and return a results report.
 *
 *   $choices = [ csv_index => [
 *       'backfill_id'          => bool,
 *       'update_title'         => bool,
 *       'create_new'           => bool,
 *       'override_placeholder' => bool,
 *   ] ]
 */
function scoop_rcc_commit_import(string $type, array $classified, array $pod_index, array $choices): array {

  $pod_name  = scoop_rcc_pod_name($type);
  $field_map = scoop_rcc_field_map($type);

  $updated = 0; $created = 0; $errors = []; $row_outcomes = [];

  foreach ($classified as $i => $row) {

    $choice = $choices[$i] ?? [];
    $csv    = $row['csv'];
    $name   = (string) ($csv['Name'] ?? '');
    $pod_id = $row['pod_id'];
    $class  = $row['class'];

    $placeholder          = !empty($csv['_placeholder_suspected']);
    $override_placeholder = !empty($choice['override_placeholder']);

    // Skip orphans that the operator didn't opt to create.
    if ($pod_id === null) {
      if ($class === 'csv_orphan' && !empty($choice['create_new'])) {
        $diff = scoop_rcc_build_field_diff($csv, null, $field_map, $placeholder, $override_placeholder);
        $writes = scoop_rcc_diff_to_writes($diff);
        $r = scoop_rcc_create_pod_row($pod_name, $name, $writes);
        if ($r['ok']) {
          $created++;
          $row_outcomes[$i] = ['action' => 'created', 'pod_id' => $r['id']];
        } else {
          $errors[] = "Row '{$name}': {$r['error']}";
          $row_outcomes[$i] = ['action' => 'error',   'error'  => $r['error']];
        }
      } else {
        $row_outcomes[$i] = ['action' => 'skipped'];
      }
      continue;
    }

    // Matched row — build the diff against the live pod data.
    $pod_row = $pod_index['rows'][$pod_id] ?? null;
    $diff    = scoop_rcc_build_field_diff($csv, $pod_row, $field_map, $placeholder, $override_placeholder);
    $writes  = scoop_rcc_diff_to_writes($diff);

    // Title rename is gated on the class + choice.
    $new_title = null;
    $allows_title = in_array($class, ['exact_id_near_title', 'near_title', 'title_match_id_conflict'], true);
    if ($allows_title && !empty($choice['update_title']) && $name !== '' && $name !== (string) $row['pod_title']) {
      $new_title = $name;
    }

    // For exact_title_missing_id, also back-fill rcc_id when the operator opted in.
    if ($class === 'exact_title_missing_id' && !empty($choice['backfill_id'])
        && !empty($pod_index['has_rcc_id_column'])
        && !empty($csv['ID'])) {
      $writes['rcc_id'] = (string) $csv['ID'];
    }

    if (empty($writes) && $new_title === null) {
      $row_outcomes[$i] = ['action' => 'noop'];
      continue;
    }

    $r = scoop_rcc_save_pod_row($pod_name, (int) $pod_id, $writes, $new_title);
    if ($r['ok']) {
      $updated++;
      $row_outcomes[$i] = ['action' => 'updated', 'pod_id' => $pod_id];
    } else {
      $errors[] = "Row '{$name}' (pod #{$pod_id}): {$r['error']}";
      $row_outcomes[$i] = ['action' => 'error',   'error'  => $r['error']];
    }
  }

  return [
    'updated'      => $updated,
    'created'      => $created,
    'errors'       => $errors,
    'row_outcomes' => $row_outcomes,
  ];
}

/**
 * Reduce a diff list to a [<field> => <value>] map ready for pods()->save().
 * Only `new` and `clobbered` rows produce writes.
 */
function scoop_rcc_diff_to_writes(array $diff): array {
  $out = [];
  foreach ($diff as $d) {
    if ($d['status'] === 'new' || $d['status'] === 'clobbered') {
      $out[$d['field']] = $d['new'];
    }
  }
  return $out;
}
