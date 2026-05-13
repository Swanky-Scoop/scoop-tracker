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

function scoop_cache_bust(?int $post_id = null, array $ctx = []): void {
  if ( $post_id ) {
    if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;

    $post_type = get_post_type( $post_id );
    if ( $post_type === 'inventory_change' ) return;
  }

  // autoload=false: this option changes frequently, no need to load on every page
  update_option( 'scoop_cache_version', scoop_cache_version() + 1, false );
}

function scoop_bundle_cache_key( \WP_REST_Request $req ): string {
  // Sort types so ?types=Batch,Cabinet and ?types=Cabinet,Batch share a cache entry
  $types = scoop_parse_types_param( $req->get_param( 'types' ) );
  sort( $types );

  $loc   = (int) ( $req->get_param( 'location' )          ?? 0 );
  $empty = (bool)( $req->get_param( 'include_empty_tubs' ) ?? false );
  $modified_range = (string)( $req->get_param( 'modified_range' ) ?? '' );

  $v = scoop_cache_version();

  // WordPress transient keys max out at 172 chars; this stays well under that
  return 'scoop_b_' . md5( $v . '|' . implode( ',', $types ) . '|' . $loc . '|' . ( $empty ? '1' : '0' ) . '|' . $modified_range . '|' . $modified_since );
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
/*
TODO: This is redundant with the 'save_post' hook below, which also fires on Pods saves. The 'save_post' hook is more general and reliable, so we can probably remove this one after confirming that cache busting still works for all endpoints.
add_action( 'pods_api_post_save_pod_item', function( array $pieces ) {
  scoop_cache_bust();
}, 10, 1 );
*/


// Belt-and-suspenders: fires for any post save regardless of code path,
// covering creates via wp_insert_post and any non-Pods save mechanisms.
add_action( 'save_post',      'scoop_cache_bust', 10, 1 );
add_action( 'trashed_post',   'scoop_cache_bust', 10, 1 );
add_action( 'untrashed_post', 'scoop_cache_bust', 10, 1 );
add_action( 'deleted_post',   'scoop_cache_bust', 10, 1 );