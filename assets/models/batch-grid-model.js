import BaseGridModel from "./_base-grid-model.js";
import Indexer       from "../data/indexer.js";

export default class BatchGridModel extends BaseGridModel{

  constructor(name = 'Batch', domain, attrs = {}, metaData = null) 
  {
    super(name, domain, attrs, metaData );
    this._flavorsById  = Indexer.byId(domain?.flavor) || {};
    this.submitMode = 'all';
    // Always a single blank "create new batch" row, not a view onto
    // persisted data — a background refresh from some other grid's save has
    // nothing here to bring up to date, so don't let it repaint mid-typing.
    // See the repaintOnRefresh check in _list.js's _onDomainUpdated.
    this.repaintOnRefresh = false;
    // Opt into _list.js's synchronous reset-to-blank + refocus-first-field,
    // fired the moment Save is clicked rather than waiting on the POST —
    // Batch is a repeat-entry workflow (add several batches in a row), so
    // the form should go back to blank with focus on `flavor` right away
    // instead of leaving the just-submitted values sitting there.
    // See the saveReset branch in _list.js's FORM submit listener.
    this.saveReset = true;
    this._build();
  }

  buildCols() {
    this.columns = [
      { key: "flavor", label: "flavor", write: true, type: "flavor", titleMap: "flavor" },
      { key: "count", label: "count", write: true, control: "text", type: "number", step:0.01 }
    ];
    return this.columns;
  }
  
  buildRows() {
    // single row
    const rowId = 0;
    
    this.rows = [
      {
        id: rowId,
        // flavor cell (FindIt expects id/display/options/etc.)
        flavor: {
          id: 0,
          rowId,
          colKey: "flavor",
          type: "flavor",
          display: "",
          options: this.getOptions("flavor", "flavor", 0),
          badges: []
        },
        // count cell (TextIt expects value/display + rowId/colKey/type)
        count: { 
          rowId,
          colKey: "count",
          type: "number",
          step: 0.01,
          value: ""          // default blank
        }        
      }
    ];

    return this.rows;
  } 

}