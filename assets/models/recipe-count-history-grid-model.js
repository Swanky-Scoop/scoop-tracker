import BaseGridModel from "./_base-grid-model.js";
import Indexer       from "../data/indexer.js";

/**
 * Read-only listing of recipe_count rows, filtered to one task — the
 * Batch/BatchHistory pattern's second concrete instance (see
 * RecipeCountGridModel's own header comment). The one real difference from
 * BatchHistoryGridModel: no date-range filter widget — "which window of
 * time" isn't the question here, "which task" is. `task` is a fixed scope
 * set once at construction (from the host's `data-task` attribute — see
 * ScoopAPI._resolveTask()), not a runtime-switchable filter the way
 * BatchHistory's date range is; there's no dropdown here.
 *
 * Minimal shortcode (mounted embedded via Batch-style `history="true"`,
 * not on its own):
 *   [scoop_grid type="RecipeCount" task="123" history="true"]
 *
 * Columns: Recipe, Count, Done — no Author/Created columns (unlike
 * BatchHistory) since those weren't asked for here; add them the same way
 * BatchHistoryGridModel does (post_fields.author_name/post_date) if
 * that's wanted later. No delete action yet either — BatchHistory's
 * _canDeleteBatch()/deleteRow() pattern is the one to mirror once
 * recipe_count deletion is actually wanted.
 */
export default class RecipeCountHistoryGridModel extends BaseGridModel {
  constructor(name = 'RecipeCountHistory', domain, attrs = {}, metaData = null) {
    super(name, null, attrs, metaData);
    this.task = Number(attrs?.task ?? 0) || 0;
    this._build();
    if (domain) this.setDomain(domain);
  }

  buildCols() {
    this.columns = [
      { key: "recipe", label: "Recipe", type: "string", titleMap: "recipe" },
      { key: "count",  label: "Count",  type: "number" },
      { key: "done",   label: "Done",   type: "string" },
    ];

    return this.columns;
  }

  buildRows() {
    this._recipesById = Indexer.byId(this.domain?.recipe) || new Map();
    const all = Array.isArray(this.domain?.recipe_count) ? this.domain.recipe_count : [];

    // Same "fixed scope, not a runtime toggle" reasoning as the class
    // header comment — filters to this.task, set once at construction.
    // this.task === 0 (no task attribute given) shows everything, same
    // "no filter configured" default BatchHistory's own date filter falls
    // back to if unset.
    const scoped = this.task
      ? all.filter(rc => Number(rc.task ?? 0) === this.task)
      : all;

    const sorted = [...scoped].sort((a, b) =>
      String(b.post_date ?? "").localeCompare(String(a.post_date ?? ""))
    );

    this.rows = sorted.map((rc) => {
      const recipeId   = Number(rc.recipe ?? 0);
      const recipeName = this._recipesById?.get?.(recipeId)?._title ?? "—";

      return {
        id: rc.id,
        recipe: { display: recipeName,               value: recipeName },
        count:  { display: this._fmtCount(rc.count),  value: Number(rc.count ?? 0) },
        done:   { display: rc.done ? 'Yes' : 'No',    value: !!rc.done },
      };
    });

    return this.rows;
  }

  _fmtCount(raw) {
    if (raw == null || raw === "") return "";
    const n = Number(raw);
    if (!Number.isFinite(n)) return String(raw);
    return Number.isInteger(n) ? String(n) : n.toFixed(2);
  }
}
