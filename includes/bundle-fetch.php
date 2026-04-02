<?php

function scoop_field_type($desc): string {
  if (is_string($desc)) return $desc; // backward compat
  if (is_array($desc)) {
    $t = $desc['data_type'] ?? $desc['type'] ?? 'string';
    return is_string($t) ? $t : 'string';
  }
  return 'string';
}

function scoop_text_out($v): string {
  if (is_array($v) || is_object($v)) return '';
  $s = (string) $v;
  $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
  $s = str_replace(["\u{2018}", "\u{2019}"], ["'", "'"], $s);

  return $s;
}

function scoop_post_names_out($v): array{
  $a = [];
  if (is_array($v)) foreach($v as $p){
    if (!empty($p['post_name'])) $a[] = $p['post_name'];
  }
  return $a;
}

function scoop_cast($v, $desc) {
  $type = scoop_field_type($desc);

  switch ($type) {
    case 'post_names':
      return scoop_post_names_out($v);

    case 'int':
      return scoop_rel_id($v);

    case 'float':
      if (is_array($v) || is_object($v)) return 0.0;
      return (float)$v;

    case 'string':
      return scoop_text_out($v);

    case 'bool':
      return (bool)$v;

    default:
      return $v;
  }
}

function scoop_fetch_entities(string $key, array $ctx = [], bool $fields_only = false ): array {
  error_log('-----------scoop_fetch_entities');
  $specs = scoop_entity_specs();
  if (empty($specs[$key])) return [];

  $spec = $specs[$key];
  if (!function_exists('pods')) return [];

  if( $fields_only ) return $spec['fields'];

  $post_type = $spec['post_type'];
  $pod_name  = $spec['pod'];
  $pod_write = $spec['writeable'] ?? [];
  if (is_callable($pod_write)) {
    $pod_write = (array) $pod_write(wp_get_current_user());
  }

  // Keep this big to avoid paging; we'll filter in PHP for now.
  // If you later confirm fields are stored in postmeta, you can add meta_query here.
  $ids = get_posts([
    'post_type'      => $post_type,
    'post_status'    => 'any',
    'posts_per_page' => 2000,
    'fields'         => 'ids',
    //'writeable'      => $pod_write,
    'no_found_rows'  => true,
  ]);

  $out = [];
  foreach ($ids as $id) {
    $pod = pods($pod_name, $id);
    if (!$pod || !$pod->exists()) continue;

    $row = [ 'id' => (int)$id ];

    if (!empty($spec['title'])) $row['_title'] = (string) get_post_field('post_title', $id, 'raw');

    foreach (($spec['fields'] ?? []) as $field => $desc) {
      $row[$field] = scoop_cast($pod->field($field), $desc);
    }

    // Handle post_fields
    $p = get_post($id);
    foreach (($spec['post_fields'] ?? []) as $field => $type) {
      if ($field === 'author_name') {
        $row['author_name'] = scoop_cast(
          get_the_author_meta('display_name', $p?->post_author ?? 0),
          'string'
        );
      } elseif ($field === 'post_modified') {
        $row['post_modified'] = $p?->post_modified ?? '';
      } elseif ($field === 'post_date') {
        $row['post_date'] = $p?->post_date ?? '';
      }
    }

    // Optional contextual filter
    if (!empty($spec['filter']) && is_callable($spec['filter'])) {
      if (!$spec['filter']($row, $ctx)) continue;
    }

    // Optional location filter (common)
    if (!empty($ctx['location']) && isset($row['location'])) {
      if ((int)$row['location'] !== (int)$ctx['location']) continue;
    }

    $out[] = $row;
  }

  // Enrich slots with location from parent cabinet
  if ($key === 'slot' && !empty($out)) {
    $out = scoop_enrich_slots_with_location($out);
  }

  return $out;
}

/**
 * Enrich slots with location from parent cabinet
 */
function scoop_enrich_slots_with_location(array $slots): array {
  // Extract unique cabinet IDs
  $cabinet_ids = array_unique(array_filter(array_map(function($slot) {
    return scoop_rel_id($slot['cabinet'] ?? null);
  }, $slots)));

  if (empty($cabinet_ids)) return $slots;

  // Batch fetch cabinet locations
  $cabinet_locations = [];
  foreach ($cabinet_ids as $cab_id) {
    $cabinet = pods('cabinet', $cab_id);
    if ($cabinet && $cabinet->exists()) {
      $location_id = scoop_rel_id($cabinet->field('location'));
      $cabinet_locations[$cab_id] = $location_id;
    }
  }

  // Enrich each slot with its cabinet's location
  foreach ($slots as &$slot) {
    $cabinet_id = scoop_rel_id($slot['cabinet'] ?? null);
    $slot['location'] = $cabinet_locations[$cabinet_id] ?? 0;
  }
  unset($slot); // Break reference

  return $slots;
}

function scoop_bundle_fetch_type(string $needType, \WP_REST_Request $req, array $bundle_ctx = []): array {
    $ctx = [];

    $loc = $req->get_param('location');
    if ($loc !== null && $loc !== '') $ctx['location'] = (int) $loc;
    
    // THIS LINE MUST BE HERE
    if (!empty($bundle_ctx['requesting_types'])) {
        $ctx['requesting_types'] = $bundle_ctx['requesting_types'];
    }
    
    error_log("scoop_bundle_fetch_type: needType={$needType}, ctx=" . json_encode($ctx));  // ← ADD THIS
    
    $key = $map[$needType] ?? $needType;
    return scoop_fetch_entities($key, $ctx);
}