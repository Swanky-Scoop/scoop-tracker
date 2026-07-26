///////////////////////////////////
// Grid — table view. Concrete List subclass: supplies the five DOM hooks
// (buildCoreDom/buildMetaFieldDom/buildGroupDom/buildItemDom/buildFieldDom),
// everything else (sort, filter, autosave, submit, Details drill-down,
// domain refresh) lives in List (./_list.js).
//
// this.TABLE is kept as a named alias for this.FRAME — popular-plot.js reads
// grid.TABLE directly for its own layout, so that property name is a public
// contract, not just an internal implementation detail.
//////////////////////////////////

import List from "./_list.js";

export default class Grid extends List {

  buildCoreDom() {
    const el = this.el;

    this.FORM    = el('form',   { classes: ['zGRID-form'] });
    this.TOGGLE  = el('button', { text: 'x', classes: ['gridToggle'] });
    this.FILTERS = el('div',    { classes: ['gridFilters', 'empty'] });
    this.SUBMIT  = el('button', { classes: ['save'], text: 'save', attrs: { type: 'submit' } });
    this.TABLE   = el('table',  { classes: ['zGRID'] });
    this.THEAD   = el('thead');
    this.TRH     = el('tr');

    this.THEAD.append(this.TRH);
    this.TABLE.append(this.THEAD);

    this.FRAME = this.TABLE;
    this.TOOLS = this.TRH;
  }

  buildMetaFieldDom(field) {
    return this.el('th', {
      text: field.label ?? field.key,
      classes: [field.key, field.type, 'sortable'],
      data: { sortKey: field.key },
    });
  }

  // group.label falsy => the synthetic ungrouped container: a bare <tbody>,
  // no header row.
  buildGroupDom(group, fields, opened) {
    const el = this.el;

    const TBODY = el('tbody', {
      classes: ['groupBody', this._groupTypeClass(group), (group.collapsible ? 'collapsible' : 'static'), (opened ? 'opened' : 'closed')],
      data: { rowType: group.rowType, groupType: group.groupType, groupContainer: '', groupId: group.groupId },
    });

    if (!group.label) return TBODY;

    const GR = el('tr', { classes: ['group'], data: { rowId: group.groupId, groupLabel: group.label } });
    const GD = el('th', { classes: ['groupCell'], attrs: { colSpan: fields.length } });
    const SP = el('b');
    const OC = group.collapsible ? el('button', { classes: ['oc'] }) : null;
    const LB = el('span', { text: group.label, classes: ['groupLabel'] });

    if (group.collapsible) SP.append(OC);
    if (group.badges && group.badges[0]) GD.append(this._getBadgeDom(group.badges));

    SP.append(LB);
    GD.append(SP);
    GR.append(GD);
    TBODY.append(GR);

    return TBODY;
  }

  buildItemDom(row) {
    return this.el('tr', { classes: ['row'], data: { rowId: row?.id?.rowId ?? row?.id ?? 0 } });
  }

  buildFieldDom(col, data) {
    const CELL = this.el('td', { classes: ['cell'] });

    // Table cells stay terse: any multi-value ('ids') field shows a count,
    // not the id list. Tile shows the fuller list — see tile.js.
    if (col.dataType === 'ids') return this._renderIdsCount(CELL, col, data);

    return this._renderFieldValue(CELL, col, data);
  }

  _renderIdsCount(CELL, col, data) {
    const ids = Array.isArray(data?.display) ? data.display : [];

    CELL.classList.add(col.key, 'ids-count', 'read-only');
    if (col.hidden) CELL.classList.add('hidden');
    CELL.append(String(ids.length));

    return CELL;
  }

}
