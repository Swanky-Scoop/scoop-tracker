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
