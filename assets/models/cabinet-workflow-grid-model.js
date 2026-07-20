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
const OPEN_TUB_STATE = 'Opened';

// The tub that actually gets promoted to Opened must be physically ready
// (Freezing) — Hardening/Tempering tubs aren't sitting in the display case
// yet. This is a narrower pool than the DISPLAY_EXCLUDED_STATES below on
// purpose: "remaining" (what's shown to staff) is a broader supply-visible
// number per change-tub.md's decision log; "promotable" (what add-next can
// actually select) stays physically-ready-only. Don't merge these two.
const PROMOTABLE_TUB_STATE = 'Freezing';

// Everything except these counts as "remaining" — i.e. still somewhere in
// the pipeline (Hardening/Tempering/Freezing/__override__), not yet in
// service and not dead stock. See change-tub.md's "Add next confirmation
// modal" decisions log — this also matches assets/models/_flavor.js's
// existing EXCLUDED_STATES precedent (Emptied, !Lost), plus Opened since a
// tub already in service isn't "remaining" stock.
const DISPLAY_EXCLUDED_STATES = new Set(['Opened', 'Emptied', '!Lost']);

// Matches scoop_find_whole_tubs()'s own "amount >= 0.8 counts as whole"
// threshold (includes/hooks/closeout.php) — kept in sync deliberately,
// not an independent choice.
const WHOLE_TUB_THRESHOLD = 0.8;

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
    row.slotId   = slot.id;
    row.location = slot.location;

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

    // Pre-planned alternates for this slot (see change-tub.md's confirm
    // modal decisions) — always the same two fields regardless of which
    // flavor is currently selected in the modal.
    row.immediateFlavorId    = Number(slot.immediate_flavor ?? 0) || 0;
    row.immediateFlavorTitle = row.immediateFlavorId ? (this._flavorsById.get(row.immediateFlavorId)?._title ?? '') : '';
    row.nextFlavorId         = Number(slot.next_flavor ?? 0) || 0;
    row.nextFlavorTitle      = row.nextFlavorId ? (this._flavorsById.get(row.nextFlavorId)?._title ?? '') : '';

    row.tubCountLocal = this.remainingSummary(flavorId, slot.location);
    row.tubCountTotal = this.remainingSummary(flavorId, null);
    row.canAddNext    = this._fohTubs(flavorId, slot.location, PROMOTABLE_TUB_STATE).length > 0;

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

  // Front-of-house tubs of a flavor in a given exact state. locationId ===
  // null means "all locations".
  _fohTubs(flavorId, locationId, state) {
    const tubs = Array.isArray(this.domain.tub) ? this.domain.tub : [];
    return tubs.filter(t =>
      Number(t.flavor) === flavorId &&
      Number(t.use) === FRONT_OF_HOUSE_USE_ID &&
      t.state === state &&
      (locationId == null || Number(t.location) === Number(locationId))
    );
  }

  // Same, but by exclusion rather than an exact state — used for the
  // broader "remaining" display figure (see DISPLAY_EXCLUDED_STATES above).
  _fohTubsExcluding(flavorId, locationId, excludeStates) {
    const tubs = Array.isArray(this.domain.tub) ? this.domain.tub : [];
    return tubs.filter(t =>
      Number(t.flavor) === flavorId &&
      Number(t.use) === FRONT_OF_HOUSE_USE_ID &&
      !excludeStates.has(t.state) &&
      (locationId == null || Number(t.location) === Number(locationId))
    );
  }

  // Sum of `amount` across a tub list — i.e. how much product is on hand,
  // not how many containers (a partial tub's amount < 1).
  _sumAmount(tubs) {
    return tubs.reduce((sum, t) => sum + Number(t.amount ?? 1), 0);
  }

  // ─── Public — used by CabinetWorkflowTile and the confirm-swap modal ───

  flavorInfo(flavorId) {
    const flavor = this._flavorsById.get(Number(flavorId));
    return { id: Number(flavorId), title: flavor?._title ?? '', photo: flavor?.photo ?? '' };
  }

  // flavor.allergens gives slugs (post_name), not ids — matches
  // domain.allergen rows by post_name rather than needing flavor.allergens
  // to change shape. Small fixed table, no caching needed.
  allergenIconUrl(slug) {
    const rows = Array.isArray(this.domain.allergen) ? this.domain.allergen : [];
    const norm = String(slug ?? '').toLowerCase();
    return rows.find(a => String(a.post_name ?? '').toLowerCase() === norm)?.icon ?? '';
  }

  // "N remaining" — broader-than-promotable supply figure, see
  // DISPLAY_EXCLUDED_STATES.
  remainingSummary(flavorId, locationId) {
    return this._sumAmount(this._fohTubsExcluding(Number(flavorId), locationId, DISPLAY_EXCLUDED_STATES));
  }

  promotablePool(flavorId, locationId) {
    return this._fohTubs(Number(flavorId), locationId, PROMOTABLE_TUB_STATE);
  }

  // The specific tub "add next"/the confirm modal would promote to Opened.
  // preferWhole toggles the tie-break (the modal's "use full tubs before
  // partial tubs" checkbox) — default matches change-tub.md's original
  // hardcoded rule, now a live per-confirmation choice instead of fixed.
  pickPromotableTub(flavorId, locationId, preferWhole = true) {
    const pool = this.promotablePool(flavorId, locationId);
    const byAge = (a, b) =>
      String(a.created_on ?? '').localeCompare(String(b.created_on ?? '')) ||
      (Number(a.index) || 0) - (Number(b.index) || 0);

    const whole   = pool.filter(t => Number(t.amount ?? 1) >= WHOLE_TUB_THRESHOLD).sort(byAge);
    const partial = pool.filter(t => Number(t.amount ?? 1) <  WHOLE_TUB_THRESHOLD).sort(byAge);
    const ordered = preferWhole ? [...whole, ...partial] : [...partial, ...whole];

    return ordered[0] ?? null;
  }

  // Batch size ("how many tubs this one came from") isn't its own field —
  // fetching the 'batch' entity just for this would pull the shop's entire
  // unbounded batch history into every CabinetWorkflow page load (bundle-
  // fetch.php's own comments document a prior incident of exactly that
  // shape crashing php-fpm). It's already embedded in the tub's own title
  // ("{flavor} {date}_{count}|{index}", see scoop_batch_title_for_data() in
  // includes/hooks/batch-tub.php), so pull it from there instead — the one
  // piece of this modal that's read from post_title rather than a
  // structured field, and only because the alternative is materially worse.
  tubBatchCount(tub) {
    const match = /_([\d.]+)\|\d+$/.exec(String(tub?._title ?? ''));
    return match ? match[1] : null;
  }
}
