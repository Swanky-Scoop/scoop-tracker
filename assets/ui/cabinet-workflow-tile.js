///////////////////////////////////
// CabinetWorkflowTile — card renderer for the "change tub" workflow (see
// change-tub.md). Paired 1:1 with CabinetWorkflowGridModel, which ships no
// columns (see that file's header comment) — so unlike Tile's normal
// column-driven buildFieldDom, every field of a slot's markup is built
// directly from the row object here, in buildItemDom. List still calls
// buildFieldDom once per (empty) fields array entry, so it's overridden
// defensively but never actually produces visible output.
//////////////////////////////////
import Tile from "./tile.js";
import ConfirmSwapModal from "./confirm-swap-modal.js";

export default class CabinetWorkflowTile extends Tile {

  // Appended straight to FRAME (not TOOLS) because TOOLS gets wiped and
  // rebuilt from this.fields the one time List._buildFields() runs during
  // init() — this model ships no fields (see CabinetWorkflowGridModel), so
  // anything put in TOOLS at construction time would just get cleared a
  // moment later. FRAME itself is never wiped wholesale, only its tracked
  // group containers are, so a button appended here survives every
  // subsequent domain refresh.
  buildCoreDom() {
    super.buildCoreDom();

    this.CONFIRM_CABINET = this.el('button', {
      text: 'Confirm Cabinet',
      classes: ['confirm-cabinet'],
      attrs: { type: 'button' },
    });
    this.CONFIRM_CABINET.addEventListener('click', () => this._reconcileCabinet({ alertResult: true }));

    this.FRAME.append(this.CONFIRM_CABINET);

    // One modal instance per Tile, reused/repopulated per 'add-next' click
    // (see confirm-swap-modal.js) — not built per-slot, and not wiped by
    // group-container rebuilds since it isn't inside FRAME.
    this.SWAP_MODAL = new ConfirmSwapModal({ api: this.api, model: this.modelInstance });

    this.FRAME.addEventListener('click', (e) => {
      const nextBtn = e.target.closest('.add-next');
      if (nextBtn && !nextBtn.disabled) {
        const slotId = Number(nextBtn.dataset.slotId);
        const row = (this.items ?? []).find(r => r.slotId === slotId);
        // row.openTub is the slot's confirmed, linked tub (see
        // CabinetWorkflowGridModel._fillSlotRow) — without it there's
        // nothing for the swap modal to swap out yet. Confirm Cabinet must
        // run first to adopt/open a tub for this slot; buildItemDom()
        // disables the button for the same reason, this is the defensive
        // backstop.
        if (row && row.openTub) this.SWAP_MODAL.open(row);
        return;
      }

      const addBtn = e.target.closest('.add-flavor');
      if (addBtn && !addBtn.disabled) {
        const slotId = Number(addBtn.dataset.slotId);
        const row = (this.items ?? []).find(r => r.slotId === slotId);
        // Narrow scope for now: only opens when this empty slot already has
        // a scheduled flavor (immediate_flavor/next_flavor, e.g. left there
        // by a prior "leave slot empty"). Free-form flavor choice for a
        // slot with no schedule at all is a later fallback, not built yet
        // — buildItemDom() disables the button in that case for the same
        // reason.
        if (row && (row.immediateFlavorId || row.nextFlavorId)) this.SWAP_MODAL.open(row);
      }
    });

    // GUI is blocked (pointer-events off, not just visually flagged) until
    // the first reconciliation pass completes — see change-tub.md. Runs
    // once, on the first render only ('ts:list:init' fires at the end of
    // List.init(), which only happens the first time setDomain() lands);
    // ts:domain:updated-triggered refreshes go through List.refresh(), not
    // init(), so this doesn't re-fire and re-block on every later save.
    this.FORM.addEventListener('ts:list:init', () => {
      this._reconcileCabinet({ alertResult: false });
    }, { once: true });
  }

  // Confirm Cabinet: makes sure every slot with a current_flavor has
  // exactly one valid tub — Opened, matching that flavor, linked via
  // tub.slot (see change-tub.md). Runs automatically (blocking) on first
  // load, and again on demand via the button.
  //
  // For each slot without an already-valid link, in order:
  //   1. A tub already Opened at this slot's own location, matching the
  //      flavor, not claimed by a different slot (openUnclaimedPool) — it's
  //      presumably already physically sitting here, just never got linked.
  //      Exactly one: adopt it (link only, no state change — already
  //      Opened). More than one: ambiguous — pair the one with `amount`
  //      closest to 1 (see pickClosestToOne), flag the slot 'discrepancy'
  //      rather than silently guessing right.
  //   2. Otherwise, the oldest non-Emptied, non-Opened tub of that flavor
  //      (Hardening/Tempering/Freezing/__override__ all qualify — only
  //      Emptied is a hard exclude, per change-tub.md), from anywhere
  //      (location doesn't gate this — see
  //      CabinetWorkflowGridModel.promotablePool) — opened, linked, and its
  //      location corrected to match this cabinet (same rule as Add Flavor).
  //   3. Otherwise — or if the flavor's allergens conflict with the
  //      cabinet's prohibited_allergens — 'impossible': current_flavor
  //      stays, no tub gets forced in.
  // Every tub this pass touches (adopted, discrepancy pair, or freshly
  // opened) is claimed immediately so a later slot in the same pass can't
  // also grab it.
  async _reconcileCabinet({ alertResult = true } = {}) {
    if (!this.api) return;

    this.FRAME.classList.add('reconciling');
    this.FRAME.style.pointerEvents = 'none';

    try {
      const allRows = this.items ?? [];
      const rows = allRows.filter(r => !r.empty);
      const claimed = new Set();

      // Already-valid links are claimed first so nothing below can also
      // hand that same tub to a different slot needing the same flavor.
      for (const row of rows) {
        if (row.openTub) claimed.add(Number(row.openTub.id));
      }

      const tubCells = {};
      // slot.confirm_state (see change-tub.md) — persisted so reporting
      // outside this GUI can see it; written for every slot every run,
      // not just the ones this pass changed something for.
      const slotCells = {};
      const assigned = [];
      const discrepancies = [];
      const impossible = [];

      for (const row of allRows) {
        if (row.empty) {
          slotCells[row.slotId] = { confirm_state: 'empty' };
          continue;
        }

        if (row.openTub) {
          slotCells[row.slotId] = { confirm_state: 'filled' };
          continue;
        }

        if (row.allergenConflict) {
          impossible.push(row);
          slotCells[row.slotId] = { confirm_state: 'impossible' };
          continue;
        }

        const unclaimedOpen = this.modelInstance.openUnclaimedPool(row.flavorId, row.location, row.slotId, claimed);
        if (unclaimedOpen.length > 0) {
          const picked = unclaimedOpen.length === 1 ? unclaimedOpen[0] : this.modelInstance.pickClosestToOne(unclaimedOpen);
          unclaimedOpen.forEach(t => claimed.add(Number(t.id))); // claim all candidates, not just the pick
          tubCells[picked.id] = { slot: row.slotId }; // already Opened — link only

          if (unclaimedOpen.length > 1) {
            discrepancies.push(row);
            slotCells[row.slotId] = { confirm_state: 'discrepancy' };
          } else {
            assigned.push(row);
            slotCells[row.slotId] = { confirm_state: 'filled' };
          }
          continue;
        }

        const candidate = this.modelInstance.pickPromotableTub(row.flavorId, true, claimed);
        if (!candidate) {
          impossible.push(row);
          slotCells[row.slotId] = { confirm_state: 'impossible' };
          continue;
        }

        claimed.add(Number(candidate.id));
        tubCells[candidate.id] = { state: 'Opened', slot: row.slotId, location: row.location };
        assigned.push(row);
        slotCells[row.slotId] = { confirm_state: 'filled' };
      }

      if (Object.keys(tubCells).length) {
        const r = await this.api.postJson({ cells: tubCells }, 'FlavorTub');
        if (!r.ok || !r.data?.ok) {
          alert(`Confirm Cabinet: saving failed.\n${r?.data?.error ?? `HTTP ${r?.status}`}`);
          return;
        }
      }

      if (Object.keys(slotCells).length) {
        const rSlots = await this.api.postJson({ cells: slotCells }, 'Cabinet');
        if (!rSlots.ok || !rSlots.data?.ok) {
          alert(`Confirm Cabinet: tubs updated, but recording slot status failed.\n${rSlots?.data?.error ?? `HTTP ${rSlots?.status}`}`);
          return;
        }
      }

      if (Object.keys(tubCells).length || Object.keys(slotCells).length) {
        await this.api.refreshPageDomain({ force: true });
      }

      if (alertResult) alert(this._confirmCabinetMessage(assigned, discrepancies, impossible));

    } finally {
      this.FRAME.classList.remove('reconciling');
      this.FRAME.style.pointerEvents = '';
    }
  }

  _confirmCabinetMessage(assigned, discrepancies, impossible) {
    const lines = assigned.length
      ? [`Linked ${assigned.length} slot(s):`, ...assigned.map(row => `- ${row.flavorTitle} (${row.cabinetTitle})`)]
      : ['No changes needed.'];

    if (discrepancies.length) {
      lines.push('', 'Multiple open tubs matched (paired closest to a whole tub):',
        ...discrepancies.map(row => `- ${row.flavorTitle} (${row.cabinetTitle})`));
    }

    if (impossible.length) {
      lines.push('', 'Slots with no valid tub:', ...impossible.map(row => {
        const reason = row.allergenConflict ? 'allergen conflict with cabinet' : 'no eligible tub found';
        return `- ${row.flavorTitle} (${row.cabinetTitle}): ${reason}`;
      }));
    }

    return lines.join('\n');
  }

  buildItemDom(row) {
    const el = this.el;

    const statusClass = row.impossible ? 'impossible' : row.discrepancy ? 'discrepancy' : null;

    const LI = el('li', {
      classes: ['slot', row.empty ? 'empty' : this._slug(row.flavorTitle), statusClass],
      data: { rowId: row.slotId ?? 0, slotId: row.slotId ?? 0 },
    });

    const DIV = el('div', { classes: ['available'] });

    if (row.flavorPhoto) {
      LI.append(el('img', { attrs: { src: row.flavorPhoto, alt: row.flavorTitle } }));
    }
    
    if (row.empty) {
      // Narrow scope for now: only opens the swap modal (reused) when this
      // slot already has a scheduled flavor (immediate_flavor/next_flavor)
      // — e.g. left there by a prior "leave slot empty". A free-form
      // flavor picker for a slot with no schedule at all is a later
      // fallback, not built yet.
      const hasSchedule = !!(row.immediateFlavorId || row.nextFlavorId);
      LI.append(el('button', {
        text: 'Add Flavor',
        classes: ['add-flavor'],
        attrs: hasSchedule
          ? { type: 'button' }
          : { type: 'button', disabled: true, title: 'No flavor scheduled for this slot yet.' },
        data: { slotId: row.slotId },
      }));
      return LI;
    }

    LI.append(el('h3', { text: row.flavorTitle }));
    LI.append(this._allergensDom(row));

    DIV.append(this._statLabel('tub-count-local', 'Local tubs', row.tubCountLocal));
    DIV.append(this._statLabel('tub-count-total', 'Total tubs', row.tubCountTotal));

    LI.append(DIV);

    // Always shown, even with 0 promotable tubs — the confirm-swap modal
    // is also the only path to "leave slot empty," which must still be
    // reachable to mark the existing tub Emptied when nothing's left to
    // replace it with. The modal itself disables Confirm Swap and shows
    // "No tub available" in that case; row.canAddNext is no longer used to
    // gate this button (superseded change-tub.md decision — was omitted
    // entirely before).
    //
    // Disabled when row.openTub is null: there's no confirmed tub linked to
    // this slot yet to swap out or empty, so nothing here has a valid
    // target until Confirm Cabinet resolves it (adopts/opens one, or flags
    // the slot impossible/discrepancy).
    LI.append(el('button', {
      text: 'add next',
      classes: ['add-next'],
      attrs: row.openTub
        ? { type: 'button' }
        : { type: 'button', disabled: true, title: 'Run Confirm Cabinet first to link a tub to this slot.' },
      data: { slotId: row.slotId },
    }));

    LI.append(el('button', {
      text: 'add special',
      classes: ['add-special'],
      attrs: { type: 'button' },
      data: { slotId: row.slotId },
    }));

    return LI;
  }

  // Same markup/classes as Tile._buildMultiFieldDom's post_names branch
  // (see tile.js) so the existing `.multiple.allergens` CSS applies as-is.
  _allergensDom(row) {
    const allergens = row.allergens ?? [];
    const WRAP = this.el('div', { classes: ['multiple', 'allergens'] });

    const LABEL = this.el('label', { classes: ['allergen-count'] });
    LABEL.append('Allergen count: ');
    LABEL.append(this.el('em', { text: String(allergens.length) }));
    WRAP.append(LABEL);

    if (allergens.length) {
      const UL = this.el('ul');
      allergens.forEach(slug => {
        const LI = this.el('li', { classes: [this._slug(slug)] });

        const iconUrl = this.modelInstance.allergenIconUrl(slug);
        if (iconUrl) LI.append(this.el('img', { attrs: { src: iconUrl, alt: slug } }));

        LI.append(slug);
        UL.append(LI);
      });
      WRAP.append(UL);
    }

    return WRAP;
  }

  _statLabel(cls, label, amount) {
    const LABEL = this.el('label', { classes: [cls] });
    LABEL.append(`${label}: `);
    LABEL.append(this.el('em', { text: this._formatAmount(amount) }));
    return LABEL;
  }

  _formatAmount(n) {
    const num = Number(n) || 0;
    return Number.isInteger(num) ? String(num) : num.toFixed(1);
  }

  // CabinetWorkflowGridModel ships no columns, so List never actually calls
  // this (fields.forEach is a no-op over an empty array) — overridden only
  // to satisfy Tile/List's abstract-method contract.
  buildFieldDom() {
    return this.el('span');
  }
}
