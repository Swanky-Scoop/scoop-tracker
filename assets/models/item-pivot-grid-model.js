///////////////////////////////////
// ItemPivotGridModel — "where are the tubs" read-only pivot. Rows: flavors,
// dairy first then non-dairy, each group alphabetical (flavor.allergens,
// not a base-type field — sorbet has no base). Columns: every location,
// each split into Hardening / Freezing / Tempering / one column per serving
// cabinet actually holding a tub / Other — Other is a catch-all, always
// last, folding in Opened-with-no-slot, recently-Emptied, !Lost, and
// anything else that doesn't map to a specific holding place; square color
// (see item-pivot-grid.js) is what distinguishes those within the column,
// not separate columns. A cell is every tub sitting at that (flavor,
// bucket) intersection — see item-pivot-grid.js for the square-per-tub
// rendering.
//
// Deliberately ignores this.location (the shortcode's location= attribute,
// used by every other grid to scope to one shop) — the whole point of this
// view is comparing holding locations side by side, so it always shows every
// location the bundle has.
//
// First concrete child of PivotGridModel (see that file for the mechanics/
// hook contract) — a future second item type (e.g. a batch or ingredient
// pivot) would be a sibling child, not a change to this file.
//////////////////////////////////
import PivotGridModel from "./_pivot-grid-model.js";
import Indexer from "../data/indexer.js";
import { EMPTIED_WINDOW_OPTIONS } from "./flavor-tub-grid-model.js";

export default class ItemPivotGridModel extends PivotGridModel {
  constructor(name = 'ItemPivot', domain, attrs = {}) {
    super(name, domain, attrs);
    this.filterValues = {
      emptied_window: '4h',
      flavor_search: '',
      show_freezing: 'true',
      show_tempering: 'true',
      show_cabinets: 'true',
      show_other: 'true',
    };
  }

  getFilterDefs() {
    return [
      {
        key: 'flavor_search',
        label: 'Flavor',
        type: 'text',
        mode: 'client',
        default: '',
        placeholder: 'Search flavor…',
      },
      {
        key: 'emptied_window',
        label: 'Emptied',
        type: 'select',
        mode: 'client',
        default: '4h',
        options: EMPTIED_WINDOW_OPTIONS.map(({ key, label }) => ({ key, label })),
      },
      { key: 'show_freezing',  label: 'Freezing',  type: 'checkbox', mode: 'client', default: 'true', group: 'columns', groupLabel: 'Columns' },
      { key: 'show_tempering', label: 'Tempering', type: 'checkbox', mode: 'client', default: 'true', group: 'columns' },
      { key: 'show_cabinets',  label: 'Cabinets',  type: 'checkbox', mode: 'client', default: 'true', group: 'columns' },
      { key: 'show_other',     label: 'Other',     type: 'checkbox', mode: 'client', default: 'true', group: 'columns' },
    ];
  }

  setFilterValue(key, value) {
    if (!key) return;
    this.filterValues[key] = String(value ?? 'all');
  }

  getFilterValue(key) {
    return this.filterValues?.[key] ?? 'all';
  }

  primaryEntityKey() { return 'tub'; }

  // Also indexes slots by id (needed to resolve an Opened tub's cabinet) —
  // computed here rather than cached across calls since this runs once per
  // buildRows() pass and the base class's getColumnDefs/colKeyFor calls that
  // follow it in the same pass depend on it already being set.
  getItems() {
    this._slotsById = Indexer.byId(this.domain?.slot ?? []);

    const all = super.getItems();
    const windowHours = this._emptiedWindowHours();
    if (!windowHours) return all.filter(t => t.state !== 'Emptied');

    const cutoff = Date.now() - windowHours * 60 * 60 * 1000;
    return all.filter(t => {
      if (t.state !== 'Emptied') return true;
      const emptiedMs = this._parseServerDateMs(t.emptied_at);
      return emptiedMs !== null && emptiedMs >= cutoff;
    });
  }

  _emptiedWindowHours() {
    const opt = EMPTIED_WINDOW_OPTIONS.find(o => o.key === this.getFilterValue('emptied_window'));
    return opt?.hours ?? 0;
  }

  _parseServerDateMs(raw) {
    if (!raw) return null;
    const ts = new Date(String(raw).replace(' ', 'T')).getTime();
    return Number.isFinite(ts) ? ts : null;
  }

  // Dairy flavors first, then non-dairy, alphabetical within each.
  getRowDefs(items) {
    const flavorIds = new Set(items.map(t => Number(t.flavor)).filter(Boolean));
    let flavors = [...flavorIds]
      .map(id => this._flavorsById.get(id))
      .filter(Boolean);

    const search = String(this.getFilterValue('flavor_search') ?? '').trim().toLowerCase();
    if (search) {
      flavors = flavors.filter(f => String(f._title ?? '').toLowerCase().includes(search));
    }

    const byTitle = (a, b) => (a._title || '').localeCompare(b._title || '');
    const isDairy = (f) => (f.allergens ?? []).includes('dairy');

    const dairy = flavors.filter(isDairy).sort(byTitle);
    const nonDairy = flavors.filter(f => !isDairy(f)).sort(byTitle);

    // detailEntity/id (see _pivot-grid-model.js's header comment) make the
    // row label a Details link to that flavor — key stays the prefixed
    // bucket id, id is the flavor's own bare post id.
    const pushFlavor = (f, groupKey, groupLabel) =>
      ({ key: `flavor_${f.id}`, label: f._title, groupKey, groupLabel, detailEntity: 'flavor', id: f.id });

    return [
      ...dairy.map(f => pushFlavor(f, 'dairy', 'Dairy')),
      ...nonDairy.map(f => pushFlavor(f, 'non-dairy', 'Non-Dairy')),
    ];
  }

  rowKeyFor(tub) {
    return `flavor_${Number(tub.flavor)}`;
  }

  getColumnDefs(items) {
    const has = this._syncGroupCheckboxes(items);

    const locations = Array.isArray(this.domain?.location) ? this.domain.location : [];
    const cabinetsUsed = this._cabinetsUsed(items);
    const defs = [];

    for (const loc of locations) {
      const locId = loc.id;
      const locLabel = loc._title || `Location ${locId}`;
      // forceShow bypasses PivotGridModel's own "hide if nothing landed
      // here" check — needed on top of the checkbox itself being 'true',
      // since a user manually re-checking an empty group (see
      // _syncGroupCheckboxes) means "show it even though it's still empty."
      // groupKey/groupLabel deliberately match rowDef's own field names
      // (see _pivot-grid-model.js) — same shape on both axes, so a future
      // axis flip is just swapping which hook returns which, not a data
      // reshape. item-pivot-grid.js reads them to build the second (group)
      // header row — see isLocationCollapsed.
      // No location prefix on the label — the location header row above
      // (see item-pivot-grid.js) already says it once per span.
      const push = (suffix, label, forceShow = false) =>
        defs.push({
          key: `${locId}|${suffix}`,
          label,
          groupKey: String(locId),
          groupLabel: locLabel,
          forceShow,
        });

      // Collapsed via the location header's own toggle (see
      // item-pivot-grid.js) — every bucket for this location folds into one
      // placeholder column instead of being listed individually.
      if (this.isLocationCollapsed(locId)) {
        defs.push({
          key: `${locId}|__collapsed__`,
          label: '',
          groupKey: String(locId),
          groupLabel: locLabel,
          collapsedPlaceholder: true,
        });
        continue;
      }

      // Each checkbox filter only hides the column(s) from view — colKeyFor
      // still buckets tubs into them regardless, so toggling one off never
      // loses data, just stops rendering that bucket.
      if (this.getFilterValue('show_freezing') === 'true') {
        push('Hardening', 'Hardening', !has.freezing);
        push('Freezing', 'Freezing', !has.freezing);
      }

      if (this.getFilterValue('show_tempering') === 'true') {
        push('Tempering', 'Tempering', !has.tempering);
      }

      if (this.getFilterValue('show_cabinets') === 'true') {
        const cabinets = [...(cabinetsUsed.get(locId) ?? new Map())]
          .sort((a, b) => a[1].localeCompare(b[1]));

        if (cabinets.length) {
          for (const [cabinetId, title] of cabinets) push(`cabinet-${cabinetId}`, title);
        } else if (!has.cabinets) {
          // Nothing is sitting in any cabinet anywhere right now, so there's
          // no "used" cabinet to force-show (cabinet columns normally only
          // exist for cabinets currently holding a tub) — fall back to
          // every cabinet at this location so the checkbox has something
          // concrete to reveal.
          this._allCabinetsAt(locId).forEach(([cabinetId, title]) => push(`cabinet-${cabinetId}`, title, true));
        }
      }

      // Always last (pushed after every cabinet, regardless of how many
      // there are) — catch-all for Opened-no-slot, Emptied, !Lost, anything
      // unrecognized. See colKeyFor.
      if (this.getFilterValue('show_other') === 'true') push('Other', 'Other', !has.other);
    }

    return defs;
  }

  colKeyFor(tub) {
    const locId = tub.location;

    if (this.isLocationCollapsed(locId)) return `${locId}|__collapsed__`;

    return `${locId}|${this._bucketSuffixFor(tub)}`;
  }

  // The state/slot -> bucket classification, collapse-state-agnostic on
  // purpose — colKeyFor layers the collapse check on top of this, but
  // _groupHasData (the show_* checkbox auto-sync) needs the tub's REAL
  // bucket regardless of whether its location happens to be collapsed right
  // now, or collapsing a location would misreport every tub in it as
  // "Other" for checkbox-sync purposes. See getColumnDefs for 'Other'.
  _bucketSuffixFor(tub) {
    const state = tub.state;

    if (state === 'Hardening' || state === 'Freezing' || state === 'Tempering') return state;

    if (state === 'Opened' && tub.slot) {
      const slot = this._slotsById.get(Number(tub.slot));
      const cabinetId = Number(slot?.cabinet ?? 0);
      if (cabinetId) return `cabinet-${cabinetId}`;
    }

    // Opened-no-slot, Emptied, !Lost, and anything else all fold into the
    // one catch-all column — item-pivot-grid.js's square color is what
    // tells these apart, not separate columns.
    return 'Other';
  }

  // Location-collapse state lives in filterValues under an ad-hoc key (not
  // a getFilterDefs() entry — locations are dynamic, unlike the four fixed
  // show_* checkboxes, so there's nothing to statically declare). Toggled
  // by item-pivot-grid.js's location header row, not the generic filter
  // controls in _list.js.
  isLocationCollapsed(locId) {
    return this.getFilterValue(`loc_collapsed_${locId}`) === 'true';
  }

  toggleLocationCollapse(locId) {
    this.setFilterValue(`loc_collapsed_${locId}`, !this.isLocationCollapsed(locId));
  }

  // Keeps each show_* checkbox synced to whether its group currently has any
  // items — but only in the two directions actually wanted:
  //   - first render ever: default to whatever the data says (empty -> unchecked)
  //   - a group that HAD items and just lost them all (e.g. a flavor search
  //     narrowed it out): auto-uncheck, so the now-empty column hides itself
  // A group that's already empty is left alone once auto-unchecked — that's
  // what lets the checkbox be manually turned back on to force an empty
  // column into view, without this immediately re-hiding it on the next
  // rebuild. Data reappearing does NOT auto-recheck — same reasoning, the
  // checkbox is the one control for both directions from that point on.
  _syncGroupCheckboxes(items) {
    const has = this._groupHasData(items);
    this._lastGroupHasData ??= {};

    for (const group of ['freezing', 'tempering', 'cabinets', 'other']) {
      const key = `show_${group}`;
      const hasNow = has[group];
      const hadBefore = this._lastGroupHasData[group];

      if (hadBefore === undefined) {
        this.filterValues[key] = hasNow ? 'true' : 'false';
      } else if (hadBefore === true && hasNow === false) {
        this.filterValues[key] = 'false';
      }

      this._lastGroupHasData[group] = hasNow;
    }

    return has;
  }

  // Which of the four checkbox groups currently have at least one item —
  // classified via _bucketSuffixFor (not colKeyFor — see its comment) so
  // this can never drift out of sync with what actually gets bucketed, and
  // isn't skewed by an unrelated location happening to be collapsed right
  // now. Aggregated across every location: there's one checkbox per group,
  // not one per location.
  _groupHasData(items) {
    const has = { freezing: false, tempering: false, cabinets: false, other: false };

    for (const t of items) {
      const suffix = this._bucketSuffixFor(t);

      if (suffix === 'Hardening' || suffix === 'Freezing') has.freezing = true;
      else if (suffix === 'Tempering') has.tempering = true;
      else if (suffix.startsWith('cabinet-')) has.cabinets = true;
      else has.other = true;

      if (has.freezing && has.tempering && has.cabinets && has.other) break;
    }

    return has;
  }

  // locationId -> Map<cabinetId, cabinetTitle>, built from Opened tubs whose
  // slot resolves to a cabinet — this is what decides which cabinet columns
  // even exist (an empty cabinet with nothing currently in it gets no column).
  _cabinetsUsed(items) {
    const map = new Map();

    for (const t of items) {
      if (t.state !== 'Opened' || !t.slot) continue;
      const slot = this._slotsById.get(Number(t.slot));
      const cabinetId = Number(slot?.cabinet ?? 0);
      if (!cabinetId) continue;

      const locId = t.location;
      if (!map.has(locId)) map.set(locId, new Map());
      if (!map.get(locId).has(cabinetId)) {
        map.get(locId).set(cabinetId, this._cabinetLabel(cabinetId));
      }
    }

    return map;
  }

  // Cabinet post_titles use '_' as a word separator (see pods-schema) — swap
  // for spaces so the header can actually wrap instead of forcing the
  // column wide. Trailing index number (e.g. "... Cabinet 1") is dropped
  // too — not meaningful here, every cabinet already gets its own column.
  _cabinetLabel(cabinetId) {
    const rawTitle = this._cabinetsById.get(cabinetId)?._title || `Cabinet ${cabinetId}`;
    return rawTitle.replace(/_/g, ' ').replace(/\s*\d+$/, '');
  }

  // Every cabinet Pods knows about at a location, regardless of whether it
  // currently holds a tub — the fallback for force-showing the 'Cabinets'
  // group when NO cabinet anywhere has one (see getColumnDefs): there's no
  // "used" cabinet to point at in that case, so this is the full set instead.
  _allCabinetsAt(locId) {
    return (this.domain?.cabinet ?? [])
      .filter(c => Number(c.location) === Number(locId))
      .map(c => [Number(c.id), this._cabinetLabel(Number(c.id))])
      .sort((a, b) => a[1].localeCompare(b[1]));
  }
}
