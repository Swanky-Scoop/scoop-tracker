///////////////////////////////////
// ItemPivotGrid — table renderer for ItemPivotGridModel (see that file).
// Paired 1:1, same relationship as CabinetWorkflowTile <-> CabinetWorkflowGridModel,
// but this one fits Grid's plain <table> shape as-is: the model already
// ships a real column list (this.columns — one synthetic '_row_label' plus
// one per visible location/bucket column, see _pivot-grid-model.js), so the
// only things that differ from a normal Grid are how those two kinds of
// column render:
//   '_row_label' -> the row's own label (a flavor), as a row header cell
//   everything else -> a clickable square per tub in that cell
// buildItemDom/buildGroupDom/buildCoreDom are untouched — Grid's defaults
// (plain <tr>, dairy/non-dairy header row spanning every column) already fit.
// Axis-agnostic either way: nothing here cares which of rows/columns is
// flavors vs. location-bucket, only that '_row_label' is always the leading
// column — see item-pivot-grid-model.js for which is which.
//////////////////////////////////
import Grid from "./grid.js";

export default class ItemPivotGrid extends Grid {

  // One extra listener on top of Grid's own: the location header row's
  // collapse toggle isn't inside a [data-group-container]/TBODY, so List's
  // generic .oc handling (_showHide, wired for ROW-group collapse) silently
  // no-ops for it — this is the column-axis equivalent, driven by the model
  // instead of a CSS class toggle (see ItemPivotGridModel.toggleLocationCollapse).
  buildCoreDom() {
    super.buildCoreDom();

    this.FORM.addEventListener('click', (e) => {
      const btn = e.target.closest('tr.location-header .oc');
      if (!btn) return;
      this._toggleLocation(btn.dataset.locationKey);
    });
  }

  async _toggleLocation(locationKey) {
    if (!this.modelInstance || !locationKey) return;
    this.modelInstance.toggleLocationCollapse(locationKey);
    this.modelInstance._buildRows();
    await this.refresh(this.modelInstance);
  }

  // List._buildFields() only ever builds the one header row (this.TOOLS,
  // aliased to this.TRH by Grid) — the location super-header is a second
  // row this class adds on top, not one of List's five override hooks.
  _buildFields() {
    super._buildFields();
    this._buildLocationHeaderRow();
  }

  // Groups this.fields into spans of consecutive same-groupKey columns
  // (groupKey/groupLabel — same field names PivotGridModel already uses for
  // ROW groups, see _pivot-grid-model.js's header comment; ItemPivotGridModel
  // just also puts them on its column defs) and renders one <th colspan> per
  // span, each with a collapse toggle — collapsing swaps that location's
  // whole column set for a single placeholder (see buildFieldDom's
  // collapsedPlaceholder branch and the model's getColumnDefs).
  _buildLocationHeaderRow() {
    if (!this.THEAD) return;
    this._LOC_TRH?.remove();

    const columns = this.fields ?? [];
    const spans = [];
    let i = 0;

    while (i < columns.length) {
      const groupKey = columns[i].key === '_row_label' ? null : (columns[i].groupKey ?? null);
      const start = i;
      do { i++; } while (i < columns.length && (columns[i].key !== '_row_label') && (columns[i].groupKey ?? null) === groupKey);
      spans.push({ span: i - start, groupKey, groupLabel: columns[start]?.groupLabel ?? '' });
    }

    const TR = this.el('tr', { classes: ['location-header'] });

    spans.forEach(({ span, groupKey, groupLabel }) => {
      if (!groupKey) {
        TR.append(this.el('th', { attrs: { colSpan: span } }));
        return;
      }

      const collapsed = this.modelInstance?.isLocationCollapsed?.(groupKey) ?? false;
      const TH = this.el('th', {
        attrs: { colSpan: span },
        classes: ['location-group', collapsed ? 'collapsed' : null],
        data: { locationKey: groupKey },
      });
      TH.append(this.el('button', { classes: ['oc'], attrs: { type: 'button' }, data: { locationKey: groupKey } }));
      TH.append(this.el('span', { text: groupLabel, classes: ['location-label'] }));
      TR.append(TH);
    });

    this._LOC_TRH = TR;
    this.THEAD.insertBefore(TR, this.THEAD.firstChild);
  }

  buildMetaFieldDom(field) {
    if (field.key === '_row_label') {
      return this.el('th', { classes: ['row-label-header'] });
    }

    const TH = super.buildMetaFieldDom(field);
    if (field.groupKey) TH.classList.add(field.groupKey);
    return TH;
  }

  buildFieldDom(col, data, row) {
    if (col.key === '_row_label') {
      return this.el('th', {
        text: String(data ?? ''),
        classes: ['row-label'],
        attrs: { scope: 'row' },
      });
    }

    const CELL = this.el('td', { classes: ['pivot-cell'] });
    const tubs = Array.isArray(data) ? data : [];

    // A collapsed location's whole bucket set folds into this one column —
    // a count is more useful (and less cramped) here than one square per
    // tub crammed into a single narrow cell. See getColumnDefs.
    if (col.collapsedPlaceholder) {
      CELL.classList.add('collapsed-placeholder');
      if (tubs.length) CELL.append(this.el('span', { text: String(tubs.length), classes: ['collapsed-count'] }));
      return CELL;
    }

    tubs.forEach(tub => {
      CELL.append(this.el('button', {
        classes: ['tub-square', this._squareStateClass(tub)],
        attrs: {
          type: 'button',
          title: `${tub.state}${tub.amount != null ? ' · ' + tub.amount : ''}`,
        },
        data: { detailEntity: 'tub', detailId: tub.id },
      }));
    });

    return CELL;
  }

  // 'Opened' alone doesn't distinguish an in-service tub (its own cabinet
  // row, styled green) from one sitting in the merged 'Other' row with
  // nowhere assigned — only tub.slot tells them apart, so that's checked
  // here rather than relying on _slug(tub.state) alone. '!Lost' slugs down
  // to 'Lost' (_slug strips the leading '!') — see css.css's .state-Lost.
  _squareStateClass(tub) {
    if (tub.state === 'Opened' && !tub.slot) return 'state-OpenedNoSlot';
    return `state-${this._slug(tub.state)}`;
  }
}
