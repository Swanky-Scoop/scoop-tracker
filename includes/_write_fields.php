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

// 'task'/'done' added for task-tracked batches created inline from the
// 'Add task' form (see TaskCreateForm) — the ordinary Batch GUI
// (assets/models/batch-grid-model.js) never sends either field, so
// broadening what's allowed here doesn't change its behavior at all, it
// just lets a caller that DOES send them (this one) actually write them.
// TaskCreateForm always sends done=false explicitly for a batch it
// creates (never omits it) — see the tub-cascade guard's own comment in
// includes/hooks/batch-tub.php for why that distinction (explicitly false
// vs. simply absent) is exactly what it's keyed on.
function scoop_batches_allowed_fields(\WP_User $u): array {
  return [ 'flavor','count','task','done' ];
}

// Same fixed-list shape as scoop_batches_allowed_fields() above, not the
// live-Pods-field-names pattern shift_report/cake_order use — task's
// authoring form is deliberately just target+other (see the 'Task' entry
// in _config.php), so there's no reason for every field Pods admin might
// grow on the task pod later (recipe_counts/batches/preps — those get set
// by their own counter records, not this form) to automatically become
// writeable from this one route.
function scoop_tasks_allowed_fields(\WP_User $u): array {
  return [ 'target','other' ];
}

// Unlike scoop_tasks_allowed_fields() above, 'task' IS included here — a
// recipe_count/prep created inline from the 'Add task' form (see
// TaskCreateForm) needs to write its own 'task' field to attach itself
// back to the task that was just created. task.recipe_counts/task.preps
// are configured as bidirectional (sister) Pods fields with
// recipe_count.task/prep.task (confirmed directly via
// pods_api()->load_field() against the real local pod config), so setting
// this one field is enough — Pods syncs the task's own reverse list
// itself, no follow-up write to the task needed.
function scoop_recipe_counts_allowed_fields(\WP_User $u): array {
  return [ 'recipe','count','task' ];
}

function scoop_preps_allowed_fields(\WP_User $u): array {
  return [ 'ingredient','other','count','units','task' ];
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

  // Same class of bug this project has hit (and fixed the same way) for
  // tub and supply: pods_api()->save_pod_item() without an explicit
  // post_status defaults new posts to 'draft', and Pods relationship
  // fields default to pick_post_status=publish — so a draft item is
  // silently invisible to any relationship pointing at it (task.other,
  // kitchen_report.recipe_counts, etc.) even though the row itself saved
  // fine. Confirmed directly: a Task created through this exact path
  // landed as 'draft' before this line existed. batch's own branch above
  // already set this explicitly; generalized here so every other pod
  // created through this one helper (task, recipe_count, prep, base_pack,
  // kitchen_report) gets it too, without needing its own copy of the same
  // fix. Only applies when the caller didn't already set post_status
  // itself (batch's branch above still wins for its own value).
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
