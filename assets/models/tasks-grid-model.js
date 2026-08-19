import BaseGridModel from "./_base-grid-model.js";
import Indexer       from "../data/indexer.js";

/**
 * Read-only listing of all tasks, grouped by assignee (description, done
 * status, created date). "Assigned to" is deliberately not its own column —
 * the group header already carries it (see buildRows/_groupByAssignee), and
 * each group's badges summarize it further (see _taskBadges): "N doing" is
 * every not-done task for that assignee (always shown, unbounded — see the
 * `completed` filter note below), "N done" is however many of their done
 * tasks fall inside the current `completed` date-range filter.
 *
 * Shortcode:
 *   [scoop_grid type="Tasks" user="all" location="all"]
 *
 * `user` (data-user, see includes/shortcode.php) hardcodes an assignee
 * filter — a WP user id, or "all"/omitted for every task. `location` is
 * accepted (every [scoop_grid] host gets one — see _resolveLocation() in
 * scoop-api.js) but unused here: 'task' has no location field in the Pods
 * schema today, so this grid always shows every location's tasks regardless
 * of that attribute's value.
 *
 * Date-range filter on `completed` (see getFilterDefs/getServerFilterParams
 * below), same UI idiom as BatchHistoryGridModel's 24h/48h/7d/30d picker —
 * but unlike Batch's, this only bounds DONE tasks. Not-done tasks are always
 * fetched regardless of the filter (see the 'task' WHERE-clause block in
 * bundle-fetch.php and hooks/task-state.php, which auto-stamps `completed`
 * the moment `done` flips true) — the open worklist should never silently
 * drop a stale task just because it's old; only the completed-history tail
 * needs to stay bounded so the request doesn't balloon over time.
 *
 * No delete/edit here — 'Task' is a create-only route server-side
 * (includes/_config.php), so this is pure browsing for now. See
 * INGREDIENT-TRACKING.md / GUI-planning.md for the still-open "task detail +
 * mark done" view this is meant to link out to next.
 */
const DATE_FILTER_PRESETS = ['last_24_hours', 'last_48_hours', 'last_7_days', 'last_30_days'];
const DEFAULT_PRESET = 'last_7_days';

export default class TasksGridModel extends BaseGridModel {
  constructor(name = 'Tasks', domain, attrs = {}, metaData = null) {
    super(name, null, attrs, metaData);
    this.filter = true; // enable find-in-list text filter
    this.userFilter = this._normalizeUserFilter(attrs?.user);
    this._assigneeNames = new Map(); // targetId(number) -> resolved name, rebuilt per buildRows
    this.filterValues = { completed: this._normalizePreset(attrs?.filterValues?.completed) };
    this._build();
    if (domain) this.setDomain(domain);
  }

  _normalizeUserFilter(raw) {
    const v = String(raw ?? '').trim().toLowerCase();
    if (!v || v === 'all') return 0;
    const n = Number(raw);
    return Number.isFinite(n) ? n : 0;
  }

  buildCols() {
    this.columns = [
      { key: "post_date",       label: "Created",            type: "datetime" },
      { key: "_title",          label: "Task",               type: "string" },
      { key: "other",           label: "Description",        type: "string" },
      { key: "done",            label: "Done",               type: "string" },
      { key: "batches",         label: "Batches",             type: "list" },
      { key: "recipe_counts",   label: "Recipe production",   type: "list" },
      { key: "preps",           label: "Ingredient prep",     type: "list" },
    ];

    return this.columns;
  }

  buildRows() {
    const tasks = Array.isArray(this.domain?.task) ? this.domain.task : [];
    const scoped = this.userFilter
      ? tasks.filter((t) => Number(t.target ?? 0) === this.userFilter)
      : tasks;

    this._indexComponents();

    const groups = this._groupByAssignee(scoped);

    return this.buildGroupedRows({
      groupsMap     : groups,
      getGroupLabel : (targetId) => this._assigneeNames.get(targetId) ?? "Unassigned",
      getGroupBadges: (items) => this._taskBadges(items),
      makeRowId     : (t) => t.id,
      fillRow       : (row, t) => this._fillTaskRow(row, t),
      collapsible   : true,
      collapsed     : false,
      groupType     : 'assignee',
      rowType       : 'task',
      rowLabel      : 'task',
    });
  }

  // Buckets by target id (0 = unassigned), newest task first within each
  // bucket, groups ordered alphabetically by assignee name with Unassigned
  // last — same "who's on the hook" scan order the Task form's own staff
  // picker uses (Unassigned as the first pick, last as a read summary).
  _groupByAssignee(tasks) {
    const buckets = new Map();
    this._assigneeNames = new Map();

    tasks.forEach((t) => {
      const id = Number(t.target ?? 0);
      if (!buckets.has(id)) buckets.set(id, []);
      buckets.get(id).push(t);
      if (id && !this._assigneeNames.has(id)) {
        this._assigneeNames.set(id, t.target_name || `User ${id}`);
      }
    });

    buckets.forEach((list) => list.sort((a, b) =>
      String(b.post_date ?? "").localeCompare(String(a.post_date ?? ""))
    ));

    const sortedIds = [...buckets.keys()].sort((a, b) => {
      if (a === 0) return 1;
      if (b === 0) return -1;
      return this._assigneeNames.get(a).localeCompare(this._assigneeNames.get(b));
    });

    const ordered = new Map();
    sortedIds.forEach((id) => ordered.set(id, buckets.get(id)));
    return ordered;
  }

  // "doing" = every not-done task for this assignee — always the true total,
  // since not-done rows are never date-bounded server-side. "done" = however
  // many of their done tasks made it into the current `completed` window —
  // the domain itself is already filtered by then, so this is just a count,
  // no extra date math needed here.
  _taskBadges(items = []) {
    const doing = items.filter((t) => !t.done).length;
    const done  = items.filter((t) => !!t.done).length;
    return [
      { key: 'doing', text: `${doing} doing` },
      { key: 'done',  text: `${done} done` },
    ];
  }

  _fillTaskRow(row, t) {
    row.post_date = { display: this._fmtDate(t.post_date), value: t.post_date ?? "" };
    row._title    = { display: t._title ?? "", value: t._title ?? "" };
    row.other     = { display: t.other ?? "", value: t.other ?? "" };
    row.done      = { display: t.done ? "Yes" : "No", value: t.done ? 1 : 0 };

    // List-valued columns — see the 'list' branch _renderFieldValue() gained
    // in assets/ui/_list.js. One <li> per attached sub-item, entityType
    // becomes its CSS class.
    row.batches       = this._listCell(this._batchesByTask.get(t.id), this._batchItemDisplay, 'batch');
    row.recipe_counts = this._listCell(this._recipeCountsByTask.get(t.id), this._recipeCountItemDisplay, 'recipe_count');
    row.preps         = this._listCell(this._prepsByTask.get(t.id), this._prepItemDisplay, 'prep');
  }

  _listCell(items, formatFn, entityType) {
    const list = Array.isArray(items) ? items : [];
    return {
      display: '',
      items: list.map((item) => ({ display: formatFn(item), entityType })),
    };
  }

  // Builds this._{batches,recipeCounts,preps}ByTask (task id -> raw items)
  // plus the three *ItemDisplay formatters closed over their id->title maps
  // — called once per buildRows() rather than re-filtering/re-resolving per
  // row, same reasoning as BatchHistoryGridModel's this._flavorsById.
  _indexComponents() {
    const flavorsById     = Indexer.byId(this.domain?.flavor);
    const recipesById     = Indexer.byId(this.domain?.recipe);
    const ingredientsById = Indexer.byId(this.domain?.ingredient);
    const unitsById       = Indexer.byId(this.domain?.unit);

    const batches      = Array.isArray(this.domain?.batch) ? this.domain.batch : [];
    const recipeCounts = Array.isArray(this.domain?.recipe_count) ? this.domain.recipe_count : [];
    const preps         = Array.isArray(this.domain?.prep) ? this.domain.prep : [];

    this._batchesByTask      = Indexer.groupBy(batches, (b) => Number(b.task ?? 0));
    this._recipeCountsByTask = Indexer.groupBy(recipeCounts, (r) => Number(r.task ?? 0));
    this._prepsByTask        = Indexer.groupBy(preps, (p) => Number(p.task ?? 0));

    this._batchItemDisplay = (b) =>
      `${flavorsById.get(Number(b.flavor ?? 0))?._title ?? '—'} ×${this._fmtCount(b.count)}`;

    this._recipeCountItemDisplay = (r) =>
      `${recipesById.get(Number(r.recipe ?? 0))?._title ?? '—'} ×${this._fmtCount(r.count)}`;

    this._prepItemDisplay = (p) => {
      const ingredient = ingredientsById.get(Number(p.ingredient ?? 0))?._title ?? '—';
      const unit = unitsById.get(Number(p.units ?? 0))?._title ?? '';
      return `${ingredient} ${this._fmtCount(p.count)}${unit ? ' ' + unit : ''}`;
    };
  }

  _fmtCount(raw) {
    if (raw == null || raw === "") return "";
    const n = Number(raw);
    if (!Number.isFinite(n)) return String(raw);
    return Number.isInteger(n) ? String(n) : n.toFixed(2);
  }

  // ── Date-range filter on `completed` (server-side, triggers a bundle
  // refresh on change) — see the module comment for why only done tasks are
  // actually bounded by this. ──
  getFilterDefs() {
    return [{
      key: 'completed',
      label: 'Done tasks from',
      type: 'select',
      mode: 'server',
      default: DEFAULT_PRESET,
      options: [
        { key: 'last_24_hours', label: '24 hrs' },
        { key: 'last_48_hours', label: '48 hrs' },
        { key: 'last_7_days',   label: '7 days'   },
        { key: 'last_30_days',  label: '30 days'  },
      ],
    }];
  }

  getServerFilterParams() {
    return {
      date_filters: 'completed',
      filter_completed: this.getFilterValue('completed'),
    };
  }

  setFilterValue(key, value) {
    if (key !== 'completed') return;
    this.filterValues.completed = this._normalizePreset(value);
  }

  getFilterValue(key) {
    return key === 'completed' ? (this.filterValues.completed ?? DEFAULT_PRESET) : undefined;
  }

  _normalizePreset(value) {
    const v = String(value ?? '').trim().toLowerCase().replace(/[^a-z0-9_]/g, '_');
    return DATE_FILTER_PRESETS.includes(v) ? v : DEFAULT_PRESET;
  }

  _fmtDate(raw) {
    if (!raw) return "";
    return String(raw).slice(0, 16); // "YYYY-MM-DD HH:MM"
  }
}
