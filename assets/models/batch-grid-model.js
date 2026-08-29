import SingleRelationCountGridModel from "./_single-relation-count-grid-model.js";

// "Add batch" — flavor + count. See _single-relation-count-grid-model.js
// for the shared shape (extracted from this file once RecipeCountGridModel
// turned out identical except for the relation field name).
export default class BatchGridModel extends SingleRelationCountGridModel {
  constructor(name = 'Batch', domain, attrs = {}, metaData = null) {
    super(name, 'flavor', domain, attrs, metaData);
  }
}
