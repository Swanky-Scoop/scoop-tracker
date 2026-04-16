<?php
/**
 * Plugin Name: Scoop Rest
 * Description: Minimal REST endpoint to receive planning grid commands.
 */
if (!defined('ABSPATH')) exit;
define('SCOOP_REST_FILE', __FILE__);
define('SCOOP_REST_DIR', plugin_dir_path(__FILE__));
define('SCOOP_REST_URL', plugin_dir_url(__FILE__));

function scoop_require($rel) {
  $path = __DIR__ . '/' . ltrim($rel, '/');
  if (!file_exists($path)) {
    error_log("Scoop Rest missing file: " . $path);
    wp_die("Scoop Rest missing required file: " . esc_html($rel));
  }
  require_once $path;
}

/**
 * Config/constants first
 */
scoop_require('includes/_config.php');
scoop_require('includes/_specs.php');
scoop_require('includes/_write_fields.php');

/**
 * Helpers next
 */
scoop_require('includes/_pods_helpers.php');
scoop_require('includes/_policy.php');
scoop_require('includes/_auth.php');
scoop_require('includes/_cache.php');

/**
 * Pods hooks / domain behavior
 */
scoop_require('includes/hooks/cabinet-slot.php');
scoop_require('includes/hooks/batch-tub.php');
scoop_require('includes/hooks/tub-state.php');
scoop_require('includes/hooks/closeout.php');

/**
 * REST + bundle
 */
scoop_require('includes/bundle-fetch.php');
scoop_require('includes/bundle.php');
scoop_require('includes/rest.php');
scoop_require('includes/_routes.php');
/**
 * UI glue (shortcode/admin/enqueue) last
 */
scoop_require('includes/enqueue.php');
scoop_require('includes/shortcode.php');
scoop_require('includes/admin-page.php');

//error_log("========== SCOOP REST PLUGIN LOADED ==========");

register_activation_hook(__FILE__, 'scoop_readonly');
add_filter('pods_api_pre_save_pod_item', 'scoop_enforce_tub_rules', 10, 3);
