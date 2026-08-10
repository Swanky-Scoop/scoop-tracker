import Grid      from "./grid.js";
import List      from "./_list.js";
import El        from "./_el.js";
import FormCodec from "../data/form-codec.js";

// Halo (decoration) sizing: DECO_MIN_R_PX is a floor so a zero-magnitude
// trend point still shows a visible ring; the ceiling is a fraction of the
// chart's own rendered WIDTH only (see _drawPlotContent) — a height-only
// resize (row grows/shrinks) never changes it.
const DECO_MIN_R_PX = 3;
const DECO_MAX_WIDTH_RATIO = 0.05;

// trend -> center-mark shape, so direction reads even without color (the
// existing trend-based fill on the center mark stays too, as a second,
// reinforcing cue rather than being replaced).
const CENTER_SHAPE_BY_TREND = { rising: "triangle-up", falling: "triangle-down", steady: "circle" };

// dairy/non-dairy -> halo hue. Table-driven (not an if/else) so a future
// category (e.g. per-allergen) is a one-line addition, not a new branch.
const CATEGORY_HUES = {
  dairy:       { bright: [0, 204, 0],   dark: [0, 51, 0] },
  "non-dairy": { bright: [204, 204, 0], dark: [51, 51, 0] },
};

export default class PopularPlot {
  constructor(target, name = "Popular", config = {}) {
    this.target = target;
    this.name = name;
    this.api = config.api ?? null;
    this.modelInstance = config.modelInstance ?? null;
    this.state = null;
    this.root = null;
    this.svg = null;
    this.keyGrid = null;
    this.activeLabel = null;
    this.activeId = null;
    this.filterInput = null;
    this.filterStyle = null;
    this._xMax = 1;
    this._yMax = 1;

    // .popularChart's height tracks the real, measured available viewport
    // space (see _syncChartHeight) rather than a guessed CSS vh-formula —
    // a guessed formula can't know how far down the page this control
    // actually sits, so it can end up taller than what's really visible,
    // scrolling the X axis off-screen. Re-measured on window resize so it
    // keeps tracking the window the way width already does. Debounced the
    // same way _scheduleAutosave debounces in _list.js. Bound once here
    // (not per-render) since this instance is constructed once per page
    // mount and only ever refresh()es afterward — see mountAllGrids().
    //
    // This is deliberately separate from the ResizeObserver set up in
    // render() (see _drawPlotContent): this listener only recomputes
    // .popularChart's own CSS height from window/page layout. The SVG's
    // actual content redraw is driven purely by the SVG's own measured
    // box size changing, whatever the cause — this height sync is one
    // cause, but so is the docked aside being drag-resized
    // (dock-resizer.js), which never fires a window 'resize' event at all.
    this._resizeSyncTimer = null;
    this._openSyncTimers = [];
    window.addEventListener('resize', () => {
      clearTimeout(this._resizeSyncTimer);
      this._resizeSyncTimer = setTimeout(() => this._syncChartHeight(), 100);
    });

    // List's own constructor stamps this (assets/ui/_list.js) so the
    // [data-canvas-mode="..."] CSS rules (canvas gutter/width/min-height —
    // see css.css) can key off it; PopularPlot doesn't extend List, so
    // nothing did this for Popular's host until now — it was silently
    // falling through to the generic .scoop-grid rule instead of any of
    // the four canvas-mode-specific ones.
    target.dataset.canvasMode = this.modelInstance?.canvasMode ?? 'half-stack';

    // TOGGLE/dockToggle() plumbing, borrowed from List (see the methods
    // below) rather than reimplemented, so PopularPlot can dock the same
    // way every List subclass (Grid/Tile) does — see DOCKING.md. Built here
    // (not in render()) so mountAllGrids() can call dockToggle() right
    // after construction, before any fetch/render has happened; matches the
    // "safe to build now" assumption already documented at that call site
    // in scoop-api.js. _dockListInstance mirrors List's own constructor —
    // lets a sibling control's _closeSlotSiblings()/_enforceCanvasExclusivity()
    // find this one's TOGGLE after dockToggle() has moved it elsewhere.
    target._dockListInstance = this;
    this.TOGGLE = this._buildToggleButton();
    if (!this.target.contains(this.TOGGLE)) this.target.append(this.TOGGLE);

    // Copied verbatim from List's own TOGGLE click binding (_list.js,
    // _bindEvents) rather than borrowed via .call(), since it's an event
    // listener — defining it here captures `this` as this PopularPlot
    // instance directly, same effect without the indirection.
    this.TOGGLE.addEventListener("click", (e) => {
      const isOpen = this.target.classList.toggle("toggled");
      this.TOGGLE.classList.toggle("active", isOpen);
      if (isOpen) {
        this._closeSlotSiblings();
        this._scheduleHeightSyncAfterOpen();
      }
      this._enforceCanvasExclusivity();
      this.target.closest('.action-target, aside')?.classList.toggle('active', isOpen);
      e.stopPropagation();
    }, true);
  }

  // Every dock slot hides a CLOSED panel with a pure CSS transform — .canvas
  // uses scale(0) (see .in-dock .canvas > .scoop-grid in css.css), <aside>
  // uses translateX — never display:none. That matters because
  // getBoundingClientRect() (what _syncChartHeight() measures) DOES reflect
  // an ancestor's transform even though transforms never affect real
  // layout, so a height computed while this.target is still collapsed is
  // measuring a degenerate rect and comes out wrong. Worse, that wrong
  // height then gets stuck: ResizeObserver (what drives _drawPlotContent)
  // deliberately IGNORES transforms, so simply finishing the open animation
  // never retriggers a redraw either — only an unrelated real layout event
  // (a window resize, which forces _syncChartHeight to recompute) used to
  // fix it, which is why the chart loaded too short until the next resize.
  //
  // An earlier version of this method waited for a single 'transitionend'
  // on this.target before re-measuring, but a dock slot's open "animation"
  // isn't always one clean transition on this.target alone — e.g. one slot
  // pairs a transform transition here with a SEPARATE, differently-timed
  // change elsewhere: `transition: flex-basis 0s linear 0.2s` (css.css) is
  // a zero-duration jump that lands 0.2s AFTER this.target's own
  // transitionend already fired, on a different element entirely. Waiting
  // for the "right" event across every possible dock-slot animation shape
  // isn't worth chasing — instead, just re-measure several times across
  // the whole plausible animation window and let the LAST one win. Each
  // call is cheap and idempotent; if an earlier one lands mid-animation
  // and writes a still-wrong height, a later one overwrites it once
  // everything has actually settled — and each real height CHANGE is a
  // genuine layout size change, which ResizeObserver DOES report, so the
  // content redraw tracks along for free without any separate call here.
  _scheduleHeightSyncAfterOpen() {
    (this._openSyncTimers ?? []).forEach(clearTimeout);
    this._openSyncTimers = [0, 60, 150, 250, 400, 550].map(delay =>
      setTimeout(() => this._syncChartHeight(), delay)
    );
  }

  // Borrowed from List (_list.js) rather than reimplemented — each only
  // depends on this.target/this.modelInstance/this.TOGGLE/this.el/this.name,
  // none of which are List-specific, so staying borrowed keeps this in sync
  // with List's implementation automatically instead of drifting.
  el(tag, opts) {
    return El.prototype.el.call(this, tag, opts);
  }

  _buildToggleButton() {
    return List.prototype._buildToggleButton.call(this);
  }

  dockToggle() {
    return List.prototype.dockToggle.call(this);
  }

  _closeSlotSiblings() {
    return List.prototype._closeSlotSiblings.call(this);
  }

  _enforceCanvasExclusivity() {
    return List.prototype._enforceCanvasExclusivity.call(this);
  }

  init(state = this.modelInstance) {
    this.state = state;
    this.render();
  }

  refresh(state = this.state) {
    this.state = state;
    this.render();
  }

  render() {
    const data = this.state?.getPlotData?.() ?? { points: [] };
    const points = data.points ?? [];

    // Only ever clear the plot's own content, not the whole host — TOGGLE
    // lives directly in this.target too (see constructor) and must survive
    // every re-render (e.g. a days-filter change), same as it implicitly
    // does for every List subclass (whose rebuilds never clear this.target
    // itself, only rebuild the parts inside FORM).
    this.root?.remove();
    this.root = this._el("div", { classes: ["popularView"] });

    if (!points.length) {
      this.root.append(this._el("p", {
        text: "No flavors have both total sold and opened-to-emptied timing for this period.",
        classes: ["popularEmpty"],
      }));
      this.target.append(this.root);
      return;
    }

    // xMax/yMax/tick values only depend on the DATA (points' own totalSold/
    // avgDays/trendPct extremes) — never on layout — so they're computed
    // once up front, synchronously, and reused by both the HTML tick
    // columns below (built immediately) and every later SVG redraw
    // (_drawPlotContent, driven by the ResizeObserver below).
    this._points = points;
    this._xMax = this._niceMax(Math.max(...points.map(p => p.totalSold)));
    this._yMax = this._niceMax(Math.max(...points.map(p => p.avgDays)));
    this._trendMagMax = this._niceMax(Math.max(...points.map(p => Math.abs(p.trendPct ?? 0))));
    this._xTicks = this._ticks(this._xMax);
    this._yTicks = this._ticks(this._yMax);

    const plotShell = this._el("div", { classes: ["popularPlotShell"] });
    const keyShell  = this._el("aside", { classes: ["popularKey"], attrs: { "aria-label": "Flavor key" } });

    // .popularChart is a CSS grid: fixed 1rem outer gutters and 0.5rem
    // inner gaps around the axis labels/tick-number columns/rows, all sized
    // to their own content (auto) — only the plot cell itself (the SVG) is
    // 1fr in both dimensions, so it's the one thing that actually grows or
    // shrinks with the available space. See .popularChart in css.css for
    // the track sizes this depends on.
    this.chart = this._el("div", { classes: ["popularChart"] });

    // No viewBox — this plot's coordinate system just IS real screen px
    // (see _drawPlotContent). An earlier version used a fixed 1000x1000
    // viewBox stretched non-uniformly via preserveAspectRatio:"none" to
    // fill whatever box CSS gave it; that's a CSS transform on a static
    // drawing, not a redraw, so squeezing the box vertically visibly
    // warped the whole picture (grid line spacing, stroke widths, shapes)
    // instead of just tightening tick spacing the way real re-layout
    // would. Every coordinate below is instead recomputed from scratch,
    // in real px, whenever the SVG's own measured size actually changes.
    this.svg = this._svg("svg", {
      attrs: {
        role: "img",
        "aria-label": "Scatter plot of total tubs sold by average days to empty",
      },
      classes: ["popularSvg"],
    });

    this.chart.append(
      this._el("div", { classes: ["popularYLabel", "popularAxisLabel"], text: "Avg Days to Empty" }),
      this._buildTickColumn(this._yTicks),
      this.svg,
      this._buildTickRow(this._xTicks),
      this._el("div", { classes: ["popularXLabel", "popularAxisLabel"], text: "Total Tubs Sold" })
    );

    plotShell.append(this.chart);
    this.root.append(plotShell, keyShell);
    this.target.append(this.root);

    // Needs this.chart actually attached (real layout, a real
    // getBoundingClientRect().top) — can't run any earlier than this.
    this._syncChartHeight();

    // Draws (and redraws) the SVG's actual content — grid lines, axes,
    // halos, center marks — from real, measured px, every time this.svg's
    // own rendered box size changes for ANY reason (window resize via
    // _syncChartHeight above changing .popularChart's height, the docked
    // aside being drag-resized, a panel being toggled open). Reconnected
    // to this fresh this.svg on every render() — the previous element (if
    // any) was just discarded by this.root?.remove() above. observe()
    // itself delivers one initial callback asynchronously once layout
    // settles, so the first real draw happens without any extra call here.
    this._resizeObserver ??= new ResizeObserver(() => this._drawPlotContent());
    this._resizeObserver.disconnect();
    this._resizeObserver.observe(this.svg);

    // The key reuses the existing Grid so its sortable headers do the
    // sort-by-tubs-sold and sort-by-avg-days work for us; the plot itself
    // stays static — only the key rows reorder when the user clicks a header.
    this._mountKeyGrid(keyShell);
    this._mountFilter();
  }

  // Sets .popularChart's height from real, measured layout instead of a
  // guessed CSS vh-formula: window.innerHeight minus wherever the chart's
  // top edge actually landed, minus whatever's really reserved below it
  // (the .in-dock toolbar's own measured height + the requested 1em
  // breathing room, if docked; a flat 2rem otherwise). This is what
  // guarantees the X axis stays on-screen regardless of how much page
  // chrome sits above the chart — a formula can't know that, a measurement
  // does. Clamped to a 20rem floor so a chart that's scrolled mostly out of
  // view doesn't collapse to nothing. Re-run on window resize (see
  // constructor) so height keeps tracking the window the way width already
  // does via ordinary CSS.
  _syncChartHeight() {
    if (!this.chart || !this.chart.isConnected) return;

    const top = this.chart.getBoundingClientRect().top;
    const dock = this.target.closest('.in-dock');
    const toolbar = dock?.querySelector(':scope > .toolbar');
    const bottomReserve = toolbar
      ? toolbar.getBoundingClientRect().height + this._remToPx(1)
      : this._remToPx(2);

    const minPx = this._remToPx(20);
    const available = window.innerHeight - top - bottomReserve;
    this.chart.style.height = `${Math.max(minPx, available)}px`;
  }

  _remToPx(rem) {
    return rem * parseFloat(getComputedStyle(document.documentElement).fontSize);
  }

  // Y-axis tick numbers, as plain HTML (not SVG text) — a fixed-width flex
  // column laid out via .popularYTicks (justify-content:space-between),
  // sharing the same grid row as the plot SVG so it stretches to exactly
  // the plot's own height with zero extra math. _ticks() returns ascending
  // [0..max]; rendered top-to-bottom that has to be reversed (highest tick
  // at the top, matching the gridlines it lines up with).
  _buildTickColumn(ticks) {
    const col = this._el("div", { classes: ["popularYTicks"] });
    [...ticks].reverse().forEach(tick => {
      col.append(this._el("span", { classes: ["popularTick"], text: this._fmt(tick) }));
    });
    return col;
  }

  // X-axis tick numbers, as plain HTML — a fixed-height flex row
  // (justify-content:space-between) sharing the plot's own grid column, so
  // it lines up under the plot's width with zero extra math. Ascending
  // order already matches left-to-right.
  _buildTickRow(ticks) {
    const row = this._el("div", { classes: ["popularXTicks"] });
    ticks.forEach(tick => {
      row.append(this._el("span", { classes: ["popularTick"], text: this._fmt(tick) }));
    });
    return row;
  }

  _mountFilter() {
    const form = this.keyGrid?.FORM;
    if (!form) return;

    const inp = document.createElement("input");
    inp.type = "text";
    inp.autocomplete = "off";
    inp.placeholder = "Filter…";
    inp.classList.add("gridFilterInput");
    form.prepend(inp);
    inp.addEventListener("input", () => this._applyFilter());

    this.filterInput = inp;

    // Filter via a <style> rule rather than per-element `hidden`/display so
    // it survives the Grid's sort-rebuild (sorting tears down and rebuilds
    // the tbodies). The same rule hides plot circles in lockstep.
    this.filterStyle = document.createElement("style");
    this.root.append(this.filterStyle);

    // Grid fires this event at the end of refresh(), which runs whenever a
    // select filter (e.g. allergen) changes. Reapply so the SVG circles
    // hide in step with the rows the model just filtered out.
    form.addEventListener("ts:grid:close-overlays", () => this._applyFilter());
  }

  _applyFilter() {
    const term = String(this.filterInput?.value ?? "").normalize("NFKC").toLowerCase().trim();
    const points = this.state?.points ?? [];

    // Combines: (a) text match against flavor name, and (b) row membership
    // from the model — which already reflects allergen-style select filters
    // because Grid calls model._buildRows() before refresh.
    const visibleByModel = typeof this.state?.getVisiblePointIds === "function"
      ? this.state.getVisiblePointIds()
      : null;

    const hideIds = [];
    for (const point of points) {
      const matchesText  = !term || String(point.flavorName ?? "").toLowerCase().includes(term);
      const matchesModel = !visibleByModel || visibleByModel.has(String(point.id));
      if (!matchesText || !matchesModel) hideIds.push(point.id);
    }

    if (this.activeId && hideIds.includes(this.activeId)) this._clearActive();

    if (!this.filterStyle) return;
    if (!hideIds.length) {
      this.filterStyle.textContent = "";
      return;
    }

    // Flavor IDs are numeric strings, safe to inline as attribute values.
    // No tag restriction on the second selector — halos are always
    // <circle>, but center marks can be <circle> (steady) or <polygon>
    // (triangle-up/triangle-down — rising/falling, see _buildCenterShape);
    // a tag-scoped selector would miss the polygon shapes entirely.
    const selectors = hideIds.flatMap(id => [
      `.popularView tr.row[data-row-id="${id}"]`,
      `.popularView [data-popular-id="${id}"]`,
    ]).join(",");
    this.filterStyle.textContent = `${selectors}{display:none !important;}`;
  }

  _mountKeyGrid(host) {
    // formCodec is required even for read-only Grids — _captureBaseline()
    // calls into it during init() regardless of whether the form will ever
    // be submitted.
    this.keyGrid = new Grid(host, "PopularKey", {
      api: this.api,
      modelInstance: this.state,
      formCodec: FormCodec,
    });
    this.keyGrid.init(this.state);

    // Grid subscribes every instance to "ts:domain:updated" so it can
    // re-render when the bundle reloads. PopularKey self-fetches from
    // /scoop/v1/analytics; the bundle's domain has no `analytics` key, so
    // a bundle refresh would feed empty data to buildRows() and wipe the
    // table the instant any neighboring bundle grid finishes loading.
    if (this.keyGrid._onDomainUpdated) {
      document.removeEventListener("ts:domain:updated", this.keyGrid._onDomainUpdated);
      this.keyGrid._onDomainUpdated = null;
      this.keyGrid._docListenerBound = false;
    }

    const table = this.keyGrid.TABLE;
    if (!table) return;

    // Bidirectional highlight: hovering a key row lights up the plot point,
    // hovering a plot point (handled in _activate) lights up the key row.
    // Use delegation with a relatedTarget check so the highlight doesn't
    // flicker as the cursor moves between <td>s within the same row.
    table.addEventListener("mouseover", (e) => {
      const tr = e.target.closest("tr.row");
      if (!tr) return;
      if (e.relatedTarget && tr.contains(e.relatedTarget)) return;
      const id = tr.dataset.rowId;
      if (id) this._activate(String(id));
    });
    table.addEventListener("mouseout", (e) => {
      const tr = e.target.closest("tr.row");
      if (!tr) return;
      if (e.relatedTarget && tr.contains(e.relatedTarget)) return;
      const id = tr.dataset.rowId;
      if (id) this._deactivate(String(id));
    });
  }

  // Redraws every pixel-positioned thing inside the SVG — grid lines, axes,
  // the plot background, halos, center marks, the active-point tooltip —
  // entirely from this.svg's OWN real, currently-measured box size (no
  // margins to exclude: those are .popularChart's surrounding CSS grid
  // tracks, not part of this SVG at all — see render()). Called by the
  // ResizeObserver set up in render() every time that measured size
  // actually changes, which is what makes this a genuine redraw (tighter
  // tick spacing, not a stretched/warped copy of a fixed-size drawing —
  // see the no-viewBox comment on this.svg's construction in render()).
  //
  // Because there's no viewBox, 1 coordinate unit here already equals 1
  // real screen px — every shape below is just plain, absolute geometry;
  // no unit-suffix or inverse-scale tricks are needed to keep circles
  // circular or polygons undistorted the way an earlier, viewBox-based
  // version needed.
  _drawPlotContent() {
    if (!this.svg?.isConnected) return;

    const rect = this.svg.getBoundingClientRect();
    const W = rect.width;
    const H = rect.height;
    if (W < 4 || H < 4) return;

    const points = this._points ?? [];
    if (!points.length) return;

    const xMax = this._xMax || 1;
    const yMax = this._yMax || 1;
    const x = value => (value / xMax) * W;
    const y = value => H - (value / yMax) * H;

    const plotBackground = this._svg("rect", {
      attrs: { x: 0, y: 0, width: W, height: H },
      classes: ["popularPlotArea"],
    });

    const grid = this._svg("g", { classes: ["popularGridLines"] });
    const axes = this._svg("g", { classes: ["popularAxes"] });
    const pointLayer = this._svg("g", { classes: ["popularPoints"] });

    this._xTicks.forEach(tick => {
      const tx = x(tick);
      grid.append(this._svg("line", { attrs: { x1: tx, y1: 0, x2: tx, y2: H } }));
    });

    this._yTicks.forEach(tick => {
      const ty = y(tick);
      grid.append(this._svg("line", { attrs: { x1: 0, y1: ty, x2: W, y2: ty } }));
    });

    axes.append(
      this._svg("line", { attrs: { x1: 0, y1: H, x2: W, y2: H }, classes: ["popularAxis"] }),
      this._svg("line", { attrs: { x1: 0, y1: 0, x2: 0, y2: H }, classes: ["popularAxis"] })
    );

    const maxStock = points.reduce((max, p) => {
      const stock = Number.isFinite(p.currentStock) ? p.currentStock : 0;
      return Math.max(max, stock);
    }, 0);

    // Halo radius cap comes from the whole CHART's width (gutters and key
    // included, not just this plot cell) — matches DECO_MAX_WIDTH_RATIO's
    // intent of tracking overall display width, not this cell's own.
    const chartWidth = this.chart?.getBoundingClientRect().width ?? W;
    const decoMaxR = Math.max(DECO_MIN_R_PX, chartWidth * DECO_MAX_WIDTH_RATIO);
    const magMax = this._trendMagMax || 1;

    // Built as two separate sublayers, halos first, so EVERY center mark
    // paints on top of EVERY halo — not just its own. Painting each point's
    // halo+center as a pair (an earlier approach) let a later point's wide
    // halo land on top of an earlier point's small center mark in a dense
    // cluster, visually burying it (SVG paint order is strict document
    // order, and stacked-up semi-opaque halos read as near-black — that's
    // what looked like "the center dots turned black").
    // haloLayer's pointer-events:none (see .popularPointHalo) means this
    // ordering is purely a paint-order fix — halos already couldn't
    // intercept hover/click — but a covered center mark couldn't be *seen*
    // or reached by the mouse either, which is the same practical problem.
    const haloLayer = this._svg("g", { classes: ["popularHalos"] });
    const centerLayer = this._svg("g", { classes: ["popularCenters"] });

    points.forEach((point, index) => {
      point.plotX = x(point.totalSold);
      point.plotY = y(point.avgDays);

      const category = this._categoryFor(point);
      const centerShape = CENTER_SHAPE_BY_TREND[point.trend] ?? "circle";
      const radiusPx = this._pointRadius(point, index, points.length);
      const magT = Math.max(0, Math.min(1, Math.abs(point.trendPct ?? 0) / magMax));
      const decoR = DECO_MIN_R_PX + magT * (decoMaxR - DECO_MIN_R_PX);

      // Decorative halo — trend MAGNITUDE (size, direction-agnostic — the
      // center mark already carries direction via shape+color) and dairy/
      // non-dairy + stock level (hue/darkness). Purely visual: pointer-
      // events:none so it never steals hover/focus from the center mark, no
      // tabindex/aria/title of its own. Keeps data-popular-id so
      // _applyFilter's existing selector still hides/shows it in step with
      // the center mark.
      const halo = this._svg("circle", {
        attrs: {
          cx: point.plotX,
          cy: point.plotY,
          r: decoR,
          fill: this._stockFillColor(category, point, maxStock),
          stroke: this._strokeColor(category),
        },
        classes: ["popularPoint", "popularPointHalo", category],
        data: { popularId: point.id },
      });
      haloLayer.append(halo);

      // Center mark — the only interactive target: hover, focus, and click
      // all resolve here, never on any halo. Shape now encodes trend
      // direction on top of the pre-existing trend-based fill color (see
      // .popularPointCenter.trend-* in css.css), so direction still reads
      // for anyone who can't distinguish the fill hues.
      const center = this._buildCenterShape(centerShape, point.plotX, point.plotY, radiusPx);
      center.classList.add("popularPoint", "popularPointCenter", `trend-${point.trend}`);
      center.dataset.popularId = point.id;
      center.setAttribute("tabindex", "0");
      center.setAttribute("aria-label", this._pointLabel(point));
      center.append(this._svg("title", { text: this._pointLabel(point) }));
      center.addEventListener("mouseenter", () => this._activate(point.id));
      center.addEventListener("mouseleave", () => this._deactivate(point.id));
      center.addEventListener("focus", () => this._activate(point.id));
      center.addEventListener("blur", () => this._deactivate(point.id));
      centerLayer.append(center);

      // A redraw rebuilds every element from scratch, so the is-active
      // state from _activate() (set on the previous generation of
      // elements) has to be reapplied here rather than surviving on its
      // own. This restores styling but not the "bring active halo to
      // front" paint-order trick _activate() also does — an acceptable
      // gap: it only matters for the rare case of a resize landing exactly
      // while a point is hovered/focused.
      if (this.activeId && String(point.id) === this.activeId) {
        halo.classList.add("is-active");
        center.classList.add("is-active");
      }
    });

    pointLayer.append(haloLayer, centerLayer);

    this.activeLabel = this._svg("g", { classes: ["popularActiveLabel"], attrs: { hidden: "" } });
    this.activeLabel.append(
      this._svg("rect", { attrs: { rx: 4, ry: 4 } }),
      this._svg("text", { attrs: { x: 0, y: 0 } })
    );

    this.svg.replaceChildren(plotBackground, grid, axes, pointLayer, this.activeLabel);

    if (this.activeId) {
      const point = this._pointById(this.activeId);
      if (point) this._showActiveLabel(point);
    }
  }

  _activate(id) {
    if (!id || this.activeId === id) return;
    this._clearActive();
    this.activeId = id;

    const point = this._pointById(id);
    this.root?.querySelectorAll("[data-popular-id]").forEach(el => {
      if (el.dataset.popularId !== id) return;
      el.classList.add("is-active");

      // Bring the active halo in front of every other halo — SVG has no
      // z-index, paint order is DOM order, and re-appending an already-
      // attached node moves it (doesn't duplicate it) to be its parent's
      // last child. Left there after deactivating too; harmless, and
      // avoids having to track/restore each halo's original position.
      if (el.classList.contains("popularPointHalo")) el.parentNode?.appendChild(el);
    });

    const tr = this.keyGrid?.TABLE?.querySelector(
      `tr.row[data-row-id="${CSS.escape(id)}"]`
    );
    if (tr) tr.classList.add("is-active");

    if (point) this._showActiveLabel(point);
  }

  _deactivate(id) {
    if (this.activeId !== id) return;
    this._clearActive();
  }

  _clearActive() {
    this.activeId = null;
    this.root?.querySelectorAll(".is-active").forEach(el => el.classList.remove("is-active"));
    if (this.activeLabel) this.activeLabel.setAttribute("hidden", "");
  }

  _showActiveLabel(point) {
    if (!this.activeLabel) return;

    const name = this._truncate(point.flavorName, 34);
    const metric = `${this._fmt(point.totalSold)} sold / ${this._fmt(point.avgDays)} days`;
    const detailLines = this._genericDumpLines(point);
    // Real, fixed px — a shape's own SIZE, same reasoning as circle radii;
    // shouldn't grow/shrink or distort just because the plot's aspect ratio
    // changed. Height grows with however many detail lines this point has.
    const labelWidthPx = Math.max(160, Math.min(290, name.length * 7.5 + 34));
    const labelHeightPx = (2 + detailLines.length) * 17 + 10;

    // Position is real px too now — point.plotX/plotY already are (see
    // _drawPlotContent) — so the flip/clear-the-edges check compares
    // directly against this.svg's own measured box instead of a data-value
    // fraction, and the offsets are plain px rather than a normalized ratio.
    const svgRect = this.svg.getBoundingClientRect();
    const gap = 10;
    const nearRightEdge = point.plotX > svgRect.width * 0.55;
    const nearTopEdge   = point.plotY < labelHeightPx + gap * 2;

    const x = nearRightEdge ? point.plotX - labelWidthPx - gap : point.plotX + gap;
    const y = nearTopEdge ? point.plotY + gap * 2 : Math.max(gap, point.plotY - labelHeightPx - gap);

    const rect = this.activeLabel.querySelector("rect");
    const text = this.activeLabel.querySelector("text");
    rect.setAttribute("x", x);
    rect.setAttribute("y", y);
    rect.setAttribute("width", labelWidthPx);
    rect.setAttribute("height", labelHeightPx);

    // Each tspan sets its own absolute x (resetting horizontal position to
    // the same anchor the rect uses, offset by a 12px inset) + dy (real px
    // vertical advance from the previous line) — the parent <text>'s own
    // x/y are unused once every child tspan overrides them.
    text.replaceChildren(
      this._svg("tspan", { text: name, attrs: { x: x + 12, y: y + 17 }, classes: ["popularLabelName"] }),
      this._svg("tspan", { text: metric, attrs: { x: x + 12, dy: 17 }, classes: ["popularLabelMetric"] }),
      ...detailLines.map(line =>
        this._svg("tspan", { text: line, attrs: { x: x + 12, dy: 15 }, classes: ["popularLabelDetail"] })
      )
    );

    this.activeLabel.removeAttribute("hidden");
  }

  // Everything on the point beyond name/sold/days, generically stringified
  // rather than each hand-curated like the two lines above — an allow-list
  // (not a raw Object.entries dump) because `point` also carries internal
  // plot-computation fields (plotX/plotY, id, flavorId) that aren't data at
  // all and would just be noise here.
  _genericDumpLines(point) {
    const fields = ["trend", "trendPct", "sellRatePerDay", "currentStock", "allergens"];
    return fields
      .filter(key => point[key] != null)
      .map(key => `${this._humanizeKey(key)}: ${this._fmtFieldValue(key, point[key])}`);
  }

  _fmtFieldValue(key, value) {
    if (Array.isArray(value)) return value.length ? value.join(", ") : "none";
    if (key === "trendPct") return `${value >= 0 ? "+" : ""}${this._fmt(value)}%`;
    if (typeof value === "number") return this._fmt(value);
    return String(value);
  }

  _humanizeKey(key) {
    return key.replace(/([A-Z])/g, " $1").replace(/^./, c => c.toUpperCase());
  }

  _pointById(id) {
    return (this.state?.points ?? []).find(point => point.id === id) ?? null;
  }

  _pointLabel(point) {
    return `${point.flavorName}: ${this._fmt(point.totalSold)} tubs sold, ${this._fmt(point.avgDays)} average days to empty`;
  }

  // Center mark's own size — density-aware so a crowded plot doesn't drown
  // in overlapping marks. A plain real-px number, used directly by
  // _buildCenterShape.
  _pointRadius(point, index, total) {
    if (total > 120) return 4.2;
    if (total > 60) return 4.8;
    if (point.totalSold >= 10) return 6.5;
    return 5.5;
  }

  // Dairy vs non-dairy uses the same allergens-slug technique as
  // FlavorsGridModel's Dairy/Non-Dairy grouping (see SHORTCODES.md) — no
  // separate "base ingredient" field is fetched, 'dairy' is already in the
  // allergens list this point carries. Returns a CATEGORY_HUES key, also
  // used verbatim as the halo's CSS class (see .popularPointHalo.dairy/
  // .non-dairy in css.css).
  _categoryFor(point) {
    return this._isDairy(point) ? "dairy" : "non-dairy";
  }

  _isDairy(point) {
    return (point.allergens ?? []).includes("dairy");
  }

  // Outline is always the category's fully-saturated hue regardless of
  // stock level. Neither endpoint is pure #0f0/#ff0 (rgb(0,255,0)/
  // rgb(255,255,0)) — too hard to see against the plot's light background —
  // the slightly darkened rgb(0,204,0)/rgb(204,204,0) in CATEGORY_HUES read
  // clearly instead.
  _strokeColor(category) {
    const [r, g, b] = (CATEGORY_HUES[category] ?? CATEGORY_HUES["non-dairy"]).bright;
    return `rgba(${r}, ${g}, ${b}, 1)`;
  }

  // Fill fades in from fully transparent (no stock — an outline-only
  // circle) toward the category's dark, muted, fully-opaque endpoint at the
  // highest stock level among the currently plotted points — both opacity
  // and color value move together as stock rises, so a well-stocked flavor
  // reads as a solid, calm dark blob while a low-stock one is just a bright
  // open ring.
  _stockFillColor(category, point, maxStock) {
    const stock = Number.isFinite(point.currentStock) ? Math.max(point.currentStock, 0) : 0;
    const s = maxStock > 0 ? Math.min(stock / maxStock, 1) : 0;
    const { bright, dark } = CATEGORY_HUES[category] ?? CATEGORY_HUES["non-dairy"];

    const r = Math.round(bright[0] + (dark[0] - bright[0]) * s);
    const g = Math.round(bright[1] + (dark[1] - bright[1]) * s);
    const b = Math.round(bright[2] + (dark[2] - bright[2]) * s);

    return `rgba(${r}, ${g}, ${b}, ${s.toFixed(2)})`;
  }

  // Builds one center mark at real (cx, cy) with real px "radius" r. Since
  // this plot's coordinate system is already real screen px (no viewBox —
  // see render()), every shape here is just plain, absolute SVG geometry;
  // no unit-suffix or inverse-scale tricks are needed to keep it
  // undistorted, unlike an earlier viewBox-based version of this method.
  _buildCenterShape(shape, cx, cy, r) {
    if (shape === "circle") {
      return this._svg("circle", { attrs: { cx, cy, r } });
    }
    // triangle-up (rising) or triangle-down (falling) — same geometry,
    // mirrored vertically: "up" points its apex toward -y (up the screen),
    // "down" flips that sign so the apex points toward +y instead.
    const dir = shape === "triangle-up" ? -1 : 1;
    const h = r * 1.7, w = r * 1.5;
    const apex = cy + dir * h;
    const base = cy - dir * h * 0.65;
    return this._svg("polygon", {
      attrs: { points: `${cx},${apex} ${cx + w},${base} ${cx - w},${base}` },
    });
  }

  _ticks(max, count = 5) {
    const ticks = [];
    for (let i = 0; i <= count; i++) ticks.push((max / count) * i);
    return ticks;
  }

  // Axis max is the data's own max rounded up to the next multiple of 10
  // (e.g. 38 -> 40, 29 -> 30) — not the classic 1/2/5/10 "nice number"
  // algorithm, which was jumping values like 29 all the way to 50.
  _niceMax(value) {
    if (!Number.isFinite(value) || value <= 0) return 10;
    return Math.ceil(value / 10) * 10;
  }

  _fmt(value) {
    if (!Number.isFinite(Number(value))) return "--";
    const n = Number(value);
    return String(parseFloat(n.toFixed(n >= 10 ? 0 : 1)));
  }

  _truncate(value, max) {
    const s = String(value ?? "");
    return s.length > max ? `${s.slice(0, max - 1)}...` : s;
  }

  _el(tag, { text, attrs = {}, classes = [], data = {} } = {}) {
    const el = document.createElement(tag);
    if (text != null) el.textContent = String(text);
    classes.filter(Boolean).forEach(cls => el.classList.add(cls));
    Object.entries(attrs).forEach(([key, value]) => {
      if (value != null) el.setAttribute(key, String(value));
    });
    Object.entries(data).forEach(([key, value]) => {
      if (value != null) el.dataset[key] = String(value);
    });
    return el;
  }

  _svg(tag, { text, attrs = {}, classes = [], data = {} } = {}) {
    const el = document.createElementNS("http://www.w3.org/2000/svg", tag);
    if (text != null) el.textContent = String(text);
    classes.filter(Boolean).forEach(cls => el.classList.add(cls));
    Object.entries(attrs).forEach(([key, value]) => {
      if (value != null) el.setAttribute(key, String(value));
    });
    Object.entries(data).forEach(([key, value]) => {
      if (value != null) el.dataset[key] = String(value);
    });
    return el;
  }
}
