<?php
  /**
   * includes/rest/php
   * 
   * Combined handler for GET/POST.
   */
  function scoop_handle_request(\WP_REST_Request $req, array $cfg, string $route_key) {
    error_log("🔍 TRACE: scoop_handle_request() called for route: $route_key");
   
    $allowed_fields = [];
    if (!empty($cfg['allowed_fields_cb']) && is_callable($cfg['allowed_fields_cb'])) {
      error_log("🔍 TRACE: [$route_key] Calling allowed_fields_cb: {$cfg['allowed_fields_cb']}");
      $allowed_fields = call_user_func($cfg['allowed_fields_cb'], wp_get_current_user());
      error_log("🔍 TRACE: [$route_key] Allowed fields: " . implode(', ', $allowed_fields));
    } else {
      error_log("🔍 TRACE: [$route_key] No allowed_fields_cb configured or not callable");
    }

    if ($req->get_method() === 'GET') {
      error_log("🔍 TRACE: [$route_key] Handling GET request");
      return new \WP_REST_Response([
        'ok'            => true,
        'route'         => $route_key,
        'message'       => "scoop/v1/{$route_key} alive",
        'allowed_fields'=> array_values($allowed_fields),
        'time'          => current_time('mysql'),
      ], 200);
    }

    error_log("🔍 TRACE: [$route_key] Handling POST request, mode: {$cfg['mode']}");

    if ($cfg['mode'] === 'create') {
      error_log("🔍 TRACE: [$route_key] Delegating to scoop_handle_create_post()");
      return scoop_handle_create_post($req, $cfg, $allowed_fields);
    } else {
      error_log("🔍 TRACE: [$route_key] Delegating to scoop_handle_cells_post()");
      return scoop_handle_cells_post( $req, $cfg, $allowed_fields);
    }
  }

  /**
   * POST handler.
   */
  function scoop_handle_create_post(\WP_REST_Request $req, array $cfg, array $allowed_fields) {
    error_log("🔍 TRACE: scoop_handle_create_post() called");

    $envelope_key = $cfg['envelope_key'] ?? null;
    if (!$envelope_key) {
      error_log("🔍 TRACE: ERROR - Missing envelope_key in config");
      return new \WP_REST_Response(['ok'=>false,'error'=>'Misconfigured endpoint (missing envelope_key).'], 500);
    }
    
    error_log("🔍 TRACE: Using envelope_key: $envelope_key");

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

    error_log("🔍 TRACE: Cells count: " . count($cells));

    if (count($cells) !== 1) {
      error_log("🔍 TRACE: ERROR - Expected 1 row, got " . count($cells));
      return new \WP_REST_Response(['ok' => false, 'error' => 'Create expects exactly one row in cells.'], 400);
    }

    $row = reset($cells);
    if (!is_array($row)) {
      error_log("🔍 TRACE: ERROR - Invalid row data");
      return new \WP_REST_Response(['ok'=>false,'error'=>'Invalid row'], 400);
    }

    error_log("🔍 TRACE: Row data: " . json_encode($row));

    $pod_name  = $cfg['pod_name'] ?? '';
    if (!$pod_name) {
      error_log("🔍 TRACE: ERROR - Missing pod_name in config");
      return new \WP_REST_Response(['ok'=>false,'error'=>'Misconfigured endpoint (missing pod_name).'], 500);
    }

    error_log("🔍 TRACE: Creating item in pod: $pod_name");
    error_log("🔍 TRACE: Allowed fields: " . implode(', ', $allowed_fields));

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

    error_log("🔍 TRACE: SUCCESS - Created item with ID: $new_id");

    return new \WP_REST_Response([
      'ok' => true,
      'type' => $pod_name,
      'created' => ['id' => (int)$new_id],
    ], 200);
  } 


  function scoop_handle_cells_post(\WP_REST_Request $req, array $cfg, array $allowed_fields) {
    error_log("🔍 TRACE: scoop_handle_cells_post() called");

    $envelope_key = $cfg['envelope_key'] ?? null;
    if (!$envelope_key) {
      error_log("🔍 TRACE: ERROR - Missing envelope_key in config");
      return new \WP_REST_Response(['ok'=>false,'error'=>'Misconfigured endpoint (missing envelope_key).'], 500);
    }

    error_log("🔍 TRACE: Using envelope_key: $envelope_key");

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

    error_log("🔍 TRACE: Processing " . count($cells) . " cells");

    $allowed = array_flip($allowed_fields);
    error_log("🔍 TRACE: Allowed fields: " . implode(', ', $allowed_fields));

    $post_type = $cfg['post_type'] ?? '';
    $pod_name  = $cfg['pod_name'] ?? '';
    
    error_log("🔍 TRACE: post_type: $post_type, pod_name: $pod_name");

    $updated = [];
    $errors  = [];

    foreach ($cells as $id_raw => $row) {
      $id = (int)$id_raw;
      error_log("🔍 TRACE: Processing cell ID: $id");
      error_log("🔍 --> UPDATING TUB: id={$id}, pod={$pod_name}, clean=" . json_encode($clean));
      
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
        if (!isset($allowed[$field])) {
          error_log("🔍 TRACE: Skipping disallowed field: $field");
          continue;
        }
        $clean[$field] = scoop_coerce_value($field, $value);
      }
      
      error_log("🔍 TRACE: Cleaned data for ID $id: " . json_encode($clean));

      if (!empty($clean)) {
        error_log("🔍 TRACE: Calling scoop_pods_api_save() for ID $id");
        $result = scoop_pods_api_save($pod_name, $id, $clean);

        if ($result !== false && !is_wp_error($result)) {
          error_log("🔍 TRACE: SUCCESS - Saved ID $id");
          $updated[$id] = ($updated[$id] ?? []) + $clean;
        } else {
          $msg = is_wp_error($result) ? $result->get_error_message() : 'Save failed';
          error_log("🔍 TRACE: ERROR - Save failed for ID $id: $msg");
          foreach (array_keys($clean) as $field) {
            $errors[$id][] = ['field'=>$field,'error'=>$msg];
          }
        }
      } else {
        error_log("🔍 TRACE: No fields to update for ID $id");
      }
    }

    $ok = empty($errors);
    error_log("🔍 TRACE: Final result - ok: " . ($ok ? 'true' : 'false') . ", updated: " . count($updated) . " items, errors: " . count($errors) . " items");
    
    return new \WP_REST_Response([
      'ok'      => $ok,
      'updated' => $updated,
      'errors'  => $errors,
    ], $ok ? 200 : 400);
  }
