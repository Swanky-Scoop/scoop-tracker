///////////////////////////////////
// CabinetWorkflow — row-per-slot model backing the physical "change tub"
// workflow (see change-tub.md). Unlike every other grid/tile type, this one
// has no server-driven metaData: there's no _config.php route entry for it,
// so BaseGridModel's constructor never calls buildCols() (it's gated on
// `if (this.metaData)`) and this.columns stays the base class's initial [].
// That's intentional, not an oversight — CabinetWorkflowTile (../ui/
// cabinet-workflow-tile.js) builds each slot's markup straight from the row
// object in buildItemDom(), so there are no per-column cells to describe.
//////////////////////////////////
import BaseGridModel from "./_base-grid-model.js";

const FRONT_OF_HOUSE_USE_ID = 1863;
const ELIGIBLE_TUB_STATE = 'Freezing';
const OPEN_TUB_STATE = 'Opened';

export default class CabinetWorkflowGridModel extends BaseGridModel {
  constructor(name = 'CabinetWorkflow', domain, attrs = {}) {
    super(name, domain, attrs);
  }

  // Every slot at the model's own location, grouped by cabinet — including
  // slots with no current_flavor. Location scoping is client-side only: the
  // bundle endpoint doesn't filter by location today (see change-tub.md),
  // so this is the one place that keeps a multi-location shop's cabinets
  // from all showing on one page.
  buildRows() {
    if (!this.domain) return [];

    const allSlots = Array.isArray(this.domain.slot) ? this.domain.slot : [];
    const slots = this.location
      ? allSlots.filter(s => Number(s.location) === Number(this.location))
      : allSlots;

    const groupsMap = new Map();
    for (const slot of slots) {
      const cabinetId = Number(slot.cabinet ?? 0) || 0;
      const list = groupsMap.get(cabinetId) ?? [];
      list.push(slot);
      groupsMap.set(cabinetId, list);
    }

    const sortedEntries = [...groupsMap.entries()].sort((a, b) => {
      const la = this._cabinetsById.get(a[0])?._title ?? '';
      const lb = this._cabinetsById.get(b[0])?._title ?? '';
      return la.localeCompare(lb);
    });

    return this.buildGroupedRows({
      groupsMap     : new Map(sortedEntries),
      includeGroupId: () => true,
      getGroupLabel : (id) => this.labelFromMap(id, this._cabinetsById) ?? `Cabinet ${id}`,
      makeRowId     : (slot) => slot.id,
      fillRow       : (row, slot) => this._fillSlotRow(row, slot),
      groupType     : 'cabinet',
      rowType       : 'slot',
      rowLabel      : 'slot',
      collapsible   : true,
      collapsed     : false,
    });
  }

  _fillSlotRow(row, slot) {
    row.slotId = slot.id;

    const flavorId = Number(slot.current_flavor ?? 0) || 0;
    if (!flavorId) {
      row.empty = true;
      return;
    }

    const flavor = this._flavorsById.get(flavorId) ?? null;
    row.flavorId     = flavorId;
    row.flavorTitle  = flavor?._title ?? '';
    row.flavorPhoto  = flavor?.photo ?? '';
    row.allergens    = Array.isArray(flavor?.allergens) ? flavor.allergens : [];
    row.cabinetTitle = this.labelFromMap(slot.cabinet, this._cabinetsById) ?? `Cabinet ${slot.cabinet}`;

    row.tubCountLocal = this._sumAmount(this._fohTubs(flavorId, slot.location, ELIGIBLE_TUB_STATE));
    row.tubCountTotal = this._sumAmount(this._fohTubs(flavorId, null, ELIGIBLE_TUB_STATE));
    row.canAddNext    = row.tubCountLocal > 0;

    // Data-integrity check for the "Confirm Cabinet" button (see
    // change-tub.md / CabinetWorkflowTile._confirmCabinet): a slot with a
    // flavor assigned should have exactly one Opened FOH tub of that flavor
    // at its own location. 'openTub' is that tub when there's exactly one
    // (the button links it to slot.tubs, but only if it isn't already
    // linked — see currentTubId); 'none'/'multi' just flag the LI via CSS
    // for a human to sort out — never auto-resolved.
    row.currentTubId = Number(slot.tubs?.[0] ?? 0) || 0;

    const openTubs = this._fohTubs(flavorId, slot.location, OPEN_TUB_STATE);
    row.openTubCount = openTubs.length;

    if (openTubs.length === 1) {
      row.openTubStatus = 'linked';
      row.openTub = openTubs[0];
    } else if (openTubs.length === 0) {
      row.openTubStatus = 'none';
    } else {
      row.openTubStatus = 'multi';
    }
  }

  // Front-of-house tubs of a flavor in a given state. locationId === null
  // means "all locations" (used for tub-count-total).
  _fohTubs(flavorId, locationId, state) {
    const tubs = Array.isArray(this.domain.tub) ? this.domain.tub : [];
    return tubs.filter(t =>
      Number(t.flavor) === flavorId &&
      Number(t.use) === FRONT_OF_HOUSE_USE_ID &&
      t.state === state &&
      (locationId == null || Number(t.location) === Number(locationId))
    );
  }

  // Sum of `amount` across a tub list — i.e. how much product is on hand,
  // not how many containers (a partial tub's amount < 1).
  _sumAmount(tubs) {
    return tubs.reduce((sum, t) => sum + Number(t.amount ?? 1), 0);
  }
}
