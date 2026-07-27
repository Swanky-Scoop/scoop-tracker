<?php

function scoop_bundle_specs(): array {
  
  $specs = [
    'Cabinet'      => ['needs' => ['cabinet','slot','flavor','tub']],
    'FlavorTub'    => ['needs' => ['tub','flavor','use','slot']],
    'Batch'        => ['needs' => ['flavor']],
    'BatchHistory' => ['needs' => ['batch','flavor']],
    'Closeout'     => ['needs' => ['flavor','use']],
    'DateActivity' => ['needs' => ['tub','inventory_change','flavor','use','location','slot','cabinet']],
    'InstockFlavor'=> ['needs' => ['flavor','tub','slot','cabinet']],
    // 'allergen' is a small fixed reference table (unlike 'batch' — see the
    // comment on that one elsewhere in change-tub.md) — cheap to fetch
    // whole, needed for the allergen icon URLs shown on each slot's flavor.
    'CabinetWorkflow' => ['needs' => ['cabinet','slot','flavor','tub','allergen']],
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
          'location'      => ['data_type' => 'int',      'control' => 'find', 'titleMap' => 'location', 'hidden' => true],
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

          $has_date_activity = in_array('DateActivity', $requesting_types, true);
          $has_other_grids   = !empty(array_diff($requesting_types, ['DateActivity']));

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
          if (!in_array('DateActivity', $requesting_types, true)) {
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

      // Read-only batch entity used by the BatchHistory grid.
      // Date filter on t.post_date is applied in scoop_fetch_entities() below
      // when BatchHistory is among the requested grid types.
      'batch' => [
        'post_type' => 'batch',
        'pod'       => 'batch',
        'title'     => true,
        'fields'    => [
          'count'  => ['data_type' => 'float'],
          'flavor' => ['data_type' => 'int', 'control' => 'find', 'titleMap' => 'flavor'],
        ],
        'post_fields' => [
          'author_name' => 'string',
          'post_date'   => 'datetime',
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