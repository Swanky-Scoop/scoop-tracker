<?php
if (!defined('ABSPATH')) exit;

/**
 * Title generation for Task's create-flow — task itself, plus the batch/
 * prep/recipe_count child rows created and linked to it via the Task form
 * (see assets/ui/task-form.js). Same idiom as scoop_set_batch_title() in
 * hooks/batch-tub.php: mutate $pieces['fields_active']/object_fields
 * before save, keep post_name in sync via sanitize_title().
 *
 * Depends on scoop_rel_id() (hooks/batch-tub.php), which must load first —
 * see the require order in scoop_rest.php.
 */

if (!function_exists('scoop_task_title_words')) {
  function scoop_task_title_words(string $text, int $max_words = 8): string {
    $text = trim(wp_strip_all_tags($text));
    if ($text === '') return '';

    $words = preg_split('/\s+/', $text);
    if (count($words) > $max_words) {
      $words = array_slice($words, 0, $max_words);
      return implode(' ', $words) . '…';
    }
    return implode(' ', $words);
  }
}

/**
 * Set Task title from its own description + assigned staff + date before
 * saving. Title: "<other, trimmed> (<target display name|Unassigned>) <date>"
 */
add_filter('pods_api_pre_save_pod_item_task', 'scoop_set_task_title', 10, 2);
function scoop_set_task_title($pieces, $is_new_item) {
  // A user-supplied title (see scoop_create_pod_item()'s task branch in
  // _write_fields.php) wins outright — skip auto-generation entirely.
  if (!empty($GLOBALS['scoop_task_custom_title'])) {
    unset($GLOBALS['scoop_task_custom_title']);
    return $pieces;
  }

  if (!isset($pieces['fields_active']) || !is_array($pieces['fields_active'])) {
    $pieces['fields_active'] = [];
  }
  if (!in_array('post_title', $pieces['fields_active'], true)) {
    $pieces['fields_active'][] = 'post_title';
  }
  if (!isset($pieces['object_fields']['post_title'])) {
    $pieces['object_fields']['post_title'] = ['value' => ''];
  }

  $other = (string)($pieces['fields']['other']['value'] ?? '');
  $desc  = scoop_task_title_words($other);
  if ($desc === '') $desc = 'Task';

  $target_id = scoop_rel_id($pieces['fields']['target']['value'] ?? null);
  $target_name = $target_id ? get_userdata($target_id) : null;
  $target_label = ($target_name && $target_name->display_name) ? $target_name->display_name : 'Unassigned';

  $date_str = current_time('Y-m-d H:i');
  $title = "{$desc} ({$target_label}) {$date_str}";

  $pieces['object_fields']['post_title']['value'] = $title;

  if (!isset($pieces['object_fields']['post_name'])) {
    $pieces['object_fields']['post_name'] = ['value' => ''];
  }
  $pieces['object_fields']['post_name']['value'] = sanitize_title($title);

  return $pieces;
}

/**
 * Set Prep title from ingredient + count + units + date before saving.
 * Falls back to the raw 'other' text if no ingredient is set.
 */
add_filter('pods_api_pre_save_pod_item_prep', 'scoop_set_prep_title', 10, 2);
function scoop_set_prep_title($pieces, $is_new_item) {
  if (!isset($pieces['fields_active']) || !is_array($pieces['fields_active'])) {
    $pieces['fields_active'] = [];
  }
  if (!in_array('post_title', $pieces['fields_active'], true)) {
    $pieces['fields_active'][] = 'post_title';
  }
  if (!isset($pieces['object_fields']['post_title'])) {
    $pieces['object_fields']['post_title'] = ['value' => ''];
  }

  $ingredient_id = scoop_rel_id($pieces['fields']['ingredient']['value'] ?? null);
  $ingredient_name = $ingredient_id ? get_the_title($ingredient_id) : '';

  $count = $pieces['fields']['count']['value'] ?? null;
  $count = is_numeric($count) ? (float)$count : null;

  $unit_id = scoop_rel_id($pieces['fields']['units']['value'] ?? null);
  $unit_name = $unit_id ? get_the_title($unit_id) : '';

  $date_str = current_time('Y-m-d H:i');

  if ($ingredient_name !== '') {
    $amount = $count !== null ? rtrim(rtrim(number_format($count, 2), '0'), '.') : '';
    $parts = array_filter([$ingredient_name, $amount, $unit_name]);
    $title = implode(' ', $parts) . " {$date_str}";
  } else {
    $other = scoop_task_title_words((string)($pieces['fields']['other']['value'] ?? ''));
    $title = ($other !== '' ? $other : 'Prep') . " {$date_str}";
  }

  $pieces['object_fields']['post_title']['value'] = $title;

  if (!isset($pieces['object_fields']['post_name'])) {
    $pieces['object_fields']['post_name'] = ['value' => ''];
  }
  $pieces['object_fields']['post_name']['value'] = sanitize_title($title);

  return $pieces;
}

/**
 * Set RecipeCount title from recipe + count + linked task id before saving.
 * Deliberately no timestamp (mirrors batch's convention elsewhere) — Task's
 * own title can be long/trimmed, so the child references it by id rather
 * than repeating its (possibly truncated) title.
 */
add_filter('pods_api_pre_save_pod_item_recipe_count', 'scoop_set_recipe_count_title', 10, 2);
function scoop_set_recipe_count_title($pieces, $is_new_item) {
  if (!isset($pieces['fields_active']) || !is_array($pieces['fields_active'])) {
    $pieces['fields_active'] = [];
  }
  if (!in_array('post_title', $pieces['fields_active'], true)) {
    $pieces['fields_active'][] = 'post_title';
  }
  if (!isset($pieces['object_fields']['post_title'])) {
    $pieces['object_fields']['post_title'] = ['value' => ''];
  }

  $recipe_id = scoop_rel_id($pieces['fields']['recipe']['value'] ?? null);
  $recipe_name = $recipe_id ? get_the_title($recipe_id) : 'Recipe';

  $count = $pieces['fields']['count']['value'] ?? null;
  $count = is_numeric($count) ? (float)$count : 0;
  $count_str = rtrim(rtrim(number_format($count, 2), '0'), '.');

  $task_id = scoop_rel_id($pieces['fields']['task']['value'] ?? null);

  $title = "{$recipe_name} {$count_str}";
  if ($task_id) $title .= " (Task {$task_id})";

  $pieces['object_fields']['post_title']['value'] = $title;

  if (!isset($pieces['object_fields']['post_name'])) {
    $pieces['object_fields']['post_name'] = ['value' => ''];
  }
  $pieces['object_fields']['post_name']['value'] = sanitize_title($title);

  return $pieces;
}
