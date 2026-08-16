///////////////////////////////////
// TaskGridModel — domain access only, for the Task creation form (see
// assets/ui/task-form.js). Not a spreadsheet grid: no columns, no rows in
// the BaseGridModel sense (same "ships no columns" precedent as
// ShiftReportGridModel/CabinetWorkflowGridModel) — TaskForm builds its own
// DOM directly off this model's domain.
//////////////////////////////////
import BaseGridModel from "./_base-grid-model.js";

export default class TaskGridModel extends BaseGridModel {
  constructor(name = "Task", domain, attrs = {}) {
    super(name, domain, attrs);
    // Single-submission form, not a persisted-row view — same reasoning as
    // ShiftReportGridModel/Batch's own repaintOnRefresh=false.
    this.repaintOnRefresh = false;
  }

  // No _config.php-driven columns for this model (Task is create-only, no
  // entity spec — see includes/_specs.php) — buildCols()/buildRows() are
  // never called by the constructor. Kept as no-ops rather than left
  // undefined, in case something ever calls them defensively.
  buildCols() { return (this.columns = []); }
  buildRows() { return (this.rows = []); }
}
