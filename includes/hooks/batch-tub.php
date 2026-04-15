<?php
if (!defined('ABSPATH')) exit;

/**
 * Batch + Tub hooks (refactored)
 *
 * Goals:
 * - Stable title/slug generation for batch
 * - Deterministic tub creation (DB-driven; not dependent on $pieces shape)
 * - Use Pods API for creates/updates so Pods hooks fire consistently
 * - Guard against accidental recursion
 * - Quiet logs unless SCOOP_DEBUG is enabled
 */

/** ------------------------------------------------------------
 *  Logging / guards
 * ------------------------------------------------------------ */
if (!function_exists('scoop_log')) {
  function scoop_log(string $msg): void {
    if (defined('SCOOP_DEBUG') && SCOOP_DEBUG) {
      error_log($msg);
    }
  }
}

if (!function_exists('scoop_guard')) {
  /**
   * Execute $fn with a keyed re-entrancy guard.
   * Returns $default if guard is already set.
   */
  function scoop_guard(string $key, callable $fn, $default = null) {
    $GLOBALS['scoop_guard'] ??= [];
    if (!empty($GLOBALS['scoop_guard'][$key])) return $default;
    $GLOBALS['scoop_guard'][$key] = true;
    try {
      return $fn();
    } finally {
      unset($GLOBALS['scoop_guard'][$key]);
    }
  }
}

if (!function_exists('scoop_rel_id')) {
  /**
   * Extract a single related item ID from a Pods relationship field value.
   */
  function scoop_rel_id($val): int {
    if (empty($val)) return 0;
    if (is_numeric($val)) return (int)$val;

    if (is_array($val)) {
      $first = reset($val);
      if (is_numeric($first)) return (int)$first;

      if (is_array($first)) {
        if (isset($first['id']) && is_numeric($first['id'])) return (int)$first['id'];
        if (isset($first['ID']) && is_numeric($first['ID'])) return (int)$first['ID'];
      }

      if (is_object($first)) {
        if (isset($first->id) && is_numeric($first->id)) return (int)$first->id;
        if (isset($first->ID) && is_numeric($first->ID)) return (int)$first->ID;
      }
    }

    if (is_object($val)) {
      if (isset($val->id) && is_numeric($val->id)) return (int)$val->id;
      if (isset($val->ID) && is_numeric($val->ID)) return (int)$val->ID;
    }

    if (is_string($val)) {
      $trim = trim($val);
      if (ctype_digit($trim)) return (int)$trim;
    }

    return 0;
  }
}

/** ------------------------------------------------------------
 *  Config
 * ------------------------------------------------------------ */
if (!defined('SCOOP_DEFAULT_LOCATION_ID')) {
  define('SCOOP_DEFAULT_LOCATION_ID', 935); // Woodinville (override in wp-config.php or _config.php)
}
if (!function_exists('scoop_get_default_location_id')) {
  function scoop_get_default_location_id(): int {
    return (int)SCOOP_DEFAULT_LOCATION_ID;
  }
}

/** ------------------------------------------------------------
 *  Batch hooks
 * ------------------------------------------------------------ */

/**
 * Set Batch title from flavor + date + count before saving.
 * Title: "<FlavorName> <Y-m-d H:i>_<count>"
 */
add_filter('pods_api_pre_save_pod_item_batch', 'scoop_set_batch_title', 10, 2);
function scoop_set_batch_title($pieces, $is_new_item) {

  // Ensure fields_active exists
  if (!isset($pieces['fields_active']) || !is_array($pieces['fields_active'])) {
    $pieces['fields_active'] = [];
  }
  if (!in_array('post_title', $pieces['fields_active'], true)) {
    $pieces['fields_active'][] = 'post_title';
  }
  if (!isset($pieces['object_fields']['post_title'])) {
    $pieces['object_fields']['post_title'] = ['value' => ''];
  }

  $flavor_id = scoop_rel_id($pieces['fields']['flavor']['value'] ?? null);
  if (!$flavor_id) {
    scoop_log('scoop_set_batch_title: flavor missing/invalid');
    return $pieces;
  }

  $flavor_name = (string)get_the_title($flavor_id);
  if ($flavor_name === '') {
    scoop_log("scoop_set_batch_title: could not resolve flavor title id={$flavor_id}");
    return $pieces;
  }

  $count = 1;
  $raw_count = $pieces['fields']['count']['value'] ?? null;
  if (is_numeric($raw_count)) {
    $count = max(1, (float)$raw_count);
  }
  $date_str   = current_time('Y-m-d H:i');
  $this_title = "{$flavor_name} {$date_str}_{$count}";

  $pieces['object_fields']['post_title']['value'] = $this_title;

  // keep slug in sync
  if (!isset($pieces['object_fields']['post_name'])) {
    $pieces['object_fields']['post_name'] = ['value' => ''];
  }
  $pieces['object_fields']['post_name']['value'] = sanitize_title($this_title);

  return $pieces;
}

/** ------------------------------------------------------------
 *  Tub hooks
 * ------------------------------------------------------------ */

/**
 * After a Batch is saved, create related Tub pods (once per batch).
 * - Does NOT trust $is_new_item
 * - Skips if any tub already references this batch
 * - Reads flavor + count from DB for reliability
 * - Uses Pods API so tub hooks fire
 */
add_filter('pods_api_post_save_pod_item_batch', 'scoop_create_tubs_for_new_batch', 30, 3);
function scoop_create_tubs_for_new_batch($pieces, $is_new_item, $id) {
  $batch_id = (int)$id;
  if (!$batch_id || !function_exists('pods')) return $pieces;

  return scoop_guard("create_tubs_for_batch:{$batch_id}", function() use ($pieces, $batch_id) {

    // Skip if tub already exist
    $existing = pods('tub', [
      'where' => 'batch.ID = ' . $batch_id,
      'limit' => 1,
    ]);
    if ($existing && $existing->total() > 0) {
      scoop_log("scoop_create_tubs_for_new_batch: batch {$batch_id} already has tub");
      return $pieces;
    }

    $batch = pods('batch', $batch_id);
    if (!$batch || !$batch->exists()) {
      scoop_log("scoop_create_tubs_for_new_batch: batch {$batch_id} not found");
      return $pieces;
    }

    $count     = (float)$batch->field('count');
    $flavor_id = (int)$batch->field('flavor.ID');

    if ($count <= 0) {
      scoop_log("scoop_create_tubs_for_new_batch: count<=0 for batch {$batch_id}");
      return $pieces;
    }
    if ($flavor_id <= 0) {
      scoop_log("scoop_create_tubs_for_new_batch: flavor missing for batch {$batch_id}");
      return $pieces;
    }

    $location_id = (int)scoop_get_default_location_id();

    $batch_title = get_the_title($batch_id);
    if (!$batch_title) $batch_title = 'Batch ' . $batch_id;

    if (!function_exists('pods_api') || !is_object(pods_api())) {
      scoop_log("scoop_create_tubs_for_new_batch: pods_api missing");
      return $pieces;
    }
    
    $fraction = fmod($count, 1);
    if($fraction > 0){
      $last = ceil($count);
      $tub_frac_args = [
        'post_title'  => "{$batch_title}|{$last}",
        'batch'       => $batch_id,
        'flavor'      => $flavor_id,
        'index'       => $last,
        'amount'      => $fraction,
        'post_status' => 'publish',
      ];
      if ($location_id) $tub_frac_args['location'] = $location_id;
      $new_tub_frac_id = pods_api()->save_pod_item([
        'pod'  => 'tub',
        'data' => $tub_frac_args,
      ]);
      scoop_log("created tub id={$new_tub_frac_id} batch={$batch_id} flavor={$flavor_id} index={$last} amount={$fraction}");
    }
    

    for ($i = 1; $i <= $count; $i++) {
      $tub_args = [
        'post_title'  => "{$batch_title}|{$i}",
        'batch'       => $batch_id,
        'flavor'      => $flavor_id,
        'index'       => $i,
        'post_status' => 'publish',
      ];
      if ($location_id) $tub_args['location'] = $location_id;

      $new_tub_id = pods_api()->save_pod_item([
        'pod'  => 'tub',
        'data' => $tub_args,
      ]);

      scoop_log("created tub id={$new_tub_id} batch={$batch_id} flavor={$flavor_id} index={$i}");
    }

    // Ensure batch is published (optional)
    wp_update_post([
      'ID'          => $batch_id,
      'post_status' => 'publish',
    ]);

    return $pieces;
  }, $pieces);
}

/**
 * Whenever a tub is created or updated, bump its flavor.modified_date.
 * Guarded per-tub to avoid recursion.
 */
add_filter('pods_api_post_save_pod_item_tub', 'scoop_bump_flavor_modified_date_on_tub_save', 30, 3);
function scoop_bump_flavor_modified_date_on_tub_save($pieces, $is_new_item, $id) {
  $tub_id = (int)$id;
  if (!$tub_id || !function_exists('pods')) return $pieces;

  return scoop_guard("bump_flavor_on_tub:{$tub_id}", function() use ($pieces, $tub_id) {

    $tub = pods('tub', $tub_id);
    if (!$tub || !$tub->exists()) return $pieces;

    $flavor_id = (int)$tub->field('flavor.ID');
    if (!$flavor_id) return $pieces;

    if (function_exists('pods_api') && is_object(pods_api())) {
      pods_api()->save_pod_item([
        'pod'  => 'flavor',
        'id'   => $flavor_id,
        'data' => ['modified_date' => current_time('mysql')],
      ]);
      scoop_log("bumped flavor.modified_date flavor={$flavor_id} via tub={$tub_id}");
    }

    return $pieces;
  }, $pieces);
}
