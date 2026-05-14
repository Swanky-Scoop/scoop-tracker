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
    this.filterValues = {
      designation: 'all',
      use_badge: 'all',
    };
    this.setShowList(['index', 'state', 'use', 'amount', 'author_name', 'post_modified']);
  }

  getFilterDefs() {
    return [
      {
        key: 'designation',
        label: 'Designation',
        type: 'select',
        mode: 'client',
        default: 'all',
        options: [
          { key: 'all', label: 'All designations' },
          { key: 'none', label: 'None' },
          { key: 'current', label: 'Current' },
          { key: 'immediate', label: 'Immediate' },
          { key: 'next', label: 'Next' },
        ],
      },
      {
        key: 'use_badge',
        label: 'Use',
        type: 'select',
        mode: 'client',
        default: 'all',
        options: this._useFilterOptions(),
      },
    ];
  }

  setFilterValue(key, value) {
    if (!key) return;
    this.filterValues[key] = String(value ?? 'all');
  }

  getFilterValue(key) {
    return this.filterValues?.[key] ?? 'all';
  }

  buildRows() {
    if (!this.domain) return [];
    
    // Access the primary entity from domain
    const primary = this.metaData?.primary || 'tub';
    const items = this.domain[primary] || [];
    
    const locationTubIds = this._activeTubs(items);
    
    const tubsByFlavorId = Indexer.groupBy(locationTubIds, t => t.flavor);
    const designationsByFlavorId = this._designationsByFlavorId(this.domain.slot ?? []);
    const filteredTubsByFlavorId = this._filterGroups(tubsByFlavorId, designationsByFlavorId);
    
    return this.buildGroupedRows({
        groupsMap     : filteredTubsByFlavorId,
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

  _activeTubs(items = []) {
    return this.filterByLocation(items)
      .filter(t => t.state !== "Emptied");
  }

  _filterGroups(groupsMap, designationsByFlavorId) {
    const out = new Map();

    for (const [flavorId, items] of groupsMap ?? new Map()) {
      const designations = designationsByFlavorId.get(Number(flavorId)) ?? [];
      if (this._groupMatchesFilters(items, designations)) out.set(flavorId, items);
    }

    return out;
  }

  _groupMatchesFilters(items = [], designations = []) {
    const designationFilter = this.getFilterValue('designation');
    if (designationFilter !== 'all') {
      if (designationFilter === 'none') {
        if (designations.length) return false;
      } else if (!designations.includes(designationFilter)) {
        return false;
      }
    }

    const useFilter = this.getFilterValue('use_badge');
    if (useFilter === 'all') return true;

    const nonFrontUseIds = this._nonFrontUseIds(items);
    if (useFilter === 'any') return nonFrontUseIds.length > 0;

    const requestedUseId = Number(String(useFilter).replace(/^use:/, ''));
    return Number.isFinite(requestedUseId) && nonFrontUseIds.includes(requestedUseId);
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

    for (const useId of this._nonFrontUseIds(items)) {
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

  _useFilterOptions() {
    const options = [
      { key: 'all', label: 'All uses' },
      { key: 'any', label: 'Any non-Front-of-House' },
    ];

    const useIds = this._nonFrontUseIds(this._activeTubs(this.domain?.tub ?? []));

    for (const useId of useIds) {
      options.push({
        key: `use:${useId}`,
        label: this.titleFrom(useId, { titleMap: "use" }) || `Use ${useId}`,
      });
    }

    return options;
  }

  _nonFrontUseIds(items = []) {
    const ids = [];

    for (const item of items ?? []) {
      const useId = Number(item?.use ?? 0);
      if (!useId || this._isFrontOfHouseUse(useId)) continue;
      if (!ids.includes(useId)) ids.push(useId);
    }

    return ids;
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
