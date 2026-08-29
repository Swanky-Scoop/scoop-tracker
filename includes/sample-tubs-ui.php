<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * includes/sample-tubs-ui.php
 *
 * Admin page "Sample Tubs" under the Scoop menu. Generates a synthetic-but-
 * realistic tub population for a second location by sampling an existing
 * location's real tubs — same flavor/use/state distribution, proportionally
 * scaled down — so new-location GUI work (Cabinet/CabinetWorkflow/FlavorTub
 * location filters, etc.) has real-looking data to develop against instead
 * of an empty grid. Every tub this tool creates is tagged (postmeta
 * `_scoop_sample_tub`) so it can be found and bulk-deleted again later,
 * distinctly from real inventory.
 *
 * Report-first, per CLAUDE.md's data-repair policy: "Preview" computes and
 * shows the basis/sample stats without writing anything; "Generate" (nonce +
 * confirm checkbox) is the only action that writes. Matches
 * includes/republish-tubs-ui.php / includes/supply-import-ui.php's shape.
 *
 * Both the create and delete paths use direct bulk SQL rather than
 * pods_api()->save_pod_item()/delete_pod_item() per tub — same reasoning as
 * scoop_create_batch_tubs_direct() in includes/hooks/batch-tub.php (see that
 * function's own docblock): Pods' per-item relationship-save/delete
 * machinery is O(n) on high-fanout fields like tub.location, measured at
 * seconds per tub on this project's real data. A few hundred tubs through
 * the slow path would be a real wait; direct SQL is one query per step
 * regardless of row count.
 */

add_action( 'admin_menu', 'scoop_register_sample_tubs_admin_page', 20 );

function scoop_register_sample_tubs_admin_page(): void {
  add_submenu_page(
    'scoop_root',
    'Sample Tubs',
    'Sample Tubs',
    'manage_options',
    'scoop_sample_tubs',
    'scoop_render_sample_tubs_page'
  );
}

/** ------------------------------------------------------------
 *  Data gathering
 * ------------------------------------------------------------ */

function scoop_sample_tubs_locations(): array {
  global $wpdb;
  $rows = $wpdb->get_results(
    "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'location' AND post_status = 'publish' ORDER BY post_title ASC"
  );
  return $rows ?: [];
}

/**
 * Every published tub at $location_id, excluding ones whose state is
 * 'Emptied' and whose emptied_at is older than $exclude_days days — those
 * are "dead" inventory a moment-in-time sample shouldn't be based on.
 * Relationship fields resolved via $pod->field() + scoop_rel_id() (NOT
 * postmeta — see project memory on Pods relationship storage: the postmeta
 * mirror is unreliable at scale for tub's own fields).
 */
function scoop_sample_tubs_compute_basis( int $location_id, int $exclude_days ): array {
  if ( ! function_exists( 'pods' ) ) return [];

  $pod = pods( 'tub' );
  $pod->find( [ 'limit' => -1, 'orderby' => 't.ID ASC' ] );

  $cutoff = time() - ( $exclude_days * DAY_IN_SECONDS );
  $basis  = [];

  while ( $pod->fetch() ) {
    if ( scoop_rel_id( $pod->field( 'location' ) ) !== $location_id ) continue;

    $state      = (string) $pod->field( 'state' );
    $emptied_at = (string) $pod->field( 'emptied_at' );

    if ( $state === 'Emptied' && $emptied_at && $emptied_at !== '0000-00-00 00:00:00' ) {
      $ts = strtotime( str_replace( ' ', 'T', $emptied_at ) );
      if ( $ts !== false && $ts < $cutoff ) continue;
    }

    $basis[] = [
      'id'         => (int) $pod->id(),
      'flavor'     => scoop_rel_id( $pod->field( 'flavor' ) ),
      'use'        => scoop_rel_id( $pod->field( 'use' ) ),
      'state'      => $state,
      'index'      => (int) $pod->field( 'index' ),
      'amount'     => (float) $pod->field( 'amount' ),
      'opened_on'  => (string) $pod->field( 'opened_on' ),
      'emptied_at' => $emptied_at,
      'created_on' => (string) $pod->field( 'created_on' ),
      'title'      => (string) $pod->field( 'post_title' ),
    ];
  }

  return $basis;
}

/**
 * Proportionally samples $basis down to round(count($basis) * $fraction),
 * preserving the flavor|use|state distribution as closely as integer counts
 * allow. Largest-remainder method: each group's exact (unrounded) share is
 * floored, then whichever groups lost the most to flooring get the leftover
 * seats first, so the total always lands exactly on the target instead of
 * drifting from per-group rounding. Within a group, which specific tubs get
 * picked is random (shuffle + slice) — only the group SIZE is deterministic.
 */
function scoop_sample_tubs_stratified_sample( array $basis, float $fraction ): array {
  $basisCount = count( $basis );
  if ( $basisCount === 0 ) return [];

  $fraction = max( 0.0, min( 1.0, $fraction ) );
  $target   = (int) round( $basisCount * $fraction );
  if ( $target <= 0 ) return [];

  $groups = [];
  foreach ( $basis as $tub ) {
    $key = $tub['flavor'] . '|' . $tub['use'] . '|' . $tub['state'];
    $groups[ $key ][] = $tub;
  }

  $exact      = [];
  $floored    = [];
  $remainders = [];
  foreach ( $groups as $key => $items ) {
    $share            = count( $items ) * $fraction;
    $exact[ $key ]    = $share;
    $floored[ $key ]  = (int) floor( $share );
    $remainders[ $key ] = $share - $floored[ $key ];
  }

  $allocated = array_sum( $floored );
  $remaining = $target - $allocated;

  arsort( $remainders );
  foreach ( array_keys( $remainders ) as $key ) {
    if ( $remaining <= 0 ) break;
    $floored[ $key ]++;
    $remaining--;
  }

  $selected = [];
  foreach ( $groups as $key => $items ) {
    $take = min( $floored[ $key ] ?? 0, count( $items ) );
    if ( $take <= 0 ) continue;
    shuffle( $items );
    foreach ( array_slice( $items, 0, $take ) as $tub ) {
      $selected[] = $tub;
    }
  }

  return $selected;
}

/** Human-readable breakdown for the preview report — by state, and top flavors by count. */
function scoop_sample_tubs_summarize( array $tubs ): array {
  $byState  = [];
  $byFlavor = [];
  foreach ( $tubs as $t ) {
    $byState[ $t['state'] ]   = ( $byState[ $t['state'] ] ?? 0 ) + 1;
    $byFlavor[ $t['flavor'] ] = ( $byFlavor[ $t['flavor'] ] ?? 0 ) + 1;
  }
  arsort( $byState );
  arsort( $byFlavor );

  $flavorLabels = [];
  foreach ( array_slice( $byFlavor, 0, 12, true ) as $flavorId => $count ) {
    $flavorLabels[] = [ 'label' => get_the_title( $flavorId ) ?: "Flavor {$flavorId}", 'count' => $count ];
  }

  return [
    'total'        => count( $tubs ),
    'by_state'     => $byState,
    'top_flavors'  => $flavorLabels,
    'flavor_count' => count( $byFlavor ),
  ];
}

/** ------------------------------------------------------------
 *  Create (direct bulk write — see file header comment)
 * ------------------------------------------------------------ */

function scoop_sample_tubs_create( array $selected, int $target_location_id, int $source_location_id ): array {
  global $wpdb;
  if ( empty( $selected ) || ! function_exists( 'pods_api' ) ) return [];

  $now_mysql    = current_time( 'mysql' );
  $now_gmt      = current_time( 'mysql', true );
  $current_user = get_current_user_id() ?: 1;

  // Step 1: wp_insert_post per tub.
  $created = [];
  foreach ( $selected as $src ) {
    $post_id = wp_insert_post( [
      'post_type'      => 'tub',
      'post_title'     => $src['title'] !== '' ? "{$src['title']} (sample)" : "Sample tub {$src['id']}",
      'post_status'    => 'publish',
      'post_author'    => $current_user,
      'post_date'      => $now_mysql,
      'post_date_gmt'  => $now_gmt,
      'comment_status' => 'closed',
      'ping_status'    => 'closed',
    ], true );

    if ( is_wp_error( $post_id ) || ! $post_id ) continue;
    $created[] = [ 'id' => (int) $post_id, 'source' => $src ];
  }
  if ( empty( $created ) ) return [];

  // Step 2: one multi-row UPSERT into wp_pods_tub — scalar fields copied
  // straight from the source tub (state/amount/opened_on/emptied_at/index),
  // so an Emptied sample tub still has a real emptied_at (needed by
  // EmptiedLogGridModel's day-bucketing / FlavorTubGridModel's
  // recently-emptied window) instead of looking freshly created.
  //
  // ON DUPLICATE KEY UPDATE, not a plain INSERT: wp_insert_post() above
  // already leaves an empty (all-NULL-scalar) row at this same id in
  // wp_pods_tub — confirmed directly, Pods creates it synchronously as part
  // of registering the new post, before this code ever runs. A plain INSERT
  // collides on the id primary key and fails outright (confirmed: MySQL
  // "Duplicate entry" error, whole multi-row statement aborts, nothing
  // written) — the first version of this function did exactly that and
  // silently produced 233 tubs with blank state/amount/index. Upserting
  // works whether or not Pods happens to pre-create that row.
  $tub_table = $wpdb->prefix . 'pods_tub';
  $values    = [];
  foreach ( $created as $c ) {
    $s = $c['source'];
    $values[] = $wpdb->prepare(
      '(%d, %s, %s, %s, %d, %f, %s, %s)',
      $c['id'],
      $s['opened_on']  ?: '0000-00-00 00:00:00',
      $s['emptied_at'] ?: '0000-00-00 00:00:00',
      $s['state'],
      $s['index'] ?: 1,
      $s['amount'],
      $s['created_on'] ?: $now_mysql,
      $now_mysql
    );
  }
  $sql = "INSERT INTO {$tub_table} (id, opened_on, emptied_at, state, `index`, amount, created_on, changed_on) VALUES "
       . implode( ',', $values )
       . " ON DUPLICATE KEY UPDATE opened_on = VALUES(opened_on), emptied_at = VALUES(emptied_at), "
       . "state = VALUES(state), `index` = VALUES(`index`), amount = VALUES(amount), "
       . "created_on = VALUES(created_on), changed_on = VALUES(changed_on)";
  $result = $wpdb->query( $sql );
  if ( $result === false ) {
    error_log( 'scoop_sample_tubs_create: wp_pods_tub upsert failed: ' . $wpdb->last_error );
  }

  // Step 3: bulk INSERT relationships into wp_podsrel — flavor + location +
  // use, forward and reverse directions, same shape as
  // scoop_create_batch_tubs_direct()'s Step 3 in includes/hooks/batch-tub.php
  // (no `batch` relationship here — these tubs aren't from a real batch).
  $tub_pod = pods_api()->load_pod( [ 'name' => 'tub' ] );
  $tub_pod_id     = (int) ( $tub_pod['id'] ?? 0 );
  $field_flavor   = (int) ( $tub_pod['fields']['flavor']['id']   ?? 0 );
  $field_location = (int) ( $tub_pod['fields']['location']['id'] ?? 0 );
  $field_use      = (int) ( $tub_pod['fields']['use']['id']      ?? 0 );

  if ( $tub_pod_id && $field_flavor ) {
    $flavor_pod   = pods_api()->load_pod( [ 'name' => 'flavor' ] );
    $location_pod = pods_api()->load_pod( [ 'name' => 'location' ] );
    $use_pod      = pods_api()->load_pod( [ 'name' => 'use' ] );

    $flavor_pod_id   = (int) ( $flavor_pod['id']   ?? 0 );
    $location_pod_id = (int) ( $location_pod['id'] ?? 0 );
    $use_pod_id      = (int) ( $use_pod['id']      ?? 0 );

    $flavor_tubs_field   = (int) ( $flavor_pod['fields']['tubs']['id']   ?? 0 );
    $location_tubs_field = (int) ( $location_pod['fields']['tubs']['id'] ?? 0 );
    $use_tubs_field      = (int) ( $use_pod['fields']['tubs']['id']      ?? 0 );

    $podsrel = $wpdb->prefix . 'podsrel';
    $values  = [];

    foreach ( $created as $c ) {
      $s = $c['source'];

      if ( $s['flavor'] && $flavor_pod_id ) {
        $values[] = $wpdb->prepare( '(%d, %d, %d, %d, %d, %d)', $tub_pod_id, $field_flavor, $c['id'], $flavor_pod_id, $s['flavor'], 0 );
        if ( $flavor_tubs_field ) {
          $values[] = $wpdb->prepare( '(%d, %d, %d, %d, %d, %d)', $flavor_pod_id, $flavor_tubs_field, $s['flavor'], $tub_pod_id, $c['id'], 0 );
        }
      }

      if ( $field_location && $location_pod_id && $target_location_id ) {
        $values[] = $wpdb->prepare( '(%d, %d, %d, %d, %d, %d)', $tub_pod_id, $field_location, $c['id'], $location_pod_id, $target_location_id, 0 );
        if ( $location_tubs_field ) {
          $values[] = $wpdb->prepare( '(%d, %d, %d, %d, %d, %d)', $location_pod_id, $location_tubs_field, $target_location_id, $tub_pod_id, $c['id'], 0 );
        }
      }

      if ( $field_use && $use_pod_id && $s['use'] ) {
        $values[] = $wpdb->prepare( '(%d, %d, %d, %d, %d, %d)', $tub_pod_id, $field_use, $c['id'], $use_pod_id, $s['use'], 0 );
        if ( $use_tubs_field ) {
          $values[] = $wpdb->prepare( '(%d, %d, %d, %d, %d, %d)', $use_pod_id, $use_tubs_field, $s['use'], $tub_pod_id, $c['id'], 0 );
        }
      }
    }

    if ( $values ) {
      $sql = "INSERT INTO {$podsrel} (pod_id, field_id, item_id, related_pod_id, related_item_id, weight) VALUES " . implode( ',', $values );
      $wpdb->query( $sql );
    }
  }

  // Step 4: tag every created tub so Cleanup can find exactly these, and
  // nothing else, later — see scoop_sample_tubs_tagged_summary/_delete_ below.
  foreach ( $created as $c ) {
    update_post_meta( $c['id'], '_scoop_sample_tub', 1 );
    update_post_meta( $c['id'], '_scoop_sample_source_tub', (int) $c['source']['id'] );
    update_post_meta( $c['id'], '_scoop_sample_source_location', $source_location_id );
  }

  // Bump each distinct flavor's modified_date once (matches what real batch
  // creation does — see scoop_create_tubs_for_new_batch — new stock existing
  // should bump flavor freshness), rather than per-tub via the
  // pods_api_post_save_pod_item_tub hook, which isn't fired here at all:
  // this direct-write path bypasses Pods entirely (same as the batch path),
  // and the hook's only other listener (scoop_bump_flavor_modified_date_on_tub_save)
  // is what this replaces, done once per flavor instead of once per tub.
  $touchedFlavors = array_unique( array_column( array_column( $created, 'source' ), 'flavor' ) );
  foreach ( $touchedFlavors as $flavorId ) {
    if ( ! $flavorId ) continue;
    pods_api()->save_pod_item( [ 'pod' => 'flavor', 'id' => $flavorId, 'data' => [ 'modified_date' => current_time( 'mysql' ) ] ] );
  }

  wp_cache_flush();
  if ( function_exists( 'scoop_cache_bust' ) ) scoop_cache_bust();

  return array_column( $created, 'id' );
}

/** ------------------------------------------------------------
 *  Cleanup (direct bulk delete)
 * ------------------------------------------------------------ */

/** Tagged sample tubs grouped by their (real, live) location field — for the Cleanup section's per-location listing. */
function scoop_sample_tubs_tagged_summary(): array {
  global $wpdb;

  $tub_ids = $wpdb->get_col(
    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_scoop_sample_tub'"
  );
  if ( empty( $tub_ids ) ) return [];

  $pod = pods( 'tub' );
  $byLocation = [];
  foreach ( $tub_ids as $tid ) {
    $pod->fetch( (int) $tid );
    if ( ! $pod->exists() ) continue;
    $locId = scoop_rel_id( $pod->field( 'location' ) );
    $byLocation[ $locId ] = ( $byLocation[ $locId ] ?? 0 ) + 1;
  }

  $out = [];
  foreach ( $byLocation as $locId => $count ) {
    $out[] = [ 'location_id' => $locId, 'location_title' => get_the_title( $locId ) ?: "Location {$locId}", 'count' => $count ];
  }
  return $out;
}

/**
 * Deletes every tagged sample tub at $location_id (or every tagged sample
 * tub everywhere, if $location_id is 0) via direct bulk SQL — posts,
 * wp_pods_tub rows, every wp_podsrel row referencing them in either
 * direction, and their own postmeta (including this tool's own tags).
 */
function scoop_sample_tubs_delete( int $location_id = 0 ): int {
  global $wpdb;

  $tub_ids = $wpdb->get_col(
    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_scoop_sample_tub'"
  );
  if ( empty( $tub_ids ) ) return 0;

  if ( $location_id > 0 ) {
    $pod = pods( 'tub' );
    $tub_ids = array_values( array_filter( $tub_ids, function ( $tid ) use ( $pod, $location_id ) {
      $pod->fetch( (int) $tid );
      return $pod->exists() && scoop_rel_id( $pod->field( 'location' ) ) === $location_id;
    } ) );
  }
  if ( empty( $tub_ids ) ) return 0;

  $ids          = array_map( 'intval', $tub_ids );
  $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
  $tub_pod_id   = (int) ( pods_api()->load_pod( [ 'name' => 'tub' ] )['id'] ?? 0 );

  $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->posts} WHERE ID IN ({$placeholders})", $ids ) );
  $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}pods_tub WHERE id IN ({$placeholders})", $ids ) );
  $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$placeholders})", $ids ) );
  if ( $tub_pod_id ) {
    $wpdb->query( $wpdb->prepare(
      "DELETE FROM {$wpdb->prefix}podsrel WHERE (pod_id = %d AND item_id IN ({$placeholders})) OR (related_pod_id = %d AND related_item_id IN ({$placeholders}))",
      array_merge( [ $tub_pod_id ], $ids, [ $tub_pod_id ], $ids )
    ) );
  }

  wp_cache_flush();
  if ( function_exists( 'scoop_cache_bust' ) ) scoop_cache_bust();

  return count( $ids );
}

/** ------------------------------------------------------------
 *  Admin page
 * ------------------------------------------------------------ */

function scoop_render_sample_tubs_page(): void {
  if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

  $locations = scoop_sample_tubs_locations();

  $source_id    = isset( $_REQUEST['source_location'] ) ? (int) $_REQUEST['source_location'] : 935;
  $target_id    = isset( $_REQUEST['target_location'] ) ? (int) $_REQUEST['target_location'] : 0;
  $exclude_days = isset( $_REQUEST['exclude_days'] ) ? max( 0, (int) $_REQUEST['exclude_days'] ) : 7;
  $fraction_pct = isset( $_REQUEST['fraction_pct'] ) ? max( 1, min( 100, (int) $_REQUEST['fraction_pct'] ) ) : 50;

  $preview     = null;
  $create_result = null;
  $delete_result = null;

  if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['scoop_sample_tubs_action'] ) ) {
    $action = sanitize_key( $_POST['scoop_sample_tubs_action'] );

    if ( $action === 'preview' ) {
      check_admin_referer( 'scoop_sample_tubs_preview' );
      $basis   = scoop_sample_tubs_compute_basis( $source_id, $exclude_days );
      $sample  = scoop_sample_tubs_stratified_sample( $basis, $fraction_pct / 100 );
      $preview = [
        'basis_count' => count( $basis ),
        'sample'      => scoop_sample_tubs_summarize( $sample ),
      ];

    } elseif ( $action === 'generate' ) {
      check_admin_referer( 'scoop_sample_tubs_generate' );

      if ( empty( $_POST['scoop_sample_tubs_confirm'] ) || ! $target_id ) {
        echo '<div class="notice notice-error"><p>Pick a target location and tick the confirmation checkbox before generating.</p></div>';
      } else {
        $basis  = scoop_sample_tubs_compute_basis( $source_id, $exclude_days );
        $sample = scoop_sample_tubs_stratified_sample( $basis, $fraction_pct / 100 );
        $ids    = scoop_sample_tubs_create( $sample, $target_id, $source_id );
        $create_result = [ 'created' => count( $ids ) ];
      }
      $basis   = scoop_sample_tubs_compute_basis( $source_id, $exclude_days );
      $sample  = scoop_sample_tubs_stratified_sample( $basis, $fraction_pct / 100 );
      $preview = [ 'basis_count' => count( $basis ), 'sample' => scoop_sample_tubs_summarize( $sample ) ];

    } elseif ( $action === 'delete' ) {
      check_admin_referer( 'scoop_sample_tubs_delete' );
      if ( empty( $_POST['scoop_sample_tubs_delete_confirm'] ) ) {
        echo '<div class="notice notice-error"><p>Tick the confirmation checkbox before deleting.</p></div>';
      } else {
        $del_location = (int) ( $_POST['delete_location'] ?? 0 );
        $deleted = scoop_sample_tubs_delete( $del_location );
        $delete_result = [ 'deleted' => $deleted ];
      }
    }
  }

  $tagged = scoop_sample_tubs_tagged_summary();

  echo '<div class="wrap">';
  echo '<h1>Sample Tubs</h1>';
  echo '<p>Generates a synthetic tub population for a new location by sampling an existing location\'s real tubs — same flavor/use/state distribution, scaled down. Every tub this creates is tagged so it can be found and deleted again below, separately from real inventory.</p>';

  // ── Generate form ──────────────────────────────────────────────────────
  echo '<div class="scoop-rcc-card"><h2>Generate</h2><form method="post">';
  wp_nonce_field( 'scoop_sample_tubs_preview' );
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

  echo '<tr><th><label for="exclude_days">Exclude Emptied older than (days)</label></th><td><input type="number" min="0" name="exclude_days" id="exclude_days" value="' . esc_attr( $exclude_days ) . '"></td></tr>';
  echo '<tr><th><label for="fraction_pct">Sample size (% of source)</label></th><td><input type="number" min="1" max="100" name="fraction_pct" id="fraction_pct" value="' . esc_attr( $fraction_pct ) . '">%</td></tr>';
  echo '</tbody></table>';
  echo '<input type="hidden" name="scoop_sample_tubs_action" value="preview">';
  submit_button( 'Preview', 'secondary', 'submit', false );
  echo '</form>';

  if ( $preview !== null ) {
    $s = $preview['sample'];
    echo '<h3>Preview</h3>';
    echo '<p>Source basis: <strong>' . (int) $preview['basis_count'] . '</strong> tubs at the chosen location (after excluding old Emptied ones).<br>';
    echo 'Sample to create: <strong>' . (int) $s['total'] . '</strong> tubs across <strong>' . (int) $s['flavor_count'] . '</strong> flavors.</p>';

    echo '<p><strong>By state:</strong> ';
    $parts = [];
    foreach ( $s['by_state'] as $state => $count ) $parts[] = esc_html( $state ) . ': ' . (int) $count;
    echo implode( ', ', $parts );
    echo '</p>';

    echo '<p><strong>Top flavors:</strong> ';
    $parts = [];
    foreach ( $s['top_flavors'] as $f ) $parts[] = esc_html( $f['label'] ) . ' (' . (int) $f['count'] . ')';
    echo implode( ', ', $parts ) ?: '(none)';
    echo '</p>';

    if ( $create_result !== null ) {
      echo '<div class="notice notice-success"><p>Created <strong>' . (int) $create_result['created'] . '</strong> sample tubs.</p></div>';
    }

    echo '<form method="post" onsubmit="return confirm(\'Create ' . (int) $s['total'] . ' sample tubs at the chosen target location?\');">';
    wp_nonce_field( 'scoop_sample_tubs_generate' );
    echo '<input type="hidden" name="source_location" value="' . esc_attr( $source_id ) . '">';
    echo '<input type="hidden" name="target_location" value="' . esc_attr( $target_id ) . '">';
    echo '<input type="hidden" name="exclude_days" value="' . esc_attr( $exclude_days ) . '">';
    echo '<input type="hidden" name="fraction_pct" value="' . esc_attr( $fraction_pct ) . '">';
    echo '<input type="hidden" name="scoop_sample_tubs_action" value="generate">';
    echo '<p><label><input type="checkbox" name="scoop_sample_tubs_confirm" value="1" required> <strong>Create these tubs at the target location.</strong></label></p>';
    submit_button( 'Generate', 'primary', 'submit', false );
    echo '</form>';
  }
  echo '</div>';

  // ── Cleanup ─────────────────────────────────────────────────────────────
  echo '<div class="scoop-rcc-card"><h2>Cleanup</h2>';
  if ( $delete_result !== null ) {
    echo '<div class="notice notice-success"><p>Deleted <strong>' . (int) $delete_result['deleted'] . '</strong> sample tubs.</p></div>';
    $tagged = scoop_sample_tubs_tagged_summary();
  }

  if ( empty( $tagged ) ) {
    echo '<p>No tagged sample tubs exist right now.</p>';
  } else {
    echo '<table class="widefat striped"><thead><tr><th>Location</th><th>Sample tubs</th><th></th></tr></thead><tbody>';
    foreach ( $tagged as $row ) {
      echo '<tr><td>' . esc_html( $row['location_title'] ) . '</td><td>' . (int) $row['count'] . '</td><td>';
      echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Delete all ' . (int) $row['count'] . ' sample tubs at ' . esc_js( $row['location_title'] ) . '? This cannot be undone.\');">';
      wp_nonce_field( 'scoop_sample_tubs_delete' );
      echo '<input type="hidden" name="scoop_sample_tubs_action" value="delete">';
      echo '<input type="hidden" name="delete_location" value="' . (int) $row['location_id'] . '">';
      echo '<label><input type="checkbox" name="scoop_sample_tubs_delete_confirm" value="1" required> Confirm</label> ';
      submit_button( 'Delete', 'delete', 'submit', false );
      echo '</form>';
      echo '</td></tr>';
    }
    echo '</tbody></table>';
  }
  echo '</div>';

  echo '</div>';
}
