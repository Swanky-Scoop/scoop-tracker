import Grid      from "./grid.js";
import FormCodec from "../data/form-codec.js";

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
    this.width = 1280;
    this.height = 780;
    this.margin = { top: 36, right: 36, bottom: 84, left: 96 };
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

    this.target.replaceChildren();
    this.root = this._el("div", { classes: ["popularView"] });

    if (!points.length) {
      this.root.append(this._el("p", {
        text: "No flavors have both total sold and opened-to-emptied timing for this period.",
        classes: ["popularEmpty"],
      }));
      this.target.append(this.root);
      return;
    }

    const plotShell = this._el("div", { classes: ["popularPlotShell"] });
    const keyShell  = this._el("aside", { classes: ["popularKey"], attrs: { "aria-label": "Flavor key" } });

    this.svg = this._svg("svg", {
      attrs: {
        viewBox: `0 0 ${this.width} ${this.height}`,
        preserveAspectRatio: "xMidYMid meet",
        role: "img",
        "aria-label": "Scatter plot of total tubs sold by average days to empty",
      },
      classes: ["popularSvg"],
    });

    this._drawPlot(points);

    plotShell.append(this.svg);
    this.root.append(plotShell, keyShell);
    this.target.append(this.root);

    // The key reuses the existing Grid so its sortable headers do the
    // sort-by-tubs-sold and sort-by-avg-days work for us; the plot itself
    // stays static — only the key rows reorder when the user clicks a header.
    this._mountKeyGrid(keyShell);
    this._mountFilter();
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
    const selectors = hideIds.flatMap(id => [
      `.popularView tr.row[data-row-id="${id}"]`,
      `.popularView circle[data-popular-id="${id}"]`,
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

  _drawPlot(points) {
    const { width, height, margin } = this;
    const plotWidth = width - margin.left - margin.right;
    const plotHeight = height - margin.top - margin.bottom;
    const xMax = this._niceMax(Math.max(...points.map(p => p.totalSold)));
    const yMax = this._niceMax(Math.max(...points.map(p => p.avgDays)));

    const x = value => margin.left + (value / xMax) * plotWidth;
    const y = value => margin.top + plotHeight - (value / yMax) * plotHeight;

    const grid = this._svg("g", { classes: ["popularGridLines"] });
    const axes = this._svg("g", { classes: ["popularAxes"] });
    const pointLayer = this._svg("g", { classes: ["popularPoints"] });

    this._ticks(xMax).forEach(tick => {
      const tx = x(tick);
      grid.append(this._svg("line", {
        attrs: { x1: tx, y1: margin.top, x2: tx, y2: margin.top + plotHeight },
      }));
      axes.append(this._svg("text", {
        text: this._fmt(tick),
        attrs: { x: tx, y: margin.top + plotHeight + 24, "text-anchor": "middle" },
        classes: ["popularTick"],
      }));
    });

    this._ticks(yMax).forEach(tick => {
      const ty = y(tick);
      grid.append(this._svg("line", {
        attrs: { x1: margin.left, y1: ty, x2: margin.left + plotWidth, y2: ty },
      }));
      axes.append(this._svg("text", {
        text: this._fmt(tick),
        attrs: { x: margin.left - 14, y: ty + 5, "text-anchor": "end" },
        classes: ["popularTick"],
      }));
    });

    axes.append(
      this._svg("line", {
        attrs: {
          x1: margin.left,
          y1: margin.top + plotHeight,
          x2: margin.left + plotWidth,
          y2: margin.top + plotHeight,
        },
        classes: ["popularAxis"],
      }),
      this._svg("line", {
        attrs: {
          x1: margin.left,
          y1: margin.top,
          x2: margin.left,
          y2: margin.top + plotHeight,
        },
        classes: ["popularAxis"],
      }),
      this._svg("text", {
        text: "Total Tubs Sold",
        attrs: { x: margin.left + plotWidth / 2, y: height - 18, "text-anchor": "middle" },
        classes: ["popularAxisLabel"],
      }),
      this._svg("text", {
        text: "Avg Days to Empty",
        attrs: {
          x: -(margin.top + plotHeight / 2),
          y: 24,
          transform: "rotate(-90)",
          "text-anchor": "middle",
        },
        classes: ["popularAxisLabel"],
      })
    );

    points.forEach((point, index) => {
      point.plotX = x(point.totalSold);
      point.plotY = y(point.avgDays);

      const circle = this._svg("circle", {
        attrs: {
          cx: point.plotX,
          cy: point.plotY,
          r: this._pointRadius(point, index, points.length),
          tabindex: 0,
          "aria-label": this._pointLabel(point),
        },
        classes: ["popularPoint", `trend-${point.trend}`],
        data: { popularId: point.id },
      });
      circle.append(this._svg("title", { text: this._pointLabel(point) }));
      circle.addEventListener("mouseenter", () => this._activate(point.id));
      circle.addEventListener("mouseleave", () => this._deactivate(point.id));
      circle.addEventListener("focus", () => this._activate(point.id));
      circle.addEventListener("blur", () => this._deactivate(point.id));
      pointLayer.append(circle);
    });

    this.activeLabel = this._svg("g", { classes: ["popularActiveLabel"], attrs: { hidden: "" } });
    this.activeLabel.append(
      this._svg("rect", { attrs: { rx: 4, ry: 4 } }),
      this._svg("text", { attrs: { x: 0, y: 0 } })
    );

    this.svg.append(grid, axes, pointLayer, this.activeLabel);
  }

  _activate(id) {
    if (!id || this.activeId === id) return;
    this._clearActive();
    this.activeId = id;

    const point = this._pointById(id);
    this.root?.querySelectorAll("[data-popular-id]").forEach(el => {
      if (el.dataset.popularId === id) el.classList.add("is-active");
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
    const labelWidth = Math.max(160, Math.min(290, name.length * 7.5 + 34));
    const labelHeight = 44;
    let x = point.plotX + 12;
    let y = point.plotY - labelHeight - 12;

    if (x + labelWidth > this.width - this.margin.right) x = point.plotX - labelWidth - 12;
    if (y < this.margin.top) y = point.plotY + 16;

    const rect = this.activeLabel.querySelector("rect");
    const text = this.activeLabel.querySelector("text");
    rect.setAttribute("x", x);
    rect.setAttribute("y", y);
    rect.setAttribute("width", labelWidth);
    rect.setAttribute("height", labelHeight);

    text.replaceChildren();
    text.setAttribute("x", x + 12);
    text.setAttribute("y", y + 17);
    text.append(
      this._svg("tspan", { text: name, attrs: { x: x + 12, dy: 0 }, classes: ["popularLabelName"] }),
      this._svg("tspan", { text: metric, attrs: { x: x + 12, dy: 17 }, classes: ["popularLabelMetric"] })
    );

    this.activeLabel.removeAttribute("hidden");
  }

  _pointById(id) {
    return (this.state?.points ?? []).find(point => point.id === id) ?? null;
  }

  _pointLabel(point) {
    return `${point.flavorName}: ${this._fmt(point.totalSold)} tubs sold, ${this._fmt(point.avgDays)} average days to empty`;
  }

  _pointRadius(point, index, total) {
    if (total > 120) return 4.2;
    if (total > 60) return 4.8;
    if (point.totalSold >= 10) return 6.5;
    return 5.5;
  }

  _ticks(max, count = 5) {
    const ticks = [];
    for (let i = 0; i <= count; i++) ticks.push((max / count) * i);
    return ticks;
  }

  _niceMax(value) {
    if (!Number.isFinite(value) || value <= 0) return 1;
    const exponent = Math.floor(Math.log10(value));
    const base = Math.pow(10, exponent);
    const fraction = value / base;
    const niceFraction = fraction <= 1 ? 1 : fraction <= 2 ? 2 : fraction <= 5 ? 5 : 10;
    return niceFraction * base;
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
