///////////////////////////////////
// Tub-specific rendering for the Details modal (assets/ui/details.js),
// registered there against entity 'tub'. Reuses the generic field dump for
// now — the exact field list/order a tub's detail view should show is still
// an open design question, see OTHER-USES.md — and adds a tub-only actions
// area: an inline "split for another use" control. GUI collects a use AND
// an amount; the server (includes/_write_fields.php's scoop_create_pod_item,
// pod_name==='tub' branch) decides what actually happens with them — either
// a real split (new tub for the requested amount, origin reduced by that
// same amount) or, if the requested amount covers the whole remaining
// amount, converts the origin tub in place instead of leaving a spent
// husk behind. Inline rather than its own modal since the inputs are just
// the two fields below — title/state/etc. are all server-computed.
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

  // Defaults to the tub's own current amount — left as-is, that's a
  // request >= the origin's amount, which the server treats as "convert
  // the whole tub" rather than a split (see scoop_create_pod_item). Lower
  // it to split off only part of the tub instead.
  const currentAmount = Number(item?.amount) || 0;
  const AMOUNT = document.createElement('input');
  AMOUNT.type = 'number';
  AMOUNT.classList.add('split-tub-amount');
  AMOUNT.min = '0.01';
  AMOUNT.step = '0.01';
  AMOUNT.value = currentAmount > 0 ? String(currentAmount) : '';

  const BTN = document.createElement('button');
  BTN.type = 'button';
  BTN.classList.add('split-tub-submit');
  BTN.textContent = 'Split for another use';

  // Mirrors the server's own validation (scoop_create_pod_item's
  // tub_split_no_amount/tub_split_missing_use errors) — nothing useful for
  // the button to do if there's no amount left to split or no use to pick.
  const noAmount = currentAmount <= 0;
  BTN.disabled = !SELECT.options.length || noAmount;
  if (noAmount) BTN.title = 'Nothing left on this tub to split.';

  BTN.addEventListener('click', () => splitTub({ item, api, SELECT, AMOUNT, BTN }));

  WRAP.append(SELECT, AMOUNT, BTN);
  return WRAP;
}

async function splitTub({ item, api, SELECT, AMOUNT, BTN }) {
  const useId = Number(SELECT.value);
  const amount = Number(AMOUNT.value);
  if (!useId || !(amount > 0)) return;

  BTN.disabled = true;
  SELECT.disabled = true;
  AMOUNT.disabled = true;

  const res = await api.postJson({
    cells: { 0: { use: useId, amount, origin_tub_id: item.id } },
  }, 'TubSplit');

  if (res.ok) {
    // Server decides whether this was a real split (new tub) or a
    // whole-tub conversion (see scoop_create_pod_item) — the client
    // doesn't need to know which to react correctly either way.
    Toast.addMessage({ title: 'Tub split saved', message: 'Split for another use recorded.' });
    await api.refreshPageDomain({ force: true });
    // Re-renders whatever's currently open (this same tub — its own
    // amount/use/state may have changed) against the refreshed domain,
    // rather than closing the panel out from under the user.
    Details.refresh();
  } else {
    Toast.addMessage({
      title: 'Tub split failed',
      message: res.data?.errors?.[0]?.error ?? res.data?.error ?? `HTTP ${res.status}`,
    });
    BTN.disabled = false;
    SELECT.disabled = false;
    AMOUNT.disabled = false;
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
