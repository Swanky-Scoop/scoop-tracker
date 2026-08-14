import Grid               from "../ui/grid.js";
import Tile                from "../ui/tile.js";
import PageStatus          from "../ui/page-status.js";
import Toast               from "../ui/toast.js";
import ColumnsProvider    from "../models/_column-provider.js";
import FormCodec          from "./form-codec.js";
import CabinetGridModel      from "../models/cabinet-grid-model.js";
import BatchGridModel        from "../models/batch-grid-model.js";
import BatchHistoryGridModel from "../models/batch-history-grid-model.js";
import CloseoutGridModel     from "../models/closeout-grid-model.js";
import FlavorTubGridModel    from "../models/flavor-tub-grid-model.js";
import DateActivityGridModel from "../models/date-activity-grid-model.js";
import AnalyticsGridModel    from "../models/analytics-grid-model.js";
import PopularGridModel      from "../models/popular-grid-model.js";
import PopularPlot           from "../ui/popular-plot.js";
import FlavorsGridModel      from "../models/flavors-grid-model.js";
import InstockFlavorGridModel from "../models/instock-flavor-grid-model.js";
import CabinetWorkflowGridModel from "../models/cabinet-workflow-grid-model.js";
import CabinetWorkflowTile      from "../ui/cabinet-workflow-tile.js";
import ItemPivotGridModel       from "../models/item-pivot-grid-model.js";
import ItemPivotGrid            from "../ui/item-pivot-grid.js";
import ShiftReportGridModel     from "../models/shift-report-grid-model.js";
import ShiftReportForm          from "../ui/shift-report-form.js";
import HashState                from "./hash-state.js";

// Some grid types run visibly heavier cold-cache queries than the rest of
// the bundle (see bundle-fetch.php's date-filter/inventory_change handling
// for DateActivity/BatchHistory) — give those a larger cache-bust countdown
// default before this page has built up its own real 'miss' history. Only
// matters pre-history; PageStatus.beginLoadTiming() prefers real history
// the moment any exists.
const ETA_DEFAULT_BUST_MS = 15000;
const ETA_TYPE_DEFAULT_BUST_MS = {
  DateActivity: 25000,
  BatchHistory: 25000,
};


export default class ScoopAPI {
  constructor({ nonce, base = "/", routes = {}, metaData = null, user = null } = {}) {
    // Core config
    this.nonce     = nonce ?? null;
    this.baseUrl   = this._absUrl(base);
    this.routes    = this._normalizeRoutes(routes);
    this.metaData  = metaData;
    this.Meta      = new ColumnsProvider(metaData);
    this.user      = user;

    // Grid/page state
    this.gridTypes = new Set();
    this.typesKey  = "";
    this._pageTypes = [];
    this.bundleUrl = new URL(this.baseUrl);
    this._bundleGrids = [];
    this._bundleFilterParams = {};

    // Domain state
    this._hosts    = null;
    this._domain   = null;
    this._domainInflight = null;
    this._lastBundleCacheStatus = null; // 'hit'|'miss' from the last bundle fetch's transient cache

    // Request control
    this.controller = new AbortController();

    // #bust in the URL hash (e.g. https://.../page/#bust) forces every fetch
    // this mount makes to skip the server's transient cache read (still
    // writes fresh data back — see bundle.php/analytics.php's force_bust
    // handling) — a manual way to exercise the cache-bust path (and its ETA
    // countdown, see page-status.js) without having to save a record first.
    this._forceCacheBust = this._hashForcesBust();
  }

  _hashForcesBust() {
    return HashState.has('bust');
  }

  // Stable per-host identity for the location hash's per-control tier
  // (#loc.<id>=...) — data-grid-type alone collides when the same type
  // appears twice on one page (see DOCKING.md's "State model"); an author
  // can break that tie with the shortcode's optional `slug` attribute
  // (data-slug). Most pages have one host per type and never need it.
  _controlId(dom) {
    const type = dom?.dataset?.gridType ?? '';
    const slug = dom?.dataset?.slug;
    return slug ? `${type}@${slug}` : type;
  }

  // Location cascade for a grid/tile host, highest priority first:
  //   1. #loc.<controlId>=  — per-control hash override (incl. a future
  //      in-GUI location picker writing back via HashState.set())
  //   2. #location=         — page-wide hash override
  //   3. data-location      — the shortcode's own explicit `location=` attr
  //   4. data-default-location on the nearest .in-dock ancestor —
  //      [scoop_dock location="..."]'s default
  //   5. 935 (Woodinville) — hardcoded fallback
  // "Code sets initial state, but hash overrides": PHP-rendered attrs (3/4)
  // are the page's authored defaults; any hash present (1/2) wins over them.
  _resolveLocation(dom) {
    const controlHash = HashState.get(`loc.${this._controlId(dom)}`);
    if (controlHash != null) return Number(controlHash);

    const pageHash = HashState.get('location');
    if (pageHash != null) return Number(pageHash);

    if (dom?.dataset?.location) return Number(dom.dataset.location);

    const dockDefault = dom?.closest?.('.in-dock')?.dataset?.defaultLocation;
    if (dockDefault) return Number(dockDefault);

    return 935;
  }

  _normalizeRoutes(routes = {}) {
    const out = {};
    for (const [k, v] of Object.entries(routes)) out[k] = this._absUrl(v);
    return out;
  }


  abort() { this.controller.abort(); }

  getTypesFromGridHosts(root = document) {
    this._hosts = [...root.querySelectorAll(".scoop-grid[data-grid-type]")];
    if(!this._hosts.length) return false;

    this.gridTypes = new Set();
    for (const node of this._hosts) {
      const t = node.dataset.gridType;
      if (t) this.gridTypes.add(t);
    }
    this._setPageTypes();

    return true;
  }

  _absUrl(pathOrUrl) {
    if (pathOrUrl instanceof URL) return pathOrUrl;
    if (!pathOrUrl) return new URL(window.location.origin);
    try { return new URL(pathOrUrl); }
    catch { return new URL(pathOrUrl, window.location.origin); }
  }

  getModelsBom() {
    return {
      "Cabinet"      : CabinetGridModel,
      "FlavorTub"    : FlavorTubGridModel,
      "Batch"        : BatchGridModel,
      "BatchHistory" : BatchHistoryGridModel,
      "Closeout"     : CloseoutGridModel,
      "DateActivity" : DateActivityGridModel,
      "Analytics"    : AnalyticsGridModel,
      "Popular"      : PopularGridModel,
      "Flavors"      : FlavorsGridModel,
      "InstockFlavor": InstockFlavorGridModel,
      "CabinetWorkflow": CabinetWorkflowGridModel,
      "ItemPivot"      : ItemPivotGridModel,
      "ShiftReport"    : ShiftReportGridModel,
    };
  }

  // Per-type View class overrides — checked before the generic data-view
  // ("grid"/"tile") switch below. CabinetWorkflow's markup (see
  // cabinet-workflow-tile.js) doesn't fit Tile's column-driven rendering, so
  // it gets its own Tile subclass rather than adding type-specific branches
  // to the shared tile.js.
  getViewOverrides() {
    return {
      "CabinetWorkflow": CabinetWorkflowTile,
      "ItemPivot"      : ItemPivotGrid,
      "ShiftReport"    : ShiftReportForm,
    };
  }

  route(name) {
    const u = this.routes?.[name];
    if (!u) throw new Error(`ScoopAPI.route("${name}") missing`);
    return u;
  }

  _absUrlWithBust(url) {
    const u = (url instanceof URL) ? new URL(url.toString()) : new URL(this._absUrl(url));
    u.searchParams.set("_ts", String(Date.now()));
    return u;
  }

  async _fetch(url, { method="GET", headers={}, body=null, useNonce=true } = {}) {
    const u0 = (url instanceof URL) ? url : this._absUrl(url);
    const u = (method === "GET") ? this._absUrlWithBust(u0) : ((u0 instanceof URL) ? u0 : this._absUrl(u0));
    const res = await fetch(u, {
      method,
      credentials: "include",
      signal: this.controller.signal,
      cache: (method === "GET") ? "no-store" : "default",
      headers: {
        Accept: "application/json",
        ...(method === "GET" ? { "Cache-Control": "no-cache" } : {}),
        ...headers,
        ...(useNonce && this.nonce ? { "X-WP-Nonce": this.nonce } : {}),
      },
      body,
    });
    const text = await res.text().catch(() => "");
    let data = null;
    try { data = text ? JSON.parse(text) : null; }
    catch { data = { ok: false, error: "Non-JSON response", raw: text }; }

    // WP's cookie-auth check runs before any route's own permission_callback
    // and fails with this specific code whenever the session cookie or its
    // nonce is no longer valid — expired session, logged out in another tab,
    // etc. That's distinct from a logged-in user just lacking permission for
    // a route (which fails its own permission_callback instead, with a
    // different code) — only THIS code means "not really logged in anymore",
    // so it's the one safe to treat as a session timeout and bounce to login.
    if (data?.code === 'rest_cookie_invalid_nonce') {
      this._redirectToLogin();
    }

    return { ok: res.ok, status: res.status, data, res };
  }

  // Shared by the reactive 401/expired-nonce check in _fetch above and the
  // proactive 6h idle-logout below. Guarded so a burst of requests that all
  // fail at once (e.g. autosave + a background refresh landing together)
  // only navigates once instead of racing multiple redirects.
  _redirectToLogin() {
    if (this._redirectingToLogin) return;
    this._redirectingToLogin = true;

    // Built from the browser's actual origin, not a server-computed
    // home_url() — WP's siteurl/home options can drift from the real host
    // (e.g. a Local site cloned from prod without remapping URLs), which
    // would otherwise bounce this tab to the wrong environment entirely.
    location.href = `${window.location.origin}/wp-login.php?redirect_to=${encodeURIComponent(window.location.href)}`;
  }

  async getJson(url = this.baseUrl) {
    const r = await this._fetch(url, { method: "GET" });
    return r.data;
  }


  // --- WRITES  ---
  async postJson(payload, type = "", { useNonce = true } = {}) {
    const url = this.route(type);
    const bodyObj = { [type]: payload }; // <-- THE RULE

    const r = await this._fetch(url, {
      method: "POST",
      useNonce,
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(bodyObj),
    });

    return { ok: r.ok, status: r.status, data: r.data };
  }

  // Batch delete — DELETE /scoop/v1/batches/{id} (see scoop_handle_batch_delete
  // in includes/rest.php). Sibling of the Batch create route (this.route('Batch')
  // is the base "/batches" URL), not its own entry in SCOOP.routes, since the
  // server registers it as one fixed path per type (see includes/_routes.php).
  async deleteBatch(id) {
    const url = new URL(this.route('Batch').toString());
    url.pathname = url.pathname.replace(/\/?$/, `/${id}`);

    const r = await this._fetch(url, { method: 'DELETE' });
    return { ok: r.ok, status: r.status, data: r.data };
  }

  // --- BUNDLE LOADING ---

  _setPageTypes() {
    this._pageTypes = [...(this.gridTypes ?? [])].map(String).filter(Boolean);
    this.typesKey = this._typesKey(this._pageTypes);
    this.bundleUrl = this._bundleUrlForTypes(this._pageTypes); // URL object
  }


  _typesKey(types = this.gridTypes) {
    const source = types instanceof Set ? [...types] : (Array.isArray(types) ? types : [...(this.gridTypes ?? [])]);
    const arr = source.map(String).filter(Boolean);
    arr.sort();
    return arr.join(",");
  }

  _bundleUrlForTypes(types = this._pageTypes) {
    const base = new URL(this.route("Bundle").toString());
    const source = types instanceof Set ? [...types] : (Array.isArray(types) ? types : [...(this.gridTypes ?? [])]);
    const key = this._typesKey(source);
    base.searchParams.set("types", key);
    if (source.includes('DateActivity')) {
      base.searchParams.set('include_empty_tubs', '1');
    }
    for (const [param, value] of Object.entries(this._bundleFilterParams ?? {})) {
      if (value != null && value !== '') base.searchParams.set(param, String(value));
    }
    if (this._forceCacheBust) {
      base.searchParams.set('force_bust', '1');
    }
    return base;
  }

  // Public wrapper so a caller outside this class (e.g. List._handleRowDelete
  // in _list.js, showing feedback the instant a delete is confirmed, before
  // the DELETE request itself — let alone the bundle refetch that follows it
  // — has even started) can start the same ETA countdown _startDomainFetch
  // uses, rather than leaving the countdown dark for however long that
  // earlier phase takes. Calling this again later, once _startDomainFetch's
  // own real fetch begins, is expected and harmless — beginLoadTiming()
  // just restarts the clock at that point, which only costs the countdown
  // reflecting the DELETE phase's own duration, not the fact that a timer
  // was showing at all.
  // `types` defaults to the full page union (initial load, or any caller
  // that hasn't been scoped) — a scoped triggered refresh passes its own
  // narrower list so its naturally-faster duration doesn't get averaged
  // into the same ETA history bucket as full-page loads (see
  // scopedRefreshTypes/_startDomainFetch below), which would corrupt the
  // countdown estimate for both.
  beginLoadTiming(types = this._pageTypes) {
    PageStatus.beginLoadTiming(`${window.location.pathname}::${this._typesKey(types)}`, this._defaultBustMsForPage(types));
  }

  _defaultBustMsForPage(types = this._pageTypes) {
    let ms = ETA_DEFAULT_BUST_MS;
    for (const type of types ?? []) {
      if (ETA_TYPE_DEFAULT_BUST_MS[type] != null) {
        ms = Math.max(ms, ETA_TYPE_DEFAULT_BUST_MS[type]);
      }
    }
    return ms;
  }

  // Which on-page bundle types could plausibly need fresher data because
  // `triggerType` just wrote to a pod — used to scope a triggered refresh
  // (autosave, manual Save) instead of always refetching every type on the
  // page. Always includes triggerType itself (it needs its own fresh
  // server-computed decorations, e.g. alertCase/badges, even though its own
  // edit was already applied optimistically client-side).
  //
  // Falls back to the FULL `_pageTypes` set — never a narrower guess —
  // whenever triggerType is missing from SCOOP.refreshScope or has no
  // writesPod (e.g. a read-only type, or one like CabinetWorkflow that
  // writes through a different type's route entirely and so never appears
  // here as a trigger identity). Under-scoping silently is worse than one
  // extra fetch. See PERFORMANCE-REFACTOR.md item #2.
  scopedRefreshTypes(triggerType) {
    const scope = SCOOP?.refreshScope ?? {};
    const entry = scope[triggerType];
    const writesPod = entry?.writesPod;

    if (!entry || !writesPod) return this._pageTypes;

    const out = new Set([triggerType]);
    for (const type of this._pageTypes) {
      if (type === triggerType) continue;
      const needs = scope[type]?.needs;
      if (Array.isArray(needs) && needs.includes(writesPod)) out.add(type);
    }

    return [...out];
  }

  _columnsForGridType(name) {
    const entityMap = {
      Cabinet: "slot",
      FlavorTub: "tub",
      Batch: "batch",
      Closeout: "closeout",
    };

    const entity = entityMap[name] || name;
    const meta = SCOOP.metaData?.[entity];

    if (!meta) return [];

    // meta is assumed to be { fieldKey: fieldDef, ... }
    return Object.entries(meta)
      .map(([key, def]) => ({
        key,
        ...def,
      }))
      .filter(col => col.visible !== false);
  }

  async userHelper(scoop){
    const u = this.user;
    if(!u) {
      document.documentElement.classList.add('SCOOP_NO_USER');
      return false;
    }
    if(! u.roles.includes('administrator')){
      document.documentElement.classList.remove('logged-in','admin-bar');
      document.documentElement.style.setProperty("margin-top", "0px", "important");
      document.getElementById('wpadminbar').remove();
    }

    document.documentElement.classList.add(...(u.roles));
    return true;

  }

  // Returns full bundle JSON: { ok, types, needs, data }. No client-side
  // caching here — every caller (see refreshPageDomain) always fetches with
  // force:true, so a cache keyed on types+filters was write-only dead
  // weight; the server's own transient cache (keyed by cache_version +
  // params, see includes/_cache.php) is the layer that actually absorbs
  // repeat-request cost — see CLAUDE.md's note not to "fix" this pattern
  // client-side.
  async getBundleForTypes(types = this._pageTypes) {
    const key = this._typesKey(types);
    if (!key) throw new Error("getBundleForTypes: no types");

    const url = this._bundleUrlForTypes(types);
    const bundle = await this.getJson(url);

    // Only default/keep entity keys the server actually fetched for THIS
    // request — bundle.needs (includes/bundle.php's $body['needs']) is
    // already scoped to exactly the entities the requested `types` union
    // needs, nothing more. Force-defaulting a fixed list of entity keys
    // here (the old behavior) was harmless when every fetch requested the
    // full page union, but is wrong for a scoped fetch: it would fabricate
    // empty arrays for entities that simply weren't requested, and
    // _startDomainFetch's merge would then read that as "now empty"
    // instead of "not part of this fetch", wiping out real data for every
    // OTHER grid relying on that entity. See PERFORMANCE-REFACTOR.md #2.
    const data  = bundle?.data ?? {};
    const needs = Array.isArray(bundle?.needs) ? bundle.needs : Object.keys(data);

    const out = {};
    for (const entityKey of needs) {
      out[entityKey] = Array.isArray(data[entityKey]) ? data[entityKey] : (data[entityKey] ?? []);
    }
    // Anything else the server included that wasn't listed in `needs`
    // still passes through untouched (shouldn't happen given bundle.php's
    // own loop, but avoids silently dropping data if that ever changes).
    for (const [k, v] of Object.entries(data)) {
      if (!(k in out)) out[k] = v;
    }

    bundle.data = {
      ...out,
      _date_filters: bundle?.date_filters ?? data._date_filters ?? {},
    };

    return bundle;
  }

  // The models expect "domain" = the data object with arrays:
  // { cabinet, slot, tub, flavor, location, use, ... }
  // `types`, when passed, scopes this one fetch to a narrower set than the
  // full page union (see scopedRefreshTypes) — omit it (or pass null/[]) to
  // get today's full-page-union behavior, which is what the initial mount
  // and any not-yet-scoped caller still does.
  async refreshPageDomain({ force = false, toast = null, info = null, types = null } = {}) {

    if (!this.gridTypes) throw new Error("refreshPageDomain: page types not set");
    if (!force && this._domain) return this._domain;

    if (this._domainInflight) {
      // A fetch is already in flight. A non-forced caller is happy to just
      // wait on it — same outcome as any other unforced call landing right
      // now. A forced caller needs genuinely fresh data, though: handing it
      // the in-flight promise risks resolving to a snapshot taken BEFORE
      // whatever this caller just wrote (e.g. a delete confirming its own
      // write landed while an earlier, unrelated refresh — another grid's
      // save, a cabinet-workflow action — was still resolving; see
      // PARTIAL-REFRESH.md). Chain a genuine follow-up fetch onto the
      // in-flight one instead of just returning it: the earlier fetch still
      // resolves and updates state normally, then this one runs and
      // re-updates with truly current data. `.catch(() => {})` lets the
      // chain proceed to the forced fetch even if the earlier one failed
      // (already reported via its own Toast) — one bad fetch shouldn't
      // block the next.
      if (!force) return this._domainInflight;
      this._domainInflight = this._domainInflight.catch(() => {}).then(() => this._startDomainFetch(info, types));
      return this._domainInflight;
    }

    return this._startDomainFetch(info, types);
  }

  // The actual fetch — factored out of refreshPageDomain() so a forced call
  // arriving mid-flight (see above) can chain a real second run of this
  // instead of reusing the first one's promise.
  _startDomainFetch(info, types = null) {
    // An empty/omitted types list means "full page union" — the initial
    // mount's call and any caller not yet scoped to a specific trigger.
    const fetchTypes = (Array.isArray(types) && types.length) ? types : this._pageTypes;
    const fetchSet = new Set(fetchTypes);

    // Only the grids actually part of THIS fetch flip to 'fetching' — an
    // out-of-scope grid's last real state (fresh/stale) is still accurate,
    // nothing about its data is changing (see the merge below). Each grid
    // reports its own 'fresh'/'stale' back once the fetch resolves (see
    // List.init/refresh/_onDomainUpdated in _list.js). info.name identifies
    // which grid's action (Save submit, autosave, filter change) caused
    // this refresh — absent only for the initial page-load call.
    this._bundleGrids.forEach(g => {
      if (g.pageStatusId && fetchSet.has(g.name)) PageStatus.setState(g.pageStatusId, 'fetching');
    });
    PageStatus.setTrigger(info?.name ?? 'page load');

    // ETA/countdown for this fetch specifically — called here (not just once
    // from mountAllGrids) so it shows up for every real bundle fetch: the
    // initial load, a Save submit, autosave's background refresh, or a
    // filter change. Keyed on fetchTypes (not always the page-wide
    // typesKey) so a scoped fetch's naturally-faster duration tracks its
    // own history bucket instead of corrupting the full-page one.
    this.beginLoadTiming(fetchTypes);

    this._domainInflight = (async () => {
      try {
        const bundle = await this.getBundleForTypes(fetchTypes);
        const incoming = bundle?.data ?? {};

        // MERGE, not replace: keep every previously-known entity key, only
        // overwrite keys THIS fetch actually asked for (getBundleForTypes
        // no longer fabricates [] for anything else — see that method). A
        // scoped fetch that only returns {tub,flavor,slot} must leave
        // location/use/batch/closeout/inventory_change exactly as they were.
        this._domain = { ...(this._domain ?? {}), ...incoming };
        this._lastBundleCacheStatus = bundle?._cache ?? null;
        PageStatus.completeLoadTiming(this._lastBundleCacheStatus);

        document.dispatchEvent(new CustomEvent("ts:domain:updated", {
          detail: { types: fetchTypes, ts: Date.now() }
        }));
        /*
        if(toast) toast.update(toast, {
          title:"Data Reloaded",
          message:(info)? 'Triggered by ' + info.name : ''
        });*/
        document.body.classList.remove('TS_GRID-UPDATING');

        return this._domain;
      } catch (err) {
        // Without this catch, a fetch that rejects (dropped connection,
        // server-side timeout on a heavy bundle) leaves _domainInflight
        // holding a rejected promise forever — every later refreshPageDomain
        // call (line ~351 above) just returns that same dead promise instead
        // of ever trying again, silently, for the rest of the page's life.
        // Falling back to 'stale' (not leaving grids stuck on 'fetching')
        // lets the next save/filter-change/manual-refresh retry normally.
        this._bundleGrids.forEach(g => {
          if (g.pageStatusId && fetchSet.has(g.name)) PageStatus.setState(g.pageStatusId, 'stale');
        });
        document.body.classList.remove('TS_GRID-UPDATING');
        Toast.addMessage({
          title: 'Data load failed',
          message: `Couldn't refresh ${this._typesKey(fetchTypes) || 'this page'} — ${err?.message ?? err}. Try again or reload the page.`,
        });
        throw err;
      } finally {
        this._domainInflight = null;
      }
    })();

    return this._domainInflight;
  }

  getDomainSnapshot() {
    return this._domain ?? {};
  }

  // True if any grid on the page has typed-but-not-yet-flushed input or an
  // autosave POST in flight. Used to gate the stale-tab reload below — never
  // force-reload out from under a mid-edit field.
  hasUnsavedEdits() {
    return this._bundleGrids.some(g => g.dirtySet?.size || g._autosaving);
  }

  // A tab left open across a deploy keeps running the old app.js forever —
  // there's no build/CDN cache to expire it. Poll the server's app.js mtime
  // (SCOOP.version at load time is the baseline) and reload once it changes,
  // but only when nothing on the page is mid-edit; otherwise recheck next tick.
  watchForStaleVersion(baseline, { intervalMs = 20 * 60 * 1000 } = {}) {
    if (!baseline) return;

    setInterval(async () => {
      let current;
      try {
        const r = await this.getJson(this.route("Version"));
        current = r?.version;
      } catch (err) {
        console.error("watchForStaleVersion: check failed", err);
        return;
      }

      if (!current || current === baseline) return;
      if (this.hasUnsavedEdits()) return; // try again next tick

      // A plain location.reload() re-requests this exact URL — if the HTML
      // itself is served from cache (browser disk cache, an intermediate
      // cache, a caching plugin) rather than hitting the server, the
      // reloaded page can still carry the OLD app.js <script> tag (enqueue.php
      // already versions that tag's URL via filemtime, so a genuine
      // server-rendered page always points at the current file — this only
      // guards against not reaching the server at all). Appending a
      // cache-busting param makes this a URL the cache has never seen, so
      // reload is forced to go all the way to the server.
      const url = new URL(location.href);
      url.searchParams.set('_ts', String(Date.now()));
      location.href = url.toString();
    }, intervalMs);
  }

  // Shared "is anyone actually at the keyboard" signal, driven by real
  // mousemove/keydown/click, NOT by background traffic like
  // watchForStaleVersion's poll or autosave — those fire on their own timers
  // regardless of whether she's at the keyboard, so counting them as
  // "activity" would defeat any idle timeout built on top of this. Both
  // watchForIdleTimeout and watchForInventoryChangeFlush share one tracker
  // rather than each wiring their own document listeners.
  _trackRealActivity() {
    if (this._lastActivity != null) return;
    this._lastActivity = Date.now();
    const bump = () => { this._lastActivity = Date.now(); };
    ['mousemove', 'keydown', 'click', 'scroll', 'touchstart'].forEach(evt =>
      document.addEventListener(evt, bump, { passive: true })
    );
  }

  // Forces a real re-login after N hours of no genuine interaction — a tab
  // left open overnight shouldn't stay authenticated forever.
  watchForIdleTimeout({ idleMs = 6 * 60 * 60 * 1000, checkIntervalMs = 60 * 1000 } = {}) {
    this._trackRealActivity();

    setInterval(async () => {
      if (Date.now() - this._lastActivity < idleMs) return;
      if (this.hasUnsavedEdits()) return; // autosave hasn't flushed yet, recheck next tick

      try {
        await this._fetch(this.route("IdleLogout"), { method: "POST" });
      } catch (err) {
        console.error("watchForIdleTimeout: logout call failed", err);
      }

      alert("You've been logged out after a period of inactivity. Please log back in.");
      this._redirectToLogin();
    }, checkIntervalMs);
  }

  // Rolls every 'update' write (tub/slot edits, autosaved or manual-submit
  // alike) made since the last flush into ONE inventory_change record —
  // see scoop_stage_inventory_change/scoop_flush_pending_inventory_change in
  // rest.php. Fires on the same real-activity signal as watchForIdleTimeout
  // but at a much shorter idle threshold, so a normal break in the day
  // writes the session's audit trail instead of waiting for the 6h logout.
  watchForInventoryChangeFlush({ idleMs = 60 * 60 * 1000, checkIntervalMs = 60 * 1000 } = {}) {
    this._trackRealActivity();
    let flushedThisIdleStretch = false;

    setInterval(async () => {
      const idleFor = Date.now() - this._lastActivity;

      if (idleFor < idleMs) {
        flushedThisIdleStretch = false; // she's back — arm for the next idle stretch
        return;
      }
      if (flushedThisIdleStretch) return; // already flushed, nothing new pending
      if (this.hasUnsavedEdits()) return; // autosave hasn't landed yet, recheck next tick

      flushedThisIdleStretch = true;
      try {
        await this._fetch(this.route("FlushInventoryChange"), { method: "POST" });
      } catch (err) {
        console.error("watchForInventoryChangeFlush: flush call failed", err);
      }
    }, checkIntervalMs);
  }

  _normalizeDateFilterKey(value) {
    return String(value ?? '')
      .trim()
      .toLowerCase()
      .replace(/-/g, '_')
      .replace(/[^a-z0-9_]/g, '');
  }

  _dateFiltersFromDataset(dom) {
    const raw = dom?.dataset?.dateFilters ?? '';
    const values = raw
      ? raw.split(',')
      : (dom?.dataset?.gridType === 'DateActivity' ? ['activity'] : []);

    const out = [];
    const seen = new Set();
    values.forEach(value => {
      const key = this._normalizeDateFilterKey(value);
      if (key && !seen.has(key)) {
        seen.add(key);
        out.push(key);
      }
    });

    return out;
  }

  _filterValuesFromDataset(dom, dateFilters = []) {
    const values = {};

    dateFilters.forEach(key => {
      const attr = `data-filter-${key.replace(/_/g, '-')}`;
      const value = dom.getAttribute(attr);
      if (value != null && value !== '') values[key] = value;
    });

    if (!values.activity && dom?.dataset?.modifiedRange) {
      values.activity = dom.dataset.modifiedRange;
    }

    return values;
  }

  _bundleFilterParamsForGrids(grids = this._bundleGrids) {
    const params = {};

    grids.forEach(grid => {
      const model = grid?.modelInstance;
      if (typeof model?.getServerFilterParams !== 'function') return;

      Object.assign(params, model.getServerFilterParams());
    });

    return params;
  }

  async refreshGridFilters(grid = null) {
    this._bundleFilterParams = this._bundleFilterParamsForGrids();
    document.body.classList.add('TS_GRID-UPDATING');
    // A filter change didn't write anything — only the one grid whose
    // filter changed needs a fresh fetch, unlike a save (which can affect
    // other on-page types too, see scopedRefreshTypes). No grid => no
    // scoping info, fall back to the full page union.
    const types = grid?.name ? [grid.name] : null;
    return this.refreshPageDomain({ force: true, info: { name: grid?.name ?? 'filters' }, types });
  }


  // Batch's `history` shortcode attribute (see includes/shortcode.php) embeds
  // a read-only BatchHistory listing right inside the Batch widget instead of
  // requiring a separate [scoop_grid type="BatchHistory"] shortcode. It gets
  // no host div and no dock toggle of its own — mounted against a throwaway
  // detached element, so dockToggle() (called by the caller, same as every
  // other grid) no-ops since that element is never inside an .in-dock
  // ancestor — then its <form> is moved to sit immediately after Batch's own
  // <form>, so it opens/closes together with Batch's own toggle instead of
  // needing one of its own. Safe to co-locate in Batch's host div because
  // List's delegated click listener is scoped to `this.FORM`, not
  // `this.target` — see the comment in _list.js's _bindEvents.
  _mountEmbeddedBatchHistory(batchDom, batchGrid, formCodec) {
    const location = this._resolveLocation(batchDom);
    const modelInstance = new BatchHistoryGridModel("BatchHistory", null, {
      location,
      metaData: SCOOP.metaData?.BatchHistory,
    });

    const grid = new Grid(document.createElement('div'), "BatchHistory", {
      api: this,
      modelInstance,
      formCodec,
      columns: modelInstance.columns,
      pageStatusId: `${batchDom.id}::history`,
    });

    grid.dockToggle?.();
    grid.FORM.classList.add('batch-history-embedded');
    batchGrid.FORM.after(grid.FORM);

    // Min/max toggle, placed right after Batch's own Save button — lets the
    // history receipt be tucked away without closing Batch's whole popup.
    // State lives as a class on the Batch host div (not on the embedded
    // form itself) so plain CSS drives the show/hide, and the state is
    // inspectable from outside this method without reaching into either
    // grid instance.
    const minMaxBtn = document.createElement('button');
    minMaxBtn.type = 'button';
    minMaxBtn.className = 'history-min-max';
    minMaxBtn.title = 'Toggle batch history';
    batchGrid.SUBMIT.after(minMaxBtn);

    const setHistoryOpen = (isOpen) => {
      batchDom.classList.toggle('history-open', isOpen);
      minMaxBtn.classList.toggle('active', isOpen);
    };

    // Unconditional toggle — works the same whether the list is currently
    // empty or populated, so an empty receipt can still be opened by hand.
    minMaxBtn.addEventListener('click', () => {
      setHistoryOpen(!batchDom.classList.contains('history-open'));
    });

    // Default open/closed once real data has actually loaded (grid.FORM's
    // 'empty' class — see List._applyAutosaveUI — is only meaningful after
    // this first init; ts:list:init fires once, right after that class is
    // set, never again on later refreshes — see List.init()).
    grid.FORM.addEventListener('ts:list:init', () => {
      setHistoryOpen(!grid.FORM.classList.contains('empty'));
    }, { once: true });

    // A freshly-created batch is exactly the thing this receipt exists to
    // show — reopen it even if the user had minimized it or it was
    // defaulted closed for having nothing to show yet.
    batchGrid.FORM.addEventListener('ts:list:saved', () => setHistoryOpen(true));

    return grid;
  }

  // --- MOUNTING ---
  //
  // Two phases, deliberately split:
  //   Phase 1 (sync, one pass over this._hosts in document order) builds
  //     every control's model+view — which also builds and docks its
  //     .gridToggle (see List.dockToggle()) — before any network request
  //     starts. This is what makes dock toolbar buttons (and the controls
  //     themselves, already in shortcode order via plain DOM order) appear
  //     immediately and in shortcode order, regardless of which type's data
  //     happens to resolve first (see DOCKING.md).
  //   Phase 2 (async) loads data into what Phase 1 already built: analytics-
  //     pattern types self-fetch one at a time (unchanged pacing from
  //     before), bundle types share the one refreshPageDomain() fetch.
  async mountAllGrids({ root = document, formCodec = FormCodec } = {}) {
    if (!this.getTypesFromGridHosts(root)) return [];

    // Register every shortcode host with PageStatus up front, before any
    // fetch (analytics self-fetch or the shared bundle fetch) starts — each
    // host already carries a stable id from shortcode.php. 'unknown' is the
    // literal starting state until its first fetch begins.
    this._hosts.forEach(dom => {
      const resolvedLocation = this._resolveLocation(dom);
      PageStatus.register(dom.id, {
        label: `${dom.dataset.gridType ?? 'grid'} (${resolvedLocation || 'no location'})`,
        type: dom.dataset.gridType ?? '',
        location: resolvedLocation || '',
      });

      // Batch's `history` shortcode attribute embeds a BatchHistory grid
      // with no host div of its own (see _mountEmbeddedBatchHistory) — give
      // it its own PageStatus entry anyway so its load state is still visible.
      if (dom.dataset.gridType === 'Batch' && dom.dataset.history) {
        PageStatus.register(`${dom.id}::history`, {
          label: `Batch History (${resolvedLocation || 'no location'})`,
          type: 'BatchHistory',
          location: resolvedLocation || '',
        });
      }
    });

    const analyticsTypes = new Set(["Analytics", "Popular", "Flavors"]);
    const modelsBom = this.getModelsBom();

    // getTypesFromGridHosts() stuffed every grid type into this.gridTypes,
    // including the analytics ones. The bundle endpoint 400s on unknown
    // types like "Popular", so re-scope to only the bundle hosts before
    // refreshPageDomain() (phase 2) builds the request URL.
    const bundleTypeHosts = this._hosts.filter(dom => !analyticsTypes.has(dom.dataset.gridType));
    this.gridTypes = new Set(bundleTypeHosts.map(dom => dom.dataset.gridType).filter(Boolean));

    // A Batch host with data-history="1" embeds BatchHistory's <form>
    // directly inside the Batch widget instead of via its own host div (see
    // _mountEmbeddedBatchHistory below), so its type wouldn't otherwise make
    // it into the bundle request below — add it explicitly so the fetched
    // domain includes what BatchHistoryGridModel needs (batch/flavor).
    if (bundleTypeHosts.some(dom => dom.dataset.gridType === 'Batch' && dom.dataset.history)) {
      this.gridTypes.add('BatchHistory');
    }

    this._setPageTypes();

    const allGrids = [];
    const analyticsEntries = []; // { dom, type, model, grid } — for phase 2
    const bundleGrids = [];

    for (const dom of this._hosts) {
      const type     = dom.dataset.gridType;
      const location = this._resolveLocation(dom);

      if (analyticsTypes.has(type)) {
        const days = Number(dom.dataset.days || 30);
        let model, grid;

        if (type === "Popular") {
          model = new PopularGridModel("Popular", null, {
            location, days, nonce: this.nonce, forceCacheBust: this._forceCacheBust,
            metaData: SCOOP.metaData?.Popular,
          });
          // PopularPlot isn't a List subclass (see popular-plot.js) — no
          // TOGGLE/dockToggle() of its own; its constructor does no DOM work
          // until init()/render(), so building it now (ahead of fetch) is safe.
          grid = new PopularPlot(dom, "Popular", { api: this, modelInstance: model });
        } else if (type === "Flavors") {
          model = new FlavorsGridModel("Flavors", null, {
            location, days, nonce: this.nonce, forceCacheBust: this._forceCacheBust,
            metaData: SCOOP.metaData?.Flavors,
          });
          grid = new Grid(dom, "Flavors", {
            api: this, modelInstance: model, formCodec, columns: model.columns, pageStatusId: dom.id,
          });
        } else {
          model = new AnalyticsGridModel("Analytics", null, {
            location, days, nonce: this.nonce, forceCacheBust: this._forceCacheBust,
            metaData: SCOOP.metaData?.Analytics,
          });
          grid = new Grid(dom, "Analytics", {
            api: this, modelInstance: model, formCodec, columns: model.columns, pageStatusId: dom.id,
          });
        }

        grid.dockToggle?.();
        analyticsEntries.push({ dom, type, model, grid });
        allGrids.push(grid);
        continue;
      }

      const ModelClass = modelsBom[type];

      // An unknown type (usually a shortcode typo like type="popular" vs
      // "Popular") would otherwise throw "ModelClass is not a constructor"
      // and kill every grid on the page. Skip it with a console warning so
      // the rest of the page still renders.
      if (typeof ModelClass !== 'function') {
        console.warn(`ScoopAPI.mountAllGrids: no model for grid type "${type}", skipping host`, dom);
        continue;
      }

      const dateFilters = this._dateFiltersFromDataset(dom);
      const filterValues = this._filterValuesFromDataset(dom, dateFilters);

      const modelInstance = new ModelClass(type, null, {
          location,
          metaData: SCOOP.metaData?.[type],
          dateFilters,
          filterValues,
          modifiedRange: dom.dataset.modifiedRange || filterValues.activity || 'last_48_hours',
          // Row grouping/filtering from the shortcode (data-group/data-filter,
          // see includes/shortcode.php) — currently read by InstockFlavorGridModel.
          group: dom.dataset.group || null,
          filters: dom.dataset.filter || '',
      });

      // data-view="tile" (see includes/shortcode.php's [scoop_tile ...])
      // picks the card renderer instead of the table one — same model,
      // same bundle domain, just a different List subclass (see tile.js).
      // getViewOverrides() takes priority for types with their own Tile
      // subclass (see cabinet-workflow-tile.js).
      const ViewClass = this.getViewOverrides()[type]
        ?? (dom.dataset.view === 'tile' ? Tile : Grid);

      const grid = new ViewClass(dom, type, {
          api: this,
          modelInstance,
          formCodec,
          columns: modelInstance.columns,
          pageStatusId: dom.id,
      });

      grid.dockToggle?.();
      bundleGrids.push(grid);
      allGrids.push(grid);

      if (type === 'Batch' && dom.dataset.history) {
        const historyGrid = this._mountEmbeddedBatchHistory(dom, grid, formCodec);
        bundleGrids.push(historyGrid);
        allGrids.push(historyGrid);
      }
    }

    // ── Phase 2: analytics grids self-fetch, one at a time (unchanged pacing) ──
    for (const { dom, type, model, grid } of analyticsEntries) {
      PageStatus.setState(dom.id, 'fetching');
      PageStatus.beginLoadTiming(`${window.location.pathname}::${type}`);
      await model.fetch();
      PageStatus.completeLoadTiming(model.lastCacheStatus);

      grid.init(model);

      // PopularPlot isn't a List subclass, so grid.init() above doesn't run
      // List's own _reportFresh() — mark it directly (Grid-based Analytics/
      // Flavors already get this for free from init()).
      if (type === "Popular") PageStatus.setState(dom.id, 'fresh');
    }

    // ── Phase 2: bundle-based grids share one fetch ──
    if (bundleGrids.length) {
      this._bundleGrids = bundleGrids;
      this._bundleFilterParams = this._bundleFilterParamsForGrids(bundleGrids);
      await this.refreshPageDomain({ force: true });

      bundleGrids.forEach(g => g.setDomain(this._domain));
    }

    return allGrids;
  }
  
  
}
