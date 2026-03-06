<?php

function scoop_bundle_specs(): array {
  error_log('🔍 TRACE: scoop_bundle_specs() called');
  
  $specs = [
    'Cabinet'   => ['needs' => ['cabinet','slot','flavor']],
    'FlavorTub' => ['needs' => ['tub','flavor','use']],
    'Batch'     => ['needs' => ['flavor']],
    'Closeout'  => ['needs' => ['flavor','use']],
    'DateActivity' => ['needs' => ['tub','flavor','use','location']],
  ];
  
  error_log('🔍 TRACE: Bundle specs available: ' . implode(', ', array_keys($specs)));
  
  return $specs;
}

function scoop_get_entity_spec_keys(string $bundle_key): array {
  error_log("🔍 TRACE: scoop_get_entity_spec_keys() called for: $bundle_key");
  
  $specs = scoop_bundle_specs();
  
  if (!isset($specs[$bundle_key])) {
    error_log("🔍 TRACE: WARNING - Bundle key not found: $bundle_key");
    return [];
  }
  
  $needs = $specs[$bundle_key]['needs'];
  error_log("🔍 TRACE: Entity specs for $bundle_key: " . implode(', ', $needs));
  
  return $needs;
}

function scoop_entity_specs(string $key = ''): array {
  error_log('🔍 TRACE: scoop_entity_specs() called with key: ' . ($key ?: '(empty)'));
  
  $spc = [
    'tub' => [
      'post_type' => 'tub',
      'pod'       => 'tub',
      'title'     => true,
      'fields'    => [
        'state'         => ['data_type' => 'string',  'control' => 'enum'  ],
        'use'           => ['data_type' => 'int',     'control' => 'find',  'titleMap' => 'use'],
        'flavor'        => ['data_type' => 'int',     'control' => 'find', 'titleMap' => 'flavor'],
        'amount'        => ['data_type' => 'float',   'control' => 'text'  ],
        'author_name'   => ['data_type' => 'string',  'label'   => 'Author'],
        'date'          => ['data_type' => 'datetime','control' => 'text', 'label' => 'Posted'],
        'created_on'    => ['data_type' => 'datetime','control' => 'text', 'label' => 'Made'],
        'changed_on'    => ['data_type' => 'datetime','control' => 'text', 'label' => 'Changed'],
        'post_modified' => ['data_type' => 'datetime','control' => 'find', 'label' => 'Updated'],
        'opened_on'     => ['data_type' => 'string'],
        'emptied_at'    => ['data_type' => 'string'],
        'location'      => ['data_type' => 'int',     'control' => 'find', 'titleMap' => 'location', 'hidden' => true],
        'index'         => ['data_type' => 'int',     'hidden'  => true],
      ],
      'post_fields' => [
        'author_name'   => 'string',
        'post_modified' => 'datetime',
        'post_date'     => 'datetime',
      ],
      'filter' => function(array $row, array $ctx) {
        $state = $row['state'] ?? '';
        $requesting_types = $ctx['requesting_types'] ?? [];
        
        // DEBUG
        error_log("TUB FILTER - Tub {$row['id']}: state={$state}, requesting_types=" . json_encode($requesting_types));
        
        $has_date_activity = in_array('DateActivity', $requesting_types, true);
        $has_other_grids = !empty(array_diff($requesting_types, ['DateActivity']));
        
        error_log("  has_date_activity={$has_date_activity}, has_other_grids={$has_other_grids}");
        
        // DateActivity needs: recent tubs (any state)
        if ($has_date_activity) {
            $modified = strtotime($row['post_modified'] ?? '');
            $fortyEightHoursAgo = time() - (48 * 60 * 60);
            
            if ($modified && $modified >= $fortyEightHoursAgo) {
                error_log("  KEEP: Recent tub for DateActivity");
                return true;
            }
        }
        
        // Other grids need: active tubs (not Emptied)
        if ($has_other_grids && $state !== 'Emptied') {
            error_log("  KEEP: Active tub for other grids");
            return true;
        }
        
        error_log("  REJECT: Doesn't match any grid needs");
        return false;
      },
      
      'writeable' => ['state','use','amount']
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
        'location' => ['data_type' => 'int', 'control' => 'find' ],
        'max_tubs' => ['data_type' => 'int', 'control' => 'find' ],
      ],
      'writeable' => []
    ],

    'flavor' => [
      'post_type' => 'flavor',
      'pod'       => 'flavor',
      'titleMap'  => 'flavor',
      'title'     => 'Flavors',
      'fields'    => [
        'web_id' => ['data_type' => 'int']
        // add fields as needed; you can omit tub if you'll compute from tub list
      ],
      'writeable' => []
    ],

    'use' => [
      'post_type' => 'use',
      'pod'       => 'use',
      'titleMap'  => 'use',
      'title'     => 'Uses',
      'fields'    => [
      'order'     => 'int',
      'titleMap'  => 'use'
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
    ]
  ];
  
  if ($key === '') {
    error_log('🔍 TRACE: Returning all entity specs, count: ' . count($spc));
    return $spc;
  }
  
  if (!isset($spc[$key])) {
    error_log("🔍 TRACE: WARNING - Entity spec key not found: $key");
    return [];
  }
  
  error_log("🔍 TRACE: Returning entity spec for: $key");
  return $spc[$key];
}
