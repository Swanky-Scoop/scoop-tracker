
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
//
// A cache-bust load (the server had to recompute — no valid transient) and
// a cached reload (the server served its transient straight back) are
// different enough in practice that averaging them together would be
// meaningless — the server already tells the client which one happened via
// the bundle/analytics response's `_cache: 'hit'|'miss'` field (see
// bundle.php/analytics.php), so history is kept as two separate rolling
// buckets per page, and both estimates are shown before the outcome of
// *this* load is known (which bucket applies isn't knowable in advance —
// it depends on server-side state, not anything the client controls).
//////////////////////////////////

import El from "./_el.js";

export const STATES = ['unknown', 'fetching', 'stale', 'fresh'];

// Display text only — STATES itself stays the internal class/dataset
// vocabulary. 'unknown' means "registered, no fetch started yet" (see
// register()), which reads as a stall to anyone watching the page; "Loading"
// says the same thing without implying something's wrong.
const STATE_LABELS = { unknown: 'Loading' };
const labelFor = (state) => STATE_LABELS[state] ?? state;

const ETA_STORAGE_KEY = 'scoop_page_load_eta_v1';
const ETA_SAMPLE_LIMIT = 8; // rolling window — adapts to recent conditions rather than all-time
const ETA_DEFAULT_MS = 15000; // cache-bust countdown start when this page has no 'miss' history yet
const COUNTDOWN_TICK_MS = 100;

// PageStatus's public API is entirely static (called as PageStatus.register(),
// not instantiated — every other List/Tile subclass extends El and calls
// this.el() as an instance, which doesn't fit here), so one shared helper
// instance is used internally instead.
const DOM = new El();

export default class PageStatus {
  static _items = new Map(); // id -> <li>
  static _editingIds = new Set(); // ids currently reporting a dirty/unsaved form
  static _anyEditing = false; // mirrors _recomputeEditingState's anyEditing — see _updateHeaderText
  // key -> { loadStart, ids: Set<statusId>, timer, pendingCacheStatus }. Keyed
  // (not a single global) because mountAllGrids() now fires every grid's own
  // fetch concurrently (Promise.all — analytics types self-fetch alongside
  // the shared bundle fetch, not sequentially before it), so more than one
  // load can genuinely be in flight at once. `ids` is what lets a shared
  // grid-count (e.g. every bundle grid on one fetch) know when ITS load is
  // done without being confused by an unrelated concurrent load's grids
  // still fetching — see _tryFinishLoadTiming/_anyIdsFetching.
  static _loads = new Map();
  // Whichever key most recently called beginLoadTiming() owns the single
  // shared PAGE-STATUS-ETA <em> — concurrent loads don't get concurrent
  // widgets, just concurrent per-button rings (see List._bindPageStatusToggle),
  // so the shared widget staying "last load wins" is a deliberate simplification,
  // not an oversight. If the owning key finishes while another load is still
  // running (e.g. Analytics self-fetching slower than the bundle fetch),
  // _tryFinishLoadTiming() hands ownership to whichever load is still in
  // _loads rather than leaving the widget frozen on the finished key's
  // summary — see the handoff there.
  static _displayKey = null;

  static _ensureHost() {
    let DIV = document.querySelector('body .PAGE-STATUS');
    if (DIV) return DIV;

    DIV = DOM.el('div', { classes: ['PAGE-STATUS'] });
    const UL = DOM.el('ul');
    const h3 = DOM.el('h3', { text: 'Status' });
    const EM = DOM.el('em'); 
    DIV.append(h3);
    DIV.append(EM);
    DIV.append(UL);
    document.body.append(DIV);

    return DIV;
  }

  static register(id, { label = id, type = '', location = '' } = {}) {
    if (!id) return null;

    let LI = PageStatus._items.get(id);
    if (!LI) {
      const DIV = PageStatus._ensureHost();
      const UL = DIV.querySelector('ul');
      const data = { statusId: id };
      if (type) data.gridType = type;
      if (location) data.location = location;

      LI = DOM.el('li', { data });
      LI.append(document.createTextNode(label), DOM.el('em'));
      UL.append(LI);
      PageStatus._items.set(id, LI);
    }

    PageStatus.setState(id, STATES[0]);
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
    if (EM) EM.textContent = labelFor(state);

    PageStatus._recomputeOverallState();
    PageStatus._recomputeEditingState();
    PageStatus._tryFinishAllPending();

    // Lets anything outside this module (e.g. a control's own dock TOGGLE
    // button — see List._bindPageStatusToggle) mirror one specific grid's
    // freshness without polling or reaching into PageStatus's internals.
    document.dispatchEvent(new CustomEvent('ts:page-status:change', { detail: { id, state } }));
  }

  // On-demand read for a single grid's current state — for a caller that
  // starts observing after register() already ran (e.g. a control wiring up
  // its own TOGGLE mid-construction) and needs today's state before the next
  // change event fires.
  static getState(id) {
    return PageStatus._items.get(id)?.dataset.state ?? null;
  }

  // Orthogonal to the fresh/stale/fetching lifecycle above: any grid with a
  // dirty/unsaved form, autosave in flight or not, reports itself here. A
  // grid can be simultaneously 'fresh' and mid-edit (a plain Save-button
  // grid the user hasn't submitted yet), so this doesn't fold into STATES.
  // Callers pass only an id + boolean — this module still knows nothing
  // about forms, cells, or autosave.
  //
  // Fetching supersedes editing in the display: she already knows she's
  // mid-edit (she's the one typing) — that's not new information. A
  // background refresh landing is the thing worth surfacing, so while any
  // grid is 'fetching' the editing indicator stays suppressed even if
  // something's dirty; it reappears once the fetch settles.
  static setEditing(id, dirty) {
    if (!id) return;
    if (dirty) PageStatus._editingIds.add(id);
    else PageStatus._editingIds.delete(id);
    PageStatus._recomputeEditingState();
  }

  static _recomputeEditingState() {
    const DIV = PageStatus._ensureHost();
    const anyEditing = PageStatus._editingIds.size > 0 && !PageStatus._anyGridFetching();
    PageStatus._anyEditing = anyEditing;

    document.body.classList.toggle('in-progress', anyEditing);
    DIV.classList.toggle('in-progress', anyEditing);

    PageStatus._updateHeaderText();
  }

  // The header <em> (next to "Status:") normally just echoes the overall
  // freshness word (see _recomputeOverallState) — but while a dirty edit is
  // in progress that word is misleading (a grid can be 'fresh' and mid-edit
  // at the same time, per the comment above setEditing), so the header
  // borrows the same anyEditing signal to say "Edit in progress" instead,
  // and reverts to the freshness word once the edit resolves (saved or
  // cancelled) and _recomputeEditingState runs again.
  static _updateHeaderText() {
    const DIV = PageStatus._ensureHost();
    const EM = DIV.querySelector(':scope > em');
    if (!EM) return;

    if (PageStatus._anyEditing) {
      EM.textContent = 'Edit in progress';
      return;
    }

    const state = DIV.dataset.state;
    if (state) {
      const label = labelFor(state);
      EM.textContent = label.charAt(0).toUpperCase() + label.slice(1);
    }
  }

  // The <DIV> itself carries whichever registered grid's state is furthest
  // from 'fresh' — 'fresh' is the steady state once every grid agrees, and
  // any single grid being 'unknown'/'fetching'/'stale' pulls the whole page
  // out of it. STATES is already ordered worst-to-best (see the header
  // comment), so "furthest from fresh" is just the lowest index present.
  // Only grids registered via register() count — the trigger/ETA <li>s
  // aren't freshness states and don't live in _items.
  static _recomputeOverallState() {
    if (!PageStatus._items.size) return;
    const DIV = PageStatus._ensureHost();

    let worstIndex = STATES.length - 1; // start at 'fresh', the ideal
    for (const LI of PageStatus._items.values()) {
      const index = STATES.indexOf(LI.dataset.state);
      if (index !== -1 && index < worstIndex) worstIndex = index;
    }

    const overall = STATES[worstIndex];
    DIV.classList.remove(...STATES);
    DIV.classList.add(overall);
    DIV.dataset.state = overall;
    DIV.dataset.stateIndex = String(worstIndex);
    PageStatus._updateHeaderText();
  }

  // name = the grid (List.name, e.g. "Cabinet") whose Save/autosave/filter
  // change caused the shared bundle to re-fetch — or 'page load' for the
  // initial mount, which isn't caused by any single grid.
  static setTrigger(name) {
    const DIV = PageStatus._ensureHost();
    const UL = DIV.querySelector('ul');
    const label = String(name ?? 'page load');
    const slug = PageStatus._slug(label);

    [...UL.classList].filter(c => c.startsWith('trigger-')).forEach(c => UL.classList.remove(c));
    UL.classList.add(`trigger-${slug}`);
    UL.dataset.trigger = label;

    let TRIGGER_LI = UL.querySelector(':scope > li.PAGE-STATUS-TRIGGER');
    if (!TRIGGER_LI) {
      TRIGGER_LI = DOM.el('li', { classes: ['PAGE-STATUS-TRIGGER'] });
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

  // <li class="PAGE-STATUS-ETA">
  //   Estimated load
  //   <em class="cached" data-cache-status="hit">...</em>
  //   <em class="cache-bust" data-cache-status="miss">...</em>
  // </li>
  static _ensureEtaLi() {
    const DIV = PageStatus._ensureHost();
    const UL = DIV.querySelector('ul');
    let LI = UL.querySelector(':scope > li.PAGE-STATUS-ETA');
    if (!LI) {
      LI = DOM.el('li', { classes: ['PAGE-STATUS-ETA'] });

      const CACHED = DOM.el('em', { classes: ['cached'], data: { cacheStatus: 'hit' } });
      const BUST   = DOM.el('em', { classes: ['cache-bust'], data: { cacheStatus: 'miss' } });

      LI.append(CACHED, BUST);
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

  // bucket: 'hit' | 'miss'
  static _etaAverageMs(key, bucket) {
    const samples = PageStatus._readEtaHistory()[key]?.[bucket] ?? [];
    if (!samples.length) return null;
    return samples.reduce((sum, ms) => sum + ms, 0) / samples.length;
  }

  // Call once per load, as early as possible — starts that key's clock and
  // (if this key currently owns the shared widget — see _displayKey) shows
  // both rolling-average estimates this page (path + grid-type combination)
  // has on this device: one for a cache-bust load, one for a cached reload.
  // Which one actually applies isn't known until completeLoadTiming() — see
  // the header comment.
  //
  // ids: the pageStatusIds this load will make 'fetching' — used only to
  // know when THIS key's load is actually done (see _tryFinishLoadTiming);
  // required for correct completion detection whenever more than one load
  // can be in flight at once, which is the normal case since mountAllGrids()
  // fires every grid's fetch concurrently.
  //
  // defaultMs is the cache-bust countdown's starting point when this page
  // has no 'miss' history yet — callers with domain knowledge of which grid
  // types tend to run slow cold queries (e.g. ScoopAPI, for DateActivity/
  // BatchHistory) can pass a larger one than the generic ETA_DEFAULT_MS.
  static beginLoadTiming(key, defaultMs = ETA_DEFAULT_MS, ids = []) {
    // Same key beginning again before its prior load finished (e.g. a
    // delete's own pre-emptive countdown — see ScoopAPI.beginLoadTiming's
    // doc comment — followed shortly by the real fetch's own call) restarts
    // the clock rather than running two timers for one key.
    const existing = PageStatus._loads.get(key);
    if (existing?.timer != null) clearInterval(existing.timer);

    PageStatus._loads.set(key, {
      loadStart: performance.now(),
      ids: new Set(ids),
      timer: null,
      pendingCacheStatus: undefined,
    });
    PageStatus._displayKey = key;

    const LI = PageStatus._ensureEtaLi();
    LI.classList.remove('estimating', 'measured');
    LI.classList.add('estimating');
    LI.dataset.etaKey = key;
    delete LI.dataset.cacheStatus;

    const BUST   = LI.querySelector('em.cache-bust');
    BUST.classList.remove('actual');

    const hitAvg  = PageStatus._etaAverageMs(key, 'hit');
    const missAvg = PageStatus._etaAverageMs(key, 'miss');

    // Cache-bust is the slow path worth watching live — count down from this
    // page's own 'miss' history, or defaultMs when there's none yet. If
    // this load actually turns out to be a cache hit, completeLoadTiming()
    // stops the countdown well before it reaches zero.
    BUST.dataset.etaSource = (missAvg != null) ? 'history' : 'default';
    PageStatus._startCountdown(key, missAvg != null ? missAvg : defaultMs);
  }

  // Runs this key's own interval independently of any other in-flight
  // load's — see the _loads doc comment. Only touches the shared
  // PAGE-STATUS-ETA <em> DOM while this key is the current _displayKey;
  // every load, display owner or not, still dispatches its own
  // 'ts:page-status:progress' tick so a button whose grid belongs to this
  // key (see List._bindPageStatusToggle) stays in sync even while some
  // *other* load owns the shared widget.
  static _startCountdown(key, durationMs) {
    const load = PageStatus._loads.get(key);
    if (!load) return;

    const deadline = performance.now() + durationMs;
    // Global hook so any page chrome (not just the PAGE-STATUS list itself)
    // can react while any cache-busting refresh is in flight.
    document.body.classList.add('counting-down');

    const tick = () => {
      // The load may have finished (and been deleted from _loads) between
      // one tick and the next — bail rather than resurrect stale DOM state.
      if (PageStatus._loads.get(key) !== load) {
        clearInterval(load.timer);
        return;
      }

      const remainingMs = deadline - performance.now();
      const overtime = remainingMs < 0;

      // Drives the CSS ring (see css.css's .cache-bust.counting-down and
      // .gridToggle.fetching rules) — 0 at start, 100 once the estimate
      // elapses, held at 100 through overtime. Pure custom-property update,
      // no DOM nodes added.
      const progressPct = durationMs > 0 ? Math.min(100, (Math.max(0, durationMs - remainingMs) / durationMs) * 100) : 100;

      if (PageStatus._displayKey === key) {
        const LI = PageStatus._ensureEtaLi();
        const BUST = LI.querySelector('em.cache-bust');
        BUST.classList.add('counting-down');

        // Counting down: decimal seconds, same precision as before. Once
        // the estimate elapses, the display doesn't just freeze at 0 — it
        // starts counting back up from 0 to show how far past the estimate
        // this fetch has run, whole seconds only (floor, so it starts at 0
        // exactly as remainingMs crosses zero).
        const displayText = overtime
          ? String(Math.floor(-remainingMs / 1000))
          : (remainingMs / 1000).toFixed(1);

        BUST.style.setProperty('--eta-progress', progressPct.toFixed(2));
        BUST.textContent = displayText;
        BUST.dataset.etaRemainingMs = String(Math.round(remainingMs));
        BUST.classList.toggle('overtime', overtime);
      }

      document.dispatchEvent(new CustomEvent('ts:page-status:progress', {
        detail: { key, ids: [...load.ids], progressPct, overtime },
      }));
    };

    tick();
    load.timer = setInterval(tick, COUNTDOWN_TICK_MS);
  }

  // Call once, when the fetch that beginLoadTiming(key, ...) timed has
  // resolved. cacheStatus ('hit'|'miss') is which bucket this particular
  // fetch landed in (see refreshPageDomain, which reads it straight off the
  // bundle/analytics response's own _cache flag).
  //
  // Doesn't necessarily finish the timer immediately — the network response
  // landing isn't the same moment the page is done "fetching" as far as
  // anyone looking at the screen is concerned; every grid still has its own
  // synchronous rebuild to run off the ts:domain:updated event this call
  // typically precedes. So this only stashes the outcome and defers to
  // _tryFinishLoadTiming(), which actually stops this key's countdown once
  // none of ITS ids are still reporting 'fetching' — re-checked every time
  // any grid's state changes (see setState()).
  static completeLoadTiming(key, cacheStatus) {
    const load = PageStatus._loads.get(key);
    if (!load) return;
    load.pendingCacheStatus = cacheStatus;
    PageStatus._tryFinishLoadTiming(key);
  }

  static _anyGridFetching() {
    for (const LI of PageStatus._items.values()) {
      if (LI.dataset.state === 'fetching') return true;
    }
    return false;
  }

  static _anyIdsFetching(ids) {
    for (const id of ids) {
      if (PageStatus._items.get(id)?.dataset.state === 'fetching') return true;
    }
    return false;
  }

  // Re-checked from setState()/remove() for every load still awaiting
  // completion — cheap, since a page rarely has more than a couple of loads
  // in flight at once.
  static _tryFinishAllPending() {
    for (const key of [...PageStatus._loads.keys()]) {
      PageStatus._tryFinishLoadTiming(key);
    }
  }

  static _tryFinishLoadTiming(key) {
    const load = PageStatus._loads.get(key);
    if (!load || load.pendingCacheStatus === undefined) return; // nothing awaiting finish
    if (PageStatus._anyIdsFetching(load.ids)) return; // still visibly fetching — keep the countdown running

    const cacheStatus = load.pendingCacheStatus;
    PageStatus._loads.delete(key);
    if (load.timer != null) clearInterval(load.timer);
    if (![...PageStatus._loads.values()].some(l => l.timer != null)) {
      document.body.classList.remove('counting-down');
    }

    const elapsedMs = performance.now() - load.loadStart;
    const bucket = (cacheStatus === 'hit' || cacheStatus === 'miss') ? cacheStatus : 'unknown';

    if (bucket !== 'unknown') {
      const all = PageStatus._readEtaHistory();
      const entry = all[key] ?? {};
      entry[bucket] = [...(entry[bucket] ?? []), elapsedMs].slice(-ETA_SAMPLE_LIMIT);
      all[key] = entry;
      PageStatus._writeEtaHistory(all);
    }

    if (PageStatus._displayKey !== key) return; // some other load owns the shared widget now

    // This key was the display owner, but another load (e.g. Analytics
    // still self-fetching while the bundle fetch already landed) is still
    // genuinely in flight — hand the widget off to it instead of freezing
    // on this finished key's summary while the real one keeps ticking
    // unseen. Its own next tick (at most COUNTDOWN_TICK_MS away) repaints
    // the widget for real, so no need to synthesize a paint here.
    const remainingKeys = [...PageStatus._loads.keys()];
    if (remainingKeys.length) {
      PageStatus._displayKey = remainingKeys.at(-1);
      return;
    }

    const LI = PageStatus._ensureEtaLi();
    LI.classList.remove('estimating');
    LI.classList.add('measured');
    LI.dataset.cacheStatus = bucket;
    LI.dataset.etaActualMs = String(Math.round(elapsedMs));

    const CACHED = LI.querySelector('em.cached');
    const BUST   = LI.querySelector('em.cache-bust');
    CACHED.classList.toggle('actual', bucket === 'hit');
    BUST.classList.toggle('actual', bucket === 'miss');
    BUST.classList.remove('counting-down');
    delete BUST.dataset.etaRemainingMs;

    const seconds = `${(elapsedMs / 1000).toFixed(1)}s`;
    if (bucket === 'hit') CACHED.textContent = `cached ${seconds}`;
    else if (bucket === 'miss') BUST.textContent = seconds;
  }

  static remove(id) {
    PageStatus._editingIds.delete(id);

    const LI = PageStatus._items.get(id);
    if (!LI) {
      PageStatus._recomputeEditingState();
      return;
    }
    LI.remove();
    PageStatus._items.delete(id);
    PageStatus._recomputeOverallState();
    PageStatus._recomputeEditingState();
    PageStatus._tryFinishAllPending();
  }
}
