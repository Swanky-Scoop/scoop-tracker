///////////////////////////////////
// Static untility  
// Consumed by FindIt and FindInList
//////////////////////////////////

export default class Find{
   
  static norm(s) {
    return (s ?? "").toString().trim().toLowerCase();
  }

  /**
   * Match items against a query.
   * items: array of objects
   * getText: fn(item) => string
   */
  static match(query, items, getText) {
    const q = Find.norm(query);
    if (!q) return items;

    const scored = [];

    for (const item of items) {
      const text = Find.norm(getText(item));
      if (!text) continue;

      if (text.startsWith(q)) {
        scored.push({ item, score: 0 });
      } else {
        const i = text.indexOf(q);
        if (i >= 0) scored.push({ item, score: 10 + i });
      }
    }

    scored.sort((a, b) => a.score - b.score);
    return scored.map(x => x.item);
  }

}