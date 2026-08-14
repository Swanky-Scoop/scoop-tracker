/**
 * Small generic reader/writer for `#key=value&key2=value2` state living in
 * the URL hash — the same URLSearchParams-over-location.hash pattern
 * ScoopAPI._hashForcesBust() already used for the one-off `#bust` flag,
 * pulled out so every future piece of hash-recorded GUI state (location
 * overrides now, dock open/closed state later — see DOCKING.md's "State
 * model") shares one parser/writer instead of each reinventing it.
 *
 * Uses history.replaceState, never pushState — hash state here reflects
 * "what's currently displayed," not a navigable history of user actions, so
 * toggling a panel or switching a location shouldn't spam the back button.
 */
export default class HashState {
  static _params() {
    const raw = location.hash.startsWith('#') ? location.hash.slice(1) : location.hash;
    return new URLSearchParams(raw);
  }

  static has(key) {
    return this._params().has(key);
  }

  static get(key) {
    return this._params().get(key);
  }

  static getAll() {
    return Object.fromEntries(this._params().entries());
  }

  // value === null/undefined/'' removes the key instead of writing an empty one.
  static set(key, value) {
    const params = this._params();
    if (value == null || value === '') params.delete(key);
    else params.set(key, String(value));

    const next = params.toString();
    const url = new URL(window.location.href);
    url.hash = next;
    history.replaceState(null, '', url);
  }
}
