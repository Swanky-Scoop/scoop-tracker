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
 */
function scoop_file_url_out( $v ): string {
  if ( empty( $v ) ) return '';

  if ( is_array( $v ) ) {
    if ( isset( $v[0] ) && is_array( $v[0] ) ) $v = $v[0];
    if ( ! empty( $v['guid'] ) ) return scoop_text_out( $v['guid'] );
    if ( ! empty( $v['ID'] ) ) return (string) ( wp_get_attachment_url( (int) $v['ID'] ) ?: '' );
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
// Core fetch
// ─────────────────────────────────────────────────────────────────────────────

function scoop_fetch_entities( string $key, array $ctx = [], bool $fields_only = false ): array {
  error_log( "Fetching entities of type '{$key}' with context: " . json_encode( $ctx ) );
  $specs = scoop_entity_specs();
  if ( empty( $specs[ $key ] ) ) return [];

  $spec = $specs[ $key ];
  if ( ! function_exists( 'pods' ) ) return [];

  if ( $fields_only ) return $spec['fields'] ?? [];

  $pod_name    = $spec['pod'];
  $spec_fields = $spec['fields']      ?? [];
  $post_fields = $spec['post_fields'] ?? [];

  [ $row_fields, $needs_field ] = scoop_classify_fetch_fields( $spec_fields );

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

    // InstockFlavor's Details drill-down needs to resolve titles for every
    // tub id in flavor.tubs, including long-emptied ones — exclude it from
    // the "active only" trimming below.
    $wants_all_tubs = in_array( 'InstockFlavor', $requesting_types, true );

    // Exclude emptied tubs at the DB level unless the caller explicitly wants them.
    // This is the biggest single filter on the tub table and saves the most work.
    $include_empty = ! empty( $ctx['include_empty_tubs'] ) || $wants_all_tubs;
    if ( ! $include_empty ) {
      $where_clauses[] = "state != 'Emptied'";
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

  $find_params = [
    'limit'   => -1,
    'orderby' => 'post_date',
    'order'   => 'ASC',
    'where'   => implode( ' AND ', $where_clauses ),
  ];

  $pod = pods( $pod_name );
  if ( ! $pod ) return [];

  $pod->find( $find_params );

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
    foreach ( $needs_field as $field => $desc ) {
      
      //error_log( "==> Processing field '{$field}' of type '{$desc}' for {$key} ID {$id}" );
      $row[ $field ] = scoop_cast( $pod->field( $field ), $desc );
    }

    // Post fields from wp_posts columns already in $pod->row.
    // post_modified and post_date are omitted when zero, same as spec datetime fields.
    foreach ( $post_fields as $field => $type ) {
      error_log( "Processing post field '{$field}' of type '{$type}' for {$key} ".($pod->row[$field] ?? 'null') );
      if ( $field === 'author_name' ) {
        // get_the_author_meta() caches per unique author — cheap for a small staff
        $row['author_name'] = scoop_text_out(
          get_the_author_meta( 'display_name', (int) ( $pod->row['post_author'] ?? 0 ) )
        );
      } else{
        $row[ $field ] = scoop_cast( $pod->field( $field ), $type );
      }
    }

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

  // Slots derive location from their parent cabinet — enriched in a single bulk query
  if ( $key === 'slot' && ! empty( $out ) ) {
    $out = scoop_enrich_slots_with_location( $out );

    if ( $loc_id > 0 ) {
      $out = array_values( array_filter( $out, function ( $slot ) use ( $loc_id ) {
        return (int) ( $slot['location'] ?? 0 ) === $loc_id;
      } ) );
    }
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
