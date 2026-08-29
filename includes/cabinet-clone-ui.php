<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * includes/cabinet-clone-ui.php
 *
 * Admin page "Clone Cabinets" under the Scoop menu. Copies an existing
 * location's cabinet structure (each cabinet's max_tubs + prohibited_allergens)
 * into a new location — for standing up a real second location's fixtures on
 * TEST/OPS, not just local sample data (see includes/sample-tubs-ui.php,
 * which is the tub-inventory equivalent but local/synthetic-only).
 *
 * Deliberately thin: creating a cabinet via pods_api()->save_pod_item()
 * already triggers this plugin's own existing hooks —
 * scoop_cabinet_pre_save_title (includes/hooks/cabinet-slot.php) derives the
 * cabinet's title/slug from location+allergens+max_tubs, and
 * scoop_cabinet_post_save_create_slots creates all max_tubs of its slots
 * automatically, titled and cabinet-linked. This tool only needs to read the
 * source cabinets' max_tubs/prohibited_allergens and re-save that shape
 * under the target location — no direct SQL, no slot-creation logic
 * duplicated here. Slots intentionally start with no current/immediate/next
 * flavor — that's live workflow state, not structure, and shouldn't be
 * copied from a different location's in-progress state.
 *
 * Report-first + nonce + confirm checkbox, same shape as every other admin
 * tool here (Schema Sync, Republish Tubs, Sample Tubs) — no extra gate for
 * shipping to TEST/OPS beyond what those already use.
 */

add_action( 'admin_menu', 'scoop_register_cabinet_clone_admin_page', 20 );

function scoop_register_cabinet_clone_admin_page(): void {
  add_submenu_page(
    'scoop_root',
    'Clone Cabinets',
    'Clone Cabinets',
    'manage_options',
    'scoop_cabinet_clone',
    'scoop_render_cabinet_clone_page'
  );
}

/** ------------------------------------------------------------
 *  Data gathering
 * ------------------------------------------------------------ */

/** Every cabinet at $location_id — id, max_tubs, prohibited_allergens (ids), title, current slot count. */
function scoop_cabinet_clone_basis( int $location_id ): array {
  if ( ! function_exists( 'pods' ) ) return [];

  $pod = pods( 'cabinet' );
  $pod->find( [ 'limit' => -1, 'orderby' => 't.ID ASC' ] );

  $basis = [];
  while ( $pod->fetch() ) {
    if ( scoop_rel_id( $pod->field( 'location' ) ) !== $location_id ) continue;

    $cabinet_id = (int) $pod->id();
    $allergens  = (array) $pod->field( 'prohibited_allergens', [ 'output' => 'ids' ] );

    $slot_count = (int) pods( 'slot', [ 'where' => "cabinet.ID = {$cabinet_id}", 'limit' => -1 ] )->total();

    $basis[] = [
      'id'                   => $cabinet_id,
      'title'                => (string) $pod->field( 'post_title' ),
      'max_tubs'             => (int) $pod->field( 'max_tubs' ),
      'prohibited_allergens' => array_values( array_map( 'intval', $allergens ) ),
      'slot_count'           => $slot_count,
    ];
  }

  return $basis;
}

/** ------------------------------------------------------------
 *  Create
 * ------------------------------------------------------------ */

/**
 * Creates one new cabinet per entry in $basis at $target_location_id.
 * Title/slug and slots are generated automatically by this plugin's own
 * existing hooks (see file header) — this just supplies location/max_tubs/
 * prohibited_allergens and lets pods_api()->save_pod_item() do the rest.
 * Returns [ 'cabinets' => [new cabinet id, ...], 'slots' => total slot count ].
 */
function scoop_cabinet_clone_create( array $basis, int $target_location_id ): array {
  if ( empty( $basis ) || ! function_exists( 'pods_api' ) ) return [ 'cabinets' => [], 'slots' => 0 ];

  $created_ids = [];
  $slot_total  = 0;

  foreach ( $basis as $src ) {
    if ( $src['max_tubs'] <= 0 ) continue;

    $new_id = pods_api()->save_pod_item( [
      'pod'  => 'cabinet',
      'data' => [
        'location'             => $target_location_id,
        'max_tubs'             => $src['max_tubs'],
        'prohibited_allergens' => $src['prohibited_allergens'],
      ],
    ] );

    if ( is_wp_error( $new_id ) || ! $new_id ) continue;

    update_post_meta( (int) $new_id, '_scoop_cloned_cabinet', 1 );
    update_post_meta( (int) $new_id, '_scoop_cloned_source_cabinet', $src['id'] );

    $created_ids[] = (int) $new_id;
    $slot_total   += $src['max_tubs']; // scoop_cabinet_post_save_create_slots creates exactly max_tubs slots.
  }

  if ( $created_ids ) {
    wp_cache_flush();
    if ( function_exists( 'scoop_cache_bust' ) ) scoop_cache_bust();
  }

  return [ 'cabinets' => $created_ids, 'slots' => $slot_total ];
}

/** ------------------------------------------------------------
 *  Cleanup
 * ------------------------------------------------------------ */

/** Tagged cloned cabinets grouped by their (real, live) location — for the Cleanup section. */
function scoop_cabinet_clone_tagged_summary(): array {
  global $wpdb;

  $cabinet_ids = $wpdb->get_col(
    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_scoop_cloned_cabinet'"
  );
  if ( empty( $cabinet_ids ) ) return [];

  $pod = pods( 'cabinet' );
  $byLocation = [];
  foreach ( $cabinet_ids as $cid ) {
    $pod->fetch( (int) $cid );
    if ( ! $pod->exists() ) continue;
    $locId = scoop_rel_id( $pod->field( 'location' ) );
    if ( ! isset( $byLocation[ $locId ] ) ) $byLocation[ $locId ] = [ 'cabinets' => 0, 'slots' => 0 ];
    $byLocation[ $locId ]['cabinets']++;
    $byLocation[ $locId ]['slots'] += (int) pods( 'slot', [ 'where' => "cabinet.ID = {$cid}", 'limit' => -1 ] )->total();
  }

  $out = [];
  foreach ( $byLocation as $locId => $counts ) {
    $out[] = [
      'location_id'    => $locId,
      'location_title' => get_the_title( $locId ) ?: "Location {$locId}",
      'cabinets'       => $counts['cabinets'],
      'slots'          => $counts['slots'],
    ];
  }
  return $out;
}

/**
 * Deletes every tagged cloned cabinet at $location_id (or every tagged
 * cloned cabinet everywhere, if $location_id is 0), plus each one's slots —
 * via pods_api()->delete_pod_item() so relationship cleanup happens
 * correctly. Small N (cabinets/slots, not tubs) — the direct-SQL approach
 * Sample Tubs uses for performance isn't needed here.
 */
function scoop_cabinet_clone_delete( int $location_id = 0 ): array {
  global $wpdb;
  if ( ! function_exists( 'pods_api' ) ) return [ 'cabinets' => 0, 'slots' => 0 ];

  $cabinet_ids = $wpdb->get_col(
    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_scoop_cloned_cabinet'"
  );
  if ( empty( $cabinet_ids ) ) return [ 'cabinets' => 0, 'slots' => 0 ];

  $pod = pods( 'cabinet' );
  if ( $location_id > 0 ) {
    $cabinet_ids = array_values( array_filter( $cabinet_ids, function ( $cid ) use ( $pod, $location_id ) {
      $pod->fetch( (int) $cid );
      return $pod->exists() && scoop_rel_id( $pod->field( 'location' ) ) === $location_id;
    } ) );
  }
  if ( empty( $cabinet_ids ) ) return [ 'cabinets' => 0, 'slots' => 0 ];

  $deleted_cabinets = 0;
  $deleted_slots    = 0;

  foreach ( $cabinet_ids as $cid ) {
    $cid = (int) $cid;

    $slot_ids = $wpdb->get_col( $wpdb->prepare(
      "SELECT p.ID FROM {$wpdb->posts} p
       JOIN {$wpdb->prefix}podsrel pr ON pr.item_id = p.ID
       JOIN {$wpdb->posts} cab ON cab.ID = pr.related_item_id
       WHERE p.post_type = 'slot' AND cab.ID = %d",
      $cid
    ) );
    // Fallback if the podsrel join above doesn't match this environment's field ids exactly — Pods API lookup is the reliable path.
    if ( empty( $slot_ids ) ) {
      $slot_pod = pods( 'slot', [ 'where' => "cabinet.ID = {$cid}", 'limit' => -1 ] );
      while ( $slot_pod->fetch() ) $slot_ids[] = (int) $slot_pod->id();
    }

    foreach ( $slot_ids as $sid ) {
      pods_api()->delete_pod_item( [ 'pod' => 'slot', 'id' => (int) $sid ] );
      $deleted_slots++;
    }

    pods_api()->delete_pod_item( [ 'pod' => 'cabinet', 'id' => $cid ] );
    $deleted_cabinets++;
  }

  wp_cache_flush();
  if ( function_exists( 'scoop_cache_bust' ) ) scoop_cache_bust();

  return [ 'cabinets' => $deleted_cabinets, 'slots' => $deleted_slots ];
}

/** ------------------------------------------------------------
 *  Admin page
 * ------------------------------------------------------------ */

function scoop_render_cabinet_clone_page(): void {
  if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

  $locations = scoop_sample_tubs_locations(); // shared helper, includes/sample-tubs-ui.php

  $source_id = isset( $_REQUEST['source_location'] ) ? (int) $_REQUEST['source_location'] : 935;
  $target_id = isset( $_REQUEST['target_location'] ) ? (int) $_REQUEST['target_location'] : 0;

  $preview       = null;
  $create_result = null;
  $delete_result = null;

  if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['scoop_cabinet_clone_action'] ) ) {
    $action = sanitize_key( $_POST['scoop_cabinet_clone_action'] );

    if ( $action === 'preview' ) {
      check_admin_referer( 'scoop_cabinet_clone_preview' );
      $preview = scoop_cabinet_clone_basis( $source_id );

    } elseif ( $action === 'generate' ) {
      check_admin_referer( 'scoop_cabinet_clone_generate' );

      if ( empty( $_POST['scoop_cabinet_clone_confirm'] ) || ! $target_id ) {
        echo '<div class="notice notice-error"><p>Pick a target location and tick the confirmation checkbox before generating.</p></div>';
      } else {
        $basis         = scoop_cabinet_clone_basis( $source_id );
        $create_result = scoop_cabinet_clone_create( $basis, $target_id );
      }
      $preview = scoop_cabinet_clone_basis( $source_id );

    } elseif ( $action === 'delete' ) {
      check_admin_referer( 'scoop_cabinet_clone_delete' );
      if ( empty( $_POST['scoop_cabinet_clone_delete_confirm'] ) ) {
        echo '<div class="notice notice-error"><p>Tick the confirmation checkbox before deleting.</p></div>';
      } else {
        $del_location  = (int) ( $_POST['delete_location'] ?? 0 );
        $delete_result = scoop_cabinet_clone_delete( $del_location );
      }
    }
  }

  $tagged = scoop_cabinet_clone_tagged_summary();

  echo '<div class="wrap">';
  echo '<h1>Clone Cabinets</h1>';
  echo '<p>Copies an existing location\'s cabinet structure (each cabinet\'s tub capacity and allergen restrictions) into a new location. Titles and slots are generated the same way a normal cabinet creation would — nothing here bypasses this plugin\'s existing rules. New slots start empty (no flavor assigned) — that\'s live workflow state, never copied.</p>';

  // ── Generate form ──────────────────────────────────────────────────────
  echo '<div class="scoop-rcc-card"><h2>Generate</h2><form method="post">';
  wp_nonce_field( 'scoop_cabinet_clone_preview' );
  echo '<table class="form-table"><tbody>';

  echo '<tr><th><label for="source_location">Source location</label></th><td><select name="source_location" id="source_location">';
  foreach ( $locations as $loc ) {
    printf( '<option value="%d"%s>%s</option>', $loc->ID, selected( $source_id, $loc->ID, false ), esc_html( $loc->post_title ) );
  }
  echo '</select></td></tr>';

  echo '<tr><th><label for="target_location">Target location</label></th><td><select name="target_location" id="target_location"><option value="0">— choose —</option>';
  foreach ( $locations as $loc ) {
    if ( (int) $loc->ID === $source_id ) continue;
    printf( '<option value="%d"%s>%s</option>', $loc->ID, selected( $target_id, $loc->ID, false ), esc_html( $loc->post_title ) );
  }
  echo '</select></td></tr>';

  echo '</tbody></table>';
  echo '<input type="hidden" name="scoop_cabinet_clone_action" value="preview">';
  submit_button( 'Preview', 'secondary', 'submit', false );
  echo '</form>';

  if ( $preview !== null ) {
    $total_cabinets = count( $preview );
    $total_slots    = array_sum( array_column( $preview, 'max_tubs' ) );

    echo '<h3>Preview</h3>';
    if ( empty( $preview ) ) {
      echo '<p>No cabinets found at the chosen source location.</p>';
    } else {
      echo '<p>Would create <strong>' . (int) $total_cabinets . '</strong> cabinets (<strong>' . (int) $total_slots . '</strong> slots total) at the chosen target location:</p>';
      echo '<table class="widefat striped"><thead><tr><th>Source cabinet</th><th>Max tubs</th><th>Prohibited allergens</th></tr></thead><tbody>';
      foreach ( $preview as $c ) {
        $allergen_labels = array_map( fn( $id ) => get_the_title( $id ) ?: "#{$id}", $c['prohibited_allergens'] );
        echo '<tr><td>' . esc_html( $c['title'] ) . '</td><td>' . (int) $c['max_tubs'] . '</td><td>' . esc_html( implode( ', ', $allergen_labels ) ?: '—' ) . '</td></tr>';
      }
      echo '</tbody></table>';

      if ( $create_result !== null ) {
        echo '<div class="notice notice-success"><p>Created <strong>' . count( $create_result['cabinets'] ) . '</strong> cabinets, <strong>' . (int) $create_result['slots'] . '</strong> slots.</p></div>';
      }

      echo '<form method="post" onsubmit="return confirm(\'Create ' . (int) $total_cabinets . ' cabinets (' . (int) $total_slots . ' slots) at the chosen target location?\');">';
      wp_nonce_field( 'scoop_cabinet_clone_generate' );
      echo '<input type="hidden" name="source_location" value="' . esc_attr( $source_id ) . '">';
      echo '<input type="hidden" name="target_location" value="' . esc_attr( $target_id ) . '">';
      echo '<input type="hidden" name="scoop_cabinet_clone_action" value="generate">';
      echo '<p><label><input type="checkbox" name="scoop_cabinet_clone_confirm" value="1" required> <strong>Create these cabinets at the target location.</strong></label></p>';
      submit_button( 'Generate', 'primary', 'submit', false );
      echo '</form>';
    }
  }
  echo '</div>';

  // ── Cleanup ─────────────────────────────────────────────────────────────
  echo '<div class="scoop-rcc-card"><h2>Cleanup</h2>';
  if ( $delete_result !== null ) {
    echo '<div class="notice notice-success"><p>Deleted <strong>' . (int) $delete_result['cabinets'] . '</strong> cabinets, <strong>' . (int) $delete_result['slots'] . '</strong> slots.</p></div>';
    $tagged = scoop_cabinet_clone_tagged_summary();
  }

  if ( empty( $tagged ) ) {
    echo '<p>No tagged cloned cabinets exist right now.</p>';
  } else {
    echo '<table class="widefat striped"><thead><tr><th>Location</th><th>Cabinets</th><th>Slots</th><th></th></tr></thead><tbody>';
    foreach ( $tagged as $row ) {
      echo '<tr><td>' . esc_html( $row['location_title'] ) . '</td><td>' . (int) $row['cabinets'] . '</td><td>' . (int) $row['slots'] . '</td><td>';
      echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Delete all ' . (int) $row['cabinets'] . ' cloned cabinets (and their slots) at ' . esc_js( $row['location_title'] ) . '? This cannot be undone.\');">';
      wp_nonce_field( 'scoop_cabinet_clone_delete' );
      echo '<input type="hidden" name="scoop_cabinet_clone_action" value="delete">';
      echo '<input type="hidden" name="delete_location" value="' . (int) $row['location_id'] . '">';
      echo '<label><input type="checkbox" name="scoop_cabinet_clone_delete_confirm" value="1" required> Confirm</label> ';
      submit_button( 'Delete', 'delete', 'submit', false );
      echo '</form>';
      echo '</td></tr>';
    }
    echo '</tbody></table>';
  }
  echo '</div>';

  echo '</div>';
}
