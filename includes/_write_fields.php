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
  return [ 'flavor','count','task','done' ];
}

function scoop_preps_allowed_fields(\WP_User $u): array {
  return [ 'ingredient','other','count','units','task' ];
}

function scoop_recipe_counts_allowed_fields(\WP_User $u): array {
  return [ 'recipe','count','task' ];
}

function scoop_tasks_allowed_fields(\WP_User $u): array {
  return [ 'target','other' ];
}

// Inline-edit path for existing tasks (the Tasks grid's Done toggle and
// Assigned-to FindIt — see assets/models/tasks-grid-model.js). Separate
// route from 'Task' above (which is create-only, mode:'create') since
// scoop_handle_request() dispatches strictly on cfg['mode'] — one route
// can't be both. 'completed' is deliberately NOT allowed here — it's
// system-stamped by hooks/task-state.php whenever 'done' flips, same as
// tub.emptied_at, never a real client-writable field.
function scoop_task_edits_allowed_fields(\WP_User $u): array {
  return [ 'done','target' ];
}

function scoop_tubs_allowed_fields(\WP_User $u): array {
  $route_fields = scoop_entity_specs('tub')['writeable']; // what this endpoint supports
  return scoop_allowed_fields_for_entity($u, 'tub', $route_fields);
}

function scoop_closeouts_allowed_fields(\WP_User $u): array {
  return [ 'tubs_emptied', 'flavor', 'use', 'location', 'order']; //'amount'
}

// Live from Pods, not a hand-maintained _specs.php list — see
// WHITEBOARD-INGESTION.md. shift_report/cake_order are single-purpose forms
// with identical field access across their three authorized roles (no
// per-field distinction has ever been needed), so there's no security
// reason to require a matching code change every time a field is added or
// renamed in Pods admin — whoever can do that already has full site-admin
// capability.
function scoop_shift_reports_allowed_fields(\WP_User $u): array {
  $route_fields = scoop_pod_field_names('shift_report');
  return scoop_allowed_fields_for_entity($u, 'shift_report', $route_fields);
}

function scoop_cake_orders_allowed_fields(\WP_User $u): array {
  $route_fields = scoop_pod_field_names('cake_order');
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

  if (empty($clean)) {
    return new WP_Error('create_empty_payload', 'Create failed: no writeable fields were provided.');
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
      }
    }
  }

  // Task supports a user-supplied title (assets/ui/task-form.js's optional
  // "Title" field) in place of the auto-generated one. 'post_title' is a WP
  // post object field, not a Pods field, so it's read straight off the raw
  // $data here (same as 'flavor'/'count' aren't gated by $allowed_fields for
  // batch above) rather than added to scoop_tasks_allowed_fields(). The flag
  // tells scoop_set_task_title() (includes/hooks/task-titles.php) to skip
  // auto-generation entirely for this save — unset by that filter once read,
  // single-request-lifetime only.
  if ($pod_name === 'task') {
    $custom_title = sanitize_text_field(trim((string)($data['post_title'] ?? '')));
    if ($custom_title !== '') {
      $clean['post_title'] = $custom_title;
      $clean['post_name'] = sanitize_title($custom_title);
      $GLOBALS['scoop_task_custom_title'] = true;
    }
  }

  // Pods relationship pick fields default to pick_post_status=publish, so a
  // draft child (wp_insert_post()'s own default when post_status isn't set
  // explicitly) is silently invisible to any relationship pointing at it —
  // e.g. a task's batches/preps/recipe_counts list would just look empty.
  // Applied to every pod created through this helper, not just batch, unless
  // a per-pod block above (or the caller) already set one explicitly.
  if (!isset($clean['post_status'])) {
    $clean['post_status'] = 'publish';
  }

  $params = ['pod' => $pod_name, 'data' => $clean];
  $id = pods_api()->save_pod_item($params);

  if (is_wp_error($id)) return $id;

  $id = (int)$id;
  if ($id <= 0) return new WP_Error('create_failed', 'Create failed (no id returned).');

  return $id;
}
