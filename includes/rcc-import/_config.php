<?php
if (!defined('ABSPATH')) exit;

/**
 * Single source of truth for RCC → pod field mappings.
 *
 * Each entry maps a CSV column header to a pod field. The `placeholder_skip`
 * flag tells the importer to drop this field from a row's diff when the row
 * has been classified as `_placeholder_suspected` (see scoop_rcc_is_placeholder
 * in csv.php and RCC_IMPORT_README.md §10).
 *
 * CSV columns absent from this map are intentionally not imported (see the
 * "Skipped CSV columns" notes in RCC_IMPORT_README.md §6).
 */
function scoop_rcc_field_map(string $type): array {

  static $maps = null;

  if ($maps === null) {
    $maps = [

      'recipe' => [
        'ID'              => ['field' => 'rcc_id'],
        'Yield Count'     => ['field' => 'yield_count'],
        'Yield Units'     => ['field' => 'yield_units'],
        'Cost'            => ['field' => 'cost',           'placeholder_skip' => true],
        'Cost Per Unit'   => ['field' => 'cost_per_unit',  'placeholder_skip' => true],
        'Allergens'       => ['field' => 'allergens_str'],
        'Categories'      => ['field' => 'categories_str'],
        'Ingredient List' => ['field' => 'ingredient_list_str'],
      ],

      'ingredient' => [
        'ID'                   => ['field' => 'rcc_id'],
        'Price'                => ['field' => 'price',      'placeholder_skip' => true],
        'Price/unit'           => [
          'field'            => 'price_unit',
          'placeholder_skip' => true,
          'transform'        => 'scoop_rcc_extract_currency_value',
        ],
        'Supplier'             => ['field' => 'supplier'],
        'Brand'                => ['field' => 'brand'],
        'Case'                 => ['field' => 'case'],
        'Pack'                 => ['field' => 'pack'],
        'Unit'                 => ['field' => 'unit'],
        'Allergens'            => ['field' => 'allergens_str'],
        'Notes'                => ['field' => 'notes'],
        'Label Certifications' => ['field' => 'label_certs'],
      ],
    ];
  }

  return $maps[$type] ?? [];
}

/**
 * Pod name for a given CSV type.
 */
function scoop_rcc_pod_name(string $type): string {
  return $type;
}

/* -------------------------------------------------------------------------
 * Recipe ingredient-quantity import (Markdown).
 *
 * A separate flow from the CSV importer: it parses a Recipe Cost Calculator
 * recipe export (Markdown) into per-recipe ingredient quantities and writes
 * one `recipe-ingredient-ma` pod row per ingredient, linked back to the
 * recipe via its `ingredient_maps` relation. See RCC_IMPORT_README.md §14.
 * ---------------------------------------------------------------------- */

/**
 * The join pod that holds one (ingredient, quantity, unit) tuple. The post
 * type name is truncated to WordPress's 20-char limit — it is NOT
 * `recipe_ingredient_map`. Confirmed against the Pods package export
 * (data-exports/pods-package-2026-06-07.json, pod id 8645).
 */
function scoop_rcc_map_pod_name(): string {
  return 'recipe-ingredient-ma';
}

/**
 * The multi-relationship field on the `recipe` pod that points at the map
 * rows (Pods field id 8650).
 */
function scoop_rcc_recipe_maps_field(): string {
  return 'ingredient_maps';
}

/**
 * The map pod's single-pick field that targets an `ingredient`.
 */
function scoop_rcc_map_ingredient_field(): string {
  return 'ingredient';
}

/**
 * The map pod's single-pick field that targets a `recipe` (sub-recipe).
 *
 * Resolved from the live Pods config rather than hardcoded — the field was
 * added after the 2026-06-07 package export, so its exact machine name isn't
 * in any export we can read. This site names fields with hyphens
 * (`sub-recipes`, `recipe-ingredient-ma`), so `sub-recipe` is tried first.
 * Returns '' if no such field exists yet, so the importer can report it
 * instead of writing to a nonexistent column.
 *
 * Must work across Pods versions: ≤2.7 returned a plain array from load_pod(),
 * but 2.8+/3.x (this site runs 3.3.9) returns a Pods\Whatsit\Pod object. The
 * old `is_array($def)` gate silently treated that object as "no fields", so the
 * lookup returned '' even when the field existed — which is exactly the
 * "sub-recipe field not found" failure on import. We now ask Pods for each
 * candidate field directly (version-agnostic) and only fall back to
 * enumerating the pod's fields.
 */
function scoop_rcc_map_subrecipe_field(): string {

  static $name = null;
  if ($name !== null) return $name;
  $name = '';

  if (!function_exists('pods_api')) return $name;

  $map_pod    = scoop_rcc_map_pod_name();
  $candidates = ['sub-recipe', 'sub_recipe', 'subrecipe', 'sub-recipes', 'sub_recipes'];
  $api        = pods_api();

  // Primary: ask Pods for each candidate field by name. load_field() works the
  // same on the legacy array API and the 3.x object API.
  foreach ($candidates as $cand) {
    try {
      $field = $api->load_field(['pod' => $map_pod, 'name' => $cand]);
    } catch (\Throwable $e) {
      $field = null;
    }
    if ($field && !is_wp_error($field)) { $name = $cand; return $name; }
  }

  // Fallback: enumerate the pod's fields (tolerant of array vs object) and
  // match by name, then by a "sub-recipe"-ish label.
  $fields = scoop_rcc_load_pod_fields($map_pod);
  foreach ($candidates as $cand) {
    if (isset($fields[$cand])) { $name = $cand; return $name; }
  }
  foreach ($fields as $fname => $f) {
    $label = scoop_rcc_field_label($f);
    if ($label !== '' && preg_match('/sub.?recipe/i', $label)) { $name = (string) $fname; return $name; }
  }

  return $name;
}

/**
 * Field definitions for a pod, keyed by field name. Tolerant of every Pods
 * return shape: a Whatsit\Pod object (2.8+/3.x, via get_fields()), an
 * ArrayAccess object, or a legacy array (≤2.7).
 */
function scoop_rcc_load_pod_fields(string $pod_name): array {
  if (!function_exists('pods_api')) return [];

  try {
    $def = pods_api()->load_pod(['name' => $pod_name]);
  } catch (\Throwable $e) {
    return [];
  }
  if (empty($def)) return [];

  if (is_object($def) && method_exists($def, 'get_fields')) {
    $fields = $def->get_fields();
  } elseif (is_array($def) || $def instanceof ArrayAccess) {
    $fields = $def['fields'] ?? [];
  } else {
    return [];
  }

  if ($fields instanceof Traversable) $fields = iterator_to_array($fields);
  return is_array($fields) ? $fields : [];
}

/** Read a field's label regardless of array vs object (Whatsit\Field) shape. */
function scoop_rcc_field_label($field): string {
  if (is_array($field)) return (string) ($field['label'] ?? '');
  if (is_object($field)) {
    if (method_exists($field, 'get_label')) return (string) $field->get_label();
    if ($field instanceof ArrayAccess && isset($field['label'])) return (string) $field['label'];
  }
  return '';
}

/**
 * Allowed values for the map pod's two unit pick fields, copied verbatim
 * from the pod definition (custom-simple lists). The importer only ever
 * writes one of these exact strings.
 */
function scoop_rcc_unit_vol_values(): array {
  return ['pinch', 'tsp', 'Tbl', 'c', 'oz', 'pt', 'qt', 'gal', 'ml', 'L', 'other'];
}

function scoop_rcc_unit_weight_values(): array {
  return ['oz', 'lb', 'g', 'kg'];
}

/**
 * Every unit token the run-style tokenizer should recognise as a boundary,
 * longest-first so the alternation prefers "tbsp" over "t". Includes count
 * units (each, pinch, …) that map to neither pick field but still mark a
 * "<qty> <unit>" boundary in the free-text run.
 */
function scoop_rcc_unit_vocab(): array {
  static $vocab = null;
  if ($vocab === null) {
    $vocab = [
      // weight
      'grams', 'gram', 'g', 'kg', 'mg', 'oz', 'ounce', 'ounces', 'lb', 'lbs', 'pound', 'pounds',
      // volume
      'tablespoons', 'tablespoon', 'tbsp', 'tbl', 'tsp', 'teaspoons', 'teaspoon',
      'cups', 'cup', 'c', 'pints', 'pint', 'pts', 'pt', 'quarts', 'quart', 'qts', 'qt',
      'gallons', 'gallon', 'gal', 'ml', 'liters', 'litres', 'liter', 'litre', 'l',
      'floz',
      // count / other
      'each', 'ct', 'count', 'pinch', 'pinches', 'dash', 'dashes', 'drop', 'drops',
      'clove', 'cloves', 'stick', 'sticks', 'slice', 'slices', 'can', 'cans',
      'bag', 'bags', 'sprig', 'sprigs', 'pieces', 'piece',
    ];
    usort($vocab, function ($a, $b) { return strlen($b) - strlen($a); });
  }
  return $vocab;
}

/**
 * Normalize a raw unit token to the pod's pick value and tell the caller
 * which field it belongs in.
 *
 * Returns:
 *   [
 *     'kind'  => 'weight' | 'vol' | 'other' | 'none',
 *     'field' => 'unit_weight' | 'unit_vol' | null,
 *     'value' => '<pick value>' | null,
 *     'raw'   => '<original token>',
 *   ]
 *
 * `oz` is ambiguous (it's in both pick lists). These recipes weigh in
 * ounces far more often than they pour fluid ounces, so bare "oz" maps to
 * weight; fluid ounces must arrive as "floz". `pinch` is a first-class
 * `unit_vol` value. Any other present-but-untranslatable token (each, dash,
 * mg, floz, …) falls back to `unit_vol = 'other'` — the operator-confirmed
 * catch-all. A genuinely empty unit returns a null field (kind 'none').
 */
function scoop_rcc_normalize_unit(string $raw): array {
  $t = strtolower(trim($raw));
  $t = rtrim($t, '.');

  if ($t === '') {
    return ['kind' => 'none', 'field' => null, 'value' => null, 'raw' => $raw];
  }

  $weight = [
    'g' => 'g', 'gram' => 'g', 'grams' => 'g',
    'kg' => 'kg',
    'oz' => 'oz', 'ounce' => 'oz', 'ounces' => 'oz',
    'lb' => 'lb', 'lbs' => 'lb', 'pound' => 'lb', 'pounds' => 'lb',
  ];
  $vol = [
    'pinch' => 'pinch', 'pinches' => 'pinch',
    'tsp' => 'tsp', 'teaspoon' => 'tsp', 'teaspoons' => 'tsp',
    'tbsp' => 'Tbl', 'tbl' => 'Tbl', 'tablespoon' => 'Tbl', 'tablespoons' => 'Tbl',
    'c' => 'c', 'cup' => 'c', 'cups' => 'c',
    'pt' => 'pt', 'pts' => 'pt', 'pint' => 'pt', 'pints' => 'pt',
    'qt' => 'qt', 'qts' => 'qt', 'quart' => 'qt', 'quarts' => 'qt',
    'gal' => 'gal', 'gallon' => 'gal', 'gallons' => 'gal',
    'ml' => 'ml',
    'l' => 'L', 'liter' => 'L', 'liters' => 'L', 'litre' => 'L', 'litres' => 'L',
  ];

  if (isset($weight[$t])) return ['kind' => 'weight', 'field' => 'unit_weight', 'value' => $weight[$t], 'raw' => $raw];
  if (isset($vol[$t]))    return ['kind' => 'vol',    'field' => 'unit_vol',    'value' => $vol[$t],    'raw' => $raw];

  // Present but untranslatable → the catch-all unit_vol bucket.
  return ['kind' => 'other', 'field' => 'unit_vol', 'value' => 'other', 'raw' => $raw];
}

/**
 * The transient key holding mid-flow state for the current user.
 * TTL is intentionally short — abandoned imports shouldn't accumulate.
 */
function scoop_rcc_transient_key(): string {
  $uid = get_current_user_id();
  return "scoop_rcc_import_{$uid}";
}

function scoop_rcc_transient_ttl(): int {
  return HOUR_IN_SECONDS;
}
