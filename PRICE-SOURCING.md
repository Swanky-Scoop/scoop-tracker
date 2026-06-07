# PRICE-SOURCING.md

Strategy and implementation guidance for gathering, tracking, and maintaining accurate core ingredient pricing at Swanky Scoop.

---

## 1. Core ingredient cost sources

### Ice cream bases

Each base type should have a single identified provider and a current price/unit on file. These are the highest-leverage ingredients — a wrong base price cascades into every flavor that uses it.

| Base | Provider | Price/unit | Unit | Last verified | Notes |
|------|----------|------------|------|---------------|-------|
| Dairy | *[identify]* | *[price]* | *[unit]* | *[date]* | |
| Oat | *[identify]* | *[price]* | *[unit]* | *[date]* | |
| Coconut | *[identify]* | *[price]* | *[unit]* | *[date]* | |
| Pea | *[identify]* | *[price]* | *[unit]* | *[date]* | |

> **Sorbet has no base.** Do not assign or expect a base ingredient for sorbet flavors.

### Vanilla

Several flavors use vanilla purchased directly from the vendor (not through a distributor). Exact vendor and SKU/price should be recorded here once identified.

| Vendor | SKU | Price/unit | Unit | Last verified |
|--------|-----|------------|------|---------------|
| *[identify]* | | | | |

### Cocoa (for chocolate flavors)

Sourced from either Webstaurant or Chef's Warehouse depending on availability and negotiated pricing. Because the source alternates, there is no single fixed price — both should be tracked.

| Vendor | SKU | Price/unit | Unit | Last verified | Notes |
|--------|-----|------------|------|---------------|-------|
| Webstaurant | | | | | |
| Chef's Warehouse | | | | | Negotiated price — verify before assuming |

---

## 2. Vendors

| Vendor | Type | Notes |
|--------|------|-------|
| Webstaurant | Online | No API — manual price lookup only |
| Chef's Warehouse | Distributor | Negotiated pricing — prices not public |
| US Foods CHEFSTORE | Local (Bothell WA) | Walk-in / local pricing may differ from online |

**No vendor APIs exist.** Do not pursue automated price scraping or API-based price feeds. All pricing is manual entry.

---

## 3. Current data quality

Ingredient pricing was entered manually in a single pass (by Bonnie) and is known to be unreliable. Key failure modes:

- **Unit mismatches** are the most common cause of wildly wrong price/unit figures. Example: price entered as $/oz but unit stored as lb → 16× error.
- **Order-of-magnitude errors** almost always indicate a unit problem, not a value problem. Fix the unit first.
- **Missing units** produce incalculable or zero prices — treat as highest priority to resolve.
- **Cascading errors**: a wrong base price or a wrong vanilla price propagates into every flavor recipe that references it.

### Sanity bounds

| Entity | Reasonable range | Flag if |
|--------|-----------------|---------|
| Finished ice cream (any base) | $10–$30 / gallon | < $1 or > $100 / gallon |
| Cocoa powder | ~$5–$20 / lb | < $0.50 or > $200 / lb |
| Vanilla extract | ~$20–$80 / liter | < $2 or > $500 / liter |

These are starting heuristics — refine bounds as real prices are confirmed.

---

## 4. Planned features

### Feature 1: Ingredient pricing error report (do this first)

**Goal:** Surface the worst data quality problems before attempting any corrections.

**Output:** A CSV with one row per ingredient, columns:
- `ingredient_id`
- `ingredient_name`
- `wp_admin_url` (direct link to the Pods edit page)
- `stored_unit`
- `stored_price`
- `calculated_price_per_liter` (normalize everything to a common unit for comparison)
- `flag` — one of: `missing_unit`, `unknown_unit`, `order_of_magnitude`, `out_of_sanity_bounds`, `ok`
- `likely_issue` — human-readable note: e.g. "price is per oz but unit stored as lb"

**Detection logic (in priority order):**
1. Unit is null or unrecognized → `missing_unit`
2. Price/unit is zero or negative → `missing_unit` or `bad_value`
3. Normalized price is off by 10× or more vs. category median → `order_of_magnitude`
4. Normalized price falls outside sanity bounds for its category → `out_of_sanity_bounds`

> **Do not auto-correct.** This report is read-only. Surface for human review only.

---

### Feature 2: Flavor cost error report (do this second)

**Goal:** Identify flavors whose calculated cost/liter is unreasonable, and trace the likely cause back to a specific ingredient.

**Output:** A CSV with one row per flavor, columns:
- `flavor_id`
- `flavor_name`
- `flavor_wp_admin_url`
- `recipe_id`
- `recipe_wp_admin_url`
- `calculated_cost_per_liter`
- `flag` — `ok`, `too_low`, `too_high`, `no_recipe`, `recipe_has_flagged_ingredients`
- `likely_cause` — e.g. "base ingredient dairy has missing unit", "vanilla price is 100× median"

**Sanity bounds:**
- Cost/liter < $1.00 → `too_low`
- Cost/liter > $50.00 → `too_high`
- No linked recipe → `no_recipe`

> Flavors with `no_recipe` or `recipe_has_flagged_ingredients` should be treated as blocked until upstream ingredient data is fixed.

---

### Feature 3: Price correction GUI (do this last)

**Goal:** A grid-based interface for reviewing and correcting mis-priced ingredients, integrated with the existing grid framework.

**Scope:**
- Read from the ingredient pricing error report (Feature 1) to pre-populate the queue
- Allow editing of `unit` and `price` fields per ingredient
- Show the before/after calculated impact on flavor cost/liter in real time
- POST corrections via the existing REST write pattern (`ScoopAPI.postJson`)
- Must follow the two-layer permission model — only administrators/editors may write

**Implementation notes:**
- This will be a new bundle-pattern grid type. Follow the checklist in `README.md § The two patterns`.
- The read-only analytics pattern is **not** appropriate here — this grid needs write access.
- Validate on TEST before any OPS writes. See `CLAUDE.md § Data repair policy`.

---

## 5. Ingredient → recipe → flavor connection audit

Some ingredients may not be linked to their recipes, and some recipes may not be linked to their flavors. Before running cost calculations, verify connection completeness.

**Audit query approach:**
- Find flavors with no linked recipe
- Find recipes with no linked flavor
- Find ingredients with no linked recipe
- Find ingredients that appear in recipes for a flavor category they don't belong to (e.g. a dairy base linked to a sorbet recipe)

> Any connection repairs must be validated on TEST first. Produce a dry-run report before writing to either environment. See `CLAUDE.md § Data repair policy`.

---

## 6. Open questions

- [ ] Exact provider and price for each base type (dairy, oat, coconut, pea)
- [ ] Vanilla direct vendor — name, SKU, current price
- [ ] Cocoa — current negotiated price at Chef's Warehouse
- [ ] What unit does the system store for base ingredients? (gallons? liters? lbs?)
- [ ] Are recipes linked to flavors via a Pods relationship field, or by a naming convention?
- [ ] What is the scoops-per-tub × cones-per-scoop conversion factor? (referenced in README but not yet pinned down — needed for production forecasting)
