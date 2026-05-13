import BaseGridModel from "./_base-grid-model.js";
import Indexer       from "../data/indexer.js";

const DESIGNATION_FIELDS = [
  { field: "current_flavor", label: "current" },
  { field: "immediate_flavor", label: "immediate" },
  { field: "next_flavor", label: "next" },
];

export default class FlavorTubGridModel extends BaseGridModel{
  constructor(name = 'FlavorTub', domain, attrs = {}) 
  {
    super(name, domain, attrs );
    this.filter = true;    
    this._addDerivedColumns();
    this.setShowList(['designation', 'state', 'use', 'amount', 'author_name', 'date', 'post_modified']);
  }

  buildRows() {
    if (!this.domain) return [];
    
    // Access the primary entity from domain
    const primary = this.metaData?.primary || 'tub';
    const items = this.domain[primary] || [];
    
    const locationTubIds = this.filterByLocation(items)  // ← Use items, not this.domain
        .filter(t => t.state !== "Emptied");
    
    const tubsByFlavorId = Indexer.groupBy(locationTubIds, t => t.flavor);
    const designationsByFlavorId = this._designationsByFlavorId(this.domain.slot ?? []);
    
    return this.buildGroupedRows({
        groupsMap     : tubsByFlavorId,
        includeGroupId: (id)   => Number(id) > 0,
        getGroupLabel : (id)   => this.labelFromMap(id, this._flavorsById),
        makeRowId     : (item) => item.id,
        getGroupBadges: (items, flavorId) => [
          ...this.getBadges(flavorId, 'flavor'),
          this._designationBadge(designationsByFlavorId.get(Number(flavorId)) ?? []),
        ],
        fillRow       : (row, item, i) => {
          this.fillRowFromColumns(row, item, i);
          this._fillDesignationCell(row, item, designationsByFlavorId);
        },
        collapsed     : true,
        groupType     :'flavor',
        rowType       :'tub',
        rowLabel      :'tub',
    });
  }

  _addDerivedColumns() {
    this._allColumns ??= [];

    if (!this._allColumns.some(col => col.key === "designation")) {
      this._allColumns.unshift({
        key: "designation",
        label: "Designation",
        type: "string",
        write: false,
      });
    }

    this._applyColumnFilter();
  }

  _designationsByFlavorId(slots = []) {
    const out = new Map();

    for (const { field, label } of DESIGNATION_FIELDS) {
      for (const slot of slots) {
        const flavorId = Number(slot?.[field] ?? 0);
        if (!flavorId) continue;

        const labels = out.get(flavorId) ?? [];
        if (!labels.includes(label)) labels.push(label);
        out.set(flavorId, labels);
      }
    }

    return out;
  }

  _fillDesignationCell(row, item, designationsByFlavorId) {
    const flavorId = Number(item?.flavor ?? 0);
    const designations = designationsByFlavorId.get(flavorId) ?? [];

    row.designation = {
      rowId: item?.id ?? 0,
      display: designations.length ? designations.join(", ") : "none",
      value: designations,
      type: "string",
      colKey: "designation",
      write: false,
    };
  }

  _designationBadge(designations = []) {
    const text = designations.length ? designations.join("/") : "none";
    return {
      key: designations.length ? "designation" : "designation-none",
      text,
      title: "Designation",
    };
  }

}
