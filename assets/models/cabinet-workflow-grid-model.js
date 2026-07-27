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

// promotablePool's exclusions — deliberately NOT "state === 'Freezing'"
// (dropped per change-tub.md: any non-Emptied, non-already-claimed-Opened
// tub is a valid candidate — Hardening/Tempering/__override__ included,
// not just Freezing). 'Opened' is excluded here specifically because
// Opened tubs are openUnclaimedPool's job (below), not this pool's — an
// Opened-and-unclaimed tub should be *adopted*, never re-"promoted."
// '!Lost' is excluded too (not explicitly requested, but flagged in
// change-tub.md as an addition worth confirming) — a flagged-lost tub
// isn't physically available to assign regardless of what Pods thinks its
// state is.
const NON_PROMOTABLE_STATES = new Set(['Emptied', OPEN_TUB_STATE, '!Lost']);

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
    row.reload   = Boolean(slot.reload);
    row.cabinetId    = Number(slot.cabinet ?? 0) || 0;
    row.cabinetTitle = this.labelFromMap(row.cabinetId, this._cabinetsById) ?? `Cabinet ${row.cabinetId}`;

    // Pre-planned alternates for this slot (see change-tub.md's confirm
    // modal decisions) — read regardless of whether the slot currently has
    // a flavor, so an empty slot's "Add Flavor" can default to whatever's
    // scheduled next (see ConfirmSwapModal._defaultFlavorId).
    row.immediateFlavorId    = Number(slot.immediate_flavor ?? 0) || 0;
    row.immediateFlavorTitle = row.immediateFlavorId ? (this._flavorsById.get(row.immediateFlavorId)?._title ?? '') : '';
    row.nextFlavorId         = Number(slot.next_flavor ?? 0) || 0;
    row.nextFlavorTitle      = row.nextFlavorId ? (this._flavorsById.get(row.nextFlavorId)?._title ?? '') : '';

    const flavorId = Number(slot.current_flavor ?? 0) || 0;
    if (!flavorId) {
      row.empty       = true;
      row.flavorId    = 0;
      row.flavorTitle = '';
      row.openTub     = null;
      return;
    }

    const flavor = this._flavorsById.get(flavorId) ?? null;
    row.flavorId     = flavorId;
    row.flavorTitle  = flavor?._title ?? '';
    row.flavorPhoto  = flavor?.photo ?? '';
    row.allergens    = Array.isArray(flavor?.allergens) ? flavor.allergens : [];

    row.tubCountLocal = this.remainingSummary(flavorId, slot.location);
    row.tubCountTotal = this.remainingSummary(flavorId, null);
    row.canAddNext    = this.promotablePool(flavorId).length > 0;

    // slot.tub (renamed from slot.tubs) is a bidirectional Pods sister
    // field with tub.slot (see change-tub.md) — always read here, never
    // written from the slot side; Pods keeps it in sync from whatever
    // wrote tub.slot. 'Opened' tubs with no slot link are a valid,
    // separate state (other GUIs can open a tub unrelated to any cabinet
    // slot) — irrelevant here, we only care about *this* slot's own link.
    row.currentTubId = Number(slot.tub ?? 0) || 0;

    const linkedTub = row.currentTubId ? (this._tubsById.get(row.currentTubId) ?? null) : null;
    const linkedIsValid = !!(linkedTub && linkedTub.state === OPEN_TUB_STATE && Number(linkedTub.flavor) === flavorId);
    row.openTub = linkedIsValid ? linkedTub : null;

    // A flavor whose allergens conflict with this cabinet's
    // prohibited_allergens can never have a valid tub here, regardless of
    // stock — Confirm Cabinet needs this to know the difference between
    // "nothing in stock right now" and "this can never work in this
    // cabinet." See change-tub.md.
    row.allergenConflict = this._allergenConflict(flavorId, row.cabinetId);

    // Before falling back to promotablePool (what Confirm Cabinet searches
    // when nothing qualifies below), check for a tub that's ALREADY
    // Opened, at this slot's own location, matching this flavor,
    // and not claimed by a different slot — i.e. one that looks like it's
    // already physically sitting here, just never got formally linked
    // (opened before this feature existed, or via some other workflow).
    // Unlike the Freezing-pool search, this check IS location-scoped: an
    // already-open tub is presumably already physically somewhere, so
    // "recognizing" it as this slot's should only happen for its own
    // location, not adopted from afar.
    const unclaimedOpen = row.openTub ? [] : this.openUnclaimedPool(flavorId, slot.location, row.slotId);
    row.discrepancy = unclaimedOpen.length > 1;

    // 'impossible' covers both "no eligible tub exists anywhere, open or
    // fresh" and "this flavor structurally can't go in this cabinet"
    // (allergen conflict) — Confirm Cabinet never auto-empties/reassigns
    // to fix either, just flags them for a human. A single unclaimed open
    // match, or a discrepancy (multiple — still resolved, just flagged),
    // both count as "not impossible": Confirm Cabinet will adopt/pair one.
    row.impossible = !row.openTub
      && unclaimedOpen.length === 0
      && (row.allergenConflict || this.promotablePool(flavorId).length === 0);
  }

  // Tubs already Opened, of this flavor, at this location, that aren't
  // claimed by a *different* slot (tub.slot is 0, or already thisSlotId).
  // excludeIds lets one reconciliation pass avoid handing the same tub to
  // two different slots (see CabinetWorkflowTile._reconcileCabinet).
  openUnclaimedPool(flavorId, locationId, thisSlotId, excludeIds = null) {
    const tubs = Array.isArray(this.domain.tub) ? this.domain.tub : [];
    return tubs.filter(t =>
      Number(t.flavor) === Number(flavorId) &&
      Number(t.use) === FRONT_OF_HOUSE_USE_ID &&
      t.state === OPEN_TUB_STATE &&
      Number(t.location) === Number(locationId) &&
      (Number(t.slot ?? 0) === 0 || Number(t.slot) === Number(thisSlotId)) &&
      (!excludeIds || !excludeIds.has(Number(t.id)))
    );
  }

  // Discrepancy tie-break: closest to a whole tub (amount nearest 1), not
  // oldest — this is picking among tubs that are already in service
  // somewhere, not choosing which fresh one to open, so "most like a
  // normal single tub" is the more meaningful signal than age.
  pickClosestToOne(tubs) {
    return tubs.slice().sort((a, b) =>
      Math.abs(Number(a.amount ?? 1) - 1) - Math.abs(Number(b.amount ?? 1) - 1)
    )[0] ?? null;
  }

  // Front-of-house tubs of a flavor, excluding a given set of states —
  // used for the broader "remaining" display figure (see
  // DISPLAY_EXCLUDED_STATES above). locationId === null means "all
  // locations" (used for tub-count-total and the modal's cross-location
  // visibility into other locations' stock).
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

  // Prohibited-allergen slugs for a cabinet, resolved from
  // cabinet.prohibited_allergens (ids) against domain.allergen's post_name
  // — matches flavor.allergens' own representation (slugs), since the two
  // fields don't share a data shape otherwise.
  _prohibitedAllergenSlugs(cabinetId) {
    const cabinet = this._cabinetsById.get(Number(cabinetId));
    const ids = Array.isArray(cabinet?.prohibited_allergens) ? cabinet.prohibited_allergens : [];
    if (!ids.length) return new Set();

    const allergens = Array.isArray(this.domain.allergen) ? this.domain.allergen : [];
    const slugs = new Set();
    for (const id of ids) {
      const match = allergens.find(a => Number(a.id) === Number(id));
      if (match?.post_name) slugs.add(String(match.post_name).toLowerCase());
    }
    return slugs;
  }

  _allergenConflict(flavorId, cabinetId) {
    const flavor = this._flavorsById.get(Number(flavorId));
    const flavorAllergens = Array.isArray(flavor?.allergens) ? flavor.allergens : [];
    if (!flavorAllergens.length) return false;

    const prohibited = this._prohibitedAllergenSlugs(cabinetId);
    if (!prohibited.size) return false;

    return flavorAllergens.some(a => prohibited.has(String(a).toLowerCase()));
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
  // DISPLAY_EXCLUDED_STATES. Location-scoped (unlike promotablePool below)
  // because this is purely informational — "how much is here vs.
  // elsewhere" — not an eligibility gate.
  remainingSummary(flavorId, locationId) {
    return this._sumAmount(this._fohTubsExcluding(Number(flavorId), locationId, DISPLAY_EXCLUDED_STATES));
  }

  // Deliberately NOT location-scoped: a tub of the right flavor can be
  // physically carried between this shop's own locations (see
  // change-tub.md's Add Flavor / Confirm Cabinet decisions) — whichever
  // action assigns one is responsible for correcting tub.location to match
  // the destination cabinet, not for excluding candidates elsewhere.
  // excludeIds (a Set of tub ids) lets a single reconciliation pass claim
  // tubs one slot at a time without two slots racing for the same one —
  // see CabinetWorkflowTile's Confirm Cabinet rewrite.
  promotablePool(flavorId, excludeIds = null) {
    const tubs = Array.isArray(this.domain.tub) ? this.domain.tub : [];
    return tubs.filter(t =>
      Number(t.flavor) === Number(flavorId) &&
      Number(t.use) === FRONT_OF_HOUSE_USE_ID &&
      !NON_PROMOTABLE_STATES.has(t.state) &&
      (!excludeIds || !excludeIds.has(Number(t.id)))
    );
  }

  // The specific tub "add next"/Confirm Cabinet/the confirm modal would
  // promote to Opened. preferWhole toggles the tie-break (the modal's "use
  // full tubs before partial tubs" checkbox) — default matches
  // change-tub.md's original hardcoded rule, now a live per-confirmation
  // choice instead of fixed.
  pickPromotableTub(flavorId, preferWhole = true, excludeIds = null) {
    const pool = this.promotablePool(flavorId, excludeIds);
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
