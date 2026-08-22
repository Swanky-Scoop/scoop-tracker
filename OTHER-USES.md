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
