<?php

/**
 * Auto-populate created_on and changed_on fields when tub is created
 * Runs on ANY creation mechanism (WP admin, API, Pods, etc.)
 * Priority 5 - runs before scoop_enforce_tub_rules
 */
add_filter('pods_api_pre_save_pod_item_tub', 'scoop_auto_set_tub_created_on', 5, 2);
function scoop_auto_set_tub_created_on($pieces, $is_new_item) {
  // Only run on new items
  if (!$is_new_item) return $pieces;
  
  error_log('scoop_auto_set_tub_created_on: Setting created_on and changed_on for new tub');
  
  // Set both created_on and changed_on to current time (matches what WP uses for post_date)
  $now = current_time('mysql');
  
  $pieces['fields']['created_on']['value'] = $now;
  $pieces['fields']['changed_on']['value'] = $now;
  
  // Ensure both are marked as active so Pods saves them
  if (!isset($pieces['fields_active']) || !is_array($pieces['fields_active'])) {
    $pieces['fields_active'] = [];
  }
  
  if (!in_array('created_on', $pieces['fields_active'], true)) {
    $pieces['fields_active'][] = 'created_on';
  }
  
  if (!in_array('changed_on', $pieces['fields_active'], true)) {
    $pieces['fields_active'][] = 'changed_on';
  }
  
  error_log('scoop_auto_set_tub_created_on: created_on and changed_on both set to ' . $now);
  
  return $pieces;
}

/**
 * Ensure WP post_modified updates on every tub edit saved via Pods API.
 * Runs on edits only.
 */
add_filter('pods_api_pre_save_pod_item_tub', 'scoop_touch_tub_post_modified', 9, 2);
function scoop_touch_tub_post_modified($pieces, $is_new_item) {
  if ($is_new_item) return $pieces;

  $now_local = current_time('mysql');
  $now_gmt   = current_time('mysql', true);

  if (!isset($pieces['object_fields']) || !is_array($pieces['object_fields'])) {
    $pieces['object_fields'] = [];
  }

  $pieces['object_fields']['post_modified']['value']     = $now_local;
  $pieces['object_fields']['post_modified_gmt']['value'] = $now_gmt;

  if (!isset($pieces['fields_active']) || !is_array($pieces['fields_active'])) {
    $pieces['fields_active'] = [];
  }
  if (!in_array('post_modified', $pieces['fields_active'], true)) {
    $pieces['fields_active'][] = 'post_modified';
  }
  if (!in_array('post_modified_gmt', $pieces['fields_active'], true)) {
    $pieces['fields_active'][] = 'post_modified_gmt';
  }

  return $pieces;
}

/**
 * Auto-update changed_on field whenever tub is edited
 * Priority 8 - runs before state rules
 */
// add_filter('pods_api_pre_save_pod_item_tub', 'scoop_auto_update_tub_changed_on', 8, 2);

function scoop_auto_update_tub_changed_on($pieces, $is_new_item) {
  // Only run on edits, not new items
  if ($is_new_item) return $pieces;
  
  error_log('scoop_auto_update_tub_changed_on: Updating changed_on for edited tub');
  
  // Check if user is explicitly setting changed_on themselves
  $user_setting_changed_on = isset($pieces['fields']['changed_on']) 
    && array_key_exists('value', (array)$pieces['fields']['changed_on']);
  
  // If user is NOT explicitly setting it, auto-update to now
  if (!$user_setting_changed_on) {
    $now = current_time('mysql');
    
    $pieces['fields']['changed_on']['value'] = $now;
    
    // Ensure it's marked as active so Pods saves it
    if (!isset($pieces['fields_active']) || !is_array($pieces['fields_active'])) {
      $pieces['fields_active'] = [];
    }
    
    if (!in_array('changed_on', $pieces['fields_active'], true)) {
      $pieces['fields_active'][] = 'changed_on';
    }
    
    error_log('scoop_auto_update_tub_changed_on: changed_on auto-updated to ' . $now);
  } else {
    error_log('scoop_auto_update_tub_changed_on: User is explicitly setting changed_on, skipping auto-update');
  }
  
  return $pieces;
}

/**
 * Enforce tub state transition rules and auto-set state-based timestamps
 * Priority 10 (default) - runs after created_on is set and changed_on is updated
 */
add_filter('pods_api_pre_save_pod_item_tub', 'scoop_enforce_tub_rules', 10, 3);
function scoop_enforce_tub_rules( $pieces, $is_new_item, $id = 0 ) {
  error_log('-----------------------------------------');
  error_log('scoop_enforce_tub_rules');

  // Resolve tub ID robustly
  $tub_id = 0;

  if (!empty($id)) {
    $tub_id = (int) $id;
  } elseif (!empty($pieces['id'])) {
    $tub_id = (int) $pieces['id'];
  } elseif (isset($pieces['params'])) {
    if (is_array($pieces['params']) && !empty($pieces['params']['id'])) {
      $tub_id = (int) $pieces['params']['id'];
    } elseif (is_object($pieces['params']) && !empty($pieces['params']->id)) {
      $tub_id = (int) $pieces['params']->id;
    }
  }

  error_log('');
  
  error_log('');
  error_log('scoop_enforce_tub_rules tub_id=' . $tub_id);
  error_log('');
  
  // Only apply to edits, not new items.
  if ($is_new_item || $tub_id <= 0) return $pieces;

  if (!function_exists('pods')) return $pieces;

  $pod_obj = pods('tub', $tub_id);
  if (!$pod_obj || !$pod_obj->exists()) return $pieces;

  // Old values from DB (authoritative)
  $old_state      = (string) $pod_obj->field('state');
  $old_opened_on  = $pod_obj->field('opened_on');
  $old_emptied_at = $pod_obj->field('emptied_at');

  // New values from pieces if provided, else fall back to old
  $new_state      = isset($pieces['fields']['state']['value'])      ? (string) $pieces['fields']['state']['value']      : $old_state;
  $new_opened_on  = isset($pieces['fields']['opened_on']['value'])  ? $pieces['fields']['opened_on']['value']           : $old_opened_on;
  $new_emptied_at = isset($pieces['fields']['emptied_at']['value']) ? $pieces['fields']['emptied_at']['value']          : $old_emptied_at;

  // Helper: did the request attempt to change a field?
  $req_changes_field = function(string $field) use ($pieces): bool {
    return isset($pieces['fields'][$field]) && array_key_exists('value', (array) $pieces['fields'][$field]);
  };

  // Helper: ensure field is marked active so Pods will persist it
  $activate = function(string $field) use (&$pieces): void {
    if (!isset($pieces['fields_active']) || !is_array($pieces['fields_active'])) {
      $pieces['fields_active'] = [];
    }
    if (!in_array($field, $pieces['fields_active'], true)) {
      $pieces['fields_active'][] = $field;
    }
  };

  // If not in override, timestamps are system-controlled
  if ($new_state !== '__override__') {

    // Revert manual timestamp edits (only if request tried to change them)
    if ($req_changes_field('opened_on') && $new_opened_on !== $old_opened_on) {
      error_log('Revert opened_on for tub ' . $tub_id);
      $pieces['fields']['opened_on']['value'] = $old_opened_on;
      $activate('opened_on');
      $new_opened_on = $old_opened_on;
    }

    if ($req_changes_field('emptied_at') && $new_emptied_at !== $old_emptied_at) {
      error_log('Revert emptied_at for tub ' . $tub_id);
      $pieces['fields']['emptied_at']['value'] = $old_emptied_at;
      $activate('emptied_at');
      $new_emptied_at = $old_emptied_at;
    }

    // State transition enforcement (only if request tried to change state)
    $state_changed = $req_changes_field('state') && ($new_state !== $old_state);
    if ($state_changed) {

      if ($old_state === 'Emptied') {
        error_log('Blocked state change from Emptied for tub ' . $tub_id);

        // Revert just the state field (do not return $old_all)
        $pieces['fields']['state']['value'] = $old_state;
        $activate('state');
        $new_state = $old_state;

      } elseif ($old_state === 'Opened' && !in_array($new_state, ['Opened', 'Emptied'], true)) {
        error_log('Blocked invalid Opened transition for tub ' . $tub_id);

        $pieces['fields']['state']['value'] = $old_state;
        $activate('state');
        $new_state = $old_state;
      }
    }

    // Auto-set timestamps (idempotent)
    $now = current_time('mysql');

    if ($new_state === 'Opened' && scoop_nodate($old_opened_on) && scoop_nodate($new_opened_on)) {
      $pieces['fields']['opened_on']['value'] = $now;
      $activate('opened_on');
      error_log('auto-set opened_on for tub ' . $tub_id);
    }

    if ($new_state === 'Emptied' && scoop_nodate($old_emptied_at) && scoop_nodate($new_emptied_at)) {
      $pieces['fields']['emptied_at']['value'] = $now;
      $activate('emptied_at');
      $pieces['object_fields']['post_status']['value'] = 'draft';
      $activate('post_status');

      error_log('auto-set emptied_at for tub ' . $tub_id);
    }
  }

  return $pieces;
}