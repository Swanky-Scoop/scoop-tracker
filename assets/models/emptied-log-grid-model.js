///////////////////////////////////
// EmptiedLogGridModel — read-only "what got emptied, by day" log for a
// non-staff audience (see DateActivity for the staff-facing full audit
// trail this deliberately does NOT try to replace). Fixed 7-day window,
// one group per calendar day (today first, including empty days so a quiet
// day still shows), rows are individual emptied tubs: flavor, use, amount,
// location, who, and when. Like ItemPivotGridModel, it ignores
// this.location — the point is seeing every location's activity together,
// with location shown as its own column.
//////////////////////////////////
import BaseGridModel from "./_base-grid-model.js";
import Indexer       from "../data/indexer.js";

const DAY_WINDOW = 7;

export default class EmptiedLogGridModel extends BaseGridModel {

  constructor(name, domain, attrs = {}) {
    super(name, null, attrs);
    // Read-only log — every row is derived from inventory_change audit rows
    // or a tub's own state snapshot, neither of which is meant to be
    // hand-edited here (same reasoning as DateActivityGridModel).
    this.manualRefreshOnly = true;

    this.dateFormat = {
      month: "numeric",
      day:   "numeric",
    };

    this._build();
    if (domain) this.setDomain(domain);
  }

  // Fixed window, no user-facing filter UI — see the module comment. Always
  // sent (mountAllGrids collects this from the model instance before the
  // very first bundle fetch), so the server-side date-scoped tub/
  // inventory_change fetch (see _specs.php / bundle-fetch.php) narrows to
  // this from the start rather than pulling full history.
  getServerFilterParams() {
    return { date_filters: 'activity', filter_activity: 'last_7_days' };
  }

  buildCols() {
    this.columns = [
      { key: "flavor",      label: "Flavor",   control: "find", type: "int",      titleMap: "flavor" },
      { key: "use",         label: "Use",      control: "find", type: "use",      titleMap: "use" },
      { key: "amount",      label: "Amount",   type: "number" },
      { key: "location",    label: "Location", control: "find", type: "int",      titleMap: "location" },
      { key: "author_name", label: "Who",      type: "string" },
      { key: "emptied_at",  label: "Time",     type: "datetime" },
    ];

    this._allColumns = this.columns;
    return this.columns;
  }

  buildRows() {
    if (!this.domain) return [];

    const tubs = Array.isArray(this.domain.tub) ? this.domain.tub : [];
    const items = this._emptiedItems(tubs);
    const buckets = this._buildDayBuckets();

    items.forEach(item => {
      const key = this._dayKeyFromTime(this._parseDateValue(item._activityAt));
      if (buckets.has(key)) buckets.get(key).push(item);
    });

    buckets.forEach(list => list.sort((a, b) => this._parseDateValue(b._activityAt) - this._parseDateValue(a._activityAt)));

    return this.buildGroupedRows({
      groupsMap     : buckets,
      getGroupLabel : (dayKey) => this._dayLabel(dayKey),
      getGroupBadges: (dayItems) => this._dayBadges(dayItems),
      makeRowId     : (item) => item.id,
      fillRow       : (row, item, i) => { this.fillRowFromColumns(row, item, i); },
      collapsed     : false,
      groupType     : 'day',
      rowType       : 'tub',
      rowLabel      : 'tub',
    });
  }

  // Prefers inventory_change audit rows (phase === 'emptied') for
  // amount/who/when, same as DateActivityGridModel; falls back to a tub's
  // own state snapshot for tubs emptied before audit rows existed.
  _emptiedItems(tubs = []) {
    const tubsById = Indexer.byId(tubs);
    const changes = Array.isArray(this.domain?.inventory_change) ? this.domain.inventory_change : [];
    const items = [];
    const auditedTubIds = new Set();

    changes.forEach((change, changeIndex) => {
      if ((change.phase || '') !== 'emptied') return;

      const tubIds = this._ids(change.tubs);
      tubIds.forEach((tubId, tubIndex) => {
        const tub = tubsById.get(Number(tubId));
        auditedTubIds.add(Number(tubId));
        items.push(this._itemFromChange(change, tub, changeIndex, tubIndex));
      });
    });

    tubs.forEach(tub => {
      if (String(tub.state ?? '') !== 'Emptied') return;
      if (auditedTubIds.has(Number(tub.id))) return;
      items.push(this._itemFromTub(tub));
    });

    return items;
  }

  _itemFromChange(change = {}, tub = null, changeIndex = 0, tubIndex = 0) {
    const activityAt = tub?.emptied_at || change.post_date || change.post_modified || '';
    const id = -1 * ((Number(change.id || 0) * 1000) + Number(changeIndex * 100 + tubIndex) + 1);
    const amount = tub ? Number(tub.amount ?? 1) : Number(change.change_count ?? 1);

    return {
      id,
      _activityAt: activityAt,
      flavor: Number(tub?.flavor || 0),
      use: tub?.use || 0,
      amount: Number.isFinite(amount) ? amount : 1,
      location: tub?.location || 0,
      emptied_at: activityAt,
      author_name: change.author_name || tub?.editor_name || '',
    };
  }

  _itemFromTub(tub = {}) {
    return {
      id: tub.id,
      _activityAt: tub.emptied_at || tub.post_modified || '',
      flavor: Number(tub.flavor || 0),
      use: tub.use || 0,
      amount: Number(tub.amount ?? 1),
      location: tub.location || 0,
      emptied_at: tub.emptied_at || '',
      author_name: tub.editor_name || '',
    };
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

  // Ordered Map, today first, DAY_WINDOW entries, each pre-seeded with an
  // empty array — a quiet day still gets its own (empty) group instead of
  // silently disappearing.
  _buildDayBuckets() {
    const buckets = new Map();
    const now = new Date();

    for (let i = 0; i < DAY_WINDOW; i++) {
      const d = new Date(now.getFullYear(), now.getMonth(), now.getDate() - i);
      buckets.set(this._dayKeyFromTime(d.getTime()), []);
    }

    return buckets;
  }

  _dayKeyFromTime(ms) {
    if (!ms) return null;
    const d = new Date(ms);
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
  }

  _dayLabel(dayKey) {
    const [y, m, d] = String(dayKey).split('-').map(Number);
    const date = new Date(y, (m || 1) - 1, d || 1);
    const now = new Date();

    const todayKey = this._dayKeyFromTime(now.getTime());
    const yesterday = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1);
    const yesterdayKey = this._dayKeyFromTime(yesterday.getTime());

    if (dayKey === todayKey) return 'Today';
    if (dayKey === yesterdayKey) return 'Yesterday';

    return date.toLocaleDateString('en-US', { weekday: 'long', month: 'short', day: 'numeric' });
  }

  _dayBadges(items = []) {
    const count = items.length;
    return [
      { key: 'emptied', text: count ? `${count} tub${count === 1 ? '' : 's'} emptied` : 'Nothing emptied' },
    ];
  }

  _parseDateValue(value) {
    if (!value) return 0;
    const time = new Date(String(value).replace(' ', 'T')).getTime();
    return Number.isFinite(time) ? time : 0;
  }
}
