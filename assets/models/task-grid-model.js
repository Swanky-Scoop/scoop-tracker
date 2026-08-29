///////////////////////////////////
// TaskGridModel — no real domain access needed. TaskCreateForm (see
// assets/ui/task-create-form.js) fetches its own staff option list
// directly from GET /kitchen-staff rather than through the bundle (see
// that endpoint's own comment in rest.php for why), so this model exists
// only to satisfy ScoopAPI.getModelsBom()'s per-type model requirement —
// same "ships no columns, nothing here to build from domain" precedent as
// ShiftReportGridModel (see that file's own header comment).
//////////////////////////////////
import BaseGridModel from "./_base-grid-model.js";

export default class TaskGridModel extends BaseGridModel {
  constructor(name = "Task", domain, attrs = {}) {
    super(name, domain, attrs);
    // Single-submission create form, not a persisted-row view — same
    // reasoning as Batch/ShiftReport's own repaintOnRefresh = false.
    this.repaintOnRefresh = false;
  }

  buildCols() { return (this.columns = []); }
  buildRows() { return (this.rows = []); }
}
