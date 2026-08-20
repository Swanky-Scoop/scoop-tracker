///////////////////////////////////
// IframePanel — bespoke, non-List dockable view for embedding a third-party
// iframe (e.g. a published Google Doc) inside a [scoop_dock]. Two ways its
// content gets defined:
//   - [scoop_iframe title=... url=... icon=...] (see includes/shortcode.php)
//     stamps them straight onto the host as data-title/data-url/data-icon —
//     per-instance, page-content-driven, general purpose: a page can embed
//     as many different URLs as it wants just by writing more shortcodes.
//   - A config-driven "iframe topic" type (e.g. 'ProductionPlan'/'esr' —
//     see _config.php's iframe_url) has no shortcode attributes of its own;
//     [scoop_grid type="..."] is a plain host like every other type, and
//     title/icon/url all come from SCOOP.metaData[name] instead — server-
//     supplied data, gated per-role in scoop_render_grid_host()
//     (shortcode.php) rather than embeddable by anyone who can edit a page.
//
// A config-driven topic can ALSO carry an explicit displayMode of 'external'
// instead of the default 'iframe' — scoop_client_metadata() (enqueue.php)
// resolves this per current user, per-role override in _policy.php falling
// back to _config.php's default (see 'esr' in both files: some forms simply
// can't be embedded for every account). mode === 'external' means this
// control never opens an in-page panel at all — clicking TOGGLE just does
// window.open(url, externalTarget) and nothing else; see the constructor
// and render() below. This is read directly off metaData.displayMode, not
// inferred from which URL field happens to be populated.
//
// No model, no fetch, no bundle entity — unlike every other dockable view
// (List subclasses, PopularPlot, ShiftReportForm/TaskForm), this control has
// no server data dependency at all; render() runs once, synchronously, off
// whichever of the sources above supplied it. Still borrows Dockable/List's
// TOGGLE/dockToggle/canvas-exclusivity plumbing (same technique
// popular-plot.js uses — see that file's own header comment) so it
// participates in the dock exactly the way every other control does.
//////////////////////////////////
import List from "./_list.js";
import El   from "./_el.js";

export default class IframePanel {
  constructor(target, name = "Iframe", config = {}) {
    this.target = target;
    this.name = name;
    this.api = config.api ?? null;

    // window.SCOOP.metaData[name] — see scoop_client_metadata()'s
    // displayTitle/icon/displayMode/iframeUrl/externalUrl fields
    // (enqueue.php). Only iframe-topic types carry a non-null displayMode;
    // every dataset.* check below still wins when present, so a
    // page-content [scoop_iframe] instance behaves exactly as before even
    // though meta is technically in scope for it too (SCOOP.metaData has
    // no 'Iframe' entry — meta is undefined there).
    const meta = window.SCOOP?.metaData?.[name];

    this.title = target.dataset.title || meta?.displayTitle || "Embed";
    // Explicit constructor field — 'iframe' | 'external' — resolved
    // server-side per current user (see enqueue.php's comment). A
    // page-content [scoop_iframe] instance has no displayMode of its own
    // and is always 'iframe' — it has no external-link concept.
    this.mode = meta?.displayMode || "iframe";
    this.url = target.dataset.url || (this.mode === "external" ? meta?.externalUrl : meta?.iframeUrl) || "";
    // Only ever set server-side, for a config-driven topic resolved to
    // 'external' mode — never a dataset.* override, [scoop_iframe] has no
    // equivalent attribute.
    this.externalTarget = meta?.externalTarget || "_blank";

    // Stand-in for a real model — every borrowed Dockable/List method below
    // only ever reads displayTitle/icon/canvasMode/dockTarget off
    // this.modelInstance, so a plain object with those four fields is
    // enough; there's no domain/columns/rows here to justify a real
    // BaseGridModel subclass. canvasMode is fixed at 'half-nostack' by this
    // control's design, not configurable from either source above.
    this.modelInstance = {
      displayTitle: this.title,
      // data-icon (see [scoop_iframe]'s `icon` attribute) beats meta.icon
      // (config-driven topic) beats config.icon (a fallback for any future
      // caller that constructs IframePanel directly) beats the plain
      // picture-frame default — same "if:<name>"/inline-svg/image-path/
      // literal-glyph shapes _buildToggleButton() understands for every
      // other dockable control.
      icon: target.dataset.icon || meta?.icon || config.icon || "🖼",
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
      // External mode: just open/reuse the named window — no in-page panel
      // to toggle open or closed, no dock-hash entry to sync (there's
      // nothing "open" in THIS page to restore on reload).
      if (this.mode === "external") {
        window.open(this.url, this.externalTarget, "noopener");
        e.stopPropagation();
        return;
      }

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

    // External mode never opens an in-page panel (see the constructor's
    // click handler) — still marks itself with the same empty .iframeView
    // class every other mode uses, purely so the loading-shimmer heuristic
    // (:has(.iframeView) exclusion — css.css) sees this host as "loaded"
    // instead of shimmering forever with nothing that will ever fill it in.
    if (this.mode === "external") {
      this.root = this._el("div", { classes: ["iframeView"] });
      this.target.append(this.root);
      return;
    }

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
