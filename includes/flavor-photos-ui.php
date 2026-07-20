<?php
if (!defined('ABSPATH')) exit;

/**
 * Flavor Photos admin page (Scoop → Flavor Photos).
 *
 * Single-page flow: scan (dry-run) → confirm → apply → results. Re-runnable
 * any time (e.g. after new flavors/photos are added) — flavors whose photo
 * already matches the file on disk are skipped automatically.
 */
function scoop_render_flavor_photos_page() {

  if (!current_user_can('manage_options')) wp_die('Unauthorized');

  $scan = null;
  $apply_result = null;

  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['scoop_fp_action'])) {
    $action = sanitize_key($_POST['scoop_fp_action']);

    if ($action === 'scan') {
      check_admin_referer('scoop_fp_scan');
      $scan = scoop_flavor_photos_scan();
    } elseif ($action === 'apply') {
      check_admin_referer('scoop_fp_apply');
      if (empty($_POST['scoop_fp_confirm'])) {
        echo '<div class="notice notice-error"><p>Please tick the confirmation checkbox before applying.</p></div>';
        $scan = scoop_flavor_photos_scan();
      } else {
        @set_time_limit(0);
        ignore_user_abort(true);
        $scan = scoop_flavor_photos_scan();
        $apply_result = scoop_flavor_photos_apply($scan['rows']);
      }
    }
  }

  echo '<div class="wrap">';
  echo '<h1>Flavor Photos</h1>';

  if ($apply_result !== null) {
    scoop_fp_render_apply_results($apply_result);
  } elseif ($scan !== null) {
    scoop_fp_render_scan_results($scan);
  } else {
    scoop_fp_render_intro();
  }

  scoop_rcc_render_styles();

  echo '</div>';
}

function scoop_fp_render_intro(): void {
  $dir = scoop_flavor_photos_dir();
  ?>
  <div class="scoop-rcc-card">
    <h2>What this does</h2>
    <p>
      Scans every <code>flavor</code> post's title, looks for a matching file in
      <code><?php echo esc_html($dir); ?></code> (sanitized title + <code>.webp</code>,
      e.g. <em>"Strawberry Rhubarb Streusel (PEA)"</em> → <code>Strawberry_Rhubarb_Streusel_PEA.webp</code>),
      and writes it into the flavor's <code>photo</code> field.
    </p>
    <p>
      Safe to re-run: a flavor is only touched if its current photo differs
      (by content hash) from the file on disk, or has no photo set yet.
      Old attachments are not deleted on overwrite — they're just unlinked from
      the flavor, so nothing is destroyed.
    </p>
  </div>

  <form method="post" class="scoop-rcc-card">
    <?php wp_nonce_field('scoop_fp_scan'); ?>
    <input type="hidden" name="scoop_fp_action" value="scan">
    <h2>Scan</h2>
    <?php submit_button('Scan flavors for matching photos', 'primary', 'submit', false); ?>
  </form>
  <?php
}

function scoop_fp_render_scan_results(array $scan): void {

  if (!$scan['ok']) {
    echo '<div class="notice notice-error inline"><p>' . esc_html($scan['error']) . '</p></div>';
    echo '<p><a href="' . esc_url(admin_url('admin.php?page=scoop_flavor_photos')) . '" class="button">Back to start</a></p>';
    return;
  }

  $counts = $scan['counts'];
  $will_set = $counts['will_set'] ?? 0;
  $will_overwrite = $counts['will_overwrite'] ?? 0;
  $skip_identical = $counts['skip_identical'] ?? 0;
  $no_image = $counts['no_image_found'] ?? 0;
  $total_actionable = $will_set + $will_overwrite;

  ?>
  <div class="scoop-rcc-card">
    <h2>Scan results</h2>
    <p>
      <strong><?php echo (int) $will_set; ?></strong> new ·
      <strong><?php echo (int) $will_overwrite; ?></strong> will overwrite (different from current) ·
      <strong><?php echo (int) $skip_identical; ?></strong> already correct (skipped) ·
      <strong><?php echo (int) $no_image; ?></strong> no matching file
    </p>
  </div>

  <?php if ($total_actionable > 0): ?>
    <div class="scoop-rcc-card">
      <h3>Will be written (<?php echo (int) $total_actionable; ?>)</h3>
      <?php scoop_fp_render_rows_table(array_filter($scan['rows'], function ($r) {
        return $r['action'] === 'will_set' || $r['action'] === 'will_overwrite';
      })); ?>
    </div>
  <?php endif; ?>

  <?php if ($no_image > 0): ?>
    <div class="scoop-rcc-card">
      <h3>No matching file (<?php echo (int) $no_image; ?>)</h3>
      <?php scoop_fp_render_rows_table(array_filter($scan['rows'], function ($r) {
        return $r['action'] === 'no_image_found';
      })); ?>
    </div>
  <?php endif; ?>

  <?php if ($total_actionable > 0): ?>
    <form method="post" class="scoop-rcc-card">
      <?php wp_nonce_field('scoop_fp_apply'); ?>
      <input type="hidden" name="scoop_fp_action" value="apply">
      <h2>Confirm and apply</h2>
      <p>
        <label>
          <input type="checkbox" name="scoop_fp_confirm" value="1" required>
          <strong>Write photos for <?php echo (int) $total_actionable; ?> flavor<?php echo $total_actionable === 1 ? '' : 's'; ?>
          (<?php echo (int) $will_overwrite; ?> of which will overwrite an existing photo).</strong>
        </label>
      </p>
      <?php submit_button('Apply', 'primary', 'submit', false); ?>
    </form>
  <?php endif; ?>

  <p>
    <a href="<?php echo esc_url(admin_url('admin.php?page=scoop_flavor_photos')); ?>" class="button">Back to start</a>
  </p>
  <?php
}

function scoop_fp_render_rows_table(array $rows): void {

  $shown_limit = 200;
  $total = count($rows);
  $rows = array_slice($rows, 0, $shown_limit);

  if (empty($rows)) {
    echo '<p>None.</p>';
    return;
  }
  ?>
  <p class="description">
    Showing <?php echo (int) count($rows); ?> of <?php echo (int) $total; ?>.
  </p>
  <table class="widefat striped">
    <thead>
      <tr><th>Flavor</th><th>Expected filename</th><th>Current photo</th><th>Action</th></tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td><code>#<?php echo (int) $row['id']; ?></code> <?php echo esc_html($row['title']); ?></td>
          <td><code><?php echo esc_html($row['expected_filename']); ?></code></td>
          <td><?php echo $row['current_attachment_id'] > 0 ? '#' . (int) $row['current_attachment_id'] : '<em>none</em>'; ?></td>
          <td>
            <?php
              $labels = [
                'will_set' => '<span class="scoop-rcc-pill scoop-rcc-pill-new">set</span>',
                'will_overwrite' => '<span class="scoop-rcc-pill scoop-rcc-pill-warn">overwrite</span>',
                'no_image_found' => '<span class="scoop-rcc-pill scoop-rcc-pill-warn">no file</span>',
                'skip_identical' => '<span class="scoop-rcc-pill">skip</span>',
              ];
              echo $labels[$row['action']] ?? esc_html($row['action']);
            ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php
}

function scoop_fp_render_apply_results(array $r): void {
  ?>
  <div class="notice notice-success inline">
    <p>
      <strong>Apply complete.</strong><br>
      Set: <?php echo (int) $r['set_count']; ?> ·
      Overwritten: <?php echo (int) $r['overwrite_count']; ?> ·
      Errors: <?php echo (int) count($r['errors']); ?>
    </p>
  </div>

  <?php if (!empty($r['log'])): ?>
    <div class="scoop-rcc-card">
      <h2>Written (<?php echo (int) count($r['log']); ?>)</h2>
      <table class="widefat striped">
        <thead>
          <tr><th>Flavor</th><th>Filename</th><th>Old attachment</th><th>New attachment</th></tr>
        </thead>
        <tbody>
          <?php foreach ($r['log'] as $entry): ?>
            <tr>
              <td><code>#<?php echo (int) $entry['id']; ?></code> <?php echo esc_html($entry['title']); ?></td>
              <td><code><?php echo esc_html($entry['filename']); ?></code></td>
              <td><?php echo $entry['old_attachment_id'] > 0 ? '#' . (int) $entry['old_attachment_id'] : '<em>none</em>'; ?></td>
              <td>#<?php echo (int) $entry['new_attachment_id']; ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <?php if (!empty($r['errors'])): ?>
    <div class="scoop-rcc-card">
      <h2>Errors (<?php echo (int) count($r['errors']); ?>)</h2>
      <ul class="scoop-rcc-errors">
        <?php foreach ($r['errors'] as $err): ?>
          <li><?php echo esc_html($err); ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <p>
    <a href="<?php echo esc_url(admin_url('admin.php?page=scoop_flavor_photos')); ?>" class="button">Back to start</a>
  </p>
  <?php
}
