# RCC Import — Plan & Design Notes

This document captures the design for folding the standalone *RCC Recipe Importer* plugin into `scoop_rest`. It is the working contract between human + assistant for the integration — update it as decisions change.

Source CSVs come from **Recipe Cost Calculator** (RCC). Two CSV shapes are supported:

- `recipes.csv` — exported from RCC's recipe view
- `ingredients-<timestamp>.csv` — exported from RCC's ingredient view

The importer auto-detects type by inspecting CSV column headers (presence of `Ingredient List` ⇒ recipe; presence of `Price/unit` ⇒ ingredient).

---

## 1. Goals

- Update existing `recipe` and `ingredient` pod rows with the latest cost, supplier, allergen, and category data from RCC.
- Reconcile RCC's record IDs with pod records when they drift apart (titles renamed, IDs missing).
- Keep the workflow **review-first**: every destructive operation gets explicit confirmation in the UI.
- Never silently clobber existing data with an empty CSV cell.

**Non-goals (for now):**

- Resolving the RCC string blobs (`allergens_str`, `categories_str`, `ingredient_list_str`) into structured relational data — see [§4](#4-string-fields-vs-structured-fields).
- Importing pricing/margin fields (`Sell Price`, `Target Margin`, etc.) — schema doesn't have columns for them yet.
- Two-way sync (writing pod changes back to RCC).

---

## 2. Where this lives

A new top-level WordPress admin menu called **Scoop** is added by the plugin. The RCC importer is a sub-page under it.

```
Scoop                       (new top-level menu)
├── RCC Import              (this feature)
└── Command Test            (existing scoop_render_command_test_page, finally wired up)
```

The implementation lives in `includes/rcc-import/`:

```
includes/rcc-import/
├── _config.php           # CSV-type detection, field mappings (one place to edit)
├── _ui.php               # Admin page rendering for upload / map / preview / results screens
├── mapper.php            # Matches CSV rows to pod rows (exact title, near title, cc_id/rcc_id)
├── importer.php          # Diffs CSV vs pod values, executes pods()->save() on commit
└── csv.php               # CSV parsing helpers
```

Loaded from `scoop_rest.php` via `scoop_require()` alongside the other `includes/*` files. Underscored files (`_config.php`, `_ui.php`) follow the existing project convention for configuration/contract layers.

---

## 3. Workflow (three screens)

Each step writes the current state to a per-user transient (`scoop_rcc_import_<user_id>`) so the workflow survives page reloads without falling back to PHP sessions.

### Screen 1 — Upload

- File input, CSV only.
- File is moved to `wp-content/uploads/RCC/rcc-<timestamp>-<original>.csv`.
- CSV type is auto-detected on parse.
- Submitting advances to screen 2.

### Screen 2 — Map & review

The mapper classifies each CSV row against existing pod rows:

| Classification | UI treatment |
|---|---|
| **Exact match** (title + ID both match) | Count only, no action |
| **Exact title, missing ID** | Per-row checkbox: back-fill `rcc_id` from CSV |
| **Exact ID, near title (≥70% similarity)** | Per-row checkbox: adopt CSV title (default off) |
| **Near title only (no ID match)** | Per-row checkbox: adopt CSV title + back-fill `rcc_id` |
| **CSV orphan** (no title or ID match in pods) | Per-row checkbox: create new pod row (default off) |
| **Pod orphan** (pod row not in CSV) | Info only — these are untouched |

Clicking *Continue to import preview* advances to screen 3.

### Screen 3 — Field-diff preview & commit

For every CSV row that maps to a pod row (either pre-existing or being created), show a per-field diff table:

- Current pod value vs new CSV value
- Status badge: **CLOBBERED** (overwrite of non-empty value) or **NEW VALUE** (empty → filled)
- **Empty CSV cells are skipped entirely** — never displayed, never written

A single "I confirm" checkbox + commit button executes the import.

### Results screen

Counts of updated/created/skipped rows, plus a list of any errors raised by `pods()->save()`.

---

## 4. String fields vs structured fields

> **⚠ Important design distinction.**

The recipe pod (and the extended ingredient pod) has columns ending in `_str` — `allergens_str`, `categories_str`, `ingredient_list_str`. These hold **the raw string blobs from RCC**, e.g.:

```
allergens_str = "Eggs, Gluten, Milk, Soy"
ingredient_list_str = "chocolate chips-large (sugar, unsweetened chocolate, …), flour, …"
```

**The importer's job is to copy these strings verbatim from CSV to pod.** It does not parse them, normalize them, or resolve them to anything else.

Separately, the project maintains **bi-directional, ID-based** structured relationships for the same concepts — e.g. a recipe ↔ allergen pod relation field where each allergen is a real pod record with its own ID. Those structured fields are **out of scope for this importer**. A future reconciliation step (TBD — separate admin tool, save-hook, or scheduled job) is responsible for parsing the `_str` blob and producing the structured relations.

This split lets the importer be dumb-but-safe: it just stashes what RCC said, and the canonicalization happens once, in one place, when the system is ready for it. If the relational fields drift from the string blob, the string blob is treated as the source of truth (because it came from RCC).

**Precedent that already exists in the codebase:**

- The `flavor` pod *already* has a structured `allergens` field declared as `data_type: post_names` in [`includes/_specs.php:188`](includes/_specs.php#L188) — relations resolved via `track_podsrel`, surfaced as post titles in the bundle. The same pattern is what `recipe.allergens_str` will eventually feed (via the to-be-built reconciliation step) into a `recipe.allergens` post_names field.
- A `recipe` field on `flavor` exists as a Pods export column (visible in [`data-exports/NO RECIPES flavor-export-2026-03-08.csv`](data-exports/NO%20RECIPES%20flavor-export-2026-03-08.csv) — note the filename), but as of that snapshot the column was empty for every row. Status as of today: TBD — Gus to confirm whether the flavor→recipe mapping has been populated since.
- A `recipe → ingredient` relation is now built by the reconciler (see [§13](#13-relation-reconciler)) — the Pods field is `recipe.ingredients`, multi-target (recipe + ingredient), stored in `track_podsrel`. The [`triage/sub-recipes-2026-04-19.tsv`](triage/sub-recipes-2026-04-19.tsv) "components resolved" column was the design precedent for this work but is not the field name in use.

---

## 5. ID semantics — `cc_id` vs `rcc_id`

Historical: both pods have a `cc_id` column (decimal). The recipe pod also has `rcc_id` (varchar). The ingredient pod is being extended to add `rcc_id` (varchar) too.

Going forward **`rcc_id` is canonical**:

- The importer **matches** on either `rcc_id` OR `cc_id` (lookup falls through both).
- The importer **writes** new IDs to `rcc_id` only.
- The importer **does not touch** `cc_id` — no back-fill, no migration, no delete.
- Eventually `cc_id` will be retired in a separate cleanup pass.

Why varchar instead of decimal? RCC IDs are stable integers today, but treating them as strings means we can't accidentally truncate or coerce them, and we never have to worry about a 13-digit ID overflowing the column.

---

## 6. Field mappings

### Recipe pod (no schema changes needed)

| CSV column | Pod field | Notes |
|---|---|---|
| `ID` | `rcc_id` | varchar, new canonical ID |
| `Yield Count` | `yield_count` | |
| `Yield Units` | `yield_units` | |
| `Cost` | `cost` | |
| `Cost Per Unit` | `cost_per_unit` | |
| `Allergens` | `allergens_str` | RCC string blob; see [§4](#4-string-fields-vs-structured-fields) |
| `Categories` | `categories_str` | RCC string blob; see [§4](#4-string-fields-vs-structured-fields) |
| `Ingredient List` | `ingredient_list_str` | RCC string blob; see [§4](#4-string-fields-vs-structured-fields) |

Skipped CSV columns: `Name` (only adopted via mapper opt-in), `Yield Units Descriptor`, `Target Margin`, `Minimum Sell Price`, `Sell Quantity`, `Sell Price`, `Sell Price Per Unit`, `Sell Margin`, `Food Cost Percentage`, `Label Certifications` (recipe pod has no label_certs column).

### Ingredient pod (requires new columns — see [§7](#7-ingredient-pod-schema-additions))

| CSV column | Pod field | Notes |
|---|---|---|
| `ID` | `rcc_id` | varchar, **new** |
| `Price` | `price` | numeric pass-through |
| `Price/unit` | `price_unit` | RCC ships `"$1.28/oz"` etc.; importer extracts the leading number via `scoop_rcc_extract_currency_value()` before storing |
| `Supplier` | `supplier` | |
| `Brand` | `brand` | |
| `Case` | `case` | |
| `Pack` | `pack` | |
| `Unit` | `unit` | CSV ships unit strings (`"oz"`, `"g"`, `"lb"`, ...). The pod's `unit` column was retyped from decimal to varchar on 2026-05-27 specifically to receive these. |
| `Allergens` | `allergens_str` | **new** column; RCC string blob; see [§4](#4-string-fields-vs-structured-fields) |
| `Notes` | `notes` | **new** column |
| `Label Certifications` | `label_certs` | **new** column |

Skipped CSV columns: `Name` (mapper opt-in only), `Price/unit change`, `Last Price Update`, `Order Code`, `Country of Origin`, `Category`, `Lead Time`, `Purchase Amount`, `Converters`, `Display Name`, `Usable Percentage`, `Is Added Sugar`.

---

## 7. Ingredient pod schema additions

Required new columns on the `ingredient` Pod (add via *Pods Admin → Edit Pod → ingredient → Add Field*):

| Field name | Type | Storage |
|---|---|---|
| `rcc_id` | Plain Text | varchar 255 |
| `allergens_str` | Plain Text | varchar 255 |
| `notes` | Paragraph Text | longtext |
| `label_certs` | Plain Text | varchar 255 |

**Status:** *Pending — confirm whether these were added to the ingredient pod or the recipe pod by mistake.*

---

## 8. Write path & save semantics

All pod writes go through `pods( $pod_name, $id )->save( $fields )`. Reasons:

- Triggers `pods_api_pre_save_pod_item_*` filters — same hook layer that enforces tub/closeout/batch rules elsewhere in the plugin (see `includes/hooks/`). Even though there are no hooks on `recipe` or `ingredient` today, this keeps the door open for adding them without retrofitting the importer.
- Matches the project's `CLAUDE.md` convention: "prefer adding to these hooks over patching the REST layer, so the rule holds for all save mechanisms."

The standalone plugin's `rcc_mapper.php` used direct `$wpdb->update()` for the `cc_id` back-fill. In this version, that becomes a `pods()->save()` too.

Performance is fine: typical recipe imports are <1000 rows, and `pods()->save()` ~50ms × 1000 ≈ under a minute even worst case.

---

## 9. Behaviors that intentionally differ from the standalone plugin

| Concern | Standalone plugin | This integration |
|---|---|---|
| Title updates | Never (silent skip with mismatch list) | Opt-in per-row checkbox in mapper |
| New pod row creation | Never (warning only) | Opt-in per-row checkbox in mapper |
| Empty CSV cells | Overwrite (could clobber with blank) | Skip entirely |
| Workflow steps | Single page with preview-then-confirm | Three explicit screens |
| Write path | Mixed (`pods()->save()` in importer, `$wpdb->update` in mapper) | Uniform `pods()->save()` |
| Sessions | Used `$_SESSION` to carry filepath | Per-user transient |
| Pod ID column | `cc_id` only | `rcc_id` canonical, `cc_id` legacy fallback |
| Scope | Recipes only | Recipes + ingredients (auto-detected) |

---

## 10. Triage awareness — `PLACEHOLDER-SUSPECTED` rows

There's an active data-quality triage project documented in [`triage/README.md`](triage/README.md) that classifies all 319 RCC ingredients (as of 2026-04-17) into confidence tiers 0–5, plus statuses including `OK`, `PLACEHOLDER-SUSPECTED`, `ZERO-PRICE`, `NEEDS-GUS-WEIGHT`, `NEEDS-REPRICE`. The triage TSVs may be partially stale, but the underlying pattern they document is structural and still relevant to anyone running this importer.

**The key pattern:** ~49% of RCC ingredient rows (157 of 319 as of the triage snapshot) ship as `1 × <unit> @ $1.00` — a recognizable data-entry stub where someone picked a unit in RCC but never recorded the real supplier price. These rows look like:

```
ID,Name,Price,Price/unit,...
591304,4-1 strawberries,1.0,$1.00/g,...
510527,Blackberry flavor,1.0,$1.00/oz,...
574615,Marsala wine,1.0,$1.00/floz,...
```

If the importer naively copies `Cost` / `Price` from these rows, it'll stamp fake `$1.00` prices on top of whatever real cost data the pod already has — and the downstream cost cascade ends up producing absurd numbers like "Black Forest Cheesecake at $12k/tub" (a real example from the triage doc).

**Importer behavior:**

- **Detect at parse time.** For each CSV row, the importer evaluates whether the row matches the placeholder shape. The rule (per the triage TSV's lineage notes): RCC reports a per-unit price of exactly `$1.00`, with `Purchase Amount` / pack size = `1`, regardless of unit. Stored on the parsed row as `placeholder_suspected = true`.
- **Skip cost-bearing fields by default.** When `placeholder_suspected` is true, the importer **excludes** `Cost`, `Cost Per Unit`, `Price`, `Price/unit` from the field-diff preview for that row entirely. Other fields (allergens, supplier, brand, yields) still flow through normally — those aren't tainted by the placeholder.
- **Surface in the mapper UI.** Mark placeholder-suspected rows with a badge in the screen-2 map review so the operator sees how many were filtered. Provide a "force import cost anyway" checkbox per row for the rare case where the operator has confirmed the $1.00 is real (e.g. someone really does sell something for $1/g).
- **Don't touch `confidence`.** That column on both pods is *triage output*, not RCC input. The importer never reads from CSV into `confidence` and never overwrites it.

This means the importer plays nicely with the triage project: it imports the safe fields, leaves the suspect cost data alone, and never overwrites the human-curated confidence score.

---

## 11. Open items / TBD

- [ ] Confirm ingredient pod has the four new columns added (see [§7](#7-ingredient-pod-schema-additions)).
- [x] Confirm whether `flavor → recipe` mapping is populated in the current DB. **Status as of 2026-05-27:** 194 flavors mapped, 32 unmapped. Partial completion. Not blocking the RCC importer, but worth tracking as a separate piece of work.
- [ ] Decide ingredient `Purchase Amount` vs `Yield Count`/`Yield Units` mapping — RCC's CSV has all three and the relationship isn't obvious.
- [ ] Decide who owns the future `_str` → structured-relation reconciliation (see [§4](#4-string-fields-vs-structured-fields)). Possible answers: dedicated admin button, scheduled action, save-hook, separate plugin.
- [ ] Decide whether legacy `cc_id` column gets dropped after migration is complete.
- [ ] Decide if the importer should write an audit log row (count of changes, who imported, when) — the `inventory_change` pod is for runtime stock changes, so probably needs a new pod or a separate option-array log if we want this.
- [ ] Decide whether the placeholder-detection rule (currently "Price = 1.00 AND Purchase Amount = 1") needs to expand if RCC starts producing different placeholder shapes.
- [ ] **Longer-term goal (out of scope for the raw importer):** normalize every ingredient cost to a comparable `price_gram` and/or `price_liter` so downstream recipe-cost math has a single unit basis. The triage cascade in [`triage/README.md`](triage/README.md) is the prior art for this. The importer stays dumb about it — getting `price`, `price_unit`, and `unit` faithfully in is enough; the normalization layer reads those.

---

## 12. Implementation plan

A six-phase build. Each phase ends at a point where the code on TEST is in a coherent state — even if the next phase hasn't started, what's there works for what it does. Phases A–C are the minimum to get a useful mapper-only diagnostic on TEST; phases D–E ship the actual writes.

### Phase A — Admin shell

Goal: a `Scoop` top-level menu appears in WP admin with two sub-pages, neither of which does anything destructive yet.

1. **`includes/admin-menu.php`** (new). Registers `add_menu_page('Scoop', …, 'scoop_root', ['dashicons-icecream'?])` + `add_submenu_page` for `Scoop → RCC Import` and `Scoop → Command Test`. Capability: `manage_options`.
2. **`scoop_rest.php`**. Add `scoop_require('includes/admin-menu.php')` near the other includes.
3. **`includes/admin-page.php`**. The existing `scoop_render_command_test_page` function gets wired into the new menu (it's currently defined but never registered). No other change to it.
4. **`includes/rcc-import/_ui.php`** (new, stub). Just renders an "RCC Import — coming soon" placeholder for now, so the sub-page exists.

End state: navigating to `Scoop → RCC Import` shows a placeholder; `Scoop → Command Test` shows the existing command-test UI.

### Phase B — CSV parsing & detection

Goal: upload a CSV, parse it, classify each row, hand the result back as a PHP array. No DB writes. No UI yet beyond raw `var_dump`-style output.

5. **`includes/rcc-import/csv.php`** (new).
   - `scoop_rcc_parse_csv($filepath): array` — UTF-8, BOM-tolerant, returns `['type' => 'recipe'|'ingredient', 'rows' => [...]]`. Type detected by header inspection: `Ingredient List` present ⇒ recipe; `Price/unit` present ⇒ ingredient; neither ⇒ throw.
   - `scoop_rcc_is_placeholder(array $row): bool` — implements the [§10](#10-triage-awareness--placeholder_suspected-rows) detection rule. Each row gets `$row['_placeholder_suspected'] = true|false` annotated by the parser.
6. **`includes/rcc-import/_config.php`** (new). The single source of truth for field mappings — two arrays, one per pod type, mapping CSV column → pod field. The `Cost` / `Price` / `Cost Per Unit` / `Price/unit` mappings carry a `placeholder_skip => true` flag so phase D knows to skip them when the row is placeholder-suspected.
7. **`includes/rcc-import/_ui.php`** (extended). Adds the file-upload form on the RCC Import sub-page. On POST, file moves to `wp-content/uploads/RCC/`, gets parsed, and the parsed result dumps to screen as a debug table (no commit anywhere yet).

End state: you can upload a CSV and see a table of "type=recipe/ingredient, N rows, M placeholder-suspected".

### Phase C + D — Mapper + Importer (bundled)

Built together as one push per [open question #1 resolution](#open-before-i-start-writing-code) — the mapper-only diagnostic is useful but not useful enough on its own to justify a separate ship.

#### C — Mapper

Goal: given a parsed CSV, classify each row against existing pod rows. Still no writes — output is a structured report consumed by the UI.

8. **`includes/rcc-import/mapper.php`** (new).
   - `scoop_rcc_load_pod_index(string $pod_name): array` — single read of all pod rows of the given type, indexed by `rcc_id`, `cc_id`, and lowercased `post_title`. Returns shape `['by_rcc_id' => [...], 'by_cc_id' => [...], 'by_title' => [...]]`. One query plus one in-memory lookup table beats per-row `pods()->find()` calls.
   - `scoop_rcc_classify_rows(array $parsed, array $pod_index): array` — for each CSV row produces one of:
     - `exact_match` — title and ID both match
     - `exact_title_missing_id` — title matches, neither `rcc_id` nor `cc_id` is set on the pod row
     - `exact_id_near_title` — ID matches, title differs (≥70% similar)
     - `near_title` — no ID match, ≥70% title similarity, exactly one candidate
     - `csv_orphan` — no plausible match
     - `pod_orphan` — pod row not referenced by any CSV row (computed once at the end across pod_index minus matched IDs)
   - Title similarity via `similar_text()` with normalized inputs (lowercase, strip punctuation, collapse whitespace).
9. **`includes/rcc-import/_ui.php`** (extended). Adds screen 2 — the map-review screen. Renders the classification report with the per-row checkboxes described in [§3](#screen-2--map--review). State for "next screen" stored in a transient `scoop_rcc_<user_id>` (filepath + parsed type + per-row checkbox decisions), per [§3](#workflow-three-screens). Submitting screen 2 dumps the transient contents to confirm — still no writes.

End of C: full mapper diagnostic visible in the UI; transient carries forward into D.

#### D — Importer (preview + commit)

Goal: actual writes via `pods()->save()`. Screen 3 shows a per-field diff; commit executes.

10. **`includes/rcc-import/importer.php`** (new).
    - `scoop_rcc_build_field_diff(array $row, $pod_row, array $field_map): array` — for each CSV→pod field mapping, compares current vs new. Skips empty CSV values entirely (per [§9 empty-cell rule](#9-behaviors-that-intentionally-differ-from-the-standalone-plugin)). Skips `placeholder_skip` fields when the row is placeholder-suspected (unless the per-row override checkbox is set in the transient state). Returns array of `{field, current, new, status: 'clobbered'|'new'|'skipped_empty'|'skipped_placeholder'}`.
    - `scoop_rcc_commit_row(array $row, ?int $pod_id, array $diff, string $pod_name): array` — calls `pods($pod_name, $pod_id)->save($changed_fields)`. For new-row creation, calls `pods($pod_name)->add($fields)` first to get an ID, then proceeds. Returns `{ok: bool, id: int, error: ?string}`.
    - Title updates and new-row creation gated on the screen-2 opt-in checkboxes stashed in the transient.
11. **`includes/rcc-import/_ui.php`** (extended). Adds screen 3 (field-diff preview) and the results screen. Single confirm checkbox + commit button. Results screen reads the commit log out of the transient.

End state: full upload → map → preview → commit → results cycle works on TEST.

### Phase E — Polish

12. **Error handling.** `pods()->save()` returning `false` or throwing → captured per row, reported on the results screen, doesn't kill the batch.
13. **Empty-state UX.** Zero-change preview screen says "no field changes — nothing to commit" instead of an empty table.
14. **Transient TTL.** Set to 1 hour so abandoned imports don't leak storage.
15. **Cancel button** on every screen, clears the transient and returns to upload.
16. **CSS isolation.** All styles prefixed `.scoop-rcc-` to avoid colliding with the existing grid CSS.

### Phase F — Ingredient parity

The above phases all work for both pod types in parallel — the field-mapping config in `_config.php` is the only place the two diverge. But ingredients can't ship until the four new ingredient pod columns ([§7](#7-ingredient-pod-schema-additions)) are confirmed in place. If Gus's pod-field additions ended up on `recipe` instead of `ingredient`, phase F is "move them" + verify the importer still loads the index correctly.

### What I'd skip building unless asked

- **Bulk CSV history / undo.** Every commit is to a live pod with hook coverage. Audit is the [§11](#11-open-items--tbd) deferred decision.
- **JS-side anything.** Pure server-rendered admin pages. No `assets/` changes.
- **REST endpoint for the importer.** Lives entirely under `admin.php` — no `scoop/v1/` route. The route registry in `_config.php` stays untouched.
- **Tests.** No test infrastructure in this repo per [`CLAUDE.md`](CLAUDE.md). Manual verification on TEST is the loop.

---

## 13. Relation reconciler

Separate from the importer, the **reconciler** parses each recipe's `ingredient_list_str` (which the importer wrote verbatim from RCC) into a tree of parent → child relationships and writes them into a Pods relation field called `ingredients`. Lives in [`includes/rcc-import/reconciler.php`](includes/rcc-import/reconciler.php) + [`includes/rcc-import/reconciler-ui.php`](includes/rcc-import/reconciler-ui.php). Admin page: *Scoop → Reconcile Relations*.

### Pre-flight: pod fields the user must configure

| Pod | Field | Type | Related to | Notes |
|---|---|---|---|---|
| `recipe` | `ingredients` | Relationship (multi) | `recipe` + `ingredient` | bi-directional; sub-recipes are stored alongside ingredient pods in the same field |
| `ingredient` | `ingredients` | Relationship (multi) | `ingredient` | bi-directional; populated for compound ingredients (those whose label declaration parses into sub-items) |

The reconciler will fail every write if these fields don't exist — errors will appear in the results-screen error list.

### What it parses

RCC composes `ingredient_list_str` with comma separation at the top level, nested parens for the breakdown of any compound item, and single quotes wrapping label declarations. Example:

```
chocolate paste (water, white sugar, cocoa powder ('sugar, cocoa- processed with alkali, unsweetened chocolate, soy lecithin- an emulsifier, vanilla.'))
```

The parser walks this into a tree of nodes, each with `name` + optional `children`. Single-quoted parens content is flagged as a label declaration but otherwise parsed the same way.

### Resolution order per node

For each parsed node, the resolver tries in order:

1. **Existing ingredient** by normalized title match.
2. **Existing recipe** by normalized title match.
3. **Paren-to-hyphen normalization** (`chocolate chips-large` → `Chocolate Chips (large)`) against ingredients.
4. **Create a new atomic ingredient** with the node name as `post_title` and a `notes` field recording the reconciler run.

### Recursion rules

- **Sub-recipes**: not recursed into from a parent's walk. The main loop iterates every recipe and writes each recipe's `ingredients` from its own canonical `ingredient_list_str`. This avoids using one recipe's parent-rederived representation of another's structure.
- **Compound ingredients** (those with label declarations): recursed into once per entity, the first time encountered. The `processed_entities` set guards against duplicate work when the same compound ingredient appears in many recipes.

### Audit log

Every auto-created ingredient is logged with: new pod ID, name, kind (`atomic` or `compound`), source recipe that triggered creation, and the raw token text from the parser. The log is rendered on the results screen and saved as `wp-content/uploads/RCC/reconciler-log-{timestamp}.csv`.

### Known limitations

- No transitive closure — `ingredients` stores direct children only. A recipe's full atomic-ingredient flat list is derivable by traversing, not stored.
- "Label-name" references (e.g. `jett puff creme (...)` → ingredient *Marshmallow fluff*) currently fall through to step 4 and create a duplicate stub. Would need a `display_name` field on the ingredient pod + a fifth resolver step to handle cleanly. Not built yet.
- If the same ingredient has different label declarations in different recipes, the first occurrence's decomposition wins; later ones are skipped.

---

### Open before I start writing code

1. Sign-off on the phase ordering — is shipping the mapper-only diagnostic at end of phase C worth it, or do you want phases C+D bundled as one push?
2. The placeholder-detection rule. I have "Price = 1.00 AND Purchase Amount = 1" as the heuristic. Should I also flag rows where `Cost = 1.00` even if `Purchase Amount > 1`, since recipes' `Cost` column doesn't have a parallel `Purchase Amount` column?
3. Confirmation on the ingredient pod schema additions actually landing on `ingredient` (not `recipe`) so phase F isn't blocked when we get there.

---

## 14. Recipe ingredient-quantity import (Markdown)

A second, independent flow under the same *Scoop → RCC Import* page. Where the
CSV importer ([§6](#6-field-mappings)) updates recipe/ingredient **cost** data,
this flow imports the **per-ingredient quantities** for each recipe from RCC's
Markdown recipe export and writes them as structured `recipe-ingredient-ma`
rows. The page picks the flow by uploaded file extension: `.csv` → cost
importer, `.md` → quantities importer.

### Why Markdown

RCC can export the recipe book as `.docx`, `.odt`, `.rtf`, or `.md`. **Markdown
is the canonical input** for this importer:

- It needs **no parsing library** — `.rtf` is ~24 MB of control words, and
  `.docx`/`.odt` would require walking zipped table XML. The repo has no
  document-parsing dependency and `CLAUDE.md` forbids adding one without asking.
- Its tables are literal `|`-delimited text — unambiguous to parse.

Always export `.md`. The other formats are not accepted by this flow.

### Pod schema (already configured)

Confirmed against `data-exports/pods-package-2026-06-07.json`:

| Pod | Field | Type | Notes |
|---|---|---|---|
| `recipe` | `ingredient_maps` | Relationship (multi) → `recipe-ingredient-ma` | the recipe's list of map rows (field id 8650) |
| `recipe-ingredient-ma` | `ingredient` | Relationship (single) → `ingredient` | **no auto-create** (`pick_allow_add_new: 0`) |
| `recipe-ingredient-ma` | `quantity` | Number (3 dp) | |
| `recipe-ingredient-ma` | `unit_vol` | Pick (custom) | `pinch, tsp, Tbl, c, oz, pt, qt, gal, ml, L, other` |
| `recipe-ingredient-ma` | `unit_weight` | Pick (custom) | `oz, lb, g, kg` |

> **Pod name gotcha:** the join pod's post-type name is **`recipe-ingredient-ma`**
> — WordPress truncates post-type names to 20 chars, so it is *not*
> `recipe_ingredient_map`. `scoop_rcc_map_pod_name()` is the single source.

### Files

```
includes/rcc-import/
├── _config.php              # + map pod name, recipe link field, unit vocab + scoop_rcc_normalize_unit()
├── quantities.php           # Markdown parser → per-recipe {name, qty, unit} items
├── quantities-importer.php  # match recipes/ingredients, create map rows, link to recipe
└── _ui.php                  # + .md upload, quantities review/preview/commit/results screens
```

### Two Markdown layouts

The same export mixes two layouts (it mirrors how each recipe was authored), so
the parser handles both, reducing each to the same `{name, qty, qty_raw, unit}`
item list:

- **Table-style** (majority): a `| Ingredient | Quantity |` table; each data
  row is `| <name> | <number> <unit> |`. Parsed by splitting on `|`.
- **Run-style** (a handful of older recipes): a single free-text run
  `name qty unit name qty unit …` after an `**Ingredient Quantity**` header.
  Tokenized by anchoring on `<number> <known-unit>` pairs from
  `scoop_rcc_unit_vocab()`; everything since the previous unit is the next name.
  Validated against the real sample (names with commas, parens, `%`, fractions,
  decimals, and count units all tokenize cleanly).

Recipes are delimited by the `Recipe Summary` header (one per recipe); the title
is the non-empty line just above it. The quantities section runs from
`Ingredient Quantities` to `Preparation Method`.

RCC also emits auto-generated **`(Scaled x N)`** variants of recipes (e.g.
"Apple Compote (Scaled x 2450)"). These are dropped at parse time by
`scoop_rcc_is_scaled_title()` and never enter the import; the review screen
reports how many were excluded.

### Preparation Method → `instructions`

The **Preparation Method** section of each recipe is lifted into the recipe
pod's `instructions` field (a `longtext` column). `scoop_rcc_extract_prep()`
unescapes the Markdown (`1\.` → `1.`), drops table/heading noise, collapses
blank runs, and treats RCC's "No preparation method defined." placeholder as
empty. Instructions are written **independently of the ingredient maps**: a
matched recipe gets its instructions updated even when its maps are skipped
(e.g. "already populated"), and even when it has no quantity table at all. The
write only happens when the parsed text differs from what's already stored, so
empty/unchanged prep never clobbers existing instructions. The review and
results screens report the instruction counts.

### Unit normalization

`scoop_rcc_normalize_unit()` maps the export's unit token to one of the pod's
two pick fields:

- Weight (`g, gram(s), kg, oz, lb(s), pound(s)`) → `unit_weight`.
- Volume (`pinch, tsp, tbsp/Tbl, c/cup(s), pt, qt(s), gal, ml, l/L, …`) → `unit_vol`.
- **`oz` is weight** (these recipes weigh ounces far more than they pour fluid
  ounces); fluid ounces must arrive as `floz`.
- **`other` is the catch-all**: any present-but-untranslatable token (`each`,
  `dash`, `mg`, `floz`, …) is stored as `unit_vol = 'other'`, with a per-row
  warning in review. A line with no unit at all leaves both unit fields blank.

The raw token is always preserved in the map row's title regardless of how it
normalized, so nothing is lost.

### Line matching (ingredient or sub-recipe)

Each ingredient line resolves to a pod target via `scoop_rcc_resolve_line()`,
trying the most specific match first:

1. full name → **ingredient**
2. full name → **recipe** (a *sub-recipe*, e.g. "Cheesecake" used inside another recipe)
3. pre-comma base → ingredient
4. pre-comma base → recipe

Each step also tries **singular/plural variants** (`"Granny Smith Apples"` →
`Granny Smith Apple`; `berries`→`berry`, `tomatoes`→`tomato`). The **pre-comma**
fallback drops RCC's prep hints (`"Bananas, Roasted,pureed"` → `Bananas`).
Variants are validated against the live pod index, so over-generating forms is
harmless. Any non-exact match (plural, pre-comma, or both) is flagged with a
per-row note for review.

A line that matches a **recipe** rather than an ingredient is written to the
map row's sub-recipe field instead of `ingredient`. That field was added after
the 2026-06-07 package export, so its machine name is resolved at runtime from
the live Pods config by `scoop_rcc_map_subrecipe_field()` (trying `sub-recipe`
first, matching this site's hyphenated naming — cf. the `sub-recipes` field on
the recipe pod). If the field doesn't exist, sub-recipe rows fail with a clear
error rather than writing to a phantom column.

Ingredients whose real name needs a comma should be renamed in the DB to a
comma-free form (e.g. `"Peanut Butter Cups (mini)"`) so the full match wins and
the fallback never fires.

### Workflow

Same four-screen, transient-backed, review-first shape as the CSV flow
(`state['mode'] = 'quantities'`):

1. **Upload** — `.md` file, parsed in memory.
2. **Review** — per recipe: title-match status, format, existing-map count, and
   a table of each ingredient line (pod match, quantity, unit → target field,
   warnings). Controls: per-recipe **skip**; per-recipe **replace** (only when
   the recipe already has maps); a global **create missing ingredients as
   stubs** (off by default, since the `ingredient` field disallows auto-create —
   when off, unmatched lines are skipped and reported).
3. **Preview** — counts of recipes/rows that will be created, then a single
   confirm checkbox.
4. **Commit / results** — per ingredient line, `pods('recipe-ingredient-ma')
   ->add()` a map row (created as `publish`), then `pods('recipe', $id)
   ->save(['ingredient_maps' => $ids])`, verified against `wp_podsrel`. Each map
   row's title is prefixed with the owning recipe's app ID so all rows for one
   recipe share that prefix — e.g. `8416 Cocoa Powder — 1/4 cups`. Results show
   recipes done, rows created/deleted, instructions written, ingredients
   created, items skipped, and errors.

### Idempotency

A recipe that already has `ingredient_maps` is **skipped by default** so
re-imports don't duplicate. Ticking **replace** deletes the recipe's existing
map rows (`wp_delete_post`) before recreating them.

### Deliberately out of scope (v1)

- Only `.md` is accepted; no `.docx`/`.odt`/`.rtf` parsing.
- Ingredient matching is exact normalized-title only (same normalizer as the
  reconciler, [§13](#13-relation-reconciler)); near-title fuzzy matching is not
  applied to ingredient lines.
- No unit conversion — the raw unit is mapped to a pick value, not converted to
  a common basis (that's the deferred normalization goal in [§11](#11-open-items--tbd)).
