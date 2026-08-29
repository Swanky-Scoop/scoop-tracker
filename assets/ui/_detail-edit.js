///////////////////////////////////
// Shared edit-mode machinery for the Details modal (assets/ui/details.js).
// Underscored (see CLAUDE.md's convention) — this is a builder other
// per-entity view modules call, not a view itself.
//
// A per-entity view (task-detail-view.js is the reference consumer) calls
// buildEditableDetailView({...}) once and exports the result directly as
// its Details._VIEWS entry. Two kinds of editable data, either or both:
//
//   - `fields` — scalar fields on the item itself (assignee, done,
//     description, ...), all saved together as one write to `routeKey`/
//     `writeKey` (e.g. TaskEdit). Each field declares a `control`
//     ('find' | 'toggle' | 'textarea' | 'text') and this module picks the
//     matching real control — FindIt/ToggleIt/TextIt, the same ones every
//     grid cell already uses — never a hand-rolled substitute.
//
//   - `lists` — attached child-entity lists (Task's batches/recipe_counts/
//     preps is the reference case): a relation picker (+ an optional
//     second relation, e.g. Prep's units) and a count, posted to the
//     child's own existing create route with a parent-linking field set,
//     read back by filtering the bundle domain on that same field, with a
//     per-item delete button calling the child's existing delete route.
//     These do NOT reuse a real Grid+GridModel instance — the Details
//     modal's PANEL is itself a <form>, and HTML forbids nesting <form>
//     elements (see task-form.js's own file comment on why ITS embedded
//     create-grids mount as siblings, never nested) — so this hand-builds
//     each add-row from the same underlying controls those grids use,
//     matching their column shape column-for-column.
//
// Edit mode here is click-gated only, not role-gated — every viewer who
// can open the panel at all sees the Edit button. Per-role restriction is
// a deliberate follow-up for whichever entity needs it first; the natural
// hook is that entity's own SCOOP.metaData.<route>.entities.<pod> per-field
// `write` flags, same server-computed data grids already read for their
// own inline edit controls.
//////////////////////////////////
import { fillPlain } from "./_detail-fields.js";
import Indexer from "../data/indexer.js";
import FindIt from "./find-it.js";
import ToggleIt from "./toggle-it.js";
import TextIt from "./text-it.js";
import Toast from "./toast.js";
import Details from "./details.js";

function addRow(BODY, label, fill) {
  const DT = document.createElement('dt');
  DT.append(label);
  const DD = document.createElement('dd');
  fill(DD);
  BODY.append(DT, DD);
}

// Same markup _list.js's own 'list' column type renders in the grid (see
// _renderFieldValue) — items are {id, text, relationTitle}. onDelete, when
// passed, gets one such item plus its <li> and appends a remove button;
// omitted entirely in view mode, where the list is plain text only.
function buildFieldList(items, onDelete = null) {
  const UL = document.createElement('ul');
  UL.classList.add('field-list');
  items.forEach((it) => {
    const LI = document.createElement('li');
    LI.append(it.text);
    if (onDelete) onDelete(it, LI);
    UL.append(LI);
  });
  return UL;
}

function makeButton(text) {
  const BTN = document.createElement('button');
  BTN.type = 'button';
  BTN.textContent = text;
  return BTN;
}

export function fmtCount(raw) {
  if (raw == null || raw === "") return "";
  const n = Number(raw);
  if (!Number.isFinite(n)) return String(raw);
  return Number.isInteger(n) ? String(n) : n.toFixed(2);
}

// Same option shape BaseGridModel.getOptions()'s generic domain-array
// fallback produces — id/title pairs off whatever pod a relation points
// at, sorted by title for a pickable list.
function domainOptions(domain, domainKey) {
  const list = Array.isArray(domain?.[domainKey]) ? domain[domainKey] : [];
  return [...list]
    .map((item) => ({ key: item.id, label: item._title || '' }))
    .sort((a, b) => a.label.localeCompare(b.label));
}

// --- scalar fields --------------------------------------------------

// Builds the real control for one field descriptor into HOST, returning a
// uniform { get() } accessor the save step reads from — the caller never
// needs to know FindIt keeps its live value on .value while TextIt keeps
// it on .INP.value (see those files) or that a toggle reads from
// .INP.checked; that quirk lives here, once.
function buildFieldControl(HOST, field, item, writeKey) {
  switch (field.control) {
    case 'find': {
      const ctl = new FindIt(HOST, {
        id: Number(item[field.key] ?? 0),
        display: field.displayValue ? field.displayValue(item) : '',
        rowId: item.id,
        colKey: field.key,
        type: 'pick',
        options: field.options ? field.options() : [],
      }, writeKey);
      return { get: () => Number(ctl.value) || 0 };
    }
    case 'toggle': {
      const ctl = new ToggleIt(HOST, {
        value: item[field.key] ? 1 : 0,
        rowId: item.id,
        colKey: field.key,
      }, writeKey);
      return { get: () => (ctl.INP.checked ? 1 : 0) };
    }
    case 'textarea': {
      // A plain <textarea>, not TextIt — TextIt only ever renders a
      // single-line <input> (see text-it.js). Read directly via .value on
      // save rather than through TextIt's hidden-input/dispatch idiom.
      const T = document.createElement('textarea');
      T.value = item[field.key] ?? '';
      T.rows = field.rows ?? 3;
      HOST.append(T);
      return { get: () => T.value || '' };
    }
    case 'text':
    default: {
      const ctl = new TextIt(HOST, {
        value: item[field.key] ?? '',
        rowId: item.id,
        colKey: field.key,
        type: field.type ?? 'text',
        step: field.step,
      }, writeKey);
      return { get: () => ctl.INP.value || '' };
    }
  }
}

function displayFieldValue(field, item) {
  if (field.displayValue) return field.displayValue(item);
  if (field.control === 'toggle') return item[field.key] ? 'Yes' : 'No';
  return item[field.key];
}

// A field with a `visible` predicate (e.g. Task's Completed, only once
// done) is skipped entirely in both view and edit mode when it returns
// false for this item.
function visibleFields(fields, item) {
  return fields.filter((f) => !f.visible || f.visible(item));
}

async function saveFields({ item, api, fields, controls, writeKey, SAVE_BTN, onSaved }) {
  SAVE_BTN.disabled = true;

  // Only fields that actually got a control (readOnly ones didn't, see
  // renderEdit) ride along in the save payload.
  const cells = {};
  fields.filter((f) => controls[f.key]).forEach((f) => { cells[f.key] = controls[f.key].get(); });

  const res = await api.postJson({ cells: { [item.id]: cells } }, writeKey);

  if (res.ok) {
    Toast.addMessage({ title: 'Saved', message: 'Changes saved.' });
    await api.refreshPageDomain({ force: true });
    onSaved();
  } else {
    Toast.addMessage({
      title: 'Save failed',
      message: res.data?.errors?.[0]?.error ?? res.data?.error ?? `HTTP ${res.status}`,
    });
    SAVE_BTN.disabled = false;
  }
}

// --- attached lists ---------------------------------------------------

// Filters domain[listCfg.domainListKey] down to rows whose parentField
// matches parentItem's id, formatted for display — the generalized shape
// of what was TasksGridModel._indexComponents()'s three *ItemDisplay
// formatters, one per list config instead of hand-duplicated per entity.
function computeListItems(listCfg, parentItem, domain) {
  const relById = Indexer.byId(domain[listCfg.relation.domainKey]);
  const secondById = listCfg.secondRelation ? Indexer.byId(domain[listCfg.secondRelation.domainKey]) : null;
  const parentId = Number(parentItem.id);

  return (Array.isArray(domain[listCfg.domainListKey]) ? domain[listCfg.domainListKey] : [])
    .filter((row) => Number(row[listCfg.parentField] ?? 0) === parentId)
    .map((row) => {
      const relationTitle = relById.get(Number(row[listCfg.relation.key] ?? 0))?._title ?? '—';
      const secondTitle = secondById ? (secondById.get(Number(row[listCfg.secondRelation.key] ?? 0))?._title ?? '') : '';
      const text = listCfg.formatText
        ? listCfg.formatText(relationTitle, row, secondTitle)
        : `${relationTitle} ×${fmtCount(row.count)}`;
      return { id: row.id, relationTitle, text };
    });
}

// A small remove button per existing item, only ever shown in edit mode —
// same confirm-then-delete flow as _list.js's generic _handleRowDelete
// ("Delete the {relation title} {noun}?" — same wording
// TaskComponentHistoryGridModel.deleteRowLabel already uses), calling
// listCfg.deleteFn directly instead of going through a List/Grid instance.
// Same button.row-delete class Grid._renderDeleteCell's own icon uses (see
// grid.js) — re-scoped for `.field-list` in css.css since this list lives
// outside a grid row.
function appendDeleteButton(it, LI, listCfg, parentItem, api, listDD) {
  const DEL = document.createElement('button');
  DEL.type = 'button';
  DEL.classList.add('row-delete');
  DEL.title = `Remove this ${listCfg.deleteNoun}`;
  DEL.textContent = '×';

  DEL.addEventListener('click', async () => {
    const label = it.relationTitle ? `the ${it.relationTitle} ${listCfg.deleteNoun}` : `this ${listCfg.deleteNoun}`;
    if (!confirm(`Delete ${label}? This cannot be undone.`)) return;

    DEL.disabled = true;
    const res = await listCfg.deleteFn(it.id, api);

    if (res.ok && res.data?.ok) {
      Toast.addMessage({ title: 'Deleted', message: `Removed ${label}.` });
      await api.refreshPageDomain({ force: true });
      redrawEditableList(listCfg, parentItem, api, listDD);
    } else {
      Toast.addMessage({ title: 'Delete failed', message: res.data?.error ?? `HTTP ${res.status}` });
      DEL.disabled = false;
    }
  });

  LI.append(DEL);
}

// One relation FindIt (+ a second, e.g. Prep's 'units'), a count TextIt, an
// optional notes TextIt, and an Add button — the same fields the matching
// single-row create grid (BatchGridModel/RecipeCountGridModel/
// PrepGridModel-shaped) would post, hand-built here since a real Grid
// can't nest inside the Details modal's own <form>.
function buildAddControl(listCfg, parentItem, api, listDD) {
  const domain = api?.getDomainSnapshot?.() ?? {};
  const WRAP = document.createElement('div');
  WRAP.classList.add('detail-add-row');

  const relHost = document.createElement('span');
  const relFind = new FindIt(relHost, {
    id: 0, display: '', rowId: 0, colKey: listCfg.relation.key, type: 'pick',
    options: domainOptions(domain, listCfg.relation.domainKey),
  }, listCfg.routeKey);

  const countHost = document.createElement('span');
  const countText = listCfg.hasCount === false ? null : new TextIt(countHost, {
    value: '', rowId: 0, colKey: 'count', type: 'number', step: 0.01,
  }, listCfg.routeKey);

  let secondFind = null;
  let secondHost = null;
  if (listCfg.secondRelation) {
    secondHost = document.createElement('span');
    secondFind = new FindIt(secondHost, {
      id: 0, display: '', rowId: 0, colKey: listCfg.secondRelation.key, type: 'pick',
      options: domainOptions(domain, listCfg.secondRelation.domainKey),
    }, listCfg.routeKey);
  }

  let notesText = null;
  let notesHost = null;
  if (listCfg.notesField) {
    notesHost = document.createElement('span');
    notesText = new TextIt(notesHost, {
      value: '', rowId: 0, colKey: listCfg.notesField, type: 'text',
    }, listCfg.routeKey);
  }

  const ADD_BTN = makeButton(listCfg.addLabel);
  WRAP.append(relHost);
  if (countText) WRAP.append(countHost);
  if (secondHost) WRAP.append(secondHost);
  if (notesHost) WRAP.append(notesHost);
  WRAP.append(ADD_BTN);

  ADD_BTN.addEventListener('click', () => addListItem({
    listCfg, parentItem, api, relFind, countText, secondFind, notesText, ADD_BTN, listDD,
  }));

  return WRAP;
}

async function addListItem({ listCfg, parentItem, api, relFind, countText, secondFind, notesText, ADD_BTN, listDD }) {
  const relId = Number(relFind.value) || 0;
  const count = countText ? (Number(countText.INP.value) || 0) : null;
  if (!relId) return;
  if (countText && !(count > 0)) return;

  const cells = {
    [listCfg.relation.key]: relId,
    [listCfg.parentField]: parentItem.id,
    ...(countText ? { count } : {}),
    ...(listCfg.secondRelation ? { [listCfg.secondRelation.key]: Number(secondFind.value) || 0 } : {}),
    ...(listCfg.notesField ? { [listCfg.notesField]: notesText.INP.value || '' } : {}),
    ...(listCfg.extraFields ?? {}),
  };

  ADD_BTN.disabled = true;

  const res = await api.postJson({ cells: { 0: cells } }, listCfg.routeKey);

  if (res.ok) {
    Toast.addMessage({ title: `${listCfg.listLabel} added`, message: 'Item recorded.' });
    await api.refreshPageDomain({ force: true });

    // Redraw just this list's <li>s + a fresh blank add-row in place, so
    // adding several in a row (the common case) doesn't bounce the whole
    // panel back to view mode — same "stay put, go blank" idea as
    // BatchGridModel's saveReset.
    redrawEditableList(listCfg, parentItem, api, listDD);
  } else {
    Toast.addMessage({
      title: `${listCfg.listLabel} add failed`,
      message: res.data?.errors?.[0]?.error ?? res.data?.error ?? `HTTP ${res.status}`,
    });
    ADD_BTN.disabled = false;
  }
}

// Shared by both mutations on an attached list (add and delete) — recompute
// this one list from the freshly-refreshed domain and redraw its <li>s plus
// a brand-new add-row control, without touching the rest of the panel.
function redrawEditableList(listCfg, parentItem, api, listDD) {
  const domain = api?.getDomainSnapshot?.() ?? {};
  const items = computeListItems(listCfg, parentItem, domain);
  listDD.replaceChildren();
  if (items.length) {
    listDD.append(buildFieldList(items, (it, LI) => appendDeleteButton(it, LI, listCfg, parentItem, api, listDD)));
  }
  listDD.append(buildAddControl(listCfg, parentItem, api, listDD));
}

// --- the builder --------------------------------------------------

// config:
//   writeKey  — route/envelope for the scalar `fields` save (e.g. 'TaskEdit')
//   fields    — [{ key, label, control, options?, displayValue?, rows?, step?, type? }]
//   lists     — [{ routeKey, domainListKey, parentField, listLabel, addLabel,
//                  relation:{key,domainKey,label}, secondRelation?, notesField?,
//                  hasCount?, extraFields?, formatText?, deleteFn, deleteNoun }]
//
// Returns a renderView(BODY, entity, item, api, ACTIONS) function — register
// it directly as a Details._VIEWS entry.
export function buildEditableDetailView({ writeKey, fields = [], lists = [] }) {

  function renderView(BODY, entity, item, api, ACTIONS) {
    BODY.replaceChildren();

    visibleFields(fields, item).forEach((f) =>
      addRow(BODY, f.label, (DD) => fillPlain(DD, displayFieldValue(f, item))));

    if (lists.length) {
      const domain = api?.getDomainSnapshot?.() ?? {};
      lists.forEach((listCfg) => {
        const items = computeListItems(listCfg, item, domain);
        addRow(BODY, listCfg.listLabel, (DD) => {
          if (!items.length) { DD.append('—'); return; }
          DD.append(buildFieldList(items));
        });
      });
    }

    if (!ACTIONS) return;
    ACTIONS.replaceChildren();
    const EDIT_BTN = makeButton('Edit');
    EDIT_BTN.addEventListener('click', () => renderEdit(BODY, ACTIONS, entity, item, api));
    ACTIONS.append(EDIT_BTN);
  }

  function renderEdit(BODY, ACTIONS, entity, item, api) {
    BODY.replaceChildren();

    const controls = {};
    visibleFields(fields, item).forEach((f) => {
      // readOnly fields (e.g. Task's Created timestamp) show in edit mode
      // too, same as view mode, but never get their own control or ride
      // along in the Save payload — they're not part of `controls`, so
      // saveFields() (which only iterates `fields`, not `controls`) would
      // otherwise choke on a missing .get(); skip building an entry for
      // them entirely and read plain instead.
      if (f.readOnly) {
        addRow(BODY, f.label, (DD) => fillPlain(DD, displayFieldValue(f, item)));
        return;
      }
      addRow(BODY, f.label, (DD) => {
        const HOST = document.createElement('span');
        DD.append(HOST);
        controls[f.key] = buildFieldControl(HOST, f, item, writeKey);
      });
    });

    if (lists.length) {
      const domain = api?.getDomainSnapshot?.() ?? {};
      lists.forEach((listCfg) => {
        const items = computeListItems(listCfg, item, domain);
        addRow(BODY, listCfg.listLabel, (DD) => {
          if (items.length) {
            DD.append(buildFieldList(items, (it, LI) => appendDeleteButton(it, LI, listCfg, item, api, DD)));
          }
          DD.append(buildAddControl(listCfg, item, api, DD));
        });
      });
    }

    ACTIONS.replaceChildren();
    const SAVE   = makeButton('Save');
    const CANCEL = makeButton('Cancel');
    SAVE.addEventListener('click', () => saveFields({
      item, api, fields, controls, writeKey, SAVE_BTN: SAVE,
      onSaved: () => Details.refresh(),
    }));
    CANCEL.addEventListener('click', () => renderView(BODY, entity, item, api, ACTIONS));
    ACTIONS.append(SAVE, CANCEL);
  }

  return renderView;
}
