# Smoother Partial Page Refresh

**Date:** 2026-07-29
**Scope:** Client-side ES modules (`assets/data/scoop-api.js`, `assets/ui/_list.js`) — when a page refetches data and how it repaints the DOM afterward.

## Problem

Grid/tile pages were repainting far more aggressively than the underlying data changes warranted. Two separate mechanisms were involved:

1. A stale-tab-after-deploy check reloaded the whole page on a tight timer.
2. Any domain refresh — even one caused by an unrelated grid elsewhere on the page — tore down and rebuilt every row of every grid from scratch.

## 1. Stale-version reload

`ScoopAPI.watchForStaleVersion()` ([assets/data/scoop-api.js](assets/data/scoop-api.js)) polls a lightweight `Version` endpoint and reloads the page if the server's `app.js` mtime has changed since load — added in commit `57b9d73` so a tab left open across a deploy doesn't keep running stale JS forever (no build/CDN cache to expire it otherwise).

- **Interval:** was 5 minutes, tuned to **20 minutes**. The check itself is cheap (one small GET) and only ever reloads on an actual version mismatch, so a shorter poll wasn't buying anything — it was just checking-and-standing-by four times more than needed.
- **Cache safety:** `location.reload()` re-requests the exact same URL, which risks being served from a browser/intermediate cache without ever reaching the server (and therefore without picking up `app.js`'s new `?ver=` query, which `includes/enqueue.php` already versions via `filemtime()`). The reload now navigates to the current URL with a `_ts=<timestamp>` param appended, guaranteeing a cache miss and a real round-trip to the server.

## 2. Additive-only ("patch") repaint

### The shared mechanism

Every domain refresh — a manual Save, an autosave's background sync, a cabinet-workflow action, a server-side filter change — ends the same way: `ScoopAPI.refreshPageDomain()` dispatches `ts:domain:updated` ([scoop-api.js](assets/data/scoop-api.js)), and **every** mounted grid/tile on the page reacts via `List._onDomainUpdated()` ([assets/ui/_list.js](assets/ui/_list.js)), regardless of which grid actually caused the refresh.

Previously, that reaction was always `_rebuildBodies()`: clear every group container, throw away every row's DOM, rebuild all of it from the fresh domain. So saving one row in one grid could reorder, regroup, or reflow every *other* grid on the page — visually jarring for something that grid had nothing to do with.

### What changed

`_onDomainUpdated` now branches:

- Grids with their own server-side filter (`getServerFilterParams` — currently `DateActivity`'s date range and `BatchHistory`) keep the old destructive `refresh()` → `_rebuildBodies()` path, because removing out-of-range rows *is* the filter.
- Every other grid gets the new additive-only path: `_patchRefresh()` → `_patchBodies()` → `_patchItemGroups()` + `_patchItems()`.

The patch path:

- **Existing rows** (matched by row id): classnames and badges are updated in place via `_patchFieldDecorations()`/`_patchRowClasses()`. The writeable FindIt/TextIt control (or read-only text/link) built by `_renderFieldValue()` is left completely untouched, so an in-progress edit or open dropdown elsewhere on the page can never be clobbered by someone else's save landing.
- **New rows/groups**: built and appended exactly as a full rebuild would.
- **Nothing is ever removed.** A row that's no longer in the fresh domain (for a grid without its own filter) just stays on screen as-is until something that legitimately should rebuild it happens — a sort click, a local filter change, or the next full page load.

### Mechanics

- `_renderFieldValue()` now stamps `EL.dataset.alertCase` at build time so the patch step knows exactly which class to remove before adding the new one, without guessing.
- `_buildItems()` now stamps `ITEM.dataset.rowClasses` the same way, for `_patchRowClasses()`.
- `_findRowDom(rowId)` locates an existing row via `[data-row-id]`, explicitly excluding group header rows (which also carry `data-row-id` = their groupId in Grid's table view).
- `_patchItemGroups()` mirrors `_buildItemGroups()`'s group-open/collapsed logic for any genuinely new group, but never calls `_clearGroupContainers()`.

## Known trade-off

Because removal is now suppressed for background-refresh-triggered repaints, a row that has *legitimately* aged out (e.g. a tub goes fully out of stock and should disappear from an "in stock" view) will keep showing stale data until a real rebuild happens for some other reason. This was an accepted trade for stability — a stale row is much less disruptive than one vanishing out from under a coworker mid-look, and it self-corrects on the next sort, filter, or page load.

## 3. Freshness check on group re-open

A **collapsed** group is one place staleness from the trade-off above can accumulate silently across several background refreshes, since nobody's looking at it to notice. `_showHide()` ([_list.js:776](assets/ui/_list.js#L776)) — the handler for the `.oc` group-toggle button — now calls `_reconcileGroupFreshness(CONTAINER)` whenever a group transitions to opened.

This does **not** issue a fetch. `this.items`/`this.itemGroups` are already kept as current as the last completed domain refresh (`modelInstance.setDomain()` runs on every refresh regardless of the patch/rebuild branch above), so reconciliation is a pure in-memory diff: compute the current row-id set for that group's slice of `this.items`, then remove any rendered row whose id isn't in that set. Existing rows are left alone (already correctly patched by the mechanism in §2); only genuinely stale rows are dropped, and only within the group that just opened.

Accepted edge case: a row that moved to a *different* group between refreshes disappears correctly from its old (now-open) group, but won't appear in a still-collapsed new group until that one is also opened — acceptable since a closed group isn't visible to the user anyway.

## 4. `.focus()` audit — settled on "never move focus for a delayed repaint"

There were 4 `.focus()` call sites in the client. Each was audited against "is this caused by the user's own current action, synchronously":

1. `text-it.js`'s clear-button handler — refocuses the input it just cleared. Same click, same element, no delay. **Kept** — this is the only one left.
2. `_focusAfterSubmit()` — after *this grid's own* manual Save, focus moved to the top filter input (grouped lists) or the exact field just saved (ungrouped lists, via `_restoreFocusAddress()`). Reasonable-sounding at first (it's a direct consequence of the user's own submit) — but see below.
3. *(same mechanism, ungrouped-list branch)*
4. `_restoreFocusAddress()` called from the bare `else` of `_onDomainUpdated` — ran on *every* refresh a grid processed that it didn't itself cause (another grid's save, an autosave elsewhere, a cabinet-workflow action), pulling focus to a `_lastFocusedEl` address that, once stamped by any past submit, stuck around and got reused on every later unrelated refresh. No scenario found where this was desired.

**Case #4 was removed first.** Then, reconsidering #2/#3: a manual-Save grid has the same underlying problem, just less obvious — every submit's focus restoration happens in `_onDomainUpdated`/the submit handler's `.then()`, both of which only run once `refreshPageDomain`'s network round-trip resolves. That's not instantaneous, and there's nothing stopping the user from clicking or typing somewhere else in the meantime. A focus jump landing at that later, unpredictable moment is a surprise interruption regardless of whether the grid autosaves or not — the delay, not the autosave, is what makes it a surprise.

**State at this point:** `_focusAfterSubmit()`, `_restoreFocusAddress()`, `_firstEditableInput()`, and `_captureFocusAddress()` were all removed outright (all four were part of the same now-unused mechanism). `_onDomainUpdated` and the submit handler's fallback still reset the `_postSubmitFocus` flag (it's also read by the unrelated `repaintOnRefresh===false` gate — see that code's comment), but neither calls `.focus()` anymore. No code path driven by a `refreshPageDomain` *round-trip* — autosave or manual save, this grid's own or someone else's — moves focus, ever. (§5 below adds one narrow, deliberate exception that moves focus at POST-success time, *not* tied to that round-trip.)

## 5. Focus-to-filter on save, and per-group deferred patching

A deliberate exception to §4's "never move focus" rule, added afterward for a specific, high-frequency workflow: on `FlavorTub` (and any other grid grouped + filterable via `FindInGrid`), saving is almost always followed by searching for the *next* flavor to work on, not lingering on the row just saved.

**Focus move:** the submit handler focuses `this._filter.inp` (if the grid has one) synchronously, right when the user submits — with no `await` between the submit event and the `.focus()` call, so it happens *before* the save's own POST is even sent, let alone before it or the follow-up `refreshPageDomain` fetch resolves. Deliberately tied to the user's own action, not to either server response: those are both async and can land at an unpredictable moment after the user has already started typing into the filter, so focus can never be moved out from under them mid-keystroke — there's no gap in which that race could happen. It fires whether or not the subsequent POST ultimately succeeds. Both Ctrl+Enter and a Save button click go through the same `submit` event, so this covers both with no extra wiring (`FORM.requestSubmit()` fires the identical handler).

Alongside the focus move, the same submit handler also calls `this._filter.clear()` (`find-in-grid.js`) — empties the query and re-applies it (so previously-hidden groups reappear) rather than just clearing the input's visible text. Without the re-apply, setting `.value = ""` alone wouldn't fire FindInGrid's own `input` listener, leaving the box empty but the grid still visually narrowed to whatever was typed before. This puts the grid back to a clean, unfiltered state exactly when focus lands in the box, ready for the next search.

**The tension this raises:** `FindInGrid` filters against the live DOM on every keystroke (see §4's audit of `find-in-grid.js`), so typing a query while the post-save fetch is still in flight (routinely several seconds — every save invalidates the server's bundle cache, so the fetch that follows one's own save is a guaranteed cold miss, see `performance.md` #4) filters against not-yet-fresh data for whichever group the save touched. Locking the UI until that fetch resolves was rejected as worse than the problem. The resolution:

- Searching for a *different* group than the one just edited (the common case) is unaffected — that group's label/content is untouched by the save, so live filtering against it is correct immediately.
- If the user tabs into a field inside a group before its fresh data has arrived, that specific group's row-patching is now deferred (`_patchItems` checks `_groupHasFocus()` per group) rather than applied — the fresh data itself is already sitting in `this.items` (via `modelInstance.setDomain()`, which always runs), only that one group's DOM catch-up waits.
- The deferred group is flushed (`_flushGroup()` — patches decorations, drops any now-stale rows, reapplies the active filter) the instant focus is confirmed to leave it: a `focusout` listener on `FORM` (checking `e.relatedTarget` isn't still inside the same group) for the general case, plus a direct flush from `_showHide()` for the one case `focusout` can't distinguish on its own — the group's own `.oc` toggle button, which lives inside that same group's container.
- Every *other* group patches immediately and normally, unaffected by one group being deferred.

**Why staleness in the deferred group isn't a real risk in practice:** a change in one group essentially never affects another group's display (confirmed — this was a design assumption, not just a hope). The actual requirement was narrower: never leave a group permanently stale. `_flushGroup()` guarantees that the moment focus moves on.

**The filter-reapplication gap this also closes:** `_patchGroupRows`/`_flushGroup` end by calling the new `_reapplyActiveFilter()`, so a group that's freshly patched or created now correctly respects whatever the user has already typed into the filter, instead of popping into view unfiltered (the gap flagged at the end of §3/§4's discussion).

## Not yet touched

One other repaint-aggressiveness source identified during this pass is still open:

- **Autosave background refresh** ([_list.js `_scheduleBackgroundDomainRefresh`](assets/ui/_list.js)) — every autosaved field edit still triggers a full page-wide bundle *refetch* 800ms later (now a gentler repaint, thanks to §2/§4 above, but still a network round-trip for every grid on the page for one cell's edit).

`_bundleCache` (client-side bundle caching) was investigated separately — see the stashed work (`git stash list`) — it turned out to be dead code (never populated, since every caller of `refreshPageDomain` passes `force:true`) and is being removed/reconsidered independently of the repaint-aggressiveness effort, since it has no DOM or focus impact either way.
