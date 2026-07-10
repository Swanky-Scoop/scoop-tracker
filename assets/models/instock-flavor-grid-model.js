import BaseGridModel from "./_base-grid-model.js";

export default class InstockFlavorGridModel extends BaseGridModel {
  constructor(name = 'InstockFlavor', domain, attrs = {}) {
    super(name, domain, attrs);
    this.filter = true; // enables text find-in-grid
  }

  // Columns come straight from SCOOP.metaData.InstockFlavor (server-driven,
  // see BaseGridModel.buildCols()) — menu_board, photo, tubs, current_slots,
  // allergens, web_id, in that order.

  buildRows() {
    super.buildRows();
    for (const row of this.rows) this._applyCounts(row);
    return this.rows;
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
