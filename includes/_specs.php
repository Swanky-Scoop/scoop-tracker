<?php

function scoop_bundle_specs(): array {
  
  $specs = [
    'Cabinet'      => ['needs' => ['cabinet','slot','flavor']],
    'FlavorTub'    => ['needs' => ['tub','flavor','use']],
    'Batch'        => ['needs' => ['flavor']],
    'Closeout'     => ['needs' => ['flavor','use']],
    'DateActivity' => ['needs' => ['tub','inventory_change','flavor','use','location','slot','cabinet']],
  ];
  
  return $specs;
}

function scoop_get_entity_spec_keys(string $bundle_key): array {
  
  $specs = scoop_bundle_specs();
  
  if (!isset($specs[$bundle_key])) {
    error_log("🔍 TRACE: WARNING - Bundle key not found: $bundle_key");
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
          'index'         => ['data_type' => 'int',      'hidden'  => true],
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
            $modified_since = strtotime($ctx['modified_since'] ?? '') ?: (time() - (48 * 60 * 60));
            $candidates = [
              $row['opened_on'] ?? '',
              $row['emptied_at'] ?? '',
              $row['created_on'] ?? '',
            ];

            if (($row['state'] ?? '') === '__override__') {
              $candidates[] = $row['post_modified'] ?? '';
            }

            foreach ($candidates as $candidate) {
              $modified = strtotime($candidate);
              if ($modified && $modified >= $modified_since) return true;
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
          'source'       => ['data_type' => 'string'],
          'problem'      => ['data_type' => 'string'],
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

          $modified = strtotime($row['post_modified'] ?? $row['post_date'] ?? '');
          $modified_since = strtotime($ctx['modified_since'] ?? '') ?: (time() - (48 * 60 * 60));

          return $modified && $modified >= $modified_since;
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
    ];
  } // end cache build

  if ( $key === '' ) return $cache;

  if ( ! isset( $cache[ $key ] ) ) {
    error_log( "scoop_entity_specs: WARNING - key not found: {$key}" );
    return [];
  }

  return $cache[ $key ];
}
