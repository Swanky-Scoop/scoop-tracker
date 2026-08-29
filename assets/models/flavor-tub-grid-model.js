import BaseGridModel from "./_base-grid-model.js";
import Indexer       from "../data/indexer.js";

const DESIGNATION_FIELDS = [
  { field: "current_flavor", label: "current" },
  { field: "immediate_flavor", label: "immediate" },
  { field: "next_flavor", label: "next" },
];

const FRONT_OF_HOUSE_USE_ID = 1863;

// Server-side ceiling: how long after emptied_at a tub is even fetched, and
// still correctable — see SCOOP_TUB_EMPTIED_REVERT_HOURS in
// includes/hooks/tub-state.php (governs whether a revert is accepted) and
// the tub SQL WHERE clause in includes/bundle-fetch.php (governs whether
// the bundle includes it at all). Must match. The 'emptied_window' filter
// below only narrows DISPLAY within whatever's already been fetched, so
// none of its options can exceed this.
export const RECENTLY_EMPTIED_CEILING_HOURS = 96;

// GUI-selectable look-back windows for the 'emptied_window' filter, entirely
// client-side (no refetch on change — everything within the ceiling above
// is already in the bundle). Spaced so a roughly-daily session that shifts
// by up to ~4h can still be bracketed from either side. Filtered against the
// ceiling rather than hardcoded up to it, so lowering the ceiling can't
// silently leave a now-invalid option in the dropdown. Defaults to '4h', not
// 'off' — recently-emptied tubs are common/expected enough to show up front
// rather than requiring an opt-in click every time.
export const EMPTIED_WINDOW_OPTIONS = [
  { key: 'off', label: 'Hide' },
  ...[4, 8, 12, 24, 36, 48, 72, 96]
    .filter(hours => hours <= RECENTLY_EMPTIED_CEILING_HOURS)
    .map(hours => ({ key: `${hours}h`, label: `${hours} hrs`, hours })),
];

export default class FlavorTubGridModel extends BaseGridModel{
  constructor(name = 'FlavorTub', domain, attrs = {})
  {
    super(name, domain, attrs );
    // History: partial autosave ('use'/'amount' autosaved, 'state'
    // manual-only) → all-manual (mixing the two meant a filter change, or
    // any other full-domain refresh, silently discarded whatever was still
    // pending on the manual side while the autosaved side had already
    // landed) → full autosave, every writeable field including 'state'.
    // Autosave needs to be all-or-nothing per grid either way.
    //
    // Back to all-manual: full autosave raced a background domain refresh
    // against active typing — _commitPosted cleared a field's dirty flag as
    // soon as its own autosave POST resolved, even if the user had typed
    // more into that same field while the POST was in flight, so the next
    // refresh rebuilt the DOM from the (now stale) committed value and
    // discarded those newer keystrokes. That race is fixed in _list.js, but
    // this grid moves back to manual Save regardless while that fix bakes.
    this.autosave = false;
    this.filter = true;
    this.filterValues = {
      designation: 'all',
      allergens: 'all',
      use_badge: 'all',
      emptied_window: '4h',
    };
    // Row-level `state-<value>`/`use-<value>` classes (e.g. "state-Opened",
    // "use-Front_of_House" — _slug() doesn't lowercase) — see
    // List._rowClasses. Default 'display' mode: for 'state' (a plain enum
    // column) that's the raw value itself; for 'use' (a relation/titleMap
    // column) that's the resolved label, which is what was actually asked
    // for here, not the underlying use post id.
    this.rowClassFields = ['state', 'use'];
    // "Updated" (post_modified) reads as "2 hours ago"/"yesterday"/etc.
    // instead of a plain date — see BaseGridModel._formatTimeAgo. Now that
    // this grid is full autosave, post_modified changes on nearly every
    // edit, so freshness-at-a-glance is more useful here than a calendar date.
    this.relativeTimeFields = ['post_modified'];
    this.setShowList(['index', 'state', 'use', 'amount', 'location', 'editor_name', 'post_modified']);
  }

  getFilterDefs() {
    return [
      {
        key: 'designation',
        label: 'Designation',
        type: 'select',
        mode: 'client',
        default: 'all',
        options: [
          { key: 'all', label: 'All' },
          { key: 'none', label: 'None' },
          { key: 'current', label: 'Current' },
          { key: 'immediate', label: 'Immediate' },
          { key: 'next', label: 'Next' },
        ],
      },
      {
        key: 'allergens',
        label: 'Allergens',
        type: 'select',
        mode: 'client',
        default: 'all',
        options: [
          { key: 'all', label: 'Any' },
          { key: 'dairy', label: 'Dairy' },
          { key: 'non-dairy', label: 'Non-Dairy' },
        ],
      },
      {
        key: 'use_badge',
        label: 'Use',
        type: 'select',
        mode: 'client',
        default: 'all',
        options: this._useFilterOptions(),
      },
      {
        key: 'emptied_window',
        label: 'Time',
        type: 'select',
        mode: 'client',
        default: '4h',
        options: EMPTIED_WINDOW_OPTIONS.map(({ key, label }) => ({ key, label })),
      },
      // See BaseGridModel's _locationFilterDef — same location-switcher as
      // Cabinet/CabinetWorkflow/EmptiedLog. Appended last rather than
      // reordered to the front, to leave this grid's existing filter layout
      // undisturbed.
      this._locationFilterDef(),
    ];
  }

  // 'location' delegates to BaseGridModel's generic implementation (updates
  // this.location + persists via HashState — see filterByLocation() in
  // _activeTubs below); every other key keeps this model's own
  // filterValues-object storage, unchanged.
  setFilterValue(key, value) {
    if (key === 'location') { super.setFilterValue(key, value); return; }
    if (!key) return;
    this.filterValues[key] = String(value ?? 'all');
  }

  getFilterValue(key) {
    if (key === 'location') return super.getFilterValue(key);
    return this.filterValues?.[key] ?? 'all';
  }

  buildRows() {
    if (!this.domain) return [];
    
    // Access the primary entity from domain
    const primary = this.metaData?.primary || 'tub';
    const items = this.domain[primary] || [];
    
    const locationTubIds = this._activeTubs(items);
    
    const tubsByFlavorId = Indexer.groupBy(locationTubIds, t => t.flavor);
    const designationsByFlavorId = this._designationsByFlavorId(this.domain.slot ?? []);
    const filteredTubsByFlavorId = this._filterGroups(tubsByFlavorId, designationsByFlavorId);
    // Show the most recently modified flavors first. groupBy preserves the
    // bundle's post_date-ascending order, which otherwise sinks fresh activity
    // to the bottom of the grid.
    const orderedTubsByFlavorId = this._sortGroupsByModifiedDesc(filteredTubsByFlavorId);

    return this.buildGroupedRows({
        groupsMap     : orderedTubsByFlavorId,
        includeGroupId: (id)   => Number(id) > 0,
        getGroupLabel : (id)   => this.labelFromMap(id, this._flavorsById),
        makeRowId     : (item) => item.id,
        getGroupBadges: (items, flavorId) => [
          ...this.getBadges(flavorId, 'flavor'),
          this._designationBadge(designationsByFlavorId.get(Number(flavorId)) ?? []),
          this._useBadge(items),
        ].filter(Boolean),
        fillRow       : (row, item, i) => {
          this.fillRowFromColumns(row, item, i);
        },
        collapsed     : true,
        groupType     :'flavor',
        rowType       :'tub',
        rowLabel      :'tub',
    });
  }

  _activeTubs(items = []) {
    const windowHours = this._emptiedWindowHours();
    const located = this.filterByLocation(items);

    if (!windowHours) return located.filter(t => t.state !== "Emptied");

    const cutoff = Date.now() - windowHours * 60 * 60 * 1000;
    return located.filter(t => {
      if (t.state !== "Emptied") return true;
      const emptiedMs = this._parseServerDateMs(t.emptied_at);
      return emptiedMs !== null && emptiedMs >= cutoff;
    });
  }

  _emptiedWindowHours() {
    const opt = EMPTIED_WINDOW_OPTIONS.find(o => o.key === this.getFilterValue('emptied_window'));
    return opt?.hours ?? 0;
  }

  // Shared by _activeTubs (emptied_at) and _sortGroupsByModifiedDesc
  // (post_modified/changed_on/post_date) — server datetimes come back as
  // "YYYY-MM-DD HH:MM:SS", which Date only parses reliably with a 'T'.
  _parseServerDateMs(raw) {
    if (!raw) return null;
    const ts = new Date(String(raw).replace(' ', 'T')).getTime();
    return Number.isFinite(ts) ? ts : null;
  }

  // Reorder the flavor groups so the flavor with the most recently modified
  // tub comes first (descending). Each group's sort key is the max
  // post_modified across its tubs.
  _sortGroupsByModifiedDesc(groupsMap) {
    const groupTime = (items = []) =>
      items.reduce((max, t) => Math.max(max, this._modifiedTime(t)), -Infinity);

    const entries = [...(groupsMap ?? new Map()).entries()]
      .sort((a, b) => groupTime(b[1]) - groupTime(a[1]));

    return new Map(entries);
  }

  _modifiedTime(tub) {
    const raw = tub?.post_modified || tub?.changed_on || tub?.post_date || '';
    return this._parseServerDateMs(raw) ?? -Infinity;
  }

  _filterGroups(groupsMap, designationsByFlavorId) {
    const out = new Map();

    for (const [flavorId, items] of groupsMap ?? new Map()) {
      const designations = designationsByFlavorId.get(Number(flavorId)) ?? [];
      if (this._groupMatchesFilters(items, designations, flavorId)) out.set(flavorId, items);
    }

    return out;
  }

  _groupMatchesFilters(items = [], designations = [], flavorId = 0) {
    const designationFilter = this.getFilterValue('designation');
    if (designationFilter !== 'all') {
      if (designationFilter === 'none') {
        if (designations.length) return false;
      } else if (!designations.includes(designationFilter)) {
        return false;
      }
    }

    const allergenFilter = this.getFilterValue('allergens');
    if (allergenFilter !== 'all') {
      const hasDairy = this._flavorAllergens(flavorId).includes('dairy');
      if (allergenFilter === 'dairy'     && !hasDairy) return false;
      if (allergenFilter === 'non-dairy' &&  hasDairy) return false;
    }

    const useFilter = this.getFilterValue('use_badge');
    if (useFilter === 'all') return true;

    const nonFrontUseIds = this._nonFrontUseIds(items);
    if (useFilter === 'any') return nonFrontUseIds.length > 0;

    const requestedUseId = Number(String(useFilter).replace(/^use:/, ''));
    return Number.isFinite(requestedUseId) && nonFrontUseIds.includes(requestedUseId);
  }

  _flavorAllergens(flavorId) {
    const flavor = this._flavorsById?.get?.(Number(flavorId));
    return Array.isArray(flavor?.allergens) ? flavor.allergens : [];
  }

  _designationsByFlavorId(slots = []) {
    const out = new Map();

    for (const { field, label } of DESIGNATION_FIELDS) {
      for (const slot of slots) {
        const flavorId = Number(slot?.[field] ?? 0);
        if (!flavorId) continue;

        const labels = out.get(flavorId) ?? [];
        if (!labels.includes(label)) labels.push(label);
        out.set(flavorId, labels);
      }
    }

    return out;
  }

  _designationBadge(designations = []) {
    const text = designations.length ? designations.join("/") : "front";
    return {
      key: designations.length ? "designation" : "designation-none",
      text,
      title: "Designation",
    };
  }

  _useBadge(items = []) {
    const labels = [];

    for (const useId of this._nonFrontUseIds(items)) {
      const label = this.titleFrom(useId, { titleMap: "use" }) || `Use ${useId}`;
      if (label && !labels.includes(label)) labels.push(label);
    }

    if (!labels.length) return null;

    return {
      key: "use",
      text: labels.join("/"),
      title: "Uses other than Front-of-House",
    };
  }

  _useFilterOptions() {
    const options = [
      { key: 'all', label: 'All uses' },
      { key: 'any', label: 'Non Front' },
    ];

    const useIds = this._nonFrontUseIds(this._activeTubs(this.domain?.tub ?? []));

    for (const useId of useIds) {
      options.push({
        key: `use:${useId}`,
        label: this.titleFrom(useId, { titleMap: "use" }) || `Use ${useId}`,
      });
    }

    return options;
  }

  _nonFrontUseIds(items = []) {
    const ids = [];

    for (const item of items ?? []) {
      const useId = Number(item?.use ?? 0);
      if (!useId || this._isFrontOfHouseUse(useId)) continue;
      if (!ids.includes(useId)) ids.push(useId);
    }

    return ids;
  }

  _isFrontOfHouseUse(useId) {
    if (Number(useId) === FRONT_OF_HOUSE_USE_ID) return true;

    const normalized = this._normalizeUseLabel(this.titleFrom(useId, { titleMap: "use" }));
    return normalized === "front of house";
  }

  _normalizeUseLabel(label = "") {
    return String(label)
      .toLowerCase()
      .replace(/&amp;/g, "&")
      .replace(/[-_]+/g, " ")
      .replace(/\s+/g, " ")
      .trim();
  }

}
