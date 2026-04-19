///////////////////////////////////
// AnalyticsGridModel
//
// Read-only grid model for the analytics dashboard.
// Extends BaseGridModel so Analytics participates in the shared bundle flow
// the same way as Cabinet, FlavorTub, Batch, Closeout, and DateActivity.
//
// Bundle path: mountAllGrids() calls setDomain(bundle.data) → reads
//   domain.analytics (same shape as GET /wp-json/scoop/v1/analytics).
//
// Standalone path: fetch() hits the dedicated endpoint directly and calls
//   setDomain({ analytics: json }) so both paths share buildRows().
///////////////////////////////////

import BaseGridModel from "./_base-grid-model.js";

export default class AnalyticsGridModel extends BaseGridModel {

  /**
   * @param {string}      name              Grid type identifier ("Analytics")
   * @param {Object|null} [domain]          Pass null when constructing before
   *                                        domain is available (bundle flow).
   *                                        Kept for API compatibility with
   *                                        BaseGridModel(name, domain, options).
   * @param {Object}      [options]
   * @param {number}      [options.location]  Location filter ID (0 = all)
   * @param {number}      [options.days]      Analysis period in days (default 30)
   * @param {string}      [options.nonce]     WP REST nonce for authentication
   */
  constructor( name = "Analytics", domain = null, options = {} ) {
    // Pass null domain — we handle it ourselves after setting instance state.
    super( name, null, options );

    this.days  = options.days  ?? 30;
    this.nonce = options.nonce ?? ( typeof SCOOP !== "undefined" ? SCOOP.nonce : null );
    this.raw   = null;

    // Explicit column build: base won't auto-build (no metaData).
    this._buildColumns();

    // Now deliver domain if provided (calls our overridden setDomain/buildRows).
    if ( domain ) this.setDomain( domain );
  }


  // ── Column definitions ────────────────────────────────────────────────────

  buildCols() {
    this._allColumns = [
      { key: "flavor_name",          label: "Flavor",           type: "string" },
      { key: "total_sold",           label: "Total Sold",       type: "number" },
      { key: "sell_rate_per_day",    label: "Rate (tubs/day)",  type: "number" },
      { key: "avg_sellthrough_days", label: "Avg Days to Sell", type: "number" },
      { key: "current_stock",        label: "Current Stock",    type: "number" },
      { key: "days_of_supply",       label: "Days of Supply",   type: "number" },
      { key: "trend",                label: "Trend",            type: "string" },
    ];

    this._applyColumnFilter();
    return this.columns;
  }


  // ── Domain handling ───────────────────────────────────────────────────────

  setDomain( domain ) {
    super.setDomain( domain );
  }

  buildRows() {
    const a = this.domain?.analytics;
    if ( ! a?.ok || ! Array.isArray( a.flavors ) ) {
      this.rows = [];
      this.raw  = a ?? null;
      return this.rows;
    }

    this.raw  = a;
    this.rows = a.flavors.map( ( f, i ) => {
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


  // ── Data fetch ────────────────────────────────────────────────────────────

  /**
   * Fetch analytics data from the REST endpoint (standalone path).
   * Retained for any page using Analytics alone (without the bundle).
   * Terminates via setDomain() so both bundle and standalone paths
   * share the same buildRows() code path.
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

    // Route through setDomain so bundle and standalone share buildRows()
    this.setDomain( { analytics: json } );
    return this;
  }


  // ── BaseGridModel overrides ───────────────────────────────────────────────

  /** Analytics is read-only; no change descriptions to generate. */
  describeFieldChanges( responseData, rawChanges ) {
    return [];
  }
}
