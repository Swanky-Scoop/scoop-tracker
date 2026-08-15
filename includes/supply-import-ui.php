<?php
/**
 * includes/supply-import-ui.php
 *
 * Admin page "Supply Import" under the Scoop menu. One-off, targeted data
 * migration: creates the real `supply` list (curated on local for the
 * shift-report feature) on whichever environment this page is loaded on.
 * Not a general-purpose tool like Schema Sync — this ships one hand-embedded
 * list (title + group) and only ever creates missing supply posts, never
 * updates or deletes an existing one.
 *
 * Report-first, per CLAUDE.md's data-repair policy: GET shows a dry-run list
 * of what's missing before any write; the actual creates only run on an
 * explicit, nonce-verified POST with a confirm() in front of it. Matches
 * includes/republish-tubs-ui.php's shape.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', 'scoop_register_supply_import_admin_page', 20 );

function scoop_register_supply_import_admin_page(): void {
  add_submenu_page(
    'scoop_root',
    'Supply Import',
    'Supply Import',
    'manage_options',
    'scoop_supply_import',
    'scoop_render_supply_import_page'
  );
}

/**
 * [title, group] pairs — exported from local 2026-08-15. `last_purchase`
 * isn't included: every local supply post has it unset (0000-00-00), so
 * there's nothing meaningful to carry over; Pods' own default applies.
 */
function scoop_supply_import_data(): array {
  return [
  ['Almond milk', 'Dairy/milk'],
  ['Bathroom fragrance/disinfectant spray', 'Cleaning & bathroom'],
  ['Bathroom trash bags', 'Bags & to-go'],
  ['Biscoff spread/sauce', 'Toppings & sauces'],
  ['Black trash bags (BOH)', 'Bags & to-go'],
  ['Boxes for cake orders/flights', 'Bags & to-go'],
  ['Caramel sauce (Torani)', 'Toppings & sauces'],
  ['Cherries (sundaes)', 'Toppings & sauces'],
  ['Chocolate sauce (Torani)', 'Toppings & sauces'],
  ['Chocolate sprinkles', 'Toppings & sauces'],
  ['Chopped nuts/peanuts', 'Toppings & sauces'],
  ['Coconut milk', 'Dairy/milk'],
  ['Cold brew (FOH)', 'Beverages'],
  ['Cone dip', 'Cones'],
  ['Cookies &amp; cream cookies', 'Cookies/frozen dough'],
  ['Coops hot fudge', 'Toppings & sauces'],
  ['Coops salted caramel', 'Toppings & sauces'],
  ['Dairy-free whipping cream', 'Dairy/milk'],
  ['Dark chocolate cookie dough', 'Cookies/frozen dough'],
  ['Decorated cones', 'Cones'],
  ['Dome lids (milkshakes)', 'Lids'],
  ['Double scoop cups', 'Cups'],
  ['Double scoop lids', 'Lids'],
  ['Drink cups (soda/floats)', 'Cups'],
  ['Dry erase markers', 'Misc/facilities'],
  ['Employee scoop cups', 'Cups'],
  ['Employee scoop lids', 'Lids'],
  ['Fantastik spray', 'Cleaning & bathroom'],
  ['Flat lids (milkshakes/floats)', 'Lids'],
  ['Freeze-dried candy', 'Toppings & sauces'],
  ['Full-size (dispenser) spoons', 'Spoons & straws'],
  ['GF/Vegan chocolate chip cookie dough', 'Cookies/frozen dough'],
  ['GF/Vegan cookies (sandwiches)', 'Cookies/frozen dough'],
  ['Gift cards', 'Misc/facilities'],
  ['Gluten-free cones', 'Cones'],
  ['Gummy bears/worms/sharks', 'Toppings & sauces'],
  ['Hand soap refills', 'Cleaning & bathroom'],
  ['Heavy whipping cream', 'Dairy/milk'],
  ['Hot fudge (regular)', 'Toppings & sauces'],
  ['Kid/child scoop cups', 'Cups'],
  ['Large gloves', 'Gloves'],
  ['Medium gloves', 'Gloves'],
  ['Milkshake cups', 'Cups'],
  ['Milkshake machine detergent', 'Cleaning & bathroom'],
  ['Mop cleaning solution', 'Cleaning & bathroom'],
  ['Mop head', 'Misc/facilities'],
  ['Napkins', 'Paper goods'],
  ['Notepads (order pads)', 'Misc/facilities'],
  ['Oat milk', 'Dairy/milk'],
  ['Orange Cream Soda', 'Beverages'],
  ['Oreo/M&amp;M cookies', 'Cookies/frozen dough'],
  ['Paper bags (need stamping)', 'Bags & to-go'],
  ['Paper towels (FOH)', 'Paper goods'],
  ['Paper towels (multifold)', 'Paper goods'],
  ['Parchment paper', 'Paper goods'],
  ['Pint cups', 'Cups'],
  ['Pint lids', 'Lids'],
  ['Rainbow sprinkles', 'Toppings & sauces'],
  ['Root beer', 'Beverages'],
  ['Salted chocolate chunk cookies', 'Cookies/frozen dough'],
  ['Sanitizer test strips/tabs', 'Cleaning & bathroom'],
  ['Simple Green (floor cleaner)', 'Cleaning & bathroom'],
  ['Single scoop cups', 'Cups'],
  ['Single scoop lids', 'Lids'],
  ['Sprinkle cookie dough', 'Cookies/frozen dough'],
  ['Straws', 'Spoons & straws'],
  ['Tampons', 'Cleaning & bathroom'],
  ['Tape (FOH)', 'Misc/facilities'],
  ['Tasting/sample spoons', 'Spoons & straws'],
  ['To-go/take-out containers', 'Bags & to-go'],
  ['Toilet bowl cleaner', 'Cleaning & bathroom'],
  ['Toilet paper', 'Paper goods'],
  ['Triple scoop cups', 'Cups'],
  ['Vegan brownies', 'Cookies/frozen dough'],
  ['Waffle cone mix/batter', 'Cones'],
  ['Waffle iron cleaning spray', 'Misc/facilities'],
  ['Water cups', 'Cups'],
  ['Waterproof bandaids', 'Misc/facilities'],
  ['Wax paper', 'Paper goods'],
  ['Whipped cream / whip cream chargers', 'Toppings & sauces'],
  ['Whole milk', 'Dairy/milk'],
  ['Windex', 'Cleaning & bathroom'],
  ['XL gloves', 'Gloves'],
  ];
}

/** [title, group] pairs not already present (by title) on this environment. */
function scoop_supply_import_missing(): array {
  global $wpdb;

  $existing = $wpdb->get_col(
    "SELECT post_title FROM {$wpdb->posts} WHERE post_type = 'supply' AND post_status NOT IN ('trash', 'auto-draft')"
  );
  $existing_set = array_flip( $existing );

  $missing = [];
  foreach ( scoop_supply_import_data() as [ $title, $group ] ) {
    if ( ! isset( $existing_set[ $title ] ) ) {
      $missing[] = [ $title, $group ];
    }
  }
  return $missing;
}

function scoop_supply_import_apply( array $missing ): array {
  $created = [];
  $errors = [];

  if ( ! function_exists( 'pods_api' ) || ! is_object( pods_api() ) ) {
    return [ 'created' => $created, 'errors' => [ 'Pods API is not available on this environment.' ] ];
  }

  foreach ( $missing as [ $title, $group ] ) {
    try {
      $id = pods_api()->save_pod_item( [
        'pod' => 'supply',
        'data' => [
          'post_title' => $title,
          'post_status' => 'publish',
          'group' => $group,
        ],
      ] );
    } catch ( \Throwable $e ) {
      $errors[] = "{$title}: " . $e->getMessage();
      continue;
    }

    if ( is_wp_error( $id ) || ! $id ) {
      $errors[] = "{$title}: " . ( is_wp_error( $id ) ? $id->get_error_message() : 'save_pod_item returned no id' );
      continue;
    }
    $created[] = $title;
  }

  return [ 'created' => $created, 'errors' => $errors ];
}

function scoop_render_supply_import_page(): void {
  if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( 'Unauthorized' );
  }

  $result = null;

  if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['scoop_supply_import_nonce'] ) ) {
    if ( ! wp_verify_nonce( $_POST['scoop_supply_import_nonce'], 'scoop_supply_import' ) ) {
      wp_die( 'Security check failed.' );
    }

    $missing = scoop_supply_import_missing();
    $result = scoop_supply_import_apply( $missing );

    if ( ! empty( $result['created'] ) && function_exists( 'scoop_cache_bust' ) ) {
      scoop_cache_bust();
    }
  }

  $missing = scoop_supply_import_missing();
  $missing_count = count( $missing );
  $nonce = wp_create_nonce( 'scoop_supply_import' );
  ?>
  <div class="wrap">
    <h1>Supply Import</h1>
    <p>
      Creates the real <code>supply</code> list (curated on local for the shift-report feature) on
      this environment. Title-matched against what's already here — only ever <strong>creates</strong>
      missing supply posts; existing ones are never touched, updated, or removed. Nothing here reaches
      another environment on its own — load this same page on each one in turn.
    </p>

    <?php if ( $result !== null ): ?>
      <div class="notice notice-<?php echo empty( $result['errors'] ) ? 'success' : 'error'; ?>">
        <p>
          Created <strong><?php echo count( $result['created'] ); ?></strong> supply post<?php echo count( $result['created'] ) === 1 ? '' : 's'; ?>.
          <?php if ( ! empty( $result['errors'] ) ): ?>
            <?php echo count( $result['errors'] ); ?> error(s):
          <?php endif; ?>
        </p>
        <?php if ( ! empty( $result['errors'] ) ): ?>
          <ul>
            <?php foreach ( $result['errors'] as $err ): ?>
              <li><?php echo esc_html( $err ); ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <p><strong><?php echo (int) $missing_count; ?></strong> supply item<?php echo $missing_count === 1 ? '' : 's'; ?> missing on this environment.</p>

    <?php if ( $missing_count > 0 ): ?>
      <ul style="columns:3;-webkit-columns:3;-moz-columns:3;max-width:1000px;">
        <?php foreach ( $missing as [ $title, $group ] ): ?>
          <li><?php echo esc_html( $title ); ?> <em>(<?php echo esc_html( $group ); ?>)</em></li>
        <?php endforeach; ?>
      </ul>
      <form method="post" onsubmit="return confirm('Create <?php echo (int) $missing_count; ?> supply posts on this environment?');">
        <input type="hidden" name="scoop_supply_import_nonce" value="<?php echo esc_attr( $nonce ); ?>">
        <button class="button button-primary" type="submit">Create <?php echo (int) $missing_count; ?> missing supply post<?php echo $missing_count === 1 ? '' : 's'; ?></button>
      </form>
    <?php else: ?>
      <p>Nothing to do — every item is already present.</p>
    <?php endif; ?>
  </div>
  <?php
}
