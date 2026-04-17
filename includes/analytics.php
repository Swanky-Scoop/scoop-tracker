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

  if ( ! function_exists( 'pods' ) ) {
    return new \WP_REST_Response( [
      'ok'    => false,
      'error' => 'Pods framework not available.',
    ], 500 );
  }

  $period_days = max( 1, (int) ( $req->get_param( 'days' ) ?? 30 ) );
  $location_id = $req->get_param( 'location' );
  $location_id = ( $location_id !== null && $location_id !== '' )
    ? (int) $location_id
    : 0;

  $now         = current_time( 'mysql' );
  $period_start = date( 'Y-m-d H:i:s', strtotime( "-{$period_days} days", strtotime( $now ) ) );

  // Half-period boundary for trend calculation (split the period into two halves)
  $half_days    = max( 1, intdiv( $period_days, 2 ) );
  $half_start   = date( 'Y-m-d H:i:s', strtotime( "-{$half_days} days", strtotime( $now ) ) );

  // ── Step 1: Fetch all flavors ──────────────────────────────────────────────

  $flavors_map = scoop_analytics_fetch_flavors();
  if ( empty( $flavors_map ) ) {
    return new \WP_REST_Response( [
      'ok'          => true,
      'period_days' => $period_days,
      'generated_at' => gmdate( 'Y-m-d\TH:i:s' ),
      'flavors'     => [],
    ], 200 );
  }

  // ── Step 2: Aggregate closeout data (sales) ────────────────────────────────

  $sales = scoop_analytics_aggregate_closeouts(
    $period_start, $now, $half_start, $location_id
  );

  // ── Step 3: Compute sellthrough times from tub lifecycle ───────────────────

  $sellthrough = scoop_analytics_sellthrough( $period_start, $now, $location_id );

  // ── Step 4: Current stock per flavor ───────────────────────────────────────

  $stock = scoop_analytics_current_stock( $location_id );

  // ── Step 5: Last batch date per flavor ─────────────────────────────────────

  $last_batch = scoop_analytics_last_batch();

  // ── Step 6: Assemble per-flavor results ────────────────────────────────────

  $flavors_out = [];

  foreach ( $flavors_map as $flavor_id => $flavor_name ) {

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

  return new \WP_REST_Response( [
    'ok'           => true,
    'period_days'  => $period_days,
    'generated_at' => gmdate( 'Y-m-d\TH:i:s' ),
    'flavors'      => $flavors_out,
  ], 200 );
}


// ─────────────────────────────────────────────────────────────────────────────
// Data-gathering helpers — each uses Pods find() for portability
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Fetch all flavors as id => title map.
 *
 * @return array<int, string>
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
    $map[ (int) $pod->id() ] = (string) ( $pod->row['post_title'] ?? '' );
  }

  return $map;
}

/**
 * Aggregate closeout sales per flavor within the analysis period.
 *
 * Returns: [ flavor_id => [ 'total' => float, 'recent' => float, 'prior' => float, 'last_sale' => string|null ] ]
 *
 * Closeouts have relationship fields (flavor, location) which must be
 * resolved via Pods, and scalar fields (tubs_emptied) readable from $pod->row.
 *
 * @param string $period_start  Start of the full analysis window
 * @param string $now           Current timestamp
 * @param string $half_start    Start of the "recent" half-period
 * @param int    $location_id   Location filter (0 = all locations)
 * @return array
 */
function scoop_analytics_aggregate_closeouts(
  string $period_start,
  string $now,
  string $half_start,
  int $location_id
): array {

  $pod = pods( 'closeout' );
  if ( ! $pod ) return [];

  $where = [
    "t.post_status NOT IN ('trash', 'auto-draft')",
    "t.post_date >= '{$period_start}'",
  ];

  // If location filter is active and location is stored as a plain column,
  // we can push it into SQL. Otherwise we filter in PHP after resolving
  // the relationship via Pods.
  $db_loc_applied = false;

  $pod->find( [
    'limit'   => -1,
    'orderby' => 'post_date',
    'order'   => 'ASC',
    'where'   => implode( ' AND ', $where ),
  ] );

  $result = [];

  while ( $pod->fetch() ) {
    // Resolve the flavor relationship via Pods — never assume column layout
    $flavor_id = scoop_rel_id( $pod->field( 'flavor' ) );
    if ( $flavor_id <= 0 ) continue;

    // Location filter (resolved via Pods relationship)
    if ( $location_id > 0 ) {
      $closeout_loc = scoop_rel_id( $pod->field( 'location' ) );
      if ( $closeout_loc !== $location_id ) continue;
    }

    // tubs_emptied is a scalar float — safe to read from $pod->row
    $tubs_emptied = (float) ( $pod->row['tubs_emptied'] ?? 0.0 );
    $post_date    = $pod->row['post_date'] ?? '';

    if ( ! isset( $result[ $flavor_id ] ) ) {
      $result[ $flavor_id ] = [
        'total'     => 0.0,
        'recent'    => 0.0,
        'prior'     => 0.0,
        'last_sale' => null,
      ];
    }

    $result[ $flavor_id ]['total'] += $tubs_emptied;

    // Bucket into recent vs. prior half-period
    if ( $post_date >= $half_start ) {
      $result[ $flavor_id ]['recent'] += $tubs_emptied;
    } else {
      $result[ $flavor_id ]['prior'] += $tubs_emptied;
    }

    // Track the most recent sale date (closeouts are ordered ASC, so last wins)
    if ( $post_date && ! scoop_nodate( $post_date ) ) {
      $result[ $flavor_id ]['last_sale'] = date( 'Y-m-d', strtotime( $post_date ) );
    }
  }

  return $result;
}

/**
 * Compute average sellthrough time per flavor.
 *
 * Sellthrough = tub.emptied_at - batch.post_date (the batch the tub came from).
 * Only considers tubs emptied within the analysis period.
 *
 * @param string $period_start
 * @param string $now
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

  // We need emptied tubs with a valid emptied_at timestamp within the period.
  // emptied_at is stored as a string/datetime field on the tub.
  $where = [
    "t.post_status NOT IN ('trash', 'auto-draft')",
    "state = 'Emptied'",
    "emptied_at IS NOT NULL",
    "emptied_at != ''",
    "emptied_at != '0000-00-00 00:00:00'",
    "emptied_at >= '{$period_start}'",
  ];

  // NOTE: location is a Pods relationship field — not a direct column on
  // track_pods_tub.  Pushing "location = N" into the SQL WHERE causes
  // "Unknown column 'location' in 'WHERE'".  Filter in PHP instead,
  // exactly like scoop_analytics_aggregate_closeouts() does.

  $pod->find( [
    'limit' => -1,
    'where' => implode( ' AND ', $where ),
  ] );

  // Collect per-flavor sellthrough days
  $by_flavor = []; // flavor_id => [ days, days, ... ]

  while ( $pod->fetch() ) {
    $flavor_id  = scoop_rel_id( $pod->field( 'flavor' ) );
    if ( $flavor_id <= 0 ) continue;

    // Location filter — resolve via Pods relationship (not raw SQL column)
    if ( $location_id > 0 ) {
      $tub_loc = scoop_rel_id( $pod->field( 'location' ) );
      if ( $tub_loc !== $location_id ) continue;
    }

    $emptied_at = $pod->row['emptied_at'] ?? '';
    if ( scoop_nodate( $emptied_at ) ) continue;

    // Resolve the batch relationship to get the batch's post_date
    $batch_id = scoop_rel_id( $pod->field( 'batch' ) );
    if ( $batch_id <= 0 ) continue;

    $batch_post = get_post( $batch_id );
    if ( ! $batch_post ) continue;

    $batch_date = $batch_post->post_date;
    if ( scoop_nodate( $batch_date ) ) continue;

    $batch_ts   = strtotime( $batch_date );
    $emptied_ts = strtotime( $emptied_at );

    if ( $batch_ts <= 0 || $emptied_ts <= 0 || $emptied_ts < $batch_ts ) continue;

    $days = ( $emptied_ts - $batch_ts ) / 86400.0;

    if ( ! isset( $by_flavor[ $flavor_id ] ) ) {
      $by_flavor[ $flavor_id ] = [];
    }
    $by_flavor[ $flavor_id ][] = $days;
  }

  // Average per flavor
  $result = [];
  foreach ( $by_flavor as $fid => $days_arr ) {
    if ( empty( $days_arr ) ) continue;
    $result[ $fid ] = array_sum( $days_arr ) / count( $days_arr );
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
