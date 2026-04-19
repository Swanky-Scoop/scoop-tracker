# RCC → OPS Ingredient Triage

Living source-of-truth for the 319-row RCC → OPS pricing cascade, Phase 1.
Each row in `ingredients-2026-04-17.tsv` represents one RCC ingredient on its
journey from a purchase price (`$/lb`, `$/floz`, `$/each`, ...) to a normalized
`$/gram` value ready to be written back to OPS's `price_gram` field.

This document is **the triage state**. The GitHub Project built on top of it
is a view. When a row changes, the TSV changes first.

## Files

- `ingredients-2026-04-17.tsv` — 319 data rows + 1 header row = 320 lines.
  UTF-8, no BOM, Unix (LF) line endings. Tab-separated.
- `flavors-2026-04-19.tsv` — 221 data rows + 1 header row = 222 lines.
  UTF-8, no BOM, Unix (LF) line endings. Tab-separated.
- `README.md` — this file.

## Column definitions

| Column | Meaning |
|--------|---------|
| `rcc_id` | RCC ingredient ID (numeric). |
| `name` | RCC ingredient name (as shown in RCC's ingredient list). |
| `rcc_price` | Dollar price per one purchase unit as read from RCC. If RCC records a pack size (e.g. `$1.00/15 oz`), this column shows the normalized $/unit (`$0.0667/oz`); the original pack size is preserved in `notes`. |
| `rcc_unit` | Purchase unit string from RCC (`g`, `oz`, `lb`, `Kg`, `kg`, `floz`, `gal`, `qt`, `pt`, `cup`, `tbsp`, `tsp`, `each`, `dozen`, ...). |
| `tier` | Confidence tier `0`–`5` (see ladder below). |
| `density_source` | The conversion assumption used when volume → mass or count → mass is required. Blank for pure mass conversions (lb/oz/Kg/g), Tier 5 rows (already in $/g), and Tier 0 rows (no cost data). |
| `target_unit` | Always `g` for Phase 1 (ingredient table targets $/gram). |
| `confidence` | Short-form of `tier` (`T5`, `T4`, `T3`, `T1`, `T0`) for sort/filter convenience in spreadsheet-style tools. Redundant with `tier`. |
| `status` | Triage state, one of the enum below. |
| `notes` | Free-text one-liner: computed `$/gram` when available, RCC source row, flag context, pack-size hint if `qty ≠ 1`, plus any per-row caveat. |

## Tier ladder (0–5)

- **Tier 5** — clean $/g direct (no assumption chain). RCC records the price literally per gram.
- **Tier 4** — standard conversion (density-table-based). Includes both pure mass unit conversions (`lb`, `oz`, `Kg`, `kg` → `g` via fixed constants) and volume conversions where RCC provides an ingredient-specific converter row (e.g. "1 cup = 8.4 oz cream" giving a derived density).
- **Tier 3** — stated assumption. Volume unit with no RCC-provided converter, so a name-pattern density rule is applied (oil = 0.92, dairy = 1.03, honey/syrup = 1.33–1.42, juice = 1.04, extract/flavor = 0.88, water-like default = 1.00). Count-based units with standard reference weights (egg = 57 g, lemon = 65 g, medium apple = 180 g, ...) also land here.
- **Tier 2** — wholesale-source estimate. Zero rows in Phase 1 (every RCC ingredient had either a supplier row or an inline Purchase Details + Price). Tier 2 methodology is reserved for items not in RCC at all.
- **Tier 1** — pure estimate, flagged. Count-based "each" / "dozen" SKUs where no standard per-item weight is known (Cabernet bottle, Chai tea bag, Glazed donut, Zucchini, Peppercorns, ...). Needs Gus's per-item weight before `$/g` is computable.
- **Tier 0** — unresolvable data. RCC explicitly records `$0.00` (Water, Apple Puree, Kiwi Puree, Pear Puree, white peach puree, Buttermilk Powder, chickpeas/aquafaba). Gus decision needed per row.

## `status` enum meaning

- **`PLACEHOLDER-SUSPECTED`** (157 rows, 49.2%) — matches the "1 × unit @ $1.00" stub shape (see next section). The conversion math is clean, but the upstream RCC price is not trustworthy. Do not write these to OPS until Gus confirms the price is real or RCC is updated.
- **`NEEDS-GUS-WEIGHT`** — count-based SKU with no standard reference weight. Need a gram-per-each (or per-dozen) from Gus before a `$/g` can be computed. Currently only Chai Tea (bagged); other NEEDS-GUS-WEIGHT rows are shadowed by `PLACEHOLDER-SUSPECTED` because their RCC price is also `$1.00` stub.
- **`ZERO-PRICE`** — RCC carries `$0.00`. For Water (ID 482245) this is intentional upstream; for the empty-puree / powder rows it's more likely missing data. Each needs Gus's call. (7 rows)
- **`NEEDS-REPRICE`** — the computed `$/g` lands well above typical wholesale food-ingredient range (>`$0.30/g`), suggesting the RCC price needs verification. Currently only Spirulina Powder ($0.48/g at 50 g @ $23.95 from Amazon retail — likely correct for spirulina but flagged for Gus spot-check).
- **`OK`** — no flags. Conversion chain is clean and the derived `$/g` is inside expected ranges. Safe to use for OPS write once Gate 1 is approved. (153 rows)

## `PLACEHOLDER-SUSPECTED` convention

157 of 319 rows (49.2%) share a uniform shape: exactly `1 × <unit> @ $1.00` as
the RCC entry. This pattern is statistically implausible as organic pricing —
real supplier prices don't cluster at one dollar across `g`, `lb`, `oz`, `Kg`,
`floz`, `tsp`, `each`, `dozen`, and `gal` all at once. The most likely
explanation: these are **data-entry placeholders** where someone chose a unit
in RCC but never came back to enter the real supplier price.

When the placeholder unit happens to be `g`, the `$/gram` reads as exactly
`$1.00/g → $1,000/kg`, which is the class of outlier that has shown up in
downstream recipe costs (Black Forest Cheesecake at $12k/tub, Hot Honey Peach
at $500k/pint). The data-quality problem is **structural, not per-row**: these
157 rows need a single bulk decision from Gus ("update RCC" vs. "skip OPS write"
vs. "case-by-case") rather than 157 individual corrections.

Phase 1 OPS write pass default: **skip `PLACEHOLDER-SUSPECTED` rows** until
Gus confirms the price is real or RCC is updated with a supplier entry.

## Update protocol

Rows evolve as Gus confirms prices, shares per-item weights, or updates RCC.
Two supported workflows:

1. **Direct commit to `triage/` branch.** Open a PR against this file, edit
   the TSV row(s) in place, land. Best for bulk updates (e.g. Gus uploads a
   replacement TSV with real prices for the 157 placeholder rows).
2. **Via the GitHub Project UI** (once set up; see "Relationship to GitHub
   Project" below). Status changes in the Project board sync back to this TSV
   through a simple commit loop — the Project is the view, the TSV is the
   authoritative record.

When a row is resolved (placeholder confirmed, weight provided, zero-price
adjudicated, outlier verified), update its `status` column to `OK` and
annotate the resolution in `notes`. Keep historical context in `notes` rather
than deleting it — future re-triage benefits from seeing prior assumptions.

## Relationship to GitHub Project

Gus's forthcoming GitHub Project (user-level, to be created via
`gh project create`) will import each TSV row as a Project item. The Project
provides kanban-style views, custom field filtering (by `tier`, by `status`),
and per-row comment threads. But the **TSV remains source-of-truth**: Project
items are regenerable from the TSV; the reverse is not true.

Sequence:

1. This PR lands → TSV + README live under `triage/`.
2. Gus creates user-level Project via `gh project create --owner gusreiber --title "RCC→OPS Phase 1 triage"`.
3. Follow-up PR (separate, blocked on step 2) seeds issues for all 319 rows and adds them to the Project, per Gus's Option B granularity choice (max granularity, issue per row).
4. Ongoing triage: as rows resolve, both the Project and the TSV update in lockstep.

## Source of truth

The underlying analysis (tier assignment, assumption strings, flag derivation)
lives in Webel's internal memo dated 2026-04-19. This TSV is a serialization
of that analysis into a repo-hosted, diff-able, spreadsheet-friendly form.
Any methodology questions (density constants, tier-boundary decisions, flag
thresholds) route back to the original analysis.

See the associated PR description for Gate-1 questions that need Gus's answer
before the Phase 1 OPS write pass unblocks.

---

# Flavor → Recipe Inventory

Parallel read-side deliverable to the ingredient triage above. Where the
ingredient table answers *"what does each RCC ingredient cost per gram?"*,
the flavor table answers *"which OPS Recipe backs each OPS Flavor, and how
confident are we in that link?"*

Source-of-truth analysis run 2026-04-19 against the live OPS REST API
(`ops.swankyscoop.net/wp-json/wp/v2`, read-only, no auth required). The
OPS **Flavor** CPT (`rest_base: flavor`, 221 published items) has **no
structured recipe-link field** exposed to REST — matching is entirely
name-based. Confidence tier 5 (deterministic field link) is therefore
unreachable from REST-visible data; top tier in this table is 4 (exact
name after normalization).

## File

- `flavors-2026-04-19.tsv` — 221 data rows + 1 header = 222 lines. UTF-8,
  no BOM, Unix (LF) line endings. Tab-separated.

## Column definitions (flavor TSV)

| Column | Meaning |
|--------|---------|
| `flavor_id` | OPS Flavor post ID (numeric). |
| `flavor_name` | Flavor title as rendered in OPS (HTML entities decoded). |
| `proposed_recipe_id` | OPS Recipe post ID of the best-fit match. Blank if no credible match. |
| `proposed_recipe_name` | OPS Recipe title (HTML entities decoded). Blank if no match. |
| `match_source` | How the match was derived. See enum below. |
| `confidence` | 0–5 confidence tier (see ladder below). |
| `recipe_class` | Best-guess class of the matched recipe: `icecream`, `sorbet`, `sauce`, `topping`, `base`, `mix-in`, `other`, `unknown`. Blank if no recipe matched. |
| `status` | Triage state, one of the enum below. |
| `notes` | One-line free text: close-runner alts (`alts=Name#id(s=0.XX)...`), style mismatches (sorbet-flavor vs icecream-recipe), vegan-base annotations, or `no-credible-match` context. |

## `match_source` enum

- **`direct-field`** — Flavor post has a structured field (e.g. `recipe_id`, `related_recipe`) pointing at the Recipe. *Not currently achievable from REST* — no such field exists on the Flavor CPT as exposed by the OPS REST API. Reserved for a future scan path that inspects admin-only ACF fields or the SQL layer.
- **`name-exact`** — Flavor title equals Recipe title after normalization (HTML-entity decode, strip parenthetical base markers like `(pea)`/`(oat)`/`(coco)`/`(sorbet)`/`(v)`/`(gf)`, strip `Vegan`/`V/GF` recipe prefixes, strip trailing `Icecream`/`Ice Cream`/`Sorbet` style tag, lowercase, collapse whitespace).
- **`name-fuzzy`** — Names differ but Levenshtein similarity ≥ 0.70 on the normalized cores, or Jaccard/overlap-coefficient on token sets crosses the fuzzy threshold.
- **`name-keyword`** — Partial token overlap only; names clearly differ but share enough lexical content to merit a Gus spot-check.
- **`none`** — No recipe meets even the keyword threshold.

## Confidence ladder (flavor → recipe adaptation)

- **Tier 5** — deterministic field link (e.g. `flavor.recipe_id → recipe.id`). **Not currently reachable** via REST; no rows will carry tier 5 until (a) the OPS schema exposes such a field or (b) a deeper scan (admin / DB) surfaces one.
- **Tier 4** — exact name match after normalization. Final score ≥ 0.98.
- **Tier 3** — fuzzy high. Normalized-core similarity ≥ 0.85 and < 0.98.
- **Tier 2** — fuzzy medium. Similarity 0.70 – 0.85, or substring / full-token-coverage with balanced size.
- **Tier 1** — keyword only. Similarity 0.40 – 0.70 or very partial overlap; credible seed but not a confident match.
- **Tier 0** — no match. No recipe crosses the 0.40 floor.

The blended score combines Levenshtein similarity on normalized cores with
Jaccard similarity on tokenized cores, plus small style bonuses (sorbet-to-sorbet,
vegan-base-to-vegan-recipe) and style penalties (flavor-is-sorbet vs
recipe-is-icecream). Close competitors within 0.02 of the top score are
tie-broken by style alignment (sorbet/icecream marker, vegan-base marker,
specificity).

## `status` enum

- **`CLEAR-MATCH`** — confidence 4. Safe to treat as the authoritative
  flavor↔recipe pair. Write-side pod update (Track J tooling) can proceed
  without per-row Gus review.
- **`LIKELY-MATCH`** — confidence 2 or 3. Probably right; Gus spot-check
  recommended before write-side.
- **`AMBIGUOUS`** — a close competitor is within 0.05 of the top score.
  Two or more recipes are plausible — Gus picks the right one. The
  `notes` column lists alternates with their scores.
- **`NO-RECIPE`** — no credible match (top score below 0.40). Likely a
  flavor that has never been backed by a recipe, or is a test/placeholder
  entry.
- **`NEEDS-GUS-CALL`** — confidence 1. There's a plausible-looking top
  match but it isn't confident enough to call `LIKELY-MATCH`. Gus needs
  to either confirm, substitute, or mark the flavor as recipe-less.

## Distribution (this pass)

| Status | Rows | % |
|--------|------|---|
| `CLEAR-MATCH` | 145 | 65.6% |
| `LIKELY-MATCH` | 18 | 8.1% |
| `AMBIGUOUS` | 52 | 23.5% |
| `NO-RECIPE` | 2 | 0.9% |
| `NEEDS-GUS-CALL` | 4 | 1.8% |
| **Total** | **221** | 100% |

Gus's prior-estimate in the 2026-04-19 email was ~120 flavors (20 no-recipe
/ 100 clear / remainder ambiguous). The actual OPS Flavor CPT row count is
**221**, ~1.84× larger — variance called out explicitly so the numeric gap
doesn't land silently.

## Recipe-class distribution (matched rows)

| Class | Rows |
|-------|------|
| `icecream` | 184 |
| `sorbet` | 29 |
| `sauce` | 1 |
| `mix-in` | 2 |
| `unknown` | 3 |
| *(blank — NO-RECIPE)* | 2 |

`unknown` is used for low-confidence matches (tier 1) where the matched
recipe title doesn't carry an obvious class marker and the flavor context
isn't strong enough to infer one. Higher-tier matches with bare-name
recipes (e.g. `"Vanilla"` rather than `"Vanilla Icecream"`) fall back to
`icecream` (or `sorbet`, if the flavor carries a sorbet marker).

## Update protocol (flavor TSV)

Same shape as the ingredient TSV above:

1. **Direct commit.** Open a PR against `triage/flavors-2026-04-19.tsv`,
   edit row(s), land.
2. **Status-column flow.** As Gus resolves AMBIGUOUS / NEEDS-GUS-CALL /
   NO-RECIPE rows, update `status` to `CLEAR-MATCH` (or the chosen state)
   and annotate the decision in `notes`. Keep historical context in
   `notes` rather than deleting it.

When Gus picks a winner from an AMBIGUOUS row's `alts=...` list, update
`proposed_recipe_id` + `proposed_recipe_name` to the chosen row, drop the
`alts=...` annotation from `notes` (or move it to an archival suffix like
`prior-alts=...`), and bump `match_source` to the appropriate tier
(usually `name-exact` with confidence 4 once he confirms).

## Source of truth

Webel internal analysis 2026-04-19 (REST scan + name-based matching via
Levenshtein + Jaccard + style-alignment tie-break). The OPS REST API
response bytes are the input; this TSV is the serialization of the
matching result into a repo-hosted, diff-able, spreadsheet-friendly form.

Methodology questions route back to the original analysis. The scoring
cut-points (0.40 / 0.70 / 0.85 / 0.98) are tunable — if Gus wants the
tier borders moved, the analysis re-runs cleanly and the TSV regenerates.

## Relationship to OPS write-side

Write-side (pod updates to OPS Flavor posts setting a `recipe_id` or
equivalent link field, plus a `recipe_class` tag per Gus's 2026-04-19
request) is **not part of this PR**. That work is gated on the OPS
write-auth tooling track (Webel-side, separate node) and will land as a
distinct follow-up PR once the tooling is in place. Read-side landing
first gives Gus a reviewable source-of-truth without committing any
mutations against OPS.
