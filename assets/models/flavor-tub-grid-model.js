import BaseGridModel from "./_base-grid-model.js";
import Indexer       from "../data/indexer.js";

const DESIGNATION_FIELDS = [
  { field: "current_flavor", label: "current" },
  { field: "immediate_flavor", label: "immediate" },
  { field: "next_flavor", label: "next" },
];

const FRONT_OF_HOUSE_USE_ID = 1863;

export default class FlavorTubGridModel extends BaseGridModel{
  constructor(name = 'FlavorTub', domain, attrs = {}) 
  {
    super(name, domain, attrs );
    this.filter = true;    
    this.setShowList(['state', 'use', 'amount', 'author_name', 'post_modified']);
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
          this._useBadge(items),
        ].filter(Boolean),
        fillRow       : (row, item, i) => {
          this.fillRowFromColumns(row, item, i);
        },
        collapsed     : true,
        groupType     :'flavor',
        rowType       :'tub',
        rowLabel      :'tub',
    });
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

  _designationBadge(designations = []) {
    const text = designations.length ? designations.join("/") : "none";
    return {
      key: designations.length ? "designation" : "designation-none",
      text,
      title: "Designation",
    };
  }

  _useBadge(items = []) {
    const labels = [];

    for (const item of items ?? []) {
      const useId = Number(item?.use ?? 0);
      if (!useId || this._isFrontOfHouseUse(useId)) continue;

      const label = this.titleFrom(useId, { titleMap: "use" }) || `Use ${useId}`;
      if (label && !labels.includes(label)) labels.push(label);
    }

    if (!labels.length) return null;

    return {
      key: "use",
      text: labels.join("/"),
      title: "Uses other than Front-of-House",
    };
  }

  _isFrontOfHouseUse(useId) {
    if (Number(useId) === FRONT_OF_HOUSE_USE_ID) return true;

    const normalized = this._normalizeUseLabel(this.titleFrom(useId, { titleMap: "use" }));
    return normalized === "front of house";
  }

  _normalizeUseLabel(label = "") {
    return String(label)
      .toLowerCase()
      .replace(/&amp;/g, "&")
      .replace(/[-_]+/g, " ")
      .replace(/\s+/g, " ")
      .trim();
  }

}
