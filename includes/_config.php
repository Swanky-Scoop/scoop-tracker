<?php


function scoop_is_debug_logging_enabled(): bool {
  return defined('SCOOP_DEBUG_LOG') && (bool) SCOOP_DEBUG_LOG;
}

function scoop_debug_log(string $message): void {
  if (scoop_is_debug_logging_enabled()) {
    error_log($message);
  }
}

function scoop_routes_config(string $batch_key = ''): array {

  // HERE — add 'display_title' => '...' and/or 'icon' => '...' to any type
  // entry below to override its dock button's label/icon (see
  // scoop_client_metadata() in includes/enqueue.php and DOCKING.md). Icon
  // accepts a unicode glyph, an "if:<name>" icon-font marker, an image path,
  // or inline "<svg ...>" markup — see List._buildToggleButton() in
  // assets/ui/_list.js. Default (no override) is the route key itself /
  // its first letter.
  $cfg = [

    'Cabinet' => [
      'display_title' => 'Flavor Plan',
      'icon'         => 'if:l',
      'target'       => 'aside',
      'path'         => '/planning',
      'methods'      => ['GET','POST'],
      'mode'         => 'update',
      'envelope_key' => 'Cabinet',
      'post_type'    => 'slot',
      'pod_name'     => 'slot',
      'allowed_fields_cb' => 'scoop_planning_allowed_slot_fields',
    ],
    'Batch' => [
      'display_title' => 'Add batch',
      'icon'         => 'if:plus',
      'target'       => 'action',
      'path'         => '/batches',
      'methods'      => ['GET','POST'],
      'mode'         => 'create',
      'envelope_key' => 'Batch',
      'post_type'    => 'batch',
      'pod_name'     => 'batch',
      // scoop_create_tubs_for_new_batch (includes/hooks/batch-tub.php) creates
      // 'tub' pod rows as a side effect of every batch save — a real client
      // write to a pod other than pod_name, caught by tests/smoke's lifecycle
      // test (FlavorTub silently stayed stale after a scoped Batch save
      // before this was added). See scoop_client_refresh_scope() in
      // includes/_specs.php, which folds this into the type's writesPods.
      'cascades_to'  => ['tub'],
      'allowed_fields_cb' => 'scoop_batches_allowed_fields',
    ],
    'Task' => [
      'display_title' => 'New Task',
      'icon'         => 'if:plus',
      'target'       => 'action',
      'path'         => '/tasks',
      'methods'      => ['GET','POST'],
      'mode'         => 'create',
      'envelope_key' => 'Task',
      'post_type'    => 'task',
      'pod_name'     => 'task',
      'allowed_fields_cb' => 'scoop_tasks_allowed_fields',
    ],
    'Prep' => [
      'display_title' => 'Add prep',
      'icon'         => 'if:plus',
      'target'       => 'action',
      'path'         => '/preps',
      'methods'      => ['GET','POST'],
      'mode'         => 'create',
      'envelope_key' => 'Prep',
      'post_type'    => 'prep',
      'pod_name'     => 'prep',
      'allowed_fields_cb' => 'scoop_preps_allowed_fields',
    ],
    'RecipeCount' => [
      'display_title' => 'Add recipe count',
      'icon'         => 'if:plus',
      'target'       => 'action',
      'path'         => '/recipe-counts',
      'methods'      => ['GET','POST'],
      'mode'         => 'create',
      'envelope_key' => 'RecipeCount',
      'post_type'    => 'recipe_count',
      'pod_name'     => 'recipe_count',
      'allowed_fields_cb' => 'scoop_recipe_counts_allowed_fields',
    ],
    'Popular' => [
      'display_title' => 'Popular plot',
      'icon'         => 'if:z',
    ],
    'BatchHistory' => [
      'display_title' => 'Batch History',
      'icon'         => 'if:t',
    ],
    'Tasks' => [
      'display_title' => 'Tasks',
      'icon'         => 'if:t',
    ],
    'ItemPivot' => [
      'display_title' => 'Flavor map',
      'icon'         => 'if:m',
      'canvas_mode'  => 'full-nostack',
    ],
    // "What got emptied, by day" log — see assets/models/emptied-log-grid-model.js.
    // No path/methods/mode/envelope_key here (same as ItemPivot above): it's
    // read purely through the /bundle endpoint, open to any authenticated
    // user rather than gated per-route (see includes/_routes.php) — that's
    // what makes it visible to every logged-in role without a _policy.php
    // entry of its own. Its state/use cells ARE inline-editable (so a closed
    // tub can be undone from here) but that writes through FlavorTub's own
    // route/permissions, not a route of this type's — see
    // EmptiedLogGridModel's writeEnvelope and List.writeType in _list.js.
    'EmptiedLog' => [
      'display_title' => 'Emptied Log',
      'icon'         => 'if:q',
      'canvas_mode'  => 'half-stack',
    ],
    'Flavors' => [
      'display_title' => 'Flavor History',
      'icon'         => 'if:s',
    ],
    'Analytics' => [
      'icon'         => 'if:E',
    ],
    'FlavorTub' => [
      'display_title' => 'Curret tubs',
      'icon'         => 'if:f',
      'path'         => '/tubs',
      'methods'      => ['GET','POST'],
      'mode'         => 'update',
      'envelope_key' => 'FlavorTub',
      'post_type'    => 'tub',
      'pod_name'     => 'tub',
      'allowed_fields_cb' => 'scoop_tubs_allowed_fields',
    ],
    'Closeout' => [
      'display_title' => 'Closeouts',
      'icon'         => 'if:x',
      'target'       => 'action',
      'path'         => '/closeouts',
      'methods'      => ['GET','POST'],
      'mode'         => 'create',
      'envelope_key' => 'Closeout',
      'post_type'    => 'closeout',
      'pod_name'     => 'closeout',
      // scoop_process_closeout (includes/hooks/closeout.php) marks matched
      // tubs Emptied as a side effect of every closeout save — same
      // cascading-write shape as Batch above, same fix.
      'cascades_to'  => ['tub'],
      'allowed_fields_cb' => 'scoop_closeouts_allowed_fields',
    ],
    'DateActivity' => [
      'display_title' => 'User activity',
      'icon'         => 'if:i',
      'path'         => '/dateactivity',
      'methods'      => ['GET','POST'],
      'mode'         => 'update',
      'envelope_key' => 'DateActivity',
      'post_type'    => 'tub',
      'pod_name'     => 'tub',
      'canvas_mode'  => 'full-nostack',
      'allowed_fields_cb' => 'scoop_tubs_allowed_fields',
    ],
    'InstockFlavor' => [
      'display_title'=> 'Clurrent flavors',
      'icon'         => 'if:c',
      'path'         => '/instockflavors',
      'methods'      => ['GET','POST'],
      'mode'         => 'update',
      'envelope_key' => 'InstockFlavor',
      'post_type'    => 'flavor',
      'pod_name'     => 'flavor',
      'allowed_fields_cb' => 'scoop_instock_flavor_fields',
    ],
  ];
  
  if ($batch_key === '') {
    return $cfg;
  }
  
  if (!isset($cfg[$batch_key])) return [];
  
  
  return $cfg[$batch_key];
}
