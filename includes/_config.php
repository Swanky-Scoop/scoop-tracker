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
  
  $cfg = [

    'Cabinet' => [
      'path'         => '/planning',
      'methods'      => ['GET','POST'],
      'mode'         => 'update',
      'envelope_key' => 'Cabinet',
      'post_type'    => 'slot',
      'pod_name'     => 'slot',
      'allowed_fields_cb' => 'scoop_planning_allowed_slot_fields',
    ],
    'Batch' => [
      'path'         => '/batches',
      'methods'      => ['GET','POST'],
      'mode'         => 'create',
      'envelope_key' => 'Batch',
      'post_type'    => 'batch',
      'pod_name'     => 'batch',
      'allowed_fields_cb' => 'scoop_batches_allowed_fields',
    ],
    'FlavorTub' => [
      'path'         => '/tubs',
      'methods'      => ['GET','POST'],
      'mode'         => 'update',
      'envelope_key' => 'FlavorTub',
      'post_type'    => 'tub',
      'pod_name'     => 'tub',
      'allowed_fields_cb' => 'scoop_tubs_allowed_fields',
    ],
    'Closeout' => [
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
    ]
  ];
  
  if ($batch_key === '') {
    return $cfg;
  }
  
  if (!isset($cfg[$batch_key])) return [];
  
  
  return $cfg[$batch_key];
}
