<?php
if (!defined('ABSPATH')) exit;

/**
 * Schema Sync admin page (Scoop → Schema Sync).
 *
 * Diffs this environment's live Pods config against the schema checked
 * into includes/pods-schema/_schema.php. Purely environment-local — it only
 * ever compares "this site" to "the code currently running on this site",
 * so validating on TEST before OPS (CLAUDE.md data repair policy) just
 * means loading this page on each environment in turn.
 *
 * Flow mirrors includes/flavor-photos-ui.php: check (dry-run) -> confirm ->
 * apply, each POST action gated by its own nonce and confirmation checkbox.
 */

add_action('admin_menu', 'scoop_register_schema_sync_admin_page', 20);

function scoop_register_schema_sync_admin_page(): void {
  add_submenu_page(
    'scoop_root',
    'Schema Sync',
    'Schema Sync',
    'manage_options',
    'scoop_schema_sync',
    'scoop_render_schema_sync_page'
  );
}

function scoop_render_schema_sync_page(): void {
  if (!current_user_can('manage_options')) wp_die('Unauthorized');

  $schema = scoop_schema_definition();
  $diff = null;
  $apply_result = null;
  $gc_result = null;
  $export_text = null;
  $save_result = null;
  $validate_result = null;

  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['scoop_schema_action'])) {
    $action = sanitize_key($_POST['scoop_schema_action']);

    if ($action === 'check') {
      check_admin_referer('scoop_schema_check');
      $diff = scoop_schema_diff($schema);

    } elseif ($action === 'apply') {
      check_admin_referer('scoop_schema_apply');
      if (empty($_POST['scoop_schema_confirm'])) {
        echo '<div class="notice notice-error"><p>Please tick the confirmation checkbox before applying.</p></div>';
        $diff = scoop_schema_diff($schema);
      } else {
        $pre_diff = scoop_schema_diff($schema);
        $apply_result = scoop_schema_apply_additive($schema, $pre_diff);
        $diff = scoop_schema_diff($schema);
        $apply_result['validation'] = scoop_schema_validate_after_apply($schema);
      }

    } elseif ($action === 'gc') {
      check_admin_referer('scoop_schema_gc');
      if (empty($_POST['scoop_schema_gc_confirm'])) {
        echo '<div class="notice notice-error"><p>Please tick the confirmation checkbox before deleting.</p></div>';
        $diff = scoop_schema_diff($schema);
      } else {
        $targets = [];
        foreach ((array) ($_POST['gc_field'] ?? []) as $pair) {
          $parts = explode('|', (string) $pair, 2);
          if (count($parts) === 2) $targets[] = ['pod' => $parts[0], 'field' => $parts[1]];
        }
        $gc_result = scoop_schema_gc_fields($targets);
        $diff = scoop_schema_diff($schema);
      }

    } elseif ($action === 'export') {
      check_admin_referer('scoop_schema_export');
      $export_text = scoop_schema_export_live_php_source();

    } elseif ($action === 'validate') {
      check_admin_referer('scoop_schema_validate');
      $validate_result = scoop_schema_validate_after_apply($schema);

    } elseif ($action === 'export_save') {
      check_admin_referer('scoop_schema_export_save');
      $save_result = scoop_schema_export_save_to_file();
    }
  }

  echo '<div class="wrap">';
  echo '<h1>Schema Sync</h1>';
  echo '<p>Compares this environment&rsquo;s live Pods schema against the schema checked into the plugin (<code>includes/pods-schema/_schema.php</code>). Nothing here reaches another environment.</p>';

  if (empty($schema)) {
    echo '<div class="notice notice-warning inline"><p><code>includes/pods-schema/_schema.php</code> is empty &mdash; nothing to compare yet. Use Export below to bootstrap it.</p></div>';
  }

  scoop_schema_render_check_form();

  if (!empty($schema)) {
    scoop_schema_render_validate_form();
  }

  if ($validate_result !== null) {
    scoop_schema_render_validate_card($validate_result);
  }

  if ($diff !== null) {
    if (!empty($diff['error'])) {
      echo '<div class="notice notice-error inline"><p>' . esc_html($diff['error']) . '</p></div>';
    } else {
      scoop_schema_render_diff_report($diff);
      if ($apply_result !== null) scoop_schema_render_apply_result($apply_result);
      if ($gc_result !== null) scoop_schema_render_gc_result($gc_result);
      if (!empty($schema)) {
        scoop_schema_render_apply_form($diff);
        scoop_schema_render_gc_form($diff);
      }
    }
  }

  scoop_schema_render_export_section($export_text, $save_result);

  scoop_rcc_render_styles();
  echo '</div>';
}

function scoop_schema_render_check_form(): void {
  ?>
  <form method="post" class="scoop-rcc-card">
    <?php wp_nonce_field('scoop_schema_check'); ?>
    <input type="hidden" name="scoop_schema_action" value="check">
    <h2>Check this environment</h2>
    <p class="description">Read-only. Loads the live Pods config on this site and compares it against the schema file.</p>
    <?php submit_button('Check schema', 'primary', 'submit', false); ?>
  </form>
  <?php
}

function scoop_schema_render_diff_report(array $diff): void {
  ?>
  <div class="scoop-rcc-card scoop-schema-diff">
    <h2>Report</h2>

    <?php if (!empty($diff['missing_pods'])): ?>
      <h3>Missing pods <span class="scoop-rcc-mini">(in schema, not live)</span></h3>
      <p>
        <?php foreach ($diff['missing_pods'] as $pod_name): ?>
          <span class="scoop-rcc-pill scoop-rcc-pill-new"><?php echo esc_html($pod_name); ?></span>
        <?php endforeach; ?>
      </p>
    <?php endif; ?>

    <?php if (!empty($diff['extra_pods'])): ?>
      <h3>Extra pods <span class="scoop-rcc-mini">(live, not in schema &mdash; report only, not deletable here)</span></h3>
      <p>
        <?php foreach ($diff['extra_pods'] as $pod_name): ?>
          <span class="scoop-rcc-pill scoop-rcc-pill-warn"><?php echo esc_html($pod_name); ?></span>
        <?php endforeach; ?>
      </p>
    <?php endif; ?>

    <?php
    $has_pod_detail = false;
    foreach ($diff['pods'] as $entry) {
      if (!empty($entry['changed_pod_attrs']) || !empty($entry['missing_fields']) || !empty($entry['extra_fields']) || !empty($entry['changed_fields'])) {
        $has_pod_detail = true;
        break;
      }
    }
    ?>

    <?php if ($has_pod_detail): ?>
      <h3>Per-pod drift</h3>
      <table class="widefat striped">
        <thead>
          <tr><th>Pod</th><th>Kind</th><th>Field / attr</th><th>Key</th><th>Schema value</th><th>Live value</th></tr>
        </thead>
        <tbody>
          <?php foreach ($diff['pods'] as $pod_name => $entry): ?>

            <?php foreach ($entry['changed_pod_attrs'] as $key => $vals): ?>
              <tr>
                <td><code><?php echo esc_html($pod_name); ?></code></td>
                <td><span class="scoop-rcc-pill scoop-rcc-pill-upd">pod attr</span></td>
                <td>&mdash;</td>
                <td><code><?php echo esc_html($key); ?></code></td>
                <td><?php echo esc_html(scoop_schema_display_val($vals['expected'])); ?></td>
                <td><?php echo esc_html(scoop_schema_display_val($vals['actual'])); ?></td>
              </tr>
            <?php endforeach; ?>

            <?php foreach ($entry['missing_fields'] as $field_name): ?>
              <tr>
                <td><code><?php echo esc_html($pod_name); ?></code></td>
                <td><span class="scoop-rcc-pill scoop-rcc-pill-new">missing field</span></td>
                <td><code><?php echo esc_html($field_name); ?></code></td>
                <td>&mdash;</td>
                <td>&mdash;</td>
                <td><em>none</em></td>
              </tr>
            <?php endforeach; ?>

            <?php foreach ($entry['changed_fields'] as $field_name => $changed): ?>
              <?php foreach ($changed as $key => $vals): ?>
                <tr>
                  <td><code><?php echo esc_html($pod_name); ?></code></td>
                  <td><span class="scoop-rcc-pill scoop-rcc-pill-upd">changed field</span></td>
                  <td><code><?php echo esc_html($field_name); ?></code></td>
                  <td><code><?php echo esc_html($key); ?></code></td>
                  <td><?php echo esc_html(scoop_schema_display_val($vals['expected'])); ?></td>
                  <td><?php echo esc_html(scoop_schema_display_val($vals['actual'])); ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endforeach; ?>

            <?php foreach ($entry['extra_fields'] as $field_name): ?>
              <tr>
                <td><code><?php echo esc_html($pod_name); ?></code></td>
                <td><span class="scoop-rcc-pill scoop-rcc-pill-warn">extra field</span></td>
                <td><code><?php echo esc_html($field_name); ?></code></td>
                <td>&mdash;</td>
                <td><em>none</em></td>
                <td>&mdash;</td>
              </tr>
            <?php endforeach; ?>

          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <?php if (empty($diff['missing_pods']) && empty($diff['extra_pods']) && !$has_pod_detail): ?>
      <p><strong>No drift.</strong> This environment matches the schema file.</p>
    <?php endif; ?>
  </div>
  <?php
}

function scoop_schema_display_val($val): string {
  if ($val === null) return '(none)';
  if (is_array($val)) return wp_json_encode($val);
  if ($val === '') return '(empty)';
  return (string) $val;
}

function scoop_schema_render_apply_form(array $diff): void {
  if (!scoop_schema_diff_has_additive_work($diff)) return;
  ?>
  <form method="post" class="scoop-rcc-card">
    <?php wp_nonce_field('scoop_schema_apply'); ?>
    <input type="hidden" name="scoop_schema_action" value="apply">
    <h2>Apply additive fixes</h2>
    <p class="description">Creates missing pods/fields and fixes the attrs/fields listed above. Never deletes anything.</p>
    <p>
      <label>
        <input type="checkbox" name="scoop_schema_confirm" value="1" required>
        <strong>Apply the additive fixes listed above to this environment.</strong>
      </label>
    </p>
    <?php submit_button('Apply', 'primary', 'submit', false); ?>
  </form>
  <?php
}

function scoop_schema_render_apply_result(array $r): void {
  ?>
  <div class="notice notice-success inline">
    <p>
      <strong>Apply complete.</strong><br>
      Created pods: <?php echo (int) count($r['created_pods']); ?> ·
      Updated pod attrs: <?php echo (int) count($r['updated_pod_attrs']); ?> ·
      Created fields: <?php echo (int) count($r['created_fields']); ?> ·
      Updated fields: <?php echo (int) count($r['updated_fields']); ?> ·
      Errors: <?php echo (int) count($r['errors']); ?>
    </p>
  </div>
  <?php if (!empty($r['errors'])): ?>
    <div class="scoop-rcc-card">
      <h2>Apply errors (<?php echo (int) count($r['errors']); ?>)</h2>
      <ul class="scoop-rcc-errors">
        <?php foreach ($r['errors'] as $err): ?>
          <li><?php echo esc_html($err); ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
  <?php
  $validation = $r['validation'] ?? null;
  if (is_array($validation)) scoop_schema_render_validate_card($validation);
}

function scoop_schema_render_gc_form(array $diff): void {
  if (!scoop_schema_diff_has_gc_work($diff)) return;
  ?>
  <form method="post" class="scoop-rcc-card">
    <?php wp_nonce_field('scoop_schema_gc'); ?>
    <input type="hidden" name="scoop_schema_action" value="gc">
    <h2>Garbage collection</h2>
    <p class="description">
      Fields that exist live but aren&rsquo;t in the schema file. Tick the ones you want deleted &mdash;
      nothing is removed unless you check it individually. Whole pods are never deletable from this page.
    </p>
    <?php foreach ($diff['pods'] as $pod_name => $entry): ?>
      <?php foreach ($entry['extra_fields'] as $field_name): ?>
        <label style="display:block;margin:4px 0;">
          <input type="checkbox" name="gc_field[]" value="<?php echo esc_attr($pod_name . '|' . $field_name); ?>">
          <code><?php echo esc_html($pod_name); ?>.<?php echo esc_html($field_name); ?></code>
        </label>
      <?php endforeach; ?>
    <?php endforeach; ?>
    <p>
      <label>
        <input type="checkbox" name="scoop_schema_gc_confirm" value="1" required>
        <strong>Delete the checked fields. This cannot be undone.</strong>
      </label>
    </p>
    <?php submit_button('Delete checked fields', 'delete', 'submit', false); ?>
  </form>
  <?php
}

/**
 * Validation card — the pass/fail assertion rendered after apply (and after
 * an on-demand Validate). Structure mirrors scoop_schema_render_apply_result():
 * a green notice when clean, a scoop-rcc-errors card when anything failed.
 *
 * The message text comes pre-built from the validator (it knows each
 * failure's kind/pod/field/attr), but it is still run through esc_html()
 * here — the renderer never trusts upstream text.
 */
function scoop_schema_render_validate_card(array $r): void {
  $failures = $r['failures'] ?? [];
  if (empty($failures)) {
    ?>
    <div class="notice notice-success inline">
      <p>
        <strong>Post-apply validation passed.</strong>
        Checked <?php echo (int) ($r['checked_pods'] ?? 0); ?> pod(s),
        <?php echo (int) ($r['checked_fields'] ?? 0); ?> field(s) — every declared field exists with the declared type and pick config.
      </p>
    </div>
    <?php
    return;
  }
  ?>
  <div class="notice notice-error inline">
    <p>
      <strong>Post-apply validation FAILED (<?php echo (int) count($failures); ?> problem<?php echo count($failures) === 1 ? '' : 's'; ?>).</strong><br>
      The apply did not produce the schema this page enforces. Fix the items below (correct the schema file, or fix this environment by hand), then Check &rarr; Apply &rarr; Validate again. Nothing self-heals on its own.
    </p>
  </div>
  <div class="scoop-rcc-card">
    <h2>Validation failures (<?php echo (int) count($failures); ?>)</h2>
    <table class="widefat striped">
      <thead>
        <tr><th>Kind</th><th>Field</th><th>Attr</th><th>Expected (schema)</th><th>Actual (live)</th></tr>
      </thead>
      <tbody>
        <?php foreach ($failures as $f): ?>
          <tr>
            <td><span class="scoop-rcc-pill scoop-rcc-pill-warn"><?php echo esc_html((string) ($f['kind'] ?? '?')); ?></span></td>
            <td><code><?php echo esc_html(trim(((string) ($f['pod'] ?? '')) . '.' . ((string) ($f['field'] ?? '')), '.')) ?: '&mdash;'; ?></code></td>
            <td><code><?php echo ((string) ($f['attr'] ?? '')) !== '' ? esc_html((string) $f['attr']) : '&mdash;'; ?></code></td>
            <td><?php echo esc_html(scoop_schema_display_val($f['expected'] ?? null)); ?></td>
            <td><?php echo esc_html(scoop_schema_display_val($f['actual'] ?? null)); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="description">
      <?php foreach ($failures as $f): ?>
        <strong><?php echo esc_html((string) ($f['kind'] ?? '?')); ?></strong> — <?php echo esc_html((string) ($f['message'] ?? '')); ?><br>
      <?php endforeach; ?>
    </p>
  </div>
  <?php
}

function scoop_schema_render_validate_form(): void {
  ?>
  <form method="post" class="scoop-rcc-card">
    <?php wp_nonce_field('scoop_schema_validate'); ?>
    <input type="hidden" name="scoop_schema_action" value="validate">
    <h2>Validate now</h2>
    <p class="description">
      Read-only. Re-checks this environment against the schema — every declared field present, with the declared
      type and pick config, and no pick field pointing at a pod that doesn&rsquo;t exist. Runs automatically after
      every Apply; use this to re-run it any time (e.g. after a hand repair).
    </p>
    <?php submit_button('Validate now', 'secondary', 'submit', false); ?>
  </form>
  <?php
}

function scoop_schema_render_gc_result(array $r): void {
  ?>
  <div class="notice notice-success inline">
    <p>
      <strong>Garbage collection complete.</strong><br>
      Deleted fields: <?php echo (int) count($r['deleted_fields']); ?> ·
      Errors: <?php echo (int) count($r['errors']); ?>
    </p>
  </div>
  <?php if (!empty($r['deleted_fields'])): ?>
    <div class="scoop-rcc-card">
      <h3>Deleted</h3>
      <p><?php foreach ($r['deleted_fields'] as $f): ?><code><?php echo esc_html($f); ?></code> <?php endforeach; ?></p>
    </div>
  <?php endif; ?>
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
  <?php
}

function scoop_schema_render_export_section(?string $export_text, ?array $save_result = null): void {
  ?>
  <div class="scoop-rcc-card">
    <h2>Export live schema</h2>
    <p class="description">
      Dumps this environment&rsquo;s live Pods config, shaped to match <code>_schema.php</code>, with
      per-environment fields (<code>id</code>, <code>sister_id</code>, <code>weight</code>, timestamps)
      already stripped. Preview/download it, or write it straight to
      <code>includes/pods-schema/_schema.php</code> in the plugin directory &mdash; that file is inert
      data read only by this page, so saving it here just edits this server&rsquo;s working copy; it
      has no effect anywhere until someone commits and deploys it. Trim the result down to what you
      actually want enforced before committing (see the authoring note at the top of
      <code>_schema.php</code>).
    </p>

    <?php if ($save_result !== null): ?>
      <div class="notice <?php echo $save_result['ok'] ? 'notice-success' : 'notice-error'; ?> inline">
        <p><?php echo esc_html($save_result['message']); ?></p>
      </div>
    <?php endif; ?>

    <form method="post" style="display:inline-block;margin-right:8px;">
      <?php wp_nonce_field('scoop_schema_export'); ?>
      <input type="hidden" name="scoop_schema_action" value="export">
      <?php submit_button('Generate export', 'secondary', 'submit', false); ?>
    </form>

    <form method="post" style="display:inline-block;" onsubmit="return confirm('Overwrite includes/pods-schema/_schema.php on disk with the live export? This replaces the file\'s current contents.');">
      <?php wp_nonce_field('scoop_schema_export_save'); ?>
      <input type="hidden" name="scoop_schema_action" value="export_save">
      <?php submit_button('Save to includes/pods-schema/_schema.php', 'secondary', 'submit', false); ?>
    </form>

    <?php if ($export_text !== null): ?>
      <p>
        <textarea id="scoop-schema-export-text" readonly style="width:100%;height:320px;font-family:monospace;font-size:12px;margin-top:1em;"><?php echo esc_textarea($export_text); ?></textarea>
      </p>
      <p>
        <button type="button" class="button" id="scoop-schema-export-download">Download as _schema.php</button>
      </p>
      <script type="text/javascript">
      (function () {
        var btn = document.getElementById('scoop-schema-export-download');
        var ta = document.getElementById('scoop-schema-export-text');
        if (!btn || !ta) return;
        btn.addEventListener('click', function () {
          var blob = new Blob([ta.value], { type: 'text/plain;charset=utf-8;' });
          var url = URL.createObjectURL(blob);
          var a = document.createElement('a');
          a.href = url; a.download = '_schema.php';
          document.body.appendChild(a); a.click(); document.body.removeChild(a);
          URL.revokeObjectURL(url);
        });
      })();
      </script>
    <?php endif; ?>
  </div>
  <?php
}
