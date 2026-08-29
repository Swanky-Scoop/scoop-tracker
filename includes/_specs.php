<?php

function scoop_bundle_specs(): array {
  
  $specs = [
    // 'location' added for the location-switcher filter (CabinetGridModel's
    // getFilterDefs) — needs every Location's title, not just the id already
    // on each cabinet row.
    'Cabinet'      => ['needs' => ['cabinet','slot','flavor','tub','location']],
    'FlavorTub'    => ['needs' => ['tub','flavor','use','slot','location']],
    'Batch'        => ['needs' => ['flavor']],
    'BatchHistory' => ['needs' => ['batch','flavor']],
    'Closeout'     => ['needs' => ['flavor','use']],
    'DateActivity' => ['needs' => ['tub','inventory_change','flavor','use','location','slot','cabinet']],
    // Read-only "what got emptied, grouped by day" log for a non-staff
    // audience — see assets/models/emptied-log-grid-model.js. Narrower needs
    // than DateActivity: no slot/cabinet, since there's no slot-warning logic
    // here, and it deliberately ignores this.location (like ItemPivot) to
    // show every location's emptied tubs together with location as a column.
    'EmptiedLog'   => ['needs' => ['tub','inventory_change','flavor','use','location']],
    'InstockFlavor'=> ['needs' => ['flavor','tub','slot','cabinet']],
    // Read-only "where are the tubs" pivot — see assets/models/item-pivot-grid-model.js.
    'ItemPivot'    => ['needs' => ['tub','flavor','slot','cabinet','location']],
    // 'allergen' is a small fixed reference table (unlike 'batch' — see the
    // comment on that one elsewhere in change-tub.md) — cheap to fetch
    // whole, needed for the allergen icon URLs shown on each slot's flavor.
    // 'use' is needed by ConfirmSwapModal's "[unless lost]" review dialog,
    // to label tubs of the outgoing flavor by their use (Front-of-House,
    // etc.) rather than a raw id.
    // 'location' added for the location-switcher filter (CabinetWorkflowGridModel's
    // getFilterDefs) — same as Cabinet, needs every Location's title.
    'CabinetWorkflow' => ['needs' => ['cabinet','slot','flavor','tub','allergen','use','location']],
    // End-of-shift report — see WHITEBOARD-INGESTION.md. Needs flavor,
    // location, and supply (the supplies_low picker), plus cabinet/slot so
    // the flavors_changed checklist can be filtered to slot.current_flavor
    // and grouped by cabinet, same "what's actually in a slot right now"
    // idea CabinetWorkflow uses — NOT cake_order: cake_orders on
    // shift_report means the staffer creates brand new cake_order records
    // inline, never picks from existing ones, so the form has no need to
    // read that domain. No _config.php entry — same reasoning as
    // CabinetWorkflow: this isn't a column-driven grid, and its write goes
    // through a dedicated custom REST route (scoop_handle_shift_report_create
    // in rest.php) that creates the cake_order posts first, then the
    // shift_report itself with their ids already embedded — which the
    // generic per-type create dispatch (exactly one row, one pod) can't
    // express.
    'ShiftReport' => ['needs' => ['flavor','location','supply','cabinet','slot']],
    // Task creation GUI — see assets/ui/task-form.js. Task itself is
    // create-only (no entity spec below, same reasoning as shift_report/
    // cake_order — it's never read back through the bundle). flavor/recipe/
    // ingredient/unit feed the create-line pickers; batch/recipe_count/prep
    // feed each widget's task-scoped "attached" history grid (filtered
    // client-side by their 'task' field) — see
    // assets/models/task-component-history-grid-model.js. Including them
    // here is also what makes scoop_client_refresh_scope()'s needs-overlap
    // check pull a fresh 'Task'-scoped refetch whenever a Batch/Prep/
    // RecipeCount create-grid saves (see that function's own comment on
    // writesPods vs needs), which is what makes the history grids repaint
    // with the newly-created row.
    'Task' => ['needs' => ['flavor','recipe','ingredient','unit','batch','recipe_count','prep']],
    // Read-only listing of all tasks — see assets/models/tasks-grid-model.js.
    // Unlike 'Task' above, this DOES need the 'task' entity itself (see the
    // entity spec below) since it's displaying existing tasks, not creating one.
    // batch/recipe_count/prep + flavor/recipe/ingredient/unit feed the
    // Batches/Recipe production/Ingredient prep list-columns (same entities
    // 'Task' above needs for its own attached-history grids, for the same
    // reason — each row's sub-items and their relation names).
    // Shortcode: [scoop_grid type="Tasks" user="..." location="..."]
    'Tasks' => ['needs' => ['task','batch','recipe_count','prep','flavor','recipe','ingredient','unit']],
    // Prep/RecipeCount need no entities of their own here (their pickers'
    // domain rides in on Task's bundle need-list above — they're embedded
    // inside the Task form, never mounted as their own [scoop_grid] host) —
    // these entries exist purely so scoop_client_refresh_scope() gives each
    // type a real SCOOP.refreshScope entry, which is what lets
    // ScoopAPI.scopedRefreshTypes() correctly scope their own post-submit
    // refresh (the same "blank row resets after a successful create" reset
    // Batch's standalone grid already relies on) — without an entry here,
    // scopedRefreshTypes() falls back to the full page-wide type union,
    // which doesn't include 'Prep'/'RecipeCount' and the reset never fires.
    'Prep' => ['needs' => []],
    'RecipeCount' => ['needs' => []],
  ];
  
  return $specs;
}

function scoop_get_entity_spec_keys(string $bundle_key): array {
  
  $specs = scoop_bundle_specs();
  
  if (!isset($specs[$bundle_key])) {
    return [];
  }
  
  return $specs[$bundle_key]['needs'];
}

function scoop_entity_specs(string $key = ''): array {

  static $cache = null;

  if ( $cache === null ) {
    $cache = [
      'tub' => [
        'post_type' => 'tub',
        'pod'       => 'tub',
        'title'     => true,
        'fields'    => [
          'state'         => ['data_type' => 'string',   'control' => 'enum'  ],
          'use'           => ['data_type' => 'int',      'control' => 'find', 'titleMap' => 'use'],
          'flavor'        => ['data_type' => 'int',      'control' => 'find', 'titleMap' => 'flavor'],
          'amount'        => ['data_type' => 'float',    'control' => 'text'  ],
          'editor_name'   => ['data_type' => 'string',   'label'   => 'Editor'],
          'date'          => ['data_type' => 'datetime', 'control' => 'text', 'label' => 'Posted'],
          'created_on'    => ['data_type' => 'datetime', 'control' => 'text', 'label' => 'Made'],
          'changed_on'    => ['data_type' => 'datetime', 'control' => 'text', 'label' => 'Changed'],
          'post_modified' => ['data_type' => 'datetime', 'control' => 'find', 'label' => 'Updated'],
          'opened_on'     => ['data_type' => 'string'],
          'emptied_at'    => ['data_type' => 'string'],
          'location'      => ['data_type' => 'int',      'control' => 'find', 'titleMap' => 'location'],
          'batch'         => ['data_type' => 'int',      'control' => 'find', 'hidden' => true],
          'closeout'      => ['data_type' => 'int',      'control' => 'find', 'hidden' => true],
          'index'         => ['data_type' => 'int'],
          // Bidirectional sister field with slot.tub (Pods-native 1:1 sync —
          // see change-tub.md). Written whenever a tub is opened/emptied in
          // association with a slot; slot.tub is never written directly by
          // client code, Pods keeps it in sync from this side.
          'slot'          => ['data_type' => 'int',      'control' => 'find', 'titleMap' => 'slot', 'hidden' => true],
        ],
        'post_fields' => [
          'editor_name'   => 'string',
          'post_modified' => 'datetime',
          'post_date'     => 'datetime',
        ],
        'filter' => function(array $row, array $ctx) {
          $state            = $row['state'] ?? '';
          $requesting_types = $ctx['requesting_types'] ?? [];

          // EmptiedLog shares DateActivity's date-scoped tub fetch (same
          // 'activity' composite window, see below) — it just groups/filters
          // the result differently client-side (emptied-phase rows only,
          // bucketed by day instead of by flavor).
          $has_date_activity = in_array('DateActivity', $requesting_types, true)
            || in_array('EmptiedLog', $requesting_types, true);
          $has_other_grids   = !empty(array_diff($requesting_types, ['DateActivity', 'EmptiedLog']));

          // DateActivity needs tubs whose actual inventory event dates are recent.
          // post_modified is still used for manual override rows, but opens and
          // empties should be keyed off opened_on/emptied_at instead of the
          // tub's current state snapshot.
          if ($has_date_activity) {
            $date_filters = $ctx['date_filters'] ?? ['activity'];
            $date_ranges  = $ctx['date_filter_ranges'] ?? [];

            foreach ($date_filters as $filter_key) {
              $range = $date_ranges[$filter_key] ?? [];
              if (!$range) continue;

              if ($filter_key === 'activity') {
                $candidates = [
                  $row['opened_on'] ?? '',
                  $row['emptied_at'] ?? '',
                  $row['created_on'] ?? '',
                ];

                if (($row['state'] ?? '') === '__override__') {
                  $candidates[] = $row['post_modified'] ?? '';
                }
              } else {
                $field_map = [
                  'created_on'    => 'created_on',
                  'opened_on'     => 'opened_on',
                  'emptied_at'    => 'emptied_at',
                  'post_modified' => 'post_modified',
                ];

                $field = $field_map[$filter_key] ?? '';
                $candidates = $field ? [ $row[$field] ?? '' ] : [];
              }

              foreach ($candidates as $candidate) {
                if (scoop_date_filter_value_in_range($candidate, $range)) return true;
              }
            }
          }

          // Other grids need: active tubs, plus ones emptied recently enough
          // to still count as "active" for FlavorTubGridModel's purposes
          // (see RECENTLY_EMPTIED_HOURS there) and, per scoop_enforce_tub_rules,
          // still be correctable — SCOOP_TUB_EMPTIED_REVERT_HOURS (defined in
          // hooks/tub-state.php) is the single window all three are keyed
          // off of, so anything the bundle includes is also still fixable.
          if ($has_other_grids) {
            if ($state !== 'Emptied') return true;

            $emptied_at = $row['emptied_at'] ?? '';
            if (!scoop_nodate($emptied_at)) {
              $hours = defined('SCOOP_TUB_EMPTIED_REVERT_HOURS') ? SCOOP_TUB_EMPTIED_REVERT_HOURS : 0;
              $ts    = strtotime((string) $emptied_at);
              if ($ts !== false && $ts >= (time() - $hours * HOUR_IN_SECONDS)) return true;
            }
          }

          return false;
        },

        'writeable' => ['state','use','amount','slot']
      ],

      'inventory_change' => [
        'post_type' => 'inventory_change',
        'pod'       => 'inventory_change',
        'title'     => true,
        'fields'    => [
          'change_count' => ['data_type' => 'float',  'hidden' => true],
          'entity'       => ['data_type' => 'string', 'hidden' => true],
          'envelope'     => ['data_type' => 'string', 'hidden' => true],
          'mode'         => ['data_type' => 'string', 'hidden' => true],
          'phase'        => ['data_type' => 'string'],
          //'source'       => ['data_type' => 'string'],
          //'problem'      => ['data_type' => 'string'],
          'tubs'         => ['data_type' => 'ids',    'control' => 'find', 'titleMap' => 'tub', 'hidden' => true],
          'flavors'      => ['data_type' => 'ids',    'control' => 'find', 'titleMap' => 'flavor', 'hidden' => true],
          'details'      => ['data_type' => 'string', 'hidden' => true],
        ],
        'post_fields' => [
          'author_name'   => 'string',
          'post_modified' => 'datetime',
          'post_date'     => 'datetime',
        ],
        'filter' => function(array $row, array $ctx) {
          $requesting_types = $ctx['requesting_types'] ?? [];
          // EmptiedLog also reads audit rows (for the emptied-phase ones,
          // same as DateActivity) — see the tub filter's comment above.
          if (!in_array('DateActivity', $requesting_types, true)
            && !in_array('EmptiedLog', $requesting_types, true)) {
            return false;
          }

          $date_filters = $ctx['date_filters'] ?? ['activity'];
          if (!in_array('activity', $date_filters, true)) {
            return false;
          }

          $range = $ctx['date_filter_ranges']['activity'] ?? [];
          return scoop_date_filter_value_in_range($row['post_date'] ?? $row['post_modified'] ?? '', $range);
        },
        'writeable' => []
      ],

      'slot' => [
        'post_type' => 'slot',
        'pod'       => 'slot',
        'title'     => true,
        'fields'    => [
          'cabinet'          => ['data_type' => 'int', 'control' => 'find', 'hidden' => true],
          'location'         => ['data_type' => 'int', 'control' => 'find', 'hidden' => true],
          'current_flavor'   => ['data_type' => 'int', 'control' => 'find', 'titleMap' => 'flavor'],
          'immediate_flavor' => ['data_type' => 'int', 'control' => 'find', 'titleMap' => 'flavor'],
          'next_flavor'      => ['data_type' => 'int', 'control' => 'find', 'titleMap' => 'flavor'],
          // Renamed from 'tubs' (id 2434) — now a proper 1:1 bidirectional
          // Pods sister field with tub.slot (see change-tub.md). Client
          // code never writes this side directly; it's kept in sync by
          // Pods whenever tub.slot is written (ConfirmSwapModal, Confirm
          // Cabinet). Read-only from the client's perspective in practice,
          // though still technically writeable server-side.
          'tub'              => ['data_type' => 'int', 'control' => 'find', 'titleMap' => 'tub', 'hidden' => true],
          // Drives the confirm-swap modal's flavor-line ordering — see
          // ConfirmSwapModal in assets/ui/confirm-swap-modal.js.
          'reload'           => ['data_type' => 'bool', 'hidden' => true],
          // Persisted Confirm Cabinet outcome — 'unconfirmed' / 'filled' /
          // 'discrepancy' / 'impossible' / 'empty'. Unlike everything else
          // on this row, this is NOT purely derived-and-computed client
          // side: it needs to be visible to reporting outside this GUI
          // (dashboards, alerts on someone NOT looking at this page), which
          // a client-only computed value structurally can't reach. Written
          // by CabinetWorkflowTile._reconcileCabinet(), and reset to
          // 'unconfirmed' by scoop_enforce_tub_rules() in
          // includes/hooks/tub-state.php whenever a linked tub gets
          // emptied by ANY path — see change-tub.md.
          'confirm_state'    => ['data_type' => 'string', 'control' => 'enum', 'hidden' => true],
        ],
        'writeable' => ['current_flavor','immediate_flavor','next_flavor','tub','confirm_state'],
      ],

      'cabinet' => [
        'post_type' => 'cabinet',
        'pod'       => 'cabinet',
        'title'     => 'Cabinets',
        'fields'    => [
          'location' => ['data_type' => 'int', 'control' => 'find'],
          'max_tubs' => ['data_type' => 'int', 'control' => 'find'],
          // Read by cabinet-slot.php server-side already (title/slug
          // logic); now also needed client-side for CabinetWorkflow's
          // Confirm Cabinet reconciliation (a flavor whose allergens
          // intersect this can never have a valid tub for this cabinet —
          // see change-tub.md).
          'prohibited_allergens' => ['data_type' => 'ids', 'control' => 'find', 'titleMap' => 'allergen', 'hidden' => true],
        ],
        'writeable' => []
      ],

      'flavor' => [
        'post_type' => 'flavor',
        'pod'       => 'flavor',
        'titleMap'  => 'flavor',
        'title'     => 'Flavors',
        'fields'    => [
          'menu_board'    => ['data_type' => 'file'],
          'photo'         => ['data_type' => 'file'],
          // 'display' is a rendering-capability hint for multi-value fields:
          // 'count' | 'list' | 'both'. Grid defaults to count-only regardless;
          // Tile shows whatever the flag allows. Defaults to 'both' when unset
          // (see scoop_client_metadata()).
          'tubs'          => ['data_type' => 'ids', 'titleMap' => 'tub',  'display' => 'both'],
          'current_slots' => ['data_type' => 'ids', 'titleMap' => 'slot', 'display' => 'both'],
          'allergens'     => ['data_type' => 'post_names'],
          'web_id'        => ['data_type' => 'int'],
        ],
        'writeable' => []
      ],

      // Read-only task entity used by the Tasks grid (see
      // assets/models/tasks-grid-model.js) — the "Task" entry above is
      // create-only and never reads tasks back, this is the list view.
      // 'target_name' resolves the target WP-user relationship to a display
      // name server-side (scoop_fetch_entities' post_fields loop in
      // bundle-fetch.php), same idiom as 'author_name'/'editor_name' — the
      // client never talks to /kitchen-staff just to label this column.
      'task' => [
        'post_type' => 'task',
        'pod'       => 'task',
        'title'     => true,
        'fields'    => [
          'other'     => ['data_type' => 'string'],
          'target'    => ['data_type' => 'int', 'control' => 'find', 'hidden' => true],
          'done'      => ['data_type' => 'bool'],
          // System-controlled — auto-stamped/cleared by
          // scoop_stamp_task_completed() (hooks/task-state.php) whenever
          // 'done' flips, same idiom as tub.emptied_at. Also the field the
          // Tasks grid's date-range filter bounds against — see
          // scoop_bundle_date_filter_context()/the 'task' WHERE-clause block
          // in bundle-fetch.php.
          'completed' => ['data_type' => 'datetime'],
        ],
        'post_fields' => [
          'target_name' => 'string',
          'post_date'   => 'datetime',
        ],
        // 'done'/'target'/'other' are writeable through the TaskEdit route
        // (see _config.php), not this one — the Tasks grid inline-edits
        // done/target the same way EmptiedLogGridModel edits tub.state/use
        // through FlavorTub's route, and the Details panel's edit mode
        // (assets/ui/task-detail-view.js) edits all three, including the
        // description textarea: this entity spec's 'writeable' list is what
        // scoop_client_metadata() intersects against each role's
        // 'task' entity grant (_policy.php) to compute the per-column
        // `write` flag those clients read.
        'writeable' => ['done','target','other']
      ],

      // Read-only batch entity used by the BatchHistory grid, and (via its
      // 'task' field) the Task form's per-widget "attached" history grids —
      // see assets/ui/task-form.js / assets/models/task-component-history-grid-model.js.
      // Date filter on t.post_date is applied in scoop_fetch_entities() below
      // when BatchHistory is among the requested grid types.
      'batch' => [
        'post_type' => 'batch',
        'pod'       => 'batch',
        'title'     => true,
        'fields'    => [
          'count'  => ['data_type' => 'float'],
          'flavor' => ['data_type' => 'int', 'control' => 'find', 'titleMap' => 'flavor'],
          'task'   => ['data_type' => 'int', 'control' => 'find', 'hidden' => true],
        ],
        'post_fields' => [
          'author_name' => 'string',
          'post_date'   => 'datetime',
        ],
        'writeable' => []
      ],

      // Read-only — feed the Task form's "Recipe production"/"Ingredient
      // prep" attached-history grids (scoped to a task via their 'task'
      // field client-side), same shape/reasoning as 'batch' above. Writes go
      // through the RecipeCount/Prep create routes, not this spec.
      'recipe_count' => [
        'post_type' => 'recipe_count',
        'pod'       => 'recipe_count',
        'title'     => true,
        'fields'    => [
          'count'  => ['data_type' => 'float'],
          'recipe' => ['data_type' => 'int', 'control' => 'find', 'titleMap' => 'recipe'],
          'task'   => ['data_type' => 'int', 'control' => 'find', 'hidden' => true],
          'done'   => ['data_type' => 'bool', 'hidden' => true],
        ],
        'writeable' => []
      ],

      'prep' => [
        'post_type' => 'prep',
        'pod'       => 'prep',
        'title'     => true,
        'fields'    => [
          'count'      => ['data_type' => 'float'],
          'ingredient' => ['data_type' => 'int', 'control' => 'find', 'titleMap' => 'ingredient'],
          'units'      => ['data_type' => 'int', 'control' => 'find', 'titleMap' => 'unit'],
          'other'      => ['data_type' => 'string'],
          'task'       => ['data_type' => 'int', 'control' => 'find', 'hidden' => true],
          'done'       => ['data_type' => 'bool', 'hidden' => true],
        ],
        'writeable' => []
      ],

      'use' => [
        'post_type' => 'use',
        'pod'       => 'use',
        'titleMap'  => 'use',
        'title'     => 'Uses',
        'fields'    => [
          'order'    => 'int',
          'titleMap' => 'use',
        ],
        'writeable' => []
      ],

      'location' => [
        'post_type' => 'location',
        'pod'       => 'location',
        'title'     => 'Locations',
        'titleMap'  => 'location',
        'fields'    => [
          // none needed for now
        ],
        'writeable' => []
      ],

      // Closeout — single-row create form (mode='create' in _config.php).
      // Mirrors the field list in scoop_closeouts_allowed_fields().
      'closeout' => [
        'post_type' => 'closeout',
        'pod'       => 'closeout',
        'title'     => true,
        'fields'    => [
          'tubs_emptied' => ['data_type' => 'float',  'control' => 'text'],
          'flavor'       => ['data_type' => 'int',    'control' => 'find', 'titleMap' => 'flavor'],
          'use'          => ['data_type' => 'int',    'control' => 'find', 'titleMap' => 'use'],
          'location'     => ['data_type' => 'int',    'control' => 'find', 'titleMap' => 'location', 'hidden' => true],
          'order'        => ['data_type' => 'int',    'hidden' => true],
        ],
        'writeable' => ['tubs_emptied', 'flavor', 'use', 'location', 'order'],
      ],

      // shift_report and cake_order intentionally have NO entry here — see
      // WHITEBOARD-INGESTION.md. Both are single-purpose create-only forms
      // whose field list, types, and writeable set are now derived live
      // from Pods (scoop_pod_field_names(), scoop_shift_report_field_schema()
      // in rest.php) instead of hand-maintained here, so a field added in
      // Pods admin shows up in the form and becomes writeable without a
      // code change. Neither is ever read via the bundle (shift_report/
      // cake_order records are write-only from this app's perspective), so
      // there's nothing here for scoop_fetch_entities() to need either.

      // shift_report.supplies_low's target entity — see
      // WHITEBOARD-INGESTION.md for the pivot from a hardcoded checklist to
      // real supply records (83 items populated on local, grouped via
      // 'group'). Not writeable from this app — supply items are managed in
      // Pods admin, not created/edited through this REST layer.
      'supply' => [
        'post_type' => 'supply',
        'pod'       => 'supply',
        'title'     => true,
        'fields'    => [
          'group' => ['data_type' => 'string', 'control' => 'enum'],
        ],
        'writeable' => [],
      ],

      // Icon field populated by the scan/apply tool in
      // includes/allergen-icons.php (matches allergen-icons/*.svg to
      // allergen titles) — same pattern as flavor.photo. 'post_name' is
      // what flavor.allergens' post_names values already are
      // (scoop_post_names_out() extracts post_name), so it's the join key
      // client-side maps allergen slug -> icon URL with.
      'allergen' => [
        'post_type' => 'allergen',
        'pod'       => 'allergen',
        'title'     => true,
        'fields'    => [
          'icon' => ['data_type' => 'file'],
        ],
        'post_fields' => [
          'post_name' => 'string',
        ],
        'writeable' => [],
      ],

      // Reference-only lookups for the Task form's batch/prep/recipe_count
      // create-line pickers (assets/ui/task-form.js) — id + title only, not
      // writeable/editable anywhere in this app.
      'recipe' => [
        'post_type' => 'recipe',
        'pod'       => 'recipe',
        'title'     => true,
        'fields'    => [],
        'writeable' => [],
      ],

      'ingredient' => [
        'post_type' => 'ingredient',
        'pod'       => 'ingredient',
        'title'     => true,
        'fields'    => [],
        'writeable' => [],
      ],

      'unit' => [
        'post_type' => 'unit',
        'pod'       => 'unit',
        'title'     => true,
        'fields'    => [],
        'writeable' => [],
      ],
    ];
  } // end cache build

  if ( $key === '' ) return $cache;
  if ( ! isset( $cache[ $key ] ) ) {
    error_log( "scoop_entity_specs: WARNING - key not found: {$key}" );
    return [];
  }

  return $cache[ $key ];
}

/**
 * Per-entity map of "this field's value(s) are id(s) into another pod" —
 * { entity => { field => { pod, multi } } } — built from the same 'titleMap'
 * + 'data_type' already on each field in scoop_entity_specs(). Shipped to the
 * client as SCOOP.entityRelations so the generic Details panel (assets/ui/details.js)
 * can turn a raw relationship id/array into a clickable, title-resolved link
 * for ANY entity, not just whichever pod happens to be a grid's primary.
 */
function scoop_entity_relations(): array {
  $out = [];

  foreach ( scoop_entity_specs() as $entity_key => $spec ) {
    $fields = $spec['fields'] ?? [];
    $rels   = [];

    foreach ( $fields as $field_key => $def ) {
      if ( is_string( $def ) ) $def = [ 'data_type' => $def ];
      if ( ! is_array( $def ) ) continue;

      $titleMap = $def['titleMap'] ?? null;
      if ( ! $titleMap ) continue;

      $rels[ $field_key ] = [
        'pod'   => $titleMap,
        'multi' => ( $def['data_type'] ?? '' ) === 'ids',
      ];
    }

    if ( $rels ) $out[ $entity_key ] = $rels;
  }

  return $out;
}

/**
 * Per-type { needs, writesPods } — shipped to the client as SCOOP.refreshScope
 * so a triggered refresh (autosave, manual Save, filter change) can be
 * scoped to only the on-page grid types that could plausibly need fresher
 * data, instead of always refetching the full page-wide type union. See
 * PERFORMANCE-REFACTOR.md item #2.
 *
 * Single-sourced from the two functions that already declare this data —
 * scoop_bundle_specs() (needs) and scoop_routes_config() (pod_name +
 * cascades_to) — no hand-maintained duplicate.
 *
 * The naive scoping rule ("only refetch other types whose needs overlap the
 * triggering type's own needs") turns out useless: every bundle type's
 * needs includes 'flavor', so that overlap always matches everything. The
 * rule that actually works is which POD(s) the triggering write targets
 * (writesPods) matched against every other on-page type's needs — e.g.
 * Cabinet's writes target the 'slot' pod, so a Cabinet autosave should only
 * refresh other on-page types whose needs include 'slot' (FlavorTub,
 * CabinetWorkflow, ...), not Batch/BatchHistory (neither needs 'slot').
 *
 * writesPods is an ARRAY, not a single pod, because some routes write to
 * more than their own declared pod_name as a side effect: Batch's create
 * hook (scoop_create_tubs_for_new_batch) also creates 'tub' rows, and
 * Closeout's save hook (scoop_process_closeout) also marks tubs Emptied —
 * see cascades_to on those two entries in scoop_routes_config(). Missing
 * this originally meant a Batch save's scoped refresh only re-fetched
 * {batch, flavor} and silently left FlavorTub (needs 'tub', not 'batch')
 * stale until something else refreshed it — caught by tests/smoke's
 * lifecycle spec, not by hand.
 *
 * writesPods is [] for any type with no writeable route in
 * scoop_routes_config() (BatchHistory, ItemPivot, Popular, Flavors,
 * Analytics — and CabinetWorkflow, which has no _config.php entry at all:
 * its writes go through the FlavorTub/Cabinet envelope keys, see
 * cabinet-workflow-tile.js/confirm-swap-modal.js). The client treats an
 * empty writesPods as "never triggers a scoped refresh of OTHER types;
 * always fall back to the full page-wide refetch when THIS type is the
 * trigger" — under-scoping silently would be worse than one extra fetch.
 */
function scoop_client_refresh_scope(): array {
  $specs  = scoop_bundle_specs();
  $routes = scoop_routes_config();

  $out = [];
  foreach ( $specs as $type => $spec ) {
    $route      = $routes[ $type ] ?? [];
    $writesPods = array_filter( array_merge(
      [ $route['pod_name'] ?? null ],
      $route['cascades_to'] ?? []
    ) );

    $out[ $type ] = [
      'needs'      => $spec['needs'] ?? [],
      'writesPods' => array_values( array_unique( $writesPods ) ),
    ];
  }
  return $out;
}