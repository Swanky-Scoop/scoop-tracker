import Grid               from "../ui/grid.js";
import ColumnsProvider    from "../models/_column-provider.js";
import FormCodec          from "./form-codec.js";
import CabinetGridModel      from "../models/cabinet-grid-model.js";
import BatchGridModel        from "../models/batch-grid-model.js";
import CloseoutGridModel     from "../models/closeout-grid-model.js";
import FlavorTubGridModel    from "../models/flavor-tub-grid-model.js";
import DateActivityGridModel from "../models/date-activity-grid-model.js";
import AnalyticsGridModel    from "../models/analytics-grid-model.js";


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
    this.bundleUrl = new URL(this.baseUrl);

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
    this.typesKey = this._typesKey(this.types);
    this.bundleUrl = this._bundleUrlForTypes(this.types); // URL object
  }


  _typesKey() {
    const arr = [...(this.gridTypes ?? [])].map(String).filter(Boolean);
    arr.sort();
    return arr.join(",");
  }

  _bundleUrlForTypes() {
    const base = new URL(this.route("Bundle").toString());
    const key = this._typesKey();
    base.searchParams.set("types", key);
    if (this.gridTypes?.has('DateActivity')) {
      base.searchParams.set('include_empty_tubs', '1');
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
  async getBundleForTypes(types, { cache = true, modifiedSince = null } = {}) {
    const key = this._typesKey(types);
    if (!key) throw new Error("getBundleForTypes: no types");

    if (cache && !modifiedSince && this._bundleCache.has(key)) {
        return this._bundleCache.get(key);
    }

    const url = this._bundleUrlForTypes(types);
    
    // Add modified_since parameter if provided
    if (modifiedSince) {
        url.searchParams.set('modified_since', modifiedSince.toISOString());
    }
    
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
      // if these exist later, keep them without forcing structure:
      batch  : Array.isArray(data.batch)   ? data.batch   : (data.batch ?? []),
      closeout: Array.isArray(data.closeout) ? data.closeout : (data.closeout ?? []),
      ...data
    };

    if (cache) this._bundleCache.set(key, bundle);
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

  // --- MOUNTING ---
  async mountAllGrids({ root = document, formCodec = FormCodec } = {}) {
    if (!this.getTypesFromGridHosts(root)) return [];

    // Separate analytics grids from bundle-based grids
    const analyticsHosts = [];
    const bundleHosts    = [];

    for (const dom of this._hosts) {
      if (dom.dataset.gridType === "Analytics") {
        analyticsHosts.push(dom);
      } else {
        bundleHosts.push(dom);
      }
    }

    const allGrids = [];

    // ── Analytics grids: self-fetching, bypass the bundle ──
    for (const dom of analyticsHosts) {
      const location = Number(dom.dataset.location || 0);
      const days     = Number(dom.dataset.days || 30);
      const model    = new AnalyticsGridModel("Analytics", {
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
      const bundleGrids = bundleHosts.map(dom => {
        const name = dom.dataset.gridType;
        const location = Number(dom.dataset.location || 0);
        const ModelClass = this.getModelsBom()[name];

        const modelInstance = new ModelClass(name, null, {
            location,
            metaData: SCOOP.metaData?.[name]
        });

        return new Grid(dom, name, {
            api: this,
            modelInstance,
            formCodec,
            columns: modelInstance.columns
        });
      });

      // Fetch domain with date filtering if needed
      const needsDateFilter = this.gridTypes.has('DateActivity');

      if (needsDateFilter) {
          const fortyEightHoursAgo = new Date(Date.now() - 48 * 60 * 60 * 1000);
          const url = this._bundleUrlForTypes();
          url.searchParams.set('modified_since', fortyEightHoursAgo.toISOString());

          const bundle = await this.getJson(url);
          this._domain = bundle?.data ?? {};
      } else {
          await this.refreshPageDomain({ force: true });
      }

      // Set domain on each bundle grid
      bundleGrids.forEach(g => {
          g.setDomain(this._domain);
      });

      allGrids.push(...bundleGrids);
    }

    return allGrids;
  }
  
  
}