import BaseGridModel from "./_base-grid-model.js";
import Indexer       from "../data/indexer.js";

export default class DateActivityGridModel extends BaseGridModel{
  constructor(name, domain, attrs = {}){
    super(name, null, attrs );
    this.filter = true;
    this.dateFilters = this._normalizeDateFilters(attrs?.dateFilters);
    this.filterValues = this._initialFilterValues(attrs?.filterValues, attrs?.modifiedRange);
    this.modifiedRange = this.filterValues.activity ?? 'last_48_hours';
    this._build();
    if (domain) this.setDomain(domain);
  }

  getFilterDefs() {
    return this.dateFilters.map(key => ({
      key,
      label: this._dateFilterLabel(key),
      type: 'select',
      mode: 'server',
      default: 'last_48_hours',
      options: [
        { key: 'last_24_hours', label: 'Last 24 hours' },
        { key: 'last_48_hours', label: 'Last 48 hours' },
        { key: 'last_7_days', label: 'Last 7 days' },
        { key: 'last_30_days', label: 'Last 30 days' },
      ],
    }));
  }

  getServerFilterParams() {
    const params = { date_filters: this.dateFilters.join(',') };
    this.dateFilters.forEach(key => {
      params[`filter_${key}`] = this.getFilterValue(key);
    });
    return params;
  }

  setFilterValue(key, value) {
    const normalized = this._normalizeDateFilterKey(key);
    if (!normalized) return;

    this.filterValues[normalized] = this._normalizePreset(value);
    if (!this.dateFilters.includes(normalized)) this.dateFilters.push(normalized);
    if (normalized === 'activity') this.modifiedRange = this.filterValues[normalized];
  }

  getFilterValue(key) {
    const normalized = this._normalizeDateFilterKey(key);
    return this.filterValues[normalized] ?? 'last_48_hours';
  }

  _normalizeDateFilters(raw) {
    const values = Array.isArray(raw) ? raw : String(raw || 'activity').split(',');
    const out = [];
    const seen = new Set();

    values.forEach(value => {
      const key = this._normalizeDateFilterKey(value);
      if (key && !seen.has(key)) {
        seen.add(key);
        out.push(key);
      }
    });

    return out.length ? out : ['activity'];
  }

  _normalizeDateFilterKey(value) {
    return String(value ?? '')
      .trim()
      .toLowerCase()
      .replace(/-/g, '_')
      .replace(/[^a-z0-9_]/g, '');
  }

  _initialFilterValues(rawValues = {}, legacyModifiedRange = null) {
    const values = {};
    const source = rawValues && typeof rawValues === 'object' ? rawValues : {};

    this.dateFilters.forEach(key => {
      values[key] = this._normalizePreset(source[key] ?? (key === 'activity' ? legacyModifiedRange : null));
    });

    return values;
  }

  _normalizePreset(value) {
    const raw = this._normalizeDateFilterKey(value);
    const aliases = {
      '24hrs': 'last_24_hours',
      '24_hours': 'last_24_hours',
      '48hrs': 'last_48_hours',
      '48_hours': 'last_48_hours',
      '1_week': 'last_7_days',
      'week': 'last_7_days',
      '1_month': 'last_30_days',
      'month': 'last_30_days',
      'today': 'last_24_hours',
      'yesterday': 'last_48_hours',
      'this_week': 'last_7_days',
    };
    const preset = aliases[raw] ?? raw;
    return ['last_24_hours', 'last_48_hours', 'last_7_days', 'last_30_days'].includes(preset)
      ? preset
      : 'last_48_hours';
  }

  _dateFilterLabel(key) {
    const labels = {
      activity: 'Activity',
      created_on: 'Created',
      opened_on: 'Opened',
      emptied_at: 'Emptied',
      post_modified: 'Modified',
    };
    return labels[key] ?? key.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
  }

  buildCols() {
    this.columns = [
      { key: "phase",         label: "Flow",     type: "string" },
     // { key: "source",        label: "Source",   type: "string" },
     // { key: "problem",       label: "Problem",  type: "string" },
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

    const locationTubs = this.filterByLocation(this.domain.tub || []);
    const slotWarnings = this._slotFlavorWarnings(locationTubs);
    const activityItems = this._filterModifiedItems(this._activityItems(locationTubs));
    const activityByFlavorId = Indexer.groupBy(activityItems, item => item.flavor);

    return this.buildGroupedRows({
      groupsMap     : activityByFlavorId,
      includeGroupId: (id) => Number(id) > 0,
      getGroupLabel : (id) => this._flavorGroupLabel(id, activityByFlavorId.get(id) ?? [], slotWarnings),
      getGroupBadges: (items, flavorId) => this._dateActivityBadges(items, flavorId, slotWarnings),
      makeRowId     : (item) => item.id,
      fillRow       : (row, item, i) => { this._fillActivityRow(row, item, i, slotWarnings); },
      collapsed     : false,
      groupType     : 'flavor',
      rowType       : 'activity',
      rowLabel      : 'activity',
    });
  }

  _activityItems(locationTubs = []) {
    const tubsById = Indexer.byId(locationTubs);
    const changes = Array.isArray(this.domain?.inventory_change) ? this.domain.inventory_change : [];
    const items = [];
    const auditedTubIdsInWindow = new Set();
    const modifiedWindow = this._modifiedWindow();

    changes.forEach((change, changeIndex) => {
      const tubIds = this._ids(change.tubs);
      const flavorIds = this._ids(change.flavors);

      if (tubIds.length) {
        tubIds.forEach((tubId, tubIndex) => {
          const tub = tubsById.get(Number(tubId));
          if (!tub && Number(this.location || 0) > 0) return;

          const activity = this._activityFromChange(change, tub, tub?.flavor || flavorIds[0] || 0, tubIndex);
          const activityTime = this._modifiedTime(activity);
          if (activityTime >= modifiedWindow.start && activityTime <= modifiedWindow.end) {
            auditedTubIdsInWindow.add(Number(tubId));
          }
          items.push(activity);
        });
        return;
      }

      // Some audit rows (for example cabinet/slot changes) are flavor-only. Keep
      // them when there is no location filter; with a location filter there is no
      // tub relationship to prove the activity belongs to this store.
      if (Number(this.location || 0) > 0) return;

      if (flavorIds.length) {
        flavorIds.forEach((flavorId, flavorIndex) => {
          items.push(this._activityFromChange(change, null, flavorId, flavorIndex));
        });
      } else {
        items.push(this._activityFromChange(change, null, 0, changeIndex));
      }
    });

    // Legacy fallback: keep tub rows when no audit row already represents that
    // tub in the selected window, so older activity still appears while the
    // audit table fills in without duplicating audited opens/empties/overrides.
    locationTubs.forEach(tub => {
      if (!auditedTubIdsInWindow.has(Number(tub.id))) items.push(this._activityFromTub(tub));
    });

    return items;
  }

  _activityFromChange(change = {}, tub = null, flavorId = 0, index = 0) {
    const phase = change.phase || this._phaseForTub(tub || {});
    const activityAt = change.post_date || change.post_modified || '';
    const id = -1 * ((Number(change.id || 0) * 1000) + Number(index || 0) + 1);
    const amount = tub ? Number(tub.amount ?? 1) : Number(change.change_count ?? 1);

    return {
      ...(tub || {}),
      id,
      _activityKind: 'inventory_change',
      _activitySourceId: Number(change.id || 0),
      _activityPhase: phase,
      _activitySource: change.source || 'audit',
      _activityProblem: change.problem || 'none',
      _activityAt: activityAt,
      _activityReadOnly: true,
      _title: change._title || tub?._title || `Change ${change.id}`,
      tub: tub?._title || (tub?.id ? `Tub ${tub.id}` : this._auditTubLabel(change)),
      state: tub?.state || '',
      use: tub?.use || 0,
      amount: Number.isFinite(amount) ? amount : 1,
      flavor: Number(flavorId || tub?.flavor || 0),
      created_on: phase === 'created' ? (tub?.created_on || activityAt) : (tub?.created_on || ''),
      opened_on: phase === 'opened' ? (tub?.opened_on || activityAt) : (tub?.opened_on || ''),
      emptied_at: phase === 'emptied' ? (tub?.emptied_at || activityAt) : (tub?.emptied_at || ''),
      post_modified: activityAt,
      author_name: change.author_name || tub?.author_name || '',
    };
  }

  _activityFromTub(tub = {}) {
    return {
      ...tub,
      _activityKind: 'tub',
      _activityAt: this._eventTimeForTub(tub) || tub.post_modified || '',
    };
  }

  _auditTubLabel(change = {}) {
    const tubIds = this._ids(change.tubs);
    if (tubIds.length === 1) return `Tub ${tubIds[0]}`;
    if (tubIds.length > 1) return `${tubIds.length} tubs`;
    return change._title || `Change ${change.id || ''}`;
  }

  _ids(value) {
    if (value == null || value === false) return [];
    const values = Array.isArray(value) ? value : [value];
    const out = [];
    const seen = new Set();

    for (const item of values) {
      const id = Number(typeof item === 'object' ? (item.id ?? item.ID ?? item.value) : item);
      if (Number.isFinite(id) && id > 0 && !seen.has(id)) {
        seen.add(id);
        out.push(id);
      }
    }

    return out;
  }

  _filterModifiedItems(items = []) {
    const windows = this._activeDateWindows();

    return items
      .filter(item => {
        if (!windows.length) return true;

        return windows.some(({ key, window }) => {
          const modified = this._timeForDateFilter(item, key);
          return modified >= window.start && modified <= window.end;
        });
      })
      .sort((a, b) => this._modifiedTime(b) - this._modifiedTime(a));
  }

  _modifiedWindow() {
    return this._filterWindow('activity') ?? this._fallbackWindowForPreset(this.getFilterValue('activity'));
  }

  _activeDateWindows() {
    return this.dateFilters
      .map(key => ({ key, window: this._filterWindow(key) ?? this._fallbackWindowForPreset(this.getFilterValue(key)) }))
      .filter(item => item.window && Number.isFinite(item.window.start) && Number.isFinite(item.window.end));
  }

  _filterWindow(key) {
    const range = this.domain?._date_filters?.ranges?.[key];
    if (!range) return null;

    const start = this._parseDateValue(range.start);
    const end = this._parseDateValue(range.end);

    if (!Number.isFinite(start) || !Number.isFinite(end)) return null;
    return { start, end };
  }

  _fallbackWindowForPreset(preset = 'last_48_hours') {
    const now = new Date();
    const startOfToday = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime();
    const day = 24 * 60 * 60 * 1000;

    switch (this._normalizePreset(preset)) {
      case 'today':
        return { start: startOfToday, end: now.getTime() };
      case 'yesterday':
        return { start: startOfToday - day, end: startOfToday - 1 };
      case 'last_24_hours':
        return { start: now.getTime() - day, end: now.getTime() };
      case 'last_7_days':
        return { start: now.getTime() - (7 * day), end: now.getTime() };
      case 'last_30_days':
        return { start: now.getTime() - (30 * day), end: now.getTime() };
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
    return this._activityTimeValue(item);
  }

  _timeForDateFilter(item = {}, key = 'activity') {
    const fieldMap = {
      activity: '_activityAt',
      created_on: 'created_on',
      opened_on: 'opened_on',
      emptied_at: 'emptied_at',
      post_modified: 'post_modified',
    };

    if (key === 'activity') return this._activityTimeValue(item);
    return this._parseDateValue(item[fieldMap[key] ?? key]);
  }

  _activityTimeValue(item = {}) {
    return this._parseDateValue(item._activityAt || item.post_modified || item.post_date || '');
  }

  _parseDateValue(value) {
    if (!value) return 0;
    const time = new Date(String(value).replace(' ', 'T')).getTime();
    return Number.isFinite(time) ? time : 0;
  }

  _eventTimeForTub(item = {}) {
    const phase = this._phaseForTub(item);
    if (phase === 'created') return item.created_on || item.post_date || item.post_modified || '';
    if (phase === 'opened') return item.opened_on || item.post_modified || '';
    if (phase === 'emptied') return item.emptied_at || item.post_modified || '';
    if (phase === 'overriden') return item.post_modified || item.changed_on || '';
    return item.post_modified || item.changed_on || item.post_date || '';
  }

  _phaseForTub(item = {}) {
    if (item._activityPhase) return item._activityPhase;

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
    if (item._activitySource) return item._activitySource;

    const phase = this._phaseForTub(item);
    if (phase === 'created' && item.batch) return 'batch';
    if (phase === 'emptied' && item.closeout) return 'audit';
    return 'tub';
  }

  _problemForTub(item = {}, slotWarnings = new Set()) {
    if (item._activityProblem) return item._activityProblem;

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

    if (item._activityReadOnly) {
      for (const colKey of ['state', 'use', 'amount']) {
        if (row[colKey]) row[colKey].write = false;
      }
    }

    row.phase = this._readOnlyCell(phase, item, 'phase', { alertCase: `phase-${phase}` });
    row.source = this._readOnlyCell(source, item, 'source', { alertCase: `source-${source}` });
    row.problem = this._readOnlyCell(problem, item, 'problem', { alertCase: `problem-${problem}` });
    row.tub = this._readOnlyCell(item.tub || item._title || `Tub ${item.id}`, item, 'tub');
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
    const slotNote = slotWarnings.has(Number(flavorId)) ? ' · slotted for front' : '';

    return `${base}  ${slotNote}`;
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
      { key: 'created', text: `${summary.created} <u>created</u>` },
      { key: 'opened',  text: `opened ${summary.opened}` },
      { key: 'emptied', text: `emptied ${summary.emptied}` },
      { key: 'active',  text: `active ${summary.active}` },
    ];

    return badges;
  }
}
