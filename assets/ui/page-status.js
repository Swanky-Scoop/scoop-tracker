///////////////////////////////////
// PageStatus — shared freshness-tracking host. Same singleton-host pattern
// as Toast (see toast.js): the first grid to call register()/setState()
// creates the shared <ul>, appended to <body> the same way Toast appends
// its .TOASTER. Every shortcode-driven grid on the page (Grid/Tile via
// List, plus PopularPlot, which isn't a List subclass) registers itself
// and reports its own freshness as it loads/re-fetches/renders.
//
// States are exactly what the client already needs to track internally to
// know whether a grid's rendered data matches the server — not GUI
// invention:
//   unknown  (0) — registered, no data has loaded yet
//   fetching (1) — a request for this grid's data is in flight
//   stale    (2) — a newer domain snapshot exists (ts:domain:updated fired)
//                   but this grid deliberately deferred applying it because
//                   the user has an in-progress edit (see List._onDomainUpdated's
//                   dirty/autosaving guard)
//   fresh    (3) — rendered data matches the last-applied domain
//
// This module only builds/updates the raw DOM — no visual styling. Per-grid
// markup:
//   <li class="[state]" data-state="[state]" data-state-index="[n]">
//     {label}<em>{state}</em>
//   </li>
// State index is included so it's usable wherever a numeric progression is
// handy; the states above aren't a strict single path every grid walks (a
// grid can go fresh -> fetching -> fresh forever, or fresh -> stale ->
// fetching -> fresh while mid-edit), but each name always maps to the same
// index.
//
// Which grid caused the most recent shared bundle re-fetch (Save submit,
// autosave's background refresh, a filter change) is page-wide info, not a
// per-grid freshness state — see setTrigger(). Recorded on the <ul> itself:
// a `trigger-{slug}` class plus a leading <li class="PAGE-STATUS-TRIGGER">
// with plain text.
//
// Initial-load ETA (beginLoadTiming/completeLoadTiming) is kept client-side
// on purpose: load time is dominated by this device's own
// network/machine, and a shop with a handful of known in-store devices
// gets a more accurate estimate from each device's own history than from
// one server-side average blending conditions that don't apply to it. No
// history yet (first visit on this browser, or localStorage unavailable —
// e.g. private browsing) just shows that plainly instead of guessing.
//////////////////////////////////

export const STATES = ['unknown', 'fetching', 'stale', 'fresh'];

const ETA_STORAGE_KEY = 'scoop_page_load_eta_v1';
const ETA_SAMPLE_LIMIT = 8; // rolling window — adapts to recent conditions rather than all-time

export default class PageStatus {
  static _items = new Map(); // id -> <li>
  static _loadStart = null;
  static _loadKey = null;

  static _ensureHost() {
    let UL = document.querySelector('body > .PAGE-STATUS');
    if (UL) return UL;

    UL = document.createElement('ul');
    UL.classList.add('PAGE-STATUS');
    document.body.append(UL);
    return UL;
  }

  static register(id, { label = id, type = '', location = '' } = {}) {
    if (!id) return null;

    let LI = PageStatus._items.get(id);
    if (!LI) {
      const UL = PageStatus._ensureHost();
      LI = document.createElement('li');
      LI.dataset.statusId = id;
      if (type) LI.dataset.gridType = type;
      if (location) LI.dataset.location = location;
      LI.append(document.createTextNode(label), document.createElement('em'));
      UL.append(LI);
      PageStatus._items.set(id, LI);
    }

    PageStatus.setState(id, 'unknown');
    return LI;
  }

  static setState(id, state) {
    const LI = PageStatus._items.get(id);
    if (!LI) return;

    const index = STATES.indexOf(state);
    if (index === -1) {
      console.error(`PageStatus.setState: unknown state "${state}"`);
      return;
    }

    LI.classList.remove(...STATES);
    LI.classList.add(state);
    LI.dataset.state = state;
    LI.dataset.stateIndex = String(index);

    const EM = LI.querySelector('em');
    if (EM) EM.textContent = state;
  }

  // name = the grid (List.name, e.g. "Cabinet") whose Save/autosave/filter
  // change caused the shared bundle to re-fetch — or 'page load' for the
  // initial mount, which isn't caused by any single grid.
  static setTrigger(name) {
    const UL = PageStatus._ensureHost();
    const label = String(name ?? 'page load');
    const slug = PageStatus._slug(label);

    [...UL.classList].filter(c => c.startsWith('trigger-')).forEach(c => UL.classList.remove(c));
    UL.classList.add(`trigger-${slug}`);
    UL.dataset.trigger = label;

    let TRIGGER_LI = UL.querySelector(':scope > li.PAGE-STATUS-TRIGGER');
    if (!TRIGGER_LI) {
      TRIGGER_LI = document.createElement('li');
      TRIGGER_LI.classList.add('PAGE-STATUS-TRIGGER');
      UL.prepend(TRIGGER_LI);
    }
    TRIGGER_LI.textContent = `Triggered by: ${label}`;
  }

  static _slug(text) {
    return String(text ?? '')
      .trim()
      .replace(/\s+/g, '-')
      .replace(/[^a-zA-Z0-9_-]/g, '')
      || 'unknown';
  }

  static _ensureEtaLi() {
    const UL = PageStatus._ensureHost();
    let LI = UL.querySelector(':scope > li.PAGE-STATUS-ETA');
    if (!LI) {
      LI = document.createElement('li');
      LI.classList.add('PAGE-STATUS-ETA');
      LI.append(document.createTextNode('Estimated load'), document.createElement('em'));
      UL.prepend(LI);
    }
    return LI;
  }

  static _readEtaHistory() {
    try {
      const raw = localStorage.getItem(ETA_STORAGE_KEY);
      const parsed = raw ? JSON.parse(raw) : {};
      return (parsed && typeof parsed === 'object') ? parsed : {};
    } catch {
      return {};
    }
  }

  static _writeEtaHistory(all) {
    try {
      localStorage.setItem(ETA_STORAGE_KEY, JSON.stringify(all));
    } catch {
      // localStorage full/unavailable (e.g. private browsing) — the estimate
      // just won't persist for next time; nothing to recover from here.
    }
  }

  static _etaAverageMs(key) {
    const samples = PageStatus._readEtaHistory()[key]?.samples ?? [];
    if (!samples.length) return null;
    return samples.reduce((sum, ms) => sum + ms, 0) / samples.length;
  }

  // Call once, as early as possible in the initial page-load mount — starts
  // the clock and, if this exact page (path + grid-type combination) has
  // loaded before on this device, shows a rolling-average estimate from
  // that history.
  static beginLoadTiming(key) {
    PageStatus._loadStart = performance.now();
    PageStatus._loadKey = key;

    const avgMs = PageStatus._etaAverageMs(key);
    const LI = PageStatus._ensureEtaLi();
    LI.classList.remove('estimating', 'measured');
    LI.classList.add('estimating');
    LI.dataset.etaKey = key;

    const EM = LI.querySelector('em');
    if (avgMs != null) {
      LI.dataset.etaAverageMs = String(Math.round(avgMs));
      if (EM) EM.textContent = `~${(avgMs / 1000).toFixed(1)}s`;
    } else {
      delete LI.dataset.etaAverageMs;
      if (EM) EM.textContent = 'no history yet';
    }
  }

  // Call once, when the initial mount has fully resolved (every grid from
  // that mount has already reported 'fresh') — records the actual duration
  // into this device's rolling history and shows what actually happened.
  static completeLoadTiming() {
    if (PageStatus._loadStart == null || !PageStatus._loadKey) return;

    const elapsedMs = performance.now() - PageStatus._loadStart;
    const key = PageStatus._loadKey;

    const all = PageStatus._readEtaHistory();
    const entry = all[key] ?? { samples: [] };
    entry.samples = [...entry.samples, elapsedMs].slice(-ETA_SAMPLE_LIMIT);
    all[key] = entry;
    PageStatus._writeEtaHistory(all);

    const LI = PageStatus._ensureEtaLi();
    LI.classList.remove('estimating');
    LI.classList.add('measured');
    LI.dataset.etaActualMs = String(Math.round(elapsedMs));

    const EM = LI.querySelector('em');
    if (EM) EM.textContent = `${(elapsedMs / 1000).toFixed(1)}s`;

    PageStatus._loadStart = null;
    PageStatus._loadKey = null;
  }

  static remove(id) {
    const LI = PageStatus._items.get(id);
    if (!LI) return;
    LI.remove();
    PageStatus._items.delete(id);
  }
}
