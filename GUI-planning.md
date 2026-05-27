# GUI Planning

A living document for the client-side UI evolution. Starts as a brain-dump of use cases, evolves into functional groupings and workflows, and finally becomes a concrete task list.

**Current phase:** collecting use cases.

---

## Direction so far

Decisions made during the 2026-05-26 discussion. These frame how use cases below should be evaluated — anything that fights these should call them out so the constraints can be revisited.

### Design system — defer adopting a library

The current widget layer (`El`, `FindIt`, `TextIt`, `Toast`, grid editors) is hand-rolled and tightly bound to the grid's form/dataset/POST-envelope pipeline. The cost of swapping in a component library is the rewrite of that glue, not the visual styling. So the plan is:

- **Stay vanilla.** No React/MUI. No build step.
- **Lean into CSS tokens.** Extend the existing `:root { --color-* }` palette with a typography scale and a spacing scale, ideally factored into a small `_tokens.css` file that `css.css` imports.
- **Re-evaluate component libraries (Shoelace, MWC, Spectrum) only when** the grid needs widgets it doesn't already have — modals, drawers, command palette, autocomplete trees, etc.

### Tabs — cheapest path first

Multiple `[scoop_grid]` shortcodes already mount independently on one page, so tabs are largely a presentation problem.

- **Plan:** new `[scoop_tab_group]` wrapper shortcode + a `tab="..."` attribute on each grid shortcode. CSS toggles which panel is active.
- **Mount eagerly, hide with CSS.** One warm bundle covers all grids on the page (bundle endpoint is type-keyed, cache stays effective).
- **Lazy mount per tab** only when profiling shows a specific tab carrying heavy unique data.

---

## Use case backlog

Raw drop-ins as they come up. No grouping or prioritization yet — that comes after we have enough to see patterns. Each entry should ideally answer: *who, what, why, how often.*

<!--
Template:

### <short name>
- **Actor:** who triggers it
- **Trigger:** when/why it happens
- **Today:** how it's done (if at all) in the current UI
- **Pain:** what's clunky or missing
- **Sketch:** one-line idea for how the new UI handles it
-->

### Cone sales data entry & import
- **Actor:** Store manager / admin. End-of-day or once-a-week task.
- **Trigger:** (a) Daily — recording today's regular + gluten-free cone count. (b) One-time — backfilling three years of historical data currently sitting in [data-exports/Waffle Cone (in store) Sales.csv](data-exports/Waffle Cone (in store) Sales.csv). (c) Future ad-hoc — if a fresh CSV export ever lands.
- **Today:** Cone counts live in a loose CSV that's maintained manually outside the tracker. The CSV has typos (e.g. `3/6//2023`), is pivot-shaped (hard to query), and can't feed live forecasts.
- **Pain:** (a) Daily entry friction — POS gives two numbers a day; the tracker needs them in the DB but staff aren't going to navigate a wp-admin CPT form for two integers. (b) The pivot CSV is unusable as a runtime data source; it has to be ingested once to backfill. (c) Forecasts (kitchen dashboard, slot planning, etc.) can't be implemented until this data is queryable.
**Data model (decided 2026-05-26):**

New `nightly_sales` CPT. One record per `(date, location)`. Fields:
- `sale_date` (date) — the day being recorded, NOT the data-entry timestamp
- `location` (relation → location CPT) — backfilled rows default to `SCOOP_DEFAULT_LOCATION_ID` (935)
- `regular_cones` (int) — regular waffle cone count for the day
- `gluten_free_cones` (int) — GF waffle cone count for the day

Name reflects the workflow cadence (end-of-night entry) rather than the data category, so the CPT can grow fields later — cup, pint, tasting-flight counts — without rename.

**Sketch:** Two surfaces sharing the same `nightly_sales` CPT:

1. **Daily entry control** — minimal-keystroke input. `sale_date` defaults to today, two number fields (regular + GF), one save. Likely a small widget on the kitchen dashboard or home page, visible at end-of-shift. Upsert behavior: re-entering for the same `(date, location)` overwrites the existing record rather than creating a duplicate.

2. **CSV upload page** — admin-only. Two-step flow so the writer doesn't run on bad data:
   - **Parse step:** read the pivot-shaped CSV, return `{ rows: [...], errors: [...] }`. Errors include typo dates (e.g. `3/6//2023`), blank cells in the middle of populated weeks, negative counts. Surface them in the UI *before* anything writes.
   - **Commit step:** upserts each clean row by `(sale_date, location)`. If any rows would overwrite an existing record, prompt for confirmation ("12 rows would overwrite — proceed?"). Skipped rows from the parse step are shown in a manual-fix list.

Re-running the same CSV is safe by construction (idempotent).

**Open questions (for later):**
- ~~**CPT shape.**~~ → *Resolved 2026-05-26*: `nightly_sales` with `(sale_date, location, regular_cones, gluten_free_cones)`. See data model above.
- ~~**Backfill atomicity.**~~ → *Resolved 2026-05-26*: parse-then-commit two-step. Parser surfaces errors; writer only sees clean rows. Upsert by `(sale_date, location)` makes re-runs idempotent.
- **POS integration.** Long-term, the daily entry should be replaced by an automated POS sync. Worth not over-investing in the manual entry UI.
- **Future-proofing for non-cone POS data.** If we later record cup / pint / tasting-flight sales, the CPT can extend with new int fields rather than splitting into parallel CPTs. Revisit only if a category needs different metadata (e.g. per-flavor breakdown).

---

### Kitchen production dashboard
- **Actor:** Kitchen staff producing tubs.
- **Trigger:** Mid-shift, between batches. Used many times a day.
- **Today:** Batch creation goes through the existing `Batch` grid — pick flavor + count, save. Surrounding context (what's already in stock, what's slotted, what's seasonally hot) lives in other grids on other pages, so staff have to navigate around to see them.
- **Pain:** (a) Entering a batch should be as close to "two keystrokes" as possible — the existing grid form has more friction than it needs. (b) Staff make the wrong call on *what* to produce because they can't see current inventory and upcoming demand at the same time as the entry form. (c) "Flavors that haven't been made in a while" is now visible (Flavors grid) but lives on a different page from where batches are entered. (d) Custom orders (cake / pie / novelties) don't surface to the kitchen at all today — they only exist in whatever the store uses for taking the order.

**Scope:** Tubs of ice cream only. Cone production (i.e. making waffle cones) is explicitly **out of scope** for this dashboard.

**Sketch:** A single kitchen-focused page with four sections:

1. **Production entry — "what we made"**
   Minimum-keystroke batch input. Focused flavor-typeahead → count → enter. Wraps the existing `/scoop/v1/batches` write path but skips the full grid chrome.

2. **Inventory — "what we have"**
   Tub counts grouped by flavor. Basically the existing `Flavors` grid's `Tubs` column promoted to the dominant view, sortable so staff can spot low-stock flavors at a glance.

3. **Expected demand — front-of-house (from historical sales)**
   ```
   → tubs needed this week: 25     ← derived
   → tubs on-hand:           7     ← from inventory section
   ```
   Derived from the cone CSV via the rolling FoH cones-per-tub ratio (see *Forecast calculation* below). One number, big and obvious.

4. **Expected demand — custom orders (cake / pie / novelties)**
   ```
   → tubs needed for orders: 3
   ```
   Aggregated from order entries (a not-yet-existing data type). Each order rolls up its expected tub draw and the dashboard sums them across the planning window.

5. **Order entry — "what's been ordered"**
   Lightweight input for cake / pie / novelty orders. Drives section 4 and probably wants its own short list of upcoming orders alongside.

**Forecast calculation — FoH tubs needed:**

The tracker now overlaps the cone CSV by several months, so the cones-to-tubs ratio is **measured empirically** rather than estimated. The rolling pipeline:

1. Trailing window (30–60 days, rolling).
2. Qualifying tubs in that window:
   - `use` = front-of-house only (matches `scoop_analytics_foh_use_ids()` in [includes/analytics.php](includes/analytics.php))
   - Both `opened_on` AND `emptied_at` populated — i.e. the tub fully completed its lifecycle inside the window. Partials are excluded so they don't skew the ratio.
3. Empirical ratio:
   ```
   foh_cones_per_tub = sum(cones_sold during window) / count(qualifying FoH tubs)
   ```
4. Forecast for target week:
   ```
   predicted_cones = lookup(CSV, matching week-of-year, prior years)
                     × (1 + growth_factor)
   foh_tubs_needed = predicted_cones / foh_cones_per_tub
   ```
5. `growth_factor` — modest year-over-year, tunable. The CSV's per-year grand totals make this measurable; 2023→2024 was ~1.3%. Default 3–5% until 2025 settles.

The FoH + complete-lifecycle filter makes the ratio clean by construction: only tubs whose entire output went to cone-serving count, so pints/cups/tasters and partial draws don't pollute it.

**Open questions (for later):**
- ~~**Orders are net-new infrastructure.**~~ → *Resolved 2026-05-26*: Gus will add an `order` CPT.
- ~~**Order → tub-draw conversion.**~~ → *Resolved 2026-05-26*: cakes and pies will have **recipes** that declare their tub draw, so the conversion lives in the recipe data rather than in shortcode config or per-order fields.
- ~~**Where does the historical CSV live in the runtime?**~~ → *Resolved 2026-05-26*: the CSV gets imported into the database. The CPT shape + the ingest UI live in their own use case — see [Cone sales data entry & import](#cone-sales-data-entry--import) below.
- **Per-flavor breakdown** — the CSV is total cones, not per-flavor. The "tubs needed this week" number is a fleet total; deciding *which* flavors to make still needs the tracker's own recent per-flavor sellthrough as a separate signal.
- **Page layout** — five sections is too many for one screen at small widths. Tabs (per earlier "tabs" decision)? Vertical-scroll stack? Persistent header (production entry on top) with tabbed body for the rest?
- Should batch entry on this page bypass the standard `Batch` grid entirely, or render a slimmed view of it? Bypassing means duplicating a write path; slimming means making `BatchGridModel` configurable enough to render in "express" mode.
- The "production schedule" view overlaps with the existing Cabinet/Slot planning UI. Decide whether this surfaces a read-only summary or links out.

---

### Left-side area navigation toolbar
- **Actor:** Anyone using the app — primary value is for staff who jump between functional areas during a shift.
- **Trigger:** Always present. Visible on every page that hosts a `[scoop_grid]`. Click an icon → navigate to that area.
- **Today:** No global nav. Users either bookmark each WordPress page (Cabinet planning, FlavorTubs, Closeouts, Analytics, etc.) or click through WP-rendered post nav. There's no consistent way to see what areas exist or which one you're in.
- **Pain:** (a) Discoverability — new staff don't know the app's full surface area. (b) Context-switching cost — leaving the current page to navigate, then losing scroll/filter state when coming back. (c) No visual cue for "where am I" in the app.
- **Sketch:** A fixed-position left rail injected by the plugin (probably via `enqueue.php` on pages that have any `[scoop_grid]`). Each rail item = icon + label, linking to the corresponding WordPress page that hosts the relevant shortcode(s). Current area is visually marked. Collapsible/expandable for screen-space efficiency.

**Open questions (for later):**
- Plugin-owned or theme-owned? Plugin-owned is more cohesive but means the plugin starts caring about navigation, which is a new concern. Theme-owned keeps the plugin focused but couples nav to whatever theme is active.
- Hard-coded list, or driven by a registration API (so new areas register themselves)? Hard-coded is fine until there's a third party.
- Icons — custom SVGs, an icon font (Material Icons, Lucide), or text-only as a v1?
- Interaction with planned tabs: tabs are *within* an area, this is *between* areas. Want to make sure they don't visually compete.
- Mobile/narrow screens — does the rail collapse to a hamburger, or hide entirely behind a different control?

---

### Inline entity detail editor (flavor / tub / batch)
- **Actor:** Anyone using a scoop_grid — ops staff, managers.
- **Trigger:** Clicking a flavor, tub, or batch title/ID anywhere it appears in the UI (grid cell, badge, plot point, group header, etc.).
- **Today:** User has to navigate out to the wp-admin edit screen for that CPT, edit there, and come back. Loses scroll position, grid filter state, and any other tab context.
- **Pain:** (a) wp-admin is not space-efficient for these CPTs and exposes a lot of irrelevant chrome. (b) The round-trip to admin breaks the operational flow — staff are usually mid-task (closing a tub, planning a slot) when they need to inspect or edit one of these. (c) Updates made in wp-admin don't always feel like they belong to "the app".
- **Sketch:** Click a title/ID → opens a slide-in panel (or modal) with the entity's full editable detail. Saves via the existing per-type write route (`/scoop/v1/tubs`, `/scoop/v1/batches`, plus a new `/scoop/v1/flavors`). Closing reuses cached bundle data unless something changed, in which case the grid that hosts it refreshes.

**Open questions (for later):**
- One generic detail component or per-type? Generic is cheaper to build; per-type can show contextual cross-references (e.g. a batch shows its child tubs).
- Drawer vs. modal vs. popover — different feel, same data path.
- Does this force adopting a component library? (drawers/modals don't exist in the current widget set — could be the first real argument against the "stay vanilla" stance in [Direction so far](#direction-so-far))

---

## Functional areas

Once the backlog above has 8-12 entries, group them here by subject matter (e.g. *Tub lifecycle*, *Batch planning*, *Reporting*, *Admin*). One section per area, with the relevant use cases listed under it.

> _(empty — fill once backlog has critical mass)_

---

## Workflows

Cross-cutting sequences that span multiple functional areas (e.g. *Closing out a shift end-to-end*, *Onboarding a new flavor*). Different from functional areas because they trace a path through the UI rather than describing a region of it.

> _(empty — fill once functional areas are stable)_

---

## Task list

Concrete, ordered work items, each linked back to the use cases / functional areas it serves. Format: `- [ ] <task> — <why> ([UC link])`.

> _(empty — populate once functional areas + workflows have stabilized)_
