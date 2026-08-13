///////////////////////////////////
// ShiftReportGridModel — domain access + static option lists for the
// end-of-shift report form (see assets/ui/shift-report-form.js and
// WHITEBOARD-INGESTION.md). Not a spreadsheet grid: no columns, no rows in
// the BaseGridModel sense (same "ships no columns" precedent as
// CabinetWorkflowGridModel — see change-tub.md) — ShiftReportForm builds its
// own DOM directly off this model's domain/option-list accessors.
//
// Field names/option values here match the actual Pods admin config for
// shift_report, not an earlier draft — see WHITEBOARD-INGESTION.md for the
// design history (supplies_low moved from a hardcoded string checklist to a
// real relationship once the 'supply' content type was built;
// flavors_changed is a direct multi-relationship, not an old/new pair;
// cake_orders means creating new cake_order records inline, not picking
// from existing ones).
//////////////////////////////////
import BaseGridModel from "./_base-grid-model.js";

// Pods "Simple (custom defined list)" option values for change_low —
// multiple selection. Spelling ("Nickles") matches the Pods field exactly.
export const CHANGE_LOW_OPTIONS = [
  "Pennies", "Nickles", "Dimes", "Quarters", "Dollars", "Fives", "Tens", "Twenties",
];

// staffing_level — single selection.
export const STAFFING_LEVEL_OPTIONS = ["Too many", "Just right", "Too few"];

// supply.group's checklist display order — matches the original design pass
// (WHITEBOARD-INGESTION.md), front-of-house-frequent items first, facilities/
// misc last, rather than alphabetical (which would scatter e.g. "Cups" and
// "Lids" apart from each other for no reason).
export const SUPPLY_GROUP_ORDER = [
  "Cups", "Lids", "Gloves", "Paper goods", "Bags & to-go",
  "Cleaning & bathroom", "Spoons & straws", "Dairy/milk", "Cones",
  "Cookies/frozen dough", "Toppings & sauces", "Beverages", "Misc/facilities",
];

export default class ShiftReportGridModel extends BaseGridModel {
  constructor(name = "ShiftReport", domain, attrs = {}) {
    super(name, domain, attrs);
    // Single-submission form, not a persisted-row view — nothing here
    // should repaint out from under someone mid-fill because some other
    // grid on the page saved something (same reasoning as Closeout's
    // repaintOnRefresh — see closeout-grid-model.js).
    this.repaintOnRefresh = false;
  }

  // BaseGridModel ships no columns for this model (no _config.php entry —
  // see this model's own bundle spec comment in includes/_specs.php), so
  // buildCols()/buildRows() are never called by the constructor. Kept as
  // no-ops rather than left undefined, in case something ever calls them
  // defensively.
  buildCols() { return (this.columns = []); }
  buildRows() { return (this.rows = []); }

  // flavors_changed's actual source list: today's slot.current_flavor,
  // filtered to the given location and grouped by cabinet — the set of
  // flavors worth asking "is this one of today's new arrivals?" about,
  // rather than every flavor in the catalog (including ones never in
  // rotation). Empty slots (no current_flavor) are skipped. Same
  // cabinet->slot->flavor grouping CabinetWorkflow uses (see
  // cabinet-workflow-grid-model.js), reused here for a much simpler
  // read-only purpose — no eligibility/allergen/tub logic, just "what's
  // showing right now."
  currentFlavorsByCabinet(locationId) {
    const slots = Array.isArray(this.domain?.slot) ? this.domain.slot : [];
    const cabinetsById = new Map((this.domain?.cabinet ?? []).map(c => [Number(c.id), c]));
    const flavorsById = new Map((this.domain?.flavor ?? []).map(f => [Number(f.id), f]));

    const groups = new Map(); // cabinetId -> { cabinetTitle, flavors: Map<flavorId, title> }

    for (const slot of slots) {
      if (Number(slot.location) !== Number(locationId)) continue;
      const flavorId = Number(slot.current_flavor || 0);
      if (!flavorId) continue;

      const cabinetId = Number(slot.cabinet || 0);
      if (!groups.has(cabinetId)) {
        const cabinet = cabinetsById.get(cabinetId);
        groups.set(cabinetId, { cabinetId, cabinetTitle: cabinet?._title || `Cabinet ${cabinetId}`, flavors: new Map() });
      }

      const flavor = flavorsById.get(flavorId);
      groups.get(cabinetId).flavors.set(flavorId, flavor?._title || `Flavor ${flavorId}`);
    }

    return [...groups.values()]
      .map(g => ({
        cabinetId: g.cabinetId,
        cabinetTitle: g.cabinetTitle,
        flavors: [...g.flavors.entries()]
          .map(([id, title]) => ({ id, title }))
          .sort((a, b) => a.title.localeCompare(b.title)),
      }))
      .sort((a, b) => a.cabinetTitle.localeCompare(b.cabinetTitle));
  }

  // supplies_low's source list, grouped by the supply pod's own 'group'
  // field (see WHITEBOARD-INGESTION.md — 83 items populated on local).
  // Ordered per SUPPLY_GROUP_ORDER rather than alphabetically; a group not
  // in that list (added in Pods admin later) sorts after all known ones
  // instead of being dropped.
  supplyOptionsByGroup() {
    const supplies = Array.isArray(this.domain?.supply) ? this.domain.supply : [];
    const groups = new Map(); // groupName -> Map<id, title>

    for (const s of supplies) {
      const groupName = s.group || 'Other';
      if (!groups.has(groupName)) groups.set(groupName, new Map());
      groups.get(groupName).set(s.id, s._title || `Supply ${s.id}`);
    }

    const rank = name => {
      const i = SUPPLY_GROUP_ORDER.indexOf(name);
      return i === -1 ? SUPPLY_GROUP_ORDER.length : i;
    };

    return [...groups.entries()]
      .map(([groupName, itemMap]) => ({
        group: groupName,
        supplies: [...itemMap.entries()]
          .map(([id, title]) => ({ id, title }))
          .sort((a, b) => a.title.localeCompare(b.title)),
      }))
      .sort((a, b) => rank(a.group) - rank(b.group) || a.group.localeCompare(b.group));
  }

  locationOptions() {
    const locations = Array.isArray(this.domain?.location) ? this.domain.location : [];
    return locations.map(l => ({ id: l.id, title: l._title || `Location ${l.id}` }));
  }
}
