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
   * Slot writes are only a real inventory event when they change the tub
   * link (slot.tubs — see the Confirm Cabinet flow in
   * cabinet-workflow-tile.js). current_flavor/immediate_flavor/next_flavor
   * are cabinet planning/merchandising fields (the ones Cabinet-grid autosave
   * writes on every field edit, see scoop_planning_allowed_slot_fields) —
   * logging those to inventory_change balloons the audit table with
   * planning noise that was never a stock movement. Every other entity type
   * logs as before; this only narrows the 'slot' case.
   */
  function scoop_should_log_inventory_change(array $cfg, array $updated): bool {
    if (($cfg['pod_name'] ?? '') !== 'slot') return true;

    foreach ($updated as $fields) {
      if (is_array($fields) && array_key_exists('tubs', $fields)) return true;
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


  function scoop_inventory_change_expected_fields(): string {
    return 'title, change_count, entity, envelope, mode, phase, source, problem, tubs, flavors, details, post_content';
  }

  function scoop_log_post(\WP_REST_Request $req, array $cfg, array $updated = [], array $errors = [], int $created_id = 0):void
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

    if( $mode === 'create' && $ok ) {
      $flav     = $updated['flavor'] ?? 0;
      $count    = $updated['count'] ?? 0;
      $flav_t   = get_the_title($flav);
      $s        = ($count > 1)?'s':'';
      $title    = "created {$count} {$entity} of {$flav_t}{$s} on {$date}";
    }

    $detail_rows = ($mode === 'create')
      ? [($created_id > 0 ? $created_id : 0) => $updated]
      : $updated;

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

    $refs = scoop_inventory_change_refs($cfg, $updated, $created_id);

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
        'phase'       => scoop_inventory_change_phase($cfg, $updated),
        'source'      => scoop_inventory_change_source($cfg),
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

    // Errors always get logged (debuggability); successful slot-only
    // planning edits are skipped — see scoop_should_log_inventory_change().
    if (!$ok || scoop_should_log_inventory_change($cfg, $updated)) {
      scoop_log_post($req, $cfg, $updated, $errors);
    }

    return new \WP_REST_Response([
      'ok'      => $ok,
      'author'  => wp_get_current_user()->user_login,
      'updated' => $updated,
      'cfg'     => $cfg,
      'errors'  => $errors,
    ], $ok ? 200 : 400);
  }
