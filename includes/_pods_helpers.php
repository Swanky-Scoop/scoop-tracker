<?php

if (!defined('ABSPATH')) exit;

function scoop_user_primary_role(\WP_User $u): string {
  $roles = (array)($u->roles ?? []);
  return $roles[0] ?? '';
}

function scoop_policy_for_user(\WP_User $u): array {
  $p = scoop_access_policy();
  $role = scoop_user_primary_role($u);
  $base = $p['_default'] ?? [];
  $rolep = $p[$role] ?? [];
  // role overrides win
  return array_replace_recursive($base, $rolep);
}

function scoop_can_route(\WP_User $u, string $route_key, string $method): bool {
  $pol = scoop_policy_for_user($u);
  return (bool)($pol['routes'][$route_key][$method] ?? false);
}

function scoop_create_custom_roles() {
  // Remove old roles if they exist (for updates)
  remove_role('scoop_manager');
  remove_role('scoop_staff');
  
/**
 * Register custom roles for Scoop Tracker
 * Run this once on plugin activation or theme setup
 */

  // Scoop Manager - Full control of inventory
  add_role('scoop_manager', 'Manager', [
    'read'           => true,
    'edit_posts'     => true,
    'upload_files'   => true,
    'scoop_readonly' => true,
    'scoop_write'    => true,
    'scoop_admin'    => true,
  ]);

  // Scoop Staff - Can update state/use/amount only
  add_role('scoop_backhouse', 'Kitchen Staff', [
    'read'         => true,
    'edit_posts'   => true,
    'scoop_readonly' => true,
    'scoop_write'    => true,
  ]);  

  // Scoop Staff - Can update state/use/amount only
  add_role('scoop_lead', 'Shift Lead', [
    'read'         => true,
    'edit_posts'   => true,
    'scoop_readonly' => true,
    'scoop_write'    => true,
  ]);

  // Scoop Staff - Can update state/use/amount only
  add_role('scoop_staff', 'Scooper', [
    'read'         => true,
    'edit_posts'   => true,
    'scoop_readonly' => true,
    'scoop_write'    => true,
  ]);
  
  // Update existing roles (optional - keep your existing function too)
  scoop_update_existing_roles();
}

function scoop_update_existing_roles() {
  // Authors get read-only
  if ($author = get_role('author')) {
    $author->add_cap('scoop_readonly');
  }

  // Editors get write access
  if ($editor = get_role('editor')) {
    $editor->add_cap('scoop_readonly');
    $editor->add_cap('scoop_write');
  }

  // Admins get everything
  if ($admin = get_role('administrator')) {
    $admin->add_cap('scoop_readonly');
    $admin->add_cap('scoop_write');
    $admin->add_cap('scoop_admin');
  }
}

// Run on activation (add to your main plugin file)
register_activation_hook(__FILE__, 'scoop_create_custom_roles');

// Or run once manually via admin action
add_action('admin_init', function() {
  if (isset($_GET['scoop_setup_roles']) && current_user_can('manage_options')) {
    scoop_create_custom_roles();
    wp_die('Roles created! <a href="' . admin_url() . '">Go back</a>');
  }
});

function scoop_readonly() {

  // Authors get read-only
  if ($author = get_role('author')) {
    $author->add_cap('scoop_readonly');
  }

  // Admins get everything
  if ($admin = get_role('administrator')) {
    $admin->add_cap('scoop_readonly');
    $admin->add_cap('scoop_write');
    $admin->add_cap('scoop_admin');
  }
}

function scoop_nodate( $value ) { 
	return ( 
		$value === null || 
		$value === '' || 
		$value === '0000-00-00 00:00:00' || 
		$value === '0000-00-00' 
	); 
}

/**
 * Extract a single related item ID from a Pods relationship field value.
 * Handles numeric, [id], [{id:..}], [{ID:..}], etc.
 */
function scoop_rel_id( $val ) {
    if ( empty( $val ) ) return 0;

    // If it’s already an ID
    if ( is_numeric( $val ) ) return (int) $val;

    // If it’s an array, get the first meaningful thing
    if ( is_array( $val ) ) {
        $first = reset( $val );
        if ( is_numeric( $first ) ) return (int) $first;

        if ( is_array( $first ) ) {
            if ( isset( $first['id'] ) && is_numeric( $first['id'] ) ) return (int) $first['id'];
            if ( isset( $first['ID'] ) && is_numeric( $first['ID'] ) ) return (int) $first['ID'];
        }

        if ( is_object( $first ) ) {
            if ( isset( $first->id ) && is_numeric( $first->id ) ) return (int) $first->id;
            if ( isset( $first->ID ) && is_numeric( $first->ID ) ) return (int) $first->ID;
        }
    }

    // If it’s an object
    if ( is_object( $val ) ) {
        if ( isset( $val->id ) && is_numeric( $val->id ) ) return (int) $val->id;
        if ( isset( $val->ID ) && is_numeric( $val->ID ) ) return (int) $val->ID;
    }

    // If it’s a string that contains an ID somewhere (rare but happens)
    if ( is_string( $val ) ) {
        $trim = trim( $val );
        if ( ctype_digit( $trim ) ) return (int) $trim;
    }

    return 0;
}

/**
 * Save fields to a Pods item via Pods API (fires Pods pre_save/post_save hooks).
 *
 * @param string $pod   Pod name (e.g. 'tub')
 * @param int    $id    Item ID
 * @param array  $data  Field values, e.g. ['state' => 'Opened']
 * @return mixed        Result from Pods API or false on failure
 */
function scoop_pods_api_save( string $pod_name, $id, array $data ) {
  $pod_name = trim((string)$pod_name);
  $id = (int)$id;

  if ($pod_name === '' || $id <= 0 || empty($data)) return false;
  if (!function_exists('pods_api') || !is_object(pods_api())) return false;

  $clean = [];
  foreach ($data as $field => $value) {
    $field = (string)$field;
    $clean[$field] = scoop_coerce_value($field, $value);
  }

  return pods_api()->save_pod_item([
    'pod'  => $pod_name,
    'id'   => $id,
    'data' => $clean,
  ]);
}

function scoop_coerce_value(string $field, $value) {

  // string enums
  if (in_array($field, ['state'], true)) {
    return (string)$value;
  }

  // integer relationship ids + numeric fields
  if (in_array($field, [
    'current_flavor',
    'immediate_flavor',
    'next_flavor',
    'flavor',
    'use',
    'location',
    'tubs_emptied',
    'order',
  ], true)) {
    return (int)$value;
  }

  if(in_array($field, [
    'count',
    'amount'
  ], true)) {
    return (float)$value;
  }

  // default: leave as-is
  return $value;
}

function scoop_pods_ready(): bool {
  return function_exists('pods_api') && is_object(pods_api());
}

function scoop_pods_field_def(string $pod_name, string $field_name) /* no : array */ {
  if (!scoop_pods_ready()) return [];

  $pod = pods_api()->load_pod(['name' => $pod_name]);
  if (!$pod) return [];

  $fields = $pod['fields'] ?? null;
  if (!is_array($fields) || empty($fields[$field_name])) return [];

  $field = $fields[$field_name];

  // New Pods: field definitions may be Pods\Whatsit\Field objects
  if (is_object($field)) {
    if (method_exists($field, 'export')) {
      $arr = $field->export();
      return is_array($arr) ? $arr : [];
    }
    if (method_exists($field, 'to_array')) {
      $arr = $field->to_array();
      return is_array($arr) ? $arr : [];
    }
    // Last resort: try public properties (often not useful, but avoids fatal)
    return (array)$field;
  }

  return is_array($field) ? $field : [];
}

function scoop_pods_dropdown_options(string $pod_name, string $field_name): array {
  static $cache = [];
  $k = $pod_name . ':' . $field_name;
  if (isset($cache[$k])) return $cache[$k];

  $field = scoop_pods_field_def($pod_name, $field_name);
  $opts  = is_array($field) ? ($field['options'] ?? []) : [];

  $out = [];

  // Pods “pick_custom” format: newline-separated, optional "key|label"
  if (!empty($opts['pick_custom']) && is_string($opts['pick_custom'])) {
    $lines = preg_split("/\r\n|\r|\n/", trim($opts['pick_custom']));
    foreach ($lines as $line) {
      $line = trim($line);
      if ($line === '') continue;

      if (strpos($line, '|') !== false) {
        [$key, $label] = array_map('trim', explode('|', $line, 2));
      } else {
        $key = $label = $line;
      }

      $out[] = ['key' => (string)$key, 'label' => (string)$label];
    }

  // Some field types store options as "choices" map
  } elseif (!empty($opts['choices']) && is_array($opts['choices'])) {
    foreach ($opts['choices'] as $key => $label) {
      $out[] = ['key' => (string)$key, 'label' => (string)$label];
    }
  }

  return $cache[$k] = $out;
}


