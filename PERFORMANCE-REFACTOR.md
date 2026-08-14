# Performance Refactor — Working Notes

**Branch:** `worktree-performance-refactor` (worktree at `.claude/worktrees/performance-refactor`), based on `origin/main` @ `c08b4a7` ("Many fixes to Docking GUI").

Read this file first on this branch — it's the fast path back to everything
established in the session that started this effort, so it doesn't need
re-deriving. Companion docs already in the repo, not duplicated here:
`performance.md` (server-side N+1/cache findings), `PARTIAL-REFRESH.md`
(client repaint mechanism), `websockets-migration.md` (cross-device
staleness, fully scoped, blocked on a hosting decision), `change-tub.md`
(sister_id / bidirectional relationship gotchas).

## Confirmed findings, this session

### 1. Pods relationship-meta-mirror is O(n) on high-fanout sister fields — FIXED for both delete and save (2026-08-14)

Real root cause of a 24-30s batch-delete: not this codebase's own delete
logic — Pods' `PodsAPI::delete_relationships()` re-syncs the *entire*
remaining relationship list into `wp_postmeta`, one `add_metadata()` call
at a time, whenever a high-fanout sister-paired field (`tub.location`/
`tub.use`, sister-paired to `location.tubs`/`use.tubs`, same mechanism as
the already-known `tub.slot`/`slot.tub` pairing) loses one member. Since
virtually every tub shares one of a handful of location/use values, that
list runs into the thousands (2,639 / 2,238 locally) — ~2.3ms/item,
confirmed proportional across two fields. Fixed via one scoped
`pods_relationship_meta_storage_enabled` filter
(`includes/hooks/batch-tub.php`, `scoop_disable_tub_relationship_meta_mirror`)
— doesn't touch the actual `wp_podsrel` relationship cleanup, verified
directly including the slot-linked-tub sister-clear case. ~24-26s → ~400-500ms
isolated, ~5-6s end-to-end on the contended dock page. Full writeup:
[[batch-delete-speedup-2026-08]] memory.

**FIXED (2026-08-14).** Turned out to be TWO distinct bugs compounding on
the save path, both now fixed in `includes/hooks/batch-tub.php`:

**Bug A — `save_relationships()`'s own per-item query loop.**
`PodsField_Pick::save()` (`pick.php:2071`), for the bidirectional side of a
sister-paired field, calls
`PodsAPI::save_relationships($related_id, $bidirectional_ids, $related_pod, $related_field)`
where `$bidirectional_ids` is the *related* item's entire current
relationship list plus the one new id being added — e.g. saving
`tub.location` calls `save_relationships()` on location's `tubs` field with
the full list of every tub currently at that location. Its "Relationships
table" sync (`PodsAPI.php` ~6445-6505) then loops that entire list doing one
individual `UPDATE`-or-`INSERT` query per item, gated only by
`pods_podsrel_enabled()`, unconditional and untouched by the metadata-mirror
filter. Fixed via `scoop_skip_tub_reverse_relationship_resync()` (a
`pods_podsrel_enabled` filter scoped to `context==='save'` + the exact
`tubs` field on `location`/`use`, matched by field name/pick-target rather
than field ID since IDs aren't stable across environments) +
`scoop_bulk_write_tub_reverse_relationship()` (a `pods_api_save_relationships`
action that does the equivalent write itself: one indexed SELECT for the
current related-id set, diffed in PHP, then a single bulk INSERT for new ids
and a single bulk DELETE for any that dropped off).

**Bug B — the existing delete-side fix had a scoping gap that also hit
saves.** Found while isolating what was still slow after fixing Bug A:
moving a tub *out* of a location (via `save()`'s bidirectional-removal call
into `delete_relationships()`) was still costing ~5s even with Bug A fixed
and even with `scoop_disable_tub_relationship_meta_mirror` (the delete fix
from earlier this session) active. Root cause: that filter's scoping check
was `is_array($pod) && in_array($pod['name'] ?? '', $scoped_pods, true)` —
but `delete_relationships()` called from *this* path passes `$pod` as a
`Pods\Whatsit\Pod` **object**, not an array. `is_array($pod)` is false, so
the filter silently fell through to `$enabled = true` and the O(n)
postmeta-mirror thrash kept running on exactly this path. It only ever
matched the plain-array shape that `pods_api()->delete_pod_item()` (the full
tub-delete path) happens to pass — which is why the original batch-delete
measurement looked fully fixed while ordinary saves stayed slow. Fixed by
broadening the pod-name read to also handle `ArrayAccess` objects (new
`scoop_pod_name()` helper), confirmed via `get_class($pod)` during a live
save that this is exactly the shape being passed.

**Verified via PHP CLI, both fields, both directions, with full DB-state
equivalence diffs against the pre-fix ("ground truth") behavior** — not
just wall-clock, the actual resulting `wp_podsrel` rows were byte-identical
in every case:

| move | before (ground truth) | after (both fixes) |
|---|---|---|
| location: hot (935, 2637 tubs) → empty | 4818ms | 129ms |
| location: empty → hot (935) | 5884ms | 69ms |
| use: hot (1863, 2236 tubs) → small (~38) | 4145ms | 179ms |
| use: small → hot (1863) | 5357ms | 61ms |

Also re-verified the `tub.slot`/`slot.tub` sister-clear (the specific risk
case the original delete fix was careful about) still works correctly under
a plain *save*-triggered link/unlink now that the broadened meta-mirror
check also reaches that path — confirmed via direct `wp_podsrel` row
inspection, not just the grid.

This was the single highest-leverage item on this branch — it hit the most
common write (any tub save touching location/use, which is most
CabinetWorkflow writes), not just the rare delete. Next up: bundle-fetch
type-scoping (#2 below).

### 2. No bundle-fetch type-scoping — every refresh refetches the full page-wide union

`ScoopAPI.refreshPageDomain()` always refetches `this._pageTypes` (every
bundle type any grid on the page needs), never just the types actually
affected by whatever triggered the call. Confirmed via trace of
`mountAllGrids`/`_setPageTypes` (`assets/data/scoop-api.js`). Worst
offender: `_list.js`'s `_scheduleBackgroundDomainRefresh` fires a full
page-wide refetch 800ms after *every* autosaved cell edit. This is the item
the user is currently leaning toward tackling first on this branch — see
recommendation below.

### 3. Dock-page PHP-FPM contention is real and measured, not theoretical

`/dock/` (5 grids: Batch, BatchHistory, FlavorTub, Analytics,
CabinetWorkflow, Cabinet) genuinely serializes work on Local's small
PHP-FPM worker pool — measured directly, not assumed. This matters because
the `_domainInflight` chaining fix shipped this session (correct: a
`force:true` call arriving mid-flight now chains a real follow-up fetch
instead of incorrectly reusing stale data) has a real cost under this
contention: it means waiting out **two full sequential bundle fetches**
instead of one. Item #2's type-scoping would shrink both the size of each
fetch and the odds of two overlapping in the first place — these two
findings compound, not independent.

### 4. CabinetWorkflow's automatic on-load reconciliation — worth reconsidering for the dock model

Runs unconditionally on every page load (`_reconcileCabinet({alertResult:
false})` on `ts:list:init`) — its own write + bundle refresh. Tolerable
today as one grid among a few; becomes a mandatory tax on every load once
more grids share a page. Not urgent, but flag before the "whole app in one
dock" move goes further.

### 5. Server-side cache-bust is still coarse — `performance.md` #4/#13, not implemented

Global `scoop_cache_version` bumps on any `save_post`/`trashed_post`/
`untrashed_post`/`deleted_post` site-wide, not just scoop-relevant types.
Per-entity scoping exists only for `flavor`/`use`/`location`/`cabinet`, not
`tub`/`inventory_change` (the highest-churn types). Already-scoped,
low-risk, not yet done — good complementary work alongside #2, not a
substitute for it (this is a read-cache-hit-rate fix; #1/#2 are write-cost
and fetch-scope fixes, a different axis).

## Recommendation: where to start (revised 2026-08-14, post-fix)

Item #1 is now fully fixed and verified (both the save-loop bug and the
delete-fix scoping gap it exposed) — tub saves touching location/use went
from 5-11s to 60-180ms, equivalence-checked against pre-fix DB state. That
was the single highest-leverage item on this branch. Sequence now:

1. ~~Fix `save_relationships()`'s per-item query loop~~ — **done.**
2. **Bundle-fetch type-scoping** (#2) — now the top of the list. Still the
   right next architectural project, still compounds with #3's measured
   dock contention.
3. Items #4/#5 remain lower-priority, standing background work.

**Concrete next step:** design bundle-fetch type-scoping — `ScoopAPI.
refreshPageDomain()` refetching only the types actually affected by a given
trigger, instead of the full page-wide union every time (see #2 above for
the specifics, notably `_list.js`'s `_scheduleBackgroundDomainRefresh`
firing a full refetch 800ms after every autosaved cell edit).
