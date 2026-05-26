import AnalyticsGridModel from "./analytics-grid-model.js";

export default class FlavorsGridModel extends AnalyticsGridModel {
  constructor(name = "Flavors", domain = null, options = {}) {
    super(name, domain, options);
  }

  buildCols() {
    this._allColumns = [
      { key: "flavor_name",       label: "Flavor",            type: "string" },
      { key: "tubs",              label: "Tubs",              type: "number" },
      { key: "days_since_served", label: "Days Since Served", type: "number" },
    ];
    this._applyColumnFilter();
    return this.columns;
  }

  buildRows() {
    const a = this.domain?.analytics;
    this.raw = a ?? null;

    if (!a?.ok || !Array.isArray(a.flavors)) {
      this.rows = [];
      this.rowGroups = [];
      return this.rows;
    }

    const sorted = [...a.flavors].sort((x, y) =>
      String(x.flavor_name ?? "").localeCompare(String(y.flavor_name ?? ""))
    );

    const dairy = [];
    const nonDairy = [];
    for (const f of sorted) {
      const allergens = Array.isArray(f.allergens) ? f.allergens : [];
      if (allergens.includes("dairy")) dairy.push(f);
      else nonDairy.push(f);
    }

    const groupsMap = new Map();
    groupsMap.set("dairy",     dairy);
    groupsMap.set("non-dairy", nonDairy);

    return this.buildGroupedRows({
      groupsMap,
      includeGroupId: (id) => (groupsMap.get(id)?.length ?? 0) > 0,
      getGroupLabel : (id) => id === "dairy" ? "Dairy" : "Non-Dairy",
      makeRowId     : (f) => f.flavor_id,
      fillRow       : (row, f) => this._fillRow(row, f),
      groupType     : "diet",
      rowType       : "flavor",
      rowLabel      : "flavor",
      collapsible   : true,
      collapsed     : false,
    });
  }

  _fillRow(row, f) {
    const name = f.flavor_name ?? "";
    row.flavor_name = { display: name, value: name };

    const tubs = Number(f.current_stock ?? 0);
    row.tubs = { display: String(tubs), value: tubs };

    const days = this._daysSince(f.last_batch_date);
    if (days === null) {
      // Infinity sorts "Never" flavors to the top under descending sort,
      // which matches the intuition that they're the most-neglected.
      row.days_since_served = { display: "Never", value: Infinity };
    } else {
      row.days_since_served = { display: String(days), value: days };
    }
  }

  _daysSince(dateStr) {
    if (!dateStr) return null;
    const t = Date.parse(dateStr);
    if (!Number.isFinite(t)) return null;
    const ms = Date.now() - t;
    return Math.max(0, Math.floor(ms / 86400000));
  }
}
