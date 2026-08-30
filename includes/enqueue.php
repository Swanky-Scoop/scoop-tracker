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

  // Dedicated routes registered outside scoop_routes_config() (see
  // includes/_routes.php) still need to reach ScoopAPI.route()/postJson() —
  // Debt's /debt-requests is the first client writer of these: its Wanted
  // column autosaves through api.postJson(changes, 'Debt'), which resolves
  // this URL (see scoop_handle_debt_requests_post in rest.php for why
  // Debt's write is a dedicated route rather than a config entry).
  $out['DebtRequests'] = rest_url('scoop/v1/debt-requests');

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

    // Dock icon strip identity for this control. No per-type override exists
    // yet — baseline defaults to the route's own logical name and its first
    // letter (see 'displayTitle'/'icon' in $cfg to override later, once a
    // real per-type icon set is designed — see DOCKING.md). 'icon' may be
    // either a unicode glyph or an image path; the client
    // (List._buildToggleButton in assets/ui/_list.js) tells them apart by
    // shape at render time, no separate flag needed here.
    // 'target' => 'action' | 'aside' (opt-in, see _config.php) routes this
    // control's whole host into the dock's matching shared slot
    // (.action-target or <aside>) instead of .canvas, with only one control
    // visible per slot at a time — see List.dockToggle()/
    // List.DOCK_SLOT_SELECTORS/_closeSlotSiblings() in assets/ui/_list.js
    // and DOCKING.md. Anything else (default null) keeps normal .canvas
    // placement.
    // 'canvas_mode' => 'half-stack' (default) | 'full-stack' |
    // 'half-nostack' | 'full-nostack' — only meaningful for controls that
    // stay in .canvas (i.e. no 'target' above); see BaseGridModel's
    // canvasMode comment (assets/models/_base-grid-model.js) and the
    // "TABLE ...canvas..." sizing rules in assets/css.css for what each
    // value actually does.
    // Some "iframe topic" types (e.g. 'esr' — see _config.php) offer TWO
    // different behaviors depending on who's asking: embedded iframe vs. a
    // plain external-link toggle (e.g. a Google Form that can't be
    // embedded at all for some accounts). _config.php's own mode/url/
    // target act as the DEFAULT; a role's _policy.php route entry only
    // needs to declare its own mode/url/target when it DEVIATES from that
    // default (see administrator/shift_lead — no override, inherit the
    // config default — vs. kitchen_manager/ice_cream_maker/kiosk, which
    // do). Resolved here, server-side, per current user, so the client
    // never has to know about roles at all — it just reads whichever of
    // iframeUrl/externalUrl came back (see IframePanel's constructor,
    // assets/ui/iframe-panel.js).
    $user_route_policy = scoop_get_user_policy($user)['routes'][$route_key] ?? [];
    $display_mode = $user_route_policy['display_mode']
      ?? $cfg['display_mode']
      ?? (isset($cfg['iframe_url']) ? 'iframe' : null);
    $url = $user_route_policy['url'] ?? $cfg['url'] ?? $cfg['iframe_url'] ?? null;
    $window_target = $user_route_policy['window_target'] ?? $cfg['window_target'] ?? null;
    $wants_external = $display_mode === 'external' && !empty($url);

    $out[$route_key] = [
      'primary'      => $primary,
      'entities'     => $entities_out,
      // Per-user, per-route write permission, resolved by the SAME
      // scoop_user_can_route() check the route's own permission callback
      // runs (scoop_write_permission in _auth.php). Lets a client gate an
      // editable cell on real server truth instead of duplicating the role
      // matrix — Debt's Wanted column reads SCOOP.metaData.Debt.canPost
      // (see debt-grid-model.js's _demandWriteable). False for display-only
      // types with no POST grant (kiosk/lead on Debt, everyone on Popular).
      'canPost'      => scoop_user_can_route($user, $route_key, 'POST'),
      'displayTitle' => $cfg['display_title'] ?? $route_key,
      'icon'         => $cfg['icon'] ?? mb_substr($route_key, 0, 1),
      'target'       => $cfg['target'] ?? null,
      'canvasMode'   => $cfg['canvas_mode'] ?? 'half-stack',
      // "Iframe topic" types only (e.g. 'ProductionPlan'/'esr' — see
      // _config.php) — resolved per-current-user from _policy.php's route
      // entry (override) falling back to _config.php's own display_mode/
      // url/window_target (default). displayMode is the EXPLICIT field
      // IframePanel's constructor reads to decide iframe-vs-external (see
      // assets/ui/iframe-panel.js) — not inferred from which URL happens
      // to be set. null for every ordinary type. Already only reaches a
      // browser whose role passed scoop_render_grid_host()'s view-gate
      // (shortcode.php), same as this whole metadata payload only ever
      // goes to a logged-in user.
      'displayMode'    => $display_mode,
      // Kept split into iframeUrl/externalUrl (rather than one bare 'url')
      // for back-compat with [scoop_iframe]'s data-url fallback and the
      // :not(:has(.iframeView)) shimmer-suppression CSS, both of which
      // predate displayMode.
      'iframeUrl'      => $wants_external ? null : $url,
      // Set only once resolved mode is 'external' — see the comment above.
      // 'esr' targets window name 'esr' by default (see 'window_target' in
      // _config.php's 'esr' entry) so repeat clicks reuse the same
      // tab/window instead of opening a new one every time.
      'externalUrl'    => $wants_external ? $url : null,
      'externalTarget' => $wants_external ? ($window_target ?? '_blank') : null,
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

    // Dock icon font (optional) — generated locally, never installed at
    // deploy time: find an icon via the Streamline VS Code extension, save
    // the SVG into assets/icon-font/svg/<name>.svg, then run
    //   npx icon-font-generator assets/icon-font/svg/*.svg -o assets/icon-font/dist -n swankyF -p si -j
    // and commit the generated assets/icon-font/dist/ output (assets/icon-font/svg/
    // itself is excluded from deploy — source only, see deploy.yml). Reference
    // an icon by setting a type's 'icon' in scoop_routes_config() (_config.php)
    // to "if:<name>" — see _buildToggleButton() in assets/ui/_list.js for how
    // that maps to the "si-<name>" class this generates. Font family/file
    // basename is "swankyF" — re-running the command above with a different
    // -n regenerates under a new name and orphans these files (delete the
    // old ones, update this path).
    $icon_font_css = $base_path . 'assets/icon-font/dist/swankyF.css';
    if (file_exists($icon_font_css)) {
      wp_enqueue_style(
        'scoop-icon-font',
        $base_url . 'assets/icon-font/dist/swankyF.css',
        [],
        filemtime($icon_font_css)
      );
    }

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
        // null = unrestricted (current default for every role, see
        // _policy.php's SCOOP_DETAIL_VIEWS_DEFAULT_ALLOW), an array = only
        // these entity types may open a Details view for this user — see
        // _base-grid-model.js's isDetailLinkEnabled().
        'detailViewableEntities' => scoop_client_detail_viewable_entities($user),
        'entityRelations' => scoop_entity_relations(),
        'refreshScope'    => scoop_client_refresh_scope(),
        // Assigned-to FindIt options for the Tasks grid (see
        // tasks-grid-model.js) — same roster scoop_kitchen_staff_handler()
        // serves live for task-form.js, but the grid needs it synchronously
        // at render time, so it rides this localized payload instead.
        'kitchenStaff'    => scoop_kitchen_staff_list(),
        // Baseline for the client-side stale-tab check (assets/version-watch.js).
        // Bumps whenever app.js is re-saved (SFTP-on-save touches mtime).
        'version' => filemtime($base_path . 'assets/app.js'),
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
    
    if (
      !has_shortcode($post->post_content, 'scoop_grid') &&
      !has_shortcode($post->post_content, 'scoop_tile') &&
      !has_shortcode($post->post_content, 'scoop_dock') &&
      !has_shortcode($post->post_content, 'scoop_iframe')
    ) return;

    scoop_enqueue_assets();
});