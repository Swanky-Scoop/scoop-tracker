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

## Stubbed for later (no-op now)

- `add special` — click handler attached, does nothing (or opens an empty
  placeholder modal shell) until the follow-up spec for its prompts
  arrives.
- `add flavor` — same; on an empty slot, clicking neither sets
  `current_flavor` nor creates a tub yet.

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
