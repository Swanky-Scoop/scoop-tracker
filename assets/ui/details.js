///////////////////////////////////
// Details panel(s)
// shows every loaded field for one Pods item, with relationship fields
// (per SCOOP.entityRelations) resolved to a title and drilled into a second
// panel on click. Two levels only: DETAILS (grid-opened) and DETAILS2
// (opened from within a details panel) — a second-level click always
// replaces DETAILS2, it never stacks a third panel.
//
// URL hash (#details=entity:id&details2=entity:id) is pushed on every open/
// close so the browser back/forward buttons step through panel state.
//////////////////////////////////

export default class Details {
  static _api = null;
  static _state1 = null; // { entity, id } | null
  static _state2 = null;
  static _docBound = false;

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

  static _ensureHost(level) {
    const cls = level === 2 ? 'DETAILS2' : 'DETAILS';
    let HOST = document.querySelector(`body > .${cls}`);
    if (HOST) return HOST;

    HOST = Details._el('div', '', cls);
    const CLOSE = Details._el('button', 'x', 'close');
    const TITLE = Details._el('h3', '', 'title');
    const BODY  = Details._el('dl', '', 'fields');

    CLOSE.type = 'button';
    CLOSE.addEventListener('click', () => Details.close(level));

    // Relation links rendered inside either panel always open into level 2.
    HOST.addEventListener('click', (e) => {
      const link = e.target.closest('[data-detail-entity]');
      if (!link) return;
      Details.open(link.dataset.detailEntity, Number(link.dataset.detailId), { level: 2 });
    });

    HOST.append(CLOSE, TITLE, BODY);
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
    BODY.replaceChildren();

    if (!item) {
      BODY.append(Details._el('dd', 'Not loaded on this page.', 'missing'));
    } else {
      Details._fillFields(BODY, entity, item);
    }

    HOST.classList.add('show');

    if (level === 1) Details._state1 = { entity, id: numId };
    else Details._state2 = { entity, id: numId };
  }

  static _fillFields(BODY, entity, item) {
    const relations = (window.SCOOP?.entityRelations ?? {})[entity] ?? {};

    Object.entries(item ?? {})
      .filter(([key]) => key !== 'id' && !key.startsWith('_'))
      .forEach(([key, value]) => {
        const DT = Details._el('dt', Details._label(key));
        const DD = Details._el('dd');
        const rel = relations[key];

        if (rel) Details._fillRelation(DD, rel, value);
        else Details._fillPlain(DD, value);

        BODY.append(DT, DD);
      });
  }

  // Renders one or more relationship ids as clickable, title-resolved links.
  // Falls back to a plain (non-clickable) "pod #id" label when that pod
  // isn't loaded in the current page's bundle domain.
  static _fillRelation(DD, rel, value) {
    const ids = Array.isArray(value) ? value : (value ? [value] : []);
    if (!ids.length) { DD.append('—'); return; }

    const byId = new Map(Details._domain(rel.pod).map(i => [Number(i.id), i]));

    ids.forEach((rawId, i) => {
      const relId = Number(rawId);
      const found = byId.get(relId);
      const label = found?._title || `${rel.pod} ${relId}`;

      if (found) {
        const LINK = Details._el('button', label, 'detail-link');
        LINK.type = 'button';
        LINK.dataset.detailEntity = rel.pod;
        LINK.dataset.detailId = String(relId);
        DD.append(LINK);
      } else {
        DD.append(label);
      }

      if (i < ids.length - 1) DD.append(', ');
    });
  }

  static _fillPlain(DD, value) {
    if (Array.isArray(value)) {
      DD.append(value.length ? value.join(', ') : '—');
      return;
    }

    const str = (value == null || value === '') ? '—' : String(value);

    if (/^https?:\/\//.test(str)) {
      const A = Details._el('a', str);
      A.href = str;
      A.target = '_blank';
      A.rel = 'noopener';
      DD.append(A);
      return;
    }

    DD.append(str);
  }

  static _label(key) {
    return String(key).replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
  }

  // --- URL hash state, so back/forward steps through panel history ---

  static _pushHash() {
    const params = new URLSearchParams();
    if (Details._state1) params.set('details', `${Details._state1.entity}:${Details._state1.id}`);
    if (Details._state2) params.set('details2', `${Details._state2.entity}:${Details._state2.id}`);

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
