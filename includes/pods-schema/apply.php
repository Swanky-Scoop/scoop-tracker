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

    foreach ($fields as $field_name => $field_def) {
      $field_params = $field_def;
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

    foreach ($entry['missing_fields'] as $field_name) {
      $field_def = $schema_fields[$field_name] ?? null;
      if ($field_def === null) continue;
      $field_params = $field_def;
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

      $field_params = $field_def;
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
