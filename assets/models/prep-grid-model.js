import BaseGridModel from "./_base-grid-model.js";

// Single blank "create new prep" row — same "one relation + a count" shape
// as BatchGridModel, plus 'units' (a second relation, → the 'unit' pod) and
// a free-text 'other' notes field. Used embedded inside the Task form's
// "Ingredient prep" widget (assets/ui/task-form.js), not mounted as its own
// [scoop_grid] host.
export default class PrepGridModel extends BaseGridModel {

  constructor(name = 'Prep', domain, attrs = {}, metaData = null)
  {
    super(name, domain, attrs, metaData);
    this.submitMode = 'all';
    this.repaintOnRefresh = false;
    this._build();
  }

  buildCols() {
    this.columns = [
      { key: "ingredient", label: "ingredient", write: true, type: "ingredient", titleMap: "ingredient" },
      { key: "count", label: "count", write: true, control: "text", type: "number", step: 0.01 },
      // 'unit' is the domain/entity key (see includes/_specs.php); 'units'
      // is the Pods field name this posts as (scoop_preps_allowed_fields())
      // — colKey below stays 'units' so the payload lands on the right field.
      { key: "units", label: "units", write: true, type: "unit", titleMap: "unit" },
      { key: "other", label: "notes", write: true, control: "text", type: "string" },
    ];
    return this.columns;
  }

  buildRows() {
    const rowId = 0;

    this.rows = [
      {
        id: rowId,
        ingredient: {
          id: 0,
          rowId,
          colKey: "ingredient",
          type: "ingredient",
          display: "",
          options: this.getOptions(0, "ingredient"),
          badges: []
        },
        count: {
          rowId,
          colKey: "count",
          type: "number",
          step: 0.01,
          value: ""
        },
        units: {
          id: 0,
          rowId,
          colKey: "units",
          type: "unit",
          display: "",
          options: this.getOptions(0, "unit"),
          badges: []
        },
        other: {
          rowId,
          colKey: "other",
          type: "string",
          value: ""
        }
      }
    ];

    return this.rows;
  }
}
