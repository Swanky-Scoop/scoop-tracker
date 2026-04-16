import BaseGridModel from "./_base-grid-model.js";

export default class DateActivityGridModel extends BaseGridModel{
  constructor(name, domain, attrs){
    super(name, domain, attrs );
    this._build();
  }

  buildRows() {
    if (!this.domain) return [];

    // Access the primary entity from domain
    const primary = this.metaData?.primary || 'tub';
    const items = this.domain[primary] || [];
    
    const locationTubIds = this.filterByLocation(items);
    
    // Filter for items modified in last 48 hours
    const now = Date.now();
    const fortyEightHoursAgo = now - (48 * 60 * 60 * 1000);
    
    const recentItems = locationTubIds.filter(item => {
      if (!item.post_modified) return false;
      const modifiedDate = new Date(item.post_modified).getTime();
      return modifiedDate >= fortyEightHoursAgo;
    });
    
    // Build rows from filtered items
    this.rows = [];
    recentItems.forEach((item, i) => {
      const row = { id: item.id };
      this.fillRowFromColumns(row, item, i);
      this.rows.push(row);
    });
    
    return this.rows;
  }
}