///////////////////////////////////
// TaskForm — Task creation GUI (see [scoop_grid type="Task"]). Deliberately
// NOT a List/Grid subclass itself, same reasoning as ShiftReportForm: the
// task's own target/other fields aren't spreadsheet columns, and the three
// batches/preps/recipe_counts widgets each need their OWN independent Grid
// instances (see below) rather than one shared table. Bundle-fed like every
// other grid (setDomain(domain), dockToggle()), just not built on List
// itself — extends Dockable (assets/ui/_dockable.js) for the dock-toggle
// piece instead, same as ShiftReportForm; every shortcode is expected to be
// dockable (see DOCKING.md).
//
// Each of the three widgets (Batch/RecipeCount/Prep) is a PAIR of real Grid
// instances, same shape as the standalone [scoop_grid type="Batch" history="true"]
// control:
//   - a create grid — the exact same control the standalone Batch grid uses
//     (BatchGridModel unchanged); RecipeCountGridModel/PrepGridModel (new)
//     are the same "one relation + a count" shape for their own pod types.
//   - a history grid (TaskComponentHistoryGridModel, shared across all
//     three via config) — everything already linked to this task, with a
//     delete button per row, same pattern as BatchHistoryGridModel except
//     scoped to this one task instead of a date range.
//
// IMPORTANT — HTML forbids nesting <form> elements. Each embedded Grid
// builds its own <form class="zList-form"> (List.buildCoreDom), so all of
// these forms are inserted as SIBLINGS inside a plain (non-<form>) container
// — never nested inside this.ROOT or inside each other — mirroring how
// ScoopAPI._mountEmbeddedBatchHistory() already embeds a second List's FORM
// as a sibling of Batch's own, not a child of it. The task's own target/
// other fields keep their own small, self-contained <form> (nothing embedded
// inside it), submitted via api.postJson() directly rather than through
// List's write machinery.
//
// Linking a child to the task: each create grid gets extra hidden inputs
// appended directly to its FORM — `${Type}[cells][0][task]` (and, for Batch,
// `[done]` — see COMPONENTS below) — outside the model's own column system
// entirely, so BatchGridModel/etc. are untouched for their standalone
// [scoop_grid] usage elsewhere. List's own _buildAllPayload() already scans
// the FORM for any hidden input matching that name convention, so these
// extra fields ride along on every submit with zero changes to List/Grid.
// batch.task/prep.task/recipe_count.task are genuine Pods bidirectional
// sister fields with task.batches/task.preps/task.recipe_counts (confirmed
// directly against wp_podsrel) — setting the child's own `task` field is
// sufficient, Pods syncs the task's reverse list automatically, no
// follow-up write to the task itself is ever needed.
//
// The history grids read straight off the bundle domain (batch/recipe_count/
// prep are all in Task's own bundle needs — see includes/_specs.php),
// filtered client-side to this task, and repaint automatically whenever
// that data changes (create, delete, or an unrelated refresh) via the same
// document-level ts:domain:updated mechanism every other List already uses
// — no bespoke event wiring needed.
//////////////////////////////////
import Dockable from "./_dockable.js";
import Toast from "./toast.js";
import PageStatus from "./page-status.js";
import Grid from "./grid.js";
import BatchGridModel from "../models/batch-grid-model.js";
import RecipeCountGridModel from "../models/recipe-count-grid-model.js";
import PrepGridModel from "../models/prep-grid-model.js";
import TaskComponentHistoryGridModel from "../models/task-component-history-grid-model.js";

// Declarative shape for the three sub-widgets — everything that differs
// between Batch/RecipeCount/Prep lives here.
const COMPONENTS = [
  {
    key: "Batch",
    label: "Batches",
    ModelClass: BatchGridModel,
    // A batch created here is a PLAN to make it, not yet a real one — the
    // standalone [scoop_grid type="Batch"] control (real batches, made
    // right now) never sends this and keeps cascading tub creation
    // immediately; scoop_create_tubs_for_new_batch() (hooks/batch-tub.php)
    // skips that cascade specifically when task+done=false are both set.
    extraFields: { done: false },
    history: {
      entityKey: "batch",
      relationField: "flavor",
      relationLabel: "flavor",
      deleteFn: (id, api) => api.deleteBatch(id),
      deleteNoun: "batch",
    },
  },
  {
    key: "RecipeCount",
    label: "Recipe production",
    ModelClass: RecipeCountGridModel,
    history: {
      entityKey: "recipe_count",
      relationField: "recipe",
      relationLabel: "recipe",
      deleteFn: (id, api) => api.deleteRecipeCount(id),
      deleteNoun: "recipe count",
    },
  },
  {
    key: "Prep",
    label: "Ingredient prep",
    ModelClass: PrepGridModel,
    history: {
      entityKey: "prep",
      relationField: "ingredient",
      relationLabel: "ingredient",
      extraCols: [
        { key: "units", label: "units" },
        { key: "other", label: "notes" },
      ],
      deleteFn: (id, api) => api.deletePrep(id),
      deleteNoun: "prep",
    },
  },
];

export default class TaskForm extends Dockable {
  constructor(dom, type, { api, modelInstance, formCodec, pageStatusId = null } = {}) {
    super();
    this.dom = dom;
    this.target = dom; // Dockable's methods key off this.target — see _dockable.js.
    this.name = type;
    this.api = api;
    this.modelInstance = modelInstance;
    this.formCodec = formCodec;
    this.pageStatusId = pageStatusId;

    this.domain = null;
    this.taskId = null;
    this._built = false;
    this._mounted = false;
    this._staff = []; // [{id, title}]
    this._widgets = {}; // componentKey -> { cfg, createHost, historyHost }

    this.ROOT = this.el("div", { classes: ["task-form"] });
    this.ROOT.append(this.el("h2", { text: "New Task" }));
    this.ROOT.append(this.el("p", { text: "Loading…", classes: ["loading-note"] }));

    // Every shortcode is expected to be dockable (see DOCKING.md) — TOGGLE
    // is a sibling of ROOT, same as List's FORM+TOGGLE under target (see
    // _attachCoreDom() in _list.js). ROOT is a plain <div> (not <form> — see
    // the file-level comment on why it can't be), so css.css needs its own
    // ".in-dock .scoop-grid > .task-form" show/hide rule alongside the
    // generic "> form" one List's FORM already matches.
    this.TOGGLE = this._buildToggleButton();
    this.dom.replaceChildren(this.ROOT, this.TOGGLE);
    this._bindDockToggle();
    this._bindPageStatusToggle();
  }

  // External contract ScoopAPI actually calls (mountAllGrids' bundleGrids
  // forEach(g => g.setDomain(...))). First call: fetches kitchen-staff and
  // builds the real form. Once the component grids are mounted, they're
  // independent List instances and handle their own domain refreshes.
  async setDomain(domain) {
    this.modelInstance.setDomain(domain);
    this.domain = domain;

    if (!this._built) {
      this._staff = await this._fetchKitchenStaff();
      this._buildForm();
      this._built = true;
    }

    if (this.pageStatusId) PageStatus.setState(this.pageStatusId, "fresh");
  }

  async _fetchKitchenStaff() {
    try {
      const url = new URL("/wp-json/scoop/v1/kitchen-staff", window.location.origin);
      const res = await fetch(url, {
        credentials: "include",
        headers: { Accept: "application/json", ...(this.api?.nonce ? { "X-WP-Nonce": this.api.nonce } : {}) },
      });
      const data = await res.json().catch(() => null);
      return (res.ok && data?.ok && Array.isArray(data.staff)) ? data.staff : [];
    } catch (err) {
      console.error("TaskForm: failed to load kitchen staff:", err);
      return [];
    }
  }

  _buildForm() {
    const el = this.el;
    this.ROOT.replaceChildren();
    this.ROOT.append(el("h2", { text: "New Task" }));

    // ── Task's own fields — a small, self-contained <form> (nothing else
    // gets nested inside it, so this is the only piece here that's safe to
    // be a real <form>). ──
    this.TASK_FORM = el("form", { classes: ["field-group", "task-fields"] });

    // Optional — left blank, the server auto-generates a title from the
    // description/assignee/date (scoop_set_task_title(), includes/hooks/
    // task-titles.php). A non-empty value here wins outright; see
    // scoop_create_pod_item()'s task branch in includes/_write_fields.php.
    this.TITLE_INPUT = el("input", { attrs: { type: "text", placeholder: "Leave blank to auto-generate" } });

    this.TARGET_SELECT = el("select", { classes: ["task-target"] });
    this.TARGET_SELECT.append(el("option", { text: "Unassigned", attrs: { value: "" } }));
    this._staff.forEach((s) => {
      this.TARGET_SELECT.append(el("option", { text: s.title, attrs: { value: String(s.id) } }));
    });

    this.OTHER_INPUT = el("textarea", { attrs: { placeholder: "What needs to happen?" } });

    this.TASK_FORM.append(
      this._fieldLabeled("Title", this.TITLE_INPUT),
      this._fieldLabeled("Assign to", this.TARGET_SELECT),
      this._fieldLabeled("Description", this.OTHER_INPUT),
    );

    this.CREATE_TASK_BTN = el("button", { text: "Create Task", attrs: { type: "submit" } });
    this.TASK_FORM.append(this.CREATE_TASK_BTN);

    this.TASK_FORM.addEventListener("submit", (e) => {
      e.preventDefault();
      this._submitTask();
    });

    this.ROOT.append(this.TASK_FORM);

    // ── Component widgets, hidden until the task exists. Plain container —
    // NOT a <form> — since each widget will hold real Grid <form>s as
    // siblings once mounted (see _mountComponentGrids). ──
    this.COMPONENTS_SECTION = el("div", { classes: ["task-components"], attrs: { hidden: "hidden" } });
    COMPONENTS.forEach((cfg) => this.COMPONENTS_SECTION.append(this._buildComponentWidget(cfg)));
    this.ROOT.append(this.COMPONENTS_SECTION);
  }

  _fieldLabeled(text, control) {
    const WRAP = this.el("label", { classes: ["field"] });
    WRAP.append(this.el("span", { text }), control);
    return WRAP;
  }

  _buildComponentWidget(cfg) {
    const el = this.el;
    const WIDGET = el("div", { classes: ["component-widget"], data: { component: cfg.key } });
    WIDGET.append(el("h3", { text: cfg.label }));

    const CREATE_HOST = el("div", { classes: ["component-grid-host"] });
    const HISTORY_HOST = el("div", { classes: ["component-history-host"] });
    WIDGET.append(CREATE_HOST, HISTORY_HOST);

    this._widgets[cfg.key] = { cfg, createHost: CREATE_HOST, historyHost: HISTORY_HOST };
    return WIDGET;
  }

  async _submitTask() {
    if (this.taskId) return; // already created, nothing left to submit

    const payload = {
      cells: {
        0: {
          target: this.TARGET_SELECT.value ? Number(this.TARGET_SELECT.value) : 0,
          other: this.OTHER_INPUT.value || "",
          post_title: this.TITLE_INPUT.value.trim(),
        },
      },
    };

    this.CREATE_TASK_BTN.disabled = true;
    this.CREATE_TASK_BTN.textContent = "Creating…";

    try {
      const r = await this.api.postJson(payload, "Task");
      if (!r.ok || !r.data?.ok) {
        Toast.addMessage({ title: "Task create failed", message: r.data?.errors?.[0]?.error ?? `HTTP ${r.status}` });
        return;
      }

      this.taskId = r.data.created.id;
      Toast.addMessage({ title: "Task created", message: "Now add batches, preps, or recipe counts below." });

      this.TITLE_INPUT.disabled = true;
      this.TARGET_SELECT.disabled = true;
      this.OTHER_INPUT.disabled = true;
      this.CREATE_TASK_BTN.remove();
      this.COMPONENTS_SECTION.hidden = false;
      this._mountComponentGrids();
    } catch (err) {
      console.error("Task create failed:", err);
      Toast.addMessage({ title: "Task create failed", message: String(err) });
    } finally {
      if (!this.taskId) {
        this.CREATE_TASK_BTN.disabled = false;
        this.CREATE_TASK_BTN.textContent = "Create Task";
      }
    }
  }

  // Builds the create + history Grid pairs once the task exists (both need
  // taskId — the create grid for its hidden link field, the history grid to
  // scope which rows it shows — so this can't happen any earlier).
  _mountComponentGrids() {
    if (this._mounted) return;
    this._mounted = true;

    for (const cfg of COMPONENTS) {
      const widget = this._widgets[cfg.key];
      this._mountCreateGrid(cfg, widget);
      this._mountHistoryGrid(cfg, widget);
    }
  }

  _mountCreateGrid(cfg, widget) {
    const modelInstance = new cfg.ModelClass(cfg.key, this.domain, {}, null);

    const grid = new Grid(document.createElement("div"), cfg.key, {
      api: this.api,
      modelInstance,
      formCodec: this.formCodec,
      columns: modelInstance.columns,
    });
    grid.dockToggle?.(); // no-op outside a [scoop_dock] page — see _list.js

    // Links every create from this grid to the task, plus any per-type
    // fixed fields (e.g. Batch's done:false — see COMPONENTS above) — see
    // the file-level comment for why this is safe to bolt on outside the
    // model's own columns.
    const hiddenFields = { task: this.taskId, ...(cfg.extraFields ?? {}) };
    for (const [key, value] of Object.entries(hiddenFields)) {
      // Booleans as "0"/"1", never JS's String(false) === "false" — a
      // non-empty string is truthy in PHP, so the literal text "false"
      // would land as done=true, backwards from what's intended.
      const domValue = typeof value === "boolean" ? (value ? "1" : "0") : String(value);
      grid.FORM.append(this.el("input", {
        attrs: { type: "hidden", name: `${cfg.key}[cells][0][${key}]`, value: domValue },
      }));
    }

    widget.createHost.replaceChildren(grid.FORM);
    widget.createGrid = grid;
    grid.setDomain(this.domain);
  }

  _mountHistoryGrid(cfg, widget) {
    const h = cfg.history;
    const modelInstance = new TaskComponentHistoryGridModel("Task", this.domain, {
      taskId: this.taskId,
      entityKey: h.entityKey,
      relationField: h.relationField,
      relationLabel: h.relationLabel,
      relationDomainKey: h.relationDomainKey,
      extraCols: h.extraCols,
      deleteFn: h.deleteFn,
      deleteNoun: h.deleteNoun,
    });

    const grid = new Grid(document.createElement("div"), "Task", {
      api: this.api,
      modelInstance,
      formCodec: this.formCodec,
      columns: modelInstance.columns,
    });
    grid.dockToggle?.();

    widget.historyHost.replaceChildren(grid.FORM);
    widget.historyGrid = grid;
    grid.setDomain(this.domain);
  }
}
