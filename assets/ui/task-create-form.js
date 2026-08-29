///////////////////////////////////
// TaskCreateForm — "Add task" quick-entry: target + description, plus the
// task's actual work — repeatable batch, recipe-count, and prep rows
// (task.batches was briefly pick_format_type=single during design, but was
// reconfigured to multi in Pods admin before this shipped — same
// repeatable-row treatment as the other two now). Same repeatable-row
// pattern as ShiftReportForm's cake_orders (see _addCakeOrderRow() there),
// applied three times.
//
// Submission is sequential, not one bespoke orchestrating endpoint like
// scoop_handle_shift_report_create(): create the task first (to get its
// id), then each batch/recipe_count/prep row via its own already-generic
// create route (Batch/RecipeCount/Prep — see _config.php), each carrying
// task: <new task id>. This works in that order (parent first) rather
// than cake_orders' children-first order because recipe_count.task/
// batch.task/prep.task are Pods bidirectional (sister) fields with
// task.recipe_counts/batches/preps — setting the child's own 'task' field
// is enough, Pods syncs the task's reverse list itself, no follow-up
// PATCH to the task ever needed (confirmed directly via
// pods_api()->load_field() against the real local pod config).
//
// See task-grid-model.js and the 'Task' bundle spec in _specs.php for
// where flavor/recipe/ingredient/unit come from (the bundle domain) vs.
// 'target' (its own GET /kitchen-staff — see that endpoint's comment in
// rest.php for why WP users don't fit the bundle).
//
// flavor/recipe/ingredient are FindIt (type-to-filter) pickers, not plain
// <select> — those lists run 200-300+ options each (real domain data,
// checked directly), which is a genuinely bad picking experience as a
// native dropdown. FindIt (assets/ui/find-it.js) is this codebase's own
// existing autocomplete control (already used for Batch's own flavor
// picker) — considered using Pods' own built-in autocomplete widget
// instead, but that's part of Pods' React-based "DFV" admin field system,
// designed to be server-rendered into a normal WP page and submitted
// through Pods' own form pipeline (see pods_form_render_fields() in
// wp-content/plugins/pods/includes/forms.php) — adopting it would mean
// bypassing this app's entire custom REST/auth layer for just this one
// form and mixing server-rendered content into an otherwise fully
// client-rendered app. FindIt is a plain, already-proven fit instead.
// target (staff, ~10 people) and units (2 options) stay plain <select> —
// no picking-experience problem at that size.
//////////////////////////////////
import El from "./_el.js";
import Toast from "./toast.js";
import PageStatus from "./page-status.js";
import FindIt from "./find-it.js";

export default class TaskCreateForm extends El {
  constructor(dom, type, { api, modelInstance, pageStatusId = null } = {}) {
    super();
    this.dom = dom;
    this.name = type;
    this.api = api;
    this.modelInstance = modelInstance;
    this.pageStatusId = pageStatusId;

    this._staff = null; // [{id, title}] from GET /kitchen-staff, fetched once
    this._batchRows = [];       // [{ li, flavorSelect, countInput }]
    this._recipeCountRows = []; // [{ li, recipeSelect, countInput }]
    this._prepRows = [];        // [{ li, ingredientSelect, countInput, unitSelect, otherInput }]

    this.ROOT = this.el('form', { classes: ['task-create-form'] });
    this.ROOT.append(this.el('h2', { text: 'Add task' }));
    this.ROOT.append(this.el('p', { text: 'Loading…', classes: ['loading-note'] }));
    this.ROOT.addEventListener('submit', (e) => {
      e.preventDefault();
      this._submit();
    });
    this.dom.replaceChildren(this.ROOT);
  }

  // Same external contract every bundle-fed view satisfies (see
  // ShiftReportForm's own setDomain() comment) — first call fetches the
  // one-time staff list and builds the real form; later calls (a
  // background bundle refresh) just refresh this.modelInstance.domain,
  // since a mid-fill rebuild would discard in-progress typing for no
  // reason (same reasoning as ShiftReportForm/BatchGridModel's own
  // repaintOnRefresh = false).
  async setDomain(domain) {
    this.modelInstance.setDomain(domain);

    if (!this._staff) {
      this._staff = await this._fetchStaff();
      this._buildForm();
    }

    if (this.pageStatusId) PageStatus.setState(this.pageStatusId, 'fresh');
  }

  async _fetchStaff() {
    try {
      const url = new URL('/wp-json/scoop/v1/kitchen-staff', window.location.origin);
      const res = await fetch(url, {
        credentials: 'include',
        headers: { Accept: 'application/json', ...(this.api?.nonce ? { 'X-WP-Nonce': this.api.nonce } : {}) },
      });
      const data = await res.json().catch(() => null);
      return (res.ok && data?.ok && Array.isArray(data.staff)) ? data.staff : [];
    } catch (err) {
      console.error('TaskCreateForm: failed to load staff list:', err);
      return [];
    }
  }

  // domain.<entity> rows all share the {id, _title} shape (see getOptions()
  // in _base-grid-model.js) — mirrored here directly since this form isn't
  // a real List/BaseGridModel consumer.
  _domainOptions(entityKey) {
    const rows = this.modelInstance.domain?.[entityKey];
    return Array.isArray(rows)
      ? [...rows].map(r => ({ id: r.id, title: r._title || `#${r.id}` })).sort((a, b) => a.title.localeCompare(b.title))
      : [];
  }

  _buildForm() {
    const el = this.el;
    this.ROOT.replaceChildren();
    this.ROOT.append(el('h2', { text: 'Add task' }));

    // ── Target + description ──────────────────────────────────────────
    const TARGET_WRAP = el('div', { classes: ['field'] });
    TARGET_WRAP.append(el('label', { classes: ['field-label'], text: 'Target staffer' }));
    this.TARGET_SELECT = el('select');
    this.TARGET_SELECT.append(el('option', { text: '— Unassigned —', attrs: { value: '' } }));
    for (const s of this._staff) {
      this.TARGET_SELECT.append(el('option', { text: s.title, attrs: { value: s.id } }));
    }
    TARGET_WRAP.append(this.TARGET_SELECT);
    this.ROOT.append(TARGET_WRAP);

    const OTHER_WRAP = el('div', { classes: ['field'] });
    OTHER_WRAP.append(el('label', { classes: ['field-label'], text: 'What needs doing' }));
    this.OTHER_INPUT = el('textarea', {
      attrs: { placeholder: 'e.g. Chop 12oz walnuts for cookie monster', required: true },
    });
    OTHER_WRAP.append(this.OTHER_INPUT);
    this.ROOT.append(OTHER_WRAP);

    // ── Ice-cream batches (repeatable) ──────────────────────────────────
    const BATCH_SECTION = el('section', { classes: ['field-group'] });
    BATCH_SECTION.append(el('h3', { text: 'Ice-cream batches' }));
    this.BATCH_ROWS_WRAP = el('div', { classes: ['batch-rows'] });
    BATCH_SECTION.append(this.BATCH_ROWS_WRAP);
    this._flavorOptions = this._domainOptions('flavor');
    const ADD_BATCH = el('button', {
      text: '+ Add batch', classes: ['add-batch'], attrs: { type: 'button' },
    });
    ADD_BATCH.addEventListener('click', () => this._addBatchRow());
    BATCH_SECTION.append(ADD_BATCH);
    this.ROOT.append(BATCH_SECTION);

    // ── Recipe counts (repeatable) ──────────────────────────────────────
    const RECIPE_SECTION = el('section', { classes: ['field-group'] });
    RECIPE_SECTION.append(el('h3', { text: 'Recipe counts' }));
    this.RECIPE_COUNT_ROWS_WRAP = el('div', { classes: ['recipe-count-rows'] });
    RECIPE_SECTION.append(this.RECIPE_COUNT_ROWS_WRAP);
    this._recipeOptions = this._domainOptions('recipe');
    const ADD_RECIPE_COUNT = el('button', {
      text: '+ Add recipe count', classes: ['add-recipe-count'], attrs: { type: 'button' },
    });
    ADD_RECIPE_COUNT.addEventListener('click', () => this._addRecipeCountRow());
    RECIPE_SECTION.append(ADD_RECIPE_COUNT);
    this.ROOT.append(RECIPE_SECTION);

    // ── Preps (repeatable) ──────────────────────────────────────────────
    const PREP_SECTION = el('section', { classes: ['field-group'] });
    PREP_SECTION.append(el('h3', { text: 'Ingredient prep' }));
    this.PREP_ROWS_WRAP = el('div', { classes: ['prep-rows'] });
    PREP_SECTION.append(this.PREP_ROWS_WRAP);
    this._ingredientOptions = this._domainOptions('ingredient');
    this._unitOptions = this._domainOptions('unit');
    const ADD_PREP = el('button', {
      text: '+ Add prep', classes: ['add-prep'], attrs: { type: 'button' },
    });
    ADD_PREP.addEventListener('click', () => this._addPrepRow());
    PREP_SECTION.append(ADD_PREP);
    this.ROOT.append(PREP_SECTION);

    this.SUBMIT = el('button', { text: 'Add task', attrs: { type: 'submit' } });
    this.ROOT.append(this.SUBMIT);
  }

  _fieldLabeled(text, control) {
    const WRAP = this.el('label', { classes: ['field'] });
    WRAP.append(this.el('span', { text }), control);
    return WRAP;
  }

  // {id, title} (this._domainOptions()'s shape) -> {key, label} (FindIt's
  // expected option shape — see find-it.js's _matchOptions/_renderOptions).
  // Builds a fresh FindIt instance appended into its own wrapper div;
  // returns both so the wrapper can go into _fieldLabeled() and the
  // instance's .value (a string id, "" for none) can be read later at
  // collection time, same as reading a plain <select>.value elsewhere in
  // this file.
  _buildFindIt(entityOptions, placeholder) {
    const wrap = this.el('div', { classes: ['find-it-wrap'] });
    const options = entityOptions.map(o => ({ key: o.id, label: o.title }));
    const instance = new FindIt(wrap, { id: 0, rowId: 0, colKey: '', display: '', type: '', options }, '');
    if (placeholder) instance.INP.setAttribute('placeholder', placeholder);
    return { wrap, instance };
  }

  // ── Batch rows ───────────────────────────────────────────────────────
  _addBatchRow() {
    const el = this.el;
    const LI = el('div', { classes: ['batch-row'] });

    const { wrap: flavorWrap, instance: flavorFind } = this._buildFindIt(this._flavorOptions, 'Type to search flavors…');
    const countInput = el('input', { attrs: { type: 'number', step: '0.01', placeholder: 'count' } });
    const remove = el('button', { text: 'Remove', classes: ['remove-batch'], attrs: { type: 'button' } });

    remove.addEventListener('click', () => {
      LI.remove();
      this._batchRows = this._batchRows.filter(r => r.li !== LI);
    });

    LI.append(this._fieldLabeled('Flavor', flavorWrap), this._fieldLabeled('Count', countInput), remove);
    this.BATCH_ROWS_WRAP.append(LI);
    this._batchRows.push({ li: LI, flavorFind, countInput });
  }

  _collectBatchRows() {
    return this._batchRows
      .map(r => ({ flavor: r.flavorFind.value ? Number(r.flavorFind.value) : 0, count: Number(r.countInput.value) || 0 }))
      .filter(r => r.flavor && r.count > 0);
  }

  // ── Recipe count rows ────────────────────────────────────────────────
  _addRecipeCountRow() {
    const el = this.el;
    const LI = el('div', { classes: ['recipe-count-row'] });

    const { wrap: recipeWrap, instance: recipeFind } = this._buildFindIt(this._recipeOptions, 'Type to search recipes…');
    const countInput = el('input', { attrs: { type: 'number', step: '0.01', placeholder: 'count' } });
    const remove = el('button', { text: 'Remove', classes: ['remove-recipe-count'], attrs: { type: 'button' } });

    remove.addEventListener('click', () => {
      LI.remove();
      this._recipeCountRows = this._recipeCountRows.filter(r => r.li !== LI);
    });

    LI.append(this._fieldLabeled('Recipe', recipeWrap), this._fieldLabeled('Count', countInput), remove);
    this.RECIPE_COUNT_ROWS_WRAP.append(LI);
    this._recipeCountRows.push({ li: LI, recipeFind, countInput });
  }

  _collectRecipeCountRows() {
    return this._recipeCountRows
      .map(r => ({ recipe: r.recipeFind.value ? Number(r.recipeFind.value) : 0, count: Number(r.countInput.value) || 0 }))
      .filter(r => r.recipe && r.count > 0);
  }

  // ── Prep rows ────────────────────────────────────────────────────────
  _addPrepRow() {
    const el = this.el;
    const LI = el('div', { classes: ['prep-row'] });

    const { wrap: ingredientWrap, instance: ingredientFind } = this._buildFindIt(this._ingredientOptions, 'Type to search ingredients…');
    const countInput = el('input', { attrs: { type: 'number', step: '0.01', placeholder: 'count' } });
    const unitSelect = el('select');
    unitSelect.append(el('option', { text: '— Unit —', attrs: { value: '' } }));
    for (const u of this._unitOptions) {
      unitSelect.append(el('option', { text: u.title, attrs: { value: u.id } }));
    }
    const otherInput = el('input', { attrs: { type: 'text', placeholder: 'e.g. Chopped' } });
    const remove = el('button', { text: 'Remove', classes: ['remove-prep'], attrs: { type: 'button' } });

    remove.addEventListener('click', () => {
      LI.remove();
      this._prepRows = this._prepRows.filter(r => r.li !== LI);
    });

    LI.append(
      this._fieldLabeled('Ingredient', ingredientWrap),
      this._fieldLabeled('Count', countInput),
      this._fieldLabeled('Unit', unitSelect),
      this._fieldLabeled('Note', otherInput),
      remove,
    );
    this.PREP_ROWS_WRAP.append(LI);
    this._prepRows.push({ li: LI, ingredientFind, countInput, unitSelect, otherInput });
  }

  _collectPrepRows() {
    return this._prepRows
      .map(r => ({
        ingredient: r.ingredientFind.value ? Number(r.ingredientFind.value) : 0,
        count: r.countInput.value ? Number(r.countInput.value) : 0,
        units: r.unitSelect.value ? Number(r.unitSelect.value) : 0,
        other: r.otherInput.value.trim(),
      }))
      .filter(r => r.ingredient);
  }

  // ── Submit ───────────────────────────────────────────────────────────
  async _submit() {
    const targetId = this.TARGET_SELECT.value ? Number(this.TARGET_SELECT.value) : 0;
    const other = this.OTHER_INPUT.value.trim();

    if (!other) {
      Toast.addMessage({ title: 'Description required', message: 'Say what the task actually is before adding it.' });
      return;
    }

    const batchRows = this._collectBatchRows();
    const recipeCountRows = this._collectRecipeCountRows();
    const prepRows = this._collectPrepRows();

    this.SUBMIT.disabled = true;
    this.SUBMIT.textContent = 'Adding…';

    try {
      // scoop_handle_create_post() (includes/rest.php) expects exactly one
      // row under 'cells' per call — same envelope shape Batch's own GUI
      // sends via List's normal save path, built by hand here since none
      // of this is a real List instance (see this file's header comment).
      const taskCells = { 0: { other } };
      if (targetId) taskCells[0].target = targetId;

      const taskRes = await this.api.postJson({ cells: taskCells }, 'Task');
      if (!taskRes.ok || taskRes.data?.ok === false) {
        Toast.addMessage({ title: 'Add task failed', message: taskRes.data?.error ?? taskRes.data?.errors?.[0]?.error ?? 'Unknown error' });
        return;
      }
      const taskId = taskRes.data?.created?.id;
      if (!taskId) {
        Toast.addMessage({ title: 'Add task failed', message: 'Task saved but no id was returned — sub-items were not attached.' });
        return;
      }

      const subItemErrors = [];

      for (const row of batchRows) {
        // done: false explicitly (never omitted) — this is exactly the
        // signal scoop_create_tubs_for_new_batch's guard is keyed on (see
        // that hook's own comment in includes/hooks/batch-tub.php): skip
        // tub creation for a batch that's only just been assigned via a
        // task, not actually made yet.
        const r = await this.api.postJson({ cells: { 0: { flavor: row.flavor, count: row.count, task: taskId, done: false } } }, 'Batch');
        if (!r.ok || r.data?.ok === false) subItemErrors.push(`Batch: ${r.data?.error ?? r.data?.errors?.[0]?.error ?? 'failed'}`);
      }

      for (const row of recipeCountRows) {
        const r = await this.api.postJson({ cells: { 0: { recipe: row.recipe, count: row.count, task: taskId } } }, 'RecipeCount');
        if (!r.ok || r.data?.ok === false) subItemErrors.push(`Recipe count: ${r.data?.error ?? r.data?.errors?.[0]?.error ?? 'failed'}`);
      }

      for (const row of prepRows) {
        const payload = { ingredient: row.ingredient, task: taskId };
        if (row.count) payload.count = row.count;
        if (row.units) payload.units = row.units;
        if (row.other) payload.other = row.other;
        const r = await this.api.postJson({ cells: { 0: payload } }, 'Prep');
        if (!r.ok || r.data?.ok === false) subItemErrors.push(`Prep: ${r.data?.error ?? r.data?.errors?.[0]?.error ?? 'failed'}`);
      }

      if (subItemErrors.length) {
        Toast.addMessage({
          title: 'Task added, with issues',
          message: `Saved, but ${subItemErrors.length} sub-item(s) failed: ${subItemErrors.join('; ')}`,
        });
      } else {
        Toast.addMessage({ title: 'Task added', message: other });
      }
      this._resetForm();
    } catch (err) {
      console.error('Add task failed:', err);
      Toast.addMessage({ title: 'Add task failed', message: String(err) });
    } finally {
      this.SUBMIT.disabled = false;
      this.SUBMIT.textContent = 'Add task';
    }
  }

  _resetForm() {
    this.ROOT.reset();
    this.BATCH_ROWS_WRAP.replaceChildren();
    this._batchRows = [];
    this.RECIPE_COUNT_ROWS_WRAP.replaceChildren();
    this._recipeCountRows = [];
    this.PREP_ROWS_WRAP.replaceChildren();
    this._prepRows = [];
  }
}
