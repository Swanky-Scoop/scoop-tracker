<?php
/**
 * Cabinet + Slot hooks (Pods-native, guard-safe)
 *
 * Responsibilities:
 * 1) Cabinet title/slug derived from:
 *    - location title
 *    - whether prohibited_allergens includes "dairy" (slug)
 *    - max_tubs
 * 2) After cabinet save, create Slot items ONCE (if none exist for this cabinet).
 *
 * Assumptions:
 * - Pod: cabinet
 *   - fields: location (rel), prohibited_allergens (rel multi), max_tubs (number)
 * - Pod: slot
 *   - fields: cabinet (rel), location (rel), index (number)
 *
 * Dependencies:
 * - scoop_rel_id($val) helper (relationship normalization)
 * - Optional: scoop_get_default_location_id()
 */

/* ------------------------------------------------------------
 * Guards / logging helpers
 * ------------------------------------------------------------ */

function scoop_guard_enter(string $key): bool {
  if (!isset($GLOBALS['scoop_guard'])) $GLOBALS['scoop_guard'] = [];
  if (!empty($GLOBALS['scoop_guard'][$key])) return false;
  $GLOBALS['scoop_guard'][$key] = true;
  return true;
}
function scoop_guard_leave(string $key): void {
  if (isset($GLOBALS['scoop_guard'][$key])) unset($GLOBALS['scoop_guard'][$key]);
}
function scoop_mark_active(array &$pieces, string $slug): void {
  if (!isset($pieces['fields_active']) || !is_array($pieces['fields_active'])) $pieces['fields_active'] = [];
  if (!in_array($slug, $pieces['fields_active'], true)) $pieces['fields_active'][] = $slug;
}

/* ------------------------------------------------------------
 * Cabinet: title + slug
 * ------------------------------------------------------------ */

add_filter('pods_api_pre_save_pod_item_cabinet', 'scoop_cabinet_pre_save_title', 10, 2);
function scoop_cabinet_pre_save_title($pieces, $is_new_item) {
  $cabinet_id = !empty($pieces['id']) ? (int)$pieces['id'] : 0;

  // Resolve location_id: prefer incoming, else DB
  $location_id = 0;
  if (isset($pieces['fields']['location']['value'])) {
    $location_id = function_exists('scoop_rel_id')
      ? (int)scoop_rel_id($pieces['fields']['location']['value'])
      : (int)(is_array($pieces['fields']['location']['value']) ? reset($pieces['fields']['location']['value']) : $pieces['fields']['location']['value']);
  }
  $cabinet_pod = null;
  if (!$location_id && $cabinet_id) {
    $cabinet_pod = pods('cabinet', $cabinet_id);
    if ($cabinet_pod && $cabinet_pod->exists()) {
      $location_id = (int)$cabinet_pod->field('location.ID');
    }
  }

  if (!$location_id) {
    // No location; do not compute title.
    return $pieces;
  }

  $location_name = get_the_title($location_id);
  if (!$location_name) $location_name = 'UnknownLocation';

  // Resolve max_tubs: prefer incoming, else DB
  $max_tubs = 0;
  if (isset($pieces['fields']['max_tubs']['value']) && is_numeric($pieces['fields']['max_tubs']['value'])) {
    $max_tubs = (int)$pieces['fields']['max_tubs']['value'];
  } elseif ($cabinet_pod instanceof Pods) {
    $max_tubs = (int)$cabinet_pod->field('max_tubs');
  }

  // Resolve allergens: prefer incoming, else DB
  $allergen_ids = [];
  if (isset($pieces['fields']['prohibited_allergens']['value'])) {
    $val = $pieces['fields']['prohibited_allergens']['value'];
    if (is_numeric($val)) {
      $allergen_ids = [(int)$val];
    } elseif (is_array($val)) {
      // may be [id,id] or [{ID:..},...]
      foreach ($val as $v) {
        $allergen_ids[] = function_exists('scoop_rel_id') ? (int)scoop_rel_id($v) : (int)(is_array($v) ? ($v['ID'] ?? ($v['id'] ?? 0)) : $v);
      }
      $allergen_ids = array_values(array_filter(array_map('intval', $allergen_ids)));
    }
  } elseif ($cabinet_pod instanceof Pods) {
    $allergen_ids = (array)$cabinet_pod->field('prohibited_allergens', ['output' => 'ids']);
  }

  // Does it include "dairy" slug?
  $has_dairy = false;
  foreach ($allergen_ids as $aid) {
    $aid = (int)$aid;
    if (!$aid) continue;
    $slug = get_post_field('post_name', $aid);
    if (is_string($slug) && strtolower($slug) === 'dairy') { $has_dairy = true; break; }
  }

  $suffix = $has_dairy ? '_restricted_' : '_dairy_';
  $title  = $location_name . $suffix . (int)$max_tubs;

  // Mark object fields active + set them
  scoop_mark_active($pieces, 'post_title');

  if (!isset($pieces['object_fields']) || !is_array($pieces['object_fields'])) $pieces['object_fields'] = [];
  if (!isset($pieces['object_fields']['post_title']) || !is_array($pieces['object_fields']['post_title'])) $pieces['object_fields']['post_title'] = [];
  $pieces['object_fields']['post_title']['value'] = $title;

  // Keep slug in sync (Pods will accept post_name when present)
  if (!function_exists('sanitize_title')) require_once ABSPATH . 'wp-includes/formatting.php';
  if (!isset($pieces['object_fields']['post_name']) || !is_array($pieces['object_fields']['post_name'])) $pieces['object_fields']['post_name'] = [];
  $pieces['object_fields']['post_name']['value'] = sanitize_title($title);

  return $pieces;
}

/* ------------------------------------------------------------
 * Cabinet post-save: create slot once
 * ------------------------------------------------------------ */

add_filter('pods_api_post_save_pod_item_cabinet', 'scoop_cabinet_post_save_create_slots', 10, 3);
function scoop_cabinet_post_save_create_slots($pieces, $is_new_item, $id) {
  $cabinet_id = (int)$id;
  if (!$cabinet_id) return $pieces;

  $guard_key = "cabinet:create_slots:{$cabinet_id}";
  if (!scoop_guard_enter($guard_key)) return $pieces;

  try {
    // If any slot exist for this cabinet, do nothing.
    $existing = pods('slot', [
      'where' => 'cabinet.ID = ' . $cabinet_id,
      'limit' => 1,
    ]);
    if ($existing && $existing->total() > 0) return $pieces;

    // Read canonical data from DB (NOT $pieces)
    $cabinet = pods('cabinet', $cabinet_id);
    if (!$cabinet || !$cabinet->exists()) return $pieces;

    $max_tubs = (int)$cabinet->field('max_tubs');
    if ($max_tubs <= 0) return $pieces;

    $location_id = (int)$cabinet->field('location.ID');
    if (!$location_id && function_exists('scoop_get_default_location_id')) {
      $location_id = (int)scoop_get_default_location_id();
    }

    $cabinet_title = get_the_title($cabinet_id);
    if (!$cabinet_title) $cabinet_title = 'Cabinet ' . $cabinet_id;

    if (!function_exists('pods_api') || !is_object(pods_api())) 
      return $pieces;

    $created = 0;

    for ($i = 1; $i <= $max_tubs; $i++) {
      $slot_title = $cabinet_title . '|' . $i;

      $data = [
        'post_title'  => $slot_title,
        'post_status' => 'publish',
        'cabinet'     => $cabinet_id,
        'index'       => $i,
      ];
      if ($location_id) $data['location'] = $location_id;

      // Pods-native create (fires Pods hooks)
      $new_slot_id = pods_api()->save_pod_item([
        'pod'  => 'slot',
        'data' => $data,
      ]);

      if (is_wp_error($new_slot_id)) continue;
      if ($new_slot_id) $created++;
    }

    return $pieces;

  } finally {
    scoop_guard_leave($guard_key);
  }
}

/* ------------------------------------------------------------
 * Slot: current_flavor/immediate_flavor/next_flavor uniqueness
 * ------------------------------------------------------------
 * A flavor may hold at most one designation (current/immediate/next)
 * across the whole slot table at a time. Assigning a flavor to one slot's
 * designation field clears it from wherever else it was already
 * designated: a different field on the SAME slot (pre-save, by mutating
 * $pieces before it's written) or any OTHER slot (post-save, once this
 * slot's own save is confirmed, since that's a genuinely separate row).
 *
 * Pods-level hooks, not REST-layer logic — applies regardless of write
 * path: the Cabinet grid's /planning route, CabinetWorkflow's tub-swap/
 * promote writes (Confirm Cabinet, flavor picker), WP admin, or a direct
 * Pods call.
 * ------------------------------------------------------------ */

function scoop_slot_designation_fields(): array {
  return ['current_flavor', 'immediate_flavor', 'next_flavor'];
}

add_filter('pods_api_pre_save_pod_item_slot', 'scoop_slot_pre_save_dedupe_own_fields', 10, 2);
function scoop_slot_pre_save_dedupe_own_fields($pieces, $is_new_item) {
  $fields  = scoop_slot_designation_fields();
  $this_id = !empty($pieces['id']) ? (int) $pieces['id'] : 0;

  // Which designation fields is THIS save actually setting, and to what
  // flavor? Only positive incoming values matter — clearing a field to 0
  // has nothing to dedupe.
  $incoming = [];
  foreach ($fields as $f) {
    if (!isset($pieces['fields'][$f]['value'])) continue;
    $fid = function_exists('scoop_rel_id')
      ? (int) scoop_rel_id($pieces['fields'][$f]['value'])
      : (int) $pieces['fields'][$f]['value'];
    if ($fid > 0) $incoming[$f] = $fid;
  }
  if (!$incoming) return $pieces;

  $slot_pod = null; // lazy DB lookup, at most once per save
  foreach ($fields as $f) {
    if (!isset($incoming[$f])) continue;
    $fid = $incoming[$f];

    foreach ($fields as $other) {
      if ($other === $f) continue;

      if (array_key_exists($other, $incoming)) {
        // Both fields set in THIS SAME save to the SAME flavor — field
        // priority order (current > immediate > next) decides which one
        // keeps it; the later field is cleared.
        if ($incoming[$other] === $fid && array_search($f, $fields, true) < array_search($other, $fields, true)) {
          $pieces['fields'][$other]['value'] = 0;
        }
        continue;
      }

      // $other isn't part of this save (unchanged) — check its current DB
      // value instead.
      if (!$this_id) continue;
      $slot_pod ??= pods('slot', $this_id);
      if ($slot_pod && $slot_pod->exists() && (int) $slot_pod->field($other . '.ID') === $fid) {
        $pieces['fields'][$other] = ['value' => 0];
        scoop_mark_active($pieces, $other);
      }
    }
  }

  return $pieces;
}

add_filter('pods_api_post_save_pod_item_slot', 'scoop_slot_post_save_dedupe_other_slots', 10, 3);
function scoop_slot_post_save_dedupe_other_slots($pieces, $is_new_item, $id) {
  $slot_id = (int) $id;
  if (!$slot_id) return $pieces;
  if (!function_exists('pods_api') || !is_object(pods_api())) return $pieces;

  $fields = scoop_slot_designation_fields();
  $slot   = pods('slot', $slot_id);
  if (!$slot || !$slot->exists()) return $pieces;

  foreach ($fields as $f) {
    $fid = (int) $slot->field($f . '.ID');
    if (!$fid) continue;

    $where_parts = [];
    foreach ($fields as $of) $where_parts[] = "{$of}.ID = {$fid}";
    $where = '(' . implode(' OR ', $where_parts) . ") AND t.ID != {$slot_id}";

    $matches = pods('slot', ['where' => $where, 'limit' => -1]);
    if (!$matches) continue;

    while ($matches->fetch()) {
      $other_id  = (int) $matches->field('ID');
      $guard_key = "slot:dedupe:{$other_id}";
      if (!scoop_guard_enter($guard_key)) continue;

      try {
        $clear = [];
        foreach ($fields as $of) {
          if ((int) $matches->field($of . '.ID') === $fid) $clear[$of] = 0;
        }
        if ($clear) {
          pods_api()->save_pod_item(['pod' => 'slot', 'id' => $other_id, 'data' => $clear]);
        }
      } finally {
        scoop_guard_leave($guard_key);
      }
    }
  }

  return $pieces;
}