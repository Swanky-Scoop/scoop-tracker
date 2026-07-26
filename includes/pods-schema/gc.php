<?php
if (!defined('ABSPATH')) exit;

/**
 * Opt-in garbage collection: deletes only fields, and only ones the user
 * explicitly checked on the Schema Sync page. Deliberately has no path to
 * delete a whole pod — a Pods CPT delete can drop its entire data table,
 * which is out of scope for this tool (do that by hand in the Pods admin
 * UI if it's ever really needed).
 *
 * $targets: [ ['pod' => pod_name, 'field' => field_name], ... ]
 */
function scoop_schema_gc_fields(array $targets): array {
  $result = [
    'deleted_fields' => [],
    'errors' => [],
  ];

  if (!scoop_pods_ready()) {
    $result['errors'][] = 'Pods API is not available on this environment.';
    return $result;
  }

  $api = pods_api();

  foreach ($targets as $target) {
    $pod_name = (string) ($target['pod'] ?? '');
    $field_name = (string) ($target['field'] ?? '');
    if ($pod_name === '' || $field_name === '') continue;

    try {
      $ok = $api->delete_field(['pod' => $pod_name, 'name' => $field_name]);
    } catch (\Throwable $e) {
      $result['errors'][] = "Delete field '{$pod_name}.{$field_name}': " . $e->getMessage();
      continue;
    }
    if (is_wp_error($ok)) {
      $result['errors'][] = "Delete field '{$pod_name}.{$field_name}': " . $ok->get_error_message();
      continue;
    }
    $result['deleted_fields'][] = "{$pod_name}.{$field_name}";
  }

  return $result;
}
