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

export default class BaseGridModel {
  
  // BaseGridModel - Split _build() into two phases
  constructor(name, domain = null, options = {}) {
      this.name = name;
      this.domain = null;  // Always start null
      this.metaData = options?.metaData ?? null;
      this.location = options?.location ?? 935;

      this.showList = [];
      this.columns = [];
      this.rows = [];
      this.rowGroups = [];
      
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
    
    if (fieldKey === 'state') return [
        {key:'__override__',   label:'__override__'},
        {key:'Hardening',      label:'Hardening'},
        {key:'Freezing',       label:'Freezing'},
        {key:'Tempering',      label:'Tempering'},
        {key:'Opened',         label:'Opened'},
        {key:'Emptied',        label:'Emptied'}
    ];
    
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
  
  fillRowFromColumns(row, rowData, i) {
    for (const col of this.columns) {
      const key = col?.key;
      if (!key) continue;

      const raw = rowData?.[key];
      const id = Number(raw ?? 0);
      
      row[key] = {
        id,
        rowId:     rowData?.id || i,
        display:   (col.titleMap) ? this.titleFrom(id, col) : raw ?? "",
        type:      col.type,
        colKey:    col.key,
        options:   this.getOptions  (id, col.key),   // ← Changed from col.type
        badges:    this.getBadges   (id, col.key),   // ← Changed from col.type
        alertCase: this.getAlertCase(id, col.key),   // ← Changed from col.key
        value:     col.value,
        hidden:    col.hidden,
        write:     col.write ?? false
      };
    }
  }

  //invalidate() { this._cache = null; this._inflight = null; }
}

