<?php
/**
 * includes/analytics.php
 *
 * REST endpoint: GET /wp-json/scoop/v1/analytics
 *
 * Computes per-flavor sales velocity from closeout and tub data.
 * Read-only, any authenticated user can access.
 *
 * Query params:
 *   ?days=30      Analysis period in days (default 30)
 *   ?location=935 Filter by location ID (optional)
 *
 * Uses the Pods API for queries rather than raw SQL against internal
 * Pods tables, since relationship storage varies by Pods version and
 * configuration (meta vs. table storage).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Register the analytics route.
 *
 * Integration: add this to your rest_api_init action in _routes.php,
 * or call scoop_register_analytics_route() from there.
 */
function scoop_register_analytics_route(): void {
  register_rest_route( 'scoop/v1', '/analytics', [
    'methods'             => 'GET',
    'callback'            => 'scoop_analytics_handler',
    'permission_callback' => 'scoop_require_authenticated_user_read_only',
  ] );
}

add_action( 'rest_api_init', 'scoop_register_analytics_route' );


// ─────────────────────────────────────────────────────────────────────────────
// Main handler
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Handle GET /wp-json/scoop/v1/analytics
 *
 * @param WP_REST_Request $req
 * @return WP_REST_Response
 */
function scoop_analytics_handler( \WP_REST_Request $req ): \WP_REST_Response {

  // Correlation ID — appears in both error_log output and the JSON error
  // response. Gus (or whoever hits the 500) can copy it from the browser
  // devtools and we can grep the PHP error log for the full stack. This is
  // the single thing that makes a "DatabaseError surfaces in the browser"
  // bug traceable without live debugger access.
  $trace_id = substr( bin2hex( random_bytes( 6 ) ), 0, 12 );

  if ( ! function_exists( 'pods' ) ) {
    return new \WP_REST_Response( [
      'ok'       => false,
      'error'    => 'Pods framework not available.',
      'trace_id' => $trace_id,
    ], 500 );
  }

  $period_days = max( 1, (int) ( $req->get_param( 'days' ) ?? 30 ) );
  $location_id = $req->get_param( 'location' );
  $location_id = ( $location_id !== null && $location_id !== '' )
    ? (int) $location_id
    : 0;

  // Only compute the stages this grid actually consumes. Unknown or missing
  // grid_type → all stages (backwards-compatible). See
  // scoop_analytics_stages_for_grid_type() for the per-grid stage map.
  $grid_type     = (string) ( $req->get_param( 'grid_type' ) ?? '' );
  $active_stages = scoop_analytics_stages_for_grid_type( $grid_type );

  // Cache read — keyed by version + days + location + grid_type. The version
  // is bumped on any relevant save_post (see _cache.php), so writes invalidate
  // every cached analytics response at once. The TTL is just a safety net.
  $cache_key = scoop_analytics_cache_key( $req );
  $cached    = get_transient( $cache_key );
  if ( $cached !== false && is_array( $cached ) ) {
    // Re-stamp trace_id so each cache-hit response still has a unique ID for
    // log correlation. _cache=hit is what distinguishes it from a fresh compute.
    $cached['trace_id'] = $trace_id;
    $cached['_cache']   = 'hit';
    return new \WP_REST_Response( $cached, 200 );
  }

  $now         = current_time( 'mysql' );
  $period_start = date( 'Y-m-d H:i:s', strtotime( "-{$period_days} days", strtotime( $now ) ) );

  // Half-period boundary for trend calculation (split the period into two halves)
  $half_days    = max( 1, intdiv( $period_days, 2 ) );
  $half_start   = date( 'Y-m-d H:i:s', strtotime( "-{$half_days} days", strtotime( $now ) ) );

  // Each data-gathering step is independently wrapped. A fault in one
  // section (e.g. a Pods schema quirk hitting closeouts) no longer blanks
  // the entire grid — the rest of the columns still render, and the
  // failing section returns [] with the real cause captured in error_log.
  //
  // We keep a list of degraded sections so the client can surface which
  // pieces of the response are stale/empty rather than silently missing.
  $degraded = [];

  $run = function ( string $stage, callable $fn ) use ( $trace_id, &$degraded ) {
    try {
      return $fn();
    } catch ( \Throwable $e ) {
      error_log( sprintf(
        '[scoop_analytics trace=%s stage=%s] %s: %s @ %s:%d',
        $trace_id,
        $stage,
        get_class( $e ),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
      ) );
      $degraded[] = $stage;
      return null;
    }
  };

  // ── Step 1: Fetch all flavors ──────────────────────────────────────────────

  $flavors_map = $run( 'fetch_flavors', 'scoop_analytics_fetch_flavors' );
  if ( ! is_array( $flavors_map ) ) {
    // Flavors are load-bearing — without them there is literally nothing to
    // aggregate against. Surface the failure rather than returning an empty
    // success that would look like "no flavors exist" (misleading for ops).
    return new \WP_REST_Response( [
      'ok'         => false,
      'error'      => 'Failed to enumerate flavors.',
      'trace_id'   => $trace_id,
      'degraded'   => $degraded,
    ], 500 );
  }
  if ( empty( $flavors_map ) ) {
    $body = [
      'ok'           => true,
      'period_days'  => $period_days,
      'generated_at' => gmdate( 'Y-m-d\TH:i:s' ),
      'flavors'      => [],
      'trace_id'     => $trace_id,
    ];
    set_transient( $cache_key, $body, SCOOP_CACHE_TTL );
    $body['_cache'] = 'miss';
    return new \WP_REST_Response( $body, 200 );
  }

  // ── Step 2: Aggregate tub sales (authoritative) ────────────────────────────
  //
  // Gus's spec: "Total Sold" must reflect tubs with status Emptied. The
  // closeout record is a human-entered summary of a shift-end action and may
  // or may not map cleanly to the Pods row column being read here; the tub
  // table is the authoritative system of record because every scoop lifecycle
  // ends in a tub being flipped to Emptied (enforced by
  // includes/hooks/tub-state.php and includes/hooks/closeout.php).

  $sales = in_array( 'aggregate_tubs', $active_stages, true )
    ? ( $run( 'aggregate_tubs', function () use ( $period_start, $now, $half_start, $location_id ) {
        return scoop_analytics_aggregate_tubs( $period_start, $now, $half_start, $location_id );
      } ) ?? [] )
    : [];

  // ── Step 3: Compute sellthrough times from tub lifecycle ───────────────────

  $sellthrough = in_array( 'sellthrough', $active_stages, true )
    ? ( $run( 'sellthrough', function () use ( $period_start, $now, $location_id ) {
        return scoop_analytics_sellthrough( $period_start, $now, $location_id );
      } ) ?? [] )
    : [];

  // ── Step 4: Current stock per flavor ───────────────────────────────────────

  $stock = in_array( 'current_stock', $active_stages, true )
    ? ( $run( 'current_stock', function () use ( $location_id ) {
        return scoop_analytics_current_stock( $location_id );
      } ) ?? [] )
    : [];

  // ── Step 5: Last batch date per flavor ─────────────────────────────────────

  $last_batch = in_array( 'last_batch', $active_stages, true )
    ? ( $run( 'last_batch', 'scoop_analytics_last_batch' ) ?? [] )
    : [];

  // ── Step 6: Assemble per-flavor results ────────────────────────────────────

  $flavors_out = [];

  foreach ( $flavors_map as $flavor_id => $flavor_info ) {
    $flavor_name = $flavor_info['name'] ?? '';
    $allergens   = $flavor_info['allergens'] ?? [];

    $total_sold   = $sales[ $flavor_id ]['total']      ?? 0.0;
    $recent_sold  = $sales[ $flavor_id ]['recent']     ?? 0.0;
    $prior_sold   = $sales[ $flavor_id ]['prior']      ?? 0.0;
    $last_sale    = $sales[ $flavor_id ]['last_sale']   ?? null;

    $sell_rate    = ( $period_days > 0 ) ? $total_sold / $period_days : 0.0;
    $recent_rate  = ( $half_days > 0 )   ? $recent_sold / $half_days : 0.0;
    $prior_rate   = ( $half_days > 0 )   ? $prior_sold / $half_days  : 0.0;

    // Trend: compare recent half vs prior half, +-10% threshold for "steady"
    $trend     = 'steady';
    $trend_pct = 0.0;
    if ( $prior_rate > 0 ) {
      $trend_pct = ( ( $recent_rate - $prior_rate ) / $prior_rate ) * 100.0;
      if ( $trend_pct > 10.0 )       $trend = 'rising';
      elseif ( $trend_pct < -10.0 )  $trend = 'falling';
    } elseif ( $recent_rate > 0 ) {
      // Had no prior sales but has recent sales — clearly rising
      $trend     = 'rising';
      $trend_pct = 100.0;
    }

    $current_stock = $stock[ $flavor_id ] ?? 0;
    $days_supply   = ( $sell_rate > 0 ) ? $current_stock / $sell_rate : null;

    $avg_sellthrough = $sellthrough[ $flavor_id ] ?? null;

    $batch_date = $last_batch[ $flavor_id ] ?? null;

    $flavors_out[] = [
      'flavor_id'           => $flavor_id,
      'flavor_name'         => $flavor_name,
      'allergens'           => $allergens,
      'total_sold'          => round( $total_sold, 2 ),
      'sell_rate_per_day'   => round( $sell_rate, 4 ),
      'avg_sellthrough_days' => ( $avg_sellthrough !== null ) ? round( $avg_sellthrough, 1 ) : null,
      'current_stock'       => $current_stock,
      'days_of_supply'      => ( $days_supply !== null ) ? round( $days_supply, 1 ) : null,
      'trend'               => $trend,
      'trend_pct'           => round( $trend_pct, 1 ),
      'recent_rate'         => round( $recent_rate, 4 ),
      'prior_rate'          => round( $prior_rate, 4 ),
      'last_batch_date'     => $batch_date,
      'last_sale_date'      => $last_sale,
    ];
  }

  // Sort by sell rate descending (fastest sellers first)
  usort( $flavors_out, function ( $a, $b ) {
    return $b['sell_rate_per_day'] <=> $a['sell_rate_per_day'];
  } );

  // A successful but partially-degraded response still returns 200 with
  // ok=true — the grid is renderable — but lists the failed stages so
  // the client can flag stale/empty columns and ops can correlate with
  // the error log via trace_id.
  $response = [
    'ok'           => true,
    'period_days'  => $period_days,
    'generated_at' => gmdate( 'Y-m-d\TH:i:s' ),
    'flavors'      => $flavors_out,
    'trace_id'     => $trace_id,
  ];
  if ( ! empty( $degraded ) ) {
    $response['degraded'] = $degraded;
    error_log( sprintf(
      '[scoop_analytics trace=%s] partial response, degraded stages: %s',
      $trace_id,
      implode( ',', $degraded )
    ) );
  }

  // Cache write — degraded responses are cached too, since the next save_post
  // bumps the version and retries the failed stage. The TTL is a safety net
  // in case version-bump invalidation is missed.
  set_transient( $cache_key, $response, SCOOP_CACHE_TTL );
  $response['_cache'] = 'miss';
  return new \WP_REST_Response( $response, 200 );
}


// ─────────────────────────────────────────────────────────────────────────────
// Stage map — which aggregates each grid type actually consumes.
// Skipping unused stages is the main perf win behind this endpoint, since each
// stage that touches the `tub` table is a full scan.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Returns the list of analytics stages a given grid type needs.
 * Unknown or empty type → every stage (backwards-compatible).
 *
 * @param string $grid_type  e.g. "Analytics", "Popular", "Flavors"
 * @return array<int,string> stage names: aggregate_tubs, sellthrough, current_stock, last_batch
 */
function scoop_analytics_stages_for_grid_type( string $grid_type ): array {
  $all = [ 'aggregate_tubs', 'sellthrough', 'current_stock', 'last_batch' ];

  $map = [
    // Full dashboard — needs everything.
    'Analytics' => $all,
    // Popular scatter plot — needs tub aggregates + sellthrough + current stock
    // (skips last_batch entirely).
    'Popular'   => [ 'aggregate_tubs', 'sellthrough', 'current_stock' ],
    // Flavors list — only needs current stock + days-since-last-batch
    // (skips both tub-table scans for sales/sellthrough).
    'Flavors'   => [ 'current_stock', 'last_batch' ],
  ];

  return $map[ $grid_type ] ?? $all;
}


// ─────────────────────────────────────────────────────────────────────────────
// Data-gathering helpers — each uses Pods find() for portability
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Fetch all flavors as id => [ name, allergens[] ] map.
 *
 * Allergens are returned as a list of post_name slugs (e.g. ["dairy","nut"]),
 * matching the wire format used elsewhere in the bundle (flavor.allergens is
 * specced as data_type='post_names' in includes/_specs.php).
 *
 * @return array<int, array{name: string, allergens: array<int, string>}>
 */
function scoop_analytics_fetch_flavors(): array {
  $pod = pods( 'flavor' );
  if ( ! $pod ) return [];

  $pod->find( [
    'limit' => -1,
    'where' => "t.post_status NOT IN ('trash', 'auto-draft')",
  ] );

  $map = [];
  while ( $pod->fetch() ) {
    // Pods returns the related allergen posts as an array of arrays;
    // scoop_post_names_out() (defined in bundle-fetch.php) extracts the
    // post_name slug from each. We resolve via $pod->field() because
    // relationship data isn't on $pod->row.
    $allergens = scoop_post_names_out( $pod->field( 'allergens' ) );

    $map[ (int) $pod->id() ] = [
      'name'      => (string) ( $pod->row['post_title'] ?? '' ),
      'allergens' => array_values( array_unique( $allergens ) ),
    ];
  }

  return $map;
}

/**
 * Aggregate tub sales per flavor within the analysis period.
 *
 * "Sold" is defined as: a tub flipped to state='Emptied' with a non-null
 * emptied_at inside the window. The sum is tub.amount (fractional 0..1 for
 * partial tubs, ≈1 for full tubs), which preserves the true quantity sold
 * even when a closeout consumed a half-tub alongside whole tubs.
 *
 * Why tubs, not closeouts: the closeout record is a manually entered shift
 * summary and can be absent, duplicated, or decoupled from reality. Every
 * scoop-sold lifecycle terminates in a tub being marked Emptied (enforced by
 * includes/hooks/tub-state.php:199 and includes/hooks/closeout.php:121), so
 * the tub table is the authoritative record of what actually left inventory.
 *
 * Returns: [ flavor_id => [ 'total' => float, 'recent' => float, 'prior' => float, 'last_sale' => string|null ] ]
 *
 * @param string $period_start  Start of the full analysis window
 * @param string $now           Current timestamp (unused directly; emptied_at
 *                              already bounded by the period window)
 * @param string $half_start    Start of the "recent" half-period
 * @param int    $location_id   Location filter (0 = all locations)
 * @return array
 */
function scoop_analytics_aggregate_tubs(
  string $period_start,
  string $now,
  string $half_start,
  int $location_id
): array {

  $pod = pods( 'tub' );
  if ( ! $pod ) return [];

  // state and emptied_at are direct columns on the tub pod table, so they
  // are safe in SQL WHERE (same pattern as scoop_analytics_sellthrough).
  // location is a Pods relationship — never pushed into SQL here — resolved
  // in PHP below.
  //
  // NB: emptied tubs get post_status='draft' via hooks/tub-state.php:202 so
  // the only statuses we need to exclude are 'trash' and 'auto-draft'.
  $where = [
    "t.post_status NOT IN ('trash', 'auto-draft')",
    "state = 'Emptied'",
    "emptied_at IS NOT NULL",
    "emptied_at >= '{$period_start}'",
  ];

  $pod->find( [
    'limit'   => -1,
    'orderby' => 'emptied_at',
    'order'   => 'ASC',
    'where'   => implode( ' AND ', $where ),
  ] );

  $result = [];

  while ( $pod->fetch() ) {
    $flavor_id = scoop_rel_id( $pod->field( 'flavor' ) );
    if ( $flavor_id <= 0 ) continue;

    // Location filter — resolved via Pods relationship (not raw SQL column)
    if ( $location_id > 0 ) {
      $tub_loc = scoop_rel_id( $pod->field( 'location' ) );
      if ( $tub_loc !== $location_id ) continue;
    }

    // amount is a float column on the tub pod table — read from $pod->row.
    // Default to 1.0 (a whole tub) if the field is null or absent, which
    // matches the closeout matcher's assumption for whole-tub matches.
    $raw_amount = $pod->row['amount'] ?? null;
    $amount     = ( $raw_amount === null || $raw_amount === '' )
      ? 1.0
      : (float) $raw_amount;

    $emptied_at = (string) ( $pod->row['emptied_at'] ?? '' );

    if ( ! isset( $result[ $flavor_id ] ) ) {
      $result[ $flavor_id ] = [
        'total'     => 0.0,
        'recent'    => 0.0,
        'prior'     => 0.0,
        'last_sale' => null,
      ];
    }

    $result[ $flavor_id ]['total'] += $amount;

    // Bucket into recent vs. prior half-period by when the tub was emptied
    if ( $emptied_at >= $half_start ) {
      $result[ $flavor_id ]['recent'] += $amount;
    } else {
      $result[ $flavor_id ]['prior'] += $amount;
    }

    // Track the most recent sale (tubs are ordered ASC by emptied_at, last wins)
    if ( ! scoop_nodate( $emptied_at ) ) {
      $result[ $flavor_id ]['last_sale'] = date( 'Y-m-d', strtotime( $emptied_at ) );
    }
  }

  return $result;
}

/**
 * Normalize a "use" CPT title for case/space/dash-insensitive comparison.
 * Mirrors FlavorTubGridModel._normalizeUseLabel() in the JS layer.
 */
function scoop_analytics_normalize_use_label( string $label ): string {
  $s = html_entity_decode( $label, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
  $s = str_replace( '&amp;', '&', strtolower( $s ) );
  $s = preg_replace( '/[-_]+/', ' ', $s );
  $s = preg_replace( '/\s+/',  ' ', $s );
  return trim( (string) $s );
}

/**
 * IDs for "use" CPT entries that count as front-of-house.
 *
 * Resolution: matches any use whose normalized title is "front of house".
 * Always includes FRONT_OF_HOUSE_USE_ID (1863) as a fallback so the
 * computation keeps working even if the canonical use post is renamed
 * or absent. Mirrors FlavorTubGridModel.FRONT_OF_HOUSE_USE_ID in the JS.
 *
 * @return array<int, bool>  set keyed by use_id
 */
function scoop_analytics_foh_use_ids(): array {
  static $cache = null;
  if ( $cache !== null ) return $cache;

  $cache = [ 1863 => true ];

  if ( ! function_exists( 'pods' ) ) return $cache;
  $pod = pods( 'use' );
  if ( ! $pod ) return $cache;

  $pod->find( [
    'limit' => -1,
    'where' => "t.post_status NOT IN ('trash', 'auto-draft')",
  ] );

  while ( $pod->fetch() ) {
    $title = scoop_analytics_normalize_use_label( (string) ( $pod->row['post_title'] ?? '' ) );
    if ( $title === 'front of house' ) {
      $cache[ (int) $pod->id() ] = true;
    }
  }

  return $cache;
}

/**
 * Compute per-flavor average days-to-sell, grouped by batch.
 *
 * Rationale: per-tub (emptied_at - opened_on) gave bad results because
 * many tubs are flipped to Emptied without ever being marked Opened, and
 * because a single tub's "time to sell" doesn't reflect demand — what
 * matters is how long a *batch's worth* of tubs lasted on the floor.
 *
 * Per-batch:
 *   - Consider only FoH tubs in the batch that have been emptied. Pints
 *     and other non-FoH uses are excluded, both from the time span and
 *     the denominator (the spec: a batch of 4 where 1 is pints should
 *     report 4 days / 3 = 1.33 days per FoH tub).
 *   - start = min( opened_on across those tubs ). For any tub flagged
 *     Emptied with no opened_on, infer opened_on = emptied_at - 1 day
 *     (spec: such tubs are assumed to have sold in under a day).
 *   - end   = max( emptied_at across those tubs )
 *   - elapsed_days = end - start (clamped to >= 0)
 *
 * Per-flavor: tub-count-weighted average across batches. That is,
 *   avg = sum( batch_elapsed_days ) / sum( batch_FoH_tub_count )
 * which gives "average days to sell one tub" without small batches
 * skewing the result.
 *
 * Tubs without a batch relation are bucketed under batch_id=0 per flavor
 * so they still contribute, just not grouped with sibling tubs.
 *
 * The period filter (emptied_at >= $period_start) still applies, so a
 * batch whose tubs all sold before the analysis window doesn't appear.
 *
 * @param string $period_start
 * @param string $now           Unused; kept for signature parity with
 *                              other helpers in this file.
 * @param int    $location_id
 * @return array<int, float|null>  flavor_id => average days, or null if no data
 */
function scoop_analytics_sellthrough(
  string $period_start,
  string $now,
  int $location_id
): array {

  $pod = pods( 'tub' );
  if ( ! $pod ) return [];

  $where = [
    "t.post_status NOT IN ('trash', 'auto-draft')",
    "state = 'Emptied'",
    "emptied_at IS NOT NULL",
    "emptied_at >= '{$period_start}'",
  ];

  // NOTE: location/use/batch/flavor are Pods relationship fields — not direct
  // SQL columns. Pushing "location = N" into WHERE causes "Unknown column"
  // errors. Filter in PHP via $pod->field() like the other helpers.

  $pod->find( [
    'limit' => -1,
    'where' => implode( ' AND ', $where ),
  ] );

  $foh_ids = scoop_analytics_foh_use_ids();
  $one_day = 86400;

  // Group by (flavor_id, batch_id) so each batch contributes one
  // (elapsed_days, tub_count) pair, averaged at the flavor level.
  $batches = []; // "{flavor}:{batch}" => [ flavor_id, min_open, max_close, count ]

  while ( $pod->fetch() ) {
    $flavor_id = scoop_rel_id( $pod->field( 'flavor' ) );
    if ( $flavor_id <= 0 ) continue;

    if ( $location_id > 0 ) {
      $tub_loc = scoop_rel_id( $pod->field( 'location' ) );
      if ( $tub_loc !== $location_id ) continue;
    }

    // Front-of-house only — pints and other uses are excluded from both
    // the time span and the denominator.
    $use_id = scoop_rel_id( $pod->field( 'use' ) );
    if ( $use_id <= 0 || empty( $foh_ids[ $use_id ] ) ) continue;

    $emptied_at = (string) ( $pod->row['emptied_at'] ?? '' );
    $opened_on  = (string) ( $pod->row['opened_on']  ?? '' );

    if ( scoop_nodate( $emptied_at ) ) continue;
    $emptied_ts = strtotime( $emptied_at );
    if ( $emptied_ts <= 0 ) continue;

    // Tub emptied without opened_on → assume it sold in under a day.
    $opened_ts = scoop_nodate( $opened_on )
      ? ( $emptied_ts - $one_day )
      : strtotime( $opened_on );
    if ( $opened_ts <= 0 ) $opened_ts = $emptied_ts - $one_day;

    $batch_id  = scoop_rel_id( $pod->field( 'batch' ) );
    $batch_key = $flavor_id . ':' . $batch_id;

    if ( ! isset( $batches[ $batch_key ] ) ) {
      $batches[ $batch_key ] = [
        'flavor_id' => $flavor_id,
        'min_open'  => $opened_ts,
        'max_close' => $emptied_ts,
        'count'     => 0,
      ];
    } else {
      if ( $opened_ts  < $batches[ $batch_key ]['min_open']  ) $batches[ $batch_key ]['min_open']  = $opened_ts;
      if ( $emptied_ts > $batches[ $batch_key ]['max_close'] ) $batches[ $batch_key ]['max_close'] = $emptied_ts;
    }
    $batches[ $batch_key ]['count']++;
  }

  // Aggregate per-flavor with tub-count weighting.
  $by_flavor = []; // flavor_id => [ days => float, tubs => int ]

  foreach ( $batches as $b ) {
    $fid          = $b['flavor_id'];
    $elapsed_days = max( 0.0, ( $b['max_close'] - $b['min_open'] ) / (float) $one_day );

    if ( ! isset( $by_flavor[ $fid ] ) ) {
      $by_flavor[ $fid ] = [ 'days' => 0.0, 'tubs' => 0 ];
    }
    $by_flavor[ $fid ]['days'] += $elapsed_days;
    $by_flavor[ $fid ]['tubs'] += $b['count'];
  }

  $result = [];
  foreach ( $by_flavor as $fid => $agg ) {
    if ( $agg['tubs'] <= 0 ) continue;
    $result[ $fid ] = $agg['days'] / $agg['tubs'];
  }

  return $result;
}

/**
 * Count current stock per flavor (tubs not in 'Emptied' state).
 *
 * @param int $location_id  0 for all locations
 * @return array<int, int>  flavor_id => count
 */
function scoop_analytics_current_stock( int $location_id ): array {

  $pod = pods( 'tub' );
  if ( ! $pod ) return [];

  $where = [
    "t.post_status NOT IN ('trash', 'auto-draft')",
    "state != 'Emptied'",
  ];

  // NOTE: location is a Pods relationship field — not a direct column on
  // track_pods_tub.  Filter in PHP after fetching, same as the closeout
  // and sellthrough helpers above.

  $pod->find( [
    'limit' => -1,
    'where' => implode( ' AND ', $where ),
  ] );

  $counts = [];

  while ( $pod->fetch() ) {
    $flavor_id = scoop_rel_id( $pod->field( 'flavor' ) );
    if ( $flavor_id <= 0 ) continue;

    // Location filter — resolve via Pods relationship (not raw SQL column)
    if ( $location_id > 0 ) {
      $tub_loc = scoop_rel_id( $pod->field( 'location' ) );
      if ( $tub_loc !== $location_id ) continue;
    }

    if ( ! isset( $counts[ $flavor_id ] ) ) {
      $counts[ $flavor_id ] = 0;
    }
    $counts[ $flavor_id ]++;
  }

  return $counts;
}

/**
 * Find the most recent batch post_date per flavor.
 *
 * @return array<int, string|null>  flavor_id => 'Y-m-d' date string
 */
function scoop_analytics_last_batch(): array {

  $pod = pods( 'batch' );
  if ( ! $pod ) return [];

  $pod->find( [
    'limit'   => -1,
    'orderby' => 'post_date',
    'order'   => 'DESC',
    'where'   => "t.post_status NOT IN ('trash', 'auto-draft')",
  ] );

  $result = [];

  while ( $pod->fetch() ) {
    $flavor_id = scoop_rel_id( $pod->field( 'flavor' ) );
    if ( $flavor_id <= 0 ) continue;

    // Ordered DESC, so first occurrence per flavor is the most recent
    if ( isset( $result[ $flavor_id ] ) ) continue;

    $post_date = $pod->row['post_date'] ?? '';
    if ( ! scoop_nodate( $post_date ) ) {
      $result[ $flavor_id ] = date( 'Y-m-d', strtotime( $post_date ) );
    }
  }

  return $result;
}
