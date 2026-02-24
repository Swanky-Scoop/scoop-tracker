///////////////////////////////////
// Filter input that narrows elements
// shown in a table
// designed GIRD forms in mind
// depends on UTIL_Find.js
//////////////////////////////////

export default class FindInGrid {
  constructor(host, {
    root = host,
    targetSelector = "tr.group",
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
    return [...this.root.querySelectorAll(this.targetSelector)];
  }

  getText(el) {
    return (el?.dataset?.[this.textKey] ?? "").toString();
  }

  getType(el) {
    return (el?.dataset?.[this.typeKey] ?? "").toString();
  }

  setVisible(el, visible) {
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
      .replace(/[\u2018\u2019\u201B\u2032]/g, "'")
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