<?php
/**
 * includes/audit-ui.php
 *
 * Admin page "Price Audit" under the Scoop menu. Read-only.
 *
 * Calls the read-only audit endpoints (includes/audit.php) from the browser
 * using the logged-in cookie + a wp_rest nonce, renders a preview table, and
 * generates the two CSVs (PRICE-SOURCING.md §4 Features 1 & 2) entirely
 * client-side — the PHP endpoint is the permanent, reusable artifact; CSV
 * shaping is just presentation.
 *
 * The submenu is registered here (hooked late so it lands under the existing
 * `scoop_root` menu from includes/admin-menu.php).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', 'scoop_register_audit_admin_page', 20 );

function scoop_register_audit_admin_page(): void {
  add_submenu_page(
    'scoop_root',
    'Price Audit',
    'Price Audit',
    'manage_options',
    'scoop_price_audit',
    'scoop_render_price_audit_page'
  );
}

function scoop_render_price_audit_page(): void {
  if ( ! current_user_can( 'manage_options' ) ) return;

  $nonce = wp_create_nonce( 'wp_rest' );
  $base  = esc_url_raw( rest_url( 'scoop/v1/audit' ) );
  ?>
  <div class="wrap">
    <h1>Price Audit</h1>
    <p>Read-only data-quality reports. Nothing here writes to the database — these surface suspect pricing for human review (see <code>PRICE-SOURCING.md</code>).</p>

    <p>
      <button class="button button-primary" id="scoop-audit-run-ing">Run ingredient audit</button>
      <button class="button" id="scoop-audit-dl-ing" disabled>Download ingredient-price-audit.csv</button>
      &nbsp;&nbsp;
      <button class="button button-primary" id="scoop-audit-run-flav">Run flavor audit</button>
      <button class="button" id="scoop-audit-dl-flav" disabled>Download flavor-cost-audit.csv</button>
    </p>

    <p id="scoop-audit-status" style="font-style:italic;color:#666;"></p>
    <div id="scoop-audit-summary"></div>
    <div id="scoop-audit-preview" style="overflow:auto;max-height:60vh;border:1px solid #ddd;margin-top:1em;"></div>
  </div>

  <script type="text/javascript">
  (function () {
    var BASE  = <?php echo wp_json_encode( $base ); ?>;
    var NONCE = <?php echo wp_json_encode( $nonce ); ?>;

    var COLS = {
      ingredients: ['ingredient_id','ingredient_name','wp_admin_url','stored_unit','stored_price','calculated_price_per_liter','flag','likely_issue'],
      flavors:     ['flavor_id','flavor_name','flavor_wp_admin_url','recipe_id','recipe_wp_admin_url','calculated_cost_per_liter','flag','likely_cause']
    };

    var statusEl  = document.getElementById('scoop-audit-status');
    var summaryEl = document.getElementById('scoop-audit-summary');
    var previewEl = document.getElementById('scoop-audit-preview');

    var lastCsv = { ingredients: null, flavors: null };

    function setStatus(msg) { statusEl.textContent = msg; }

    function csvCell(v) {
      if (v === null || v === undefined) return '';
      var s = String(v);
      if (/[",\n\r]/.test(s)) s = '"' + s.replace(/"/g, '""') + '"';
      return s;
    }

    function toCsv(cols, rows) {
      var lines = [cols.join(',')];
      rows.forEach(function (r) {
        lines.push(cols.map(function (c) { return csvCell(r[c]); }).join(','));
      });
      // CRLF — friendliest for Excel on Windows.
      return lines.join('\r\n');
    }

    function download(filename, text) {
      var blob = new Blob([text], { type: 'text/csv;charset=utf-8;' });
      var url = URL.createObjectURL(blob);
      var a = document.createElement('a');
      a.href = url; a.download = filename;
      document.body.appendChild(a); a.click(); document.body.removeChild(a);
      URL.revokeObjectURL(url);
    }

    function renderSummary(kind, data) {
      var fc = data.flag_counts || {};
      var bits = Object.keys(fc).sort().map(function (k) { return k + ': ' + fc[k]; });
      var extra = '';
      if (kind === 'ingredients' && data.median_price_per_liter != null) {
        extra = ' — median price/liter $' + data.median_price_per_liter;
      }
      summaryEl.innerHTML = '<p><strong>' + data.count + ' rows</strong>' + extra +
        '<br>Flags — ' + (bits.join(' | ') || 'none') +
        '<br><span style="color:#888">generated ' + (data.generated_at || '') + ' · trace ' + (data.trace_id || '') + ' · cache ' + (data._cache || '') + '</span></p>';
    }

    function renderPreview(cols, rows) {
      var flagIdx = cols.indexOf('flag');
      var html = '<table class="widefat striped"><thead><tr>';
      cols.forEach(function (c) { html += '<th>' + c + '</th>'; });
      html += '</tr></thead><tbody>';
      rows.forEach(function (r) {
        var flag = r[cols[flagIdx]];
        var color = (flag && flag !== 'ok') ? ' style="background:#fff3cd"' : '';
        html += '<tr' + color + '>';
        cols.forEach(function (c) {
          var v = r[c];
          if (v === null || v === undefined) v = '';
          html += '<td>' + String(v).replace(/&/g,'&amp;').replace(/</g,'&lt;') + '</td>';
        });
        html += '</tr>';
      });
      html += '</tbody></table>';
      previewEl.innerHTML = html;
    }

    function run(kind, dlBtn) {
      setStatus('Running ' + kind + ' audit…');
      summaryEl.innerHTML = '';
      previewEl.innerHTML = '';
      dlBtn.disabled = true;

      fetch(BASE + '/' + kind + '?nocache=1', {
        method: 'GET',
        credentials: 'same-origin',
        headers: { 'X-WP-Nonce': NONCE, 'Accept': 'application/json' }
      })
      .then(function (res) { return res.json().then(function (j) { return { ok: res.ok, body: j }; }); })
      .then(function (out) {
        if (!out.ok || !out.body || out.body.ok === false) {
          setStatus('Error: ' + ((out.body && out.body.error) || ('HTTP ' + (out.body && out.body.trace_id ? '(' + out.body.trace_id + ')' : '')) || 'request failed'));
          return;
        }
        var data = out.body;
        var cols = COLS[kind];
        var rows = data.rows || [];
        lastCsv[kind] = toCsv(cols, rows);
        renderSummary(kind, data);
        renderPreview(cols, rows);
        dlBtn.disabled = false;
        setStatus('Done. ' + rows.length + ' rows. Use the Download button to save the CSV.');
      })
      .catch(function (err) { setStatus('Request failed: ' + err); });
    }

    document.getElementById('scoop-audit-run-ing').addEventListener('click', function () {
      run('ingredients', document.getElementById('scoop-audit-dl-ing'));
    });
    document.getElementById('scoop-audit-run-flav').addEventListener('click', function () {
      run('flavors', document.getElementById('scoop-audit-dl-flav'));
    });
    document.getElementById('scoop-audit-dl-ing').addEventListener('click', function () {
      if (lastCsv.ingredients) download('ingredient-price-audit.csv', lastCsv.ingredients);
    });
    document.getElementById('scoop-audit-dl-flav').addEventListener('click', function () {
      if (lastCsv.flavors) download('flavor-cost-audit.csv', lastCsv.flavors);
    });
  })();
  </script>
  <?php
}
