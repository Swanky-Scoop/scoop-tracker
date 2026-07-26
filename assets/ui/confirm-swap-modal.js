///////////////////////////////////
// ConfirmSwapModal — the "add next" confirmation dialog for CabinetWorkflow
// (see change-tub.md's "Add next confirmation modal" section; DOM shape
// modeled on assets/emptyAdd.html). One instance per CabinetWorkflowTile,
// built once in buildCoreDom() and reused/repopulated on every 'add-next'
// click — not a page-wide singleton like Details.js, since only this one
// view needs it.
//
// State it tracks between open() and a button click: which flavor is
// currently proposed as the replacement (starts at the slot's own
// current_flavor, can switch to immediate_flavor/next_flavor via their
// links) and the whole-vs-partial preference (the checkbox). Both feed
// CabinetWorkflowGridModel.pickPromotableTub() to decide the one tub any
// of the three action buttons would actually act on.
//////////////////////////////////
import El from "./_el.js";

export default class ConfirmSwapModal extends El {
  constructor({ api, model }) {
    super();
    this.api = api;
    this.model = model;

    this._row = null;
    this._selectedFlavorId = 0;
    this._preferWhole = true;

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
      alert("Change Plan isn't built yet — pick from the options above, or close and use Add Special.");
    });

    this.BTN_GROUP = el('p', { classes: ['btn_group'] });
    this.BTN_GROUP.append(this.CONFIRM_BTN, this.OTHER_BTN);

    this.EMPTY_BTN = el('button', { text: 'Click here to leave slot empty.', classes: ['empty'], attrs: { type: 'button' } });
    this.EMPTY_BTN.addEventListener('click', () => this._confirmEmpty());
    const EMPTY_P = el('p', { classes: ['none'] });
    EMPTY_P.append(this.EMPTY_BTN);

    this.FORM.append(
      this.CLOSE,
      el('p', { text: 'Replace flavor' }),
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
    );
    this.ROOT.append(this.FORM);
    document.body.append(this.ROOT);
  }

  open(row) {
    this._row = row;
    this._selectedFlavorId = this._defaultFlavorId(row);
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

    this.REMOVE_TITLE.textContent = row.flavorTitle;

    const target = this.model.flavorInfo(this._selectedFlavorId);
    const tub = this.model.pickPromotableTub(this._selectedFlavorId, this._preferWhole);

    this.IMG.src = target.photo || '';
    this.IMG.alt = target.title;
    this.IMG_TITLE.textContent = target.title;
    this.IMG_META.textContent = tub ? this._tubMetaText(tub) : 'No tub available for this flavor.';

    this.CONFIRM_BTN.disabled = !tub;

    this._renderFlavorLine(this.CURRENT_P, '', row.flavorId, row.flavorTitle, row.location, false);
    this._renderFlavorLine(this.IMMEDIATE_P, 'Next scheduled flavor is', row.immediateFlavorId, row.immediateFlavorTitle, row.location, true);
    this._renderFlavorLine(this.NEXT_P, 'Then scheduled flavor is', row.nextFlavorId, row.nextFlavorTitle, row.location, true);

    // reload === false: planned rotation takes priority over current_flavor
    // in both the default target (_defaultFlavorId) and here, the display
    // order — immediate, then next, then current last.
    const order = row.reload === false
      ? [this.IMMEDIATE_P, this.NEXT_P, this.CURRENT_P]
      : [this.CURRENT_P, this.IMMEDIATE_P, this.NEXT_P];
    order.forEach(p => this.FORM.insertBefore(p, this.BTN_GROUP));
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
  // already what's shown unless one of the links has been clicked.
  _renderFlavorLine(P, label, flavorId, flavorTitle, locationId, linkable) {
    P.replaceChildren();

    if (!flavorId) {
      P.append(`${label} none planned.`);
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

    P.append(' has ', this.el('b', { text: String(total) }), ' remaining tubs, ', this.el('b', { text: String(here) }), ' are here.');
  }

  async _confirm() {
    const row = this._row;
    const tub = this.model.pickPromotableTub(this._selectedFlavorId, this._preferWhole);
    if (!row || !tub) return;

    // tub.slot is the bidirectional sister field to slot.tub (see
    // change-tub.md) — writing it here is what links the new tub to this
    // slot; slot.tub is never written directly, Pods syncs it. location is
    // corrected to this cabinet's regardless of where the tub came from —
    // location doesn't restrict eligibility (same rule as Confirm Cabinet/
    // Add Flavor), so a cross-location tub can get picked here.
    const tubCells = { [tub.id]: { state: 'Opened', slot: row.slotId, location: row.location } };

    if (row.openTub?.id) {
      // Checked "This box is not empty": the old tub still has product in
      // it — this isn't a stock event, just unlink it and leave it Opened.
      // Unchecked (default): mark it Emptied+stamped as usual. Either way
      // slot: 0 clears the bidirectional link from the tub side — slot.tub
      // is never written directly (see change-tub.md).
      tubCells[row.openTub.id] = this.NOT_EMPTY_CHECK.checked
        ? { slot: 0 }
        : { state: 'Emptied', slot: 0 };
    }

    const rTubs = await this.api.postJson({ cells: tubCells }, 'FlavorTub');
    if (!rTubs.ok || !rTubs.data?.ok) {
      alert(`Tub swap failed.\n${rTubs?.data?.error ?? `HTTP ${rTubs?.status}`}`);
      return;
    }

    const rSlot = await this.api.postJson(
      { cells: { [row.slotId]: { current_flavor: this._selectedFlavorId } } },
      'Cabinet',
    );
    if (!rSlot.ok || !rSlot.data?.ok) {
      alert(`Tubs were swapped, but the slot's flavor failed to save.\n${rSlot?.data?.error ?? `HTTP ${rSlot?.status}`}`);
      return;
    }

    await this.api.refreshPageDomain({ force: true });
    this.close();
  }

  // Clears the slot, but leftover stock of the flavor being removed isn't
  // just dropped — if there's still at least one tub of it (remaining,
  // local to this slot), it gets rescheduled into whichever of
  // immediate_flavor/next_flavor is open. Only truly unscheduled (neither
  // written) if both planning fields are already taken by something else.
  async _confirmEmpty() {
    const row = this._row;
    if (!row) return;

    if (row.openTub?.id) {
      // slot: 0 clears the bidirectional link from the tub side — slot.tub
      // is never written directly (see change-tub.md).
      const rTub = await this.api.postJson({ cells: { [row.openTub.id]: { state: 'Emptied', slot: 0 } } }, 'FlavorTub');
      if (!rTub.ok || !rTub.data?.ok) {
        alert(`Emptying the tub failed.\n${rTub?.data?.error ?? `HTTP ${rTub?.status}`}`);
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

    const rSlot = await this.api.postJson({ cells: { [row.slotId]: slotCells } }, 'Cabinet');
    if (!rSlot.ok || !rSlot.data?.ok) {
      alert(`Tub emptied, but clearing the slot failed.\n${rSlot?.data?.error ?? `HTTP ${rSlot?.status}`}`);
      return;
    }

    await this.api.refreshPageDomain({ force: true });
    this.close();
  }
}
