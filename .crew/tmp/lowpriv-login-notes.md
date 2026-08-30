# Task 013e13df00000013 — low-privilege login for smoke negative branches

## Established facts (verified 2026-08-30, branch worktree-tub-moving @ c0cd302, PR #36 open)

**Policy matrix (includes/_policy.php) — Debt route per role:**
- administrator / author / editor / kitchen_manager / shift_lead / lead: `Debt GET:true POST:true`
- ice_cream_maker: `'Debt' => ['GET' => false]` (line ~377 block, explicit deny)
- kiosk: `'Debt' => ['GET' => false]`
- _default (subscriber/unknown): deny everything

→ **NO current role renders the Debt board with canPost:false.** The debt-grid-model.js
comment "(kiosk/lead on Debt...)" is STALE. The negative branch (viewable board, non-writeable
Wanted cell) is unreachable without one policy line.

**Decision: flip ice_cream_maker Debt to GET:true (view-only, NO POST key).**
Why this role: individual staff role (kiosk = shared customer-facing tablet — wrong surface);
Debt data is derived from tub/slot data that role already sees via FlavorTub (no new leak);
debt view author explicitly contemplated view-without-write for Debt (stale comment proves intent).
Documented in _policy.php comment + flagged to Gus in PR/room as vetoable product line.
kiosk stays untouched.

**canPost resolution:** includes/enqueue.php ~line 195 `'canPost' => scoop_user_can_route($user, $route_key, 'POST')`
→ scoop_user_can_route (includes/_policy.php:535): `$policy['routes'][$route][$method] ?? false`.
Model: assets/models/debt-grid-model.js `_demandWriteable()` reads `window.SCOOP?.metaData?.Debt` → `!!md.canPost`;
no metadata (harness) → default true.

**Existing suite shape (tests/smoke/):**
- playwright.config.js: inline loadDotEnv, baseURL ops.swanky.local, workers:1, project 'edge' channel msedge.
- .env.example: SCOOP_BASE_URL / SCOOP_TEST_USER / SCOOP_TEST_PASS.
- debt-wanted-edit.spec.js: serial, fetchFreshBundle (types=Debt&force_bust=1), postDebtRequests,
  pollBundleUntil, supplyForPair mirror (WHOLE_TUB_THRESHOLD .8, DEAD_STATES Emptied/!Lost, OPEN_STATE
  Opened, FOH id 1863 + label fallback), fixture zz__flavor debt test___ / Mountlake Terrace,
  rowId = loc*100000+flavor, hidden input name `Debt[cells][<rowId>][demand]`, `.textIt.col-demand`
  number input, login via /wp-login.php form fill, finally cleanup wanted=0.
- Negative-branch comment at EOF of that spec says a 2nd low-priv login is needed → THIS task.

## TODO
1. ice_cream_maker Debt GET:true line + comment (includes/_policy.php); fix stale comments
   (debt-grid-model.js "kiosk/lead", enqueue.php same).
2. .env.example: SCOOP_TEST_USER_2 / SCOOP_TEST_PASS_2 (+ role doc).
3. New spec tests/smoke/tests/debt-wanted-readonly.spec.js:
   - skip unless _2 creds set; admin context mints override (supply mirror, WANTED_VISIBLE);
     low-priv context: login, metadata canPost===false, row renders hidden input with value,
     Wanted cell has NO number input, value still displayed; low-priv POST /debt-requests refused
     (probe body wanted=0 — safe-if-leaked: cleanup); admin context cleanup.
4. README: second-login setup (wp user create / wp-admin steps) + new spec section.
5. Validate: php -l _policy.php; node --check spec; unit suite; read renderer for the exact
   non-writeable cell DOM before finalizing selectors.
6. FF-push onto worktree-tub-moving (fetch first — same-branch concurrency), lands via PR #36.

## Open reads needed before selectors are final
- rest.php: /debt-requests permission_callback wiring + refusal shape (401 rest_forbidden vs 200 ok:false)
- renderer (list.js/text-it.js or grid.js): how a writeable:false cell renders in DOM
- shortcode.php render gate: confirms GET-grant ⇒ grid host rendered
- git log -L policy Debt lines: history context for the flip (comment already written accordingly)
