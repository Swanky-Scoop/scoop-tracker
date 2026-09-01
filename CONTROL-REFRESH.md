# Localized control refresh — version-gated polling + on-demand refresh

**Status:** IMPLEMENTED on branch `dock-refresh` (poll + reopen refresh,
validated in-box; awaiting Gus's merge decision). Original planning
conversation 2026-09-01; implementation rides the merge of this plan with the
parallel `DOCK-CONTROL-REFRESH.md` — see the Appendix for the comparison and
the two points conceded to it. **Update:** the manual refresh buttons
(§5 — per-control and dock-wide) were removed after landing; the 1s poll +
repaint gate made them redundant. §5 is kept below for history only.

**Companion docs:** [PARTIAL-REFRESH.md](PARTIAL-REFRESH.md) (the repaint machinery
this rides on), [websockets-migration.md](websockets-migration.md) (why not
sockets), [DOCKING.md](DOCKING.md) (dock mechanics).

## Requirements (Gus, 2026-09-01)

1. Every dock control gets a **refresh button** that fetches fresh data and repaints.
2. **Re-opening a minimized control** repaints it with fresh data.
3. Decisions made during planning:
   - Poll frequency: **1 second** (not minutes).
   - Refresh scope: **page-wide** — all controls refresh together, because most
     share inter-related data.
   - Buttons on **both** each control and the dock.
   - Pause conditions as proposed (see §6).

## Approach: version-gated polling + on-demand refresh

Alternatives considered and rejected:

- **WebSockets** (Gus's original option 1) — needs a long-running process; shared
  LiteSpeed/cPanel hosting can't run one (full analysis in
  [websockets-migration.md](websockets-migration.md), incl. auth/nonce mismatch and
  the WP write-hook bridge problem). Even its cheapest realistic phase — coarse
  push-to-invalidate, clients re-fetch over REST — delivers exactly the signal this
  plan's version poll delivers, minus new standing infrastructure. Closed unless
  hosting changes.
- **Unconditional 1s bundle polling** (naive reading of Gus's option 2) — every
  open tab would fetch the full bundle every second, 100% of the time, to catch
  the <1% of seconds in which anything changed. The version gate removes that
  constant cost: between saves the poll is a few-hundred-byte response; the
  expensive bundle fetch happens only when the server says something changed.
- **Server-Sent Events** — same persistent-process blocker as WebSockets.
- **Per-control polling scopes** — controls share inter-related data (the reason
  the scope decision is page-wide), and the server cache is already one global
  version (see below). Per-control scopes would fragment that signal and
  re-derive overlap per control for no benefit.

The design in one sentence: **a 1s timer polls a cheap version endpoint; when the
server's data version has moved (and settled), the tab refetches the page-wide
bundle through the existing `refreshPageDomain({force:true})` path, which already
repaints every control safely — plus two explicit on-demand triggers (buttons and
control re-open) that use the same path.**

### Why this composes so cheaply

The client already has everything a "refresh and repaint" needs:

- `ScoopAPI.refreshPageDomain()` ([scoop-api.js]) fetches the bundle and dispatches
  `ts:domain:updated`; **every** mounted control reacts via
  `List._onDomainUpdated` — additive patch path for most grids, destructive
  rebuild for server-filtered grids (`DateActivity`, `BatchHistory`) and
  `rebuildOnRefresh` models (`CabinetWorkflowTile`). No new repaint code.
- Safety guards for background refreshes already exist and are exercised by
  today's save-triggered refreshes: `hasUnsavedEdits()` (dirty inputs / autosave
  in flight — the global form of this gate is now reserved for the hard-reload
  checks, see §3 step 5), `_groupHasFocus()` deferral (a focused group's DOM
  catch-up waits for blur), FindIt `refresh()` skipped when the cell is
  dirty/open/focused.
  A poll-triggered refresh is indistinguishable to this machinery from any other
  refresh a grid didn't cause.
- In-flight correctness: forced refreshes arriving mid-fetch already chain a
  genuine follow-up fetch instead of reusing the stale promise
  (PARTIAL-REFRESH.md §6). Poll-triggered refreshes get this for free.
- Polling precedent: `watchForStaleVersion()` already polls the `/version`
  endpoint on a timer.

## 1. The version signal

`includes/_cache.php` keys the bundle transients on one global integer,
`scoop_cache_version()`, bumped on every relevant `save_post`/trash/delete
(irrelevant post types are filtered out; slow-changing entity types also bust
their per-type entity cache, but the global still bumps for them too — every
bundle-affecting write moves this number). The audit/analytics cache keys are
derived from it as well.

So "has anything changed since the tab last looked?" = "has
`scoop_cache_version` changed?" — one integer comparison, no per-entity diffing.

## 2. Server change (tiny)

`/scoop/v1/version` (`includes/_routes.php`, `register_rest_route('scoop/v1',
'/version')`) currently returns only `{ version: filemtime(app.js) }` for the
stale-JS check. Extend the same response with:

```php
'cache_version' => scoop_cache_version(),
```

Same permission callback (`scoop_require_authenticated_user_read_only`), same
route, one added field. No schema, no new endpoint, no infra.

## 3. The poll loop (`ScoopAPI.watchForDataChanges`)

One `setInterval` loop in `scoop-api.js`, started from `app.js` next to the
existing `watchFor*` calls. Defaults as named constants:

```js
watchForDataChanges({ pollMs = 1000, settleMs = 2500, backoffCapMs = 30000 } = {})
```

Behavior per tick:

1. **Hidden tab → skip.** `document.hidden` tabs don't poll at all; a
   `visibilitychange` listener runs one immediate check when the tab becomes
   visible again (a tab open in the background while someone works in another
   window is exactly the "reopen needs fresh data" case — catching up on focus
   closes that gap without paying for hidden polling).
2. **GET `/version`.** On failure, exponential backoff: next delay
   `min(pollMs * 2^n, backoffCapMs)`; a success resets n. Poll failures are
   silent (console + backoff) — the freshness system must never be the thing
   that makes a struggling host worse, and the tab still works fine manually.
3. **Compare `cache_version` to last-seen** (baseline = first successful poll;
   the initial bundle fetch has already run by then, and a save landing inside
   that first second simply produces one extra catch-up refetch — correct, not
   harmful).
4. **Settle window before refetching.** Staff often make several related saves
   in a burst (moving three tubs in a row). First differing observation arms
   `pendingSince = now`; the refetch fires only once the observed version has
   stayed the same for `settleMs`. One burst → one refetch per tab of the final
   state. Freshness cost is bounded at roughly `settleMs + pollMs`; server cost
   drops from N fetches per burst to one.
5. **Refetch when the settle window closes** — deliberately NOT gated on a
   global `hasUnsavedEdits()` (see below):
   - `refreshPageDomain({ force: true, info: { name: 'background poll' } })`
     and update last-seen. The `info.name` flows into the existing
     `PageStatus.setTrigger()` so the status pill says what caused the fetch.
   - **Per-grid, not global, edit protection.** One long-idle dirty cell must
     not stall page-wide freshness for as long as it stays dirty. What needs
     protecting is the edit itself, and `_onDomainUpdated` already does that
     per grid (`dirtySet` cells keep their values, `_groupHasFocus` defers the
     focused group's patch, FindIt skips dirty/open/focused cells). The global
     `hasUnsavedEdits()` gate stays only where it belongs: the hard-reload
     checks (the `watchForStaleVersion` consolidation below), which would
     actually destroy an edit.
6. **Consolidate `watchForStaleVersion` into this loop.** It already polls the
   same endpoint (20-min interval) for the app.js-mtime reload. Riding the 1s
   loop removes the second timer; its semantics are unchanged (mtime mismatch +
   no unsaved edits → cache-busting reload). Its 20-min cadence becomes "check
   every tick, it's one integer comparison we're already doing."

### The repaint gate — fetch ≠ repaint (added after the 2026-09-01 feel-test)

The plan separated "check" from "fetch" but not "fetch" from "repaint":
invalidation is global (one `scoop_cache_version` for the whole site), and
`_startDomainFetch` dispatched `ts:domain:updated` unconditionally — so any
write, anywhere, by anyone, repainted every control on every open tab even
when nothing a grid displayed had changed. The contract now enforced:
**a control repaints only if (a) the data it shows actually changed,
(b) the user explicitly asked (per-control refresh, dock Refresh-all,
click-open — these carry `demandRepaint: true`), or (c) it was minimized
and re-opened (also demandRepaint).**

Mechanics: `refreshPageDomain()` threads `demandRepaint` into the
`ts:domain:updated` detail; `List._onDomainUpdated` compares the model's
post-`setDomain` render input (`rows`/`rowGroups` — plain data, no DOM) to
the last-painted signature and skips the repaint (settling its PageStatus
pill to 'fresh' instead of flashing 'fetching') when they match and the
refresh wasn't demanded. Zero-rows models (CabinetWorkflow's tiles, which
derive straight from the domain) fall back to a whole-domain comparison.
The signature updates on every post-init pass, so a demanded repaint can't
poison the next comparison. This also neutralizes the global-invalidation
cost *as seen by the user* — an unrelated save's fetch diff-equals to "no
change" on every unaffected grid. (Scoped *server* fetches — not refetching
unchanged entities at all — remain the documented follow-up; the client
gate deliberately doesn't depend on it.) Known bounded churn: a
`relativeTimeFields` column's rendered "N hours ago" coarsens with wall
time, so such a grid (FlavorTub) may repaint up to once a minute boundary
with unchanged data — data-visible, and far rarer than the old behavior.

## 4. Refetch-on-reopen (requirement 2)

Dock open/close is `Dockable._setToggled()` (`assets/ui/_dockable.js`), reached
from two paths: the **user click** (dock icon click / toolbar TOGGLE, handled in
`_bindDockToggle()`) and the **hash-restore path** (`dockToggle()` during
`mountAllGrids()`, which deliberately runs before any data loads — DOCKING.md).

The hook goes on the **user-click open only**, not inside `_setToggled()` and not
on hash-restore:

- `_setToggled(true)` is shared by restore and click; a fetch there would fire
  once per control during every mount pass, racing the initial fetch it would
  duplicate.
- On a real click-open, fire-and-forget a forced refresh scoped to what this
  control renders:

  ```js
  // Dockable — called only from the click handler's open transition.
  _refreshOnReopen() {
    this.api?.refreshPageDomain?.({
      force: true,
      types: [this.name], // this control's own entry in scoop_bundle_specs()
      info: { name: 'control re-open' },
    })?.catch(() => {}); // failures already toast via the fetch path
  }
  ```

  Why `types: [this.name]` and not `scopedRefreshTypes()`: that helper is
  write-oriented (SCOOP.refreshScope's writesPods) and falls back to the full
  page union for any read-only trigger — backwards for a "refresh THIS view"
  action. Every mounted type has its own bundle spec (mountAllGrids requires
  it), so `[this.name]` fetches exactly what the view displays — minimal and
  complete (e.g. CabinetWorkflow's own spec pulls
  cabinet/slot/flavor/tub/allergen/use/location). One caveat, by design:
  `repaintOnRefresh === false` views (Batch's create-widget) ignore refreshes
  they didn't cause, so their button fetches but deliberately doesn't repaint
  the form. With the 1s poll running, this usually confirms already-fresh data
  cheaply (bundle cache is warm between saves); its real job is guaranteeing
  requirement 2 when the tab was hidden (no polling) or the poll is in
  backoff.

Escape-close then re-click counts as re-open — it's the same click path.

## 5. Buttons (requirement 1)

**Per-control refresh button.** Built alongside the dock toggle so every dockable
view gets it in one place: `Dockable._bindDockToggle()` builds and appends a
`this.REFRESH` button next to the existing `this.TOGGLE`. **Revised per Gus's
2026-09-01 feel-test:** the per-control button does NOT ride the shared
`.toolbar` — it stays attached to its own `.scoop-grid` host, visible only when
the control is docked AND open (top-right corner of the control, with the same
fetching ring the toolbar buttons have), while the toolbar keeps exactly ONE
refresh button: the dock chrome's Refresh-all below. Views with no server data
at all (IframePanel — ProductionPlan/esr/`[scoop_iframe]`; an iframe is its own
freshest source, there is nothing to fetch) declare
`this.dockRefreshEligible = false` and get no button. Action: the same
`_refreshOnReopen()`-shaped scoped forced refresh, `info: { name: 'refresh button' }`.

**Dock-level refresh button.** One button in the dock chrome itself
(`[scoop_dock]` markup, `includes/shortcode.php`) = full page-union refresh —
"everything, now". Decoupled wiring to avoid threading the api instance into
chrome: the button dispatches `ts:page:refresh-requested` on `document`;
`ScoopAPI` listens for that event and runs `refreshPageDomain({ force: true })`.
Same pattern as `Dockable.bindEscapeToClose()` (document-level listener, queries
state fresh, no registry).

## 6. Pause conditions (agreed)

| Condition | Poll | Refetch |
|---|---|---|
| Tab hidden (`document.hidden`) | skip | n/a (no poll) |
| Unsaved edits (a dirty cell mid-edit) | runs (cheap) | proceeds — per-grid guards protect just the dirty cell (see §3 step 5) |
| Version poll failing | exponential backoff to 30s | n/a |
| Refresh already in flight | runs | chained by `refreshPageDomain` (existing) |

Idle logout / inventory-change flush are untouched — they key off real-activity
tracking, which background version polls deliberately do not count as activity
(`_trackRealActivity()` already excludes its own timers).

## 7. Touch points

| File | Change |
|---|---|
| `includes/_routes.php` | +1 field on the existing `/version` response |
| `includes/shortcode.php` | dock-chrome refresh button markup |
| `assets/css.css` | `.gridRefresh` styling mirroring `.gridToggle`'s docked-view rules; dock chrome button |
| `assets/data/scoop-api.js` | `watchForDataChanges()` (poll/settle/backoff/visibility), `ts:page:refresh-requested` listener, `watchForStaleVersion` consolidation |
| `assets/ui/_dockable.js` | `REFRESH` button build in `_bindDockToggle()`, `_refreshOnReopen()`, click-open hook |
| `assets/app.js` | start `watchForDataChanges()` |

Icon font: one new glyph regenerated through the documented `icon-font-generator`
pipeline (dev-time, output committed). No build step, no new dependencies, no
infra — consistent with the repo's constraints.

## 8. Costs, knobs, and known trade-offs

**Server load.** Baseline: ~1 tiny request/second per open tab (WP bootstrap + an
option read). Each save burst: one bundle fetch per open tab after settle — which
is what every manual save already costs per tab today, so the poll adds no new
per-save cost. With a handful of floor tabs this is well within shared-hosting
range; worth a glance at the host resource panel the first week. Every cost knob
is one constant: `pollMs`, `settleMs`, `backoffCapMs`; hidden tabs pay nothing.

**Cold misses.** Every bundle fetch right after a save is a guaranteed cache
miss (performance.md #4). The settle window means a burst costs one cold fetch
per tab, not one per save. Same profile as today's manual saves.

**Additive-patch trade-off inherited.** Background refreshes never remove rows
(the accepted PARTIAL-REFRESH.md trade): a row that legitimately aged out lingers
until a sort/filter/rebuild. The poll neither worsens nor fixes this; it's listed
so it isn't mistaken for a poll bug.

**Stale-while-hidden, then catch-up.** A hidden tab stops polling; on becoming
visible (or on control re-open) it catches up within one poll + settle. This is
the intended behavior, not a gap — it's what makes hidden tabs free.

**PageStatus noise.** Every version-triggered refresh flips affected controls'
status pills to 'fetching' briefly — the same thing that happens today on every
save, now attributable via the trigger label ('background poll').

**Out of scope:** fine-grained delta payloads over the wire, per-control poll
scopes, WebSockets/SSE, any write-path or schema change, analytics endpoints
outside the bundle (if any mounted view reads a non-bundle endpoint, wiring its
refresh into the same trigger is a small follow-up — verify during implementation).

## 9. Verification plan

- `node --check` on touched assets; `php -l` on touched PHP (existing gates).
- Unit suites unaffected (no touched logic has a suite; `tests/unit` stays green).
- Manual checklist on test.swanky.ink:
  1. Two tabs open; save in tab A → tab B converges within ~`settleMs + pollMs`.
  2. Minimize a control; change its data elsewhere; re-open → fresh within the
     refetch.
  3. Control refresh button → scoped fetch, repaint, no focus steal.
  4. Dock refresh button → full page-union fetch.
  5. Hidden tab (background window) → zero `/version` traffic (Network tab);
     visible again → immediate catch-up.
  6. Type mid-edit into a TextIt/FindIt cell while an external save lands →
     field never clobbered; deferred patch applies on blur.
  7. Deploy mid-session → stale-JS reload still fires via the consolidated check.

## 10. Sizing

~150 lines of JS across three files, ~5 lines PHP, ~15 lines CSS, one icon glyph.
One focused sitting plus the verification session above. No schema work, no
Schema Sync, no deploy-pipeline changes (the usual rsync ships it).

---

## Appendix — comparison with `DOCK-CONTROL-REFRESH.md` (origin/dock-refresh, d572d15)

Read and compared 2026-09-01. The two plans are ~80% identical: version-gated
polling → `refreshPageDomain({force:true})` → the existing `_onDomainUpdated`
repaint machinery; both button types; reopen fetch; no WebSockets; "a later WS
migration replaces only the poll, not the buttons/reopen." The differences below
were reconciled into this doc at merge time (§3 step 5, §4, §6, §8 above).

### Conceded to the parallel plan (adopted here)

1. **Per-grid, not global, dirty-edit gating on refetches** (§3 step 5, §6).
   The parallel plan correctly notes a global `hasUnsavedEdits()` gate would let
   one long-idle dirty cell stall page-wide freshness indefinitely. The per-cell
   guards in `_onDomainUpdated` protect exactly the edit itself; the global gate
   remains only for the hard-reload checks.
2. **`types: [this.name]` scope for per-control refresh actions** (§4, §5).
   `scopedRefreshTypes()` (this doc's original borrow) is write-oriented and
   falls back to the full page union for read-only triggers — wrong primitive
   for "refresh THIS view". A control's own bundle spec is authoritative and
   minimal for what it displays.

### Gaps the parallel plan should fold in

1. **Reopen-fetch mount guard.** Its hook lives in `_setToggled`, which the
   hash-restore path calls during `mountAllGrids()` *before any data loads*
   (`_dockable.js:222-224`; DOCKING.md "State model"). A shared link restoring
   3 docked controls would fire 3 extra forced fetches racing the initial load —
   and forced calls arriving mid-flight chain serially (PARTIAL-REFRESH.md §6),
   so N+1 cold-miss fetches on every page open. Guard: fetch only when
   `this.api._domain` already exists, or hook the click-open path only (this
   doc's §4).
2. **No settle window in the parallel plan.** Saves come in bursts; each save
   bumps the global version. Without a settle, every open tab cold-fetches once
   per save instead of once per burst (performance.md #4 cost × tab count). §3
   step 4's settle window converts a burst to one fetch per tab for ~2.5s of
   latency — small against a 1–5s poll cadence.
3. **No visibility gating or failure backoff.** Hidden tabs poll for nothing
   (and browser intensive-throttling then delivers a stale-on-wake tab — the
   reopen fetch covers it, but explicit `document.hidden` skip + catch-up is
   strictly cleaner); failing polls need exponential backoff so the freshness
   system never becomes the load problem. Also: its new `/cache-version` route
   section doesn't mention a `permission_callback` — the `/version` precedent
   (`scoop_require_authenticated_user_read_only`) should be carried over.
4. **`watchForStaleVersion` consolidation** (§3 step 6) — same endpoint, same
   pattern; riding one loop removes a second timer.

### Knob still open: 1s vs 5s poll

The parallel plan's 5s was sized for *unconditional* polling safety; with
visibility gating and the settle window, the cost difference largely dissolves
and detection latency is near-identical. Gus's 1s stands as the default in this
doc; `pollMs` is one constant if the host's resource panel says otherwise in
week one.

### Net

Single merged design: this doc's §3–§8 as amended. The per-control and dock-level
buttons build identically under either plan, so the UX feel-test Gus asked for is
unaffected by the reconciliation.

