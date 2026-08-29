import SingleRelationCountGridModel from "./_single-relation-count-grid-model.js";

// "Add Recipe Count" — recipe + count. See _single-relation-count-grid-model.js
// for the shared shape (this file was the second concrete instance,
// compared side-by-side with BatchGridModel to confirm the pattern before
// extracting the base).
export default class RecipeCountGridModel extends SingleRelationCountGridModel {
  constructor(name = 'RecipeCount', domain, attrs = {}, metaData = null) {
    super(name, 'recipe', domain, attrs, metaData);
  }
}
