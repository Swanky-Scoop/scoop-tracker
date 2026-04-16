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