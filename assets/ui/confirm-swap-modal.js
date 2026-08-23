///////////////////////////////////
// ConfirmSwapModal — the "resolve a tub for this slot" confirmation dialog
// for CabinetWorkflow (see change-tub.md's "Add next confirmation modal"
// section; DOM shape modeled on assets/emptyAdd.html). One instance per
// CabinetWorkflowTile, built once in buildCoreDom() and reused/repopulated
// on every open() — not a page-wide singleton like Details.js, since only
// this one view needs it.
//
// Two entry points, same dialog: open(row) from an already-paired slot's
// 'add-next' (defaults to the slot's own current_flavor, switchable to
// immediate_flavor/next_flavor via their links) — or open(row, flavorId)
// from FlavorPickerModal, handing off a freshly-picked flavor with no tub
// behind it yet. Either way, this dialog owns the actual tub search/write;
// FlavorPickerModal itself writes nothing (see its own header comment).
//
// A third, smaller use of the picker lives here too: when immediate_flavor
// or next_flavor is unset, its line reads "none planned" as a link — click
// it and the SAME FlavorPickerModal instance opens again (via
// openPickerFor), but this time picking a flavor writes straight to that
// slot field (_pickScheduled) instead of proposing a tub-swap target. No
// tub gets touched; once the write lands, this dialog reopens itself
// (via getRow, since the row object captured at open() time goes stale the
// moment refreshPageDomain rebuilds the tile's rows) with the same
// tub-swap target it had before the detour.
//
// State it tracks between open() and a button click: which flavor is
// currently proposed (this._selectedFlavorId) and the whole-vs-partial
// preference (the checkbox, this._preferWhole — user-dictated, the only
// place amount still factors into tub selection). Both feed
// CabinetWorkflowGridModel.planTubChange() to decide the one tub any of the
// three action buttons would actually act on; the result is cached as
// this._plan for _confirm()/_confirmEmpty() to reuse without recomputing.
//////////////////////////////////
import El from "./_el.js";
import Toast from "./toast.js";
import { buildSplitTubControl } from "./_tub-split-control.js";

export default class ConfirmSwapModal extends El {
  constructor({ api, model, onChangePlan, openPickerFor, getRow, paintOptimistic, confirmOptimistic }) {
    super();
    this.api = api;
    this.model = model;
    this.onChangePlan = onChangePlan;
    this.openPickerFor = openPickerFor;
    this.getRow = getRow;
    this.paintOptimistic = paintOptimistic;
    this.confirmOptimistic = confirmOptimistic;

    this._row = null;
    this._selectedFlavorId = 0;
    this._preferWhole = true;
    this._plan = null;

    this._buildDom();

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && this._row) this.close();
    });
  }

  _buildDom() {
    const el = this.el;

    this.ROOT = el('div', { classes: ['modal', 'confirm_swap'] });
    this.FORM = el('form');

    this.CLOSE = el('button', { text: 'X', classes: ['close'], attrs: { type: 'button' } });
    this.CLOSE.addEventListener('click', () => this.close());

    this.REMOVE_TITLE = el('h2');

    this.IMG_BOX   = el('div', { classes: ['img'] });
    this.IMG       = el('img', { attrs: { alt: '' } });
    this.IMG_TITLE = el('h3');
    this.IMG_META  = el('p');
    this.IMG_BOX.append(this.IMG, this.IMG_TITLE, this.IMG_META);

    this.PARTIAL_CHECK = el('input', { attrs: { type: 'checkbox', id: 'partial' } });
    this.PARTIAL_CHECK.checked = true;
    this.PARTIAL_CHECK.addEventListener('change', () => {
      this._preferWhole = this.PARTIAL_CHECK.checked;
      this._render();
    });
    const PARTIAL_LABEL = el('label');
    PARTIAL_LABEL.append(this.PARTIAL_CHECK, ' Use full tubs before partial tubs.');

    // Unchecked by default: the old tub gets marked Emptied+stamped as
    // usual. Checked: it's being pulled with product still in it (not a
    // stock event) — stays Opened, just unlinked from this slot (see
    // _confirm()).
    this.NOT_EMPTY_CHECK = el('input', { attrs: { type: 'checkbox', id: 'not-empty' } });
    this.NOT_EMPTY_CHECK.checked = false;
    const NOT_EMPTY_LABEL = el('label');
    NOT_EMPTY_LABEL.append(this.NOT_EMPTY_CHECK, ' This box is not empty.');

    this.CURRENT_P   = el('p', { classes: ['current'] });
    this.IMMEDIATE_P = el('p', { classes: ['next', 'immediate'] });
    this.NEXT_P      = el('p', { classes: ['next', 'planned'] });

    this.CONFIRM_BTN = el('button', { text: 'Confirm Swap', classes: ['confirm'], attrs: { type: 'button' } });
    this.CONFIRM_BTN.addEventListener('click', () => this._confirm());

    this.OTHER_BTN = el('button', { text: 'Change Plan', classes: ['other'], attrs: { type: 'button' } });
    this.OTHER_BTN.addEventListener('click', () => {
      const row = this._row;
      this.close();
      if (row && this.onChangePlan) this.onChangePlan(row);
    });

    this.BTN_GROUP = el('p', { classes: ['btn_group'] });
    this.BTN_GROUP.append(this.CONFIRM_BTN, this.OTHER_BTN);

    this.EMPTY_BTN = el('button', { text: 'Click here to leave slot empty.', classes: ['empty'], attrs: { type: 'button' } });
    this.EMPTY_BTN.addEventListener('click', () => this._confirmEmpty());
    const EMPTY_P = el('p', { classes: ['none'] });
    EMPTY_P.append(this.EMPTY_BTN);

    this.REPLACE_LABEL = el('p', { text: 'Replace flavor' });

    // Rebuilt fresh on every _render() (see there) — it acts on
    // this._plan.outgoingTub, which changes per row/open, same reasoning
    // as the flavor lines above being repositioned each render rather than
    // built once. Empty (no outgoing tub, e.g. filling a previously-empty
    // slot) until _render() has something to put in it.
    this.SPLIT_HOST = el('div', { classes: ['split-tub-host'] });

    this.FORM.append(
      this.CLOSE,
      this.REPLACE_LABEL,
      this.REMOVE_TITLE,
      el('p', { text: 'with' }),
      this.IMG_BOX,
      PARTIAL_LABEL,
      NOT_EMPTY_LABEL,
      this.CURRENT_P,
      this.IMMEDIATE_P,
      this.NEXT_P,
      this.BTN_GROUP,
      EMPTY_P,
      this.SPLIT_HOST,
    );
    this.ROOT.append(this.FORM);
    document.body.append(this.ROOT);
  }

  // flavorId: explicit target from FlavorPickerModal. Omitted (the classic
  // 'add-next' entry) falls back to _defaultFlavorId's current/immediate/
  // next chain.
  open(row, flavorId = null) {
    this._row = row;
    this._selectedFlavorId = flavorId || this._defaultFlavorId(row);
    this._preferWhole = true;
    this.PARTIAL_CHECK.checked = true;
    this.NOT_EMPTY_CHECK.checked = false;
    this._render();
    this.ROOT.classList.add('show');
  }

  // reload === false ("don't reload the current flavor") overrides the
  // target outright, regardless of current_flavor's own stock — this slot
  // is meant to move on to the planned rotation. Otherwise, current_flavor
  // is the default target unless it has nothing left to promote, in which
  // case fall through the same immediate_flavor -> next_flavor chain
  // (their own availability isn't re-checked, only current_flavor's is).
  _defaultFlavorId(row) {
    if (row.reload === false) return row.immediateFlavorId || row.nextFlavorId || row.flavorId;
    if (this.model.promotablePool(row.flavorId).length) return row.flavorId;
    return row.immediateFlavorId || row.nextFlavorId || row.flavorId;
  }

  close() {
    this.ROOT.classList.remove('show');
    this._row = null;
  }

  _render() {
    const row = this._row;
    if (!row) return;

    // row.empty (opened via "Add Flavor" on an empty slot, not "add next")
    // has no current flavor to name as being replaced.
    this.REPLACE_LABEL.textContent = row.empty ? 'Add flavor' : 'Replace flavor';
    this.REMOVE_TITLE.textContent = row.empty ? '(slot is empty)' : row.flavorTitle;

    const target = this.model.flavorInfo(this._selectedFlavorId);
    this._plan = this.model.planTubChange(row, this._selectedFlavorId, this._preferWhole);
    const tub = this._plan.tub;

    // target.photo can be '' (no photo set) — assigning that to img.src
    // resolves to the *current page's own URL*, which makes the browser
    // re-fetch the whole document as an "image" (a well-known <img> gotcha).
    // That stray full-page GET is what looked like "a page refresh" when
    // picking a flavor with no photo. removeAttribute avoids issuing it.
    if (target.photo) this.IMG.src = target.photo;
    else this.IMG.removeAttribute('src');
    this.IMG.alt = target.title;
    this.IMG_TITLE.textContent = target.title;
    this.IMG_META.textContent = tub ? this._tubMetaText(tub) : 'No tub available for this flavor.';

    this.CONFIRM_BTN.disabled = !tub;

    // The currently PROPOSED target (this._selectedFlavorId/target), not
    // row.flavorId — this line sits right under the photo/title box, which
    // already shows the target, not the slot's old flavor (that's
    // REMOVE_TITLE's job, above). Was previously hardcoded to row.flavorId,
    // which only looked right by coincidence when the target happened to
    // equal the slot's own current flavor.
    this._renderFlavorLine(this.CURRENT_P, '', this._selectedFlavorId, target.title, row.location, false);
    this._renderFlavorLine(this.IMMEDIATE_P, 'Next-up,', row.immediateFlavorId, row.immediateFlavorTitle, row.location, true, 'immediate_flavor');
    this._renderFlavorLine(this.NEXT_P, 'After that,', row.nextFlavorId, row.nextFlavorTitle, row.location, true, 'next_flavor');

    // reload === false: planned rotation takes priority over current_flavor
    // in both the default target (_defaultFlavorId) and here, the display
    // order — immediate, then next, then current last.
    const order = row.reload === false
      ? [this.IMMEDIATE_P, this.NEXT_P, this.CURRENT_P]
      : [this.CURRENT_P, this.IMMEDIATE_P, this.NEXT_P];
    order.forEach(p => this.FORM.insertBefore(p, this.BTN_GROUP));

    // Acts on the OUTGOING tub (the one physically in this slot right now,
    // about to be swapped out) — not plan.tub, the incoming one being
    // proposed. Nothing to split if there's no outgoing tub at all (e.g.
    // filling a previously-empty slot).
    this.SPLIT_HOST.replaceChildren();
    if (this._plan.outgoingTub) {
      this.SPLIT_HOST.append(
        buildSplitTubControl(this._plan.outgoingTub, this.api, { onSplit: () => this._afterSplit() })
      );
    }
  }

  // Same reopen-with-fresh-row pattern as _pickScheduled below — the split
  // already refreshed the domain (see _tub-split-control.js) by the time
  // this runs, so getRow() picks up whatever changed (the outgoing tub's
  // reduced amount, or its new use/state if it was converted in place).
  _afterSplit() {
    const row = this._row;
    if (!row) return;

    const freshRow = this.getRow?.(row.slotId) ?? row;
    this.open(freshRow, this._selectedFlavorId);
  }

  _tubMetaText(tub) {
    const created = tub.created_on ? new Date(tub.created_on) : null;
    const dateStr = created && !Number.isNaN(created.getTime())
      ? created.toLocaleString('en-US', { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' })
      : 'unknown date';
    const batchCount = this.model.tubBatchCount(tub) ?? '?';
    return `${dateStr}_${batchCount}|${tub.index ?? '?'}`;
  }

  // linkable flavors (immediate_flavor/next_flavor) switch the proposed
  // target on click; the current flavor's own line is plain text — it's
  // already what's shown unless one of the links has been clicked. field
  // ('immediate_flavor'/'next_flavor', undefined for the current-target
  // line) makes an empty slot's "none planned" itself a link too — opens
  // the picker to schedule that field directly (see _openPickerForField).
  _renderFlavorLine(P, label, flavorId, flavorTitle, locationId, linkable, field) {
    P.replaceChildren();

    if (!flavorId) {
      if (field) {
        const LINK = this.el('a', { text: 'none planned.', attrs: { href: '#schedule_flavor' } });
        LINK.addEventListener('click', (e) => {
          e.preventDefault();
          this._openPickerForField(field);
        });
        P.append(`${label} `, LINK);
      } else {
        P.append(`${label} none planned.`);
      }
      return;
    }

    const here  = this.model.remainingSummary(flavorId, locationId);
    const total = this.model.remainingSummary(flavorId, null);

    P.append(`${label} `);

    if (linkable) {
      const LINK = this.el('a', { text: flavorTitle, attrs: { href: '#confirm_other_tub_flavor' } });
      LINK.addEventListener('click', (e) => {
        e.preventDefault();
        this._selectedFlavorId = flavorId;
        this._render();
      });
      P.append(LINK);
    } else {
      P.append(flavorTitle);
    }

    P.append(' has ', this.el('b', { text: String(total) }), ' and ', this.el('b', { text: String(here) }), ' here.');
  }

  // "none planned" clicked for immediate_flavor/next_flavor — closes this
  // dialog and reopens the shared FlavorPickerModal, but overrides its
  // onPick for this one visit: picking a flavor there schedules it onto
  // `field` (see _pickScheduled) instead of proposing a tub-swap target.
  // this._selectedFlavorId is preserved across the detour (captured here,
  // threaded through) so the dialog looks the same when it reopens, just
  // with the scheduled field now filled in.
  _openPickerForField(field) {
    const row = this._row;
    if (!row || !this.openPickerFor) return;

    const resumeFlavorId = this._selectedFlavorId;
    this.close();
    this.openPickerFor(row, (_row, flavorId) => this._pickScheduled(row, field, flavorId, resumeFlavorId));
  }

  // No tub involved — just writes `field` on the slot directly, then
  // reopens this dialog. row is the one captured at _openPickerForField
  // time; it's stale after refreshPageDomain rebuilds the tile's rows, so
  // getRow() re-fetches the current one for the reopen (row itself is
  // still a safe fallback — same slotId either way).
  async _pickScheduled(row, field, flavorId, resumeFlavorId) {
    const rSlot = await this.api.postJson({ cells: { [row.slotId]: { [field]: flavorId } }, source: 'workflow' }, 'Cabinet');
    if (!rSlot.ok || !rSlot.data?.ok) {
      Toast.addMessage({ title: 'Scheduling the flavor failed', message: rSlot?.data?.error ?? `HTTP ${rSlot?.status}` });
      return;
    }

    await this.api.refreshPageDomain({ force: true });

    const freshRow = this.getRow?.(row.slotId) ?? row;
    this.open(freshRow, resumeFlavorId);
  }

  async _confirm() {
    const row = this._row;
    const plan = this._plan;
    if (!row || !plan?.tub) return;

    // "Staff gave up looking for the old flavor and moved on" detection —
    // row.flavorId (the slot's actual current flavor) differs from what's
    // about to be confirmed. If that old flavor still has tubs sitting in
    // the pipeline at this location (remainingTubs — same eligibility as
    // remainingSummary; Opened is already excluded, so this never includes
    // plan.outgoingTub itself), they probably couldn't be found — offer to
    // mark them !Lost now instead of letting them sit as phantom available
    // stock forever. See OTHER-USES.md.
    const flavorChanged = !row.empty && row.flavorId > 0 && row.flavorId !== this._selectedFlavorId;
    const staleTubs = flavorChanged ? this.model.remainingTubs(row.flavorId, row.location) : [];

    let markLost = [];
    if (staleTubs.length) {
      const oldTitle = row.flavorTitle || 'the previous flavor';
      const newTitle = this.model.flavorInfo(this._selectedFlavorId).title || 'the new flavor';
      const n = staleTubs.length;
      const confirmed = window.confirm(
        `Switching from ${oldTitle} to ${newTitle} — but ${n} tub${n === 1 ? '' : 's'} of ${oldTitle} `
        + `still show${n === 1 ? 's' : ''} as remaining here. This usually means they can't be found `
        + `in the freezer; left alone, they'll keep counting as available stock indefinitely.\n\n`
        + `Empty the current tub and mark ${n === 1 ? 'that tub' : `those ${n} tubs`} of ${oldTitle} as lost?`
      );
      if (confirmed) markLost = staleTubs;
    }

    // tub.slot is the bidirectional sister field to slot.tub (see
    // change-tub.md) — writing it here is what links the new tub to this
    // slot; slot.tub is never written directly, Pods syncs it. location is
    // corrected to this cabinet's regardless of where the tub came from —
    // location doesn't restrict eligibility, so a cross-location tub can
    // get picked here (see the location-mismatch alert below).
    const tubCells = { [plan.tub.id]: { state: 'Opened', slot: row.slotId, location: row.location } };

    // outgoingTub is row.currentTubId resolved regardless of its state —
    // "any existing tub should be removed" (uniform logic, no same-flavor
    // special case; see CabinetWorkflow QA conversation). Only actually
    // marked Emptied+stamped if it was genuinely Opened AND "This box is
    // not empty" isn't checked (product still in it — not a stock event,
    // just unlink it, stays Opened) — a stale link to a tub that was never
    // actually opened here (e.g. still Hardening) just gets unlinked, not
    // misrepresented as emptied.
    const emptying = plan.outgoingTub?.state === 'Opened' && !this.NOT_EMPTY_CHECK.checked;
    if (plan.outgoingTub) {
      tubCells[plan.outgoingTub.id] = emptying ? { state: 'Emptied', slot: 0 } : { slot: 0 };
    }

    // Confirmed "mark as lost" tubs ride the same write as the swap itself
    // — one request, one inventory_change entry. None of these are ever
    // Opened (remainingTubs excludes it, same as remainingSummary), and
    // pods_api_pre_save_pod_item_tub (tub-state.php) only blocks an
    // Opened tub from jumping straight to !Lost — every other state can,
    // so this is always a clean transition.
    for (const staleTub of markLost) {
      tubCells[staleTub.id] = { state: '!Lost' };
    }

    const rTubs = await this.api.postJson({ cells: tubCells, source: 'workflow' }, 'FlavorTub');
    if (!rTubs.ok || !rTubs.data?.ok) {
      Toast.addMessage({ title: 'Tub swap failed', message: rTubs?.data?.error ?? `HTTP ${rTubs?.status}` });
      return;
    }

    const rSlot = await this.api.postJson(
      { cells: { [row.slotId]: { current_flavor: this._selectedFlavorId } }, source: 'workflow' },
      'Cabinet',
    );
    if (!rSlot.ok || !rSlot.data?.ok) {
      Toast.addMessage({
        title: "Slot flavor didn't save",
        message: `Tubs were swapped, but the slot's flavor failed to save.\n${rSlot?.data?.error ?? `HTTP ${rSlot?.status}`}`,
      });
      return;
    }

    // Close before the refetch, not after: closing only after
    // refreshPageDomain resolves left the dialog stuck open (looking
    // "unresponsive") for however long the bundle refetch took, and stuck
    // open for good if that refetch ever threw.
    this.close();

    // Optimistic repaint (see CabinetWorkflowTile._paintOptimistic) — shows
    // the outcome on the card immediately, marked 'confirming', rather than
    // waiting out the confirmation refetch below (can take 10+ seconds).
    const target = this.model.flavorInfo(this._selectedFlavorId);
    this.paintOptimistic?.(row.slotId, {
      empty: false,
      flavorId: this._selectedFlavorId,
      flavorTitle: target.title,
      flavorPhoto: target.photo,
      allergens: target.allergens,
      openTub: { ...plan.tub, state: 'Opened', slot: row.slotId, location: row.location },
      currentTubId: plan.tub.id,
      discrepancy: false,
      impossible: false,
    });

    // One consolidated, non-blocking Toast instead of the former sequential
    // native alert() cascade (see change-tub.md's "Debug alert cascade" —
    // that was deliberately crude scaffolding, not final UX, and blocked
    // the confirmation refetch below on the user dismissing every dialog).
    // Toast's `changes` list (see Toast.addMessage) is the "reviewable
    // change log" that section explicitly deferred building.
    const swapNotes = [];
    if (markLost.length) {
      swapNotes.push({ sentence: `Marked ${markLost.length} tub${markLost.length === 1 ? '' : 's'} of ${row.flavorTitle} as lost.` });
    }
    if (emptying) swapNotes.push({ sentence: `${plan.outgoingTub._title} was emptied` });
    if (Number(plan.tub.location) !== Number(row.location)) swapNotes.push({ sentence: `${plan.tub._title} is at a different location.` });
    if (Number(plan.tub.amount ?? 1) < 1) swapNotes.push({ sentence: 'Using a partial' });
    if (plan.rule === 'a') {
      if (plan.pool.length === 1) {
        swapNotes.push({ sentence: 'Perfect match — one candidate.' });
      } else {
        const skipped = plan.pool.filter(t => Number(t.id) !== Number(plan.tub.id)).map(t => t._title).join(', ');
        swapNotes.push({ sentence: `Found tub ${plan.tub._title}. Not selected: ${skipped}` });
      }
    } else {
      swapNotes.push({ sentence: `Found match: ${plan.tub._title}` });
    }
    Toast.addMessage({ title: 'Tub swap confirmed', changes: swapNotes });

    // The "confirmation fetch" — refreshPageDomain's broadcast also repaints
    // every other grid on the page (e.g. a plain Cabinet view control, if
    // one's mounted) via the existing ts:domain:updated mechanism (see
    // PARTIAL-REFRESH.md), with no extra code needed here. confirmOptimistic
    // then diffs the real result against what was just painted above.
    await this.api.refreshPageDomain({ force: true });
    this.confirmOptimistic?.(row.slotId, { current_flavor: this._selectedFlavorId, tub: plan.tub.id });
  }

  // Clears the slot, but leftover stock of the flavor being removed isn't
  // just dropped — if there's still at least one tub of it (remaining,
  // local to this slot), it gets rescheduled into whichever of
  // immediate_flavor/next_flavor is open. Only truly unscheduled (neither
  // written) if both planning fields are already taken by something else.
  async _confirmEmpty() {
    const row = this._row;
    if (!row) return;

    // this._plan.outgoingTub (not row.openTub) — same "any existing tub
    // should be removed" uniform handling as _confirm(): a stale link to a
    // tub that was never actually Opened here just gets unlinked, not
    // misrepresented as emptied.
    const outgoingTub = this._plan?.outgoingTub ?? null;
    if (outgoingTub) {
      // slot: 0 clears the bidirectional link from the tub side — slot.tub
      // is never written directly (see change-tub.md).
      const cells = outgoingTub.state === 'Opened' ? { state: 'Emptied', slot: 0 } : { slot: 0 };
      const rTub = await this.api.postJson({ cells: { [outgoingTub.id]: cells }, source: 'workflow' }, 'FlavorTub');
      if (!rTub.ok || !rTub.data?.ok) {
        Toast.addMessage({ title: 'Emptying the tub failed', message: rTub?.data?.error ?? `HTTP ${rTub?.status}` });
        return;
      }
    }

    const slotCells = { current_flavor: 0 };

    if (this.model.remainingSummary(row.flavorId, row.location) > 0) {
      if (!row.immediateFlavorId) slotCells.immediate_flavor = row.flavorId;
      else if (!row.nextFlavorId) slotCells.next_flavor = row.flavorId;
      // else: both planning fields already taken — this flavor drops out
      // of this slot's plan entirely, nothing forced in.
    }

    // { cells: { [row.slotId]: slotCells } } — not the bare
    // { cells: slotCells } this line previously had (a real, pre-existing
    // bug found while adding the source hint below: scoop_handle_cells_post
    // expects `cells` keyed by post ID, so the unwrapped shape meant this
    // write silently touched nothing every time "leave slot empty" ran —
    // current_flavor never actually cleared).
    const rSlot = await this.api.postJson({ cells: { [row.slotId]: slotCells }, source: 'workflow' }, 'Cabinet');
    if (!rSlot.ok || !rSlot.data?.ok) {
      Toast.addMessage({
        title: "Slot didn't clear",
        message: `Tub emptied, but clearing the slot failed.\n${rSlot?.data?.error ?? `HTTP ${rSlot?.status}`}`,
      });
      return;
    }

    this.close();

    this.paintOptimistic?.(row.slotId, {
      empty: true,
      flavorId: 0,
      flavorTitle: '',
      flavorPhoto: '',
      allergens: [],
      openTub: null,
      currentTubId: 0,
    });

    await this.api.refreshPageDomain({ force: true });
    this.confirmOptimistic?.(row.slotId, { current_flavor: 0, tub: 0 });
  }
}
