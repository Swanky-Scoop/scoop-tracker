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

/* ------------------------------------------------------------
 * Tub-moving (worktree-tub-moving): auto-earmark a tub from elsewhere
 * as moving_to this slot's destination, whenever current_flavor/
 * immediate_flavor is scheduled here and this location has no
 * front-of-house-eligible stock of that flavor yet.
 *
 * Pods-level hook (not REST-layer logic), same reasoning as the
 * designation-uniqueness hooks above — applies regardless of which write
 * path set current_flavor/immediate_flavor (Cabinet grid, CabinetWorkflow,
 * WP admin, direct Pods call).
 * ------------------------------------------------------------ */

if (!defined('SCOOP_FRONT_OF_HOUSE_USE_ID')) {
  // Matches FRONT_OF_HOUSE_USE_ID in assets/models/cabinet-workflow-grid-model.js
  // — same real Pods post id on this environment, kept in sync by hand.
  define('SCOOP_FRONT_OF_HOUSE_USE_ID', 1863);
}

add_filter('pods_api_post_save_pod_item_slot', 'scoop_slot_post_save_mark_tub_moving', 20, 3);
function scoop_slot_post_save_mark_tub_moving($pieces, $is_new_item, $id) {
  $slot_id = (int) $id;
  if (!$slot_id) return $pieces;
  if (!function_exists('pods_api') || !is_object(pods_api())) return $pieces;

  // Only the two fields that mean "this flavor is now actually wanted
  // here" — next_flavor is a further-out plan, not yet acted on, so it
  // doesn't trigger a move.
  $incoming = [];
  foreach (['current_flavor', 'immediate_flavor'] as $f) {
    if (!isset($pieces['fields'][$f]['value'])) continue;
    $fid = (int) scoop_rel_id($pieces['fields'][$f]['value']);
    if ($fid > 0) $incoming[] = $fid;
  }
  if (!$incoming) return $pieces;

  $slot = pods('slot', $slot_id);
  if (!$slot || !$slot->exists()) return $pieces;

  $cabinet_id = (int) $slot->field('cabinet.ID');
  if (!$cabinet_id) return $pieces;

  $destination_id = (int) pods('cabinet', $cabinet_id)->field('location.ID');
  if (!$destination_id) return $pieces;

  foreach (array_unique($incoming) as $flavor_id) {
    scoop_mark_tub_moving_if_needed($flavor_id, $destination_id);
  }

  return $pieces;
}

/**
 * If $destination_id has no front-of-house-eligible stock of $flavor_id
 * already — in place, or already earmarked to arrive — earmarks ONE
 * eligible tub from elsewhere by setting its moving_to. No-ops if nothing
 * eligible exists elsewhere either — this only ever flags a real
 * candidate, never invents stock.
 *
 * Eligibility mirrors CabinetWorkflowGridModel.promotablePool() (JS):
 * front-of-house use, not Emptied/Opened/!Lost, not already earmarked
 * elsewhere. Deliberately not scoped to Woodinville specifically — any
 * OTHER location's stock is a valid source, same "tubs can be carried
 * between this shop's own locations" philosophy as promotablePool's own
 * comment.
 */
function scoop_mark_tub_moving_if_needed(int $flavor_id, int $destination_id): void {
  if (!$flavor_id || !$destination_id || !function_exists('pods')) return;

  $tubs = pods('tub', [
    'where' => "flavor.ID = {$flavor_id}",
    'limit' => -1,
  ]);
  if (!$tubs) return;

  $already_satisfied = false;
  $candidates = []; // [id, created_on] elsewhere, eligible to move

  while ($tubs->fetch()) {
    $use_id   = (int) scoop_rel_id($tubs->field('use'));
    $is_front = !$use_id || $use_id === SCOOP_FRONT_OF_HOUSE_USE_ID;
    if (!$is_front) continue;

    $state = (string) $tubs->field('state');
    if (in_array($state, ['Emptied', '!Lost'], true)) continue;

    $loc_id    = (int) scoop_rel_id($tubs->field('location'));
    $moving_to = (int) scoop_rel_id($tubs->field('moving_to'));

    if ($loc_id === $destination_id || $moving_to === $destination_id) {
      $already_satisfied = true;
      break;
    }

    if ($state === 'Opened') continue; // already in service elsewhere — not a candidate to relocate
    if ($moving_to) continue;          // already earmarked to move somewhere else

    $candidates[] = [
      'id'         => (int) $tubs->id(),
      'created_on' => (string) $tubs->field('created_on'),
    ];
  }

  if ($already_satisfied || empty($candidates)) return;

  usort($candidates, fn($a, $b) => strcmp($a['created_on'], $b['created_on']));
  $chosen = $candidates[0];

  $data = ['moving_to' => $destination_id];

  // Additive alongside moving_to (2026-08-31 design conversation) — claim
  // the same chosen tub against the (destination, flavor) demand's
  // flavor_request row, auto-creating it if this demand has never been
  // requested/claimed before. tub.flavor_request is the forward field this
  // writes; flavor_request.tubs (its reverse list) is populated by Pods'
  // own sister sync once that pairing is configured — see both fields'
  // schema descriptions for why this never reads that list back.
  $request_id = scoop_find_or_create_flavor_request($destination_id, $flavor_id);
  if ($request_id) $data['flavor_request'] = $request_id;

  pods_api()->save_pod_item(['pod' => 'tub', 'id' => $chosen['id'], 'data' => $data]);
}

/**
 * Find-or-create the flavor_request row for (location, flavor) — same
 * upsert-by-pair shape as scoop_handle_debt_requests_post() (rest.php),
 * duplicated rather than shared because that function is REST-request
 * shaped (parses a payload, returns a WP_REST_Response) and this call site
 * has neither. Returns 0 (never writes) if pods()/pods_api() aren't
 * available.
 */
function scoop_find_or_create_flavor_request(int $location_id, int $flavor_id): int {
  if (!$location_id || !$flavor_id || !function_exists('pods') || !function_exists('pods_api')) return 0;

  $existing = pods('flavor_request', [
    'where' => "location.ID = {$location_id} AND flavor.ID = {$flavor_id}",
    'limit' => 1,
  ]);
  if ($existing && $existing->total() > 0) {
    $existing->fetch();
    return (int) $existing->id();
  }

  $title = sprintf(
    '%s | %s',
    get_the_title($location_id) ?: "Location {$location_id}",
    get_the_title($flavor_id)   ?: "Flavor {$flavor_id}",
  );

  $new_id = pods_api()->save_pod_item([
    'pod'  => 'flavor_request',
    'data' => [
      'post_title' => $title,
      'post_status' => 'publish',
      'location'   => $location_id,
      'flavor'     => $flavor_id,
    ],
  ]);

  return is_wp_error($new_id) ? 0 : (int) $new_id;
}

/**
 * Retroactive counterpart to scoop_slot_post_save_mark_tub_moving() above.
 * That hook only ever earmarks at the moment a slot's flavor is scheduled —
 * if demand already existed with no donor tub anywhere, it no-ops and
 * nothing re-checks once a donor later appears (confirmed live on the
 * local mirror 2026-08-30: 6 real slots stuck exactly this way). This scans
 * every slot currently wanting $flavor_id and re-runs
 * scoop_mark_tub_moving_if_needed() per destination — safe to call any
 * time a tub of this flavor could have newly become eligible, since that
 * function already no-ops per destination once it's satisfied.
 *
 * Guarded per-flavor: scoop_mark_tub_moving_if_needed()'s own
 * pods_api()->save_pod_item() call re-enters this same reconciliation via
 * the post-save tub hook below — the already_satisfied check makes that
 * self-terminating on its own, but the guard avoids the wasted rescan.
 */
function scoop_reconcile_moving_for_flavor(int $flavor_id): void {
  if (!$flavor_id || !function_exists('pods') || !function_exists('pods_api')) return;

  $guard_key = "reconcile_moving:{$flavor_id}";
  if (!scoop_guard_enter($guard_key)) return;

  try {
    $destinations = [];
    $slots = pods('slot', ['limit' => -1]);
    if (!$slots) return;

    while ($slots->fetch()) {
      $wants = false;
      foreach (['current_flavor', 'immediate_flavor'] as $f) {
        if ((int) scoop_rel_id($slots->field($f)) === $flavor_id) { $wants = true; break; }
      }
      if (!$wants) continue;

      $cabinet_id = (int) $slots->field('cabinet.ID');
      if (!$cabinet_id) continue;
      $destination_id = (int) pods('cabinet', $cabinet_id)->field('location.ID');
      if ($destination_id) $destinations[$destination_id] = true;
    }

    foreach (array_keys($destinations) as $destination_id) {
      scoop_mark_tub_moving_if_needed($flavor_id, $destination_id);
    }
  } finally {
    scoop_guard_leave($guard_key);
  }
}

/**
 * General safety net: any tub save that could make it newly eligible
 * (created, un-emptied, use flipped to front-of-house, moving_to cleared,
 * location changed, ...) re-checks demand for its flavor. Cheap — slot
 * counts are small — and idempotent by construction.
 */
add_filter('pods_api_post_save_pod_item_tub', 'scoop_tub_post_save_check_demand', 20, 3);
function scoop_tub_post_save_check_demand($pieces, $is_new_item, $id) {
  $tub_id = (int) $id;
  if (!$tub_id || !function_exists('pods')) return $pieces;

  $tub = pods('tub', $tub_id);
  if (!$tub || !$tub->exists()) return $pieces;

  $flavor_id = (int) $tub->field('flavor.ID');
  if ($flavor_id) scoop_reconcile_moving_for_flavor($flavor_id);

  return $pieces;
}
