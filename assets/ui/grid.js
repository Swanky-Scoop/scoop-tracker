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

    this.FORM    = el('form',   { classes: ['zList-form'] });
    this.TOGGLE  = this._buildToggleButton();
    this.FILTERS = el('div',    { classes: ['gridFilters', 'empty'] });
    this.SUBMIT  = this._buildSubmitButton();
    this.TABLE   = el('table',  { classes: ['zList'] });
    this.THEAD   = el('thead');
    this.TRH     = el('tr');

    this.THEAD.append(this.TRH);
    this.TABLE.append(this.THEAD);

    // Only .action-target-docked grids (Batch/Task/Prep/RecipeCount/
    // Closeout — dockTarget:'action', see _config.php's 'target' entries —
    // and BatchHistory when embedded inside Batch, which gets dockTarget
    // forced to 'action' for exactly this reason, see
    // ScoopAPI._mountEmbeddedBatchHistory) get wrapped in a scrolling
    // container (see .action-target .zList-scroll in css.css). Every other
    // grid — critically, anything docked into <aside> (Cabinet) or sitting
    // in .canvas — keeps the exact DOM shape it always had: <aside> already
    // runs its own overflow/z-index/sizing system tuned against a bare
    // <table>, and wrapping every table unconditionally broke that (cells
    // not filling, parts of the menu becoming unreachable) the first time
    // this was tried. FRAME stays the <table> itself either way — every
    // append/query elsewhere reaches into FRAME directly — FRAME_HOST is
    // only what _attachCoreDom() (_list.js) actually puts into FORM in
    // FRAME's place, and only gets set when the wrapper exists.
    if (this.modelInstance?.dockTarget === 'action') {
      this.TABLE_SCROLL = el('div', { classes: ['zList-scroll'] });
      this.TABLE_SCROLL.append(this.TABLE);
      this.FRAME_HOST = this.TABLE_SCROLL;
    }

    this.FRAME = this.TABLE;
    this.TOOLS = this.TRH;
  }

  buildMetaFieldDom(field) {
    // Action columns ('delete', 'edit') have nothing to sort by — omit
    // 'sortable' and data-sort-key so List._sortCols's [data-sort-key]
    // delegation skips them.
    const sortable = field.type !== 'delete' && field.type !== 'edit';

    return this.el('th', {
      text: field.label ?? field.key,
      classes: [field.key, field.type, sortable ? 'sortable' : null],
      data: sortable ? { sortKey: field.key } : {},
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
    // group.detailEntity (see _base-grid-model.js's buildGroupedRows) is only
    // set when groupType names a real, loaded domain entity — a synthetic
    // grouping key (diet, day, assignee, ...) stays plain text.
    const LB = group.detailEntity
      ? el('a', {
          text: group.label,
          classes: ['groupLabel', 'detail-link'],
          attrs: { href: `#details=${encodeURIComponent(group.detailEntity)}%3A${group.groupId}` },
          data: { detailEntity: group.detailEntity, detailId: group.groupId },
        })
      : el('span', { text: group.label, classes: ['groupLabel'] });

    if (group.collapsible) SP.append(OC);
    if (group.badges && group.badges[0]) GD.append(this._getBadgeDom(group.badges));

    SP.append(LB);
    GD.append(SP);
    GR.append(GD);
    TBODY.append(GR);

    return TBODY;
  }

  buildItemDom(row) {
    return this.el('tr', {
      classes: ['row', ...this._rowClasses(row)],
      data: { rowId: row?.id?.rowId ?? row?.id ?? 0 },
    });
  }

  // Overrides List's plain-div default: a table needs a real <tr>/<td>, with
  // the message cell spanning every column so it doesn't look like a
  // one-column row in an N-column table.
  buildEmptyDom(fields) {
    const TR = this.el('tr', { classes: ['empty-row'] });
    const TD = this.el('td', {
      classes: ['empty-state'],
      text: this._emptyStateText(),
      attrs: { colspan: fields?.length || 1 },
    });
    TR.append(TD);
    return TR;
  }

  buildFieldDom(col, data, row) {
    const CELL = this.el('td', { classes: ['cell'] });

    // Table cells stay terse: any multi-value ('ids') field shows a count,
    // not the id list. Tile shows the fuller list — see tile.js.
    if (col.dataType === 'ids') return this._renderIdsCount(CELL, col, data);
    if (col.type === 'delete') return this._renderDeleteCell(CELL, col, row);
    if (col.type === 'edit') return this._renderEditCell(CELL, col, row);

    return this._renderFieldValue(CELL, col, data);
  }

  _renderIdsCount(CELL, col, data) {
    const ids = Array.isArray(data?.display) ? data.display : [];

    CELL.classList.add(col.key, 'ids-count', 'read-only');
    if (col.hidden) CELL.classList.add('hidden');
    CELL.append(String(ids.length));

    return CELL;
  }

  // A model opts a row into deletion by declaring a { type: 'delete' }
  // column (see BatchHistoryGridModel.buildCols) — this is what renders it.
  // The click itself is handled generically by List's delegated FORM
  // listener (see _list.js's [data-delete-row] branch and
  // List._handleRowDelete), which defers the actual API call to
  // modelInstance.deleteRow() so Grid/List stay ignorant of which entity
  // "delete" means for any given grid type.
  _renderDeleteCell(CELL, col, row) {
    CELL.classList.add(col.key, 'delete-action', 'read-only');
    const rowId = row?.id?.rowId ?? row?.id ?? 0;

    const BTN = this.el('button', {
      classes: ['row-delete'],
      attrs: { type: 'button', title: col.title ?? 'Delete' },
      data: { deleteRow: rowId },
    });

    // col.icon reuses List's "if:<name>" icon-font marker convention (see
    // _buildToggleButton/ICON_FONT_MARKER) — falls back to a plain '✕' glyph
    // if col.icon isn't set to one.
    const icon = String(col.icon ?? '');
    if (icon.startsWith(List.ICON_FONT_MARKER)) {
      const cls = icon.slice(List.ICON_FONT_MARKER.length);
      BTN.append(this.el('i', { classes: [`${List.ICON_FONT_CSS_PREFIX}${cls}`] }));
    } else {
      BTN.append('✕');
    }

    CELL.append(BTN);
    return CELL;
  }

  // The fallback every row gets when nothing already links its own title
  // (see _base-grid-model.js's _ensureRowDetailAccess — this is a { type:
  // 'edit', detailEntity: <row's own pod> } column it prepends). Reuses the
  // exact same [data-detail-entity] delegated click handler every other
  // detail-link already goes through (_list.js) — no separate wiring.
  _renderEditCell(CELL, col, row) {
    CELL.classList.add(col.key, 'edit-action', 'read-only');
    const rowId = row?.id?.rowId ?? row?.id ?? 0;

    const BTN = this.el('button', {
      classes: ['row-edit'],
      attrs: { type: 'button', title: col.title ?? 'Details' },
      data: { detailEntity: col.detailEntity, detailId: rowId },
    });

    const icon = String(col.icon ?? '');
    if (icon.startsWith(List.ICON_FONT_MARKER)) {
      const cls = icon.slice(List.ICON_FONT_MARKER.length);
      BTN.append(this.el('i', { classes: [`${List.ICON_FONT_CSS_PREFIX}${cls}`] }));
    } else {
      BTN.append('✎');
    }

    CELL.append(BTN);
    return CELL;
  }

}
