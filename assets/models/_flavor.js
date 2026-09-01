///////////////////////////////////
// because Flavors are so important
// this helper exists
// Flavors are often option topics 
// in FindIt and used in Grid groups
// Used in the view models that need Flavor options
//////////////////////////////////

import Indexer from "../data/indexer.js";

// Tub states that drop out of the "in play" set entirely. "Emptied" tubs are
// gone; "!Lost" tubs are flagged lost and must not affect alertCase coloring or
// availability counts whether or not they still hold product.
const EXCLUDED_STATES = new Set(["Emptied", "!Lost"]);

export default class Flavor {
  constructor({ flavorsById, tub, location }) {
    this.flavorsById = flavorsById;
    this.location = Number(location) || 0;

    const notEmpty = (tub ?? []).filter(t => !EXCLUDED_STATES.has(t.state));

    // location 0 means "all locations" throughout this codebase (see
    // SHORTCODES.md's "`location` defaults to multi-location when
    // omitted") — treat it as no location filter at all, rather than
    // comparing tub.location === 0, which is never true for a real tub and
    // silently zeroed out hereNotEmpty for every flavor (every cell then
    // read as "none-left" — see alertCase() below) whenever a grid mounts
    // without a location attribute. Number(t.location) guards against a
    // string/number type mismatch with `location`, which was the original,
    // narrower bug behind the same symptom.
    const hereNotEmpty = this.location
      ? notEmpty.filter(t => Number(t.location) === this.location)
      : notEmpty;
    const hereOpened   = hereNotEmpty.filter(t => t.state === "Opened");
    const hereFresh    = hereNotEmpty.filter(t => t.state !== "Opened" && (!t.use || t.use === 1863));
    const remoteAll    = this.location
      ? notEmpty.filter(t => Number(t.location) !== this.location)
      : [];

    this.notEmptyByFlavor = Indexer.groupBy(hereNotEmpty, t => Number(t.flavor));
    this.openedByFlavor   = Indexer.groupBy(hereOpened,   t => Number(t.flavor));
    this.freshByFlavor    = Indexer.groupBy(hereFresh,    t => Number(t.flavor));
    this.remoteByFlavor   = Indexer.groupBy(remoteAll,    t => Number(t.flavor));

    this.optionsAll = [...flavorsById.entries()]
      .map(([id, f]) => ({ key: id, label: f._title }))
      .sort((a, b) => a.label.localeCompare(b.label));
  }

  badges(id, specs) {
    if (!id) return [];
    return (specs ?? [])
      .map(s => {
        const n = s.count(id, this);
        if (s.hideZero && !n) return null;
        return { key: s.key, text: s.format(n,id), title: s.title ?? "" };
      })
      .filter(Boolean);
  }

  alertCase(flavorId, type='flavor') {
    const id = Number(flavorId);
    if (!id) return 'n';

    const nTotal  = this.notEmptyByFlavor.get(id)?.length ?? 0;
    const nOpened = this.openedByFlavor.get(id)?.length ?? 0;
    const nFresh  = this.freshByFlavor.get(id)?.length ?? 0;
    const last    = nTotal - nOpened;

    if (nTotal  === 0)               return "none-left";
    if (nFresh  === 0)               return "all-committed";
    if (last    === 0)               return "only-opened";
    if (nFresh  === 1 || last === 1) return "last-unopened";
    return '';
  }


  getFlavorBadgeSpecs() {
    // Deliberately just the one badge (Gus, 2026-09-01): a second
    // "available elsewhere" figure (remoteByFlavor) sat right next to this
    // one with no visible label distinguishing them (the `title` above only
    // shows on hover) and read as a single miscomputed count rather than
    // two intentional ones. If "available elsewhere" is wanted again later,
    // it needs an on-screen label, not just a tooltip.
    return [
      { key:"loc", title:"Available here", hideZero:true,
        count:(flavorId, flvModel) => flvModel.notEmptyByFlavor.get(Number(flavorId))?.length ?? 0,
        format:n => `${n}` },
    ];
  }
}