///////////////////////////////////
// Tub-specific rendering for the Details modal (assets/ui/details.js),
// registered there against entity 'tub'. Reuses the generic field dump for
// now — the exact field list/order a tub's detail view should show is still
// an open design question, see OTHER-USES.md — and adds a tub-only actions
// area: an inline "split for another use" control (new tub for half the
// origin's amount, under a different use — see OTHER-USES.md for the full
// design). Inline rather than its own modal since the only real input is
// the use picker (amount/title/state/etc. are all server-computed — see
// the pod_name==='tub' branch in includes/_write_fields.php's
// scoop_create_pod_item()).
//////////////////////////////////
import { fillFields } from "./_detail-fields.js";
import Details from "./details.js";
import Toast from "./toast.js";

export function renderTubDetails(BODY, entity, item, api, ACTIONS) {
  fillFields(BODY, entity, item, api);

  if (!ACTIONS) return;
  ACTIONS.append(buildSplitControl(item, api));
}

function buildSplitControl(item, api) {
  const WRAP = document.createElement('div');
  WRAP.classList.add('split-tub');

  const SELECT = document.createElement('select');
  SELECT.classList.add('split-tub-use');
  useOptions(api).forEach(({ key, label }) => {
    const OPT = document.createElement('option');
    OPT.value = String(key);
    OPT.textContent = label;
    SELECT.append(OPT);
  });

  const BTN = document.createElement('button');
  BTN.type = 'button';
  BTN.classList.add('split-tub-submit');
  BTN.textContent = 'Split for another use';

  // Mirrors the server's own validation (scoop_create_pod_item's
  // tub_split_no_amount/tub_split_missing_use errors) — nothing useful for
  // the button to do if there's no amount left to split or no use to pick.
  const noAmount = !(Number(item?.amount) > 0);
  BTN.disabled = !SELECT.options.length || noAmount;
  if (noAmount) BTN.title = 'Nothing left on this tub to split.';

  BTN.addEventListener('click', () => splitTub({ item, api, SELECT, BTN }));

  WRAP.append(SELECT, BTN);
  return WRAP;
}

async function splitTub({ item, api, SELECT, BTN }) {
  const useId = Number(SELECT.value);
  if (!useId) return;

  BTN.disabled = true;
  SELECT.disabled = true;

  const res = await api.postJson({
    cells: { 0: { use: useId, origin_tub_id: item.id } },
  }, 'TubSplit');

  if (res.ok) {
    Toast.addMessage({
      title: 'Tub split',
      message: 'Created a new tub for the selected use.',
    });
    await api.refreshPageDomain({ force: true });
    // Re-renders whatever's currently open (this same tub — its amount is
    // now halved) against the refreshed domain, rather than closing the
    // panel out from under the user.
    Details.refresh();
  } else {
    Toast.addMessage({
      title: 'Tub split failed',
      message: res.data?.errors?.[0]?.error ?? res.data?.error ?? `HTTP ${res.status}`,
    });
    BTN.disabled = false;
    SELECT.disabled = false;
  }
}

// Same option shape/sort as BaseGridModel.getOptions('use') (assets/models/
// _base-grid-model.js) — order field, title fallback chain — kept separate
// since this view has no model instance of its own to call that on, just
// the raw domain snapshot.
function useOptions(api) {
  const domain = api?.getDomainSnapshot?.() ?? {};
  const uses = Array.isArray(domain.use) ? domain.use : [];

  return [...uses]
    .sort((a, b) => (a.order ?? 0) - (b.order ?? 0))
    .map(u => ({ key: u.id, label: u._title || u.title?.rendered || '' }));
}
