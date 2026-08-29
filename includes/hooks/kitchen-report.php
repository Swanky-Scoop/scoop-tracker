<?php
if (!defined('ABSPATH')) exit;

/**
 * Kitchen Report hooks: prep + task + recipe_count
 *
 * Auto-title generation, same reasoning and shape as batch-tub.php's
 * scoop_set_batch_title()/scoop_batch_title_for_data() — a staffer filling
 * these in via the Kitchen Report form (or whoever authors tasks) shouldn't
 * have to type a title by hand. See that file for scoop_rel_id()/
 * scoop_field_option()/scoop_log(), reused here rather than duplicated.
 */

/** ------------------------------------------------------------
 *  Prep title: "{other }{ingredient} {count} {unit} {date}"
 * ------------------------------------------------------------ */
if (!function_exists('scoop_prep_title_for_data')) {
  function scoop_prep_title_for_data(int $ingredient_id, float $count, int $unit_id = 0, string $other = ''): string {
    $ingredient_name = (string)get_the_title($ingredient_id);
    if ($ingredient_name === '') return '';

    $unit_name = $unit_id ? (string)get_the_title($unit_id) : '';
    $qty       = $unit_name !== '' ? "{$count} {$unit_name}" : (string)$count;
    $prefix    = $other !== '' ? trim($other) . ' ' : '';
    $date_str  = current_time('Y-m-d H:i');

    return "{$prefix}{$ingredient_name} {$qty} {$date_str}";
  }
}

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
  if (!$ingredient_id) {
    scoop_log('scoop_set_prep_title: ingredient missing/invalid');
    return $pieces;
  }

  $count = 1;
  $raw_count = $pieces['fields']['count']['value'] ?? null;
  if (is_numeric($raw_count)) {
    $count = max(0, (float)$raw_count);
  }

  $unit_id = scoop_rel_id($pieces['fields']['units']['value'] ?? null);
  $other   = (string)($pieces['fields']['other']['value'] ?? '');

  $title = scoop_prep_title_for_data($ingredient_id, $count, $unit_id, $other);
  if ($title === '') {
    scoop_log("scoop_set_prep_title: could not resolve ingredient title id={$ingredient_id}");
    return $pieces;
  }

  $pieces['object_fields']['post_title']['value'] = $title;

  if (!isset($pieces['object_fields']['post_name'])) {
    $pieces['object_fields']['post_name'] = ['value' => ''];
  }
  $pieces['object_fields']['post_name']['value'] = sanitize_title($title);

  return $pieces;
}

/** ------------------------------------------------------------
 *  Task title: "{other, trimmed} ({target name}) {date}"
 *
 *  Generated once at creation from `other`/`target` only. A task's
 *  recipe_counts/batches/preps point BACK at the task (the task is created
 *  first, then its counter sub-items are saved with `task` set to it), so
 *  they don't exist yet at the moment the task itself is saved — same
 *  "title reflects what's known at save time, not refreshed later"
 *  precedent as batch's own title, which doesn't rename itself as tubs get
 *  created either. If a task's `other` is empty (a task authored purely to
 *  bundle sub-items, with no standalone description), the title falls back
 *  to a generic "Task" — a still-blank/generic title only reads that way
 *  until you open the task and see its actual sub-items.
 * ------------------------------------------------------------ */
if (!function_exists('scoop_task_title_for_data')) {
  function scoop_task_title_for_data(string $other, int $target_id): string {
    $target_name = '';
    if ($target_id) {
      $user = get_userdata($target_id);
      if ($user) $target_name = $user->display_name;
    }

    $desc = trim($other) !== '' ? wp_trim_words(trim($other), 8, '…') : 'Task';
    $who  = $target_name !== '' ? $target_name : 'Unassigned';
    $date_str = current_time('Y-m-d H:i');

    return "{$desc} ({$who}) {$date_str}";
  }
}

add_filter('pods_api_pre_save_pod_item_task', 'scoop_set_task_title', 10, 2);
function scoop_set_task_title($pieces, $is_new_item) {
  if (!isset($pieces['fields_active']) || !is_array($pieces['fields_active'])) {
    $pieces['fields_active'] = [];
  }
  if (!in_array('post_title', $pieces['fields_active'], true)) {
    $pieces['fields_active'][] = 'post_title';
  }
  if (!isset($pieces['object_fields']['post_title'])) {
    $pieces['object_fields']['post_title'] = ['value' => ''];
  }

  $other     = (string)($pieces['fields']['other']['value'] ?? '');
  $target_id = scoop_rel_id($pieces['fields']['target']['value'] ?? null);

  $title = scoop_task_title_for_data($other, $target_id);

  $pieces['object_fields']['post_title']['value'] = $title;

  if (!isset($pieces['object_fields']['post_name'])) {
    $pieces['object_fields']['post_name'] = ['value' => ''];
  }
  $pieces['object_fields']['post_name']['value'] = sanitize_title($title);

  return $pieces;
}

/** ------------------------------------------------------------
 *  Recipe count title: "{recipe} {count} (Task {task_id})"
 *
 *  Same shape as batch's own "{flavor} {date}_{count}" title, but with the
 *  date component swapped out for the linked task's id instead — the
 *  recipe/count pair alone doesn't say which task it belongs to, and the
 *  id (not the task's own title) is what's stable/glanceable in a wp-admin
 *  list (a task's title can be a long trimmed description — see
 *  scoop_task_title_for_data() above — which makes a noisier title here
 *  than a bare id does). Falls back to no task suffix at all for a
 *  recipe_count created without one (e.g. an ad-hoc entry not tied to any
 *  task) — same "omit what's missing" precedent as prep's `other` prefix.
 * ------------------------------------------------------------ */
if (!function_exists('scoop_recipe_count_title_for_data')) {
  function scoop_recipe_count_title_for_data(int $recipe_id, float $count, int $task_id = 0): string {
    $recipe_name = (string)get_the_title($recipe_id);
    if ($recipe_name === '') return '';

    $suffix = $task_id ? " (Task {$task_id})" : '';

    return "{$recipe_name} {$count}{$suffix}";
  }
}

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
  if (!$recipe_id) {
    scoop_log('scoop_set_recipe_count_title: recipe missing/invalid');
    return $pieces;
  }

  $count = 1;
  $raw_count = $pieces['fields']['count']['value'] ?? null;
  if (is_numeric($raw_count)) {
    $count = max(0, (float)$raw_count);
  }

  $task_id = scoop_rel_id($pieces['fields']['task']['value'] ?? null);

  $title = scoop_recipe_count_title_for_data($recipe_id, $count, $task_id);
  if ($title === '') {
    scoop_log("scoop_set_recipe_count_title: could not resolve recipe title id={$recipe_id}");
    return $pieces;
  }

  $pieces['object_fields']['post_title']['value'] = $title;

  if (!isset($pieces['object_fields']['post_name'])) {
    $pieces['object_fields']['post_name'] = ['value' => ''];
  }
  $pieces['object_fields']['post_name']['value'] = sanitize_title($title);

  return $pieces;
}
