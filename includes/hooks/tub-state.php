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
  } 
  
  return $pieces;
}

// How long after emptied_at a tub's state can still be reverted away from
// 'Emptied' (see scoop_enforce_tub_rules below) — must match
// RECENTLY_EMPTIED_HOURS in assets/models/flavor-tub-grid-model.js (which
// governs whether the tub even shows as "active" client-side) and the
// tub entity's bundle filter in includes/_specs.php (which governs whether
// the bundle includes it at all), so anything visible is also correctable.
// Past this window the transition is locked forever, same as it always was
// (override in wp-config.php).
if (!defined('SCOOP_TUB_EMPTIED_REVERT_HOURS')) {
  define('SCOOP_TUB_EMPTIED_REVERT_HOURS', 48);
}

/**
 * Enforce tub state transition rules and auto-set state-based timestamps
 * Priority 10 (default) - runs after created_on is set and changed_on is updated
 */
add_filter('pods_api_pre_save_pod_item_tub', 'scoop_enforce_tub_rules', 10, 3);
function scoop_enforce_tub_rules( $pieces, $is_new_item, $id = 0 ) {
  
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

  
  // Only apply to edits, not new items.
  if ($is_new_item || $tub_id <= 0) return $pieces;

  if (!function_exists('pods')) return $pieces;

  $pod_obj = pods('tub', $tub_id);
  if (!$pod_obj || !$pod_obj->exists()) return $pieces;

  // Old values from DB (authoritative)
  $old_state      = (string) $pod_obj->field('state');
  $old_opened_on  = $pod_obj->field('opened_on');
  $old_emptied_at = $pod_obj->field('emptied_at');
  $old_slot_id    = function_exists('scoop_rel_id') ? (int) scoop_rel_id($pod_obj->field('slot')) : 0;

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
      $pieces['fields']['opened_on']['value'] = $old_opened_on;
      $activate('opened_on');
      $new_opened_on = $old_opened_on;
    }

    if ($req_changes_field('emptied_at') && $new_emptied_at !== $old_emptied_at) {
      $pieces['fields']['emptied_at']['value'] = $old_emptied_at;
      $activate('emptied_at');
      $new_emptied_at = $old_emptied_at;
    }

    // State transition enforcement (only if request tried to change state)
    $state_changed = $req_changes_field('state') && ($new_state !== $old_state);
    if ($state_changed) {

      if ($old_state === 'Emptied') {

        $emptied_ts     = scoop_nodate($old_emptied_at) ? false : strtotime($old_emptied_at);
        $revert_horizon = time() - (SCOOP_TUB_EMPTIED_REVERT_HOURS * HOUR_IN_SECONDS);

        if ($emptied_ts === false || $emptied_ts < $revert_horizon) {
          // Past the correction window (or no emptied_at to measure from) —
          // locked forever, as before.
          $pieces['fields']['state']['value'] = $old_state;
          $activate('state');
          $new_state = $old_state;
        } else {
          // Within the window — allow the revert, but clear the now-stale
          // emptied_at so the tub stops reading as "recently emptied" once
          // it's active again (it's system-controlled, only meaningful while
          // state actually is 'Emptied' — see the timestamp block above).
          $pieces['fields']['emptied_at']['value'] = '0000-00-00 00:00:00';
          $activate('emptied_at');
          $new_emptied_at = '0000-00-00 00:00:00';

          // Restore the post_status demotion from when it emptied (see the
          // 'Emptied' auto-set-timestamps block below) — an un-emptied tub
          // is active again and belongs back in 'publish', symmetric with
          // how it was demoted to 'draft' on the way in.
          $pieces['object_fields']['post_status']['value'] = 'publish';
          $activate('post_status');
        }

      } elseif ($old_state === 'Opened' && !in_array($new_state, ['Opened', 'Emptied'], true)) {
        
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
    }

    if ($new_state === 'Emptied' && scoop_nodate($old_emptied_at) && scoop_nodate($new_emptied_at)) {
      $pieces['fields']['emptied_at']['value'] = $now;
      $activate('emptied_at');
      $pieces['object_fields']['post_status']['value'] = 'draft';
      $activate('post_status');
    }

    // A tub leaving service is unlinked from whatever slot claimed it —
    // regardless of which GUI/write path emptied it (REST, WP admin, direct
    // Pods call). tub.slot/slot.tub is a bidirectional Pods sister field
    // (see change-tub.md), so clearing this side also clears slot.tub via
    // Pods' own sync. An Opened tub with no slot link is still a valid,
    // separate state (other GUIs/workflows can open a tub unrelated to any
    // cabinet slot) — this only guards the reverse: a slot still pointing
    // at a tub that's no longer Opened.
    if ($new_state === 'Emptied' && $old_state !== 'Emptied') {
      $pieces['fields']['slot']['value'] = 0;
      $activate('slot');

      // Confirm Cabinet's persisted outcome (slot.confirm_state — see
      // change-tub.md) goes stale the instant the tub it was based on
      // empties, from ANY path. Reset it here, in the same hook, so
      // reporting outside the CabinetWorkflow GUI (which can't see a
      // client-computed value at all) has a chance of noticing before
      // someone next opens that page and re-runs the check.
      if ($old_slot_id > 0 && function_exists('pods_api')) {
        try {
          pods_api()->save_pod_item([
            'pod'  => 'slot',
            'id'   => $old_slot_id,
            'data' => ['confirm_state' => 'unconfirmed'],
          ]);
        } catch (\Throwable $e) {
          error_log("scoop_enforce_tub_rules: failed to mark slot {$old_slot_id} unconfirmed: " . $e->getMessage());
        }
      }
    }
  }

  return $pieces;
}