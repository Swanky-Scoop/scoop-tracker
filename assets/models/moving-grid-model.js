///////////////////////////////////
// MovingGridModel — "what needs to move where" list (tub-moving feature,
// worktree-tub-moving). Rows: every tub with a `moving_to` set (see
// includes/hooks/cabinet-slot.php — set automatically when a slot at
// another location is scheduled for a flavor with no local stock, or by
// hand), grouped by destination location so "how many tubs need to move to
// Mountlake Terrace" is a straight group-badge count, not a manual tally.
//
// Read-mostly: state/flavor/location aren't editable here (this isn't a
// general tub editor), but `moving_to` itself is — clearing it (blank the
// FindIt) cancels the earmark, reassigning it redirects the same tub
// elsewhere. Cells POST as 'FlavorTub' (see List.writeType) — this grid has
// no write route of its own, same as EmptiedLogGridModel, whose header
// comment explains the same choice.
//////////////////////////////////
import BaseGridModel from "./_base-grid-model.js";

export default class MovingGridModel extends BaseGridModel {
  constructor(name = 'Moving', domain, attrs = {}, metaData = null) {
    super(name, null, attrs, metaData);
    this.autosave = true;
    this.writeEnvelope = 'FlavorTub';

    // No _config.php route entry for 'Moving' (see this file's header
    // comment — writes go through FlavorTub's route, this type has none of
    // its own), so BaseGridModel's icon fallback never gets a server-driven
    // value and would otherwise default to this.name's first letter ('M') —
    // same reasoning as CabinetWorkflowGridModel's own icon override.
    this.icon = 'if:truck';

    this._build();
    if (domain) this.setDomain(domain);
  }

  buildCols() {
    this.columns = [
      { key: 'tub',       label: 'Tub',       type: 'string' },
      { key: 'flavor',    label: 'Flavor',    type: 'string', titleMap: 'flavor' },
      { key: 'location',  label: 'From',      type: 'string', titleMap: 'location' },
      { key: 'moving_to', label: 'Moving to', control: 'find', type: 'int', titleMap: 'location', write: true },
      { key: 'state',     label: 'State',     type: 'string' },
      { key: 'amount',    label: 'Amount',    type: 'number' },
    ];

    this._allColumns = this.columns;
    return this.columns;
  }

  buildRows() {
    if (!this.domain) return [];

    const tubs = Array.isArray(this.domain.tub) ? this.domain.tub : [];
    const items = tubs
      .filter(tub => Number(tub.moving_to))
      .map(tub => this._itemFromTub(tub));

    const buckets = new Map();
    for (const item of items) {
      const key = Number(item.moving_to);
      if (!buckets.has(key)) buckets.set(key, []);
      buckets.get(key).push(item);
    }

    // Newest-first within each destination — same rationale as
    // EmptiedLogGridModel's day buckets: whatever's most likely to need
    // acting on soon (or was earmarked most recently) reads first.
    buckets.forEach(list => list.sort((a, b) => String(b.created_on ?? '').localeCompare(String(a.created_on ?? ''))));

    return this.buildGroupedRows({
      groupsMap     : buckets,
      getGroupLabel : (locId) => this.labelFromMap(locId, this._locationsById()) ?? `Location ${locId}`,
      getGroupBadges: (destItems) => this._destinationBadge(destItems),
      makeRowId     : (item) => item.id,
      fillRow       : (row, item, i) => { this.fillRowFromColumns(row, item, i); },
      collapsed     : false,
      groupType     : 'destination',
      rowType       : 'tub',
      rowLabel      : 'tub',
    });
  }

  _itemFromTub(tub = {}) {
    return {
      id: tub.id,
      tub: tub._title || `Tub ${tub.id}`,
      flavor: tub.flavor || 0,
      location: tub.location || 0,
      moving_to: tub.moving_to || 0,
      state: tub.state ?? '',
      amount: Number(tub.amount ?? 1),
      created_on: tub.created_on ?? '',
    };
  }

  _destinationBadge(items = []) {
    const count = items.length;
    return [
      { key: 'moving', text: `${count} tub${count === 1 ? '' : 's'} to move` },
    ];
  }

  // No _locationsById on BaseGridModel (unlike _flavorsById/_cabinetsById —
  // see setDomain) since most models never need one. Rebuilt fresh every
  // buildRows() call rather than cached — cheap (a handful of locations),
  // and avoids needing a setDomain() override just to invalidate a cache.
  _locationsById() {
    const rows = Array.isArray(this.domain?.location) ? this.domain.location : [];
    return new Map(rows.map(l => [Number(l.id), l]));
  }
}
