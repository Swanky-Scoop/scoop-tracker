<?php
if (!defined('ABSPATH')) exit;

/**
 * RCC Import admin page (Scoop → RCC Import).
 *
 * Flow lives in a per-user transient keyed by scoop_rcc_transient_key():
 *
 *   no transient              → upload form
 *   transient (no choices)    → map review
 *   transient w/ choices      → preview + commit
 *   transient w/ results      → results screen
 */
function scoop_render_rcc_import_page() {

  if (!current_user_can('manage_options')) wp_die('Unauthorized');

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = sanitize_key($_POST['scoop_rcc_action'] ?? '');
    if ($action !== '') {
      check_admin_referer("scoop_rcc_{$action}");
      switch ($action) {
        case 'upload': scoop_rcc_handle_upload();      break;
        case 'map':    scoop_rcc_handle_map_submit();  break;
        case 'commit': scoop_rcc_handle_commit();      break;
        case 'back':   scoop_rcc_handle_back();        break;
        case 'reset':  delete_transient(scoop_rcc_transient_key()); break;
      }
    }
  }

  $state = get_transient(scoop_rcc_transient_key());

  echo '<div class="wrap">';
  echo '<h1>RCC Import</h1>';

  if (!$state) {
    scoop_rcc_render_upload_form();
  } elseif (isset($state['results'])) {
    scoop_rcc_render_results($state);
  } elseif (isset($state['choices'])) {
    scoop_rcc_render_preview($state);
  } else {
    scoop_rcc_render_map_review($state);
  }

  scoop_rcc_render_styles();

  echo '</div>';
}

/* -------------------------------------------------------------------------
 * POST handlers
 * ---------------------------------------------------------------------- */

function scoop_rcc_handle_upload(): void {

  if (empty($_FILES['scoop_rcc_csv'])) {
    echo '<div class="notice notice-error"><p>No file selected.</p></div>';
    return;
  }

  $stash = scoop_rcc_stash_upload($_FILES['scoop_rcc_csv']);
  if (isset($stash['error'])) {
    echo '<div class="notice notice-error"><p>' . esc_html($stash['error']) . '</p></div>';
    return;
  }

  $parsed = scoop_rcc_parse_csv($stash['path']);
  if (isset($parsed['error'])) {
    @unlink($stash['path']);
    echo '<div class="notice notice-error"><p>' . esc_html($parsed['error']) . '</p></div>';
    return;
  }

  set_transient(
    scoop_rcc_transient_key(),
    ['filepath' => $stash['path'], 'type' => $parsed['type']],
    scoop_rcc_transient_ttl()
  );
}

function scoop_rcc_handle_map_submit(): void {

  $state = get_transient(scoop_rcc_transient_key());
  if (!$state) {
    echo '<div class="notice notice-error"><p>Import state expired. Please re-upload the file.</p></div>';
    return;
  }

  $choices_in = isset($_POST['scoop_rcc_choices']) && is_array($_POST['scoop_rcc_choices'])
    ? $_POST['scoop_rcc_choices'] : [];

  $choices = [];
  foreach ($choices_in as $i => $opts) {
    if (!is_array($opts)) continue;
    $idx = (int) $i;
    $choices[$idx] = [
      'backfill_id'          => !empty($opts['backfill_id']),
      'update_title'         => !empty($opts['update_title']),
      'create_new'           => !empty($opts['create_new']),
      'override_placeholder' => !empty($opts['override_placeholder']),
    ];
  }

  $state['choices'] = $choices;
  set_transient(scoop_rcc_transient_key(), $state, scoop_rcc_transient_ttl());
}

function scoop_rcc_handle_back(): void {
  $state = get_transient(scoop_rcc_transient_key());
  if (!$state) return;
  unset($state['choices'], $state['results']);
  set_transient(scoop_rcc_transient_key(), $state, scoop_rcc_transient_ttl());
}

function scoop_rcc_handle_commit(): void {

  $state = get_transient(scoop_rcc_transient_key());
  if (!$state || !isset($state['choices'])) {
    echo '<div class="notice notice-error"><p>Import state expired. Please re-upload the file.</p></div>';
    return;
  }
  if (empty($_POST['scoop_rcc_confirm'])) {
    echo '<div class="notice notice-error"><p>Please tick the confirmation checkbox before committing.</p></div>';
    return;
  }

  $parsed = scoop_rcc_parse_csv($state['filepath']);
  if (isset($parsed['error'])) {
    echo '<div class="notice notice-error"><p>' . esc_html($parsed['error']) . '</p></div>';
    return;
  }

  $pod_index   = scoop_rcc_load_pod_index(scoop_rcc_pod_name($parsed['type']));
  $classified  = scoop_rcc_classify_rows($parsed['rows'], $pod_index);
  $results     = scoop_rcc_commit_import($parsed['type'], $classified['classified'], $pod_index, $state['choices']);

  $state['results'] = $results;
  set_transient(scoop_rcc_transient_key(), $state, scoop_rcc_transient_ttl());
}

/* -------------------------------------------------------------------------
 * Screen 1 — upload
 * ---------------------------------------------------------------------- */

function scoop_rcc_render_upload_form(): void {
  ?>
  <p>Import recipe and ingredient data exported from <em>Recipe Cost Calculator</em>.</p>
  <div class="scoop-rcc-card">
    <form method="post" enctype="multipart/form-data">
      <?php wp_nonce_field('scoop_rcc_upload'); ?>
      <input type="hidden" name="scoop_rcc_action" value="upload">
      <table class="form-table" role="presentation">
        <tr>
          <th scope="row"><label for="scoop-rcc-csv">CSV file</label></th>
          <td>
            <input type="file" id="scoop-rcc-csv" name="scoop_rcc_csv" accept=".csv" required>
            <p class="description">
              Type is auto-detected. Recipes export must include the <code>Ingredient List</code>
              column; ingredients export must include <code>Price/unit</code>.
            </p>
          </td>
        </tr>
      </table>
      <?php submit_button('Upload &amp; parse'); ?>
    </form>
  </div>
  <?php
}

/* -------------------------------------------------------------------------
 * Screen 2 — map review
 * ---------------------------------------------------------------------- */

function scoop_rcc_render_map_review(array $state): void {

  $parsed = scoop_rcc_parse_csv($state['filepath']);
  if (isset($parsed['error'])) {
    echo '<div class="notice notice-error"><p>' . esc_html($parsed['error']) . '</p></div>';
    scoop_rcc_render_reset_form();
    return;
  }

  $type    = $parsed['type'];
  $rows    = $parsed['rows'];
  $pod_idx = scoop_rcc_load_pod_index(scoop_rcc_pod_name($type));
  $classed = scoop_rcc_classify_rows($rows, $pod_idx);

  $counts  = $classed['counts'];
  $total   = count($rows);
  $stub_n  = count(array_filter($rows, function ($r) { return !empty($r['_placeholder_suspected']); }));

  ?>
  <div class="scoop-rcc-card">
    <h2><?php echo esc_html(ucfirst($type)); ?>s — map review</h2>
    <p>
      <strong>File:</strong> <code><?php echo esc_html(basename($state['filepath'])); ?></code><br>
      <strong>Total CSV rows:</strong> <?php echo (int) $total; ?>
      &nbsp;·&nbsp; <strong>Placeholder-suspected:</strong> <?php echo (int) $stub_n; ?>
      <?php if (!$pod_idx['has_rcc_id_column']): ?>
        <br><span class="scoop-rcc-pill scoop-rcc-pill-warn">heads up</span>
        Pod table <code><?php echo esc_html($type); ?></code> has no <code>rcc_id</code> column yet —
        ID back-fill is disabled. See RCC_IMPORT_README.md §7.
      <?php endif; ?>
    </p>
    <table class="scoop-rcc-counts">
      <?php foreach ([
        'exact_match',
        'exact_id_near_title',
        'exact_title_missing_id',
        'title_match_id_conflict',
        'near_title',
        'csv_orphan',
      ] as $cls):
        $n = $counts[$cls] ?? 0; ?>
        <tr>
          <td><?php echo esc_html(scoop_rcc_class_label($cls)); ?></td>
          <td class="scoop-rcc-num"><?php echo (int) $n; ?></td>
        </tr>
      <?php endforeach; ?>
      <tr class="scoop-rcc-counts-sep">
        <td>Pod rows not in CSV</td>
        <td class="scoop-rcc-num"><?php echo (int) count($classed['pod_orphans']); ?></td>
      </tr>
    </table>
  </div>

  <form method="post">
    <?php wp_nonce_field('scoop_rcc_map'); ?>
    <input type="hidden" name="scoop_rcc_action" value="map">

    <?php
    $groups = [
      'exact_match'             => 'Exact matches (no decision needed)',
      'exact_id_near_title'     => 'ID match · title differs',
      'exact_title_missing_id'  => 'Title match · pod has no ID (safe back-fill)',
      'title_match_id_conflict' => 'Title match · pod has a different ID (will not change ID)',
      'near_title'              => 'Near title match (no ID)',
      'csv_orphan'              => 'CSV orphans — no match in pod',
    ];
    foreach ($groups as $cls => $label) {
      $bucket = array_filter($classed['classified'], function ($r) use ($cls) { return $r['class'] === $cls; });
      if (empty($bucket)) continue;
      scoop_rcc_render_map_group($cls, $label, $bucket, $pod_idx);
    }

    if (!empty($classed['pod_orphans'])) {
      scoop_rcc_render_pod_orphans($classed['pod_orphans']);
    }
    ?>

    <p>
      <?php submit_button('Continue to import preview', 'primary', 'submit', false); ?>
    </p>
  </form>

  <?php scoop_rcc_render_reset_form(); ?>
  <?php
}

function scoop_rcc_render_map_group(string $cls, string $label, array $bucket, array $pod_idx): void {
  ?>
  <div class="scoop-rcc-card">
    <h3><?php echo esc_html($label); ?> · <span class="scoop-rcc-mini"><?php echo count($bucket); ?> row<?php echo count($bucket) === 1 ? '' : 's'; ?></span></h3>
    <?php if ($cls === 'exact_match'): ?>
      <p class="description">No action needed for these. They'll appear on the preview only if a non-ID field changed.</p>
    <?php endif; ?>
    <table class="widefat striped scoop-rcc-group">
      <thead>
        <tr>
          <th class="scoop-rcc-col-name">CSV name</th>
          <th class="scoop-rcc-col-pod">Pod match</th>
          <th class="scoop-rcc-col-id">CSV ID</th>
          <th class="scoop-rcc-col-id">Pod ID(s)</th>
          <th class="scoop-rcc-col-actions">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($bucket as $i => $r):
        $csv = $r['csv'];
        $pod_row = $r['pod_id'] !== null ? ($pod_idx['rows'][$r['pod_id']] ?? null) : null;
        $csv_name = (string) ($csv['Name'] ?? '');
        $csv_id   = (string) ($csv['ID']   ?? '');
        $placeholder = !empty($csv['_placeholder_suspected']);
        $sim = $r['similarity'];
      ?>
        <tr>
          <td>
            <?php echo esc_html($csv_name); ?>
            <?php if ($placeholder): ?>
              <span class="scoop-rcc-pill scoop-rcc-pill-warn" title="Cost/price fields will be skipped for this row.">$1.00 stub</span>
            <?php endif; ?>
          </td>
          <td>
            <?php echo $pod_row ? '<code>#' . (int) $pod_row['id'] . '</code> ' . esc_html((string) $pod_row['post_title']) : '<em>—</em>'; ?>
            <?php if ($sim !== null): ?>
              <span class="scoop-rcc-mini">(<?php echo (int) $sim; ?>%)</span>
            <?php endif; ?>
          </td>
          <td><code><?php echo esc_html($csv_id); ?></code></td>
          <td><?php
            if ($pod_row) {
              $rcc = (string) ($pod_row['rcc_id'] ?? '');
              $cc  = (string) ($pod_row['cc_id']  ?? '');
              $parts = [];
              if ($rcc !== '') $parts[] = 'rcc=' . esc_html($rcc);
              if ($cc !== '' && $cc !== '0') $parts[] = 'cc=' . esc_html($cc);
              echo $parts ? implode(' · ', $parts) : '<em>none</em>';
            } else {
              echo '<em>—</em>';
            }
          ?></td>
          <td>
            <?php scoop_rcc_render_row_actions($cls, $i, $placeholder, $pod_idx); ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php
}

/**
 * Per-row checkboxes drawn from the class.
 */
function scoop_rcc_render_row_actions(string $cls, int $i, bool $placeholder, array $pod_idx): void {

  $name_prefix = "scoop_rcc_choices[{$i}]";

  if ($cls === 'exact_title_missing_id' && $pod_idx['has_rcc_id_column']) {
    ?>
    <label>
      <input type="checkbox" name="<?php echo esc_attr($name_prefix); ?>[backfill_id]" value="1" checked>
      Back-fill <code>rcc_id</code>
    </label>
    <?php
  }

  if (in_array($cls, ['exact_id_near_title', 'near_title', 'title_match_id_conflict'], true)) {
    ?>
    <label>
      <input type="checkbox" name="<?php echo esc_attr($name_prefix); ?>[update_title]" value="1">
      Adopt CSV title
    </label>
    <?php
  }

  if ($cls === 'near_title' && $pod_idx['has_rcc_id_column']) {
    ?>
    <label>
      <input type="checkbox" name="<?php echo esc_attr($name_prefix); ?>[backfill_id]" value="1">
      Back-fill <code>rcc_id</code>
    </label>
    <?php
  }

  if ($cls === 'csv_orphan') {
    ?>
    <label>
      <input type="checkbox" name="<?php echo esc_attr($name_prefix); ?>[create_new]" value="1">
      Create new pod row
    </label>
    <?php
  }

  if ($placeholder) {
    ?>
    <label class="scoop-rcc-override">
      <input type="checkbox" name="<?php echo esc_attr($name_prefix); ?>[override_placeholder]" value="1">
      Force-import cost/price
    </label>
    <?php
  }
}

function scoop_rcc_render_pod_orphans(array $orphans): void {

  if (empty($orphans)) return;

  // Limit display to a reasonable number — these are info-only.
  $shown_limit = 50;
  $total = count($orphans);
  $orphans = array_slice($orphans, 0, $shown_limit);

  ?>
  <div class="scoop-rcc-card">
    <h3>Pod rows not in CSV · <span class="scoop-rcc-mini"><?php echo (int) $total; ?> row<?php echo $total === 1 ? '' : 's'; ?></span></h3>
    <p class="description">Info only — these stay untouched. (Showing first <?php echo (int) min($shown_limit, $total); ?>.)</p>
    <table class="widefat striped">
      <thead><tr><th>Pod ID</th><th>Title</th><th>rcc_id</th><th>cc_id</th></tr></thead>
      <tbody>
      <?php foreach ($orphans as $r): ?>
        <tr>
          <td><code><?php echo (int) $r['id']; ?></code></td>
          <td><?php echo esc_html((string) $r['post_title']); ?></td>
          <td><?php echo esc_html((string) ($r['rcc_id'] ?? '')); ?></td>
          <td><?php echo esc_html((string) ($r['cc_id']  ?? '')); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php
}

/* -------------------------------------------------------------------------
 * Screen 3 — preview + commit
 * ---------------------------------------------------------------------- */

function scoop_rcc_render_preview(array $state): void {

  $parsed = scoop_rcc_parse_csv($state['filepath']);
  if (isset($parsed['error'])) {
    echo '<div class="notice notice-error"><p>' . esc_html($parsed['error']) . '</p></div>';
    scoop_rcc_render_reset_form();
    return;
  }

  $type      = $parsed['type'];
  $pod_idx   = scoop_rcc_load_pod_index(scoop_rcc_pod_name($type));
  $classed   = scoop_rcc_classify_rows($parsed['rows'], $pod_idx);
  $field_map = scoop_rcc_field_map($type);
  $choices   = $state['choices'];

  $updates_n = 0; $creates_n = 0; $title_changes_n = 0;
  $rows_to_render = [];

  foreach ($classed['classified'] as $i => $r) {

    $choice  = $choices[$i] ?? [];
    $csv     = $r['csv'];
    $placeholder = !empty($csv['_placeholder_suspected']);
    $override    = !empty($choice['override_placeholder']);

    if ($r['pod_id'] === null) {
      if ($r['class'] === 'csv_orphan' && !empty($choice['create_new'])) {
        $diff = scoop_rcc_build_field_diff($csv, null, $field_map, $placeholder, $override);
        if (!empty($diff)) {
          $rows_to_render[] = ['row' => $r, 'diff' => $diff, 'mode' => 'create'];
          $creates_n++;
        }
      }
      continue;
    }

    $pod_row = $pod_idx['rows'][$r['pod_id']] ?? null;
    $diff = scoop_rcc_build_field_diff($csv, $pod_row, $field_map, $placeholder, $override);

    $will_title = false;
    if (in_array($r['class'], ['exact_id_near_title', 'near_title', 'title_match_id_conflict'], true)
        && !empty($choice['update_title']) && $r['pod_title'] !== ($csv['Name'] ?? '')) {
      $will_title = true;
      $title_changes_n++;
    }

    $will_backfill = false;
    if ($r['class'] === 'exact_title_missing_id' && !empty($choice['backfill_id'])
        && !empty($pod_idx['has_rcc_id_column']) && !empty($csv['ID'])) {
      $will_backfill = true;
    }

    if (empty($diff) && !$will_title && !$will_backfill) continue;
    $rows_to_render[] = [
      'row'  => $r,
      'diff' => $diff,
      'mode' => 'update',
      'title_change'   => $will_title,
      'backfill_id'    => $will_backfill,
    ];
    $updates_n++;
  }

  ?>
  <div class="scoop-rcc-card">
    <h2><?php echo esc_html(ucfirst($type)); ?>s — preview</h2>
    <p>
      <strong>Updates:</strong> <?php echo (int) $updates_n; ?>
      &nbsp;·&nbsp; <strong>Creates:</strong> <?php echo (int) $creates_n; ?>
      &nbsp;·&nbsp; <strong>Title renames:</strong> <?php echo (int) $title_changes_n; ?>
    </p>
  </div>

  <?php if (empty($rows_to_render)): ?>
    <div class="scoop-rcc-card">
      <p>No field changes. Nothing to commit.</p>
    </div>
  <?php else: ?>
    <?php foreach ($rows_to_render as $r): scoop_rcc_render_preview_row($r); endforeach; ?>
  <?php endif; ?>

  <form method="post" class="scoop-rcc-card">
    <?php wp_nonce_field('scoop_rcc_commit'); ?>
    <input type="hidden" name="scoop_rcc_action" value="commit">
    <p>
      <label>
        <input type="checkbox" name="scoop_rcc_confirm" value="1" required>
        <strong>I've reviewed the diff above. Commit these changes.</strong>
      </label>
    </p>
    <?php submit_button('Commit import', 'primary', 'submit', false); ?>
  </form>

  <form method="post" style="display:inline-block; margin-top:1em;">
    <?php wp_nonce_field('scoop_rcc_back'); ?>
    <input type="hidden" name="scoop_rcc_action" value="back">
    <?php submit_button('Back to map review', 'secondary', 'submit', false); ?>
  </form>

  <?php scoop_rcc_render_reset_form(); ?>
  <?php
}

function scoop_rcc_render_preview_row(array $entry): void {

  $r    = $entry['row'];
  $diff = $entry['diff'];
  $csv  = $r['csv'];
  $name = (string) ($csv['Name'] ?? '');

  $mode_pill = $entry['mode'] === 'create'
    ? '<span class="scoop-rcc-pill scoop-rcc-pill-new">CREATE</span>'
    : '<span class="scoop-rcc-pill scoop-rcc-pill-upd">UPDATE</span>';

  ?>
  <div class="scoop-rcc-card scoop-rcc-row">
    <h3>
      <?php echo $mode_pill; ?>
      <?php echo esc_html($name); ?>
      <?php if (!empty($entry['title_change'])): ?>
        <span class="scoop-rcc-pill scoop-rcc-pill-warn">title rename</span>
      <?php endif; ?>
      <?php if (!empty($entry['backfill_id'])): ?>
        <span class="scoop-rcc-pill scoop-rcc-pill-warn">rcc_id back-fill</span>
      <?php endif; ?>
      <?php if ($r['pod_id']): ?>
        <span class="scoop-rcc-mini">→ pod #<?php echo (int) $r['pod_id']; ?></span>
      <?php endif; ?>
    </h3>
    <?php if (!empty($diff)): ?>
      <table class="widefat striped scoop-rcc-diff">
        <thead>
          <tr>
            <th>Field</th>
            <th>Current</th>
            <th>New</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($diff as $d):
          $row_cls = 'scoop-rcc-status-' . esc_attr($d['status']);
        ?>
          <tr class="<?php echo $row_cls; ?>">
            <td><code><?php echo esc_html($d['field']); ?></code></td>
            <td><?php scoop_rcc_render_cell_value($d['current']); ?></td>
            <td><?php scoop_rcc_render_cell_value($d['new']); ?></td>
            <td><?php echo esc_html(scoop_rcc_status_label($d['status'])); ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
  <?php
}

function scoop_rcc_render_cell_value(string $v): void {
  if ($v === '') { echo '<em>(empty)</em>'; return; }
  echo esc_html(scoop_rcc_truncate($v, 200));
}

function scoop_rcc_status_label(string $status): string {
  $map = [
    'new'                 => 'new value',
    'clobbered'           => 'overwrite',
    'skipped_empty'       => 'skipped (CSV empty)',
    'skipped_placeholder' => 'skipped (placeholder stub)',
  ];
  return $map[$status] ?? $status;
}

/* -------------------------------------------------------------------------
 * Screen 4 — results
 * ---------------------------------------------------------------------- */

function scoop_rcc_render_results(array $state): void {

  $r = $state['results'];
  ?>
  <div class="notice notice-success inline">
    <p>
      <strong>Import complete.</strong>
      Updated: <?php echo (int) $r['updated']; ?> ·
      Created: <?php echo (int) $r['created']; ?> ·
      Errors: <?php echo (int) count($r['errors']); ?>
    </p>
  </div>

  <?php if (!empty($r['errors'])): ?>
    <div class="scoop-rcc-card">
      <h3>Errors</h3>
      <ul class="scoop-rcc-errors">
        <?php foreach ($r['errors'] as $err): ?>
          <li><?php echo esc_html($err); ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php scoop_rcc_render_reset_form('Import another file'); ?>
  <?php
}

/* -------------------------------------------------------------------------
 * Shared bits
 * ---------------------------------------------------------------------- */

function scoop_rcc_render_reset_form(string $label = 'Start over (upload a different file)'): void {
  ?>
  <form method="post" style="margin-top:1em;">
    <?php wp_nonce_field('scoop_rcc_reset'); ?>
    <input type="hidden" name="scoop_rcc_action" value="reset">
    <?php submit_button($label, 'secondary', 'submit', false); ?>
  </form>
  <?php
}

function scoop_rcc_truncate(string $s, int $n): string {
  if (mb_strlen($s) <= $n) return $s;
  return mb_substr($s, 0, $n - 1) . '…';
}

function scoop_rcc_render_styles(): void {
  ?>
  <style>
    .scoop-rcc-card {
      background:#fff; border:1px solid #ccd0d4; padding:15px; margin:15px 0;
      border-radius:4px;
    }
    .scoop-rcc-card h2, .scoop-rcc-card h3 { margin-top:0; }
    .scoop-rcc-pill {
      display:inline-block; padding:2px 8px; border-radius:10px;
      font-size:11px; font-weight:600; letter-spacing:.02em;
      vertical-align:middle; margin-left:6px;
    }
    .scoop-rcc-pill-warn { background:#fff8e1; color:#7a5d00; border:1px solid #ffe082; }
    .scoop-rcc-pill-new  { background:#e7f5e7; color:#1f6f1f; border:1px solid #b6e0b6; }
    .scoop-rcc-pill-upd  { background:#e3f0fb; color:#1f4f86; border:1px solid #b6d4ef; }
    .scoop-rcc-mini      { color:#666; font-size:90%; font-weight:normal; }
    .scoop-rcc-counts    { border-collapse:collapse; margin:.5em 0; }
    .scoop-rcc-counts td { padding:2px 12px 2px 0; }
    .scoop-rcc-counts td.scoop-rcc-num { text-align:right; font-variant-numeric:tabular-nums; }
    .scoop-rcc-counts-sep td { border-top:1px solid #eee; padding-top:6px; }
    .scoop-rcc-group td, .scoop-rcc-group th {
      max-width:280px; overflow:hidden; text-overflow:ellipsis;
    }
    .scoop-rcc-col-actions label { display:block; margin:2px 0; }
    .scoop-rcc-override { color:#7a5d00; }
    .scoop-rcc-diff tr.scoop-rcc-status-clobbered { background:#fff8e1; }
    .scoop-rcc-diff tr.scoop-rcc-status-new        { background:#f3f9f5; }
    .scoop-rcc-diff tr.scoop-rcc-status-skipped_empty,
    .scoop-rcc-diff tr.scoop-rcc-status-skipped_placeholder { color:#888; }
    .scoop-rcc-errors li { font-family:monospace; font-size:90%; margin:.25em 0; }
  </style>
  <?php
}
