///////////////////////////////////
// Filter input that narrows the .row elements shown in a List (Grid's tr.row
// or Tile's li.row — selected by class only, not tag, so this one
// implementation serves both renderers; see tile.js's buildItemDom comment
// on why they share class hooks). For a grouped list, a group with zero
// surviving rows hides itself too (its .group header + shared .groupBody
// container); a flat/ungrouped list has no .group headers to hide and this
// simply narrows individual rows.
//
// A row's match text is its own content PLUS its group's label (see
// getGroupLabelText) — FlavorTub groups rows by flavor and only shows the
// flavor name on the group header, not repeated per tub row underneath, so
// row-only matching found nothing for any flavor search and filtered every
// row away. CabinetWorkflow (each slot already shows its own flavor name)
// doesn't need the fallback but isn't hurt by it either.
//////////////////////////////////

export default class FindInList {
  constructor(host, {
    root = host,
    itemSelector = ".row",
    groupSelector = ".group",
    typeKey = "groupType",        // read off the shared .groupBody container
    defaultType = null,           // e.g. if only one type present
    placeholder = "Filter…",
  } = {}) {
    this.host = host;
    this.root = root;
    this.itemSelector = itemSelector;
    this.groupSelector = groupSelector;
    this.typeKey = typeKey;
    this.defaultType = defaultType;
    this.placeholder = placeholder;

    this.render();
    this.bind();
  }

  items() {
    return [...this.root.querySelectorAll(this.itemSelector)];
  }

  groups() {
    return [...this.root.querySelectorAll(this.groupSelector)];
  }

  // .groupBody is the one ancestor shared by both renderers' group shapes —
  // Grid keeps tr.group/tr.row as direct tbody.groupBody siblings; Tile
  // nests its li.row items one level deeper, inside a <ul> that's itself a
  // sibling of div.group inside section.groupBody (see grid.js/tile.js
  // buildGroupDom). closest() finds either shape the same way. A flat/
  // ungrouped list still has a synthetic groupBody wrapper (no .group header
  // inside it) — see buildGroupDom's "group.label falsy" case.
  containerFor(el) {
    return el.closest('.groupBody');
  }

  getType(container) {
    return (container?.dataset?.[this.typeKey] ?? "").toString();
  }

  // textContent, not innerText — innerText is render-dependent and reads as
  // "" for an element apply() itself already hid on a previous keystroke, so
  // narrowing "cook" then backspacing to "co" would find nothing to re-show.
  getItemText(el) {
    return (el?.textContent ?? "").toString();
  }

  // A row's own text is enough for CabinetWorkflow (each slot's card already
  // shows its flavor name), but FlavorTub groups rows BY flavor and doesn't
  // repeat the flavor name on every tub row underneath — only the group
  // header carries it (.group's data-group-label, set in grid.js/tile.js's
  // buildGroupDom). Without folding that in, typing a flavor name matched
  // zero rows in every group and the list filtered down to nothing. Empty
  // string for a row whose container has no .group header at all (a flat/
  // ungrouped list, or the item argument passed straight in) — harmless, it
  // just contributes nothing to the row's own haystack.
  getGroupLabelText(container) {
    const header = container?.querySelector?.(`:scope > ${this.groupSelector}`);
    return (header?.dataset?.groupLabel ?? "").toString();
  }

  inferSingleType() {
    const types = new Set(this.groups().map(g => this.getType(this.containerFor(g))).filter(Boolean));
    return types.size === 1 ? [...types][0] : null;
  }

  parseQuery(q) {
    const s = (q ?? "").trim().toLowerCase();
    const m = s.match(/^([a-z0-9_]+)\s*:\s*(.*)$/);
    if (m) return { type: m[1], term: (m[2] ?? "").trim() };
    return { type: null, term: s };
  }

  normSearch(s) {
    return String(s ?? "")
      .normalize("NFKC")
      .toLowerCase()
      .replace(/[‘’‛′]/g, "'")
      .trim();
  }

  // Per-item text match narrows individual rows (a CabinetWorkflow slot, a
  // Grid tr, ...) rather than all-or-nothing on whichever group they live
  // in — a cabinet with 8 slots and 1 matching flavor now shows that 1 slot,
  // not all 8 or none. Groups left with zero surviving rows hide too, so an
  // empty cabinet header doesn't linger on screen.
  apply(q) {
    const { type, term } = this.parseQuery(q);
    const impliedType = type ?? this.defaultType ?? this.inferSingleType();
    const items = this.items();
    const visibleCountByContainer = new Map();

    for (const item of items) {
      const container = this.containerFor(item);
      const tOk = !impliedType || !container || this.getType(container) === impliedType;
      const hay = this.normSearch(`${this.getGroupLabelText(container)} ${this.getItemText(item)}`);
      const hit = !term || hay.includes(term);
      const visible = tOk ? hit : true;

      item.hidden = !visible;
      if (visible && container) {
        visibleCountByContainer.set(container, (visibleCountByContainer.get(container) ?? 0) + 1);
      }
    }

    const groups = this.groups();
    for (const group of groups) {
      const container = this.containerFor(group);
      const count = container ? (visibleCountByContainer.get(container) ?? 0) : 0;
      this.setVisible(group, count > 0);
    }

    // QoL: when the filter narrows to exactly one matching group, open it so
    // its surviving rows show without an extra click. Deliberately NOT "any
    // matching group" — a manual click on a group's own .oc toggle
    // (_showHide in _list.js) re-applies the active filter afterward
    // (_flushGroup -> _reapplyActiveFilter, so a freshly-opened group's rows
    // get caught up), and forcing every match open on that same pass fought
    // the click that had just closed it, making toggling read as broken
    // while a term was active. Only ever force-CLOSES a group this filter
    // itself force-opened (the autoOpened marker) — a group the user opened
    // or closed by hand never carries that marker, so a manual toggle is
    // never fought either way, matching pre-item-level-filtering behavior.
    const matchingGroups = groups.filter(group => {
      const container = this.containerFor(group);
      return !!container && (visibleCountByContainer.get(container) ?? 0) > 0;
    });

    if (term && matchingGroups.length === 1) {
      this.setGroupOpen(matchingGroups[0], true);
      matchingGroups[0].dataset.autoOpened = "1";
    } else {
      for (const group of groups) {
        if (group.dataset.autoOpened) {
          this.setGroupOpen(group, false);
          delete group.dataset.autoOpened;
        }
      }
    }
  }

  // Empties the query and re-applies it (i.e. shows everything again) —
  // setting .value alone doesn't fire 'input', so the visible filtered state
  // would otherwise be left stale/mismatched with the now-empty box. Used
  // when focus is programmatically moved here (e.g. after a save) rather
  // than by the user actually clearing it themselves.
  clear() {
    this.inp.value = "";
    this.apply("");
  }

  setVisible(el, visible) {
    const p = el.parentElement;
    if (p?.matches?.('.collapsible')) p.hidden = !visible;
    el.hidden = !visible;
  }

  setGroupOpen(groupRow, open) {
    const tb = groupRow?.parentElement;
    if (!tb || !tb.matches?.(".collapsible")) return;
    tb.classList.toggle("opened", open);
    tb.classList.toggle("closed", !open);
  }

  render() {
    const inp = document.createElement("input");
    inp.type = "text";
    inp.autocomplete = "off";
    inp.placeholder = this.placeholder;
    inp.classList.add("gridFilterInput");
    this.host.prepend(inp);
    this.inp = inp;
  }

  bind() {
    this.inp.addEventListener("input", () => this.apply(this.inp.value));
  }
}
