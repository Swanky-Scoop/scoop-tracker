<?php
  /**
   * includes/rest/php
   * 
   * Combined handler for GET/POST.
   */
  function scoop_handle_request(\WP_REST_Request $req, array $cfg, string $route_key) {
    
    $allowed_fields = [];
    if (!empty($cfg['allowed_fields_cb']) && is_callable($cfg['allowed_fields_cb'])) {
      $allowed_fields = call_user_func($cfg['allowed_fields_cb'], wp_get_current_user());
    } else {
      error_log("🔍 TRACE: [$route_key] No allowed_fields_cb configured or not callable");
    }

    if ($req->get_method() === 'GET') {
      return new \WP_REST_Response([
        'ok'            => true,
        'route'         => $route_key,
        'message'       => "scoop/v1/{$route_key} alive",
        'allowed_fields'=> array_values($allowed_fields),
        'time'          => current_time('mysql'),
      ], 200);
    }

    if ($cfg['mode'] === 'create') {
      return scoop_handle_create_post($req, $cfg, $allowed_fields);
    } else {
      return scoop_handle_cells_post( $req, $cfg, $allowed_fields);
    }
  }

  /**
   * POST handler.
   */
  function scoop_handle_create_post(\WP_REST_Request $req, array $cfg, array $allowed_fields) {

    $envelope_key = $cfg['envelope_key'] ?? null;
    if (!$envelope_key) {
      error_log("🔍 TRACE: ERROR - Missing envelope_key in config");
      return new \WP_REST_Response(['ok'=>false,'error'=>'Misconfigured endpoint (missing envelope_key).'], 500);
    }

    $payload = $req->get_param($envelope_key);
    if (!is_array($payload)) {
      error_log("🔍 TRACE: ERROR - Missing or invalid $envelope_key payload");
      return new \WP_REST_Response(['ok'=>false,'error'=>"Missing or invalid {$envelope_key} payload."], 400);
    }

    $cells = $payload['cells'] ?? null;
    if (!is_array($cells)) {
      return new \WP_REST_Response(['ok'=>false,'error'=>"Missing {$envelope_key}[cells]."], 400);
    }

    if (count($cells) !== 1) {
      error_log("🔍 TRACE: ERROR - Expected 1 row, got " . count($cells));
      return new \WP_REST_Response(['ok' => false, 'error' => 'Create expects exactly one row in cells.'], 400);
    }

    $row = reset($cells);
    if (!is_array($row)) {
      error_log("🔍 TRACE: ERROR - Invalid row data");
      return new \WP_REST_Response(['ok'=>false,'error'=>'Invalid row'], 400);
    }

    $pod_name  = $cfg['pod_name'] ?? '';
    if (!$pod_name) {
      error_log("🔍 TRACE: ERROR - Missing pod_name in config");
      return new \WP_REST_Response(['ok'=>false,'error'=>'Misconfigured endpoint (missing pod_name).'], 500);
    }

    $request_start = microtime(true);
    if (function_exists('scoop_debug_log')) {
      scoop_debug_log("create {$pod_name}: starting save");
    }

    $new_id = scoop_create_pod_item($pod_name, $allowed_fields, $row);
    
    if (is_wp_error($new_id)) {
      error_log("🔍 TRACE: ERROR - Create failed: " . $new_id->get_error_message());
      return new \WP_REST_Response([
        'ok' => false,
        'errors' => [
          ['field' => null, 'error' => $new_id->get_error_message()]
        ]
      ], 400);
    }

    if (function_exists('scoop_debug_log')) {
      $elapsed = (int)round((microtime(true) - $request_start) * 1000);
      scoop_debug_log("create {$pod_name}: saved id=" . (int)$new_id . " in {$elapsed}ms; starting audit log");
    }

    $audit_start = microtime(true);
    scoop_log_post($req, $cfg, $row, [], (int)$new_id);
    if (function_exists('scoop_debug_log')) {
      $audit_elapsed = (int)round((microtime(true) - $audit_start) * 1000);
      $total_elapsed = (int)round((microtime(true) - $request_start) * 1000);
      scoop_debug_log("create {$pod_name}: audit log finished in {$audit_elapsed}ms; total {$total_elapsed}ms");
    }

    return new \WP_REST_Response([
      'ok' => true,
      'type' => $pod_name,
      'created' => ['id' => (int)$new_id],
    ], 200);
  }

  /**
   * DELETE /scoop/v1/batches/{id} — removes a batch and every tub created
   * from it. Irreversible (track_pods_* tables are MyISAM, no transactions —
   * see CLAUDE.md's Data repair policy), so tubs are deleted first and the
   * batch last: a failure partway through leaves orphaned tubs (recoverable
   * by re-running the delete) rather than a tub-less batch pointing at
   * nothing. Uses pods_api()->delete_pod_item() (not a direct wpdb delete)
   * so Pods' own table + relationship cleanup runs, same reasoning as the
   * "safe" pods-api create path documented in hooks/batch-tub.php.
   */
  function scoop_handle_batch_delete(\WP_REST_Request $req) {
    $batch_id = (int)$req->get_param('id');

    if ($batch_id <= 0) {
      return new \WP_REST_Response(['ok' => false, 'error' => 'Invalid batch id.'], 400);
    }

    $post = get_post($batch_id);
    if (!$post || $post->post_type !== 'batch') {
      return new \WP_REST_Response(['ok' => false, 'error' => 'Batch not found.'], 404);
    }

    if (!function_exists('pods_api') || !is_object(pods_api()) || !function_exists('pods')) {
      return new \WP_REST_Response(['ok' => false, 'error' => 'Pods API not available.'], 500);
    }

    $batch       = pods('batch', $batch_id);
    $flavor_id   = ($batch && $batch->exists()) ? (int)$batch->field('flavor.ID') : 0;
    $batch_title = get_the_title($batch_id) ?: "Batch {$batch_id}";

    $tub_ids = scoop_inventory_change_tubs_for_relation('batch.ID = ' . $batch_id);

    $deleted_tubs = [];
    foreach ($tub_ids as $tub_id) {
      $result = pods_api()->delete_pod_item(['pod' => 'tub', 'id' => $tub_id]);
      if ($result) {
        $deleted_tubs[] = $tub_id;
      } else {
        error_log("scoop_handle_batch_delete: failed to delete tub {$tub_id} for batch {$batch_id}");
      }
    }

    $batch_deleted = pods_api()->delete_pod_item(['pod' => 'batch', 'id' => $batch_id]);

    if (!$batch_deleted) {
      error_log("scoop_handle_batch_delete: failed to delete batch {$batch_id}");
      return new \WP_REST_Response([
        'ok'    => false,
        'error' => 'Batch delete failed.',
        'deleted_tubs' => $deleted_tubs,
      ], 500);
    }

    $user  = wp_get_current_user()->user_login;
    $date  = date('D m/d');
    $tub_count = count($deleted_tubs);
    $details = "<strong>{$batch_title}</strong><br />Removed {$batch_title} and {$tub_count} tub" . ($tub_count === 1 ? '' : 's') . '.';

    $change_id = scoop_inventory_change_add([
      'post_status'  => 'publish',
      'title'        => "{$user} deleted {$batch_title} on {$date}",
      'change_count' => 1,
      'entity'       => 'batch',
      'envelope'     => 'BatchHistory',
      'mode'         => 'delete',
      'phase'        => 'deleted',
      'source'       => 'batch',
      'problem'      => 'none',
      'tubs'         => $deleted_tubs,
      'flavors'      => $flavor_id ? [$flavor_id] : [],
      'details'      => $details,
      'post_content' => wp_kses($details, ['strong' => [], 'br' => []]),
    ], [
      'entity'   => 'batch',
      'mode'     => 'delete',
      'batch_id' => $batch_id,
    ]);

    if (!$change_id) {
      error_log("scoop_handle_batch_delete: inventory_change add returned empty result for batch={$batch_id}");
    }

    return new \WP_REST_Response([
      'ok' => true,
      'deleted' => ['id' => $batch_id, 'tubs' => $deleted_tubs],
    ], 200);
  }

  /**
   * GET /scoop/v1/shift-report-fields — live field schema for the
   * end-of-shift report form, grouped and ordered exactly as configured in
   * Pods admin. See WHITEBOARD-INGESTION.md: the form used to hardcode its
   * field list, so every new Pods field needed a matching code change
   * before it would appear. This makes the form (assets/ui/shift-report-form.js)
   * schema-driven instead — new fields (and new Pods field GROUPS, used as
   * the form's own section headers) show up automatically. A handful of
   * fields still get bespoke rendering client-side (photo upload,
   * flavors_changed's cabinet-grouped picker, supplies_low's
   * category-grouped picker, cake_orders' create-new-record flow) because
   * their UX can't be derived from field metadata alone — everything else
   * renders generically off what's returned here.
   *
   * Pods' own field-level 'group' is a numeric id referencing a pod-level
   * group definition (label/weight) — NOT the same thing as the 'supply'
   * pod's own custom 'group' field used for the supplies_low checklist
   * (see supply's entity spec) — different concepts that happen to share a
   * name.
   */
  function scoop_shift_report_field_schema(): array {
    if (!function_exists('pods_api')) return ['groups' => []];

    $pod = pods_api()->load_pod(['name' => 'shift_report']);
    if (!$pod || empty($pod['fields'])) return ['groups' => []];

    $groupsById = [];
    $order = 0;

    // Pod-level group definitions (label/weight) — keyed here by the
    // group's own numeric id, since that's what each field's 'group'
    // property references, not the group's name key in $pod['groups'].
    foreach (($pod['groups'] ?? []) as $groupDef) {
      $id = (string)($groupDef['id'] ?? '');
      if ($id === '') continue;

      $groupsById[$id] = [
        // 'name' is the stable Pods group slug (e.g. "end_of_day") — unlike
        // 'label', which is just display text an admin could rename, this
        // is what client code should match against for anything that needs
        // to target a specific group (e.g. shift-report-form.js's
        // conditional visibility for the "End of day" group).
        'name'   => $groupDef['name'] ?? $id,
        'label'  => $groupDef['label'] ?? $id,
        'weight' => (int)($groupDef['weight'] ?? 0),
        // Tie-break for equal-weight groups — PHP 8's usort is stable, so
        // this preserves $pod['groups']'s own array order, which reflects
        // Pods admin's actual configured order.
        'order'  => $order++,
        'fields' => [],
      ];
    }

    foreach ($pod['fields'] as $name => $field) {
      $groupId = (string)($field['group'] ?? '');
      if (!isset($groupsById[$groupId])) {
        // A field with no matching group definition (shouldn't normally
        // happen, but Pods data can drift) — surfaced under its own
        // fallback group rather than silently dropped.
        $groupsById[$groupId] = ['name' => 'other', 'label' => 'Other', 'weight' => PHP_INT_MAX, 'order' => $order++, 'fields' => []];
      }
      $groupsById[$groupId]['fields'][] = scoop_shift_report_field_entry((string)$name, $field);
    }

    foreach ($groupsById as &$g) {
      usort($g['fields'], fn($a, $b) => $a['weight'] <=> $b['weight']);
    }
    unset($g);

    $groups = array_values($groupsById);
    usort($groups, fn($a, $b) => $a['weight'] <=> $b['weight'] ?: $a['order'] <=> $b['order']);

    return ['groups' => $groups];
  }

  /**
   * One field's render-relevant metadata — enough for a generic client-side
   * renderer to build the right control (text/textarea/number/date/
   * checkbox/radio/select) without knowing the field ahead of time. Pick
   * fields' option lists are resolved here (custom lists via
   * scoop_pods_dropdown_options(), relationship fields via a live query on
   * the related pod) so the client never needs its own Pods knowledge.
   */
  function scoop_shift_report_field_entry(string $name, $field): array {
    // Pods field definitions can come back as Pods\Whatsit\Field objects
    // (confirmed directly 2026-08-11: array-vs-object depends on the Pods
    // version/context, not something to assume either way) — same
    // normalization scoop_pods_field_def() already does. Whatsit objects
    // support array-style property access via ArrayAccess, so this loop's
    // own $field['key'] reads below already work either way; only the
    // strict `array` type hint on this function's own signature needed
    // loosening.
    if (is_object($field)) {
      if (method_exists($field, 'export')) {
        $field = $field->export();
      } elseif (method_exists($field, 'to_array')) {
        $field = $field->to_array();
      } else {
        $field = (array)$field;
      }
    }
    if (!is_array($field)) $field = [];

    $entry = [
      'name'        => $name,
      'label'       => $field['label'] ?? $name,
      'description' => $field['description'] ?? '',
      'type'        => $field['type'] ?? 'text',
      'weight'      => (int)($field['weight'] ?? 0),
      'required'    => !empty($field['required']),
    ];

    if ($entry['type'] === 'boolean') {
      $entry['format']   = $field['boolean_format_type'] ?? 'checkbox';
      $entry['yesLabel'] = $field['boolean_yes_label'] ?? 'Yes';
      $entry['noLabel']  = $field['boolean_no_label'] ?? 'No';
    }

    if ($entry['type'] === 'pick') {
      $entry['multi'] = ($field['pick_format_type'] ?? 'single') === 'multi';
      $pickObject = $field['pick_object'] ?? '';

      if ($pickObject === 'custom-simple') {
        $entry['options'] = array_map(
          fn($o) => ['id' => $o['key'], 'title' => $o['label']],
          scoop_pods_dropdown_options('shift_report', $name)
        );
      } elseif ($pickObject === 'post_type' && !empty($field['pick_val'])) {
        $entry['relatedPod'] = $field['pick_val'];

        // flavors_changed/supplies_low/cake_orders keep their own hand-built
        // client-side logic (cabinet-grouped current-flavor picker,
        // category-grouped supply checklist, create-new-record flow — see
        // WHITEBOARD-INGESTION.md) and never read this generic options list,
        // so skip fetching it for them — flavors_changed alone would mean
        // querying and returning the entire ~200-item flavor catalog on
        // every call to this endpoint for a client that discards it.
        static $skipGenericOptions = ['flavors_changed', 'supplies_low', 'cake_orders'];
        if (!in_array($name, $skipGenericOptions, true) && function_exists('pods')) {
          $options = [];
          $related = pods($field['pick_val'], ['limit' => -1]);
          if ($related) {
            while ($related->fetch()) {
              $options[] = ['id' => $related->id(), 'title' => get_the_title($related->id())];
            }
          }
          $entry['options'] = $options;
        } else {
          $entry['options'] = [];
        }
      }
    }

    return $entry;
  }

  /**
   * POST /scoop/v1/shift-reports — end-of-shift report create. Custom route
   * (see includes/_routes.php), not the generic per-type create dispatch
   * (scoop_handle_create_post): cake_orders on shift_report means the
   * staffer creates brand-new cake_order records as part of this submission
   * (never picks from existing ones — cake_order has no shift_report
   * back-reference field, the link is one-directional via
   * shift_report.cake_orders), and the generic dispatch only ever creates
   * exactly one row of one pod per call. Body shape:
   *   { "ShiftReport": { ...shift_report fields..., "new_cake_orders": [
   *       { "order_name": "...", "cake_pie_flavor": "...",
   *         "pickup_date": "...", "details": "..." }, ... ] } }
   *
   * MyISAM, no transactions (see CLAUDE.md's data-repair policy) — cake_order
   * posts are created FIRST, then shift_report is created with their ids
   * already embedded in its own cake_orders field. This order avoids ever
   * needing a follow-up UPDATE to shift_report: a failed cake_order row is
   * simply left out of shift_report.cake_orders and reported back, while the
   * report itself still saves cleanly either way.
   */
  /* ------------------------------------------------------------
   * Debt board — /debt-requests (worktree-tub-moving)
   *
   * The Debt grid's rows are SYNTHETIC (destination, flavor) pairs, not
   * flavor_request post ids, so the generic update-mode cells dispatch
   * (scoop_handle_cells_post, which saves by numeric row id) structurally
   * cannot serve them. This dedicated POST route upserts one
   * flavor_request row per (location, flavor) instead — the same
   * "dedicated route because the row shape doesn't fit the generic
   * dispatch" reasoning as ShiftReport's /shift-reports above.
   *
   * Body shape (envelope key = route key, the same client contract as
   * every other write — see ScoopAPI.postJson's "THE RULE"):
   *   { "Debt": { "cells": { "1010:600": { "wanted": 3 } } } }
   * Each cell key is "<locationId>:<flavorId>". wanted=0 deletes the
   * override row (a missing row means "no override, slots rule"); a
   * positive wanted upserts/replaces the row's wanted.
   *
   * flavor_request is NOT in inventory_change scope: like slot flavor
   * designations (see scoop_should_log_inventory_change), this is
   * planning intent, not a stock movement — the audit table stays free
   * of it.
   * ------------------------------------------------------------ */

  /**
   * Parse + validate one /debt-requests body into upsert/delete ops.
   * Pure (no WP/Pods calls) so it's testable in-box; the handler below
   * owns all persistence. Returns [ops, errors] where each op is
   * ['type' => 'upsert'|'delete', 'location' => int, 'flavor' => int,
   * 'wanted' => int (upsert only)].
   *
   * Cell keys are the Debt grid's SYNTHETIC NUMERIC row ids —
   * location*100000 + flavor (see PAIR_ID_MULTIPLIER in
   * assets/models/debt-grid-model.js; List's write path Number()s row ids,
   * so the "1010:600" string form would die as NaN client-side). Decoded
   * here by divmod, never trusted blindly: flavor must be 1..99999 and
   * location >= 1.
   */
  function scoop_parse_debt_requests(array $payload): array {
    $errors = [];
    $ops    = [];

    $cells = $payload['cells'] ?? null;
    if (!is_array($cells)) {
      return [[], ['Missing Debt[cells].']];
    }

    foreach ($cells as $row_key => $fields) {
      if (!is_array($fields)) {
        $errors[] = 'Cell ' . (string) $row_key . ': not an object.';
        continue;
      }

      $pair_id = filter_var($row_key, FILTER_VALIDATE_INT);
      if ($pair_id === false || $pair_id <= 0) {
        $errors[] = 'Cell ' . (string) $row_key . ": malformed row id (expected location*100000+flavor).";
        continue;
      }

      $flavor_id   = $pair_id % 100000;
      $location_id = intdiv($pair_id - $flavor_id, 100000);

      if ($location_id < 1 || $flavor_id < 1 || $flavor_id > 99999) {
        $errors[] = "Cell {$row_key}: row id decodes to an invalid location/flavor pair.";
        continue;
      }

      if (!array_key_exists('wanted', $fields)) {
        $errors[] = "Cell {$row_key}: missing 'wanted'.";
        continue;
      }

      $wanted = filter_var($fields['wanted'], FILTER_VALIDATE_INT);
      if ($wanted === false) {
        $errors[] = "Cell {$row_key}: 'wanted' must be a whole number.";
        continue;
      }

      // 0 = "no override" — delete any existing row. Negative values are
      // a client bug: refuse rather than clamp silently.
      if ($wanted < 0 || $wanted > 99) {
        $errors[] = "Cell {$row_key}: 'wanted' must be between 0 and 99.";
        continue;
      }

      $ops[] = $wanted === 0
        ? ['type' => 'delete', 'location' => $location_id, 'flavor' => $flavor_id]
        : ['type' => 'upsert', 'location' => $location_id, 'flavor' => $flavor_id, 'wanted' => $wanted];
    }

    return [$ops, $errors];
  }

  function scoop_handle_debt_requests_post(\WP_REST_Request $req) {
    $payload = $req->get_param('Debt');
    if (!is_array($payload)) {
      return new \WP_REST_Response(['ok' => false, 'error' => 'Missing or invalid Debt payload.'], 400);
    }

    [$ops, $errors] = scoop_parse_debt_requests($payload);
    if ($errors) {
      return new \WP_REST_Response(['ok' => false, 'error' => implode(' ', $errors)], 400);
    }
    if (!$ops) {
      return new \WP_REST_Response(['ok' => false, 'error' => 'No cells to save.'], 400);
    }

    if (!function_exists('pods')) {
      error_log('Scoop /debt-requests: Pods API not available');
      return new \WP_REST_Response(['ok' => false, 'error' => 'Pods API not available.'], 500);
    }
    $api = pods_api();
    if (!$api) {
      return new \WP_REST_Response(['ok' => false, 'error' => 'Pods API not available.'], 500);
    }

    // One upsert per (location, flavor): find the existing row for the
    // pair, then save (update) or create. pods() with a where clause is
    // the established read here (same as the slot dedupe hook's lookup).
    $updated = [];
    foreach ($ops as $op) {
      $pair_key = $op['location'] * 100000 + $op['flavor']; // inverse of the client's synthetic row id

      $existing = pods('flavor_request', [
        'where' => sprintf(
          'location.ID = %d AND flavor.ID = %d',
          $op['location'],
          $op['flavor']
        ),
        'limit' => 1,
      ]);
      $existing_id = ($existing && $existing->total() > 0) ? (int) $existing->field('ID') : 0;

      if ($op['type'] === 'delete') {
        if (!$existing_id) continue; // nothing to delete — idempotent no-op
        $res = wp_delete_post($existing_id, true);
        if (!$res) {
          error_log("Scoop /debt-requests: delete failed for flavor_request {$existing_id}");
          $errors[] = "Delete failed for {$op['location']}:{$op['flavor']}.";
          continue;
        }
        $updated[(string) $pair_key] = ['wanted' => 0];
        continue;
      }

      $title = sprintf(
        '%s | %s',
        get_the_title($op['location']) ?: ("Location {$op['location']}"),
        get_the_title($op['flavor'])   ?: ("Flavor {$op['flavor']}"),
      );

      $data = [
        'post_title' => $title,
        'post_status' => 'publish',
        'location' => $op['location'],
        'flavor'   => $op['flavor'],
        'wanted'   => $op['wanted'],
      ];

      $res = $existing_id
        ? $api->save_pod_item(['pod' => 'flavor_request', 'id' => $existing_id, 'data' => $data])
        : $api->save_pod_item(['pod' => 'flavor_request', 'data' => $data]);

      if (is_wp_error($res) || !$res) {
        $msg = is_wp_error($res) ? $res->get_error_message() : 'Save failed';
        error_log("Scoop /debt-requests: save failed for {$op['location']}:{$op['flavor']}: {$msg}");
        $errors[] = "Save failed for {$op['location']}:{$op['flavor']}.";
        continue;
      }

      $updated[(string) $pair_key] = ['wanted' => $op['wanted']];
    }

    $ok = empty($errors);
    if ($ok) scoop_cache_bust();

    return new \WP_REST_Response([
      'ok'      => $ok,
      'author'  => wp_get_current_user()->user_login,
      'updated' => $updated,
      'errors'  => $errors,
    ], $ok ? 200 : 400);
  }

  function scoop_handle_shift_report_create(\WP_REST_Request $req) {
    $payload = $req->get_param('ShiftReport');
    if (!is_array($payload)) {
      return new \WP_REST_Response(['ok' => false, 'error' => 'Missing or invalid ShiftReport payload.'], 400);
    }

    $new_cake_orders = $payload['new_cake_orders'] ?? [];
    if (!is_array($new_cake_orders)) $new_cake_orders = [];
    unset($payload['new_cake_orders']);

    $user = wp_get_current_user();
    $report_fields = function_exists('scoop_shift_reports_allowed_fields')
      ? scoop_shift_reports_allowed_fields($user) : [];
    $cake_order_fields = function_exists('scoop_cake_orders_allowed_fields')
      ? scoop_cake_orders_allowed_fields($user) : [];

    $cake_order_ids = [];
    $cake_order_errors = [];
    foreach ($new_cake_orders as $order) {
      if (!is_array($order)) continue;

      $order_id = scoop_create_pod_item('cake_order', $cake_order_fields, $order);
      if (is_wp_error($order_id)) {
        $cake_order_errors[] = $order_id->get_error_message();
        error_log('scoop_handle_shift_report_create: cake_order create failed: ' . $order_id->get_error_message());
        continue;
      }
      $cake_order_ids[] = (int)$order_id;
    }

    $payload['cake_orders'] = $cake_order_ids;

    $report_id = scoop_create_pod_item('shift_report', $report_fields, $payload);
    if (is_wp_error($report_id)) {
      error_log('scoop_handle_shift_report_create: report create failed: ' . $report_id->get_error_message());
      return new \WP_REST_Response([
        'ok' => false,
        'errors' => [['field' => null, 'error' => $report_id->get_error_message()]],
        // Cake orders already saved even though the report failed —
        // reported so they aren't silently lost/duplicated on retry.
        'created' => ['cake_order_ids' => $cake_order_ids],
      ], 400);
    }

    return new \WP_REST_Response([
      'ok' => true,
      'created' => ['id' => (int)$report_id, 'cake_order_ids' => $cake_order_ids],
      'cake_order_errors' => $cake_order_errors,
    ], 200);
  }

  /**
   * DELETE /scoop/v1/recipe-counts/{id} and DELETE /scoop/v1/preps/{id} —
   * used by each widget's "attached" history grid in the Task form (see
   * assets/models/task-component-history-grid-model.js). Simpler than
   * scoop_handle_batch_delete(): neither pod cascades to any child records,
   * so there's nothing to clean up beyond the row itself, and no
   * inventory_change log entry — recipe_count/prep are planned-production
   * counts, not tub inventory, so they aren't part of that audit trail.
   */
  function scoop_handle_recipe_count_delete(\WP_REST_Request $req) {
    return scoop_handle_simple_pod_delete($req, 'recipe_count');
  }

  function scoop_handle_prep_delete(\WP_REST_Request $req) {
    return scoop_handle_simple_pod_delete($req, 'prep');
  }

  function scoop_handle_simple_pod_delete(\WP_REST_Request $req, string $pod_name) {
    $id = (int)$req->get_param('id');
    if ($id <= 0) {
      return new \WP_REST_Response(['ok' => false, 'error' => 'Invalid id.'], 400);
    }

    $post = get_post($id);
    if (!$post || $post->post_type !== $pod_name) {
      return new \WP_REST_Response(['ok' => false, 'error' => ucfirst($pod_name) . ' not found.'], 404);
    }

    if (!function_exists('pods_api') || !is_object(pods_api())) {
      return new \WP_REST_Response(['ok' => false, 'error' => 'Pods API not available.'], 500);
    }

    $deleted = pods_api()->delete_pod_item(['pod' => $pod_name, 'id' => $id]);
    if (!$deleted) {
      error_log("scoop_handle_simple_pod_delete: failed to delete {$pod_name} {$id}");
      return new \WP_REST_Response(['ok' => false, 'error' => ucfirst($pod_name) . ' delete failed.'], 500);
    }

    scoop_cache_bust();

    return new \WP_REST_Response(['ok' => true, 'deleted' => ['id' => $id]], 200);
  }

  /**
   * GET /scoop/v1/kitchen-staff — role-filtered staff list for the Task
   * form's 'target' picker (assets/ui/task-form.js). Task.target is a Pods
   * pick_object=user field, which isn't a Pods pod and doesn't fit
   * scoop_fetch_entities()/the bundle pattern — hence its own small endpoint.
   * Scoped to the same kitchen-role set that can actually be assigned a
   * task in practice, not every WP user on the install.
   */
  function scoop_kitchen_staff_handler(\WP_REST_Request $req) {
    return new \WP_REST_Response(['ok' => true, 'staff' => scoop_kitchen_staff_list()], 200);
  }

  /**
   * Shared by scoop_kitchen_staff_handler() above (task-form.js's live
   * /kitchen-staff fetch) and enqueue.php's SCOOP.kitchenStaff (the Tasks
   * grid's Assigned-to FindIt options — see tasks-grid-model.js). The grid
   * needs this synchronously at render time, not via an async fetch-then-
   * repaint dance, so it rides the same wp_localize_script() payload as
   * SCOOP.routes/metaData rather than duplicating this query client-side.
   */
  function scoop_kitchen_staff_list(): array {
    $roles = ['administrator', 'kitchen_manager', 'shift_lead', 'ice_cream_maker'];

    $users = get_users([
      'role__in' => $roles,
      'orderby'  => 'display_name',
      'order'    => 'ASC',
      'fields'   => ['ID', 'display_name'],
    ]);

    return array_map(fn($u) => ['id' => (int)$u->ID, 'title' => $u->display_name], $users);
  }

  /**
   * Slot writes are only a real inventory event when they change the tub
   * link (slot.tub — renamed from slot.tubs; see change-tub.md). As of the
   * tub.slot<->slot.tub bidirectional rewrite, the Confirm Cabinet/add-next/
   * leave-empty flows no longer write slot.tub directly at all (they write
   * tub.slot instead, and Pods syncs this side) — so this check now mostly
   * guards against some other, future direct write to slot.tub. Tub-side
   * inventory logging (state transitions) is handled separately, below.
   * current_flavor/immediate_flavor/next_flavor are cabinet planning/
   * merchandising fields (the ones Cabinet-grid autosave writes on every
   * field edit, see scoop_planning_allowed_slot_fields) — logging those to
   * inventory_change balloons the audit table with planning noise that was
   * never a stock movement. Every other entity type logs as before; this
   * only narrows the 'slot' case.
   */
  function scoop_should_log_inventory_change(array $cfg, array $updated): bool {
    if (($cfg['pod_name'] ?? '') !== 'slot') return true;

    foreach ($updated as $fields) {
      if (is_array($fields) && array_key_exists('tub', $fields)) return true;
    }

    return false;
  }

  function scoop_inventory_change_phase(array $cfg, array $updated = []): string {
    $entity = $cfg['pod_name'] ?? '';
    $mode   = $cfg['mode'] ?? '';

    if ($entity === 'batch' && $mode === 'create') return 'created';
    if ($entity === 'closeout' && $mode === 'create') return 'emptied';

    if ($entity === 'tub') {
      foreach ($updated as $fields) {
        if (!is_array($fields) || !array_key_exists('state', $fields)) continue;
        $state = (string)$fields['state'];
        if ($state === '__override__') return 'overriden';
        if ($state === 'Opened') return 'opened';
        if ($state === 'Emptied') return 'emptied';
      }
    }

    return 'unknown';
  }

  function scoop_inventory_change_source(array $cfg): string {
    $entity = $cfg['pod_name'] ?? '';

    if ($entity === 'batch') return 'batch';
    if ($entity === 'slot') return 'cabinet';
    if ($entity === 'tub') return 'tub';

    return 'audit';
  }

  function scoop_inventory_change_unique_ids(array $ids): array {
    $out = [];
    foreach ($ids as $id) {
      $id = (int)$id;
      if ($id > 0) $out[$id] = $id;
    }
    return array_values($out);
  }

  function scoop_inventory_change_rel_ids($value): array {
    if (empty($value)) return [];

    if (is_numeric($value)) return [(int)$value];

    $ids = [];
    $values = is_array($value) ? $value : [$value];
    foreach ($values as $item) {
      $ids[] = scoop_rel_id($item);
    }

    return scoop_inventory_change_unique_ids($ids);
  }

  function scoop_inventory_change_expected_data_keys(): array {
    return [
      'title',
      'change_count',
      'entity',
      'envelope',
      'mode',
      'phase',
      'source',
      'problem',
      'tubs',
      'flavors',
      'details',
      'post_content',
    ];
  }

  function scoop_inventory_change_expected_custom_fields(): array {
    return [
      'change_count',
      'entity',
      'envelope',
      'mode',
      'phase',
      'source',
      'problem',
      'tubs',
      'flavors',
      'details',
    ];
  }

  function scoop_inventory_change_custom_field_names(): array {
    if (!function_exists('scoop_pods_ready') || !scoop_pods_ready()) return [];

    $pod = pods_api()->load_pod(['name' => 'inventory_change']);
    if (!$pod || !is_array($pod)) return [];

    $fields = $pod['fields'] ?? [];
    return is_array($fields) ? array_keys($fields) : [];
  }

  function scoop_inventory_change_log_failure(string $message, array $data = [], array $context = []): void {
    $custom_fields = scoop_inventory_change_custom_field_names();
    $expected_custom_fields = scoop_inventory_change_expected_custom_fields();

    $diagnostic = [
      'message'                => $message,
      'expected_data_keys'     => scoop_inventory_change_expected_data_keys(),
      'data_keys'             => array_keys($data),
      'expected_custom_fields' => $expected_custom_fields,
      'custom_fields'          => $custom_fields,
      'missing_custom_fields'  => $custom_fields ? array_values(array_diff($expected_custom_fields, $custom_fields)) : $expected_custom_fields,
      'context'                => $context,
    ];

    $encoded = function_exists('wp_json_encode') ? wp_json_encode($diagnostic) : json_encode($diagnostic);
    error_log('Scoop inventory_change audit insert failed. Check that the inventory_change Pod exists and has relationship fields "tubs" and "flavors". ' . $encoded);
  }

  function scoop_inventory_change_add(array $data, array $context = []): int {
    if (!function_exists('pods')) {
      scoop_inventory_change_log_failure('pods() is not available', $data, $context);
      return 0;
    }
    
    try {
      $pod = pods('inventory_change');
      if (!$pod || !method_exists($pod, 'add')) {
        scoop_inventory_change_log_failure('pods("inventory_change") is unavailable or cannot add records', $data, $context);
        return 0;
      }

      $result = $pod->add($data);

      if (is_wp_error($result)) {
        scoop_inventory_change_log_failure('Pods add returned WP_Error: ' . $result->get_error_message(), $data, $context);
        return 0;
      }

      if (empty($result)) {
        scoop_inventory_change_log_failure('Pods add returned an empty result', $data, $context);
        return 0;
      }
    } catch (\Throwable $e) {
      scoop_inventory_change_log_failure('Pods add threw ' . get_class($e) . ': ' . $e->getMessage(), $data, $context);
      return 0;
    }

    return is_int($result) ? $result : 0;
  }

  function scoop_inventory_change_tub_flavor_id(int $tub_id): int {
    if ($tub_id <= 0 || !function_exists('pods')) return 0;

    $tub = pods('tub', $tub_id);
    if (!$tub || !$tub->exists()) return 0;

    return scoop_rel_id($tub->field('flavor'));
  }

  function scoop_inventory_change_tubs_for_relation(string $where): array {
    if (!function_exists('pods')) return [];

    $pod = pods('tub', [
      'where' => $where,
      'limit' => -1,
    ]);
    if (!$pod) return [];

    $ids = [];
    while ($pod->fetch()) {
      $ids[] = (int)$pod->id();
    }

    return scoop_inventory_change_unique_ids($ids);
  }

  function scoop_inventory_change_refs(array $cfg, array $updated = [], int $created_id = 0): array {
    $entity = $cfg['pod_name'] ?? '';
    $tubs   = [];
    $flavors = [];

    if ($entity === 'tub') {
      foreach ($updated as $row_id => $fields) {
        $tub_id = (int)$row_id;
        if ($tub_id <= 0) continue;

        $tubs[] = $tub_id;
        if (is_array($fields) && array_key_exists('flavor', $fields)) {
          $flavors[] = scoop_rel_id($fields['flavor']);
        } else {
          $flavors[] = scoop_inventory_change_tub_flavor_id($tub_id);
        }
      }
    } elseif ($entity === 'batch') {
      $flavors[] = scoop_rel_id($updated['flavor'] ?? null);
      if ($created_id > 0) {
        $tubs = array_merge($tubs, scoop_inventory_change_tubs_for_relation('batch.ID = ' . $created_id));
      }
    } elseif ($entity === 'closeout') {
      $flavors[] = scoop_rel_id($updated['flavor'] ?? null);
      if ($created_id > 0 && function_exists('pods')) {
        $closeout = pods('closeout', $created_id);
        if ($closeout && $closeout->exists()) {
          $tubs = array_merge($tubs, scoop_inventory_change_rel_ids($closeout->field('tub')));
        }
        if (empty($tubs)) {
          $tubs = array_merge($tubs, scoop_inventory_change_tubs_for_relation('closeout.ID = ' . $created_id));
        }
      }
    } elseif ($entity === 'slot') {
      foreach ($updated as $fields) {
        if (!is_array($fields)) continue;
        foreach (['current_flavor', 'immediate_flavor', 'next_flavor'] as $field) {
          if (array_key_exists($field, $fields)) $flavors[] = scoop_rel_id($fields[$field]);
        }
      }
    }

    foreach ($tubs as $tub_id) {
      $flavors[] = scoop_inventory_change_tub_flavor_id((int)$tub_id);
    }

    return [
      'tubs'    => scoop_inventory_change_unique_ids($tubs),
      'flavors' => scoop_inventory_change_unique_ids($flavors),
    ];
  }


  /**
   * Audit shaping for mode:'create' tub writes — i.e. TubSplit, "split for
   * another use" (see OTHER-USES.md and scoop_create_pod_item's tub branch
   * in _write_fields.php). NOT the shapes the generic branches expect: the
   * client row is a flat {use, amount, origin_tub_id} map (not the
   * update-mode tub-id => fields shape refs/phase iterate), and $created_id
   * is either the NEW tub (true split) or the ORIGIN itself
   * (convert-in-place, because that branch updates the origin and returns
   * its own id). Feeding this shape to the batch-flavored create title and
   * the update-shaped refs/phase branches logged junk — phase 'unknown',
   * empty tubs/flavors relations, title "created 0 tub of  on …".
   *
   * Returns the override slices scoop_log_post() merges into its record:
   *
   *   - True split (created_id != origin_tub_id): phase 'created', tubs =
   *     [new tub, origin], details under the new tub's title naming
   *     use/amount/origin, plus a row for the origin's post-split amount
   *     (the origin-reduction second write has already run by the time this
   *     is called, so the fresh amount read here IS what the origin shows;
   *     if that write failed the row just shows the amount it still has).
   *   - Convert-in-place (created_id == origin_tub_id): the origin was
   *     UPDATED (use + state Emptied), not created — mode 'update', phase
   *     'converted', details = the fields actually written. The envelope
   *     still identifies the TubSplit endpoint, so recording the record as
   *     an update keeps it honest about the write shape without losing
   *     endpoint forensics.
   *   - No origin_tub_id (no such route today): a plain new-tub create.
   *
   * Returns null only when there is no created item at all (failed/error
   * create) — the generic error-record path handles those unchanged.
   */
  function scoop_inventory_change_tub_create_audit(array $updated, int $created_id, string $user, string $date): ?array {
    if ($created_id <= 0) return null;

    $origin_id  = (int)($updated['origin_tub_id'] ?? 0);
    $use_id     = (int)($updated['use'] ?? 0);
    $amount     = isset($updated['amount']) && is_numeric($updated['amount']) ? (float)$updated['amount'] : 0.0;
    $is_convert = ($origin_id > 0 && $origin_id === $created_id);

    $origin = ($origin_id > 0 && function_exists('pods')) ? pods('tub', $origin_id) : null;
    $origin_exists = $origin && $origin->exists();

    // The new tub carries the origin's flavor (copied in scoop_create_pod_item).
    $flavor_id = $origin_exists
      ? scoop_rel_id($origin->field('flavor'))
      : scoop_inventory_change_tub_flavor_id($created_id);

    $flavor_t = get_the_title($flavor_id) ?: '';
    $use_t    = get_the_title($use_id) ?: ($use_id > 0 ? "Use {$use_id}" : '');
    $origin_t = get_the_title($origin_id) ?: "Tub {$origin_id}";
    $of       = ($flavor_t !== '') ? " of {$flavor_t}" : '';

    // Read fresh at log time: by now scoop_create_pod_item's second write
    // has already reduced the origin's amount (or failed and left it as-is
    // — either way this is what the origin actually shows right now).
    $origin_amount_now = ($origin_exists && is_object($origin)) ? (float)$origin->field('amount') : 0.0;

    if ($is_convert) {
      return [
        'mode'  => 'update',
        'phase' => 'converted',
        'count' => 1,
        'title' => "{$user} converted {$origin_t}{$of}" . ($use_t !== '' ? " to {$use_t}" : '') . " on {$date}",
        // Exactly what the convert branch wrote — amount is deliberately
        // absent (it was left untouched).
        'detail_rows' => [$origin_id => ['use' => $use_id, 'state' => 'Emptied']],
        'tubs'    => [$origin_id],
        'flavors' => $flavor_id > 0 ? [$flavor_id] : [],
      ];
    }

    if ($origin_id <= 0) {
      return [
        'mode'  => 'create',
        'phase' => 'created',
        'count' => 1,
        'title' => "{$user} created 1 tub{$of} on {$date}",
        'detail_rows' => [$created_id => $updated],
        'tubs'    => [$created_id],
        'flavors' => $flavor_id > 0 ? [$flavor_id] : [],
      ];
    }

    return [
      'mode'  => 'create',
      'phase' => 'created',
      'count' => 2,
      'title' => "{$user} split {$amount} off {$origin_t}{$of}" . ($use_t !== '' ? " for {$use_t}" : '') . " on {$date}",
      'detail_rows' => [
        $created_id => [
          'use'           => $use_id,
          'amount'        => $amount,
          'origin_tub_id' => $origin_id,
        ],
        $origin_id => ['amount' => $origin_amount_now],
      ],
      'tubs'    => [$created_id, $origin_id],
      'flavors' => $flavor_id > 0 ? [$flavor_id] : [],
    ];
  }

  function scoop_inventory_change_expected_fields(): string {
    return 'title, change_count, entity, envelope, mode, phase, source, problem, tubs, flavors, details, post_content';
  }

  /**
   * Session-batched inventory_change logging for UPDATE-mode writes
   * (tub/slot cell edits, autosaved or manual-submit alike). Creates
   * (Batch, Closeout) are deliberately NOT routed through here — each one
   * is already its own meaningful, infrequent event and keeps logging
   * immediately via scoop_log_post(). Updates are frequent/incremental
   * (every autosaved keystroke would otherwise mint its own audit row), so
   * they're staged per-user and rolled into ONE inventory_change record by
   * scoop_flush_pending_inventory_change() when the client's idle-flush
   * timer fires (see watchForInventoryChangeFlush in scoop-api.js).
   */
  function scoop_pending_inventory_change_key(int $user_id): string {
    return "scoop_pending_inventory_change_{$user_id}";
  }

  function scoop_stage_inventory_change(array $cfg, array $updated): void {
    $user_id = get_current_user_id();
    if (!$user_id || empty($updated)) return;

    $key     = scoop_pending_inventory_change_key($user_id);
    $pending = get_transient($key);
    if (!is_array($pending)) $pending = [];

    $pending[] = [
      'ts'      => current_time('mysql'),
      'entity'  => $cfg['pod_name'] ?? '',
      'mode'    => $cfg['mode']     ?? '',
      'updated' => $updated,
    ];

    // TTL resets on every staged change, so an actively-edited session never
    // expires mid-session — only real inactivity lets the transient lapse.
    set_transient($key, $pending, 2 * HOUR_IN_SECONDS);
  }

  function scoop_flush_pending_inventory_change(int $user_id = 0): int {
    $user_id = $user_id ?: get_current_user_id();
    if (!$user_id) return 0;

    $key     = scoop_pending_inventory_change_key($user_id);
    $pending = get_transient($key);
    delete_transient($key);

    if (!is_array($pending) || empty($pending)) return 0;

    return scoop_write_session_inventory_change($pending, $user_id);
  }

  function scoop_write_session_inventory_change(array $entries, int $user_id): int {
    if (!function_exists('pods')) return 0;

    $user       = get_user_by('id', $user_id);
    $user_login = $user ? $user->user_login : "user {$user_id}";

    $tubs      = [];
    $flavors   = [];
    $details   = '';
    $row_count = 0;

    foreach ($entries as $entry) {
      $entry_cfg = [
        'pod_name' => $entry['entity'] ?? '',
        'mode'     => $entry['mode']   ?? '',
      ];
      $updated = $entry['updated'] ?? [];

      $refs    = scoop_inventory_change_refs($entry_cfg, $updated);
      $tubs    = array_merge($tubs, $refs['tubs']);
      $flavors = array_merge($flavors, $refs['flavors']);

      foreach ($updated as $row_id => $fields) {
        if (!is_array($fields)) continue;
        $row_count++;
        $details .= '<strong>' . (get_the_title((int) $row_id) ?: "Item {$row_id}") . '</strong><br />';
        foreach ($fields as $field => $value) {
          $details .= $field . ' => ' . (get_the_title((int) $value) ?: $value) . '<br />';
        }
      }
    }

    if ($row_count === 0) return 0;

    $tubs    = scoop_inventory_change_unique_ids($tubs);
    $flavors = scoop_inventory_change_unique_ids($flavors);
    $date    = date('D m/d');
    $s       = ($row_count > 1) ? 's' : '';
    $title   = "{$user_login} updated {$row_count} item{$s} over a session ending {$date}";

    $allowed = [
      'strong' => [],
      'br'     => [],
    ];

    $change_data = [
      'post_status'  => 'publish',
      'title'        => $title,
      'change_count' => $row_count,
      'entity'       => 'session',
      'envelope'     => 'session',
      'mode'         => 'update',
      'phase'        => 'session',
      'source'       => 'session',
      'problem'      => 'none',
      'tubs'         => $tubs,
      'flavors'      => $flavors,
      'details'      => $details,
      'post_content' => wp_kses($details, $allowed),
    ];

    $change_id = scoop_inventory_change_add($change_data, [
      'entity'   => 'session',
      'envelope' => 'session',
      'mode'     => 'update',
      'user_id'  => $user_id,
    ]);

    if (!$change_id) {
      error_log("scoop_write_session_inventory_change: inventory_change add returned empty result for user={$user_id}");
    }

    return $change_id ?: 0;
  }

  function scoop_log_post(\WP_REST_Request $req, array $cfg, array $updated = [], array $errors = [], int $created_id = 0, ?string $source_override = null):void
  {
    $user       = wp_get_current_user()->user_login;
    $payload    = $req->get_param($cfg['envelope_key'] ?? '') ?: [];
    $cells      = is_array($payload['cells'] ?? null) ? $payload['cells'] : [];

    $mode       = $cfg['mode']         ?? 'unknown';
    $entity     = $cfg['pod_name']     ?? 'unknown';
    $envelope   = $cfg['envelope_key'] ?? 'unknown';
    $count      = count($cells);
    $s          = ($count > 1)?'s':'';
    $date       = date("D m/d");
    $title      = "{$user} {$mode}d {$count} {$entity}{$s} on {$date}";
    
    $details    = "";

    $ok = empty($errors);

    // TubSplit (mode:'create', pod 'tub') gets its own audit shape: the
    // generic create title below is batch-flavored (count/flavor) and the
    // tub refs/phase branches iterate the update-mode shape (tub-id =>
    // fields) — neither matches the flat {use, amount, origin_tub_id} row
    // this route receives, and the convert-in-place branch is really an
    // update of the origin (see scoop_inventory_change_tub_create_audit).
    // The override slice supplies mode/title/count/phase/detail_rows/refs;
    // a null return (no created item, i.e. an error record) leaves the
    // generic behavior completely alone.
    $tub_create_override = null;
    if ($mode === 'create' && $entity === 'tub' && $ok) {
      $tub_create_override = scoop_inventory_change_tub_create_audit($updated, $created_id, $user, $date);
      if ($tub_create_override !== null) {
        $mode        = $tub_create_override['mode'];
        $title       = $tub_create_override['title'];
        $count       = $tub_create_override['count'];
        $detail_rows = $tub_create_override['detail_rows'];
        $updated     = []; // generic refs/phase must not re-read the flat row
      }
    }

    if( $mode === 'create' && $ok && $tub_create_override === null ) {
      $flav     = $updated['flavor'] ?? 0;
      $count    = $updated['count'] ?? 0;
      $flav_t   = get_the_title($flav);
      $s        = ($count > 1)?'s':'';
      $title    = "created {$count} {$entity} of {$flav_t}{$s} on {$date}";
    }

    if ($tub_create_override === null) {
      $detail_rows = ($mode === 'create')
        ? [($created_id > 0 ? $created_id : 0) => $updated]
        : $updated;
    }

    foreach ($detail_rows as $row_id => $fields) {
      if (!is_array($fields)) {
        scoop_log("scoop_log_post: skipping non-array detail row entity={$entity} row_id={$row_id} type=" . gettype($fields));
        continue;
      }

      $details .= '<strong>'. (get_the_title((int) $row_id) ?: "Item {$row_id}") .'</strong><br />';
      foreach ($fields as $field => $value)
        $details .= $field .' => ' .( get_the_title((int) $value) ?: $value ) . '<br />';
    }

    $allowed = [
      'strong'     => [],
      'br'         => [],
    ];

    $refs = ($tub_create_override !== null)
      ? [
          'tubs'    => $tub_create_override['tubs'],
          'flavors' => $tub_create_override['flavors'],
        ]
      : scoop_inventory_change_refs($cfg, $updated, $created_id);

    $phase = ($tub_create_override !== null)
      ? $tub_create_override['phase']
      : scoop_inventory_change_phase($cfg, $updated);

    if (!function_exists('pods')) {
      error_log('scoop_log_post: Pods function unavailable; inventory_change log skipped.');
      return;
    }

    $inventory_change = pods('inventory_change');
    if (!$inventory_change || !is_object($inventory_change)) {
      error_log("scoop_log_post: pods('inventory_change') unavailable; log skipped for entity={$entity}, mode={$mode}. Check that the inventory_change pod exists/enabled with expected fields: " . scoop_inventory_change_expected_fields());
      return;
    }

    $change_data = [
        'post_status' => 'publish',
        'title'       => $title,
        'change_count'=> $count,
        'entity'      => $entity,
        'envelope'    => $envelope,
        'mode'        => $mode,
        'phase'       => $phase,
        // $source_override lets a specific client feature (e.g.
        // CabinetWorkflow — see scoop_handle_cells_post's source-hint
        // handling below) identify itself distinctly from
        // scoop_inventory_change_source($cfg)'s generic pod-based default
        // ('tub'/'cabinet'), which can't tell CabinetWorkflow's writes
        // apart from any other grid writing to the same tub/slot routes.
        'source'      => $source_override ?? scoop_inventory_change_source($cfg),
        'problem'     => empty($errors) ? 'none' : 'error',
        'tubs'        => $refs['tubs'],
        'flavors'     => $refs['flavors'],
        'details'     => $details,
        'post_content'=> wp_kses( $details, $allowed )
    ];

    $change_id = scoop_inventory_change_add($change_data, [
      'entity'     => $entity,
      'envelope'   => $envelope,
      'mode'       => $mode,
      'created_id' => $created_id,
      'tub_ids'    => $refs['tubs'],
      'flavor_ids' => $refs['flavors'],
    ]);

    if (!$change_id) {
      error_log("scoop_log_post: inventory_change add returned empty result for entity={$entity}, mode={$mode}. Check inventory_change fields: " . scoop_inventory_change_expected_fields());
    }
    error_log("Inventory change logged with ID: $change_id");

  }

  function scoop_handle_cells_post(\WP_REST_Request $req, array $cfg, array $allowed_fields) {
    $envelope_key = $cfg['envelope_key'] ?? null;
    if (!$envelope_key) {
      error_log("🔍 TRACE: ERROR - Missing envelope_key in config");
      return new \WP_REST_Response(['ok'=>false,'error'=>'Misconfigured endpoint (missing envelope_key).'], 500);
    }

    $payload = $req->get_param($envelope_key);
    if (!is_array($payload)) {
      error_log("🔍 TRACE: ERROR - Missing or invalid $envelope_key payload");
      return new \WP_REST_Response(['ok'=>false,'error'=>"Missing or invalid {$envelope_key} payload."], 400);
    }

    $cells = $payload['cells'] ?? null;
    if (!is_array($cells)) {
      error_log("🔍 TRACE: ERROR - Missing cells in payload");
      return new \WP_REST_Response(['ok'=>false,'error'=>"Missing {$envelope_key}[cells]."], 400);
    }

    // Optional client-supplied hint identifying which feature made this
    // write, for inventory_change's 'source' field — see
    // scoop_inventory_change_source()'s own doc: it only knows which POD
    // was written (tub/slot), not which client feature wrote it, so
    // CabinetWorkflow's actions were otherwise indistinguishable from any
    // other grid editing the same tub/slot routes (folded anonymously into
    // the generic per-session 'source'=>'session' batch — see
    // scoop_stage_inventory_change()). Whitelisted, not passed through
    // freely — untrusted client input landing directly in a Pods field.
    $source_hint = $payload['source'] ?? null;
    $allowed_source_hints = [ 'workflow' ];
    $source_hint = ( is_string( $source_hint ) && in_array( $source_hint, $allowed_source_hints, true ) )
      ? $source_hint
      : null;

    $allowed = array_flip($allowed_fields);
    
    $post_type = $cfg['post_type'] ?? '';
    $pod_name  = $cfg['pod_name'] ?? '';
    
    $updated = [];
    $errors  = [];

    foreach ($cells as $id_raw => $row) {
      $id = (int)$id_raw;
      
      if ($id <= 0 || !is_array($row)) {
        error_log("🔍 TRACE: Skipping invalid cell: ID=$id, is_array=" . (is_array($row) ? 'yes' : 'no'));
        continue;
      }

      $post = get_post($id);
      if (!$post) { 
        error_log("🔍 TRACE: ERROR - Post $id not found");
        $errors[$id][] = ['field'=>null,'error'=>'get_post() not found']; 
        continue; 
      }
      
      if ($post->post_type !== $post_type) {
        error_log("🔍 TRACE: ERROR - Post $id is type {$post->post_type}, expected $post_type");
        $errors[$id][] = ['field'=>null,'error'=>"ID is post_type={$post->post_type}, not {$post_type}"];
        continue;
      }

      if (!function_exists('pods_api') || !is_object(pods_api())) {
        error_log("🔍 TRACE: ERROR - Pods API not available");
        $errors[$id][] = ['field'=>null,'error'=>'Pods API not available.'];
        continue;
      }

      $clean = [];
      foreach ($row as $field => $value) {
        if (!isset($allowed[$field])) continue;
        
        $clean[$field] = scoop_coerce_value($field, $value);
      }
      
      if (!empty($clean)) {
        $result = scoop_pods_api_save($pod_name, $id, $clean);

        if ($result !== false && !is_wp_error($result)) {
          $updated[$id] = ($updated[$id] ?? []) + $clean;
        } else {
          $msg = is_wp_error($result) ? $result->get_error_message() : 'Save failed';
          error_log("🔍 TRACE: ERROR - Save failed for ID $id: $msg");
          foreach (array_keys($clean) as $field) 
            $errors[$id][] = ['field'=>$field,'error'=>$msg];
        }
      } else {
        error_log("🔍 TRACE: No fields to update for ID $id");
      }
    }

    $ok = empty($errors);
    if($ok) scoop_cache_bust();

    // Errors always get logged immediately (debuggability). Successful
    // updates are staged and rolled into a single end-of-session
    // inventory_change instead of one event per write — see
    // scoop_stage_inventory_change() and the /flush-inventory-change route.
    // Planning-only slot edits are still skipped entirely, per
    // scoop_should_log_inventory_change(). Batch/Closeout creation goes
    // through scoop_handle_create_post(), not here, and still logs
    // immediately — each create is its own meaningful, infrequent event.
    //
    // A recognized $source_hint (CabinetWorkflow — see above) gets the
    // same immediate-logging treatment as a create, on success or failure:
    // each Confirm Swap/Leave Empty/Confirm Cabinet/schedule action is
    // already its own meaningful, infrequent event (not rapid autosave
    // keystrokes), so it earns its own inventory_change record right away
    // instead of being folded anonymously into the generic per-session
    // batch.
    if (!$ok) {
      scoop_log_post($req, $cfg, $updated, $errors, 0, $source_hint);
    } elseif ($source_hint !== null) {
      scoop_log_post($req, $cfg, $updated, [], 0, $source_hint);
    } elseif (scoop_should_log_inventory_change($cfg, $updated)) {
      scoop_stage_inventory_change($cfg, $updated);
    }

    return new \WP_REST_Response([
      'ok'      => $ok,
      'author'  => wp_get_current_user()->user_login,
      'updated' => $updated,
      'cfg'     => $cfg,
      'errors'  => $errors,
    ], $ok ? 200 : 400);
  }
