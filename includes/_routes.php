<?php
// includes/_routes.php

if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {

  // Bundle endpoint (read-only)
  register_rest_route('scoop/v1', '/bundle', [
    'methods'  => ['GET'],
    'callback' => function(\WP_REST_Request $req) {
      return scoop_bundle_get($req);
    },
    'permission_callback' => 'scoop_require_authenticated_user_read_only',
  ]);

  // Idle-timeout logout. Client tracks real interaction (mousemove/keydown/
  // click) separately from background polling and calls this after 6h of
  // no genuine activity. Clears the actual WP auth cookie/session server-side
  // — this is a real logout, not just a client-side redirect.
  register_rest_route('scoop/v1', '/idle-logout', [
    'methods'  => ['POST'],
    'callback' => function(\WP_REST_Request $req) {
      wp_clear_auth_cookie();
      wp_destroy_current_session();
      return new \WP_REST_Response(['ok' => true], 200);
    },
    'permission_callback' => function(\WP_REST_Request $req) {
      return is_user_logged_in();
    },
  ]);

  // Rolls the accumulated 'update' writes (tub/slot edits, staged by
  // scoop_stage_inventory_change since the last flush) into ONE
  // inventory_change record. Called by the client's shorter idle-flush timer
  // (see watchForInventoryChangeFlush), independent of the 6h idle-logout
  // timer above. No-ops if nothing is pending.
  register_rest_route('scoop/v1', '/flush-inventory-change', [
    'methods'  => ['POST'],
    'callback' => function(\WP_REST_Request $req) {
      $change_id = scoop_flush_pending_inventory_change();
      return new \WP_REST_Response(['ok' => true, 'change_id' => $change_id], 200);
    },
    'permission_callback' => function(\WP_REST_Request $req) {
      return is_user_logged_in();
    },
  ]);

  // Stale-tab check (read-only): current app.js mtime, so a long-lived
  // tab can detect a deploy happened. See assets/version-watch.js.
  register_rest_route('scoop/v1', '/version', [
    'methods'  => ['GET'],
    'callback' => function(\WP_REST_Request $req) {
      return new \WP_REST_Response([
        'version' => filemtime(SCOOP_REST_DIR . 'assets/app.js'),
        // Global bundle-cache version (includes/_cache.php) — the "has any
        // scoop data changed since you last looked?" signal the client's
        // background poll (ScoopAPI.watchForDataChanges) compares between
        // ticks so it only refetches the bundle after a real save, not on
        // every poll. Bumped by scoop_cache_bust() on every relevant
        // save_post/trash/delete. See CONTROL-REFRESH.md §1-§2.
        'cache_version' => scoop_cache_version(),
      ], 200);
    },
    'permission_callback' => 'scoop_require_authenticated_user_read_only',
  ]);

  // Price/data-quality audit endpoints (read-only). Handlers live in
  // includes/audit.php. See PRICE-SOURCING.md §4 Features 1 & 2.
  register_rest_route('scoop/v1', '/audit/ingredients', [
    'methods'  => ['GET'],
    'callback' => 'scoop_audit_ingredients_handler',
    'permission_callback' => 'scoop_require_authenticated_user_read_only',
  ]);

  register_rest_route('scoop/v1', '/audit/flavors', [
    'methods'  => ['GET'],
    'callback' => 'scoop_audit_flavors_handler',
    'permission_callback' => 'scoop_require_authenticated_user_read_only',
  ]);

  
  // REST endpoints (write)
  $routes = scoop_routes_config();

  foreach ($routes as $key => $cfg) {
    // scoop_routes_config() deliberately also holds "display-only" entries
    // for types with no REST write route of their own (Popular, Analytics,
    // BatchHistory, ItemPivot, EmptiedLog, ...) — just a display_title/icon
    // override for their dock button (see that function's own top comment).
    // Those never had 'path'/'methods' to begin with; registering a route
    // for them isn't just unnecessary, it's impossible (no path to match)
    // and was firing "Undefined array key" on every single REST request —
    // found while diagnosing an unrelated test failure whose JSON parse
    // broke because this leaked ahead of the real response body.
    if (empty($cfg['path']) || empty($cfg['methods'])) continue;

    register_rest_route(
      'scoop/v1',
      $cfg['path'],
      [
        'methods'  => $cfg['methods'],
        'callback' => function(\WP_REST_Request $req) use ($cfg, $key) {
          return scoop_handle_request($req, $cfg, $key);
        },
        'permission_callback' => scoop_write_permission($key),
      ]
    );
  }

  // Batch delete — removes the batch and its related tubs (see
  // scoop_handle_batch_delete in rest.php). Sibling of Batch's own
  // create route (/batches) rather than a scoop_routes_config() entry,
  // since that config's per-type loop above only ever registers one path
  // with one fixed method set per type. Reuses Batch's own permission
  // matrix (scoop_user_can_route) keyed on the 'DELETE' method — see
  // includes/_policy.php.
  register_rest_route('scoop/v1', '/batches/(?P<id>\d+)', [
    'methods'  => ['DELETE'],
    'callback' => function(\WP_REST_Request $req) {
      return scoop_handle_batch_delete($req);
    },
    'permission_callback' => scoop_write_permission('Batch'),
  ]);

  // End-of-shift report create — see scoop_handle_shift_report_create() in
  // rest.php for why this is a dedicated route rather than a
  // scoop_routes_config() entry. 'ShiftReport' is a route key ONLY known to
  // _policy.php (no _config.php entry — same reasoning as CabinetWorkflow),
  // so scoop_write_permission() still resolves it the same way it does for
  // every _config.php-driven route.
  register_rest_route('scoop/v1', '/shift-reports', [
    'methods'  => ['POST'],
    'callback' => function(\WP_REST_Request $req) {
      return scoop_handle_shift_report_create($req);
    },
    'permission_callback' => scoop_write_permission('ShiftReport'),
  ]);

  // Debt board demand-override upserts (worktree-tub-moving) — see
  // scoop_handle_debt_requests_post() in rest.php for why this is a
  // dedicated route rather than a scoop_routes_config() entry (synthetic
  // (location, flavor) row keys, not flavor_request post ids). 'Debt' IS a
  // route key known to _policy.php (it already carries the Debt GET grant
  // for the board itself), so scoop_write_permission() resolves it the
  // same way it does for every _config.php-driven route — roles with
  // Debt POST denied (kiosk, and everyone without an explicit Debt grant)
  // are refused here without any per-route logic.
  register_rest_route('scoop/v1', '/debt-requests', [
    'methods'  => ['POST'],
    'callback' => function(\WP_REST_Request $req) {
      return scoop_handle_debt_requests_post($req);
    },
    'permission_callback' => scoop_write_permission('Debt'),
  ]);

  // Live field schema (groups + fields, ordered per Pods admin) for the
  // shift-report form — see scoop_shift_report_field_schema() in rest.php.
  // Read-only, same permission tier as /bundle: any authenticated user, not
  // gated per-route like the write side, since it's just field metadata.
  register_rest_route('scoop/v1', '/shift-report-fields', [
    'methods'  => ['GET'],
    'callback' => function(\WP_REST_Request $req) {
      return new \WP_REST_Response(['ok' => true] + scoop_shift_report_field_schema(), 200);
    },
    'permission_callback' => 'scoop_require_authenticated_user_read_only',
  ]);

  // Delete a recipe count / prep row — used by each widget's "attached"
  // history grid in the Task form (see
  // assets/models/task-component-history-grid-model.js). Siblings of their
  // own create routes (/recipe-counts, /preps) rather than
  // scoop_routes_config() entries, same reasoning as Batch's own DELETE
  // route above. Reuse RecipeCount/Prep's own permission matrix keyed on
  // the 'DELETE' method — see includes/_policy.php.
  register_rest_route('scoop/v1', '/recipe-counts/(?P<id>\d+)', [
    'methods'  => ['DELETE'],
    'callback' => function(\WP_REST_Request $req) {
      return scoop_handle_recipe_count_delete($req);
    },
    'permission_callback' => scoop_write_permission('RecipeCount'),
  ]);

  register_rest_route('scoop/v1', '/preps/(?P<id>\d+)', [
    'methods'  => ['DELETE'],
    'callback' => function(\WP_REST_Request $req) {
      return scoop_handle_prep_delete($req);
    },
    'permission_callback' => scoop_write_permission('Prep'),
  ]);

  // Staff list for the Task form's 'target' picker (assets/ui/task-form.js).
  // Its own endpoint because WP Users aren't a Pods pod and don't fit
  // scoop_fetch_entities()/the bundle pattern. Read-only, same permission
  // tier as /bundle — gating who can actually create a Task happens at the
  // 'Task' route itself (see includes/_policy.php), not here.
  register_rest_route('scoop/v1', '/kitchen-staff', [
    'methods'  => ['GET'],
    'callback' => 'scoop_kitchen_staff_handler',
    'permission_callback' => 'scoop_require_authenticated_user_read_only',
  ]);
});