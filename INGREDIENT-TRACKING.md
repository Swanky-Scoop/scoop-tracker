# Ingredient Tracking — Working Notes

**Branch:** `worktree-ingredient-tracking` (worktree at `.claude/worktrees/ingredient-tracking`), based on `main` @ `aa9a37b` ("Shift report and DOM clean-up for doc").

Read this file first on this branch. It captures two features deliberately
deferred out of a longer main-branch session about generalizing
`assets/ui/shift-report-form.js` into a reusable form builder (for Shift
Report + a new Kitchen Report + future forms) — that generalization work
stays on `main`/its own thread, not this branch. This branch is specifically
for the two ideas below, neither of which has any code written yet.

## Why these two are split out here

Mid-planning the Kitchen Report field list, the user flagged that two of its
questions aren't really "how does the form render this field" problems —
they're real features in their own right:

1. Tracking **sub-ingredient production** — cookies, brownies, etc. baked as
   inclusions for flavors that need them (e.g. Cookie Monster, Death By
   Chocolate). The Kitchen Report's Google Form field "Recipes completed
   (Name/Amount)" partly captured this informally, alongside actual
   ice-cream-batch records (now superseded — see below) and unrelated
   kitchen-task narrative that doesn't belong here at all.
2. A **standing shopping list**, fed by "running low" signals from both
   Shift Report (`supplies_low`) and Kitchen Report (supplies + ingredients
   running low, once that field exists).

## Feature 1: sub-ingredient production log

**Shape, roughly:** structurally similar to the existing **Batch** feature
(`[scoop_grid type="Batch"]`, `includes/hooks/batch-tub.php`,
`includes/_config.php`'s `'Batch'` entry) — but for `ingredient` records
instead of `flavor` records. Batch is the direct precedent to study first:
who made it, which entity, how much, when, plus whatever cascading-write
hooks it needs.

**What prompted this:** the user is *already* replacing the ice-cream-batch
portion of Kitchen Report's "Recipes completed" field with direct entry via
the existing Batch GUI (as batches happen, not summarized after the fact at
end of shift) — that part of the old Google Form field is fully resolved and
out of scope here. What's left over from that same field, once the
ice-cream-batch part is pulled out, is entries like "Edible cc dough ×3",
"Baked FOH cookies", "Sage truffles ×2", "Chopped fresh bettercreme" — real
production events for things that go *into* flavors' recipes, not flavors
themselves.

**Open, not yet decided:**
- Does this need a genuinely new Pods pod (e.g. `ingredient_production` or
  similar — post_type, relationship to `ingredient`, amount, date, maybe
  location), or can it reuse/extend something that already exists?
- Is *every* item in that leftover list actually ingredient-production (cake
  layers, deco work, "chopping pecans" showed up in the real sample data
  too — is that in scope, or does it stay narrative/out of structured
  tracking)? The real sample data (see companion notes below) was a genuine
  mix of ice-cream-batch-like entries and general kitchen-task narrative
  that may not all belong in one structured entity.
- Does this connect to the existing recipe/ingredient/cost chain (CLAUDE.md:
  "Flavors are linked to recipes, which reference ingredients") — e.g.
  should producing a batch of cookie dough actually *consume* ingredient
  stock, mirroring how a flavor batch works? Or is this simpler — just a
  production log with no inventory-deduction side effects (at least
  initially)?

**Relevant existing precedent to read before designing:**
- `includes/hooks/batch-tub.php` — the whole batch→tub cascading-creation
  flow, including the direct-SQL bulk-insert fast path and its
  `pods_api_pre_save_pod_item_batch` hook for title generation.
- `includes/_config.php`'s `'Batch'` route entry (`mode: 'create'`,
  `pod_name: 'batch'`) and `assets/models/*` for how a "create" grid type
  (as opposed to an "update" one like FlavorTub) is wired end to end.
- CLAUDE.md's "Ingredient pricing — known data quality issues" section —
  ingredient/recipe cost data is already known-unreliable; a new
  production-log feature should not assume clean unit data exists yet.

## Feature 2: standing shopping list

**Not designed at all yet** — no shape, no pod, nothing. What's established:
it should be *fed by* the low-stock-picker signals both forms will capture
(Shift Report's existing `supplies_low`; Kitchen Report's forthcoming
supplies + ingredients low-stock pickers, built on `main` as part of the
form-builder generalization work), not re-invent its own data entry. The
actual "shopping list" entity — presumably checkable/manageable over time,
maybe with quantity/vendor info given CLAUDE.md's vendor notes
(Webstaurant / Chef's Warehouse / US Foods CHEFSTORE, no vendor APIs, manual
pricing only) — needs its own design pass from scratch.

**Open questions, none discussed yet:**
- Does an item get added to the list automatically the moment a form
  reports it "running low," or does that require a human confirmation step?
- Is this a real Pods CPT (`shopping_list_item` or similar) with its own
  admin GUI/grid, or something simpler?
- How does an item get marked "bought"/removed — another form? A grid with
  a delete/checkbox action, matching e.g. BatchHistory's delete-column
  pattern (`assets/models/batch-history-grid-model.js`)?
- Does it need to dedupe against the *same* item being flagged low by
  multiple shift reports in a row before it's actually restocked?

## Companion context (not duplicated here, from the main-branch session)

- The "sharpen data clarity" design rule that came out of mapping Shift
  Report's original Google Form headers against its real Pods fields:
  structure a field only where the structure pays off later (queryable,
  relatable, actionable) — `flavors_changed`/`supplies_low`/`cake_orders`
  got upgraded from loose form questions to real relationships;
  `positive_feedback`/`customer_issues`/`notes_for_tomorrow` stayed plain
  text because there's no entity behind them worth building. Apply the same
  test when deciding the sub-ingredient log's exact shape.
- Kitchen Report's real sample CSV data (4 rows) is what revealed the
  ice-cream-batch vs. bakery-task mix in "Recipes completed" in the first
  place — worth re-requesting from the user if design work here needs it
  again, rather than assuming the shape from the header text alone.
- `supply`/`use` pods and their grouped-picker pattern
  (`supplies_low` on `shift_report`) are the direct precedent for whatever
  the shopping list's "what's actually low right now" view ends up looking
  like.
