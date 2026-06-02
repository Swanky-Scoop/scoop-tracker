<?php

function scoop_bundle_specs(): array {
  
  $specs = [
    'Cabinet'      => ['needs' => ['cabinet','slot','flavor','tub']],
    'FlavorTub'    => ['needs' => ['tub','flavor','use','slot']],
    'Batch'        => ['needs' => ['flavor']],
    'BatchHistory' => ['needs' => ['batch','flavor']],
    'Closeout'     => ['needs' => ['flavor','use']],
    'DateActivity' => ['needs' => ['tub','inventory_change','flavor','use','location','slot','cabinet']],
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
          'author_name'   => ['data_type' => 'string',   'label'   => 'Author'],
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
        ],
        'post_fields' => [
          'author_name'   => 'string',
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

          // Other grids need: active tubs (not Emptied)
          if ($has_other_grids && $state !== 'Emptied') {
            return true;
          }

          return false;
        },

        'writeable' => ['state','use','amount']
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
        ],
        'writeable' => ['current_flavor','immediate_flavor','next_flavor'],
      ],

      'cabinet' => [
        'post_type' => 'cabinet',
        'pod'       => 'cabinet',
        'title'     => 'Cabinets',
        'fields'    => [
          'location' => ['data_type' => 'int', 'control' => 'find'],
          'max_tubs' => ['data_type' => 'int', 'control' => 'find'],
        ],
        'writeable' => []
      ],

      'flavor' => [
        'post_type' => 'flavor',
        'pod'       => 'flavor',
        'titleMap'  => 'flavor',
        'title'     => 'Flavors',
        'fields'    => [
          'web_id'    => ['data_type' => 'int'],
          'allergens' => ['data_type' => 'post_names'],
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
    ];
  } // end cache build

  if ( $key === '' ) return $cache;
  if ( ! isset( $cache[ $key ] ) ) {
    error_log( "scoop_entity_specs: WARNING - key not found: {$key}" );
    return [];
  }

  return $cache[ $key ];
}