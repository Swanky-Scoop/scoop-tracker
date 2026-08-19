///////////////////////////////////
// EmptiedLogGridModel — "what got emptied, by day" log for a non-staff
// audience (see DateActivity for the staff-facing full audit trail this
// deliberately does NOT try to replace). Fixed 7-day window, one group per
// calendar day (today first, including empty days so a quiet day still
// shows), rows are individual emptied tubs. Like ItemPivotGridModel, it
// ignores this.location — the point is seeing every location's activity
// together, with location shown as its own column.
//
// state/use are inline-editable (so an accidental close can be undone right
// from this log) even though this grid has no _config.php route entry of
// its own — those two cells POST through FlavorTub's existing write route/
// permissions instead (see this.writeEnvelope below and List.writeType in
// assets/ui/_list.js), and their write-permission + state options are read
// straight off SCOOP.metaData.FlavorTub rather than duplicated here.
//////////////////////////////////
import BaseGridModel from "./_base-grid-model.js";

const DAY_WINDOW = 7;

export default class EmptiedLogGridModel extends BaseGridModel {

  constructor(name, domain, attrs = {}) {
    super(name, null, attrs);
    // Manual save, not autosave — same reasoning as FlavorTubGridModel
    // (see its own comment): autosave raced a background domain refresh
    // against active typing. This grid reuses that model's write route, so
    // it inherits the same risk and the same fix.
    this.autosave = false;
    // Cells actually POST as 'FlavorTub' (see List.writeType) — this grid
    // has no write route of its own, it edits tub rows through the real
    // Tub grid's route.
    this.writeEnvelope = 'FlavorTub';

    this.dateFormat = {
      month: "numeric",
      day:   "2-digit",
    };

    this._build();
    if (domain) this.setDomain(domain);
  }

  // Fixed window, no user-facing filter UI — see the module comment. Always
  // sent (mountAllGrids collects this from the model instance before the
  // very first bundle fetch), so the server-side date-scoped tub fetch (see
  // _specs.php / bundle-fetch.php) narrows to this from the start rather
  // than pulling full history.
  getServerFilterParams() {
    return { date_filters: 'activity', filter_activity: 'last_7_days' };
  }

  // state/use write-permission + state's option list live on the real Tub
  // write route's server-computed metadata (scoop_client_metadata() in
  // enqueue.php), keyed by the CURRENT user's role — reading it here instead
  // of duplicating it means "can this user edit tub state" stays correct
  // without this grid needing a _config.php/_policy.php entry of its own.
  _flavorTubColumn(key) {
    const cols = window.SCOOP?.metaData?.FlavorTub?.entities?.tub ?? [];
    return cols.find(c => c.key === key) ?? null;
  }

  buildCols() {
    const stateCol = this._flavorTubColumn('state');
    const useCol   = this._flavorTubColumn('use');

    this.columns = [
      { key: "tub",         label: "Tub",      type: "string" },
      { key: "state",       label: "State",    control: "enum", type: "string", write: !!stateCol?.write, options: stateCol?.options ?? [] },
      { key: "use",         label: "Use",      control: "find", type: "use",    titleMap: "use", write: !!useCol?.write },
      { key: "amount",      label: "Amount",   type: "number" },
      { key: "location",    label: "Location", control: "find", type: "int",   titleMap: "location" },
      { key: "author_name", label: "Who",      type: "string" },
    ];

    this._allColumns = this.columns;
    return this.columns;
  }

  // One row per real tub currently in state 'Emptied' — not per audit event.
  // A single "emptied N tubs" action produces N inventory_change entries
  // (or one entry covering N tub ids); reading from that instead of current
  // tub state showed every such event as its own row, including ones for
  // tubs since reopened/un-emptied. Reading straight off this.domain.tub
  // means this view always matches the tub's actual current state, and a
  // tub can only ever produce the one row here regardless of how many times
  // it's been emptied/reopened/re-emptied historically.
  buildRows() {
    if (!this.domain) return [];

    const tubs = Array.isArray(this.domain.tub) ? this.domain.tub : [];
    const items = tubs
      .filter(tub => String(tub.state ?? '') === 'Emptied')
      .map(tub => this._itemFromTub(tub));
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

  _itemFromTub(tub = {}) {
    return {
      id: tub.id,
      _activityAt: tub.emptied_at || tub.post_modified || '',
      tub: tub._title || `Tub ${tub.id}`,
      state: tub.state ?? '',
      use: tub.use || 0,
      amount: Number(tub.amount ?? 1),
      location: tub.location || 0,
      author_name: tub.editor_name || '',
    };
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
