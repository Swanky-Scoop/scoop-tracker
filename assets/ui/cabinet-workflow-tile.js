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
import FlavorPickerModal from "./flavor-picker-modal.js";
import Toast from "./toast.js";

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

    // Two modal instances per Tile, reused/repopulated per open() call —
    // not built per-slot, and not wiped by group-container rebuilds since
    // neither is inside FRAME. FlavorPickerModal picks a flavor and writes
    // nothing itself; ConfirmSwapModal owns the actual tub search/preview/
    // write for both entry points (see each file's header comment). The
    // two wire into each other via callbacks (not direct references) to
    // avoid a construction-order circular dependency:
    //   onPick        — tile grid pick -> propose it as a tub-swap target.
    //   onChangePlan  — "Change Plan" button -> back to the tile grid.
    //   openPickerFor — "none planned" links -> reopen the SAME picker
    //                   instance with a one-off onPick override (schedule
    //                   immediate_flavor/next_flavor instead).
    //   getRow        — re-fetch a slot's current row by id after a write;
    //                   the row object captured at open() time goes stale
    //                   the moment refreshPageDomain rebuilds this.items.
    //   paintOptimistic/confirmOptimistic — see their own definitions below.
    this.SWAP_MODAL = new ConfirmSwapModal({
      api: this.api,
      model: this.modelInstance,
      onChangePlan: (row) => this.PICKER_MODAL.open(row),
      openPickerFor: (row, onPicked) => this.PICKER_MODAL.open(row, onPicked),
      getRow: (slotId) => (this.items ?? []).find(r => r.slotId === slotId),
      paintOptimistic: (slotId, patch) => this._paintOptimistic(slotId, patch),
      confirmOptimistic: (slotId, expected) => this._confirmOptimistic(slotId, expected),
    });
    this.PICKER_MODAL = new FlavorPickerModal({
      model: this.modelInstance,
      onPick: (row, flavorId) => this.SWAP_MODAL.open(row, flavorId),
    });

    this.FRAME.addEventListener('click', (e) => {
      const nextBtn = e.target.closest('.add-next');
      if (nextBtn && !nextBtn.disabled) {
        const slotId = Number(nextBtn.dataset.slotId);
        const row = (this.items ?? []).find(r => r.slotId === slotId);
        if (!row) return;
        // row.openTub: a confirmed, linked tub already exists — swap it for
        // another (current/immediate/next). No openTub but not a
        // discrepancy (needs-tub or impossible): nothing to swap, offer the
        // free-form picker instead. Discrepancy stays out of both — run
        // Confirm Cabinet first to resolve which open tub belongs here
        // (buildItemDom() disables the button in that case).
        if (row.openTub) this.SWAP_MODAL.open(row);
        else this.PICKER_MODAL.open(row);
        return;
      }

      const addBtn = e.target.closest('.add-flavor');
      if (addBtn && !addBtn.disabled) {
        const slotId = Number(addBtn.dataset.slotId);
        const row = (this.items ?? []).find(r => r.slotId === slotId);
        if (row) this.PICKER_MODAL.open(row);
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

  // Immediate, optimistic repaint of one slot — see the CabinetWorkflow QA
  // conversation. Used by ConfirmSwapModal right after a write's POST(s)
  // succeed, so the card shows the outcome before the confirmation refetch
  // (refreshPageDomain, which can take 10+ seconds) resolves. patch is a
  // shallow overlay on the slot's current row — only the fields the caller
  // already knows the write will produce (flavor identity, the newly
  // linked tub); everything else (tubCountLocal/Total, allergen icons for
  // an unchanged flavor, etc.) carries over from the pre-write row as-is
  // and self-corrects once the real refresh lands.
  //
  // Rendered through buildItemDom() — the same path every row uses, so
  // there's no second rendering implementation to keep in sync — then
  // marked 'confirming' and swapped in for the real DOM node. Does NOT
  // touch this.items itself: the model's own domain (and therefore the
  // next real refresh) is untouched until the server actually confirms it,
  // this is purely a DOM-level preview.
  _paintOptimistic(slotId, patch) {
    const row = (this.items ?? []).find(r => r.slotId === slotId);
    if (!row) return;

    const optimisticRow = { ...row, ...patch };
    const newLi = this.buildItemDom(optimisticRow);
    newLi.classList.add('confirming');

    const oldLi = this.FRAME.querySelector(`li[data-slot-id="${slotId}"]`);
    if (oldLi) oldLi.replaceWith(newLi);
  }

  // Single-slot convenience wrapper around _confirmOptimisticBatch — see
  // its own header comment. Used by ConfirmSwapModal's writes, which only
  // ever touch one slot at a time.
  _confirmOptimistic(slotId, expected) {
    this._confirmOptimisticBatch([{ slotId, ...expected }]);
  }

  // Called once the write's confirmation refetch (refreshPageDomain) has
  // resolved. expectedList = [{ slotId, current_flavor, tub }, ...] — the
  // raw slot fields each write was supposed to produce (same raw-field
  // comparison the FRAME-level mismatch QA indicator uses — see
  // _applyConformanceStatus — deliberately checking Pods' own fields
  // directly via api.getDomainSnapshot(), not this.items/
  // _onDomainUpdated's eventual patch, since that generic listener's
  // timing relative to this call isn't guaranteed — see change-tub.md).
  // Batched (rather than one call per slot) so Confirm Cabinet's
  // potentially-many resolved slots get ONE consolidated alert and ONE
  // rebuild on mismatch, not one of each per slot.
  //
  // Match: the optimistic paint was correct, just drop the 'confirming'
  // mark — no further repaint. Mismatch (any slot in the batch): this
  // tile's hand-built cards have no field-level patch mechanism (see this
  // file's header comment), so the generic additive _patchRefresh can't be
  // trusted to correct a wrong optimistic node either — alert once, then
  // force a genuine full rebuild (this.refresh, not the additive patch) so
  // the user is never looking at confidently-wrong data.
  _confirmOptimisticBatch(expectedList) {
    if (!expectedList.length) return;

    const freshDomain = this.api.getDomainSnapshot();
    const freshSlotsById = new Map((freshDomain.slot ?? []).map(s => [Number(s.id), s]));

    const mismatchedSlotIds = [];
    for (const expected of expectedList) {
      const freshSlot = freshSlotsById.get(Number(expected.slotId));
      const matches = !!freshSlot
        && Number(freshSlot.current_flavor ?? 0) === Number(expected.current_flavor ?? 0)
        && Number(freshSlot.tub ?? 0) === Number(expected.tub ?? 0);

      if (matches) {
        this.FRAME.querySelector(`li[data-slot-id="${expected.slotId}"]`)?.classList.remove('confirming');
      } else {
        mismatchedSlotIds.push(expected.slotId);
      }
    }

    if (!mismatchedSlotIds.length) return;

    Toast.addMessage({
      title: 'Repainted with actual state',
      message: mismatchedSlotIds.length === 1
        ? "This slot's change didn't match what the server confirmed — repainting with its actual state."
        : `${mismatchedSlotIds.length} slots' changes didn't match what the server confirmed — repainting with its actual state.`,
    });
    this.modelInstance.setDomain(freshDomain);
    this.refresh(this.modelInstance);
  }

  // Crude dev-only QA indicator, not real UI — see cabinet-workflow QA
  // conversation. Recomputed on every render (init AND refresh, since both
  // call _reportFresh() at the end — see _list.js) so it stays live across
  // domain updates. State class goes on FRAME (.zList, the component's
  // root div); css.css reads it off an ::after on .tileTools, a fixed,
  // always-present child (see tile.js buildCoreDom), so the class itself
  // stays easy to find in devtools while the visible glyph lives somewhere
  // stable on screen.
  //
  //   mismatch   (X)  — a slot's linked tub disagrees with reality: either
  //                      the tub the raw Cabinet view would show
  //                      (slot.tub) doesn't point back at this slot via
  //                      tub.slot, or the tub can't be found at all. Pods
  //                      relationship fields are only reliably canonical
  //                      from one side (see project memory on postmeta
  //                      mirroring) — this exists to catch exactly that
  //                      class of drift.
  //   all-paired (**) — no mismatch, AND every slot that Confirm Cabinet
  //                      could ever pair (not empty, not impossible) is
  //                      already paired.
  //   conforms   (+)  — no mismatch, but at least one pairable slot is
  //                      still waiting (i.e. some row is 'needs-tub').
  _reportFresh() {
    super._reportFresh();
    this._applyConformanceStatus();
  }

  _applyConformanceStatus() {
    if (!this.FRAME) return;

    const rows = this.items ?? [];
    const tubs = Array.isArray(this.modelInstance?.domain?.tub) ? this.modelInstance.domain.tub : [];
    const tubsById = new Map(tubs.map(t => [Number(t.id), t]));

    let mismatch = false;
    for (const row of rows) {
      if (row.empty || !row.openTub) continue;
      const tub = tubsById.get(Number(row.currentTubId));
      if (!tub || Number(tub.slot ?? 0) !== Number(row.slotId)) {
        mismatch = true;
        break;
      }
    }

    const resolvable = rows.filter(r => !r.empty && !r.impossible);
    const allPaired = resolvable.length > 0 && resolvable.every(r => !!r.openTub);

    this.FRAME.classList.remove('mismatch', 'conforms', 'all-paired');
    this.FRAME.classList.add(mismatch ? 'mismatch' : allPaired ? 'all-paired' : 'conforms');
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
      // Every row that gets a NEW tub linked this pass (adopted or freshly
      // promoted) — optimistic-repaint candidates (see paintOptimistic
      // below). Not populated for already-filled or impossible rows —
      // nothing changed on those, nothing to preview.
      const pendingPatches = [];

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
          pendingPatches.push({ row, tub: picked });

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
        pendingPatches.push({ row, tub: candidate });
        assigned.push(row);
        slotCells[row.slotId] = { confirm_state: 'filled' };
      }

      if (Object.keys(tubCells).length) {
        const r = await this.api.postJson({ cells: tubCells, source: 'workflow' }, 'FlavorTub');
        if (!r.ok || !r.data?.ok) {
          Toast.addMessage({ title: 'Confirm Cabinet: saving failed', message: r?.data?.error ?? `HTTP ${r?.status}` });
          return;
        }
      }

      if (Object.keys(slotCells).length) {
        const rSlots = await this.api.postJson({ cells: slotCells, source: 'workflow' }, 'Cabinet');
        if (!rSlots.ok || !rSlots.data?.ok) {
          Toast.addMessage({
            title: 'Confirm Cabinet: recording slot status failed',
            message: `Tubs updated, but recording slot status failed.\n${rSlots?.data?.error ?? `HTTP ${rSlots?.status}`}`,
          });
          return;
        }
      }

      // Optimistic repaint for every slot that got a new tub link this pass
      // — same mechanism/reasoning as ConfirmSwapModal's writes (see
      // paintOptimistic's own header comment): show the outcome now, don't
      // make the whole cabinet wait out the confirmation refetch below.
      for (const { row, tub } of pendingPatches) {
        this._paintOptimistic(row.slotId, {
          openTub: { ...tub, state: 'Opened', slot: row.slotId, location: row.location },
          currentTubId: tub.id,
          discrepancy: false,
          impossible: false,
        });
      }

      if (alertResult) {
        Toast.addMessage({
          title: 'Confirm Cabinet',
          changes: this._confirmCabinetMessage(assigned, discrepancies, impossible),
        });
      }

      if (Object.keys(tubCells).length || Object.keys(slotCells).length) {
        await this.api.refreshPageDomain({ force: true });
      }

      this._confirmOptimisticBatch(pendingPatches.map(({ row, tub }) => ({
        slotId: row.slotId,
        current_flavor: row.flavorId,
        tub: tub.id,
      })));

    } finally {
      this.FRAME.classList.remove('reconciling');
      this.FRAME.style.pointerEvents = '';
    }
  }

  // Returns Toast.addMessage's `changes` shape ([{ sentence }, ...]) rather
  // than a single joined string — Toast renders each entry as its own <li>,
  // so this reads as a real list instead of a run-on paragraph (the TOAST
  // <p> has no `white-space: pre-line`, so embedded '\n's wouldn't break
  // lines there the way they used to inside a native alert()).
  _confirmCabinetMessage(assigned, discrepancies, impossible) {
    const lines = assigned.length
      ? [`Linked ${assigned.length} slot(s):`, ...assigned.map(row => `- ${row.flavorTitle} (${row.cabinetTitle})`)]
      : ['No changes needed.'];

    if (discrepancies.length) {
      lines.push('Multiple open tubs matched (paired closest to a whole tub):',
        ...discrepancies.map(row => `- ${row.flavorTitle} (${row.cabinetTitle})`));
    }

    if (impossible.length) {
      lines.push('Slots with no valid tub:', ...impossible.map(row => {
        const reason = row.allergenConflict ? 'allergen conflict with cabinet' : 'no eligible tub found';
        return `- ${row.flavorTitle} (${row.cabinetTitle}): ${reason}`;
      }));
    }

    return lines.map(sentence => ({ sentence }));
  }

  buildItemDom(row) {
    const el = this.el;

    // needs-tub/ready-to-link are mutually exclusive by construction (see
    // row.readyToLink's own comment in the model) — both are the common,
    // auto-resolvable case, split by what Confirm Cabinet would actually
    // do: ready-to-link means an already-Opened unclaimed tub is just
    // waiting to be linked (no state write); needs-tub means resolving it
    // requires promoting a fresh tub. Kept distinct from impossible/
    // discrepancy, which flag cases Confirm Cabinet can't (or can only
    // ambiguously) resolve on its own.
    const statusClass = row.impossible ? 'impossible'
      : row.discrepancy ? 'discrepancy'
      : row.readyToLink ? 'ready-to-link'
      : (!row.empty && !row.openTub) ? 'needs-tub'
      : null;

    const LI = el('li', {
      classes: ['slot', row.empty ? 'empty' : this._slug(row.flavorTitle), statusClass],
      data: { rowId: row.slotId ?? 0, slotId: row.slotId ?? 0 },
    });

    const DIV = el('div', { classes: ['available'] });

    if (row.flavorPhoto) {
      LI.append(el('img', { attrs: { src: row.flavorPhoto, alt: row.flavorTitle } }));
    }
    
    if (row.empty) {
      // Opens the free-form flavor picker (see flavor-picker-modal.js) —
      // no scheduled-flavor gate needed, the picker covers any eligible
      // flavor including immediate_flavor/next_flavor.
      LI.append(el('button', {
        text: 'Add Flavor',
        classes: ['add-flavor'],
        attrs: { type: 'button' },
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
    // Disabled only for discrepancy: multiple open tubs already match, and
    // which one belongs here needs Confirm Cabinet to resolve, not a manual
    // pick. openTub present -> opens SWAP_MODAL; absent but not a
    // discrepancy (needs-tub/impossible) -> opens the free-form picker
    // instead (see the FRAME click handler above) — both cases are
    // clickable here.
    LI.append(el('button', {
      text: 'add next',
      classes: ['add-next'],
      attrs: row.discrepancy
        ? { type: 'button', disabled: true, title: 'Run Confirm Cabinet first to resolve which tub belongs here.' }
        : { type: 'button' },
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
