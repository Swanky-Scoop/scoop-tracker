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

function scoop_cast( $v, $desc ) {
  $type = scoop_field_type( $desc );
  switch ( $type ) {
    case 'post_names': return scoop_post_names_out( $v );
    case 'int':        return scoop_rel_id( $v );
    case 'float':      return ( is_array( $v ) || is_object( $v ) ) ? 0.0 : (float) $v;
    case 'string':     return scoop_text_out( $v );
    case 'bool':       return (bool) $v;
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
    // Exclude emptied tubs at the DB level unless the caller explicitly wants them.
    // This is the biggest single filter on the tub table and saves the most work.
    $include_empty = ! empty( $ctx['include_empty_tubs'] );
    if ( ! $include_empty ) {
      $where_clauses[] = "state != 'Emptied'";
    }

    // DateActivity-only tub bundles should not scan every historical tub.
    // When other grids are present, keep active tubs in scope for their views.
    $requesting_types   = $ctx['requesting_types'] ?? [];
    $has_date_activity = in_array( 'DateActivity', $requesting_types, true );
    $has_other_grids   = ! empty( array_diff( $requesting_types, [ 'DateActivity' ] ) );
    $modified_since_ts = strtotime( $ctx['modified_since'] ?? '' );

    if ( $has_date_activity && ! $has_other_grids && $modified_since_ts ) {
      $modified_since_sql = esc_sql( date( 'Y-m-d H:i:s', $modified_since_ts ) );
      $where_clauses[] = "t.post_modified >= '{$modified_since_sql}'";
    }

    // Push location into SQL for tubs — location is a plain int column here.
    if ( $loc_id > 0 && array_key_exists( 'location', $spec_fields ) ) {
      $where_clauses[] = "location = {$loc_id}";
      $db_loc_applied  = true;
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

      if ( $type === 'datetime' && scoop_nodate( $raw ) ) continue;

      $row[ $field ] = scoop_cast( $raw, $desc );
    }

    // Relationship + unknown-typed fields — resolved by Pods
    foreach ( $needs_field as $field => $desc ) {
      $row[ $field ] = scoop_cast( $pod->field( $field ), $desc );
    }

    // Post fields from wp_posts columns already in $pod->row.
    // post_modified and post_date are omitted when zero, same as spec datetime fields.
    foreach ( $post_fields as $field => $type ) {
      if ( $field === 'author_name' ) {
        // get_the_author_meta() caches per unique author — cheap for a small staff
        $row['author_name'] = scoop_text_out(
          get_the_author_meta( 'display_name', (int) ( $pod->row['post_author'] ?? 0 ) )
        );
      } elseif ( $field === 'post_modified' ) {
        $val = $pod->row['post_modified'] ?? null;
        if ( ! scoop_nodate( $val ) ) $row['post_modified'] = $val;
      } elseif ( $field === 'post_date' ) {
        $val = $pod->row['post_date'] ?? null;
        if ( ! scoop_nodate( $val ) ) $row['post_date'] = $val;
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
