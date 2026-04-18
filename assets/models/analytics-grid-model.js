///////////////////////////////////
// AnalyticsGridModel
//
// Read-only grid model for the analytics dashboard.
// Fetches computed sales velocity data from GET /wp-json/scoop/v1/analytics
// and renders a flat table sorted by sell_rate_per_day (fastest sellers first).
//
// Unlike other grid models, this one:
//   - Does NOT extend BaseGridModel (no domain bundle, no dirty tracking, no save)
//   - Fetches from its own dedicated endpoint rather than the bundle
//   - All columns are read-only
//   - Owns its own data lifecycle (fetch, transform, render)
///////////////////////////////////

export default class AnalyticsGridModel {

  /**
   * @param {string}        name              Grid type identifier ("Analytics")
   * @param {Object|null}   [domain]          Pass null when constructing before
   *                                          domain is available (bundle flow).
   *                                          Kept for API compatibility with
   *                                          BaseGridModel(name, domain, options).
   * @param {Object}        [options]
   * @param {number}        [options.location]  Location filter ID (0 = all)
   * @param {number}        [options.days]      Analysis period in days (default 30)
   * @param {string}        [options.nonce]     WP REST nonce for authentication
   */
  constructor( name = "Analytics", domain = null, options = {} ) {
    this.name     = name;
    this.location = options.location ?? 0;
    this.days     = options.days     ?? 30;
    this.nonce    = options.nonce    ?? (typeof SCOOP !== "undefined" ? SCOOP.nonce : null);

    this.columns   = [];
    this.rows      = [];
    this.rowGroups = [];
    this.raw       = null;  // raw API response for inspection

    this._buildColumns();

    if ( domain ) this.setDomain( domain );
  }


  // ── Column definitions ────────────────────────────────────────────────────

  _buildColumns() {
    this.columns = [
      {
        key:   "flavor_name",
        label: "Flavor",
        type:  "string",
      },
      {
        key:   "total_sold",
        label: "Total Sold",
        type:  "number",
      },
      {
        key:   "sell_rate_per_day",
        label: "Rate (tubs/day)",
        type:  "number",
      },
      {
        key:   "avg_sellthrough_days",
        label: "Avg Days to Sell",
        type:  "number",
      },
      {
        key:   "current_stock",
        label: "Current Stock",
        type:  "number",
      },
      {
        key:   "days_of_supply",
        label: "Days of Supply",
        type:  "number",
      },
      {
        key:   "trend",
        label: "Trend",
        type:  "string",
      },
    ];

    return this.columns;
  }


  // ── Data fetch ────────────────────────────────────────────────────────────

  /**
   * Fetch analytics data from the REST endpoint.
   * Call this after construction, then pass this model to a Grid.
   *
   * @return {Promise<AnalyticsGridModel>}  Returns self for chaining.
   */
  async fetch() {
    const url = new URL( "/wp-json/scoop/v1/analytics", window.location.origin );
    url.searchParams.set( "days", String( this.days ) );
    if ( this.location ) {
      url.searchParams.set( "location", String( this.location ) );
    }
    // Cache-bust
    url.searchParams.set( "_ts", String( Date.now() ) );

    const headers = { Accept: "application/json" };
    if ( this.nonce ) {
      headers["X-WP-Nonce"] = this.nonce;
    }

    const res  = await fetch( url, { credentials: "include", headers } );
    const json = await res.json();

    if ( ! json?.ok ) {
      console.error( "AnalyticsGridModel: endpoint returned error", json );
      this.raw  = json;
      this.rows = [];
      return this;
    }

    this.raw = json;
    this._buildRows( json.flavors ?? [] );
    return this;
  }


  // ── Row construction ──────────────────────────────────────────────────────

  /**
   * Transform the API response into Grid-compatible row objects.
   * Each cell is { display, value, ... } matching what Grid._getCellDom reads
   * for read-only cells (it uses data.display when col.write is falsy).
   *
   * @param {Array} flavors  Array of flavor objects from the API
   */
  _buildRows( flavors ) {
    this.rows = flavors.map( ( f, i ) => {
      const row = { id: f.flavor_id ?? i };

      // Flavor name — plain text
      row.flavor_name = {
        display: f.flavor_name ?? "",
        value:   f.flavor_name ?? "",
      };

      // Total sold in the period
      row.total_sold = {
        display: this._fmtNum( f.total_sold, 1 ),
        value:   f.total_sold ?? 0,
      };

      // Daily sell rate
      row.sell_rate_per_day = {
        display: this._fmtNum( f.sell_rate_per_day, 2 ),
        value:   f.sell_rate_per_day ?? 0,
      };

      // Average days from batch to emptied
      row.avg_sellthrough_days = {
        display: f.avg_sellthrough_days != null
          ? this._fmtNum( f.avg_sellthrough_days, 1 )
          : "--",
        value: f.avg_sellthrough_days ?? null,
      };

      // Current tub count
      row.current_stock = {
        display: String( f.current_stock ?? 0 ),
        value:   f.current_stock ?? 0,
      };

      // Days of supply with color-coding via alertCase
      // Grid applies alertCase as a CSS class on the <td>,
      // so the stylesheet can target .supply-critical, .supply-warning, .supply-ok
      const dos = f.days_of_supply;
      let supplyAlert = "supply-ok";
      if ( dos != null ) {
        if ( dos < 3 )      supplyAlert = "supply-critical";
        else if ( dos < 7 ) supplyAlert = "supply-warning";
      }

      row.days_of_supply = {
        display:   dos != null ? this._fmtNum( dos, 1 ) : "--",
        value:     dos ?? null,
        alertCase: supplyAlert,
      };

      // Trend with directional arrow and percentage
      const arrow = f.trend === "rising"  ? "\u2191"   // up arrow
                  : f.trend === "falling" ? "\u2193"    // down arrow
                  : "\u2192";                           // right arrow (steady)
      const pct   = f.trend_pct != null ? ` ${f.trend_pct > 0 ? "+" : ""}${this._fmtNum( f.trend_pct, 1 )}%` : "";

      row.trend = {
        display:   `${arrow}${pct}`,
        value:     f.trend ?? "steady",
        alertCase: `trend-${f.trend ?? "steady"}`,
      };

      return row;
    } );

    return this.rows;
  }


  // ── Formatting helpers ────────────────────────────────────────────────────

  /**
   * Format a number to the given decimal places, stripping trailing zeros.
   *
   * @param {number} n
   * @param {number} decimals
   * @return {string}
   */
  _fmtNum( n, decimals = 1 ) {
    if ( n == null || ! Number.isFinite( n ) ) return "--";
    // parseFloat strips trailing zeros from toFixed
    return String( parseFloat( n.toFixed( decimals ) ) );
  }


  // ── Grid interface stubs ──────────────────────────────────────────────────
  // These exist so Grid can call them without blowing up, even though
  // this model has no editable cells, dirty tracking, or domain bundle.

  /**
   * Populate rows from the bundle domain.
   *
   * The bundle places analytics data at domain.analytics — the same shape
   * returned by GET /wp-json/scoop/v1/analytics.  If the key is absent (e.g.
   * a bundle request that pre-dates this change) the model stays empty rather
   * than throwing.
   *
   * @param {Object} domain  Full bundle data object (bundle.data).
   */
  setDomain( domain ) {
    const a = domain?.analytics;
    if ( a?.ok && Array.isArray( a.flavors ) ) {
      this.raw = a;
      this._buildRows( a.flavors );
    }
  }

  /** Read-only grid has no save target. */
  get submitMode() { return null; }

  /** No editable columns means no change descriptions. */
  describeFieldChanges() { return []; }

  /** No title maps needed for read-only display. */
  getTitleMap() { return new Map(); }
  titleFrom( id ) { return String( id ); }

  /** No badges on analytics rows. */
  getBadges()    { return []; }
  getOptions()   { return []; }
  getAlertCase() { return ""; }
}
