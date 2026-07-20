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
  await api.mountAllGrids(SCOOP.metaData);
  Details.refresh();
  api.watchForStaleVersion(SCOOP.version);
  api.watchForIdleTimeout({ loginUrl: SCOOP.loginUrl });

});