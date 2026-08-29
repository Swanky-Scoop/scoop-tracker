///////////////////////////////////
// Shared field-rendering helpers for the Details modal (assets/ui/details.js)
// and any per-entity detail view registered with it (e.g. tub-detail-view.js).
// Not a view itself — see CLAUDE.md's underscored-file convention.
//////////////////////////////////
import { resolveRelationIds } from "../data/relations.js";

function el(tag, text = '', ...classes) {
  const n = document.createElement(tag);
  if (classes.length) n.classList.add(...classes);
  if (text.length > 0) n.append(text);
  return n;
}

export function label(key) {
  return String(key).replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
}

// Default, entity-agnostic rendering: every loaded field for the item, in
// whatever order the domain object has them, relationship fields resolved
// to clickable title links via SCOOP.entityRelations.
export function fillFields(BODY, entity, item, api) {
  const relations = (window.SCOOP?.entityRelations ?? {})[entity] ?? {};

  Object.entries(item ?? {})
    .filter(([key]) => key !== 'id' && !key.startsWith('_'))
    .forEach(([key, value]) => {
      const DT = el('dt', label(key));
      const DD = el('dd');
      const rel = relations[key];

      if (rel) fillRelation(DD, rel, value, api);
      else fillPlain(DD, value);

      BODY.append(DT, DD);
    });
}

// Renders one or more relationship ids as clickable, title-resolved links.
// Falls back to a plain (non-clickable) "pod #id" label when that pod
// isn't loaded in the current page's bundle domain.
export function fillRelation(DD, rel, value, api) {
  const ids = Array.isArray(value) ? value : (value ? [value] : []);
  if (!ids.length) { DD.append('—'); return; }

  const resolved = resolveRelationIds(rel.pod, ids, api?.getDomainSnapshot?.() ?? {});

  resolved.forEach(({ id, title, found }, i) => {
    if (found) {
      const LINK = el('a', title, 'detail-link');
      LINK.href = `#details2=${encodeURIComponent(rel.pod)}%3A${id}`;
      LINK.dataset.detailEntity = rel.pod;
      LINK.dataset.detailId = String(id);
      DD.append(LINK);
    } else {
      DD.append(title);
    }

    if (i < resolved.length - 1) DD.append(', ');
  });
}

export function fillPlain(DD, value) {
  if (Array.isArray(value)) {
    DD.append(value.length ? value.join(', ') : '—');
    return;
  }

  const str = (value == null || value === '') ? '—' : String(value);

  if (/^https?:\/\//.test(str)) {
    const A = el('a', str);
    A.href = str;
    A.target = '_blank';
    A.rel = 'noopener';
    DD.append(A);
    return;
  }

  DD.append(str);
}
