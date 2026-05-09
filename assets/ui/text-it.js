///////////////////////////////////
// Basic cell display dom
// designed GIRD forms in mind
//////////////////////////////////

// Simple text input
export default class TextIt {
  constructor(target, data, formKey = "") {
    this.target = target;
    this.data = data ?? {};
    this.formKey = formKey;

    this.value  = this.data.value ?? this.data.display ?? "";
    this.rowId  = this.data.rowId ?? this.data.id ?? 0;
    this.colKey = this.data.colKey ?? this.data.key ?? '';
    this.type   = (this.data.hidden)? "hidden" : this.data.type ?? "text"; // "number" or "text"
    this.step   = this.data.step ?? null;
    this.min    = this.data.min ?? null;
    this.max    = this.data.max ?? null;
    this.title  = this.data.title ?? null;

    this.fieldName = `${this.formKey}[cells][${this.rowId}][${this.colKey}]`;

    this.render();
  }

  render() {
    this.target.classList.add('textIt-box');
    const HDN = document.createElement("input");
    HDN.type = "hidden";
    HDN.name = this.fieldName;
    HDN.value = String(this.value ?? "");

    const INP = document.createElement("input");
    INP.type = (this.type === "number") ? "number" : "text";
    INP.value = String(this.value ?? "");
    INP.autocomplete = "off";
    if(this.type === "number" && this.step) INP.step = this.step;
    if(this.type === "number" && this.min !== null) INP.min = this.min;
    if(this.type === "number" && this.max !== null) INP.max = this.max;
    if(this.title) INP.title = this.title;

    // Keep hidden input authoritative
    INP.addEventListener("input", () => {
      HDN.value = INP.value;
      console.log('FINDIT????', HDN.value);
      HDN.dispatchEvent(new Event('ts:findit-change', { bubbles: true }));
    });

    const BTN = document.createElement("button");
    BTN.type = "button";
    BTN.classList.add("clear");
    BTN.textContent = "X";
    BTN.addEventListener("click", (e) => {
      e.preventDefault();
      INP.value = "";
      HDN.value = "";
      INP.focus();
      HDN.dispatchEvent(new Event('ts:findit-change', { bubbles: true }));
    });

    const BASE = document.createElement("div");
    BASE.classList.add("textIt", `col-${this.colKey}`);

    BASE.append(HDN, INP, BTN);
    this.target.append(BASE);

    this.HDN = HDN;
    this.INP = INP;
  }
}
