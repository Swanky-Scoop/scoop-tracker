///////////////////////////////////
// Tub-specific rendering for the Details modal (assets/ui/details.js),
// registered there against entity 'tub'. Reuses the generic field dump for
// now — the exact field list/order a tub's detail view should show is still
// an open design question, see OTHER-USES.md — and adds the shared "split
// for another use" control (see _tub-split-control.js — also used by
// CabinetWorkflow's ConfirmSwapModal, same control, different host).
//////////////////////////////////
import { fillFields } from "./_detail-fields.js";
import { buildSplitTubControl } from "./_tub-split-control.js";
import Details from "./details.js";

export function renderTubDetails(BODY, entity, item, api, ACTIONS) {
  fillFields(BODY, entity, item, api);

  if (!ACTIONS) return;
  // Re-renders whatever's currently open (this same tub) against the
  // refreshed domain once the split lands, rather than closing the panel
  // out from under the user.
  ACTIONS.append(buildSplitTubControl(item, api, { onSplit: () => Details.refresh() }));
}
