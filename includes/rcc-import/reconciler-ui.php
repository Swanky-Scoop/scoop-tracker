<?php
if (!defined('ABSPATH')) exit;

/**
 * Reconciler admin page (Scoop → Reconcile Relations).
 *
 * Single-page flow: confirm → run → results-with-log. Run is synchronous;
 * for ~370 recipes it generally completes inside the PHP time limit, but
 * we bump it just in case.
 */
function scoop_render_reconciler_page() {

  if (!current_user_can('manage_options')) wp_die('Unauthorized');

  $results = null;
  $dairy_allergy_state = null;

  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['scoop_rcc_action'])) {
    $action = sanitize_key($_POST['scoop_rcc_action']);

    // Reconciler flow.
    if ($action === 'reconcile_run') {
      check_admin_referer('scoop_rcc_reconcile_run');
      if (empty($_POST['scoop_rcc_confirm'])) {
        echo '<div class="notice notice-error"><p>Please tick the confirmation checkbox before running.</p></div>';
      } else {
        @set_time_limit(0);
        ignore_user_abort(true);
        $results = scoop_rcc_run_reconciler();

        // Persist the log CSV so it's downloadable from the results screen.
        $log_path = '';
        if (!empty($results['created_log'])) {
          $log_path = scoop_rcc_write_reconciler_log_csv($results['created_log']);
        }
        $results['log_path'] = $log_path;
      }
    }
    // Dairy allergy flow.
    elseif ($action === 'dairy_allergy_preview') {
      check_admin_referer('scoop_rcc_dairy_allergy_preview');
      $dairy_allergy_state = scoop_dairy_allergy_scan();
    }
    elseif ($action === 'dairy_allergy_apply') {
      check_admin_referer('scoop_rcc_dairy_allergy_apply');
      if (empty($_POST['scoop_rcc_confirm'])) {
        echo '<div class="notice notice-error"><p>Please tick the confirmation checkbox before running.</p></div>';
      } else {
        @set_time_limit(0);
        ignore_user_abort(true);
        $scan_result = isset($_POST['scoop_rcc_dairy_allergy_scan'])
          ? maybe_unserialize(wp_unslash($_POST['scoop_rcc_dairy_allergy_scan']))
          : scoop_dairy_allergy_scan();
        $remove_from_sorbet = !empty($_POST['scoop_rcc_dairy_remove_sorbet']);
        $apply_result = scoop_dairy_allergy_apply($scan_result, $remove_from_sorbet);
        $dairy_allergy_state = array_merge($scan_result, [
          'applied' => true,
          'removed_from_sorbet' => $remove_from_sorbet,
          'added_count' => $apply_result['added_count'],
          'removed_count' => $apply_result['removed_count'],
          'errors' => $apply_result['errors'],
        ]);
      }
    }
  }

  echo '<div class="wrap">';
  echo '<h1>Reconcile Ingredient Relations</h1>';

  if ($dairy_allergy_state !== null) {
    if (!empty($dairy_allergy_state['applied'])) {
      scoop_rcc_render_dairy_allergy_results($dairy_allergy_state);
    } else {
      scoop_rcc_render_dairy_allergy_preview($dairy_allergy_state);
    }
  } elseif ($results !== null) {
    scoop_rcc_render_reconciler_results($results);
  } else {
    scoop_rcc_render_reconciler_intro();
  }

  scoop_rcc_render_styles();

  echo '</div>';
}

function scoop_rcc_render_reconciler_intro(): void {

  $field_check = scoop_rcc_check_ingredients_field();
  ?>
  <div class="scoop-rcc-card">
    <h2>Pre-flight field check</h2>
    <table class="widefat">
      <thead>
        <tr><th>Pod</th><th>Field exists?</th><th>Type</th><th>Related to</th><th>Issue</th></tr>
      </thead>
      <tbody>
        <?php foreach ($field_check['details'] as $pod => $info):
          $exists = !empty($info['exists']);
          $err    = $info['error'] ?? '';
          $type   = $info['type'] ?? '';
          $rel    = '';
          if (!empty($info['pick_object'])) {
            $rel = $info['pick_object'];
            if (!empty($info['pick_val'])) $rel .= ': ' . $info['pick_val'];
          }
        ?>
          <tr>
            <td><code><?php echo esc_html($pod); ?></code></td>
            <td><?php echo $exists
                  ? '<span class="scoop-rcc-pill scoop-rcc-pill-new">yes</span>'
                  : '<span class="scoop-rcc-pill scoop-rcc-pill-warn">no</span>'; ?></td>
            <td><?php echo esc_html($type); ?></td>
            <td><?php echo esc_html($rel); ?></td>
            <td><?php echo $err ? esc_html($err) : '—'; ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (!$field_check['ok']): ?>
      <p>
        <span class="scoop-rcc-pill scoop-rcc-pill-warn">blocked</span>
        Reconciler will refuse to run until both pods have an <code>ingredients</code>
        field of type <strong>Relationship</strong> (Pods <code>type=pick</code>).
      </p>
    <?php endif; ?>
  </div>

  <div class="scoop-rcc-card">
    <h2>What this does</h2>
    <p>
      Walks every recipe's <code>ingredient_list_str</code>, parses the nested
      structure that RCC writes (parens, single-quoted label declarations),
      and writes the resulting parent → child relationships into each pod's
      <code>ingredients</code> field.
    </p>
    <p>
      Tokens that don't match an existing pod row are <strong>auto-created
      as atomic ingredient stubs</strong>. Every creation is logged with its
      provenance (which recipe first referenced it, whether the parser saw
      it as atomic or compound). The log is downloadable as CSV at the end
      of the run.
    </p>
  </div>

  <div class="scoop-rcc-card">
    <h2>Required pod fields</h2>
    <p>Configure these via <em>Pods Admin → Edit Pod</em> before running:</p>
    <table class="widefat">
      <thead>
        <tr><th>Pod</th><th>Field name</th><th>Type</th><th>Related to</th></tr>
      </thead>
      <tbody>
        <tr><td><code>recipe</code></td><td><code>ingredients</code></td><td>Relationship (multi)</td><td><code>recipe</code> + <code>ingredient</code></td></tr>
        <tr><td><code>ingredient</code></td><td><code>ingredients</code></td><td>Relationship (multi)</td><td><code>ingredient</code></td></tr>
      </tbody>
    </table>
    <p class="description">
      Enable bi-directional on both so the reverse edge (<em>"which recipes use cocoa powder?"</em>)
      is queryable without extra work.
    </p>
  </div>

  <form method="post" class="scoop-rcc-card">
    <?php wp_nonce_field('scoop_rcc_reconcile_run'); ?>
    <input type="hidden" name="scoop_rcc_action" value="reconcile_run">
    <h2>Run</h2>
    <p>
      <label>
        <input type="checkbox" name="scoop_rcc_confirm" value="1" required>
        <strong>I understand this writes to all recipes and creates new ingredient pod entries for unmatched names. This is test data.</strong>
      </label>
    </p>
    <?php submit_button('Run reconciler on all recipes', 'primary', 'submit', false); ?>
  </form>

  <form method="post" class="scoop-rcc-card">
    <?php wp_nonce_field('scoop_rcc_dairy_allergy_preview'); ?>
    <input type="hidden" name="scoop_rcc_action" value="dairy_allergy_preview">
    <h2>Apply Dairy Allergen</h2>
    <p>
      Find all flavors <strong>not ending with ")" or "Sorbet"</strong> (case-insensitive) and add the dairy allergen (ID 1204).
      Optionally, remove the dairy allergen from flavors that do match these patterns.
    </p>
    <?php submit_button('Preview dairy allergen changes', 'secondary', 'submit', false); ?>
  </form>
  <?php
}

function scoop_rcc_render_reconciler_results(array $r): void {

  $errors   = $r['errors'];
  $log      = $r['created_log'];
  $compound = count(array_filter($log, function ($e) { return $e['compound'] === 'compound'; }));
  $atomic   = count($log) - $compound;

  if (!empty($r['aborted'])):
    ?>
    <div class="notice notice-error inline">
      <p>
        <strong>Reconciler aborted before processing any recipes.</strong>
        The pre-flight check found problems with the <code>ingredients</code> Pods field —
        see the table on the previous screen for details. Fix the field config in Pods admin and try again.
      </p>
    </div>

    <?php if (!empty($errors)): ?>
      <div class="scoop-rcc-card">
        <h3>Pre-flight errors</h3>
        <ul class="scoop-rcc-errors">
          <?php foreach ($errors as $err): ?>
            <li><?php echo esc_html($err); ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <p><a href="<?php echo esc_url(admin_url('admin.php?page=scoop_reconcile')); ?>" class="button">Back to start</a></p>
    <?php
    return;
  endif;
  ?>
  <div class="notice notice-success inline">
    <p>
      <strong>Reconciler complete.</strong><br>
      Recipes scanned: <?php echo (int) $r['recipes_seen']; ?> ·
      With ingredient data: <?php echo (int) $r['recipes_with_data']; ?> ·
      Components writes: <?php echo (int) $r['relations_written']; ?> ·
      New ingredients created: <?php echo (int) $r['ingredients_created']; ?>
      (<?php echo (int) $atomic; ?> atomic, <?php echo (int) $compound; ?> compound) ·
      Errors: <?php echo (int) count($errors); ?>
    </p>
  </div>

  <?php if (!empty($r['log_path'])):
    $upload    = wp_upload_dir();
    $rel       = ltrim(str_replace($upload['basedir'], '', $r['log_path']), '/\\');
    $url       = trailingslashit($upload['baseurl']) . $rel;
  ?>
    <div class="scoop-rcc-card">
      <h2>Creation log</h2>
      <p>
        Saved to <code><?php echo esc_html(basename($r['log_path'])); ?></code>.
        <a href="<?php echo esc_url($url); ?>" class="button button-secondary">Download CSV</a>
      </p>
      <?php scoop_rcc_render_created_log_table($log); ?>
    </div>
  <?php elseif (!empty($log)): ?>
    <div class="scoop-rcc-card">
      <h2>Creation log</h2>
      <p class="description">(CSV write failed — table view only.)</p>
      <?php scoop_rcc_render_created_log_table($log); ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($errors)): ?>
    <div class="scoop-rcc-card">
      <h2>Errors (<?php echo (int) count($errors); ?>)</h2>
      <ul class="scoop-rcc-errors">
        <?php foreach ($errors as $err): ?>
          <li><?php echo esc_html($err); ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <p>
    <a href="<?php echo esc_url(admin_url('admin.php?page=scoop_reconcile')); ?>" class="button">Back to start</a>
  </p>
  <?php
}

function scoop_rcc_render_created_log_table(array $log): void {

  if (empty($log)) {
    echo '<p>No new ingredients created.</p>';
    return;
  }

  $shown_limit = 200;
  $total = count($log);
  $rows  = array_slice($log, 0, $shown_limit);

  ?>
  <p class="description">
    Showing <?php echo (int) count($rows); ?> of <?php echo (int) $total; ?>
    new ingredient<?php echo $total === 1 ? '' : 's'; ?>.
    Full list in the CSV.
  </p>
  <table class="widefat striped">
    <thead>
      <tr>
        <th>New ID</th>
        <th>Name</th>
        <th>Kind</th>
        <th>First seen in</th>
        <th>Raw token</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td><code>#<?php echo (int) $row['id']; ?></code></td>
          <td><?php echo esc_html($row['name']); ?></td>
          <td>
            <?php if ($row['compound'] === 'compound'): ?>
              <span class="scoop-rcc-pill scoop-rcc-pill-warn">compound</span>
            <?php else: ?>
              <span class="scoop-rcc-pill scoop-rcc-pill-new">atomic</span>
            <?php endif; ?>
          </td>
          <td>
            <?php echo esc_html($row['source_type']); ?> #<?php echo (int) $row['source_id']; ?>
            — <em><?php echo esc_html($row['source_name']); ?></em>
          </td>
          <td><code class="scoop-rcc-token"><?php echo esc_html($row['raw_token']); ?></code></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php
}

function scoop_rcc_render_dairy_allergy_preview(array $scan_result): void {

  if (!$scan_result['ok']) {
    echo '<div class="notice notice-error inline"><p>';
    echo 'Scan failed: ' . esc_html($scan_result['error']);
    echo '</p></div>';
    echo '<p><a href="' . esc_url(admin_url('admin.php?page=scoop_reconcile')) . '" class="button">Back to start</a></p>';
    return;
  }

  $to_add = $scan_result['to_add'] ?? [];
  $to_remove = $scan_result['to_remove'] ?? [];
  $total_to_add = count($to_add);
  $total_to_remove = count($to_remove);

  ?>
  <div class="scoop-rcc-card">
    <h2>Preview: Dairy Allergen Changes</h2>
    <p>
      <strong><?php echo (int) $total_to_add; ?> flavor<?php echo $total_to_add === 1 ? '' : 's'; ?></strong> will have dairy allergen added
      (do not end with ")" or "Sorbet").
      <?php if ($total_to_remove > 0): ?>
        <strong><?php echo (int) $total_to_remove; ?> flavor<?php echo $total_to_remove === 1 ? '' : 's'; ?></strong>
        can optionally have dairy allergen removed (end with ")" or "Sorbet").
      <?php endif; ?>
    </p>
  </div>

  <?php if ($total_to_add > 0): ?>
    <div class="scoop-rcc-card">
      <h3>Flavors to add dairy allergen</h3>
      <?php scoop_rcc_render_dairy_allergy_flavor_table($to_add); ?>
    </div>
  <?php endif; ?>

  <?php if ($total_to_remove > 0): ?>
    <div class="scoop-rcc-card">
      <h3>Flavors with dairy allergen (that could be removed)</h3>
      <?php scoop_rcc_render_dairy_allergy_flavor_table($to_remove); ?>
    </div>
  <?php endif; ?>

  <form method="post" class="scoop-rcc-card">
    <?php wp_nonce_field('scoop_rcc_dairy_allergy_apply'); ?>
    <input type="hidden" name="scoop_rcc_action" value="dairy_allergy_apply">
    <input type="hidden" name="scoop_rcc_dairy_allergy_scan" value="<?php echo esc_attr(wp_json_encode($scan_result)); ?>">

    <h2>Confirm and apply</h2>
    <p>
      <label>
        <input type="checkbox" name="scoop_rcc_confirm" value="1" required>
        <strong>Yes, apply dairy allergen to <?php echo (int) $total_to_add; ?> flavor<?php echo $total_to_add === 1 ? '' : 's'; ?>.</strong>
      </label>
    </p>

    <?php if ($total_to_remove > 0): ?>
      <p>
        <label>
          <input type="checkbox" name="scoop_rcc_dairy_remove_sorbet" value="1">
          Also remove dairy allergen from <?php echo (int) $total_to_remove; ?> flavor<?php echo $total_to_remove === 1 ? '' : 's'; ?>
          (ending with ")" or "Sorbet").
        </label>
      </p>
    <?php endif; ?>

    <?php submit_button('Apply changes', 'primary', 'submit', false); ?>
  </form>

  <p>
    <a href="<?php echo esc_url(admin_url('admin.php?page=scoop_reconcile')); ?>" class="button">Back to start</a>
  </p>
  <?php
}

function scoop_rcc_render_dairy_allergy_flavor_table(array $flavors): void {

  if (empty($flavors)) {
    echo '<p>No flavors.</p>';
    return;
  }

  $shown_limit = 100;
  $total = count($flavors);
  $rows = array_slice($flavors, 0, $shown_limit);

  ?>
  <p class="description">
    Showing <?php echo (int) count($rows); ?> of <?php echo (int) $total; ?> flavor<?php echo $total === 1 ? '' : 's'; ?>.
  </p>
  <table class="widefat striped">
    <thead>
      <tr>
        <th>Flavor ID</th>
        <th>Title</th>
        <th>Current Allergens</th>
        <th>Reason</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $flavor): ?>
        <tr>
          <td><code>#<?php echo (int) $flavor['id']; ?></code></td>
          <td><?php echo esc_html($flavor['title']); ?></td>
          <td>
            <?php
              if (empty($flavor['current_allergens'])) {
                echo '<em>None</em>';
              } else {
                echo implode(', ', array_map(function ($id) {
                  return '<code>#' . (int) $id . '</code>';
                }, $flavor['current_allergens']));
              }
            ?>
          </td>
          <td><?php echo esc_html($flavor['reason']); ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php
}

function scoop_rcc_render_dairy_allergy_results(array $state): void {

  $added = (int) ($state['added_count'] ?? 0);
  $removed = (int) ($state['removed_count'] ?? 0);
  $errors = $state['errors'] ?? [];

  ?>
  <div class="notice notice-success inline">
    <p>
      <strong>Dairy allergen update complete.</strong><br>
      Flavors updated with dairy: <?php echo $added; ?> ·
      Flavors updated to remove dairy: <?php echo $removed; ?> ·
      Errors: <?php echo (int) count($errors); ?>
    </p>
  </div>

  <?php if (!empty($errors)): ?>
    <div class="scoop-rcc-card">
      <h2>Errors (<?php echo (int) count($errors); ?>)</h2>
      <ul class="scoop-rcc-errors">
        <?php foreach ($errors as $err): ?>
          <li><?php echo esc_html($err); ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <p>
    <a href="<?php echo esc_url(admin_url('admin.php?page=scoop_reconcile')); ?>" class="button">Back to start</a>
  </p>
  <?php
}
