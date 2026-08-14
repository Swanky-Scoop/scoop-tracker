<?php

if (!defined('ABSPATH')) exit;

function scoop_nodate( $value ) {
	return ( 
		$value === null || 
		$value === '' || 
		$value === '0000-00-00 00:00:00' || 
		$value === '0000-00-00' 
	); 
}

/**
 * Extract a single related item ID from a Pods relationship field value.
 * Handles numeric, [id], [{id:..}], [{ID:..}], etc.
 */
function scoop_rel_id( $val ) {
    if ( empty( $val ) ) return 0;

    // If it’s already an ID
    if ( is_numeric( $val ) ) return (int) $val;

    // If it’s an array, get the first meaningful thing
    if ( is_array( $val ) ) {
        $first = reset( $val );
        if ( is_numeric( $first ) ) return (int) $first;

        if ( is_array( $first ) ) {
            if ( isset( $first['id'] ) && is_numeric( $first['id'] ) ) return (int) $first['id'];
            if ( isset( $first['ID'] ) && is_numeric( $first['ID'] ) ) return (int) $first['ID'];
        }

        if ( is_object( $first ) ) {
            if ( isset( $first->id ) && is_numeric( $first->id ) ) return (int) $first->id;
            if ( isset( $first->ID ) && is_numeric( $first->ID ) ) return (int) $first->ID;
        }
    }

    // If it’s an object
    if ( is_object( $val ) ) {
        if ( isset( $val->id ) && is_numeric( $val->id ) ) return (int) $val->id;
        if ( isset( $val->ID ) && is_numeric( $val->ID ) ) return (int) $val->ID;
    }

    // If it’s a string that contains an ID somewhere (rare but happens)
    if ( is_string( $val ) ) {
        $trim = trim( $val );
        if ( ctype_digit( $trim ) ) return (int) $trim;
    }

    return 0;
}

/**
 * Save fields to a Pods item via Pods API (fires Pods pre_save/post_save hooks).
 *
 * @param string $pod   Pod name (e.g. 'tub')
 * @param int    $id    Item ID
 * @param array  $data  Field values, e.g. ['state' => 'Opened']
 * @return mixed        Result from Pods API or false on failure
 */
function scoop_pods_api_save( string $pod_name, $id, array $data ) {
  $pod_name = trim((string)$pod_name);
  $id = (int)$id;

  if ($pod_name === '' || $id <= 0 || empty($data)) return false;
  if (!function_exists('pods_api') || !is_object(pods_api())) return false;

  $clean = [];
  foreach ($data as $field => $value) {
    $field = (string)$field;
    $clean[$field] = scoop_coerce_value($field, $value);
  }

  return pods_api()->save_pod_item([
    'pod'  => $pod_name,
    'id'   => $id,
    'data' => $clean,
  ]);
}

function scoop_coerce_value(string $field, $value) {

  // string enums
  if (in_array($field, ['state'], true)) {
    return (string)$value;
  }

  // integer relationship ids + integer-valued fields
  if (in_array($field, [
    'current_flavor',
    'immediate_flavor',
    'next_flavor',
    'flavor',
    'use',
    'location',
    'order',
  ], true)) {
    return (int)$value;
  }

  // fractional numeric fields — tubs_emptied can be 3.5 (three whole tubs +
  // one half tub), so it MUST be stored as float. Casting to int here truncated
  // half-tub closeouts, so a 3.5-tub closeout would persist as 3 and the
  // fractional tub would never be marked Emptied downstream.
  if(in_array($field, [
    'count',
    'amount',
    'tubs_emptied',
  ], true)) {
    return (float)$value;
  }

  // default: leave as-is
  return $value;
}

function scoop_pods_ready(): bool {
  return function_exists('pods_api') && is_object(pods_api());
}

// Live field-name list for a pod — used where a route's writeable-field
// list should track whatever fields actually exist in Pods admin right now
// rather than a hand-maintained array that drifts out of sync (see
// WHITEBOARD-INGESTION.md — shift_report/cake_order's writeable lists used
// to be hardcoded in _specs.php and needed a code change every time a field
// was added in Pods admin). Memoized per-request; Pods field lists don't
// change mid-request.
function scoop_pod_field_names(string $pod_name): array {
  static $cache = [];
  if (isset($cache[$pod_name])) return $cache[$pod_name];

  if (!scoop_pods_ready()) return [];
  $pod = pods_api()->load_pod(['name' => $pod_name]);
  if (!$pod || empty($pod['fields'])) return $cache[$pod_name] = [];

  return $cache[$pod_name] = array_keys($pod['fields']);
}

function scoop_pods_field_def(string $pod_name, string $field_name) /* no : array */ {
  if (!scoop_pods_ready()) return [];

  $pod = pods_api()->load_pod(['name' => $pod_name]);
  if (!$pod) return [];

  $fields = $pod['fields'] ?? null;
  if (!is_array($fields) || empty($fields[$field_name])) return [];

  $field = $fields[$field_name];

  // New Pods: field definitions may be Pods\Whatsit\Field objects
  if (is_object($field)) {
    if (method_exists($field, 'export')) {
      $arr = $field->export();
      return is_array($arr) ? $arr : [];
    }
    if (method_exists($field, 'to_array')) {
      $arr = $field->to_array();
      return is_array($arr) ? $arr : [];
    }
    // Last resort: try public properties (often not useful, but avoids fatal)
    return (array)$field;
  }

  return is_array($field) ? $field : [];
}

function scoop_pods_dropdown_options(string $pod_name, string $field_name): array {
  static $cache = [];
  $k = $pod_name . ':' . $field_name;
  if (isset($cache[$k])) return $cache[$k];

  $field = scoop_pods_field_def($pod_name, $field_name);

  // pick_custom/choices live at the TOP level of the field definition
  // array, not nested under an 'options' key — confirmed directly against
  // real field defs (tub.state, shift_report.change_low) via the local
  // PHP CLI 2026-08-11: neither has an 'options' key at all. This was
  // previously read from $field['options'][...], which silently always
  // returned [] — closeout.php's scoop_closeout_tub_where() already had a
  // defensive fallback for exactly this ('Fallback if helper fails'), so
  // the practical impact was "always uses the static fallback state list,
  // never actually reads Pods" rather than a visible bug — but the
  // fallback masked a real bug in this helper for anyone else relying on
  // "dynamic!" actually being dynamic.
  $opts = is_array($field) ? $field : [];

  $out = [];

  // Pods “pick_custom” format: newline-separated, optional "key|label"
  if (!empty($opts['pick_custom']) && is_string($opts['pick_custom'])) {
    $lines = preg_split("/\r\n|\r|\n/", trim($opts['pick_custom']));
    foreach ($lines as $line) {
      $line = trim($line);
      if ($line === '') continue;

      if (strpos($line, '|') !== false) {
        [$key, $label] = array_map('trim', explode('|', $line, 2));
      } else {
        $key = $label = $line;
      }

      $out[] = ['key' => (string)$key, 'label' => (string)$label];
    }

  // Some field types store options as "choices" map
  } elseif (!empty($opts['choices']) && is_array($opts['choices'])) {
    foreach ($opts['choices'] as $key => $label) {
      $out[] = ['key' => (string)$key, 'label' => (string)$label];
    }
  }

  return $cache[$k] = $out;
}


