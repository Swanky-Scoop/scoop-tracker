import BaseGridModel from "./_base-grid-model.js";

export default class InstockFlavorGridModel extends BaseGridModel {
  constructor(name = 'InstockFlavor', domain, attrs = {}) {
    super(name, domain, attrs);
    this.filter = true; // enables text find-in-grid
  }

  // Columns come straight from SCOOP.metaData.InstockFlavor (server-driven,
  // see BaseGridModel.buildCols()) — menu_board, photo, tubs, current_slots,
  // allergens, web_id, in that order. _title (flavor post title) isn't a
  // Pods field so it isn't in metaData; prepend it here.
  buildCols() {
    super.buildCols();

    // detailEntity (not titleMap) opts this cell into the Details-panel link
    // in Grid._getCellDom() without going through titleMap's id-lookup
    // display logic — this cell's raw value already IS the title text.
    const titleCol = { key: "_title", label: "Flavor", dataType: "string", control: "input", hidden: false, visible: true, write: false, detailEntity: "flavor" };
    this._allColumns = [titleCol, ...(this._allColumns ?? [])];
    this._applyColumnFilter();
    return this.columns;
  }

  buildRows() {
    super.buildRows();
    for (const row of this.rows) {
      this._applyCounts(row);
      this._applyTitleLink(row);
    }
    return this.rows;
  }

  // fillRowFromColumns() sets cell.id = Number(raw), but raw here is the
  // title string (not a foreign key) — fix it up to the flavor's own post id
  // so the Details link points at the right item.
  _applyTitleLink(row) {
    if (row._title) row._title.id = row.id;
  }

  // tubs / current_slots arrive as relationship-id arrays; show a count
  // rather than the raw id list (filtering by those tubs is a later step).
  _applyCounts(row) {
    for (const key of ['tubs', 'current_slots']) {
      const cell = row[key];
      if (!cell) continue;

      const ids = Array.isArray(cell.display) ? cell.display : [];
      cell.value = ids.length;
      cell.display = String(ids.length);
    }
  }
}
