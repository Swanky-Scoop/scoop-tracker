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

  // Stale-tab check (read-only): current app.js mtime, so a long-lived
  // tab can detect a deploy happened. See assets/version-watch.js.
  register_rest_route('scoop/v1', '/version', [
    'methods'  => ['GET'],
    'callback' => function(\WP_REST_Request $req) {
      return new \WP_REST_Response([
        'version' => filemtime(SCOOP_REST_DIR . 'assets/app.js'),
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
});