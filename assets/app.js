import ScoopAPI    from "./data/scoop-api.js";
import Details     from "./ui/details.js";
import Dockable    from "./ui/_dockable.js";
import { initDockResizers } from "./ui/dock-resizer.js";

document.addEventListener("DOMContentLoaded", async () => {
  // Independent of grid mounting — .dock-resizer elements are server-
  // rendered by [scoop_dock] (see includes/shortcode.php), present in the
  // DOM before any JS runs.
  initDockResizers();

  // Same reasoning — a single document-level listener, not tied to any one
  // control, so it doesn't need to wait for mountAllGrids(). Queries
  // .in-dock's current state fresh on every Escape press rather than
  // tracking anything itself, so mounting order doesn't matter here.
  Dockable.bindEscapeToClose();

  const api = new ScoopAPI({
    nonce: SCOOP.nonce,
    base: "/",
    routes: SCOOP.routes,
    metaData: SCOOP.metaData,
    user: SCOOP.user
  });
  if( await api.userHelper(SCOOP) === false ) return;
  Details.attach(api);
  api.bindDockRefreshButton();
  // Each control docks its own toggle button (see List.dockToggle(), called
  // from within mountAllGrids itself) the moment it's constructed, in
  // shortcode/document order and before any of its data has loaded — see
  // that method's header comment in assets/data/scoop-api.js.
  const grids = await api.mountAllGrids(SCOOP.metaData);
  Details.refresh();
  // One watcher, one timer: 1s version-gated background refresh
  // (CONTROL-REFRESH.md §3) with watchForStaleVersion's app.js-mtime
  // reload folded in as a ride-along comparison on the same poll.
  api.watchForDataChanges({ staleVersionBaseline: SCOOP.version });
  api.watchForIdleTimeout();
  api.watchForInventoryChangeFlush();

});