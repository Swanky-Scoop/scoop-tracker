///////////////////////////////////
// Task-specific config for the Details modal (assets/ui/details.js),
// registered there against entity 'task'. All the actual rendering/edit
// machinery — view mode, the Edit button, scalar-field controls (FindIt/
// ToggleIt/textarea), and the attached-list add/delete mechanism — lives in
// _detail-edit.js's buildEditableDetailView(); this file only declares
// Task's shape so any other entity that needs the same kind of editable
// Details panel can reuse the same builder instead of re-implementing it.
//
// `other` (Task's own description) rides through 'TaskEdit' alongside
// target/done — see scoop_task_edits_allowed_fields(). The three attached
// lists (batches/recipe_counts/preps) aren't fields on the task item at
// all — they're reverse Pods relations; each child (batch/recipe_count/
// prep) carries its own `task` field, and setting that is enough for Pods
// to sync the task's reverse list automatically (see task-form.js's
// identical note on this bidirectional sister-field behavior). Adding one
// posts to its own existing create route ('Batch'/'RecipeCount'/'Prep'),
// same as task-form.js's "New Task" widgets do.
//////////////////////////////////
import { buildEditableDetailView, fmtCount } from "./_detail-edit.js";

// Same roster TasksGridModel.getOptions('target') and task-form.js's own
// assignee picker draw from — WP Users aren't a bundle entity, so this
// rides SCOOP.kitchenStaff (localized at page load, see enqueue.php)
// instead of the generic domain-array option fallback _detail-edit.js uses
// for relation fields that DO point at a Pod.
function kitchenStaffOptions() {
  const staff = Array.isArray(window.SCOOP?.kitchenStaff) ? window.SCOOP.kitchenStaff : [];
  return staff.map((s) => ({ key: s.id, label: s.title }));
}

const FIELDS = [
  {
    key: 'target', label: 'Assignee', control: 'find',
    displayValue: (item) => item.target_name || 'Unassigned',
    options: kitchenStaffOptions,
  },
  { key: 'other', label: 'Description', control: 'textarea' },
  { key: 'done', label: 'Done', control: 'toggle' },
  { key: 'post_date', label: 'Created', readOnly: true },
  {
    key: 'completed', label: 'Completed', readOnly: true,
    visible: (item) => item.done && item.completed,
  },
];

const LISTS = [
  {
    routeKey: 'Batch', domainListKey: 'batch', parentField: 'task',
    listLabel: 'Ice-cream Production', addLabel: 'Add batch',
    relation: { key: 'flavor', domainKey: 'flavor', label: 'Flavor' },
    // A batch added here is a PLAN to make it, not yet a real one — see
    // task-form.js's identical extraFields comment (skips the
    // tub-creation cascade in hooks/batch-tub.php).
    extraFields: { done: false },
    deleteFn: (id, api) => api.deleteBatch(id),
    deleteNoun: 'batch',
  },
  {
    routeKey: 'RecipeCount', domainListKey: 'recipe_count', parentField: 'task',
    listLabel: 'Recipe production', addLabel: 'Add recipe count',
    relation: { key: 'recipe', domainKey: 'recipe', label: 'Recipe' },
    deleteFn: (id, api) => api.deleteRecipeCount(id),
    deleteNoun: 'recipe count',
  },
  {
    routeKey: 'Prep', domainListKey: 'prep', parentField: 'task',
    listLabel: 'Ingredient prep', addLabel: 'Add prep',
    relation: { key: 'ingredient', domainKey: 'ingredient', label: 'Ingredient' },
    secondRelation: { key: 'units', domainKey: 'unit', label: 'Units' },
    notesField: 'other',
    formatText: (relationTitle, row, unitTitle) =>
      `${relationTitle} ${fmtCount(row.count)}${unitTitle ? ' ' + unitTitle : ''}`,
    deleteFn: (id, api) => api.deletePrep(id),
    deleteNoun: 'prep',
  },
];

export const renderTaskDetails = buildEditableDetailView({
  writeKey: 'TaskEdit',
  fields: FIELDS,
  lists: LISTS,
});
