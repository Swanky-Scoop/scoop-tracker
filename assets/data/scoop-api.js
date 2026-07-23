import Grid               from "../ui/grid.js";
import Tile                from "../ui/tile.js";
import PageStatus          from "../ui/page-status.js";
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

    // Request control + caching
    this.controller = new AbortController();
    this._bundleCache = new Map(); // key:string -> bundleJson
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

    return { ok: res.ok, status: res.status, data, res };
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
    return base;
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

  // Returns full bundle JSON: { ok, types, needs, data }
  _bundleCacheKey(types = this._pageTypes) {
    const params = new URLSearchParams();
    Object.entries(this._bundleFilterParams ?? {})
      .sort(([a], [b]) => a.localeCompare(b))
      .forEach(([key, value]) => {
        if (value != null && value !== '') params.set(key, String(value));
      });

    return `${this._typesKey(types)}|${params.toString()}`;
  }

  async getBundleForTypes(types = this._pageTypes, { cache = true } = {}) {
    const key = this._typesKey(types);
    if (!key) throw new Error("getBundleForTypes: no types");

    const cacheKey = this._bundleCacheKey(types);
    if (cache && this._bundleCache.has(cacheKey)) {
        return this._bundleCache.get(cacheKey);
    }

    const url = this._bundleUrlForTypes(types);
    const bundle = await this.getJson(url);

    // Minimal guards so models can safely assume arrays exist.
    const data = bundle?.data ?? {};
    bundle.data = {
      //cabinet : Array.isArray(data.cabinet)  ? data.cabinet  : [],
      slot    : Array.isArray(data.slot)     ? data.slot     : [],
      tub     : Array.isArray(data.tub)      ? data.tub      : [],
      flavor  : Array.isArray(data.flavor)   ? data.flavor   : [],
      location: Array.isArray(data.location) ? data.location : [],
      use     : Array.isArray(data.use)      ? data.use      : [],
      inventory_change: Array.isArray(data.inventory_change) ? data.inventory_change : [],
      // if these exist later, keep them without forcing structure:
      batch  : Array.isArray(data.batch)   ? data.batch   : (data.batch ?? []),
      closeout: Array.isArray(data.closeout) ? data.closeout : (data.closeout ?? []),
      ...data,
      _date_filters: bundle?.date_filters ?? data._date_filters ?? {},
    };

    if (cache) this._bundleCache.set(cacheKey, bundle);
    return bundle;
  }

  // The models expect "domain" = the data object with arrays:
  // { cabinet, slot, tub, flavor, location, use, ... }
  async refreshPageDomain({ force = false, toast = null, info = null } = {}) {

    if (!this.gridTypes) throw new Error("refreshPageDomain: page types not set");
    if (!force && this._domain) return this._domain;
    if (this._domainInflight) return this._domainInflight;
    //if(toast) toast.update(toast, {title:"Data Saved..."});

    // A real fetch is about to happen (not a cache-hit early return above,
    // not piggy-backing on an already-inflight request) — every bundle grid
    // shares this one fetch, so mark them all at once. Each grid reports its
    // own 'fresh'/'stale' back once the fetch resolves (see List.init/
    // refresh/_onDomainUpdated in _list.js). info.name identifies which
    // grid's action (Save submit, autosave, filter change) caused this
    // refresh — absent only for the initial page-load call.
    this._bundleGrids.forEach(g => {
      if (g.pageStatusId) PageStatus.setState(g.pageStatusId, 'fetching');
    });
    PageStatus.setTrigger(info?.name ?? 'page load');

    this._domainInflight = (async () => {
      // bypass in-memory bundle cache on force
      const bundle = await this.getBundleForTypes(this._pageTypes, { cache: !force });
      this._domain = bundle?.data ?? {};
      this._lastBundleCacheStatus = bundle?._cache ?? null;
      this._domainInflight = null;

      document.dispatchEvent(new CustomEvent("ts:domain:updated", {
        detail: { types: this._pageTypes, ts: Date.now() }
      }));
      /*
      if(toast) toast.update(toast, {
        title:"Data Reloaded", 
        message:(info)? 'Triggered by ' + info.name : ''
      });*/
      document.body.classList.remove('TS_GRID-UPDATING');

      return this._domain;
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
  watchForStaleVersion(baseline, { intervalMs = 5 * 60 * 1000 } = {}) {
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

      location.reload();
    }, intervalMs);
  }

  // Forces a real re-login after N hours of no genuine interaction — a tab
  // left open overnight shouldn't stay authenticated forever. Deliberately
  // driven by actual mousemove/keydown/click, NOT by background traffic like
  // watchForStaleVersion's poll or autosave — those fire on their own timers
  // regardless of whether she's at the keyboard, so counting them as
  // "activity" would defeat the timeout.
  watchForIdleTimeout({ idleMs = 6 * 60 * 60 * 1000, checkIntervalMs = 60 * 1000, loginUrl } = {}) {
    let lastActivity = Date.now();
    const bump = () => { lastActivity = Date.now(); };
    ['mousemove', 'keydown', 'click', 'scroll', 'touchstart'].forEach(evt =>
      document.addEventListener(evt, bump, { passive: true })
    );

    setInterval(async () => {
      if (Date.now() - lastActivity < idleMs) return;
      if (this.hasUnsavedEdits()) return; // autosave hasn't flushed yet, recheck next tick

      try {
        await this._fetch(this.route("IdleLogout"), { method: "POST" });
      } catch (err) {
        console.error("watchForIdleTimeout: logout call failed", err);
      }

      alert("You've been logged out after a period of inactivity. Please log back in.");
      location.href = loginUrl || "/wp-login.php";
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
    this._bundleCache.clear();
    document.body.classList.add('TS_GRID-UPDATING');
    return this.refreshPageDomain({ force: true, info: { name: grid?.name ?? 'filters' } });
  }


  // --- MOUNTING ---
  async mountAllGrids({ root = document, formCodec = FormCodec } = {}) {
    if (!this.getTypesFromGridHosts(root)) return [];

    // ETA for how long THIS page (URL + this combination of grid types)
    // typically takes to fully resolve — see PageStatus.beginLoadTiming's
    // header comment for why this stays client-side. typesKey reflects
    // every host on the page at this point (analytics included); captured
    // now because it gets narrowed to bundle-only types further down.
    PageStatus.beginLoadTiming(`${window.location.pathname}::${this.typesKey}`);

    // Register every shortcode host with PageStatus up front, before any
    // fetch (analytics self-fetch or the shared bundle fetch) starts — each
    // host already carries a stable id from shortcode.php. 'unknown' is the
    // literal starting state until its first fetch begins.
    this._hosts.forEach(dom => {
      PageStatus.register(dom.id, {
        label: `${dom.dataset.gridType ?? 'grid'} (${dom.dataset.location || 'no location'})`,
        type: dom.dataset.gridType ?? '',
        location: dom.dataset.location ?? '',
      });
    });

    // Separate analytics grids from bundle-based grids
    const analyticsHosts = [];
    const bundleHosts    = [];
    const analyticsTypes = new Set(["Analytics", "Popular", "Flavors"]);

    for (const dom of this._hosts) {
      if (analyticsTypes.has(dom.dataset.gridType)) {
        analyticsHosts.push(dom);
      } else {
        bundleHosts.push(dom);
      }
    }

    const allGrids = [];

    // 'hit'/'miss' from every fetch this mount makes — reduced to one
    // overall cache-status for the page-load ETA below. Any single miss
    // means the page had to wait on a cold compute somewhere, so 'miss'
    // wins over 'hit' when both occur on the same page.
    const cacheStatuses = [];

    // ── Analytics grids: self-fetching, bypass the bundle ──
    for (const dom of analyticsHosts) {
      const type     = dom.dataset.gridType;
      const location = Number(dom.dataset.location || 0);
      const days     = Number(dom.dataset.days || 30);
      if (type === "Popular") {
        const model = new PopularGridModel("Popular", null, {
          location,
          days,
          nonce: this.nonce,
        });

        PageStatus.setState(dom.id, 'fetching');
        await model.fetch();
        cacheStatuses.push(model.lastCacheStatus);

        // PopularPlot isn't a List subclass (see popular-plot.js) so it has
        // no _reportFresh() hook of its own — mark it directly.
        const plot = new PopularPlot(dom, "Popular", {
          api: this,
          modelInstance: model,
        });
        plot.init(model);
        PageStatus.setState(dom.id, 'fresh');
        allGrids.push(plot);
        continue;
      }

      if (type === "Flavors") {
        const model = new FlavorsGridModel("Flavors", null, {
          location,
          days,
          nonce: this.nonce,
        });

        PageStatus.setState(dom.id, 'fetching');
        await model.fetch();
        cacheStatuses.push(model.lastCacheStatus);

        const grid = new Grid(dom, "Flavors", {
          api: this,
          modelInstance: model,
          formCodec,
          columns: model.columns,
          pageStatusId: dom.id,
        });
        grid.init(model);
        allGrids.push(grid);
        continue;
      }

      const model    = new AnalyticsGridModel("Analytics", null, {
        location,
        days,
        nonce: this.nonce,
      });

      PageStatus.setState(dom.id, 'fetching');
      await model.fetch();
      cacheStatuses.push(model.lastCacheStatus);

      const grid = new Grid(dom, "Analytics", {
        api: this,
        modelInstance: model,
        formCodec,
        columns: model.columns,
        pageStatusId: dom.id,
      });
      grid.init(model);
      allGrids.push(grid);
    }

    // ── Bundle-based grids: existing behavior ──
    if (bundleHosts.length) {
      // getTypesFromGridHosts() stuffed every grid type into this.gridTypes,
      // including the analytics ones. The bundle endpoint 400s on unknown
      // types like "Popular", so re-scope to only the bundle hosts before
      // refreshPageDomain() builds the request URL.
      this.gridTypes = new Set(bundleHosts.map(dom => dom.dataset.gridType).filter(Boolean));
      this._setPageTypes();

      const modelsBom = this.getModelsBom();
      const bundleGrids = bundleHosts.map(dom => {
        const name = dom.dataset.gridType;
        const ModelClass = modelsBom[name];

        // An unknown type (usually a shortcode typo like type="popular" vs
        // "Popular") would otherwise throw "ModelClass is not a constructor"
        // and kill every grid on the page. Skip it with a console warning so
        // the rest of the page still renders.
        if (typeof ModelClass !== 'function') {
          console.warn(`ScoopAPI.mountAllGrids: no model for grid type "${name}", skipping host`, dom);
          return null;
        }

        const location = Number(dom.dataset.location || 0);
        const dateFilters = this._dateFiltersFromDataset(dom);
        const filterValues = this._filterValuesFromDataset(dom, dateFilters);

        const modelInstance = new ModelClass(name, null, {
            location,
            metaData: SCOOP.metaData?.[name],
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
        const ViewClass = this.getViewOverrides()[name]
          ?? (dom.dataset.view === 'tile' ? Tile : Grid);

        return new ViewClass(dom, name, {
            api: this,
            modelInstance,
            formCodec,
            columns: modelInstance.columns,
            pageStatusId: dom.id,
        });
      }).filter(Boolean);

      this._bundleGrids = bundleGrids;
      this._bundleFilterParams = this._bundleFilterParamsForGrids(bundleGrids);
      await this.refreshPageDomain({ force: true });
      cacheStatuses.push(this._lastBundleCacheStatus);

      // Set domain on each bundle grid
      bundleGrids.forEach(g => {
          g.setDomain(this._domain);
      });

      allGrids.push(...bundleGrids);
    }

    // Every grid above was mounted synchronously to completion (analytics'
    // model.fetch()/init() per host, bundle grids' setDomain()->init() in
    // the forEach) — by this line, every registered grid has already
    // reported 'fresh', so this is the true resolve moment for the initial load.
    const overallCacheStatus = cacheStatuses.includes('miss')
      ? 'miss'
      : (cacheStatuses.includes('hit') ? 'hit' : 'unknown');
    PageStatus.completeLoadTiming(overallCacheStatus);

    return allGrids;
  }
  
  
}
