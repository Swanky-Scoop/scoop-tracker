import BaseGridModel from "./_base-grid-model.js";
import HashState      from "../data/hash-state.js";

export default class CabinetGridModel extends BaseGridModel{
  constructor(name = 'Cabinet', domain, attrs = {})
  {
    super(name, domain, attrs );
    // Save each change immediately, no save button, no full page reload.
    this.autosave = true;
  }

  // Second-location support: a FindIt-style filter (see _list.js's
  // _buildFilters 'find' branch) that shows this grid's current location by
  // name and lets you switch to any other. 'client' mode — the bundle
  // already fetches every location's cabinets/slots unfiltered (nothing on
  // the client sends a `location` request param, despite bundle-fetch.php
  // supporting it server-side); filterByLocation() in buildRows() is what
  // narrows to this.location, so switching it is just a client-side
  // re-filter, no refetch needed.
  getFilterDefs() {
    const locations = this.domain?.location || [];
    return [
      {
        key: 'location',
        label: 'Location',
        type: 'find',
        mode: 'client',
        options: locations.map(l => ({ key: l.id, label: l._title || `Location ${l.id}` })),
      },
    ];
  }

  getFilterValue(key) {
    return key === 'location' ? this.location : undefined;
  }

  setFilterValue(key, value) {
    if (key !== 'location') return;
    const id = Number(value);
    if (!id || id === this.location) return;
    this.location = id;
    // Per _resolveLocation()'s cascade (scoop-api.js) — the "in-GUI location
    // picker" tier it was already documented to expect. Persists the choice
    // across reload/sharing without affecting any other grid on the page.
    HashState.set(`loc.${this.name}`, id);
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