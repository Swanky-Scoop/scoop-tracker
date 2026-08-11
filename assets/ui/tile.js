///////////////////////////////////
// Tile — card view. Concrete List subclass, sibling to Grid (./grid.js):
// same five hooks, different markup. Sort/filter/group/autosave/submit/
// Details drill-down all come from List (./_list.js) — this file only
// decides what a card looks like.
//
// Field rendering is dataType-driven (see grid.js/_list.js's long-standing
// col.dataType vs col.type split — this reads dataType, which is what the
// server-driven metaData columns actually carry):
//   col.key === '_title' && col.detailEntity  -> <a class="{primary}-link"><h3></h3></a>
//   dataType 'file'                            -> <i class="{key}"><img></i> (skipped if no URL)
//   dataType 'ids' | 'post_names'              -> .multiple.{key} block: label+count
//                                                  and/or a resolved <ul><li> list, per
//                                                  col.display ('count'|'list'|'both')
//   everything else (scalar)                   -> <div class="{number|dataType} {key}">,
//                                                  via the shared _renderFieldValue so
//                                                  writeable scalar fields still work
//
// Known DOM-order deviation from the original mockup: the bulk-select
// checkbox is appended in buildItemDom(), which runs BEFORE fields are
// appended — so it renders as the LI's first child, not last. Reposition
// with CSS (flex `order`) if last-child placement matters visually.
//////////////////////////////////

import List from "./_list.js";
import { resolveRelationIds } from "../data/relations.js";

export default class Tile extends List {

  buildCoreDom() {
    const el = this.el;

    this.FORM    = el('form',   { classes: ['zList-form'] });
    this.TOGGLE  = this._buildToggleButton();
    this.FILTERS = el('div',    { classes: ['gridFilters', 'empty'] });
    this.SUBMIT  = this._buildSubmitButton();
    this.FRAME   = el('div',    { classes: ['zList'] });
    this.TOOLS   = el('div',    { classes: ['tileTools'] });

    this.FRAME.append(this.TOOLS);
  }

  // No column headers in a card layout — this becomes the sort-trigger bar
  // instead. Style/hide via CSS; the [data-sort-key] click still works
  // regardless (List's _sortCols is tag-agnostic).
  buildMetaFieldDom(field) {
    return this.el('button', {
      text: field.label ?? field.key,
      classes: [field.key, this._dataTypeClass(field.dataType), 'sort-trigger'],
      attrs: { type: 'button' },
      data: { sortKey: field.key },
    });
  }

  // group.label falsy => the synthetic ungrouped container: a bare <ul>
  // wrapper, no header.
  //
  // Same three-level shape as Grid's tbody.groupBody > tr.group >
  // th.groupCell (see grid.js's buildGroupDom) — section/div/div instead of
  // tbody/tr/th, but identical class hooks throughout ('groupBody' state
  // container, 'group' header row, 'groupCell'/'groupLabel'/'oc' inside it)
  // so css.css styles and collapses both from one rule set, not a parallel
  // Tile-only copy. Items go into <ul>, a sibling of the header div, rather
  // than Grid's item <tr>s, which are siblings of tr.group directly inside
  // tbody — <li> needs a real <ul>/<ol> parent to stay valid HTML, table
  // rows don't have an equivalent constraint.
  buildGroupDom(group, fields, opened) {
    const el = this.el;

    const SECTION = el('section', {
      classes: ['groupBody', this._groupTypeClass(group), (group.collapsible ? 'collapsible' : 'static'), (opened ? 'opened' : 'closed')],
      data: { groupType: group.groupType, rowType: group.rowType, groupContainer: '', groupId: group.groupId },
    });

    const UL = el('ul');
    SECTION._itemsHost = UL; // List._buildItems() appends items here, not into SECTION directly

    if (!group.label) {
      SECTION.append(UL);
      return SECTION;
    }

    const GR = el('div', {
      classes: ['group', this._slug(group.label)],
      data: { rowId: group.groupId, groupLabel: group.label },
    });
    const GD = el('div', { classes: ['groupCell'] });
    const SP = el('b');
    const OC = group.collapsible ? el('button', { classes: ['oc'], attrs: { type: 'button' } }) : null;
    const LB = el('h2', { text: group.label, classes: ['groupLabel'] });

    if (group.collapsible) SP.append(OC);
    if (group.badges && group.badges[0]) GD.append(this._getBadgeDom(group.badges));

    SP.append(LB);
    GD.append(SP);
    GR.append(GD);

    SECTION.append(GR, UL);
    return SECTION;
  }

  buildItemDom(row) {
    const primary = this.modelInstance?.metaData?.primary ?? 'item';
    const slug = this._slug(row?._title?.display ?? row?.id ?? '');

    const LI = this.el('li', {
      classes: [primary, slug, ...this._rowClasses(row)],
      data: { rowId: row?.id?.rowId ?? row?.id ?? 0 },
    });

    // Bulk-select: markup only, no selection state/behavior wired up yet.
    LI.append(this.el('input', {
      classes: ['select-item'],
      attrs: { type: 'checkbox', name: 'selected-items', value: `${primary}-${row?.id ?? 0}` },
    }));

    return LI;
  }

  buildFieldDom(col, data, row) {
    if (col.key === '_title' && col.detailEntity) return this._buildTitleFieldDom(col, data);
    if (col.dataType === 'file') return this._buildFileFieldDom(col, data, row);
    if (col.dataType === 'ids' || col.dataType === 'post_names') return this._buildMultiFieldDom(col, data);

    return this._buildScalarFieldDom(col, data);
  }

  _buildTitleFieldDom(col, data) {
    const primary = this.modelInstance?.metaData?.primary ?? 'item';

    const A = this.el('a', {
      classes: [`${primary}-link`, 'detail-link'],
      attrs: { href: `#details=${encodeURIComponent(col.detailEntity)}%3A${data?.id || 0}` },
      data: { detailEntity: col.detailEntity, detailId: data?.id || 0 },
    });
    A.append(this.el('h3', { text: '' + (data?.display || '') }));

    return A;
  }

  _buildFileFieldDom(col, data, row) {
    const url = typeof data?.display === 'string' ? data.display : '';
    const WRAP = this.el('i', { classes: [col.key] });

    if (url) {
      const subject = row?._title?.display || 'this item';
      WRAP.append(this.el('img', {
        attrs: { src: url, alt: `${col.label ?? col.key} for ${subject}` },
      }));
    }

    return WRAP;
  }

  // 'ids' fields resolve through the same id->title lookup Details.js uses
  // (col.titleMap names the target pod); 'post_names' fields are already
  // plain strings, no resolution needed. col.display ('count'|'list'|'both',
  // default 'both' — see includes/_specs.php) decides what renders; either
  // half is skipped cleanly if the data or the flag doesn't support it.
  _buildMultiFieldDom(col, data) {
    const kebab = col.key.replace(/_/g, '-');
    const WRAP = this.el('div', { classes: ['multiple', kebab] });

    const raw = Array.isArray(data?.display) ? data.display : [];
    const items = col.dataType === 'post_names'
      ? raw.map(slug => ({ title: String(slug), className: this._slug(slug) }))
      : (col.titleMap
          ? resolveRelationIds(col.titleMap, raw, this.modelInstance?.domain ?? {})
              .map(r => ({ title: r.title, className: this._slug(r.title) }))
          : []);

    const showCount = col.display !== 'list';
    const showList  = col.display !== 'count';

    if (showCount) {
      const LABEL = this.el('label', { classes: [`${kebab}-count`] });
      LABEL.append(`${col.label ?? col.key}: `);
      LABEL.append(this.el('em', { text: String(items.length) }));
      WRAP.append(LABEL);
    }

    if (showList && items.length) {
      const UL = this.el('ul');
      items.forEach(item => UL.append(this.el('li', { text: item.title, classes: [item.className] })));
      WRAP.append(UL);
    }

    return WRAP;
  }

  _buildScalarFieldDom(col, data) {
    const kebab = col.key.replace(/_/g, '-');
    const WRAP = this.el('div', { classes: [this._dataTypeClass(col.dataType), kebab] });
    return this._renderFieldValue(WRAP, col, data);
  }

  _dataTypeClass(dataType) {
    if (dataType === 'int' || dataType === 'float') return 'number';
    return dataType || 'string';
  }

}
