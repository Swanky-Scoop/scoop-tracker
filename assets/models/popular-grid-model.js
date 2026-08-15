import AnalyticsGridModel from "./analytics-grid-model.js";

export default class PopularGridModel extends AnalyticsGridModel {
  constructor(name = "Popular", domain = null, options = {}) {
    super(name, domain, options);
    if (!Array.isArray(this.points)) this.points = [];
    if (this.excludedCount == null) this.excludedCount = 0;

    // Never routed through scoop_routes_config() (see DOCKING.md), so
    // BaseGridModel's constructor had nothing to read this from and fell
    // back to 'half-stack' — wrong for a scatter plot that wants the whole
    // canvas: half-stack caps it at max-width:40rem and keeps the outer
    // gutter around it (see the [data-canvas-mode] rules in css.css).
    // full-nostack also drops that gutter entirely (:has(...[data-canvas-
    // mode="full-nostack"]) in css.css) and gets nostack's exclusivity via
    // List._enforceCanvasExclusivity() — see PopularPlot's borrowed copy.
    this.canvasMode = 'full-nostack';

    // Allergen select is rendered via getFilterDefs(); no need to set
    // this.filter = true (that flag enables List's built-in FindInList text
    // widget, which would duplicate PopularPlot._mountFilter()'s input).
    // _allergenOptions is populated from the data each time buildRows runs.
    this.filterValues = { allergen: 'all' };
    this._allergenOptions = [];
  }

  // Three columns are enough to support sort-by-popularity (total_sold)
  // and sort-by-speed (avg_sellthrough_days); both are what the plot's
  // axes show, so the key Grid mirrors the plot's data exactly.
  buildCols() {
    this._allColumns = [
      { key: "flavor_name",          label: "Flavor",    type: "string" },
      { key: "total_sold",           label: "Sold",      type: "number" },
      { key: "avg_sellthrough_days", label: "Day avg",   type: "number" },
    ];
    this._applyColumnFilter();
    return this.columns;
  }

  getFilterDefs() {
    return [
      {
        key: 'allergen',
        label: 'Allergen',
        type: 'select',
        mode: 'client',
        default: 'all',
        options: [
          { key: 'all',  label: 'All flavors' },
          { key: 'none', label: 'Allergen-free' },
          ...this._allergenOptions.map(slug => ({
            key: `has:${slug}`,
            label: `Contains ${slug}`,
          })),
          ...this._allergenOptions.map(slug => ({
            key: `not:${slug}`,
            label: `Without ${slug}`,
          })),
        ],
      },
    ];
  }

  setFilterValue(key, value) {
    if (!key) return;
    this.filterValues[key] = String(value ?? 'all');
  }

  getFilterValue(key) {
    return this.filterValues?.[key] ?? 'all';
  }

  buildRows() {
    const a = this.domain?.analytics;
    this.raw = a ?? null;

    if (!a?.ok || !Array.isArray(a.flavors)) {
      this.points = [];
      this.rows = [];
      this._allergenOptions = [];
      this.excludedCount = 0;
      return this.rows;
    }

    const points = [];
    const allergenSet = new Set();
    let excludedCount = 0;

    a.flavors.forEach((f, i) => {
      const totalSold = this._finiteNumber(f.total_sold);
      const avgDays   = this._finiteNumber(f.avg_sellthrough_days);

      if (totalSold === null || avgDays === null || totalSold <= 0) {
        excludedCount++;
        return;
      }

      const id        = String(f.flavor_id ?? i);
      const name      = f.flavor_name ?? "";
      const allergens = Array.isArray(f.allergens) ? f.allergens.map(String) : [];
      allergens.forEach(slug => allergenSet.add(slug));

      points.push({
        id,
        flavorId: f.flavor_id ?? i,
        flavorName: name,
        allergens,
        totalSold,
        avgDays,
        sellRatePerDay: this._finiteNumber(f.sell_rate_per_day),
        currentStock: this._finiteNumber(f.current_stock),
        trend: f.trend ?? "steady",
        trendPct: this._finiteNumber(f.trend_pct),
      });
    });

    points.sort((aPoint, bPoint) => {
      const soldDiff = bPoint.totalSold - aPoint.totalSold;
      if (soldDiff !== 0) return soldDiff;
      return aPoint.avgDays - bPoint.avgDays;
    });

    this.points = points;
    this.excludedCount = excludedCount;
    this._allergenOptions = [...allergenSet].sort((a, b) => a.localeCompare(b));

    this.rows = points
      .filter(p => this._passesFilters(p))
      .map(p => this._rowFor(p));

    return this.rows;
  }

  _rowFor(p) {
    return {
      id: p.id,
      flavor_name:          { display: p.flavorName,            value: p.flavorName },
      total_sold:           { display: this._fmtNum(p.totalSold, 1), value: p.totalSold },
      avg_sellthrough_days: { display: this._fmtNum(p.avgDays,   1), value: p.avgDays,
                              alertCase: `trend-${p.trend}` },
    };
  }

  _passesFilters(point) {
    const filter = this.getFilterValue('allergen');
    if (filter === 'all') return true;
    const allergens = point.allergens ?? [];
    if (filter === 'none') return allergens.length === 0;
    if (filter.startsWith('has:')) return allergens.includes(filter.slice(4));
    if (filter.startsWith('not:')) return !allergens.includes(filter.slice(4));
    return true;
  }

  // Plot consumes this to mirror grid-side filtering onto the SVG circles.
  getVisiblePointIds() {
    return new Set((this.rows ?? []).map(r => String(r.id)));
  }

  getPlotData() {
    return {
      points: this.points ?? [],
      periodDays: this.raw?.period_days ?? this.days,
      generatedAt: this.raw?.generated_at ?? null,
      degraded: this.raw?.degraded ?? [],
      excludedCount: this.excludedCount ?? 0,
    };
  }

  _finiteNumber(value) {
    if (value == null || value === "") return null;
    const n = Number(value);
    return Number.isFinite(n) ? n : null;
  }
}
