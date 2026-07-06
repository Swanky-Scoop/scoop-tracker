///////////////////////////////////
// Table amd Form combo
// consumes different cell/input types
// can be augmented with FindInGrid
// depends on MOM_El.js
// Is fed by MDL*.js files
//////////////////////////////////

// GRID ---------------------------
import El         from "./_el.js";
import FindIt     from "./find-it.js";
import TextIt     from "./text-it.js";
import FindInGrid from "./find-in-grid.js";
import Toast      from "./toast.js";

export default class Grid extends El{
  constructor(target, name, config = {}) {
    super();
    this.target = target;
    this.name = name;
    this.modelInstance = config?.modelInstance ?? null;
    this.location = config?.modelInstance?.location ?? 0;
    this.formCodec = config?.formCodec;
    this.msgManager = config?.msgManager;

    this._columnsSet = false;
    this.cols = null;
    this.rows = [];
    this.rowGroups = [];
    this.rowGroupDom = [];

    this.sortColumn = null;
    this.sortDirection = 'asc';

    this.baseline = new Map();
    this.dirtySet = new Set();
    this.state = null;
    this.filter = null;
    this._isInit = false;
    this._docListenerBound = false;
    this._lastFocusedEl = this.target;
    this._postSubmitFocus = false;

    // Autosave mode (opt-in per model via `autosave = true`): every cell change
    // POSTs immediately with NO full page reload, and the save button is hidden.
    this._autosaving = false;
    this._autosavePending = false;
    this._autosaveTimer = null;

    this.loadConfig(config);
    this._build();
    
    // NEW: Preload columns from metadata if available
    if (config?.columns?.length) {
      this.setColumns(config.columns, true);
    } else if (config?.api?.Meta) {
      const metaCols = config.api.Meta.forGrid(name);
      if (metaCols?.length) this.setColumns(metaCols, true);
    }
    
    this._attachCoreDom();
    this._bindEvents();
  }

  init(state = this.state) {
    if (this._isInit) return this.refresh(state);

    this.state = state;
    
    // Only set columns if not already set from metadata
    if (!this._columnsSet) {
      this.setColumns(state.columns, true);
    }
    
    this._buildFilters(state);
    this._rebuildBodies(state);
    this._filter = (state?.filter) ? new FindInGrid(this.FORM, { root: this.TABLE }) : this.filter;

    this._captureBaseline();
    this._applyAutosaveUI();

    this.FORM.dispatchEvent(new Event("ts:grid:init"));
    this._isInit = true;
  }

  loadConfig({ api, formCodec, domainCodec, modelInstance } = {}) {
    if (api) this.api = api;
    if (formCodec) this.formCodec = formCodec;
    if (domainCodec) this.domainCodec = domainCodec;
    if (modelInstance) this.modelInstance = modelInstance;
    this.postUrl = this.api?.baseUrl ?? this.postUrl;
  }

  async setDomain(domain) {
    // Pass full domain to model, which will build rows
    this.modelInstance.setDomain(domain);
    
    // Model IS the state
    this.state = this.modelInstance;
    
    // Initialize or refresh grid
    if (this._isInit) {
        this.refresh(this.state);
    } else {
        this.init(this.state);
    }
  }

  async refresh(state) {
    if (!this._isInit) throw new Error("Grid.refresh() called before init()");
    this.state = state;
    this._buildFilters(state);
    this._rebuildBodies(state);
    this._captureBaseline();
    this._applyAutosaveUI();
    this.FORM.dispatchEvent(new Event("ts:grid:close-overlays"));
  }
  
  preloadColumns(columns) {
    this.setColumns(columns, true);
    this._captureBaseline(); // optional: only if inputs already exist (often they won't yet)
  }

  setColumns(columns = [], force){
    if(!force && this._columnsSet) return;
    this._columnsSet = true;
    this.cols = columns;
    this._buildCols();
  }

  setRowGroups(rowGroups = []){
    this.rowGroups = rowGroups;
    this._buildRowGroups();
  }

  setRows(rows = [], rowGroups = this.rowGroups ?? []){
    this.rows = rows;
    this.rowGroups = rowGroups;
    this._buildRows();
  }

  _build(){
    const el = this.el;
    this.FORM   = el( 'form',  { classes:['zGRID-form'] } );
    this.TOGGLE = el("button", {text:"x", classes:["gridToggle"] } );
    this.FILTERS = el( 'div',   { classes:['gridFilters', 'empty'] } );
    this.SUBMIT = el( 'button',{ classes:['save'], text : 'save', attrs:{ type:'submit' }  }  );
    this.TABLE  = el( 'table', { classes:['zGRID'] } );
    this.THEAD  = el( 'thead' );
    this.TRH    = el( 'tr' );

    this.THEAD.append(this.TRH);

    if(this.cols) this._buildCols();
  }

  _rebuildBodies({ rowGroups, rows }) {
    this.TABLE.querySelectorAll("tbody").forEach(tb => tb.remove());
    this.rowGroupDom = [];
    this.setRowGroups(rowGroups ?? []);
    this.setRows(rows ?? []);
  }

  _buildCols(){
    if(!this.cols || !this.TABLE  || !this.TRH) return;

    this.TRH.replaceChildren();
    this.TABLE.querySelectorAll('tbody').forEach( el=>el.remove() );
    
    for (const col of this.cols) {
      const TH = this.el( 
        "th", { 
          text: col.label ?? col.key, 
          classes:[col.key, col.type, 'sortable'],
          data: {key:col.key} 
        }  
      );
      if(col.hidden) TH.classList.add('hidden');
      this.TRH.append(TH);
    }
  
    this.TABLE.prepend(this.THEAD);
  }

  _buildRowGroups(){
    if(!this.rowGroups || this.rowGroups.length === 0) return;
    this.rowGroupDom.forEach(g=>g.remove());
    this.rowGroupDom = [];
    const gEls = this.rowGroupDom;
    const el = this.el;
    
    // When the grid is narrowed to a single group, open it so its child rows
    // are visible without an extra click.
    const onlyOneGroup = this.rowGroups.length === 1;

    let groupCount = 0;
    for(const g of this.rowGroups){

      const opened = onlyOneGroup ? true : !g.collapsed;
      const TBODY = el('tbody', {classes:['groupBody', (g.collapsible?'collapsible':'static'), (opened?'opened':'closed') ],
        data:{rowType:g.rowType, groupType: g.groupType}
      });
      const GR = el('tr', {classes:['group'], data:{ rowId:g.groupId, groupLabel:g.label } } );
      const GD = el('th', {classes:['groupCell'], attrs:{'colSpan':this.cols.length } } )  
      const SP = el('b');
      const OC = (g.collapsible)? el( 'button', {classes:["oc"]} ) : null;
      const LB = el("span", {text:g.label, classes:["groupLabel"] } );
      
      if(g.collapsible) SP.append(OC);
      if(g.badges && g.badges[0]) GD.append(this._getBadgeDom(g.badges));
      
        
      SP.append(LB);
      GD.append(SP);
      GR.append(GD);
      
      TBODY.append(GR);

      this.TABLE.append(TBODY);
      this.rowGroupDom.push(TBODY);
      
    }
  }

  _buildRows() {
    try {
      const rows = this.rows ?? [];
      const cols = this.cols ?? [];
      const rowGroups = this.rowGroups ?? [];
      const el = this.el;

      if (!rowGroups.length) {
        this.rowGroupDom = [ el('tbody') ];
        this.TABLE.append(this.rowGroupDom[0]);
      }

      let i = 0;

      rows.forEach((row, r) => {
        if (rowGroups[i + 1] && r === rowGroups[i + 1].startIndex) i++;

        const TR = el('tr', { classes:['row'], data:{ rowId: row?.id?.rowId ?? row?.id ?? 0 } });

        cols.forEach(col => {
          const data = row?.[col.key] ?? "";
          TR.append(this._getCellDom(col, data));
        });

        const body = this.rowGroupDom[i];
        if (!body) {
          console.error("Grid buildRows: missing tbody", {
            grid: this.name,
            i, r,
            rowGroups: rowGroups.map(g => g.startIndex),
            rowGroupDomLen: this.rowGroupDom.length,
            rowsLen: rows.length
          });
          return; // stop building further rows; or create a fallback body (below)
        }

        body.append(TR);
      });

    } catch (e) {
      console.error("Grid _buildRows exception", this.name, e, {
        rowsLen: this.rows?.length,
        rowGroups: this.rowGroups
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

  _getCellDom(col,data){
    // TODO: Decide if I am going to demand data types for all cells
    // TODO: Decide how I am going to swallow or reflect those types in the css class
    const d = (data && typeof data === "object") ? data : { display: String(data ?? "") };
    const CELL = this.el('td', { classes:['cell', col.key, col.type ?? 'ok_colType', d.alertCase ?? 'ok_alertCase' ] });

    if(col.hidden) CELL.classList.add('hidden');
    
    if(col.write && d.write !== false){
      if(col.hidden)
        new TextIt(CELL, col, this.name);
      else if(col.control === "text" )
        new TextIt(CELL, data, this.name);
      //else if(col.num);
      else new FindIt(CELL, data, this.name);
    }else{
      CELL.append('' + (data.display || ''));
      CELL.classList.add('read-only');
    }
    if(data.badges && data.badges[0]) CELL.append(this._getBadgeDom(data.badges));

    return CELL;
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
    if (!this.FORM.contains(this.TABLE)) this.FORM.append(this.TABLE);
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

  _buildDirtyPayload() {
    const changes = { cells: {} };
    for (const k of this.dirtySet) {
      const [rowIdStr, colKey] = k.split("|");
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
        this.dirtySet.delete(k);
      }
    }
  }
  
  _showHide(e, el=e.target){
    if(el.closest(".oc")){
        const TB = e.target.closest("TBODY");
        TB.classList.toggle('opened');
        TB.classList.toggle('closed');
    }
    
    this.FORM.dispatchEvent(new Event("ts:grid:close-overlays"));

  }

  _captureFocusAddress(e) {
    const el = document.activeElement;
    if (!el || !this.FORM.contains(el)) return null;

    // If focus is inside a cell editor, find the hidden input that already has the key
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
  // - Grouped grids: the rebuild can collapse/reorder the group that held the
  //   edited cell, so returning there is jarring. Send focus to the top text
  //   filter (so the user can keep filtering); if there's no text filter, the
  //   first top-most editable input.
  // - Ungrouped grids: keep the original behavior — return to the cell the
  //   user was editing.
  _focusAfterSubmit() {
    if (!this.rowGroups || this.rowGroups.length === 0) {
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

  // First visible, editable input control in table order (skips hidden-column
  // inputs and rows inside collapsed groups). Falls back to the first match.
  _firstEditableInput() {
    const sel = 'input:not([type="hidden"]):not([disabled]), textarea:not([disabled]), [contenteditable="true"]';
    const candidates = [...(this.TABLE?.querySelectorAll(sel) ?? [])];
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

    // Prefer focusing the visible input in the same cell (FindIt/TextIt)
    const cell = h.closest('td');
    const focusable =
      cell?.querySelector('input:not([type="hidden"]), textarea, [contenteditable="true"], button');

    requestAnimationFrame(() => (focusable ?? h).focus());
  }

  _sortCols(e) {
    const th = e.target.closest("th.sortable");
    if (!th) return;
  
    const colKey = th.dataset.key;
    if (!colKey) return;
  
    if (this.sortColumn === colKey) {
      this.sortDirection = this.sortDirection === "asc" ? "desc" : "asc";
    } else {
      this.sortColumn = colKey;
      this.sortDirection = "asc";
    }
  
    this._applySortAndRender();
  }

  _applySortAndRender(){
    const preSortRows = this.rows;
    if(!this.rowGroups || this.rowGroups.length < 1 ){
      this.rows = this._sortRows(preSortRows, this.sortColumn, this.sortDirection);
      this._rebuildBodies({ rowGroups: this.rowGroups, rows: this.rows });
      return;
    } 
    const sortedGroups = this.rowGroups.map((group, groupIndex) => {
      const groupRows = this.rows.filter((row, i) => {
        // Find which group this row belongs to
        let currentGroup = 0;
        for (let g = 0; g < this.rowGroups.length; g++) {
          if (i >= this.rowGroups[g].startIndex) {
            currentGroup = g;
          } else {
            break;
          }
        }
        return currentGroup === groupIndex;
      });
      
      const sorted = this._sortRows(groupRows, this.sortColumn, this.sortDirection);
      return { group, rows: sorted };
    });
     
    // Rebuild rows array in sorted order
    this.rows = [];
    this.rowGroups = [];
    let startIndex = 0;
    
    sortedGroups.forEach(({ group, rows }) => {
      group.startIndex = startIndex;
      this.rowGroups.push(group);
      this.rows.push(...rows);
      startIndex += rows.length;
    });
    
    // Re-render the table
    this._rebuildBodies({ rowGroups: this.rowGroups, rows: this.rows });
  }

  _sortRows(rows, colKey, direction) {
    if (!colKey) return rows;

    // Find the column descriptor so _getSortValue can honour col.type.
    // Without this, numeric columns (whose display is a formatted string
    // like "9.7") fall through to localeCompare and sort lexically —
    // "84.6" < "9.7" as text.
    const col = (this.cols ?? []).find(c => c.key === colKey) ?? null;

    const sorted = [...rows].sort((a, b) => {
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
    // Handle different cell data structures
    if (cellData == null) return null;

    if (typeof cellData === 'object') {
      // For numeric columns, prefer the raw numeric .value over the
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
    
    const v = (h.name.indexOf('[state]') === -1) ?
      this.formCodec.normalizeScalar(h.value ?? "") : h.value;
  
    const before = this._normValue(colKey, this.baseline.get(k));
    const after  = this._normValue(colKey, v);

    if (before === after) this.dirtySet.delete(k);
    else this.dirtySet.add(k);

    // Autosave grids persist each change right away (no save button, no reload).
    if (this._autosaveEnabled()) this._scheduleAutosave();
  }

  // ─── Autosave ──────────────────────────────────────────────────────────────
  // Opt in per model: `this.autosave = true`. Each cell change is POSTed on a
  // short debounce; unlike the normal submit path it does NOT call
  // refreshPageDomain(), so the page/grid is never rebuilt out from under the
  // user mid-edit. The save button is hidden (see _applyAutosaveUI).

  _autosaveEnabled() {
    return !!(this.state && this.state.autosave);
  }

  _applyAutosaveUI() {
    const on = this._autosaveEnabled();
    this.FORM.classList.toggle('autosave', on);
    if (this.SUBMIT) this.SUBMIT.hidden = on;
  }

  _scheduleAutosave() {
    clearTimeout(this._autosaveTimer);
    this._autosaveTimer = setTimeout(() => this._autosaveFlush(), 250);
  }

  async _autosaveFlush() {
    // Coalesce: if a save is already in flight, run once more when it lands.
    if (this._autosaving) { this._autosavePending = true; return; }

    const changes = this._buildDirtyPayload();
    if (!Object.keys(changes.cells).length) return;

    if (!this.api || !this.postUrl) {
      console.error('Grid autosave: missing api/postUrl');
      return;
    }

    this._autosaving = true;
    this.FORM.classList.add('autosaving');

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
      // cells already display the values the user entered.
      this._commitPosted(changes);
      this._flashCells(changes, 'cell-saved');

    } catch (err) {
      console.error('Grid autosave exception:', err);
      Toast.addMessage({ title: 'Autosave error', message: String(err) });
    } finally {
      this._autosaving = false;
      this.FORM.classList.remove('autosaving');
      if (this._autosavePending) {
        this._autosavePending = false;
        this._scheduleAutosave();
      }
    }
  }

  // Briefly mark the just-saved (or errored) cells so autosave is visible.
  _flashCells(changes, cls) {
    for (const [rowId, row] of Object.entries(changes.cells ?? {})) {
      for (const colKey of Object.keys(row ?? {})) {
        const h = this.FORM.querySelector(
          `input[type="hidden"][name="${this.name}[cells][${rowId}][${colKey}]"]`
        );
        const cell = h?.closest('td');
        if (!cell) continue;
        cell.classList.add(cls);
        setTimeout(() => cell.classList.remove(cls), 800);
      }
    }
  }

  async _bindEvents() {
    if (this._eventsBound || !this.FORM) return;
    this._eventsBound = true;

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
      this._showHide(e);
      this._sortCols(e);
    }, true);

    this.FORM.addEventListener("submit", async (e) => {      
      e.preventDefault();
      this._captureFocusAddress(e);

      if(e.submitter && e.submitter.classList.contains('oc')) return false;

      if (!this.api) throw new Error('Grid submit: missing this.api');
      if (!this.postUrl) throw new Error('Grid submit: missing this.postUrl');

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
          await this.api.refreshPageDomain({ force: true, toast:TOAST, info:{name:this.name, response:r} });
          // Usually handled by the ts:domain:updated listener after it rebuilds
          // the DOM; this is a fallback if that listener didn't run/consume it.
          if (this._postSubmitFocus) {
            this._postSubmitFocus = false;
            this._focusAfterSubmit();
          }
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
        this._reloading = true;
        try {
          // Get fresh domain from API
          const freshDomain = this.api.getDomainSnapshot();
          
          // Update model with new domain (rebuilds rows)
          if (this.modelInstance) {
            this.modelInstance.setDomain(freshDomain);
          }
          
          // Refresh grid with updated model
          if (this._isInit) {
            await this.refresh(this.modelInstance);
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
  
  destroy() {
    if (this._onDomainUpdated) {
      document.removeEventListener("ts:domain:updated", this._onDomainUpdated);
      this._onDomainUpdated = null;
      this._docListenerBound = false;
    }
    this.FORM?.remove();
  }

}
