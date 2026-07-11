///////////////////////////////////
// CabinetWorkflowTile — card renderer for the "change tub" workflow (see
// change-tub.md). Paired 1:1 with CabinetWorkflowGridModel, which ships no
// columns (see that file's header comment) — so unlike Tile's normal
// column-driven buildFieldDom, every field of a slot's markup is built
// directly from the row object here, in buildItemDom. List still calls
// buildFieldDom once per (empty) fields array entry, so it's overridden
// defensively but never actually produces visible output.
//
// Phase 1 only (see change-tub.md "Build phases"): add-next/add-special/
// add-flavor buttons render with the right visibility, but are not wired to
// any click handler yet — that's Phase 2/3 and the not-yet-designed modal.
//////////////////////////////////
import Tile from "./tile.js";

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
    this.CONFIRM_CABINET.addEventListener('click', () => this._confirmCabinet());

    this.FRAME.append(this.CONFIRM_CABINET);
  }

  // Bootstraps slot.tubs links (see change-tub.md): every row whose
  // openTubStatus is 'linked' (computed in CabinetWorkflowGridModel —
  // exactly one Opened FOH tub of that slot's flavor at its own location)
  // AND isn't already linked to that same tub gets it written to
  // slot.tubs, via the existing 'Cabinet' write route (same pod/post_type
  // as this model — slot — just a different envelope key; no dedicated
  // route needed for a single-field write). 'none'/'multi' rows are never
  // auto-resolved here — buildItemDom already flags them on the LI every
  // render, straight from the row data — this handler only reports them.
  async _confirmCabinet() {
    if (!this.api) return;

    const cells = {};
    const toLink = [];
    const problems = [];

    for (const row of this.items ?? []) {
      if (row.empty) continue;

      if (row.openTubStatus === 'linked' && row.openTub) {
        if (row.openTub.id !== row.currentTubId) {
          cells[row.slotId] = { tubs: row.openTub.id };
          toLink.push(row);
        }
      } else if (row.openTubStatus === 'none') {
        problems.push(`${row.flavorTitle} (${row.cabinetTitle}): no Opened tub found`);
      } else if (row.openTubStatus === 'multi') {
        problems.push(`${row.flavorTitle} (${row.cabinetTitle}): ${row.openTubCount} Opened tubs found`);
      }
    }

    if (Object.keys(cells).length) {
      const r = await this.api.postJson({ cells }, 'Cabinet');
      if (!r.ok || !r.data?.ok) {
        alert(`Confirm Cabinet: saving failed.\n${r?.data?.error ?? `HTTP ${r?.status}`}`);
        return;
      }
      await this.api.refreshPageDomain({ force: true });
    }

    alert(this._confirmCabinetMessage(toLink, problems));
  }

  _confirmCabinetMessage(toLink, problems) {
    const lines = toLink.length
      ? [`Linked ${toLink.length} slot(s):`, ...toLink.map(row => `- ${row.flavorTitle} (${row.cabinetTitle})`)]
      : ['No changes needed.'];

    if (problems.length) {
      lines.push('', 'Slots needing attention:', ...problems.map(p => `- ${p}`));
    }

    return lines.join('\n');
  }

  buildItemDom(row) {
    const el = this.el;

    const statusClass = row.openTubStatus === 'none'  ? 'none-opened'
                       : row.openTubStatus === 'multi' ? 'multi-opened'
                       : null;

    const LI = el('li', {
      classes: ['slot', row.empty ? 'empty' : this._slug(row.flavorTitle), statusClass],
      data: { rowId: row.slotId ?? 0, slotId: row.slotId ?? 0 },
    });

    if (row.empty) {
      LI.append(el('button', {
        text: 'Add Flavor',
        classes: ['add-flavor'],
        attrs: { type: 'button' },
        data: { slotId: row.slotId },
      }));
      return LI;
    }

    LI.append(el('h3', { text: row.flavorTitle }));

    if (row.flavorPhoto) {
      LI.append(el('img', { attrs: { src: row.flavorPhoto, alt: row.flavorTitle } }));
    }

    LI.append(this._statLabel('tub-count-local', 'Local tubs', row.tubCountLocal));
    LI.append(this._statLabel('tub-count-total', 'Total tubs', row.tubCountTotal));

    // Omitted (not disabled) when there's no local FOH tub to advance to —
    // see change-tub.md's "add next" pool definition.
    if (row.canAddNext) {
      LI.append(el('button', {
        text: 'add next',
        classes: ['add-next'],
        attrs: { type: 'button' },
        data: { slotId: row.slotId },
      }));
    }

    LI.append(el('button', {
      text: 'add special',
      classes: ['add-special'],
      attrs: { type: 'button' },
      data: { slotId: row.slotId },
    }));

    return LI;
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
