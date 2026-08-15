///////////////////////////////////
// Filter input that narrows the .group elements shown in a List (Grid's
// tr.group or Tile's div.group — selected by class only, not tag, so this
// one implementation serves both renderers; see tile.js's buildGroupDom
// comment on why they share class hooks). Falls back to filtering
// individual .row elements directly for a grid with no groups at all.
//////////////////////////////////

export default class FindInList {
  constructor(host, {
    root = host,
    // Class-only, not tag-qualified — Grid (tr.group) and Tile (div.group)
    // deliberately share this class so shared logic like this one file
    // works for both renderers without a per-List-subclass copy (see
    // tile.js's buildGroupDom comment).
    targetSelector = ".group",
    textKey = "groupLabel",       // use data-group-label
    typeKey = "groupType",        // use data-group-type
    defaultType = null,           // e.g. if only one type present
    placeholder = "Filter…",
  } = {}) {
    this.host = host;
    this.root = root;
    this.targetSelector = targetSelector;
    this.textKey = textKey;
    this.typeKey = typeKey;
    this.defaultType = defaultType;
    this.placeholder = placeholder;

    this.render();
    this.bind();
  }

  targets() {
    const groups = [...this.root.querySelectorAll(this.targetSelector)];
    if (groups.length) return groups;

    // Flat/ungrouped grids (e.g. BatchHistory) never render a .group header
    // at all (see Grid.buildGroupDom's "synthetic ungrouped container"
    // case) — the default target list is always empty for them, so the
    // filter accepted input but silently narrowed nothing. Fall back to
    // filtering individual data rows directly. getText/setVisible already
    // handle a non-group element correctly (getText uses the element's own
    // text instead of its group body's; setVisible only hides a collapsible
    // parent, which a flat grid's shared group container isn't).
    return [...this.root.querySelectorAll('.row')];
  }

  getText(el) {
    const label = (el?.dataset?.[this.textKey] ?? "").toString();
    const groupBody = el?.matches?.(this.targetSelector) ? el.parentElement : el;
    const rowText = (groupBody?.innerText ?? groupBody?.textContent ?? "").toString();
    return `${label} ${rowText}`;
  }

  getType(el) {
    return (el?.dataset?.[this.typeKey] ?? "").toString();
  }

  setVisible(el, visible) {
    const p = el.parentElement;
    if( p.matches('.collapsible') ) p.hidden = !visible;
    el.hidden = !visible;
  }

  inferSingleType() {
    const types = new Set(this.targets().map(t => this.getType(t)).filter(Boolean));
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

  apply(q) {
    const { type, term } = this.parseQuery(q);
    const targets = this.targets();
    const impliedType = type ?? this.defaultType ?? this.inferSingleType();

    // Use your static Find matcher (ranking optional; boolean match is enough)
    for (const el of targets) {
      const tOk = !impliedType || this.getType(el) === impliedType;
      const hay = this.normSearch( this.getText(el) );
      const hit = !term || hay.includes(term);
      this.setVisible(el, tOk ? hit : true);
    }

    // QoL: when the filter narrows to exactly one group, open it so its child
    // rows show without an extra click. Groups auto-opened this way are
    // collapsed again once they're no longer the sole match; groups the user
    // toggled by hand are left alone.
    const visible = targets.filter(el => !el.hidden);
    if (visible.length === 1) {
      this.setGroupOpen(visible[0], true);
      visible[0].dataset.autoOpened = "1";
    } else {
      for (const el of targets) {
        if (el.dataset?.autoOpened) {
          this.setGroupOpen(el, false);
          delete el.dataset.autoOpened;
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
