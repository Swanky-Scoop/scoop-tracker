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
scoop_require('includes/diag.php'); // SCOOP-DIAG: /tubs save-chain breadcrumbs + shutdown capture (cabinet-500); remove file + all `// SCOOP-DIAG` calls to uninstall
scoop_require('includes/_policy.php');
scoop_require('includes/_auth.php');
scoop_require('includes/_cache.php');

/**
 * Pods hooks / domain behavior
 */
scoop_require('includes/hooks/cabinet-slot.php');
scoop_require('includes/hooks/batch-tub.php');
scoop_require('includes/hooks/task-titles.php');
scoop_require('includes/hooks/task-state.php');
scoop_require('includes/hooks/tub-state.php');
scoop_require('includes/hooks/closeout.php');
scoop_require('includes/hooks/nightly-sales.php');

/**
 * REST + bundle
 */
scoop_require('includes/bundle-fetch.php');
scoop_require('includes/bundle.php');
scoop_require('includes/rest.php');
scoop_require('includes/_routes.php');
scoop_require('includes/analytics.php');
scoop_require('includes/audit.php');
scoop_require('includes/audit-ui.php');
scoop_require('includes/republish-tubs-ui.php');
scoop_require('includes/supply-import-ui.php');
scoop_require('includes/sample-tubs-ui.php');
scoop_require('includes/cabinet-clone-ui.php');
/**
 * UI glue (shortcode/admin/enqueue) last
 */
scoop_require('includes/enqueue.php');
scoop_require('includes/shortcode.php');
scoop_require('includes/dock-template.php');
scoop_require('includes/admin-page.php');

/**
 * RCC importer — see RCC_IMPORT_README.md
 */
scoop_require('includes/rcc-import/_config.php');
scoop_require('includes/rcc-import/csv.php');
scoop_require('includes/rcc-import/mapper.php');
scoop_require('includes/rcc-import/importer.php');
scoop_require('includes/rcc-import/quantities.php');
scoop_require('includes/rcc-import/quantities-importer.php');
scoop_require('includes/rcc-import/_ui.php');
scoop_require('includes/rcc-import/reconciler.php');
scoop_require('includes/rcc-import/dairy-allergy.php');
scoop_require('includes/rcc-import/reconciler-ui.php');
scoop_require('includes/flavor-photos.php');
scoop_require('includes/flavor-photos-ui.php');
scoop_require('includes/allergen-icons.php');
scoop_require('includes/allergen-icons-ui.php');

/**
 * Pods schema sync — see includes/pods-schema/_schema.php
 */
scoop_require('includes/pods-schema/_schema.php');
scoop_require('includes/pods-schema/diff.php');
scoop_require('includes/pods-schema/apply.php');
scoop_require('includes/pods-schema/gc.php');
scoop_require('includes/pods-schema/export.php');
scoop_require('includes/pods-schema/ui.php');

scoop_require('includes/admin-menu.php');

/**
 * Periodic cache refresh (WP-Cron). Registers a 2hr schedule that clears
 * Pods's per-item caches; safety net for plugins that read through Pods's
 * resolver and don't see invalidations from our direct-write paths.
 */
scoop_require('includes/cron.php');

/**
 * WP-CLI commands (no-op outside CLI; the file early-returns if WP_CLI isn't defined)
 */
scoop_require('includes/cli.php');

//error_log("========== SCOOP REST PLUGIN LOADED ==========");

// register_activation_hook removed: scoop_readonly() was deleted from the codebase
// by 20ed07a; the dangling hook fatals plugin activation under PHP 8 (CI stack).

add_filter('pods_api_pre_save_pod_item', 'scoop_enforce_tub_rules', 10, 3);

// Hook into the login page script queue
add_action( 'login_enqueue_scripts', 'scoop_login_styles' );

function scoop_login_styles() {
    // Generates the correct URL to your plugin folder's CSS file
    $css_url = plugins_url( 'assets/login.css', __FILE__ );

    // Enqueue the stylesheet safely
    wp_enqueue_style( 'scoop-login-css', $css_url, array(), '1.0.0' );
}