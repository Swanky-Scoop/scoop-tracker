///////////////////////////////////
// Type-to-complete input
// shown in a table
// designed GIRD forms in mind
// depends on UTIL_Find.js
//////////////////////////////////
import El from "./_el.js";
import Find from "./_find.js";

export default class FindIt extends El {
  // Every FindIt's open dropdown renders here instead of inside .findIt
  // itself — a shared page-level layer, lazily created once and reused by
  // every instance. See open()/_positionOptions(): position:fixed computed
  // from the input's getBoundingClientRect() escapes any scrolling
  // ancestor's overflow clipping (e.g. .action-target's capped table),
  // which position:absolute-inside-the-cell could never do.
  static _overlayRoot() {
    let root = document.getElementById('findit-overlay');
    if (!root) {
      root = document.createElement('div');
      root.id = 'findit-overlay';
      document.body.append(root);
    }
    return root;
  }

  constructor(
    target,
    data = { id: 0, rowId: 0, colKey: "", display: "", type: "", options: [], badges: [] },
    formKey = "",
    { resolve = value => value } = {}
  ) {
    super();
    this.target  = target;
    this.formKey = String(formKey ?? "");
    this.resolve = resolve;
    this.load(data, formKey);
    this._build();
    this._bindEvents();
  }

  load(data = {}, formKey = this.formKey, resolve = this.resolve) {
    const d = data; // Assumes object due to default param
    this.formKey = String(formKey ?? "");
    this.resolve = resolve ?? (v => v);
    
    // Core State
    this.value = d.id?.toString() ?? "";
    this.display = d.display?.toString() ?? "";
    this.options = Array.isArray(d.options) ? d.options : [];

    // Metadata
    this.rowId = Number(d.rowId ?? d.id ?? 0);
    this.colKey = String(d.colKey ?? "");
    this.type = String(d.type ?? "");

    // Use nullish coalescing to allow manual fieldName overrides
    this.fieldName = d.fieldName ?? `${this.formKey}[cells][${this.rowId}][${this.colKey}]`;

    // Reset UI State
    this.filtered = [];
    this.activeIndex = -1;
    this.isOpen = false;
  }
  
  _build(){
    this.BASE = this.el("div",   { classes:["findIt", `type-${this.type}`,`col-${this.colKey}`] } );
    this.UL   = this.el("ul",    { classes: ["options"], 
            attrs: { role: "listbox" } } );
    this.HDN  = this.el("input", {
            attrs: { type: "hidden", name: this.fieldName }, 
            props: { value: String(this.value ?? "") } } );
    this.INP  = this.el("input", {
            attrs: { type: "text", autocomplete: "off", "data-field": this.fieldName },
            props: { value: String(this.display ?? "") } } );

    // Compose
    this.BASE.replaceChildren();
    this.BASE.append(this.HDN, this.INP);

    // Insert (idempotent)
    if (!this.target.contains(this.BASE)) this.target.append(this.BASE);

    this._syncHasValue();
  }

  // Toggles a 'has-value' class on BASE whenever the visible text input is
  // non-empty — a plain CSS state hook, e.g. for a placeholder-style :before
  // on an ancestor cell (see .scoop-grid.Batch td.flavor's "Add batch" hint
  // in css.css) that CSS alone can't key off a text input's live value.
  _syncHasValue() {
    this.BASE.classList.toggle('has-value', !!this.INP?.value);
  }

  _bindEvents() {
    if (this._eventsBound) return;
    this._eventsBound = true;

    const root = this.BASE.closest("form") ?? document;
    root.addEventListener("ts:grid:close-findits", () => this.close());

    Find.selectOnFocus(this.INP);

    this.INP.addEventListener("input", () => {
      if (this.suppressInput) return;
      this._syncHasValue();
      if (!this.isOpen) this.open();
      this._applyFilter(this.INP.value);
    });

    this.INP.addEventListener("keydown", (e) => {
      const k = e.key;

      if (k === "Escape") {
        e.preventDefault();
        this.clear();
        this.close();
        return;
      }

      if (k === "ArrowDown" || k === "ArrowUp") {
        e.preventDefault();
        if (!this.isOpen) this.open();
        if (!this.filtered?.length) return;

        if (
          k === "ArrowDown" &&
          this.isOpen &&
          this.filtered.length === 1 &&
          this.activeIndex === 0 &&
          (this.options?.length ?? 0) > 1
        ) {
          const keepKey = this.filtered[0]?.key;
          this.filtered = this.options ?? [];
          const idx = this.filtered.findIndex(op => String(op.key) === String(keepKey));
          this.activeIndex = idx >= 0 ? idx : 0;
          this._renderOptions();
          this._setActiveIndex(this.activeIndex, { updateInput: true });
          return;
        }

        const dir = (k === "ArrowDown") ? 1 : -1;
        const n = this.filtered.length;
        const next = (this.activeIndex < 0) ? 0 : (this.activeIndex + dir + n) % n;
        this._setActiveIndex(next, { updateInput: true });
        return;
      }

      if (k === "Tab") return;

      if (k === "Enter") {
        if (!this.isOpen) return;
        e.preventDefault();
        this._commitActive();
        return;
      }
    });

    this.UL.addEventListener("mousedown", (e) => e.preventDefault());

    this.UL.addEventListener("click", (e) => {
      const li = e.target.closest('li[data-idx]');
      if (!li) return;
      e.preventDefault();
      const op = this.filtered?.[Number(li.dataset.idx)];
      if (op) this.select(op);
    });

    this.INP.addEventListener("blur", () => {
      const typed = this.INP.value;

      // Emptying the field is the only sanctioned way to leave it unselected.
      if (typed.trim() === "") {
        this._clearValue();
        this.close();
        return;
      }

      // An exact label match always wins (also covers arrow-key navigation,
      // which writes the full label into the input).
      let match = this.options.find(op => op.label === typed);

      // Otherwise, once the user has typed enough to filter (2+ chars), commit
      // the top filtered option. This stops them from tabbing/clicking out on a
      // half-typed value that was never actually selected from the list.
      if (!match && typed.trim().length >= 2) {
        match = this._matchOptions(typed)[0] ?? null;
      }

      if (match) this.select(match);
      else       this._clearValue();

      this.close();
    });
  }

  // Reset to "no selection": blanks the committed value AND the visible text so
  // the cell never shows a stray, unselected string. Used by the blur handler
  // when the field is empty or the typed text matches nothing. "0" and ""
  // both mean "no selection" in this codebase (see clear() vs this) — either
  // counts as already-empty so tabbing through an already-unset field
  // doesn't fire a spurious change.
  _clearValue() {
    const alreadyEmpty = this.value === "" || this.value === "0";

    this.value   = "";
    this.display = "";
    if (this.HDN) this.HDN.value = "";
    if (this.INP) this.INP.value = "";
    this._syncHasValue();
    if (!alreadyEmpty) this.HDN?.dispatchEvent(new Event('ts:findit-change', { bubbles: true }));
  }

  update(value = (this.value ?? ''), { refresh = true, resolve = this.resolve } = {}) {
      // 1. Update internal state
      this.value = value == null ? "" : String(value);
      this.resolve = resolve;
      this.display = this.resolve(this.value);

      if (refresh) {
          if (this.HDN) this.HDN.value = this.value;
          if (this.INP) this.INP.value = this.display;
          this._syncHasValue();
      }
      this.close();
  }
  
  // --- RERENDER new model ---
  refresh(data = null) {
    if(data) this.load(data);

    this.BASE.classList.remove(...this.BASE.classList); 
    this.BASE.classList.add('findIt', `type-${this.type}`, `col-${this.colKey}`);

    this.HDN.name       = this.fieldName;
    this.HDN.value      = this.value;
    this.INP.value      = this.display;
    this.INP.dataset.field = this.fieldName;
    this._syncHasValue();
  }
  
  // semi public actions...
  open() {
    if (this.isOpen) return;
    if (!this.options || this.options.length === 0) return;

    this.isOpen = true;
    const overlay = FindIt._overlayRoot();
    if (!overlay.contains(this.UL)) overlay.append(this.UL);

    this._applyFilter(this.INP.value);
    this._positionOptions();

    // position:fixed can't track a scrolling ancestor continuously without
    // a listener, and a stale-positioned dropdown floating over the wrong
    // cell is worse than just closing it — same as how a native <select>
    // behaves in most browsers. capture:true is required: scroll doesn't
    // bubble, so only a capturing listener on window sees it fire on a
    // nested scrollable ancestor (the .action-target table, <aside>, ...).
    this._boundCloseOnScroll = () => this.close();
    window.addEventListener('scroll', this._boundCloseOnScroll, { capture: true, passive: true });
  }

  close() {
    if (!this.isOpen) return;
    this.isOpen = false;
    this.activeIndex = -1;

    if (this.UL.parentElement) this.UL.remove();

    if (this._boundCloseOnScroll) {
      window.removeEventListener('scroll', this._boundCloseOnScroll, { capture: true });
      this._boundCloseOnScroll = null;
    }
  }

  // Anchors the detached (overlay-mounted) dropdown to the input's current
  // on-screen position — must run after _applyFilter's _renderOptions() has
  // populated the <li>s, so this.UL has a real, measurable height to flip
  // against. Only computed once, at open() — while already open, typing
  // narrows/widens the result count without re-measuring; the input itself
  // doesn't move mid-type, so this is a reasonable trade against
  // repositioning on every keystroke.
  _positionOptions() {
    const rect = this.INP.getBoundingClientRect();
    this.UL.style.left     = `${rect.left}px`;
    this.UL.style.minWidth = `${rect.width}px`;
    this.UL.style.maxWidth = `${rect.width * 2}px`;
    this.UL.style.top      = `${rect.bottom}px`;
    this.UL.style.bottom   = '';

    // Flip above the input instead of running off the bottom of the
    // viewport — checked against the dropdown's own real rendered height,
    // not an assumed/max-height guess.
    if (this.UL.getBoundingClientRect().bottom > window.innerHeight) {
      this.UL.style.top    = '';
      this.UL.style.bottom = `${window.innerHeight - rect.top}px`;
    }
  }

  clear() {
    const alreadyEmpty = this.value === "0" || this.value === "";

    this.HDN.value = "0";
    this.INP.value = "";
    this.value = "0";
    this.display = "";
    this._syncHasValue();
    this._applyFilter("", { noPaint: !this.isOpen });
    if (!alreadyEmpty) this.HDN.dispatchEvent(new Event('ts:findit-change', { bubbles: true }));
  }

  // Tabbing into a cell and back out without touching anything re-runs the
  // blur handler below, which calls this with whatever option still matches
  // the unchanged display text — same key, same label. Only dispatch (and
  // thus only mark the cell dirty) when the selection actually moved.
  select(op) {
    const key = op?.key;
    const newValue   = key == null ? "0" : String(key);
    const newDisplay = String(op?.label ?? "");
    const unchanged  = newValue === this.value && newDisplay === this.display;

    this.value   = newValue;
    this.display = newDisplay;

    this.HDN.value = this.value;
    this.INP.value = this.display;
    this._syncHasValue();

    this.onSelect?.(op);
    if (!unchanged) this.HDN.dispatchEvent(new Event('ts:findit-change', { bubbles: true }));
    this.close();
  }

  // --- HELPERS ---
  _applyFilter(query, {noPaint = false} = {}) {
    this.filtered = this._matchOptions(query);
    this.activeIndex = this.filtered.length ? 0 : -1;
    if (this.isOpen && !noPaint) this._renderOptions();
  }

  _commitActive() {
    const op = this.filtered?.[this.activeIndex];
    if (!op) return false;
    this.select(op);      // must accept option object
    return true;
  }

  _matchOptions(query) {
    return Find.match(
      query,
      this.options ?? [],
      op => op.label
    );
  }

  _renderOptions() {
    this.UL.replaceChildren();

    for (let i = 0; i < (this.filtered?.length ?? 0); i++) {
      const op = this.filtered[i];
      const li = this.el("li", {
        text: op.label ?? "",
        classes: i === this.activeIndex ? ["active"] : [],
        data: { idx: i, key: op.key }, // keep key if you want it for debugging/click
        attrs: {
          role: "option",
          "aria-selected": i === this.activeIndex ? "true" : "false",
        },
      });

      this.UL.append(li);
    }
  }

  _setActiveIndex(i, { updateInput = true } = {}) {
    if (!this.filtered?.length) return;

    const prev = this.UL.querySelector(".active");
    if (prev) prev.classList.remove("active");

    this.activeIndex = i;

    const li = this.UL.querySelector(`li[data-idx="${i}"]`);
    if (li) li.classList.add("active");
    if (prev) prev.setAttribute("aria-selected", "false");
    if (li)  li.setAttribute("aria-selected", "true");

    if (updateInput) {
      const op = this.filtered[i];
      if (op) {
        this.suppressInput = true;
        this.INP.value = op.label ?? "";
        this.HDN.value = op.key ?? "";
        this.suppressInput = false;
      }
    }

    li?.scrollIntoView({ block: "nearest" });
  }

} 