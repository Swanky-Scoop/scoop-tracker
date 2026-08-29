# PR #32 — ops.swanky.local verify checklist (Other Uses arc)

**Branch:** `worktree-other-uses` @ `bc2dbaa` · **CI:** green (visual suite + review fixes) · **Review:** all 6 findings fixed on head
**Get the branch onto a server:** Actions → **Deploy** → Run workflow → branch `worktree-other-uses`, target **test-ink** (App can't dispatch; you can) — or your usual SFTP flow. Merging auto-deploys to ops.

---

## 1. Tub split-for-another-use (partial)
Open a tub's Details modal → split a **portion** to a different use.
- [ ] New tub appears with the chosen use + amount; origin's amount reduced by the split
- [ ] **Audit record is correct** (fix `bc2dbaa` — this was junk before): DateActivity shows a real title, phase "created", both tubs linked (new + origin), flavor refs populated — *not* "created 0 tub of" / phase "unknown"

## 2. Convert-in-place (split covers the whole tub)
Split with amount = origin's full amount.
- [ ] The **same tub** flips to the new use (no duplicate created)
- [ ] Audit shows phase **"converted"** as an update, not a create

## 3. Mark abandoned flavor's tubs lost
- [ ] Abandoning a flavor offers to mark remaining tubs Lost; accepting marks them

## 4. Multi-click N-tub swap gesture (CabinetWorkflow)
- [ ] Multi-click count swaps exactly N tubs; count capped at the promotable pool

## 5. Details modal + detail links
- [ ] Row and group titles open the Details modal across grids (incl. ItemPivot's Flavor label)
- [ ] Links behave per `detailViewableEntities` (default: all clickable). Note: detail_views is now honestly a **client-side link control** — the dead "server gate" was removed in review (`51e6e9d`)

## 6. Split control's use-picker default
- [ ] Defaults to the tub's **real current use**, not Front-of-house (the fix you verified 2026-08-27)

## 7. Regression sweep on untouched write paths
- [ ] Batch swap + closeout still write correct audit records (untouched by `bc2dbaa`)

## Error paths worth one poke each (new guards from `7f14982`)
- [ ] Split to a nonexistent use → rejected (`tub_split_bad_use`)
- [ ] Concurrent/tab-duplicated split → origin amount can't go negative (server re-reads before decrement)

---

**After verify:** tell this room "verified" — I'll un-draft #32 and stage the merge card (merge → production deploy of `swankyscoop.net`).
