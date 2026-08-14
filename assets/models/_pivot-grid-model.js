///////////////////////////////////
// PivotGridModel — abstract base for a row-bucket x column-bucket matrix
// view (rows and columns are both derived from the data, not from server
// metaData columns — see item-pivot-grid-model.js for the first concrete
// child). Mechanics only: bucket a flat item list into a matrix, drop
// row/column buckets nothing landed in, then hand rows to
// BaseGridModel.buildGroupedRows() the same way every other grid does.
//
// A concrete child must implement, in matching pairs per axis:
//   primaryEntityKey()  -> which domain[] array is "the items" (e.g. 'tub')
//   getRowDefs(items)   -> ordered [{ key, label, groupKey, groupLabel, forceShow }]
//   rowKeyFor(item)     -> which row bucket (a getRowDefs().key) this item falls into
//   getColumnDefs(items)-> ordered [{ key, label, groupKey, groupLabel, forceShow }]
//   colKeyFor(item)     -> which column bucket (a getColumnDefs().key) this item falls into
//
// Row and column defs are deliberately the SAME shape (not just superficially
// — groupKey/groupLabel/forceShow mean the same thing on both) precisely so
// a concrete model can flip which axis is which by swapping which pair of
// hooks returns what, without reshaping any data. Only the buildGroupedRows()
// call below is row-specific (its group header is native, generic List/Grid
// machinery — a live example of what colDef.groupKey drives is
// item-pivot-grid.js's own second header row, since columns have no
// built-in equivalent to reuse).
//
// forceShow (optional, on either a rowDef or a colDef) bypasses the "hide if
// nothing landed in this bucket" check below — e.g. a concrete model letting
// a user explicitly ask to see a bucket it would otherwise auto-hide as
// empty. Whichever axis the concrete model puts its checkbox-driven buckets
// on is the one that needs it; the other axis can leave it unset.
//
// getItems() defaults to domain[primaryEntityKey()] but is overridable for
// pre-bucketing filtering (e.g. a recency window) — see getRowDefs/rowKeyFor,
// which run against whatever getItems() returns, not the raw domain array.
//
// Every row also carries a synthetic leading '_row_label' column so a plain
// Grid/Tile subclass can render the bucket's label through the same
// per-field loop as everything else — see item-pivot-grid.js.
//////////////////////////////////
import BaseGridModel from "./_base-grid-model.js";

export default class PivotGridModel extends BaseGridModel {
  constructor(name, domain = null, options = {}) {
    super(name, domain, options);

    // Every pivot's columns are rebuilt from scratch in buildRows() (see
    // this file's header comment) — never a stable list, so
    // List._onDomainUpdated's additive _patchRefresh path (which patches
    // existing rows by iterating a model's columns) has nothing to work
    // with and silently can't update a bucket's contents on a refresh this
    // grid didn't itself cause. Same fix, same reasoning as
    // CabinetWorkflowGridModel's constructor — opt into a real rebuild
    // every time instead of the generic patch.
    this.rebuildOnRefresh = true;
  }

  primaryEntityKey() {
    throw new Error(`${this.constructor.name}.primaryEntityKey() must be overridden — return the domain[] key holding this pivot's items.`);
  }

  getRowDefs(items) {
    throw new Error(`${this.constructor.name}.getRowDefs() must be overridden — return ordered [{ key, label, groupKey, groupLabel }].`);
  }

  rowKeyFor(item) {
    throw new Error(`${this.constructor.name}.rowKeyFor() must be overridden — return the getRowDefs() key this item belongs to.`);
  }

  getColumnDefs(items) {
    throw new Error(`${this.constructor.name}.getColumnDefs() must be overridden — return ordered [{ key, label }].`);
  }

  colKeyFor(item) {
    throw new Error(`${this.constructor.name}.colKeyFor() must be overridden — return the getColumnDefs() key this item belongs to.`);
  }

  getItems() {
    const key = this.primaryEntityKey();
    return Array.isArray(this.domain?.[key]) ? this.domain[key] : [];
  }

  buildRows() {
    if (!this.domain) return [];

    const items = this.getItems();
    const rowDefs = this.getRowDefs(items);
    const colDefs = this.getColumnDefs(items);

    // item -> matrix.get(rowKey).get(colKey) -> item[]
    const matrix = new Map();
    for (const item of items) {
      const rowKey = this.rowKeyFor(item);
      const colKey = this.colKeyFor(item);
      if (!rowKey || !colKey) continue;

      if (!matrix.has(rowKey)) matrix.set(rowKey, new Map());
      const rowMap = matrix.get(rowKey);
      if (!rowMap.has(colKey)) rowMap.set(colKey, []);
      rowMap.get(colKey).push(item);
    }

    // Hide columns nothing landed in — unless the column itself says not to
    // (colDef.forceShow, see the header comment above).
    const usedColKeys = new Set();
    for (const rowMap of matrix.values()) {
      for (const [colKey, cellItems] of rowMap) {
        if (cellItems.length) usedColKeys.add(colKey);
      }
    }
    const usedColDefs = colDefs.filter(c => usedColKeys.has(c.key) || c.forceShow);
    // getColumnDefs() can itself narrow columns beyond "has any items" (e.g.
    // a flavor-name search filter, see item-pivot-grid-model.js) — a row's
    // visibility must follow the SAME narrowed set, or a row whose only
    // items are in a now-filtered-out column would render as a label with
    // no squares instead of being hidden.
    const usedColKeySet = new Set(usedColDefs.map(c => c.key));

    // Synthetic leading column: the bucket's own label, rendered through the
    // same per-field loop every other column goes through (see
    // item-pivot-grid.js's buildFieldDom/buildMetaFieldDom '_row_label' case).
    this.columns = [{ key: '_row_label', label: '' }, ...usedColDefs];

    // Hide row buckets nothing landed in, grouped by groupKey (order
    // preserved from getRowDefs()).
    const groupsMap = new Map();
    const groupLabels = new Map();
    for (const rowDef of rowDefs) {
      const rowMap = matrix.get(rowDef.key);
      const hasAny = rowMap && [...rowMap.entries()].some(([colKey, list]) => usedColKeySet.has(colKey) && list.length);
      if (!hasAny && !rowDef.forceShow) continue;

      groupLabels.set(rowDef.groupKey, rowDef.groupLabel);
      const list = groupsMap.get(rowDef.groupKey) ?? [];
      list.push(rowDef);
      groupsMap.set(rowDef.groupKey, list);
    }

    return this.buildGroupedRows({
      groupsMap,
      includeGroupId: () => true,
      getGroupLabel: (groupKey) => groupLabels.get(groupKey) ?? String(groupKey),
      makeRowId: (rowDef) => rowDef.key,
      fillRow: (row, rowDef) => {
        row._row_label = rowDef.label;
        const rowMap = matrix.get(rowDef.key) ?? new Map();
        for (const col of usedColDefs) {
          row[col.key] = rowMap.get(col.key) ?? [];
        }
      },
      groupType: 'pivot-group',
      rowType: 'pivot-row',
      rowLabel: 'pivot-row',
      collapsible: true,
      collapsed: false,
    });
  }
}
