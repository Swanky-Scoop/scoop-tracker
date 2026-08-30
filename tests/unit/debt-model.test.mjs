#!/usr/bin/env node
///////////////////////////////////
// tests/unit/debt-model.test.mjs — pure-arithmetic unit tests for
// computeDebtRows() (assets/models/debt-grid-model.js), ported from the
// in-box node harness that proved the Debt view when it shipped
// (worktree-tub-moving). Run: node tests/unit/debt-model.test.mjs
//
// Zero-dependency: plain node ESM against the repo's own module graph.
// The model file transitively imports BaseGridModel + HashState, so
// browser globals must exist for a plain import in node — see the stub
// block at the top (same pattern tests/unit/debt-class.test.mjs uses).
///////////////////////////////////

// --- browser-global stubs (BEFORE the model import) -------------------------
// BaseGridModel reads window.SCOOP at construction time; HashState reads
// location.hash on get() and calls history.replaceState on set(). Neither
// runs anything at import time, but stubbing here keeps this file runnable
// standalone and documents the surface the grid models touch.
globalThis.location = { hash: '', href: 'http://localhost/' };
globalThis.history  = { replaceState() {} };
globalThis.window   = { SCOOP: {}, location: globalThis.location, history: globalThis.history };

import { computeDebtRows, isFrontOfHouseUse, debtRowId } from '../../assets/models/debt-grid-model.js';
import { eq, section, finish } from './helpers.mjs';

const FOH = 1863;  // FRONT_OF_HOUSE_USE_ID in the model — same number here
const BOH = 1877;  // any non-FOH use id

// One fixture world shared by the first block. Two destinations, two
// flavors, and a tub in every state bucket the arithmetic distinguishes:
// on-hand opened, earmarked inbound, fresh on-hand elsewhere, dead states,
// non-FOH, sub-threshold amount, and use-id-0-means-FOH.
const WORLD_A_SLOTS = [
  { id: 's1', location: 1010, current_flavor: 600 },   // Mountlake Terrace wants Vanilla
  { id: 's2', location: 1010, immediate_flavor: 600 }, // ...twice (current + immediate)
  { id: 's3', location: 1010, current_flavor: 700 },   // ...and Chocolate
  { id: 's4', location: 1020, current_flavor: 600 },   // Woodinville wants Vanilla
];
const WORLD_A_TUBS = [
  { id: 't1', flavor: 600, use: FOH, state: 'Opened',  amount: 1,   location: 1010, moving_to: 0 },
  { id: 't2', flavor: 600, use: FOH, state: 'Fresh',   amount: 1,   location: 1010, moving_to: 1020 }, // inbound to Woodinville
  { id: 't3', flavor: 600, use: FOH, state: 'Fresh',   amount: 1,   location: 1020, moving_to: 0 },
  { id: 't4', flavor: 600, use: FOH, state: 'Emptied', amount: 1,   location: 1020, moving_to: 0 }, // dead stock
  { id: 't5', flavor: 600, use: FOH, state: '!Lost',   amount: 0.9, location: 1020, moving_to: 0 }, // dead stock
  { id: 't6', flavor: 700, use: BOH, state: 'Fresh',   amount: 1,   location: 1020, moving_to: 0 }, // non-FOH
  { id: 't7', flavor: 700, use: FOH, state: 'Fresh',   amount: 0.7, location: 1020, moving_to: 0 }, // sub-whole
  { id: 't8', flavor: 600, use: 0,   state: 'Fresh',   amount: 1,   location: 1030, moving_to: 0 }, // use 0 = FOH
];

const pick = (rows, loc, flavor) => rows.find(r => r.destination === loc && r.flavor === flavor);
const run  = ({ slots = [], tubs = [], requests = [], useTitleOf = () => '' } = {}) =>
  computeDebtRows({ slots, tubs, requests, useTitleOf });

// ---- demand aggregation ----------------------------------------------------
section('demand aggregation');
{
  const rows = run({ slots: WORLD_A_SLOTS, tubs: WORLD_A_TUBS });

  eq(rows.length, 3, 'one row per demanded (location, flavor) pair');
  eq(pick(rows, 1010, 600), {
    id: 1010 * 100000 + 600, destination: 1010, flavor: 600,
    demand: 2, on_hand: 1, inbound: 0, gap: 1, available: 2, status: 'fillable',
  }, 'full row shape: current+immediate demand aggregate, Opened counts on hand, gap = max(0, d - on_hand - inbound), available = FOH pipeline at OTHER destinations');

  eq(pick(rows, 1010, 600).status, 'fillable', 'gap > 0 with stock elsewhere is fillable');
  eq(pick(rows, 1010, 700).status, 'unfillable', 'gap > 0 with nothing sendable (only BOH + sub-threshold tubs exist) is unfillable');
  eq(pick(rows, 1010, 700).available, 0, 'non-FOH and sub-whole tubs are NOT available');
  eq(pick(rows, 1010, 700).on_hand, 0, 'non-FOH tub is not on hand for a FOH plan');
  eq(pick(rows, 1020, 600).status, 'pending', 'gap closed by an earmarked tub is pending');
  eq(pick(rows, 1020, 600).inbound, 1, 'inbound = whole tubs with moving_to = destination');
  eq(pick(rows, 1020, 600).on_hand, 1, 'on_hand counts tubs physically at the destination');
  eq(pick(rows, 1020, 600).available, 1, 'available excludes tubs already AT the destination (t3) but counts elsewhere ones (t8)');
  eq(pick(rows, 1010, 600).available, 2, 'earmarked tub (t2, moving_to 1020) is NOT also available');
}

// ---- next_flavor is deliberately excluded from demand ----------------------
section('next_flavor exclusion');
{
  eq(run({ slots: [{ location: 1010, next_flavor: 600 }] }).length, 0,
    'a next_flavor-only designation creates no demand (deliberate — the column is rarely used and the auto-earmark hook excludes it too)');
  eq(run({ slots: [{ location: 1010, current_flavor: 600, next_flavor: 600 }] })
       .find(r => r.destination === 1010).demand,
     1, 'next_flavor on the same slot does not inflate current_flavor demand');
  eq(run({ slots: [{ location: 1010, current_flavor: 700 }, { location: 1010, next_flavor: 700 }],
           tubs: [{ flavor: 700, use: FOH, state: 'Fresh', amount: 1, location: 1020 }] })
       .find(r => r.destination === 1010).status,
     'fillable', 'demand counts only current/immediate even when the only stock is elsewhere');
}

// ---- Opened is in service where it sits — never sendable -------------------
section('Opened is not sendable stock');
{
  const rows = run({
    slots: [{ location: 1010, current_flavor: 700 }],
    tubs:  [{ flavor: 700, use: FOH, state: 'Opened', amount: 1, location: 1030 }],
  });
  eq(pick(rows, 1010, 700).available, 0, 'an Opened tub elsewhere is NOT available (adoption is a local fix, never a transfer)');
  eq(pick(rows, 1010, 700).status, 'unfillable', 'so the pair stays unfillable despite the Opened tub');
}

// ---- flavor debt: demand with zero tubs anywhere ---------------------------
section('flavor debt (zero tubs anywhere)');
{
  const rows = run({ slots: [{ location: 1010, current_flavor: 600 }] });
  eq(rows.length, 1, 'a designation with no tubs anywhere still yields a row');
  eq(pick(rows, 1010, 600).demand, 1, 'the pair is the slot designation, not any tub');
  eq(pick(rows, 1010, 600).on_hand, 0, 'zero-tub pair has nothing on hand');
  eq(pick(rows, 1010, 600).available, 0, '...and nothing available');
  eq(pick(rows, 1010, 600).gap, 1, 'gap = the full demand');
  eq(pick(rows, 1010, 600).status, 'unfillable', 'status unfillable — the churn-queue case MovingGridModel cannot show');
}

// ---- whole-tub threshold (0.8, same as scoop_find_whole_tubs) --------------
section('whole-tub threshold 0.8');
{
  eq(run({ slots: [{ location: 1010, current_flavor: 600 }],
           tubs: [{ flavor: 600, use: FOH, state: 'Fresh', amount: 0.8, location: 1010 }] })
       .find(r => r.destination === 1010).on_hand,
     1, 'amount exactly 0.8 counts as a whole tub');

  eq(run({ slots: [{ location: 1010, current_flavor: 600 }],
           tubs: [{ flavor: 600, use: FOH, state: 'Fresh', amount: 0.79, location: 1010 }] })
       .find(r => r.destination === 1010).on_hand,
     0, 'amount just below 0.8 does not count');
}

// ---- "Front of House" resolution: id first, normalized-label fallback ------
section('FOH use resolution (id-first, label fallback)');
{
  // Each case: one demand pair at 1010, one candidate tub of that flavor at
  // 1020 — its availability tells whether the tub classified as FOH. The
  // model only ever sees uses through useTitleOf(useId) (exactly like the
  // real bundle flow: titleById over domain.use), so titles ride there.
  const availWith = (use, title) => run({
    slots: [{ location: 1010, current_flavor: 700 }],
    tubs: [{ flavor: 700, use, state: 'Fresh', amount: 1, location: 1020 }],
    useTitleOf: (id) => (Number(id) === use ? (title ?? '') : ''),
  }).find(r => r.destination === 1010).available;

  eq(availWith(9999, 'Front-of-House'), 1, 'differently-numbered id + "Front-of-House" label matches (hyphen normalized)');
  eq(availWith(9999, 'Front of House'), 1, 'plain-space label matches');
  eq(availWith(9999, 'front_of_house'), 1, 'underscore label matches');
  eq(availWith(9999, 'Back of House'), 0, 'non-FOH id + non-FOH label is not available');
  eq(availWith(9999, '  Front   of  House  '), 1, 'whitespace runs collapse before matching');
  eq(availWith(0, ''), 1, 'use 0 with no title defaults to FOH (same convention as Flavor\'s !t.use || t.use === 1863)');
  eq(availWith(undefined, ''), 1, 'a tub with NO use at all also defaults FOH — the seat it feeds is front of house');
  eq(availWith(FOH, ''), 1, 'FOH id matches without a label');
  eq(availWith(FOH, 'Back of House'), 1, 'FOH id wins over a conflicting label');
  eq(availWith(BOH, ''), 0, 'non-FOH id with no label is not FOH');

  eq(isFrontOfHouseUse(FOH, ''), true, 'isFrontOfHouseUse: FOH id, empty title');
  eq(isFrontOfHouseUse(0, 'front of house'), true, 'isFrontOfHouseUse: label fallback');
  eq(isFrontOfHouseUse(0, ''), true, 'isFrontOfHouseUse: both absent defaults true');
  eq(isFrontOfHouseUse(BOH, ''), false, 'isFrontOfHouseUse: other id, no label');
  eq(isFrontOfHouseUse(9999, 'front of house'), true, 'isFrontOfHouseUse: label fallback applies to any id — differently-numbered environments classify by name (same as FlavorTub _isFrontOfHouseUse)');
  eq(isFrontOfHouseUse(FOH, 'front of house'), true, 'isFrontOfHouseUse: id and label agree');
}

// ---- earmark exclusivity: one tub, one bucket ------------------------------
section('earmark exclusivity');
{
  const rows = run({
    slots: [{ location: 1010, current_flavor: 600 }, { location: 1020, current_flavor: 600 }],
    tubs:  [{ flavor: 600, use: FOH, state: 'Fresh', amount: 1, location: 1010, moving_to: 1020 }],
  });
  eq(pick(rows, 1020, 600).inbound, 1, 'earmarked tub is inbound to its destination');
  eq(pick(rows, 1010, 600).on_hand, 0, '...and NOT on hand at its source location');
  eq(pick(rows, 1010, 600).available, 0, '...and NOT in the available pool');
  eq(pick(rows, 1010, 600).status, 'unfillable', 'source left with a gap and nothing sendable');
  eq(pick(rows, 1020, 600).status, 'pending', 'destination covered-as-pending by the claim');

  // An Opened tub CAN be earmarked (it is physically moving) — it still
  // counts as inbound, but an opened tub is never SENDABLE stock.
  const opened = run({
    slots: [{ location: 1020, current_flavor: 600 }],
    tubs:  [{ flavor: 600, use: FOH, state: 'Opened', amount: 1, location: 1010, moving_to: 1020 }],
  });
  eq(pick(opened, 1020, 600).inbound, 1, 'an Opened earmarked tub still counts as inbound');

  // Dead states are excluded everywhere.
  const dead = run({
    slots: [{ location: 1020, current_flavor: 600 }],
    tubs:  [
      { flavor: 600, use: FOH, state: 'Emptied', amount: 1, location: 1010, moving_to: 1020 },
      { flavor: 600, use: FOH, state: '!Lost',   amount: 1, location: 1010, moving_to: 1020 },
    ],
  });
  eq(pick(dead, 1020, 600).inbound, 0, 'Emptied/!Lost tubs satisfy no plan, even earmarked ones');
}

// ---- covered / overcovered -------------------------------------------------
section('covered status');
{
  const rows = run({
    slots: [{ location: 1010, current_flavor: 600 }, { location: 1010, current_flavor: 700 }],
    tubs:  [
      { flavor: 600, use: FOH, state: 'Fresh', amount: 1, location: 1010, moving_to: 0 }, // surplus
      { flavor: 700, use: FOH, state: 'Fresh', amount: 1, location: 1010, moving_to: 0 }, // exact
    ],
  });
  eq(pick(rows, 1010, 600).status, 'covered', 'surplus stock covers the plan');
  eq(pick(rows, 1010, 600).available, 0, 'the destination\'s own surplus is NOT "available to send" (already home)');
  eq(pick(rows, 1010, 700).status, 'covered', 'exact demand is covered — gap 0, no inbound');
}

// ---- requests: replace-not-add demand overrides ----------------------------
section('flavor_request overrides (replace, not add)');
{
  const base = { slots: WORLD_A_SLOTS, tubs: WORLD_A_TUBS };

  const more = run({ ...base, requests: [{ location: 1010, flavor: 600, wanted: 5 }] });
  eq(pick(more, 1010, 600).demand, 5, 'a request STATES demand outright — replaces the slot-implied 2');
  eq(pick(more, 1010, 600).gap, 4, 'gap recomputes against the requested demand');
  eq(pick(more, 1010, 600).status, 'fillable', 'status tracks the new gap');

  const less = run({ ...base, requests: [{ location: 1010, flavor: 600, wanted: 1 }] });
  eq(pick(less, 1010, 600).demand, 2, 'slots are the floor — max(slot-implied, requested), never a down-shift to 1');

  const same = run({ ...base, requests: [{ location: 1010, flavor: 600, wanted: 2 }] });
  eq(pick(same, 1010, 600).demand, 2, 'a request equal to the slot-implied demand never doubles it');

  // A request for a pair no slot implies creates its own row — and can be
  // fillable when stock exists elsewhere (R4: tub t3 at 1020).
  const only = run({ tubs: [WORLD_A_TUBS[2]], requests: [{ location: 1030, flavor: 600, wanted: 3 }] });
  eq(only.length, 1, 'request-only pair creates its own row (no slot demand for it)');
  eq(pick(only, 1030, 600).demand, 3, 'request-only demand carries the full wanted count');
  eq(pick(only, 1030, 600).available, 1, 'request-only row CAN be fillable — stock elsewhere counts');
  eq(pick(only, 1030, 600).status, 'fillable', 'status fillable for the request-only pair');

  // Max wins between overlapping requests for the same pair.
  const dup = run({ requests: [
    { location: 1020, flavor: 700, wanted: 4 },
    { location: 1020, flavor: 700, wanted: 2 },
  ] });
  eq(dup.length, 1, 'two requests for one pair stay one row');
  eq(pick(dup, 1020, 700).demand, 4, 'max wins between overlapping requests');
  eq(pick(dup, 1020, 700).status, 'unfillable', 'request-only pair with no stock is unfillable');

  // wanted 0 is a DELETE op at the parse layer (scoop_parse_debt_requests),
  // so persisted requests never carry it — but if one arrives, the join
  // still yields its demand-0 pair as a covered row (hidden by the default
  // hide_covered filter), not a crash.
  const zero = run({ requests: [{ location: 999, flavor: 600, wanted: 0 }] });
  eq(pick(zero, 999, 600), { id: 999 * 100000 + 600, destination: 999, flavor: 600, demand: 0, on_hand: 0, inbound: 0, gap: 0, available: 0, status: 'covered' }, 'a wanted-0 request yields a demand-0 covered row, not a crash');
}

// ---- synthetic row ids ------------------------------------------------------
section('synthetic numeric row ids');
{
  eq(debtRowId(1010, 600), 1010 * 100000 + 600, 'debtRowId = location*100000 + flavor');
  eq(debtRowId(1010, 600), 101000600, 'stable numeric pair id');
  eq(typeof debtRowId('1010', '600'), 'number', 'string ids coerce — List Number()s row ids, so the pair id must survive as a number');

  const id = debtRowId(1010, 600);
  eq(id % 100000, 600, 'decodes: id % 100000 = flavor');
  eq((id - 600) / 100000, 1010, 'decodes: floor(id/100000) = location');
}

finish('tests/unit/debt-model.test.mjs');
