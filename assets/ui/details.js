///////////////////////////////////
// Details panel(s)
// Generic click-an-item -> modal mechanism: any element carrying
// [data-detail-entity]/[data-detail-id] (a grid's .tub-square, a
// detail-link, a delete column — anything) opens this. What renders
// *inside* the modal is per-entity: entities with a registered view in
// _VIEWS (below) get that; everything else falls back to a plain dump of
// every loaded field via _detail-fields.js's fillFields, with relationship
// fields (per SCOOP.entityRelations) resolved to a title and drilled into a
// second panel on click. Two levels only: DETAILS (grid-opened) and
// DETAILS2 (opened from within a details panel) — a second-level click
// always replaces DETAILS2, it never stacks a third panel.
//
// URL hash (#details=entity:id&details2=entity:id) is pushed on every open/
// close so the browser back/forward buttons step through panel state.
//////////////////////////////////

import { fillFields } from "./_detail-fields.js";
import { renderTubDetails } from "./tub-detail-view.js";
import { renderTaskDetails } from "./task-detail-view.js";

export default class Details {
  static _api = null;
  static _state1 = null; // { entity, id } | null
  static _state2 = null;
  static _docBound = false;

  // entity -> (BODY, entity, item, api, ACTIONS) => void. Add an entry here
  // (and its own <entity>-detail-view.js, see tub-detail-view.js) for any
  // entity that needs more than the generic field dump — a curated field
  // list, entity-only actions, etc. Entities with no entry just get fillFields.
  static _VIEWS = { tub: renderTubDetails, task: renderTaskDetails };

  static attach(api) {
    Details._api = api;
    if (!Details._docBound) {
      Details._docBound = true;
      window.addEventListener('popstate', () => Details._restoreFromHash());

      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && Details._state1) Details.close(1);
      });

      // Outside click closes everything, but not the grid link that opened it
      // (that click is handled by Grid's own delegated listener).
      document.addEventListener('click', (e) => {
        if (!Details._state1) return;
        if (e.target.closest('.DETAILS, .DETAILS2')) return;
        if (e.target.closest('[data-detail-entity]')) return;
        Details.close(1);
      }, true);
    }

    Details._restoreFromHash();
  }

  // Re-check the current hash against the (now presumably loaded) domain.
  // Useful right after mountAllGrids() resolves, for the deep-link case where
  // the page loaded with a #details=... hash before any bundle data existed.
  static refresh() {
    Details._restoreFromHash();
  }

  static _el(tag, text = '', ...classes) {
    const n = document.createElement(tag);
    if (classes.length) n.classList.add(...classes);
    if (text.length > 0) n.append(text);
    return n;
  }

  // 'modal' reuses CabinetWorkflow's body > .modal / .modal.show CSS
  // (assets/css.css) as-is — same overlay + centered panel treatment,
  // same class names, no parallel modal styling of its own. The extra
  // <form> wrapper matches that CSS's `& > form` panel selector; it's
  // structural only; nothing here submits it.
  static _ensureHost(level) {
    const cls = level === 2 ? 'DETAILS2' : 'DETAILS';
    let HOST = document.querySelector(`body > .${cls}`);
    if (HOST) return HOST;

    HOST = Details._el('div', '', cls, 'modal');
    const PANEL = Details._el('form');
    const CLOSE = Details._el('button', 'x', 'close');
    const TITLE = Details._el('h3', '', 'title');
    const BODY  = Details._el('dl', '', 'fields');
    const ACTIONS = Details._el('div', '', 'actions');

    CLOSE.type = 'button';
    CLOSE.addEventListener('click', () => Details.close(level));
    PANEL.addEventListener('submit', (e) => e.preventDefault());

    // Relation links rendered inside either panel always open into level 2.
    // preventDefault so the real href doesn't also trigger a native hash
    // navigation alongside Details.open()'s own pushState.
    HOST.addEventListener('click', (e) => {
      const link = e.target.closest('[data-detail-entity]');
      if (!link) return;
      e.preventDefault();
      Details.open(link.dataset.detailEntity, Number(link.dataset.detailId), { level: 2 });
    });

    PANEL.append(CLOSE, TITLE, BODY, ACTIONS);
    HOST.append(PANEL);
    document.body.append(HOST);

    return HOST;
  }

  // entity: pod key ('flavor', 'tub', 'slot', ...), id: post id
  static open(entity, id, { level = 1 } = {}) {
    Details._render(entity, id, level);
    Details._pushHash();
  }

  static close(level = 1) {
    Details._ensureHost(2).classList.remove('show');
    Details._state2 = null;

    if (level === 1) {
      Details._ensureHost(1).classList.remove('show');
      Details._state1 = null;
    }

    Details._pushHash();
  }

  static _domain(pod) {
    return Details._api?.getDomainSnapshot?.()?.[pod] ?? [];
  }

  static _render(entity, id, level) {
    const numId = Number(id);
    const HOST = Details._ensureHost(level);
    const item = Details._domain(entity).find(i => Number(i.id) === numId);

    HOST.querySelector('.title').textContent = item?._title || `${entity} ${numId}`;

    const BODY = HOST.querySelector('.fields');
    const ACTIONS = HOST.querySelector('.actions');
    BODY.replaceChildren();
    ACTIONS.replaceChildren();

    if (!item) {
      BODY.append(Details._el('dd', 'Not loaded on this page.', 'missing'));
    } else {
      const render = Details._VIEWS[entity] ?? fillFields;
      render(BODY, entity, item, Details._api, ACTIONS);
    }

    HOST.classList.add('show');

    if (level === 1) Details._state1 = { entity, id: numId };
    else Details._state2 = { entity, id: numId };
  }

  // --- URL hash state, so back/forward steps through panel history ---

  static _pushHash() {
    // Start from whatever's already in the hash (#dock=, #loc.*=, #bust —
    // see HashState) rather than a blank URLSearchParams — building fresh
    // here silently dropped every other hash key the moment a Details panel
    // opened or closed. Still hand-rolled rather than routed through
    // HashState itself: this needs history.pushState (so back/forward steps
    // through panel history), not HashState.set's always-replaceState.
    const raw = location.hash.startsWith('#') ? location.hash.slice(1) : location.hash;
    const params = new URLSearchParams(raw);

    if (Details._state1) params.set('details', `${Details._state1.entity}:${Details._state1.id}`);
    else params.delete('details');

    if (Details._state2) params.set('details2', `${Details._state2.entity}:${Details._state2.id}`);
    else params.delete('details2');

    const hash = params.toString();
    const url = hash ? `#${hash}` : (location.pathname + location.search);
    history.pushState({ scoopDetails: true }, '', url);
  }

  static _restoreFromHash() {
    const raw = location.hash.startsWith('#') ? location.hash.slice(1) : location.hash;
    const params = new URLSearchParams(raw);

    const d1 = Details._parseParam(params.get('details'));
    const d2 = Details._parseParam(params.get('details2'));

    if (d1) Details._render(d1.entity, d1.id, 1);
    else { Details._ensureHost(1).classList.remove('show'); Details._state1 = null; }

    if (d2) Details._render(d2.entity, d2.id, 2);
    else { Details._ensureHost(2).classList.remove('show'); Details._state2 = null; }
  }

  static _parseParam(raw) {
    if (!raw) return null;
    const [entity, idStr] = raw.split(':');
    const id = Number(idStr);
    if (!entity || !Number.isFinite(id)) return null;
    return { entity, id };
  }
}
