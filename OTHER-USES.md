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

### Still open

- Exact field list/order to show for a tub in the modal (candidates from
  the spec: `state`, `use`, `amount`, `slot`, `flavor`, `opened_on`,
  `emptied_at`, `location`, `batch`, `closeout` — confirm which to include).
- Whether generalizing the detail-link renderer needs an explicit opt-in
  signal on a column def beyond reusing `detailEntity`/`titleMap`
  detection, given today it's a hardcoded single-case check.
- How per-field edit permissions actually get wired into `Details.js` once
  that phase starts (not this pass).
