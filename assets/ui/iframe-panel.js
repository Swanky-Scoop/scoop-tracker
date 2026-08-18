///////////////////////////////////
// IframePanel — bespoke, non-List dockable view for embedding a third-party
// iframe (e.g. a published Google Doc) inside a [scoop_dock]. General
// purpose: title/url come straight off the host's data-title/data-url (see
// [scoop_iframe] in includes/shortcode.php) rather than being hardcoded per
// instance, so a page can embed as many different URLs as it wants just by
// writing more shortcodes.
//
// No model, no fetch, no bundle entity — unlike every other dockable view
// (List subclasses, PopularPlot, ShiftReportForm/TaskForm), this control has
// no server data dependency at all; render() runs once, synchronously, off
// the shortcode's own attributes. Still borrows Dockable/List's TOGGLE/
// dockToggle/canvas-exclusivity plumbing (same technique popular-plot.js
// uses — see that file's own header comment) so it participates in the dock
// exactly the way every other control does.
//////////////////////////////////
import List from "./_list.js";
import El   from "./_el.js";

export default class IframePanel {
  constructor(target, name = "Iframe", config = {}) {
    this.target = target;
    this.name = name;
    this.api = config.api ?? null;

    this.title = target.dataset.title || "Embed";
    this.url = target.dataset.url || "";

    // Stand-in for a real model — every borrowed Dockable/List method below
    // only ever reads displayTitle/icon/canvasMode/dockTarget off
    // this.modelInstance, so a plain object with those four fields is
    // enough; there's no domain/columns/rows here to justify a real
    // BaseGridModel subclass. canvasMode is fixed at 'half-nostack' by this
    // control's design, not configurable from the shortcode (title/url are
    // the only per-instance parameters).
    this.modelInstance = {
      displayTitle: this.title,
      icon: config.icon ?? "🖼",
      canvasMode: "half-nostack",
      dockTarget: null,
    };

    // Only meaningful within .in-dock's .canvas (see the "canvas" sizing
    // rules in css.css) — harmless to set unconditionally outside a dock,
    // same as every other borrowed-plumbing view.
    target.dataset.canvasMode = this.modelInstance.canvasMode;

    target._dockListInstance = this;
    this.TOGGLE = this._buildToggleButton();
    if (!this.target.contains(this.TOGGLE)) this.target.append(this.TOGGLE);

    this.TOGGLE.addEventListener("click", (e) => {
      const isOpen = !this.target.classList.contains("toggled");
      this._setToggled(isOpen);
      this._syncDockHash();
      e.stopPropagation();
    }, true);

    this._bindPageStatusToggle();

    this.render();
  }

  // Borrowed from List (_list.js) rather than reimplemented — each only
  // depends on this.target/this.modelInstance/this.TOGGLE/this.el/this.name,
  // none of which are List-specific, so staying borrowed keeps this in sync
  // with List's implementation automatically instead of drifting. Same
  // technique popular-plot.js uses.
  el(tag, opts) { return El.prototype.el.call(this, tag, opts); }
  _buildToggleButton() { return List.prototype._buildToggleButton.call(this); }
  dockToggle() { return List.prototype.dockToggle.call(this); }
  _bindPageStatusToggle() { return List.prototype._bindPageStatusToggle.call(this); }
  _closeSlotSiblings() { return List.prototype._closeSlotSiblings.call(this); }
  _enforceCanvasExclusivity() { return List.prototype._enforceCanvasExclusivity.call(this); }
  _setToggled(isOpen) { return List.prototype._setToggled.call(this, isOpen); }
  _syncDockHash() { return List.prototype._syncDockHash.call(this); }

  render() {
    this.root?.remove();

    if (!this.url) {
      this.root = this._el("div", { classes: ["iframeView"] });
      this.root.append(this._el("p", {
        text: "No URL configured for this embed.",
        classes: ["iframeEmpty"],
      }));
      this.target.append(this.root);
      return;
    }

    this.root = this._el("div", { classes: ["iframeView"] });
    this.frame = document.createElement("iframe");
    this.frame.src = this.url;
    this.frame.title = this.title;
    this.frame.loading = "lazy";
    this.frame.classList.add("iframeEmbed");
    this.root.append(this.frame);
    this.target.append(this.root);
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
}
