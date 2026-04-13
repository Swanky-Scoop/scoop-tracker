<?php
if (!defined('ABSPATH')) exit;

/**
 * CLOSEOUT AUTOMATION
 * 
 * When a closeout is created with:
 * - tubs_emptied: 3.5 (can be fractional)
 * - flavor: ID
 * - location: ID  
 * - use: ID
 * 
 * This will:
 * 1. Find matching partial tub if needed (±0.2)
 * 2. Find whole tubs (prefer Opened, oldest first)
 * 3. Mark tubs as Emptied, link them bidirectionally
 * 4. Set emptied_at date on tubs
 */

/**
 * Pre-save: Build title for closeout
 */
add_filter('pods_api_pre_save_pod_item_closeout', 'scoop_prepare_closeout', 5, 2);
function scoop_prepare_closeout($pieces, $is_new_item) {
  
  if (!isset($pieces['fields_active']) || !is_array($pieces['fields_active'])) {
    $pieces['fields_active'] = [];
  }

  $tubs_emptied = (float)($pieces['fields']['tubs_emptied']['value'] ?? 0);
  $flavor_id    = scoop_rel_id($pieces['fields']['flavor']['value'] ?? null);
  $location_id  = scoop_rel_id($pieces['fields']['location']['value'] ?? null);

  $flavor_name   = $flavor_id ? get_the_title($flavor_id) : '';
  $location_name = $location_id ? get_the_title($location_id) : '';

  if ($tubs_emptied > 0 && $flavor_name) {
    $post_title = sprintf(
      '%.2fx %s%s',
      $tubs_emptied,
      $flavor_name,
      $location_name ? " @ {$location_name}" : ''
    );
    
    $pieces['object_fields']['post_title']['value'] = $post_title;
    $pieces['object_fields']['post_name']['value'] = sanitize_title($post_title);
    
    if (!in_array('post_title', $pieces['fields_active'], true)) {
      $pieces['fields_active'][] = 'post_title';
    }
  }

  return $pieces;
}

/**
 * Post-save: Process closeout (idempotent)
 */
add_filter('pods_api_post_save_pod_item_closeout', 'scoop_process_closeout', 20, 3);
function scoop_process_closeout($pieces, $is_new_item, $id) {
  
  $closeout_id = (int)$id;
  if (!$closeout_id) return $pieces;

  return scoop_guard(
    "process_closeout:{$closeout_id}", 
    function() use ($pieces, $closeout_id) 
    {
      $closeout = pods('closeout', $closeout_id);
      if (!$closeout || !$closeout->exists()) return $pieces;

      // Idempotency: skip if already has linked tubs
      $existing_tubs = $closeout->field('tub');
      if (!empty($existing_tubs)) return $pieces;
        // Extract field values
      $location_raw = $closeout->field('location');
      $flavor_raw   = $closeout->field('flavor');
      $use_raw      = $closeout->field('use');
      $need_raw     = $closeout->field('tubs_emptied');
    
      $location_id  = scoop_rel_id($location_raw);
      $flavor_id    = scoop_rel_id($flavor_raw);
      $use_id       = scoop_rel_id($use_raw);
      $need         = (float)$need_raw;

      if (!$location_id || !$flavor_id || !$use_id || $need <= 0) {
        scoop_fail_closeout($closeout_id, 'Missing required data (location/flavor/use/tubs_emptied)');
        return $pieces;
      }

      // Match tubs with fractional logic
      $match = scoop_match_closeout_tubs($flavor_id, $location_id, $need);
    
      if ($match['error']) {
        scoop_fail_closeout($closeout_id, $match['error']);
        return $pieces;
      }

      if (empty($match['tubs'])) {
        scoop_fail_closeout($closeout_id, 'No eligible tubs found');
        return $pieces;
      }

      // Mark tubs as emptied
      $updated_tub_ids = [];
      $total_amount = 0;
      $emptied_at = current_time('mysql');
    
      foreach ($match['tubs'] as $idx => $tub_obj) {
        $tub_id = (int)$tub_obj->id;  // ← Changed from ->id()
        if (!$tub_id) continue;
        
        $amount = (float)$tub_obj->amount;  // ← Changed from ->field('amount')
        $update_data = [
          'state'      => 'Emptied',
          'use'        => $use_id,
          'closeout'   => $closeout_id,
          'emptied_at' => $emptied_at,
        ];
      
        $res = pods_api()->save_pod_item([
          'pod'  => 'tub',
          'id'   => $tub_id,
          'data' => $update_data,
        ]);
      
        if ($res) {
          $updated_tub_ids[] = $tub_id;
          $total_amount += $amount;
        }
      }

      // Link tubs back to closeout
      $note = sprintf(
        'Closed %.2f tubs (requested %.2f). Tub IDs: %s',
        $total_amount,
        $need,
        implode(', ', $updated_tub_ids)
      );
    
      $closeout_update = [
        'tub'          => $updated_tub_ids,
        'post_content' => $note,
      ];
    
      $closeout_res = pods_api()->save_pod_item([
        'pod'  => 'closeout',
        'id'   => $closeout_id,
        'data' => $closeout_update,
      ]);

      return $pieces;
    }, 
    $pieces

  );
}

/**
 * Match tubs: handle fractional + whole logic
 */
function scoop_match_closeout_tubs(int $flavor_id, int $location_id, float $need): array {
  
  $whole = floor($need);
  $fraction = $need - $whole;
  
  $results = [
    'tubs'  => [],
    'total' => 0,
    'error' => null
  ];
  
  // Step 1: Match fractional part if needed
  if ($fraction > 0.01) {
    $fractional_tub = scoop_find_fractional_tub($flavor_id, $location_id, $fraction);
    
    if (!$fractional_tub) {
      $error = sprintf(
        'No partial tub found matching %.2f (need %.2f ± 0.2)',
        $fraction,
        $fraction
      );
      $results['error'] = $error;
      return $results;
    }
    
    $frac_amount = (float)$fractional_tub->field('amount') ?: 1.0;
    
    $results['tubs'][] = $fractional_tub;
    $results['total'] += $frac_amount;
  }
  
  // Step 2: Match whole tubs
  if ($whole > 0) {
    $whole_tubs = scoop_find_whole_tubs($flavor_id, $location_id, $whole);
    
    if (count($whole_tubs) < $whole) {
      $error = sprintf(
        'Only found %d whole tubs, need %d',
        count($whole_tubs),
        $whole
      );
      $results['error'] = $error;
      return $results;
    }
    
    foreach ($whole_tubs as $tub) {
      $whole_amount = (float)$tub->amount;  // ← Changed
      $results['tubs'][] = $tub;
      $results['total'] += $whole_amount;
    }
  }
  
  return $results;
}

/**
 * Find one partial tub matching target ± 0.2
 */
function scoop_find_fractional_tub(int $flavor_id, int $location_id, float $target): ?object {
  $min = max(0.01, $target - 0.2);
  $max = min(1.0, $target + 0.2);
  
  $where = scoop_closeout_tub_where($flavor_id, $location_id);
  $where .= " AND amount >= {$min} AND amount <= {$max} AND amount < 1";
  
  $tub = pods('tub', [
    'where'   => $where,
    'orderby' => "
      CASE WHEN state = 'Opened' THEN 0 ELSE 1 END ASC,
      post_date ASC,
      `index` ASC
    ",
    'limit'   => 1,
  ]);
  
  $count = $tub ? $tub->total() : 0;
  
  return ($tub && $tub->total() > 0) ? $tub : null;
}

/**
 * Find whole tubs (amount >= 0.8, prefer Opened, oldest first)
 */
function scoop_find_whole_tubs(int $flavor_id, int $location_id, int $count): array {

  $where = scoop_closeout_tub_where($flavor_id, $location_id);
  $where .= " AND (amount IS NULL OR amount >= 0.8)";
  
  $tub = pods('tub', [
    'where'   => $where,
    'orderby' => "
      CASE WHEN state = 'Opened' THEN 0 ELSE 1 END ASC,
      post_date ASC,
      `index` ASC
    ",
    'limit'   => $count,
  ]);
  
  $total = $tub ? $tub->total() : 0;
  
  $results = [];
  if ($tub && $tub->total() > 0) {
    $idx = 0;
    while ($tub->fetch()) {
      $tub_id = $tub->id();
      $amount = $tub->field('amount') ?: 1.0;
      $state = $tub->field('state');
      
      // Store as simple object with just the data we need
      $results[] = (object)[
        'id'     => $tub_id,
        'amount' => $amount,
        'state'  => $state,
      ];
      $idx++;
    }
  }
  
  return $results;
}

/**
 * Build WHERE clause for eligible tubs
 */
function scoop_closeout_tub_where(int $flavor_id, int $location_id): string {
  
  // Get valid states from Pods (dynamic!)
  $state_options = scoop_pods_dropdown_options('tub', 'state');
  
  if (empty($state_options)) {
    // Fallback if helper fails
    $valid_states = ['Hardening', 'Freezing', 'Tempering', 'Opened'];
  } else {
    $valid_states = array_filter(
      array_column($state_options, 'key'),
      fn($s) => $s !== 'Emptied' && $s !== '__override__'
    );
  }
  
  $state_sql = "'" . implode("','", array_map('esc_sql', $valid_states)) . "'";
  
  $where = sprintf(
    "flavor.ID = %d AND location.ID = %d AND state IN (%s)",
    $flavor_id,
    $location_id,
    $state_sql
  );
  
  return $where;
}

/**
 * Mark closeout as failed with error note
 */
function scoop_fail_closeout(int $closeout_id, string $error): void {
  
  $res = pods_api()->save_pod_item([
    'pod'  => 'closeout',
    'id'   => $closeout_id,
    'data' => [
      'post_content' => 'ERROR: ' . $error,
    ],
  ]);
  
}