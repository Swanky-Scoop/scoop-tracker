<?php
if (!defined('ABSPATH')) exit;

/**
 * Importer for recipe ingredient quantities parsed from a Markdown export.
 *
 * Builds a review plan (which recipes/ingredients matched, which units are
 * storable) and, on commit, writes one `recipe-ingredient-ma` pod row per
 * ingredient and links them onto the recipe's `ingredient_maps` relation.
 * All writes go through pods()->add()/->save(). See RCC_IMPORT_README.md §14.
 */

/**
 * Resolve an exported line name to a pod target: an `ingredient` first, else a
 * `recipe` (a sub-recipe — e.g. "Cheesecake" used inside another recipe).
 *
 * Match order, most specific first:
 *   1. full name → ingredient        3. pre-comma base → ingredient
 *   2. full name → recipe            4. pre-comma base → recipe
 * Each step also tries singular/plural variants of the name
 * ("Granny Smith Apples" → "Granny Smith Apple"). The pre-comma fallback
 * strips RCC's prep hints ("Bananas, Roasted,pureed" → "Bananas").
 *
 * Returns:
 *   [ 'target_type' => 'ingredient'|'sub_recipe'|null,
 *     'id' => ?int, 'status' => 'matched'|'ambiguous'|'unmatched',
 *     'matched_on' => 'full'|'plural'|'pre_comma'|'pre_comma_plural'|null,
 *     'matched_name' => ?string ]
 */
function scoop_rcc_resolve_line(string $name, array $ing_index, array $recipe_index): array {

  $variants = [['name' => $name, 'from' => 'full']];
  $comma = strpos($name, ',');
  if ($comma !== false) {
    $base = trim(substr($name, 0, $comma));
    if ($base !== '' && scoop_rcc_normalize_title($base) !== scoop_rcc_normalize_title($name)) {
      $variants[] = ['name' => $base, 'from' => 'pre_comma'];
    }
  }

  foreach ($variants as $v) {
    $cands = scoop_rcc_match_candidates($v['name']);

    $ing = scoop_rcc_lookup_in_index($cands, $ing_index);
    if ($ing['ambiguous']) {
      return ['target_type' => 'ingredient', 'id' => null, 'status' => 'ambiguous', 'matched_on' => null, 'matched_name' => null];
    }
    if ($ing['id'] !== null) {
      return [
        'target_type' => 'ingredient', 'id' => $ing['id'], 'status' => 'matched',
        'matched_on'  => scoop_rcc_matched_on($v['from'], $ing['how']),
        'matched_name' => $v['from'] === 'pre_comma' ? $v['name'] : null,
      ];
    }

    $rec = scoop_rcc_lookup_in_index($cands, $recipe_index);
    if ($rec['ambiguous']) {
      return ['target_type' => 'sub_recipe', 'id' => null, 'status' => 'ambiguous', 'matched_on' => null, 'matched_name' => null];
    }
    if ($rec['id'] !== null) {
      return [
        'target_type' => 'sub_recipe', 'id' => $rec['id'], 'status' => 'matched',
        'matched_on'  => scoop_rcc_matched_on($v['from'], $rec['how']),
        'matched_name' => $v['from'] === 'pre_comma' ? $v['name'] : null,
      ];
    }
  }

  return ['target_type' => null, 'id' => null, 'status' => 'unmatched', 'matched_on' => null, 'matched_name' => null];
}

/**
 * Ordered normalized lookup keys for a name: the exact normalized title first,
 * then singular/plural variants. Variants are validated against the live index
 * by the caller, so over-generating harmless non-existent forms is fine.
 */
function scoop_rcc_match_candidates(string $name): array {
  $out = [];
  $key = scoop_rcc_normalize_title($name);
  if ($key === '') return $out;

  $out[] = ['key' => $key, 'how' => 'exact'];
  foreach (scoop_rcc_depluralize_keys($key) as $dk) {
    if ($dk !== $key) $out[] = ['key' => $dk, 'how' => 'plural'];
  }
  return $out;
}

/**
 * Candidate singular/plural forms of a normalized key (operating on its
 * trailing word): "apples"→"apple", "berries"→"berry", "tomatoes"→"tomato",
 * plus the naive pluralization "apple"→"apples".
 */
function scoop_rcc_depluralize_keys(string $key): array {
  $out = [];
  if ($key === '') return $out;
  if (substr($key, -3) === 'ies') $out[] = substr($key, 0, -3) . 'y';
  if (substr($key, -2) === 'es')  $out[] = substr($key, 0, -2);
  if (substr($key, -1) === 's')   $out[] = substr($key, 0, -1);
  $out[] = $key . 's';
  return array_values(array_unique(array_filter($out, function ($k) { return $k !== ''; })));
}

/**
 * Try each candidate key against a pod index in order. Returns the first key
 * that hits exactly one row; flags >1 as ambiguous.
 */
function scoop_rcc_lookup_in_index(array $candidates, array $index): array {
  foreach ($candidates as $c) {
    $m = $index['by_title'][$c['key']] ?? [];
    if (count($m) === 1) return ['id' => (int) $m[0], 'how' => $c['how'], 'ambiguous' => false];
    if (count($m) > 1)  return ['id' => null, 'how' => null, 'ambiguous' => true];
  }
  return ['id' => null, 'how' => null, 'ambiguous' => false];
}

/**
 * Combine the name-variant origin with how it normalized into one label.
 */
function scoop_rcc_matched_on(string $from, string $how): string {
  if ($from === 'pre_comma') return $how === 'plural' ? 'pre_comma_plural' : 'pre_comma';
  return $how === 'plural' ? 'plural' : 'full';
}

/**
 * Turn the parsed recipes into a per-recipe plan resolved against the live
 * pods. Reuses the CSV importer's pod index + title normalizer.
 *
 * Returns:
 *   [ 'recipes' => [ <plan>, ... ],
 *     'counts'  => [ 'recipes_matched'=>, 'recipes_unmatched'=>,
 *                    'items_total'=>, 'items_matched'=>, 'items_unmatched'=>,
 *                    'recipes_with_existing'=> ] ]
 */
function scoop_rcc_plan_quantities(array $parsed_recipes): array {

  $recipe_index = scoop_rcc_load_pod_index('recipe');
  $ing_index    = scoop_rcc_load_pod_index('ingredient');

  $plan = [];
  $counts = [
    'recipes_matched'       => 0,
    'recipes_unmatched'     => 0,
    'recipes_with_existing' => 0,
    'items_total'           => 0,
    'items_matched'         => 0,
    'items_unmatched'       => 0,
    'recipes_with_prep'     => 0,
  ];

  foreach ($parsed_recipes as $rec) {

    $title_key = scoop_rcc_normalize_title($rec['title']);
    $matches   = $recipe_index['by_title'][$title_key] ?? [];

    if (count($matches) === 1) {
      $recipe_id = $matches[0];
      $recipe_status = 'matched';
      $counts['recipes_matched']++;
    } elseif (count($matches) > 1) {
      $recipe_id = null;
      $recipe_status = 'ambiguous';
      $counts['recipes_unmatched']++;
    } else {
      $recipe_id = null;
      $recipe_status = 'unmatched';
      $counts['recipes_unmatched']++;
    }

    $existing = $recipe_id !== null ? scoop_rcc_count_recipe_maps($recipe_id) : 0;
    if ($existing > 0) $counts['recipes_with_existing']++;

    $items = [];
    foreach ($rec['items'] as $item) {
      $counts['items_total']++;

      $resolved  = scoop_rcc_resolve_line($item['name'], $ing_index, $recipe_index);
      $target_id = $resolved['id'];

      if ($target_id !== null) $counts['items_matched']++;
      else                     $counts['items_unmatched']++;

      $unit_norm = scoop_rcc_normalize_unit($item['unit']);

      $item_warnings = [];
      switch ($resolved['matched_on']) {
        case 'plural':
          $item_warnings[] = 'Matched on a singular/plural variant.';
          break;
        case 'pre_comma':
          $item_warnings[] = "Matched on \"{$resolved['matched_name']}\" (text after the comma treated as a prep hint).";
          break;
        case 'pre_comma_plural':
          $item_warnings[] = "Matched on \"{$resolved['matched_name']}\" (prep hint dropped) + singular/plural variant.";
          break;
      }
      if ($resolved['target_type'] === 'sub_recipe' && $target_id !== null) {
        $item_warnings[] = 'Resolved to a sub-recipe (found in recipe titles, not ingredients).';
      }
      if ($unit_norm['kind'] === 'other') {
        $item_warnings[] = "Unit \"{$item['unit']}\" not translatable — stored as unit_vol \"other\".";
      } elseif ($unit_norm['kind'] === 'none') {
        $item_warnings[] = 'No unit on this line — quantity stored, unit left blank.';
      }

      $items[] = [
        'name'        => $item['name'],
        'qty'         => $item['qty'],
        'qty_raw'     => $item['qty_raw'],
        'unit'        => $item['unit'],
        'target_type' => $resolved['target_type'],
        'target_id'   => $target_id,
        'status'      => $resolved['status'],
        'matched_on'  => $resolved['matched_on'],
        'unit_norm'   => $unit_norm,
        'warnings'    => $item_warnings,
      ];
    }

    $prep = trim((string) ($rec['prep'] ?? ''));
    if ($prep !== '') $counts['recipes_with_prep']++;

    $instructions_current = '';
    if ($recipe_id !== null) {
      $instructions_current = trim((string) ($recipe_index['rows'][$recipe_id]['instructions'] ?? ''));
    }

    $plan[] = [
      'title'                => $rec['title'],
      'format'               => $rec['format'],
      'recipe_id'            => $recipe_id,
      'recipe_status'        => $recipe_status,
      'existing_maps'        => $existing,
      'items'                => $items,
      'prep'                 => $prep,
      'instructions_current' => $instructions_current,
      'warnings'             => $rec['warnings'] ?? [],
    ];
  }

  return ['recipes' => $plan, 'counts' => $counts];
}

/**
 * Count the map rows currently linked to a recipe via `ingredient_maps`.
 * Reads wp_podsrel directly (reusing the reconciler's field-meta lookup) so
 * a stale Pods field cache can't hide existing relations.
 */
function scoop_rcc_count_recipe_maps(int $recipe_id): int {

  global $wpdb;
  $meta = scoop_rcc_get_pod_field_meta('recipe', scoop_rcc_recipe_maps_field());
  if (!$meta) return 0;

  $sql = $wpdb->prepare(
    "SELECT COUNT(*) FROM `{$wpdb->prefix}podsrel`
      WHERE pod_id = %d AND field_id = %d AND item_id = %d",
    $meta['pod_id'], $meta['field_id'], $recipe_id
  );
  return (int) $wpdb->get_var($sql);
}

/**
 * Return the map post IDs currently linked to a recipe via `ingredient_maps`.
 */
function scoop_rcc_existing_map_ids(int $recipe_id): array {

  global $wpdb;
  $meta = scoop_rcc_get_pod_field_meta('recipe', scoop_rcc_recipe_maps_field());
  if (!$meta) return [];

  $sql = $wpdb->prepare(
    "SELECT related_item_id FROM `{$wpdb->prefix}podsrel`
      WHERE pod_id = %d AND field_id = %d AND item_id = %d",
    $meta['pod_id'], $meta['field_id'], $recipe_id
  );
  return array_map('intval', (array) $wpdb->get_col($sql));
}

/**
 * Execute the import.
 *
 *   $choices = [ recipe_index => ['skip'=>bool, 'replace'=>bool] ]
 *   $opts    = ['create_missing_ingredients' => bool]
 *
 * Returns a results report consumed by the results screen.
 */
function scoop_rcc_commit_quantities(array $plan, array $choices, array $opts): array {

  $create_missing = !empty($opts['create_missing_ingredients']);
  $map_pod = scoop_rcc_map_pod_name();

  $recipes_done         = 0;
  $maps_created         = 0;
  $maps_deleted         = 0;
  $ing_created          = 0;
  $items_skipped        = 0;
  $instructions_written = 0;
  $errors               = [];
  $outcomes             = [];

  foreach ($plan as $i => $rec) {

    $choice = $choices[$i] ?? [];

    if ($rec['recipe_status'] !== 'matched') {
      $outcomes[$i] = ['action' => 'skipped', 'reason' => $rec['recipe_status']];
      continue;
    }
    if (!empty($choice['skip'])) {
      $outcomes[$i] = ['action' => 'skipped', 'reason' => 'operator skip'];
      continue;
    }

    $recipe_id = (int) $rec['recipe_id'];
    $did = [];

    // 1. Instructions (Preparation Method) — independent of the maps decision,
    //    written whenever the parsed prep differs from what's already stored.
    if ($rec['prep'] !== '' && $rec['prep'] !== $rec['instructions_current']) {
      $ir = scoop_rcc_save_recipe_instructions($recipe_id, $rec['prep']);
      if ($ir['ok']) { $instructions_written++; $did[] = 'instructions'; }
      else { $errors[] = "{$rec['title']}: instructions save failed: {$ir['error']}"; }
    }

    // 2. Ingredient maps.
    $maps_reason = null;
    if (empty($rec['items'])) {
      $maps_reason = 'no quantities';
    } elseif ($rec['existing_maps'] > 0 && empty($choice['replace'])) {
      $maps_reason = 'already populated';
    } else {
      // Replace mode: unlink + delete the old map rows so we don't orphan them.
      if ($rec['existing_maps'] > 0 && !empty($choice['replace'])) {
        foreach (scoop_rcc_existing_map_ids($recipe_id) as $old_id) {
          if (wp_delete_post($old_id, true)) $maps_deleted++;
        }
      }

      $new_ids = [];
      foreach ($rec['items'] as $item) {

        $target_type = $item['target_type'];
        $target_id   = $item['target_id'];

        // Unmatched line: optionally create a new ingredient stub for it.
        if ($target_id === null) {
          if (!$create_missing) { $items_skipped++; continue; }
          $stub = scoop_rcc_create_ingredient_stub($item['name']);
          if (!$stub['ok']) {
            $errors[] = "{$rec['title']}: create ingredient '{$item['name']}' failed: {$stub['error']}";
            $items_skipped++;
            continue;
          }
          $target_type = 'ingredient';
          $target_id   = (int) $stub['id'];
          $ing_created++;
        }

        $created = scoop_rcc_create_map_row($map_pod, $recipe_id, $item, $target_type, (int) $target_id);
        if ($created['ok']) {
          $new_ids[] = (int) $created['id'];
          $maps_created++;
        } else {
          $errors[] = "{$rec['title']} / {$item['name']}: {$created['error']}";
        }
      }

      if (!empty($new_ids)) {
        $link = scoop_rcc_link_recipe_maps($recipe_id, $new_ids);
        if ($link['ok']) {
          $did[] = count($new_ids) . ' maps';
        } else {
          $errors[] = "{$rec['title']}: linking maps to recipe failed: {$link['error']}";
        }
      } else {
        $maps_reason = 'no rows created';
      }
    }

    if (!empty($did)) {
      $recipes_done++;
      $outcomes[$i] = ['action' => 'imported', 'recipe_id' => $recipe_id, 'detail' => implode(' + ', $did)];
    } else {
      $outcomes[$i] = ['action' => 'noop', 'reason' => $maps_reason ?? 'nothing to do'];
    }
  }

  return [
    'recipes_done'         => $recipes_done,
    'maps_created'         => $maps_created,
    'maps_deleted'         => $maps_deleted,
    'ing_created'          => $ing_created,
    'items_skipped'        => $items_skipped,
    'instructions_written' => $instructions_written,
    'errors'               => $errors,
    'outcomes'             => $outcomes,
  ];
}

/**
 * Write the parsed Preparation Method into the recipe's `instructions` field.
 */
function scoop_rcc_save_recipe_instructions(int $recipe_id, string $text): array {
  try {
    $pod = pods('recipe', $recipe_id);
    if (!$pod || !$pod->id()) {
      return ['ok' => false, 'error' => "recipe #{$recipe_id} not found"];
    }
    $r = $pod->save(['instructions' => $text]);
    if ($r === false) {
      return ['ok' => false, 'error' => 'pods()->save() returned false'];
    }
    return ['ok' => true, 'error' => null];
  } catch (\Throwable $e) {
    return ['ok' => false, 'error' => $e->getMessage()];
  }
}

/**
 * Create one `recipe-ingredient-ma` row, pointing either its `ingredient`
 * field or its sub-recipe field at $target_id depending on $target_type.
 * Returns ['ok'=>bool,'id'=>?int,'error'=>?str].
 */
function scoop_rcc_create_map_row(string $map_pod, int $recipe_id, array $item, string $target_type, int $target_id): array {

  // Title is prefixed with the owning recipe's app ID so every map row for a
  // recipe shares that prefix, e.g. "8416 Cocoa Powder — 1/4 cups".
  $unit_raw = $item['unit'] !== '' ? ' ' . $item['unit'] : '';
  $title = scoop_rcc_truncate_str("{$recipe_id} {$item['name']} — {$item['qty_raw']}{$unit_raw}", 200);

  $writes = [
    'name'        => $title,
    'post_status' => 'publish', // Pods defaults new items to draft; the recipe
                                // relation only surfaces published map rows.
    'quantity'    => $item['qty'],
  ];

  if ($target_type === 'sub_recipe') {
    $field = scoop_rcc_map_subrecipe_field();
    if ($field === '') {
      return ['ok' => false, 'id' => null, 'error' => "sub-recipe field not found on '{$map_pod}' pod — add the field (or check its name) before importing sub-recipes"];
    }
    $writes[$field] = $target_id;
  } else {
    $writes[scoop_rcc_map_ingredient_field()] = $target_id;
  }

  $norm = $item['unit_norm'];
  if ($norm['field'] !== null && $norm['value'] !== null) {
    $writes[$norm['field']] = $norm['value'];
  }

  try {
    $pod = pods($map_pod);
    $new_id = $pod->add($writes);
    if (!$new_id) {
      return ['ok' => false, 'id' => null, 'error' => "pods('{$map_pod}')->add() returned no id"];
    }
    return ['ok' => true, 'id' => (int) $new_id, 'error' => null];
  } catch (\Throwable $e) {
    return ['ok' => false, 'id' => null, 'error' => $e->getMessage()];
  }
}

/**
 * Set a recipe's `ingredient_maps` relation to the given map IDs, then verify
 * the rows actually landed in wp_podsrel (Pods's cache can mask a no-op save).
 */
function scoop_rcc_link_recipe_maps(int $recipe_id, array $map_ids): array {

  try {
    $pod = pods('recipe', $recipe_id);
    if (!$pod || !$pod->id()) {
      return ['ok' => false, 'error' => "recipe #{$recipe_id} not found"];
    }
    $result = $pod->save([scoop_rcc_recipe_maps_field() => array_values(array_unique($map_ids))]);
    if ($result === false) {
      return ['ok' => false, 'error' => "pods()->save() returned false"];
    }
    if (scoop_rcc_count_recipe_maps($recipe_id) === 0) {
      return ['ok' => false, 'error' => 'save() ok but 0 rows in wp_podsrel — check the recipe.ingredient_maps field config'];
    }
    return ['ok' => true, 'error' => null];
  } catch (\Throwable $e) {
    return ['ok' => false, 'error' => $e->getMessage()];
  }
}
