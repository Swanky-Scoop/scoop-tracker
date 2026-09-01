# Dock control freshness: refresh button + repaint-on-reopen

**Update (2026-09-01):** the manual refresh buttons (per-control and the
dock-wide "Refresh all") described below were built, then removed once the
1s background poll + repaint gate proved reliable enough on their own —
Gus judged them redundant rather than a useful fallback. The reopen-on-open
fetch (§ below) and the background poll both remain. Left the rest of this
doc as-is for the history; see the removal commit on `dock-refresh` for
what actually changed.

Planning doc for the `dock-refresh` branch. No implementation yet — this
records the agreed design so a future session (or a future you) doesn't
have to re-derive it. Full plan, including verification steps, also lives
at `C:\Users\gusre\.claude\plans\refactored-dreaming-fern.md` on the
machine that authored this.

## Problem

Dock controls (`assets/ui/_dockable.js`) only fetch data once on page load
and again after their own save (`ScoopAPI.refreshPageDomain`,
`assets/data/scoop-api.js`) — the client never re-checks the server on its
own. Two gaps: minimizing and reopening a control shows stale data, and
there's no manual "get current data" affordance, per-control or dock-wide.

## Why not WebSockets

Already fully scoped in `websockets-migration.md` for a related
cross-device staleness problem, but blocked on an unresolved hosting
question (shared Namecheap hosting, no persistent-process support
confirmed). That doc's own conclusion — and this planning conversation's —
converged on the same cheaper answer: a background poll, layered under the
same on-demand refresh mechanisms a future WebSockets push would also feed
into. Everything below routes through the existing `refreshPageDomain()` /
`_onDomainUpdated` pipeline, so a later WebSockets migration would only
replace the *poll*, not the buttons or reopen behavior. Manual refresh
buttons stay useful even after a hypothetical WebSockets migration: missed
pushes (backgrounded tab, reconnect gaps) need a fallback, and the
per-grid dirty-edit gate (below) means a stale-but-dirty control always
needs an explicit human decision to discard a draft and pull fresh data,
regardless of transport.

## Decisions locked in

- Poll covers **every mounted control** (open or minimized), not just
  visible ones.
- Check cadence: **1 second** (revised from an initial 5s — see
  "Reconciliation" below). Safe at 1s only with three things together,
  all required at this cadence: the cheap-check/expensive-fetch split
  (below), a **settle window** so a burst of saves collapses into one
  refetch per tab instead of one per save, and **visibility gating** —
  hidden tabs poll nothing. Pollers are bounded to authenticated staff
  devices with a tab open (a handful, not public traffic); 1s of *that*
  is cheap, but only once hidden tabs are excluded from the count.
- Refresh button: **per-control is primary, plus one global "refresh
  dock" button.**
- Per-grid dirty/unsaved-edit protection already exists (`dirtySet` check
  in `_onDomainUpdated`, `assets/ui/_list.js`) and needs no changes — it
  already applies per grid instance regardless of trigger source. The
  global `hasUnsavedEdits()` check stays reserved for the hard-reload
  watchers (stale-version, idle-logout) where it actually belongs — using
  it to gate the poll's refetch would let one long-idle dirty cell stall
  freshness for every other mounted control.

## Design

**Backend** — extend the *existing* `/wp-json/scoop/v1/version` route
(`includes/_routes.php`) rather than add a new one: it already returns
`{ version: filemtime(app.js) }` for the stale-JS check, on the same
`scoop_require_authenticated_user_read_only` permission callback. Add one
field: `'cache_version' => scoop_cache_version()` (`includes/_cache.php` —
a single `get_option` read, no Pods query). One route, one field, reused
by both the stale-JS reload and the new data-freshness poll below — no new
endpoint needed.

**Client poll** — new `ScoopAPI.watchForDataChanges({ pollMs = 1000,
settleMs = 2500, backoffCapMs = 30000 } = {})` in `assets/data/scoop-api.js`.
Per tick:
1. Skip entirely if `document.hidden` — a `visibilitychange` listener runs
   one immediate check when the tab regains visibility instead, which is
   also what closes the "control was minimized in a backgrounded tab"
   gap (see reopen fetch below).
2. GET the (now-extended) `/version` route. On failure, back off
   exponentially up to `backoffCapMs`; a success resets the backoff.
3. Compare `cache_version` to the last-seen value (baseline = first
   successful poll).
4. **Settle window**: don't refetch on the first differing observation —
   arm `pendingSince = now` and only refetch once the observed version has
   held steady for `settleMs`. Turns a burst of several saves in a row
   (e.g. moving three tubs) into one refetch per tab, not one per save.
5. On refetch: `refreshPageDomain({ force: true, info: { name:
   'background poll' } })` (unscoped — coarse invalidation, a bare version
   bump doesn't tell us which pod changed). Not gated on `hasUnsavedEdits()`
   — see "Decisions locked in" above.
6. **Consolidates `watchForStaleVersion`** into this same loop (it already
   polls the same endpoint, just on a 20-minute timer) — one fewer
   `setInterval`, same mtime-mismatch-plus-no-unsaved-edits reload logic,
   now re-checked every tick instead of on its own schedule.

Called from `assets/app.js` alongside the other watchers (replaces the
separate `watchForStaleVersion()` call).

**Reopen catch-up fetch** — goes on the **user-click open path only**
(`_bindDockToggle()`'s click handler in `assets/ui/_dockable.js`), *not*
inside `_setToggled()` itself. `_setToggled(true)` is also called from
`dockToggle()`'s hash-restore path during `mountAllGrids()` — *before any
data has loaded* (confirmed via code read: `assets/ui/_dockable.js`,
`dockToggle()`) — so hooking `_setToggled` broadly would fire redundant
forced fetches, racing the initial load, once per control a shared
`#dock=` link happens to restore open. On a genuine click-open:
`refreshPageDomain({ force: true, types: [this.name], info: { name:
'control re-open' } })`. Mostly confirms already-fresh data cheaply (the
1s poll usually already caught it); its real job is the tab-was-hidden or
poll-in-backoff gap. Confirmed via code read that `_onDomainUpdated` has
no visibility/`.toggled` check — a minimized control's listener already
repaints on `ts:domain:updated` same as an open one, so the poll alone
mostly keeps minimized controls current; this fetch is the gap-filler for
what the poll couldn't reach while hidden.

**Refresh buttons** — both call the same `refreshPageDomain({force:true,
...})`, no new fetch/repaint path:
- Per-control: small icon built alongside `TOGGLE` in `_dockable.js`,
  moved into the toolbar together with it in `dockToggle()`,
  `stopPropagation()` so it doesn't also toggle open/closed. Scoped to
  `types: [this.name]`.
- Global: static button added to `includes/shortcode.php`'s `[scoop_dock]`
  toolbar markup, same pattern as the existing static "WP Admin"/"Log out"
  links. Unscoped `refreshPageDomain({force:true})`.

Both get the existing fetching-ring/ETA indicator for free
(`_bindPageStatusToggle`) since they ride the same instrumented
`refreshPageDomain` call.

## Accepted residual risk

Multiple devices can still detect the same version bump within the same
settle window and each fire one full bundle fetch — same stampede shape
any coarse invalidation scheme has (including a future WebSockets Phase
1), just per-tab instead of per-save now that the settle window collapses
each burst. Bounded by the WP transient cache and each tab's own
`_domainInflight` chaining; only triggered on genuine edits, not every
tick — poll cadence (1s vs 5s) doesn't change how often this fires, only
how quickly a change is *detected*.

## Files to touch

- `includes/_routes.php` — add `cache_version` to the existing `/version`
  response
- `assets/data/scoop-api.js` — new `watchForDataChanges()` (replaces
  `watchForStaleVersion()`)
- `assets/app.js` — swap the watcher call
- `assets/ui/_dockable.js` — click-open-only reopen fetch, per-control
  refresh button
- `includes/shortcode.php` — global refresh button markup
- `assets/css.css` — styling for the new refresh icon(s)

## Reconciliation with Webel's `CONTROL-REFRESH.md` (origin/main, 2026-08-31)

Webel (an automated agent, `app/webel-ai`) independently produced a
parallel plan for the same feature, pushed directly to `main` as
`CONTROL-REFRESH.md` (not via PR — worth a separate conversation about
review process, not addressed here). Its self-described "Appendix
comparison" against an earlier snapshot of this doc overstated itself as
an already-negotiated merge — it wasn't; the two plans were never actually
reconciled between agents. Independently verifying its claims against this
doc and the real code: its architecture is the same core design (version
poll → `refreshPageDomain` → existing repaint pipeline), and three of its
four "gaps" in this doc were real and are folded in above — the click-open
mount-guard bug, the settle window, and visibility gating/backoff/endpoint
consolidation. Its fourth claim (this doc's route lacked a
`permission_callback`) was inaccurate — this doc always specified
`scoop_require_authenticated_user_read_only`, same as the `/version`
precedent. Poll interval was a genuine open disagreement (5s here vs. 1s
there), not a gap — resolved at 1s per Gus, who confirmed giving that
number to Webel directly in a separate conversation.
