<?php

/**
 * bundle-fetch.php
 *
 * Hybrid fetch strategy:
 * - Bulk fetch each entity type with pods()->find() — one query per entity type
 * - Read known scalar columns (string, float, bool, datetime) from $pod->row
 * - Read int, post_names, and any unknown-typed fields via $pod->field()
 *   because in this schema almost all ints are relationship IDs that Pods
 *   needs to resolve correctly
 * - Cabinet enrichment for slots uses a single bulk find() instead of N calls
 * - Zero-date datetime fields are omitted from the response entirely;
 *   the client treats absence as equivalent to 0000-00-00 00:00:00
 */

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

function scoop_field_type( $desc ): string {
  if ( is_string( $desc ) ) return $desc;
  if ( is_array( $desc ) ) {
    $t = $desc['data_type'] ?? $desc['type'] ?? 'string';
    return is_string( $t ) ? $t : 'string';
  }
  return 'string';
}

function scoop_text_out( $v ): string {
  if ( is_array( $v ) || is_object( $v ) ) return '';
  $s = (string) $v;
  $s = html_entity_decode( $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
  $s = str_replace( [ "\u{2018}", "\u{2019}" ], [ "'", "'" ], $s );
  return $s;
}

function scoop_post_names_out( $v ): array {
  $a = [];
  if ( is_array( $v ) ) {
    foreach ( $v as $p ) {
      if ( ! empty( $p['post_name'] ) ) $a[] = $p['post_name'];
    }
  }
  return $a;
}

/**
 * Pods 'file' fields resolve via $pod->field() to an attachment array (or a
 * bare attachment ID, depending on Pods version/config). Reduce either shape
 * to the plain attachment URL the client can show as text.
 *
 * ID + wp_get_attachment_url() is tried before falling back to the raw
 * 'guid' column: guid is frozen at upload time and never gets recalculated
 * if the site's domain/upload path changes later (a well-known WP gotcha —
 * NOT meant to be used as a display URL), which broke image paths on
 * environments other than the one an attachment was originally sideloaded
 * on (see CLAUDE.md's env-drift notes — local/TEST/OPS are separate DBs).
 * wp_get_attachment_url() always reflects the current environment's
 * upload_url/baseurl, so it's the one to prefer.
 */
function scoop_file_url_out( $v ): string {
  if ( empty( $v ) ) return '';

  if ( is_array( $v ) ) {
    if ( isset( $v[0] ) && is_array( $v[0] ) ) $v = $v[0];

    if ( ! empty( $v['ID'] ) ) {
      $url = wp_get_attachment_url( (int) $v['ID'] );
      if ( $url ) return $url;
    }
    if ( ! empty( $v['guid'] ) ) return scoop_text_out( $v['guid'] );
    return '';
  }

  if ( is_numeric( $v ) ) return (string) ( wp_get_attachment_url( (int) $v ) ?: '' );

  return scoop_text_out( $v );
}

function scoop_relation_ids_out( $v ): array {
  if ( empty( $v ) ) return [];

  $values = is_array( $v ) ? $v : [ $v ];
  $ids = [];

  foreach ( $values as $item ) {
    $id = scoop_rel_id( $item );
    if ( $id > 0 ) $ids[ $id ] = $id;
  }

  return array_values( $ids );
}

function scoop_normalize_date_filter_key( $key ): string {
  $key = strtolower( trim( (string) $key ) );
  $key = str_replace( '-', '_', $key );
  return preg_replace( '/[^a-z0-9_]/', '', $key );
}

function scoop_parse_date_filter_keys( $raw ): array {
  $values = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
  $out    = [];

  foreach ( $values as $value ) {
    $key = scoop_normalize_date_filter_key( $value );
    if ( $key !== '' ) $out[ $key ] = $key;
  }

  return array_values( $out );
}

function scoop_date_filter_presets(): array {
  $hour = defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600;
  $day  = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;

  return [
    'last_24_hours' => 24 * $hour,
    'last_48_hours' => 48 * $hour,
    'last_7_days'   => 7 * $day,
    'last_30_days'  => 30 * $day,
  ];
}

function scoop_normalize_date_filter_preset( $raw ): string {
  $preset = scoop_normalize_date_filter_key( $raw );
  $aliases = [
    '24hrs'         => 'last_24_hours',
    '24_hours'      => 'last_24_hours',
    '48hrs'         => 'last_48_hours',
    '48_hours'      => 'last_48_hours',
    '1_week'        => 'last_7_days',
    'week'          => 'last_7_days',
    '1_month'       => 'last_30_days',
    'month'         => 'last_30_days',
    'today'         => 'last_24_hours',
    'yesterday'     => 'last_48_hours',
    'this_week'     => 'last_7_days',
  ];

  $preset = $aliases[ $preset ] ?? $preset;
  return array_key_exists( $preset, scoop_date_filter_presets() ) ? $preset : 'last_48_hours';
}

function scoop_date_filter_range_for_preset( string $preset ): array {
  $presets = scoop_date_filter_presets();
  $preset  = scoop_normalize_date_filter_preset( $preset );
  $end_ts  = time();
  $start_ts = $end_ts - ( $presets[ $preset ] ?? $presets['last_48_hours'] );
  $format_date = function ( int $ts ): string {
    return function_exists( 'wp_date' ) ? wp_date( 'c', $ts ) : date( 'c', $ts );
  };

  return [
    'preset'   => $preset,
    'start'    => $format_date( $start_ts ),
    'end'      => $format_date( $end_ts ),
    'start_ts' => $start_ts,
    'end_ts'   => $end_ts,
  ];
}

function scoop_bundle_date_filter_context( \WP_REST_Request $req, array $requesting_types = [] ): array {
  $keys = scoop_parse_date_filter_keys( $req->get_param( 'date_filters' ) );

  if ( empty( $keys ) && in_array( 'DateActivity', $requesting_types, true ) ) {
    $keys = [ 'activity' ];
  }
  if ( empty( $keys ) && in_array( 'BatchHistory', $requesting_types, true ) ) {
    $keys = [ 'created' ];
  }

  $ranges = [];
  foreach ( $keys as $key ) {
    $preset = $req->get_param( 'filter_' . $key );

    if ( ( $preset === null || $preset === '' ) && $key === 'activity' ) {
      $preset = $req->get_param( 'modified_range' );
    }

    $preset = scoop_normalize_date_filter_preset( $preset );
    $ranges[ $key ] = scoop_date_filter_range_for_preset( $preset );
  }

  return [
    'enabled' => $keys,
    'ranges'  => $ranges,
  ];
}

function scoop_date_filter_sql_clause( string $field, array $range ): string {
  if ( empty( $range['start'] ) || empty( $range['end'] ) ) return '';

  $start = esc_sql( $range['start'] );
  $end   = esc_sql( $range['end'] );

  return "{$field} >= '{$start}' AND {$field} <= '{$end}'";
}

function scoop_date_filter_value_in_range( $value, array $range ): bool {
  if ( empty( $value ) || empty( $range['start'] ) || empty( $range['end'] ) ) return false;

  $candidate = str_replace( 'T', ' ', trim( (string) $value ) );
  if ( $candidate === '' || strpos( $candidate, '0000-00-00' ) === 0 ) return false;

  if ( preg_match( '/^\d{4}-\d{2}-\d{2}/', $candidate ) ) {
    $candidate = substr( $candidate, 0, 19 );
  } else {
    $ts = strtotime( $candidate );
    if ( ! $ts ) return false;
    $candidate = function_exists( 'wp_date' ) ? wp_date( 'c', $ts ) : date( 'c', $ts );
  }

  return $candidate >= $range['start'] && $candidate <= $range['end'];
}

function scoop_tub_date_filter_sql_clauses( array $date_filters, array $ranges ): array {
  $clauses = [];

  foreach ( $date_filters as $filter_key ) {
    $range = $ranges[ $filter_key ] ?? null;
    if ( ! is_array( $range ) ) continue;

    if ( $filter_key === 'activity' ) {
      $event_clauses = array_filter( [
        scoop_date_filter_sql_clause( 'opened_on', $range ),
        scoop_date_filter_sql_clause( 'emptied_at', $range ),
        scoop_date_filter_sql_clause( 'created_on', $range ),
      ] );
      $override_clause = scoop_date_filter_sql_clause( 't.post_modified', $range );
      if ( $override_clause !== '' ) $event_clauses[] = "(state = '__override__' AND {$override_clause})";
      if ( $event_clauses ) $clauses[] = '(' . implode( ' OR ', $event_clauses ) . ')';
      continue;
    }

    $field_map = [
      'created_on'    => 'created_on',
      'opened_on'     => 'opened_on',
      'emptied_at'    => 'emptied_at',
      'post_modified' => 't.post_modified',
    ];

    if ( ! empty( $field_map[ $filter_key ] ) ) {
      $clause = scoop_date_filter_sql_clause( $field_map[ $filter_key ], $range );
      if ( $clause !== '' ) $clauses[] = '(' . $clause . ')';
    }
  }

  return $clauses;
}

function scoop_datetime_out( $v ): string {
  if ( scoop_nodate( $v ) ) return '';

  return mysql2date( 'c', (string) $v, false ) ?: '';
}

function scoop_cast( $v, $desc ) {
  $type = scoop_field_type( $desc );
  switch ( $type ) {
    case 'post_names': return scoop_post_names_out( $v );
    case 'ids':        return scoop_relation_ids_out( $v );
    case 'int':        return scoop_rel_id( $v );
    case 'float':      return ( is_array( $v ) || is_object( $v ) ) ? 0.0 : (float) $v;
    case 'string':     return scoop_text_out( $v );
    case 'bool':       return (bool) $v;
    case 'datetime':   return scoop_datetime_out( $v );
    case 'file':       return scoop_file_url_out( $v );
    default:           return $v;
  }
}

/**
 * Classify spec fields into two buckets:
 *
 * $row_fields  — known safe scalars: string, float, bool, datetime.
 *                Read directly from $pod->row after find(). Zero extra queries.
 *
 * $needs_field — everything else: int, post_names, or any unknown type
 *                (e.g. 'use', 'flavor' from a loose spec entry).
 *                Resolved via $pod->field() so Pods can handle relationship
 *                resolution, multi-value fields, and aliased columns correctly.
 *
 * Why the whitelist rather than a blacklist:
 *   A blacklist of int+post_names misses unknown types like the 'titleMap'
 *   entry in the use spec, which has no matching column in wp_pods_use and
 *   would silently return null from $pod->row instead of the expected [].
 */
function scoop_classify_fetch_fields( array $spec_fields ): array {
  $scalar_types = [ 'string', 'float', 'bool', 'datetime' ];

  $row_fields  = [];
  $needs_field = [];

  foreach ( $spec_fields as $field => $desc ) {
    $type = scoop_field_type( $desc );
    if ( in_array( $type, $scalar_types, true ) ) {
      $row_fields[ $field ] = $desc;
    } else {
      $needs_field[ $field ] = $desc;
    }
  }

  return [ $row_fields, $needs_field ];
}

// ─────────────────────────────────────────────────────────────────────────────
// tub relationship bulk read (performance.md #11)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * tub's six relationship ('pick') fields — batch, flavor, location, use,
 * slot, closeout — each resolved via a per-row $pod->field() call today,
 * confirmed (performance.md #11) as ~83% of a cold bundle fetch's total
 * time. Below is a bulk wp_podsrel read replacing all six per-row calls
 * with one query for the whole result set.
 *
 * Verified equivalent to $pod->field() across all 2,427 real local tub rows
 * (14,562 (row, field) checks, 0 mismatches) — see the CabinetWorkflow QA
 * conversation — PROVIDED the read joins wp_posts and requires
 * post_status = 'publish' on the related item. Without that join there were
 * 18 real mismatches, all explained: $pod->field() silently excludes
 * relationship targets that aren't published (confirmed via 3 real
 * draft-status batch/closeout posts) — wp_podsrel still carries the row
 * regardless of the target's status, so a naive read doesn't know to
 * exclude it. This matters here specifically because a large share of
 * `tub` posts in this shop's data are `draft` (Emptied tubs manually
 * unpublished) — this bypass reproduces $pod->field()'s existing exclusion
 * behavior exactly, not a new rule.
 */

/**
 * Resolves the tub pod's field_id for each relationship field slug, from
 * wp_posts (_pods_field posts, child of the _pods_pod 'tub' post) — NEVER
 * hardcode these: they're per-environment auto-increment IDs and will
 * differ between local/TEST/OPS. Cached in a transient since Pods field
 * definitions essentially never change; DAY_IN_SECONDS is generous, not
 * load-bearing (a stale/missing cache just means one extra cheap query,
 * not wrong data).
 */
function scoop_tub_relationship_field_ids(): array {
  static $cache = null;
  if ( $cache !== null ) return $cache;

  $transient_key = 'scoop_tub_rel_field_ids';
  $cached = get_transient( $transient_key );
  if ( is_array( $cached ) && count( $cached ) === 6 ) {
    $cache = $cached;
    return $cache;
  }

  global $wpdb;
  $slugs = [ 'batch', 'flavor', 'location', 'use', 'slot', 'closeout' ];

  $pod_post_id = $wpdb->get_var( $wpdb->prepare(
    "SELECT ID FROM {$wpdb->posts} WHERE post_type = '_pods_pod' AND post_name = %s LIMIT 1",
    'tub'
  ) );

  $out = [];
  if ( $pod_post_id ) {
    $placeholders = implode( ',', array_fill( 0, count( $slugs ), '%s' ) );
    $rows = $wpdb->get_results( $wpdb->prepare(
      "SELECT ID, post_name FROM {$wpdb->posts} WHERE post_type = '_pods_field' AND post_parent = %d AND post_name IN ({$placeholders})",
      array_merge( [ $pod_post_id ], $slugs )
    ) );
    foreach ( $rows as $r ) {
      $out[ $r->post_name ] = (int) $r->ID;
    }
  }

  $cache = $out;
  set_transient( $transient_key, $out, DAY_IN_SECONDS );
  return $out;
}

/**
 * Bulk-reads tub's six relationship fields from wp_podsrel in ONE query,
 * scoped to the same tub rows the caller's own find() WHERE clause would
 * select ($tub_where_sql — pass the exact same $where_clauses string
 * scoop_fetch_entities builds for 'tub', so the two selections can never
 * drift apart). Returns [ tub_id => [ field_slug => related_post_id ], ... ]
 * — a field/tub with no eligible relationship is simply absent (caller
 * treats that as 0, same as $pod->field() returning empty). All six fields
 * are pick_format_type 'single' (confirmed: 0 multi-row cases in the
 * verification pass), so only the first matching row per (item, field) is
 * kept — 'ORDER BY r.id ASC' makes that deterministic.
 *
 * The subquery joins {$wpdb->prefix}pods_tub AS d (Pods' own table storage
 * for tub's scalar fields — state/amount/emptied_at/etc., see project
 * memory on Pods storage) alongside wp_posts AS t, with the SAME aliases
 * Pods' own internal find() query uses. $tub_where_sql routinely contains
 * bare column references like `state`/`emptied_at`/`location` (the
 * Emptied-exclusion clause, the location filter) that only resolve against
 * `d`, not `t` — without this join those references would error as
 * unknown columns.
 */
function scoop_bulk_tub_relationships( string $tub_where_sql ): array {
  $field_ids = scoop_tub_relationship_field_ids();
  if ( empty( $field_ids ) ) return [];

  global $wpdb;
  $id_to_slug    = array_flip( $field_ids );
  $field_id_list = implode( ',', array_map( 'intval', array_values( $field_ids ) ) );

  $rows = $wpdb->get_results(
    "SELECT r.item_id, r.field_id, r.related_item_id
     FROM {$wpdb->prefix}podsrel r
     INNER JOIN {$wpdb->posts} p ON p.ID = r.related_item_id
     WHERE r.field_id IN ({$field_id_list})
       AND p.post_status = 'publish'
       AND r.item_id IN (
         SELECT t.ID
         FROM {$wpdb->posts} t
         LEFT JOIN {$wpdb->prefix}pods_tub d ON d.id = t.ID
         WHERE t.post_type = 'tub' AND ({$tub_where_sql})
       )
     ORDER BY r.id ASC"
  );

  $out = [];
  foreach ( $rows as $r ) {
    $slug = $id_to_slug[ (int) $r->field_id ] ?? null;
    if ( ! $slug ) continue;
    $item_id = (int) $r->item_id;
    if ( isset( $out[ $item_id ][ $slug ] ) ) continue; // first match wins — pick_format_type=single
    $out[ $item_id ][ $slug ] = (int) $r->related_item_id;
  }

  return $out;
}

/**
 * inventory_change.tubs/.flavors — same N+1 pattern as tub's six fields
 * above, on a different pod. Both are multi-value ('ids' data_type,
 * pick_format_type=multi, pick_limit=0 — confirmed via each field's own
 * Pods config, postmeta on the _pods_field posts) and both are configured
 * pick_post_status=publish, same as every tub relationship field verified
 * so far — an EXPLICIT per-field Pods setting, not a hidden framework
 * default (worth restating: it would be a mistake to assume this holds for
 * some OTHER field without checking that field's own config first).
 *
 * This matters a lot more here than it did for tub: an inventory_change
 * row logs everything touched by one action (e.g. a whole batch's tubs at
 * once — one real row carried 23 related tubs), and since those tubs
 * accumulate `draft` status over time (this shop's practice of
 * unpublishing tubs once Emptied), a field that started with 23 related
 * tubs can resolve down to as few as 2 by the time it's read — DateActivity
 * displaying a shrinking, inaccurate picture of what an inventory_change
 * event actually touched, silently, well after the fact.
 *
 * Verified equivalent to $pod->field() across all 1,546 real local
 * inventory_change rows (3,092 (row, field) checks) — exact SET match AND
 * exact ORDER match (weight-ordered) on every single one, 0 mismatches
 * either way — see the CabinetWorkflow QA conversation.
 *
 * Unlike tub, inventory_change's own WHERE clauses (scoop_fetch_entities,
 * $key === 'inventory_change') only ever reference wp_posts columns
 * (t.post_date, t.post_status) — no bare pod-storage-table column
 * references — so the subquery below needs no extra JOIN the way tub's
 * did.
 */
function scoop_inventory_change_relationship_field_ids(): array {
  static $cache = null;
  if ( $cache !== null ) return $cache;

  $transient_key = 'scoop_ic_rel_field_ids';
  $cached = get_transient( $transient_key );
  if ( is_array( $cached ) && count( $cached ) === 2 ) {
    $cache = $cached;
    return $cache;
  }

  global $wpdb;
  $slugs = [ 'tubs', 'flavors' ];

  $pod_post_id = $wpdb->get_var( $wpdb->prepare(
    "SELECT ID FROM {$wpdb->posts} WHERE post_type = '_pods_pod' AND post_name = %s LIMIT 1",
    'inventory_change'
  ) );

  $out = [];
  if ( $pod_post_id ) {
    $placeholders = implode( ',', array_fill( 0, count( $slugs ), '%s' ) );
    $rows = $wpdb->get_results( $wpdb->prepare(
      "SELECT ID, post_name FROM {$wpdb->posts} WHERE post_type = '_pods_field' AND post_parent = %d AND post_name IN ({$placeholders})",
      array_merge( [ $pod_post_id ], $slugs )
    ) );
    foreach ( $rows as $r ) {
      $out[ $r->post_name ] = (int) $r->ID;
    }
  }

  $cache = $out;
  set_transient( $transient_key, $out, DAY_IN_SECONDS );
  return $out;
}

/**
 * Returns [ inventory_change_id => [ 'tubs' => [ids...], 'flavors' => [ids...] ], ... ]
 * — full arrays in weight order (unlike tub's single-value bypass, these
 * are genuinely multi-value; 'first match wins' would be wrong here).
 * $ic_where_sql: the exact same WHERE scoop_fetch_entities builds for
 * 'inventory_change', so this can never select a different row set than
 * the row loop actually iterates.
 */
function scoop_bulk_inventory_change_relationships( string $ic_where_sql ): array {
  $field_ids = scoop_inventory_change_relationship_field_ids();
  if ( empty( $field_ids ) ) return [];

  global $wpdb;
  $id_to_slug    = array_flip( $field_ids );
  $field_id_list = implode( ',', array_map( 'intval', array_values( $field_ids ) ) );

  $rows = $wpdb->get_results(
    "SELECT r.item_id, r.field_id, r.related_item_id
     FROM {$wpdb->prefix}podsrel r
     INNER JOIN {$wpdb->posts} p ON p.ID = r.related_item_id
     WHERE r.field_id IN ({$field_id_list})
       AND p.post_status = 'publish'
       AND r.item_id IN (
         SELECT t.ID FROM {$wpdb->posts} t WHERE t.post_type = 'inventory_change' AND ({$ic_where_sql})
       )
     ORDER BY r.item_id, r.field_id, r.weight, r.id"
  );

  $out = [];
  foreach ( $rows as $r ) {
    $slug = $id_to_slug[ (int) $r->field_id ] ?? null;
    if ( ! $slug ) continue;
    $out[ (int) $r->item_id ][ $slug ][] = (int) $r->related_item_id;
  }

  return $out;
}

// ─────────────────────────────────────────────────────────────────────────────
// Core fetch
// ─────────────────────────────────────────────────────────────────────────────

function scoop_fetch_entities( string $key, array $ctx = [], bool $fields_only = false ): array {
  scoop_debug_log( "Fetching entities of type '{$key}' with context: " . json_encode( $ctx ) );
  $specs = scoop_entity_specs();
  if ( empty( $specs[ $key ] ) ) return [];

  $spec = $specs[ $key ];
  if ( ! function_exists( 'pods' ) ) return [];

  if ( $fields_only ) return $spec['fields'] ?? [];

  // Per-entity cache for slow-changing entities (performance.md #13) —
  // layered on top of bundle.php's whole-bundle transient, and only
  // matters when that outer cache misses (e.g. an unrelated tub save
  // bumped the global version): flavor/use/location can still be served
  // warm from here instead of hitting the DB + $pod->field() again.
  // 'flavor's key includes whether InstockFlavor-only fields are included
  // (see #12 above) since that changes the actual row shape — otherwise a
  // cache entry built for one request could wrongly serve the other.
  $entity_cache_key = null;
  if ( in_array( $key, scoop_slow_changing_entity_types(), true ) ) {
    $includes_instock = $key === 'flavor' && in_array( 'InstockFlavor', $ctx['requesting_types'] ?? [], true );
    $entity_cache_key = 'scoop_ec_' . md5( $key . '|' . scoop_entity_cache_version( $key ) . '|' . ( $includes_instock ? '1' : '0' ) );

    $entity_cached = get_transient( $entity_cache_key );
    if ( $entity_cached !== false && is_array( $entity_cached ) ) {
      scoop_debug_log( "scoop_fetch_entities('{$key}'): entity-cache HIT" );
      return $entity_cached;
    }
  }

  $pod_name    = $spec['pod'];
  $spec_fields = $spec['fields']      ?? [];
  $post_fields = $spec['post_fields'] ?? [];

  [ $row_fields, $needs_field ] = scoop_classify_fetch_fields( $spec_fields );

  // flavor.tubs/current_slots are multi-value ('ids') relationship fields.
  // Only InstockFlavorGridModel (assets/models/instock-flavor-grid-model.js)
  // actually reads them; every other grid type that needs 'flavor'
  // (FlavorTub, Cabinet, Batch, ...) builds its own flavor grouping/badges/
  // dropdown options from the tub entity's own 'flavor' field and flavor's
  // basic identity fields instead — confirmed by tracing
  // _base-grid-model.js/_flavor.js/flavor-tub-grid-model.js, none of which
  // reference these two fields. See performance.md #12 — measured ~35% cut
  // to 'flavor's own needs_field cost (~656ms of ~1928ms on 226 rows
  // locally), not a majority share; the remaining cost is menu_board/photo/
  // allergens/web_id, still worth a further look (see #13's cache-scope
  // idea, which would help those too since they're just as static).
  if ( $key === 'flavor' && ! in_array( 'InstockFlavor', $ctx['requesting_types'] ?? [], true ) ) {
    unset( $needs_field['tubs'], $needs_field['current_slots'] );
  }

  // slot.location is unconditionally overwritten by
  // scoop_enrich_slots_with_location() below (single bulk cabinet lookup) —
  // resolving it per-row via $pod->field() here first is pure waste.
  if ( $key === 'slot' ) {
    unset( $needs_field['location'] );
  }

  // tub's six relationship fields are resolved via one bulk wp_podsrel read
  // instead of six $pod->field() calls per row — see
  // scoop_bulk_tub_relationships()'s own header comment for the
  // equivalence verification this relies on. Populated into $row directly
  // in the fetch loop below, from $tub_relationships (computed after
  // $where_clauses is finalized, since it reuses that exact WHERE so the
  // two selections can never drift apart).
  if ( $key === 'tub' ) {
    foreach ( [ 'batch', 'flavor', 'location', 'use', 'slot', 'closeout' ] as $rel_field ) {
      unset( $needs_field[ $rel_field ] );
    }

    // tub.index is classified 'int' (scoop_classify_fetch_fields' whitelist
    // treats every int as a possible relationship needing $pod->field() —
    // true for the six fields above, not for this one). It's a plain
    // scalar column in {$wpdb->prefix}pods_tub, same table as amount/state
    // (already read directly via $pod->row, no relationship resolution at
    // all) — not a relationship, nothing for $pod->field() to resolve.
    // Verified: $pod->row['index'] === $pod->field('index') across all
    // 2,427 real local tub rows (draft and published), 0 mismatches.
    unset( $needs_field['index'] );
  }

  // inventory_change.tubs/.flavors — same bulk-read swap, see
  // scoop_bulk_inventory_change_relationships()'s header comment.
  if ( $key === 'inventory_change' ) {
    unset( $needs_field['tubs'], $needs_field['flavors'] );
  }

  $loc_id = ! empty( $ctx['location'] ) ? (int) $ctx['location'] : 0;

  // NOT IN trash/auto-draft matches the original get_posts( 'post_status'=>'any' )
  // which excluded only those two. An explicit IN list risks silently dropping
  // records saved with custom or unexpected statuses (pending, future, etc).
  $where_clauses = [
    "t.post_status NOT IN ('trash', 'auto-draft')",
  ];

  $db_loc_applied = false;

  if ( $key === 'tub' ) {
    $requesting_types = $ctx['requesting_types'] ?? [];

    // Exclude emptied tubs at the DB level unless the caller explicitly wants them.
    // This is the biggest single filter on the tub table and saves the most work.
    //
    // InstockFlavor's Details drill-down would ideally resolve titles for
    // every tub id in flavor.tubs, including long-emptied ones — but
    // unconditionally disabling this filter whenever InstockFlavor shares a
    // page with any other grid pulls the shop's ENTIRE tub history into one
    // request regardless of the other grids' needs. That's unbounded and
    // exhausted PHP's memory limit in practice (crashed the php-fpm worker).
    // Reverted: always exclude Emptied here. Details falls back to a plain
    // "tub #id" label for tubs old enough to have been trimmed — a small
    // cosmetic downgrade, not a crash.
    //
    // One bounded exception: tubs emptied within the last
    // SCOOP_TUB_EMPTIED_REVERT_HOURS (see hooks/tub-state.php) still pass —
    // FlavorTubGridModel's "recently emptied" filter and scoop_enforce_tub_rules'
    // revert window both key off that same fixed ceiling, so this stays a
    // small, bounded addition to the result set, not the unbounded "entire
    // history" query that crashed things before. The cutoff is computed from
    // current_time('mysql') (not SQL NOW()) to match the clock emptied_at was
    // actually stamped with — see scoop_touch_tub_post_modified.
    $include_empty = ! empty( $ctx['include_empty_tubs'] );
    if ( ! $include_empty ) {
      $revert_hours = defined( 'SCOOP_TUB_EMPTIED_REVERT_HOURS' ) ? (int) SCOOP_TUB_EMPTIED_REVERT_HOURS : 0;
      $cutoff_sql   = esc_sql( date( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - ( $revert_hours * HOUR_IN_SECONDS ) ) );
      $where_clauses[] = "(state != 'Emptied' OR emptied_at >= '{$cutoff_sql}')";
    }

    // DateActivity-only tub bundles should not scan every historical tub.
    // When other grids are present, keep active tubs in scope for their views.
    $has_date_activity = in_array( 'DateActivity', $requesting_types, true );
    $has_other_grids   = ! empty( array_diff( $requesting_types, [ 'DateActivity' ] ) );
    $date_filters      = $ctx['date_filters'] ?? [];
    $date_ranges       = $ctx['date_filter_ranges'] ?? [];

    if ( $has_date_activity && ! $has_other_grids ) {
      $date_clauses = scoop_tub_date_filter_sql_clauses( $date_filters, $date_ranges );
      if ( $date_clauses ) {
        $where_clauses[] = '(' . implode( ' OR ', $date_clauses ) . ')';
      } else {
        $where_clauses[] = '1=0';
      }
    }

    // Push location into SQL for tubs — location is a plain int column here.
    if ( $loc_id > 0 && array_key_exists( 'location', $spec_fields ) ) {
      $where_clauses[] = "location = {$loc_id}";
      $db_loc_applied  = true;
    }
  }


  if ( $key === 'inventory_change' ) {
    $requesting_types   = $ctx['requesting_types'] ?? [];
    $has_date_activity = in_array( 'DateActivity', $requesting_types, true );
    $date_filters      = $ctx['date_filters'] ?? [];
    $date_ranges       = $ctx['date_filter_ranges'] ?? [];

    if ( $has_date_activity ) {
      if ( in_array( 'activity', $date_filters, true ) ) {
        $activity_clause = scoop_date_filter_sql_clause( 't.post_date', $date_ranges['activity'] ?? [] );
        $where_clauses[] = $activity_clause !== '' ? $activity_clause : '1=0';
      } else {
        $where_clauses[] = '1=0';
      }
    }
  }

  if ( $key === 'batch' ) {
    // Date-range filter for the BatchHistory grid. Defaults to the 'created'
    // filter key (set in scoop_bundle_date_filter_context above), bound to
    // t.post_date. If no filter is enabled, fall back to "1=0" so we never
    // ship every batch ever made — the grid is intended to be windowed.
    $requesting_types   = $ctx['requesting_types'] ?? [];
    $has_batch_history  = in_array( 'BatchHistory', $requesting_types, true );
    $date_filters       = $ctx['date_filters'] ?? [];
    $date_ranges        = $ctx['date_filter_ranges'] ?? [];

    if ( $has_batch_history ) {
      if ( in_array( 'created', $date_filters, true ) ) {
        $created_clause  = scoop_date_filter_sql_clause( 't.post_date', $date_ranges['created'] ?? [] );
        $where_clauses[] = $created_clause !== '' ? $created_clause : '1=0';
      } else {
        $where_clauses[] = '1=0';
      }
    }
  }

  $find_where = implode( ' AND ', $where_clauses );

  $find_params = [
    'limit'   => -1,
    'orderby' => 'post_date',
    'order'   => 'ASC',
    'where'   => $find_where,
  ];

  // Timing diagnostic for the "why does a cold-cache bundle fetch take
  // 10-15s" investigation — same lap-timing-via-microtime pattern used for
  // the batch-creation investigation in hooks/batch-tub.php. Entirely
  // gated behind scoop_debug_log()'s SCOOP_DEBUG_LOG constant, zero cost
  // otherwise. Suspect: $pod->field() is called once per needs_field/
  // post_field PER ROW (relationship resolution) — this isolates that from
  // the find() query itself so the hypothesis can be confirmed with real
  // numbers before touching the fetch logic.
  $t_request_start  = microtime( true );
  $t_needs_field_ms = 0.0;
  $t_post_fields_ms = 0.0;
  $t_rel_bulk_ms    = 0.0;

  // See scoop_bulk_tub_relationships()'s header comment. $find_where is the
  // exact same WHERE this fetch's own find() uses, so the bulk relationship
  // read can never select a different set of tubs than the row loop below
  // actually iterates.
  $tub_relationships = [];
  if ( $key === 'tub' ) {
    $t0 = microtime( true );
    $tub_relationships = scoop_bulk_tub_relationships( $find_where );
    $t_rel_bulk_ms = ( microtime( true ) - $t0 ) * 1000;
  }

  // Same idea for inventory_change.tubs/.flavors — see
  // scoop_bulk_inventory_change_relationships()'s header comment.
  $ic_relationships = [];
  if ( $key === 'inventory_change' ) {
    $t0 = microtime( true );
    $ic_relationships = scoop_bulk_inventory_change_relationships( $find_where );
    $t_rel_bulk_ms = ( microtime( true ) - $t0 ) * 1000;
  }

  $pod = pods( $pod_name );
  if ( ! $pod ) return [];

  $t0 = microtime( true );
  $pod->find( $find_params );
  $t_find_ms = ( microtime( true ) - $t0 ) * 1000;

  $out = [];

  while ( $pod->fetch() ) {
    $id  = (int) $pod->id();
    $row = [ 'id' => $id ];

    // Title from the wp_posts columns already in $pod->row — no extra query
    if ( ! empty( $spec['title'] ) ) {
      $row['_title'] = (string) ( $pod->row['post_title'] ?? '' );
    }

    // Scalar fields — plain columns in $pod->row, zero extra DB hits.
    // Datetime fields with a zero/null value are omitted entirely;
    // the client treats absence as equivalent to 0000-00-00 00:00:00.
    foreach ( $row_fields as $field => $desc ) {
      $type = scoop_field_type( $desc );
      $raw  = $pod->row[ $field ] ?? null;

      //if ( $type === 'datetime' && scoop_nodate( $raw ) ) continue;
     // error_log( "Also Pre-Processing post field '{$field}' of type '{$desc}' " );
      $row[ $field ] = scoop_cast( $raw, $desc );
    }

    // Relationship + unknown-typed fields — resolved by Pods
    $t0 = microtime( true );
    foreach ( $needs_field as $field => $desc ) {

      //error_log( "==> Processing field '{$field}' of type '{$desc}' for {$key} ID {$id}" );
      $row[ $field ] = scoop_cast( $pod->field( $field ), $desc );
    }
    $t_needs_field_ms += ( microtime( true ) - $t0 ) * 1000;

    // tub's six relationship fields, from the bulk podsrel read above —
    // already plain resolved post IDs (or 0), no $pod->field()/scoop_cast()
    // needed. See scoop_bulk_tub_relationships().
    if ( $key === 'tub' ) {
      foreach ( [ 'batch', 'flavor', 'location', 'use', 'slot', 'closeout' ] as $rel_field ) {
        if ( ! array_key_exists( $rel_field, $spec_fields ) ) continue;
        $row[ $rel_field ] = $tub_relationships[ $id ][ $rel_field ] ?? 0;
      }

      // index — plain scalar, not a relationship, see the unset() comment
      // above. Direct $pod->row read, same as the row_fields loop.
      if ( array_key_exists( 'index', $spec_fields ) ) {
        $row['index'] = scoop_cast( $pod->row['index'] ?? null, $spec_fields['index'] );
      }
    }

    // inventory_change.tubs/.flavors, from the bulk podsrel read above —
    // already plain arrays of resolved post IDs in weight order, matching
    // scoop_relation_ids_out()'s own output shape. See
    // scoop_bulk_inventory_change_relationships().
    if ( $key === 'inventory_change' ) {
      foreach ( [ 'tubs', 'flavors' ] as $rel_field ) {
        if ( ! array_key_exists( $rel_field, $spec_fields ) ) continue;
        $row[ $rel_field ] = $ic_relationships[ $id ][ $rel_field ] ?? [];
      }
    }

    // Post fields from wp_posts columns already in $pod->row.
    // post_modified and post_date are omitted when zero, same as spec datetime fields.
    $t0 = microtime( true );
    foreach ( $post_fields as $field => $type ) {
      //scoop_debug_log( "Processing post field '{$field}' of type '{$type}' for {$key} ".($pod->row[$field] ?? 'null') );
      if ( $field === 'author_name' ) {
        // get_the_author_meta() caches per unique author — cheap for a small staff
        $row['author_name'] = scoop_text_out(
          get_the_author_meta( 'display_name', (int) ( $pod->row['post_author'] ?? 0 ) )
        );
      } elseif ( $field === 'editor_name' ) {
        // Not a Pods/post field — stamped as postmeta by scoop_stamp_tub_editor
        // (includes/hooks/tub-state.php) on every real edit. Display-only
        // fallback to the original author until a tub gets its first edit
        // post-deploy — deliberately NOT backfilled into the postmeta itself,
        // since that would make "never edited" indistinguishable from
        // "actually edited by the original author" once a real edit happens.
        $editor_id = (int) get_post_meta( $id, 'scoop_last_editor_id', true );
        $editor_id = $editor_id ?: (int) ( $pod->row['post_author'] ?? 0 );
        $row['editor_name'] = $editor_id
          ? scoop_text_out( get_the_author_meta( 'display_name', $editor_id ) )
          : '';
      } else{
        // post_modified/post_date (and any other non-author/editor
        // post_field) are plain wp_posts columns already sitting in
        // $pod->row for any post-type pod — not a Pods relationship at
        // all, nothing for $pod->field() to resolve. Same direct-row-read
        // pattern as the row_fields loop above. See performance.md #11/#13.
        $row[ $field ] = scoop_cast( $pod->row[ $field ] ?? null, $type );
      }
    }
    $t_post_fields_ms += ( microtime( true ) - $t0 ) * 1000;

    // Custom per-entity filter (e.g. tub state/DateActivity logic)
    if ( ! empty( $spec['filter'] ) && is_callable( $spec['filter'] ) ) {
      if ( ! $spec['filter']( $row, $ctx ) ) continue;
    }

    // PHP-side location guard for entity types where the SQL filter wasn't applied
    if ( $loc_id > 0 && ! $db_loc_applied && isset( $row['location'] ) && $key !== 'slot' ) {
      if ( (int) $row['location'] !== $loc_id ) continue;
    }

    $out[] = $row;
  }

  $row_count = count( $out );

  // Slots derive location from their parent cabinet — enriched in a single bulk query
  $t_enrich_ms = 0.0;
  if ( $key === 'slot' && ! empty( $out ) ) {
    $t0  = microtime( true );
    $out = scoop_enrich_slots_with_location( $out );
    $t_enrich_ms = ( microtime( true ) - $t0 ) * 1000;

    if ( $loc_id > 0 ) {
      $out = array_values( array_filter( $out, function ( $slot ) use ( $loc_id ) {
        return (int) ( $slot['location'] ?? 0 ) === $loc_id;
      } ) );
    }
  }

  $t_total_ms = ( microtime( true ) - $t_request_start ) * 1000;

  scoop_debug_log( sprintf(
    "scoop_fetch_entities('%s'): %d rows | find=%.1fms | rel_bulk=%.1fms | needs_field=%.1fms | post_fields=%.1fms | slot_enrich=%.1fms | TOTAL=%.1fms (%.2fms/row)",
    $key, $row_count, $t_find_ms, $t_rel_bulk_ms, $t_needs_field_ms, $t_post_fields_ms, $t_enrich_ms, $t_total_ms,
    $row_count ? $t_total_ms / $row_count : 0.0
  ) );

  if ( $entity_cache_key !== null ) {
    set_transient( $entity_cache_key, $out, SCOOP_ENTITY_CACHE_TTL );
  }

  return $out;
}

// ─────────────────────────────────────────────────────────────────────────────
// Slot enrichment — one bulk query for all cabinets
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Enrich slots with location from their parent cabinet.
 *
 * Uses a single find() with WHERE t.ID IN (...) to fetch all needed cabinets
 * at once, then resolves location via $pod->field() so Pods handles the
 * relationship correctly — same as every other int field in this schema.
 *
 * Before: N calls to pods('cabinet', $id) — one DB round-trip per cabinet.
 * After:  one find() call — one DB round-trip for all cabinets.
 */
function scoop_enrich_slots_with_location( array $slots ): array {

  $cabinet_ids = array_values( array_unique( array_filter( array_map(
    fn( $slot ) => scoop_rel_id( $slot['cabinet'] ?? null ),
    $slots
  ) ) ) );

  if ( empty( $cabinet_ids ) ) return $slots;

  $id_list = implode( ',', array_map( 'intval', $cabinet_ids ) );

  $cabinet_pod = pods( 'cabinet' );
  if ( ! $cabinet_pod ) return $slots;

  $cabinet_pod->find( [
    'limit' => count( $cabinet_ids ),
    'where' => "t.ID IN ({$id_list})",
  ] );

  $cabinet_locations = [];
  while ( $cabinet_pod->fetch() ) {
    $cab_id = (int) $cabinet_pod->id();
    // field() lets Pods resolve the location relationship ID correctly
    $cabinet_locations[ $cab_id ] = scoop_rel_id( $cabinet_pod->field( 'location' ) );
  }

  foreach ( $slots as &$slot ) {
    $cabinet_id       = scoop_rel_id( $slot['cabinet'] ?? null );
    $slot['location'] = $cabinet_locations[ $cabinet_id ] ?? 0;
  }
  unset( $slot );

  return $slots;
}

// ─────────────────────────────────────────────────────────────────────────────
// Bundle fetch dispatcher
// ─────────────────────────────────────────────────────────────────────────────

function scoop_bundle_fetch_type( string $needType, \WP_REST_Request $req, array $bundle_ctx = [] ): array {
  $ctx = [];

  $loc = $req->get_param( 'location' );
  if ( $loc !== null && $loc !== '' ) {
    $ctx['location'] = (int) $loc;
  }

  // Optional flag for audit/history views that need emptied tubs
  $include_empty = $req->get_param( 'include_empty_tubs' );
  if ( $include_empty !== null && $include_empty !== '' ) {
    $ctx['include_empty_tubs'] = (bool) $include_empty;
  }

  $modified_since = $req->get_param( 'modified_since' );
  if ( $modified_since !== null && $modified_since !== '' ) {
    $ctx['modified_since'] = (string) $modified_since;
  }

  $modified_range = $req->get_param( 'modified_range' );
  if ( $modified_range !== null && $modified_range !== '' ) {
    $ctx['modified_range'] = (string) $modified_range;
  }

  if ( ! empty( $bundle_ctx['requesting_types'] ) ) {
    $ctx['requesting_types'] = $bundle_ctx['requesting_types'];
  }

  $date_filter_context = $bundle_ctx['date_filter_context'] ?? scoop_bundle_date_filter_context( $req, $ctx['requesting_types'] ?? [] );
  $ctx['date_filters']       = $date_filter_context['enabled'];
  $ctx['date_filter_ranges'] = $date_filter_context['ranges'];

  // Only the entries that are actually reachable from bundle specs.
  // Capitalized keys like 'Flavor' => 'flavor' are never sent as needType —
  // those come from $specs[$t]['needs'] in bundle.php, which are all lowercase.
  // FlavorTub is the one real alias: the FlavorTub bundle needs tub records.
  $map = [
    'FlavorTub' => 'tub',
  ];

  $key = $map[ $needType ] ?? $needType;
  return scoop_fetch_entities( $key, $ctx );
}
