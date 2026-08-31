# Dock control freshness: refresh button + repaint-on-reopen

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
- Check cadence: **5 seconds**, but split into a cheap check + an expensive
  fetch (see below) specifically so 5s is safe on shared hosting.
- Refresh button: **per-control is primary, plus one global "refresh
  dock" button.**
- Per-grid dirty/unsaved-edit protection already exists (`dirtySet` check
  in `_onDomainUpdated`, `assets/ui/_list.js`) and needs no changes — it
  already applies per grid instance regardless of trigger source.

## Design

**Backend** — new cheap route `GET /wp-json/scoop/v1/cache-version` in
`includes/_routes.php` (standalone `register_rest_route`, following the
existing `/version` precedent), returning `scoop_cache_version()`
(`includes/_cache.php` — a single `get_option` read, no Pods query).
Registered in `SCOOP.routes` via `enqueue.php`'s `scoop_client_routes()`.

**Client poll** — new `ScoopAPI.watchForBackgroundRefresh({ checkIntervalMs
= 5000 })` in `assets/data/scoop-api.js`, matching the existing
`setInterval`-watcher idiom (`watchForStaleVersion` et al. in the same
file). Every tick hits the cheap version-check; only on an actual version
change does it call `refreshPageDomain({ force: true })` (unscoped — coarse
invalidation, we don't know which pod changed from a bare version bump).
Not gated on `hasUnsavedEdits()` globally — that would block every mounted
control over one dirty field; the existing per-grid gate already handles
it correctly. Called from `assets/app.js` alongside the other watchers.

**Reopen catch-up fetch** — `Dockable._setToggled` (`assets/ui/_dockable.js`)
fires a scoped `refreshPageDomain({ force: true, types: [this.name] })`
when a control opens, to cover the case where a background tab's poll
timer got throttled while minimized. Confirmed via code read that
`_onDomainUpdated` has no visibility/`.toggled` check — a minimized
control's listener already repaints on `ts:domain:updated` same as an open
one, so the poll alone mostly keeps minimized controls current; this is
just the gap-filler.

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

Multiple devices can detect the same version bump within the same ~5s
window and each fire a full bundle fetch simultaneously — same stampede
shape any coarse invalidation scheme has (including a future WebSockets
Phase 1). Bounded by the WP transient cache and each tab's own
`_domainInflight` chaining; only triggered on genuine edits, not every
tick.

## Files to touch

- `includes/_routes.php` — new `/cache-version` route
- `includes/enqueue.php` — register it in `SCOOP.routes`
- `assets/data/scoop-api.js` — new `watchForBackgroundRefresh()`
- `assets/app.js` — call the new watcher
- `assets/ui/_dockable.js` — reopen catch-up fetch, per-control refresh
  button
- `includes/shortcode.php` — global refresh button markup
- `assets/css.css` — styling for the new refresh icon(s)
