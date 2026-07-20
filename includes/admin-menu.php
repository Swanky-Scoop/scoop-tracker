<?php
if (!defined('ABSPATH')) exit;

/**
 * Registers the `Scoop` top-level admin menu and its sub-pages.
 *
 * - RCC Import   → scoop_render_rcc_import_page() (defined in includes/rcc-import/_ui.php)
 * - Command Test → scoop_render_command_test_page() (defined in includes/admin-page.php)
 */
add_action('admin_menu', 'scoop_register_admin_menu');

function scoop_register_admin_menu() {

  add_menu_page(
    'Scoop',
    'Scoop',
    'manage_options',
    'scoop_root',
    'scoop_render_rcc_import_page',
    'dashicons-clipboard',
    25
  );

  add_submenu_page(
    'scoop_root',
    'RCC Import',
    'RCC Import',
    'manage_options',
    'scoop_root',
    'scoop_render_rcc_import_page'
  );

  add_submenu_page(
    'scoop_root',
    'Reconcile Relations',
    'Reconcile Relations',
    'manage_options',
    'scoop_reconcile',
    'scoop_render_reconciler_page'
  );

  add_submenu_page(
    'scoop_root',
    'Command Test',
    'Command Test',
    'edit_posts',
    'scoop_command_test',
    'scoop_render_command_test_page'
  );

  add_submenu_page(
    'scoop_root',
    'Flavor Photos',
    'Flavor Photos',
    'manage_options',
    'scoop_flavor_photos',
    'scoop_render_flavor_photos_page'
  );
}
