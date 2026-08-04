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
  const grids = await api.mountAllGrids(SCOOP.metaData);
  // Once every control on the page has mounted, let each one check whether
  // it landed inside a [scoop_dock] (.in-dock ancestor) and, if so, move its
  // own toggle button into the shared .toolbar row. See List.dockToggle()
  // in assets/ui/_list.js. PopularPlot isn't a List subclass and has no
  // toggle to dock, hence the optional call.
  grids.forEach(g => g.dockToggle?.());
  Details.refresh();
  api.watchForStaleVersion(SCOOP.version);
  api.watchForIdleTimeout();
  api.watchForInventoryChangeFlush();

});