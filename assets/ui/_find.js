///////////////////////////////////
// Static untility  
// Consumed by FindIt and FindInList
//////////////////////////////////

export default class Find{
   
  static norm(s) {
    return (s ?? "").toString().trim().toLowerCase();
  }

  /**
   * Match items against a query.
   * items: array of objects
   * getText: fn(item) => string
   */
  static match(query, items, getText) {
    const q = Find.norm(query);
    if (!q) return items;

    const scored = [];

    for (const item of items) {
      const text = Find.norm(getText(item));
      if (!text) continue;

      if (text.startsWith(q)) {
        scored.push({ item, score: 0 });
      } else {
        const i = text.indexOf(q);
        if (i >= 0) scored.push({ item, score: 10 + i });
      }
    }

    scored.sort((a, b) => a.score - b.score);
    return scored.map(x => x.item);
  }

  /**
   * Make an input select its entire text whenever it gains focus, so the
   * common "click it, type over it" flow replaces the value instead of
   * inserting into the middle of it. Used by FindIt and FindInList — the
   * two Find consumers.
   *
   * The mouse needs more care than the naive version (select() on focus):
   * a focus-gaining click runs mousedown → focus → mouseup, and the
   * browser's default mouseup caret placement collapses a selection made
   * at focus time — and worse, a select() made while a drag-selection
   * gesture is still in progress makes Chromium ABANDON that gesture, so
   * the user's drag-out-a-range on an unfocused input never survives
   * (verified headless: same drag leaves 0..2 on a plain input, but 0..7
   * — a stomped select-all — when select() ran on focus). So the mouse
   * path defers to mouseup, which arbitrates on what the gesture produced:
   *
   * - plain click on an unfocused input: the browser collapsed to a caret
   *   at the click point → re-select all (setTimeout 0 runs after the
   *   default action);
   * - a drag that selected a sub-range: the user's own range wins;
   * - a click on an ALREADY-focused input: an editing gesture (caret
   *   placement, double-click word selection) — untouched;
   * - a double/triple click on an UNFOCUSED input: still a text-selection
   *   gesture (grab one word) — the second press cancels the re-select the
   *   first mouseup queued, so the browser's word selection survives.
   *
   * Keyboard (Tab) and programmatic .focus() have no mouse gesture, so the
   * focus handler selects immediately for them.
   */
  static selectOnFocus(input) {
    let inMouseGesture = false;
    let wasFocused = true;
    let multiClick = false;
    let pendingSelect = null;

    input.addEventListener("mousedown", (e) => {
      wasFocused = document.activeElement === input;
      inMouseGesture = true;
      multiClick = e.detail > 1;
      // A second press means the gesture is deliberate text selection
      // (double/triple click) — cancel any re-select the earlier mouseup
      // queued, or it would fire after the dblclick and stomp the word
      // selection with a select-all.
      clearTimeout(pendingSelect);
      // The gesture can end with the release OUTSIDE the input (drag out,
      // let go elsewhere — no mouseup fires on the input itself). A one-shot
      // window-level cleanup ends it wherever the release lands, so
      // inMouseGesture can't stick true and swallow a later Tab/.focus().
      const done = () => {
        inMouseGesture = false;
        window.removeEventListener("mouseup", done, true);
      };
      window.addEventListener("mouseup", done, true);
    });

    input.addEventListener("focus", () => {
      if (!inMouseGesture) input.select();
    });

    input.addEventListener("mouseup", () => {
      if (wasFocused || multiClick) return;

      const s = input.selectionStart;
      const t = input.selectionEnd;
      const len = input.value.length;

      // A partial selection means the user dragged out their own range —
      // don't touch it. (A collapsed selection can only be an empty input;
      // re-selecting is a harmless no-op there.)
      if (s !== t && !(s === 0 && t === len)) return;
      pendingSelect = setTimeout(() => input.select(), 0);
    });
  }

}