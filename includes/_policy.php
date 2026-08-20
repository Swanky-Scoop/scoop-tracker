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
 *                       else. No Cabinet write, no flavor edit. Batch is
 *                       full view/create/delete (explicit request, not the
 *                       "no batch" it originally shipped with).
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
        'Task'          => ['GET' => true, 'POST' => true],
        'TaskEdit'      => ['GET' => true, 'POST' => true],
        'Prep'          => ['GET' => true, 'POST' => true, 'DELETE' => true],
        'RecipeCount'   => ['GET' => true, 'POST' => true, 'DELETE' => true],
        // See _config.php's 'ProductionPlan'/'KitchenReport' entries /
        // ice_cream_maker's own block below for the full comment —
        // administrator sees every iframe topic in addition to whichever
        // role it's actually for.
        'ProductionPlan' => ['GET' => true],
        'KitchenReport'   => ['GET' => true],
        // 'ItemPivot' declared here is new — every role below gets this
        // same explicit grant so nobody who could already see the Flavor
        // map loses it now that it's actually enforced (see
        // scoop_type_has_explicit_view_policy() above).
        'ItemPivot'      => ['GET' => true],
      ],
      'entities' => [
        'tub'  => ['state','use','amount','slot'],
        'slot' => ['current_flavor','immediate_flavor','next_flavor','tub','confirm_state'],
        // Live from Pods, not hardcoded — see scoop_shift_reports_allowed_fields()
        // in _write_fields.php for why this entity's field list doesn't need
        // per-role hand-maintenance the way tub/slot's do.
        'shift_report'         => scoop_pod_field_names('shift_report'),
        'cake_order'           => scoop_pod_field_names('cake_order'),
        // Tasks grid inline-edit (Done toggle, Assigned-to FindIt) — see
        // 'TaskEdit' route above and tasks-grid-model.js.
        'task'                 => ['done','target'],
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
        // See administrator's 'ItemPivot' comment above — same reasoning
        // now covers Task/Prep/RecipeCount/InstockFlavor too, backfilled
        // wherever this role had no explicit entry, so extending
        // scoop_type_has_explicit_view_policy() coverage to them didn't
        // silently hide anything this legacy role could already see.
        'ItemPivot'     => ['GET' => true],
        'Task'          => ['GET' => true],
        'Prep'          => ['GET' => true],
        'RecipeCount'   => ['GET' => true],
        'InstockFlavor' => ['GET' => true],
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
        // See author's comment above.
        'ItemPivot'     => ['GET' => true],
        'Task'          => ['GET' => true],
        'Prep'          => ['GET' => true],
        'RecipeCount'   => ['GET' => true],
        'InstockFlavor' => ['GET' => true],
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
        'Task'          => ['GET' => true, 'POST' => true],
        'TaskEdit'      => ['GET' => true, 'POST' => true],
        'Prep'          => ['GET' => true, 'POST' => true, 'DELETE' => true],
        'RecipeCount'   => ['GET' => true, 'POST' => true, 'DELETE' => true],
        // See administrator's 'ItemPivot' comment above.
        'ItemPivot'     => ['GET' => true],
        // kitchen_manager is a strict superset of ice_cream_maker for the
        // view-gated set (Batch/Cabinet/ItemPivot above already were, or
        // exceed it — Cabinet POST, Batch DELETE) — these two were the
        // remaining gap, ice_cream_maker/kiosk/administrator only. See
        // _config.php's 'ProductionPlan'/'KitchenReport' entries.
        'ProductionPlan' => ['GET' => true],
        'KitchenReport'   => ['GET' => true],
      ],
      'entities' => [
        'tub'  => ['state','use','amount','slot'],
        'slot' => ['current_flavor','immediate_flavor','next_flavor','tub','confirm_state'],
        // Live from Pods, not hardcoded — see scoop_shift_reports_allowed_fields()
        // in _write_fields.php for why this entity's field list doesn't need
        // per-role hand-maintenance the way tub/slot's do.
        'shift_report'         => scoop_pod_field_names('shift_report'),
        'cake_order'           => scoop_pod_field_names('cake_order'),
        // Tasks grid inline-edit (Done toggle, Assigned-to FindIt) — see
        // 'TaskEdit' route above and tasks-grid-model.js.
        'task'                 => ['done','target'],
      ],
    ],

    'shift_lead' => [
      'routes' => [
        // No batch create/delete — that's ice_cream_maker/kitchen_manager's
        // job. GET left true only for parity with every other role (Batch's
        // GET is just a diagnostic ping — see scoop_handle_request — real
        // batch data comes through the bundle endpoint, ungated by route).
        // GET now also gates Batch's own dock button visibility (see
        // scoop_type_has_explicit_view_policy() above), so this true is
        // load-bearing now, not just parity — shift_lead needs to keep
        // seeing the widget even though it can't create/delete from it.
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
        // See author's backfill comment above (_policy.php's 'author'
        // block) — same reasoning.
        'ItemPivot'     => ['GET' => true],
        'Task'          => ['GET' => true],
        'Prep'          => ['GET' => true],
        'RecipeCount'   => ['GET' => true],
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
        // This is the intended audience for these buttons (plus
        // administrator, above) — see _config.php's 'ProductionPlan'/
        // 'KitchenReport' entries and scoop_type_has_explicit_view_policy()
        // above. Every other role below simply omits them, which
        // scoop_user_can_route()'s `?? false` default already treats as
        // denied — no explicit 'GET' => false needed.
        'ProductionPlan' => ['GET' => true],
        'KitchenReport'   => ['GET' => true],
        // See author's backfill comment above (_policy.php's 'author'
        // block) — same reasoning.
        'ItemPivot'      => ['GET' => true],
        'Task'           => ['GET' => true],
        'Prep'           => ['GET' => true],
        'RecipeCount'    => ['GET' => true],
      ],
      'entities' => [
        'tub'  => [],
        'slot' => [],
      ],
    ],

    // Shared front-of-house tablet. Tub state tracking only — no amount/use
    // (kiosk doesn't adjust quantities), no slot (not part of Cabinet
    // planning), no flavor edit. Batch was "no batch" too until explicit
    // request granted full view/create/delete — kiosk now works the
    // Batch/BatchHistory/ProductionPlan/KitchenReport/ItemPivot/Cabinet set
    // the same as ice_cream_maker.
    'kiosk' => [
      'routes' => [
        'Batch'         => ['GET' => true, 'POST' => true, 'DELETE' => true],
        'Cabinet'       => ['GET' => true, 'POST' => false],
        'FlavorTub'     => ['GET' => true, 'POST' => true],
        'DateActivity'  => ['GET' => true, 'POST' => true],
        'InstockFlavor' => ['GET' => false, 'POST' => false],
        'Closeout'      => ['GET' => false, 'POST' => false],
        'Analytics'     => ['GET' => false],
        // Also part of the intended audience — see administrator's own
        // 'ProductionPlan'/'KitchenReport' comment above for the full
        // explanation.
        'ProductionPlan' => ['GET' => true],
        'KitchenReport'   => ['GET' => true],
        // See author's backfill comment above (_policy.php's 'author'
        // block) — same reasoning.
        'ItemPivot'      => ['GET' => true],
        'Task'           => ['GET' => true],
        'Prep'           => ['GET' => true],
        'RecipeCount'    => ['GET' => true],
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

/**
 * Does ANY named role (not '_default', which is a deny-all fallback, not a
 * real declared policy) explicitly declare a 'GET' value — true OR false —
 * for this route key? Used by scoop_render_grid_host() (shortcode.php) to
 * decide whether a [scoop_grid type="..."] host should even render for the
 * current user: if a type's GET access has genuinely never been declared
 * anywhere (e.g. EmptiedLog — deliberately open to any authenticated user,
 * see its own _config.php comment), it stays ungated, visible to everyone,
 * exactly as before. The moment even ONE role writes a 'GET' entry for a
 * type, that type is now "clearly meant to be permission-controlled" — every
 * OTHER role's silence on it starts meaning "denied" (scoop_user_can_route's
 * own `?? false`), not "ungated". This is what makes an explicit
 * 'GET' => false in this file actually hide the button instead of only
 * blocking the route call after the button was already shown and clicked —
 * previously an unconditional [scoop_grid] shortcode would render, then any
 * data fetch behind it just 403'd, since these are two independent layers
 * that used to only agree by accident, per-type, when someone remembered to
 * gate the render side too.
 */
function scoop_type_has_explicit_view_policy(string $type): bool {
  foreach (scoop_access_policy() as $role_slug => $role_policy) {
    if ($role_slug === '_default') continue;
    if (array_key_exists('GET', $role_policy['routes'][$type] ?? [])) {
      return true;
    }
  }
  return false;
}
