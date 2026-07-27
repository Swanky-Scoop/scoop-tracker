import BaseGridModel from "./_base-grid-model.js";
import Indexer       from "../data/indexer.js";

/**
 * Read-only listing of past batches, filterable by creation date.
 *
 * Minimal shortcode (uses 48hr default):
 *   [scoop_grid type="BatchHistory"]
 *
 * Override starting window:
 *   [scoop_grid type="BatchHistory" date_filters="created" filter_created="last_7_days"]
 *
 * Both `date_filters` and `filter_created` are optional. When omitted:
 *   - JS model defaults `dateFilters` to `['created']` and the value to `last_48_hours`.
 *   - Server-side, scoop_bundle_date_filter_context also defaults a missing
 *     date_filters to ['created'] when BatchHistory is requested, and
 *     scoop_normalize_date_filter_preset falls back to 'last_48_hours'.
 *
 * Filter widget at the top of the grid lets the user switch between
 * 24h / 48h / 7d / 30d at runtime; selection triggers a fresh bundle fetch.
 *
 * Columns: Created, Flavor, Tubs (count), Author. Sorted newest-first.
 */
export default class BatchHistoryGridModel extends BaseGridModel {
  constructor(name = 'BatchHistory', domain, attrs = {}, metaData = null) {
    super(name, null, attrs, metaData);
    this.filter = true;                                  // enable find-in-grid text filter
    this.dateFilters  = this._normalizeDateFilters(attrs?.dateFilters);
    this.filterValues = this._initialFilterValues(attrs?.filterValues);
    this._build();
    if (domain) this.setDomain(domain);
  }

  buildCols() {
    this.columns = [
      { key: "post_date",   label: "Created", type: "datetime" },
      { key: "flavor",      label: "Flavor",  type: "string", titleMap: "flavor" },
      { key: "count",       label: "Tubs",    type: "number" },
      { key: "author_name", label: "Author",  type: "string" },
    ];
    return this.columns;
  }

  buildRows() {
    this._flavorsById = Indexer.byId(this.domain?.flavor) || new Map();
    const batches = Array.isArray(this.domain?.batch) ? this.domain.batch : [];

    // Newest-first
    const sorted = [...batches].sort((a, b) =>
      String(b.post_date ?? "").localeCompare(String(a.post_date ?? ""))
    );

    this.rows = sorted.map((b) => {
      const flavorId   = Number(b.flavor ?? 0);
      const flavorName = this._flavorsById?.get?.(flavorId)?._title ?? "—";

      return {
        id: b.id,
        post_date:   { display: this._fmtDate(b.post_date), value: b.post_date ?? "" },
        flavor:      { display: flavorName,                  value: flavorName },
        count:       { display: this._fmtCount(b.count),     value: Number(b.count ?? 0) },
        author_name: { display: b.author_name ?? "",         value: b.author_name ?? "" },
      };
    });

    return this.rows;
  }

  // ── Date-range filter (server-side, triggers a bundle refresh on change) ──
  getFilterDefs() {
    return this.dateFilters.map(key => ({
      key,
      label: this._dateFilterLabel(key),
      type: 'select',
      mode: 'server',
      default: 'last_48_hours',
      options: [
        { key: 'last_24_hours', label: '24 hrs' },
        { key: 'last_48_hours', label: '48 hrs' },
        { key: 'last_7_days',   label: '7 days'   },
        { key: 'last_30_days',  label: '30 days'  },
      ],
    }));
  }

  getServerFilterParams() {
    const params = { date_filters: this.dateFilters.join(',') };
    this.dateFilters.forEach(key => {
      params[`filter_${key}`] = this.getFilterValue(key);
    });
    return params;
  }

  setFilterValue(key, value) {
    const k = this._normalizeDateFilterKey(key);
    if (!k) return;
    this.filterValues[k] = this._normalizePreset(value);
    if (!this.dateFilters.includes(k)) this.dateFilters.push(k);
  }

  getFilterValue(key) {
    const k = this._normalizeDateFilterKey(key);
    return this.filterValues[k] ?? 'last_48_hours';
  }

  // ── Helpers ───────────────────────────────────────────────────────────────
  _normalizeDateFilters(raw) {
    const values = Array.isArray(raw) ? raw : String(raw || 'created').split(',');
    const out = [];
    const seen = new Set();
    values.forEach(v => {
      const k = this._normalizeDateFilterKey(v);
      if (k && !seen.has(k)) { out.push(k); seen.add(k); }
    });
    return out.length ? out : ['created'];
  }

  _initialFilterValues(rawValues) {
    const out = {};
    const src = rawValues && typeof rawValues === 'object' ? rawValues : {};
    Object.keys(src).forEach(k => {
      const nk = this._normalizeDateFilterKey(k);
      if (nk) out[nk] = this._normalizePreset(src[k]);
    });
    this.dateFilters.forEach(k => {
      if (out[k] == null) out[k] = 'last_48_hours';
    });
    return out;
  }

  _normalizeDateFilterKey(key) {
    return String(key ?? '').trim().toLowerCase().replace(/[^a-z0-9_]/g, '_');
  }

  _normalizePreset(value) {
    const allowed = ['last_24_hours', 'last_48_hours', 'last_7_days', 'last_30_days'];
    const v = String(value ?? '').trim().toLowerCase().replace(/[^a-z0-9_]/g, '_');
    return allowed.includes(v) ? v : 'last_48_hours';
  }

  _dateFilterLabel(key) {
    if (key === 'created') return 'Created in';
    return key.replace(/_/g, ' ');
  }

  _fmtDate(raw) {
    if (!raw) return "";
    return String(raw).slice(0, 16); // "YYYY-MM-DD HH:MM"
  }

  _fmtCount(raw) {
    if (raw == null || raw === "") return "";
    const n = Number(raw);
    if (!Number.isFinite(n)) return String(raw);
    return Number.isInteger(n) ? String(n) : n.toFixed(2);
  }
}
