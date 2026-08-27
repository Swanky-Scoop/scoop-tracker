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
import FindIt from "./find-it.js";

// item: the tub to split. api: ScoopAPI instance. onSplit: called after a
// successful split (write done, domain already refreshed) — the caller
// decides what "refresh myself" means (Details.refresh() vs a modal
// reopening itself with a fresh row).
export function buildSplitTubControl(item, api, { onSplit } = {}) {
  const WRAP = document.createElement('div');
  WRAP.classList.add('split-tub');

  // FindIt (assets/ui/find-it.js) — the same type-to-complete widget every
  // other writeable relationship field in this app already uses (see
  // _list.js's _renderFieldValue), not a hand-rolled <select>. That
  // matters beyond consistency: a bare <select> always starts on its
  // first option regardless of data, which silently defaulted this picker
  // to "Front-of-house" even when the tub's real current use was
  // something else — a real bug (a <select>'s AND FindIt's whole job is
  // to always reflect the field's actual current value, not the first
  // option in the list). Left blank rather than assumed when the tub's
  // own use is genuinely 0/unset — this control should show what's
  // actually there, not guess a business-logic default on top of it.
  const HOST = document.createElement('span');
  HOST.classList.add('split-tub-use');

  const options = useOptions(api);
  const currentUseId = Number(item?.use) || 0;
  const currentUseTitle = options.find(o => Number(o.key) === currentUseId)?.label ?? '';

  const useFind = new FindIt(HOST, {
    id: currentUseId,
    display: currentUseTitle,
    rowId: item?.id ?? 0,
    colKey: 'use',
    type: 'pick',
    options,
  }, 'TubSplit');

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
  BTN.disabled = !options.length || noAmount;
  if (noAmount) BTN.title = 'Nothing left on this tub to split.';

  BTN.addEventListener('click', () => splitTub({ item, api, useFind, AMOUNT, BTN, onSplit }));

  WRAP.append(HOST, AMOUNT, BTN);
  return WRAP;
}

async function splitTub({ item, api, useFind, AMOUNT, BTN, onSplit }) {
  const useId = Number(useFind.value);
  const amount = Number(AMOUNT.value);
  if (!useId || !(amount > 0)) return;

  BTN.disabled = true;
  AMOUNT.disabled = true;
  useFind.INP.disabled = true;
  useFind.close();

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
    AMOUNT.disabled = false;
    useFind.INP.disabled = false;
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
