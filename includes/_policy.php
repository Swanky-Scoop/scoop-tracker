<?php
// Single-purpose file: WP role registration + the REST authorization matrix
// that gates every scoop/v1 request (scoop_write_permission() in _auth.php
// calls scoop_user_can_route() below).

function scoop_access_policy(): array {
  
  $policy = [
    '_default' => [
      'routes' => [
        'Cabinet'   => ['GET' => true, 'POST' => true],
        'FlavorTub' => ['GET' => true, 'POST' => true],
        'Batch'     => ['GET' => true, 'POST' => true],
        'Closeout'  => ['GET' => true, 'POST' => true],
        'DateActivity' => ['GET' => true, 'POST' => true],  // ← ADDED THIS
        'Analytics' => ['GET' => true],
      ],
      'entities' => [
        'tub'  => ['state','use','amount','slot'],
        'slot' => ['current_flavor','immediate_flavor','next_flavor','tub','confirm_state'],
      ],
    ],

    'author' => [
      'routes' => [
        'Cabinet'   => ['GET' => true, 'POST' => false],
        'FlavorTub' => ['GET' => true, 'POST' => false],
        'Batch'     => ['GET' => true, 'POST' => true],
        'Closeout'  => ['GET' => true, 'POST' => false],
        'DateActivity' => ['GET' => true, 'POST' => false],  // ← ADDED THIS
        'Analytics' => ['GET' => true],
      ],
      'entities' => [
        'tub'  => [],
        'slot' => [],
      ],
    ],

    'editor' => [
      'routes' => [
        'Cabinet'   => ['GET' => true, 'POST' => true],
        'FlavorTub' => ['GET' => true, 'POST' => true],
        'Batch'     => ['GET' => true, 'POST' => true],
        'Closeout'  => ['GET' => true, 'POST' => true],
        'DateActivity' => ['GET' => true, 'POST' => true],  // ← ADDED THIS
        'Analytics' => ['GET' => true],
      ],
      'entities' => [
        // 'slot' added so add-next/leave-empty/Confirm Cabinet can write
        // tub.slot (the tub side of the bidirectional slot<->tub link —
        // see change-tub.md) — this is a normal CabinetWorkflow action,
        // not an admin-only one.
        'tub'  => ['state','slot'],
        // immediate_flavor/next_flavor added so 'leave slot empty' can
        // reschedule leftover stock into the planning fields (see
        // ConfirmSwapModal._confirmEmpty in confirm-swap-modal.js) — this
        // is a normal CabinetWorkflow action, not an admin-only one.
        'slot' => ['current_flavor','immediate_flavor','next_flavor','tub','confirm_state'],
      ],
    ],

    // Explicit allow-list for writes, explicit deny-list for reads. Any
    // route/method not listed here falls through to the ?? false default
    // in scoop_user_can_route(), i.e. denied — so this role only ever
    // gains access by adding a line below, never by omission elsewhere.
    'ice_cream_maker' => [
      'routes' => [
        // Write allow-list: batch-grid only.
        'Batch'        => ['GET' => true, 'POST' => true],
        // Read access, not write (no allow-list entry below → no POST).
        'Cabinet'      => ['GET' => true, 'POST' => false],
        'FlavorTub'    => ['GET' => true, 'POST' => false],
        'DateActivity' => ['GET' => true, 'POST' => false],
        'InstockFlavor'=> ['GET' => true, 'POST' => false],
        // Read deny-list.
        'Closeout'  => ['GET' => false, 'POST' => false],
        'Analytics' => ['GET' => false],
      ],
      'entities' => [
        'tub'  => [],
        'slot' => [],
      ],
    ],
  ];
  
  return $policy;
}

function scoop_get_user_policy(\WP_User $user): array {
  
  $policy = scoop_access_policy();
  
  // Check roles in priority order
  if (in_array('administrator', $user->roles)) {
    return $policy['_default'];
  }
  
  if (in_array('editor', $user->roles)) {
    return $policy['editor'];
  }
  
  if (in_array('author', $user->roles)) {
    return $policy['author'];
  }

  if (in_array('ice_cream_maker', $user->roles)) {
    return $policy['ice_cream_maker'];
  }

  // Default policy
  return $policy['_default'];
}

function scoop_user_writeable_fields(\WP_User $user, string $entity): array {
  
  $policy = scoop_get_user_policy($user);
  $fields = $policy['entities'][$entity] ?? [];
  
  return $fields;
}

function scoop_user_can_route(\WP_User $user, string $route, string $method): bool {

  $policy = scoop_get_user_policy($user);
  $can = $policy['routes'][$route][$method] ?? false;

  return $can;
}

// ─────────────────────────────────────────────────────────────────────────────
// WP role registration. These add_role()'d roles/caps (scoop_manager,
// scoop_backhouse, scoop_lead, scoop_staff, scoop_readonly/scoop_write/
// scoop_admin caps) are NOT read by scoop_get_user_policy() above — that
// checks $user->roles slugs directly (administrator/editor/author/
// ice_cream_maker/_default). Kept as-is (unmodified) during the move from
// _pods_helpers.php; not wired into the access matrix.
// ─────────────────────────────────────────────────────────────────────────────

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
