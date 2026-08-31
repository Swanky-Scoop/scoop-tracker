#!/usr/bin/env node
///////////////////////////////////
// tests/unit/debt-class.test.mjs — unit tests for the DebtGridModel CLASS
// (assets/models/debt-grid-model.js): column shape, cell objects, grouping/
// ordering, badges, and both client-side filters. Run:
// node tests/unit/debt-class.test.mjs
//
// Rewritten 2026-08-31 for the 1-to-1-with-a-flavor_request redesign: the
// domain fixture now carries `flavor_request` posts (what actually drives
// rows) instead of `slot` designations (which drove rows before — slots
// still exist server-side, but only to sync flavor_request.wanted; this
// class no longer reads this.domain.slot at all). `demand`/Wanted values
// below are chosen to match what the OLD slot fixture implied, so this
// file's story ("Alderwood wants Vanilla x2", etc.) reads the same as
// before — only the wiring underneath changed.
//
// Zero-dependency node ESM. The stub block below MUST run before the model
// import: BaseGridModel reads window.SCOOP (detail-link gating, canPost),
// HashState reads location.hash on get() and calls history.replaceState on
// set() — the stubbed replaceState mirrors the hash into the stubbed
// location so HashState round-trips work in node like they do in the
// browser.
///////////////////////////////////

// --- browser-global stubs (BEFORE the model import) -------------------------
globalThis.location = { hash: '', href: 'http://localhost/' };
globalThis.history  = {
  replaceState(_state, _title, url) {
    const href = String(url);
    globalThis.location.href = href;
    globalThis.location.hash = href.includes('#') ? href.slice(href.indexOf('#')) : '';
  },
};
globalThis.window = { SCOOP: {}, location: globalThis.location, history: globalThis.history };

import DebtGridModel, { debtRowId } from '../../assets/models/debt-grid-model.js';
import { eq, section, finish } from './helpers.mjs';

const FOH = 1863, BOH = 1877;

// Fixture world — one row per status, one group per badge shape. Same
// story as before the redesign, but demand now comes from flavor_request
// posts (wanted) instead of a slot scan:
//   Alderwood  Vanilla   fillable  (Opened tub on hand, Cedar Park fresh tub sendable)
//   Alderwood  Chocolate unfillable (Chocolate exists only as BOH + sub-whole tubs elsewhere)
//   Bothell    Vanilla   pending   (nothing local; Cedar Park tub earmarked to it)
//   Bothell    Pistachio covered   (local exact stock, no inbound)
//   Cedar Park Strawberry covered   (local exact stock, no inbound)
//   Darrington Chocolate unfillable (the churn queue — no Chocolate anywhere)
const domain = {
  flavor: [
    { id: 600, _title: 'Vanilla' },
    { id: 700, _title: 'Chocolate' },
    { id: 800, _title: 'Strawberry' },
    { id: 900, _title: 'Pistachio' },
    { id: 950, _title: 'Mint' }, // used only by the tie-break variant below
  ],
  location: [
    { id: 1010, _title: 'Alderwood' },
    { id: 1020, _title: 'Bothell' },
    { id: 1030, _title: 'Cedar Park' },
    { id: 1040, _title: 'Darrington' },
  ],
  use: [
    { id: FOH, _title: 'Front of House' },
    { id: BOH, _title: 'Back of House' },
  ],
  flavor_request: [
    { id: 1, location: 1010, flavor: 600, wanted: 2 }, // Alderwood wants Vanilla x2 (was current+immediate)
    { id: 2, location: 1010, flavor: 700, wanted: 1 }, // ...and Chocolate
    { id: 3, location: 1020, flavor: 600, wanted: 1 }, // Bothell wants Vanilla
    { id: 4, location: 1020, flavor: 900, wanted: 1 }, // ...and Pistachio
    { id: 5, location: 1030, flavor: 800, wanted: 1 }, // Cedar Park wants Strawberry
    { id: 6, location: 1040, flavor: 700, wanted: 1 }, // Darrington wants Chocolate
  ],
  tub: [
    { id: 't1', flavor: 600, use: FOH, state: 'Opened',  amount: 1,   location: 1010, moving_to: 0 },    // on hand @1010 (Opened counts)
    { id: 't2', flavor: 600, use: FOH, state: 'Fresh',   amount: 1,   location: 1030, moving_to: 1020 }, // inbound to Bothell
    { id: 't3', flavor: 600, use: FOH, state: 'Fresh',   amount: 1,   location: 1030, moving_to: 0 },    // on hand @1030 AND sendable to 1010
    { id: 't6', flavor: 700, use: BOH, state: 'Fresh',   amount: 1,   location: 1030, moving_to: 0 },    // BOH: NOT sendable
    { id: 't7', flavor: 700, use: FOH, state: 'Fresh',   amount: 0.7, location: 1030, moving_to: 0 },    // sub-whole: NOT sendable
    { id: 't8', flavor: 600, use: FOH, state: 'Emptied', amount: 1,   location: 1020, moving_to: 0 },    // dead: counts nowhere
    { id: 't9', flavor: 600, use: FOH, state: '!Lost',   amount: 1,   location: 1020, moving_to: 1020 }, // dead: even earmarked
    { id: 't10', flavor: 800, use: FOH, state: 'Fresh',  amount: 1,   location: 1030, moving_to: 0 },    // covers Cedar Park's Strawberry
    { id: 't11', flavor: 900, use: FOH, state: 'Fresh',  amount: 1,   location: 1020, moving_to: 0 },    // covers Bothell's Pistachio
  ],
};

const fresh = () => new DebtGridModel('Debt', { ...domain });

// A row does NOT carry a bare destination — the (destination, flavor) pair
// lives in the group (rowGroups[i].groupId) and the flavor CELL (row.flavor
// .id). A group's rows are the contiguous slice [startIndex, next startIndex).
const rowsByDest = (m) => {
  const byDest = new Map();
  m.rowGroups.forEach((g, i) => {
    const end = i + 1 < m.rowGroups.length ? m.rowGroups[i + 1].startIndex : m.rows.length;
    byDest.set(g.groupId, m.rows.slice(g.startIndex, end));
  });
  return byDest;
};

// ---- construction & columns -------------------------------------------------
section('construction & columns');
{
  const m = fresh();

  eq(m.displayTitle, 'Debt', 'displayTitle defaults to the model name');
  eq(m.location, 0, 'starts at location 0 = all destinations (page location default must NOT scope it)');
  eq(m.writeEnvelope, 'Debt', 'write envelope key is Debt');
  eq(m.writeRoute, 'DebtRequests', 'writes go to the dedicated /debt-requests route');
  eq(m.autosave, true, 'full autosave (one writeable column makes full = partial)');

  eq(m.columns.map(c => c.key), ['flavor', 'demand', 'on_hand', 'inbound', 'gap', 'available', 'status'], 'column keys in board order');
  eq(m.columns.find(c => c.key === 'demand'), { key: 'demand', label: 'Wanted', type: 'number', control: 'text', write: true }, 'Wanted is the one writeable column (TextIt control)');
  eq(m.columns.find(c => c.key === 'flavor').titleMap, 'flavor', 'flavor column is titleMap-linked (detail link to the flavor modal)');
  eq(m.columns.find(c => c.key === 'flavor').detailLinkable, true, 'flavor column is detail-linkable (no server restriction)');
  eq(m.columns.filter(c => c.write === true).map(c => c.key), ['demand'], 'exactly one writeable column — everything else is computed');

  const empty = new DebtGridModel('Debt');
  eq(empty.buildRows(), [], 'no domain -> no rows, no crash');
}

// ---- row shape: numeric ids, flavor cells, demand cells ---------------------
section('row shape');
{
  const m = fresh();
  m.setFilterValue('hide_covered', 'false');
  m.buildRows(); // see ALL six pairs (covered included) for the shape checks

  const byDest = rowsByDest(m);
  eq(m.rows.length, 6, 'six flavor_request posts = six rows');
  const r600 = byDest.get(1010).find(r => r.flavor.id === 600);
  eq(r600.id, debtRowId(1010, 600), 'row id is the synthetic numeric pair id');
  eq(Number.isInteger(r600.id), true, 'row id is an INTEGER (List Number()s ids — a string key would NaN-drop the edit)');
  eq(r600.id, 1010 * 100000 + 600, 'pair id = location*100000 + flavor');
  eq(m.rows.every(r => Number.isInteger(r.id)), true, 'every row id is an integer');

  eq(r600.flavor, { id: 600, rowId: r600.id, display: 'Vanilla' }, 'flavor cell is {id,rowId,display} — display title, not the raw id (the display-title bug)');
  eq(m.rows.every(r => typeof r.flavor?.display === 'string' && r.flavor.rowId === r.id), true, 'every row carries a display-titled, id-consistent flavor cell');
  eq(byDest.get(1030).find(r => r.flavor.id === 800).flavor.display, 'Strawberry', 'second flavor resolves its own display title');

  eq(r600.demand, {
    id: r600.id, rowId: r600.id, colKey: 'demand',
    display: '2', value: 2, write: true, type: 'number', min: 0, max: 99, step: 1,
  }, 'Wanted cell is a full TextIt object — colKey REQUIRED (autosave input name), min/max/step 0..99 by 1, value straight from the request\'s wanted field');

  eq(r600.on_hand, 1, 'on_hand: Opened tub at destination counts');
  eq(r600.inbound, 0, 'inbound: none for Alderwood');
  eq(r600.claimed, 0, 'no tub\'s flavor_request field claims this request — the fixture never sets one');
  eq(r600.gap, 2, 'Owed = wanted 2 - claimed 0 (on_hand/inbound do NOT factor into Owed anymore)');
  eq(r600.available, 1, 'available: Cedar Park\'s fresh FOH Vanilla is sendable (Opened/earmarked/dead excluded)');
  eq(r600.status, 'fillable', 'status still reads the legacy on_hand/inbound gap vs available -> fillable');

  const a700 = byDest.get(1010).find(r => r.flavor.id === 700);
  eq(a700.available, 0, 'BOH + sub-whole Chocolate tubs are NOT available');
  eq(a700.status, 'unfillable', 'so Alderwood Chocolate is unfillable');
  eq(byDest.get(1040).find(r => r.flavor.id === 700).status, 'unfillable', 'Darrington Chocolate: the churn queue (no sendable Chocolate anywhere)');

  const b600 = byDest.get(1020).find(r => r.flavor.id === 600);
  eq(b600.inbound, 1, 'earmarked tub (moving_to 1020) is inbound');
  eq(b600.on_hand, 0, 'Emptied/!Lost tubs at Bothell count nowhere');
  eq(b600.status, 'pending', 'and its destination is pending');
  eq(byDest.get(1020).find(r => r.flavor.id === 900).status, 'covered', 'local exact stock covers Bothell Pistachio');
  eq(byDest.get(1030).find(r => r.flavor.id === 800).status, 'covered', 'Cedar Park Strawberry covered by its own tub');
}

// ---- Wanted writeability rides window.SCOOP.metaData.Debt.canPost ----------
section('Wanted writeability vs server metadata');
{
  // No metadata at all (dev/test harness): the column's own write flag wins.
  const noMd = fresh();
  eq(noMd.rows[0].demand.write, true, 'no metaData -> write:true (let the column flag speak)');

  // Server says the current user may not post: cell must say so too.
  globalThis.window.SCOOP.metaData = { Debt: { canPost: false } };
  const denied = fresh();
  eq(denied.rows[0].demand.write, false, 'canPost:false -> write:false (matches what the server will accept)');
  eq(denied.rows[0].demand.display, '2', '...but the number still displays');
  eq(denied.rows[0].demand.colKey, 'demand', '...and the colKey still names the autosave field');

  globalThis.window.SCOOP.metaData = { Debt: { canPost: true } };
  const allowed = fresh();
  eq(allowed.rows[0].demand.write, true, 'canPost:true -> write:true');

  delete globalThis.window.SCOOP.metaData; // restore for later sections
}

// ---- grouping: owed desc, then label ---------------------------------------
section('group order & badges');
{
  const m = fresh(); // default hide_covered=on
  // Owed no longer factors in on_hand/inbound — since no test tub claims
  // any request here, Owed == Wanted for every row: Alderwood 2+1=3,
  // Bothell 1 (Pistachio's covered row is filtered out before grouping,
  // so its own Owed never reaches this destination's total), Darrington 1.
  // Bothell/Darrington's tie at 1 breaks alphabetically — a real order
  // change from the pre-redesign formula (which credited Bothell's inbound
  // tub against Owed; this one doesn't).
  eq(m.rowGroups.map(g => g.label), ['Alderwood', 'Bothell', 'Darrington'],
    'groups ordered by total owed desc (3 / 1 / 1, tie broken by label); Cedar Park (only covered rows) hidden');
  eq(m.rowGroups.map(g => g.groupId), [1010, 1020, 1040], 'group ids are the destinations');
  eq(m.rowGroups[0].detailEntity, 'location', 'group headers detail-link to their location');
  eq(m.rowGroups[0].groupType, 'location', 'groupType location');

  eq(m.rowGroups[0].badges, [{ key: 'debt', text: '3 owed · 1 fillable · 1 need churning' }], 'Alderwood badge: owed (2 Vanilla + 1 Chocolate, neither claimed) + fillable + churn queue');
  eq(m.rowGroups.find(g => g.groupId === 1020).badges, [{ key: 'debt', text: '1 owed' }], 'Bothell badge: Vanilla reads pending but is still unclaimed -> 1 owed (no fillable/unfillable rows visible while Pistachio stays hidden)');
  eq(m.rowGroups.find(g => g.groupId === 1040).badges, [{ key: 'debt', text: '1 owed · 1 need churning' }], 'Darrington badge: unfillable only');
}

// ---- row order inside a group: status rank, then demand desc ----------------
section('row order within a group');
{
  const m = fresh();
  const groups = rowsByDest(m);

  eq(groups.get(1010).map(r => r.status), ['fillable', 'unfillable'], 'Alderwood: fillable before unfillable (work queue order)');
  eq(groups.get(1040).map(r => r.status), ['unfillable'], 'Darrington: single unfillable row');
  eq(groups.get(1020).map(r => r.status), ['pending'], 'Bothell (default view): pending only');

  // Break the rank tie by demand desc: give Alderwood two stockless flavors —
  // Chocolate x2 (demand 2) and Mint x1 (demand 1) — both unfillable (neither
  // has any sendable tub anywhere), so Chocolate (owed 2) sorts first.
  const m2 = new DebtGridModel('Debt', {
    ...domain,
    flavor_request: [
      ...domain.flavor_request.filter(r => r.location !== 1010),
      { id: 101, location: 1010, flavor: 700, wanted: 2 },
      { id: 102, location: 1010, flavor: 950, wanted: 1 },
    ],
  });
  const g2 = rowsByDest(m2).get(1010);
  eq(g2.map(r => r.status), ['unfillable', 'unfillable'], 'both Alderwood rows unfillable (no sendable Chocolate OR Mint anywhere)');
  eq(g2.map(r => r.flavor.display), ['Chocolate', 'Mint'], 'status rank tie broken by demand desc (Chocolate owes 2, Mint 1)');
}

// ---- hide_covered filter ------------------------------------------------------
section('hide_covered filter');
{
  const m = fresh();
  eq(m.getFilterValue('hide_covered'), 'true', 'hide_covered defaults ON');
  eq(m.rows.some(r => r.status === 'covered'), false, 'covered rows hidden by default');
  eq(m.rowGroups.map(g => g.label), ['Alderwood', 'Bothell', 'Darrington'], 'Cedar Park group (only covered rows) absent while hidden');

  m.setFilterValue('hide_covered', 'false');
  eq(m.getFilterValue('hide_covered'), 'false', 'setFilterValue stores the new value');
  m.buildRows(); // the GUI re-queries rows after a filter change; the model does not self-rebuild
  eq(m.rows.length, 6, 'covered rows shown when the filter is off');
  // Bothell's total Owed rises to 2 once Pistachio's own (unclaimed) Owed-1
  // rejoins the group, overtaking Cedar Park/Darrington's tie at 1 (broken
  // alphabetically, Cedar Park first).
  eq(m.rowGroups.map(g => g.label), ['Alderwood', 'Bothell', 'Cedar Park', 'Darrington'], 'Bothell now owed 2 (Vanilla + Pistachio, both unclaimed); Cedar Park/Darrington tie at 1 broken by label');
  eq(m.rowGroups.find(g => g.groupId === 1020).badges, [{ key: 'debt', text: '2 owed' }], 'Bothell badge: both rows unclaimed, neither fillable/unfillable while one is inbound and the other covered');
  eq(m.rowGroups.find(g => g.groupId === 1030).badges, [{ key: 'debt', text: '1 owed' }], 'Cedar Park badge: Strawberry is covered by on-hand stock but still shows Owed 1 (unclaimed) — the two numbers are independent now');
  eq(rowsByDest(m).get(1020).map(r => r.status), ['pending', 'covered'], 'Bothell: pending before covered (status rank extends to the tail)');

  m.setFilterValue('hide_covered', 'true');
  m.buildRows();
  eq(m.rows.some(r => r.status === 'covered'), false, 'filter back on hides covered again');
}

// ---- location filter (base-class persistence via HashState) -----------------
section('location filter narrows destinations, 0 = all');
{
  globalThis.location.hash = ''; // clean hash state for this section

  const m = fresh();
  eq(m.getFilterValue('location'), 0, 'location filter value starts at 0 = all');
  eq(m.rowGroups.length, 3, 'three destinations with visible rows at 0 (Cedar Park covered-hidden)');

  m.setFilterValue('location', 1010);
  eq(m.location, 1010, 'setFilterValue(location) rides the base class');
  m.buildRows();
  eq(m.rowGroups.map(g => g.label), ['Alderwood'], 'narrowed to the one destination');
  eq(m.rows.map(r => r.id).sort((a, b) => a - b), [debtRowId(1010, 600), debtRowId(1010, 700)], 'both Alderwood rows, ids intact');
  eq(globalThis.location.hash, '#loc.Debt=1010', 'choice persisted to HashState (loc.Debt in the URL hash)');

  const restored = fresh(); // a fresh grid reads the same hash override back
  eq(restored.location, 1010, 'a NEW Debt grid restores the persisted destination');
  eq(restored.rowGroups.map(g => g.label), ['Alderwood'], '...and starts narrowed');

  m.setFilterValue('location', 0);
  m.buildRows();
  eq(m.rowGroups.length, 3, 'back to 0 = all destinations');
  const cleared = fresh();
  eq(cleared.location, 0, '0 persists too (hash stored, value 0 = all)');

  globalThis.location.hash = ''; // leave a clean hash behind
}

finish('tests/unit/debt-class.test.mjs');
