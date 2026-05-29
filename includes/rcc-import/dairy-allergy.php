<?php
if (!defined('ABSPATH')) exit;

/**
 * Dairy Allergen bulk application — parses all flavor titles and adds/removes
 * allergen 1204 (dairy) based on pattern matching.
 *
 * Flavors that do NOT end with ")" or "Sorbet" (case-insensitive) are tagged
 * with the dairy allergen. Optionally, flavors that DO match those patterns
 * can have dairy allergen removed.
 *
 * This module provides a two-step workflow: scan (dry-run) and apply (commit).
 */

/**
 * Scan all flavors and return a dry-run result set indicating which flavors
 * would be affected by the dairy allergen operation.
 *
 * @return array {
 *   'ok': bool,
 *   'error': string|null,
 *   'to_add': array<int, array{id, title, current_allergens, reason}>,
 *   'to_remove': array<int, array{id, title, current_allergens, reason}>,
 * }
 */
function scoop_dairy_allergy_scan(): array {

  try {
    $pod = pods('flavor');
    if (!$pod) {
      return [
        'ok' => false,
        'error' => 'Could not load flavor pod',
        'to_add' => [],
        'to_remove' => [],
      ];
    }

    $to_add = [];
    $to_remove = [];

    // Fetch all flavors. Pods find() returns an array of posts.
    $flavors = $pod->find(['limit' => -1, 'orderby' => 'post_title ASC']);
    if (!$flavors) {
      $flavors = [];
    }

    foreach ($flavors as $flavor_post) {
      $flavor_id = (int) $flavor_post->ID;
      $flavor_title = (string) $flavor_post->post_title;

      if (empty($flavor_title)) {
        continue;
      }

      // Load the flavor pod to read allergens.
      $flavor_pod = pods('flavor', $flavor_id);
      $current_allergen_ids = scoop_dairy_allergy_get_allergen_ids($flavor_pod);

      $should_have_dairy = !preg_match('/\)|sorbet$/i', $flavor_title);
      $has_dairy = in_array(1204, $current_allergen_ids, true);

      if ($should_have_dairy && !$has_dairy) {
        $to_add[$flavor_id] = [
          'id' => $flavor_id,
          'title' => $flavor_title,
          'current_allergens' => $current_allergen_ids,
          'reason' => 'Does not end with ) or Sorbet',
        ];
      } elseif (!$should_have_dairy && $has_dairy) {
        $to_remove[$flavor_id] = [
          'id' => $flavor_id,
          'title' => $flavor_title,
          'current_allergens' => $current_allergen_ids,
          'reason' => 'Ends with ) or Sorbet',
        ];
      }
    }

    return [
      'ok' => true,
      'error' => null,
      'to_add' => $to_add,
      'to_remove' => $to_remove,
    ];

  } catch (\Throwable $e) {
    return [
      'ok' => false,
      'error' => 'Scan failed: ' . $e->getMessage(),
      'to_add' => [],
      'to_remove' => [],
    ];
  }
}

/**
 * Apply the dairy allergen changes based on a scan result and user options.
 *
 * @param array $scan_result  Result from scoop_dairy_allergy_scan()
 * @param bool  $remove_from_sorbet  If true, also remove dairy from sorbet/paren flavors
 *
 * @return array {
 *   'ok': bool,
 *   'error': string|null,
 *   'added_count': int,
 *   'removed_count': int,
 *   'errors': string[],
 * }
 */
function scoop_dairy_allergy_apply(array $scan_result, bool $remove_from_sorbet = false): array {

  $errors = [];
  $added_count = 0;
  $removed_count = 0;

  // Apply additions (always).
  foreach ($scan_result['to_add'] as $flavor_id => $info) {
    $result = scoop_dairy_allergy_add_allergen($flavor_id, 1204);
    if ($result['ok']) {
      $added_count++;
    } else {
      $errors[] = "Flavor #{$flavor_id} ({$info['title']}): {$result['error']}";
    }
  }

  // Apply removals (only if user opted in).
  if ($remove_from_sorbet) {
    foreach ($scan_result['to_remove'] as $flavor_id => $info) {
      $result = scoop_dairy_allergy_remove_allergen($flavor_id, 1204);
      if ($result['ok']) {
        $removed_count++;
      } else {
        $errors[] = "Flavor #{$flavor_id} ({$info['title']}): {$result['error']}";
      }
    }
  }

  return [
    'ok' => count($errors) === 0,
    'error' => count($errors) > 0 ? 'Some operations failed' : null,
    'added_count' => $added_count,
    'removed_count' => $removed_count,
    'errors' => $errors,
  ];
}

/**
 * Get allergen post IDs (not slugs) for a flavor pod.
 *
 * @param \Pod $flavor_pod  A loaded flavor pod
 * @return int[]  Array of allergen post IDs
 */
function scoop_dairy_allergy_get_allergen_ids(\Pod $flavor_pod): array {

  try {
    $allergen_posts = $flavor_pod->field('allergens');
    if (empty($allergen_posts)) {
      return [];
    }

    if (!is_array($allergen_posts)) {
      $allergen_posts = [$allergen_posts];
    }

    $ids = [];
    foreach ($allergen_posts as $allergen_post) {
      if (is_object($allergen_post) && isset($allergen_post->ID)) {
        $ids[] = (int) $allergen_post->ID;
      } elseif (is_array($allergen_post) && isset($allergen_post['ID'])) {
        $ids[] = (int) $allergen_post['ID'];
      } elseif (is_int($allergen_post)) {
        $ids[] = $allergen_post;
      }
    }
    return array_unique($ids);
  } catch (\Throwable $e) {
    return [];
  }
}

/**
 * Add an allergen ID to a flavor's allergen list.
 *
 * @param int $flavor_id
 * @param int $allergen_id
 *
 * @return array { 'ok': bool, 'error': string|null }
 */
function scoop_dairy_allergy_add_allergen(int $flavor_id, int $allergen_id): array {

  try {
    $flavor_pod = pods('flavor', $flavor_id);
    if (!$flavor_pod || !$flavor_pod->id()) {
      return ['ok' => false, 'error' => "Flavor #{$flavor_id} not found"];
    }

    $current_ids = scoop_dairy_allergy_get_allergen_ids($flavor_pod);

    // Deduplicate: if allergen already present, skip.
    if (in_array($allergen_id, $current_ids, true)) {
      return ['ok' => true, 'error' => null];
    }

    $current_ids[] = $allergen_id;
    $current_ids = array_unique($current_ids);

    $result = $flavor_pod->save(['allergens' => $current_ids]);
    if ($result === false) {
      return ['ok' => false, 'error' => 'pods()->save() returned false'];
    }

    return ['ok' => true, 'error' => null];

  } catch (\Throwable $e) {
    return ['ok' => false, 'error' => $e->getMessage()];
  }
}

/**
 * Remove an allergen ID from a flavor's allergen list.
 *
 * @param int $flavor_id
 * @param int $allergen_id
 *
 * @return array { 'ok': bool, 'error': string|null }
 */
function scoop_dairy_allergy_remove_allergen(int $flavor_id, int $allergen_id): array {

  try {
    $flavor_pod = pods('flavor', $flavor_id);
    if (!$flavor_pod || !$flavor_pod->id()) {
      return ['ok' => false, 'error' => "Flavor #{$flavor_id} not found"];
    }

    $current_ids = scoop_dairy_allergy_get_allergen_ids($flavor_pod);

    // If allergen not present, no-op.
    if (!in_array($allergen_id, $current_ids, true)) {
      return ['ok' => true, 'error' => null];
    }

    $current_ids = array_filter($current_ids, function ($id) use ($allergen_id) {
      return $id !== $allergen_id;
    });

    $result = $flavor_pod->save(['allergens' => array_values($current_ids)]);
    if ($result === false) {
      return ['ok' => false, 'error' => 'pods()->save() returned false'];
    }

    return ['ok' => true, 'error' => null];

  } catch (\Throwable $e) {
    return ['ok' => false, 'error' => $e->getMessage()];
  }
}
