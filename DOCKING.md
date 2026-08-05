# GUI Docking Architecture (design notes, MVP scope)

Status: baseline implemented. `[scoop_dock]` shortcode
([shortcode.php](includes/shortcode.php)), `displayTitle`/`icon` plumbing
(PHP: [enqueue.php](includes/enqueue.php); JS:
[_base-grid-model.js](assets/models/_base-grid-model.js)), and the
toolbar-reparenting toggle
([_list.js](assets/ui/_list.js): `_buildToggleButton()`/`dockToggle()`,
wired from [app.js](assets/app.js)) are live. Values are currently the
per-type defaults only (logical name / first letter) — no per-type override
has been authored yet.

## Goal

Every grid/tile control gets a `.gridToggle` button that collapses it out of
view and re-expands it on demand. Long-term: a persistent icon strip ("dock")
pinned to the bottom of the screen, with each mounted grid represented as one
icon. Clicking an icon expands its control into view; clicking again (or the
control's own toggle) collapses it back to an icon.

## What already exists

- `TOGGLE` button (`class="gridToggle"`) is built in every list control,
  both view types: [grid.js:20](assets/ui/grid.js#L20),
  [tile.js:34](assets/ui/tile.js#L34).
- Click handling is already generic, not type-specific:
  [_list.js:1500-1505](assets/ui/_list.js#L1500-L1505) toggles a `.toggled`
  class on `this.target` (the `.scoop-grid` host element) for *any* list
  type.
- CSS currently only *styles* this for `.Batch`:
  [css.css:540-555](assets/css.css#L540-L555) sets `.gridToggle { display:
  none }` by default and turns it back on only for `.Batch`.
- `.Batch` also already has a working collapse/expand animation —
  [css.css:612-678](assets/css.css#L612-L678) — a fixed-position fab with a
  `::before`/`::after` pseudo-content icon+label, and `&.toggled form` doing
  the actual slide/fade transition. This is a working prototype of the
  target interaction, but it's hardcoded per-instance (fixed `top`, no
  shared row, icon baked into CSS content).
- All `.scoop-grid` hosts exist in the DOM before JS runs — WordPress
  renders the full page server-side; `mountAllGrids()`
  ([app.js:14](assets/app.js#L14)) does one synchronous pass over
  everything already in the DOM at `DOMContentLoaded`. There is currently no
  case where a grid shortcode mounts *after* initial page load. This matters
  for the registration question below — a pub/sub "dock listens for grids
  that show up later" mechanism is not needed yet, because nothing shows up
  later yet.
- Each host gets a random per-render id: `id="scoop-grid-<uniqid()>"`
  ([shortcode.php:33](includes/shortcode.php#L33)). Not stable across
  reloads — relevant to the URL-hash state idea below.

## MVP scope (this pass, when implemented)

- All controls dock to the same **aside** mode: expanding a control adds it
  beside whatever else is already open, nothing else auto-closes.
- No accordion/eviction logic yet.
- Icon + title as two explicit pieces of markup/config, not the `.Batch`
  pseudo-content trick (see "Icon representation" below).
- Apply the same docking rule evenly to *all* GUI controls (not just
  Batch) — i.e. Batch's bespoke fab is the pattern to generalize, not a
  special case to preserve as-is.
- URL hash reflects open/closed state (see "State model" below), so a link
  can force a given display state, but no size-mode logic riding on it yet.

## Sizing / placement modes (concept, later)

Per-type declared placement, richer than plain expand/collapse:

- `aside` — opens alongside other open controls (MVP default, only mode
  implemented initially).
- `overlay` — floats above other content without displacing it.
- `half` / `quarter` — claims a fraction of the dock viewport.
- `only-full` — takes over the full screen and closes every other open
  control (except the persistent dock strip itself). Opening *anything*
  else while an `only-full` control is open closes it first.

This is a per-type declaration (lives wherever type config already lives —
see `scoop_routes_config()` in `includes/_config.php`, the existing
single-source for per-type behavior) plus a small runtime coordinator that
enforces eviction rules when they conflict. Not needed for MVP; noted here
so the MVP data shape doesn't have to be reworked to add it later — e.g.
storing `dockMode` per open entry now, even though only `aside` is legal
today, avoids a schema change when the other modes land.

## State model

Requirement: a URL can force a specific set of controls open (shareable
"display state" link), not just runtime-only toggle state.

Open design question, not yet resolved: what identifies a grid instance in
the hash? `data-grid-type` alone collides if the same type appears twice on
one page (e.g. two `FlavorTub` grids at different locations). The DOM id
(`scoop-grid-<uniqid>`) is unique but regenerates every page render, so it
can't be the thing a URL points at. Most likely stable key is a composite of
`data-grid-type` + `data-location` (+ possibly a shortcode-supplied slug for
the rare case of two same-type/same-location instances) — needs a decision
before implementation, not just documentation.

Shape sketch (not final): `#dock=FlavorTub:935,Batch:935` — comma-separated
`type:location` pairs, parsed on load, applied as `.toggled` before/at mount
so there's no flash of the default (collapsed) state, and kept in sync via
`history.replaceState` as the user opens/closes things (not `pushState` —
toggling dock panels shouldn't spam browser back-button history).

## Icon representation — IMPLEMENTED

Moved off the `.Batch` `::before`/`::after` pseudo-content pattern (
[css.css:218-232](assets/css.css#L218-L232)) — not enough CSS control over
the icon (sizing, positioning, swapping) through pseudo-content alone.

Two explicit properties per type, shipped like other per-type client config
(parallel to how `scoop_client_metadata()` already ships per-field column
config to the JS): `displayTitle` and `icon`, added to each route's entry in
`scoop_client_metadata()` ([enqueue.php](includes/enqueue.php)) — default is
the route's own logical name / its first letter (`mb_substr($route_key, 0,
1)`); no per-type override authored yet, though `$cfg['display_title']` /
`$cfg['icon']` in `scoop_routes_config()` will be read first once one is.

`BaseGridModel`'s constructor
([_base-grid-model.js](assets/models/_base-grid-model.js)) exposes these as
`this.displayTitle`/`this.icon`, falling back to the model's own `name` for
types that don't go through `scoop_routes_config()` at all (Analytics,
Popular, Flavors, ...).

`List._buildToggleButton()` ([_list.js](assets/ui/_list.js)) renders them as
real DOM nodes plus a `<span class="dockTitle">` — inside the `.gridToggle`
button, not CSS content. Full styling control, and the icon/title are
swappable per-type later without a CSS deploy. `icon` is told apart by
shape/marker, checked in this order:

1. Starts with `<svg` → injected as real inline SVG markup (`innerHTML`),
   e.g. pasted straight from the Streamline VS Code extension's clipboard
   copy. Checked first since raw SVG markup usually contains `/` itself,
   which would otherwise match the image-path check below.
2. Starts with `if:` (`List.ICON_FONT_MARKER`) → icon-font glyph: `if:battery`
   renders `<i class="dockIcon si-battery">`, empty, glyph supplied by the
   generated icon-font CSS (see below). `si-` (`List.ICON_FONT_CSS_PREFIX`)
   is the only place the actual CSS prefix is spelled out — the per-type
   config only ever carries the marker, never the raw class name.
3. Contains `/` or ends in a known image extension → `<img class="dockIcon"
   src="...">`.
4. Anything else → literal text/unicode glyph in a `<span>` (the `mb_substr`
   first-letter default from PHP lands here).

### Icon font pipeline (dev-time only, via `icon-font-generator`)

No build step at deploy time — this runs locally, once per icon added, and
only its *output* is committed:

1. Find an icon via the Streamline VS Code extension, save the SVG into
   `assets/icon-font/svg/<name>.svg`.
2. Run:
   ```
   npx icon-font-generator assets/icon-font/svg/*.svg -o assets/icon-font/dist -n scoop-icons -p si -j
   ```
3. Commit the generated `assets/icon-font/dist/` output. `scoop_enqueue_assets()`
   ([enqueue.php](includes/enqueue.php)) enqueues `scoop-icons.css` from there
   automatically, but only `if (file_exists(...))` — harmless before the
   first icon is ever generated.
4. Point a type at it: `'icon' => 'if:battery'` in that type's entry in
   `scoop_routes_config()` ([_config.php](includes/_config.php)).

`assets/icon-font/svg/` (source) is excluded from the deploy rsync
([deploy.yml](.github/workflows/deploy.yml)) — only `dist/` (the actual
runtime CSS/font files) ships. `node_modules`/`package.json`/
`package-lock.json` are excluded too — `icon-font-generator` is a `npx`-run
dev tool, never installed on the live server.

## Registration / mounting model — RESOLVED: ancestor class, not pub/sub

Decision: no event plumbing, no explicit registration call. A control
activates docking behavior purely by DOM position — if `this.target`
(the `.scoop-grid` host) has an ancestor element carrying class `in-dock`,
it's docked; otherwise it's a normal inline control. Check via
`this.target.closest('.in-dock')`.

This drops both options previously weighed here (shortcode pub/sub vs.
central app.js coordination) — neither is needed:

- No listener/registration step. A control doesn't announce itself to a
  dock; the dock is just whatever DOM structure wraps it. Works
  identically whether that wrapping happens via a `[scoop_dock]` shortcode
  emitting an `.in-dock` container, a page template, or a manually placed
  `<div class="in-dock">` — the control doesn't care who put the class
  there.
- Layout/behavior differences between docked and undocked can mostly be
  plain CSS scoping (`.in-dock .scoop-grid { ... }`), since the click
  handler that toggles `.toggled` ([_list.js:1500-1505](assets/ui/_list.js#L1500-L1505))
  is already generic and unaware of docking. Docking changes *how* a
  toggled/untoggled control looks and where it sits, not whether toggling
  itself works.
- JS is only needed where behavior (not just appearance) must differ for
  docked controls — e.g. defaulting to collapsed on mount, or honoring the
  URL-hash open state (see "State model") only for controls that are
  actually dockable. Same check applies there: `closest('.in-dock')` at
  init time, no separate registry to keep in sync.

This also answers the layout-ownership question raised in the previous
draft of this section (whether page composition stays per-shortcode or
goes central): it stays per-shortcode / per-page, same as today. The page
author decides what's inside `.in-dock` by where they place markup; no
control needs to know about any other control.

## Explicitly out of scope for MVP

- `overlay` / `half` / `quarter` / `only-full` sizing modes and their
  eviction rules.
- Refactoring `.Batch`'s existing fixed fab into the shared dock (tracked as
  a follow-up once the generic dock exists and proves out).
- Per-user persistence of open/closed state beyond what the URL hash
  encodes.
