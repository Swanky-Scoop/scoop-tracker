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
 * kiosk's field-scoped 'state'-only write grant and ice_cream_maker's "no
 * tub writes at all" carve-out are one-off enough that a derivation layer
 * wouldn't have been any shorter than just writing the arrays.
 *
 *   administrator    — everything. 'esr' route entry has no mode/url of its
 *                       own — inherits _config.php's default (external-link
 *                       toggle for "Shift Report"), same as shift_lead.
 *   kitchen_manager  — batch create/delete (cascades tub create/delete —
 *                       see hooks/batch-tub.php), full tub edit (state +
 *                       use + amount + slot), Cabinet slot-schedule write,
 *                       flavor edit (InstockFlavor). Full view access to
 *                       every gated type, including ProductionPlan/esr/
 *                       ItemPivot — a strict superset of ice_cream_maker
 *                       (see that role's own comment). 'esr' route entry
 *                       overrides _config.php's default with explicit
 *                       embedded-iframe mode.
 *   shift_lead       — full tub edit + Cabinet slot-schedule write. No
 *                       batch create/delete, no flavor edit. 'esr' route
 *                       entry has no mode/url override, same as
 *                       administrator — new grant, didn't have this type's
 *                       predecessor ('KitchenReport') at all.
 *   ice_cream_maker  — explicit request: sees ONLY Batch (full view/
 *                       create/delete — makes tubs, corrections happen by
 *                       deleting and remaking the batch, never editing one
 *                       directly), ProductionPlan, esr ("Shift Report",
 *                       overridden to explicit embedded-iframe mode),
 *                       ItemPivot, and Cabinet (view-only). Every other
 *                       type is explicitly denied, not just omitted — see
 *                       that role's own block for why each 'false' is
 *                       spelled out.
 *   kiosk            — shared front-of-house tablet. Explicit request,
 *                       repointed at the exact same five-item set as
 *                       ice_cream_maker above (Batch full view/create/
 *                       delete, ProductionPlan/esr (overridden to
 *                       embedded-iframe mode)/ItemPivot, Cabinet view-only)
 *                       — this supersedes the role's original "FlavorTub/
 *                       DateActivity 'state'-only tub tracking" purpose
 *                       entirely, not just narrows it.
 *   lead             — another front-of-house kiosk-style role, distinct
 *                       from 'kiosk'. Write capabilities mirror shift_lead
 *                       (full tub edit, Cabinet slot-schedule write — same
 *                       'entities' block), but view is narrowed to ONLY
 *                       esr ("Shift Report" link, inherits the external
 *                       default like shift_lead)/Music (new external-link
 *                       type, see _config.php)/EmptiedLog/CabinetWorkflow/
 *                       ItemPivot/Cabinet/FlavorTub (full read/write, same
 *                       as shift_lead). No Batch access at all (unlike
 *                       kiosk/ice_cream_maker) — explicit request.
 *   editor / author  — legacy WP roles, predate the kitchen-role set above;
 *                       left exactly as they were, not part of this redesign.
 *
 *   View access (GET): most types are viewable by every role named below —
 *   "everyone except subscriber" per original explicit request — with
 *   ice_cream_maker/kiosk now the deliberate exception (see above). A type
 *   scoop_type_has_explicit_view_policy() finds no role has ever declared a
 *   'GET' value for (EmptiedLog, Popular, BatchHistory, Tasks, Flavors)
 *   stays ungated — visible to any logged-in user regardless of role, same
 *   as always. A role not named here at all (subscriber, a typo, a URE role
 *   nobody's wired in yet) falls through to '_default', which denies
 *   everything — see scoop_get_user_policy()'s comment on why that changed
 *   from the old behavior of silently granting admin-equivalent access.
 */
function scoop_access_policy(): array {

  // Shared raw URL for 'esr' ("Shift Report") roles that OVERRIDE
  // _config.php's default — kitchen_manager/ice_cream_maker/kiosk below
  // each restate their own explicit mode/url so that reading any ONE
  // role's block here tells the complete story for that button ("this
  // user goes here, in this mode") without needing to also open
  // _config.php. Roles that don't deviate (administrator, shift_lead)
  // just declare 'GET' => true and silently inherit _config.php's
  // external-link default — see that file's 'esr' entry.
  $esr_iframe_url = 'https://docs.google.com/forms/d/e/1FAIpQLSdQLiwPwmIFDDrNCpfWaTA3ZssfWdAykabJrQ4YxI5zlVwlMg/viewform?embedded=true';

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
        // "Split for another use" (see OTHER-USES.md) — granted alongside
        // FlavorTub, same underlying tub-write capability.
        'TubSplit'      => ['GET' => true, 'POST' => true],
        'Batch'         => ['GET' => true, 'POST' => true, 'DELETE' => true],
        'Closeout'      => ['GET' => true, 'POST' => true],
        'DateActivity'  => ['GET' => true, 'POST' => true],
        'InstockFlavor' => ['GET' => true, 'POST' => true],
        'Analytics'     => ['GET' => true],
        'ShiftReport'   => ['GET' => true, 'POST' => true],
        'Task'          => ['GET' => true, 'POST' => true],
        'TaskEdit'      => ['GET' => true, 'POST' => true],
        'Prep'          => ['GET' => true, 'POST' => true, 'DELETE' => true],
        'RecipeCount'   => ['GET' => true, 'POST' => true, 'DELETE' => true],
        // See _config.php's 'ProductionPlan'/'esr' entries / ice_cream_maker's
        // own block below for the full comment — administrator sees every
        // iframe topic in addition to whichever role it's actually for.
        // 'esr': no mode/url override — inherits _config.php's default
        // (external-link, window.open target 'esr'), same as shift_lead.
        // kitchen_manager/ice_cream_maker/kiosk are the ones who override
        // to the embedded-iframe alternative instead (see their own blocks).
        'ProductionPlan' => ['GET' => true],
        'esr'             => ['GET' => true],
        // New grant — see _config.php's 'Music' entry. Only 'lead' and
        // administrator get this one; no other existing role has any use
        // for it, so it's left undeclared (implicitly denied) everywhere
        // else rather than backfilled the way EmptiedLog/CabinetWorkflow
        // were (those had genuine prior unconditional access to preserve).
        'Music'           => ['GET' => true],
        // 'ItemPivot' declared here is new — every role below gets this
        // same explicit grant so nobody who could already see the Flavor
        // map loses it now that it's actually enforced (see
        // scoop_type_has_explicit_view_policy() above).
        'ItemPivot'      => ['GET' => true],
        // EmptiedLog/CabinetWorkflow were NEVER declared anywhere before —
        // deliberately, both were meant to stay open to any authenticated
        // user regardless of role (see EmptiedLog's own comment in
        // _config.php). Explicit kiosk request narrowed that: kiosk should
        // ONLY see Batch/ProductionPlan/esr/ItemPivot/Cabinet, so
        // both now need real declarations — true here and for every other
        // role, false only for kiosk (see kiosk's own block below) —
        // otherwise scoop_type_has_explicit_view_policy() picking up
        // kiosk's new 'false' would silently deny everyone else too.
        'EmptiedLog'      => ['GET' => true],
        'CabinetWorkflow' => ['GET' => true],
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
        // EmptiedLog/CabinetWorkflow/ShiftReport were NEVER declared for
        // any role before — deliberately open to everyone (see
        // administrator's comment above). Explicit kiosk/ice_cream_maker
        // narrowing means they now need real declarations everywhere;
        // true here preserves this role's existing unconditional access.
        'EmptiedLog'      => ['GET' => true],
        'CabinetWorkflow' => ['GET' => true],
        'ShiftReport'     => ['GET' => true],
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
        // See administrator's TubSplit comment above — same reasoning.
        'TubSplit'  => ['GET' => true, 'POST' => true],
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
        'EmptiedLog'      => ['GET' => true],
        'CabinetWorkflow' => ['GET' => true],
        'ShiftReport'     => ['GET' => true],
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
        // See administrator's TubSplit comment above — same reasoning.
        'TubSplit'      => ['GET' => true, 'POST' => true],
        'DateActivity'  => ['GET' => true, 'POST' => true],
        'InstockFlavor' => ['GET' => true, 'POST' => true],
        'Closeout'      => ['GET' => false, 'POST' => false],
        'Analytics'     => ['GET' => false],
        'ShiftReport'   => ['GET' => true, 'POST' => true],
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
        // _config.php's 'ProductionPlan' entry. 'esr': embedded-iframe mode
        // (see administrator's own 'esr' comment above for the external
        // alternative) — same form kiosk/ice_cream_maker get below.
        'ProductionPlan' => ['GET' => true],
        'esr' => ['GET' => true, 'display_mode' => 'iframe', 'url' => $esr_iframe_url],
        // See author's EmptiedLog/CabinetWorkflow comment above.
        'EmptiedLog'      => ['GET' => true],
        'CabinetWorkflow' => ['GET' => true],
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
        // See administrator's TubSplit comment above — same reasoning.
        'TubSplit'      => ['GET' => true, 'POST' => true],
        'DateActivity'  => ['GET' => true, 'POST' => true],
        'InstockFlavor' => ['GET' => true, 'POST' => false],
        'Closeout'      => ['GET' => false, 'POST' => false],
        'Analytics'     => ['GET' => false],
        // Filing the end-of-shift report is this role's job in practice —
        // see WHITEBOARD-INGESTION.md.
        'ShiftReport'   => ['GET' => true, 'POST' => true],
        // See author's backfill comment above (_policy.php's 'author'
        // block) — same reasoning.
        'ItemPivot'     => ['GET' => true],
        'Task'          => ['GET' => true],
        'Prep'          => ['GET' => true],
        'RecipeCount'   => ['GET' => true],
        'EmptiedLog'      => ['GET' => true],
        'CabinetWorkflow' => ['GET' => true],
        // New grant (didn't have 'KitchenReport' before this rename) — no
        // mode/url override, same as administrator: inherits _config.php's
        // external-link default (that Google Form can't be embedded for
        // this role, which is exactly what the default already reflects).
        'esr' => ['GET' => true],
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

    // Explicit request: ice_cream_maker should ONLY see Batch (create +
    // delete — makes tubs, corrections happen by deleting and remaking the
    // batch, never editing one directly)/ProductionPlan/esr ("Shift
    // Report", overridden below to explicit embedded-iframe mode instead
    // of _config.php's external-link default)/ItemPivot/Cabinet
    // (view-only) — nothing else, at all. Every other type below is
    // spelled out as an explicit false/false rather than just omitted, so
    // the restriction reads as deliberate, not a gap someone forgot to
    // fill in.
    'ice_cream_maker' => [
      'routes' => [
        'Batch'          => ['GET' => true, 'POST' => true, 'DELETE' => true],
        'Cabinet'        => ['GET' => true, 'POST' => false],
        'ProductionPlan' => ['GET' => true],
        // Deviates from _config.php's external-link default — this role
        // gets the embeddable form instead (see the block comment above).
        'esr'             => ['GET' => true, 'display_mode' => 'iframe', 'url' => $esr_iframe_url],
        'ItemPivot'      => ['GET' => true],
        'FlavorTub'       => ['GET' => false, 'POST' => false],
        'DateActivity'    => ['GET' => false, 'POST' => false],
        'InstockFlavor'   => ['GET' => false, 'POST' => false],
        'Closeout'        => ['GET' => false, 'POST' => false],
        'Analytics'       => ['GET' => false],
        'EmptiedLog'      => ['GET' => false],
        'CabinetWorkflow' => ['GET' => false],
        'ShiftReport'     => ['GET' => false],
        'Task'            => ['GET' => false],
        'Prep'            => ['GET' => false],
        'RecipeCount'     => ['GET' => false],
      ],
      'entities' => [
        'tub'  => [],
        'slot' => [],
      ],
    ],

    // Shared front-of-house tablet. Explicit request, same as
    // ice_cream_maker above: kiosk should ONLY see Batch (full view/
    // create/delete)/ProductionPlan/esr (embedded-iframe mode, see
    // ice_cream_maker's own comment above)/ItemPivot/Cabinet (view-only) —
    // nothing else. This supersedes the role's original "FlavorTub/
    // DateActivity 'state'-only tub tracking" purpose (see the top-of-file
    // role reference) — kiosk has been repointed at this different set of
    // controls entirely, not just narrowed.
    'kiosk' => [
      'routes' => [
        'Batch'          => ['GET' => true, 'POST' => true, 'DELETE' => true],
        'Cabinet'        => ['GET' => true, 'POST' => false],
        'ProductionPlan' => ['GET' => true],
        // Deviates from _config.php's external-link default — this role
        // gets the embeddable form instead (see the block comment above).
        'esr'             => ['GET' => true, 'display_mode' => 'iframe', 'url' => $esr_iframe_url],
        'ItemPivot'      => ['GET' => true],
        'FlavorTub'       => ['GET' => false, 'POST' => false],
        'DateActivity'    => ['GET' => false, 'POST' => false],
        'InstockFlavor'   => ['GET' => false, 'POST' => false],
        'Closeout'        => ['GET' => false, 'POST' => false],
        'Analytics'       => ['GET' => false],
        'EmptiedLog'      => ['GET' => false],
        'CabinetWorkflow' => ['GET' => false],
        'ShiftReport'     => ['GET' => false],
        'Task'            => ['GET' => false],
        'Prep'            => ['GET' => false],
        'RecipeCount'     => ['GET' => false],
      ],
      'entities' => [
        'tub'  => ['state'],
        'slot' => [],
      ],
    ],

    // Another front-of-house kiosk-style role, distinct from 'kiosk' above.
    // Explicit request: mirrors shift_lead's WRITE capabilities (full tub
    // edit + Cabinet slot-schedule write — see 'entities' below, copied
    // straight from shift_lead's own block) but, like kiosk/ice_cream_maker,
    // is narrowed to see ONLY: esr ("Shift Report" link — no display_mode/
    // url override, inherits _config.php's external default, same as
    // shift_lead), Music (external link — see _config.php), EmptiedLog,
    // CabinetWorkflow, ItemPivot, Cabinet (full read/write), and FlavorTub
    // (full read/write, same as shift_lead). Every other type is
    // explicitly denied, not just omitted — same pattern as kiosk/
    // ice_cream_maker's own blocks.
    'lead' => [
      'routes' => [
        'Cabinet'         => ['GET' => true, 'POST' => true],
        'esr'             => ['GET' => true],
        'Music'           => ['GET' => true],
        'EmptiedLog'      => ['GET' => true],
        'CabinetWorkflow' => ['GET' => true],
        'ItemPivot'       => ['GET' => true],
        // Same as shift_lead — full read/write.
        'FlavorTub'       => ['GET' => true, 'POST' => true],
        // See administrator's TubSplit comment above — same reasoning.
        'TubSplit'        => ['GET' => true, 'POST' => true],
        'Batch'           => ['GET' => false, 'POST' => false],
        'ProductionPlan'  => ['GET' => false],
        'DateActivity'    => ['GET' => false, 'POST' => false],
        'InstockFlavor'   => ['GET' => false, 'POST' => false],
        'Closeout'        => ['GET' => false, 'POST' => false],
        'Analytics'       => ['GET' => false],
        'ShiftReport'     => ['GET' => false, 'POST' => false],
        'Task'            => ['GET' => false],
        'Prep'            => ['GET' => false],
        'RecipeCount'     => ['GET' => false],
      ],
      'entities' => [
        // Same write scope as shift_lead — Cabinet/CabinetWorkflow actions
        // (add-next, leave-empty, confirm swap) write through these tub/
        // slot fields regardless of which route the action came in
        // through.
        'tub'  => ['state','use','amount','slot'],
        'slot' => ['current_flavor','immediate_flavor','next_flavor','tub','confirm_state'],
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
    'lead',
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

// TEMPORARY (2026-08-21): while the click-to-details GUI is being built out
// (see OTHER-USES.md), an unset detail_views defaults to "every entity
// allowed" — no role below has a detail_views key yet, so this is
// currently a no-op everywhere, on purpose, so the linking behavior can be
// exercised without first populating policy data for all 8 roles. Flip
// this to false once that flow is settled (per conversation) — that
// changes the DEFAULT to deny, at which point every role needs its own
// explicit detail_views list, matching how 'entities' write-lists already
// work (see scoop_user_writeable_fields — same deny-unless-listed shape).
if (!defined('SCOOP_DETAIL_VIEWS_DEFAULT_ALLOW')) {
  define('SCOOP_DETAIL_VIEWS_DEFAULT_ALLOW', true);
}

// Role → which entity/pod types (lowercase — 'tub', 'flavor', 'use', ...,
// same vocabulary as col.detailEntity/titleMap client-side) that role may
// open a Details view for at all. Unlike scoop_user_writeable_fields'
// $fields ?? [] (always deny-unless-listed), this distinguishes "no
// detail_views key at all" (falls to SCOOP_DETAIL_VIEWS_DEFAULT_ALLOW)
// from "detail_views explicitly set" (an allow-list, including an
// explicit [] meaning deny everything) — see
// scoop_client_detail_viewable_entities() below for how this reaches the
// client as SCOOP.detailViewableEntities, and _base-grid-model.js's
// isDetailLinkEnabled() for how the client combines it with a model's own
// detailLinks/detailLinkTypes.
function scoop_user_can_view_details(\WP_User $user, string $entity): bool {

  $policy = scoop_get_user_policy($user);

  if (!array_key_exists('detail_views', $policy)) {
    return SCOOP_DETAIL_VIEWS_DEFAULT_ALLOW;
  }

  return in_array($entity, $policy['detail_views'], true);
}

// What enqueue.php ships to the client as SCOOP.detailViewableEntities —
// null (no restriction, current default for every role) rather than
// enumerating every entity name Scoop knows about, so isDetailLinkEnabled()
// client-side can tell "unrestricted" apart from "restricted to this exact
// (possibly empty) list" without the two ever needing to define the same
// canonical entity list twice. Once a role's detail_views is set, this
// mirrors it verbatim — scoop_user_can_view_details() and this function
// must never disagree, since one gates the server, the other gates what
// the client even offers to click.
function scoop_client_detail_viewable_entities(\WP_User $user): ?array {

  $policy = scoop_get_user_policy($user);

  if (!array_key_exists('detail_views', $policy)) {
    return null;
  }

  return array_values($policy['detail_views']);
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
