///////////////////////////////////
// List — abstract parent for Grid (table) and Tile (card) views.
// Owns everything that is NOT visual markup: sort, filter wiring, autosave,
// submit, domain refresh, Details drill-down. A concrete subclass supplies
// the actual DOM by overriding five hooks:
//
//   buildCoreDom()             — create + assign this.FORM/TOGGLE/FILTERS/
//                                 SUBMIT/FRAME/TOOLS (the chrome)
//   buildMetaFieldDom(field)   — one node per field in the header/tools area.
//                                 Give it [data-sort-key] if it should sort.
//   buildGroupDom(group,       — one container node that items get appended
//     fields, opened)            into. If group.label is falsy, this is the
//                                 synthetic "ungrouped" container — skip
//                                 rendering a header for it.
//   buildItemDom(row, fields)  — one EMPTY container node for a single item;
//                                 List appends each field's DOM into it.
//   buildFieldDom(col, data)   — create your own wrapper node, call
//                                 this._renderFieldValue(wrapper, col, data)
//                                 to fill it (FindIt/TextIt/detail-link/
//                                 badges — all shared), then return it.
//
// Every hook throws until a subclass overrides it — this class cannot be
// used unless all five are implemented.
//////////////////////////////////

import El         from "./_el.js";
import FindIt     from "./find-it.js";
import TextIt     from "./text-it.js";
import FindInGrid from "./find-in-grid.js";
import Toast      from "./toast.js";
import Details    from "./details.js";
import PageStatus from "./page-status.js";

// Fade duration for a dirty marker resolving once the domain that confirms
// it actually lands — see _flashResolvedMarks(). Must match the
// `transition` durations on .cell-dirty-clearing/.row-dirty-clearing/
// .group-dirty-clearing in css.css.
const DIRTY_CLEAR_FADE_MS = 600;

export default class List extends El{
  constructor(target, name, config = {}) {
    super();
    this.target = target;
    this.name = name;
    this.modelInstance = config?.modelInstance ?? null;
    this.location = config?.modelInstance?.location ?? 0;
    this.formCodec = config?.formCodec;
    this.msgManager = config?.msgManager;
    this.pageStatusId = config?.pageStatusId ?? null;

    this._fieldSet = false;
    this.fields = null;
    this.items = [];
    this.itemGroups = [];
    this.itemGroupDom = [];

    // Survives a rebuild (refresh() rebuilds every group container from
    // scratch, so the DOM's own .opened/.closed classes are lost) — keyed by
    // group.groupId, the one thing that's stable for the same group across a
    // domain refresh even though its DOM node isn't. Only holds entries the
    // user has actually toggled; groups never touched keep falling back to
    // the model's own collapsed default (see _buildItemGroups).
    this._groupOpenOverrides = new Map();

    this.sortField = null;
    this.sortDirection = 'asc';

    this.baseline = new Map();
    this.dirtySet = new Set();
    // A cell moves here from dirtySet the instant its save is confirmed
    // (_commitPosted) — it's no longer at risk of being lost, but the DOM
    // still hasn't been rebuilt from a domain fetch that actually reflects
    // it, so it's not "current" yet either. Only clears once a genuine
    // domain refresh lands and rebuilds this grid — see _onDomainUpdated.
    // Three states this + dirtySet together capture: dirty (in dirtySet) =
    // changed, not saved; here-not-dirtySet = changed, not refreshed; in
    // neither = current.
    this.awaitingRefreshSet = new Set();
    this.state = null;
    this.filter = null;
    this._isInit = false;
    this._docListenerBound = false;
    this._lastFocusedEl = this.target;
    this._postSubmitFocus = false;

    // Autosave mode (opt-in per model via `autosave = true`): every field change
    // POSTs immediately with NO full page reload, and the save button is hidden.
    this._autosaving = false;
    this._autosavePending = false;
    this._autosaveTimer = null;

    this.loadConfig(config);
    this._build();

    // Preload fields from metadata if available
    if (config?.columns?.length) {
      this.setFields(config.columns, true);
    } else if (config?.api?.Meta) {
      const metaCols = config.api.Meta.forGrid(name);
      if (metaCols?.length) this.setFields(metaCols, true);
    }

    this._attachCoreDom();
    this._bindEvents();
  }

  init(state = this.state) {
    if (this._isInit) return this.refresh(state);

    this.state = state;

    // Only set fields if not already set from metadata
    if (!this._fieldSet) {
      this.setFields(state.columns, true);
    }

    this._buildFilters(state);
    this._rebuildBodies(state);
    this._filter = (state?.filter) ? new FindInGrid(this.FORM, { root: this.FRAME }) : this.filter;

    this._captureBaseline();
    this._applyAutosaveUI();

    this.FORM.dispatchEvent(new Event("ts:list:init"));
    this._isInit = true;
    this._reportFresh();
  }

  loadConfig({ api, formCodec, domainCodec, modelInstance } = {}) {
    if (api) this.api = api;
    if (formCodec) this.formCodec = formCodec;
    if (domainCodec) this.domainCodec = domainCodec;
    if (modelInstance) this.modelInstance = modelInstance;
    this.postUrl = this.api?.baseUrl ?? this.postUrl;
  }

  async setDomain(domain) {
    // Pass full domain to model, which will build items
    this.modelInstance.setDomain(domain);

    // Model IS the state
    this.state = this.modelInstance;

    // Initialize or refresh
    if (this._isInit) {
        this.refresh(this.state);
    } else {
        this.init(this.state);
    }
  }

  async refresh(state) {
    if (!this._isInit) throw new Error("List.refresh() called before init()");
    this.state = state;
    this._buildFilters(state);
    this._rebuildBodies(state);
    this._captureBaseline();
    this._applyAutosaveUI();
    this.FORM.dispatchEvent(new Event("ts:list:close-overlays"));
    this._reportFresh();
  }

  // Rendered data now matches the last domain snapshot this grid applied —
  // called from both first-load (init) and every subsequent re-render
  // (refresh). No-op for grids that weren't registered with PageStatus
  // (pageStatusId null — e.g. the PopularKey Grid nested inside PopularPlot).
  _reportFresh() {
    if (this.pageStatusId) PageStatus.setState(this.pageStatusId, 'fresh');
  }

  // Reports this grid's dirty/unsaved-edit boolean to PageStatus — see
  // PageStatus.setEditing(). Covers every List (autosave or manual-Save),
  // since dirtySet tracking itself isn't autosave-specific.
  _reportEditingState() {
    if (!this.pageStatusId) return;
    PageStatus.setEditing(this.pageStatusId, !!(this.dirtySet?.size || this._autosaving));
  }

  preloadColumns(columns) {
    this.setFields(columns, true);
    this._captureBaseline(); // optional: only if inputs already exist (often they won't yet)
  }

  setFields(columns = [], force){
    if(!force && this._fieldSet) return;
    this._fieldSet = true;
    this.fields = columns;
    this._buildFields();
  }

  setItemGroups(itemGroups = []){
    this.itemGroups = itemGroups;
    this._buildItemGroups();
  }

  setItems(items = [], itemGroups = this.itemGroups ?? []){
    this.items = items;
    this.itemGroups = itemGroups;
    this._buildItems();
  }

  // ─── Subclass hooks — all abstract, all required ──────────────────────────

  buildCoreDom() {
    throw new Error(`${this.constructor.name}.buildCoreDom() must be overridden — create and assign this.FORM, this.TOGGLE, this.FILTERS, this.SUBMIT, this.FRAME, and this.TOOLS.`);
  }

  buildMetaFieldDom(field) {
    throw new Error(`${this.constructor.name}.buildMetaFieldDom() must be overridden — return one DOM node representing field "${field?.key}" for the header/tools area. Give it a [data-sort-key] attribute if it should be sortable.`);
  }

  buildGroupDom(group, fields, opened) {
    throw new Error(`${this.constructor.name}.buildGroupDom() must be overridden — return one container node that items get appended into. If group.label is falsy, this is the synthetic ungrouped container: skip rendering a header for it.`);
  }

  buildItemDom(row, fields) {
    throw new Error(`${this.constructor.name}.buildItemDom() must be overridden — return one empty container node for a single item; List appends each field's DOM into it.`);
  }

  buildFieldDom(col, data, row) {
    throw new Error(`${this.constructor.name}.buildFieldDom() must be overridden — create your own wrapper node, call this._renderFieldValue(wrapper, col, data) to fill it, then return the wrapper. row is the full item, for cases like an <img alt> that need more than the one field's value.`);
  }

  // ─── Shared build pipeline — calls the hooks above, owns nothing visual ───

  _build(){
    this.buildCoreDom(this);
    if (this.fields) this._buildFields();
  }

  // Accepts either the model itself (which exposes .rows/.rowGroups — that
  // naming lives on BaseGridModel and isn't part of this refactor) or a
  // plain { rows, rowGroups } object from _applySortAndRender's re-sort.
  _rebuildBodies(state) {
    this.setItemGroups(state?.rowGroups ?? []);
    this.setItems(state?.rows ?? []);
  }

  _buildFields(){
    if (!this.fields || !this.FRAME || !this.TOOLS) return;

    this.TOOLS.replaceChildren();

    for (const field of this.fields) {
      const META = this.buildMetaFieldDom(field);
      if (field.hidden) META.classList.add('hidden');
      this.TOOLS.append(META);
    }
  }

  _clearGroupContainers() {
    this.itemGroupDom.forEach(el => el.remove());
    this.itemGroupDom = [];
  }

  _buildItemGroups(){
    this._clearGroupContainers();
    if (!this.itemGroups || this.itemGroups.length === 0) return;

    // When narrowed to a single group, open it so its items are visible
    // without an extra click.
    const onlyOneGroup = this.itemGroups.length === 1;

    for (const g of this.itemGroups) {
      const override = g.groupId != null ? this._groupOpenOverrides.get(String(g.groupId)) : undefined;
      const opened = onlyOneGroup ? true : (override !== undefined ? override : !g.collapsed);
      const CONTAINER = this.buildGroupDom(g, this.fields, opened);
      this.FRAME.append(CONTAINER);
      this.itemGroupDom.push(CONTAINER);
    }
  }

  _buildItems() {
    try {
      const items = this.items ?? [];
      const fields = this.fields ?? [];
      const itemGroups = this.itemGroups ?? [];

      // No groups: one synthetic, headerless container holds every item.
      if (!itemGroups.length) {
        const CONTAINER = this.buildGroupDom({ collapsible: false, label: null }, fields, true);
        this.FRAME.append(CONTAINER);
        this.itemGroupDom = [CONTAINER];
      }

      let i = 0;

      items.forEach((row, r) => {
        if (itemGroups[i + 1] && r === itemGroups[i + 1].startIndex) i++;

        const ITEM = this.buildItemDom(row, fields);

        fields.forEach(col => {
          const data = row?.[col.key] ?? "";
          ITEM.append(this.buildFieldDom(col, data, row));
        });

        const container = this.itemGroupDom[i];
        if (!container) {
          console.error("List _buildItems: missing group container", {
            list: this.name,
            i, r,
            itemGroups: itemGroups.map(g => g.startIndex),
            itemGroupDomLen: this.itemGroupDom.length,
            itemsLen: items.length
          });
          return; // stop building further items
        }

        // buildGroupDom may nest items inside a child element (e.g. a <ul>
        // inside a card group's <div>) rather than appending directly to the
        // container it returned — that child, if any, is stashed on
        // container._itemsHost.
        (container._itemsHost ?? container).append(ITEM);
      });

    } catch (e) {
      console.error("List _buildItems exception", this.name, e, {
        itemsLen: this.items?.length,
        itemGroups: this.itemGroups
      });
    }
  }

  _buildFilters(state = this.state) {
    if (!this.FILTERS) return;

    this.FILTERS.replaceChildren();

    const defs = typeof state?.getFilterDefs === 'function' ? state.getFilterDefs() : [];
    if (!defs.length) {
      this.FILTERS.remove();
      return;
    }

    this.FILTERS.hidden = false;
    this.FILTERS.classList.remove('empty');

    defs.forEach(def => {
      if (def?.type !== 'select') return;

      const key = String(def.key ?? '');
      if (!key) return;

      const id = `${this.name}-${key}-filter`;
      const label = this.el('label', { attrs: { for: id }, classes: ['gridFilter'] });
      const labelText = this.el('span', { text: def.label ?? key.replace(/_/g, ' ') });
      const select = this.el('select', {
        attrs: { id },
        data: { filterKey: key, filterMode: def.mode ?? 'client' },
      });

      const selected = typeof state?.getFilterValue === 'function'
        ? state.getFilterValue(key)
        : def.default;

      (def.options ?? []).forEach(option => {
        const opt = this.el('option', {
          text: option.label ?? option.key,
          attrs: { value: option.key },
        });
        if (String(option.key) === String(selected)) opt.selected = true;
        select.append(opt);
      });

      label.append(labelText, select);
      this.FILTERS.append(label);
    });
  }

  // Fills an already-created wrapper node (built by the subclass's
  // buildFieldDom) with the field's actual value: FindIt/TextIt when
  // writeable, plain text or a Details detail-link when read-only, plus
  // badges. This is the part every view shares — only the wrapper tag/class
  // varies per subclass.
  _renderFieldValue(EL, col, data) {
    const d = (data && typeof data === "object") ? data : { display: String(data ?? "") };

    // classList.add() throws on an empty-string token (unlike El.el()'s
    // `classes` array, which silently filters those out) — getAlertCase()
    // defaults to '' rather than undefined, so this must filter too.
    // col.type is the hand-authored-model convention; col.dataType is what
    // server-driven metadata columns carry (see grid.js's header comment on
    // this long-standing split). Prefer whichever is actually present.
    const stateClasses = [col.key, col.type ?? col.dataType ?? 'ok_colType', d.alertCase ?? 'ok_alertCase']
      .filter(c => typeof c === 'string' && c.trim().length > 0);
    if (stateClasses.length) EL.classList.add(...stateClasses);
    if (col.hidden) EL.classList.add('hidden');

    if (col.write && d.write !== false) {
      if (col.hidden)
        new TextIt(EL, col, this.name);
      else if (col.control === "text")
        new TextIt(EL, data, this.name);
      else new FindIt(EL, data, this.name);
    } else {
      // Read-only relationship fields (currently: flavor) link to a Details
      // panel instead of plain text. col.detailEntity lets a non-relationship
      // field (e.g. a row's own title) opt into the same link without
      // triggering titleMap's id-lookup display logic.
      const entityKey = col.detailEntity ?? col.titleMap;
      if (entityKey === 'flavor' && d.id) {
        EL.append(this.el('a', {
          text: '' + (d.display || ''),
          classes: ['detail-link'],
          attrs: { href: `#details=${encodeURIComponent(entityKey)}%3A${d.id}` },
          data: { detailEntity: entityKey, detailId: d.id },
        }));
      } else {
        EL.append('' + (d.display || ''));
      }
      EL.classList.add('read-only');
    }

    if (data.badges && data.badges[0]) EL.append(this._getBadgeDom(data.badges));

    return EL;
  }

  // Group container class from group.groupType ('cabinet', 'flavor', ...) —
  // shared by Grid and Tile's buildGroupDom so every grouped view's group
  // container carries a class naming what it's grouped by, not just the
  // per-group data-group-type attribute already set. null (not '_slug's
  // 'item' fallback) for the synthetic ungrouped container, which has no
  // groupType at all — see List._buildItems().
  _groupTypeClass(group) {
    return group?.groupType ? this._slug(group.groupType) : null;
  }

  // CSS-safe class token from arbitrary text (a title, a slot name, an
  // allergen slug already conforms and passes through unchanged). Falls back
  // to 'item' rather than risk classList.add('') — see _renderFieldValue's
  // comment on why that throws.
  _slug(text) {
    return String(text ?? '')
      .trim()
      .replace(/\s+/g, '_')
      .replace(/[^a-zA-Z0-9_-]/g, '') || 'item';
  }

  // Optional per-model hook: this.state.rowClassFields — an array of column
  // keys (or { key, value: 'display'|'logical' } objects) whose resolved
  // cell value becomes a `${key}-${slug}` class on the row's <tr>/<li>. Can
  // list as many fields as the model wants, including every field it shows.
  // _slug() doesn't lowercase, so e.g. 'state' displaying "Opened" becomes
  // class "state-Opened", not "state-opened" — match that casing in CSS.
  //
  // 'display' (the default) reads row[key].display — the human-readable
  // value already resolved by fillRowFromColumns: for a relation/titleMap
  // column that's the looked-up label ("Vanilla"), for a plain scalar/enum
  // column (e.g. 'state') it's the raw value itself, since fillRowFromColumns
  // only does a titleMap lookup when there IS a titleMap.
  //
  // 'logical' reads row[key].id instead — the raw foreign-key id. Only
  // meaningful for relation/titleMap columns; a plain scalar column has no
  // distinct id separate from its display value (fillRowFromColumns sets id
  // = Number(raw), which is just NaN for a non-numeric scalar like 'state'),
  // so 'logical' isn't useful there — use 'display' for those.
  //
  // Skips a field entirely (no class) rather than falling through to
  // _slug's 'item' default when the resolved value is empty — an unset
  // field shouldn't render as a literal "-item" class.
  _rowClasses(row) {
    const fields = this.state?.rowClassFields;
    if (!fields?.length || !row) return [];

    return fields.map(entry => {
      const { key, value: mode = 'display' } = (typeof entry === 'string') ? { key: entry } : (entry ?? {});
      if (!key) return null;

      const cell = row[key];
      const raw = mode === 'logical' ? cell?.id : cell?.display;
      if (raw == null || raw === '' || (typeof raw === 'number' && !Number.isFinite(raw))) return null;

      return `${key}-${this._slug(String(raw))}`;
    }).filter(Boolean);
  }

  _getBadgeDom(badges){
    if(!badges || badges.length === 0) return null;
    const BDGs = this.el("span", {classes:["badges"] } );
    badges.forEach( (b) => {
      const B = this.el('b', {text:b.text, classes:['badge', b.key] } );
      BDGs.append(B);
    } );
    return BDGs;
  }

  _attachCoreDom(){
    if (!this.FORM.contains(this.FILTERS)) this.FORM.append(this.FILTERS);
    if (!this.FORM.contains(this.FRAME)) this.FORM.append(this.FRAME);
    if (!this.FORM.contains(this.SUBMIT)) this.FORM.append(this.SUBMIT);
    if (!this.target.contains(this.FORM)) this.target.append(this.FORM);
    if (!this.target.contains(this.TOGGLE)) this.target.append(this.TOGGLE);
  }

  _buildAllPayload() {
    const changes = { cells: {} };

    const inputs = this.FORM.querySelectorAll(
      `input[type="hidden"][name^="${this.name}[cells]"]`
    );

    for (const input of inputs) {
      const parsed = this.formCodec.parseBracketName(input.name);
      if (!parsed || parsed.length < 4) continue;
      if (parsed[0] !== this.name || parsed[1] !== "cells") continue;

      const rowId = Number(parsed[2]);      // allow 0
      const colKey = parsed[3];

      const value = this.formCodec.normalizeScalar(input.value ?? "");
      if (!changes.cells[rowId]) changes.cells[rowId] = {};
      changes.cells[rowId][colKey] = value;
    }

    return changes;
  }

  _buildDirtyPayload(fieldFilter = null) {
    const changes = { cells: {} };
    for (const k of this.dirtySet) {
      const [rowIdStr, colKey] = k.split("|");
      if (fieldFilter && !fieldFilter(colKey)) continue;
      const rowId = Number(rowIdStr);

      const input = this.FORM.querySelector(
        `input[type="hidden"][name="${this.name}[cells][${rowId}][${colKey}]"]`
      );
      if (!input) continue;

      const value = this.formCodec.normalizeScalar(input.value ?? "");
      if (!changes.cells[rowId] ) changes.cells[rowId] = {};
      changes.cells[rowId][colKey] = value;
    }

    return changes;
  }

  _captureBaseline() {
    const { flat } = this.formCodec.extractGridChanges(this.FORM, this.name);

    this.baseline = new Map();
    this.dirtySet = new Set();

    for (const f of flat) {
      const k = `${f.rowId}|${f.colKey}`;
      this.baseline.set(k, f.value);
    }

    this._reportEditingState();
  }

  _normValue(colKey, raw) {
    if (raw == null) return "";

    // state enum
    if (colKey === "state") return String(raw);

    // amount float
    if (colKey === "amount") {
      const n = Number(raw);
      return Number.isFinite(n) ? n : 0;
    }

    // default: use your existing scalar normalizer (may return number or string)
    return this.formCodec.normalizeScalar(raw);
  }


  _normRelId(v) {
    if (v == null) return 0;
    if (typeof v === 'number') return v > 0 ? v : 0;
    if (typeof v === 'string') {
      const n = Number(v);
      return Number.isFinite(n) && n > 0 ? n : 0;
    }
    if (typeof v === 'object') {
      const n = Number(v.id ?? v.ID ?? 0);
      return Number.isFinite(n) && n > 0 ? n : 0;
    }
    return 0;
  }

  _commitPosted(changes) {
    for (const [rowId, row] of Object.entries(changes.cells ?? {})) {
      for (const [colKey, val] of Object.entries(row ?? {})) {
        const k = `${rowId}|${colKey}`;
        this.baseline.set(k, val);

        // She may have kept typing while this POST was in flight (autosave
        // debounces 250ms, then waits on the network) — the live input can
        // already differ from the value we just committed. Only clear the
        // dirty flag if it still matches; otherwise leave it dirty so the
        // next autosave sweep picks up the newer keystrokes instead of a
        // background domain refresh rebuilding the DOM out from under her
        // and discarding them (see the dirty guard in _onDomainUpdated).
        if (this._normValue(colKey, this._liveValue(rowId, colKey)) === this._normValue(colKey, val)) {
          this.dirtySet.delete(k);
          this.awaitingRefreshSet.add(k);
        }
        this._refreshDirtyMarks(rowId, colKey);
      }
    }

    this._reportEditingState();
  }

  _showHide(e, el=e.target){
    if(el.closest(".oc")){
        const CONTAINER = e.target.closest("[data-group-container], TBODY");
        CONTAINER?.classList.toggle('opened');
        CONTAINER?.classList.toggle('closed');

        const groupId = CONTAINER?.dataset.groupId;
        if (groupId != null) {
          this._groupOpenOverrides.set(groupId, CONTAINER.classList.contains('opened'));
        }
    }

    this.FORM.dispatchEvent(new Event("ts:list:close-overlays"));

  }

  _captureFocusAddress(e) {
    const el = document.activeElement;
    if (!el || !this.FORM.contains(el)) return null;

    // If focus is inside a field editor, find the hidden input that already has the key
    const h = (e.target instanceof HTMLInputElement && e.target.type === "hidden")
      ? e.target : e.target.closest('input[type="hidden"][name]');
    if (!h) return null;

    const parsed = this.formCodec.parseBracketName(h.name);
    if (!parsed || parsed.length < 4) return null;
    if (parsed[0] !== this.name || parsed[1] !== 'cells') return null;

    this._lastFocusedEl = { rowId: Number(parsed[2]), colKey: parsed[3] };

    return this._lastFocusedEl;
  }

  // Where focus lands after a successful submit.
  //
  // - Grouped lists: the rebuild can collapse/reorder the group that held the
  //   edited field, so returning there is jarring. Send focus to the top text
  //   filter (so the user can keep filtering); if there's no text filter, the
  //   first top-most editable input.
  // - Ungrouped lists: keep the original behavior — return to the field the
  //   user was editing.
  _focusAfterSubmit() {
    if (!this.itemGroups || this.itemGroups.length === 0) {
      this._restoreFocusAddress();
      return;
    }

    requestAnimationFrame(() => {
      const filterInput = this.FORM.querySelector('input.gridFilterInput');
      if (filterInput) { filterInput.focus(); return; }

      const first = this._firstEditableInput();
      if (first) first.focus();
    });
  }

  // First visible, editable input control in document order (skips hidden-
  // field inputs and items inside collapsed groups). Falls back to the first match.
  _firstEditableInput() {
    const sel = 'input:not([type="hidden"]):not([disabled]), textarea:not([disabled]), [contenteditable="true"]';
    const candidates = [...(this.FRAME?.querySelectorAll(sel) ?? [])];
    return candidates.find(el => el.offsetParent !== null) ?? candidates[0] ?? null;
  }

  _restoreFocusAddress() {
    const addr = this._lastFocusedEl;
    if (!addr) return;

    const { rowId, colKey } = addr;

    const h = this.FORM.querySelector(
      `input[type="hidden"][name="${this.name}[cells][${rowId}][${colKey}]"]`
    );
    if (!h) return;

    // Prefer focusing the visible input in the same field (FindIt/TextIt)
    const field = h.closest('td, [data-field]') ?? h.parentElement;
    const focusable =
      field?.querySelector('input:not([type="hidden"]), textarea, [contenteditable="true"], button');

    requestAnimationFrame(() => (focusable ?? h).focus());
  }

  // Sort trigger is any element carrying [data-sort-key] — a <th> in a table
  // view, a button/chip in a card view — so both drive the same sort logic
  // via one delegated click handler.
  _sortCols(e) {
    const trigger = e.target.closest("[data-sort-key]");
    if (!trigger) return;

    const colKey = trigger.dataset.sortKey;
    if (!colKey) return;

    if (this.sortField === colKey) {
      this.sortDirection = this.sortDirection === "asc" ? "desc" : "asc";
    } else {
      this.sortField = colKey;
      this.sortDirection = "asc";
    }

    this._applySortAndRender();
  }

  _applySortAndRender(){
    const preSortItems = this.items;
    if(!this.itemGroups || this.itemGroups.length < 1 ){
      this.items = this._sortItems(preSortItems, this.sortField, this.sortDirection);
      this._rebuildBodies({ rowGroups: this.itemGroups, rows: this.items });
      return;
    }
    const sortedGroups = this.itemGroups.map((group, groupIndex) => {
      const groupItems = this.items.filter((row, i) => {
        // Find which group this item belongs to
        let currentGroup = 0;
        for (let g = 0; g < this.itemGroups.length; g++) {
          if (i >= this.itemGroups[g].startIndex) {
            currentGroup = g;
          } else {
            break;
          }
        }
        return currentGroup === groupIndex;
      });

      const sorted = this._sortItems(groupItems, this.sortField, this.sortDirection);
      return { group, items: sorted };
    });

    // Rebuild items array in sorted order
    this.items = [];
    this.itemGroups = [];
    let startIndex = 0;

    sortedGroups.forEach(({ group, items }) => {
      group.startIndex = startIndex;
      this.itemGroups.push(group);
      this.items.push(...items);
      startIndex += items.length;
    });

    // Re-render
    this._rebuildBodies({ rowGroups: this.itemGroups, rows: this.items });
  }

  _sortItems(items, colKey, direction) {
    if (!colKey) return items;

    // Find the field descriptor so _getSortValue can honour col.type.
    // Without this, numeric fields (whose display is a formatted string
    // like "9.7") fall through to localeCompare and sort lexically —
    // "84.6" < "9.7" as text.
    const col = (this.fields ?? []).find(c => c.key === colKey) ?? null;

    const sorted = [...items].sort((a, b) => {
      const aVal = this._getSortValue(a[colKey], col);
      const bVal = this._getSortValue(b[colKey], col);

      // Handle nulls
      if (aVal == null && bVal == null) return 0;
      if (aVal == null) return 1;
      if (bVal == null) return -1;

      // Compare
      let comparison = 0;
      if (typeof aVal === 'number' && typeof bVal === 'number') {
        comparison = aVal - bVal;
      } else {
        comparison = String(aVal).localeCompare(String(bVal));
      }

      return direction === 'asc' ? comparison : -comparison;
    });

    return sorted;
  }

  _getSortValue(cellData, col = null) {
    // Handle different field data structures
    if (cellData == null) return null;

    if (typeof cellData === 'object') {
      // For numeric fields, prefer the raw numeric .value over the
      // formatted display string so the comparator takes the numeric
      // branch. Fall back through value -> display -> id, coercing when
      // the value is a numeric string (e.g. "9.7" from JSON).
      if (col?.type === 'number') {
        const n = this._asFiniteNumber(cellData.value);
        if (n !== null) return n;
        const d = this._asFiniteNumber(cellData.display);
        if (d !== null) return d;
        const i = this._asFiniteNumber(cellData.id);
        if (i !== null) return i;
        return null;
      }

      // Non-numeric: preserve the original preference order.
      return cellData.display ?? cellData.id ?? cellData.value ?? null;
    }

    return cellData;
  }

  _asFiniteNumber(v) {
    if (v == null || v === '') return null;
    if (typeof v === 'number') return Number.isFinite(v) ? v : null;
    if (typeof v === 'string') {
      const n = Number(v);
      return Number.isFinite(n) ? n : null;
    }
    return null;
  }

  // Reads a cell's current live DOM value, normalized the same way
  // _handleCellChange compares it — shared with _commitPosted so it can
  // tell whether a field is still dirty after an autosave POST that may
  // have raced with more typing.
  _liveValue(rowId, colKey) {
    const input = this.FORM.querySelector(
      `input[type="hidden"][name="${this.name}[cells][${rowId}][${colKey}]"]`
    );
    if (!input) return null;
    return (input.name.indexOf('[state]') === -1)
      ? this.formCodec.normalizeScalar(input.value ?? "")
      : input.value;
  }

  _handleCellChange(e)
  {
    const h = e.target.closest('input[type="hidden"][name]');
    if (!h) return;

    const parsed = this.formCodec.parseBracketName(h.name);
    if (!parsed || parsed.length < 4) return;
    if (parsed[0] !== this.name || parsed[1] !== "cells") return;

    const rowId = Number(parsed[2]);
    const colKey = parsed[3];
    const k = `${rowId}|${colKey}`;

    const v = this._liveValue(rowId, colKey);

    const before = this._normValue(colKey, this.baseline.get(k));
    const after  = this._normValue(colKey, v);

    if (before === after) this.dirtySet.delete(k);
    else this.dirtySet.add(k);

    this._refreshDirtyMarks(rowId, colKey);

    // Autosave lists persist each change right away (no save button, no reload).
    if (this._fieldAutosaveEnabled(colKey)) this._scheduleAutosave();

    this._reportEditingState();
  }

  // Reflects dirtySet + awaitingRefreshSet onto the DOM as they change, at
  // three levels: the specific cell, the row it's in (marked if ANY of its
  // cells are), and the group it's in (marked if ANY of its rows are) — see
  // css.css's --color-dirty-* variables for what these classes look like.
  // "Marked" here means changed-not-saved OR changed-not-refreshed — both
  // read the same visually (still unsettled); see the constructor comment
  // on awaitingRefreshSet for why they're tracked as two sets instead of
  // one. Called live as the user types (_handleCellChange), once a change
  // is confirmed saved (_commitPosted), and once it's actually resolved by
  // a real domain refresh (_flashResolvedMarks) — always instant, both
  // directions; the visible "fade" only happens at the third point, once a
  // fetched domain has actually confirmed the value, not on a guessed timer.
  _refreshDirtyMarks(rowId, colKey) {
    const h = this.FORM.querySelector(
      `input[type="hidden"][name="${this.name}[cells][${rowId}][${colKey}]"]`
    );
    if (!h) return;

    const k = `${rowId}|${colKey}`;
    const CELL = h.closest('td, [data-field]') ?? h.parentElement;
    CELL?.classList.toggle('cell-dirty', this.dirtySet.has(k) || this.awaitingRefreshSet.has(k));

    const ROW = h.closest('[data-row-id]');
    if (!ROW) return;

    const rowMarked = [...this.dirtySet, ...this.awaitingRefreshSet].some(key => key.startsWith(`${rowId}|`));
    ROW.classList.toggle('row-dirty', rowMarked);

    const GROUP = ROW.closest('[data-group-container]');
    if (!GROUP) return;

    const groupRowIds = new Set(
      [...GROUP.querySelectorAll('[data-row-id]:not(.group)')].map(el => el.dataset.rowId)
    );
    const groupMarked = [...this.dirtySet, ...this.awaitingRefreshSet].some(key => groupRowIds.has(key.split('|')[0]));
    GROUP.classList.toggle('group-dirty', groupMarked);
  }

  // Called from _onDomainUpdated right after a real domain refresh rebuilds
  // this grid — resolvedKeys is whatever was in awaitingRefreshSet just
  // before that rebuild (already cleared by the caller, and the fresh DOM
  // it just built has none of the dirty/awaiting classes on it, since
  // _refreshDirtyMarks was never called for the new elements). This briefly
  // stamps the *-clearing variant onto the elements that correspond to
  // those resolved keys, then lets css.css's transition fade them out over
  // DIRTY_CLEAR_FADE_MS — so confirmation reads as a deliberate fade rather
  // than the marker just never having been there.
  _flashResolvedMarks(resolvedKeys) {
    if (!resolvedKeys.length) return;

    const resolvedRows = new Set();
    const resolvedGroups = new Set();

    for (const k of resolvedKeys) {
      const [rowIdStr, colKey] = k.split('|');
      const rowId = Number(rowIdStr);

      const h = this.FORM.querySelector(
        `input[type="hidden"][name="${this.name}[cells][${rowId}][${colKey}]"]`
      );
      if (!h) continue; // no longer rendered (e.g. filtered out by the refresh)

      const CELL = h.closest('td, [data-field]') ?? h.parentElement;
      this._flashClearing(CELL, 'cell-dirty', 'cell-dirty-clearing');

      const ROW = h.closest('[data-row-id]');
      if (ROW) resolvedRows.add(ROW);

      const GROUP = ROW?.closest('[data-group-container]');
      if (GROUP) resolvedGroups.add(GROUP);
    }

    resolvedRows.forEach(ROW => this._flashClearing(ROW, 'row-dirty', 'row-dirty-clearing'));
    resolvedGroups.forEach(GROUP => this._flashClearing(GROUP, 'group-dirty', 'group-dirty-clearing'));
  }

  // The *-clearing classes only define the FADED-OUT end state (transparent
  // + a transition) — on a freshly-rebuilt element there's nothing colored
  // to transition FROM, so swapping straight to *-clearing would just be
  // transparent the whole time, no visible flash at all. So: apply the
  // solid dirtyClass first, force the browser to actually paint that (the
  // offsetWidth read below flushes pending style changes — a plain
  // classList.add() followed immediately by another change would otherwise
  // get coalesced into one paint with no transition to animate across),
  // *then* swap to clearingClass so the transition has a real start point.
  _flashClearing(EL, dirtyClass, clearingClass) {
    if (!EL) return;
    EL.classList.add(dirtyClass);
    void EL.offsetWidth;
    EL.classList.remove(dirtyClass);
    EL.classList.add(clearingClass);
    setTimeout(() => EL.classList.remove(clearingClass), DIRTY_CLEAR_FADE_MS);
  }

  // ─── Autosave ──────────────────────────────────────────────────────────────
  // Opt in per model: `this.autosave = true`. Each field change is POSTed on a
  // short debounce; unlike the normal submit path it does NOT call
  // refreshPageDomain(), so the page/list is never rebuilt out from under the
  // user mid-edit. The save button is hidden (see _applyAutosaveUI) unless the
  // model also sets `autosaveFields` (a Set of column keys) — that opts IN
  // only those fields to autosave and leaves the rest on the manual Save
  // button. Mixing the two on one grid (FlavorTubGridModel used to: 'use'/
  // 'amount' autosaved, 'state' didn't) turned out to be a bad idea in
  // practice — a full-domain refresh (e.g. a filter change) rebuilds the
  // whole grid from server truth, which silently drops whatever's still
  // pending on the manual side right as the autosaved side visibly lands,
  // reading as data loss even though nothing autosaved was actually lost.
  // Autosave needs to be all-or-nothing per grid until/unless that gets
  // fixed some other way.

  _autosaveEnabled() {
    return !!(this.state && this.state.autosave);
  }

  _fieldAutosaveEnabled(colKey) {
    if (!this._autosaveEnabled()) return false;
    const fields = this.state?.autosaveFields;
    return !fields || fields.has(colKey);
  }

  _applyAutosaveUI() {
    const on = this._autosaveEnabled();
    const partial = on && !!this.state?.autosaveFields;
    this.FORM.classList.toggle('autosave', on);
    // Partial autosave models still need the Save button for their
    // manual-only fields (e.g. FlavorTub's 'state').
    this.FORM.classList.toggle('autosave-partial', partial);
    if (this.SUBMIT) this.SUBMIT.hidden = on && !partial;
  }

  _scheduleAutosave() {
    clearTimeout(this._autosaveTimer);
    this._autosaveTimer = setTimeout(() => this._autosaveFlush(), 250);
  }

  async _autosaveFlush() {
    // Coalesce: if a save is already in flight, run once more when it lands.
    if (this._autosaving) { this._autosavePending = true; return; }

    // Only sweep in fields this model actually autosaves — a dirty 'state'
    // edit sitting alongside an autosaved 'use'/'amount' change on the same
    // row must stay pending for the manual Save button.
    const fields = this.state?.autosaveFields;
    const changes = this._buildDirtyPayload(fields ? (colKey) => fields.has(colKey) : null);
    if (!Object.keys(changes.cells).length) return;

    if (!this.api || !this.postUrl) {
      console.error('List autosave: missing api/postUrl');
      return;
    }

    this._autosaving = true;
    this.FORM.classList.add('autosaving');
    this._reportEditingState();

    try {
      const r = await this.api.postJson(changes, this.name);

      if (!r.ok || !r.data?.ok) {
        Toast.addMessage({
          title: 'Autosave failed',
          message: r?.data ? JSON.stringify(r.data) : `HTTP ${r?.status}`,
        });
        this._flashCells(changes, 'cell-error');
        return;
      }

      // Commit to baseline (clears the dirty flags) with NO page reload — the
      // fields already display the values the user entered.
      this._commitPosted(changes);
      this._flashCells(changes, 'cell-saved');
      this._scheduleBackgroundDomainRefresh();

    } catch (err) {
      console.error('List autosave exception:', err);
      Toast.addMessage({ title: 'Autosave error', message: String(err) });
    } finally {
      this._autosaving = false;
      this.FORM.classList.remove('autosaving');
      this._reportEditingState();
      if (this._autosavePending) {
        this._autosavePending = false;
        this._scheduleAutosave();
      }
    }
  }

  // After an autosaved write, other grids on the page (bundle-based derived
  // views not being edited right now) may be showing stale downstream state.
  // Debounce a background bundle refetch so a burst of autosaves only
  // triggers one fetch once things settle; this does NOT touch this grid's
  // own DOM — see the dirty/in-flight guard in _onDomainUpdated.
  _scheduleBackgroundDomainRefresh() {
    clearTimeout(this._domainRefreshTimer);
    this._domainRefreshTimer = setTimeout(() => {
      this.api?.refreshPageDomain({ force: true, info: { name: this.name } }).catch(err =>
        console.error('Background domain refresh failed:', err)
      );
    }, 800);
  }

  // Briefly mark the just-saved (or errored) fields so autosave is visible.
  _flashCells(changes, cls) {
    for (const [rowId, row] of Object.entries(changes.cells ?? {})) {
      for (const colKey of Object.keys(row ?? {})) {
        const h = this.FORM.querySelector(
          `input[type="hidden"][name="${this.name}[cells][${rowId}][${colKey}]"]`
        );
        const field = h?.closest('td, [data-field]') ?? h?.parentElement;
        if (!field) continue;
        field.classList.add(cls);
        setTimeout(() => field.classList.remove(cls), 800);
      }
    }
  }

  async _bindEvents() {
    if (this._eventsBound || !this.FORM) return;
    this._eventsBound = true;

    // Warn before navigating away with edits that never made it to the
    // server — the manual-submit fields (e.g. FlavorTub's 'state') are the
    // real risk here since autosaved fields clear within ~250ms.
    window.addEventListener('beforeunload', (e) => {
      if (!this.dirtySet.size) return;
      e.preventDefault();
      e.returnValue = '';
    });

    // Listen for BOTH FindIt and TextIt changes
    this.FORM.addEventListener('ts:findit-change', this._handleCellChange.bind(this));
    this.FORM.addEventListener('ts:textit-change', this._handleCellChange.bind(this));

    this.FORM.addEventListener('change', async (e) => {
      const select = e.target.closest('select[data-filter-key]');
      if (!select || !this.FORM.contains(select)) return;

      const key = select.dataset.filterKey;
      const mode = select.dataset.filterMode;

      if (typeof this.modelInstance?.setFilterValue === 'function') {
        this.modelInstance.setFilterValue(key, select.value);
      }

      if (mode === 'server' && typeof this.api?.refreshGridFilters === 'function') {
        select.disabled = true;
        try {
          await this.api.refreshGridFilters(this);
        } finally {
          select.disabled = false;
        }
      } else if (this.modelInstance) {
        // BaseGridModel's own rebuild method — model-side naming, not part of this refactor.
        this.modelInstance._buildRows();
        await this.refresh(this.modelInstance);
      }
    });

    this.FORM.addEventListener("keydown", (e) => {

      // Spacebar activates focused buttons (including group toggles)
      if (e.key === " "){
        const el = document.activeElement;
        if (!el) return;
        if (!this.FORM.contains(el)) return;

        const isButton = el.matches("button, [role='button']");
        if (!isButton) return;

        // Avoid interfering with typing in inputs/textareas
        if (el.matches("input, textarea, [contenteditable='true']")) return;

        e.preventDefault();
        this._showHide(e,el);
      }

      // Ctrl+s to save the form
      if(
        (e.key === "Enter" && (e.ctrlKey || e.metaKey)) ||
        (e.key.toLowerCase() === "s" && (e.ctrlKey || e.metaKey))
      ){
        const el = document.activeElement;
        if (!el || !this.FORM.contains(el)) return;

        // Don't interfere with IME / composition
        if (e.isComposing) return;

        e.preventDefault();
        this.FORM.requestSubmit();
      }

    }, true);

    this.target.addEventListener("click", (e)=>{
      if(e.target.closest(".gridToggle")){
        this.target.classList.toggle("toggled");
        e.stopPropagation();
        return false;
      }

      const link = e.target.closest("[data-detail-entity]");
      if (link) {
        e.preventDefault();
        this._openDetails(link);
        return;
      }

      this._showHide(e);
      this._sortCols(e);
    }, true);

    this.FORM.addEventListener("submit", async (e) => {
      e.preventDefault();
      this._captureFocusAddress(e);

      if(e.submitter && e.submitter.classList.contains('oc')) return false;

      if (!this.api) throw new Error('List submit: missing this.api');
      if (!this.postUrl) throw new Error('List submit: missing this.postUrl');

      const submitBtn = this.FORM.querySelector('button[type="submit"], input[type="submit"]');
      if (submitBtn) submitBtn.disabled = true;

      try {
        const isAll = this.state.submitMode === 'all';
        const changes = ( isAll)? this._buildAllPayload() :this._buildDirtyPayload();

        // OPTIONAL: no-op submit guard
        if (!Object.keys(changes.cells).length && !isAll) {
          console.log("No changes to submit.", changes);
          return;
        }

        const r = await this.api.postJson(changes, this.name);

        if (!r.ok) {
          Toast.addMessage({title:'no POST', message:`HTTP ${r.status}`});
          return;
        }

        if (!r.data?.ok) {
          Toast.addMessage({title:'bad post', message:JSON.stringify(r.data, null)});
          return;
        }

        if (r.ok && r.data?.ok) {
          document.body.classList.add('TS_GRID-UPDATING');

          this._commitPosted(changes);

          const chng = this.modelInstance.describeFieldChanges(r.data, changes?.cells??[] );
          const TOAST = Toast.addMessage({
            title: 'Update Confirmed',
            changes: chng
          });

          this._postSubmitFocus = true;

          // The save is already confirmed and this grid already reflects it
          // (_commitPosted above applied it optimistically), so the submit
          // button doesn't need to stay disabled for the length of this
          // refresh too — it's re-enabled below in `finally` as soon as this
          // fires, not once it resolves. It's still kicked off immediately
          // (not awaited later) so every grid on the page starts re-checking
          // its state with the server right away.
          this.api.refreshPageDomain({ force: true, toast:TOAST, info:{name:this.name, response:r} })
            .then(() => {
              // Usually handled by the ts:domain:updated listener after it
              // rebuilds the DOM; this is a fallback if that listener didn't
              // run/consume it.
              if (this._postSubmitFocus) {
                this._postSubmitFocus = false;
                this._focusAfterSubmit();
              }
            })
            .catch(err => console.error("Post-save domain refresh failed:", err));
        }

      } catch (err) {
        console.error("POST exception:", err);
      } finally {
        if (submitBtn) submitBtn.disabled = false;
      }
    });

    if (!this._docListenerBound) {
      this._docListenerBound = true;
      this._onDomainUpdated = async () => {
        if (this._reloading) return;

        // This grid is mid-autosave or has edits not yet flushed — a full
        // rebuild here would wipe in-progress input/focus. Skip it; the
        // background refresh was for OTHER grids' derived state, and this
        // grid gets its own fresh domain next time it rebuilds unforced.
        if (this._autosaveEnabled() && (this._autosaving || this.dirtySet?.size)) {
          if (this.pageStatusId) PageStatus.setState(this.pageStatusId, 'stale');
          return;
        }

        this._reloading = true;
        try {
          // Get fresh domain from API
          const freshDomain = this.api.getDomainSnapshot();

          // Update model with new domain (rebuilds items)
          if (this.modelInstance) {
            this.modelInstance.setDomain(freshDomain);
          }

          // Refresh with updated model
          if (this._isInit) {
            // Whatever's still pending here is about to be genuinely
            // resolved by this rebuild — snapshot it before refresh()
            // rebuilds the DOM out from under it (fresh elements carry no
            // dirty/awaiting classes at all), then flash the fade on the
            // new elements that correspond to it. See awaitingRefreshSet's
            // constructor comment and _flashResolvedMarks.
            const resolvedKeys = [...this.awaitingRefreshSet];
            this.awaitingRefreshSet.clear();

            await this.refresh(this.modelInstance);
            this._flashResolvedMarks(resolvedKeys);

            if (this._postSubmitFocus) {
              this._postSubmitFocus = false;
              this._focusAfterSubmit();
            } else {
              this._restoreFocusAddress();
            }
          }
        } finally {
          this._reloading = false;
        }
      };

      document.addEventListener("ts:domain:updated", this._onDomainUpdated);
    }
  }

  // Details resolves the item itself from the page's shared bundle domain.
  _openDetails(linkEl) {
    const entity = linkEl.dataset.detailEntity;
    const id = Number(linkEl.dataset.detailId);
    if (!entity || !id) return;

    Details.open(entity, id, { level: 1 });
  }

  destroy() {
    if (this._onDomainUpdated) {
      document.removeEventListener("ts:domain:updated", this._onDomainUpdated);
      this._onDomainUpdated = null;
      this._docListenerBound = false;
    }
    if (this.pageStatusId) PageStatus.remove(this.pageStatusId);
    this.FORM?.remove();
  }

}
