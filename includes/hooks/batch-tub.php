<?php
if (!defined('ABSPATH')) exit;

/**
 * Batch + Tub hooks (refactored)
 *
 * Goals:
 * - Stable title/slug generation for batch
 * - Deterministic tub creation (DB-driven; not dependent on $pieces shape)
 * - Use Pods API for creates/updates so Pods hooks fire consistently
 * - Guard against accidental recursion
 * - Quiet logs unless SCOOP_DEBUG is enabled
 */

/** ------------------------------------------------------------
 *  Logging / guards
 * ------------------------------------------------------------ */
if (!function_exists('scoop_log')) {
  function scoop_log(string $msg): void {
    if (defined('SCOOP_DEBUG') && SCOOP_DEBUG) {
      error_log($msg);
    }
  }
}

if (!function_exists('scoop_guard')) {
  /**
   * Execute $fn with a keyed re-entrancy guard.
   * Returns $default if guard is already set.
   */
  function scoop_guard(string $key, callable $fn, $default = null) {
    $GLOBALS['scoop_guard'] ??= [];
    if (!empty($GLOBALS['scoop_guard'][$key])) return $default;
    $GLOBALS['scoop_guard'][$key] = true;
    try {
      return $fn();
    } finally {
      unset($GLOBALS['scoop_guard'][$key]);
    }
  }
}

if (!function_exists('scoop_batch_elapsed_ms')) {
  function scoop_batch_elapsed_ms(float $start): int {
    return (int)round((microtime(true) - $start) * 1000);
  }
}

if (!function_exists('scoop_batch_debug')) {
  function scoop_batch_debug(string $msg): void {
    if (function_exists('scoop_debug_log')) {
      scoop_debug_log($msg);
      return;
    }
    scoop_log($msg);
  }
}

if (!function_exists('scoop_batch_title_for_data')) {
  function scoop_batch_title_for_data(int $flavor_id, float $count): string {
    $flavor_name = (string)get_the_title($flavor_id);
    if ($flavor_name === '') return '';

    $date_str = current_time('Y-m-d H:i');
    return "{$flavor_name} {$date_str}_{$count}";
  }
}

if (!function_exists('scoop_rel_id')) {
  /**
   * Extract a single related item ID from a Pods relationship field value.
   */
  function scoop_rel_id($val): int {
    if (empty($val)) return 0;
    if (is_numeric($val)) return (int)$val;

    if (is_array($val)) {
      $first = reset($val);
      if (is_numeric($first)) return (int)$first;

      if (is_array($first)) {
        if (isset($first['id']) && is_numeric($first['id'])) return (int)$first['id'];
        if (isset($first['ID']) && is_numeric($first['ID'])) return (int)$first['ID'];
      }

      if (is_object($first)) {
        if (isset($first->id) && is_numeric($first->id)) return (int)$first->id;
        if (isset($first->ID) && is_numeric($first->ID)) return (int)$first->ID;
      }
    }

    if (is_object($val)) {
      if (isset($val->id) && is_numeric($val->id)) return (int)$val->id;
      if (isset($val->ID) && is_numeric($val->ID)) return (int)$val->ID;
    }

    if (is_string($val)) {
      $trim = trim($val);
      if (ctype_digit($trim)) return (int)$trim;
    }

    return 0;
  }
}

/** ------------------------------------------------------------
 *  Config
 * ------------------------------------------------------------ */
if (!defined('SCOOP_DEFAULT_LOCATION_ID')) {
  define('SCOOP_DEFAULT_LOCATION_ID', 935); // Woodinville (override in wp-config.php or _config.php)
}
if (!function_exists('scoop_get_default_location_id')) {
  function scoop_get_default_location_id(): int {
    return (int)SCOOP_DEFAULT_LOCATION_ID;
  }
}
// Default "use" for new tubs. Source of truth is the Pods GUI: tub → "use"
// field → Default Value. The direct-write path bypasses Pods, so the field
// default isn't auto-applied (same reason state is set to 'Freezing'
// explicitly) — we read that configured default here and apply it ourselves.
// Returns 0 when no default is configured, in which case no "use" is set.
if (!function_exists('scoop_get_default_use_id')) {
  function scoop_get_default_use_id(): int {
    static $cached = null;
    if ($cached !== null) return $cached;
    $cached = 0;

    if (!function_exists('pods_api')) return $cached;
    try {
      $field = pods_api()->load_field(['pod' => 'tub', 'name' => 'use']);
    } catch (\Throwable $e) {
      return $cached;
    }
    if (!$field || is_wp_error($field)) return $cached;

    $cached = scoop_rel_id(scoop_field_option($field, 'default_value'));
    return $cached;
  }
}

if (!function_exists('scoop_field_option')) {
  /**
   * Read a single Pods field option, tolerant of the array (≤2.7) and
   * Whatsit\Field object (2.8+/3.x) shapes load_field() can return.
   */
  function scoop_field_option($field, string $key) {
    if (is_array($field)) return $field[$key] ?? null;
    if (is_object($field)) {
      if (method_exists($field, 'get_arg')) {
        $v = $field->get_arg($key);
        if ($v !== null) return $v;
      }
      if ($field instanceof ArrayAccess && isset($field[$key])) return $field[$key];
    }
    return null;
  }
}

/** ------------------------------------------------------------
 *  Batch hooks
 * ------------------------------------------------------------ */

/**
 * Set Batch title from flavor + date + count before saving.
 * Title: "<FlavorName> <Y-m-d H:i>_<count>"
 */
add_filter('pods_api_pre_save_pod_item_batch', 'scoop_set_batch_title', 10, 2);
function scoop_set_batch_title($pieces, $is_new_item) {

  // Ensure fields_active exists
  if (!isset($pieces['fields_active']) || !is_array($pieces['fields_active'])) {
    $pieces['fields_active'] = [];
  }
  if (!in_array('post_title', $pieces['fields_active'], true)) {
    $pieces['fields_active'][] = 'post_title';
  }
  if (!isset($pieces['object_fields']['post_title'])) {
    $pieces['object_fields']['post_title'] = ['value' => ''];
  }

  $flavor_id = scoop_rel_id($pieces['fields']['flavor']['value'] ?? null);
  if (!$flavor_id) {
    scoop_log('scoop_set_batch_title: flavor missing/invalid');
    return $pieces;
  }

  $count = 1;
  $raw_count = $pieces['fields']['count']['value'] ?? null;
  if (is_numeric($raw_count)) {
    $count = max(1, (float)$raw_count);
  }

  $this_title = scoop_batch_title_for_data($flavor_id, $count);
  if ($this_title === '') {
    scoop_log("scoop_set_batch_title: could not resolve flavor title id={$flavor_id}");
    return $pieces;
  }

  $pieces['object_fields']['post_title']['value'] = $this_title;

  // keep slug in sync
  if (!isset($pieces['object_fields']['post_name'])) {
    $pieces['object_fields']['post_name'] = ['value' => ''];
  }
  $pieces['object_fields']['post_name']['value'] = sanitize_title($this_title);

  return $pieces;
}

/** ------------------------------------------------------------
 *  Tub hooks
 * ------------------------------------------------------------ */

/**
 * After a Batch is saved, create related Tub pods (once per batch).
 * - Does NOT trust $is_new_item
 * - Skips if any tub already references this batch
 * - Reads flavor + count from DB for reliability
 * - Uses Pods API so tub hooks fire
 */
add_filter('pods_api_post_save_pod_item_batch', 'scoop_create_tubs_for_new_batch', 30, 3);
function scoop_create_tubs_for_new_batch($pieces, $is_new_item, $id) {
  $batch_id = scoop_batch_saved_id($pieces, $id);
  if (!$batch_id || !function_exists('pods')) return $pieces;

  return scoop_guard("create_tubs_for_batch:{$batch_id}", function() use ($pieces, $batch_id) {
    $request_start = microtime(true);
    $lap_start = $request_start;

    // Skip if tub already exist
    $existing = pods('tub', [
      'where' => 'batch.ID = ' . $batch_id,
      'limit' => 1,
    ]);
    if ($existing && $existing->total() > 0) {
      scoop_log("scoop_create_tubs_for_new_batch: batch {$batch_id} already has tub");
      return $pieces;
    }
    scoop_batch_debug("batch {$batch_id}: existing tub check " . scoop_batch_elapsed_ms($lap_start) . "ms");
    $lap_start = microtime(true);

    $batch = pods('batch', $batch_id);
    if (!$batch || !$batch->exists()) {
      scoop_log("scoop_create_tubs_for_new_batch: batch {$batch_id} not found");
      return $pieces;
    }

    // Task-linked batches (created via the Task form — see
    // assets/ui/task-form.js) are a PLAN to make a batch, not a real one
    // yet — the client explicitly sends done=false for these. Skip cascading
    // tub creation until it's actually marked done. Scoped to task-linked
    // batches only (task_id > 0) so the standalone Batch GUI's batches —
    // which never set `task` or `done` — are completely unaffected and keep
    // creating tubs immediately, exactly as before.
    $task_id = (int) scoop_rel_id($batch->field('task'));
    if ($task_id > 0 && !$batch->field('done')) {
      scoop_log("scoop_create_tubs_for_new_batch: batch {$batch_id} is task-linked (task={$task_id}) and not done — skipping tub creation");
      return $pieces;
    }

    $count     = (float)$batch->field('count');
    $flavor_id = (int)$batch->field('flavor.ID');
    scoop_batch_debug("batch {$batch_id}: loaded batch count={$count} flavor={$flavor_id} in " . scoop_batch_elapsed_ms($lap_start) . "ms");

    if ($count <= 0) {
      scoop_log("scoop_create_tubs_for_new_batch: count<=0 for batch {$batch_id}");
      return $pieces;
    }
    if ($flavor_id <= 0) {
      scoop_log("scoop_create_tubs_for_new_batch: flavor missing for batch {$batch_id}");
      return $pieces;
    }

    $location_id = (int)scoop_get_default_location_id();

    $batch_title = get_the_title($batch_id);
    if (!$batch_title) $batch_title = 'Batch ' . $batch_id;

    if (!function_exists('pods_api') || !is_object(pods_api())) {
      scoop_log("scoop_create_tubs_for_new_batch: pods_api missing");
      return $pieces;
    }
    
    $GLOBALS['scoop_suppress_batch_tub_flavor_bump'][$batch_id] = true;
    $GLOBALS['scoop_suppress_cache_bust'] = true;
    $created_count = 0;
    $lap_start = microtime(true);

    $use_direct = defined('SCOOP_DIRECT_TUB_CREATE') ? (bool)SCOOP_DIRECT_TUB_CREATE : true;

    try {
      if ($use_direct) {
        $created_count = count(scoop_create_batch_tubs_direct(
          $batch_id, $flavor_id, $count, $location_id, $batch_title
        ));
      } else {
        $created_count = scoop_create_batch_tubs_via_pods_api(
          $batch_id, $flavor_id, $count, $location_id, $batch_title
        );
      }
    } finally {
      unset($GLOBALS['scoop_suppress_batch_tub_flavor_bump'][$batch_id]);
      unset($GLOBALS['scoop_suppress_cache_bust']);
    }
    scoop_batch_debug("batch {$batch_id}: created {$created_count} tub rows in " . scoop_batch_elapsed_ms($lap_start) . "ms" . ($use_direct ? ' (direct)' : ' (pods-api)'));

    // The per-tub save_post fires were suppressed above — bump the cache version
    // once now so bundle/analytics caches invalidate cleanly.
    scoop_cache_bust();

    if (function_exists('pods_api') && is_object(pods_api())) {
      $lap_start = microtime(true);
      pods_api()->save_pod_item([
        'pod'  => 'flavor',
        'id'   => $flavor_id,
        'data' => ['modified_date' => current_time('mysql')],
      ]);
      scoop_batch_debug("batch {$batch_id}: bumped flavor {$flavor_id} once in " . scoop_batch_elapsed_ms($lap_start) . "ms");
    }

    // Ensure batch is published (optional)
    if (get_post_status($batch_id) !== 'publish') {
      $lap_start = microtime(true);
      wp_update_post([
        'ID'          => $batch_id,
        'post_status' => 'publish',
      ]);
      scoop_batch_debug("batch {$batch_id}: publish update " . scoop_batch_elapsed_ms($lap_start) . "ms");
    }

    scoop_batch_debug("batch {$batch_id}: post-save hook total " . scoop_batch_elapsed_ms($request_start) . "ms");

    return $pieces;
  }, $pieces);
}

/**
 * Direct-write tub creation. Bypasses pods_api()->save_pod_item() because that
 * function's PodsField_Pick->save → PodsAPI->save_relationships chain runs one
 * DB round-trip per relationship field per tub (~9s/tub on dev as of 2026-05-27).
 *
 * Three explicit wpdb writes per batch (no per-tub pods_api round-trips):
 *   1) wp_insert_post() per tub — creates the WP post row.
 *   2) One multi-row INSERT into wp_pods_tub for ALL tubs' scalar fields
 *      (id, state, index, amount, created_on, changed_on). Sets state='Freezing'
 *      as the default to match what the Pods-API path produces (observed empirically
 *      on the working Birthday Cake batch 8354). The Pods field default does NOT
 *      apply when we bypass Pods, so we set it explicitly.
 *   3) One multi-row INSERT into wp_podsrel for batch + flavor + location
 *      relationships across every tub.
 *   4) do_action('pods_api_post_save_pod_item_tub', ...) per tub so downstream
 *      listeners still fire (e.g. scoop_bump_flavor_modified_date_on_tub_save,
 *      which is suppressed via the per-batch flag the caller sets).
 *
 * History — why we don't use pods('tub', $id)->save() here anymore:
 *   The earlier version of this function (pre-2026-05-27 late) tried to write
 *   scalars via pods('tub', $id)->save() after wp_insert_post(). That treated
 *   the call as an UPDATE on an existing post, which means Pods field DEFAULTS
 *   (state='Freezing', etc.) did NOT get applied — only the fields we passed in
 *   $args were written. The result: tub rows landed in wp_pods_tub but with
 *   state=NULL, which made them invisible to the FlavorTub grid's standard
 *   `state != 'Emptied'` filter. The fix is to write every needed column
 *   ourselves rather than rely on Pods to fill in defaults.
 *
 * Reverting to the safe (slow) Pods-API path:
 *   In wp-config.php (above the "stop editing!" line) add:
 *     define( 'SCOOP_DIRECT_TUB_CREATE', false );
 *   That routes scoop_create_tubs_for_new_batch() to
 *   scoop_create_batch_tubs_via_pods_api() instead. The Pods-API path is
 *   ~10× slower per tub (each save() walks the relationship-save machinery)
 *   but is the canonical safeguard: full field validation, all hooks fire,
 *   defaults are applied. Use it any time the direct path's schema assumptions
 *   feel risky.
 *
 * Assumes:
 *   - Pods is in tables mode (per-Pod table wp_pods_<podname> exists with the
 *     columns documented in the project README).
 *   - Relationships go through wp_podsrel (the default in non-simple configs).
 *   - The tub Pod's "state" field accepts 'Freezing' as a valid value (it does;
 *     this matches what the Pods-API path emits).
 *
 * Caller is expected to have set:
 *   $GLOBALS['scoop_suppress_batch_tub_flavor_bump'][$batch_id] = true;
 *   $GLOBALS['scoop_suppress_cache_bust'] = true;
 *
 * @return int[] IDs of the newly created tubs.
 */
function scoop_create_batch_tubs_direct(int $batch_id, int $flavor_id, float $count, int $location_id, string $batch_title): array {
  global $wpdb;

  // Plan tub specs: 1 fractional (if count has a fractional part) + N whole tubs.
  // amount = 1.00 for whole tubs to match the Pods-API path; the fractional tub
  // takes its actual fraction value.
  $fraction = fmod($count, 1);
  $whole    = (int)floor($count);
  $specs    = [];
  if ($fraction > 0) {
    $specs[] = ['index' => (int)ceil($count), 'amount' => (float)$fraction];
  }
  for ($i = 1; $i <= $whole; $i++) {
    $specs[] = ['index' => $i, 'amount' => 1.00];
  }
  if (empty($specs)) return [];

  // ── Step 1: insert wp_posts rows ──────────────────────────────────────────
  $now_mysql    = current_time('mysql');
  $now_gmt      = current_time('mysql', true);
  $current_user = get_current_user_id() ?: 1;

  $created = [];
  $step_start = microtime(true);
  foreach ($specs as $spec) {
    $post_id = wp_insert_post([
      'post_type'      => 'tub',
      'post_title'     => "{$batch_title}|{$spec['index']}",
      'post_status'    => 'publish',
      'post_author'    => $current_user,
      'post_date'      => $now_mysql,
      'post_date_gmt'  => $now_gmt,
      'comment_status' => 'closed',
      'ping_status'    => 'closed',
    ], true);

    if (is_wp_error($post_id) || !$post_id) {
      $err = is_wp_error($post_id) ? $post_id->get_error_message() : 'unknown';
      scoop_batch_debug("scoop_create_batch_tubs_direct: wp_insert_post failed index={$spec['index']}: {$err}");
      continue;
    }

    $created[] = [
      'id'     => (int)$post_id,
      'index'  => $spec['index'],
      'amount' => $spec['amount'],
    ];
  }
  scoop_batch_debug("batch {$batch_id}: wp_insert_post x" . count($created) . " in " . scoop_batch_elapsed_ms($step_start) . "ms");

  if (empty($created)) return [];

  // ── Step 2: one multi-row UPSERT into wp_pods_tub ─────────────────────────
  // This is the row that makes the tub visible to Pods queries. Writing it
  // directly (rather than via pods('tub', $id)->save()) is what's new in
  // 2026-05-27 late — see the history note in the docblock.
  //
  // ON DUPLICATE KEY UPDATE, not a bare INSERT: Pods 2.8.23 (and 3.x) hooks
  // wp_insert_post → PodsMeta::save_post, which auto-creates an EMPTY
  // wp_pods_tub row (id, created_on, changed_on only) for every new tub
  // post — verified on a fresh CI-parity stack (the plain wp_insert_post
  // probe materializes the row with state=''). On the mirror this never
  // happens (its wp_pods_tub predates the pod's save_post wiring / its
  // Pods copy behaves differently), which is why the plain INSERT worked
  // there: on this stack the bare INSERT collides ("Duplicate entry") and
  // the batch-created tub is invisible to every Pods consumer. The upsert
  // is correct in BOTH worlds: when the auto-row exists it upgrades it to
  // the real values; when it doesn't, the insert behaves exactly as before.
  // Confirmed on a live stack: empty auto-row + failed INSERT → upsert →
  // bundle read returns state/amount correctly.
  $tub_table = $wpdb->prefix . 'pods_tub';
  $step_start = microtime(true);
  $values    = [];
  foreach ($created as $tub) {
    $values[] = $wpdb->prepare(
      '(%d, %s, %d, %f, %s, %s)',
      $tub['id'], 'Freezing', $tub['index'], $tub['amount'], $now_mysql, $now_mysql
    );
  }
  $sql = "INSERT INTO {$tub_table} (id, state, `index`, amount, created_on, changed_on) VALUES "
       . implode(',', $values)
       . " ON DUPLICATE KEY UPDATE state = VALUES(state), `index` = VALUES(`index`), amount = VALUES(amount), changed_on = VALUES(changed_on)";
  $result = $wpdb->query($sql);
  if ($result === false) {
    scoop_batch_debug("scoop_create_batch_tubs_direct: wp_pods_tub bulk upsert FAILED: " . $wpdb->last_error);
  } else {
    scoop_batch_debug("batch {$batch_id}: wp_pods_tub bulk upsert {$result} rows in " . scoop_batch_elapsed_ms($step_start) . "ms");
  }

  // ── Step 3: bulk INSERT relationships into wp_podsrel ─────────────────────
  // Pods stores BIDIRECTIONAL relationships as two rows — one per direction.
  // For each tub we write up to SIX rows: tub→batch, tub→flavor, tub→location
  // (the forward directions) AND batch.tubs, flavor.tubs, location.tubs (the
  // reverse directions, so the batch / flavor / location items can list their
  // tubs without a JOIN-back). Skipping the reverse rows is what made batch
  // 8394 (Tomato Sorbet, 2026-05-27) show no tubs in wp-admin even though all
  // 12 tubs had a back-reference to the batch. The audit log writes its own
  // inventory_change.tubs reverse; we don't have to.
  $tub_pod = pods_api()->load_pod(['name' => 'tub']);
  $tub_pod_id     = (int)($tub_pod['id'] ?? 0);
  $field_batch    = (int)($tub_pod['fields']['batch']['id']    ?? 0);
  $field_flavor   = (int)($tub_pod['fields']['flavor']['id']   ?? 0);
  $field_location = (int)($tub_pod['fields']['location']['id'] ?? 0);
  $field_use      = (int)($tub_pod['fields']['use']['id']      ?? 0);

  // Default "use" for new tubs (front-of-house). Pods' field default isn't
  // applied on this direct-write path, so set it explicitly like state.
  $use_id = (int)scoop_get_default_use_id();

  if ($tub_pod_id && $field_batch && $field_flavor) {
    $batch_pod    = pods_api()->load_pod(['name' => 'batch']);
    $flavor_pod   = pods_api()->load_pod(['name' => 'flavor']);
    $location_pod = pods_api()->load_pod(['name' => 'location']);
    $use_pod      = pods_api()->load_pod(['name' => 'use']);

    $batch_pod_id    = (int)($batch_pod['id']    ?? 0);
    $flavor_pod_id   = (int)($flavor_pod['id']   ?? 0);
    $location_pod_id = (int)($location_pod['id'] ?? 0);
    $use_pod_id      = (int)($use_pod['id']      ?? 0);

    // Reverse-direction field IDs — the "tubs" field on each related Pod.
    $batch_tubs_field    = (int)($batch_pod['fields']['tubs']['id']    ?? 0);
    $flavor_tubs_field   = (int)($flavor_pod['fields']['tubs']['id']   ?? 0);
    $location_tubs_field = (int)($location_pod['fields']['tubs']['id'] ?? 0);
    $use_tubs_field      = (int)($use_pod['fields']['tubs']['id']      ?? 0);

    $podsrel = $wpdb->prefix . 'podsrel';
    $values  = [];

    $step_start = microtime(true);
    foreach ($created as $tub) {
      // Forward: tub.batch
      $values[] = $wpdb->prepare(
        '(%d, %d, %d, %d, %d, %d)',
        $tub_pod_id, $field_batch, $tub['id'], $batch_pod_id, $batch_id, 0
      );
      // Reverse: batch.tubs
      if ($batch_tubs_field) {
        $values[] = $wpdb->prepare(
          '(%d, %d, %d, %d, %d, %d)',
          $batch_pod_id, $batch_tubs_field, $batch_id, $tub_pod_id, $tub['id'], 0
        );
      }
      // Forward: tub.flavor
      $values[] = $wpdb->prepare(
        '(%d, %d, %d, %d, %d, %d)',
        $tub_pod_id, $field_flavor, $tub['id'], $flavor_pod_id, $flavor_id, 0
      );
      // Reverse: flavor.tubs
      if ($flavor_tubs_field) {
        $values[] = $wpdb->prepare(
          '(%d, %d, %d, %d, %d, %d)',
          $flavor_pod_id, $flavor_tubs_field, $flavor_id, $tub_pod_id, $tub['id'], 0
        );
      }
      if ($field_location && $location_pod_id && $location_id) {
        // Forward: tub.location
        $values[] = $wpdb->prepare(
          '(%d, %d, %d, %d, %d, %d)',
          $tub_pod_id, $field_location, $tub['id'], $location_pod_id, $location_id, 0
        );
        // Reverse: location.tubs
        if ($location_tubs_field) {
          $values[] = $wpdb->prepare(
            '(%d, %d, %d, %d, %d, %d)',
            $location_pod_id, $location_tubs_field, $location_id, $tub_pod_id, $tub['id'], 0
          );
        }
      }
      if ($field_use && $use_pod_id && $use_id) {
        // Forward: tub.use (default front-of-house)
        $values[] = $wpdb->prepare(
          '(%d, %d, %d, %d, %d, %d)',
          $tub_pod_id, $field_use, $tub['id'], $use_pod_id, $use_id, 0
        );
        // Reverse: use.tubs
        if ($use_tubs_field) {
          $values[] = $wpdb->prepare(
            '(%d, %d, %d, %d, %d, %d)',
            $use_pod_id, $use_tubs_field, $use_id, $tub_pod_id, $tub['id'], 0
          );
        }
      }
    }
    $sql = "INSERT INTO {$podsrel} (pod_id, field_id, item_id, related_pod_id, related_item_id, weight) VALUES "
         . implode(',', $values);
    $result = $wpdb->query($sql);
    if ($result === false) {
      scoop_batch_debug("scoop_create_batch_tubs_direct: wp_podsrel bulk insert FAILED: " . $wpdb->last_error);
    } else {
      scoop_batch_debug("batch {$batch_id}: wp_podsrel bulk insert {$result} rows (incl reverse) in " . scoop_batch_elapsed_ms($step_start) . "ms");
    }
  } else {
    scoop_batch_debug("scoop_create_batch_tubs_direct: WARNING — could not resolve tub/batch/flavor field IDs; relationships not written");
  }

  // ── Step 4: manually fire pods_api_post_save_pod_item_tub ─────────────────
  // Downstream listeners (scoop_bump_flavor_modified_date_on_tub_save and
  // anything added later) expect this hook to fire per-tub. The flavor bump
  // is suppressed via the per-batch flag the caller sets, so this is a no-op
  // for that one — but firing it keeps the contract honest.
  $step_start = microtime(true);
  foreach ($created as $tub) {
    $fake_pieces = ['fields' => [], 'fields_active' => [], 'id' => $tub['id']];
    do_action('pods_api_post_save_pod_item_tub', $fake_pieces, true, $tub['id']);
  }
  scoop_batch_debug("batch {$batch_id}: post-save hooks fired x" . count($created) . " in " . scoop_batch_elapsed_ms($step_start) . "ms");

  return array_column($created, 'id');
}

/**
 * Original per-tub pods_api()->save_pod_item() path, kept as a fallback for
 * installs where the direct-write assumptions don't hold. Toggle via
 * define('SCOOP_DIRECT_TUB_CREATE', false) in wp-config.php.
 *
 * @return int Count of tubs successfully created.
 */
function scoop_create_batch_tubs_via_pods_api(int $batch_id, int $flavor_id, float $count, int $location_id, string $batch_title): int {
  $fraction = fmod($count, 1);
  $created_count = 0;
  $use_id = (int)scoop_get_default_use_id();

  if ($fraction > 0) {
    $last = ceil($count);
    $tub_frac_args = [
      'post_title'  => "{$batch_title}|{$last}",
      'batch'       => $batch_id,
      'flavor'      => $flavor_id,
      'index'       => $last,
      'amount'      => $fraction,
      'post_status' => 'publish',
    ];
    if ($location_id) $tub_frac_args['location'] = $location_id;
    if ($use_id)      $tub_frac_args['use']      = $use_id;
    $new_tub_frac_id = pods_api()->save_pod_item(['pod' => 'tub', 'data' => $tub_frac_args]);
    scoop_log("created tub id={$new_tub_frac_id} batch={$batch_id} flavor={$flavor_id} index={$last} amount={$fraction}");
    if ($new_tub_frac_id) $created_count++;
  }

  for ($i = 1; $i <= $count; $i++) {
    $tub_args = [
      'post_title'  => "{$batch_title}|{$i}",
      'batch'       => $batch_id,
      'flavor'      => $flavor_id,
      'index'       => $i,
      'post_status' => 'publish',
    ];
    if ($location_id) $tub_args['location'] = $location_id;
    if ($use_id)      $tub_args['use']      = $use_id;
    $new_tub_id = pods_api()->save_pod_item(['pod' => 'tub', 'data' => $tub_args]);
    scoop_log("created tub id={$new_tub_id} batch={$batch_id} flavor={$flavor_id} index={$i}");
    if ($new_tub_id) $created_count++;
  }

  return $created_count;
}

function scoop_batch_saved_id($pieces, $id = 0): int {
  if (!empty($id)) return (int)$id;
  if (!empty($pieces['id'])) return (int)$pieces['id'];
  if (!empty($pieces['params']['id'])) return (int)$pieces['params']['id'];
  if (isset($pieces['params']) && is_object($pieces['params']) && !empty($pieces['params']->id)) {
    return (int)$pieces['params']->id;
  }
  return 0;
}

/**
 * Whenever a tub is created or updated, bump its flavor.modified_date.
 * Guarded per-tub to avoid recursion.
 */
add_filter('pods_api_post_save_pod_item_tub', 'scoop_bump_flavor_modified_date_on_tub_save', 30, 3);
function scoop_bump_flavor_modified_date_on_tub_save($pieces, $is_new_item, $id) {
  $tub_id = (int)$id;
  if (!$tub_id || !function_exists('pods')) return $pieces;

  return scoop_guard("bump_flavor_on_tub:{$tub_id}", function() use ($pieces, $tub_id) {

    $tub = pods('tub', $tub_id);
    if (!$tub || !$tub->exists()) return $pieces;

    $batch_id = (int)$tub->field('batch.ID');
    if ($batch_id > 0 && !empty($GLOBALS['scoop_suppress_batch_tub_flavor_bump'][$batch_id])) {
      return $pieces;
    }

    $flavor_id = (int)$tub->field('flavor.ID');
    if (!$flavor_id) return $pieces;

    if (function_exists('pods_api') && is_object(pods_api())) {
      pods_api()->save_pod_item([
        'pod'  => 'flavor',
        'id'   => $flavor_id,
        'data' => ['modified_date' => current_time('mysql')],
      ]);
      scoop_log("bumped flavor.modified_date flavor={$flavor_id} via tub={$tub_id}");
    }

    return $pieces;
  }, $pieces);
}

/**
 * Batch delete (scoop_handle_batch_delete, includes/rest.php) was measured
 * at 24-26s per tub on real local data — traced (see partitioned-plotting-
 * galaxy.md) to Pods' own PodsAPI::delete_relationships(), NOT to anything
 * in this codebase's own delete path. Pods' bidirectional sister-field sync
 * (tub.location/tub.use, both sister-paired to location.tubs/use.tubs — see
 * change-tub.md for the same sister_id mechanism as tub.slot/slot.tub) does
 * two things when a tub is removed from the related item's list: (1) an
 * unconditional, cheap, single-query wp_podsrel delete — the actual
 * relationship removal — and (2) IF `pods_relationship_meta_storage_enabled`
 * is true (Pods' own hardcoded default for every relationship, not
 * something this project configured), a full re-sync of the related item's
 * *entire remaining relationship list* into wp_postmeta, one `add_metadata()`
 * call per item. Virtually every tub shares one of a handful of location/use
 * values, so that list runs into the thousands — profiled at ~2.3ms per
 * remaining item, i.e. genuinely O(n) per single tub delete, not a fixed
 * cost. This postmeta mirror is never read by this app: relationship data
 * is read canonically from wp_podsrel (see project memory on Pods
 * relationship storage — postmeta mirroring was already found unreliable at
 * scale for tub's own fields). Disabling it here removes pure overhead with
 * no functional effect, and does NOT touch (2)'s sibling wp_podsrel delete —
 * verified directly (both for a plain tub and for a tub with a real slot
 * link, confirming the slot.tub sister-clear still fires) that relationship
 * cleanup stays intact with this off. Scoped to just the pods in tub's own
 * relationship graph, not applied schema-wide, since only these were
 * profiled/verified.
 *
 * 2026-08-14 update: this filter had a real scoping gap, found while
 * chasing the separate tub-SAVE slowness below. `delete_relationships()`
 * passes `$pod` as a `Pods\Whatsit\Pod` OBJECT when it's invoked from
 * PodsField_Pick::save()'s bidirectional-removal call (i.e. every ordinary
 * tub save that changes location/use away from its old value) — the
 * `is_array($pod)` check below is false for that shape, so the filter
 * silently fell through to `$enabled` (true) and the O(n) meta-mirror
 * thrash kept running on exactly this path even with this "fix" in place.
 * It only ever matched the plain-array `$pod` shape that
 * `pods_api()->delete_pod_item()` happens to pass, which is why the full
 * batch-delete measurement above looked fixed while ordinary saves stayed
 * slow. Broadened to also read the name off array-accessible objects
 * (`Pods\Whatsit\Pod` implements `ArrayAccess`), confirmed via PHP CLI:
 * moving a tub out of a 2,636-tub location dropped from ~5.0s to ~15ms for
 * this step alone.
 */
add_filter('pods_relationship_meta_storage_enabled', 'scoop_disable_tub_relationship_meta_mirror', 10, 3);
function scoop_disable_tub_relationship_meta_mirror($enabled, $field, $pod) {
  $scoped_pods = ['tub', 'batch', 'flavor', 'location', 'use', 'slot', 'closeout'];
  $pod_name = scoop_pod_name($pod);
  if ($pod_name !== null && in_array($pod_name, $scoped_pods, true)) {
    return false;
  }
  return $enabled;
}

if (!function_exists('scoop_pod_name')) {
  /**
   * Read a pod's name, tolerant of the array (older Pods) and
   * Whatsit\Pod object (2.8+/3.x, ArrayAccess) shapes Pods passes around.
   */
  function scoop_pod_name($pod): ?string {
    if (is_array($pod)) return $pod['name'] ?? null;
    if (is_object($pod) && $pod instanceof ArrayAccess && isset($pod['name'])) return (string)$pod['name'];
    return null;
  }
}

/**
 * A plain tub SAVE that sets/changes location or use was measured at
 * 5-11s — a different Pods bug from the delete-side one above, not touched
 * by scoop_disable_tub_relationship_meta_mirror(). See project memory
 * tub-save-relationship-loop-bug.
 *
 * Root cause: for a bidirectional sister-paired field, PodsField_Pick::save()
 * (pick.php ~2071) calls
 *   PodsAPI::save_relationships($related_id, $bidirectional_ids, $related_pod, $related_field)
 * where $bidirectional_ids is the RELATED item's entire current relationship
 * list plus the one new id being added. Concretely: saving tub.location
 * calls save_relationships() on location's "tubs" field with the FULL list
 * of every tub currently at that location (thousands, for the common
 * locations — virtually every tub shares one of a handful of location/use
 * values). save_relationships()'s "Relationships table" sync
 * (PodsAPI.php ~6446-6506) then loops that entire list doing one individual
 * UPDATE-or-INSERT wp_podsrel query per item, even though only the single
 * newly-added tub actually needs a new row — every other row gets rewritten
 * with identical data. Confirmed via PHP CLI: 5.0-11.0s per save, scaling
 * with the target location/use's tub count (2,639-member location: 11.0s;
 * a smaller one: 5.5s) — same O(n) signature as the delete bug, different
 * code path, unconditional on `pods_podsrel_enabled()` rather than the
 * metadata-mirror filter.
 *
 * Fix: skip Pods' own per-item loop for exactly this field pairing (the
 * reverse "tubs" field on location/use — matched by field name + pick
 * target rather than field ID, since field IDs aren't guaranteed stable
 * across environments, see the Schema-Sync sister_id gap in project
 * memory) via the `pods_podsrel_enabled` filter, scoped to context==='save'
 * only so 'lookup'/'lookup-from' reads are completely untouched — every
 * read still hits the real, unmodified wp_podsrel table. Once Pods' loop is
 * skipped, `scoop_bulk_write_tub_reverse_relationship()` performs the
 * equivalent write itself: one indexed SELECT (on the field_id+item_id key
 * that already exists on wp_podsrel) for the item's current related-id set,
 * diffed in PHP against the full incoming list, then a single bulk
 * multi-row INSERT for ids that are new and a single bulk DELETE for ids
 * that dropped off (covers pick_limit eviction, even though location/use
 * have none today) — never touching rows that didn't change. Weight is not
 * preserved/renumbered for existing rows (new rows are appended after the
 * current max) — confirmed nothing in this codebase reads location.tubs /
 * use.tubs order (both are `data_type=ids, hidden=true` membership lookups
 * in _specs.php, never a display list), only their membership.
 *
 * Known narrow gap: `pods_podsrel_enabled('save')` is also consulted a
 * handful of times inside Pods' *field-definition* save path
 * (PodsAPI::save_field(), re-linking a field's own sister_id bookkeeping
 * rows when a field's config is edited in Pods Admin) — the same field
 * name/shape match could theoretically fire there too. That path only runs
 * when someone edits the location/use "tubs" field's own schema entry in
 * Pods Admin (not on ordinary app writes), and only skips a metadata
 * self-correction on the field-definition rows, not tub data — accepted as
 * a low-frequency, low-blast-radius edge case rather than engineered
 * around.
 */
add_filter('pods_podsrel_enabled', 'scoop_skip_tub_reverse_relationship_resync', 10, 3);
function scoop_skip_tub_reverse_relationship_resync($enabled, $field, $context) {
  if (!$enabled || 'save' !== $context) return $enabled;
  if (!scoop_is_tub_reverse_relationship_field($field)) return $enabled;
  return false;
}

add_action('pods_api_save_relationships', 'scoop_bulk_write_tub_reverse_relationship', 10, 4);
function scoop_bulk_write_tub_reverse_relationship($id, $related_ids, $field, $pod) {
  if (!scoop_is_tub_reverse_relationship_field($field)) return;

  global $wpdb;
  $lap_start = microtime(true);

  $item_id  = (int)$id;
  $field_id = (int)($field['id'] ?? 0);
  $pod_id   = (int)($pod['id'] ?? 0);
  if (!$item_id || !$field_id || !$pod_id) {
    scoop_batch_debug("scoop_bulk_write_tub_reverse_relationship: could not resolve item/field/pod id (item={$item_id} field={$field_id} pod={$pod_id}) — relationship NOT written");
    return;
  }

  $tub_field = $field->get_bidirectional_field();
  if (!$tub_field) {
    scoop_batch_debug("scoop_bulk_write_tub_reverse_relationship: no bidirectional field for field_id={$field_id} — relationship NOT written");
    return;
  }
  $related_field_id = (int)($tub_field['id'] ?? 0);
  $tub_pod = $tub_field->get_parent_object();
  $related_pod_id = (int)($tub_pod['id'] ?? 0);
  if (!$related_field_id || !$related_pod_id) {
    scoop_batch_debug("scoop_bulk_write_tub_reverse_relationship: could not resolve tub-side field/pod id — relationship NOT written");
    return;
  }

  $wanted_ids = array_unique(array_map('intval', (array)$related_ids));
  $table = $wpdb->prefix . 'podsrel';

  $existing_ids = $wpdb->get_col($wpdb->prepare(
    "SELECT related_item_id FROM {$table} WHERE pod_id = %d AND field_id = %d AND item_id = %d",
    $pod_id, $field_id, $item_id
  ));
  $existing_ids = array_map('intval', $existing_ids);

  $to_insert = array_values(array_diff($wanted_ids, $existing_ids));
  $to_remove = array_values(array_diff($existing_ids, $wanted_ids));

  if ($to_remove) {
    $placeholders = implode(',', array_fill(0, count($to_remove), '%d'));
    $wpdb->query($wpdb->prepare(
      "DELETE FROM {$table} WHERE pod_id = %d AND field_id = %d AND item_id = %d AND related_item_id IN ({$placeholders})",
      array_merge([$pod_id, $field_id, $item_id], $to_remove)
    ));
  }

  if ($to_insert) {
    $next_weight = count($existing_ids) - count($to_remove);
    $values = [];
    foreach ($to_insert as $related_item_id) {
      $values[] = $wpdb->prepare(
        '(%d, %d, %d, %d, %d, %d, %d)',
        $pod_id, $field_id, $item_id, $related_pod_id, $related_field_id, $related_item_id, $next_weight++
      );
    }
    $wpdb->query("INSERT INTO {$table} (pod_id, field_id, item_id, related_pod_id, related_field_id, related_item_id, weight) VALUES " . implode(',', $values));
  }

  scoop_batch_debug("scoop_bulk_write_tub_reverse_relationship: item={$item_id} field={$field_id} +" . count($to_insert) . "/-" . count($to_remove) . " in " . scoop_batch_elapsed_ms($lap_start) . "ms");
}

function scoop_is_tub_reverse_relationship_field($field): bool {
  if (!is_object($field)) return false;
  $name = (string)($field['name'] ?? '');
  if ('tubs' !== $name) return false;

  $pick_val = method_exists($field, 'get_arg') ? $field->get_arg('pick_val') : ($field['pick_val'] ?? null);
  if ('tub' !== $pick_val) return false;

  if (!method_exists($field, 'get_parent_object')) return false;
  $parent = $field->get_parent_object();
  if (!$parent) return false;
  $parent_name = (string)($parent['name'] ?? '');
  return in_array($parent_name, ['location', 'use'], true);
}
