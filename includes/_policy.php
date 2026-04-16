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
        'tub'  => ['state','use','amount'],
        'slot' => ['current_flavor','immediate_flavor','next_flavor'],
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
        'tub'  => ['state'],
        'slot' => ['current_flavor'],
      ],
    ],
  ];
  
  error_log('🔍 TRACE: scoop_access_policy() returning policy with routes: ' . implode(', ', array_keys($policy['_default']['routes'])));
  return $policy;
}

function scoop_get_user_policy(\WP_User $user): array {
  
  $policy = scoop_access_policy();
  
  // Check roles in priority order
  if (in_array('administrator', $user->roles)) {
    error_log("🔍 TRACE: User is administrator - using _default policy");
    return $policy['_default'];
  }
  
  if (in_array('editor', $user->roles)) {
    error_log("🔍 TRACE: User is editor - using editor policy");
    return $policy['editor'];
  }
  
  if (in_array('author', $user->roles)) {
    error_log("🔍 TRACE: User is author - using author policy");
    return $policy['author'];
  }
  
  // Default policy
  error_log("🔍 TRACE: User has no matching role - using _default policy");
  return $policy['_default'];
}

function scoop_user_writeable_fields(\WP_User $user, string $entity): array {
  error_log("🔍 TRACE: scoop_user_writeable_fields() called for entity: $entity, user: " . $user->user_login);
  
  $policy = scoop_get_user_policy($user);
  $fields = $policy['entities'][$entity] ?? [];
  
  error_log("🔍 TRACE: Writeable fields for $entity: " . print_r($fields, true));
  
  return $fields;
}

function scoop_user_can_route(\WP_User $user, string $route, string $method): bool {
  error_log("🔍 TRACE: scoop_user_can_route() called - route: $route, method: $method, user: " . $user->user_login);
  
  $policy = scoop_get_user_policy($user);
  $can = $policy['routes'][$route][$method] ?? false;
  
  error_log("🔍 TRACE: Permission result for $route $method: " . ($can ? 'ALLOWED' : 'DENIED'));
  
  return $can;
}
