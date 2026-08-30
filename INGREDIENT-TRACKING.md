# Ingredient Tracking — Working Notes

**Branch:** `worktree-ingredient-tracking` (worktree at `.claude/worktrees/ingredient-tracking`), based on `main` @ `aa9a37b` ("Shift report and DOM clean-up for doc").

Read this file first on this branch.

## ⚡ STATUS AS OF 2026-08-30 — READ THIS FIRST (supersedes the 08-15 section below)

**Main has independently rebuilt most of this branch's infrastructure —
this branch is now mostly obsolete.** Since this branch diverged (at
`aa9a37b`), main added its own Task/RecipeCount/Prep implementation
(`72bfed7` "task stuff" and later commits), via a **different, further-along
UI approach**: a detail-view/edit pattern (`assets/ui/task-detail-view.js`,
`assets/ui/_detail-edit.js`) instead of this branch's grid-create-form +
bespoke `task-create-form.js`, plus a real `TaskComponentHistoryGridModel`
main built on its own (this branch's history-grid generalization was never
finished). Main also independently has: the `done`-guard on batch-tub
cascading creation, the `post_status` defaulting-to-draft fix (main's
version is more thorough — includes a `republish-tubs-ui.php` cleanup tool
for tubs stuck as drafts before the fix landed), the route-registration
warning fix, and "my tasks" filtering (client-side group-by-assignee in
`tasks-grid-model.js`, not a dedicated endpoint like this branch's
`/my-tasks`).

**Do not resume this branch's own code** (`_single-relation-count-grid-model.js`,
`task-create-form.js`, this branch's `/my-tasks` + `/kitchen-staff`
endpoints, its `Task`/`RecipeCount`/`Prep` route configs) — it's all
superseded by main's parallel implementation. If picking ingredient-tracking
work back up, branch fresh off current main rather than rebasing this one.

**What's still genuinely unbuilt anywhere** (main included) — this is the
real remaining scope:
- The **`kitchen_report`** pod itself and its form — the branch's original
  goal, entirely unstarted. Planned fields: `target`, `recipe_counts`,
  `base_counts`, `supplies_low`, `tasks`, `kitchen_visitors`, `notes`.
- **`base_pack`** pod (`base`, `count`, `cooling`, `report`) — no code, no
  spec entry, anywhere.
- **Feature 2, the standing shopping list** — no design work at all, on
  this branch or main.
- The `ingredients_low`-vs-consumption-tracking decision (see "How the plan
  actually diverged" below) — still the right call, still unimplemented
  (`recipe_count.done` × the recipe's ingredient list doesn't exist yet as
  actual consumption tracking).

**Priority framing (2026-08-30):** getting tasks to actual *completion* is
the gating priority for `kitchen_report` — the confirmation/completion flow
on assigned tasks (marking batch/recipe_count/prep sub-items done) needs to
work first, since `kitchen_report` mostly **is** that confirmation surface.
`kitchen_report` needs its own info fields (`supplies_low`, `base_counts`,
`kitchen_visitors`, `notes` — the ones with no task/sub-item precedent to
lean on), but the bulk of the form should be reviewing/confirming progress
or completion on assigned tasks, not a parallel data-entry surface next to
the task system. Design the completion flow against main's current
Task/RecipeCount/Prep/TaskComponentHistory shape, not this branch's.

---

## STATUS AS OF 2026-08-15 evening (historical — this branch's own now-superseded work)

Everything below this section (down to "Feature 1: sub-ingredient production
log") is the **original planning doc**, written before any code existed.
Substantial implementation has happened since — this section is the
up-to-date picture. The original two "features" framing is now partly
superseded (see "How the plan actually diverged" below) — the Pods schema
the user built and the client architecture direction from this session are
the current source of truth, not the original doc's guesses.

### Environment state
- Local WP's plugin junction (`Local Sites/swank-tracker/.../plugins/scoop_rest`)
  is pointed at **this worktree** (`.claude/worktrees/ingredient-tracking`) —
  swap tool: `.claude/tools/local-plugin-link.ps1` (see
  `[[local-plugin-symlink-swap]]` memory). Verify before assuming — it
  doesn't reset on its own.
- Local dev login: see `[[local-dev-login]]` memory (user-approved to store,
  local-only).
- **Known testing gotcha, hit repeatedly this session:** only `assets/app.js`
  gets `?ver=filemtime()` cache-busting (see `enqueue.php`). Every file it
  `import`s (all of `assets/models/*.js`, `assets/ui/*.js`,
  `assets/data/scoop-api.js`) does NOT — editing one of those and reloading
  the page can silently keep running the OLD cached copy, even across
  `about:blank` round-trips and new tabs (same browser profile shares HTTP
  cache). **Workaround:** dynamically import the specific file with a
  `?bust=<timestamp>` query string in `browser_evaluate` to get a genuinely
  fresh copy — see `[[eta-timer-immediate-feedback]]` memory for the first
  time this was hit. This is a real, recurring cost — worth fixing properly
  (extend the cache-busting scheme to child modules) if it keeps coming up,
  but nobody's asked for that yet.
- Test/scratch pages on local: **Production** page (id 8140, published,
  slug `/production/`) has
  `[scoop_grid type="Batch" history="true" location="935"]`,
  `[scoop_grid type="Task"]`, `BatchHistory`, `Cabinet`, `ItemPivot`.
  **tasks** page (id 15210, published, slug `/tasks/`) has
  `[scoop_grid type="Task"]` and a **stale** `[scoop_grid type="RecipeCount"
  task="15233" history="true"]` — task 15233 was a throwaway test task and
  is already deleted, so that shortcode currently shows nothing useful.
  Update the `task="..."` id to a real task before relying on it.

### Pods schema — built by the user directly in Pods admin (not in code)

Confirmed live on local via `pods_api()->load_field()`, not just read off
the admin screenshots:

- **`task`**: `recipe_counts` (multi → `recipe_count`), `batches` (multi →
  `batch` — **was briefly `single`, user changed it to `multi` mid-session**),
  `preps` (multi → `prep`), `other` (text), `target` (→ Users, single).
  `task.recipe_counts` / `task.batches` / `task.preps` are genuine Pods
  bidirectional (sister_id) pairs with `recipe_count.task` / `batch.task` /
  `prep.task` — setting the child's own `task` field is enough, Pods syncs
  the task's reverse list automatically, no follow-up write needed.
- **`recipe_count`**: `recipe` (→ recipe), `count`, `task` (→ task),
  `done` (bool), `report` (→ kitchen_report, unused so far).
- **`prep`**: `ingredient` (→ ingredient), `other` (text), `count`,
  `units` (→ unit), `task` (→ task), `done` (bool). User flagged this one
  may be worth simplifying (drop `other`) to match the 2-field
  batch/recipe_count shape once its own create+history grid gets built.
- **`base_pack`**: `base`, `count`, `cooling` (custom list, fridge/freezer),
  `report` (→ kitchen_report). **Not touched yet** — no client code, no
  entity spec.
- **`batch`** (pre-existing pod, extended): added `task` (→ task, single)
  and `done` (bool, new field, no default configured). Existing
  Batch-GUI-created batches never set either — confirmed via direct
  DB/field-config inspection that Pods resolves an *unset* `done` to the
  same `'0'` as an *explicit* false, which is why the guard below can't
  just check the resolved value.
- **`kitchen_report`**: `target`, `recipe_counts`, `base_counts`,
  `supplies_low` (→ supply), `tasks`, `kitchen_visitors` (custom list),
  `notes`. **Not touched by any code yet** — this is the branch's actual
  original goal and hasn't been started.

### What's actually been built (server)

All in this worktree, uncommitted (check `git status`):

- **`includes/hooks/kitchen-report.php`** (new file) — auto-title pre-save
  hooks for `prep`, `task`, `recipe_count`. Patterns:
  - `prep`: `"{other }{ingredient} {count} {unit} {date}"`
  - `task`: `"{other, trimmed to 8 words} ({target name|Unassigned}) {date}"`
  - `recipe_count`: `"{recipe} {count} (Task {task_id})"` — **deliberately
    no timestamp**, per explicit user request; task id instead, omitted
    entirely if no task is linked. Verified live: `"Runny Sauce 4 (Task
    15243)"` vs `"Runny Sauce 2"` with no task.
- **`includes/hooks/batch-tub.php`** — added a guard at the top of
  `scoop_create_tubs_for_new_batch()`: skips the tub-creation cascade only
  when `done` was **explicitly** part of *this* save's payload AND
  resolved falsy (checked via Pods' own `fields_active`, not the resolved
  value — see the reasoning above). Ordinary Batch-GUI saves (which never
  touch `done`) are completely unaffected — verified directly: a
  task-tracked batch saved with `done:false` created 0 tubs; the existing
  Batch GUI path still creates tubs normally.
- **`includes/_config.php`** — new `'Task'`, `'RecipeCount'`, `'Prep'`
  route entries, `mode: 'create'`, mirroring `'Batch'` exactly (minimal
  fields, `target: 'action'`).
- **`includes/_write_fields.php`** — `scoop_tasks_allowed_fields()`
  (`target`,`other`), `scoop_recipe_counts_allowed_fields()`
  (`recipe`,`count`,`task`), `scoop_preps_allowed_fields()`
  (`ingredient`,`other`,`count`,`units`,`task`); broadened
  `scoop_batches_allowed_fields()` to also allow `task`,`done` (ordinary
  Batch GUI never sends them, so this is additive-only). **Real bug fixed**
  in `scoop_create_pod_item()`: every pod created through it now defaults
  `post_status` to `publish` unless the caller set it explicitly — before
  this, `task`/`recipe_count`/`prep` rows silently saved as WordPress
  `draft`, which makes them invisible to Pods relationship queries (same
  class of bug this project has hit before for tubs/supply items).
- **`includes/_policy.php`** — `'Task'`, `'RecipeCount'`, `'Prep'` routes
  granted to `administrator` and `kitchen_manager` (task/sub-item
  *authoring* is a manager job — the wife, per the design discussion).
  Staffers (`ice_cream_maker`/`shift_lead`) do **not** have these grants
  yet — they'll need at least read access once the actual Kitchen Report
  form (task confirmation) gets built.
- **`includes/_specs.php`** — new entity specs: `recipe`, `ingredient`,
  `unit` (all minimal id+title, not writeable from this app), `recipe_count`
  (recipe, count, task [hidden], done). New bundle specs: `'Task'` (needs
  flavor/recipe/ingredient/unit — from the now-mostly-superseded
  TaskCreateForm work), `'RecipeCount'` (needs `recipe`),
  `'RecipeCountHistory'` (needs `recipe_count`,`recipe`).
- **`includes/rest.php`** — `scoop_kitchen_staff_handler()` (`GET
  /kitchen-staff`, role-filtered `get_users()` for target pickers — its own
  endpoint, not threaded through the Pods-only bundle pipeline, since WP
  Users aren't a Pods pod); `scoop_my_tasks_handler()` (`GET /my-tasks`,
  the current user's unassigned-or-mine task list with nested
  batches/recipe_counts/preps resolved — also its own endpoint, since the
  bundle's cache is a single shared-per-type transient with no per-user
  concept, and a personalized result can't safely ride it). Both verified
  live with real filtering (unassigned + mine shown, someone-else's task
  correctly excluded).
- **`includes/_routes.php`** — registered `/kitchen-staff`, `/my-tasks`.
  **Real pre-existing bug fixed**, unrelated to this branch's own new code:
  the generic per-type route-registration loop indexed
  `$cfg['path']`/`$cfg['methods']` unconditionally, but 6 existing types
  (`Popular`,`BatchHistory`,`ItemPivot`,`EmptiedLog`,`Flavors`,`Analytics`)
  are deliberately bundle-only with neither key set — this threw PHP
  warnings on **every single REST request** (confirmed on `/bundle` itself,
  which predates this branch entirely), and with this local site's
  `display_errors` on, that warning HTML silently corrupted every JSON
  response's body. Only ever tolerated because `res.json().catch(()=>null)`
  swallows the parse failure everywhere it's called. Fixed with a guard
  clause skipping config entries missing either key.
- **`includes/shortcode.php`** — new `task="..."` attribute (parallel to
  `location`), emits `data-task` on the host div. Generalized the `history`
  attribute's own doc comment off "Batch-only" phrasing.

### What's actually been built (client)

- **`assets/models/_single-relation-count-grid-model.js`** (new,
  underscored = abstract base) — the generalized "pick one relationship +
  a count, single blank row" create-grid shape, extracted after
  `RecipeCountGridModel` turned out identical to `BatchGridModel` except
  for one string (the relation field name). `BatchGridModel` and
  `RecipeCountGridModel` are now ~3-line subclasses naming their field.
  **Not yet done:** the *history*-grid half (`BatchHistoryGridModel` vs
  `RecipeCountHistoryGridModel`) is NOT generalized — they diverge more
  (date-range filter + delete action vs. fixed task-scope + no delete) —
  deliberately left alone until a third instance clarifies the common
  shape, same reasoning that held off generalizing the create side until
  RecipeCount existed to compare against Batch.
- **`assets/models/recipe-count-grid-model.js`**,
  **`recipe-count-history-grid-model.js`** — the second concrete
  create+history pair (after Batch/BatchHistory), built specifically to
  find the generalizable seam. History grid filters to one `task` (fixed
  at construction, no runtime switcher UI) instead of a date range.
- **`assets/models/_base-grid-model.js`** — added a generic fallback in
  `getOptions()`: any `this.domain[fieldKey]` array not already
  special-cased (state/location/use/flavor) gets the same `{key,label}`
  treatment — this is what `recipe` (and any future domain-backed picker)
  rides on for free.
- **`assets/data/scoop-api.js`** — generalized `_mountEmbeddedBatchHistory`
  → `_mountEmbeddedHistory(dom, grid, formCodec, historyType)`, driven by
  a new `HISTORY_TYPE_MAP` (`{Batch: 'BatchHistory', RecipeCount:
  'RecipeCountHistory'}`) instead of being hardcoded to Batch. Added
  `_resolveTask(dom)` (reads `data-task`, no hash-state cascade yet —
  simpler than `_resolveLocation`). `getModelsBom()` includes
  `Task`/`RecipeCount`/`RecipeCountHistory` now.
- **`assets/models/task-grid-model.js`**, **`assets/ui/task-create-form.js`**
  — a **bespoke, hand-built** "Add task" form (target select, description,
  repeatable batch/recipe-count/prep rows via hand-wired `FindIt`
  instances, sequential multi-endpoint POST orchestration on submit). This
  was built **before** the user redirected toward the Batch/BatchHistory
  generalized-grid pattern for RecipeCount — it still works (fully tested,
  including multi-batch rows and FindIt search), but its approach is now
  somewhat orphaned relative to the newer, cleaner pattern. **Open
  question for next session: does Task get the same create+history-grid
  treatment (in which case this bespoke form gets replaced/retired), or
  does it stay bespoke because `target`+`other` doesn't fit the "one
  relation + a count" shape the new base class assumes?** Leaning toward
  the latter (Task isn't really a "single-relation-count" thing — it has
  no count field at all — so it may legitimately need its own shape), but
  this wasn't explicitly settled with the user.
- **`assets/css.css`** — added `Task` to the shimmer-overlay exclusion
  selector (alongside the pre-existing `ShiftReport` one) — standalone
  bespoke forms need this or they render correctly but stay visually
  covered forever. **Note:** if the Task bespoke form does get retired in
  favor of a real grid, this exclusion becomes dead and could be removed;
  if RecipeCount or Prep ever need a *non-embedded*, standalone-form
  treatment (unlikely, they're grid-based), they'd need the same
  treatment `Batch`/`ShiftReport`/`Task` already have.

### How the plan actually diverged from the original doc below

The original doc (rest of this file) framed this branch as designing a
**new pod** for sub-ingredient production. What actually happened: the user
had already designed and built (in Pods admin) a much richer, more general
system — `task` as a central assignment unit bundling typed "counter"
sub-items (`batch`, `recipe_count`, `prep`), rather than one dedicated
production-log pod. `recipe_count` (recipe + count) turned out to likely
*be* the answer to "sub-ingredient production log," since recipes already
carry their own ingredient lists — a `recipe_count.done` provides the path
to real ingredient-consumption tracking later, without a new pod. **Feature
2 (standing shopping list) has not been touched at all.**

**`ingredients_low` was explicitly decided against** (see `kitchen_report`
schema above — no such field): the user's call was that real signal should
come from actual consumption (`recipe_count.done` × the recipe's own
ingredient list) rather than staff manually flagging "running low" — this
directly avoids CLAUDE.md's documented ingredient-data-quality problem
instead of adding another unreliable manually-entered signal on top of it.

### Immediate next steps (not yet started) — SUPERSEDED, see the 2026-08-30
### section at the top of this file for the current list. Kept below only
### as historical context for what this branch itself had planned.

~~1. Decide Task's own UI treatment~~ — resolved by main independently
   (detail-view/edit pattern, `task-detail-view.js`/`_detail-edit.js`).
~~2. Prep grid-pattern generalization~~ — moot; main already has Prep, built
   its own way.
~~3. Generalize the history-grid pattern~~ — done by main independently
   (`TaskComponentHistoryGridModel`).
4. **The actual Kitchen Report form** — still the real next step. See the
   2026-08-30 section at top for the current priority framing: task
   completion/confirmation is gating, `kitchen_report`'s own info fields
   (`supplies_low`/`base_counts`/`kitchen_visitors`/`notes`) come second,
   and it should be designed against main's current Task/RecipeCount/Prep
   shape, not this branch's.
5. Feature 2 (shopping list) — still completely unstarted.
6. ~~Minor: fix the stale `task="15233"`~~ — moot, this branch's own test
   pages/shortcodes aren't the live implementation path anymore.

---

## Original planning doc (superseded in large part by the above — kept for
## historical context, e.g. the "sharpen data clarity" rule and the CSV data
## discussion are still directly relevant)

It captures two features deliberately
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
