<?php
if (!defined('ABSPATH')) exit;

/**
 * Relation reconciler — parses every recipe's `ingredient_list_str` into a
 * tree of {name + nested children} and writes those relationships back into
 * the pods via the `ingredients` Pods relation field. Missing pod entries
 * get auto-created as atomic ingredients. See RCC_IMPORT_README.md §4 and
 * §13 for design.
 *
 * Required pod fields (configure via Pods admin BEFORE running):
 *
 *   recipe.ingredients      Pods Relation, multi, related to: recipe + ingredient
 *   ingredient.ingredients  Pods Relation, multi, related to: ingredient
 *
 * Both should be bi-directional so the reverse edge ("which recipes use X")
 * is queryable for free.
 */

/* -------------------------------------------------------------------------
 * Parser
 *
 * Turns:
 *
 *   "chocolate paste (water, white sugar, cocoa powder ('sugar, cocoa-... vanilla.'))"
 *
 * into a tree of nodes:
 *
 *   [
 *     [ 'name' => 'chocolate paste', 'children' => [
 *         [ 'name' => 'water' ],
 *         [ 'name' => 'white sugar' ],
 *         [ 'name' => 'cocoa powder', 'is_label_decl' => true, 'children' => [
 *             [ 'name' => 'sugar' ],
 *             [ 'name' => 'cocoa- processed with alkali' ],
 *             [ 'name' => 'unsweetened chocolate' ],
 *             [ 'name' => 'soy lecithin- an emulsifier' ],
 *             [ 'name' => 'vanilla' ],
 *         ]],
 *     ]]
 *   ]
 *
 * Splitting is paren-depth-aware and single-quote-aware (label declarations
 * are wrapped in `'...'` per RCC's convention).
 * ---------------------------------------------------------------------- */

function scoop_rcc_parse_ingredient_list(string $s): array {
  $s = trim($s);
  if ($s === '') return [];

  $tokens = scoop_rcc_split_top_level_commas($s);
  $nodes = [];
  foreach ($tokens as $tok) {
    $node = scoop_rcc_parse_token(trim($tok));
    if ($node !== null) $nodes[] = $node;
  }
  return $nodes;
}

/**
 * Split a string on commas that are at paren depth 0 and not inside a
 * single-quoted region.
 */
function scoop_rcc_split_top_level_commas(string $s): array {
  $tokens = [];
  $buf    = '';
  $depth  = 0;
  $in_sq  = false;
  $len    = strlen($s);

  for ($i = 0; $i < $len; $i++) {
    $c = $s[$i];

    if ($c === "'") {
      $in_sq = !$in_sq;
      $buf  .= $c;
      continue;
    }
    if ($in_sq) { $buf .= $c; continue; }

    if ($c === '(') {
      $depth++;
      $buf .= $c;
    } elseif ($c === ')') {
      if ($depth > 0) $depth--;
      $buf .= $c;
    } elseif ($c === ',' && $depth === 0) {
      $tokens[] = $buf;
      $buf = '';
    } else {
      $buf .= $c;
    }
  }
  if ($buf !== '') $tokens[] = $buf;
  return $tokens;
}

/**
 * Parse one comma-separated token into {name, paren_content, is_label_decl,
 * children}. Strips trailing punctuation from the name (RCC's hyphen-before-
 * paren idiom: `chocolate chips-large(...)` and the period that sometimes
 * trails inside label decls).
 */
function scoop_rcc_parse_token(string $tok): ?array {

  $tok = trim($tok);
  if ($tok === '') return null;

  // Locate the first depth-0 "(" outside of single-quoted regions.
  $depth = 0; $in_sq = false; $paren_start = -1;
  $len = strlen($tok);

  for ($i = 0; $i < $len; $i++) {
    $c = $tok[$i];
    if ($c === "'") { $in_sq = !$in_sq; continue; }
    if ($in_sq) continue;
    if ($c === '(') {
      if ($depth === 0) { $paren_start = $i; break; }
      $depth++;
    } elseif ($c === ')') {
      if ($depth > 0) $depth--;
    }
  }

  if ($paren_start === -1) {
    $name = scoop_rcc_clean_name($tok);
    if ($name === '') return null;
    return [
      'name'          => $name,
      'paren_content' => '',
      'is_label_decl' => false,
      'children'      => [],
    ];
  }

  $name = scoop_rcc_clean_name(substr($tok, 0, $paren_start));
  if ($name === '') return null;

  // Find the matching close paren.
  $depth = 1; $in_sq = false; $paren_end = -1;
  for ($i = $paren_start + 1; $i < $len; $i++) {
    $c = $tok[$i];
    if ($c === "'") { $in_sq = !$in_sq; continue; }
    if ($in_sq) continue;
    if ($c === '(') $depth++;
    elseif ($c === ')') {
      $depth--;
      if ($depth === 0) { $paren_end = $i; break; }
    }
  }
  if ($paren_end === -1) $paren_end = $len; // malformed; recover

  $paren_content = substr($tok, $paren_start + 1, $paren_end - $paren_start - 1);
  $paren_content_trim = trim($paren_content);

  // Single-quoted parens content = label declaration. Strip the quotes; mark
  // it. Inner items get parsed with the same recursive logic.
  $is_label_decl = false;
  if (strlen($paren_content_trim) >= 2
      && $paren_content_trim[0] === "'"
      && substr($paren_content_trim, -1) === "'") {
    $is_label_decl = true;
    $paren_content = substr($paren_content_trim, 1, -1);
  }

  $children = scoop_rcc_parse_ingredient_list($paren_content);

  return [
    'name'          => $name,
    'paren_content' => $paren_content,
    'is_label_decl' => $is_label_decl,
    'children'      => $children,
  ];
}

/**
 * Strip the RCC-specific artifacts from a name fragment: trailing hyphens
 * (`16% meadowvale dairy base-(...)`), trailing periods (label-decl items
 * like `vanilla.`), and collapse internal whitespace.
 */
function scoop_rcc_clean_name(string $s): string {
  $s = trim($s);
  $s = rtrim($s, '-.');
  $s = preg_replace('/\s+/', ' ', $s);
  return trim((string) $s);
}

/* -------------------------------------------------------------------------
 * Resolver
 *
 * Given a node name, find an existing pod entity or create one.
 * ---------------------------------------------------------------------- */

/**
 * @return array{ entity_type: string, id: int, created: bool, match_kind: string }
 */
function scoop_rcc_resolve_node(array $node, array &$state): array {

  $raw_name = $node['name'];
  $norm     = scoop_rcc_normalize_title($raw_name);

  if ($norm === '') {
    return ['entity_type' => 'ingredient', 'id' => 0, 'created' => false, 'match_kind' => 'empty'];
  }

  // 1. Existing ingredient by normalized title.
  if (isset($state['ingredient_index']['by_title'][$norm])) {
    return [
      'entity_type' => 'ingredient',
      'id'          => $state['ingredient_index']['by_title'][$norm][0],
      'created'     => false,
      'match_kind'  => 'ingredient_title',
    ];
  }

  // 2. Existing recipe by normalized title.
  if (isset($state['recipe_index']['by_title'][$norm])) {
    return [
      'entity_type' => 'recipe',
      'id'          => $state['recipe_index']['by_title'][$norm][0],
      'created'     => false,
      'match_kind'  => 'recipe_title',
    ];
  }

  // 3. Paren-to-hyphen normalization. "chocolate chips-large" → "Chocolate Chips (large)"
  if (!$node['is_label_decl'] && strpos($raw_name, '-') !== false) {
    $dash_at = strrpos($raw_name, '-');
    if ($dash_at !== false && $dash_at > 0 && $dash_at < strlen($raw_name) - 1) {
      $candidate = trim(substr($raw_name, 0, $dash_at)) . ' (' . trim(substr($raw_name, $dash_at + 1)) . ')';
      $cnorm = scoop_rcc_normalize_title($candidate);
      if (isset($state['ingredient_index']['by_title'][$cnorm])) {
        return [
          'entity_type' => 'ingredient',
          'id'          => $state['ingredient_index']['by_title'][$cnorm][0],
          'created'     => false,
          'match_kind'  => 'ingredient_hyphen',
        ];
      }
    }
  }

  // 4. Create a new atomic ingredient.
  $r = scoop_rcc_create_ingredient_stub($raw_name);
  if (!$r['ok']) {
    $state['errors'][] = "Create-stub failed for '{$raw_name}': {$r['error']}";
    return ['entity_type' => 'ingredient', 'id' => 0, 'created' => false, 'match_kind' => 'create_failed'];
  }
  // Index it immediately so subsequent lookups hit it.
  $state['ingredient_index']['by_title'][$norm] = [$r['id']];
  $state['ingredient_index']['rows'][$r['id']]  = ['id' => $r['id'], 'post_title' => $raw_name];

  return [
    'entity_type' => 'ingredient',
    'id'          => $r['id'],
    'created'     => true,
    'match_kind'  => 'created',
  ];
}

/**
 * Create a bare ingredient pod row with just a title. The reconciler stores
 * a one-line note explaining provenance.
 */
function scoop_rcc_create_ingredient_stub(string $name): array {

  try {
    $pod = pods('ingredient');
    $new_id = $pod->add([
      'name'  => $name,
      'notes' => 'Auto-created by Scoop reconciler ' . current_time('mysql'),
    ]);
    if (!$new_id) {
      return ['ok' => false, 'id' => null, 'error' => 'pods()->add() returned no id'];
    }
    return ['ok' => true, 'id' => (int) $new_id, 'error' => null];
  } catch (\Throwable $e) {
    return ['ok' => false, 'id' => null, 'error' => $e->getMessage()];
  }
}

/* -------------------------------------------------------------------------
 * Walker
 *
 * Recursively walks a recipe's parsed tree, resolves each node, writes the
 * parent's `ingredients` relation, and recurses into nodes that have their
 * own children (sub-recipes and compound ingredients).
 *
 * `processed_entities` ensures we only write each entity's ingredients once
 * across the entire run, even when the same ingredient/recipe is referenced
 * by many parent recipes.
 * ---------------------------------------------------------------------- */

function scoop_rcc_walk_entity_tree(
  string $entity_type,
  int $entity_id,
  string $entity_name,
  array $nodes,
  array &$state
): void {

  if ($entity_id === 0 || empty($nodes)) return;

  $child_refs = [];

  foreach ($nodes as $node) {

    $resolved = scoop_rcc_resolve_node($node, $state);
    if ($resolved['id'] === 0) continue;

    if ($resolved['created']) {
      $state['created_log'][] = [
        'time'        => current_time('mysql'),
        'id'          => $resolved['id'],
        'name'        => $node['name'],
        'compound'    => !empty($node['children']) ? 'compound' : 'atomic',
        'source_type' => $entity_type,
        'source_id'   => $entity_id,
        'source_name' => $entity_name,
        'raw_token'   => scoop_rcc_truncate_token($node),
      ];
    }

    $child_refs[] = [
      'id'   => $resolved['id'],
      'type' => $resolved['entity_type'],
    ];

    // Recurse only for ingredients. Sub-recipes are handled authoritatively
    // by the main loop using each recipe's own ingredient_list_str — recursing
    // here would write parent-rederived data that may not match the recipe's
    // own canonical decomposition.
    if (!empty($node['children']) && $resolved['entity_type'] === 'ingredient') {
      $key = 'ingredient:' . $resolved['id'];
      if (!isset($state['processed_entities'][$key])) {
        $state['processed_entities'][$key] = true;
        scoop_rcc_walk_entity_tree(
          'ingredient',
          $resolved['id'],
          $node['name'],
          $node['children'],
          $state
        );
      }
    }
  }

  $ok = scoop_rcc_write_ingredients($entity_type, $entity_id, $child_refs);
  if ($ok['ok']) {
    $state['relations_written']++;
  } else {
    $state['errors'][] = "{$entity_type} #{$entity_id} '{$entity_name}': {$ok['error']}";
  }
}

/**
 * Write the ingredients relation on a pod row. Pass an array of post IDs;
 * Pods resolves which target pod each ID belongs to via wp_posts.post_type.
 *
 * Verifies the write by querying `wp_podsrel` directly — Pods's own field()
 * read can return stale cached data inside the same request, so we bypass
 * it and trust the relations table.
 */
function scoop_rcc_write_ingredients(string $pod_name, int $pod_id, array $child_refs): array {

  if (empty($child_refs)) return ['ok' => true, 'error' => null, 'persisted' => 0];

  try {
    $pod = pods($pod_name, $pod_id);
    if (!$pod || !$pod->id()) {
      return ['ok' => false, 'error' => "pod row {$pod_name}#{$pod_id} not found", 'persisted' => 0];
    }

    $ids = array_values(array_unique(array_map(
      function ($ref) { return (int) $ref['id']; },
      $child_refs
    )));

    $result = $pod->save(['ingredients' => $ids]);
    if ($result === false) {
      return ['ok' => false, 'error' => "pods()->save() returned false", 'persisted' => 0];
    }

    // Direct SQL ground-truth: count rows in podsrel for this entity.
    $persisted_count = scoop_rcc_count_podsrel_rows($pod_name, $pod_id);

    if ($persisted_count === 0) {
      // Persisted nothing — likely a format issue or a Pods cache miss.
      // Inspect the field config and report something actionable.
      $hint = scoop_rcc_diagnose_relation_failure($pod_name);
      return [
        'ok'        => false,
        'error'     => "save() returned ok but 0 rows landed in wp_podsrel for `{$pod_name}#{$pod_id}.ingredients`. {$hint}",
        'persisted' => 0,
      ];
    }

    return ['ok' => true, 'error' => null, 'persisted' => $persisted_count];

  } catch (\Throwable $e) {
    return ['ok' => false, 'error' => $e->getMessage(), 'persisted' => 0];
  }
}

/**
 * Count rows in wp_podsrel for the given source row + the `ingredients` field.
 * Returns 0 if anything's missing (field, pod metadata, etc.) — the caller
 * treats 0 as failure.
 */
function scoop_rcc_count_podsrel_rows(string $pod_name, int $pod_id): int {

  global $wpdb;

  $meta = scoop_rcc_get_pod_field_meta($pod_name, 'ingredients');
  if (!$meta) return 0;

  $sql = $wpdb->prepare(
    "SELECT COUNT(*) FROM `{$wpdb->prefix}podsrel`
     WHERE pod_id = %d AND field_id = %d AND item_id = %d",
    $meta['pod_id'], $meta['field_id'], $pod_id
  );
  return (int) $wpdb->get_var($sql);
}

/**
 * Cached lookup of [pod_id, field_id] for a (pod_name, field_name) pair.
 * Pods stores both as WP-post-style IDs in modern versions.
 */
function scoop_rcc_get_pod_field_meta(string $pod_name, string $field_name): ?array {

  static $cache = [];
  $key = "{$pod_name}::{$field_name}";
  if (isset($cache[$key])) return $cache[$key];

  if (!function_exists('pods_api')) { return $cache[$key] = null; }

  $pod_def = pods_api()->load_pod(['name' => $pod_name]);
  if (!$pod_def) return $cache[$key] = null;

  $pod_id = (int) ($pod_def['id'] ?? 0);
  $fields = $pod_def['fields'] ?? [];
  if (!isset($fields[$field_name])) return $cache[$key] = null;

  $field_id = (int) ($fields[$field_name]['id'] ?? 0);
  if (!$pod_id || !$field_id) return $cache[$key] = null;

  return $cache[$key] = ['pod_id' => $pod_id, 'field_id' => $field_id];
}

/**
 * Build a one-liner hint about what's likely wrong with the field config
 * when a save persists 0 rows. Uses load_pod() since pre-flight already
 * passed surface-level checks.
 */
function scoop_rcc_diagnose_relation_failure(string $pod_name): string {

  if (!function_exists('pods_api')) return '(Pods not available)';
  $pod_def = pods_api()->load_pod(['name' => $pod_name]);
  if (!$pod_def) return "(could not load pod definition for `{$pod_name}`)";

  $f = ($pod_def['fields']['ingredients'] ?? null);
  if (!$f) return "(no `ingredients` field on `{$pod_name}` pod)";

  $opts = is_array($f['options'] ?? null) ? $f['options'] : [];
  $pick_object = $opts['pick_object'] ?? ($f['pick_object'] ?? '');
  $pick_val    = $opts['pick_val']    ?? ($f['pick_val']    ?? '');
  $format_type = $opts['pick_format_type'] ?? ($f['pick_format_type'] ?? '');

  if ($pick_object === '') {
    return 'Field has no "Related to" target set. In Pods admin: edit the `ingredients` field → set "Related to" to a Pod (recipe and/or ingredient).';
  }
  if ($format_type === 'single') {
    return "Field is set to single-select but the reconciler writes multiple IDs. In Pods admin: change `ingredients` to multi-select.";
  }
  return "Field is `{$pick_object}/{$pick_val}`, format `{$format_type}`. Verify the target pod matches what's being written (writes contained mixed recipe + ingredient IDs).";
}

/**
 * Pre-flight: confirm the `ingredients` Pods field exists and is a relationship.
 * Cheap (one pod load per type) — runs once before the bulk walk.
 *
 * Returns ['ok'=>bool, 'details'=>[<pod_name>=>['exists'=>bool, 'type'=>str, 'related_to'=>str]]]
 */
function scoop_rcc_check_ingredients_field(): array {

  $report = [];
  $all_ok = true;

  foreach (['recipe', 'ingredient'] as $pod_name) {

    if (!function_exists('pods_api')) {
      $report[$pod_name] = ['exists' => false, 'error' => 'Pods plugin not active'];
      $all_ok = false;
      continue;
    }

    $pod_def = pods_api()->load_pod(['name' => $pod_name]);
    if (!$pod_def) {
      $report[$pod_name] = ['exists' => false, 'error' => "pod definition not loadable"];
      $all_ok = false;
      continue;
    }

    $fields = $pod_def['fields'] ?? [];
    if (!isset($fields['ingredients'])) {
      $report[$pod_name] = ['exists' => false, 'error' => "no field named 'ingredients'"];
      $all_ok = false;
      continue;
    }

    $f    = $fields['ingredients'];
    $type = $f['type'] ?? '';

    if ($type !== 'pick') {
      $report[$pod_name] = [
        'exists'     => true,
        'type'       => $type,
        'error'      => "field type is '{$type}' — expected 'pick' (Pods Relationship)",
      ];
      $all_ok = false;
      continue;
    }

    $opts        = is_array($f['options'] ?? null) ? $f['options'] : [];
    $pick_object = $opts['pick_object'] ?? ($f['pick_object'] ?? '');
    $pick_val    = $opts['pick_val']    ?? ($f['pick_val']    ?? '');

    $report[$pod_name] = [
      'exists'      => true,
      'type'        => $type,
      'pick_object' => $pick_object,
      'pick_val'    => $pick_val,
      'error'       => null,
    ];
  }

  return ['ok' => $all_ok, 'details' => $report];
}

/**
 * Slim down a parsed-node back into a string for the log.
 */
function scoop_rcc_truncate_token(array $node): string {
  $s = $node['name'];
  if (!empty($node['paren_content'])) {
    $inside = $node['is_label_decl']
      ? "'" . $node['paren_content'] . "'"
      : $node['paren_content'];
    $s .= ' (' . $inside . ')';
  }
  if (mb_strlen($s) > 120) $s = mb_substr($s, 0, 117) . '…';
  return $s;
}

/* -------------------------------------------------------------------------
 * Public entry point
 * ---------------------------------------------------------------------- */

/**
 * Run the reconciler over every recipe with a non-empty ingredient_list_str.
 * Returns a result array with counts + the creation log + the error list.
 *
 * Caller should set_time_limit(0) and ignore_user_abort(true) before calling.
 */
function scoop_rcc_run_reconciler(): array {

  global $wpdb;

  // Pre-flight: confirm the ingredients relation field exists on both pods.
  // Without this, the walk would silently no-op and report success.
  $field_check = scoop_rcc_check_ingredients_field();
  if (!$field_check['ok']) {
    return [
      'recipes_seen'        => 0,
      'recipes_with_data'   => 0,
      'relations_written'   => 0,
      'ingredients_created' => 0,
      'created_log'         => [],
      'errors'              => array_map(
        function ($pod, $info) {
          return "pod '{$pod}': " . ($info['error'] ?? 'unknown problem');
        },
        array_keys(array_filter($field_check['details'], function ($d) { return !empty($d['error']); })),
        array_values(array_filter($field_check['details'], function ($d) { return !empty($d['error']); }))
      ),
      'field_check'         => $field_check,
      'aborted'             => true,
    ];
  }

  $ingredient_index = scoop_rcc_load_pod_index('ingredient');
  $recipe_index     = scoop_rcc_load_pod_index('recipe');

  $state = [
    'ingredient_index'    => $ingredient_index,
    'recipe_index'        => $recipe_index,
    'processed_entities'  => [],
    'created_log'         => [],
    'errors'              => [],
    'relations_written'   => 0,
    'recipes_seen'        => 0,
    'recipes_with_data'   => 0,
  ];

  // Pull every recipe that has a non-empty ingredient_list_str.
  $rt = $wpdb->prefix . 'pods_recipe';
  $pt = $wpdb->prefix . 'posts';
  $recipes = $wpdb->get_results(
    "SELECT pr.id, p.post_title, pr.ingredient_list_str
       FROM `{$rt}` pr
       INNER JOIN `{$pt}` p ON p.ID = pr.id
      WHERE p.post_type = 'recipe'
        AND p.post_status NOT IN ('trash','auto-draft')
        AND pr.ingredient_list_str IS NOT NULL
        AND pr.ingredient_list_str != ''",
    ARRAY_A
  );

  foreach ((array) $recipes as $r) {
    $state['recipes_seen']++;
    $tree = scoop_rcc_parse_ingredient_list((string) $r['ingredient_list_str']);
    if (empty($tree)) continue;

    $state['recipes_with_data']++;
    $recipe_id   = (int) $r['id'];
    $recipe_name = (string) $r['post_title'];

    $key = "recipe:{$recipe_id}";
    $state['processed_entities'][$key] = true;

    scoop_rcc_walk_entity_tree('recipe', $recipe_id, $recipe_name, $tree, $state);
  }

  return [
    'recipes_seen'        => $state['recipes_seen'],
    'recipes_with_data'   => $state['recipes_with_data'],
    'relations_written'   => $state['relations_written'],
    'ingredients_created' => count($state['created_log']),
    'created_log'         => $state['created_log'],
    'errors'              => $state['errors'],
    'field_check'         => $field_check,
    'aborted'             => false,
  ];
}

/**
 * Write the creation log to a CSV file in wp-content/uploads/RCC/.
 * Returns the absolute path on success, '' on failure.
 */
function scoop_rcc_write_reconciler_log_csv(array $created_log): string {

  $upload = wp_upload_dir();
  $dir    = trailingslashit($upload['basedir']) . 'RCC';
  if (!is_dir($dir)) wp_mkdir_p($dir);

  $path = trailingslashit($dir) . 'reconciler-log-' . gmdate('Ymd-His') . '.csv';
  $fh   = @fopen($path, 'w');
  if (!$fh) return '';

  fputcsv($fh, ['time','new_id','name','kind','source_type','source_id','source_name','raw_token']);
  foreach ($created_log as $row) {
    fputcsv($fh, [
      $row['time'], $row['id'], $row['name'], $row['compound'],
      $row['source_type'], $row['source_id'], $row['source_name'], $row['raw_token'],
    ]);
  }
  fclose($fh);
  return $path;
}
