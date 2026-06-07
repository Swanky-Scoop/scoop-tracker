import BaseGridModel from "./_base-grid-model.js";

export default class InventoryChangeGridModel extends BaseGridModel {
  constructor(name = "InventoryChange", domain, attrs = {}) {
    super(name, domain, attrs);
  }
}