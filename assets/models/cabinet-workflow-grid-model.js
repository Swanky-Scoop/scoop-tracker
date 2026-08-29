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
const TEMPERING_STATE = 'Tempering';

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

// A tub's `use` relationship field being unset (0) is treated the same as
// an explicit Front-of-House id everywhere this model looks for FOH stock
// — every tub is expected to end up with SOME use assigned, and the
// business default for an unset one is Front-of-House (confirmed against a
// real tub found with use=0 despite being open, linked, and shown as
// Front-of-House in WP admin). Matches the precedent already established
// in flavor-tub-grid-model.js's _isFrontOfHouseUse, which treats a falsy
// use the same way for its own "non-front" filter.
function isFrontOfHouseUse(useId) {
  const id = Number(useId ?? 0);
  return !id || id === FRONT_OF_HOUSE_USE_ID;
}

// pickableFlavors' exclusions — deliberately narrower than
// NON_PROMOTABLE_STATES: an Opened tub DOES make its flavor "available" to
// pick (see CabinetWorkflow QA conversation), it's only excluded from
// promotablePool because that pool is about choosing a tub to newly
// promote, a different question from "does this flavor have any usable
// stock at all."
const NON_PICKABLE_STATES = new Set(['Emptied', '!Lost']);

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

    // No _config.php entry (see this file's header comment) means
    // BaseGridModel's icon fallback never gets a server-driven value to use
    // — set it directly here rather than adding a _config.php entry just
    // for this, which would flip the `if (this.metaData)` buildCols() gate
    // on and break the "no server-driven metaData" design on purpose.
    this.icon = 'if:c';

    // Find-in-list text filter, narrowing by cabinet (this grid groups
    // slots by cabinet — see buildRows below). Works via find-in-list.js's
    // class-only .group/.row selectors, so it needs no changes on this
    // model's side beyond opting in.
    this.filter = true;

    // This model ships no columns (see this file's header comment), so
    // List._onDomainUpdated's additive _patchRefresh path — which patches
    // existing rows by iterating a model's columns — has nothing to work
    // with and silently can't update a slot's flavor/tub/status on a
    // refresh this tile didn't itself cause (see change-tub.md's
    // "Optimistic repaint + confirmation diff" section). Opting into a real
    // rebuild every time is the counterpart to Batch/Closeout's
    // `repaintOnRefresh = false` — same idea (the generic patch mechanism
    // doesn't apply to this view), opposite direction (always repaint for
    // real, rather than never).
    this.rebuildOnRefresh = true;
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

    // pickableTubCount (not remainingSummary) — per the CabinetWorkflow QA
    // conversation: the cabinet card's counts should match the same
    // eligibility rule the flavor picker itself uses (front-of-house, not
    // Emptied/!Lost — Opened tubs DO count here, unlike remainingSummary's
    // "still in the pipeline" figure, which additionally excludes Opened).
    row.tubCountLocal = this.pickableTubCount(flavorId, slot.location);
    row.tubCountTotal = this.pickableTubCount(flavorId, null);
    row.canAddNext    = this.promotablePool(flavorId).length > 0;

    // Deliberately NOT read from slot.tub. slot.tub/tub.slot are meant to
    // be a bidirectional Pods sister field (see change-tub.md), but that
    // pairing has to be joined by hand in Pods admin on every environment
    // (never propagated by Schema Sync — see project memory on the
    // sister_id gap) and was found unjoined here: a genuinely-linked tub
    // (tub.slot correct) still read slot.tub === 0. tub.slot is the side
    // every write actually goes through (ConfirmSwapModal, Confirm
    // Cabinet — slot.tub is never written from the slot side), so it's
    // searched directly here instead, sidestepping the sister-field
    // dependency entirely rather than just papering over its current gap.
    const linkedTub = (Array.isArray(this.domain.tub) ? this.domain.tub : [])
      .find(t => Number(t.slot ?? 0) === Number(slot.id)) ?? null;
    row.currentTubId = linkedTub ? Number(linkedTub.id) : 0;

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

    // Exactly one already-Opened, unclaimed tub of this flavor is sitting
    // here — Confirm Cabinet will just link it (no state write, nothing
    // gets newly opened), unlike the 0-candidate case (needs a fresh tub
    // promoted) or the >1 case (discrepancy, ambiguous). See the
    // CabinetWorkflow QA conversation — this is what separates the two
    // outcomes 'needs-tub' alone couldn't distinguish.
    row.readyToLink = !row.openTub && unclaimedOpen.length === 1;

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
      isFrontOfHouseUse(t.use) &&
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
      isFrontOfHouseUse(t.use) &&
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

  // 'dairy' base isn't its own field — a flavor is dairy-base iff 'dairy'
  // is among its allergens (see CabinetWorkflow QA conversation). Two rules,
  // both must clear:
  //   1. Standard exclusion — any of the flavor's allergens is on the
  //      cabinet's own prohibited_allergens list.
  //   2. If the cabinet does NOT prohibit dairy, it's a dairy cabinet by
  //      convention — only dairy-base flavors qualify there, regardless of
  //      what else it does/doesn't prohibit (e.g. a cabinet prohibiting
  //      only tree nuts still requires 'dairy', it isn't "anything but tree
  //      nuts"). A cabinet that DOES prohibit dairy (an oat/coconut/pea
  //      cabinet) skips this extra requirement — rule 1 already excludes
  //      dairy-base flavors there on its own.
  _allergenConflict(flavorId, cabinetId) {
    const flavor = this._flavorsById.get(Number(flavorId));
    const flavorAllergens = (Array.isArray(flavor?.allergens) ? flavor.allergens : [])
      .map(a => String(a).toLowerCase());

    const prohibited = this._prohibitedAllergenSlugs(cabinetId);

    if (flavorAllergens.some(a => prohibited.has(a))) return true;
    if (!prohibited.has('dairy') && !flavorAllergens.includes('dairy')) return true;

    return false;
  }

  // ─── Public — used by CabinetWorkflowTile and the confirm-swap modal ───

  flavorInfo(flavorId) {
    const flavor = this._flavorsById.get(Number(flavorId));
    return {
      id: Number(flavorId),
      title: flavor?._title ?? '',
      photo: flavor?.photo ?? '',
      // Needed for CabinetWorkflowTile's optimistic repaint (buildItemDom
      // renders allergen icons) — not previously exposed here since nothing
      // else needed it.
      allergens: Array.isArray(flavor?.allergens) ? flavor.allergens : [],
    };
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

  // Same eligibility/exclusion as remainingSummary — front-of-house, still
  // somewhere in the pipeline (not Opened/Emptied/!Lost) — but the actual
  // tubs, not a summed amount. Opened is already excluded here (see
  // DISPLAY_EXCLUDED_STATES), so this never includes a slot's own outgoing
  // tub — used by ConfirmSwapModal's "staff gave up looking, mark the rest
  // lost" flow (see OTHER-USES.md) to both count and write to them.
  remainingTubs(flavorId, locationId) {
    return this._fohTubsExcluding(Number(flavorId), locationId, DISPLAY_EXCLUDED_STATES);
  }

  // Every tub of a flavor that isn't Emptied, minus a given set of ids —
  // used by ConfirmSwapModal's "[unless lost]" review dialog to show what
  // WON'T be touched by that action. Deliberately not front-of-house-only
  // or location-scoped like remainingTubs above — this is "what else is
  // out there" context for a human reviewing the mark-lost prompt, not an
  // eligibility filter.
  otherNonEmptiedTubs(flavorId, excludeIds) {
    const tubs = Array.isArray(this.domain.tub) ? this.domain.tub : [];
    return tubs.filter(t =>
      Number(t.flavor) === Number(flavorId) &&
      t.state !== 'Emptied' &&
      !excludeIds.has(Number(t.id))
    );
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
      isFrontOfHouseUse(t.use) &&
      !NON_PROMOTABLE_STATES.has(t.state) &&
      (!excludeIds || !excludeIds.has(Number(t.id)))
    );
  }

  // Count of tubs matching pickableFlavors' own eligibility rule (front-
  // of-house use, not Emptied, not !Lost — NON_PICKABLE_STATES, Opened DOES
  // count) for a single flavor. locationId === null means everywhere
  // (matches pickableFlavors' own unscoped philosophy); pass a location to
  // scope it, same local/total split remainingSummary uses. Deliberately a
  // different figure from remainingSummary, which additionally excludes
  // Opened tubs — this one answers "is this flavor actually pickable right
  // now," not "how much is still in the pipeline." Used by the cabinet
  // card's tub-count-local/total (see _fillSlotRow) — kept as its own
  // method (rather than only computed inline in pickableFlavors below) so
  // a single flavor/location pair can be queried without rebuilding the
  // whole candidate list.
  pickableTubCount(flavorId, locationId = null) {
    const tubs = Array.isArray(this.domain.tub) ? this.domain.tub : [];
    return tubs.filter(t =>
      Number(t.flavor) === Number(flavorId) &&
      isFrontOfHouseUse(t.use) &&
      !NON_PICKABLE_STATES.has(t.state) &&
      (locationId == null || Number(t.location) === Number(locationId))
    ).length;
  }

  // Distinct flavors eligible for the "Pick new flavor" picker (see
  // FlavorPickerModal) — all four must hold, per the CabinetWorkflow QA
  // conversation:
  //   1. At least one front-of-house tub of that flavor, anywhere, not
  //      Emptied and not !Lost (NON_PICKABLE_STATES — Opened tubs DO count,
  //      unlike promotablePool's stricter pool). Not location-scoped, same
  //      rationale as promotablePool.
  //   2. Doesn't conflict with the cabinet's allergen rules (see
  //      _allergenConflict).
  //   3. Not already `current_flavor` on a different slot in the same
  //      cabinet — no duplicate flavors per cabinet.
  //   4. Excludes row.currentTubId (the slot's own outgoing tub, if any)
  //      from the count — picking a flavor always evicts whatever tub is
  //      currently linked here (see "uniform tub-selection logic" in the QA
  //      conversation), so that tub can't count as this flavor's own
  //      available stock (problem case 2: it's about to be gone).
  // row is the slot being filled (needs row.cabinetId/row.slotId/
  // row.currentTubId for rules 2-4); pass null/undefined to skip all three
  // and return every flavor with an eligible tub anywhere. Each returned
  // flavor carries `count` — the same tub tally as pickableTubCount(id)
  // would give, computed inline here (one pass over domain.tub) rather
  // than calling that method per flavor after the fact — shown on the
  // picker's own tiles so a staleness mismatch (e.g. a tub emptied via
  // another tab moments ago) is visible before committing to a pick.
  pickableFlavors(row = null) {
    const tubs = Array.isArray(this.domain.tub) ? this.domain.tub : [];
    const excludeTubId = Number(row?.currentTubId ?? 0) || 0;
    const counts = new Map();
    for (const t of tubs) {
      if (!isFrontOfHouseUse(t.use)) continue;
      if (NON_PICKABLE_STATES.has(t.state)) continue;
      if (excludeTubId && Number(t.id) === excludeTubId) continue;
      const flavorId = Number(t.flavor);
      counts.set(flavorId, (counts.get(flavorId) ?? 0) + 1);
    }

    const cabinetId = row?.cabinetId ?? null;
    const dupeIds = cabinetId ? this._siblingFlavorIds(cabinetId, row?.slotId) : new Set();

    return [...counts.keys()]
      .filter(id => !dupeIds.has(id))
      .filter(id => !cabinetId || !this._allergenConflict(id, cabinetId))
      .map(id => ({ ...this.flavorInfo(id), count: counts.get(id) }))
      .filter(f => f.title)
      .sort((a, b) => a.title.localeCompare(b.title));
  }

  // current_flavor already in use by another slot in the same cabinet —
  // backs pickableFlavors' no-duplicate-flavors-per-cabinet rule.
  // excludeSlotId is the slot being filled itself, not a duplicate of
  // itself.
  _siblingFlavorIds(cabinetId, excludeSlotId) {
    const slots = Array.isArray(this.domain.slot) ? this.domain.slot : [];
    const ids = new Set();
    for (const s of slots) {
      if (Number(s.cabinet ?? 0) !== Number(cabinetId)) continue;
      if (Number(s.id) === Number(excludeSlotId)) continue;
      const flavorId = Number(s.current_flavor ?? 0) || 0;
      if (flavorId) ids.add(flavorId);
    }
    return ids;
  }

  // Tempering outranks every other non-Opened, non-Emptied, non-!Lost
  // state outright when picking a tub to promote — the top sort key, not a
  // tie-break among candidates already equal on whole/partial or age. See
  // the CabinetWorkflow QA conversation: a tub already Tempering is closer
  // to ready for service than one still Hardening/Freezing/__override__,
  // so it goes first regardless of how old or how full anything else is.
  // Used by both pickPromotableTub (Confirm Cabinet) and pickNextTub
  // (ConfirmSwapModal) — a physical-correctness rule, not a per-flow
  // preference, so it applies wherever a tub gets promoted.
  _temperingRank(t) {
    return t.state === TEMPERING_STATE ? 0 : 1;
  }

  // The specific tub Confirm Cabinet would promote to Opened. preferWhole
  // toggles the tie-break (the modal's "use full tubs before partial
  // tubs" checkbox) — default matches change-tub.md's original hardcoded
  // rule, now a live per-confirmation choice instead of fixed.
  pickPromotableTub(flavorId, preferWhole = true, excludeIds = null) {
    const pool = this.promotablePool(flavorId, excludeIds);
    const byAge = (a, b) =>
      String(a.created_on ?? '').localeCompare(String(b.created_on ?? '')) ||
      (Number(a.index) || 0) - (Number(b.index) || 0);

    const whole   = pool.filter(t => Number(t.amount ?? 1) >= WHOLE_TUB_THRESHOLD).sort(byAge);
    const partial = pool.filter(t => Number(t.amount ?? 1) <  WHOLE_TUB_THRESHOLD).sort(byAge);
    const ordered = (preferWhole ? [...whole, ...partial] : [...partial, ...whole])
      .sort((a, b) => this._temperingRank(a) - this._temperingRank(b));

    return ordered[0] ?? null;
  }

  // ConfirmSwapModal's tub-selection algorithm — deliberately separate from
  // pickPromotableTub/pickClosestToOne (still used by Confirm Cabinet's own
  // automatic reconciliation, unchanged). Per the CabinetWorkflow QA
  // conversation: state, use, slot, location, and created_on drive
  // eligibility/order; amount only re-enters as preferWhole, the modal's
  // own user-dictated checkbox — not an automatic default.
  //
  //   rule (a): an Opened, front-of-house, unclaimed tub of this flavor —
  //     nothing to promote, just adopt. Global, not location-scoped
  //     (location is a sort preference below, not an eligibility gate).
  //     Ignores preferWhole entirely — there's no "prefer whole" decision
  //     to make about a tub that's already open and sitting there.
  //   rule (b): falls back to promotablePool (Emptied/Opened/!Lost
  //     excluded) when rule (a) finds nothing. Tempering outranks every
  //     other state outright here (see _temperingRank) — the top sort key,
  //     ahead of even location. preferWhole applies below that, same
  //     true/false meaning as pickPromotableTub's.
  //
  // rule (a) has no state-rank tier — it's exclusively Opened tubs by
  // definition, nothing to rank Tempering against. Both pools sort
  // same-location-first (below Tempering, for rule (b)), then (rule (b)
  // only) whole/partial per preferWhole, then oldest created_on.
  // excludeTubId is the slot's own outgoing tub (about to be evicted as
  // part of this same action, per "uniform tub-selection logic" — see the
  // QA conversation's problem case 2) so it can never count as its own
  // replacement.
  pickNextTub(flavorId, locationId, excludeTubId, preferWhole = true) {
    const locRank = (t) => Number(t.location) === Number(locationId) ? 0 : 1;
    const byAge = (a, b) =>
      String(a.created_on ?? '').localeCompare(String(b.created_on ?? '')) ||
      (Number(a.index) || 0) - (Number(b.index) || 0);

    const openPool = this._openUnclaimedAnywhere(flavorId, excludeTubId)
      .sort((a, b) => (locRank(a) - locRank(b)) || byAge(a, b));
    if (openPool.length) return { tub: openPool[0], rule: 'a', pool: openPool };

    const excludeIds = excludeTubId ? new Set([Number(excludeTubId)]) : null;
    const promotePool = this._promotableRanked(flavorId, locationId, excludeIds, preferWhole);

    return { tub: promotePool[0] ?? null, rule: 'b', pool: promotePool };
  }

  // The sort behind pickNextTub's rule (b) — Tempering rank, then location,
  // then whole/partial per preferWhole, then oldest created_on — factored
  // out so pickNextTubs (below) can draw more than one tub in the exact
  // same order a sequence of single swaps would have picked them, rather
  // than duplicating this sort.
  _promotableRanked(flavorId, locationId, excludeIds, preferWhole) {
    const locRank = (t) => Number(t.location) === Number(locationId) ? 0 : 1;
    const byAge = (a, b) =>
      String(a.created_on ?? '').localeCompare(String(b.created_on ?? '')) ||
      (Number(a.index) || 0) - (Number(b.index) || 0);
    const wholeRank = (t) => (Number(t.amount ?? 1) >= WHOLE_TUB_THRESHOLD) === preferWhole ? 0 : 1;

    return this.promotablePool(flavorId, excludeIds)
      .sort((a, b) =>
        (this._temperingRank(a) - this._temperingRank(b))
        || (locRank(a) - locRank(b))
        || (wholeRank(a) - wholeRank(b))
        || byAge(a, b)
      );
  }

  // Up to `count` tubs from the promotable pool, same order pickNextTub's
  // rule (b) already uses — backs ConfirmSwapModal's multi-click "N tubs of
  // this flavor sold out today" gesture (see OTHER-USES.md): the last one
  // returned is what gets promoted to Opened, everything before it gets
  // marked Emptied directly (never individually opened) — same outcome as
  // N sequential single swaps, settled in one write. Not location-scoped
  // for eligibility (a tub can be carried between locations, same as
  // promotablePool/pickNextTub) — locationId only affects sort preference.
  pickNextTubs(flavorId, locationId, count, preferWhole = true) {
    return this._promotableRanked(flavorId, locationId, null, preferWhole).slice(0, Math.max(0, count));
  }

  // rule (a)'s raw pool — see pickNextTub.
  _openUnclaimedAnywhere(flavorId, excludeTubId) {
    const tubs = Array.isArray(this.domain.tub) ? this.domain.tub : [];
    return tubs.filter(t =>
      Number(t.flavor) === Number(flavorId) &&
      isFrontOfHouseUse(t.use) &&
      t.state === OPEN_TUB_STATE &&
      Number(t.slot ?? 0) === 0 &&
      Number(t.id) !== Number(excludeTubId || 0)
    );
  }

  // Full plan for "pick flavor `flavorId` for this row" — everything
  // ConfirmSwapModal needs to preview, commit, and alert on in one place.
  // outgoingTub is row.currentTubId resolved regardless of its
  // state/validity — the slot's stale link, if any, per "any existing tub
  // should be removed" (uniform logic, no same-flavor special case — see
  // the QA conversation).
  planTubChange(row, flavorId, preferWhole = true) {
    const outgoingTub = row.currentTubId ? (this._tubsById.get(Number(row.currentTubId)) ?? null) : null;
    const { tub, rule, pool } = this.pickNextTub(flavorId, row.location, row.currentTubId, preferWhole);
    return { outgoingTub, tub, rule, pool };
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
