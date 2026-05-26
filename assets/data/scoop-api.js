import Grid               from "../ui/grid.js";
import ColumnsProvider    from "../models/_column-provider.js";
import FormCodec          from "./form-codec.js";
import CabinetGridModel      from "../models/cabinet-grid-model.js";
import BatchGridModel        from "../models/batch-grid-model.js";
import CloseoutGridModel     from "../models/closeout-grid-model.js";
import FlavorTubGridModel    from "../models/flavor-tub-grid-model.js";
import DateActivityGridModel from "../models/date-activity-grid-model.js";
import AnalyticsGridModel    from "../models/analytics-grid-model.js";
import PopularGridModel      from "../models/popular-grid-model.js";
import PopularPlot           from "../ui/popular-plot.js";
import FlavorsGridModel      from "../models/flavors-grid-model.js";


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
      "Closeout"     : CloseoutGridModel,
      "DateActivity" : DateActivityGridModel,
      "Analytics"    : AnalyticsGridModel,
      "Popular"      : PopularGridModel,
      "Flavors"      : FlavorsGridModel,
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

    this._domainInflight = (async () => {
      // bypass in-memory bundle cache on force
      const bundle = await this.getBundleForTypes(this._pageTypes, { cache: !force });
      this._domain = bundle?.data ?? {};
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

        await model.fetch();

        const plot = new PopularPlot(dom, "Popular", {
          api: this,
          modelInstance: model,
        });
        plot.init(model);
        allGrids.push(plot);
        continue;
      }

      if (type === "Flavors") {
        const model = new FlavorsGridModel("Flavors", null, {
          location,
          days,
          nonce: this.nonce,
        });

        await model.fetch();

        const grid = new Grid(dom, "Flavors", {
          api: this,
          modelInstance: model,
          formCodec,
          columns: model.columns,
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

      await model.fetch();

      const grid = new Grid(dom, "Analytics", {
        api: this,
        modelInstance: model,
        formCodec,
        columns: model.columns,
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
            modifiedRange: dom.dataset.modifiedRange || filterValues.activity || 'last_48_hours'
        });

        return new Grid(dom, name, {
            api: this,
            modelInstance,
            formCodec,
            columns: modelInstance.columns
        });
      }).filter(Boolean);

      this._bundleGrids = bundleGrids;
      this._bundleFilterParams = this._bundleFilterParamsForGrids(bundleGrids);
      await this.refreshPageDomain({ force: true });

      // Set domain on each bundle grid
      bundleGrids.forEach(g => {
          g.setDomain(this._domain);
      });

      allGrids.push(...bundleGrids);
    }

    return allGrids;
  }
  
  
}
