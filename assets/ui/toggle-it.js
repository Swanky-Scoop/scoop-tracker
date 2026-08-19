///////////////////////////////////
// Basic cell display dom
// designed GIRD forms in mind
//////////////////////////////////

// Editable boolean — a pure-CSS switch (checkbox + label styled as a
// pill/knob, see .toggle-switch in css.css). Same hidden-input-is-
// authoritative pattern as TextIt: a real <input type=checkbox> drives a
// hidden Type[cells][rowId][colKey] input and dispatches the same
// 'ts:findit-change' event List already listens for on every other control,
// so autosave/dirty-tracking need zero changes to pick this up. Any future
// editable boolean column in a grid (recipe_count.done, prep.done, ...)
// should use this same control rather than growing its own.
export default class ToggleIt {
  constructor(target, data, formKey = "") {
    this.target = target;
    this.data = data ?? {};
    this.formKey = formKey;

    this.value  = this._toBool(this.data.value ?? this.data.display);
    this.rowId  = this.data.rowId ?? this.data.id ?? 0;
    this.colKey = this.data.colKey ?? this.data.key ?? '';
    this.title  = this.data.title ?? null;

    this.fieldName = `${this.formKey}[cells][${this.rowId}][${this.colKey}]`;

    this.render();
  }

  _toBool(v) {
    return v === true || v === 1 || v === '1' || v === 'Yes';
  }

  render() {
    this.target.classList.add('toggleIt-box');

    const HDN = document.createElement("input");
    HDN.type = "hidden";
    HDN.name = this.fieldName;
    HDN.value = this.value ? "1" : "0";

    const INP = document.createElement("input");
    INP.type = "checkbox";
    INP.checked = this.value;
    if (this.title) INP.title = this.title;

    // Keep hidden input authoritative — same idiom as TextIt/FindIt.
    INP.addEventListener("change", () => {
      HDN.value = INP.checked ? "1" : "0";
      HDN.dispatchEvent(new Event('ts:findit-change', { bubbles: true }));
    });

    const SLIDER = document.createElement("span");
    SLIDER.classList.add("toggle-slider");

    const SWITCH = document.createElement("label");
    SWITCH.classList.add("toggle-switch");
    SWITCH.append(INP, SLIDER);

    const BASE = document.createElement("div");
    BASE.classList.add("toggleIt", `col-${this.colKey}`);
    BASE.append(HDN, SWITCH);

    this.target.append(BASE);

    this.HDN = HDN;
    this.INP = INP;
  }
}
