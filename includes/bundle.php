<?php
// includes/bundle.php

function scoop_parse_types_param($raw): array {
  if (is_array($raw)) return array_values(array_filter(array_map('trim', $raw)));
  $raw = (string)$raw;
  if ($raw === '') return [];
  return array_values(array_filter(array_map('trim', explode(',', $raw))));
}

function scoop_bundle_get( \WP_REST_Request $req ) {
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

  $unknown = [];
  $needs   = [];

  foreach ( $types as $t ) {
    if ( ! isset( $specs[ $t ] ) ) { $unknown[] = $t; continue; }
    foreach ( ( $specs[ $t ]['needs'] ?? [] ) as $needType ) {
      $needs[ $needType ] = true;
    }
  }

  if ( $unknown ) {
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
  $date_filters = scoop_bundle_date_filter_context( $req, $types );

  foreach ( $needTypes as $needType ) {
    $data[ $needType ] = scoop_bundle_fetch_type( $needType, $req, [
      'requesting_types'    => $types,
      'date_filter_context' => $date_filters,
    ] );
  }

  $body = [
    'ok'           => true,
    'types'        => $types,
    'needs'        => $needTypes,
    'date_filters' => $date_filters,
    'data'         => $data,
  ];

  // ── Cache write ───────────────────────────────────────────────────────────
  set_transient( $cache_key, $body, SCOOP_CACHE_TTL );

  $body['_cache'] = 'miss';
  return new \WP_REST_Response( $body, 200 );
}
