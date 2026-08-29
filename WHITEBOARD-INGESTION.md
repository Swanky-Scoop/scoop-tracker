# Tempering Freezer Whiteboard → Inventory Ingestion

A living document tracking the plan to turn the tempering freezer whiteboards into structured inventory data. Starts as a discussion capture, will evolve into a concrete design once a direction is confirmed.

**Current phase:** paused 2026-08-29. The end-of-shift report (`shift_report` pod + form, see below) was built and works on local; promotion to other environments is incomplete - only OPS has had a successful schema Apply so far, and `supply`'s 83 catalog rows only exist on local (see "Supply items populated on local"). Not being actively worked right now; see `includes/pods-schema/_schema.php`'s docstring for the specific Schema Sync gap this leaves on any brand-new environment.

---

## The source of truth: two whiteboards

Two dry-erase boards mounted on the tempering freezer ("Dairy" and "GF + Vegan") are the real-world source of truth for what's in the freezer. Shift leads mark them up through the shift.

**Legend (shared by both boards):**

| Mark | Meaning |
|---|---|
| ★ | new flavor |
| `[ ]` | pulled back |
| underline | last one in freezer |
| `P` | partial box |

**Movement notation:**
- A struck-through vertical slash next to a flavor = one tub of that flavor moved out of the freezer into the front-of-house service cabinet.
- A struck-through flavor *name* = no tubs of that flavor remain in the freezer.
- Both the old flavor's name **and** the next flavor's slash struck through together = the flavor was swapped out entirely — old flavor gone, first tub of the new flavor now in the cabinet.

**Assumption baked into the process:** changes in the tempering freezer are assumed (not verified) to track 1:1 with changes in the front-of-house service cabinets. The whiteboard is the de facto input for what should become `inventory_change`/`DateActivity` records in the app, but as of this writing it isn't wired to the app at all.

**Known reliability gap:** a struck-through flavor name just means the shift lead didn't spot a tub quickly — not a guaranteed-empty freezer. This is an accepted weak link in the current manual process, not something new tooling introduces. Any automation built on top of whiteboard reads must not make this weaker by auto-committing an uncertain read as fact.

## Current process

At end of shift, a shift lead photographs both boards as part of an end-of-shift report captured via a Google Form. The photo and report land in a Google Drive folder / Sheet. Nothing currently reads that Sheet/Drive data back into the app — it's a disconnected third silo alongside WordPress and Pods.

## Options discussed (2026-08-11)

1. **Keep Forms/Sheets, improve readability.** Forms itself is fine as a mobile capture UI — free, familiar, low friction for shift leads. The actual problem isn't the form, it's that submissions dead-end in a Sheet instead of becoming structured data anywhere. Investing in prettier Sheets output doesn't fix that.

2. **Ad hoc: send the photo to an LLM chat session each night for manual interpretation.** Cost is trivial (one vision read per image). Reliability for *direct, unreviewed writes* is not good enough: stacking a model's read of handwriting/strikethroughs/arrows on top of an already-uncertain signal (see reliability gap above) compounds error, and the failure mode — silently wrong inventory — is the same class of problem this codebase already refuses to allow for ingredient pricing data (never auto-correct, always surface for human review). It's also not real automation: it requires a human to remember to run it, run it, and sanity-check it every night.

3. **Hybrid pipeline (leading direction).** Capture (keep Forms, or a small in-app photo upload) → server-side call to a vision-capable model that parses the board into structured diff candidates (flavor: moved-to-cabinet / depleted / swapped) → a human confirm/edit step, ideally as a lightweight screen in the existing app rather than a Sheet → commit via the existing REST write path into `inventory_change`/`DateActivity`, reusing the grid/bundle/hooks architecture already in place. New pieces needed are small relative to the rest of the app: one parsing call, one review UI, one ingestion endpoint — everything downstream (routes, policy, cache invalidation) already exists.

## End-of-shift report fields (2026-08-11)

The current Google Form asks 13 questions. They split into two groups that don't need the same treatment:

- **Inventory-relevant, worth structuring:** Photos of tempering cabinets, Flavors changed out, Supplies running low, Cash discrepancies, Change/small bills low, # of cake orders.
- **Shift-log/HR, fine as plain text:** Name, Shift, Final tasks, positive-feedback count, Customer issues, Notes for tomorrow, Scooper staffing, Staff issues.

## The whiteboard stays — low-tech wins the in-rush moment

Discussed with the shop's decision-maker (Gus's wife): a dry-erase marker beats any tablet UI at the actual moment of a tub swap, mid-rush — no unlocking a device, no menu-hunting, no cold-wet-gloves problem, visible to the whole team at a glance. That's conceded, not up for debate. **Decision: the whiteboard is not being replaced as the freezer-side capture surface.**

The real weak link was never the marker — it's the *transcription* step (someone reading a marked-up board, possibly hours later from memory, and free-typing a sentence into Google Forms). That's the part worth fixing, and it doesn't require touching the whiteboard at all.

## CabinetWorkflow is a different tool than the whiteboard, not a competing one

`CabinetWorkflow` (see `change-tub.md`) already exists and covers the *front-of-house cabinet* side — which slot has which tub, conflict-free tub selection, and reconciliation (`Confirm Cabinet`, `impossible`/`discrepancy` flags) that catches drift between recorded and physical state. This is a different problem than the whiteboard's (which covers back-of-house freezer staging) — keeping the whiteboard doesn't make CabinetWorkflow superfluous, and CabinetWorkflow's reconciliation logic is actually a hedge against the whiteboard's own weak-link problem, not a redundant system.

**Interaction cost claim (not yet measured):** ~90% of CabinetWorkflow use is the "add next" flow (pick slot, confirm advance — 2 clicks), ~8% is confirming a planned trade-out (2 clicks), and the remaining ~2% needs a 3-click resolution or gets flagged for later. If true, this may be fast enough to challenge the "marker always wins" assumption even in a rush — but it's a claim, not a measurement yet. Before pitching it: **time it for real**, head-to-head against the marker, same as the transcription-step pilot below. Screen-wake time, tablet mount location, and tile-finding speed aren't captured by a click count.

## Customer-facing flavor board photo — an audit signal, not a whiteboard replacement

A photo of the customer-facing flavor board (always shows exactly what's currently being served) is a cheap, high-quality **verification signal for the cabinet side** — diff it against what `slot.current_flavor` claims is true. It's a better ground-truth check than eyeballing the cabinet, since staff already have their own reason to keep that board accurate. It does **not** cover the tempering freezer (no staging info, no partial-box flags, no "what's next") — it complements CabinetWorkflow, it doesn't compete with the whiteboard.

Because it's a clean, printed/written target rather than a marked-up whiteboard, it's also a much better candidate for vision-parsing automation later than the freezer whiteboard ever was — worth keeping in mind if OCR/LLM-assisted diffing comes back into scope.

**Granularity tradeoff — confirmed against real data, not assumed:** a once-daily photo only captures the flavor in a slot at the moment it's taken, missing same-day swaps. Checked against actual tub sell-through: most flavors sell out slower than one tub per day, but **~4 flavors reliably sell multiple tubs in a single summer day** — daily-snapshot granularity is unsafe for those specific flavors and needs to stay on file as a known, named exception, not a rounding error.

## Direction so far

- **Whiteboard stays** as the freezer-side (back-of-house) capture surface — settled, not revisited.
- **CabinetWorkflow** is the pilot for the cabinet-side (front-of-house): needs real timing data before being pitched as faster than the marker, and is the natural tool for the "structured transcription" step instead of a from-scratch form — someone doing the calmer end-of-shift pass uses `FlavorPickerModal`/`ConfirmSwapModal` directly rather than typing a free-text sentence.
- **Daily customer-board photo** is the audit layer proving CabinetWorkflow's data matches reality — both for the pilot's evidence and ongoing drift-catching.
- **~4 high-velocity flavors** are a known, named exception to "daily snapshot is enough" and need to be handled explicitly wherever snapshot-based tooling gets built, not silently dropped.
- Google Form/Sheets end-of-shift report: still the plan is to eventually rebuild it in-app (Closeout-shaped: structured fields for inventory-relevant items, plain text for the rest), but this is now sequenced behind the CabinetWorkflow pilot rather than a parallel effort.

## End-of-shift report — built (2026-08-11), pending Pods schema + testing

Chose to build the in-app report rebuild first (ahead of the other brainstormed options — see the prior section) once the design questions below were settled:

- **Flavors changed out**: manual picker (old flavor → new flavor, repeatable rows), not auto-derived from CabinetWorkflow and not free text. Plain `<select>` dropdowns, not a reuse of `FlavorPickerModal` — that modal's photo-tile UI is built for CabinetWorkflow's slot-eligibility-filtered physical workflow, not "pick any flavor at a desk."
- **UI shape**: a new standalone form view (`assets/ui/shift-report-form.js`), not a List/Tile/Grid subclass — List's cell/dirty-tracking/autosave machinery has no vocabulary for a checklist, a repeatable picker, or a file upload, and forcing those in would mean extending shared grid infrastructure for one one-off form.
- **Photos**: upload straight to WP core's `wp/v2/media` REST endpoint (the existing `SCOOP` nonce authorizes core routes the same as this plugin's own) — no custom upload endpoint needed. Sortability comes free via Pods' File/Image field `post_parent` linking; no folders plugin needed unless real volume shows it's not enough.
- **Supplies field**: originally spec'd as free text, changed after reviewing ~800 historical "Supplies: I noticed we are running out of" Google Form responses — deduped into a ~65-item checklist (Pods multi-select Pick field), grouped by category (cups, lids, gloves, paper goods, bags, cleaning, spoons/straws, dairy, cones, cookies, toppings, beverages, misc), plus one free-text "Other" field for the long tail. The full checklist lives in `assets/models/shift-report-grid-model.js`'s `SUPPLIES_CHECKLIST` export — that's the source of truth if it needs editing, not this doc.

**Built**: `_specs.php` (entity specs for `shift_report`/`shift_flavor_change`, bundle spec), `_write_fields.php` (allowed-field callbacks), `_policy.php` (role grants — administrator/kitchen_manager/shift_lead), `rest.php`'s `scoop_handle_shift_report_create()` (custom orchestrating endpoint — one shift_report post + N shift_flavor_change posts per submission, since the generic per-type create dispatch only handles one row of one pod), `_routes.php` (route registration), `assets/models/shift-report-grid-model.js`, `assets/ui/shift-report-form.js`, `scoop-api.js` wiring, and CSS.

## Schema revised after Pods admin was actually built (2026-08-11)

The initial spec (above) was a guess before any Pods fields existed. Once `shift_report` was actually built in Pods admin, several things turned out simpler or different, and the code was rewritten to match:

- **`flavors_changed`** is a direct multi-relationship to `flavor` — not an old→new pair. This eliminated the need for a second `shift_flavor_change` content type entirely; `shift_report` needs no child records for flavor swaps at all.
- **`supplies_low`** is a multi-relationship to the new `supply` content type (see the earlier section on that pivot) — confirmed multi-select.
- **`cake_orders`** is a multi-relationship to a new `cake_order` content type (fields: `order_name` plain text, `cake_pie_flavor` custom Pick list — option values not yet known, `pickup_date` date, `details` paragraph text) — but the staffer always *creates new* `cake_order` records as part of submitting the shift report, never picks from existing ones. `cake_order` carries no back-reference to `shift_report`; the link is one-directional via `shift_report.cake_orders`. This reintroduced the "create child records in one submission" problem the old `shift_flavor_change` design solved, just reshaped: `cake_order` posts are created FIRST (no dependency on the parent existing), then `shift_report` is created with their ids already embedded in its own `cake_orders` field — no follow-up UPDATE ever needed, and a failed cake_order row just doesn't make it into the list rather than blocking the report.
- **`change_low`** is a Pods "Simple (custom defined list)", multi-select: `Pennies, Nickles, Dimes, Quarters, Dollars, Fives, Tens, Twenties`.
- **`staffing_level`** is a single-select custom Pick list: `Too many, Just right, Too few`.
- **`positive_feedback`** is Paragraph Text (free text about the feedback given), not a yes/no checkbox as originally guessed.
- **`tempering_cabinet_photo`** is required, single-image (not multi as originally guessed).
- No `shift` (AM/PM) field, no free-text "other supplies" field, and no `staff_issues` field exist — the final field list is exactly 12 fields: `tempering_cabinet_photo`, `flavors_changed`, `supplies_low`, `cash_discrepancy`, `change_low`, `cake_orders`, `final_tasks`, `positive_feedback`, `customer_issues`, `notes_for_tomorrow`, `staffing_level`, `location`.

**Built** (rewritten to match): `_specs.php` (real field names/types, `cake_order` entity replacing `shift_flavor_change`), `_write_fields.php`, `_policy.php` (all three roles), `rest.php`'s `scoop_handle_shift_report_create()` (cake-orders-first sequencing), `shift-report-grid-model.js` (real option lists), `shift-report-form.js` (multi-select pickers for flavors/supplies, repeatable cake-order creation rows, real checklists), CSS.

**Known gaps**:
- `cake_pie_flavor`'s actual option list isn't known yet — stubbed as free text in the form; should become a proper `<select>` once confirmed.
- `supply`'s own fields (category/price/purchase-date/quantity) aren't confirmed yet, so `supplies_low`'s picker is a flat alphabetical list, not grouped by category.
- Not yet validated end-to-end beyond the PageStatus fix below.

**Fixed**: PageStatus never cleared — `PageStatus.register()` marks every grid host `'unknown'` at mount time regardless of view type, and only `PageStatus.setState(id, 'fresh')` clears it, which List calls itself (`_reportFresh`) but this standalone view didn't inherit. Worse than cosmetic: PageStatus's page-wide indicator shows the *worst* state across every registered grid, so the stuck host held the entire page's status at "unknown," not just its own. `ShiftReportForm` now takes `pageStatusId` and calls `setState(..., 'fresh')` at the end of `setDomain()`.

## Flavors-changed checklist, camera capture (2026-08-11)

Two usability refinements after the schema rewrite:

- **`flavors_changed` filtered/organized by cabinet, checkboxes instead of a multi-select.** Rather than picking from the entire flavor catalog, the checklist now sources from today's `slot.current_flavor` (via a new `currentFlavorsByCabinet(locationId)` model method), grouped by cabinet — only flavors actually in a cabinet slot right now are worth asking "is this one of today's new arrivals?" about. Added `cabinet`/`slot` to `ShiftReport`'s bundle `needs`. Rebuilds when the location dropdown changes (a different location has different cabinets), preserving already-checked flavors across the rebuild.
- **Camera capture on the tempering cabinet photo** — `capture="environment"` on the file input biases mobile/tablet browsers toward opening the rear camera directly, consistent with this photo needing to show what's there *right now*, not an old one from camera roll. Desktop ignores the attribute.

## Supply items populated on local (2026-08-11)

All 83 items from the deduped checklist were created as `supply` posts on local, grouped via the pod's `group` field (13 values, matching the categories above). Created via `pods('supply')->add()` (local PHP CLI, `wp-load.php` bootstrap — see project memory on the local PHP CLI how-to), not raw SQL, to go through Pods' own storage/relationship handling correctly (the pod uses table storage, not meta).

**Hit the same class of bug this project already fixed once for tubs**: `pods()->add()` without an explicit `post_status` defaults new posts to `draft`, and Pods relationship fields default to `pick_post_status=publish` — so the 83 new supply posts existed correctly (right titles, right `group` values) but were invisible to any relationship pointing at them, including `ShiftReport.supplies_low`'s own picker. Fixed with the same bulk-SQL-update + `wp_cache_flush()` + `scoop_cache_bust()` pattern `includes/republish-tubs-ui.php` already established for tubs — no new admin tool built, since this was a one-time creation-time oversight, not a recurring structural cause the way tub's old "demote to draft on Emptied" hook was.

**Not yet done**: same population still needs to happen on TEST/OPS whenever this feature is promoted there (per the data-repair policy) — these 83 rows only exist on local right now.

## supplies_low also switched to grouped checkboxes (2026-08-11)

Same treatment as flavors_changed: replaced the `<select multiple>` with a checkbox checklist grouped by the `supply` pod's own `group` field, via a new `supplyOptionsByGroup()` model method. Ordered per a fixed `SUPPLY_GROUP_ORDER` (matching the original design pass — front-of-house-frequent categories first) rather than alphabetically. Unlike flavors_changed, this doesn't need to react to a location change (supply isn't location-specific), so it only renders once, from `setDomain()`.

**Found and fixed a real gap while wiring this up**: `supply` had no entity spec in `_specs.php` at all — it was only referenced via `titleMap`, so `domain.supply` was never actually being populated correctly even for the old `<select multiple>`. Added a minimal spec (just the `group` field; not writeable from this app — supply items are managed in Pods admin).

## Form made fully schema-driven (2026-08-11)

Three times in a row, a field added in Pods admin didn't show up in the form because the field list was hardcoded client-side. Rebuilt the whole thing to be live from Pods instead:

- **`scoop_pod_field_names($pod)`** (`_pods_helpers.php`) — live field-name list for a pod, replacing hand-maintained `writeable` arrays. `shift_report`/`cake_order`'s entity specs were removed from `_specs.php` entirely (dead once nothing read them) — `_write_fields.php` and `_policy.php` now call this directly for both entities, across all three authorized roles. No per-field distinction has ever existed between those roles for this form, so there's no security loss — whoever can add a field in Pods admin already has full site-admin capability.
- **`scoop_shift_report_field_schema()`** (`rest.php`, served via new `GET /shift-report-fields`) — reads Pods' live field AND field-*group* definitions for `shift_report`, returns `{ groups: [{ label, weight, fields: [{ name, label, description, type, required, options... }] }] }`, sorted by group weight then field weight (both exactly as configured in Pods admin, including custom field groups like "Who and when are you" / "End of day" / "More Fields"). Pick fields' options are resolved server-side (`scoop_pods_dropdown_options()` for custom lists, a live query for relationship fields) so the client needs zero Pods-specific knowledge.
- **`shift-report-form.js`** now fetches that schema once and renders one `<section>` per group, iterating fields in order. Only four fields keep hand-built rendering (`BESPOKE_FIELD_NAMES`): `tempering_cabinet_photo` (upload + camera capture), `flavors_changed` (cabinet-grouped, filtered to today's `slot.current_flavor`), `supplies_low` (category-grouped via `supply.group`), `cake_orders` (creates new `cake_order` records inline). Everything else — including `location`, `change_low`, `staffing_level`, and any future field — renders generically off type (`text`/`paragraph`/`number`/`date`/`boolean`/single or multi `pick`). `location` needed one small bridge: it's generically rendered, but still needs a `change` listener to trigger `flavors_changed`'s rebuild, so `_buildGenericField()` stashes a reference to it (`this.LOCATION_SELECT`) for that one wire-up.

**Bugs found and fixed along the way** (all real, not hypothetical):
- `scoop_pods_dropdown_options()` (`_pods_helpers.php`) read `$field['options']['pick_custom']`, but Pods actually returns `pick_custom` at the top level of the field definition — always returned `[]`. Silent in practice because its one prior caller (`closeout.php`'s `scoop_closeout_tub_where()`) already had a defensive static fallback, but it meant "Get valid states from Pods (dynamic!)" was never actually dynamic. Fixed to read the top-level key.
- Pods returns field definitions as `Pods\Whatsit\Field` objects in this environment, not plain arrays — a strict `array` type hint on the new field-entry builder fataled immediately. Fixed by normalizing the same way `scoop_pods_field_def()` already did (`export()`/`to_array()`/cast fallback).

**Not done**: `cake_order`'s own sub-form (order name / cake-pie flavor / pickup date / details, shown when adding a cake order inline) is still hand-built, not schema-driven — `cake_pie_flavor` in particular is a plain text input standing in for what should eventually be a proper dropdown once that pod's fields stabilize. Lower priority since `cake_order` is a smaller, less actively-changing pod than `shift_report`.

## "End of day" group made conditional on shift (2026-08-11)

The "End of day" group (`tempering_cabinet_photo` + `final_tasks`) now only shows when `shift` = Late — hidden otherwise, since it's not relevant to an opening shift. Checked first whether Pods' own conditional-logic feature could drive this (it exists per-field — every field has `enable_conditional_logic`/`conditional_logic_save_value` — but not per-group, and nothing has it enabled anyway), so this is a small explicit rule in `shift-report-form.js` (`_wireConditionalGroups()`), not a generic system — not worth building a rules engine for one instance.

Two things this interacts with, both handled:
- **`tempering_cabinet_photo` is marked "required" in Pods admin.** Verified server-side first (`scoop_create_pod_item()` with the field omitted) that Pods' `required` flag is a client/form hint only, never enforced by `save_pod_item()` — so making it conditionally optional client-side needed no server change. The group is hidden via the native `hidden` attribute rather than a CSS-only class, which matters here: per the HTML5 spec, a form control whose ancestor has `display: none` (what `hidden` applies) is excluded from constraint validation, so the required file input doesn't block submission while hidden. `_submit()`'s own explicit photo check reads the section's actual `hidden` state directly rather than tracking a separate flag, so the two can't drift apart.
- **Groups needed a stable identifier.** `scoop_shift_report_field_schema()` only returned `label` before (display text, renameable) — added `name` (the Pods group slug, e.g. `end_of_day`) since that's what client code should match against.

## Next step

Validate the schema-driven form end-to-end in a real browser on the local mirror (server-side logic and JS syntax are verified, but nothing's been click-tested yet). After that: the other brainstormed options (timing instrumentation on CabinetWorkflow, kiosk role activation, customer-board photo audit, high-velocity flavor flag) are still open, unstarted.
