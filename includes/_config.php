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
      'allowed_fields_cb' => 'scoop_batches_allowed_fields',
    ],
    'BatchHistory' => [
      'display_title' => 'Batch History',
      'icon'         => 'if:t',
    ],
    'ItemPivot' => [
      'display_title' => 'Flavor map',
      'icon'         => 'if:m',
    ],
    'Flavors' => [
      'display_title' => 'Flavor History',
      'icon'         => 'if:s',
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
      'allowed_fields_cb' => 'scoop_closeouts_allowed_fields',
    ],
    'DateActivity' => [
      'path'         => '/dateactivity',
      'methods'      => ['GET','POST'],
      'mode'         => 'update',
      'envelope_key' => 'DateActivity',
      'post_type'    => 'tub',
      'pod_name'     => 'tub',
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
