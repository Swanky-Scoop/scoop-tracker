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

## Not yet touched

Two other repaint-aggressiveness sources identified during this pass are still open:

- **Autosave background refresh** ([_list.js `_scheduleBackgroundDomainRefresh`](assets/ui/_list.js)) — every autosaved field edit still triggers a full page-wide bundle *refetch* 800ms later (now a gentler repaint, thanks to the change above, but still a network round-trip for every grid on the page for one cell's edit).
- **Server-side filter change** ([scoop-api.js `refreshGridFilters`](assets/data/scoop-api.js)) — still clears the *entire* in-memory bundle cache (`this._bundleCache.clear()`), not just the affected grid's slice.
