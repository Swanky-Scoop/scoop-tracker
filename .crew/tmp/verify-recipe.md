## ✅ Verify recipe — TubSplit audit records (task: Verify TubSplit split + convert audit records on ops.swanky.local)

For ops.swanky.local running this branch at `bc2dbaa`:

### A. True split

1. Open a tub's Details modal → **Split for another use** → pick a use → enter an amount **less than** the tub's current amount → submit.
2. Expect: toast "Tub split saved"; a new tub (title `<Origin>/<Use>`, state Emptied, amount = what you entered) and the origin's amount reduced by that amount.
3. In DateActivity (or the WP admin `inventory_change` list), the newest record should be — **exactly one**:
   - title like `<user> split 1.5 off Chocolate 5gal of Chocolate for Grab-and-go on Sat 08/29`
   - phase `created`, mode `create`, envelope `TubSplit`, change_count 2
   - **tubs: both** the new tub and the origin (clickable links); **flavors: the origin's flavor**
   - details: the new tub's row (use / amount / origin) plus the origin's row showing its **post-split amount**

### B. Convert-in-place

1. Open a tub → **Split for another use** → leave the amount at its default (**equal to** the tub's full amount — that's the convert trigger) → pick a use → submit.
2. Expect: **no new tub**; the origin itself now shows the chosen use, state Emptied, amount untouched.
3. Newest audit record — **exactly one**: mode `update`, **phase `converted`**, title `<user> converted <Origin title> of <Flavor> to <Use> on <date>`, tubs = [origin], flavors = [flavor], details = `use => <use>` / `state => Emptied`.

### C. Regression spot-checks (must be unchanged)

- Create one **Batch** → its record still reads the legacy shape `created N batch of <Flavor>s on <date>` (the generic create branch is untouched).
- A regular tub/slot edit still logs the way it did before (staged session batch, or immediate with a CabinetWorkflow source hint).

### Pass bar

No junk record for either action: no `created 0 tub of`, no phase `unknown`, no empty tubs/flavors. If anything looks off, comment here or drop into the Work room.
