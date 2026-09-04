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

/* ------------------------------------------------------------
 * Schema-readiness guards (same doctrine as scoop_flavor_request_schema_ready()
 * below — see that function's own comment for the full failure-mode story:
 * inside a real REST request, Pods' error path for a missing pod/field is
 * wp_send_json(500) + die(), which no try/catch anywhere can intercept, so
 * the only real defense is checking BEFORE calling into Pods).
 *
 * These guard the two remaining hook families that call Pods on fields this
 * plugin expects to exist but that no committed provisioning step has ever
 * guaranteed:
 * - slot.current_flavor/immediate_flavor/next_flavor (the designation fields)
 *   — not declared in _schema.php until this file's guards went in, so
 *   Schema Sync could never repair them; on any environment stood up without
 *   them (or where they were deleted), every slot save 500s the same way the
 *   missing flavor_request pod did.
 * - tub.moving_to — declared in _schema.php and repaired by Schema Sync, but
 *   scoop_mark_tub_moving_if_needed()'s write predates that declaration's
 *   arrival on every existing environment; until each one actually applies
 *   the schema, its write is live drift exactly like flavor_request was.
 *
 * Both read AND write exposure is real for these: the WHERE clauses traverse
 * them as "<field>.ID = …" (relationship-join syntax Pods can only resolve
 * against a real pick field — against a missing field or a plain number field
 * it's the same uncatchable 500), and the writes land through
 * pods_api()->save_pod_item() on the same fields.
 * ------------------------------------------------------------ */

/** One shared log-skip line — deduped per check per request so a reconcile
 * loop over many slots/flavors logs once, not once per slot. */
function scoop_log_schema_skip(string $check, string $what): void {
  static $logged = [];
  if (isset($logged[$check])) return;
  $logged[$check] = true;
  error_log("scoop_{$check}: {$what} missing or misconfigured on this environment — dependent save-hook work skipped until repaired (Scoop -> Schema Sync).");
}

/** Which pod's fields a given schema-ready check depends on, and which of
 * them must be real pick relations (their WHERE clauses traverse .ID).
 * Everything else in those functions is either a pod lookup (guarded by the
 * pod check itself) or a plain scalar. */
function scoop_guard_field_requirements(string $check): array {
  switch ($check) {
    case 'slot_designation_schema_ready':
      return ['pod' => 'slot', 'picks' => ['current_flavor', 'immediate_flavor', 'next_flavor']];
    case 'tub_moving_schema_ready':
      return ['pod' => 'tub', 'picks' => ['moving_to']];
  }
  return ['pod' => '', 'picks' => []];
}

function scoop_schema_ready_for(string $check): bool {
  static $ready = [];
  if (array_key_exists($check, $ready)) return $ready[$check];

  $ready[$check] = false;
  $req = scoop_guard_field_requirements($check);
  if ($req['pod'] !== '' && function_exists('pods_api')) {
    try {
      $pod = pods_api()->load_pod(['name' => $req['pod']]);
      $fields = is_array($pod['fields'] ?? null) ? $pod['fields'] : [];
      $ok = !empty($pod);
      foreach ($req['picks'] as $pf) {
        if (!$ok) break;
        $ok = (($fields[$pf]['type'] ?? '') === 'pick');
      }
      $ready[$check] = $ok;
    } catch (\Throwable $e) {
      $ready[$check] = false;
    }
  }

  if (!$ready[$check]) {
    scoop_log_schema_skip($check, $req['pod'] . '.' . implode('/', $req['picks']));
  }
  return $ready[$check];
}

/**
 * Whether this environment's slot pod has the designation fields
 * (current_flavor/immediate_flavor/next_flavor) as real pick relations —
 * required by every ".ID" traversal and designation write in the slot
 * dedupe/designation hooks above, and by the demand scans below. Memoized
 * per request; see scoop_schema_ready_for() for the failure-mode rationale.
 */
function scoop_slot_designation_schema_ready(): bool {
  return scoop_schema_ready_for('slot_designation_schema_ready');
}

/**
 * Whether tub.moving_to exists as a real pick relation on this environment —
 * required by scoop_mark_tub_moving_if_needed()'s earmark write and by every
 * reader that traverses moving_to. Memoized per request; see
 * scoop_schema_ready_for() for the failure-mode rationale.
 */
function scoop_tub_moving_schema_ready(): bool {
  return scoop_schema_ready_for('tub_moving_schema_ready');
}

add_filter('pods_api_pre_save_pod_item_slot', 'scoop_slot_pre_save_dedupe_own_fields', 10, 2);
function scoop_slot_pre_save_dedupe_own_fields($pieces, $is_new_item) {
  // ".ID" traversals below need real pick fields; on a drifted environment
  // this fires for every slot save, so guard rather than die (see the
  // schema-ready guards' comment above).
  if (!scoop_slot_designation_schema_ready()) return $pieces;

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

  // The designation uniqueness sweep reads the other slots' designation
  // fields through ".ID" traversals and writes them back through
  // save_pod_item() — both die uncatchably on a drifted environment (see
  // the schema-ready guards' comment), so guard before touching Pods. Same
  // class as the flavor_request guard; the pre-save half of this hook pair
  // is guarded too.
  if (!scoop_slot_designation_schema_ready()) return $pieces;

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

  // Everything downstream (the slot/cabinet ".ID" reads below, then
  // scoop_mark_tub_moving_if_needed()'s moving_to work and the flavor_request
  // machinery) needs both field families to be real pick relations — on a
  // drifted environment this fires on every slot save of a designated flavor,
  // so guard before touching Pods (see the schema-ready guards' comment).
  if (!scoop_slot_designation_schema_ready()) return $pieces;
  if (!scoop_tub_moving_schema_ready()) return $pieces;

  $slot = pods('slot', $slot_id);
  if (!$slot || !$slot->exists()) return $pieces;

  $cabinet_id = (int) $slot->field('cabinet.ID');
  if (!$cabinet_id) return $pieces;

  $destination_id = (int) pods('cabinet', $cabinet_id)->field('location.ID');
  if (!$destination_id) return $pieces;

  foreach (array_unique($incoming) as $flavor_id) {
    scoop_mark_tub_moving_if_needed($flavor_id, $destination_id);
    scoop_sync_flavor_request($destination_id, $flavor_id);
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

  // The flavor.ID WHERE below only resolves through Pods' relationship join
  // and the moving_to write assumes that pick field exists — see the
  // schema-ready guards' comment for why this is checked upfront rather than
  // relying on a catch (the REST error path here is die(), not an exception).
  // Reached from reconcile loops over every slot wanting a flavor, so the
  // checks memoize and stay cheap.
  if (!scoop_tub_moving_schema_ready()) return;

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

  pods_api()->save_pod_item(['pod' => 'tub', 'id' => $chosen['id'], 'data' => ['moving_to' => $destination_id]]);
}

/* ------------------------------------------------------------
 * Flavor Requests: one persisted, 1-to-1 Debt row per (destination,
 * flavor) demand pair (2026-08-31 redesign — see the design conversation
 * and assets/models/debt-grid-model.js's header comment). Separate
 * mechanism from moving_to/scoop_mark_tub_moving_if_needed() above: this
 * one only ever claims from Woodinville, Freezing stock specifically (per
 * your spec — Woodinville is where every flavor is made), not "anywhere
 * eligible" the way the older earmark hook does. A tub claimed here that
 * needs to physically relocate also gets moving_to set (so Moving/Debt's
 * own inbound bucket still sees it) — the older hook's own search already
 * skips any tub with moving_to set, so the two mechanisms can't
 * double-claim the same tub regardless of which runs first.
 * ------------------------------------------------------------ */

/**
 * Count of slot designations (current_flavor OR immediate_flavor, each
 * counted separately — same as debt-grid-model.js's old client-side demand
 * scan, now moved server-side) at $location_id naming $flavor_id.
 */
function scoop_slot_demand_count(int $location_id, int $flavor_id): int {
  if (!$location_id || !$flavor_id || !function_exists('pods')) return 0;

  $count = 0;
  $slots = pods('slot', ['limit' => -1]);
  if (!$slots) return 0;

  while ($slots->fetch()) {
    $cabinet_id = (int) $slots->field('cabinet.ID');
    if (!$cabinet_id) continue;
    $dest = (int) pods('cabinet', $cabinet_id)->field('location.ID');
    if ($dest !== $location_id) continue;

    foreach (['current_flavor', 'immediate_flavor'] as $f) {
      if ((int) scoop_rel_id($slots->field($f)) === $flavor_id) $count++;
    }
  }

  return $count;
}

/**
 * "Does not already have a tub of that flavor present at the slot's
 * cabinet's location" — your wording, verbatim. Front-of-house, non-dead
 * state, physically at $location_id; state otherwise unrestricted (an
 * Opened tub in service still counts as present — nothing needs to move).
 */
function scoop_flavor_present_at_location(int $flavor_id, int $location_id): bool {
  if (!$flavor_id || !$location_id || !function_exists('pods')) return false;

  $tubs = pods('tub', [
    'where' => "flavor.ID = {$flavor_id} AND location.ID = {$location_id}",
    'limit' => -1,
  ]);
  if (!$tubs) return false;

  while ($tubs->fetch()) {
    $state = (string) $tubs->field('state');
    if (in_array($state, ['Emptied', '!Lost'], true)) continue;

    $use_id   = (int) scoop_rel_id($tubs->field('use'));
    $is_front = !$use_id || $use_id === SCOOP_FRONT_OF_HOUSE_USE_ID;
    if (!$is_front) continue;

    return true;
  }

  return false;
}

/**
 * Whether this environment's schema actually supports the flavor_request
 * machinery below — checked once per request and cached (this is reachable
 * from a loop over every slot wanting a flavor, e.g.
 * scoop_reconcile_moving_for_flavor(), so repeat calls need to be cheap).
 *
 * This exists because try/catch cannot protect against the actual failure
 * mode here. Confirmed live against a copy of production data (2026-09):
 * the flavor_request pod does not exist there at all — 9d545dd added this
 * machinery but nothing ever created it on that environment. Inside a real
 * REST request, Pods' own pods_error() does not throw a catchable PHP
 * exception for "operate on a pod/field that doesn't exist" — pods_doing_json()
 * sees the REST_REQUEST constant, resolves error_mode to 'json', and calls
 * wp_send_json(['message' => $error], 500) followed by die(): a clean HTTP
 * 500 response, then a hard process termination no try/catch anywhere can
 * intercept (confirmed directly: overriding the wp_die_handler filter never
 * fires either — this bypasses wp_die() too). The only real fix is never
 * calling into Pods for this pod/field unless this check says it's safe to.
 *
 * Checks both possible drift shapes: the whole pod missing (what production
 * actually has), and tub.flavor_request present but not a proper single
 * pick field (what a gen-pods.php-provisioned environment would have,
 * before the _specs.php fix — see that file's own comment on this field).
 */
function scoop_flavor_request_schema_ready(): bool {
  static $ready = null;
  if ($ready !== null) return $ready;

  $ready = false;
  if (function_exists('pods_api')) {
    try {
      $fr_pod = pods_api()->load_pod(['name' => 'flavor_request']);
      $tub_pod = pods_api()->load_pod(['name' => 'tub']);
      $tub_field = is_array($tub_pod['fields'] ?? null) ? ($tub_pod['fields']['flavor_request'] ?? null) : null;
      $ready = !empty($fr_pod) && !empty($tub_field) && ($tub_field['type'] ?? '') === 'pick';
    } catch (\Throwable $e) {
      $ready = false;
    }
  }

  if (!$ready) {
    error_log('scoop_flavor_request_schema_ready: flavor_request pod and/or tub.flavor_request field missing or misconfigured on this environment — flavor_request sync/claim machinery skipped until repaired (Scoop -> Schema Sync).');
  }

  return $ready;
}

/** Read-only lookup — does a flavor_request already exist for this pair? */
function scoop_find_flavor_request(int $location_id, int $flavor_id): int {
  if (!$location_id || !$flavor_id || !function_exists('pods')) return 0;

  $existing = pods('flavor_request', [
    'where' => "location.ID = {$location_id} AND flavor.ID = {$flavor_id}",
    'limit' => 1,
  ]);
  if ($existing && $existing->total() > 0) {
    $existing->fetch();
    return (int) $existing->id();
  }
  return 0;
}

/** Same upsert-by-pair shape as scoop_handle_debt_requests_post() (rest.php) — duplicated rather than shared since that function is REST-request shaped and this call site has neither a payload nor a response to build. */
function scoop_find_or_create_flavor_request(int $location_id, int $flavor_id): int {
  $existing_id = scoop_find_flavor_request($location_id, $flavor_id);
  if ($existing_id) return $existing_id;
  if (!function_exists('pods_api')) return 0;
  // See scoop_flavor_request_schema_ready()'s own comment — creating a
  // flavor_request post when that pod doesn't exist on this environment is
  // exactly the call that terminates the whole request uncatchably.
  if (!scoop_flavor_request_schema_ready()) return 0;

  $title = sprintf(
    '%s | %s',
    get_the_title($location_id) ?: "Location {$location_id}",
    get_the_title($flavor_id)   ?: "Flavor {$flavor_id}",
  );

  $new_id = pods_api()->save_pod_item([
    'pod'  => 'flavor_request',
    'data' => [
      'post_title'  => $title,
      'post_status' => 'publish',
      'location'    => $location_id,
      'flavor'      => $flavor_id,
    ],
  ]);

  return is_wp_error($new_id) ? 0 : (int) $new_id;
}

/**
 * Tops up $request_id's claimed tubs to $wanted, sourcing ONLY from
 * Woodinville, state Freezing, front-of-house tubs not already claimed by
 * any request — per your spec, narrower than
 * scoop_mark_tub_moving_if_needed()'s "anywhere, widened states" pool on
 * purpose (Woodinville is where every flavor is made; Freezing specifically
 * because a tub already Hardening/Tempering/Opened is further along a
 * different plan already). Claims oldest-first. If fewer than needed exist,
 * claims what it can — the remainder shows as Owed (gap = wanted - claimed
 * in computeDebtRows, assets/models/debt-grid-model.js), never invents
 * stock.
 */
function scoop_topup_flavor_request_claims(int $request_id, int $flavor_id, int $destination_id, int $wanted): void {
  if (!$request_id || !$flavor_id || !$destination_id || !function_exists('pods') || !function_exists('pods_api')) return;

  // Every query/write below assumes tub.flavor_request is a real Pods
  // relationship (pick) field — the WHERE clauses traverse it as
  // "flavor_request.ID = …", which only resolves through Pods' relationship
  // join. See scoop_flavor_request_schema_ready()'s own comment for why this
  // is checked upfront rather than relying on the try/catch below: inside a
  // real REST request, Pods' error path for this specific failure is not a
  // catchable exception at all (wp_send_json + die()), so the guard is the
  // actual fix — the try/catch is only a second layer for anything else
  // unexpected in this function. tub.moving_to is checked alongside it: this
  // function WRITES that field too (claim top-up sets moving_to alongside
  // flavor_request), and on a drifted environment that write dies the same
  // way the flavor_request ones did. This whole function is best-effort
  // bookkeeping (an index/cache of demand-to-tub claims, not the source of
  // truth for what's physically in stock), so any problem here should
  // degrade to a logged skip, never take down the write that triggered it.
  if (!scoop_flavor_request_schema_ready()) return;
  if (!scoop_tub_moving_schema_ready()) return;

  try {
    $claimed = pods('tub', ['where' => "flavor_request.ID = {$request_id}", 'limit' => -1]);
    $claimed_count = $claimed ? (int) $claimed->total() : 0;

    $need = $wanted - $claimed_count;
    if ($need <= 0) return;

    $woodinville_id = (int) scoop_get_default_location_id();

    $pool = pods('tub', [
      'where' => sprintf(
        "flavor.ID = %d AND location.ID = %d AND state = 'Freezing'",
        $flavor_id,
        $woodinville_id
      ),
      'limit' => -1,
    ]);
    if (!$pool) return;

    $candidates = [];
    while ($pool->fetch()) {
      $use_id   = (int) scoop_rel_id($pool->field('use'));
      $is_front = !$use_id || $use_id === SCOOP_FRONT_OF_HOUSE_USE_ID;
      if (!$is_front) continue;

      if ((int) scoop_rel_id($pool->field('flavor_request'))) continue; // already claimed by some request

      $candidates[] = [
        'id'         => (int) $pool->id(),
        'created_on' => (string) $pool->field('created_on'),
      ];
    }
    if (!$candidates) return;

    usort($candidates, fn($a, $b) => strcmp($a['created_on'], $b['created_on']));

    foreach (array_slice($candidates, 0, $need) as $c) {
      $data = ['flavor_request' => $request_id];
      if ($destination_id !== $woodinville_id) $data['moving_to'] = $destination_id;
      pods_api()->save_pod_item(['pod' => 'tub', 'id' => $c['id'], 'data' => $data]);
    }
  } catch (\Throwable $e) {
    error_log("scoop_topup_flavor_request_claims: request {$request_id} (flavor {$flavor_id}, destination {$destination_id}) failed, skipping claim top-up: " . $e->getMessage());
  }
}

/**
 * Ensures a flavor_request exists (per your spec: only when a slot
 * actually wants this flavor here AND nothing's already present locally)
 * and keeps it topped up. Safe/idempotent to call any time slot demand,
 * supply, or a manual Wanted value could have changed for this pair.
 *
 * Once a row exists, it's always maintained regardless of the creation
 * gate — a human's Wanted override or a slot's own later save shouldn't
 * stop being tracked just because local stock happens to exist at that
 * instant.
 */
function scoop_sync_flavor_request(int $location_id, int $flavor_id): void {
  if (!$location_id || !$flavor_id || !function_exists('pods') || !function_exists('pods_api')) return;
  if (!scoop_flavor_request_schema_ready()) return;

  // scoop_slot_demand_count() below traverses the slot designation fields
  // (".ID" syntax) — same drift exposure as everywhere else in this file.
  if (!scoop_slot_designation_schema_ready()) return;

  $guard_key = "sync_flavor_request:{$location_id}:{$flavor_id}";
  if (!scoop_guard_enter($guard_key)) return;

  try {
    $existing_id = scoop_find_flavor_request($location_id, $flavor_id);
    $slot_demand = scoop_slot_demand_count($location_id, $flavor_id);

    if (!$existing_id) {
      if ($slot_demand <= 0) return;
      if (scoop_flavor_present_at_location($flavor_id, $location_id)) return;

      $existing_id = scoop_find_or_create_flavor_request($location_id, $flavor_id);
      if (!$existing_id) return;
    }

    $request = pods('flavor_request', $existing_id);
    if (!$request || !$request->exists()) return;

    $current_wanted = (int) $request->field('wanted');
    $new_wanted = max($current_wanted, $slot_demand);

    if ($new_wanted !== $current_wanted) {
      pods_api()->save_pod_item(['pod' => 'flavor_request', 'id' => $existing_id, 'data' => ['wanted' => $new_wanted]]);
    }

    scoop_topup_flavor_request_claims($existing_id, $flavor_id, $location_id, $new_wanted);
  } finally {
    scoop_guard_leave($guard_key);
  }
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
 *
 * Also re-syncs flavor_request per destination (scoop_sync_flavor_request())
 * — the same "supply arrived after demand was already scheduled" gap
 * applies there too: a flavor_request's claim can only fill from whatever
 * Woodinville stock exists AT THE TIME a slot is saved, so newly-created
 * supply needs this same retroactive pass to get topped up.
 */
function scoop_reconcile_moving_for_flavor(int $flavor_id): void {
  if (!$flavor_id || !function_exists('pods') || !function_exists('pods_api')) return;

  // The slot scan below reads the designation fields through ".ID" traversals
  // (and dispatches into scoop_mark_tub_moving_if_needed()/the flavor_request
  // machinery, both of which carry their own guards). Skip the scan entirely
  // on a drifted environment rather than dying mid-reconcile — see the
  // schema-ready guards' comment.
  if (!scoop_slot_designation_schema_ready()) return;

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
      scoop_sync_flavor_request($destination_id, $flavor_id);
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
