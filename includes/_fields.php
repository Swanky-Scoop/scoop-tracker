<?php 
/**
 * Fields (Pods slugs) that the Cabinet endpoint is allowed to write.
 */
function scoop_planning_allowed_slot_fields( $user ): array {
  return [ 'current_flavor', 'immediate_flavor', 'next_flavor' ];
}
function scoop_batches_allowed_fields( $user ): array {
  return [ 'flavor','count' ];
}
function scoop_tubs_allowed_fields( $user ): array {
  return [ 'state', 'use', 'amount']; //'amount' 
}
function scoop_closeouts_allowed_fields( $user ): array {
  return [ 'tubs_emptied', 'flavor', 'use', 'location', 'order']; //'amount' 
}

function scoop_dateactivity_allowed_fields( $user ): array {
  return [ 'state', 'use', 'amount' ];

}

function scoop_save_pod_field( string $pod_name, int $id, string $field, $value ) {
  $value = scoop_coerce_value($field, $value);
  return scoop_pods_api_save($pod_name, $id, [ $field => $value ]);
}

function scoop_save_pod_fields( string $pod_name, int $id, array $data ) {
  try {
    $clean = [];
    foreach ($data as $field => $value) {
      $clean[$field] = scoop_coerce_value((string)$field, $value);
    }
    return scoop_pods_api_save($pod_name, $id, $clean);
  } catch ( \Throwable $e ) {
    return $e->getMessage();
  }
}


function scoop_create_pod_item(string $pod_name, array $allowed_fields, array $data) {
  if (!function_exists('pods_api')) {
    return new WP_Error('pods_missing', 'Pods API not available.');
  }

  $allowed = array_flip($allowed_fields);
  $clean = [];
  foreach ($data as $k => $v) {
    if (!isset($allowed[$k])) continue;
    $clean[$k] = scoop_coerce_value($k, $v);
  }

  $params = ['pod' => $pod_name, 'data' => $clean];
  $id = pods_api()->save_pod_item($params);

  if (is_wp_error($id)) return $id;

  $id = (int)$id;
  if ($id <= 0) return new WP_Error('create_failed', 'Create failed (no id returned).');

  return $id;
}