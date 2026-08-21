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
  link) for tracing where a split-off tub came from. This supersedes
  `alt_uses` for the lineage question — `alt_uses` may still be redundant/
  removable once `split_tubs` lands, since a tub's uses can be derived by
  walking its `split_tubs` links and reading each linked tub's `use`.
  Developer is adding this field; still open whether it's single- or
  multi-value, one-directional (child→parent) or bidirectional (with a
  `sister_id` pairing per [[schema-sync-sister-id-gap]] caveats if
  bidirectional), and whether it's schema-file-authored like `alt_uses` or
  added via Pods admin + Schema Sync.

## Open questions (not yet decided)

- Should `FlavorTub`'s route move from `update`-only to also support
  `create` (mirroring how `batch` creates tubs today), or should splits go
  through a distinct write path?
- UI: is this a new button/modal inside CabinetWorkflow's tile
  (`cabinet-workflow-tile.js`), or a separate flow?
- Should there be a floor on split size (e.g. can't split off less than some
  minimum amount), or any validation that split amounts sum correctly
  against the original?
