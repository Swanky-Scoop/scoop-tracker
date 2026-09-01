///////////////////////////////////
// DebtGridModel — "what does each location OWE its own plan" (tub-moving
// feature, worktree-tub-moving). Companion to MovingGridModel:
//
//   Moving  = supply-side claim list. Rows are tubs that HAVE a moving_to
//             earmark (only tubs that exist can appear — a plan with zero
//             tubs is invisible there by construction).
//   Debt    = demand-side board. Rows are now 1-to-1 with a real,
//             PERSISTED flavor_request post (2026-08-31 redesign) — no
//             longer independently re-derived from a slot scan on every
//             render. Server-side (scoop_sync_flavor_request(),
//             includes/hooks/cabinet-slot.php) auto-creates a
//             flavor_request the moment a slot names a flavor
//             (current_flavor/immediate_flavor) with no local stock, keeps
//             its 'wanted' count synced to slot demand, and tops up its
//             claimed tubs from Woodinville front-of-house/Freezing stock —
//             this file only reads the result, it doesn't compute demand
//             or run the claim search itself anymore.
//
// The arithmetic, per row (a flavor_request post):
//   demand   — req.wanted, straight off the persisted field. No slot-scan
//              or override-merge here anymore — that reconciliation
//              (slots are the floor, a human's Wanted edit can only raise
//              it) now happens server-side, once, at write time.
//   claimed  — count of tubs whose OWN forward `flavor_request` field
//              points at this request's real post id. Deliberately reads
//              tub.flavor_request, never flavor_request.tubs' reverse
//              list — same "trust the forward field" rule slot.tub/tub.slot
//              taught the hard way (see change-tub.md).
//   gap      — max(0, demand - claimed): the "Owed" column. This is a
//              genuinely different number from on_hand/inbound below — it
//              answers "how many tubs are formally committed to this
//              request," not "is stock physically here or moving."
//   on_hand  — FOH whole tubs (amount >= WHOLE_TUB_THRESHOLD, same 0.8 as
//              scoop_find_whole_tubs() in includes/hooks/closeout.php and
//              CabinetWorkflowGridModel's WHOLE_TUB_THRESHOLD) physically AT
//              the destination, state not Emptied/!Lost. Opened counts as
//              on hand — it is physically there and in service.
//   inbound  — whole tubs with moving_to = destination (state not
//              Emptied/!Lost) — a broader signal than "claimed by this
//              request" (moving_to can be set by the older, wider
//              scoop_mark_tub_moving_if_needed() pool too).
//   available — FOH whole PIPELINE tubs of the flavor located anywhere ELSE,
//              not already earmarked elsewhere (moving_to falsy): the pool
//              that could actually be sent. Opened excluded — an open tub
//              is in service where it sits. This is CabinetWorkflowGridModel
//              promotablePool()'s eligibility, aggregated across locations
//              instead of per-slot.
//   status   — still driven by on_hand/inbound vs available (UNCHANGED
//              formula, kept deliberately separate from the new Owed
//              number — see gap's own note above): fillable (on_hand+inbound
//              short, but stock exists elsewhere), unfillable (short, and
//              nothing sendable — the churn queue), pending (covered on
//              paper by an inbound tub), covered (satisfied by local stock
//              alone).
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

// Pure per-request arithmetic — exported so it can be exercised directly
// (same-box node harness) without instantiating the grid model or its
// BaseGridModel/DOM dependencies. Input rows are raw bundle rows: requests
// need id/location/flavor/wanted; tubs need
// flavor/state/use/amount/location/moving_to/flavor_request.
export function computeDebtRows({ tubs = [], requests = [], useTitleOf = () => '' } = {}) {
  // 1) Claims: how many tubs' own forward flavor_request field names each
  //    request. See this file's header note on why the forward field, not
  //    flavor_request.tubs' reverse list.
  const claimed = new Map(); // requestId -> count
  for (const tub of Array.isArray(tubs) ? tubs : []) {
    const reqId = Number(tub?.flavor_request ?? 0);
    if (!reqId) continue;
    claimed.set(reqId, (claimed.get(reqId) ?? 0) + 1);
  }

  // 2) Supply: bucket every non-dead tub once — on-hand (at dest, any
  //    non-dead state), inbound (moving_to = dest), available (elsewhere,
  //    pipeline, unearmarked). Unchanged from before this redesign; still
  //    joined per (destination, flavor) below, independent of the claim
  //    count above.
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

  // 3) One row per flavor_request post — row EXISTENCE is 1-to-1 with a
  //    real, persisted request now (server auto-creates one; see this
  //    file's header note), not re-derived from a slot scan here.
  const rows = [];
  for (const req of Array.isArray(requests) ? requests : []) {
    const reqId    = Number(req?.id ?? 0);
    const destId   = Number(req?.location ?? 0);
    const flavorId = Number(req?.flavor ?? 0);
    if (!reqId || !destId || !flavorId) continue;

    const key      = `${destId}:${flavorId}`;
    const demand   = Number(req?.wanted ?? 0);
    const claimedN = claimed.get(reqId) ?? 0;
    const gap      = Math.max(0, demand - claimedN);

    const onHandN  = onHand.get(key)  ?? 0;
    const inboundN = inbound.get(key) ?? 0;

    const perLoc  = availAt.get(flavorId) ?? new Map();
    const availN  = [...perLoc.entries()]
      .filter(([src]) => Number(src) !== destId)
      .reduce((sum, [, n]) => sum + n, 0);

    // Status deliberately still reads the on_hand/inbound-derived gap, NOT
    // the new claim-based Owed number above — see this file's header note
    // on why the two are kept independent.
    const legacyGap = Math.max(0, demand - onHandN - inboundN);
    let status;
    if (legacyGap > 0)     status = availN > 0 ? 'fillable' : 'unfillable';
    else if (inboundN > 0) status = 'pending';
    else                   status = 'covered';

    rows.push({
      id: debtRowId(destId, flavorId),
      requestId: reqId,
      destination: destId,
      flavor: flavorId,
      demand,
      claimed: claimedN,
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

    this._build();
    if (domain) this.setDomain(domain);
  }

  buildCols() {
    this.columns = [
      { key: 'flavor',    label: 'Flavor',        type: 'string', titleMap: 'flavor' },
      // The ONE writeable cell on this board. Writes upsert the request's
      // 'wanted' field via /debt-requests (see the route comment in
      // includes/_routes.php) and top up its claimed tubs to match
      // (scoop_topup_flavor_request_claims(), includes/rest.php). Slots are
      // still the floor server-side — a later slot save re-raises 'wanted'
      // back up to slot-implied demand even after a human lowers it — but
      // that reconciliation happens in PHP now, not here. TextIt control,
      // same hand-authored convention as any model-authored number column.
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
  // carries every location's slots/tubs). 0 = all destinations. No other
  // filter right now — a "Hide covered" checkbox lived here before
  // 2026-08-31 but was removed (usage pattern still unclear; may come back
  // once it is) — getFilterValue/setFilterValue need no override for just
  // this one filter, the base class already handles 'location' on its own.
  getFilterDefs() {
    return [this._locationFilterDef('Destination')];
  }

  buildRows() {
    if (!this.domain) return [];

    const useRows = Array.isArray(this.domain.use) ? this.domain.use : [];
    const useTitleById = new Map(useRows.map(u => [Number(u.id), u._title || u.title?.rendered || '']));

    let items = computeDebtRows({
      tubs:  Array.isArray(this.domain.tub)  ? this.domain.tub  : [],
      requests: Array.isArray(this.domain.flavor_request) ? this.domain.flavor_request : [],
      useTitleOf: (id) => useTitleById.get(Number(id)) ?? '',
    });

    // Client-side narrowing — the bundle already carries every location's
    // slots/tubs, so nothing here changes what gets fetched.
    if (Number(this.location) > 0) {
      items = items.filter(item => Number(item.destination) === Number(this.location));
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
        row.claimed   = item.claimed;
        row.gap       = item.gap;
        row.available = item.available;
        row.status    = item.status;
        row.requestId = item.requestId;
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
