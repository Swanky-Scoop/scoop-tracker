import BaseGridModel from "./_base-grid-model.js";
import Indexer       from "../data/indexer.js";

// Read-only "what's attached to this task" listing for one of the three
// Task-form widgets (Batches/Recipe production/Ingredient prep) — same
// shape/reasoning as BatchHistoryGridModel, just scoped to a single task
// (via each row's own 'task' field) instead of a date range, and shared
// across all three entity types via config rather than one subclass per
// type (the only real difference between them is field names). See
// assets/ui/task-form.js for how this is embedded and kept scoped.
//
// Config (attrs):
//   taskId            — this task's id; rows are filtered to entity.task === taskId
//   entityKey         — bundle domain key holding the raw rows: 'batch' | 'recipe_count' | 'prep'
//   relationField     — the row's own relation field name: 'flavor' | 'recipe' | 'ingredient'
//   relationLabel     — column header for that field
//   relationDomainKey — domain key to resolve relation id -> title (defaults to relationField)
//   extraCols         — [{ key, label }] additional plain-text columns read straight off the row (e.g. Prep's units/other)
//   deleteFn          — (rowId, api) => Promise, e.g. api.deleteRecipeCount
//   deleteNoun        — singular noun for the delete confirmation label, e.g. "recipe count"
export default class TaskComponentHistoryGridModel extends BaseGridModel {
  constructor(name, domain, attrs = {}, metaData = null) {
    super(name, domain, attrs, metaData);
    this.taskId = Number(attrs?.taskId || 0);
    this.entityKey = attrs?.entityKey;
    this.relationField = attrs?.relationField;
    this.relationLabel = attrs?.relationLabel ?? this.relationField;
    this.relationDomainKey = attrs?.relationDomainKey ?? this.relationField;
    this.extraCols = attrs?.extraCols ?? [];
    this.deleteFn = attrs?.deleteFn ?? null;
    this.deleteNoun = attrs?.deleteNoun ?? "item";
    this._build();
  }

  _canDelete() {
    return typeof this.deleteFn === "function";
  }

  buildCols() {
    this.columns = [
      { key: this.relationField, label: this.relationLabel, type: "string", titleMap: this.relationDomainKey },
      { key: "count", label: "count", type: "number" },
      ...this.extraCols.map((c) => ({ key: c.key, label: c.label, type: "string" })),
    ];

    if (this._canDelete()) {
      this.columns.push({
        key: "delete", label: "", type: "delete",
        title: `Remove this ${this.deleteNoun}`,
        icon: "if:d",
      });
    }

    return this.columns;
  }

  buildRows() {
    const relById = Indexer.byId(this.domain?.[this.relationDomainKey]) || new Map();
    const items = Array.isArray(this.domain?.[this.entityKey]) ? this.domain[this.entityKey] : [];
    const scoped = items.filter((it) => Number(it.task ?? 0) === this.taskId);

    this.rows = scoped.map((it) => {
      const relId = Number(it[this.relationField] ?? 0);
      const relTitle = relById?.get?.(relId)?._title ?? "—";

      const row = {
        id: it.id,
        [this.relationField]: { display: relTitle, value: relTitle },
        count: { display: this._fmtCount(it.count), value: Number(it.count ?? 0) },
      };

      this.extraCols.forEach((c) => {
        const v = it[c.key] ?? "";
        row[c.key] = { display: v, value: v };
      });

      return row;
    });

    return this.rows;
  }

  deleteRowLabel(rowId) {
    const row = this.rows.find((r) => r.id === rowId);
    const label = row?.[this.relationField]?.display;
    return label ? `the ${label} ${this.deleteNoun}` : `this ${this.deleteNoun}`;
  }

  async deleteRow(rowId, api) {
    return this.deleteFn?.(rowId, api);
  }

  _fmtCount(raw) {
    if (raw == null || raw === "") return "";
    const n = Number(raw);
    if (!Number.isFinite(n)) return String(raw);
    return Number.isInteger(n) ? String(n) : n.toFixed(2);
  }
}
