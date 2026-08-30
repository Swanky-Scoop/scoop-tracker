///////////////////////////////////
// FlavorPickerModal — free-form "pick any eligible flavor" dialog for
// CabinetWorkflow, opened from a slot in the needs-tub, impossible, or
// empty state (see cabinet-workflow-tile.js). Unlike ConfirmSwapModal
// (which proposes one target — current/immediate/next flavor — for an
// already-paired slot), this lists every flavor with at least one
// eligible tub anywhere and lets the user pick freely.
//
// This modal writes nothing itself — picking a tile just hands
// { row, flavorId } to whichever onPick is active, which CabinetWorkflowTile
// wires by default to ConfirmSwapModal.open(row, flavorId). ConfirmSwapModal
// owns the actual tub search/preview/write (see its own header comment);
// this one's job is purely "which flavor." One instance per
// CabinetWorkflowTile, built once and reused/repopulated per open().
//
// open()'s onPick param overrides the constructor default for that one
// open — used by ConfirmSwapModal's "none planned" links (see its own
// header comment) to repurpose this same picker for scheduling
// immediate_flavor/next_flavor instead of proposing a tub-swap target.
//
// Each tile also shows its flavor's pickableFlavors() tub count (a small
// badge, top-right) — the same tally that qualified it for the list in
// the first place, surfaced so a stale count (e.g. a tub emptied via
// another tab moments before this picker opened) is at least visible
// before committing to a pick — see the CabinetWorkflow QA conversation.
//////////////////////////////////
import El from "./_el.js";

export default class FlavorPickerModal extends El {
  constructor({ model, onPick }) {
    super();
    this.model = model;
    this.onPick = onPick;

    this._row = null;
    this._activeOnPick = onPick;
    // Flavor ids to leave off the list for this one open() — see open()'s
    // own comment. Empty for the default "Add Flavor"/"add next" free pick,
    // which has no other-position conflict to avoid (current_flavor is 0
    // for an empty slot; a linked openTub's own flavor is what's already
    // proposed, not something you'd pick again here).
    this._excludeIds = [];

    this._buildDom();

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && this._row) this.close();
    });
  }

  _buildDom() {
    const el = this.el;

    this.ROOT = el('div', { classes: ['modal', 'flavor_picker'] });
    this.FORM = el('form');

    this.CLOSE = el('button', { text: 'X', classes: ['close'], attrs: { type: 'button' } });
    this.CLOSE.addEventListener('click', () => this.close());

    this.TITLE = el('h2', { text: 'Pick new flavor' });

    this.FILTER = el('input', {
      classes: ['gridFilterInput'],
      attrs: { type: 'text', autocomplete: 'off', placeholder: 'Filter…' },
    });
    this.FILTER.addEventListener('input', () => this._applyFilter());

    this.GRID = el('div', { classes: ['flavor-grid'] });

    this.FORM.append(this.CLOSE, this.TITLE, this.FILTER, this.GRID);
    this.ROOT.append(this.FORM);
    document.body.append(this.ROOT);
  }

  // excludeIds: flavor ids this open() shouldn't offer — used by
  // ConfirmSwapModal's "none planned" links (see _openPickerForField) when
  // repurposing this same picker to schedule immediate_flavor/next_flavor
  // directly, so a flavor already sitting in another position of the SAME
  // slot (current, or whichever of immediate/next isn't the one being set)
  // can't be re-picked into a second one — the slot-uniqueness rule
  // enforced server-side (scoop_slot_pre_save_dedupe_own_fields, includes/
  // hooks/cabinet-slot.php) shouldn't be something the user discovers only
  // after picking it and watching a DIFFERENT field go blank.
  open(row, onPick = this.onPick, { excludeIds = [] } = {}) {
    this._row = row;
    this._activeOnPick = onPick;
    this._excludeIds = excludeIds;
    this.FILTER.value = '';
    this._render();
    this.ROOT.classList.add('show');
  }

  // Plain text-contains match against each tile's title (same string the
  // tile's own title attr/label already show) — this list is a flat button
  // grid, not a List-based grid/tile with the .group/.row markup
  // find-in-list.js (assets/ui/find-in-list.js) depends on, so it gets its
  // own small filter here rather than forcing that component onto a shape
  // it wasn't built for (see popular-plot.js's _applyFilter for the same
  // precedent elsewhere in this codebase).
  _applyFilter() {
    const term = this.FILTER.value.normalize('NFKC').toLowerCase().trim();
    for (const tile of this.GRID.children) {
      const title = (tile.dataset.flavorTitle ?? '').toLowerCase();
      tile.hidden = !!term && !title.includes(term);
    }
  }

  close() {
    this.ROOT.classList.remove('show');
    this._row = null;
  }

  _render() {
    const el = this.el;
    this.GRID.replaceChildren();

    const excluded = new Set(this._excludeIds.map(Number));
    for (const flavor of this.model.pickableFlavors(this._row)) {
      if (excluded.has(Number(flavor.id))) continue;
      const TILE = el('button', {
        classes: ['flavor-tile'],
        attrs: { type: 'button', title: flavor.title },
        data: { flavorId: flavor.id, flavorTitle: flavor.title },
      });

      if (flavor.photo) TILE.append(el('img', { attrs: { src: flavor.photo, alt: flavor.title } }));
      TILE.append(el('span', { classes: ['flavor-tile-title'], text: flavor.title }));
      // Same tub tally the tile's own eligibility check used to include
      // this flavor at all (see pickableFlavors) — visible up front so a
      // staleness mismatch (e.g. a tub emptied via another tab moments
      // ago) is at least noticeable before committing to a pick.
      TILE.append(el('span', { classes: ['flavor-tile-count'], text: String(flavor.count) }));

      TILE.addEventListener('click', () => {
        const row = this._row;
        const onPick = this._activeOnPick;
        this.close();
        if (row && onPick) onPick(row, flavor.id);
      });
      this.GRID.append(TILE);
    }
  }
}
