///////////////////////////////////
// Tub-specific rendering for the Details modal (assets/ui/details.js),
// registered there against entity 'tub'. Reuses the generic field dump for
// now — the exact field list/order a tub's detail view should show is still
// an open design question, see OTHER-USES.md — and adds a tub-only actions
// area, starting with a disabled split/alt-use placeholder: the write path
// for actually splitting a tub (new route vs. alternative) hasn't been
// decided yet, see OTHER-USES.md's write-path section.
//////////////////////////////////
import { fillFields } from "./_detail-fields.js";

export function renderTubDetails(BODY, entity, item, api, ACTIONS) {
  fillFields(BODY, entity, item, api);

  if (!ACTIONS) return;

  const SPLIT = document.createElement('button');
  SPLIT.type = 'button';
  SPLIT.classList.add('split-tub');
  SPLIT.textContent = 'Split for another use…';
  SPLIT.disabled = true;
  SPLIT.title = 'Not available yet — write path still being designed, see OTHER-USES.md';
  ACTIONS.append(SPLIT);
}
