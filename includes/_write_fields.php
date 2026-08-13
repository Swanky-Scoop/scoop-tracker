<?php 
/**
 * Fields (Pods slugs) that the Cabinet endpoint is allowed to write.
 */
function scoop_allowed_fields_for_entity(\WP_User $u, string $entity_key, array $route_fields): array {
  $policy_fields = scoop_user_writeable_fields($u, $entity_key); // from policy
  if (empty($policy_fields)) return [];
  return array_values(array_intersect($route_fields, $policy_fields));
}

function scoop_planning_allowed_slot_fields(\WP_User $u): array {
  $route_fields =  scoop_entity_specs('slot')['writeable']; // ['current_flavor','immediate_flavor','next_flavor'];
  return scoop_allowed_fields_for_entity($u, 'slot', $route_fields);
}

function scoop_batches_allowed_fields(\WP_User $u): array {
  return [ 'flavor','count' ];
}

function scoop_tubs_allowed_fields(\WP_User $u): array {
  $route_fields = scoop_entity_specs('tub')['writeable']; // what this endpoint supports
  return scoop_allowed_fields_for_entity($u, 'tub', $route_fields);
}

function scoop_closeouts_allowed_fields(\WP_User $u): array {
  return [ 'tubs_emptied', 'flavor', 'use', 'location', 'order']; //'amount'
}

function scoop_shift_reports_allowed_fields(\WP_User $u): array {
  $route_fields = scoop_entity_specs('shift_report')['writeable'];
  return scoop_allowed_fields_for_entity($u, 'shift_report', $route_fields);
}

function scoop_cake_orders_allowed_fields(\WP_User $u): array {
  $route_fields = scoop_entity_specs('cake_order')['writeable'];
  return scoop_allowed_fields_for_entity($u, 'cake_order', $route_fields);
}

function scoop_save_pod_field( string $pod_name, int $id, string $field, $value ) {
  $value = scoop_coerce_value($field, $value);
  return scoop_pods_api_save($pod_name, $id, [ $field => $value ]);
}

function scoop_save_pod_fields( string $pod_name, int $id, array $data ) {
  scoop_debug_log("SAVE helper hit for pod={$pod_name}");
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
  scoop_debug_log("CREATE helper hit for pod={$pod_name}");
  if (!function_exists('pods_api')) {
    return new WP_Error('pods_missing', 'Pods API not available.');
  }

  $allowed = array_flip($allowed_fields);
  $clean = [];
  foreach ($data as $k => $v) {
    if (!isset($allowed[$k])) continue;
    $clean[$k] = scoop_coerce_value($k, $v);
  }

  if ($pod_name === 'batch') {
    $flavor_id = function_exists('scoop_rel_id') ? scoop_rel_id($clean['flavor'] ?? 0) : (int)($clean['flavor'] ?? 0);
    $count = isset($clean['count']) && is_numeric($clean['count']) ? (float)$clean['count'] : 0;

    if ($flavor_id <= 0) {
      return new WP_Error('batch_missing_flavor', 'Batch create requires a flavor.');
    }
    if ($count <= 0) {
      return new WP_Error('batch_missing_count', 'Batch create requires a positive count.');
    }

    $clean['flavor'] = $flavor_id;
    $clean['count'] = $count;

    if (function_exists('scoop_batch_title_for_data')) {
      $title = scoop_batch_title_for_data($flavor_id, $count);
      if ($title !== '') {
        $clean['post_title'] = $title;
        $clean['post_name'] = sanitize_title($title);
        $clean['post_status'] = 'publish';
      }
    }
  } elseif (empty($clean)) {
    return new WP_Error('create_empty_payload', 'Create failed: no writeable fields were provided.');
  }

  $params = ['pod' => $pod_name, 'data' => $clean];
  $id = pods_api()->save_pod_item($params);

  if (is_wp_error($id)) return $id;

  $id = (int)$id;
  if ($id <= 0) return new WP_Error('create_failed', 'Create failed (no id returned).');

  return $id;
}
