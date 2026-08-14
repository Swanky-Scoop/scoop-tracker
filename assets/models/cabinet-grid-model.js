import BaseGridModel from "./_base-grid-model.js";

export default class CabinetGridModel extends BaseGridModel{
  constructor(name = 'Cabinet', domain, attrs = {})
  {
    super(name, domain, attrs );
    // Save each change immediately, no save button, no full page reload.
    this.autosave = true;
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