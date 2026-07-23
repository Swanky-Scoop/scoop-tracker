<?php

/**
 * Enqueue Scoop assets (CSS/JS) and inject SCOOP config.
 * Call this from both front-end and admin (when appropriate).
 */

function scoop_client_routes(): array {
  $out = [];
  foreach (scoop_routes_config() as $key => $cfg) {
    $path = $cfg['path'] ?? '';
    $out[$key] = rest_url('scoop/v1' . $path);
  }

  $out['Bundle']     = rest_url('scoop/v1/bundle');
  $out['Version']    = rest_url('scoop/v1/version');
  $out['IdleLogout'] = rest_url('scoop/v1/idle-logout');
  $out['FlushInventoryChange'] = rest_url('scoop/v1/flush-inventory-change');

  return $out;
}

/**
 * Option list for a Pods pick / custom-simple ("enum") field, read live from
 * the field config so the Pods admin GUI is the single source of truth.
 *
 * Pods stores custom-simple values in the field's `pick_custom` option as a
 * newline-delimited string; each line is either `value` or `value|Label`.
 * Order is preserved. Returns [] if the field or its list can't be read (the
 * client then shows an empty dropdown — a loud, traceable failure, by design).
 */
function scoop_field_enum_options(string $pod_name, string $field_key): array {
  if (!function_exists('pods_api') || !function_exists('scoop_field_option')) return [];

  try {
    $field = pods_api()->load_field(['pod' => $pod_name, 'name' => $field_key]);
  } catch (\Throwable $e) {
    return [];
  }
  if (!$field || is_wp_error($field)) return [];

  $raw = scoop_field_option($field, 'pick_custom');
  if (!is_string($raw) || $raw === '') return [];

  $options = [];
  foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
    $line = trim($line);
    if ($line === '') continue;
    $parts = explode('|', $line, 2);
    $value = trim($parts[0]);
    if ($value === '') continue;
    $label = (isset($parts[1]) && trim($parts[1]) !== '') ? trim($parts[1]) : $value;
    $options[] = ['key' => $value, 'label' => $label];
  }

  return $options;
}

function scoop_client_metadata(): array {

  $out  = [];
  $user = wp_get_current_user();
  $routes = scoop_routes_config();

  foreach ($routes as $route_key => $cfg) {

    // Primary entity for this module (today: one entity per module)
    $primary = $cfg['pod_name'] ?? ($cfg['post_type'] ?? '');

    // Future-proof: allow multiple editable entities per module
    // Later you can add in _config.php: 'entity_keys' => ['slot','tub']
    if (!empty($cfg['entity_keys']) && is_array($cfg['entity_keys'])) {
      $entity_keys = $cfg['entity_keys'];
      if ($primary === '' && !empty($entity_keys)) $primary = (string)$entity_keys[0];
    } else {
      $entity_keys = ($primary !== '') ? [$primary] : [];
    }

    $entities_out = [];
    foreach ($entity_keys as $entity_key) {
      $spec   = scoop_entity_specs($entity_key);
      $fields = $spec['fields'] ?? [];

      if (empty($fields) || !is_array($fields)) {
        $entities_out[$entity_key] = [];
        continue;
      }

      // Static "ever editable" list from specs
      $spec_writeable = $spec['writeable'] ?? [];
      if (!is_array($spec_writeable)) $spec_writeable = [];

      // Dynamic per-user list from policy
      $policy_writeable = scoop_user_writeable_fields($user, $entity_key);
      if (!is_array($policy_writeable)) $policy_writeable = [];

      // Final editable set = spec ∩ policy
      $writeable_set = array_flip(array_values(array_intersect($spec_writeable, $policy_writeable)));

      // Build LIST (Grid-friendly): [{key,label,dataType,control,hidden,visible,editable}, ...]
      $columns = [];
      foreach ($fields as $field_key => $field_def) {
        // Back-compat: if you still have 'state' => 'string' style
        if (is_string($field_def)) $field_def = ['data_type' => $field_def];
        if (!is_array($field_def)) $field_def = [];

        $hidden  = (bool)($field_def['hidden'] ?? false);
        $control = $field_def['control'] ?? 'input';

        $column = [
          'key'      => (string)$field_key,
          'label'    => $field_def['label'] ?? ucfirst(str_replace('_', ' ', (string)$field_key)),
          'dataType' => $field_def['data_type'] ?? 'string',
          'control'  => $control,
          'hidden'   => $hidden,
          'titleMap' => $field_def['titleMap'] ?? null,
          'visible'  => !$hidden,
          'write'    => isset($writeable_set[$field_key]),
          // Multi-value rendering capability ('count'|'list'|'both'); only
          // meaningful for data_type 'ids'/'post_names', default 'both'.
          'display'  => $field_def['display'] ?? 'both',
        ];

        // Enum fields (Pods pick / custom-simple, e.g. tub.state) carry their
        // option list, read live from the Pods field config. The client renders
        // these directly — there is no hardcoded copy on the JS side.
        if ($control === 'enum') {
          $column['options'] = scoop_field_enum_options((string)$entity_key, (string)$field_key);
        }

        $columns[] = $column;
      }

      $entities_out[$entity_key] = $columns;
    }

    $out[$route_key] = [
      'primary'  => $primary,
      'entities' => $entities_out,
    ];
  }

  //error_log('Final metadata module keys: ' . print_r(array_keys($out), true));
  return $out;
}

function scoop_enqueue_assets() {
  $base_url  = SCOOP_REST_URL;
  $base_path = SCOOP_REST_DIR;
  $user      = wp_get_current_user(); 

    // CSS
    wp_enqueue_style(
    'scoop-grid',
    $base_url . 'assets/css.css',
    [],
    filemtime($base_path . 'assets/css.css')
    );

    // JS
    wp_enqueue_script(
    'scoop-grid',
    $base_url . 'assets/app.js',
    [], // add deps if you need (e.g. ['wp-api-fetch'])
    filemtime($base_path . 'assets/app.js'),
    true
    );

    wp_localize_script('scoop-grid', 'SCOOP', [
        'nonce'   => wp_create_nonce('wp_rest'),
        'isAdmin' => current_user_can( 'manage_options' ),
        'routes'  => scoop_client_routes(),
        'metaData'=> scoop_client_metadata(), //scoop_fetch_entities
        'entityRelations' => scoop_entity_relations(),
        // Baseline for the client-side stale-tab check (assets/version-watch.js).
        // Bumps whenever app.js is re-saved (SFTP-on-save touches mtime).
        'version' => filemtime($base_path . 'assets/app.js'),
        // Where to send her back after a forced idle-timeout re-login.
        'loginUrl' => wp_login_url( home_url( $_SERVER['REQUEST_URI'] ?? '/' ) ),
        'user'    => ( is_user_logged_in() ) ? [
          'name'  => $user -> data -> user_nicename,
          'roles' => $user -> roles
        ] : null,
    ]);
}


// Mark the scoop-grid handle as type="module"
add_filter('script_loader_tag', function($tag, $handle, $src) {
  if ($handle !== 'scoop-grid') return $tag;

  // replace the <script ...> tag with a module script tag
  return sprintf(
    '<script type="module" src="%s"></script>' . "\n",
    esc_url($src)
  );
}, 10, 3);

// admin-page.php (or wherever you have your wp_enqueue_scripts hook)

add_action('wp_enqueue_scripts', function () {
    
    if (!is_singular()) return;

    global $post;
    if (!$post) return;
    
    if (!has_shortcode($post->post_content, 'scoop_grid') && !has_shortcode($post->post_content, 'scoop_tile')) return;

    scoop_enqueue_assets();
});