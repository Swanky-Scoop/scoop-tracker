import BaseGridModel from "./_base-grid-model.js";
import Indexer       from "../data/indexer.js";

export default class DateActivityGridModel extends BaseGridModel{
  constructor(name, domain, attrs = {}){
    super(name, domain, attrs );
    this.filter = true;
    this.modifiedRange = attrs?.modifiedRange ?? 'last_48_hours';
    this._build();
  }

  getFilterDefs() {
    return [
      {
        key: 'modified_range',
        label: 'Modified',
        type: 'select',
        mode: 'server',
        default: 'last_48_hours',
        options: [
          { key: 'today', label: 'Today' },
          { key: 'yesterday', label: 'Yesterday' },
          { key: 'last_48_hours', label: 'Last 48 hours' },
          { key: 'last_7_days', label: 'Last 7 days' },
          { key: 'this_week', label: 'This week' },
        ],
      },
    ];
  }

  buildCols() {
    this.columns = [
      { key: "phase",         label: "Flow",     type: "string" },
      { key: "source",        label: "Source",   type: "string" },
      { key: "problem",       label: "Problem",  type: "string" },
      { key: "tub",           label: "Tub",      type: "string" },
      { key: "state",         label: "State",    write: true, control: "find", type: "string" },
      { key: "use",           label: "Use",      write: true, control: "find", type: "use", titleMap: "use" },
      { key: "amount",        label: "Amount",   write: true, control: "text", type: "number", step: 0.01, min: 0, max: 1, title: "Fraction of this tub that is full (0 to 1)." },
      { key: "created_on",    label: "Created",  type: "datetime" },
      { key: "opened_on",     label: "Opened",   type: "datetime" },
      { key: "emptied_at",    label: "Emptied",  type: "datetime" },
      { key: "post_modified", label: "Modified", type: "datetime" },
      { key: "author_name",   label: "Who",      type: "string" },
    ];

    this._allColumns = this.columns;
    return this.columns;
  }

  buildRows() {
    if (!this.domain) return [];

    const primary = this.metaData?.primary || 'tub';
    const items = this.domain[primary] || [];
    const locationTubIds = this.filterByLocation(items);
    const recentItems = this._filterModifiedItems(locationTubIds);
    const tubsByFlavorId = Indexer.groupBy(recentItems, t => t.flavor);
    const slotWarnings = this._slotFlavorWarnings(locationTubIds);

    return this.buildGroupedRows({
      groupsMap     : tubsByFlavorId,
      includeGroupId: (id) => Number(id) > 0,
      getGroupLabel : (id) => this._flavorGroupLabel(id, tubsByFlavorId.get(id) ?? [], slotWarnings),
      getGroupBadges: (items, flavorId) => this._dateActivityBadges(items, flavorId, slotWarnings),
      makeRowId     : (item) => item.id,
      fillRow       : (row, item, i) => { this._fillActivityRow(row, item, i, slotWarnings); },
      collapsed     : false,
      groupType     : 'flavor',
      rowType       : 'tub',
      rowLabel      : 'tub',
    });
  }

  _filterModifiedItems(items = []) {
    const { start, end } = this._modifiedWindow();

    return items
      .filter(item => {
        const modified = this._modifiedTime(item);
        return modified >= start && modified <= end;
      })
      .sort((a, b) => this._modifiedTime(b) - this._modifiedTime(a));
  }

  _modifiedWindow() {
    const now = new Date();
    const startOfToday = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime();
    const day = 24 * 60 * 60 * 1000;

    switch (this.modifiedRange) {
      case 'today':
        return { start: startOfToday, end: now.getTime() };
      case 'yesterday':
        return { start: startOfToday - day, end: startOfToday - 1 };
      case 'last_7_days':
        return { start: now.getTime() - (7 * day), end: now.getTime() };
      case 'this_week': {
        const dayOfWeek = now.getDay();
        return { start: startOfToday - (dayOfWeek * day), end: now.getTime() };
      }
      case 'last_48_hours':
      default:
        return { start: now.getTime() - (48 * 60 * 60 * 1000), end: now.getTime() };
    }
  }

  _modifiedTime(item = {}) {
    const time = new Date(item.post_modified).getTime();
    return Number.isFinite(time) ? time : 0;
  }

  _phaseForTub(item = {}) {
    const state = String(item.state ?? '');
    if (state === '__override__') return 'overriden';

    const events = [
      { phase: 'created', time: new Date(item.created_on).getTime() },
      { phase: 'opened',  time: new Date(item.opened_on).getTime() },
      { phase: 'emptied', time: new Date(item.emptied_at).getTime() },
    ].filter(event => Number.isFinite(event.time));

    events.sort((a, b) => b.time - a.time);
    if (events[0]) return events[0].phase;

    if (state === 'Emptied') return 'emptied';
    if (state === 'Opened') return 'opened';
    if (item.created_on) return 'created';
    return 'unknown';
  }

  _sourceForTub(item = {}) {
    const phase = this._phaseForTub(item);
    if (phase === 'created' && item.batch) return 'batch';
    if (phase === 'emptied' && item.closeout) return 'audit';
    return 'tub';
  }

  _problemForTub(item = {}, slotWarnings = new Set()) {
    const phase = this._phaseForTub(item);
    const flavorId = Number(item.flavor || 0);

    if (phase === 'opened' && this._noDate(item.opened_on)) return 'warning';
    if (phase === 'emptied' && this._noDate(item.emptied_at)) return 'warning';
    if (Number(item.amount ?? 1) < 0 || Number(item.amount ?? 1) > 1) return 'warning';
    if (slotWarnings.has(flavorId) && phase !== 'opened') return 'warning';

    return 'none';
  }

  _noDate(value) {
    if (value == null || value === '') return true;
    const s = String(value);
    return s.startsWith('0000-00-00');
  }

  _fillActivityRow(row, item, i, slotWarnings) {
    this.fillRowFromColumns(row, item, i);

    const phase = this._phaseForTub(item);
    const source = this._sourceForTub(item);
    const problem = this._problemForTub(item, slotWarnings);

    row.phase = this._readOnlyCell(phase, item, 'phase', { alertCase: `phase-${phase}` });
    row.source = this._readOnlyCell(source, item, 'source', { alertCase: `source-${source}` });
    row.problem = this._readOnlyCell(problem, item, 'problem', { alertCase: `problem-${problem}` });
    row.tub = this._readOnlyCell(item._title ?? `Tub ${item.id}`, item, 'tub');
  }

  _readOnlyCell(display, item, colKey, extra = {}) {
    return {
      id: item?.id ?? 0,
      rowId: item?.id ?? 0,
      colKey,
      display: display ?? '',
      value: display ?? '',
      write: false,
      ...extra,
    };
  }

  _slotFlavorWarnings(locationTubs = []) {
    const openedByFlavor = new Set(
      locationTubs
        .filter(t => String(t.state ?? '') === 'Opened')
        .map(t => Number(t.flavor || 0))
        .filter(Boolean)
    );

    const currentSlotFlavorIds = new Set(
      (this.domain?.slot ?? [])
        .filter(slot => Number(slot.location || 0) === Number(this.location || 0))
        .map(slot => Number(slot.current_flavor || 0))
        .filter(Boolean)
    );

    const warnings = new Set();
    for (const flavorId of currentSlotFlavorIds) {
      if (!openedByFlavor.has(flavorId)) warnings.add(flavorId);
    }
    return warnings;
  }

  _flavorGroupLabel(flavorId, items = [], slotWarnings = new Set()) {
    const base = this.labelFromMap(Number(flavorId), this._flavorsById) ?? `Flavor ${flavorId}`;
    const summary = this._summaryForFlavor(items);
    const slotNote = slotWarnings.has(Number(flavorId)) ? ' · slot needs opened tub' : '';

    return `${base} · Created ${summary.created} · Opened ${summary.opened} · Emptied ${summary.emptied} · Active ${summary.active}${slotNote}`;
  }

  _summaryForFlavor(items = []) {
    return items.reduce((summary, item) => {
      const amount = Number(item.amount ?? 1) || 0;

      const phase = this._phaseForTub(item);
      if (phase === 'created') summary.created += amount;
      if (phase === 'opened') summary.opened += amount;
      if (phase === 'emptied') summary.emptied += amount;
      if (String(item.state ?? '') !== 'Emptied') summary.active += amount;

      return summary;
    }, { created: 0, opened: 0, emptied: 0, active: 0 });
  }

  _dateActivityBadges(items = [], flavorId, slotWarnings = new Set()) {
    const summary = this._summaryForFlavor(items);
    const badges = [
      { key: 'created', text: `created ${summary.created}` },
      { key: 'opened',  text: `opened ${summary.opened}` },
      { key: 'emptied', text: `emptied ${summary.emptied}` },
      { key: 'active',  text: `active ${summary.active}` },
    ];

    if (slotWarnings.has(Number(flavorId))) {
      badges.push({ key: 'warning', text: 'slot/no opened tub' });
    }

    return badges;
  }
}
