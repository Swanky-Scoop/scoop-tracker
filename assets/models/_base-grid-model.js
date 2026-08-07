///////////////////////////////////
// base class for Models 
// Does almost everything for everyone
// leaves getRows and getColumns 
// should be overritten by children
// TODO: needs to refactor out IF blocks
// ...and leave that to the kids...
//////////////////////////////////
import Indexer         from "../data/indexer.js";
import Flavor          from "./_flavor.js";
import ColumnsProvider from "./_column-provider.js";

// _formatTimeAgo's day-scale tiers past "yesterday" — first one whose
// `under` (days) the actual gap still falls under wins, so keep it ordered
// finest-to-coarsest. A "year" is a flat 365 days from today, not calendar
// years, per how this was asked for. Past 400 days the vague bucket stops
// being useful and _formatTimeAgo falls through to an exact "X days old"
// instead — no tier needed for that, it's the loop's fallback.
const TIME_AGO_DAY_TIERS = [
  { under: 7,   label: n => `${n} days ago` },
  { under: 30,  label: () => 'More than a week ago' },
  { under: 60,  label: () => 'More than 30 days ago' },
  { under: 90,  label: () => 'More than 60 days ago' },
  { under: 365, label: () => 'More than 90 days ago' },
  { under: 400, label: () => 'More than a year ago' },
];

export default class BaseGridModel {
  
  // BaseGridModel - Split _build() into two phases
  constructor(name, domain = null, options = {}) {
      this.name = name;
      this.domain = null;  // Always start null
      this.metaData = options?.metaData ?? null;
      this.location = options?.location ?? 935;

      // Dock icon-strip identity (see DOCKING.md). Constructor option wins,
      // then server-fed metaData (scoop_client_metadata() in enqueue.php),
      // then a bare fallback off the type's own logical name — so every
      // model has a usable displayTitle/icon even for types that don't go
      // through scoop_routes_config() (Analytics, Popular, Flavors, ...).
      this.displayTitle = options?.displayTitle ?? this.metaData?.displayTitle ?? name;
      this.icon = options?.icon ?? this.metaData?.icon ?? (name ? String(name).charAt(0) : '?');

      // Named dockTarget, not target — this.target on List (assets/ui/_list.js)
      // is the host DOM element; this is the unrelated string config value
      // ('action' | 'aside', or null for normal .canvas placement) from
      // 'target' in scoop_routes_config() (_config.php). See DOCKING.md.
      this.dockTarget = options?.dockTarget ?? this.metaData?.target ?? null;

      // How this control's host should size/behave when docked in .canvas
      // (irrelevant once dockTarget routes it into a slot instead — see
      // above) — one of 'half-stack' (default) | 'full-stack' |
      // 'half-nostack' | 'full-nostack'. 'half'/'full' is roughly how much
      // canvas width it claims; 'nostack' means it expects the whole canvas
      // HEIGHT too, so opening it closes every other open canvas control
      // (and vice versa) — see List._enforceCanvasExclusivity() in
      // assets/ui/_list.js. From 'canvas_mode' in scoop_routes_config().
      this.canvasMode = options?.canvasMode ?? this.metaData?.canvasMode ?? 'half-stack';

      this.showList = [];
      this.columns = [];
      this.rows = [];
      this.rowGroups = [];

      this.dateFormat = {
        month: "numeric",
        day:   "numeric",
      };
      
      // Phase 1: Build columns from metadata (no domain needed)
      if (this.metaData) {
          this._buildColumns();
      }
      
      // Phase 2: Set domain and build rows if provided
      if (domain) {
          this.setDomain(domain);
      }
  }
  
  setShowList(keys = []) {
    this.showList = Array.isArray(keys) ? keys : [];
    this._applyColumnFilter();
  }
  getShowList(){
    return this.showList;
  }

  setDomain(domain) {
    this.domain = domain;
    
    // Clear cached title maps when domain changes
    this._titleMaps = new Map();
    
    // Initialize domain-dependent helpers
        
    this._cabinetsById     = domain.cabinet ? Indexer.byId(domain.cabinet) : new Map();
    this._slotsByCabinetId = domain.cabinet && domain.slot ? Indexer.groupBy(
        domain.slot, s => s.cabinet) : new Map();
    this._flavorsById      = domain.flavor ?  Indexer.byId(domain.flavor) : new Map();
    this._tubsById         = domain.tub ?     Indexer.byId(
        domain.tub.filter(t => t.state !== 'Emptied')) : new Map();
    this._availByFlavor = domain?.tub ?       Indexer.groupBy(
        domain.tub.filter(t => t.state !== "Emptied"),
        t => t.flavor) : new Map();

    if (domain) {
      this.flavorMeta = new Flavor({
          flavorsById: this._flavorsById,
          location:    this.location,
          tub:         domain.tub
      });

      this.fBadgeSpecs = this.flavorMeta.getFlavorBadgeSpecs();
    }
    
    this._buildRows();
  }

  _buildColumns() {
      this.buildCols();  // Only columns, from metadata
  }
  
  _buildRows() {
      this.buildRows();  // Only rows, from domain
  }
  
  // Keep _build() for legacy one-shot init if needed
  _build() {
      this._buildColumns();
      this._buildRows();
  }

  getById(map, id) {
    if (!id) return null;
    return map.get(Number(id)) ?? null;
  }

  titleById(map, id, fallback = "") {
    if(typeof map === 'undefined') return fallback || id;
    if (!id) return "";
    return map.get(Number(id))?._title ?? fallback;
  }

  getIdsForLocation(list, { locationKey = "location", idKey = "id" } = {}) {
    const ids = new Set();
    for (const item of (Array.isArray(list) ? list : [])) {
      ids.add(item?.[idKey]);
    }
    return ids;
  }

  filterByLocation(list, { locationKey = "location" } = {}) {
    // Accept either array or full domain object
    const items = Array.isArray(list) ? list : (list?.[this.metaData?.primary] || []);
    
    return items.filter(item =>
        item?.[locationKey] === this.location
    );
  }
  
  labelFromMap(objOrId, map, {fieldKey = null, fallbackNoun = "Item"} = {}){
    const id = fieldKey
      ? objOrId?.[fieldKey]
      : objOrId;
    if (!id) return null;

    const item = map?.get?.(id);
    return item?._title ?? `${fallbackNoun} ${id}`;

  }

  describeFieldChanges(responseData = {}, rawChanges = [] ) {
    const changes = [];
    const created = responseData?.created ?? null;
    const updates = responseData?.updated ?? {};
    const ref     = responseData?.cfg?.pod_name ?? this.metaData?.primary ?? "unknown";
  
    const items   = Array.isArray(this.domain?.[ref]) ? this.domain[ref] : [];
    const byId    = Indexer.byId(items);
  
    // Prefer the full column catalog so hidden/editable fields still resolve nicely
    const cols    = this._allColumns?.length ? this._allColumns : (this.columns ?? []);
    const colsByKey = new Map(cols.map(col => [col.key, col]));

    if (created) {
      const { count = 0, flavor: flavorId = 0 } = rawChanges?.[0] ?? {};
      const flavorTitle = this.titleById(this._flavorsById, flavorId, "unknown flavor");
    
      return [ { sentence: `Created ${count} tubs of ${flavorTitle}` }];
    }

    for (const [rowIdStr, patch] of Object.entries(updates)) {
      const rowId     = Number(rowIdStr);
      const item      = byId.get(rowId);
      const subject   =  item?._title ?? `${ref} ${rowId}`;
      
  
      for (const [fieldKey, rawNewValue] of Object.entries(patch ?? {})) {
        const col = colsByKey.get(fieldKey) ?? {
          key:    fieldKey,
          label:  fieldKey.replace(/_/g, " ")
        };
        const newValue = this._describePostedValue(rawNewValue, col, fieldKey);
        const modified = col.label ?? fieldKey.replace(/_/g, " ");

        changes.push({
          rowId,
          rawNewValue,
          subject,
          newValue,
          modified,
          sentence: `${subject}: ${modified} -> ${newValue}`,
        });
      }

    }
  
    return changes;
  }
  
  _describePostedValue(rawValue, col = {}, fieldKey = "") {
    // Relationship cleared intentionally
    if (rawValue === 0 || rawValue === "0") {
      if (col.titleMap) return "(cleared)";
      return "0";
    }
    if (rawValue == null || rawValue === "") return "";
  
    // Resolve relationship IDs through existing titleMap logic
    if (col.titleMap) {
      const titled = this.titleFrom(rawValue, col);
      return titled ?? String(rawValue);
    }
  
    // Try enum/options labels
    const options = this.getOptions(0, fieldKey) ?? [];
    const match   = options.find(opt => String(opt.key) === String(rawValue));
    if (match?.label) return match.label;
  
    return String(rawValue);
  }

  buildCols() {
    const md      = this.metaData;
    if (!md || !md.primary || !md.entities) return; 
    
    const rawColumns = md.entities[md.primary];
    
    // Apply titleMap inference
    this._allColumns = rawColumns.map(col => ({
        ...col,
        titleMap: col.titleMap || this._inferTitleMap(col.key)
    }));
    
    this._applyColumnFilter();
    
    if (this.columns?.length) return this.columns;
    
    throw new Error(`${this.name}.buildCols(): no metadata and no fallback implemented`);
  }

  _applyColumnFilter() {
    if (!this._allColumns.length) return;
    
    // If showList is empty, show all columns
    if (!this.showList.length) {
        this.columns = this._allColumns;
        return;
    }
    
    // Filter to only columns in showList
    const showSet = new Set(this.showList);
    this.columns = this._allColumns.filter(col => showSet.has(col.key));
  }
  
  _columnsFromMetadata(key, propList) {
    const cols = [];
    for (const [key, meta] of Object.entries(this.metaData.columns)) {
      if (!meta.visible) continue;
      
      cols.push({
        key,
        label: meta.label,
        type: meta.dataType,
        write: meta.editable,  // ← Server-controlled editability
        hidden: meta.hidden,
        control: meta.control === 'input' ? 'text' : undefined,
        titleMap: this._inferTitleMap(key)
      });
    }
    return cols;
  }

  _inferTitleMap(key) {
    const maps = {
      'flavor': 'flavor',
      'current_flavor': 'flavor',
      'immediate_flavor': 'flavor',
      'next_flavor': 'flavor',
      'use': 'use',
      'location': 'location',
      'cabinet': 'cabinet'
    };
    return maps[key] ?? null;
  }

  _filterColumnsByKeys(columns, allowedKeys = []) {
    const set = new Set(allowedKeys);
    return (columns ?? []).filter(col => set.has(col.key));
  }

  buildRows(){
    const type = this.metaData.primary;
    const items = this.domain[type] || [];
    
    this.rows = [];
    items.forEach((item, i) => {
        const row = { id: item.id };
        this.fillRowFromColumns(row, item, i);
        this.rows.push(row);
    });
    
    return this.rows;
  }

  buildGroupedRows({
    groupsMap,            // Map<groupId, item[]>
    includeGroupId,       // (groupId) => boolean
    getGroupLabel,        // (groupId) => string
    getGroupBadges,       // optional callback to get meta info on groups
    makeRowId,            // (item) => any
    fillRow,              // (row, item) => void
    groupType  = 'na',    // flavor, location, 
    rowType    = 'na',
    rowLabel   = 'na',
    collapsible= true, 
    collapsed  = false,
    groupIdKey = "groupId"// key name stored in rowGroups entries
  }) {
    this.rows = [];
    this.rowGroups = [];
    let i = 0;
    for (const [groupId, items] of (groupsMap ?? new Map()).entries()) {
      if (includeGroupId && !includeGroupId(groupId)) continue;

      const badges = getGroupBadges ? (getGroupBadges(items, groupId) ?? []) : [];

      this.rowGroups.push({
        index       : i,
        startIndex  : this.rows.length,
        label       : getGroupLabel ? getGroupLabel(groupId) : String(groupId),
        [groupIdKey]: groupId,
        groupType,
        rowType,
        rowLabel,
        badges,
        collapsible,
        collapsed
      });

      for (const item of (items ?? [])) {
        const row = { id: makeRowId ? makeRowId(item) : item?.id };
        if (fillRow) fillRow(row, item, i++);
        this.rows.push(row);
      }
    }

    return this.rows;

  }
  
  // In BaseGridModel
  getBadges(id, fieldKey = 'flavor', spec = this.fBadgeSpecs) {
    // Gracefully handle missing domain
    if (!this.flavorMeta) return [];
    
    if (fieldKey === 'flavor' || fieldKey.includes('_flavor')) {
        return this.flavorMeta.badges(id, spec);
    }
    return [];
  }
  
  getOptions(id, fieldKey) {
    if (!fieldKey || fieldKey === 'id') return [];
    
    // Gracefully handle missing domain
    if (!this.domain) return [];
    
    // State options are NOT defined here. Single source of truth: the Pods
    // admin `tub.state` field (pick / custom-simple). The server reads that
    // field's value list and ships it as the column's `options` in
    // SCOOP.metaData (see scoop_field_enum_options() / scoop_client_metadata()
    // in includes/enqueue.php). To add/rename/remove a state, edit the Pods
    // field — not this file. Example values (first 3): __override__, Hardening,
    // Freezing. If the dropdown is empty, the Pods field or the metadata feed
    // is where to look.
    if (fieldKey === 'state') {
      return (this.columns ?? this._allColumns ?? [])
        .find(c => c.key === 'state')?.options ?? [];
    }
    
    if (fieldKey === 'location') {
        return [...(this.domain.location || [])]
            .map(u => ({
                key: u.id,
                label: u._title || u.title?.rendered || ''
            }));
    }
    
    if (fieldKey === 'use') {
        return [...(this.domain.use || [])]
            .sort((a, b) => (a.order ?? 0) - (b.order ?? 0))
            .map(u => ({
                key: u.id,
                label: u._title || u.title?.rendered || ''
            }));
    }
    
    if (fieldKey === 'flavor' || fieldKey === 'current_flavor' || 
        fieldKey === 'immediate_flavor' || fieldKey === 'next_flavor') {
        return this.flavorMeta?.optionsAll || [];  // ← Safe access
    }
    
    return [];
  }
  
  getAlertCase(id, fieldKey = 'flavor') {
    if (!this.flavorMeta) return '';  // ← Safe access
    
    if (fieldKey === 'flavor' || fieldKey.includes('_flavor')) {
        return this.flavorMeta.alertCase(id);
    }
    return '';
  }

  getTitleMap(name) {
    this._titleMaps ??= new Map();
    
    // Return cached if available
    if (this._titleMaps.has(name)) {
        return this._titleMaps.get(name);
    }

    // Defensive: return empty map if no domain
    if (!this.domain) {
        console.warn(`getTitleMap("${name}") called without domain - returning empty Map`);
        const emptyMap = new Map();
        this._titleMaps.set(name, emptyMap);
        return emptyMap;
    }

    // Build map from domain
    const list = this.domain[name];
    if (!Array.isArray(list)) {
        console.warn(`getTitleMap("${name}") - domain.${name} is not an array`);
        const emptyMap = new Map();
        this._titleMaps.set(name, emptyMap);
        return emptyMap;
    }
    
    const map = Indexer.byId(list);
    this._titleMaps.set(name, map);
    return map;
  }

  titleFrom(id, col) {
    if (!col?.titleMap) return id;
    
    const map = this.getTitleMap(col.titleMap);
    const title = map.get(Number(id))?._title;
    
    // Return title if found, otherwise return the ID as a string
    return title ?? String(id);
  }
  
  fillRowFromColumns(row, rowData, i, dateFormat = this.dateFormat) {
    for (const col of this.columns) {
      const key = col?.key;
      if (!key) continue;

      const raw = rowData?.[key];
      // Multi-value ('ids') fields carrying titleMap (added so Details.js can
      // resolve them — see scoop_entity_relations()) are NOT a single foreign
      // key; Number(array) => NaN and titleFrom() would show "NaN". Only
      // apply the single-id titleMap lookup for scalar values.
      const isMulti = Array.isArray(raw);
      const id = isMulti ? 0 : Number(raw ?? 0);

      let display = (col.titleMap && !isMulti)
        ? this.titleFrom(id, col)
        : raw ?? "";

      // col.type is the hand-authored-model convention, col.dataType is what
      // server-driven metadata columns carry (see _list.js's
      // _renderFieldValue for the same split) — check both, or a
      // server-metadata datetime column (e.g. FlavorTub's post_modified)
      // never hits this branch at all and shows the raw ISO string instead.
      if ((col.type ?? col.dataType) === 'datetime') {
        // Server sends ISO-8601 with an explicit offset for real
        // 'datetime'-typed fields (scoop_datetime_out / mysql2date('c', ...)
        // anchored to wp_timezone()), but normalize a bare
        // "YYYY-MM-DD HH:MM:SS" too — Date only parses that reliably with a
        // 'T' — so this never silently misreads the moment as local-only.
        const date = new Date(String(raw).replace(' ', 'T'));
        display = Number.isFinite(date.getTime())
          ? (this.relativeTimeFields?.includes(col.key)
              ? this._formatTimeAgo(date)
              : date.toLocaleDateString('en-US', this.dateFormat))
          : '';
      }

      row[key] = {
        id,
        rowId:     rowData?.id || i,
        display:   display,
        type:      col.type,
        colKey:    col.key,
        options:   this.getOptions  (id, col.key),   // ← Changed from col.type
        badges:    this.getBadges   (id, col.key),   // ← Changed from col.type
        alertCase: this.getAlertCase(id, col.key),   // ← Changed from col.key
        value:     col.value,
        step:      col.step,
        min:       col.min,
        max:       col.max,
        title:     col.title,
        hidden:    col.hidden,
        write:     col.write ?? false
      };
    }
  }

  // Opt-in per model via this.relativeTimeFields = ['post_modified', ...] —
  // a list of datetime column keys that should render as "2 hours ago" /
  // "yesterday" / "3 days ago" instead of a plain locale date. Coarsens in
  // widening steps past a week (see TIME_AGO_DAY_TIERS) — this is meant to
  // answer "how fresh is this" at a glance, not serve as a precise audit
  // trail; the exact timestamp is still in the underlying data for anything
  // that needs it (e.g. Details).
  _formatTimeAgo(date) {
    const now = Date.now();
    const diffMs = now - date.getTime(); // both sides are absolute epoch ms — timezone-agnostic
    if (diffMs < 0) return 'just now'; // clock skew — a future timestamp shouldn't read as nonsense

    const MINUTE = 60 * 1000, HOUR = 60 * MINUTE, DAY = 24 * HOUR;

    if (diffMs < MINUTE) return 'just now';
    if (diffMs < HOUR) {
      const m = Math.floor(diffMs / MINUTE);
      return `${m} minute${m === 1 ? '' : 's'} ago`;
    }
    if (diffMs < DAY) {
      const h = Math.floor(diffMs / HOUR);
      return `${h} hour${h === 1 ? '' : 's'} ago`;
    }

    // Whole days elapsed, straight off the same absolute-ms diff used above —
    // no local-calendar reconstruction (getFullYear/getMonth/getDate), which
    // runs in the browser's timezone and could disagree with the hour bucket
    // above about whether a day had actually passed.
    const dayDiff = Math.floor(diffMs / DAY);

    if (dayDiff <= 1) return 'yesterday';

    for (const tier of TIME_AGO_DAY_TIERS) {
      if (dayDiff < tier.under) return tier.label(dayDiff);
    }
    return `${dayDiff} days old`;
  }

  //invalidate() { this._cache = null; this._inflight = null; }
}

