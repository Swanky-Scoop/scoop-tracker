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

// Longer safety-net TTL for the per-entity cache below (performance.md #13)
// — real invalidation is precise (bumped only by that entity's own save),
// so this is purely a backstop against a missed invalidation path, not the
// thing correctness relies on. 1 day, not indefinite, so any such miss
// self-corrects instead of persisting.
define( 'SCOOP_ENTITY_CACHE_TTL', DAY_IN_SECONDS );

function scoop_cache_version(): int {
  return (int) get_option( 'scoop_cache_version', 1 );
}

// Entities whose bundle-relevant data changes rarely (flavor/use/location
// are edited maybe a handful of times a year — see performance.md #13) but
// whose transient cache was, until now, wholesale-invalidated by the same
// global scoop_cache_version a routine tub save bumps constantly. These get
// their own, narrower version — see scoop_entity_cache_version/_bust below
// and their use in scoop_fetch_entities() (includes/bundle-fetch.php) —
// bumped only by their own post type's save, so they can stay warm across
// the tub saves that dominate a normal session, independent of
// bundle.php's own whole-bundle cache (which still exists unchanged; this
// only matters when THAT one misses).
function scoop_slow_changing_entity_types(): array {
  return [ 'flavor', 'use', 'location', 'cabinet' ];
}

function scoop_entity_cache_version( string $entity_key ): int {
  return (int) get_option( "scoop_ecv_{$entity_key}", 1 );
}

function scoop_entity_cache_bust( string $entity_key ): void {
  update_option( "scoop_ecv_{$entity_key}", scoop_entity_cache_version( $entity_key ) + 1, false );
}

function scoop_cache_bust(?int $post_id = null, array $ctx = []): void {
  // Suppression flag — lets bulk writers (e.g. batch tub creation) skip the
  // per-row bump and call scoop_cache_bust() exactly once at the end.
  if ( ! empty( $GLOBALS['scoop_suppress_cache_bust'] ) ) return;

  if ( $post_id ) {
    if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;

    $post_type = get_post_type( $post_id );
    if ( $post_type === 'inventory_change' ) return;

    if ( $post_type && in_array( $post_type, scoop_slow_changing_entity_types(), true ) ) {
      scoop_entity_cache_bust( $post_type );
    }
  }

  // autoload=false: this option changes frequently, no need to load on every page
  update_option( 'scoop_cache_version', scoop_cache_version() + 1, false );
}

function scoop_analytics_cache_key( \WP_REST_Request $req ): string {
  $days = max( 1, (int) ( $req->get_param( 'days' ) ?? 30 ) );
  $loc  = (int) ( $req->get_param( 'location' )  ?? 0 );
  $grid = (string) ( $req->get_param( 'grid_type' ) ?? '' );
  $v    = scoop_cache_version();

  return 'scoop_a_' . md5( $v . '|' . $days . '|' . $loc . '|' . $grid );
}

function scoop_bundle_cache_key( \WP_REST_Request $req ): string {
  // Sort types so ?types=Batch,Cabinet and ?types=Cabinet,Batch share a cache entry
  $types = scoop_parse_types_param( $req->get_param( 'types' ) );
  sort( $types );

  $loc   = (int) ( $req->get_param( 'location' )          ?? 0 );
  $empty = (bool)( $req->get_param( 'include_empty_tubs' ) ?? false );
  $modified_range = (string)( $req->get_param( 'modified_range' ) ?? '' );
  $modified_since = (string)( $req->get_param( 'modified_since' ) ?? '' );
  $date_filters   = (string)( $req->get_param( 'date_filters' ) ?? '' );
  $filter_params  = [];

  foreach ( $req->get_params() as $key => $value ) {
    if ( strpos( (string) $key, 'filter_' ) !== 0 ) continue;
    $filter_params[ (string) $key ] = (string) $key . '=' . ( is_array( $value ) ? wp_json_encode( $value ) : (string) $value );
  }

  ksort( $filter_params );

  $v = scoop_cache_version();

  // WordPress transient keys max out at 172 chars; this stays well under that
  return 'scoop_b_' . md5( $v . '|' . implode( ',', $types ) . '|' . $loc . '|' . ( $empty ? '1' : '0' ) . '|' . $date_filters . '|' . implode( ',', $filter_params ) . '|' . $modified_range . '|' . $modified_since );
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
