<?php
// Single-purpose file: WP role registration + the REST authorization matrix
// that gates every scoop/v1 request (scoop_write_permission() in _auth.php
// calls scoop_user_can_route() below).

/**
 * Role → capability reference (kept in prose here so nobody has to re-derive
 * "what can an ice_cream_maker actually do" from the routes/entities matrix
 * below every time a role changes — update this comment whenever a block
 * changes). Every role's block still uses the same routes/entities shape as
 * before; a generic "entity+action" table was considered and dropped —
 * kiosk's field-scoped 'state'-only grant and ice_cream_maker's "no tub
 * writes at all" carve-out are one-off enough that a derivation layer
 * wouldn't have been any shorter than just writing the arrays.
 *
 *   administrator    — everything.
 *   kitchen_manager  — batch create/delete (cascades tub create/delete —
 *                       see hooks/batch-tub.php), full tub edit (state +
 *                       use + amount + slot), Cabinet slot-schedule write,
 *                       flavor edit (InstockFlavor).
 *   shift_lead       — full tub edit + Cabinet slot-schedule write. No
 *                       batch create/delete, no flavor edit.
 *   ice_cream_maker  — batch create/delete only. Makes tubs, doesn't touch
 *                       them afterwards — corrections happen by deleting
 *                       and remaking the batch, not editing a tub directly.
 *   kiosk            — shared front-of-house tablet. FlavorTub/DateActivity
 *                       write, but ONLY the 'state' field (no amount/use/
 *                       slot) — tub tracking (Opened/Emptied etc.), nothing
 *                       else. No batch, no Cabinet write, no flavor edit.
 *   editor / author  — legacy WP roles, predate the kitchen-role set above;
 *                       left exactly as they were, not part of this redesign.
 *
 *   View access: Cabinet + FlavorTub are viewable (GET) by every role named
 *   below — "everyone except subscriber" per explicit request. A role not
 *   named here at all (subscriber, a typo, a URE role nobody's wired in
 *   yet) falls through to '_default', which denies everything — see
 *   scoop_get_user_policy()'s comment on why that changed from the old
 *   behavior of silently granting admin-equivalent access.
 */
function scoop_access_policy(): array {

  $policy = [

    // True fallback for any role NOT explicitly named in this array —
    // deny everything. Every role scoop_get_user_policy() actually checks
    // (including 'administrator') has its own named block below, so this
    // only fires for a genuinely unrecognized role: WP's built-in
    // 'subscriber', a typo, or a new URE-created role nobody's wired in
    // here yet. Previously this same key doubled as BOTH the fallback AND
    // administrator's own policy — meaning an unrecognized role silently
    // inherited full admin-equivalent write+delete access. That was a real
    // gap (e.g. the dead scoop_manager/scoop_backhouse/scoop_lead/
    // scoop_staff roles registered below fell through to it) — administrator
    // now gets its own explicit block instead.
    '_default' => [
      'routes'   => [],
      'entities' => [],
    ],

    'administrator' => [
      'routes' => [
        'Cabinet'       => ['GET' => true, 'POST' => true],
        'FlavorTub'     => ['GET' => true, 'POST' => true],
        'Batch'         => ['GET' => true, 'POST' => true, 'DELETE' => true],
        'Closeout'      => ['GET' => true, 'POST' => true],
        'DateActivity'  => ['GET' => true, 'POST' => true],
        'InstockFlavor' => ['GET' => true, 'POST' => true],
        'Analytics'     => ['GET' => true],
        'ShiftReport'   => ['POST' => true],
      ],
      'entities' => [
        'tub'  => ['state','use','amount','slot'],
        'slot' => ['current_flavor','immediate_flavor','next_flavor','tub','confirm_state'],
        // Live from Pods, not hardcoded — see scoop_shift_reports_allowed_fields()
        // in _write_fields.php for why this entity's field list doesn't need
        // per-role hand-maintenance the way tub/slot's do.
        'shift_report'         => scoop_pod_field_names('shift_report'),
        'cake_order'           => scoop_pod_field_names('cake_order'),
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

    // ── Kitchen roles (real WP role slugs, created + assigned via the User
    // Role Editor plugin — see the reference comment at the top of this
    // file for what each one is meant to do) ──────────────────────────────

    'kitchen_manager' => [
      'routes' => [
        'Batch'         => ['GET' => true, 'POST' => true, 'DELETE' => true],
        'Cabinet'       => ['GET' => true, 'POST' => true],
        'FlavorTub'     => ['GET' => true, 'POST' => true],
        'DateActivity'  => ['GET' => true, 'POST' => true],
        'InstockFlavor' => ['GET' => true, 'POST' => true],
        'Closeout'      => ['GET' => false, 'POST' => false],
        'Analytics'     => ['GET' => false],
        'ShiftReport'   => ['POST' => true],
      ],
      'entities' => [
        'tub'  => ['state','use','amount','slot'],
        'slot' => ['current_flavor','immediate_flavor','next_flavor','tub','confirm_state'],
        // Live from Pods, not hardcoded — see scoop_shift_reports_allowed_fields()
        // in _write_fields.php for why this entity's field list doesn't need
        // per-role hand-maintenance the way tub/slot's do.
        'shift_report'         => scoop_pod_field_names('shift_report'),
        'cake_order'           => scoop_pod_field_names('cake_order'),
      ],
    ],

    'shift_lead' => [
      'routes' => [
        // No batch create/delete — that's ice_cream_maker/kitchen_manager's
        // job. GET left true only for parity with every other role (Batch's
        // GET is just a diagnostic ping — see scoop_handle_request — real
        // batch data comes through the bundle endpoint, ungated by route).
        'Batch'         => ['GET' => true, 'POST' => false],
        'Cabinet'       => ['GET' => true, 'POST' => true],
        'FlavorTub'     => ['GET' => true, 'POST' => true],
        'DateActivity'  => ['GET' => true, 'POST' => true],
        'InstockFlavor' => ['GET' => true, 'POST' => false],
        'Closeout'      => ['GET' => false, 'POST' => false],
        'Analytics'     => ['GET' => false],
        // Filing the end-of-shift report is this role's job in practice —
        // see WHITEBOARD-INGESTION.md.
        'ShiftReport'   => ['POST' => true],
      ],
      'entities' => [
        'tub'  => ['state','use','amount','slot'],
        'slot' => ['current_flavor','immediate_flavor','next_flavor','tub','confirm_state'],
        // Live from Pods, not hardcoded — see scoop_shift_reports_allowed_fields()
        // in _write_fields.php for why this entity's field list doesn't need
        // per-role hand-maintenance the way tub/slot's do.
        'shift_report'         => scoop_pod_field_names('shift_report'),
        'cake_order'           => scoop_pod_field_names('cake_order'),
      ],
    ],

    // Makes batches (which cascades tub creation) and can delete one to
    // redo it, but never edits a tub directly afterwards — no FlavorTub/
    // DateActivity write.
    'ice_cream_maker' => [
      'routes' => [
        'Batch'         => ['GET' => true, 'POST' => true, 'DELETE' => true],
        'Cabinet'       => ['GET' => true, 'POST' => false],
        'FlavorTub'     => ['GET' => true, 'POST' => false],
        'DateActivity'  => ['GET' => true, 'POST' => false],
        'InstockFlavor' => ['GET' => true, 'POST' => false],
        'Closeout'      => ['GET' => false, 'POST' => false],
        'Analytics'     => ['GET' => false],
      ],
      'entities' => [
        'tub'  => [],
        'slot' => [],
      ],
    ],

    // Shared front-of-house tablet. Tub state tracking only — no amount/use
    // (kiosk doesn't adjust quantities), no slot (not part of Cabinet
    // planning), no batch, no flavor edit.
    'kiosk' => [
      'routes' => [
        'Batch'         => ['GET' => false, 'POST' => false, 'DELETE' => false],
        'Cabinet'       => ['GET' => true, 'POST' => false],
        'FlavorTub'     => ['GET' => true, 'POST' => true],
        'DateActivity'  => ['GET' => true, 'POST' => true],
        'InstockFlavor' => ['GET' => false, 'POST' => false],
        'Closeout'      => ['GET' => false, 'POST' => false],
        'Analytics'     => ['GET' => false],
      ],
      'entities' => [
        'tub'  => ['state'],
        'slot' => [],
      ],
    ],
  ];

  return $policy;
}

function scoop_get_user_policy(\WP_User $user): array {

  $policy = scoop_access_policy();

  // Priority order for the rare multi-role user (WP allows more than one
  // role per user) — first match wins. Not a strict privilege ranking
  // between every pair (ice_cream_maker and shift_lead grant different,
  // not strictly overlapping, things), just a stable tie-break.
  $order = [
    'administrator',
    'kitchen_manager',
    'shift_lead',
    'ice_cream_maker',
    'kiosk',
    'editor',
    'author',
  ];

  foreach ($order as $slug) {
    if (in_array($slug, $user->roles, true)) {
      return $policy[$slug];
    }
  }

  // Genuinely unrecognized role — deny everything (see '_default' above).
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
