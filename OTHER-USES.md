# OTHER USES

Status: context captured, no code written yet.

## Problem

Today a `tub` record has exactly one `use` (a relationship to the `use` pod —
e.g. Front-of-House, Event, Kiosk) and one `amount` (fraction of a tub, 1.0 =
whole), set at creation and never adjusted afterward.

In reality a tub is sometimes split across uses: e.g. part of a tub goes to
an event, the rest stays Front-of-House. There's currently no way to record
that split.

## Developer's proposed approach

Rather than support multiple uses + multiple amounts on a single tub record,
model a split as **new tub records**:

- When a portion of a tub is claimed for a different use, create a new tub
  row for that portion: `amount` = the claimed portion, `use` = the new use,
  `state` = `'Emptied'`, `emptied_at` = now (i.e. it's recorded as already
  used, immediately).
- Subtract that same portion from the **original** tub's `amount`.
- A tub that's been split multiple times just ends up as several tub records
  each with `amount` < 1, tied back to the same origin.

Ideally this is exposed as an action inside CabinetWorkflow, since that's
where staff already interact with tubs day-to-day.

## Grounded facts (current codebase, as of worktree creation)

- The Pods post type behind the `FlavorTub` grid is named `tub` (`FlavorTub`
  is only the bundle/route/grid-type key). Fields: `state` (enum: Opened,
  Emptied, Tempering, Hardening, Freezing, `__override__`, `!Lost`), `use`
  (single relationship → `use` pod), `flavor`, `amount` (float), `opened_on`
  / `emptied_at` (system-controlled datetimes), `slot`, `location`, `batch`,
  `closeout`. There is **no boolean `emptied` field** — "emptied" is the
  `state` value `'Emptied'`, paired with `emptied_at`. Writeable fields for
  `tub`: `state, use, amount, slot` (`includes/_specs.php`).
- The `use` pod is a plain lookup post type (just title + sort order) — no
  custom fields. Front-of-House's ID is hardcoded as `1863`
  (`FRONT_OF_HOUSE_USE_ID` in both `assets/models/cabinet-workflow-grid-model.js`
  and `assets/models/flavor-tub-grid-model.js`); a tub with `use` unset (0)
  is treated as Front-of-House everywhere.
- **A field named `tub.alt_uses` already exists in the hand-authored schema**
  (`includes/pods-schema/_schema.php`, lines ~899-949) — a multi-value pick
  relationship to the `use` pod, documented in its own description as
  "Every other use this tub has been recorded as, besides its current one...
  Written by a pre-save hook (`includes/hooks/tub-state.php`), not
  user-editable." **This is aspirational only** — no such hook logic exists
  yet in `tub-state.php`, and the field isn't applied to any live
  environment. It looks like unfinished groundwork for exactly this feature
  from an earlier session — worth reconciling with rather than duplicating.
- `includes/hooks/tub-state.php` (`scoop_enforce_tub_rules`) is where tub
  write rules are enforced today: `opened_on`/`emptied_at` are always
  system-stamped (client values are silently reverted), state transitions
  are gated (e.g. reverting out of `Emptied` only within
  `SCOOP_TUB_EMPTIED_REVERT_HOURS` = 96h), and transitioning a tub to
  `Emptied` unlinks it from its `slot` and force-resets that slot's
  `confirm_state` to `unconfirmed`. No existing logic there touches `use`,
  `amount`, or `alt_uses` — a split feature would be net-new here.
- `includes/hooks/closeout.php` defines the "whole tub" threshold
  (`amount >= 0.8`), mirrored client-side as `WHOLE_TUB_THRESHOLD` in
  `cabinet-workflow-grid-model.js`. A split feature will produce more
  sub-0.8 partial tubs than exist today — worth checking this threshold and
  any other `amount`-based logic still behaves sensibly.
- CabinetWorkflow (`assets/models/cabinet-workflow-grid-model.js` +
  `assets/ui/cabinet-workflow-tile.js`) has no server-side `_config.php`
  route of its own — it reads tub data from the bundle and writes back
  through the existing `FlavorTub` route (`ScoopAPI.postJson(..., 'FlavorTub')`).
  It currently writes `state` and `slot` only; it reads but does not write
  `use` or `amount`. Its promotion logic (`promotablePool`, `pickPromotableTub`)
  filters on `isFrontOfHouseUse(t.use)` and uses `amount` only as a
  whole-vs-partial tie-break — a new "split" action would need a new code
  path here (and likely a `create`-mode write, since it produces a new tub
  row, unlike the route's current `update`-only mode).

## Decisions

- Lineage: a new field **`split_tubs`** links tubs to each other directly
  (tub-to-tub relationship), rather than relying on `alt_uses` (a tub→use
  link) for tracing where a split-off tub came from.
- **`split_tubs` shape (created in Pods admin on `.local`, not yet in the
  schema file):** multi-value, bidirectional sister field, self-referential
  on `tub`. Bidirectional means Pods auto-mirrors A→B as B→A on save — but
  it is *not* transitive: if tub A splits into B and C, A.split_tubs = [B,
  C] and each of B/C auto-gets [A], but B and C are not directly linked to
  each other. Finding "every piece of one original whole tub" from an
  arbitrary member means a graph walk (BFS/DFS over `split_tubs`), not a
  single flat read. Caveat per [[schema-sync-sister-id-gap]]: if/when this
  field is exported to the schema file for test/ops, Schema Sync strips
  `sister_id` — the bidirectional pairing has to be manually re-established
  on each environment it lands on.
- **`alt_uses` removed** (2026-08-21) — deleted from
  `includes/pods-schema/_schema.php` (field entry + its header comment
  block). It was hand-authored only, never applied to any live environment
  (nothing to Apply/remove elsewhere), and `split_tubs` supersedes it for
  the lineage/history purpose it was meant to serve.

## Write-path design (discussed, not yet decided/coded)

Splitting needs to (a) create a new tub row for the claimed portion and (b)
decrement the origin tub's `amount` — in one operation, ideally, since the
`tub` table is MyISAM (no transactions). The existing `FlavorTub` route is
`mode: update` only, so it can't create a row.

Leading option discussed: a new create-mode route (e.g. `TubSplit`, `mode:
create`, `pod_name: tub`) whose pre-save hook — mirroring how `Batch`
creation already spawns tub rows via `scoop_create_tubs_for_new_batch` in
`includes/hooks/batch-tub.php` — reads the origin tub id off the incoming
`split_tubs` value, validates the requested portion against the origin's
current `amount`, decrements the origin, and stamps the new row
`state='Emptied'` / `emptied_at=now()`. Rejected alternative: two separate
client requests (create, then update origin) — no transactions to fall back
on if the second call fails.

**Not yet decided — explicitly deferred, discuss before coding:**

- Whether to actually build the route above, or a different write path.
- Whether the split action is exposed only inside CabinetWorkflow (matches
  the original framing — staff already work tubs there, and it already
  knows a tub's cabinet/slot context) or also from the plain FlavorTub grid.
- Whether to enforce a minimum split size (floor on the portion, to avoid
  near-zero junk records) — and if so, what the floor is.
- What the new split-off row inherits from origin (draft assumption, not
  confirmed: `flavor`/`location`/`batch`/`closeout` copied over, `slot`
  left unset since it's recorded as already emptied).
- Permissions: same roles that can already write `use`/`amount` on a tub,
  or a narrower set for splitting specifically.

## Tub detail modal (GUI plan)

Prompted by wanting a click-to-see-details modal for tubs — reachable from
`.tub-square` in the ItemPivot grid, and from a new "edit" column on the
FlavorTub grid — that eventually also hosts the split/alt-use control.

**Grounded finding that changed the plan:** `.tub-square`
(`assets/ui/item-pivot-grid.js`) already carries `data-detail-entity="tub"`
/ `data-detail-id`, and a click on it already opens something today — the
existing generic, page-wide `Details` panel (`assets/ui/details.js`,
attached once via `Details.attach(api)` in `assets/app.js`). It's a
read-only `<dl>` field list, with two-level stacking (`.DETAILS`/
`.DETAILS2`) and hash-based URL state (`#details=entity:id`, so
back/forward works) — but it has **zero CSS today**, unlike CabinetWorkflow's
two modals (`FlavorPickerModal`, `ConfirmSwapModal` in `assets/ui/`), which
are bespoke `El` subclasses (no shared base class between them) sharing a
real `.modal`/`.show` overlay CSS treatment (`assets/css.css`, "MODAL -
Cabinet Workflow grid" section).

### Decisions

- **Reuse, don't rebuild**: restyle the existing `Details` panel with
  CabinetWorkflow's `.modal`/`.show` CSS convention rather than building a
  new bespoke modal class. Keeps the already-working plumbing (global click
  delegation in `_list.js`, `.tub-square`'s existing wiring, hash-based
  state).
- **Editability**: long-term goal is editable fields in the modal, gated
  per-field by the same server-computed write permissions already used
  elsewhere (`scoop_client_metadata()`'s per-column `write` flag, sourced
  from `_policy.php`). **Not built now** — for this pass, the modal is
  read-only field display plus a separate "actions" area (for row-level
  actions like the future split control). The actions area is **not**
  permission-gated yet either — assume available to everyone for now;
  restricting it via `_policy.php` is explicit future follow-up, not part
  of this pass.
- **FlavorTub "edit" column**: generalize the existing detail-link column
  renderer (`_list.js`, currently hardcoded to only fire when
  `col.detailEntity ?? col.titleMap === 'flavor'`) to work for any
  entity/column, rather than adding a new declarative column `type`
  (unlike the `type: 'delete'` pattern). `FlavorTubGridModel` will need a
  synthetic client-side column opting into it — following the precedent in
  `assets/models/instock-flavor-grid-model.js` (`{key:'_title',
  detailEntity:'flavor'}`), but pointed at `tub`/the row's own id.
- **Split/alt-use control**: placeholder only. Reserve a spot in the
  modal's actions area, stubbed/no-op, until the write-path question above
  (new create-mode route vs. alternative) is actually decided and built.

### Built so far (2026-08-21)

The click→modal *mechanism* is generic; what renders *inside* the modal is
per-entity — that split is now real, not just a plan:

- `assets/ui/details.js`: `Details._VIEWS` is an `entity -> renderFn` map
  (currently `{ tub: renderTubDetails }`). `_render()` looks up
  `_VIEWS[entity]`, falling back to the generic `fillFields` dump for any
  entity without an entry. `_ensureHost()` now gives the panel the exact
  `.modal`/`form`/`.show` structure and class names CabinetWorkflow's
  modals use (`assets/css.css`'s existing `body > .modal` rules apply
  as-is, no parallel CSS), plus a new `.actions` container alongside
  `.fields` for entity-specific row actions.
- `assets/ui/_detail-fields.js` (new): the generic field-dump + relation-
  link-resolving logic, factored out of `Details` so entity-specific views
  can call it as a base and layer on top, instead of duplicating it.
- `assets/ui/tub-detail-view.js` (new): `renderTubDetails()` — calls the
  generic `fillFields` (no field curation yet, see "still open" below) and
  appends one disabled placeholder button to `.actions` for the future
  split action.
- `assets/ui/item-pivot-grid.js` + `assets/css.css`: ItemPivot's squares
  now carry both `item-square` (generic sizing/border/hover, entity-
  agnostic) and `tub-square` (tub-only `state-*` background colors) — the
  click trigger itself was already entity-agnostic (keyed on the
  `data-detail-entity`/`data-detail-id` attributes, not the class), so no
  change was needed there.

**Not built yet**: the FlavorTub grid "edit" column (still needs the
detail-link renderer generalized per the decision above, and a synthetic
column added to `FlavorTubGridModel`), the split action's real write path,
per-field edit permissions.

### Detail-link renderer generalized (2026-08-21)

The "generalize the detail-link column pattern" decision above is now
partly done — the *rendering* half, ahead of the FlavorTub edit column
that will consume it:

- `assets/ui/_list.js`'s `_renderFieldValue`: the detail-link branch was
  hardcoded to `entityKey === 'flavor'`; now any column with a resolved
  `col.detailEntity ?? col.titleMap` links, not just `flavor`. This also
  activates links for `use`/`location`/`cabinet` relationship columns
  (`_inferTitleMap` already computed them, they just never rendered as
  links before).
- `assets/models/_base-grid-model.js`'s `fillRowFromColumns`: a
  `col.detailEntity` column now automatically gets `id = rowData.id` (the
  row's own id) instead of `Number(raw)` (which was `NaN` for a title
  string). This is what `instock-flavor-grid-model.js`'s local
  `_applyTitleLink` hack did by hand for one case — removed that hack
  (and its now-redundant `_fillFlavorRow` wrapper line) now that it's
  automatic for any model.
- Net effect: any grid model can make its own row's title clickable with
  one column def line — `{ key: '_title', detailEntity: '<its own pod>' }`
  — same as InstockFlavor already had, just no longer flavor-specific or
  requiring a per-model id-fixup hack.

**Checked, deliberately not touched**: `flavors-grid-model.js`'s
`flavor_name` column (Analytics-sourced Flavors list) shows each flavor's
own name as plain text today — a real candidate for this. Left alone
because it's built from `/scoop/v1/analytics` response data
(`f.flavor_id`/`f.flavor_name`), bypassing the bundle pattern entirely (see
CLAUDE.md, "Analytics is the odd one out") — wiring it up risks a dead
link ("Not loaded on this page") on any page where the Flavors analytics
view is shown without the `flavor` domain also being bundled from another
grid on the same page. Flag for a follow-up if this list should get the
same treatment.

### The general rule, stated and implemented (2026-08-21)

Prompted by FlavorTub as the concrete case: its group headers are flavor
names (should link to that flavor's details) and its rows are tubs with no
title column at all (should get a leading edit icon instead). Generalized
to every Grid/Tile-backed list, not just FlavorTub — three pieces, all in
the shared List machinery (`_base-grid-model.js`, `grid.js`, `tile.js`),
none per-model:

1. **Row title shown → already covered** by the detail-link generalization
   above (any `col.detailEntity`-marked column becomes a link).
2. **No row title shown → leading edit-icon column, automatic.**
   `_base-grid-model.js`'s `_buildColumns()` now always runs a new
   `_ensureRowDetailAccess()` after `buildCols()` (own or inherited): if no
   column already has `detailEntity === this.metaData.primary` (the row's
   own pod), it prepends a synthetic `{ key: '_edit', type: 'edit',
   detailEntity: primary }` column. `grid.js`'s `_renderEditCell` and
   `tile.js`'s `_buildEditFieldDom` render it as a small icon button
   carrying the same `data-detail-entity`/`data-detail-id` attributes every
   other detail-link uses — no new click-handling code, same delegated
   `_list.js` listener. Models with no `metaData.primary` (Analytics-
   derived, CabinetWorkflow's hand-built columns) are untouched — nothing
   to link to a single "own pod."
   - **Bug caught before shipping**: `_applyColumnFilter()`'s `showList`
     filtering would have silently dropped the injected `_edit` column on
     any model calling `setShowList()` with an explicit allowlist —
     `flavor-tub-grid-model.js` does exactly that. Fixed by always keeping
     `type: 'edit'`/`type: 'delete'` columns through the filter regardless
     of `showList` — they're structural affordances, not data fields a
     showList is curating.
3. **Group header titles → clickable when the group is a real entity.**
   `buildGroupedRows()` now computes `detailEntity` for each group:
   `groupType` counts only if `Array.isArray(this.domain?.[groupType])` —
   i.e. it names a pod actually present in the bundle. This correctly
   links FlavorTub's/Cabinet's flavor/cabinet group headers, while leaving
   synthetic grouping keys alone (Flavors' `diet` bucket, EmptiedLog's
   `day` bucket, Tasks' `assignee` — a WP user id with no matching
   `domain.assignee` array, so it's excluded even though the id itself is
   numeric). `grid.js`/`tile.js`'s `buildGroupDom` render the label as a
   `detail-link` `<a>` instead of plain `<span>`/`<h2>` when set.

Verified against existing models, not just FlavorTub: Cabinet grid's own
header comment confirms `metaData.primary` is `'slot'` for that route (not
`'cabinet'`), so its rows correctly get slot-detail edit icons while its
`cabinet`-grouped headers correctly get cabinet-detail links — same
mechanism, two different entities, both right. ItemPivot's pivot-computed
`this.columns` gets fully overwritten by its own `buildRows()` after
construction, so the injected edit column never reaches its actually-custom
rendering (confirmed by reading `_pivot-grid-model.js` — not just assumed).

### Missed spot, then fixed: ItemPivot's own "Flavor" row label (2026-08-21)

Caught by the developer, not by the sweep above: ItemPivot's leading
"Flavor" column (the pivot row's own bucket label) wasn't clickable either,
and none of the three mechanisms above could have caught it — `PivotGridModel.buildRows()`
(`_pivot-grid-model.js`) computes `this.columns` itself from scratch every
time and overwrites whatever `_ensureRowDetailAccess()` set during
construction, and `item-pivot-grid.js`'s `_row_label` case was a hardcoded
plain-`<th>`-text special case that returned before any generic detail-link
logic ran. The row's `id` was also a prefixed string (`"flavor_123"`, not a
bare post id) — `Details.open()`'s `Number(id)` would have read that as NaN.

Fixed by extending `_pivot-grid-model.js`'s (reusable, documented-for-future-
reuse) row-bucket contract: `getRowDefs()` entries can now optionally carry
`detailEntity`/`id` alongside `key`/`label` — set when the bucket IS a real
Pods item, left unset for a synthetic bucket (a date, a category) that
isn't. `fillRow` turns `row._row_label` into `{ display, id, detailEntity }`
when set (still a bare string otherwise) — verified `_list.js`'s
`_getSortValue` already handles both shapes correctly (falls to `.display`
for the object case), so the existing alphabetical sort needed no changes.
`item-pivot-grid-model.js`'s `pushFlavor` sets `detailEntity: 'flavor', id:
f.id`; `item-pivot-grid.js`'s `_row_label` case renders a `detail-link` when
present. Net: any future PivotGridModel child (a batch or ingredient pivot,
per that file's own header comment) gets this for free by setting two
fields, not by re-deriving this fix.

### Per-model on/off toggle: detailLinks / detailLinkTypes (2026-08-21)

Went through several rounds of naming before landing here — worth recording
the final shape and why it's simpler than the first few drafts:

- **Rejected**: a separate `detailLinkKinds` axis (row-title / group-title /
  edit-icon as independently toggleable "kinds"). Collapsed away — which of
  the three actually applies is a fact about *where a linkable type shows
  up* in a given grid (its own title column, a group header, neither), not
  a separate decision a model author should have to make. "Edit" and
  "Details" are the same action/terminology, not a distinct
  write-permission-gated feature — no field-editability check needed either.
- **Final shape** — one axis, content type only:
  ```js
  this.detailLinks = false;                  // model-wide default, true unless explicitly false
  this.detailLinkTypes = ['tub', 'flavor'];  // allow-list; only matters when detailLinks is false
  ```
  `detailLinkTypes` is exclusively an allow-list (no per-type "off" entries)
  — with `detailLinks` at its default `true`, every type's already on and
  the list has nothing to add; a model only needs to name the types it
  wants back after setting `detailLinks = false`.
- **Implementation** (`_base-grid-model.js`):
  - `isDetailLinkEnabled(type)` — the one resolution function every other
    piece calls: `detailLinkTypes.includes(type)` wins if true, else falls
    back to `detailLinks !== false`.
  - `_applyDetailLinkGating()` — runs right after `buildCols()` in
    `_buildColumns()`, bakes `col.detailLinkable` onto every column with a
    resolvable type (`col.detailEntity ?? col.titleMap`). `_list.js`'s
    `_renderFieldValue` reads that flag (`!== false` default-on) instead of
    calling the resolver itself — toggle logic lives once, in the model,
    never duplicated into rendering.
  - `_ensureRowDetailAccess()` now bails before even considering the edit
    icon if `isDetailLinkEnabled(primary)` is false — matches the stated
    rule exactly: no icon if the type isn't linkable at all, and (already
    true before this) no icon if a title column already covers it.
  - `buildGroupedRows()`'s group-header linking and `_pivot-grid-model.js`'s
    row-label linking both gate through the same `isDetailLinkEnabled` call.
- **Dormant by default**: no model sets `detailLinks`/`detailLinkTypes` yet,
  so `isDetailLinkEnabled` always returns `true` and nothing about current
  behavior changed — this is plumbing for a future per-model opt-out, not
  itself a behavior change.

### Server-side permission layer: _policy.php's detail_views (2026-08-21)

Extends the existing two-layer permission model (route-level
`scoop_user_can_route()`, field-level `scoop_user_writeable_fields()`, both
in `includes/_policy.php`) with a third: can this user's role open a
Details view for a given entity type at all. Same resolution pattern as
the other two (`scoop_get_user_policy($user)` then a policy-array lookup).

- **New policy key**: `detail_views`, sibling to `routes`/`entities` in each
  role's block — a flat allow-list of entity/pod names (same vocabulary as
  `col.detailEntity`/`titleMap`/client-side `detailLinkTypes`).
- **`scoop_user_can_view_details(\WP_User $user, string $entity): bool`** —
  server-side gate. Distinguishes "no `detail_views` key at all" from "key
  present" (even an explicit `[]`), unlike `scoop_user_writeable_fields`'s
  always-`?? []`: absence falls to `SCOOP_DETAIL_VIEWS_DEFAULT_ALLOW`.
- **`scoop_client_detail_viewable_entities(\WP_User $user): ?array`** —
  what actually reaches the client, via a new `enqueue.php` key,
  `SCOOP.detailViewableEntities`: `null` when unrestricted, an array when
  restricted. `null` rather than enumerating every known entity name, so
  neither side has to keep a duplicate canonical entity list in sync.
- **Client wiring**: `isDetailLinkEnabled()` (`_base-grid-model.js`) checks
  `SCOOP.detailViewableEntities` first — a hard deny if the array exists
  and doesn't include the type, unoverridable by a model's own
  `detailLinks`/`detailLinkTypes` (same relationship as route-vs-field
  permissions elsewhere: server denies are final, model-level settings can
  only narrow within whatever the server already allows).
- **Temporary default-allow posture**: `SCOOP_DETAIL_VIEWS_DEFAULT_ALLOW`
  (`_policy.php`) is `true` while the GUI flow is still being built —
  every role currently has no `detail_views` key, so this is presently a
  no-op everywhere, letting the linking behavior be exercised freely
  without first populating policy data for all 8 roles. **Explicitly
  deferred, not forgotten**: flip that constant to `false` (switching the
  default to deny) and populate each role's `detail_views` once the GUI
  flow settles — developer will ask for role-by-role proposals then, not
  guessed now.

### Still open

- Exact field list/order to show for a tub in the modal (candidates from
  the spec: `state`, `use`, `amount`, `slot`, `flavor`, `opened_on`,
  `emptied_at`, `location`, `batch`, `closeout` — confirm which to include).
- Whether generalizing the detail-link renderer needs an explicit opt-in
  signal on a column def beyond reusing `detailEntity`/`titleMap`
  detection, given today it's a hardcoded single-case check.
- How per-field edit permissions actually get wired into `Details.js` once
  that phase starts (not this pass).
- Flip `SCOOP_DETAIL_VIEWS_DEFAULT_ALLOW` to `false` and populate real
  `detail_views` per role once the GUI flow is settled (see above) —
  developer will ask for proposals when ready, don't guess ahead of that.

## "Split for another use" — implemented (2026-08-21)

The write-path question from way back ("Should `FlavorTub`'s route move
from `update`-only to also support `create`...") is resolved: a new
`TubSplit` route, `mode: 'create'`, `pod_name: 'tub'`.

### Amount is a GUI choice, not always a half — two outcomes

Revised after the first pass (which always halved): the GUI now collects
**both** `use` and `amount`. The server compares the requested amount to
the origin's current amount and picks one of two outcomes:

- **`requested >= origin's amount`** — nothing meaningful would be left
  over. No new tub is created at all — the **origin itself** is converted:
  `use` → the picked use, `state` → `'Emptied'`. `amount` is left
  untouched. This is a plain update through the normal `pods_api()` path
  (not `scoop_create_pod_item()`'s create flow), so `scoop_enforce_tub_rules`
  (the *update*-path hook, `tub-state.php`) auto-stamps `emptied_at` the
  same way it already does for every other transition into `'Emptied'` —
  nothing tub-split-specific needed for that half. `>=`, not just `>`: an
  exact match would leave a zero-`amount` origin behind if forced through
  the split branch, which is a worse outcome than just relabeling the one
  tub that already exists.
- **`requested < origin's amount`** — a real split, same shape as the
  original design: new tub for the requested amount (`title`:
  `"{origin title}/{use title}"`, `flavor`/`location`/`batch`/`closeout`/
  `opened_on` copied from origin, `state: 'Emptied'`, `emptied_at`: now),
  origin's own `amount` reduced by that same requested amount (not halved).

`created_on` is still never attempted either way — confirmed (see below)
that it can't be set on create regardless. **No `split_tubs` link** either
way — explicitly dropped as a "nice-to-have" per developer request; even
one bidirectional link was judged not worth the complexity for what it'd
buy right now.

### Why the write lives where it does

`pods_api_pre_save_pod_item_tub` (the hook `scoop_enforce_tub_rules` in
`includes/hooks/tub-state.php` is registered on) only ever sees the
**already-filtered** field set — a non-Pods-field value like
`origin_tub_id` never reaches it, filtered out before Pods' own hooks fire.
So the whole thing had to move into plain PHP in the request-handling path
itself, not a hook. The real precedent already existed:
`scoop_create_pod_item()` (`includes/_write_fields.php`) already
special-cases `post_title` for `batch`/`task` by reading values straight
off the raw, unfiltered `$data` — the split logic is a new
`if ($pod_name === 'tub')` branch there, doing the same thing at larger
scale (load the origin tub, compare the requested amount, then either
convert it in place or copy several fields onto a new row).

Confirmed by reading `tub-state.php` directly (not assumed):
`scoop_enforce_tub_rules` explicitly bails (`if ($is_new_item ...) return`)
for new items — it only enforces opened_on/emptied_at/state rules on
*edits*. So none of its reversion logic fights the values the split branch
sets on create, and the convert-in-place branch (a real edit) gets the
*benefit* of that same hook's existing `state → 'Emptied'` auto-stamp
logic for free. Separately, `scoop_auto_set_tub_created_on` unconditionally
forces `created_on`/`changed_on` to now on every new tub — confirming
`created_on` genuinely can't be copied, exactly as anticipated.

### `origin_tub_id`

A plain sibling key in the write payload
(`{ cells: { 0: { use, amount, origin_tub_id } } }`), never a Pods field,
never persisted — read once, directly off raw `$data`, same pattern as
`CabinetWorkflowTile`'s existing `source: 'workflow'` hint (though that one
only feeds an audit-log label; this one drives real logic). `amount` itself
*is* a real allowed tub field already (same grant as editing it anywhere
else), so it's read off the already-filtered `$clean`, not raw `$data`.

### Write ordering (split branch only — no transactions, MyISAM)

New tub is created **first**; the origin's `amount` is reduced **second**.
If the second save fails, it's logged but not surfaced as a request
failure — the split itself already succeeded (a real new tub exists), so
the client shouldn't be told to retry (which would create a duplicate
split tub). The origin's stale amount becomes a visible, reconcilable
inconsistency instead of a silent one. Ordered this way specifically so a
mid-flight failure leaves *extra* data (recoverable) rather than *lost*
data (an already-reduced origin with nothing to show for the missing
portion). The convert-in-place branch has no such ordering question — it's
a single write.

### Permissions

`TubSplit`'s `allowed_fields_cb` reuses `scoop_tubs_allowed_fields` — a
role that can't already write `tub.use`/`tub.amount` can't split a tub
either, no new permission concept invented. Route-level access
(`_policy.php`) was granted to the same five roles that already have
`FlavorTub` POST access (`administrator`, `editor`, `kitchen_manager`,
`shift_lead`, `lead`) — a mechanical mirror of an existing grant, not a
new judgment call.

### Client: inline use + amount picker

`assets/ui/tub-detail-view.js`'s `.actions` placeholder button became a
real `<select>` (every `use`, sorted by `order`, same shape as
`BaseGridModel.getOptions('use')`) + a number `<input>` (defaults to the
tub's own current `amount` — left as-is, that's a `>=` request, i.e.
"convert the whole tub"; lowered, that's a real split) + a submit button,
disabled up front if the tub has no `amount` left at all (mirrors the
server's own `tub_split_no_amount` check). On submit: POSTs to `TubSplit`,
then `api.refreshPageDomain({force:true})` + `Details.refresh()` —
re-renders the same still-open panel against the refreshed domain (showing
whatever changed — origin's reduced amount, or its new use/state) rather
than closing it out from under the user. The client doesn't need to know
which of the two server outcomes happened; both leave the origin tub in a
correct state to redisplay. Success/failure feedback via the existing
`Toast` component (`assets/ui/toast.js`), same as `ConfirmSwapModal`'s
write flows.

### Extracted into a shared control, second consumer: ConfirmSwapModal

The split control now lives in `assets/ui/_tub-split-control.js`
(`buildSplitTubControl(item, api, { onSplit })`) — `tub-detail-view.js` was
the first consumer; `ConfirmSwapModal` (CabinetWorkflow) is the second,
per developer request ("it is also acting on tubs"). Genuinely shared, not
duplicated: same DOM builder, same write, same `Toast` feedback — only
`onSplit` (what "refresh myself" means) differs per host.

- **Which tub**: `ConfirmSwapModal` juggles two tubs at once — the
  incoming one it's proposing (`plan.tub`) and the one currently in the
  slot being swapped out (`plan.outgoingTub`). Confirmed with the
  developer: the control acts on **`plan.outgoingTub`**, matching the
  original front-of-house/event scenario this whole feature started from.
- **Placement/lifecycle**: a `this.SPLIT_HOST` div, appended last in
  `_buildDom()` (bottom of the modal, per the request), but *rebuilt* each
  `_render()` — same reasoning as the flavor lines already being
  repositioned per render, not built once: the target tub changes with
  whatever row/slot is currently open. Empty (nothing appended) when
  there's no outgoing tub at all, e.g. filling a previously-empty slot.
- **`onSplit`**: reopens the dialog with a fresh row
  (`this.getRow?.(row.slotId) ?? row`, then `this.open(freshRow, this.
  _selectedFlavorId)`) — the exact same reopen-after-a-detour pattern
  `_pickScheduled()` already used for its own "schedule a flavor, then
  come back" flow. The domain is already refreshed by the time this runs
  (`_tub-split-control.js` does that before calling `onSplit`), so
  `getRow` picks up whatever changed on the outgoing tub.
- Reuses the same `.modal` base CSS (`body > .modal * { display:flex; ...
  }`) `ConfirmSwapModal`'s own `.modal.confirm_swap` already gets — no new
  CSS needed, lays out the same way it does inside the Details modal.

## "Mark abandoned flavor's remaining tubs as lost" — implemented (2026-08-22)

Separate problem, same modal: staff often can't physically find tubs of
the currently active flavor and just move on to the next one instead of
continuing to hunt — leaving the old flavor's tubs sitting in the system
forever as phantom "available" stock. `ConfirmSwapModal._confirm()` now
detects this and offers to clean it up.

- **Trigger**: `row.flavorId` (the slot's actual current flavor) differs
  from `this._selectedFlavorId` (what's about to be confirmed) — i.e. the
  plan actually changed, not just a re-confirm of the same flavor.
- **What counts as "remaining"**: a new `remainingTubs(flavorId,
  locationId)` on `CabinetWorkflowGridModel` — the tub-list twin of the
  already-existing `remainingSummary` (same front-of-house-use,
  still-in-the-pipeline eligibility, same `DISPLAY_EXCLUDED_STATES`
  exclusion of Opened/Emptied/!Lost). Opened is already excluded there, so
  this can never include the slot's own outgoing tub — no separate
  exclusion needed. Scoped to the slot's own location — the whole premise
  is "staff searched *this* freezer and couldn't find them."
- **Confirmation**: a plain `window.confirm()` — deliberately, not a
  custom UI. This codebase moved away from *sequential* native alerts
  before (see `_confirm()`'s own comment on the old "alert cascade"), but
  that was about a chain of blocking dialogs, not a single yes/no gate.
  Wording was written to carry the full context on its own (no surrounding
  UI to lean on): what's about to happen, why it's probably true (tubs
  that can't be found), and why it matters (they'd otherwise count as
  available stock indefinitely).
- **The write**: on confirm, `{ state: '!Lost' }` for every stale tub rides
  the *same* `FlavorTub` POST as the swap itself — one request, one
  `inventory_change` entry, not a second round trip. Verified against
  `tub-state.php`'s `scoop_enforce_tub_rules`: it only blocks an `Opened`
  tub from jumping straight to `!Lost` (must go through `Emptied` first) —
  since `remainingTubs` already excludes `Opened`, every tub this reaches
  is in a state (Hardening/Freezing/Tempering/`__override__`) the hook
  lets go straight to `!Lost` with no fight.
- Surfaced in the existing post-confirm `Toast` (`swapNotes`), same list
  the swap's own outcome notes already ride.

## Multi-click "N-tub swap" gesture — implemented (2026-08-22)

Third CabinetWorkflow feature this session, same slot-click surface as the
two above but the opposite scenario: not a flavor change, a **same-flavor
restock** — a slot sold through several tubs of one flavor over a busy day,
and clicking through "Confirm Swap" once per tub is the thing being
avoided. 2 or 3 rapid clicks on a slot (2 for a 2-tub swap, 3 for a 3-tub
swap — capped at 3, nothing past that is detected) settles several
sellouts in one write instead.

### The mechanic

An "N-tub swap" is **not** N independent swaps. The tub currently in the
slot empties either way, same as any normal swap — that's the baseline,
not part of N. N is how many tubs get drawn from the *promotable pool*
(the pipeline — Hardening/Freezing/Tempering, same pool a single swap
already draws from): the first `N-1`, in the same order `pickNextTub`
would have picked them one at a time, go straight to `Emptied` (never
individually opened); the `N`th gets promoted to `Opened` and linked to
the slot, same outcome as a normal swap's last step.

- **`CabinetWorkflowGridModel.pickNextTubs(flavorId, locationId, count,
  preferWhole)`** (new) — up to `count` tubs, same ranking `pickNextTub`'s
  rule (b) already used (Tempering rank, location, whole/partial
  preference, age). That ranking was pulled out into a shared
  `_promotableRanked()` so both methods draw from the identical order,
  rather than a second hand-copied sort silently drifting from the first
  over time.
- Every tub touched — including the outgoing one — is treated as fully
  emptied. No "not empty" checkbox consideration in this flow (developer:
  "assume that they are all emptied") — the whole premise is tubs that
  actually sold through. "Prefer whole tubs" only matters when there's a
  real choice among candidates; in the one case this session flagged where
  literally everything remaining gets drawn (see "exactly available"
  below), the developer confirmed it has no effect either way — nothing
  special was built for that, it's just naturally inert there.

### Three outcomes, gated on `count` vs. the promotable pool's size

`ConfirmSwapModal.openBulk(row, count)` — new entry point, sibling to the
normal `open(row)`:

- **`count > available`**: `alert()` saying how many are actually left,
  then the normal single-tub modal (`open(row)`) — as if the extra clicks
  never happened.
- **`count === available`**: this would draw down the *entire* remaining
  pool. Deliberately **not** the same shortcut as the case below —
  developer: show the normal modal "so the user can see what the
  replacement will be and potentially change it, or cancel the entire
  operation." `alert()` first (draining the last N), then
  `open(row, null, { bulkCount: count })` — the real, visible modal,
  primed so `_confirm()` (see below) still does the N-tub write if the
  user clicks through, with `CONFIRM_BTN`'s label changed to `Confirm
  N-tub swap` so it's visually obvious this isn't an ordinary 1-tub
  confirm. Still fully escapable — "Change Plan"/a flavor-line click
  drops the pending bulk count automatically (see below), "leave slot
  empty" and the close button work as they always did.
- **`count < available`**: a `window.confirm()` restating the count
  ("Swap N tubs of {flavor}? ..."). **No** → normal single-tub modal
  (never a silent no-op). **Yes** → the write happens with the modal
  **never shown at all** — `open(row, null, { silent: true, bulkCount:
  count })` followed immediately by `_confirm()`. `silent` is a new
  `open()` option that skips `classList.add('show')` but still runs the
  normal `_render()` (populates `this._plan`/`this._row`, harmlessly, into
  a hidden root) so `_confirm()` has what it needs.

### Why a native `confirm()`/`alert()` here too

Same reasoning as the "mark lost" feature above — single yes/no or
informational gates, not the *sequential* alert cascade this codebase
moved away from. Wording restates the click count back explicitly (per
developer request from planning: "does that confirmation need to restate
the click count back prominently... so a misfire is obvious"), so an
accidental extra tap is easy to catch and back out of.

### `_pendingBulkCount` — how the bulk intent survives into `_confirm()`

A single piece of new state on `ConfirmSwapModal`, set only through
`open()`'s new `bulkCount` option. Every *normal* `open()` call (no
`bulkCount` passed) resets it to `0` by default — so switching flavors
mid-flow (Change Plan → `FlavorPickerModal`, or clicking one of the
Current/Immediate/Next flavor lines) automatically drops a stale bulk
intent with no extra code, since those paths all eventually call `open()`
again without a bulk count. `_confirm()` reads and clears it at the top
(`bulkCount > 1 && this._selectedFlavorId === row.flavorId` — the flavor
match guard is what makes this mutually exclusive with the "mark lost"
flow's flavor-*changed* detection by construction), routing to a new
`_confirmBulk(row, count)` — structurally parallel to `_confirm()`'s own
write/optimistic-repaint/Toast/refresh tail, just building `tubCells` from
`pickNextTubs()` instead of a single `plan.tub`.

### Click detection — debounced, not click-then-upgrade

`CabinetWorkflowTile._registerSwapClick(slotId)` buffers `.add-next`
clicks per slot for `SWAP_CLICK_WINDOW_MS` (350ms) before acting on
however many landed, rather than firing on the first click and
"upgrading" if more arrive. Trade-off made deliberately: every click
(including the ordinary single-click case) gets a uniform ~350ms delay
before the modal/write happens, in exchange for the modal never visibly
flickering open-then-closed mid-gesture. Capped at
`SWAP_CLICK_MAX = 3` — a 4th+ rapid click still only ever reads as 3.
Only applies when `row.openTub` exists (there's no "N tubs" concept
without an existing open tub to restock) — the "no open tub, offer the
picker" path is untouched, still fires on the very first click.

### Not built (this feature)

- Not tested end-to-end (same caveat as the rest of this session's PHP —
  no linter available in this environment; this piece is pure client-side
  JS, reviewed by hand against the real model/modal code, not executed in
  a browser yet).
- No handling for the click landing on a slot that changes discrepancy/
  impossible state mid-buffer-window — `_handleSwapClicks` re-fetches the
  row and bails if `openTub` is gone, but doesn't re-check `discrepancy`/
  `impossible` specifically; edge case, not flagged as a concern by the
  developer.

### Not built

- No minimum split-size floor (still an open item from the original design
  notes — a tub can be split down to a very small `amount` repeatedly).
- No lineage between split tubs at all now (`split_tubs` dropped) — if this
  is revisited later, `_pivot-grid-model.js`/detail-view precedent from
  this session is the place to look at how a self-referential link would
  surface in the UI.
- Not tested end-to-end yet (no PHP linting available in this environment —
  reviewed by hand against real hook/dispatch code, not executed).

## End-to-end verification + a real regression found and fixed (2026-08-27)

Ran a full Playwright smoke test against the local site (`ops.swanky.local`)
for all three CabinetWorkflow features above, plus the split control's
Details-modal entry point. All confirmed working against real writes:
split (both the true-split and convert-in-place branches — verified via
the actual resulting tub records, not just UI state), mark-lost (confirm
wording, tub states, toast), and the multi-click gesture's `count <
available` (silent) and `count === available` (visible, bulk-labeled
button) branches. No new console errors — the one error present throughout
is a pre-existing, unrelated `DOCS_timing` reference error from an
embedded Google Docs iframe.

One real regression found and fixed during that pass:
`_tub-split-control.js`'s use-picker was a hand-rolled `<select>`, which
(being a plain `<select>`) always starts on its first option regardless of
data — so it silently defaulted to "Front-of-house" even when a tub's
actual current `use` was something else entirely. Caught by triple-clicking
a tub whose real `use` was "Events" and noticing the picker still showed
"Front-of-house". Fixed by switching to `FindIt` (`assets/ui/find-it.js`)
— the same type-to-complete widget every other writeable relationship
field in this app already uses, which correctly reflects whatever
`id`/`display` it's constructed with. Verified fixed in both hosts (the
Details modal and `ConfirmSwapModal`) against a real tub with a non-default
`use`. Left blank (not defaulted to Front-of-house) when a tub's `use` is
genuinely `0`/unset — showing what's actually there, not a guessed
business-logic default.
