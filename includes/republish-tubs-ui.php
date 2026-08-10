<?php
/**
 * includes/republish-tubs-ui.php
 *
 * Admin page "Republish Tubs" under the Scoop menu. Data-repair utility
 * paired with the removal of the "demote tub to draft on Emptied" logic
 * from includes/hooks/tub-state.php (see change-tub.md / the CabinetWorkflow
 * QA conversation).
 *
 * Why this exists: most relationship ('pick') fields across this plugin's
 * whole Pods schema default to pick_post_status=publish (see
 * includes/pods-schema/_schema.php) — an explicit per-field setting, not a
 * hidden framework default. A tub demoted to draft silently drops out of
 * ANY other record's relationship to it, not just the app's own tub
 * queries (which already scope 'trash'/'auto-draft' only, not 'draft', so
 * tub data itself was never hidden — this is specifically about OTHER
 * entities' fields that point AT a tub). Confirmed concretely on
 * inventory_change.tubs: a batch-creation record referencing 23 tubs
 * resolved down to just 2 once most of them were emptied — see
 * performance.md #11 for the full investigation.
 *
 * Report-first, per CLAUDE.md's data-repair policy: GET shows a dry-run
 * count before any write; the actual bulk update only runs on an explicit,
 * nonce-verified POST with a confirm() in front of it.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', 'scoop_register_republish_tubs_admin_page', 20 );

function scoop_register_republish_tubs_admin_page(): void {
  add_submenu_page(
    'scoop_root',
    'Republish Tubs',
    'Republish Tubs',
    'manage_options',
    'scoop_republish_tubs',
    'scoop_render_republish_tubs_page'
  );
}

function scoop_republish_tubs_draft_count(): int {
  global $wpdb;
  return (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'tub' AND post_status = 'draft'"
  );
}

function scoop_render_republish_tubs_page(): void {
  if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( 'Unauthorized' );
  }

  $result = null;

  if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['scoop_republish_tubs_nonce'] ) ) {
    if ( ! wp_verify_nonce( $_POST['scoop_republish_tubs_nonce'], 'scoop_republish_tubs' ) ) {
      wp_die( 'Security check failed.' );
    }

    global $wpdb;

    // A single bulk SQL UPDATE, not a per-row wp_update_post() loop —
    // switched 2026-08-10 after a real run on ~2,090 rows timed out at the
    // web-server level (Apache's mod_fcgid gave up mid-request, "read
    // timeout from pipe") even though the loop itself completed
    // successfully server-side (confirmed after the fact: 0 drafts
    // remained). post_status is the ONLY column this write ever touches —
    // verified against wp_update_post() on a single real tub beforehand
    // (state/amount/emptied_at all confirmed unchanged, then reverted) —
    // so a raw UPDATE is exactly equivalent, just without per-row WP
    // object-lifecycle overhead. One query regardless of row count, no
    // timeout risk at any scale. Fires no hooks at all (bypasses
    // wp_update_post()/the Pods API entirely) — appropriate here, since
    // this is a bulk infrastructure correction, not a real per-tub edit;
    // scoop_enforce_tub_rules/scoop_bump_flavor_modified_date_on_tub_save
    // are bound to pods_api_pre/post_save_pod_item_tub either way, so
    // wouldn't have fired regardless.
    $updated = $wpdb->query(
      "UPDATE {$wpdb->posts} SET post_status = 'publish' WHERE post_type = 'tub' AND post_status = 'draft'"
    );

    if ( $updated === false ) {
      $result = [ 'updated' => 0, 'error' => $wpdb->last_error ];
    } else {
      if ( $updated > 0 ) {
        // A raw SQL write bypasses wp_update_post()'s clean_post_cache()
        // call, so WordPress's post object cache can still hold the old
        // post_status for these IDs — confirmed directly: get_post_status()
        // on a just-updated ID returned stale 'draft' later in the same
        // request. wp_cache_flush() is blunt (clears the whole object
        // cache, not just these posts) but this runs rarely, manually, and
        // correctness matters more than the cost here. A no-op cost-wise
        // under the default non-persistent object cache (fresh per
        // request anyway) — this only matters if a persistent cache
        // backend (Redis/Memcached) is ever added.
        wp_cache_flush();

        if ( function_exists( 'scoop_cache_bust' ) ) {
          scoop_cache_bust();
        }
      }
      $result = [ 'updated' => (int) $updated ];
    }
  }

  $draft_count = scoop_republish_tubs_draft_count();
  $nonce = wp_create_nonce( 'scoop_republish_tubs' );
  ?>
  <div class="wrap">
    <h1>Republish Tubs</h1>
    <p>
      Tubs used to be demoted to <code>draft</code> automatically when marked <code>Emptied</code>
      (<code>includes/hooks/tub-state.php</code>) — that logic has been removed. Most relationship
      fields in this plugin's Pods schema are configured <code>pick_post_status = publish</code>, so a
      <code>draft</code> tub silently drops out of any <em>other</em> record's relationship to it — for
      example, an <code>inventory_change</code> record listing every tub a batch created. This tool
      republishes every currently-draft tub so those relationships resolve correctly again.
      <code>state = 'Emptied'</code> already keeps a tub out of the way everywhere this app looks —
      only <code>post_status</code> changes here, nothing else about the tub (flavor, state, amount,
      timestamps) is touched.
    </p>

    <?php if ( $result !== null ): ?>
      <div class="notice notice-<?php echo isset( $result['error'] ) ? 'error' : 'success'; ?>">
        <p>
          <?php if ( isset( $result['error'] ) ): ?>
            Republish failed: <?php echo esc_html( $result['error'] ); ?>
          <?php else: ?>
            Republished <strong><?php echo (int) $result['updated']; ?></strong> draft tub<?php echo $result['updated'] === 1 ? '' : 's'; ?>.
          <?php endif; ?>
        </p>
      </div>
    <?php endif; ?>

    <p><strong><?php echo (int) $draft_count; ?></strong> tub<?php echo $draft_count === 1 ? '' : 's'; ?> currently <code>draft</code>.</p>

    <?php if ( $draft_count > 0 ): ?>
      <form method="post" onsubmit="return confirm('Republish all <?php echo (int) $draft_count; ?> draft tubs? This changes post_status only — flavor, state, amount, and timestamps are untouched.');">
        <input type="hidden" name="scoop_republish_tubs_nonce" value="<?php echo esc_attr( $nonce ); ?>">
        <button class="button button-primary" type="submit">Republish all <?php echo (int) $draft_count; ?> draft tubs</button>
      </form>
    <?php else: ?>
      <p>Nothing to do.</p>
    <?php endif; ?>
  </div>
  <?php
}
