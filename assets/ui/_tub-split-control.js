///////////////////////////////////
// Shared "split for another use" control — pick a use + amount for one
// tub; the server decides whether that's a real split (new tub) or
// converts the tub in place (see includes/_write_fields.php's
// scoop_create_pod_item, pod_name==='tub' branch). One builder, two hosts:
// assets/ui/tub-detail-view.js (Details modal, acting on whichever tub is
// open) and assets/ui/confirm-swap-modal.js (CabinetWorkflow, acting on
// the outgoing tub) — same control, same write, different "now what" after
// a successful split (the onSplit callback).
//////////////////////////////////
import Toast from "./toast.js";

// item: the tub to split. api: ScoopAPI instance. onSplit: called after a
// successful split (write done, domain already refreshed) — the caller
// decides what "refresh myself" means (Details.refresh() vs a modal
// reopening itself with a fresh row).
export function buildSplitTubControl(item, api, { onSplit } = {}) {
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

  BTN.addEventListener('click', () => splitTub({ item, api, SELECT, AMOUNT, BTN, onSplit }));

  WRAP.append(SELECT, AMOUNT, BTN);
  return WRAP;
}

async function splitTub({ item, api, SELECT, AMOUNT, BTN, onSplit }) {
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
    // whole-tub conversion (see scoop_create_pod_item) — the caller
    // doesn't need to know which to react correctly either way.
    Toast.addMessage({ title: 'Tub split saved', message: 'Split for another use recorded.' });
    await api.refreshPageDomain({ force: true });
    onSplit?.();
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
// since callers here have no model instance to call that on, just the raw
// domain snapshot.
function useOptions(api) {
  const domain = api?.getDomainSnapshot?.() ?? {};
  const uses = Array.isArray(domain.use) ? domain.use : [];

  return [...uses]
    .sort((a, b) => (a.order ?? 0) - (b.order ?? 0))
    .map(u => ({ key: u.id, label: u._title || u.title?.rendered || '' }));
}
