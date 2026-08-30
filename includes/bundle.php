<?php
// includes/bundle.php

function scoop_parse_types_param($raw): array {
  if (is_array($raw)) return array_values(array_filter(array_map('trim', $raw)));
  $raw = (string)$raw;
  if ($raw === '') return [];
  return array_values(array_filter(array_map('trim', explode(',', $raw))));
}

function scoop_bundle_get( \WP_REST_Request $req ) {
  // A dock page combining many grid types (e.g. every [scoop_grid] type on
  // one page) unions all their entity 'needs' into one request — heavier
  // than any single grid, and slow enough on a real (larger) dataset to hit
  // PHP's default max_execution_time (often 30s) even though the same
  // request finishes comfortably on a small local dataset. 120s, not 0/
  // unlimited: this endpoint is hit on every page load (not a one-off admin
  // action like the importer's @set_time_limit(0) calls), so an unbounded
  // limit risks a genuinely broken query hanging a PHP-FPM worker forever
  // instead of failing. @-suppressed because hosts that disable
  // set_time_limit() via disable_functions raise a warning, not a fatal —
  // harmless to ignore, this just becomes a no-op there. Does NOT override
  // any reverse-proxy/CDN gateway timeout (nginx, Cloudflare, etc.) sitting
  // in front of the site — that's a host-level setting, out of this
  // codebase's reach; if the bundle still cuts off before this, that's
  // where to look next.
  @set_time_limit( 120 );

  // Timing diagnostic — see the matching comment in bundle-fetch.php's
  // scoop_fetch_entities(). This wraps the WHOLE request so a cache hit's
  // near-zero time and a cold miss's real cost are both visible, and so the
  // sum of each entity's own TOTAL (logged separately) can be compared
  // against this request's total to reveal any cost living outside the
  // per-entity fetch loop (cache write, JSON encoding, REST dispatch, etc).
  $t_request_start = microtime( true );

  // ── Cache read ────────────────────────────────────────────────────────────
  // force_bust (client: assets/ui/page-status.js's #bust URL hash, see
  // ScoopAPI._hashForcesBust) skips the read only — the write below still
  // happens as normal, so this also warms the transient with fresh data for
  // the next ordinary request rather than leaving it stale.
  $cache_key  = scoop_bundle_cache_key( $req );
  $force_bust = ! empty( $req->get_param( 'force_bust' ) );
  $cached     = $force_bust ? false : get_transient( $cache_key );

  if ( $cached !== false ) {
    // Stamp it so you can confirm cache hits in Query Monitor / network tab
    $cached['_cache'] = 'hit';
    scoop_debug_log( sprintf(
      'scoop_bundle_get: CACHE HIT types=%s in %.1fms',
      (string) $req->get_param( 'types' ),
      ( microtime( true ) - $t_request_start ) * 1000
    ) );
    return new \WP_REST_Response( $cached, 200 );
  }

  // ── Everything below is unchanged from your current function ──────────────
  $types = scoop_parse_types_param( $req->get_param( 'types' ) );
  $specs = scoop_bundle_specs();

  if ( ! $types ) {
    return new \WP_REST_Response( [
      'ok'    => false,
      'error' => 'Missing types param. Example: ?types=Cabinet,FlavorTub',
      'known' => array_keys( $specs ),
    ], 400 );
  }

  $unknown     = [];
  $known_types = [];
  $needs       = [];

  foreach ( $types as $t ) {
    if ( ! isset( $specs[ $t ] ) ) { $unknown[] = $t; continue; }
    $known_types[] = $t;
    foreach ( ( $specs[ $t ]['needs'] ?? [] ) as $needType ) {
      $needs[ $needType ] = true;
    }
  }

  // A dock page unions every host's grid type into one request (see the
  // comment atop this function), so one unrecognized type here previously
  // 400'd the whole bundle — every other, perfectly valid grid on that
  // page failed to load too. A grid type can be legitimately unknown to
  // THIS checkout while it's mid-development on another branch/worktree
  // (its shortcode already lives in the page content), so drop it and
  // serve everything this checkout does recognize instead of failing
  // closed. Only fail if NOTHING on the page is recognized — that's still
  // very likely a real mistake (typo, wrong param) worth surfacing as an
  // error rather than a silent empty bundle.
  if ( $unknown ) {
    scoop_debug_log( sprintf(
      'scoop_bundle_get: ignoring unknown grid type(s): %s (known=%s)',
      implode( ',', $unknown ), implode( ',', array_keys( $specs ) )
    ) );
  }

  if ( ! $known_types ) {
    return new \WP_REST_Response( [
      'ok'      => false,
      'error'   => 'Unknown grid type(s)',
      'unknown' => $unknown,
      'known'   => array_keys( $specs ),
      'types'   => $types,
    ], 400 );
  }

  $needTypes = array_keys( $needs );
  $data      = [];
  $date_filters = scoop_bundle_date_filter_context( $req, $known_types );

  foreach ( $needTypes as $needType ) {
    $data[ $needType ] = scoop_bundle_fetch_type( $needType, $req, [
      'requesting_types'    => $known_types,
      'date_filter_context' => $date_filters,
    ] );
  }

  $body = [
    'ok'            => true,
    'types'         => $known_types,
    'unknown_types' => $unknown,
    'needs'         => $needTypes,
    'date_filters'  => $date_filters,
    'data'          => $data,
  ];

  // ── Cache write ───────────────────────────────────────────────────────────
  set_transient( $cache_key, $body, SCOOP_CACHE_TTL );

  scoop_debug_log( sprintf(
    'scoop_bundle_get: CACHE MISS types=%s needs=%s TOTAL=%.1fms',
    implode( ',', $types ), implode( ',', $needTypes ),
    ( microtime( true ) - $t_request_start ) * 1000
  ) );

  $body['_cache'] = 'miss';
  return new \WP_REST_Response( $body, 200 );
}
