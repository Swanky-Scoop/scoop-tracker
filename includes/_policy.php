<?php
// Add to _pods_helpers.php (or create new _access.php file)

function scoop_access_policy(): array {
  
  $policy = [
    '_default' => [
      'routes' => [
        'Cabinet'   => ['GET' => true, 'POST' => true],
        'FlavorTub' => ['GET' => true, 'POST' => true],
        'Batch'     => ['GET' => true, 'POST' => true],
        'Closeout'  => ['GET' => true, 'POST' => true],
        'DateActivity' => ['GET' => true, 'POST' => true],  // ← ADDED THIS
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
