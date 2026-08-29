import BaseGridModel from "./_base-grid-model.js";

// Shared base for the Batch/BatchHistory-pattern's "create" half — a
// single blank row picking one relationship (via FindIt, `control:"find"`)
// plus a count. Extracted after building RecipeCountGridModel field-for-
// field identical to BatchGridModel (flavor<->recipe) and finding the ONLY
// difference between them was the one relation field name — everything
// else (columns shape, row shape, submitMode, repaintOnRefresh) was
// character-for-character the same. A concrete type just names its
// relation field; this class does the rest.
//
// Not (yet) generalizing the history half the same way — BatchHistory
// (date-range filter, delete action, author/created columns) and
// RecipeCountHistory (fixed task scope, done column, no delete) diverge
// more than the create grids did. Revisit once a third instance makes the
// common shape there clearer too, same "don't generalize from one example"
// reasoning that held off generalizing this half until RecipeCount existed
// to compare against Batch.
export default class SingleRelationCountGridModel extends BaseGridModel {

  // relationField: the Pods relationship field name this grid's one picker
  // column writes to (e.g. 'flavor', 'recipe') — also doubles as the
  // FindIt `type` used by getOptions()'s domain-backed lookup (see
  // _base-grid-model.js), so it only works out of the box for a field name
  // that's also a real bundle domain key. flavor is a special case
  // (getOptions() reads flavorMeta instead of domain.flavor directly, for
  // badge/eligibility data recipe/ingredient/etc. don't need) — everything
  // else rides the generic domain-array fallback added alongside
  // RecipeCountGridModel.
  constructor(name, relationField, domain, attrs = {}, metaData = null) {
    super(name, domain, attrs, metaData);
    this.relationField = relationField;
    this.submitMode = 'all';
    // Always a single blank "create new" row, not a view onto persisted
    // data — a background refresh from some other grid's save has nothing
    // here to bring up to date, so don't let it repaint mid-typing. See
    // the repaintOnRefresh check in _list.js's _onDomainUpdated.
    this.repaintOnRefresh = false;
    this._build();
  }

  buildCols() {
    this.columns = [
      { key: this.relationField, label: this.relationField, write: true, type: this.relationField, titleMap: this.relationField },
      { key: "count", label: "count", write: true, control: "text", type: "number", step: 0.01 },
    ];
    return this.columns;
  }

  buildRows() {
    // single row
    const rowId = 0;
    const field = this.relationField;

    this.rows = [
      {
        id: rowId,
        // relation cell (FindIt expects id/display/options/etc.)
        [field]: {
          id: 0,
          rowId,
          colKey: field,
          type: field,
          display: "",
          options: this.getOptions(field, field, 0),
          badges: [],
        },
        // count cell (TextIt expects value/display + rowId/colKey/type)
        count: {
          rowId,
          colKey: "count",
          type: "number",
          step: 0.01,
          value: "", // default blank
        },
      },
    ];

    return this.rows;
  }
}
