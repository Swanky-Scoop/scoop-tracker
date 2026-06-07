<?php
/**
 * includes/audit.php
 *
 * Read-only price/data-quality audit endpoints.
 *
 *   GET /wp-json/scoop/v1/audit/ingredients   → Feature 1 (PRICE-SOURCING.md §4)
 *   GET /wp-json/scoop/v1/audit/flavors        → Feature 2 (PRICE-SOURCING.md §4)
 *
 * Both are pure reporting endpoints — they NEVER write. Their job is to
 * surface the worst pricing/data-quality problems for human review, per
 * CLAUDE.md "Ingredient pricing — known data quality issues" and the
 * "Data repair policy" (detect first, never silently correct).
 *
 * Routes are registered in includes/_routes.php (alongside the bundle route);
 * this file holds only the handlers + helpers, mirroring includes/analytics.php.
 *
 * All data access goes through pods()->find()/->field() rather than raw SQL,
 * because relationship/currency storage varies by Pods version+config.
 *
 * ── Data model (confirmed against data-exports/pods-package-2026-06-07.json) ──
 *
 *   ingredient pod:
 *     price        (currency)  purchase price for one pack
 *     price_unit   (currency)  RCC "Price/unit" with the unit decoration
 *                              stripped on import ("$1.28/oz" → 1.28) — the
 *                              unit it was per is LOST, so it is surfaced but
 *                              never used for the calc.
 *     price_gram   (currency)  price per gram (normalized)
 *     grams        (number)    pack size normalized to grams
 *     liters       (number)    pack size normalized to liters
 *     unit         (number)    legacy "Unit (org)" code
 *     yield_units  (text)      human unit label ("lb", "Kg", "L", …)
 *     base         (pick→base) base type for base ingredients (most are null)
 *
 *   recipe pod:
 *     cost (currency), cost_per_unit (currency), price_gram (currency),
 *     grams/liters (number), yield_units (text), yield_count (number),
 *     ingredients (pick multi→ingredient), ingredient_maps (pick multi→
 *     recipe-ingredient-ma), sub-recipes (pick multi→recipe), flavor (pick→flavor)
 *
 *   flavor pod:
 *     recipe (pick single→recipe)  ← the flavor→recipe link this audit follows
 *
 * ── Normalization caveat ──
 *
 *   "calculated_price_per_liter" / "calculated_cost_per_liter" are normalized to
 *   liters because that is what PRICE-SOURCING.md asks for. When only a weight
 *   (grams / price_gram) is on file we convert with an assumed density of
 *   1 g/ml. That is approximate for non-water-like ingredients (e.g. cocoa
 *   powder) and is flagged in `normalization_basis` on every row so the
 *   approximation is never silent. This is fine for the audit's purpose —
 *   catching order-of-magnitude and out-of-bounds errors — but a normalized
 *   value must NOT be treated as a true price without human confirmation.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Tunable heuristics (PRICE-SOURCING.md §3 "Sanity bounds" are starting
//    points; refine as real prices are confirmed). ─────────────────────────

// Generic per-liter "obviously broken" bounds for an ingredient. Deliberately
// wide — order-of-magnitude vs. the median is the primary detector; these only
// catch values that are absurd on their face.
if ( ! defined( 'SCOOP_AUDIT_INGREDIENT_PPL_MIN' ) ) define( 'SCOOP_AUDIT_INGREDIENT_PPL_MIN', 0.10 );
if ( ! defined( 'SCOOP_AUDIT_INGREDIENT_PPL_MAX' ) ) define( 'SCOOP_AUDIT_INGREDIENT_PPL_MAX', 1000.0 );

// Order-of-magnitude trigger: value is ≥ this many times, or ≤ 1/this, the median.
if ( ! defined( 'SCOOP_AUDIT_OOM_FACTOR' ) ) define( 'SCOOP_AUDIT_OOM_FACTOR', 10.0 );

// Finished-flavor cost/liter sanity bounds (PRICE-SOURCING.md §4 Feature 2).
if ( ! defined( 'SCOOP_AUDIT_FLAVOR_COST_MIN' ) ) define( 'SCOOP_AUDIT_FLAVOR_COST_MIN', 1.00 );
if ( ! defined( 'SCOOP_AUDIT_FLAVOR_COST_MAX' ) ) define( 'SCOOP_AUDIT_FLAVOR_COST_MAX', 50.0 );


// ─────────────────────────────────────────────────────────────────────────────
// Route handlers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * GET /wp-json/scoop/v1/audit/ingredients
 */
function scoop_audit_ingredients_handler( \WP_REST_Request $req ): \WP_REST_Response {
  $trace_id = scoop_audit_trace_id();

  if ( ! function_exists( 'pods' ) ) {
    return new \WP_REST_Response( [ 'ok' => false, 'error' => 'Pods framework not available.', 'trace_id' => $trace_id ], 500 );
  }

  $cache_key = 'scoop_audit_ing_' . scoop_cache_version();
  if ( ! $req->get_param( 'nocache' ) ) {
    $cached = get_transient( $cache_key );
    if ( is_array( $cached ) ) {
      $cached['trace_id'] = $trace_id;
      $cached['_cache']   = 'hit';
      return new \WP_REST_Response( $cached, 200 );
    }
  }

  try {
    $built = scoop_audit_build_ingredients();
  } catch ( \Throwable $e ) {
    error_log( sprintf( '[scoop_audit trace=%s ingredients] %s: %s @ %s:%d', $trace_id, get_class( $e ), $e->getMessage(), $e->getFile(), $e->getLine() ) );
    return new \WP_REST_Response( [ 'ok' => false, 'error' => 'Failed to build ingredient audit.', 'trace_id' => $trace_id ], 500 );
  }

  $rows = array_values( $built['rows'] );

  $response = [
    'ok'                     => true,
    'generated_at'           => gmdate( 'Y-m-d\TH:i:s' ),
    'count'                  => count( $rows ),
    'median_price_per_liter' => $built['median'],
    'bounds'                 => [ 'min' => SCOOP_AUDIT_INGREDIENT_PPL_MIN, 'max' => SCOOP_AUDIT_INGREDIENT_PPL_MAX, 'oom_factor' => SCOOP_AUDIT_OOM_FACTOR ],
    'flag_counts'            => scoop_audit_count_flags( $rows ),
    'notes'                  => [
      'price_per_liter is normalized; weight-only ingredients use an assumed density of 1 g/ml (see normalization_basis per row).',
      'price_unit ("$X/oz" with unit stripped on import) is surfaced but never used in the calculation.',
      'order_of_magnitude is measured against the median of all computable per-liter values.',
      'This report is read-only. Do not auto-correct — surface for human review only.',
    ],
    'columns'                => [ 'ingredient_id', 'ingredient_name', 'wp_admin_url', 'stored_unit', 'stored_price', 'calculated_price_per_liter', 'flag', 'likely_issue' ],
    'rows'                   => $rows,
    'trace_id'               => $trace_id,
  ];

  set_transient( $cache_key, $response, SCOOP_CACHE_TTL );
  $response['_cache'] = 'miss';
  return new \WP_REST_Response( $response, 200 );
}

/**
 * GET /wp-json/scoop/v1/audit/flavors
 */
function scoop_audit_flavors_handler( \WP_REST_Request $req ): \WP_REST_Response {
  $trace_id = scoop_audit_trace_id();

  if ( ! function_exists( 'pods' ) ) {
    return new \WP_REST_Response( [ 'ok' => false, 'error' => 'Pods framework not available.', 'trace_id' => $trace_id ], 500 );
  }

  $cache_key = 'scoop_audit_flav_' . scoop_cache_version();
  if ( ! $req->get_param( 'nocache' ) ) {
    $cached = get_transient( $cache_key );
    if ( is_array( $cached ) ) {
      $cached['trace_id'] = $trace_id;
      $cached['_cache']   = 'hit';
      return new \WP_REST_Response( $cached, 200 );
    }
  }

  try {
    // Flavor flags depend on whether the recipe's ingredients are themselves
    // flagged, so the ingredient audit is computed first and its flagged set
    // is reused here.
    $ingredients   = scoop_audit_build_ingredients();
    $flagged_ing   = [];
    foreach ( $ingredients['rows'] as $id => $row ) {
      if ( ( $row['flag'] ?? 'ok' ) !== 'ok' ) {
        $flagged_ing[ (int) $id ] = [ 'name' => $row['ingredient_name'], 'flag' => $row['flag'] ];
      }
    }

    $rows = scoop_audit_build_flavors( $flagged_ing );
  } catch ( \Throwable $e ) {
    error_log( sprintf( '[scoop_audit trace=%s flavors] %s: %s @ %s:%d', $trace_id, get_class( $e ), $e->getMessage(), $e->getFile(), $e->getLine() ) );
    return new \WP_REST_Response( [ 'ok' => false, 'error' => 'Failed to build flavor audit.', 'trace_id' => $trace_id ], 500 );
  }

  $response = [
    'ok'           => true,
    'generated_at' => gmdate( 'Y-m-d\TH:i:s' ),
    'count'        => count( $rows ),
    'bounds'       => [ 'too_low_below' => SCOOP_AUDIT_FLAVOR_COST_MIN, 'too_high_above' => SCOOP_AUDIT_FLAVOR_COST_MAX ],
    'flag_counts'  => scoop_audit_count_flags( $rows ),
    'link_sources' => scoop_audit_tally( $rows, 'recipe_link_source' ),
    'notes'        => [
      'cost_per_liter is normalized; recipes priced only by weight use an assumed density of 1 g/ml (see normalization_basis per row).',
      'recipe_has_flagged_ingredients means at least one linked ingredient was flagged by the ingredient audit — the cost is suspect regardless of its value.',
      'no_cost_data: the recipe has no usable cost+yield to compute a per-liter figure.',
      'Flavors flagged no_recipe / recipe_has_flagged_ingredients / no_cost_data should be treated as blocked until upstream data is fixed.',
    ],
    'columns'      => [ 'flavor_id', 'flavor_name', 'flavor_wp_admin_url', 'recipe_id', 'recipe_wp_admin_url', 'calculated_cost_per_liter', 'flag', 'likely_cause' ],
    'rows'         => $rows,
    'trace_id'     => $trace_id,
  ];

  set_transient( $cache_key, $response, SCOOP_CACHE_TTL );
  $response['_cache'] = 'miss';
  return new \WP_REST_Response( $response, 200 );
}


// ─────────────────────────────────────────────────────────────────────────────
// Builders
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Build the full ingredient audit.
 *
 * Returns [ 'rows' => [ id => row ], 'median' => float|null ] where each row is
 * the public CSV shape plus diagnostic fields (raw_* and normalization_basis).
 *
 * Two passes: gather raw values + compute per-liter for every ingredient, take
 * the median of all computable values, then assign a single flag per row.
 */
function scoop_audit_build_ingredients(): array {
  $pod = pods( 'ingredient' );
  if ( ! $pod ) return [ 'rows' => [], 'median' => null ];

  $pod->find( [
    'limit' => -1,
    'where' => "t.post_status NOT IN ('trash', 'auto-draft')",
  ] );

  $rows   = [];
  $ppls   = []; // computable per-liter values, for the median

  while ( $pod->fetch() ) {
    $id   = (int) $pod->id();
    $name = (string) ( $pod->row['post_title'] ?? '' );

    $price      = scoop_audit_num( $pod->field( 'price' ) );
    $price_unit = scoop_audit_num( $pod->field( 'price_unit' ) );
    $price_gram = scoop_audit_num( $pod->field( 'price_gram' ) );
    $grams      = scoop_audit_num( $pod->field( 'grams' ) );
    $liters     = scoop_audit_num( $pod->field( 'liters' ) );
    $unit_num   = scoop_audit_num( $pod->field( 'unit' ) );
    $yield_units = trim( (string) scoop_audit_scalar( $pod->field( 'yield_units' ) ) );

    // Best-available human unit label for the CSV's stored_unit column.
    $stored_unit = scoop_audit_resolve_unit_label( $yield_units, $unit_num );

    // Normalize to price-per-liter from whatever the system already computed.
    [ $ppl, $basis ] = scoop_audit_ingredient_ppl( $price, $liters, $grams, $price_gram );

    if ( $ppl !== null && $ppl > 0 ) $ppls[] = $ppl;

    $rows[ $id ] = [
      'ingredient_id'              => $id,
      'ingredient_name'            => $name,
      'wp_admin_url'               => scoop_audit_edit_url( $id ),
      'stored_unit'                => $stored_unit,
      'stored_price'               => $price,
      'calculated_price_per_liter' => ( $ppl !== null ) ? round( $ppl, 4 ) : null,
      'flag'                       => null, // assigned in pass 2
      'likely_issue'               => '',
      // diagnostics (kept in JSON, dropped from the CSV by the client)
      'normalization_basis'        => $basis,
      'raw_price_unit'             => $price_unit,
      'raw_price_gram'             => $price_gram,
      'raw_grams'                  => $grams,
      'raw_liters'                 => $liters,
      'raw_unit'                   => $unit_num,
      'raw_yield_units'            => $yield_units,
    ];
  }

  $median = scoop_audit_median( $ppls );

  // Pass 2 — assign one flag per row (priority order per PRICE-SOURCING.md §4).
  foreach ( $rows as $id => &$row ) {
    [ $flag, $issue ] = scoop_audit_ingredient_flag( $row, $median );
    $row['flag']         = $flag;
    $row['likely_issue'] = $issue;
  }
  unset( $row );

  return [ 'rows' => $rows, 'median' => ( $median !== null ) ? round( $median, 4 ) : null ];
}

/**
 * Decide the single flag + human note for one ingredient row.
 * Priority: bad_value → missing_unit → unknown_unit → order_of_magnitude →
 * out_of_sanity_bounds → ok.
 */
function scoop_audit_ingredient_flag( array $row, ?float $median ): array {
  $price  = (float) $row['stored_price'];
  $ppl    = $row['calculated_price_per_liter'];
  $basis  = $row['normalization_basis'];

  // 1. No usable purchase price.
  if ( $price <= 0 ) {
    return [ 'bad_value', 'Purchase price is zero or empty; nothing to evaluate.' ];
  }

  // 2/3. Could not normalize → is there any unit signal at all?
  if ( $ppl === null || $basis === 'none' ) {
    $has_unit_signal = ( (float) $row['raw_unit'] > 0 ) || ( trim( (string) $row['raw_yield_units'] ) !== '' );
    if ( ! $has_unit_signal ) {
      return [ 'missing_unit', 'No unit and no normalized grams/liters on file — price/liter cannot be computed.' ];
    }
    $u = trim( (string) $row['raw_yield_units'] );
    $u = $u !== '' ? "\"{$u}\"" : "code {$row['raw_unit']}";
    return [ 'unknown_unit', "Unit {$u} is on file but there is no normalized grams/liters to convert it — price/liter cannot be computed." ];
  }

  $ppl = (float) $ppl;
  $approx = ( $basis !== 'volume' ) ? ' (weight-derived, density≈1 g/ml assumed)' : '';

  // 4. Order of magnitude vs. the category median.
  if ( $median !== null && $median > 0 ) {
    $ratio = $ppl / $median;
    if ( $ratio >= SCOOP_AUDIT_OOM_FACTOR || $ratio <= ( 1.0 / SCOOP_AUDIT_OOM_FACTOR ) ) {
      $x = ( $ratio >= 1 ) ? round( $ratio, 1 ) . '×' : round( 1.0 / $ratio, 1 ) . '× below';
      return [ 'order_of_magnitude', sprintf(
        'Normalized price $%s/L is %s the median ($%s/L)%s — likely a unit mismatch (price entered per a different unit than stored).',
        round( $ppl, 2 ), $x, round( $median, 2 ), $approx
      ) ];
    }
  }

  // 5. Absurd on its face.
  if ( $ppl < SCOOP_AUDIT_INGREDIENT_PPL_MIN || $ppl > SCOOP_AUDIT_INGREDIENT_PPL_MAX ) {
    return [ 'out_of_sanity_bounds', sprintf(
      'Normalized price $%s/L is outside the plausible range $%s–$%s/L%s.',
      round( $ppl, 2 ), SCOOP_AUDIT_INGREDIENT_PPL_MIN, SCOOP_AUDIT_INGREDIENT_PPL_MAX, $approx
    ) ];
  }

  return [ 'ok', $approx !== '' ? 'Within range' . $approx . '.' : '' ];
}

/**
 * Compute price-per-liter for one ingredient and report which basis was used.
 * Returns [ float|null $ppl, string $basis ] where basis is one of
 * 'volume' | 'weight_density1' | 'none'.
 */
function scoop_audit_ingredient_ppl( float $price, float $liters, float $grams, float $price_gram ): array {
  if ( $price > 0 && $liters > 0 ) {
    return [ $price / $liters, 'volume' ];
  }
  if ( $price_gram > 0 ) {
    return [ $price_gram * 1000.0, 'weight_density1' ]; // $/g × 1000 g/L (density 1)
  }
  if ( $price > 0 && $grams > 0 ) {
    return [ $price / ( $grams / 1000.0 ), 'weight_density1' ];
  }
  return [ null, 'none' ];
}

/**
 * Build the flavor audit. $flagged_ing is [ ingredient_id => [name,flag] ] from
 * the ingredient audit. Returns a list of public CSV rows (+ diagnostics).
 */
function scoop_audit_build_flavors( array $flagged_ing ): array {
  $pod = pods( 'flavor' );
  if ( ! $pod ) return [];

  // The flavor↔recipe pick is sistered (flavor.recipe ⇄ recipe.flavor), but
  // depending on which side was edited the data may only be readable from one
  // direction. Build a reverse map (flavor_id → recipe_id) from the recipe side
  // so the link resolves regardless. This also surfaces §5 connection gaps.
  $by_recipe_side = scoop_audit_flavor_recipe_reverse_map();

  $pod->find( [
    'limit' => -1,
    'where' => "t.post_status NOT IN ('trash', 'auto-draft')",
  ] );

  $rows = [];

  while ( $pod->fetch() ) {
    $flavor_id   = (int) $pod->id();
    $flavor_name = (string) ( $pod->row['post_title'] ?? '' );

    // Prefer the flavor side; fall back to the recipe side.
    $recipe_id   = scoop_rel_id( $pod->field( 'recipe' ) );
    $link_source = $recipe_id > 0 ? 'flavor.recipe' : 'none';
    if ( $recipe_id <= 0 && ! empty( $by_recipe_side[ $flavor_id ] ) ) {
      $recipe_id   = (int) $by_recipe_side[ $flavor_id ];
      $link_source = 'recipe.flavor';
    }

    $row = [
      'flavor_id'                 => $flavor_id,
      'flavor_name'               => $flavor_name,
      'flavor_wp_admin_url'       => scoop_audit_edit_url( $flavor_id ),
      'recipe_id'                 => $recipe_id > 0 ? $recipe_id : null,
      'recipe_wp_admin_url'       => $recipe_id > 0 ? scoop_audit_edit_url( $recipe_id ) : '',
      'calculated_cost_per_liter' => null,
      'flag'                      => 'no_recipe',
      'likely_cause'              => 'Flavor has no linked recipe (checked both flavor.recipe and recipe.flavor).',
      'normalization_basis'       => 'none',
      'recipe_link_source'        => $link_source,
    ];

    if ( $recipe_id <= 0 ) {
      $rows[] = $row;
      continue;
    }

    $recipe = pods( 'recipe', $recipe_id );
    if ( ! $recipe || ! $recipe->exists() ) {
      $row['flag']         = 'no_recipe';
      $row['likely_cause'] = "Linked recipe #{$recipe_id} does not exist or is trashed.";
      $rows[] = $row;
      continue;
    }

    $cost       = scoop_audit_num( $recipe->field( 'cost' ) );
    $liters     = scoop_audit_num( $recipe->field( 'liters' ) );
    $grams      = scoop_audit_num( $recipe->field( 'grams' ) );
    $price_gram = scoop_audit_num( $recipe->field( 'price_gram' ) );

    [ $cpl, $basis ] = scoop_audit_ingredient_ppl( $cost, $liters, $grams, $price_gram );
    $row['normalization_basis']       = $basis;
    $row['calculated_cost_per_liter'] = ( $cpl !== null ) ? round( $cpl, 4 ) : null;

    // Which linked ingredients are flagged? Prefer the direct `ingredients`
    // relation (cheap, one field() call); fall back to resolving ingredient_maps
    // when it is empty. Depth: this recipe + one level of sub-recipes.
    $ing_ids   = scoop_audit_recipe_ingredient_ids( $recipe, $recipe_id, 1 );
    $hits      = [];
    foreach ( $ing_ids as $iid ) {
      if ( isset( $flagged_ing[ $iid ] ) ) $hits[ $iid ] = $flagged_ing[ $iid ];
    }
    $has_flagged = ! empty( $hits );

    $approx = ( $basis === 'weight_density1' ) ? ' (weight-derived, density≈1 g/ml assumed)' : '';

    // Flag priority: too_low / too_high (strong, observable error) → then
    // recipe_has_flagged_ingredients → no_cost_data → ok. likely_cause always
    // names flagged ingredients when present, so the root cause is visible
    // even when the headline flag is too_low/too_high.
    if ( $cpl !== null && $cpl < SCOOP_AUDIT_FLAVOR_COST_MIN ) {
      $row['flag']         = 'too_low';
      $row['likely_cause'] = sprintf( 'Cost $%s/L is below $%s/L%s.', round( $cpl, 2 ), SCOOP_AUDIT_FLAVOR_COST_MIN, $approx )
        . scoop_audit_flagged_suffix( $hits );
    } elseif ( $cpl !== null && $cpl > SCOOP_AUDIT_FLAVOR_COST_MAX ) {
      $row['flag']         = 'too_high';
      $row['likely_cause'] = sprintf( 'Cost $%s/L is above $%s/L%s.', round( $cpl, 2 ), SCOOP_AUDIT_FLAVOR_COST_MAX, $approx )
        . scoop_audit_flagged_suffix( $hits );
    } elseif ( $has_flagged ) {
      $row['flag']         = 'recipe_has_flagged_ingredients';
      $row['likely_cause'] = 'Cost is in range but built on suspect ingredient data.' . scoop_audit_flagged_suffix( $hits );
    } elseif ( $cpl === null ) {
      $row['flag']         = 'no_cost_data';
      $row['likely_cause'] = 'Recipe has no usable cost + yield (cost/liters/grams/price_gram all empty) — cost/liter cannot be computed.';
    } else {
      $row['flag']         = 'ok';
      $row['likely_cause'] = $approx !== '' ? 'Within range' . $approx . '.' : '';
    }

    $rows[] = $row;
  }

  return $rows;
}

/**
 * Build a reverse map flavor_id → recipe_id from the recipe side of the
 * sistered flavor↔recipe relationship. First recipe wins per flavor; this is a
 * best-effort fallback for when the flavor side reads empty.
 */
function scoop_audit_flavor_recipe_reverse_map(): array {
  $pod = pods( 'recipe' );
  if ( ! $pod ) return [];

  $pod->find( [
    'limit' => -1,
    'where' => "t.post_status NOT IN ('trash', 'auto-draft')",
  ] );

  $map = [];
  while ( $pod->fetch() ) {
    $recipe_id = (int) $pod->id();
    $flavor_id = scoop_rel_id( $pod->field( 'flavor' ) );
    if ( $flavor_id > 0 && ! isset( $map[ $flavor_id ] ) ) {
      $map[ $flavor_id ] = $recipe_id;
    }
  }
  return $map;
}

/**
 * Collect ingredient IDs linked to a recipe, descending up to $depth levels of
 * sub-recipes. Prefers the direct `ingredients` relation; falls back to
 * resolving `ingredient_maps` rows when the direct relation is empty.
 */
function scoop_audit_recipe_ingredient_ids( $recipe, int $recipe_id, int $depth, array &$seen = [] ): array {
  if ( isset( $seen[ $recipe_id ] ) ) return [];
  $seen[ $recipe_id ] = true;

  $ids = scoop_audit_rel_ids( $recipe->field( 'ingredients' ) );

  if ( empty( $ids ) ) {
    // Fall back to the join rows RCC actually populates.
    foreach ( scoop_audit_rel_ids( $recipe->field( 'ingredient_maps' ) ) as $map_id ) {
      $map = pods( 'recipe-ingredient-ma', $map_id );
      if ( $map && $map->exists() ) {
        $iid = scoop_rel_id( $map->field( 'ingredient' ) );
        if ( $iid > 0 ) $ids[] = $iid;
      }
    }
  }

  if ( $depth > 0 ) {
    foreach ( scoop_audit_rel_ids( $recipe->field( 'sub-recipes' ) ) as $sub_id ) {
      $sub = pods( 'recipe', $sub_id );
      if ( $sub && $sub->exists() ) {
        $ids = array_merge( $ids, scoop_audit_recipe_ingredient_ids( $sub, $sub_id, $depth - 1, $seen ) );
      }
    }
  }

  return array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
}


// ─────────────────────────────────────────────────────────────────────────────
// Small helpers
// ─────────────────────────────────────────────────────────────────────────────

function scoop_audit_trace_id(): string {
  return substr( bin2hex( random_bytes( 6 ) ), 0, 12 );
}

/** Admin edit URL for a post — built directly so it works under Basic Auth too. */
function scoop_audit_edit_url( int $id ): string {
  return admin_url( 'post.php?post=' . $id . '&action=edit' );
}

/** Coerce a Pods field value (currency/number, possibly array or "$1,234.56") to float. */
function scoop_audit_num( $v ): float {
  if ( is_array( $v ) ) $v = reset( $v );
  if ( is_object( $v ) ) return 0.0;
  if ( is_numeric( $v ) ) return (float) $v;
  $s = preg_replace( '/[^0-9.\-]/', '', (string) $v );
  return ( $s === '' || $s === '-' || $s === '.' ) ? 0.0 : (float) $s;
}

/** Coerce a Pods field value to a plain scalar (first element if array). */
function scoop_audit_scalar( $v ) {
  if ( is_array( $v ) ) $v = reset( $v );
  if ( is_object( $v ) || is_array( $v ) ) return '';
  return $v;
}

/** Normalize a Pods relationship value to a flat list of int IDs. */
function scoop_audit_rel_ids( $v ): array {
  if ( empty( $v ) ) return [];
  $out = [];
  foreach ( ( is_array( $v ) ? $v : [ $v ] ) as $item ) {
    $id = scoop_rel_id( $item );
    if ( $id > 0 ) $out[] = $id;
  }
  return array_values( array_unique( $out ) );
}

/**
 * Best human-readable unit label for an ingredient. Prefers the text
 * yield_units; if absent, tries to resolve the legacy numeric `unit` code
 * against the `unit` pod's abbreviation. Returns '' when nothing is on file.
 */
function scoop_audit_resolve_unit_label( string $yield_units, float $unit_num ): string {
  $yield_units = trim( $yield_units );
  if ( $yield_units !== '' ) return $yield_units;

  $code = (int) $unit_num;
  if ( $code <= 0 ) return '';

  // The `unit` pod (conversion table) may key the legacy code by post ID.
  $u = pods( 'unit', $code );
  if ( $u && $u->exists() ) {
    $abbr = trim( (string) scoop_audit_scalar( $u->field( 'abbreviation' ) ) );
    if ( $abbr !== '' ) return $abbr;
    $title = (string) ( $u->row['post_title'] ?? '' );
    if ( $title !== '' ) return $title;
  }

  return 'code:' . $code;
}

/** Median of a numeric list, or null if empty. */
function scoop_audit_median( array $vals ): ?float {
  $vals = array_values( array_filter( $vals, function ( $v ) { return is_numeric( $v ) && $v > 0; } ) );
  $n = count( $vals );
  if ( $n === 0 ) return null;
  sort( $vals );
  $mid = intdiv( $n, 2 );
  return ( $n % 2 ) ? (float) $vals[ $mid ] : ( (float) $vals[ $mid - 1 ] + (float) $vals[ $mid ] ) / 2.0;
}

/** Tally the values of $key across a set of rows → [ value => count ]. */
function scoop_audit_tally( array $rows, string $key ): array {
  $counts = [];
  foreach ( $rows as $r ) {
    $v = $r[ $key ] ?? 'unknown';
    $counts[ $v ] = ( $counts[ $v ] ?? 0 ) + 1;
  }
  return $counts;
}

/** Tally flag → count for a set of rows. */
function scoop_audit_count_flags( array $rows ): array {
  return scoop_audit_tally( $rows, 'flag' );
}

/** Human suffix listing flagged ingredient names + their flags (capped). */
function scoop_audit_flagged_suffix( array $hits ): string {
  if ( empty( $hits ) ) return '';
  $parts = [];
  $i = 0;
  foreach ( $hits as $h ) {
    if ( $i++ >= 5 ) { $parts[] = '…'; break; }
    $parts[] = "{$h['name']} ({$h['flag']})";
  }
  return ' Flagged ingredient(s): ' . implode( '; ', $parts ) . '.';
}
