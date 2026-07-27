import BaseGridModel from "./_base-grid-model.js";
import Indexer       from "../data/indexer.js";

export default class CloseoutGridModel extends BaseGridModel{
  constructor(name = 'Closeout', domain, attrs = {}) 
  {
    super(name, domain, attrs );
      this.submitMode = 'all';
      // Always a single blank "create new closeout" row, not a view onto
      // persisted data — a background refresh from some other grid's save
      // has nothing here to bring up to date, so don't let it repaint
      // mid-typing. See the repaintOnRefresh check in _list.js's
      // _onDomainUpdated.
      this.repaintOnRefresh = false;
  }

  buildCols() {
    this.columns = [
      { key: "tubs_emptied", label: "count",    write: true, control: "text", type: "number" },
      { key: "flavor",       label: "flavor",   write: true, type: "flavor" },
      { key: "use",          label: "use",      write: true, type: "use" },
      { key: "location",     label: "location", write: true, type:"location"},
     // { key: "date",         label: "date",     write: true, hidden: true,  type:"date", value:new Date()}
    ];
    return this.columns;
  }
  
  buildRows() {
    // single row
    const rowId = 0;
    
    this.rows = [
      {
        id: rowId,

        // count cell (TextIt expects value/display + rowId/colKey/type)
        tubs_emptied: { 
          rowId,
          colKey: "tubs_emptied",
          type: "number",
          value: ""          // default blank
        },

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

        use: {
          id: 0,
          rowId,
          colKey: "use",
          type: "use",
          display: "",
          options: this.getOptions("use", "use", 0),
          badges: []
        },

        location:{
          id: 935,
          rowId,
          colKey: "location",
          type: "location",
          display: "Woodinville",
          options: this.getOptions("location", "location", 935),
          value: 935,
          badges: []
        }
      }
    ];

    return this.rows;
  } 

}