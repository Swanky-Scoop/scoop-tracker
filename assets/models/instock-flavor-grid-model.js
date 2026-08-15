import BaseGridModel from "./_base-grid-model.js";
import Indexer       from "../data/indexer.js";

export default class InstockFlavorGridModel extends BaseGridModel {
  constructor(name = 'InstockFlavor', domain, attrs = {}) {
    super(name, domain, attrs);
    this.filter = true; // enables text find-in-list

    // From the shortcode's data-group/data-filter (see includes/shortcode.php).
    // No attributes => show every flavor, flat/ungrouped.
    this.group = attrs?.group ? String(attrs.group).trim().toLowerCase() : null;
    this.rowFilters = new Set(
      String(attrs?.filters ?? '')
        .split(',')
        .map(s => s.trim().toLowerCase())
        .filter(Boolean)
    );
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

  // tubs/current_slots stay as raw id arrays on the row (no count-collapsing
  // here); Grid and Tile each decide for themselves how much of that to show
  // (see grid.js buildFieldDom and tile.js buildFieldDom).
  //
  // group="cabinet" / filter="tubs" on the shortcode (see includes/shortcode.php)
  // opt into grouping-by-cabinet and/or excluding flavors with no active tubs.
  // With neither, every flavor shows, flat.
  buildRows() {
    if (!this.domain) return [];

    const allFlavors = Array.isArray(this.domain.flavor) ? this.domain.flavor : [];
    const flavors = this.rowFilters.has('tubs')
      ? allFlavors.filter(f => (this._availByFlavor.get(f.id)?.length ?? 0) > 0)
      : allFlavors;

    if (this.group === 'cabinet') {
      return this._buildRowsGroupedByCabinet(flavors);
    }

    return this._buildFlatRows(flavors);
  }

  _buildFlatRows(flavors) {
    this.rows = flavors.map((f, i) => {
      const row = { id: f.id };
      this._fillFlavorRow(row, f, i);
      return row;
    });
    this.rowGroups = [];
    return this.rows;
  }

  // Groups by the cabinet each flavor's current_slots point at — flavors
  // with no resolvable cabinet fall into an "Others" bucket.
  _buildRowsGroupedByCabinet(flavors) {
    const slotsById = Indexer.byId(this.domain.slot ?? []);
    const OTHERS = 'others';

    const groupsMap = new Map();
    for (const flavor of flavors) {
      const cabinetId = this._cabinetIdForFlavor(flavor, slotsById) || OTHERS;
      const list = groupsMap.get(cabinetId) ?? [];
      list.push(flavor);
      groupsMap.set(cabinetId, list);
    }

    return this.buildGroupedRows({
      groupsMap     : this._sortGroupsOthersLast(groupsMap, OTHERS),
      includeGroupId: () => true,
      getGroupLabel : (id) => id === OTHERS ? 'Others' : this.labelFromMap(id, this._cabinetsById),
      makeRowId     : (f) => f.id,
      fillRow       : (row, f, i) => this._fillFlavorRow(row, f, i),
      groupType     : 'cabinet',
      rowType       : 'flavor',
      rowLabel      : 'flavor',
      collapsible   : true,
      collapsed     : false,
    });
  }

  _cabinetIdForFlavor(flavor, slotsById) {
    const slotIds = Array.isArray(flavor.current_slots) ? flavor.current_slots : [];
    for (const slotId of slotIds) {
      const slot = slotsById.get(Number(slotId));
      const cabinetId = Number(slot?.cabinet ?? 0);
      if (cabinetId > 0) return cabinetId;
    }
    return 0;
  }

  // "Others" always sorts last regardless of insertion order.
  _sortGroupsOthersLast(groupsMap, othersKey) {
    const entries = [...groupsMap.entries()].sort((a, b) => {
      if (a[0] === othersKey) return 1;
      if (b[0] === othersKey) return -1;
      return 0;
    });
    return new Map(entries);
  }

  _fillFlavorRow(row, flavor, i) {
    this.fillRowFromColumns(row, flavor, i);
    this._applyTitleLink(row);
  }

  // fillRowFromColumns() sets cell.id = Number(raw), but raw here is the
  // title string (not a foreign key) — fix it up to the flavor's own post id
  // so the Details link points at the right item.
  _applyTitleLink(row) {
    if (row._title) row._title.id = row.id;
  }
}
