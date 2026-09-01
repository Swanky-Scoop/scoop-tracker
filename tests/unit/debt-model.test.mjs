#!/usr/bin/env node
///////////////////////////////////
// tests/unit/debt-model.test.mjs — pure-arithmetic unit tests for
// computeDebtRows() (assets/models/debt-grid-model.js). Run:
// node tests/unit/debt-model.test.mjs
//
// Rewritten 2026-08-31 for the 1-to-1-with-a-flavor_request redesign: rows
// are no longer derived from a slot scan in this file at all — demand
// (server-synced 'wanted') and row EXISTENCE both come from `requests` now.
// Slot-driven creation/wanted-sync (scoop_sync_flavor_request()) and the
// Woodinville/Freezing-only claim search (scoop_topup_flavor_request_claims())
// are server-side (includes/hooks/cabinet-slot.php) and have no JS
// equivalent to test here — this file only covers the arithmetic once a
// request and some tubs already exist.
//
// Zero-dependency: plain node ESM against the repo's own module graph.
// The model file transitively imports BaseGridModel + HashState, so
// browser globals must exist for a plain import in node — see the stub
// block at the top (same pattern tests/unit/debt-class.test.mjs uses).
///////////////////////////////////

// --- browser-global stubs (BEFORE the model import) -------------------------
globalThis.location = { hash: '', href: 'http://localhost/' };
globalThis.history  = { replaceState() {} };
globalThis.window   = { SCOOP: {}, location: globalThis.location, history: globalThis.history };

import { computeDebtRows, isFrontOfHouseUse, debtRowId } from '../../assets/models/debt-grid-model.js';
import { eq, section, finish } from './helpers.mjs';

const FOH = 1863;  // FRONT_OF_HOUSE_USE_ID in the model — same number here
const BOH = 1877;  // any non-FOH use id

const pick = (rows, loc, flavor) => rows.find(r => r.destination === loc && r.flavor === flavor);
const run  = ({ tubs = [], requests = [], useTitleOf = () => '' } = {}) =>
  computeDebtRows({ tubs, requests, useTitleOf });

// ---- row existence: 1-to-1 with a flavor_request post ----------------------
section('row existence is 1-to-1 with a flavor_request post');
{
  eq(run({ tubs: [{ flavor: 600, use: FOH, state: 'Fresh', amount: 1, location: 1020 }] }).length, 0,
    'stock existing with NO request at all yields zero rows — demand no longer comes from a slot scan here');

  eq(run({ requests: [{ id: 1, location: 1010, flavor: 600, wanted: 1 }] }).length, 1,
    'one request post = one row');

  eq(run({ requests: [{ location: 1010, flavor: 600, wanted: 1 }] }).length, 0,
    'a request with no real id is skipped, not crashed on');
  eq(run({ requests: [{ id: 1, flavor: 600, wanted: 1 }] }).length, 0,
    'a request with no location is skipped');
  eq(run({ requests: [{ id: 1, location: 1010, wanted: 1 }] }).length, 0,
    'a request with no flavor is skipped');

  eq(run({ requests: [
    { id: 1, location: 1010, flavor: 600, wanted: 1 },
    { id: 2, location: 1020, flavor: 700, wanted: 1 },
  ] }).length, 2, 'two distinct requests = two rows');
}

// ---- demand reads straight off 'wanted' — no slot merge in this layer ------
section('demand = req.wanted, no client-side floor/merge');
{
  const rows = run({ requests: [{ id: 1, location: 1010, flavor: 600, wanted: 3 }] });
  eq(pick(rows, 1010, 600).demand, 3, 'demand is exactly the persisted wanted value');
  eq(pick(rows, 1010, 600).on_hand, 0, 'no tubs -> nothing on hand');
  eq(pick(rows, 1010, 600).claimed, 0, 'no tubs claim it -> claimed 0');
  eq(pick(rows, 1010, 600).gap, 3, 'gap (Owed) = full wanted when nothing is claimed');

  // Two requests for the same (location, flavor) pair are NOT deduped/merged
  // here anymore (that uniqueness is a server-side guarantee — see
  // scoop_find_or_create_flavor_request(), includes/hooks/cabinet-slot.php)
  // — surfacing two rows for a real duplicate is more honest than silently
  // merging them.
  const dup = run({ requests: [
    { id: 1, location: 1020, flavor: 700, wanted: 4 },
    { id: 2, location: 1020, flavor: 700, wanted: 2 },
  ] });
  eq(dup.length, 2, 'duplicate-pair requests each get their own row now — no max()-merge in this layer');
}

// ---- claimed tubs are what Owed actually measures --------------------------
section('Owed = max(0, wanted - claimed), read off tub.flavor_request');
{
  const req = { id: 42, location: 1020, flavor: 600, wanted: 3 };

  eq(pick(run({ requests: [req] }), 1020, 600).gap, 3, 'zero claimed tubs -> full Owed');

  const oneClaimed = run({
    requests: [req],
    tubs: [{ flavor: 600, use: FOH, state: 'Freezing', amount: 1, location: 935, flavor_request: 42 }],
  });
  eq(pick(oneClaimed, 1020, 600).claimed, 1, 'a tub whose OWN flavor_request field names this request counts as claimed');
  eq(pick(oneClaimed, 1020, 600).gap, 2, 'Owed drops by exactly the claimed count');

  const fullyClaimed = run({
    requests: [req],
    tubs: [
      { flavor: 600, use: FOH, state: 'Freezing', amount: 1, location: 935, flavor_request: 42 },
      { flavor: 600, use: FOH, state: 'Freezing', amount: 1, location: 935, flavor_request: 42 },
      { flavor: 600, use: FOH, state: 'Freezing', amount: 1, location: 935, flavor_request: 42 },
    ],
  });
  eq(pick(fullyClaimed, 1020, 600).gap, 0, 'claimed == wanted -> Owed 0');

  const overClaimed = run({
    requests: [req],
    tubs: Array.from({ length: 5 }, () => ({ flavor: 600, use: FOH, state: 'Freezing', amount: 1, location: 935, flavor_request: 42 })),
  });
  eq(pick(overClaimed, 1020, 600).gap, 0, 'Owed never goes negative when over-claimed');
  eq(pick(overClaimed, 1020, 600).claimed, 5, '...but claimed itself reports the true (higher) count');

  const wrongRequest = run({
    requests: [req],
    tubs: [{ flavor: 600, use: FOH, state: 'Freezing', amount: 1, location: 935, flavor_request: 999 }],
  });
  eq(pick(wrongRequest, 1020, 600).claimed, 0, 'a tub claimed by a DIFFERENT request does not count here');

  const unclaimed = run({
    requests: [req],
    tubs: [{ flavor: 600, use: FOH, state: 'Freezing', amount: 1, location: 935, flavor_request: 0 }],
  });
  eq(pick(unclaimed, 1020, 600).claimed, 0, 'flavor_request: 0/falsy is not a claim');
}

// ---- Owed and Status are deliberately independent numbers now --------------
section('Owed (claim-based) vs Status (on_hand/inbound-based) are independent');
{
  // Physically satisfied locally (on_hand covers demand -> status covered),
  // but nothing has ever been formally claimed against the request -> Owed
  // still shows the full wanted amount. This is a real, expected divergence
  // (see this file's header + debt-grid-model.js's own header comment for
  // why the two are kept separate).
  const rows = run({
    requests: [{ id: 1, location: 1010, flavor: 600, wanted: 1 }],
    tubs: [{ flavor: 600, use: FOH, state: 'Fresh', amount: 1, location: 1010 }],
  });
  eq(pick(rows, 1010, 600).status, 'covered', 'on-hand stock covers demand -> status covered');
  eq(pick(rows, 1010, 600).gap, 1, 'but Owed is untouched by on_hand — no tub claimed this request');

  // The reverse: fully claimed (Owed 0) but the claimed tub hasn't
  // physically arrived yet (still at Woodinville, moving_to set) -> status
  // still reads pending off the legacy on_hand/inbound arithmetic.
  const rows2 = run({
    requests: [{ id: 2, location: 1020, flavor: 700, wanted: 1 }],
    tubs: [{ flavor: 700, use: FOH, state: 'Freezing', amount: 1, location: 935, moving_to: 1020, flavor_request: 2 }],
  });
  eq(pick(rows2, 1020, 700).gap, 0, 'fully claimed -> Owed 0');
  eq(pick(rows2, 1020, 700).status, 'pending', 'status still pending — physically still inbound, independent of the claim');
}

// ---- whole-tub threshold (0.8, same as scoop_find_whole_tubs) --------------
section('whole-tub threshold 0.8');
{
  eq(run({
    requests: [{ id: 1, location: 1010, flavor: 600, wanted: 1 }],
    tubs: [{ flavor: 600, use: FOH, state: 'Fresh', amount: 0.8, location: 1010 }],
  }).find(r => r.destination === 1010).on_hand, 1, 'amount exactly 0.8 counts as a whole tub');

  eq(run({
    requests: [{ id: 1, location: 1010, flavor: 600, wanted: 1 }],
    tubs: [{ flavor: 600, use: FOH, state: 'Fresh', amount: 0.79, location: 1010 }],
  }).find(r => r.destination === 1010).on_hand, 0, 'amount just below 0.8 does not count');
}

// ---- "Front of House" resolution: id first, normalized-label fallback ------
section('FOH use resolution (id-first, label fallback)');
{
  const availWith = (use, title) => run({
    requests: [{ id: 1, location: 1010, flavor: 700, wanted: 1 }],
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

// ---- Opened is in service where it sits — never sendable -------------------
section('Opened is not sendable stock');
{
  const rows = run({
    requests: [{ id: 1, location: 1010, flavor: 700, wanted: 1 }],
    tubs:  [{ flavor: 700, use: FOH, state: 'Opened', amount: 1, location: 1030 }],
  });
  eq(pick(rows, 1010, 700).available, 0, 'an Opened tub elsewhere is NOT available (adoption is a local fix, never a transfer)');
  eq(pick(rows, 1010, 700).status, 'unfillable', 'so the pair stays unfillable despite the Opened tub');
}

// ---- earmark exclusivity: one tub, one bucket ------------------------------
section('earmark exclusivity');
{
  const rows = run({
    requests: [{ id: 1, location: 1010, flavor: 600, wanted: 1 }, { id: 2, location: 1020, flavor: 600, wanted: 1 }],
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
    requests: [{ id: 1, location: 1020, flavor: 600, wanted: 1 }],
    tubs:  [{ flavor: 600, use: FOH, state: 'Opened', amount: 1, location: 1010, moving_to: 1020 }],
  });
  eq(pick(opened, 1020, 600).inbound, 1, 'an Opened earmarked tub still counts as inbound');

  // Dead states are excluded everywhere.
  const dead = run({
    requests: [{ id: 1, location: 1020, flavor: 600, wanted: 1 }],
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
    requests: [{ id: 1, location: 1010, flavor: 600, wanted: 1 }, { id: 2, location: 1010, flavor: 700, wanted: 1 }],
    tubs:  [
      { flavor: 600, use: FOH, state: 'Fresh', amount: 1, location: 1010, moving_to: 0 }, // surplus
      { flavor: 700, use: FOH, state: 'Fresh', amount: 1, location: 1010, moving_to: 0 }, // exact
    ],
  });
  eq(pick(rows, 1010, 600).status, 'covered', 'surplus stock covers the plan');
  eq(pick(rows, 1010, 600).available, 0, 'the destination\'s own surplus is NOT "available to send" (already home)');
  eq(pick(rows, 1010, 700).status, 'covered', 'exact demand is covered — legacy gap 0, no inbound');
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

  eq(pick(run({ requests: [{ id: 1, location: 1010, flavor: 600, wanted: 1 }] }), 1010, 600).id, debtRowId(1010, 600),
    'a computed row\'s own id is still the (location, flavor) pair id, not the request\'s real post id');
  eq(pick(run({ requests: [{ id: 7, location: 1010, flavor: 600, wanted: 1 }] }), 1010, 600).requestId, 7,
    'the request\'s real post id is carried separately as requestId');
}

finish('tests/unit/debt-model.test.mjs');
