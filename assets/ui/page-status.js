
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
  static _loadStart = null;
  static _loadKey = null;
  static _countdownTimer = null;
  static _pendingCacheStatus = undefined; // set by completeLoadTiming(), consumed once nothing's still 'fetching'

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
    PageStatus._tryFinishLoadTiming();
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

  // Call once, as early as possible in the initial page-load mount — starts
  // the clock and shows both rolling-average estimates this page (path +
  // grid-type combination) has on this device: one for a cache-bust load,
  // one for a cached reload. Which one actually applies isn't known until
  // completeLoadTiming() — see the header comment.
  //
  // defaultMs is the cache-bust countdown's starting point when this page
  // has no 'miss' history yet — callers with domain knowledge of which grid
  // types tend to run slow cold queries (e.g. ScoopAPI, for DateActivity/
  // BatchHistory) can pass a larger one than the generic ETA_DEFAULT_MS.
  static beginLoadTiming(key, defaultMs = ETA_DEFAULT_MS) {
    PageStatus._loadStart = performance.now();
    PageStatus._loadKey = key;

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
    PageStatus._startBustCountdown(BUST, missAvg != null ? missAvg : defaultMs);
  }

  static _startBustCountdown(EM, durationMs) {
    PageStatus._stopBustCountdown();

    const deadline = performance.now() + durationMs;
    EM.classList.add('counting-down');
    // Global hook so any page chrome (not just the PAGE-STATUS list itself)
    // can react while a cache-busting refresh is in flight.
    document.body.classList.add('counting-down');

    const tick = () => {
      const remainingMs = deadline - performance.now();
      const overtime = remainingMs < 0;

      // Counting down: decimal seconds, same precision as before. Once the
      // estimate elapses, the display doesn't just freeze at 0 — it starts
      // counting back up from 0 to show how far past the estimate this
      // fetch has run, whole seconds only (floor, so it starts at 0 exactly
      // as remainingMs crosses zero).
      const displayText = overtime
        ? String(Math.floor(-remainingMs / 1000))
        : (remainingMs / 1000).toFixed(1);

      // Drives the CSS ring (see css.css's .cache-bust.counting-down rules)
      // — 0 at start, 100 once the estimate elapses, held at 100 through
      // overtime. Pure custom-property update, no DOM nodes added.
      const progressPct = durationMs > 0 ? Math.min(100, (Math.max(0, durationMs - remainingMs) / durationMs) * 100) : 100;
      EM.style.setProperty('--eta-progress', progressPct.toFixed(2));
      EM.textContent = displayText;
      EM.dataset.etaRemainingMs = String(Math.round(remainingMs));
      EM.classList.toggle('overtime', overtime);
    };

    tick();
    PageStatus._countdownTimer = setInterval(tick, COUNTDOWN_TICK_MS);
  }

  static _stopBustCountdown() {
    if (PageStatus._countdownTimer != null) {
      clearInterval(PageStatus._countdownTimer);
      PageStatus._countdownTimer = null;
    }
    document.body.classList.remove('counting-down');
  }

  // Call once, when the fetch that beginLoadTiming() timed has resolved.
  // cacheStatus ('hit'|'miss') is which bucket this particular fetch landed
  // in (see refreshPageDomain, which reads it straight off the bundle
  // response's own _cache flag).
  //
  // Doesn't necessarily finish the timer immediately — the network response
  // landing isn't the same moment the page is done "fetching" as far as
  // anyone looking at the screen is concerned; every grid still has its own
  // synchronous rebuild to run off the ts:domain:updated event this call
  // typically precedes. So this only stashes the outcome and defers to
  // _tryFinishLoadTiming(), which actually stops the countdown once no
  // registered grid is still reporting 'fetching' — re-checked every time
  // any grid's state changes (see setState()).
  static completeLoadTiming(cacheStatus) {
    if (PageStatus._loadStart == null || !PageStatus._loadKey) return;
    PageStatus._pendingCacheStatus = cacheStatus;
    PageStatus._tryFinishLoadTiming();
  }

  static _anyGridFetching() {
    for (const LI of PageStatus._items.values()) {
      if (LI.dataset.state === 'fetching') return true;
    }
    return false;
  }

  static _tryFinishLoadTiming() {
    if (PageStatus._pendingCacheStatus === undefined) return; // nothing awaiting finish
    if (PageStatus._anyGridFetching()) return; // still visibly fetching — keep the countdown running

    const cacheStatus = PageStatus._pendingCacheStatus;
    PageStatus._pendingCacheStatus = undefined;
    PageStatus._stopBustCountdown();

    const elapsedMs = performance.now() - PageStatus._loadStart;
    const key = PageStatus._loadKey;
    const bucket = (cacheStatus === 'hit' || cacheStatus === 'miss') ? cacheStatus : 'unknown';

    if (bucket !== 'unknown') {
      const all = PageStatus._readEtaHistory();
      const entry = all[key] ?? {};
      entry[bucket] = [...(entry[bucket] ?? []), elapsedMs].slice(-ETA_SAMPLE_LIMIT);
      all[key] = entry;
      PageStatus._writeEtaHistory(all);
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

    PageStatus._loadStart = null;
    PageStatus._loadKey = null;
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
    PageStatus._tryFinishLoadTiming();
  }
}
