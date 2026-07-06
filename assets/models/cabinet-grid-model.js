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
    // Access the primary entity from domain
    const primary = this.metaData?.primary || 'cabinet';
    const items = this.domain[primary] || [];
    
    const cabinetIds = this.filterByLocation(items);

    const rtn = this.buildGroupedRows({
      groupsMap     : this._slotsByCabinetId,
      includeGroupId: (id)   => Number(id) > 0,    
      getGroupLabel : (id) => this.labelFromMap(id, this._cabinetsById),
      fillRow       : (row, item, i) => { this.fillRowFromColumns(row, item, i); },
      groupType     : 'cabinet',
      rowType       : 'slot'
    });
    return rtn;
  }

}