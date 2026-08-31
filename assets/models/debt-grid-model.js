///////////////////////////////////
// DebtGridModel — "what does each location OWE its own plan" (tub-moving
// feature, worktree-tub-moving). Companion to MovingGridModel:
//
//   Moving  = supply-side claim list. Rows are tubs that HAVE a moving_to
//             earmark (only tubs that exist can appear — a plan with zero
//             tubs is invisible there by construction).
//   Debt    = demand-side arithmetic. Rows are (destination, flavor) pairs
//             derived from SLOT designations — current_flavor/immediate_flavor
//             at a location imply that location's plan wants tubs of that
//             flavor, whether or not any such tub exists yet. This is what
//             makes "flavor debt" visible: Woodinville is where every flavor
//             is created, so a Mountlake Terrace slot scheduled for a flavor
//             with no Mountlake Terrace tub is a tub owed to Mountlake
//             Terrace — even when the tub count anywhere is zero.
//
// Purely derived — reads slot/tub/use/location straight off the bundle, has
// no write route of its own and nothing to persist (see the planning
// conversation: a persisted move-request schema was compared against this
// and parked; the slot designation IS the plan, moving_to is only a claim
// against it). Every number here recomputes from live data on every
// buildRows(), so it can never go stale — the flip side is it can only see
// demand the slots actually express.
//
// The arithmetic, per (destination, flavor):
//   demand   — how many designations at this destination name this flavor
//              (each slot's current_flavor or immediate_flavor = 1 tub
//              wanted; next_flavor deliberately excluded, see the planning
//              conversation — the column is rarely used and the auto-earmark
//              hook already excludes it).
//   on_hand  — FOH whole tubs (amount >= WHOLE_TUB_THRESHOLD, same 0.8 as
//              scoop_find_whole_tubs() in includes/hooks/closeout.php and
//              CabinetWorkflowGridModel's WHOLE_TUB_THRESHOLD) physically AT
//              the destination, state not Emptied/!Lost. Opened counts as
//              on hand — it is physically there and in service; the moment
//              it's emptied, gap re-opens on the next recompute (derived
//              views self-correct, that's the point). This is a deliberate
//              divergence from remainingSummary()'s pipeline-only reading —
//              that answers "can I promote a FRESH tub into a slot from
//              stock", this answers "does the location have enough of the
//              flavor for its plan".
//   inbound  — whole tubs with moving_to = destination (state not
//              Emptied/!Lost): claims already made against this debt, from
//              any source location.
//   gap      — max(0, demand - on_hand - inbound): still-owed tubs.
//   available — FOH whole PIPELINE tubs of the flavor located anywhere ELSE,
//              not already earmarked elsewhere (moving_to falsy): the pool
//              that could actually be sent. Opened excluded — an open tub
//              is in service where it sits. This is CabinetWorkflowGridModel
//              promotablePool()'s eligibility, aggregated across locations
//              instead of per-slot.
//   status   — fillable (gap > 0, available > 0): sendable now.
//              unfillable (gap > 0, available = 0): the churn queue —
//              Woodinville needs to make this flavor before any tub can move.
//              pending (gap = 0, inbound > 0): covered by tubs already
//              earmarked/arriving.
//              covered (gap = 0, no inbound): the plan is satisfied by local
//              stock alone.
//
// "Front of House" is resolved id-first (FRONT_OF_HOUSE_USE_ID, matching
// CabinetWorkflowGridModel's constant and the PHP hook's
// SCOOP_FRONT_OF_HOUSE_USE_ID — three hardcoded copies, kept in sync by
// hand per environment, a known wart recorded in the planning conversation)
// with the same normalized-label fallback flavor-tub-grid-model.js's
// _isFrontOfHouseUse established, so a differently-numbered environment
// still classifies correctly by name.
///////////////////////////////////
import BaseGridModel from "./_base-grid-model.js";
import HashState     from "../data/hash-state.js";

const FRONT_OF_HOUSE_USE_ID = 1863;

// Whole-tub threshold — deliberately the same number as
// CabinetWorkflowGridModel.WHOLE_TUB_THRESHOLD and
// scoop_find_whole_tubs() in includes/hooks/closeout.php. Kept in sync
// deliberately, not an independent choice (same comment all three carry).
const WHOLE_TUB_THRESHOLD = 0.8;

// States that mean "this tub cannot satisfy anyone's plan" — dead stock.
const DEAD_STATES = new Set(['Emptied', '!Lost']);

// Pipeline-only states for the "available to send" pool: an Opened tub is in
// service where it sits and cannot be shipped to another location (same
// reasoning as promotablePool's Opened exclusion — adoption is a LOCAL
// fix, never a transfer).
const OPEN_STATE = 'Opened';

// Status sort rank — fillable first (an action exists), then unfillable
// (the churn queue needs a human/churn decision), then pending (already
// being handled), covered last (informational).
const STATUS_RANK = { fillable: 0, unfillable: 1, pending: 2, covered: 3 };

// Synthetic numeric row id for a (location, flavor) pair — the Debt board's
// rows are NOT posts, but List's write path requires numeric row ids
// (_buildDirtyPayload Number()s the id out of the input name; a string key
// like "1010:600" would become NaN and silently drop the edit). The
// /debt-requests route decodes these back into the pair (floor(id/100000),
// id%100000) — see scoop_parse_debt_requests(). Supports flavor ids up to
// 99999; flavor ids are small sequential Pods posts, far under that.
const PAIR_ID_MULTIPLIER = 100000;

export function debtRowId(locationId, flavorId) {
  return Number(locationId) * PAIR_ID_MULTIPLIER + Number(flavorId);
}

export function isFrontOfHouseUse(useId, useTitle) {
  const id = Number(useId ?? 0);
  if (id && id === FRONT_OF_HOUSE_USE_ID) return true;
  // Same normalization as flavor-tub-grid-model.js._isFrontOfHouseUse —
  // "Front-of-House", "front_of_house", "Front of House" all match.
  const normalized = String(useTitle ?? '')
    .toLowerCase()
    .replace(/&amp;/g, '&')
    .replace(/[-_]+/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
  return !id || normalized === 'front of house';
}

// Pure (destination, flavor) arithmetic — exported so it can be exercised
// directly (same-box node harness) without instantiating the grid model or
// its BaseGridModel/DOM dependencies. Input rows are raw bundle rows:
// slots need location/current_flavor/immediate_flavor; tubs need
// flavor/state/use/amount/location/moving_to.
export function computeDebtRows({ slots = [], tubs = [], requests = [], useTitleOf = () => '' } = {}) {
  // 1) Demand: every current/immediate designation names a (location, flavor)
  //    pair that wants a tub.
  const demand = new Map(); // "locId:flavorId" -> count
  for (const slot of Array.isArray(slots) ? slots : []) {
    const loc = Number(slot?.location ?? 0);
    if (!loc) continue;
    for (const f of ['current_flavor', 'immediate_flavor']) {
      const flavorId = Number(slot?.[f] ?? 0);
      if (!flavorId) continue;
      const key = `${loc}:${flavorId}`;
      demand.set(key, (demand.get(key) ?? 0) + 1);
    }
  }

  // 1b) Persisted demand overrides (flavor_request, written by this grid's
  //     editable Wanted column through /debt-requests): a request STATES how
  //     many are wanted outright — replaces, never adds to, the slot-implied
  //     count for its pair, so planning can ask for more tubs than slots
  //     currently imply without double-counting the slots it already
  //     reflects. Slots remain the floor: effective = max(slot-implied,
  //     requested).
  for (const req of Array.isArray(requests) ? requests : []) {
    const loc = Number(req?.location ?? 0);
    const fid = Number(req?.flavor ?? 0);
    const wanted = Number(req?.wanted ?? 0);
    if (!loc || !fid) continue;
    const key = `${loc}:${fid}`;
    demand.set(key, Math.max(demand.get(key) ?? 0, wanted));
  }

  // 2) Supply: bucket every non-dead tub once — on-hand (at dest, any
  //    non-dead state), inbound (moving_to = dest), available (elsewhere,
  //    pipeline, unearmarked). One pass, then join against demand.
  const onHand  = new Map(); // "locId:flavorId" -> count
  const inbound = new Map(); // "locId:flavorId" -> count
  const avail   = new Map(); // "flavorId" -> count (source-agnostic pool)
  const availAt = new Map(); // "flavorId" -> Map<sourceLocId, count>

  for (const tub of Array.isArray(tubs) ? tubs : []) {
    const state = String(tub?.state ?? '');
    if (DEAD_STATES.has(state)) continue;
    if (Number(tub?.amount ?? 1) < WHOLE_TUB_THRESHOLD) continue;

    const flavorId = Number(tub?.flavor ?? 0);
    const locId    = Number(tub?.location ?? 0);
    const movingTo = Number(tub?.moving_to ?? 0);
    const foh      = isFrontOfHouseUse(tub?.use, useTitleOf(tub?.use));
    if (!flavorId) continue;

    if (movingTo) {
      const key = `${movingTo}:${flavorId}`;
      inbound.set(key, (inbound.get(key) ?? 0) + 1);
      continue; // an earmarked tub is claimed — never also "available"
    }

    if (foh) {
      if (locId) {
        const key = `${locId}:${flavorId}`;
        onHand.set(key, (onHand.get(key) ?? 0) + 1);
      }
      if (state !== OPEN_STATE && locId) {
        // Sendable pool: FOH + pipeline + physically somewhere + not claimed.
        // Non-FOH pipeline tubs (Back-of-House etc.) are deliberately NOT
        // available to fill a front-of-house plan — same eligibility the
        // PHP earmark hook and promotablePool both enforce.
        avail.set(flavorId, (avail.get(flavorId) ?? 0) + 1);
        if (!availAt.has(flavorId)) availAt.set(flavorId, new Map());
        const perLoc = availAt.get(flavorId);
        perLoc.set(locId, (perLoc.get(locId) ?? 0) + 1);
      }
    }
  }

  // 3) Join: one row per demanded (destination, flavor) — demand is the
  //    driver; a pair with demand but zero tubs anywhere STILL gets a row
  //    (the flavor-debt case MovingGridModel structurally cannot show).
  const rows = [];
  for (const [key, dem] of demand.entries()) {
    const [locStr, flavorStr] = key.split(':');
    const destId = Number(locStr), flavorId = Number(flavorStr);

    const onHandN  = onHand.get(key)  ?? 0;
    const inboundN = inbound.get(key) ?? 0;
    const gap      = Math.max(0, dem - onHandN - inboundN);

    const perLoc  = availAt.get(flavorId) ?? new Map();
    const availN  = [...perLoc.entries()]
      .filter(([src]) => Number(src) !== destId)
      .reduce((sum, [, n]) => sum + n, 0);

    let status;
    if (gap > 0)      status = availN > 0 ? 'fillable' : 'unfillable';
    else if (inboundN > 0) status = 'pending';
    else                   status = 'covered';

    rows.push({
      id: debtRowId(destId, flavorId),
      destination: destId,
      flavor: flavorId,
      demand: dem,
      on_hand: onHandN,
      inbound: inboundN,
      gap,
      available: availN,
      status,
    });
  }

  return rows;
}

export default class DebtGridModel extends BaseGridModel {
  constructor(name = 'Debt', domain, attrs = {}) {
    super(name, null, attrs);

    // Override BaseGridModel's location resolution (same reasoning as
    // EmptiedLogGridModel's constructor): this board's whole point is
    // cross-location ("what does each location owe"), so the page's
    // location default (dock picker / shortcode / 935 fallback) must NOT
    // scope it. Only an explicit filter choice from THIS grid persists
    // (HashState `loc.Debt`); everything else starts at 0 = all
    // destinations.
    const hashOverride = HashState.get(`loc.${this.name}`);
    this.location = hashOverride != null ? Number(hashOverride) : 0;

    // Read-mostly, ONE writeable column: the Wanted cell upserts a
    // flavor_request demand override through the dedicated /debt-requests
    // route (this.name = 'Debt' is the envelope key the List posts under —
    // see scoop_handle_debt_requests_post in includes/rest.php). Everything
    // else is computed. Editing demand's OTHER source happens where it
    // lives — the Cabinet grid's slot flavor fields.
    // Full autosave, not partial: FlavorTub's history (mixed autosaved +
    // manual fields on one grid read as data loss even when nothing was
    // lost — see its constructor comment) rules out autosaveFields; with
    // exactly one writeable column, full autosave IS the partial case.
    this.autosave = true;
    this.writeEnvelope = 'Debt';
    // URL vs envelope split: envelope key stays 'Debt' (the server handler
    // reads $req->get_param('Debt')); the POST goes to the dedicated
    // /debt-requests route (registered in includes/_routes.php, URL emitted
    // in includes/enqueue.php's DebtRequests entry) — Debt is display-only
    // in scoop_routes_config() so it has no config path of its own.
    this.writeRoute = 'DebtRequests';

    // Non-location filter values (see setFilterValue/getFilterValue below —
    // location itself rides the base class's this.location + HashState
    // persistence, same as every location-scoped model).
    this.filterValues = { hide_covered: 'true' };

    this._build();
    if (domain) this.setDomain(domain);
  }

  buildCols() {
    this.columns = [
      { key: 'flavor',    label: 'Flavor',        type: 'string', titleMap: 'flavor' },
      // Editable demand override — the ONE writeable cell on this board.
      // Writes upsert a flavor_request row via /debt-requests (see the
      // route comment in includes/_routes.php): a positive number replaces
      // the pair's slot-implied demand (max wins), 0 deletes the override
      // so slots rule again. TextIt control, same hand-authored convention
      // as any model-authored number column.
      { key: 'demand',    label: 'Wanted',        type: 'number', control: 'text', write: true },
      { key: 'on_hand',   label: 'On hand',       type: 'number' },
      { key: 'inbound',   label: 'Inbound',       type: 'number' },
      { key: 'gap',       label: 'Owed',          type: 'number' },
      { key: 'available', label: 'Ready to send', type: 'number' },
      { key: 'status',    label: 'Status',        type: 'string' },
    ];

    this._allColumns = this.columns;
    return this.columns;
  }

  // Narrow the board to one destination (client-side — the bundle already
  // carries every location's slots/tubs). 0 = all destinations.
  getFilterDefs() {
    return [
      this._locationFilterDef('Destination'),
      {
        key: 'hide_covered',
        label: 'Hide covered',
        type: 'checkbox',
        mode: 'client',
        default: 'true',
        group: 'columns',
        groupLabel: 'Rows',
      },
    ];
  }

  // Location rides the base class (this.location via getFilterValue —
  // 0 = all destinations, HashState-persisted per grid name, same as every
  // location-scoped model). Everything else rides this.filterValues, the
  // ItemPivot pattern.
  getFilterValue(key) {
    if (key === 'location') return super.getFilterValue(key);
    return this.filterValues?.[key] ?? 'true';
  }

  setFilterValue(key, value) {
    if (key === 'location') return super.setFilterValue(key, value);
    if (!key) return;
    this.filterValues[key] = String(value ?? 'all');
  }

  buildRows() {
    if (!this.domain) return [];

    const useRows = Array.isArray(this.domain.use) ? this.domain.use : [];
    const useTitleById = new Map(useRows.map(u => [Number(u.id), u._title || u.title?.rendered || '']));

    let items = computeDebtRows({
      slots: Array.isArray(this.domain.slot) ? this.domain.slot : [],
      tubs:  Array.isArray(this.domain.tub)  ? this.domain.tub  : [],
      requests: Array.isArray(this.domain.flavor_request) ? this.domain.flavor_request : [],
      useTitleOf: (id) => useTitleById.get(Number(id)) ?? '',
    });

    // Client-side narrowing — the bundle already carries every location's
    // slots/tubs, so nothing here changes what gets fetched.
    if (Number(this.location) > 0) {
      items = items.filter(item => Number(item.destination) === Number(this.location));
    }
    if (this.getFilterValue('hide_covered') === 'true') {
      items = items.filter(item => item.status !== 'covered');
    }

    // Group by destination; sort groups by total owed desc, then label —
    // the location owing the most reads first.
    const byDest = new Map();
    for (const item of items) {
      if (!byDest.has(item.destination)) byDest.set(item.destination, []);
      byDest.get(item.destination).push(item);
    }

    const destLabel = (id) => this.labelFromMap(id, this._locationsById()) ?? `Location ${id}`;
    const destinations = [...byDest.keys()].sort((a, b) => {
      const owed = (id) => byDest.get(id).reduce((s, r) => s + r.gap, 0);
      return owed(b) - owed(a) || destLabel(a).localeCompare(destLabel(b));
    });

    const ordered = new Map();
    for (const destId of destinations) {
      const rows = byDest.get(destId);
      // Within a destination: work queue order (status rank), then biggest
      // demand first, then flavor label — deterministic, actionable-first.
      rows.sort((a, b) =>
        (STATUS_RANK[a.status] - STATUS_RANK[b.status])
        || (b.demand - a.demand)
        || String(a.flavor).localeCompare(String(b.flavor)),
      );
      ordered.set(destId, rows);
    }

    return this.buildGroupedRows({
      groupsMap:     ordered,
      getGroupLabel: destLabel,
      getGroupBadges: (destItems) => this._destinationBadges(destItems),
      makeRowId:     (item) => item.id,
      fillRow:       (row, item) => {
        // flavor must be the {id, display} cell object the render path
        // expects (List reads row[col.key] verbatim — _list.js
        // _renderFieldValue): d.display is the text shown, d.id + the
        // column's titleMap ('flavor') is what makes it a detail link to
        // the flavor's modal. A bare number here renders as the raw id with
        // no link — exactly the bug. Same lookup other models use
        // (titleById + _flavorsById, built by setDomain).
        row.flavor    = {
          id:      item.flavor,
          rowId:   item.id,
          display: this.titleById(this._flavorsById, item.flavor, `Flavor ${item.flavor}`),
        };
        // Wanted is the one writeable cell: d.write !== false + col.write
        // put a TextIt input here (see _renderFieldValue's write branch).
        // TextIt reads data.value ?? data.display and posts the input name
        // Debt[cells][<rowId>][demand] — rowId is the synthetic numeric
        // pair id the /debt-requests route decodes. colKey is REQUIRED —
        // TextIt builds the input name from data.colKey ?? data.key, and
        // fillRowFromColumns always sets it; without it the autosave posts
        // an empty field name and the server drops the edit.
        row.demand    = {
          id:      item.id,
          rowId:   item.id,
          colKey:  'demand',
          display: String(item.demand),
          value:   item.demand,
          write:   this._demandWriteable(),
          type:    'number',
          min:     0,
          max:     99,
          step:    1,
        };
        row.on_hand   = item.on_hand;
        row.inbound   = item.inbound;
        row.gap       = item.gap;
        row.available = item.available;
        row.status    = item.status;
      },
      collapsed: false,
      groupType: 'location',
      rowType:   'debt',
      rowLabel:  'debt',
    });
  }

  // Whether the CURRENT USER may write the Wanted column — read straight
  // off the server-computed Debt metadata rather than duplicating the role
  // matrix here. scoop_client_metadata() now emits canPost on every type
  // (see includes/enqueue.php), resolved by the same scoop_user_can_route
  // check the /debt-requests route's own permission callback runs — so
  // what the cell shows matches exactly what the server will accept
  // (ice_cream_maker is the view-only Debt role as of 2026-08-30; see
  // tests/smoke/tests/debt-wanted-readonly.spec.js, which exercises this
  // branch in a browser).
  _demandWriteable() {
    const md = window.SCOOP?.metaData?.Debt;
    if (!md) return true; // no server metadata at all (dev/test harness) — let the column's own write flag speak
    return !!md.canPost;
  }

  // Group badge — the destination's whole story in one line:
  // "3 owed · 1 fillable · 1 unfillable" / "2 inbound" / "covered".
  _destinationBadges(destItems = []) {
    const owed      = destItems.reduce((s, r) => s + r.gap, 0);
    const fillable  = destItems.filter(r => r.status === 'fillable').length;
    const unfill    = destItems.filter(r => r.status === 'unfillable').length;
    const inbound   = destItems.reduce((s, r) => s + r.inbound, 0);

    const parts = [];
    if (owed > 0) {
      parts.push(`${owed} owed`);
      if (fillable > 0) parts.push(`${fillable} fillable`);
      if (unfill > 0)   parts.push(`${unfill} need churning`);
    } else if (inbound > 0) {
      parts.push(`${inbound} inbound`);
    } else {
      parts.push('covered');
    }
    return [{ key: 'debt', text: parts.join(' · ') }];
  }

  // No _locationsById on BaseGridModel (unlike _flavorsById/_cabinetsById —
  // see setDomain). Rebuilt fresh every call — cheap (a handful of
  // locations), and avoids needing a setDomain() override just to
  // invalidate a cache. Same choice as MovingGridModel.
  _locationsById() {
    const rows = Array.isArray(this.domain?.location) ? this.domain.location : [];
    return new Map(rows.map(l => [Number(l.id), l]));
  }
}
