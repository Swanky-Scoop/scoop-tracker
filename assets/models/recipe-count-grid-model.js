import BaseGridModel from "./_base-grid-model.js";

// Single blank "create new recipe count" row — same shape as BatchGridModel,
// just a different relation field. Used embedded inside the Task form's
// "Recipe production" widget (assets/ui/task-form.js), not mounted as its
// own [scoop_grid] host.
export default class RecipeCountGridModel extends BaseGridModel {

  constructor(name = 'RecipeCount', domain, attrs = {}, metaData = null)
  {
    super(name, domain, attrs, metaData);
    this.submitMode = 'all';
    // Always a single blank row, not a view onto persisted data — see
    // BatchGridModel's identical comment.
    this.repaintOnRefresh = false;
    this._build();
  }

  buildCols() {
    this.columns = [
      { key: "recipe", label: "recipe", write: true, type: "recipe", titleMap: "recipe" },
      { key: "count", label: "count", write: true, control: "text", type: "number", step: 0.01 }
    ];
    return this.columns;
  }

  buildRows() {
    const rowId = 0;

    this.rows = [
      {
        id: rowId,
        recipe: {
          id: 0,
          rowId,
          colKey: "recipe",
          type: "recipe",
          display: "",
          options: this.getOptions(0, "recipe"),
          badges: []
        },
        count: {
          rowId,
          colKey: "count",
          type: "number",
          step: 0.01,
          value: ""
        }
      }
    ];

    return this.rows;
  }
}
