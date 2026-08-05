import ScoopAPI    from "./data/scoop-api.js";
import Details     from "./ui/details.js";

document.addEventListener("DOMContentLoaded", async () => {
  const api = new ScoopAPI({
    nonce: SCOOP.nonce,
    base: "/",
    routes: SCOOP.routes,
    metaData: SCOOP.metaData,
    user: SCOOP.user
  });
  if( await api.userHelper(SCOOP) === false ) return;
  Details.attach(api);
  // Each control docks its own toggle button (see List.dockToggle(), called
  // from within mountAllGrids itself) the moment it's constructed, in
  // shortcode/document order and before any of its data has loaded — see
  // that method's header comment in assets/data/scoop-api.js.
  const grids = await api.mountAllGrids(SCOOP.metaData);
  Details.refresh();
  api.watchForStaleVersion(SCOOP.version);
  api.watchForIdleTimeout();
  api.watchForInventoryChangeFlush();

});