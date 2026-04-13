<?php
// includes/cache.php

/**
 * Bundle response transient caching.
 *
 * Strategy: a single integer version number is stored in wp_options.
 * Every transient key includes the current version. When any Pods record
 * is saved, the version increments by 1. Old transients become unreachable
 * immediately and expire naturally after their TTL — no need to track or
 * delete individual keys.
 */

define( 'SCOOP_CACHE_TTL', 300 ); // 5 minutes — safety net if invalidation misses

function scoop_cache_version(): int {
  return (int) get_option( 'scoop_cache_version', 1 );
}

function scoop_cache_bust(): void {
  // autoload=false: this option changes frequently, no need to load on every page
  update_option( 'scoop_cache_version', scoop_cache_version() + 1, false );
  error_log( 'scoop_cache_bust: version now ' . scoop_cache_version() );
}

function scoop_bundle_cache_key( \WP_REST_Request $req ): string {
  // Sort types so ?types=Batch,Cabinet and ?types=Cabinet,Batch share a cache entry
  $types = scoop_parse_types_param( $req->get_param( 'types' ) );
  sort( $types );

  $loc   = (int) ( $req->get_param( 'location' )          ?? 0 );
  $empty = (bool)( $req->get_param( 'include_empty_tubs' ) ?? false );

  $v = scoop_cache_version();

  // WordPress transient keys max out at 172 chars; this stays well under that
  return 'scoop_b_' . md5( $v . '|' . implode( ',', $types ) . '|' . $loc . '|' . ( $empty ? '1' : '0' ) );
}

/**
 * Bust the cache whenever any Pods item is saved.
 *
 * pods_api_post_save_pod_item fires after the save is committed,
 * so the next bundle request will fetch fresh data.
 *
 * The $pieces array contains 'pod' (pod name) and 'id' if you ever
 * want to do smarter partial invalidation later.
 */
add_action( 'pods_api_post_save_pod_item', function( array $pieces ) {
  scoop_cache_bust();
}, 10, 1 );