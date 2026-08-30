<?php
/**
 * Seed the smoke suite's fixture data on a FRESH WP+Pods stack.
 * Derived entirely from the repo's own fixture contract (tests/smoke/tests/*.spec.js
 * + includes/_specs.php) — no mirrored DB.
 * Run: php wp-cli.phar eval-file seed-fixtures.php --allow-root
 */
if (!function_exists('scoop_seed_pod')) {
  function scoop_seed_pod($pod, $title, $data = []) {
    $existing = pods($pod, ['where' => 't.post_title = "' . esc_sql($title) . '"', 'limit' => 1]);
    if ($existing && $existing->total()) return (int)$existing->id();
    $id = pods_api()->save_pod_item(['pod' => $pod, 'data' => array_merge(['post_title' => $title, 'post_status' => 'publish'], $data)]);
    if (is_wp_error($id)) throw new Exception("seed $pod '$title': " . $id->get_error_message());
    return (int)$id;
  }
  // Pin a leaf row's post ID to the exact id a spec hardcodes (use=1863, location=935).
  // Safe only BEFORE anything references it — called immediately after creation.
  function scoop_pin_post_id($pod, $old, $new) {
    global $wpdb;
    $podsTable = $wpdb->prefix . 'pods_' . $pod;
    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->posts} SET ID = %d WHERE ID = %d", $new, $old));
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $podsTable)) === $podsTable) {
      $wpdb->query($wpdb->prepare("UPDATE {$podsTable} SET id = %d WHERE id = %d", $new, $old));
    }
    return $new;
  }
}

$api = pods_api();

// ---- Reference rows (ids pinned BEFORE any relationship references them) ----
$use_foh = scoop_seed_pod('use', 'Front-of-house', ['order' => 0]);
if ($use_foh !== 1863) scoop_pin_post_id('use', $use_foh, 1863);
$use_boh = scoop_seed_pod('use', 'Back-of-house', ['order' => 1]);

$allergens = [];
foreach (['dairy', 'peanuts', 'tree nuts', 'gluten', 'soy-2', 'corn', 'eggs'] as $a) {
  $allergens[$a] = scoop_seed_pod('allergen', $a);
}

$loc_wood = scoop_seed_pod('location', 'Woodinville');
if ($loc_wood !== 935) scoop_pin_post_id('location', $loc_wood, 935);
$loc_mount = scoop_seed_pod('location', 'Mountlake Terrace');
echo "locations: Woodinville=935 Mountlake=$loc_mount  use_foh=1863 use_boh=$use_boh\n";

// ---- Cabinets + slots ------------------------------------------------------
// Woodinville_dairy_18: a DAIRY-holding cabinet (does NOT prohibit dairy —
// CabinetWorkflowGridModel._allergenConflict only lists dairy-TAGGED flavors
// there; the lifecycle spec's own setup step self-heals the fixture flavor's
// dairy tag through the real wp-admin UI).
// Mountlake Terrace_restricted_12: PROHIBITS dairy (tub-moving fixture ships untagged).
$cab_dairy = scoop_seed_pod('cabinet', 'Woodinville_dairy_18', ['location' => 935, 'max_tubs' => 18]);
$cab_restr = scoop_seed_pod('cabinet', 'Mountlake Terrace_restricted_12', ['location' => $loc_mount, 'max_tubs' => 12, 'prohibited_allergens' => [$allergens['dairy']]]);

$slot_dairy_1 = null;
for ($i = 1; $i <= 18; $i++) {
  $id = scoop_seed_pod('slot', "Woodinville_dairy_18|$i", ['cabinet' => $cab_dairy, 'location' => 935, 'index' => $i]);
  if ($i === 1) $slot_dairy_1 = $id;
}
for ($i = 1; $i <= 12; $i++) {
  scoop_seed_pod('slot', "Mountlake Terrace_restricted_12|$i", ['cabinet' => $cab_restr, 'location' => $loc_mount, 'index' => $i]);
}

// ---- Fixture flavors ---------------------------------------------------------
// 'zz__flavor test___' ships UNTAGGED on purpose: the lifecycle spec's setup
// step tags it dairy through the real wp-admin react-select UI — leave it so.
scoop_seed_pod('flavor', 'zz__flavor test___');
scoop_seed_pod('flavor', 'zz__flavor moving test___');
scoop_seed_pod('flavor', 'zz__flavor debt test___');
// Occupant flavor for the lifecycle's target slot — must be dairy-tagged so
// step 7's picker check (a dairy cabinet lists only dairy flavors) finds it.
$flavor_vanilla = scoop_seed_pod('flavor', 'Plain vanilla', ['allergens' => [$allergens['dairy']]]);

// ---- CI-stack deviation (documented): pre-tag the lifecycle fixture flavor.
// On the Local mirror the spec tags this flavor through wp-admin's Pods
// react-select UI; on this stack Pods 2.8's admin JS fatals, so the tag is
// applied here instead. The spec's ensureFlavorTaggedDairy() then skips the
// wp-admin step by design.
$dairy_id = $allergens['dairy'];
$ft = pods('flavor', ['where' => 't.post_title = "zz__flavor test___"', 'limit' => 1]);
$ft->save('allergens', [$dairy_id]);

// ---- One pre-seeded occupant in the lifecycle's target slot -----------------
// Step 2 requires the slot to currently hold a tub; step 7/8 restore it.
scoop_seed_pod('tub', 'Plain vanilla 2026-08-30 08:00_1|1', [
  'state' => 'Opened', 'amount' => 1, 'use' => 1863, 'flavor' => $flavor_vanilla,
  'location' => 935, 'slot' => $slot_dairy_1,
]);
// The occupied slot also needs its current_flavor designation (what
// CabinetWorkflow's tile state and Confirm Cabinet read) — keep tub and
// slot sides consistent.
pods('slot', $slot_dairy_1)->save('current_flavor', $flavor_vanilla);
echo "seeded: cabinets+slots, 4 fixture flavors, 1 occupant tub (slot $slot_dairy_1)\n";

// Post-seed cache hygiene: the flavor/allergen writes above ride direct pods
// saves that don't bump scoop's entity-cache version, and the bundle's
// force_bust only busts the bundle cache, not the slow-entity cache. Purge it.
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_scoop_ec_%' OR option_name LIKE '_transient_timeout_scoop_ec_%'");
