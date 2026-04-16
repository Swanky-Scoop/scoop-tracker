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

    scoop_log_post($req, $cfg, $row);

    return new \WP_REST_Response([
      'ok' => true,
      'type' => $pod_name,
      'created' => ['id' => (int)$new_id],
    ], 200);
  } 

  function scoop_log_post(\WP_REST_Request $req, array $cfg, array $updated = [], array $errors = []):void
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
      $title    = "created {$count} {$entity} of {$flav_t}{$s} on {$date}";
      $s        = ($count > 1)?'s':'';
    }

    foreach ($updated as $row_id => $fields) {
      $details .= '<strong>'. (get_the_title((int) $row_id) ?: "Item {$row_id}") .'</strong><br />';
      foreach ($fields as $field => $value)
        $details .= $field .' => ' .( get_the_title((int) $value) ?: $value ) . '<br />';
    }

    $allowed = [
      'strong'     => [],
      'br'         => [],
    ];

    pods('inventory_change')->add([
        'title'       => $title,
        'change_count'=> $count,
        'entity'      => $entity,
        'envelope'    => $envelope,
        'mode'        => $mode,
        'details'     => $details,
        'post_content'=> wp_kses( $details, $allowed )
    ]);
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
    
    scoop_log_post($req, $cfg, $updated, $errors);

    return new \WP_REST_Response([
      'ok'      => $ok,
      'author'  => wp_get_current_user()->user_login,
      'updated' => $updated,
      'notes'   => 'here\'s interesting stuff',
      'cfg'     => $cfg,
      'errors'  => $errors,
    ], $ok ? 200 : 400);
  }
