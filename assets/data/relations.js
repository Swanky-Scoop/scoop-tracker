// Resolves relationship id(s) to titled items using whatever pod domain is
// loaded in the page's shared bundle — no new fetch. Pure data, no DOM;
// Details.js and List (Grid/Tile) each build their own markup from the result.
//
// pod: the target pod key (e.g. 'tub', 'flavor') — from SCOOP.entityRelations
//      or a column's titleMap.
// ids: single id, array of ids, or nullish.
// domainSnapshot: the bundle's { flavor: [...], tub: [...], ... } object.
//
// Returns [{ id, title, found }] — found=false means that pod isn't loaded
// on this page (or the id doesn't exist in it); title falls back to
// "pod #id" so callers never render a blank.
export function resolveRelationIds(pod, ids, domainSnapshot) {
  const list = Array.isArray(ids) ? ids : (ids ? [ids] : []);
  const pool = domainSnapshot?.[pod] ?? [];
  const byId = new Map(pool.map(item => [Number(item.id), item]));

  return list.map(rawId => {
    const id = Number(rawId);
    const item = byId.get(id);
    return {
      id,
      title: item?._title || `${pod} ${id}`,
      found: !!item,
    };
  });
}
