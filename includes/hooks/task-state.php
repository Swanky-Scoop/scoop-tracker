<?php
if (!defined('ABSPATH')) exit;

/**
 * Auto-stamp task.completed the instant task.done flips true, and clear it
 * again if done reverts to false — same idiom as tub.emptied_at in
 * hooks/tub-state.php: 'completed' is entirely system-controlled, any
 * client-submitted value is reverted, only this hook ever sets it. Runs on
 * every save path (REST, WP admin, direct Pods call) per CLAUDE.md's
 * hooks-over-REST rule, so this already holds once a Task update route
 * exists — no code here assumes one does.
 *
 * Unlike scoop_enforce_tub_rules() (which skips new items outright — a tub
 * is never created already-Emptied through this app), a task CAN legitimately
 * be created already done (e.g. logging finished work after the fact), so
 * this runs on create too.
 */
add_filter('pods_api_pre_save_pod_item_task', 'scoop_stamp_task_completed', 10, 3);
function scoop_stamp_task_completed($pieces, $is_new_item, $id = 0) {

  $activate = function(string $field) use (&$pieces): void {
    if (!isset($pieces['fields_active']) || !is_array($pieces['fields_active'])) {
      $pieces['fields_active'] = [];
    }
    if (!in_array($field, $pieces['fields_active'], true)) {
      $pieces['fields_active'][] = $field;
    }
  };

  $req_changes_field = function(string $field) use ($pieces): bool {
    return isset($pieces['fields'][$field]) && array_key_exists('value', (array) $pieces['fields'][$field]);
  };

  // Resolve task ID robustly, same pattern as scoop_enforce_tub_rules.
  $task_id = 0;
  if (!empty($id)) {
    $task_id = (int) $id;
  } elseif (!empty($pieces['id'])) {
    $task_id = (int) $pieces['id'];
  }

  $pod_obj = (!$is_new_item && $task_id > 0 && function_exists('pods')) ? pods('task', $task_id) : null;
  $exists  = $pod_obj && $pod_obj->exists();

  $old_done      = $exists ? (bool) $pod_obj->field('done') : false;
  $old_completed = $exists ? $pod_obj->field('completed') : null;

  $new_done = $req_changes_field('done')
    ? (bool) $pieces['fields']['done']['value']
    : $old_done;

  // Revert any client-submitted 'completed' before the auto-stamp/clear
  // logic below decides its real value — defaults to whatever it already
  // was (or empty, for a brand-new task) so a stray client value never
  // sticks even when 'done' itself isn't changing this save.
  if ($req_changes_field('completed')) {
    $pieces['fields']['completed']['value'] = $exists ? $old_completed : '0000-00-00 00:00:00';
    $activate('completed');
  }

  if ($new_done && !$old_done) {
    $pieces['fields']['completed']['value'] = current_time('mysql');
    $activate('completed');
  } elseif ($exists && !$new_done && $old_done) {
    // Reverted to not-done — clear the now-stale completion stamp, same as
    // emptied_at gets cleared when a tub's state reverts away from Emptied.
    $pieces['fields']['completed']['value'] = '0000-00-00 00:00:00';
    $activate('completed');
  }

  return $pieces;
}
