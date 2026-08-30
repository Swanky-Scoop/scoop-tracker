// THROWAWAY in-box dry-run stub for debt-wanted-readonly.spec.js — mimics
// the minimum of ops.swanky.local the spec touches. NOT committed (mirror
// of the debt-wanted-edit dry-run method). Serves:
//   /wp-login.php          GET form / POST sets session cookie
//   /dock/                 grid-host page (real-model-shaped DOM mimic)
//   /wp-json/scoop/v1/bundle?types=Debt&force_bust=1
//   /wp-json/scoop/v1/debt-requests   200 for admin, 403 rest_forbidden for low
const http = require('node:http');

const PORT = 8899;
const NONCE = 'dryrun-nonce-1';
const sessions = new Map(); // cookie -> 'admin' | 'low'
let cookieSeq = 0;
const flavorRequest = new Map(); // pairKey -> wanted  (single fixture pair)

const ROW_ID = 102 * 100000 + 600; // Mountlake Terrace(102) x fixture flavor(600)

function sessionOf(req) {
  const m = /(?:^|;\s*)scoop_session=([^;]+)/.exec(req.headers.cookie || '');
  return m ? sessions.get(m[1]) || null : null;
}

const dockHtml = (role) => `<!doctype html><html><body>
<div class="scoop-grid" data-grid-type="Debt"><table><tbody>
  <tr class="group" data-row-id="102"><th class="groupCell"><b>Mountlake Terrace</b></th></tr>
  <tr class="row" data-row-id="${ROW_ID}">
    <td class="col-flavor">zz__flavor debt test___</td>
    <td class="col-demand" id="demand-cell"></td>
    <td class="col-status">fillable</td>
  </tr>
</tbody></table></div>
<script>
  window.SCOOP = {
    nonce: ${JSON.stringify(NONCE)},
    metaData: { Debt: { canPost: ${role === 'admin'} } },
  };
  // Boot mimic: fetch the bundle once, expose the shape the spec's
  // waitForFunction polls, then render the demand cell EXACTLY the way
  // _renderFieldValue's two branches do (writeable -> TextIt hidden+number
  // inputs; read-only -> plain text + .read-only). Rendering logic copied
  // from the real renderer's branch so the dry-run exercises the spec's
  // selectors against faithful structure.
  (async () => {
    const res = await fetch('/wp-json/scoop/v1/bundle?types=Debt&force_bust=1', {
      headers: { 'X-WP-Nonce': window.SCOOP.nonce }, credentials: 'include',
    });
    const body = await res.json();
    window._domain = body.data;
    const host = document.querySelector('.scoop-grid[data-grid-type="Debt"]');
    host._dockListInstance = {
      TOGGLE: { click: () => host.classList.add('toggled') },
      api: { getDomainSnapshot: () => window._domain },
    };
    const canPost = window.SCOOP.metaData?.Debt?.canPost ?? true;
    const row = window._domain.flavor_request.find((r) => Number(r.location) * 100000 + Number(r.flavor) === ${ROW_ID});
    if (row) {
      const cell = document.getElementById('demand-cell');
      if (canPost) {
        cell.innerHTML = '';
        const hid = document.createElement('input');
        hid.type = 'hidden'; hid.name = 'Debt[cells][${ROW_ID}][demand]'; hid.value = String(row.wanted);
        const num = document.createElement('input');
        num.type = 'number'; num.value = String(row.wanted);
        cell.append(hid, num);
        cell.classList.remove('read-only');
      } else {
        cell.textContent = String(row.wanted);
        cell.classList.add('read-only');
      }
    }
  })();
</script>
</body></html>`;

const loginHtml = `<!doctype html><html><body><form method="post" action="/wp-login.php">
<input id="user_login" name="log"><input id="user_pass" name="pwd" type="password">
<button type="submit">Log In</button></form></body></html>`;

function bundleBody(role) {
  const flavorRequestRows = [...flavorRequest.entries()].map(([k, wanted]) => {
    const loc = Math.floor(Number(k) / 100000);
    const flv = Number(k) % 100000;
    return { id: k, location: loc, flavor: flv, wanted };
  });
  return {
    ok: true,
    data: {
      flavor: [{ id: 600, _title: 'zz__flavor debt test___' }],
      location: [{ id: 102, _title: 'Mountlake Terrace' }],
      use: [],
      slot: [],
      tub: [],
      flavor_request: flavorRequestRows,
    },
  };
}

function send(res, code, body, type = 'application/json') {
  res.writeHead(code, { 'Content-Type': type });
  res.end(typeof body === 'string' ? body : JSON.stringify(body));
}

const server = http.createServer((req, res) => {
  const url = new URL(req.url, `http://127.0.0.1:${PORT}`);
  const role = sessionOf(req);

  if (url.pathname === '/wp-login.php') {
    if (req.method === 'GET') return send(res, 200, loginHtml, 'text/html');
    let raw = '';
    req.on('data', (c) => (raw += c));
    req.on('end', () => {
      const p = new URLSearchParams(raw);
      const user = p.get('log');
      const pass = p.get('pwd') || '';
      const isUser =
        (user === process.env.SCOOP_TEST_USER && pass === process.env.SCOOP_TEST_PASS) ||
        (user === process.env.SCOOP_TEST_USER_2 && pass === process.env.SCOOP_TEST_PASS_2);
      if (!isUser) return send(res, 200, loginHtml.replace('</form>', '<div>bad</div></form>'), 'text/html');
      const cookie = `c${++cookieSeq}`;
      sessions.set(cookie, user === process.env.SCOOP_TEST_USER_2 ? 'low' : 'admin');
      res.writeHead(302, {
        'Set-Cookie': `scoop_session=${cookie}; Path=/`,
        Location: '/dock/',
      });
      res.end();
    });
    return;
  }

  if (url.pathname === '/dock/') {
    if (!role) return send(res, 302, '', 'text/html');
    return send(res, 200, dockHtml(role), 'text/html');
  }

  if (url.pathname === '/wp-json/scoop/v1/bundle') {
    if (req.headers['x-wp-nonce'] !== NONCE) return send(res, 403, { code: 'rest_forbidden', message: 'bad nonce', data: { status: 403 } });
    if (!role) return send(res, 401, { code: 'rest_forbidden', message: 'not logged in', data: { status: 401 } });
    return send(res, 200, bundleBody(role));
  }

  if (url.pathname === '/wp-json/scoop/v1/debt-requests') {
    if (req.headers['x-wp-nonce'] !== NONCE) return send(res, 403, { code: 'rest_forbidden', message: 'bad nonce', data: { status: 403 } });
    if (!role) return send(res, 401, { code: 'rest_forbidden', message: 'not logged in', data: { status: 401 } });
    let raw = '';
    req.on('data', (c) => (raw += c));
    req.on('end', () => {
      // The REAL gate: permission_callback runs BEFORE the handler. Mirror
      // it exactly — low-privilege write is refused before any store change.
      if (role !== 'admin') {
        return send(res, 403, { code: 'rest_forbidden', message: 'Sorry, you are not allowed to do that.', data: { status: 403 } });
      }
      let payload;
      try { payload = JSON.parse(raw); } catch { return send(res, 400, { ok: false, error: 'bad json' }); }
      const cells = payload?.Debt?.cells;
      if (!cells || typeof cells !== 'object') return send(res, 400, { ok: false, error: 'Missing or invalid Debt payload.' });
      const updated = {};
      for (const [k, cell] of Object.entries(cells)) {
        const wanted = Number(cell?.wanted ?? cell?.demand);
        if (!Number.isFinite(wanted)) return send(res, 400, { ok: false, error: "missing 'wanted'" });
        if (wanted === 0) { flavorRequest.delete(k); updated[k] = { wanted: 0 }; }
        else { flavorRequest.set(k, wanted); updated[k] = { wanted }; }
      }
      return send(res, 200, { ok: true, author: 'admin', updated, errors: [] });
    });
    return;
  }

  send(res, 404, { error: 'not found', url: req.url });
});

server.listen(PORT, '127.0.0.1', () => console.log(`stub listening on http://127.0.0.1:${PORT}`));
