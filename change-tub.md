# Change Tub — Cabinet Slot Management

Feature draft. Fill/empty cabinet slots from a tile view, grouped by cabinet,
including empty slots. Two write actions ship now (`add next`, `confirm tubs
in cabinets`); two are stubbed for a later modal (`add special`, `add
flavor`).

## New grid type: `CabinetWorkflow`

Row = **slot**, not flavor. This differs from `InstockFlavor`
(`assets/models/instock-flavor-grid-model.js`), which is flavor-primary and
drops slots with no stock. `CabinetWorkflow` must show every slot, including
ones with no `current_flavor`. Named apart from the reporting-style grids
(`Cabinet`, `FlavorTub`, `InstockFlavor`, ...) because it's the first view
built to mirror a physical staff workflow (one button = one real-world
action) rather than surface inventory data — but kept bare PascalCase, no
prefix, so it drops into the same file/route/CSS naming everywhere else
uses.

- Shortcode: `[scoop_tile type="CabinetWorkflow" location="935"]` — the
  existing `scoop_tile` shortcode (`includes/shortcode.php`), not
  `scoop_grid`; it sets `data-view="tile"` for you. Grouped by cabinet
  always, no flat mode.
- **Location scoping is client-side only.** Traced the actual request path:
  `assets/data/scoop-api.js`'s `_bundleUrlForTypes()` only ever sets
  `types` (+ per-model `getServerFilterParams()` extras) on the bundle URL
  — `location` is never forwarded, so `scoop_bundle_fetch_type()`'s
  `$req->get_param('location')` in `includes/bundle-fetch.php` is always
  null today and the SQL location filter never fires. The bundle always
  returns every cabinet/slot/tub across every location. So
  `CabinetWorkflowGridModel.buildRows()` filters slots to `this.location`
  (from the shortcode's `data-location`, same as every other model) itself
  before grouping — that's the only thing standing between one location's
  view and every cabinet on earth showing on one page once a second
  location opens.
- Bundle spec (`scoop_bundle_specs()` in `includes/_specs.php`):
  `'CabinetWorkflow' => ['needs' => ['cabinet','slot','flavor','tub']]`.
- **No `_config.php` route entry, on purpose.** Every other grid type has
  one because `scoop_client_metadata()` needs it to build column defs for
  a generic, editable, column-driven view. `CabinetWorkflow` has no
  editable columns and no per-cell rendering — everything is computed
  onto the row and drawn directly in the Tile subclass (below), the same
  metaData-less pattern `AnalyticsGridModel`/`PopularGridModel` already
  use. With no `_config.php` entry, `SCOOP.metaData.CabinetWorkflow`
  doesn't exist, `BaseGridModel`'s constructor never calls `buildCols()`
  (gated on `if (this.metaData)`), and `this.columns` just stays `[]` —
  which is exactly what's wanted. The dedicated write endpoints in later
  phases (`advance`, `confirm-tubs`) are separate custom REST routes, not
  routed through this generic per-type mechanism, so they don't need a
  `_config.php` entry either.
- Model: `assets/models/cabinet-workflow-grid-model.js` (built, Phase 1),
  row-per-slot, grouped by `slot.cabinet` (every cabinet present, not just
  ones with stock — unlike `InstockFlavorGridModel
  ._buildRowsGroupedByCabinet`, no "Others" bucket since every slot has a
  cabinet by construction).
- View: `assets/ui/cabinet-workflow-tile.js` (built, Phase 1) —
  `Tile extends List` (`assets/ui/tile.js`) subclass. Reuses Tile's
  `buildCoreDom`/`buildGroupDom`/`buildMetaFieldDom` as-is (cabinet
  group headers, collapse chrome) but overrides `buildItemDom` completely:
  since the model ships no columns, `List._buildItems`'s
  `fields.forEach(...)` is a no-op, so `buildItemDom(row)` is the only
  place building a slot's markup, straight off the row object — no
  column/cell indirection. Wired into `assets/data/scoop-api.js` via a new
  `getViewOverrides()` map (checked before the existing `data-view`
  grid-vs-tile switch), rather than adding type-specific branches to the
  shared `tile.js`.
- Permissions: same role gate as `InstockFlavor` for viewing (i.e. none
  today — `InstockFlavor` isn't even listed in `scoop_access_policy()`;
  reads go through the bundle endpoint, which only checks
  "authenticated user", not per-type policy). Phase 2/3's write endpoints
  get their own permission checks when they're built.

## LI markup per slot

**Empty slot** (`current_flavor` empty AND `tubs` empty):
```html
<li class="slot empty">
  <button type="button" class="add-flavor">Add Flavor</button>
</li>
```

**Slot with a flavor assigned:**
```html
<li class="slot flavor-cherry-garcia">
  <h3>Cherry Garcia</h3>
  <img src="{flavor.photo}" alt="Cherry Garcia" />
  <label class="tub-count-local">Local tubs: <em>3.5</em></label>
  <label class="tub-count-total">Total tubs: <em>7.5</em></label>
  <button type="button" class="add-next" data-slot-id="123">add next</button>
  <button type="button" class="add-special" data-slot-id="123">add special</button>
</li>
```
`add next` is **omitted** when there is no eligible *local* tub — i.e.
`tub-count-local` is 0 (every FOH tub of this flavor at this location is
already `Opened` or `Emptied`). `add special` always renders when a
flavor is assigned, regardless of tub availability, and is the fallback
staff use to pull from another location when local is 0 but
`tub-count-total` is not.

No allergen / menu-board blocks here (unlike the `InstockFlavor` demo
tile) — this view is about slot state, not flavor detail. No Details-panel
drill-down either: this GUI exists to make one physical action (swap the
tub in a cabinet slot) a single button click, not a research tool, so the
flavor name/photo are static, not links.

- `tub-count-local` / `tub-count-total`: **sum of `amount`**, not a tub
  count — the placeholder values (3.5, 7.5) are fractional on purpose,
  since a partial tub's `amount < 1` and this is meant to answer "how much
  product is on hand," not "how many containers." Pool: `flavor =
  current_flavor`, `use = 1863 (FRONT_OF_HOUSE_USE_ID)`, `state =
  Freezing` (the same eligibility pool `add next` draws from — tubs
  already `Opened`/`Emptied` don't count). `-local` scopes to the slot's
  own `location`; `-total` doesn't scope at all, so staff can see stock
  exists elsewhere before invoking `add special`. `canAddNext` (whether
  the `add next` button renders) is `tub-count-local > 0`.

## "Add next" — one atomic REST endpoint

`POST /wp-json/scoop/v1/slots/{id}/advance` (new route in
`includes/_config.php` + `_routes.php`, dispatched from `rest.php`).

Server-side sequence, each step logged so a partial failure is diagnosable
(MyISAM: no transaction, no rollback — see CLAUDE.md data-repair policy):

1. Read slot, its `current_flavor`, and its `cabinet.location`.
2. Find the old tub: the one FOH tub of `current_flavor` currently in
   state `Opened` at this location (should be the slot's linked `tubs`
   value once "confirm tubs" has run — falls back to a lookup if unlinked).
3. Find the replacement tub — pool: `flavor = current_flavor`, `use =
   1863 (FRONT_OF_HOUSE_USE_ID)`, `state = Freezing`, `location =
   slot.cabinet.location` (this location only — see scoping note below).
   Tie-break, opposite of closeout's "prefer partial" rule: take the
   oldest **whole** tub (`amount >= 0.8`, ordered by `post_date`/`index`,
   same field ordering as `scoop_find_whole_tubs` in
   `includes/hooks/closeout.php`) if one exists; only fall back to the
   oldest fractional tub (`amount < 1`) when no whole tub is available.
   `add special` is where an early/deliberate fractional pull belongs —
   `add next` never reaches for a partial tub while a whole one is on hand.
4. If no replacement tub found: abort before step 5, return 409 with a
   clear message ("no eligible tub for {flavor} at {location}") — do not
   touch the old tub.
5. Write old tub: `state = 'Emptied'` (hook auto-sets `emptied_at` +
   `post_status = draft`, per `scoop_enforce_tub_rules` in
   `includes/hooks/tub-state.php` — don't set these client/server-side
   manually, the hook is the single source of truth here).
6. Write new tub: `state = 'Opened'` (hook auto-sets `opened_on`).
7. Write slot: `tubs = [new_tub_id]`.
8. Return the updated slot + both tub ids so the client can patch its
   local domain without a full re-fetch.

Bump `scoop_cache_version` as the existing `save_post` hook already does
for tub/slot saves — no extra invalidation code needed.

## "Confirm Cabinet" — built, one click, no dry-run step

Superseded the two-step dry-run/apply design below with something simpler,
per your later instruction: a "Confirm Cabinet" button
(`CabinetWorkflowTile.buildCoreDom`/`_confirmCabinet`, appended to `FRAME`
directly rather than `TOOLS` — `TOOLS` gets wiped and rebuilt from
`this.fields` once during `List.init()`, and this model ships no fields).

For every slot with a `current_flavor`, `CabinetWorkflowGridModel
._fillSlotRow()` already computes `row.openTubStatus` on every render (not
just on click) by looking for Opened FOH tubs of that flavor at the slot's
own location:

- **Exactly 1** → `'linked'`, `row.openTub` set to that tub. The button
  writes `slot.tubs = [that tub's id]` for every such row, batched into one
  `cells` payload POSTed through the **existing `'Cabinet'` write route**
  (`includes/_config.php`'s `Cabinet` entry — same `pod_name: 'slot'`,
  just a different envelope key than this view's own name; no new PHP
  route needed). Required adding `'tubs'` to `slot`'s `writeable` array
  (`_specs.php`) and to `_default`/`editor`'s `entities.slot` list in
  `scoop_access_policy()` (`_policy.php`) — `author` already has no slot
  write access via this route, untouched.
- **0** → `'none'`, LI gets class `none-opened` (spelling fixed from the
  original `none-openned`).
- **2+** → `'multi'`, LI gets class `multi-opened` (fixed from
  `muli-opened`).

Both flag classes are computed and applied on **every render**, straight
from row data — not a one-shot side effect of clicking the button. Clicking
Confirm Cabinet only writes unambiguous ('linked', and only if not already
linked to that same tub — see `row.currentTubId`) rows; 'none'/'multi' are
never auto-resolved, matching the "surface bad data, don't silently
correct" standard from CLAUDE.md's pricing-data policy applied to
tub/slot links.

On completion, an `alert()` reports: which slots got newly linked (or "No
changes needed" if none did), plus a listed reason for every 'none'/'multi'
slot ("no Opened tub found" / "N Opened tubs found").

Not yet done: no styling for `.none-opened`/`.multi-opened` — add CSS
when you're ready to make them visually obvious.

<details>
<summary>Earlier two-step dry-run/apply design (superseded)</summary>

Two-step per your original answer:

- `GET /wp-json/scoop/v1/slots/confirm-tubs` (or a `dry_run=1` query flag
  on the POST below) — for every slot with a `current_flavor`, check
  whether `slot.tubs` already points at a tub that is `Opened` and matches
  `current_flavor`. Report per slot: `ok` / `missing` (no tub linked, or
  linked tub is stale — wrong flavor or no longer `Opened`) / `no-match`
  (no `Opened` FOH tub of that flavor exists at all, so nothing to link).
  No writes.
- `POST /wp-json/scoop/v1/slots/confirm-tubs` — applies only the `missing`
  fixes found in the dry run (links each slot to the correct `Opened` tub).
  Does **not** create or change tub state — it only repairs the
  `slot.tubs` link. `no-match` cases are left as reported findings, not
  auto-resolved (nothing to link to).
- Scope: no cabinet-id filter needed — the existing `location` param
  already scopes both the GET bundle and slot lookups (`bundle-fetch.php`
  resolves each slot's location from its parent cabinet, see
  `scoop_enrich_slots_with_location()`), so passing the page's `location`
  through to `confirm-tubs` naturally covers just that store's cabinets
  (2 today; more once the second location opens). Only the second location
  going live changes cabinet *count*, not this logic.
- UI: a button in `CabinetWorkflow`'s toolbar area (`TOOLS`/`FILTERS`
  region Tile already has, see `tile.js` `buildCoreDom`) that runs the dry
  run and shows a small report list before a second "apply" click fires
  the POST.

</details>

## "Add next" confirmation modal — built

Draft markup: `assets/emptyAdd.html`. Decisions made before implementation:

- **Eligible/"remaining" tub states widen.** Was `state === 'Freezing'`
  only (`CabinetWorkflowGridModel`'s `ELIGIBLE_TUB_STATE`). Now: exclude
  only `Opened`, `Emptied`, and the client-only `!Lost` flag
  (`assets/models/_flavor.js`'s `EXCLUDED_STATES` precedent) — so
  `Hardening`/`Tempering`/`__override__` count as "remaining" too. This
  changes **both** the modal's "N remaining / N here" text and the base
  tile's existing `tub-count-local`/`tub-count-total` — they need to
  agree, so the tile's numbers will grow once this lands (not a bug when
  it happens).
- **Both `immediate_flavor` and `next_flavor` get a block** in this
  primary modal (each with its own switch-confirmation link), not just
  `immediate_flavor` — matches the original "up to 2 additional flavors"
  framing.
- **"Leave slot empty"**: marks the old tub `Emptied` (it's being
  physically pulled regardless of what replaces it) and clears
  `slot.current_flavor`/`slot.tubs`. Originally: does **not** touch
  `immediate_flavor`/`next_flavor` at all. **Refined below** — leftover
  stock now gets rescheduled into them rather than always left alone.
- **Date/index/batch-quantity in the tub preview come from structured
  fields**, not `post_title` parsing: `tub.created_on`, `tub.index`,
  and `tub.batch → batch.count`. Requires adding `batch` to
  `CabinetWorkflow`'s bundle spec `needs` (currently
  `['cabinet','slot','flavor','tub']`) — it isn't fetched today.
- **Whole-vs-partial becomes a live checkbox** in the modal (checked =
  whole tub preferred, unchecked = partial first), recomputing which tub
  is shown. This **supersedes** the earlier hardcoded Decisions-log #1
  rule ("`add next` always takes whole over fractional") — that was a
  fixed server-side rule; it's now a per-confirmation user choice with
  the same default.

Built: `assets/ui/confirm-swap-modal.js` (`ConfirmSwapModal`), wired to
every slot's `add-next` button via a delegated click listener in
`CabinetWorkflowTile.buildCoreDom()`. `CabinetWorkflowGridModel` gained the
state-widening split (`remainingSummary()` = broad display figure,
`promotablePool()`/`pickPromotableTub()` = `Freezing`-only selection pool
— deliberately different sets, see the model's own comments) plus
`flavorInfo()`/`tubBatchCount()` for the modal's tub preview.

**Batch quantity is parsed from `tub._title`**, not a `batch.count`
lookup — adding `batch` to this view's bundle `needs` would pull the
shop's *entire* unbounded batch history into every page load (the same
failure shape `bundle-fetch.php`'s own comments document crashing
php-fpm once already). The title format
(`"{flavor} {date}_{count}|{index}"`, `scoop_batch_title_for_data()` in
`includes/hooks/batch-tub.php`) already carries the count, so
`tubBatchCount()` regexes it out — the one place this feature reads a
title string instead of a structured field, and only because the
alternative was worse.

**Known gap, not yet handled**: if `openTubStatus` is `'none'`/`'multi'`
(no clean single currently-open tub — see "Confirm Cabinet" above), the
confirm modal still opens a new tub without emptying anything (there's
nothing unambiguous to empty). Could leave two tubs `Opened`
simultaneously for that flavor in the already-inconsistent case. Not
blocking — `Confirm Cabinet` is the tool for fixing that separately — but
worth knowing before relying on `add-next` in that state.

Not yet built: the "Change Plan" from-scratch picker (`Change Plan`
button currently just alerts that it isn't built).

### Later enhancements (built)

- **Tub-exhaustion fallback**: if `current_flavor` has no promotable
  (`Freezing`) tub left, the modal still opens — default target falls
  through `immediate_flavor` → `next_flavor` → back to `current_flavor`
  (whichever is set first; the fallback targets' *own* availability isn't
  re-checked, only `current_flavor`'s triggers the fallback).
- **`slot.reload` field** (Pods boolean, "Reload current flavor?", added
  to `includes/_specs.php`'s `slot` fields as `data_type: 'bool'`) — when
  `false` ("don't reload the current flavor"), it overrides the default
  target outright (`immediate_flavor` → `next_flavor` → `current_flavor`,
  *regardless* of `current_flavor`'s own stock — this slot means to move
  on to the planned rotation, full stop) **and** reorders the three info
  lines to `[immediate, next, current]` instead of the default
  `[current, immediate, next]`. Two independent effects from one flag,
  confirmed explicitly since the wording was ambiguous on both counts.
- **`add next` button is always visible now**, not omitted when
  `canAddNext` is false — superseding the original Phase 1 decision
  ("omitted when there's no local FOH tub"). Reason: the confirm modal is
  the only path to "leave slot empty," which must stay reachable to mark
  the existing tub `Emptied` even with zero stock to replace it with. The
  modal itself still disables `Confirm Swap` and shows "No tub available"
  in that case — only the button's *visibility* changed, not the modal's
  own empty-pool handling.
- **"Leave slot empty" reschedules leftover stock.** If the flavor being
  removed still has `remainingSummary(flavorId, slot.location) > 0`, it's
  written into `immediate_flavor` when that field is empty; `next_flavor`
  is only used when `immediate_flavor` is already occupied *and*
  `next_flavor` is itself empty (a strict if/else-if sequence, not "either
  empty slot"). Drops out of the slot's plan entirely with nothing forced
  in only when both are already taken. Required adding
  `immediate_flavor`/`next_flavor` to the `editor` role's writeable slot
  fields in `_policy.php` (previously only `current_flavor`/`tubs` —
  editor couldn't write the planning fields via the `Cabinet` route at
  all). `remainingSummary` (broad, excludes only `Opened`/`Emptied`/
  `!Lost`) was used rather than `promotablePool` (`Freezing`-only) since
  it's the same number already shown to the user in the modal.
- **`slot.tubs` renamed to `slot.tub`, and it's now a real bidirectional
  Pods sister field with a new `tub.slot`** (1:1, Pods-native sync — you
  set it up in the Pods admin). Client code **never writes `slot.tub`
  directly anymore** — only `tub.slot`, paired with whatever write is
  already touching that tub (`state: 'Opened', slot: slotId` when opening;
  `state: 'Emptied', slot: 0` when emptying), and Pods keeps `slot.tub` in
  sync from that side. Rationale: since every write goes through
  `pods_api()->save_pod_item()` (never raw SQL), either side syncs
  correctly regardless of which we write — so writing both risked nothing
  but two call sites disagreeing about the same fact, with no upside.
  Touched: `_specs.php` (`tub.slot` new field + writeable; `slot.tub`
  rename, `data_type` changed `ids`→`int` since it's genuinely 1:1 now),
  `_policy.php` (`tub.slot` added to `_default`/`editor`), `rest.php`
  (`scoop_should_log_inventory_change()` was matching the literal string
  `'tubs'` — would've silently stopped logging slot-side tub-link changes
  the moment the field renamed; fixed to `'tub'`), `ConfirmSwapModal
  ._confirm`/`_confirmEmpty`, `CabinetWorkflowTile._confirmCabinet` (now
  posts to the `FlavorTub` route, not `Cabinet` — it's writing tubs now,
  not slots), and the model's `row.currentTubId` read.

### Confirm Cabinet rebuilt: search-and-claim reconciliation, auto-run, `'impossible'`

Superseded the earlier "link an already-Opened tub" design. New job: make
sure every slot with a `current_flavor` ends up with exactly one valid
tub — `Opened`, matching flavor, linked via `tub.slot` — actively finding
and opening a fresh one when nothing already qualifies, not just detecting
whether one already does.

- **Trigger**: runs automatically, once, on first render (`'ts:list:init'`
  fires only from `List.init()`, which only happens the first time
  `setDomain()` lands — later domain refreshes go through `List.refresh()`
  and don't re-fire it). The `Confirm Cabinet` button still exists too, for
  re-running on demand — both call the same `_reconcileCabinet()`.
- **Blocks the GUI while running**: `FRAME.style.pointerEvents = 'none'`
  (a real block, not just a `.reconciling` CSS hook) for the duration —
  per your requirement, the grid isn't usable until the pass completes.
  The automatic run doesn't `alert()` its result (would fire on every page
  load); the manual button still does.
- **Per-slot claim, not a global count**: processes every slot needing a
  tub, tracking claimed tub ids across the whole pass, so two slots that
  legitimately want the same flavor can't both grab the same physical tub
  — this is what actually closes the gap flagged earlier (the old
  flavor+location heuristic couldn't tell "two valid slots, same flavor"
  from "one tub, wrongly detected twice"). Already-valid links are claimed
  first (before the search runs) so the search can't steal a tub a
  different slot is already correctly using.
- **`row.impossible`** (replaces `'none'`/`'multi'`, which are obsolete now
  that `tub.slot` is a scalar 1:1 link — there's no "multi" to detect
  anymore): true when either (a) the flavor's allergens conflict with the
  cabinet's `prohibited_allergens` (new field, `_specs.php`/`cabinet`), or
  (b) no `Freezing` tub of that flavor exists *anywhere* (search is
  global, not location-scoped — see below). `current_flavor` is left
  alone, no tub forced in — flagged with an `impossible` class on the
  `<li>` for a human, same "surface it, don't silently correct" standard
  as before.
- **Location doesn't gate eligibility, for the search or for `add-next`
  either now** — extended from the earlier Add Flavor-only rule for
  consistency: a tub of the right flavor can be physically carried
  between this shop's own locations, so `promotablePool`/
  `pickPromotableTub` dropped their `locationId` param entirely (this was
  a real change from `add-next`'s original location-scoped behavior — flag
  if that wasn't intended to extend this far). Whichever action assigns a
  cross-location tub corrects `tub.location` to match the destination
  cabinet as part of the same write (`ConfirmSwapModal._confirm` and
  `_reconcileCabinet` both do this now). `remainingSummary` (the
  local/total *display* numbers) is unaffected — still location-scoped,
  since that's informational, not an eligibility gate.
- **Tub-side hook, not JS**: a tub can be `Opened` with no slot at all —
  explicitly a valid, separate state (other GUIs/workflows can open a tub
  unrelated to any cabinet slot). What has to hold is the reverse: a slot
  never keeps pointing at a tub that's no longer `Opened`. Since "another
  GUI marks a tub Emptied" is a different code path entirely, this can't
  be enforced from this feature's JS — added to
  `scoop_enforce_tub_rules()` in `includes/hooks/tub-state.php` instead
  (same hook that already auto-stamps `emptied_at`): whenever a tub
  transitions to `Emptied` (`old_state !== 'Emptied'`), `slot` gets forced
  to `0` in the same save, and Pods' bidirectional sync clears `slot.tub`
  too — regardless of which GUI, REST call, or direct Pods save triggered
  it. This is why "someone will need to reconfirm the slots" before using
  this GUI again is already handled: Confirm Cabinet re-runs (blocking)
  every time the page loads.
- **`_confirm()`'s "This box is not empty" checkbox** (unchecked by
  default): checked means the old tub isn't a real stock event — it stays
  `Opened`, just gets `slot: 0` (unlinked), instead of `state: 'Emptied'`.
  No `state` field is sent at all in that case, so `tub-state.php`'s hook
  has nothing to react to.

### Bug fix: reconciliation ignored already-Opened, unlinked tubs

Confirm Cabinet only ever searched the `Freezing` pool, so any tub already
`Opened` at a slot's location (opened before this feature existed, or via
another workflow) but never linked was invisible to it — reported
`impossible` when a valid tub was sitting right there. Fixed with a new
step between "already linked" and "search Freezing": `openUnclaimedPool()`
finds `Opened` tubs of the flavor, at the slot's own location (this check
*is* location-scoped, unlike the Freezing search — an already-open tub is
presumably already physically somewhere, so only recognize it for its own
location), not claimed by a different slot. Exactly one match → adopt it
(link only, no state change). More than one → **`discrepancy`** (new
class, `_fillSlotRow`/`buildItemDom`): pair the one with `amount` closest
to 1 (`pickClosestToOne` — nearest-to-whole is the more meaningful signal
here than age, unlike the Freezing pool's oldest-first rule), still link
it, just flagged for a human. Both `row.impossible` and `row.discrepancy`
are computed live in the model (not just as a `_reconcileCabinet()`
side-effect) so they stay accurate between full reconciliation runs.

**`promotablePool`'s search widened**: was `state === 'Freezing'` only.
Now excludes only `Emptied` (hard exclude) and `Opened` (handled
exclusively by `openUnclaimedPool` instead — an Opened-and-unclaimed tub
should be *adopted*, never "promoted" a second time) —
Hardening/Tempering/`__override__` all qualify now, per your correction.
Also excluded `!Lost` here, which wasn't explicitly requested — flagging
that addition: a flagged-lost tub isn't physically assignable regardless
of its Pods `state`, but say if that's wrong. `add-next`'s live pick
(`ConfirmSwapModal`) shares this same widened pool automatically (same
method) but does **not** yet get the "prefer an already-Opened unclaimed
tub first" two-tier preference — that's currently Confirm-Cabinet-only.
Worth doing there too if a staffer clicking `add next` should also adopt
a sitting-open tub instead of opening a fresh one; flagging as a known
asymmetry, not yet built.

Considered making this a persisted Pods field on `slot` instead of
computed — recommended against by default (every value is 100% derivable,
persisting risks drift). **Reversed**: confirmed the "loud flags/alarms"
requirement needs to reach people not looking at this page (email/
dashboard/another tool) — a client-only computed value structurally can't
do that, nothing server-side can react to something that only exists in
one browser tab. Built:

- **New field, `slot.confirm_state`** — `unconfirmed` / `filled` /
  `discrepancy` / `impossible` / `empty` (pick/custom-simple, same pattern
  as `tub.state`). **You need to create this field in Pods admin**
  yourself (local now; remember TEST/OPS later) — I wired the code
  assuming it exists, but can't create Pods fields from here.
  `_specs.php`/`_policy.php` updated (writeable for `_default`/`editor`).
- **Written by `_reconcileCabinet()`** — every slot, every run (not just
  ones this pass changed), batched into a `Cabinet` POST alongside the
  `FlavorTub` writes. Means every page load's automatic run bumps
  `scoop_cache_version` (global, not per-row — matches existing behavior,
  just a bit more often now) even when nothing needed fixing. Not
  optimized to skip unchanged slots — would need reading the current value
  back first, which nothing does today.
- **Reset to `unconfirmed` proactively** — `scoop_enforce_tub_rules()`
  (`tub-state.php`) reads the tub's *old* `slot` value before clearing it
  on the Emptied transition, and writes `confirm_state: 'unconfirmed'` to
  that slot in the same hook — so the flag goes stale the instant a linked
  tub empties via ANY path, not just when someone next opens this GUI.
- **Not built**: the actual alarm/reach channel (email, wp-admin widget,
  whatever). This is the infrastructure that makes one possible — reading
  `confirm_state` and doing something loud with it is a separate,
  not-yet-specced piece.

### `needs-tub`/`impossible`/empty QA indicators + `FlavorPickerModal`

Crude dev-only status glyphs added first (`CabinetWorkflowTile
._applyConformanceStatus`, `css.css`'s `.needs-tub`/`.impossible` `::after`
rules): a filled slot with no `openTub` yet (auto-resolvable, Confirm
Cabinet just hasn't paired it) gets `needs-tub` (red X on the `<li>`);
`row.impossible` gets a black `I`; a FRAME-level `mismatch`/`conforms`/
`all-paired` class cross-checks `slot.tub` against the tub's own `.slot`
back-reference (Pods relationship fields are only reliably canonical from
one side — see project memory on postmeta drift) and whether every
pairable slot is actually paired.

Then the free-form picker: `flavor-picker-modal.js` (`FlavorPickerModal`),
opened from `add-next` (needs-tub/impossible slots) or `add-flavor` (empty
slots) instead of `ConfirmSwapModal` whenever there's no already-linked tub
to propose swapping — `add-next` on a `discrepancy` slot still stays
disabled (must run Confirm Cabinet first, ambiguous which open tub belongs
there).

**`pickableFlavors(row)`** (`cabinet-workflow-grid-model.js`) is the
candidate list, all four required:

1. At least one front-of-house tub of that flavor, anywhere, not `Emptied`
   and not `!Lost` — `NON_PICKABLE_STATES`, deliberately narrower than
   `promotablePool`'s `NON_PROMOTABLE_STATES`: an already-`Opened` tub DOES
   make its flavor pickable here (different question from "what should get
   newly promoted").
2. No `_allergenConflict` with the slot's cabinet — two rules, both must
   clear: (a) none of the flavor's allergens are on the cabinet's own
   `prohibited_allergens`, and (b) if the cabinet does **not** prohibit
   `dairy`, it's a dairy cabinet by convention and only dairy-base flavors
   qualify (`'dairy'` isn't its own field — a flavor is dairy-base iff
   `'dairy'` is among `flavor.allergens`), regardless of what else that
   cabinet does/doesn't prohibit. A cabinet that DOES prohibit dairy (oat/
   coconut/pea) skips the extra requirement — rule (a) already excludes
   dairy-base flavors there on its own. This same method backs both the
   picker and `row.impossible`, so the dairy-cabinet rule also changes
   which existing slots Confirm Cabinet flags `impossible`, not just what
   the picker offers.
3. Not already `current_flavor` on a *different* slot in the same cabinet —
   no duplicate flavors per cabinet (`_siblingFlavorIds`), not enforced
   anywhere else (not server-side, not retroactively against existing
   data).
4. Excludes the row's own `currentTubId` from counting as that flavor's
   stock — it's about to be evicted by the very action being previewed (see
   "uniform tub-selection" below), so it can't count as its own
   replacement.

### Flavor pick hands off to `ConfirmSwapModal` — no separate write path

Reworked once already (briefly: picking only wrote `current_flavor`,
deferred all tub resolution to the next Confirm Cabinet pass) before
landing here. **`FlavorPickerModal` writes nothing.** Clicking a tile just
hands `{ row, flavorId }` to `ConfirmSwapModal.open(row, flavorId)` — the
same dialog `add-next` already used for an already-paired slot, now
handling a second entry point. `ConfirmSwapModal` owns the actual tub
search/preview/write either way; a real Confirm Swap / Change Plan / Leave
Empty dialog is the "reject before it happens" gate, so no separate native
`confirm()` was needed after all. The two modals wire together via
constructor callbacks (`onPick`/`onChangePlan`), not direct references, to
avoid a construction-order circular dependency — "Change Plan" now reopens
`FlavorPickerModal` instead of alerting "isn't built yet."

**Uniform tub-selection**, `CabinetWorkflowGridModel.planTubChange(row,
flavorId, preferWhole)` — same logic for both entry points, no
same-flavor special case (deliberately: re-picking the slot's own current
flavor to cycle to the next tub, cited as the *most common* use of this
GUI, must behave identically to picking a brand-new flavor):

- **`outgoingTub`** — `row.currentTubId` resolved regardless of its
  state/validity (a `needs-tub`/`impossible` slot can still have a stale
  `slot.tub` link — e.g. pointing at a tub whose flavor didn't match the
  *old* `current_flavor`, or one still `Hardening`). Always evicted. Only
  actually marked `Emptied`+stamped if it was genuinely `Opened` and "This
  box is not empty" isn't checked — a stale non-`Opened` link just gets
  unlinked (`slot: 0`), not misrepresented as emptied.
- **`pickNextTub(flavorId, locationId, excludeTubId, preferWhole)`** —
  separate from `pickPromotableTub`/`pickClosestToOne`, which still serve
  Confirm Cabinet's own automatic reconciliation unchanged. Two rules:
  - (a) an `Opened`, front-of-house, unclaimed tub of this flavor —
    nothing to promote, just adopt. Global, not location-scoped (location
    is a sort preference, not a filter). Ignores `preferWhole` entirely.
  - (b) falls back to `promotablePool` (`Emptied`/`Opened`/`!Lost`
    excluded) when (a) finds nothing. `preferWhole` applies here, same
    meaning as `pickPromotableTub`'s checkbox.
  - Both pools sort same-location-first, then (b only) whole/partial per
    `preferWhole`, then oldest `created_on`. `amount` is otherwise not a
    factor — a broader reconsideration of amount-based selection is out of
    scope for this feature; `preferWhole` remains the one user-dictated
    exception.
  - `excludeTubId` = the row's own `outgoingTub` id, so it can never count
    as its own replacement (the QA conversation's "problem case 2": an
    about-to-be-evicted tub shouldn't inflate its own flavor's apparent
    availability, in `pickableFlavors` *or* here).

**Debug alert cascade** (`ConfirmSwapModal._confirm()`, after a successful
write) — native, sequential `alert()` calls, deliberately crude for
now (manual/visual verification), not final UX:

- `"[tub] was emptied"` — only if the outgoing tub was actually marked
  `Emptied` (not just unlinked).
- `"[tub] is at a different location."` — if the selected tub's location
  differs from the slot's (tub gets promoted in from cross-location
  regardless, same as Confirm Cabinet always has — this only adds the
  alert).
- `"Using a partial"` — if the selected tub's `amount < 1`.
- Then exactly one of: `"perfect!"` (rule (a), exactly one candidate),
  `"Found tub [x]. Not selected: [y], [z]"` (rule (a), multiple
  candidates), or `"Found match: [x]"` (rule (b)).
- No-match (`plan.tub` null) doesn't get a separate alert — `CONFIRM_BTN`
  is simply disabled and `IMG_META` already reads "No tub available for
  this flavor," which the dialog shows before any commit is possible.

**Explicitly deferred**: "back out of after it happens" (a real undo, once
Pods/WordPress writes have already landed — there's no transaction layer
here) and a reviewable change log through another GUI element. Both were
raised as requirements but scoped out of this pass, same pattern as prior
stubbed work in this doc.

### "none planned" schedules immediate_flavor/next_flavor via the same picker

`ConfirmSwapModal`'s `IMMEDIATE_P`/`NEXT_P` lines already went through
`_renderFlavorLine`; when empty they read plain-text "none planned." Made
clickable: opens the same `FlavorPickerModal` instance, but with a one-off
`onPick` override (passed through `open(row, onPick)`, which now takes an
optional per-call override instead of always using the constructor
default) — picking a flavor there writes straight to `immediate_flavor` or
`next_flavor` (`_pickScheduled`), no tub involved. `openPickerFor`/`getRow`
are two more constructor callbacks threaded from `CabinetWorkflowTile`
(same circular-dependency-avoidance pattern as `onChangePlan`): the former
opens the shared picker with an override, the latter re-fetches the row by
`slotId` after `refreshPageDomain` rebuilds `this.items` — the row object
captured when "none planned" was clicked is stale by the time the write
lands, so the dialog needs a fresh one to reopen with (same tub-swap
target it had before the detour, since `this._selectedFlavorId` is
captured and threaded through independent of which row instance it's
rendered against).

### Optimistic repaint + confirmation diff, `confirming` marker

`ConfirmSwapModal._confirm()`/`_confirmEmpty()` no longer just sit through
`refreshPageDomain`'s round-trip (can take 10+ seconds — every write
invalidates the server's bundle cache, guaranteeing a cold miss) with the
card showing stale data the whole time. Two new `CabinetWorkflowTile`
methods, wired in as constructor callbacks (`paintOptimistic`/
`confirmOptimistic`, same pattern as `onChangePlan`/`getRow`):

- **`_paintOptimistic(slotId, patch)`** — called right after the write's
  own POST(s) succeed (before the confirmation refetch even starts).
  Shallow-overlays the known outcome (flavor identity, the newly linked
  tub — only what the caller can already predict, not derived fields like
  `tubCountLocal/Total`) onto the slot's current row, renders it through
  `buildItemDom()` (same path every row uses — no second rendering
  implementation), marks the `<li>` `confirming` (CSS: dashed yellow
  outline + pulse, `css.css`), and swaps it in for the real node.
- **`_confirmOptimistic(slotId, expected)`** — called after
  `refreshPageDomain` resolves. Reads `api.getDomainSnapshot()` directly
  and compares the slot's raw `current_flavor`/`tub` fields against
  `expected`, rather than relying on `this.items`/the generic
  `_onDomainUpdated` listener having already run — that listener's async
  body isn't guaranteed to have completed by the time this await resolves,
  since `ts:domain:updated` dispatch doesn't wait for its own handlers.
  Match: drop `confirming`, nothing else repaints. Mismatch: alert, then a
  genuine `this.refresh()` (full rebuild) — the additive `_patchRefresh`
  every other grid gets on a background refresh (see PARTIAL-REFRESH.md)
  has no field-level mechanism for this tile's hand-built cards (it ships
  no columns — see this file's header comment), so it can't be trusted to
  correct a wrong optimistic node either.

**No new code needed for other grids on the page**: `refreshPageDomain`'s
`ts:domain:updated` broadcast already reaches every mounted List/Tile,
including a plain `Cabinet` grid if one's on the same page — it patch-
refreshes itself via the existing PARTIAL-REFRESH.md mechanism regardless
of what this tile does with its own `confirming` state.

### Confirm Cabinet gets the same optimistic preview, batched

Extended to `_reconcileCabinet()`, which can resolve many slots in one
pass (unlike `ConfirmSwapModal`'s writes, always exactly one). Every row
that gets a NEW tub linked this pass (adopted via `openUnclaimedPool`,
a discrepancy pick, or freshly promoted via `pickPromotableTub`) is
collected into `pendingPatches` as `{ row, tub }` while the existing
resolution loop runs — nothing added for already-`filled` or
`impossible` rows, since those aren't changing.

After the writes succeed: `_paintOptimistic()` runs once per pending
patch (so every resolved slot shows its new tub immediately, marked
`confirming`), then the summary alert fires (moved earlier than before —
previously it waited out the confirmation refetch too), then the
confirmation refetch runs, then `_confirmOptimisticBatch()` — a new
batched sibling of `_confirmOptimistic` — diffs every pending patch
against the fresh domain in one pass: any match just drops `confirming`;
if any mismatch, ONE consolidated alert + ONE full rebuild, not one of
each per slot. `_confirmOptimistic(slotId, expected)` (used by
`ConfirmSwapModal`'s single-slot writes) is now a thin wrapper calling
`_confirmOptimisticBatch([{ slotId, ...expected }])` — no duplicated
comparison logic between the two call shapes.

The GUI-blocking behavior (`FRAME.style.pointerEvents = 'none'` for the
whole pass, per the original "grid isn't usable until reconciliation
completes" requirement) is unchanged — optimistic painting happens
*inside* that same blocked window, it doesn't shorten it. What changes is
what the user sees *during* that window: the resolved cards update (and
the summary alert fires) right after the writes land, rather than the
whole cabinet sitting visually frozen until the confirmation refetch
(10+ seconds) also completes.

### `ready-to-link` (P) splits `needs-tub` into its two real outcomes

`needs-tub` (X) never distinguished *which* of Confirm Cabinet's two
resolution paths a given slot would take — link an already-`Opened`
unclaimed tub (no state write) vs. promote a fresh one (`state: 'Opened'`
write). `row.readyToLink` (`cabinet-workflow-grid-model.js`, computed
right next to `row.discrepancy` since both reuse the same already-computed
`unclaimedOpen` list) is `true` exactly when that pool has one match —
mutually exclusive with `discrepancy` (>1 match) and `impossible`
(0 matches AND nothing promotable either) by construction, so no extra
guard was needed. `needs-tub` now means specifically "0 unclaimed-open
matches, but something promotable exists" — the case that actually
requires opening a new tub. Glyph: `P`, `var(--color-status-info)` (blue),
same `::after` pattern as `needs-tub`/`impossible`.

### Tempering outranks every other non-Opened state when promoting a tub

A physical-correctness rule, not a per-flow preference — applied to both
`pickPromotableTub` (Confirm Cabinet) and `pickNextTub`'s rule (b)
(`ConfirmSwapModal`), via a shared `_temperingRank(t)` helper
(`cabinet-workflow-grid-model.js`). A tub already `Tempering` beats any
`Hardening`/`Freezing`/`__override__` tub outright — the *top* sort key,
not a tie-break applied only among candidates already equal on whole/
partial or age. Order became:

- `pickPromotableTub`: Tempering, then whole/partial (`preferWhole`), then
  oldest `created_on`.
- `pickNextTub` rule (b): Tempering, then location match, then whole/
  partial, then oldest `created_on` — Tempering ranks even above location,
  reflecting the QA conversation's "outranks all non-opened" as
  unconditional, stronger language than location's earlier "one of the
  earliest rules, but not enough to disqualify."

Confirmed scope before implementing: Tempering only outranks other
non-`Opened` candidates within the promotable pool — an already-`Opened`,
unclaimed tub (rule (a)/`openUnclaimedPool`) is still always tried and
adopted first, unaffected by this change.

### Diagnosed a real-flavor bug via the local mirror's PHP CLI, not speculation

A slot flipped to "Raspberry Rose Truffle" (an `impossible` slot) even
though its only tub was believed already `Emptied`. Verified against the
actual `local` DB (`wp-load.php` bootstrap + Pods API, via the site's own
`php.exe`/`php.ini` under `AppData\Roaming\Local\...` — see project memory
on the local PHP CLI how-to) rather than guessing: `_temperingRank`,
`NON_PICKABLE_STATES`, `NON_PROMOTABLE_STATES` are all correct as shipped
(no inverted condition, no case-sensitivity bug) — the flavor's one tub
(`id=12850`) really was `Emptied` by the time it was queried, but that
doesn't prove it was already `Emptied` at pick time. Most likely
explanation: **client-side domain staleness** — `pickableFlavors`/
`pickNextTub` check `this.domain.tub`, a snapshot from this tab's last
fetch, not a live query; if the tub was emptied via a different tab/
action after that snapshot but before the picker opened, the picker
would correctly-per-its-stale-view show it as available. No pre-write
freshness re-check exists today (only the post-write
`_confirmOptimisticBatch` diff, which catches it *after* the fact, not
before) — flagged, not yet built.

### Tub counts, made visible where they matter

Two additions, both reusing the same new `pickableTubCount(flavorId,
locationId)` rule (front-of-house, not `Emptied`, not `!Lost` —
`NON_PICKABLE_STATES`, so `Opened` tubs DO count) — a deliberately
different figure from `remainingSummary`'s `DISPLAY_EXCLUDED_STATES`
(which additionally excludes `Opened`, answering "how much is still in
the pipeline" rather than "is this pickable right now"). `remainingSummary`
itself is untouched — still used by `ConfirmSwapModal`'s "X has A and B
here" lines, not in scope here.

- **`FlavorPickerModal` tiles** now show a small count badge (top-right)
  — the same tub tally that qualified each flavor for the list in the
  first place, via `pickableFlavors()`'s `count` (computed inline, one
  pass over `domain.tub`, alongside the flavor-id set it already built —
  not a second pass calling `pickableTubCount` per flavor). Directly
  motivated by the Raspberry Rose Truffle bug above: a visible count is
  at least a chance to notice "this says 1, but I thought that one was
  already emptied" before committing to a pick.
- **Cabinet card counts** (`tub-count-local`/`tub-count-total`,
  `_fillSlotRow`) switched from `remainingSummary` to `pickableTubCount`
  — now consistent with what the flavor picker itself considers pickable,
  where before the two used different eligibility rules (the card
  excluded `Opened` tubs from its count, the picker didn't).

### CabinetWorkflow writes get their own immediate `inventory_change` record

Every CabinetWorkflow write (Confirm Swap, Leave Empty, Confirm Cabinet,
schedule immediate/next) is a plain UPDATE-mode write to `tub`/`slot` —
before this, indistinguishable server-side from any *other* grid editing
the same routes, so it was silently folded into the generic per-user,
per-session batch (`scoop_stage_inventory_change()`, flushed on an idle
timer) with `entity: 'session'`, `source: 'session'` hardcoded. No trace
of "this came from CabinetWorkflow" survived.

Fixed by having the client send an explicit hint alongside `cells` in
every CabinetWorkflow write — `{ cells: {...}, source: 'workflow' }` —
which `scoop_handle_cells_post()` (`includes/rest.php`) reads, validates
against a whitelist (`['workflow']` — untrusted client input landing
directly in a Pods field, not passed through freely), and when present,
routes to **immediate** logging via `scoop_log_post()` (a new optional
`$source_override` param) instead of staging — same treatment Batch/
Closeout creates already get, on the same reasoning: each of these actions
is already its own meaningful, infrequent event, not rapid autosave
keystrokes. Confirmed end-to-end against real local data (a real REST
POST through `scoop_handle_request`) — the new record appears immediately,
correctly tagged `source: 'workflow'`, not staged.

**Found and fixed in passing**: `ConfirmSwapModal._confirmEmpty()`'s slot
write was `{ cells: slotCells }` — missing the `{ [row.slotId]: slotCells }`
wrapper every other write in the file uses. Since `scoop_handle_cells_post`
expects `cells` keyed by post ID, this meant "leave slot empty" never
actually cleared `current_flavor` — a real, pre-existing bug, unrelated to
today's change, caught only because this exact line needed touching for
the source hint.

### `row.openTub` stopped trusting `slot.tub` — sister-field pairing gap

A slot with a genuinely valid, already-linked tub (`tub.slot` correctly
pointing at it) still showed `impossible` on load. Root cause: `slot.tub`
(the *reverse* direction of the `slot.tub`/`tub.slot` bidirectional Pods
sister-field pair — see the top of this doc) was returning `0` on the
environment being tested, even though `tub.slot` (the *forward* direction,
the side every write actually goes through — Confirm Cabinet/
ConfirmSwapModal never write `slot.tub` directly) was correct. Confirmed via
a real bundle response: slot `1242`'s `tub` field was `0` while tub `10992`'s
`slot` field was `1242`.

Two theories were checked and ruled out before landing on the real one:

- **Draft `post_status`** — Pods pick fields across this schema default to
  `pick_post_status=publish` (see `includes/republish-tubs-ui.php`), so a
  draft *target* silently drops out of a relationship read. Tempting given
  the same-week `removing unpublished hack`/`speeding up republish to avoid
  timeout` commits, but doesn't fit: the tub's `opened_on` was set for the
  first time on this exact promotion (never previously `Emptied`, the only
  thing that ever demoted a tub to draft — and that logic is gone now
  anyway), and the republish sweep had already run site-wide (0 drafts
  remaining) before this bug was even reported.
- **Broken `sister_id` pairing in Pods admin** — checked directly in Pods
  Admin: the `slot`↔`tub` bidirectional pairing *was* correctly configured,
  both sides showing each other. So not a config gap either, at least not
  a persistent one.

The practical fix doesn't depend on knowing exactly why the reverse lookup
failed: `_fillSlotRow` (`cabinet-workflow-grid-model.js`) now finds the
linked tub by searching `domain.tub` for `t.slot === slot.id` (the forward
field) instead of reading `slot.tub` at all. Same data Pods would eventually
resolve either way, but sourced from the side that's actually written and
whose target (a slot) is effectively always `publish` — structurally
immune to whatever class of relationship-read hiccup this was, not just a
one-off patch for this one slot.

**Also fixed in passing**: `FRONT_OF_HOUSE_USE_ID` (`1863`) matching was
exact-only across every FOH-stock check in this model
(`openUnclaimedPool`/`promotablePool`/`pickableTubCount`/`pickableFlavors`/
`_fohTubsExcluding`/`_openUnclaimedAnywhere`). A real tub (`10992`) had
`use: 0` (unset) despite being open, linked, and shown as Front-of-House in
WP admin — invisible to all of the above regardless of the `slot.tub` bug,
which is what turned a "should be `ready-to-link`" row into `impossible`.
Added `isFrontOfHouseUse()`, treating an unset `use` the same as an
explicit Front-of-House id everywhere this model checks for FOH stock —
matches the precedent already in `flavor-tub-grid-model.js`'s
`_isFrontOfHouseUse`, which does the same for its own "non-front" filter.

## Decisions log

1. `add next` tie-break: always take the oldest **whole** tub if one
   exists; fractional only when no whole tub is available. `add special`
   owns any deliberate early/fractional pull.
2. No Details-panel drill-down on the flavor name — this view is a
   one-click workflow tool, not a research/reporting surface.
3. `confirm-tubs` needs no cabinet-id filter — the page's existing
   `location` param already scopes it correctly (2 cabinets today, more
   per location later). `add next` is gated on `tub-count-local` being
   nonzero; `add special` is where staff act on `tub-count-total` showing
   stock at another location.
4. Grid type name: `CabinetWorkflow` — bare PascalCase like every other
   type, no `WF_`/underscore prefix, so it needs no special-casing in file
   names, routes, or CSS.
5. Same-flavor-in-two-positions (current/immediate/next), 2026-08-29:
   the server-side rule that a flavor can only hold one of a slot's three
   designations at a time already existed (`scoop_slot_pre_save_dedupe_own_fields`/
   `scoop_slot_post_save_dedupe_other_slots`, `includes/hooks/cabinet-slot.php`)
   and needed no changes — it correctly clears whichever OTHER field held a
   duplicate, for any write path. Two gaps on top of it got fixed:
   (a) `ConfirmSwapModal._confirm()` progressing current -> immediate
   (`_defaultFlavorId`'s common case) only ever wrote `current_flavor`, so
   the dedupe hook just zeroed immediate_flavor out — it has no way to know
   this was a genuine progression rather than an arbitrary edit, so it can
   clear a duplicate but can't shift the chain. Fixed client-side, the one
   place that actually knows: immediate_flavor now explicitly inherits
   next_flavor (or 0) in the same write. (b) `FlavorPickerModal`, reused by
   the "none planned" links to schedule immediate_flavor/next_flavor
   directly, offered every pickable flavor including ones already sitting
   in this same slot's OTHER two fields — technically harmless (the hook
   would just clear the collision on save) but a bad UX surprise. Now takes
   an `excludeIds` list (open()'s 3rd arg) so `_openPickerForField` can
   leave those out up front.

## Build phases (incremental test/deploy)

**Phase 1 — read-only view. Built, needs local-mirror verification.**
Bundle spec + `slot.tubs` entity field (`includes/_specs.php`),
`cabinet-workflow-grid-model.js` (rows-per-slot, grouped by cabinet,
`tub-count-local`/`tub-count-total` computed client-side from the bundle's
`tub` domain, slots scoped to `this.location` client-side), and
`cabinet-workflow-tile.js` (the slot LI markup — empty vs
flavor-assigned, `add next`/`add special`/`add flavor` buttons present/
hidden per the rules above but **not wired to any handler**), plus the
`getViewOverrides()` hook in `scoop-api.js` and a `button.save` hide rule
in `css.css`. Pure GET — no new write routes, nothing mutates. Safe to
validate on TEST directly since there's no write path yet.

Not yet done: try `[scoop_tile type="CabinetWorkflow" location="935"]` on
the local mirror (`https://ops.swankyscoop.local`) and confirm — every
slot shows in the right cabinet group, empty slots show "Add Flavor",
filled slots show name/photo/counts, `add next` disappears when
`tub-count-local` is 0, and the console has no errors.

**Phase 2 — "add next".** `POST /wp-json/scoop/v1/slots/{id}/advance`
(steps 1–8 above) plus the client click handler and in-place slot/tub
patch. This is the first phase that writes to live tub/slot data — build
and validate on TEST per CLAUDE.md's data-repair policy (dry run isn't
practical here since it's a single-slot atomic action; test with a
disposable slot/tub pair first) before shipping to OPS.

**Phase 3 — "confirm tubs in cabinets".** Dry-run GET + apply POST,
toolbar button + report list UI. Also write-path — same TEST-before-OPS
rule, and the dry-run report *is* the built-in safety check.

**Later, unscheduled** — `add special` and `add flavor` modals: no spec
yet, buttons stay inert past phase 1 until that follow-up design lands.
