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
  } elseif (($state['mode'] ?? 'csv') === 'quantities') {
    if (isset($state['results'])) {
      scoop_rcc_render_q_results($state);
    } elseif (isset($state['choices'])) {
      scoop_rcc_render_q_preview($state);
    } else {
      scoop_rcc_render_q_review($state);
    }
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

  $stash = scoop_rcc_stash_upload($_FILES['scoop_rcc_csv'], ['csv', 'md']);
  if (isset($stash['error'])) {
    echo '<div class="notice notice-error"><p>' . esc_html($stash['error']) . '</p></div>';
    return;
  }

  // Markdown → recipe ingredient-quantity flow; CSV → recipe/ingredient flow.
  if ($stash['ext'] === 'md') {
    $parsed = scoop_rcc_parse_recipe_quantities_md($stash['path']);
    if (isset($parsed['error'])) {
      @unlink($stash['path']);
      echo '<div class="notice notice-error"><p>' . esc_html($parsed['error']) . '</p></div>';
      return;
    }
    set_transient(
      scoop_rcc_transient_key(),
      ['mode' => 'quantities', 'filepath' => $stash['path']],
      scoop_rcc_transient_ttl()
    );
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
    ['mode' => 'csv', 'filepath' => $stash['path'], 'type' => $parsed['type']],
    scoop_rcc_transient_ttl()
  );
}

function scoop_rcc_handle_map_submit(): void {

  $state = get_transient(scoop_rcc_transient_key());
  if (!$state) {
    echo '<div class="notice notice-error"><p>Import state expired. Please re-upload the file.</p></div>';
    return;
  }

  if (($state['mode'] ?? 'csv') === 'quantities') {
    scoop_rcc_handle_q_map_submit($state);
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

/**
 * Quantities-mode map submit: per-recipe skip/replace + global create-missing.
 */
function scoop_rcc_handle_q_map_submit(array $state): void {

  $choices_in = isset($_POST['scoop_rcc_choices']) && is_array($_POST['scoop_rcc_choices'])
    ? $_POST['scoop_rcc_choices'] : [];

  $choices = [];
  foreach ($choices_in as $i => $opts) {
    if (!is_array($opts)) continue;
    $choices[(int) $i] = [
      'skip'    => !empty($opts['skip']),
      'replace' => !empty($opts['replace']),
    ];
  }

  $state['choices'] = $choices;
  $state['opts'] = [
    'create_missing_ingredients' => !empty($_POST['scoop_rcc_create_missing']),
  ];
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

  if (($state['mode'] ?? 'csv') === 'quantities') {
    scoop_rcc_handle_q_commit($state);
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

/**
 * Quantities-mode commit: re-parse the Markdown, re-plan against live pods,
 * then write the map rows.
 */
function scoop_rcc_handle_q_commit(array $state): void {

  $parsed = scoop_rcc_parse_recipe_quantities_md($state['filepath']);
  if (isset($parsed['error'])) {
    echo '<div class="notice notice-error"><p>' . esc_html($parsed['error']) . '</p></div>';
    return;
  }

  $plan    = scoop_rcc_plan_quantities($parsed['recipes']);
  $results = scoop_rcc_commit_quantities($plan['recipes'], $state['choices'], $state['opts'] ?? []);

  $state['results'] = $results;
  set_transient(scoop_rcc_transient_key(), $state, scoop_rcc_transient_ttl());
}

/* -------------------------------------------------------------------------
 * Screen 1 — upload
 * ---------------------------------------------------------------------- */

function scoop_rcc_render_upload_form(): void {
  ?>
  <p>Import data exported from <em>Recipe Cost Calculator</em>.</p>
  <div class="scoop-rcc-card">
    <form method="post" enctype="multipart/form-data">
      <?php wp_nonce_field('scoop_rcc_upload'); ?>
      <input type="hidden" name="scoop_rcc_action" value="upload">
      <table class="form-table" role="presentation">
        <tr>
          <th scope="row"><label for="scoop-rcc-csv">Export file</label></th>
          <td>
            <input type="file" id="scoop-rcc-csv" name="scoop_rcc_csv" accept=".csv,.md" required>
            <p class="description">
              Flow is chosen by file type:
            </p>
            <ul class="description" style="list-style:disc; margin-left:1.4em;">
              <li><strong><code>.csv</code></strong> — recipe/ingredient cost data.
                Type auto-detected: recipes export includes <code>Ingredient List</code>;
                ingredients export includes <code>Price/unit</code>.</li>
              <li><strong><code>.md</code></strong> — recipe <em>ingredient quantities</em>
                (the Markdown recipe export). Creates <code>recipe-ingredient-ma</code> rows
                linked to each recipe. See RCC_IMPORT_README.md §14.</li>
            </ul>
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

  <form method="post" id="scoop-rcc-map-form">
    <?php wp_nonce_field('scoop_rcc_map'); ?>
    <input type="hidden" name="scoop_rcc_action" value="map">

    <?php
    $orphan_n = $counts['csv_orphan'] ?? 0;
    $near_n   = $counts['near_title'] ?? 0;
    scoop_rcc_render_bulk_controls($stub_n > 0, ($orphan_n + $near_n) > 0);
    ?>

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

    scoop_rcc_render_map_js();
    ?>

    <p>
      <?php submit_button('Continue to import preview', 'primary', 'submit', false); ?>
    </p>
  </form>

  <?php scoop_rcc_render_reset_form(); ?>
  <?php
}

/**
 * Bulk "select all" toggles for the two repeated row checkboxes. Each master
 * checkbox flips every checkbox in the form whose name contains the given
 * bracketed key (e.g. "[override_placeholder]"). Rendered once, above the
 * groups. Only shows a toggle when at least one such checkbox exists.
 */
function scoop_rcc_render_bulk_controls(bool $show_force, bool $show_create): void {

  if (!$show_force && !$show_create) return;
  ?>
  <div class="scoop-rcc-card scoop-rcc-bulk">
    <strong>Select all:</strong>
    <?php if ($show_create): ?>
      <label>
        <input type="checkbox" class="scoop-rcc-selectall" data-scoop-target="[create_new]">
        Create new entry <span class="scoop-rcc-mini">(orphans + near matches)</span>
      </label>
    <?php endif; ?>
    <?php if ($show_force): ?>
      <label>
        <input type="checkbox" class="scoop-rcc-selectall" data-scoop-target="[override_placeholder]">
        Force-import cost/price <span class="scoop-rcc-mini">(all $1.00 stubs)</span>
      </label>
    <?php endif; ?>
  </div>
  <?php
}

/**
 * One script for the map-review form: wires the "select all" masters and the
 * per-row exclusivity rule (a checked "Create new entry" disables that row's
 * merge-only options — adopt-title and rcc_id back-fill don't apply when you're
 * creating a brand-new row). Rendered once, after the groups.
 */
function scoop_rcc_render_map_js(): void {
  ?>
  <script>
  (function () {
    var form = document.getElementById('scoop-rcc-map-form');
    if (!form) return;

    // "Select all" masters — flip every (enabled) checkbox whose name contains
    // the target key, firing change so dependent rules re-run.
    form.querySelectorAll('.scoop-rcc-selectall').forEach(function (master) {
      var key = master.getAttribute('data-scoop-target');
      master.addEventListener('change', function () {
        form.querySelectorAll('input[type="checkbox"]').forEach(function (cb) {
          if (cb !== master && cb.name && cb.name.indexOf(key) !== -1 && !cb.disabled) {
            cb.checked = master.checked;
            cb.dispatchEvent(new Event('change', { bubbles: true }));
          }
        });
      });
    });

    // Per-row exclusivity: "Create new entry" vs the merge-only options.
    form.querySelectorAll('input[name$="[create_new]"]').forEach(function (create) {
      var row = create.closest('tr');
      if (!row) return;
      var others = Array.prototype.filter.call(
        row.querySelectorAll('input[type="checkbox"]'),
        function (cb) { return cb !== create && cb.name && /\[(update_title|backfill_id)\]/.test(cb.name); }
      );
      function sync() {
        others.forEach(function (cb) {
          cb.disabled = create.checked;
          if (create.checked) cb.checked = false;
        });
      }
      create.addEventListener('change', sync);
      sync();
    });
  })();
  </script>
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
            <?php scoop_rcc_render_row_actions($cls, $i, $placeholder, $pod_idx, $r['pod_id'] ?? null); ?>
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
function scoop_rcc_render_row_actions(string $cls, int $i, bool $placeholder, array $pod_idx, ?int $pod_id = null): void {

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

  // Near-match rows fuzzy-matched an existing pod row. By default they UPDATE
  // that row. Opt in here to instead import the CSV item as a brand-new pod
  // row (use when the near match is a false positive — a genuinely different
  // item with a similar name). Mutually exclusive with the title/back-fill
  // actions above, which only make sense when updating the matched row.
  if ($cls === 'near_title') {
    ?>
    <label class="scoop-rcc-create-new">
      <input type="checkbox" name="<?php echo esc_attr($name_prefix); ?>[create_new]" value="1">
      <strong>Create new entry</strong><?php echo $pod_id ? ' <span class="scoop-rcc-mini">(ignore the #' . (int) $pod_id . ' near match)</span>' : ''; ?>
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

    // Near match the operator chose to import as a brand-new row instead of
    // merging into the fuzzy-matched pod row.
    if ($r['class'] === 'near_title' && !empty($choice['create_new'])) {
      $diff = scoop_rcc_build_field_diff($csv, null, $field_map, $placeholder, $override);
      if (!empty($diff)) {
        $rows_to_render[] = ['row' => $r, 'diff' => $diff, 'mode' => 'create'];
        $creates_n++;
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
 * Quantities mode — review / preview / results
 * (Markdown recipe ingredient-quantity import; see RCC_IMPORT_README.md §14)
 * ---------------------------------------------------------------------- */

function scoop_rcc_render_q_review(array $state): void {

  $parsed = scoop_rcc_parse_recipe_quantities_md($state['filepath']);
  if (isset($parsed['error'])) {
    echo '<div class="notice notice-error"><p>' . esc_html($parsed['error']) . '</p></div>';
    scoop_rcc_render_reset_form();
    return;
  }

  $plan   = scoop_rcc_plan_quantities($parsed['recipes']);
  $recipes = $plan['recipes'];
  $c      = $plan['counts'];

  $with_items = array_filter($recipes, function ($r) { return !empty($r['items']); });
  $empty_n    = count($recipes) - count($with_items);
  $excluded_n = count($parsed['excluded'] ?? []);

  ?>
  <div class="scoop-rcc-card">
    <h2>Recipe ingredient quantities — review</h2>
    <p>
      <strong>File:</strong> <code><?php echo esc_html(basename($state['filepath'])); ?></code><br>
      <strong>Recipes parsed:</strong> <?php echo (int) count($recipes); ?>
      &nbsp;·&nbsp; <strong>With quantities:</strong> <?php echo (int) count($with_items); ?>
      &nbsp;·&nbsp; <strong>Empty (skipped):</strong> <?php echo (int) $empty_n; ?>
      &nbsp;·&nbsp; <strong>Scaled variants excluded:</strong> <?php echo (int) $excluded_n; ?><br>
      <strong>Recipe matches:</strong> <?php echo (int) $c['recipes_matched']; ?> matched,
      <?php echo (int) $c['recipes_unmatched']; ?> unmatched
      &nbsp;·&nbsp; <strong>Ingredient lines:</strong> <?php echo (int) $c['items_matched']; ?> matched,
      <?php echo (int) $c['items_unmatched']; ?> unmatched of <?php echo (int) $c['items_total']; ?>
      &nbsp;·&nbsp; <strong>Recipes already populated:</strong> <?php echo (int) $c['recipes_with_existing']; ?>
      &nbsp;·&nbsp; <strong>With Preparation Method:</strong> <?php echo (int) ($c['recipes_with_prep'] ?? 0); ?>
    </p>
    <p class="description">
      Each ingredient line becomes one <code>recipe-ingredient-ma</code> row linked to the recipe.
      Preparation Method text is written to the recipe's <code>instructions</code> field.
      Recipes with no title match are skipped; maps for recipes that already have them are skipped
      unless you opt in below (instructions still update).
    </p>
  </div>

  <form method="post">
    <?php wp_nonce_field('scoop_rcc_map'); ?>
    <input type="hidden" name="scoop_rcc_action" value="map">

    <div class="scoop-rcc-card">
      <label>
        <input type="checkbox" name="scoop_rcc_create_missing" value="1">
        <strong>Create missing ingredients as stubs</strong>
        <span class="scoop-rcc-mini">(off = unmatched ingredient lines are skipped)</span>
      </label>
    </div>

    <?php foreach ($recipes as $i => $r):
      if (empty($r['items']) && $r['prep'] === '') continue;
      scoop_rcc_render_q_recipe_card($i, $r, true);
    endforeach; ?>

    <p><?php submit_button('Continue to import preview', 'primary', 'submit', false); ?></p>
  </form>

  <?php scoop_rcc_render_reset_form(); ?>
  <?php
}

/**
 * One recipe card. $editable adds the per-recipe skip/replace controls
 * (review screen); the preview screen passes false.
 */
function scoop_rcc_render_q_recipe_card(int $i, array $r, bool $editable): void {

  $matched = $r['recipe_status'] === 'matched';
  $status_pill = $matched
    ? '<span class="scoop-rcc-pill scoop-rcc-pill-new">recipe matched</span>'
    : '<span class="scoop-rcc-pill scoop-rcc-pill-warn">' . esc_html($r['recipe_status']) . '</span>';
  ?>
  <div class="scoop-rcc-card scoop-rcc-row">
    <h3>
      <?php echo esc_html($r['title']); ?>
      <?php echo $status_pill; ?>
      <span class="scoop-rcc-mini"><?php echo esc_html($r['format']); ?> ·
        <?php echo (int) count($r['items']); ?> ingredient<?php echo count($r['items']) === 1 ? '' : 's'; ?></span>
      <?php if ($r['existing_maps'] > 0): ?>
        <span class="scoop-rcc-pill scoop-rcc-pill-warn">already has <?php echo (int) $r['existing_maps']; ?> map<?php echo $r['existing_maps'] === 1 ? '' : 's'; ?></span>
      <?php endif; ?>
    </h3>

    <?php foreach ($r['warnings'] as $w): ?>
      <p class="description">⚠ <?php echo esc_html($w); ?></p>
    <?php endforeach; ?>

    <?php if ($r['prep'] !== ''): ?>
      <p class="scoop-rcc-prep">
        <span class="scoop-rcc-pill scoop-rcc-pill-upd">instructions</span>
        Preparation Method → <code>instructions</code>
        (<?php echo (int) mb_strlen($r['prep']); ?> chars<?php
          echo $r['instructions_current'] !== '' ? ', overwrites existing' : '';
        ?>)
      </p>
    <?php endif; ?>

    <?php if (empty($r['items'])): ?>
      <p class="description">No ingredient quantities — instructions only.</p>
    <?php else: ?>
    <table class="widefat striped scoop-rcc-diff">
      <thead>
        <tr><th>Ingredient (from file)</th><th>Pod match</th><th>Quantity</th><th>Unit → field</th><th>Notes</th></tr>
      </thead>
      <tbody>
      <?php foreach ($r['items'] as $item):
        $ok = $item['status'] === 'matched';
        $is_sub = $item['target_type'] === 'sub_recipe';
        $norm = $item['unit_norm'];
        $unit_target = $norm['field'] !== null
          ? esc_html($norm['field']) . '=' . esc_html((string) $norm['value'])
          : '<em>—</em>';
      ?>
        <tr class="<?php echo $ok ? 'scoop-rcc-status-new' : 'scoop-rcc-status-clobbered'; ?>">
          <td><?php echo esc_html($item['name']); ?></td>
          <td><?php
            if ($ok) {
              echo ($is_sub ? '<span class="scoop-rcc-pill scoop-rcc-pill-upd">sub-recipe</span> ' : '')
                . '<code>#' . (int) $item['target_id'] . '</code>';
            } else {
              echo '<span class="scoop-rcc-pill scoop-rcc-pill-warn">' . esc_html($item['status']) . '</span>';
            }
          ?></td>
          <td><?php echo esc_html($item['qty_raw']); ?>
              <span class="scoop-rcc-mini">(<?php echo esc_html(rtrim(rtrim(number_format((float) $item['qty'], 3, '.', ''), '0'), '.')); ?>)</span></td>
          <td><?php echo esc_html($item['unit']); ?> <span class="scoop-rcc-mini">→ <?php echo $unit_target; ?></span></td>
          <td><?php
            $notes = $item['warnings'];
            if (!$ok) $notes[] = 'no ingredient or recipe match';
            echo $notes ? esc_html(implode('; ', $notes)) : '<span class="scoop-rcc-mini">ok</span>';
          ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

    <?php if ($editable && $matched): ?>
      <p class="scoop-rcc-col-actions">
        <label><input type="checkbox" name="scoop_rcc_choices[<?php echo (int) $i; ?>][skip]" value="1"> Skip this recipe</label>
        <?php if ($r['existing_maps'] > 0): ?>
          <label class="scoop-rcc-override">
            <input type="checkbox" name="scoop_rcc_choices[<?php echo (int) $i; ?>][replace]" value="1">
            Replace existing maps (deletes the <?php echo (int) $r['existing_maps']; ?> current row<?php echo $r['existing_maps'] === 1 ? '' : 's'; ?>)
          </label>
        <?php endif; ?>
      </p>
    <?php elseif (!$matched): ?>
      <p class="description">Recipe title not matched — will be skipped.</p>
    <?php endif; ?>
  </div>
  <?php
}

function scoop_rcc_render_q_preview(array $state): void {

  $parsed = scoop_rcc_parse_recipe_quantities_md($state['filepath']);
  if (isset($parsed['error'])) {
    echo '<div class="notice notice-error"><p>' . esc_html($parsed['error']) . '</p></div>';
    scoop_rcc_render_reset_form();
    return;
  }

  $plan    = scoop_rcc_plan_quantities($parsed['recipes']);
  $choices = $state['choices'];
  $create_missing = !empty($state['opts']['create_missing_ingredients']);

  $will_import = []; $skipped = 0; $rows_planned = 0; $instr_planned = 0;

  foreach ($plan['recipes'] as $i => $r) {
    $choice = $choices[$i] ?? [];
    if ($r['recipe_status'] !== 'matched' || !empty($choice['skip'])) { $skipped++; continue; }

    $will_instr = ($r['prep'] !== '' && $r['prep'] !== $r['instructions_current']);
    $will_maps  = (!empty($r['items']) && !($r['existing_maps'] > 0 && empty($choice['replace'])));

    if (!$will_instr && !$will_maps) { $skipped++; continue; }

    $will_import[$i] = $r;
    if ($will_instr) $instr_planned++;
    if ($will_maps) {
      foreach ($r['items'] as $item) {
        if ($item['status'] === 'matched' || $create_missing) $rows_planned++;
      }
    }
  }

  ?>
  <div class="scoop-rcc-card">
    <h2>Recipe ingredient quantities — preview</h2>
    <p>
      <strong>Recipes to change:</strong> <?php echo (int) count($will_import); ?>
      &nbsp;·&nbsp; <strong>Map rows to create:</strong> <?php echo (int) $rows_planned; ?>
      &nbsp;·&nbsp; <strong>Instructions to write:</strong> <?php echo (int) $instr_planned; ?>
      &nbsp;·&nbsp; <strong>Skipped:</strong> <?php echo (int) $skipped; ?>
      &nbsp;·&nbsp; <strong>Create missing ingredients:</strong> <?php echo $create_missing ? 'yes' : 'no'; ?>
    </p>
  </div>

  <?php if (empty($will_import)): ?>
    <div class="scoop-rcc-card"><p>Nothing to import. Go back and adjust your choices.</p></div>
  <?php else: ?>
    <?php foreach ($will_import as $i => $r) scoop_rcc_render_q_recipe_card($i, $r, false); ?>
  <?php endif; ?>

  <form method="post" class="scoop-rcc-card">
    <?php wp_nonce_field('scoop_rcc_commit'); ?>
    <input type="hidden" name="scoop_rcc_action" value="commit">
    <p>
      <label>
        <input type="checkbox" name="scoop_rcc_confirm" value="1" required>
        <strong>I've reviewed the above. Apply these changes.</strong>
      </label>
    </p>
    <?php submit_button('Commit import', 'primary', 'submit', false); ?>
  </form>

  <form method="post" style="display:inline-block; margin-top:1em;">
    <?php wp_nonce_field('scoop_rcc_back'); ?>
    <input type="hidden" name="scoop_rcc_action" value="back">
    <?php submit_button('Back to review', 'secondary', 'submit', false); ?>
  </form>

  <?php scoop_rcc_render_reset_form(); ?>
  <?php
}

function scoop_rcc_render_q_results(array $state): void {

  $r = $state['results'];
  ?>
  <div class="notice notice-success inline">
    <p>
      <strong>Import complete.</strong>
      Recipes: <?php echo (int) $r['recipes_done']; ?> ·
      Map rows created: <?php echo (int) $r['maps_created']; ?> ·
      Map rows deleted: <?php echo (int) $r['maps_deleted']; ?> ·
      Instructions written: <?php echo (int) ($r['instructions_written'] ?? 0); ?> ·
      Ingredients created: <?php echo (int) $r['ing_created']; ?> ·
      Items skipped: <?php echo (int) $r['items_skipped']; ?> ·
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
    .scoop-rcc-create-new { color:#1f6f1f; }
    .scoop-rcc-bulk label { margin-right:20px; font-weight:600; }
    .scoop-rcc-bulk .scoop-rcc-mini { font-weight:normal; }
    .scoop-rcc-diff tr.scoop-rcc-status-clobbered { background:#fff8e1; }
    .scoop-rcc-diff tr.scoop-rcc-status-new        { background:#f3f9f5; }
    .scoop-rcc-diff tr.scoop-rcc-status-skipped_empty,
    .scoop-rcc-diff tr.scoop-rcc-status-skipped_placeholder { color:#888; }
    .scoop-rcc-errors li { font-family:monospace; font-size:90%; margin:.25em 0; }
    .scoop-rcc-prep { margin:.5em 0; color:#1f4f86; }
  </style>
  <?php
}
