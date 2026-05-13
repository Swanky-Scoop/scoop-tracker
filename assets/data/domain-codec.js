export default class DomainCodec {
  // Keep “policy” explicit so you can audit/remove later.
  static defaults = {
    // If true, decode relationship fields (arrays/objects) into scalar IDs.
    coerceRelationIds: true,
    // If true, compute _title strings from WP-ish title shapes.
    computeTitles: true,
    // If true, normalize embedded tub inside flavor.
    normalizeEmbeddedTubs: true,
  };

  /**
   * Decode a raw bundle (WP REST / Pods-shaped) into your canonical domain.
   * This is the one place “WP shape quirks” should live.
   */

  static decoders = {
    tub     : DomainCodec._decodeTub,
    slot    : DomainCodec._decodeSlot,
    cabinet : DomainCodec._decodeCabinet,
    flavor  : DomainCodec._decodeFlavor,
    location: DomainCodec._decodeLocation,
    use     : DomainCodec._decodeUse,
    inventory_change: DomainCodec._decodeInventoryChange
  };


  static decode(bundle, opts = {}) {
    if (!bundle || typeof bundle !== "object") return bundle;

    const o = { ...DomainCodec.defaults, ...opts };
    const D = { ...bundle }; // shallow clone of bundle

    for (const [key, itemDecoder] of Object.entries(DomainCodec.decoders)) 
      if (Array.isArray(D[key]))
        D[key] = DomainCodec.decodeList(D[key], itemDecoder, o);    

    return D;
  }

  // -------------------------
  // Collection decoders
  // -------------------------

  static decodeList(list, itemFn, o = DomainCodec.defaults) {
    if (!Array.isArray(list)) return [];
    return list.map(item => itemFn.call(DomainCodec, item, o));
  }

  static decodeTubs(tub = [], o = DomainCodec.defaults) {
    return DomainCodec.decodeList(tub, DomainCodec._decodeTub, o);
  }
  static decodeSlots(slot = [], o = DomainCodec.defaults) {
    return DomainCodec.decodeList(slot, DomainCodec._decodeSlot, o);
  }
  static decodeCabinets(cabinet = [], o = DomainCodec.defaults) {
    return DomainCodec.decodeList(cabinet, DomainCodec._decodeCabinet, o);
  }
  static decodeFlavors(flavor = [], o = DomainCodec.defaults) {
    return DomainCodec.decodeList(flavor, DomainCodec._decodeFlavor, o);
  }
  static decodeLocations(location = [], o = DomainCodec.defaults) {
    return DomainCodec.decodeList(location, DomainCodec._decodeLocation, o);
  }
  static decodeUses(use = [], o = DomainCodec.defaults) {
    return DomainCodec.decodeList(use, DomainCodec._decodeUse, o);
  }
  static decodeInventoryChanges(inventoryChange = [], o = DomainCodec.defaults) {
    return DomainCodec.decodeList(inventoryChange, DomainCodec._decodeInventoryChange, o);
  }

  // -------------------------
  // Shared per-entity helpers
  // -------------------------

  /**
   * Ensure:
   * - canonical numeric id in x.id
   * - optional string title in x._title (without overwriting server-provided _title)
   *
   * Returns a CLONED object so callers can safely mutate.
   */
  static _withIdAndTitle(obj, o) {
    if (!obj || typeof obj !== "object") return obj;

    const x = { ...obj };

    if (x.id == null || x.id === 0) {
      x.id = DomainCodec._idOf(x.id ?? x.ID);
    }

    if (o.computeTitles) {
      if (x._title == null || x._title === "") {
        x._title = DomainCodec._titleOf(x);
      }
    }

    return x;
  }

  // -------------------------
  // Item decoders
  // -------------------------

  static _decodeTub(t, o) {
    if (!t || typeof t !== "object") return t;

    const x = DomainCodec._withIdAndTitle(t, o);

    // scalar overwrites
    x.state = DomainCodec._titleOf(x.state);

    if (o.coerceRelationIds) {
      x.flavor   = DomainCodec._idOf(x.flavor);
      x.location = DomainCodec._idOf(x.location);
      x.batch    = DomainCodec._idOf(x.batch);
      x.cabinet  = DomainCodec._idOf(x.cabinet);
    }

    return x;
  }

  static _decodeSlot(s, o) {
    if (!s || typeof s !== "object") return s;

    const x = DomainCodec._withIdAndTitle(s, o);

    if (o.coerceRelationIds) {
      x.cabinet          = DomainCodec._idOf(x.cabinet);
      x.location         = DomainCodec._idOf(x.location);
      x.current_flavor   = DomainCodec._idOf(x.current_flavor);
      x.immediate_flavor = DomainCodec._idOf(x.immediate_flavor);
      x.next_flavor      = DomainCodec._idOf(x.next_flavor);
    }

    return x;
  }

  static _decodeCabinet(c, o) {
    if (!c || typeof c !== "object") return c;

    const x = DomainCodec._withIdAndTitle(c, o);

    if (o.coerceRelationIds) {
      x.location = DomainCodec._idOf(x.location);
    }

    if (x.max_tubs != null) {
      const n = Number(x.max_tubs);
      x.max_tubs = Number.isFinite(n) ? n : 0;
    }

    return x;
  }

  static _decodeFlavor(f, o) {
    if (!f || typeof f !== "object") return f;

    const x = DomainCodec._withIdAndTitle(f, o);

    if (o.normalizeEmbeddedTubs && Array.isArray(x.tub)) {
      x.tub = DomainCodec.decodeTubs(x.tub, o);
    }

    return x;
  }

  static _decodeLocation(loc, o) {
    if (!loc || typeof loc !== "object") return loc;

    const x = DomainCodec._withIdAndTitle(loc, o);

    // If you have relationship fields on location later, coerce them here.

    return x;
  }

  static _decodeUse(use, o) {
    if (!use || typeof use !== "object") return use;

    const x = DomainCodec._withIdAndTitle(use, o);

    // If you have relationship fields on location later, coerce them here.

    return x;
  }

  static _decodeInventoryChange(change, o) {
    if (!change || typeof change !== "object") return change;

    const x = DomainCodec._withIdAndTitle(change, o);

    if (o.coerceRelationIds) {
      x.tubs = DomainCodec._idsOf(change.tubs);
      x.flavors = DomainCodec._idsOf(change.flavors);
    }

    if (x.change_count != null) {
      const n = Number(x.change_count);
      x.change_count = Number.isFinite(n) ? n : 0;
    }

    return x;
  }

  // -------------------------
  // Shared coercion helpers
  // -------------------------

  static _first(v) {
    if (v == null) return null;
    return Array.isArray(v) ? (v.length ? v[0] : null) : v;
  }

  static _idsOf(v) {
    if (v == null || v === false) return [];
    const values = Array.isArray(v) ? v : [v];
    const ids = [];
    const seen = new Set();

    for (const value of values) {
      const id = DomainCodec._idOf(value);
      if (id > 0 && !seen.has(id)) {
        seen.add(id);
        ids.push(id);
      }
    }

    return ids;
  }

  static _idOf(v) {
    const x = DomainCodec._first(v);
    if (x == null || x === false) return 0;

    if (typeof x === "object") {
      return DomainCodec._idOf(x.id ?? x.ID ?? x.value);
    }

    const n = Number(x);
    return Number.isFinite(n) ? n : 0;
  }

  static _titleOf(v) {
    const x = DomainCodec._first(v);
    if (x == null || x === false) return "";
    if (typeof x === "string") return x;

    if (typeof x === "object") {
      const t = x.title;
      if (t && typeof t === "string") return t;
      if (t && typeof t === "object" && typeof t.rendered === "string") return t.rendered;

      if (typeof x.post_title === "string") return x.post_title;
      if (typeof x.name === "string") return x.name;
      if (typeof x.slug === "string") return x.slug;
      if (typeof x.rendered === "string") return x.rendered;
    }

    return "";
  }
}