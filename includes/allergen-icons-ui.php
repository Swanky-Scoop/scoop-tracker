<?php
if (!defined('ABSPATH')) exit;

/**
 * Allergen Icons admin page (Scoop → Allergen Icons).
 *
 * Single-page flow: scan (dry-run) → confirm → apply → results. Re-runnable
 * any time. Matching is fuzzy on '-'/'_'/space and case, but NOT on
 * spelling — a mistyped filename shows up as both an unmatched allergen
 * and an orphan file, rather than silently failing to match.
 */
function scoop_render_allergen_icons_page() {

  if (!current_user_can('manage_options')) wp_die('Unauthorized');

  $scan = null;
  $apply_result = null;

  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['scoop_ai_action'])) {
    $action = sanitize_key($_POST['scoop_ai_action']);

    if ($action === 'scan') {
      check_admin_referer('scoop_ai_scan');
      $scan = scoop_allergen_icons_scan();
    } elseif ($action === 'apply') {
      check_admin_referer('scoop_ai_apply');
      if (empty($_POST['scoop_ai_confirm'])) {
        echo '<div class="notice notice-error"><p>Please tick the confirmation checkbox before applying.</p></div>';
        $scan = scoop_allergen_icons_scan();
      } else {
        @set_time_limit(0);
        ignore_user_abort(true);
        $scan = scoop_allergen_icons_scan();
        $apply_result = scoop_allergen_icons_apply($scan['rows']);
      }
    }
  }

  echo '<div class="wrap">';
  echo '<h1>Allergen Icons</h1>';

  if ($apply_result !== null) {
    scoop_ai_render_apply_results($apply_result);
  } elseif ($scan !== null) {
    scoop_ai_render_scan_results($scan);
  } else {
    scoop_ai_render_intro();
  }

  scoop_rcc_render_styles();

  echo '</div>';
}

function scoop_ai_render_intro(): void {
  $dir = scoop_allergen_icons_dir();
  ?>
  <div class="scoop-rcc-card">
    <h2>What this does</h2>
    <p>
      Scans every <code>allergen</code> post's title, fuzzy-matches it against
      files in <code><?php echo esc_html($dir); ?></code>, and writes the match
      into the allergen's <code>icon</code> field.
    </p>
    <p>
      Matching is case-insensitive and treats <code>-</code>, <code>_</code>, and spaces
      as interchangeable — so <em>"Tree nuts"</em> matches <code>tree-nuts.svg</code>,
      <code>Tree_nuts.svg</code>, or <code>tree nuts.svg</code> equally. It does
      <strong>not</strong> correct spelling — a typo'd filename won't match and will
      show up as both an unmatched allergen and an orphan file below.
    </p>
    <p>
      Safe to re-run: an allergen is only touched if its current icon differs
      (by content hash) from the file on disk, or has no icon set yet. Old
      attachments are not deleted on overwrite.
    </p>
  </div>

  <form method="post" class="scoop-rcc-card">
    <?php wp_nonce_field('scoop_ai_scan'); ?>
    <input type="hidden" name="scoop_ai_action" value="scan">
    <h2>Scan</h2>
    <?php submit_button('Scan allergens for matching icons', 'primary', 'submit', false); ?>
  </form>
  <?php
}

function scoop_ai_render_scan_results(array $scan): void {

  if (!$scan['ok']) {
    echo '<div class="notice notice-error inline"><p>' . esc_html($scan['error']) . '</p></div>';
    echo '<p><a href="' . esc_url(admin_url('admin.php?page=scoop_allergen_icons')) . '" class="button">Back to start</a></p>';
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

  <?php if (!empty($scan['ambiguous'])): ?>
    <div class="scoop-rcc-card">
      <h3>Ambiguous filenames (skipped — need a rename)</h3>
      <p class="description">Two or more files normalized to the same key, so none of them were auto-matched.</p>
      <ul class="scoop-rcc-errors">
        <?php foreach ($scan['ambiguous'] as $key => $paths): ?>
          <li><code><?php echo esc_html($key); ?></code>: <?php echo esc_html(implode(', ', array_map('basename', $paths))); ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if ($total_actionable > 0): ?>
    <div class="scoop-rcc-card">
      <h3>Will be written (<?php echo (int) $total_actionable; ?>)</h3>
      <?php scoop_ai_render_rows_table(array_filter($scan['rows'], function ($r) {
        return $r['action'] === 'will_set' || $r['action'] === 'will_overwrite';
      })); ?>
    </div>
  <?php endif; ?>

  <?php if ($no_image > 0): ?>
    <div class="scoop-rcc-card">
      <h3>No matching file (<?php echo (int) $no_image; ?>)</h3>
      <?php scoop_ai_render_rows_table(array_filter($scan['rows'], function ($r) {
        return $r['action'] === 'no_image_found';
      })); ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($scan['orphan_files'])): ?>
    <div class="scoop-rcc-card">
      <h3>Orphan files (<?php echo count($scan['orphan_files']); ?>) — no allergen title matched</h3>
      <p class="description">Likely a typo or an allergen that doesn't exist yet.</p>
      <ul>
        <?php foreach ($scan['orphan_files'] as $f): ?>
          <li><code><?php echo esc_html($f); ?></code></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if ($total_actionable > 0): ?>
    <form method="post" class="scoop-rcc-card">
      <?php wp_nonce_field('scoop_ai_apply'); ?>
      <input type="hidden" name="scoop_ai_action" value="apply">
      <h2>Confirm and apply</h2>
      <p>
        <label>
          <input type="checkbox" name="scoop_ai_confirm" value="1" required>
          <strong>Write icons for <?php echo (int) $total_actionable; ?> allergen<?php echo $total_actionable === 1 ? '' : 's'; ?>
          (<?php echo (int) $will_overwrite; ?> of which will overwrite an existing icon).</strong>
        </label>
      </p>
      <?php submit_button('Apply', 'primary', 'submit', false); ?>
    </form>
  <?php endif; ?>

  <p>
    <a href="<?php echo esc_url(admin_url('admin.php?page=scoop_allergen_icons')); ?>" class="button">Back to start</a>
  </p>
  <?php
}

function scoop_ai_render_rows_table(array $rows): void {

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
      <tr><th>Allergen</th><th>Matched filename</th><th>Current icon</th><th>Action</th></tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td><code>#<?php echo (int) $row['id']; ?></code> <?php echo esc_html($row['title']); ?></td>
          <td><?php echo $row['matched_filename'] ? '<code>' . esc_html($row['matched_filename']) . '</code>' : '<em>none</em>'; ?></td>
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

function scoop_ai_render_apply_results(array $r): void {
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
          <tr><th>Allergen</th><th>Filename</th><th>Old attachment</th><th>New attachment</th></tr>
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
    <a href="<?php echo esc_url(admin_url('admin.php?page=scoop_allergen_icons')); ?>" class="button">Back to start</a>
  </p>
  <?php
}
