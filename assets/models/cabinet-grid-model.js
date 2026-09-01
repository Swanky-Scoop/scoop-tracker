import BaseGridModel from "./_base-grid-model.js";

export default class CabinetGridModel extends BaseGridModel{
  constructor(name = 'Cabinet', domain, attrs = {})
  {
    // No own-title link on a slot row, so BaseGridModel's
    // _ensureRowDetailAccess() would otherwise prepend a pencil "Details"
    // icon column by default — suppressed per Gus (2026-09-01): a slot's
    // own row doesn't need a details-view affordance. Must go through the
    // constructor's options (read by BaseGridModel before it builds
    // columns), not a this.* assignment after super() — by then the
    // column-build the flag is meant to affect has already run.
    super(name, domain, { ...attrs, suppressRowEditIcon: true });
    // Save each change immediately, no save button, no full page reload.
    this.autosave = true;
  }

  // Second-location support: a FindIt-style filter (see _list.js's
  // _buildFilters 'find' branch, and BaseGridModel's _locationFilterDef/
  // getFilterValue/setFilterValue) that shows this grid's current location
  // by name and lets you switch to any other, or to every location at once.
  // 'client' mode — the bundle already fetches every location's
  // cabinets/slots unfiltered (nothing on the client sends a `location`
  // request param, despite bundle-fetch.php supporting it server-side);
  // filterByLocation() in buildRows() is what narrows to this.location, so
  // switching it is just a client-side re-filter, no refetch needed.
  getFilterDefs() {
    return [this._locationFilterDef()];
  }

  buildRows() {
    if (!this.domain) return [];

    // NOTE: this.metaData.primary is 'slot' for this route (writes target
    // slot, not cabinet — see _config.php's Cabinet.pod_name), so it can't
    // be used here. Cabinet groups/locations come from domain.cabinet
    // directly, not the generic primary-entity convention.
    const cabinets = this.domain.cabinet || [];
    const cabinetIds = new Set(this.filterByLocation(cabinets).map(c => c.id));

    const rtn = this.buildGroupedRows({
      groupsMap     : this._slotsByCabinetId,
      includeGroupId: (id)   => Number(id) > 0 && cabinetIds.has(Number(id)),
      getGroupLabel : (id) => this.labelFromMap(id, this._cabinetsById),
      fillRow       : (row, item, i) => { this.fillRowFromColumns(row, item, i); },
      groupType     : 'cabinet',
      rowType       : 'slot'
    });
    return rtn;
  }

}