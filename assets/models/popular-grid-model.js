import AnalyticsGridModel from "./analytics-grid-model.js";

export default class PopularGridModel extends AnalyticsGridModel {
  constructor(name = "Popular", domain = null, options = {}) {
    super(name, domain, options);
    if (!Array.isArray(this.points)) this.points = [];
    if (this.excludedCount == null) this.excludedCount = 0;
  }

  buildCols() {
    this._allColumns = [];
    this.columns = [];
    return this.columns;
  }

  buildRows() {
    const a = this.domain?.analytics;
    this.raw = a ?? null;

    if (!a?.ok || !Array.isArray(a.flavors)) {
      this.points = [];
      this.rows = [];
      this.excludedCount = 0;
      return this.rows;
    }

    const points = [];
    let excludedCount = 0;

    a.flavors.forEach((f, i) => {
      const totalSold = this._finiteNumber(f.total_sold);
      const avgDays = this._finiteNumber(f.avg_sellthrough_days);

      if (totalSold === null || avgDays === null || totalSold <= 0) {
        excludedCount++;
        return;
      }

      points.push({
        id: String(f.flavor_id ?? i),
        flavorId: f.flavor_id ?? i,
        flavorName: f.flavor_name ?? "",
        totalSold,
        avgDays,
        sellRatePerDay: this._finiteNumber(f.sell_rate_per_day),
        currentStock: this._finiteNumber(f.current_stock),
        trend: f.trend ?? "steady",
      });
    });

    points.sort((aPoint, bPoint) => {
      const soldDiff = bPoint.totalSold - aPoint.totalSold;
      if (soldDiff !== 0) return soldDiff;
      return aPoint.avgDays - bPoint.avgDays;
    });

    this.points = points;
    this.excludedCount = excludedCount;
    this.rows = points;
    return this.rows;
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
