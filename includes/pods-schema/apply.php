<?php
if (!defined('ABSPATH')) exit;

/**
 * Additive-only apply: creates pods/fields that are in the schema but
 * missing live, and fixes pod/field attrs that differ from the schema.
 * Never deletes anything — see gc.php for the separate, opt-in delete path.
 *
 * This is new territory for the codebase: every existing pods_api() call
 * elsewhere writes data rows (save_pod_item); nothing before this wrote
 * pod/field *definitions*. Verify on the local dev site before trusting
 * this against TEST, and TEST before OPS (CLAUDE.md data repair policy).
 */
/**
 * Resolve a schema field's 'group' for save_field() — Pods' save_field()
 * accepts group_id or a bare slug string, NOT the ['name'=>..,'pod'=>..]
 * array shape the schema uses (that's save_group()'s shape): the array hits
 * $group_identifier = 'Slug: ' . $params->group in PodsAPI::save_field()
 * (~line 3167 in Pods 2.9.x), firing "Array to string conversion" and
 * passing an array as load_group()'s name, which never matches →
 * pods_error() throws (PodsAPI::$display_errors is false → exception mode),
 * the apply loop catches it and records "Create field ...: Group (...)
 * not found.", and the field is NOT created/updated by that call. Pods does
 * not create missing fields on its own — the field only appears after a
 * human-run repair/manual GUI action, as happened for tub.moving_to on
 * local (see PR 34's commit message about the repair tool).
 *
 * Resolving to the pod's own group id here (same lookup
 * scoop_schema_resolve_group_id() uses for the diff comparison) removes the
 * warning AND the failure: save_field's group_id branch is first-choice and
 * concat-free. On resolution failure (group genuinely absent) the value is
 * left untouched so save_field fails loudly and visibly — an explicit
 * recorded error, never a silent misassignment.
 *
 * $known_group_ids (name => id) lets a caller that just created a group via
 * scoop_schema_apply_ensure_groups() skip re-querying for it here. That's
 * not just an optimization: load_group() right after a same-request
 * save_group() was confirmed unreliable on a real run (10 fields across 3
 * brand-new pods all fell through to the raw-array path below and hit the
 * "Array to string conversion" warning at PodsAPI::save_field() ~line 3167,
 * even though every group had just been created successfully) — some
 * Pods object-cache layer doesn't see the new group in time. Passing the id
 * straight through sidesteps that lookup entirely instead of chasing it.
 *
 * $is_update matters for which key actually moves the field: PodsAPI::save_field()
 * only honors 'group_id'/'group' when creating a brand-new field (~line 3454:
 * `if ($group) { $field['group'] = $group->get_id(); }`, in the "field doesn't
 * exist yet" branch). For an EXISTING field it's silently ignored — only
 * 'new_group_id'/'new_group' moves an existing field to a different group
 * (~line 3378: `if ($new_group) { $field['group'] = $new_group->get_id(); }`,
 * in the "field exists" branch). Confirmed on a real run: save_field() on an
 * existing field with 'group_id' set returned success with no error, but the
 * field's live group never changed — a silent no-op, not a failure the apply
 * loop could ever have caught on its own.
 */
function scoop_schema_apply_resolve_group(array $field_def, string $pod_name, array $known_group_ids = [], bool $is_update = false): array {
  $group = $field_def['group'] ?? null;
  if (!is_array($group) || empty($group['name'])) return $field_def;

  $group_key = $is_update ? 'new_group_id' : 'group_id';

  if (isset($known_group_ids[$group['name']])) {
    $out = $field_def;
    $out[$group_key] = (int) $known_group_ids[$group['name']];
    unset($out['group']);
    return $out;
  }

  if (!function_exists('pods_api')) return $field_def;

  try {
    $g = pods_api()->load_group(['name' => $group['name'], 'pod' => $pod_name], false);
  } catch (\Throwable $e) {
    return $field_def;
  }
  if (!is_object($g)) return $field_def; // group absent — keep the loud failure path

  $out = $field_def;
  $out[$group_key] = (int) $g->get_id();
  unset($out['group']);
  return $out;
}

/**
 * scoop_schema_apply_resolve_group() only ever resolves an EXISTING group —
 * by design it leaves an unresolvable ['name'=>..,'pod'=>..] shape untouched
 * so save_field() fails loudly rather than silently misassigning (see that
 * function's docblock). But Pods never creates a missing group as a side
 * effect of save_field(), so for a genuinely new pod — or a new group added
 * to an existing pod — that group doesn't exist yet on this environment and
 * every field referencing it would fail. Create whatever groups this pod's
 * fields need, once each, before any save_field() call is attempted; a
 * group that already resolves is left alone.
 *
 * Returns name => id for every group this pod's fields need (existing or
 * freshly created) — feed it straight into scoop_schema_apply_resolve_group()
 * as $known_group_ids so the field-creation loop never has to re-look-up a
 * group this same request just created (see that function's docblock for why
 * that re-lookup can't be trusted).
 */
function scoop_schema_apply_ensure_groups(array $fields, string $pod_name, array &$result): array {
  $known = [];
  if (!function_exists('pods_api')) return $known;
  $api = pods_api();

  $needed = [];
  foreach ($fields as $field_def) {
    $group = $field_def['group'] ?? null;
    if (is_array($group) && !empty($group['name'])) {
      $needed[$group['name']] = true;
    }
  }

  foreach (array_keys($needed) as $group_name) {
    try {
      $existing = $api->load_group(['name' => $group_name, 'pod' => $pod_name], false);
    } catch (\Throwable $e) {
      $existing = null;
    }
    if (is_object($existing)) {
      $known[$group_name] = (int) $existing->get_id();
      continue;
    }

    try {
      $group_id = $api->save_group(['name' => $group_name, 'label' => $group_name, 'pod' => $pod_name]);
    } catch (\Throwable $e) {
      $result['errors'][] = "Create group '{$pod_name}.{$group_name}': " . $e->getMessage();
      continue;
    }
    if (is_wp_error($group_id)) {
      $result['errors'][] = "Create group '{$pod_name}.{$group_name}': " . $group_id->get_error_message();
      continue;
    }
    $known[$group_name] = (int) $group_id;
  }

  return $known;
}

function scoop_schema_apply_additive(array $schema, array $diff): array {
  $result = [
    'created_pods' => [],
    'updated_pod_attrs' => [],
    'created_fields' => [],
    'updated_fields' => [],
    'errors' => [],
  ];

  if (!scoop_pods_ready()) {
    $result['errors'][] = 'Pods API is not available on this environment.';
    return $result;
  }

  $api = pods_api();

  foreach ($diff['missing_pods'] as $pod_name) {
    $pod_schema = $schema[$pod_name] ?? null;
    if ($pod_schema === null) continue;

    $fields = $pod_schema['fields'] ?? [];
    $pod_params = $pod_schema;
    unset($pod_params['fields']);
    $pod_params['name'] = $pod_name;

    try {
      $pod_id = $api->save_pod($pod_params);
    } catch (\Throwable $e) {
      $result['errors'][] = "Create pod '{$pod_name}': " . $e->getMessage();
      continue;
    }
    if (is_wp_error($pod_id)) {
      $result['errors'][] = "Create pod '{$pod_name}': " . $pod_id->get_error_message();
      continue;
    }
    $result['created_pods'][] = $pod_name;

    // save_pod() above already creates any field nested inside a 'groups'
    // entry, as part of that same call — Pods threads each group's
    // freshly-resolved (THIS environment's own) id straight onto its
    // nested fields (PodsAPI::save_pod(), ~line 2394 in classes/PodsAPI.php),
    // never consulting that field's own 'group' value at all on that path.
    // Unconditionally re-processing every field again below via the flat
    // 'fields' dict is redundant when 'groups' covered it, AND fragile —
    // that path DOES need each field's own 'group' to resolve as a real
    // slug on its own. Confirmed on a real OPS run 2026-08-16: 'groups' was
    // present, save_pod() alone correctly created all 14 shift_report
    // fields in their right groups, and the then-unconditional loop below
    // still re-ran save_field() on every one of them and reported 21 fake
    // "errors" for work that had already succeeded (harmless in that case
    // only because Pods didn't corrupt the already-good result).
    //
    // Fix: check what's ACTUALLY live after save_pod() and only attempt
    // save_field() for whatever's still missing — correct whether the
    // schema used 'groups' (nothing left to do), was flat-only (everything
    // left to do), or mixed (only the uncovered fields left to do).
    $live_pod = $api->load_pod(['name' => $pod_name]);
    $live_field_names = is_array($live_pod['fields'] ?? null) ? array_keys($live_pod['fields']) : [];

    // A brand-new pod means every group its fields reference is brand-new
    // too — nothing for the flat-fields loop below to resolve against yet.
    $known_group_ids = scoop_schema_apply_ensure_groups($fields, $pod_name, $result);

    foreach ($fields as $field_name => $field_def) {
      if (in_array($field_name, $live_field_names, true)) {
        $result['created_fields'][] = "{$pod_name}.{$field_name}";
        continue;
      }

      $field_params = scoop_schema_apply_resolve_group($field_def, $pod_name, $known_group_ids);
      $field_params['pod'] = $pod_name;
      $field_params['name'] = $field_name;
      try {
        $field_id = $api->save_field($field_params);
      } catch (\Throwable $e) {
        $result['errors'][] = "Create field '{$pod_name}.{$field_name}': " . $e->getMessage();
        continue;
      }
      if (is_wp_error($field_id)) {
        $result['errors'][] = "Create field '{$pod_name}.{$field_name}': " . $field_id->get_error_message();
        continue;
      }
      $result['created_fields'][] = "{$pod_name}.{$field_name}";
    }
  }

  foreach ($diff['pods'] as $pod_name => $entry) {
    $pod_schema = $schema[$pod_name] ?? null;
    if ($pod_schema === null) continue;

    if (!empty($entry['changed_pod_attrs'])) {
      $live = scoop_schema_load_live_pod($pod_name);
      $pod_id = (int) ($live['id'] ?? 0);
      if ($pod_id > 0) {
        $pod_params = $pod_schema;
        unset($pod_params['fields']);
        $pod_params['id'] = $pod_id;
        $pod_params['name'] = $pod_name;
        try {
          $ok = $api->save_pod($pod_params);
        } catch (\Throwable $e) {
          $ok = null;
          $result['errors'][] = "Update pod '{$pod_name}': " . $e->getMessage();
        }
        if (is_wp_error($ok)) {
          $result['errors'][] = "Update pod '{$pod_name}': " . $ok->get_error_message();
        } elseif ($ok !== null) {
          $result['updated_pod_attrs'][] = $pod_name;
        }
      } else {
        $result['errors'][] = "Update pod '{$pod_name}': could not resolve live pod id.";
      }
    }

    $schema_fields = $pod_schema['fields'] ?? [];

    $known_group_ids = [];
    if (!empty($entry['missing_fields']) || !empty($entry['changed_fields'])) {
      // Covers the repair case too: a prior run that created the pod (and
      // maybe even its fields, ungrouped) but never got as far as creating
      // its group leaves those fields showing as 'changed' — not 'missing' —
      // on re-sync, still needing the group created before either loop's
      // save_field() call can set the right group_id.
      $known_group_ids = scoop_schema_apply_ensure_groups($schema_fields, $pod_name, $result);
    }

    foreach ($entry['missing_fields'] as $field_name) {
      $field_def = $schema_fields[$field_name] ?? null;
      if ($field_def === null) continue;
      $field_params = scoop_schema_apply_resolve_group($field_def, $pod_name, $known_group_ids);
      $field_params['pod'] = $pod_name;
      $field_params['name'] = $field_name;
      try {
        $field_id = $api->save_field($field_params);
      } catch (\Throwable $e) {
        $result['errors'][] = "Create field '{$pod_name}.{$field_name}': " . $e->getMessage();
        continue;
      }
      if (is_wp_error($field_id)) {
        $result['errors'][] = "Create field '{$pod_name}.{$field_name}': " . $field_id->get_error_message();
        continue;
      }
      $result['created_fields'][] = "{$pod_name}.{$field_name}";
    }

    foreach (array_keys($entry['changed_fields']) as $field_name) {
      $field_def = $schema_fields[$field_name] ?? null;
      if ($field_def === null) continue;

      try {
        $live_field = $api->load_field(['pod' => $pod_name, 'name' => $field_name]);
      } catch (\Throwable $e) {
        $live_field = null;
      }
      $live_field_arr = scoop_schema_field_to_array($live_field);
      $field_id = (int) ($live_field_arr['id'] ?? 0);
      if ($field_id <= 0) {
        $result['errors'][] = "Update field '{$pod_name}.{$field_name}': could not resolve live field id.";
        continue;
      }

      $field_params = scoop_schema_apply_resolve_group($field_def, $pod_name, $known_group_ids, true);
      $field_params['id'] = $field_id;
      $field_params['pod'] = $pod_name;
      $field_params['name'] = $field_name;
      try {
        $saved_id = $api->save_field($field_params);
      } catch (\Throwable $e) {
        $result['errors'][] = "Update field '{$pod_name}.{$field_name}': " . $e->getMessage();
        continue;
      }
      if (is_wp_error($saved_id)) {
        $result['errors'][] = "Update field '{$pod_name}.{$field_name}': " . $saved_id->get_error_message();
        continue;
      }
      $result['updated_fields'][] = "{$pod_name}.{$field_name}";
    }
  }

  return $result;
}
