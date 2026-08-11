# css.css — extended notes

Rationale comments that used to live inline in [`css.css`](css.css) as
multi-line blocks, moved out here to keep the stylesheet itself scannable.
Each entry corresponds to a `/* see css.md#slug */` pointer comment at the
same spot in the CSS. Short comments (section banners, one-liners) were left
in place — this file only holds the long "why" explanations.

Organized by the same `====` section banners `css.css` itself uses, in file
order.

## CSS VARIABLES - COLOR PALETTE

<a id="color-status-critical"></a>
### `--color-status-critical`

Status/Trend Colors — each reused across at least two otherwise-
unrelated features (Analytics' supply/trend columns, DateActivity's
problem/phase columns, Popular's scatter-plot points and key) that
happen to want the same semantic color; kept as one shared value here
so they can't quietly drift apart.

<a id="color-state-emptied-row-bg"></a>
### `--color-state-emptied-row-bg`

Emptied tub row (targets .state-Emptied — see List._rowClasses and
FlavorTubGridModel's rowClassFields). Only recently-emptied tubs are
ever rendered at all (see RECENTLY_EMPTIED_CEILING_HOURS and
scoop_enforce_tub_rules), so no separate "recently" distinction is
needed here — any row with this class is, by construction, still
correctable.

<a id="dimension-batch-grid-width"></a>
### `--dimension-batch-grid-width`

Batch + its embedded BatchHistory receipt (see .scoop-grid.Batch below)
— one shared target width so both tables line up. Batch's own table is
narrower than the target by its Save button's protruding width, so the
button's own outer edge lines up flush with BatchHistory's table too.

min(30rem, calc(100vw - 1rem)) — not a plain 30rem — because both
tables read this var directly for their own `width` (see the table
rule inside `& form:not(.batch-history-embedded)` and
`form.batch-history-embedded` further down), not a percentage of
their ancestors. Capping .in-dock > .action-target's own width (or
.scoop-grid.Batch's) doesn't help: a raw-rem-width descendant just
overflows past a narrower ancestor instead of shrinking with it.
Capping the shared variable itself is what actually keeps the popup
inside the viewport (with 0.5rem to spare on each side) on a narrow
phone, and keeps both tables shrinking in lockstep so they still line
up.

<a id="dimension-dock-toolbar-bottom"></a>
### `--dimension-dock-toolbar-bottom`

.in-dock's .toolbar and .action-target (see DOCKING section below) are
peer elements, both independently position:fixed and bottom-anchored —
clearance is how far above the toolbar .action-target (and anything
fixed-positioned that docks inside it, e.g. .scoop-grid.Batch) sits, an
estimate of the toolbar's own rendered height plus a gap.

<a id="dock-aside-width"></a>
### `--dock-aside-width`

.in-dock's <aside> slot (see DOCKING section below) — 3/8 of .in-dock's
width, leaving .canvas the remaining 5/8. Live-adjustable: dragging
.dock-resizer (assets/ui/dock-resizer.js) overwrites this on .in-dock
directly, which this initial value is just the fallback for.

<a id="dimension-canvas-control-min"></a>
### `--dimension-canvas-control-min`

Preferred (not hard-floor) width for a half-stack/half-nostack .canvas
control (see DOCKING section below) — below this a control's own
content starts to feel cramped, so .canvas's padding collapses to 0
first (see --dimension-canvas-gutter-collapse below) to buy back a
little room. Past that, the control itself shrinks below this value
rather than forcing .canvas into a horizontal scroll — a 'half' target
must never be wider than the screen actually available to it (see the
half-stack/half-nostack rule further down, `min-width: min(...,
100%)`); the horizontal-scroll fallback on .canvas is reserved for
'full-stack'/'full-nostack' content that's intrinsically wide (e.g.
ItemPivot's own table), which IS expected to scroll. Paired with the
40rem ceiling on .scoop-grid itself (see "GRID CONTAINER" further down)
so a lone control grows toward 40rem but never past it, leaving calm
space for .TOASTER to slide in from the right.

<a id="dimension-canvas-gutter"></a>
### `--dimension-canvas-gutter`

.canvas's own outer gutter (see DOCKING section below) — kept at
--dimension-canvas-gutter whenever there's room, but dropped to 0 once
available width can't otherwise fit one --dimension-canvas-control-min-
wide control without scrolling. A variable (not a literal 1rem) because
a sticky .gridFilterInput inside .canvas has to cancel this padding out
of its own `top` offset — see ".in-dock .canvas .gridFilterInput"
further down — and the two must move together or the filter drifts out
of flush again. 34rem gives a control at its 30rem floor a 2rem margin
(1rem each side) of room to lose before an actual scrollbar becomes
necessary. Viewport-based (like popularView's own breakpoint further
down), not a true container query against .canvas itself — so with
<aside> open the padding can drop a little earlier than this number
alone implies, since .canvas is then narrower than the viewport.

## STATUS NOTIFICATIONS

<a id="page-status-cache-bust-counting-down"></a>
### `.PAGE-STATUS .cache-bust.counting-down`

Circular timer ring around the cache-bust countdown text. Two pseudo-
elements, no extra DOM: a dim full-circle track (::after) plus a
conic-gradient fill (::before) driven by the --eta-progress custom
property (0-100) that page-status.js's _startBustCountdown sets on the
element every tick. Both are masked from solid circles into a ring via
radial-gradient. Scoped to .counting-down so the ring only exists while
the countdown is actually running.

<a id="page-status-cache-bust-counting-down-letter-spacing-0-05em"></a>
### `.PAGE-STATUS .cache-bust.counting-down { letter-spacing: -0.05em }`

Monospace gives '.' the same full character cell as every digit, which
reads as an oversized gap around it. A small negative letter-spacing
tightens that uniformly (isolating just the '.' glyph would need either
wrapping it in its own element in JS or a unicode-range @font-face
override — more moving parts for a subtle gain).

## GRID CONTAINER

<a id="scoop-grid-not-batch-not-has-tbody-tr-not-has-li-data-row-id"></a>
### `.scoop-grid:not(.Batch):not(:has(tbody tr)):not(:has(li[data-row-id])):not(:has(.empty-state)) > form.zList-form`

Sizes the loading card itself (not just the shimmer overlay) to fit 6
skeleton rows, so form.zList-form's own box-shadow wraps the full
placeholder instead of just the (still-short) real header content. One
selector now covers both Grid and Tile — zGRID-form/zTILE-form used to be
two separate classes needing two selectors here; both are zList-form now
(see "TILE VIEW" section below on how table vs. div.zList still
differentiates the two where a real difference is needed).
.scoop-grid is unsized and just grows to contain this. Scoped to the same
"no rows yet" condition as the shimmer below, so a populated grid with
only a few real rows isn't forced to this height too.

<a id="scoop-grid-not-batch-not-has-tbody-tr-not-has-li-data-row-id-2"></a>
### `.scoop-grid:not(.Batch):not(:has(tbody tr)):not(:has(li[data-row-id])):not(:has(.empty-state))::after`

Shimmering skeleton placeholder — shown while a grid has no rows yet
(before the bundle fetch resolves and List/Grid appends the first
<tbody> row or, for the card view, the first <li>). Mostly gated on DOM
shape (table.zList holds only its <thead>, .zList's group <ul> stays
empty, until real data lands) — but a resolved-and-genuinely-empty grid
has the same empty shape, so List.buildEmptyDom() appends a real
.empty-state node ("No matching items") to tell the two apart. Grid's
.empty-state sits inside a real <tr>, which already satisfies
:has(tbody tr) on its own; Tile's (List's plain-div default, no
[data-row-id]) needs the explicit :not(:has(.empty-state)) below to stop
the shimmer.

<a id="scoop-grid-batch"></a>
### `.scoop-grid.Batch`

Special Grid Types: Batch, plus its optionally-embedded BatchHistory
receipt (`history="true"` — see ScoopAPI._mountEmbeddedBatchHistory).
Both tables share --dimension-batch-grid-width so they line up; Batch's
own table is narrower by its Save button's protruding width so the
button's own outer edge lines up flush too (see button.save below).

<a id="scoop-grid-batch-width-fit-content-important"></a>
### `.scoop-grid.Batch { width: fit-content !important }`

!important to actually beat the general .scoop-grid rule's own
`width: calc(100% - 2rem) !important` above — fit-content (rather than
a fixed rem value) keeps this host exactly as wide as its toggle
button/form(s), never wider.

<a id="and-form-not-batch-history-embedded"></a>
### `& form:not(.batch-history-embedded)`

Batch's own single flavor+count row. position:absolute while closed
takes it out of flow entirely, so it contributes nothing to this root
element's own width:fit-content/auto-height sizing — transform/opacity
alone (below) only hide it visually, they don't stop a normal-flow box
from still being measured at full size. Switches back to position:
relative once .toggled so the root grows to actually fit it.

<a id="and-has-findit-has-value-before"></a>
### `&:has(.findIt.has-value):before`

Hides the "Add batch" hint once the flavor text field actually has a
value typed/selected into it — .has-value is toggled by FindIt itself
(see _syncHasValue() in assets/ui/find-it.js), not derivable from CSS
alone since a plain text input's value isn't a selector target.

<a id="and-form-batch-history-embedded"></a>
### `& form.batch-history-embedded`

Embedded BatchHistory receipt — visible only when Batch's own panel is
open (.toggled, from the main gridToggle button) AND its own
min/max state is open (.history-open, from button.history-min-max
below): closing Batch's whole popup always closes history with it,
while .history-open alone still lets it be minimized independently
whenever Batch itself is open.

## DOCKING — [scoop_dock] baseline (see DOCKING.md)

<a id="in-dock"></a>
### `.in-dock`

Row layout: .canvas | .dock-resizer | <aside>, each independently
scrollable (overflow-y:auto on .canvas/aside below) within one bounded,
non-scrolling viewport-height frame — overflow:hidden here is what
stops a second, redundant page-level scrollbar from also appearing.
.toolbar and .action-target (further below) are position:fixed overlays
floating on TOP of this row, unaffected by it — fixed positioning
escapes normal flow entirely, so adding display:flex here doesn't move
them.

<a id="in-dock-toolbar"></a>
### `.in-dock > .toolbar`

.toolbar and .action-target are peers (see [scoop_dock]'s markup in
includes/shortcode.php and List.dockToggle() in assets/ui/_list.js) —
deliberately NOT nested, so that neither one is an ancestor the other
needs a `transform` on for centering. Both position:fixed and centered
at the bottom of the screen; .action-target sits stacked directly above
the toolbar row via --dimension-dock-toolbar-clearance.

<a id="in-dock-toolbar-width-max-content"></a>
### `.in-dock > .toolbar { width: max-content }`

With width left to its default 'auto', a fixed-position box with only
`left` set (no `right`) shrink-to-fits against the space from that left
offset to the containing block's edge — i.e. only ~50% of the
viewport, wrapping content that exceeds THAT long before `transform`
below ever repositions it. width: max-content sizes the box from its
own content instead, sidestepping that halved available-width — the
classic gotcha with the left:50%/translateX(-50%) centering trick.

<a id="in-dock-action-target"></a>
### `.in-dock > .action-target`

Given a fixed, explicit width — rather than fit-content/auto — for two
reasons: (1) a docked control's own host (e.g. .scoop-grid.Batch) starts
empty/collapsed and only gains real width once its content actually
renders, so any width computed FROM that content is unreliable right at
the moment centering needs to happen — position:fixed doesn't force a
re-layout the way normal flow would; (2) .scoop-grid.Batch is itself
position:fixed in its base rule, which — being taken out of normal flow
— never contributes to an ancestor's fit-content/auto sizing at all
regardless of timing. An explicit width sidesteps both problems, and
also means centering via transform is safe here (no position:fixed
descendant left whose containing block a transform could hijack — see
.in-dock .scoop-grid.Batch below, which drops the base rule's own
position:fixed once docked, becoming a normal in-flow child of this box
instead of a second independently-positioned element).

Off-screen (translateY 150%, i.e. one full height of itself below its
own resting position) by default; :has(.scoop-grid.toggled) — true the
moment any docked control inside opens, no JS wiring needed, it's the
same .toggled class List's own TOGGLE click handler already applies —
slides it up into view, still centered throughout via translateX.

<a id="in-dock-action-target-width-var-dimension-batch-grid-width"></a>
### `.in-dock > .action-target { width: var(--dimension-batch-grid-width) }`

--dimension-batch-grid-width is itself capped to calc(100vw - 1rem)
(see :root further up) — capping it HERE too wouldn't be enough on its
own, since the actual content-bearing table inside .scoop-grid.Batch
reads that variable directly rather than a percentage of this box.

<a id="in-dock-action-target-transform-translatex-50-translatey-150"></a>
### `.in-dock > .action-target { transform: translateX(-50%) translateY(150%) }`

translateX(-50%) is the ONLY thing doing the centering (left:50% alone
just moves the box's LEFT EDGE to center) — it must stay byte-for-byte
identical between this rule and the :has() one below. transition
animates the whole `transform` value as one list; if the two states'
translateX ever differ (or one omits it), the box visibly slides
sideways during the animation as a side effect, even though only
translateY was meant to change.

<a id="in-dock-scoop-grid-batch"></a>
### `.in-dock .scoop-grid.Batch`

Docked Batch gives up its base rule's own position:fixed/top/margin —
.action-target above now owns the fixed positioning/centering/clearance
for the whole panel, so Batch just needs to be a normal in-flow block
filling it (width:100%), not a second, independently-positioned fixed
element. position:relative (not static) so its own form's
position:absolute children (see .scoop-grid.Batch further up) still
resolve against Batch's own box rather than escaping further up.

<a id="in-dock-scoop-grid-batch-form-not-batch-history-embedded"></a>
### `.in-dock .scoop-grid.Batch form:not(.batch-history-embedded)`

Batch's own standalone open/close animation (see .scoop-grid.Batch
further up) flies sideways — translateX(-100%) — which read fine when it
floated near the top-left nav rail, but reads wrong now that it's
docked and bottom-anchored above the toolbar: closing should drop DOWN
toward the toolbar it's docked to, not slide off sideways. Same
transform/transition timing, just translateY instead of translateX.

<a id="and-dockicon-and-docktitle"></a>
### `& .dockIcon, & .dockTitle`

Icon/title are decorative — pointer-events: none makes a click anywhere
over them resolve straight to the <button> itself (this.TOGGLE's own
click listener, see _list.js), instead of the browser treating the
<img>/<span> as its own hit target (an <img> in particular is otherwise
happy to eat the click for itself, e.g. for drag-start). Without this,
only the padding/bevel around the icon+label actually toggled.

<a id="and-dockicon-svg"></a>
### `& .dockIcon svg`

Inline SVG markup (pasted via the Streamline VS Code extension, see
_buildToggleButton() in assets/ui/_list.js) is injected as a real <svg>
child of .dockIcon, carrying whatever width/height/viewBox the source
file had — constrain it to match the unicode-glyph/icon-font sizing
above instead of rendering at its native size.

<a id="in-dock-canvas-overflow-x-auto"></a>
### `.in-dock > .canvas { overflow-x: auto }`

Half-stack/half-nostack controls now shrink below --dimension-canvas-
control-min rather than forcing this (see that var's own comment and
the half-stack/half-nostack rule further down), so this is really for
'full-stack'/'full-nostack' content that's intrinsically wide (e.g.
ItemPivot's own table, which sets its own overflow-x too) — lets it
scroll into view instead of being silently clipped by .in-dock's own
overflow:hidden (see "TUBS - Docked view" further down).

<a id="in-dock-canvas-gridfilterinput"></a>
### `.in-dock .canvas .gridFilterInput`

A sticky descendant's `top` offset is measured from .canvas's PADDING
edge, not its border edge — so a plain `top: 0` sticks a filter bar
--dimension-canvas-gutter below the actual visible top of .canvas,
leaving a gap that whatever's scrolling underneath shows through. Pulling
it up by that same amount (negative top) re-aligns it with .canvas's real
top edge instead. Scoped to .in-dock .canvas specifically — .gridFilterInput
elsewhere (a plain page, <aside>) has no padded scrolling ancestor between
it and the viewport, so the un-compensated top: 0 there is already
correct. Must collapse to 0 in lockstep with .canvas's own padding
(below) — see --dimension-canvas-gutter-collapse's own comment (:root,
further up).

<a id="in-dock-canvas-button-save"></a>
### `.in-dock .canvas button.save`

Same fix, opposite edge: form.zList-form's save button (see "FORM"
further down) sticks with `bottom: 0`, which is measured from .canvas's
PADDING-bottom edge — --dimension-canvas-gutter above the real visible
bottom of .canvas. Uncompensated, the button stops that far short of the
bottom instead of staying flush, which reads as "not sticking" once the
gap is wide enough to notice. Same scoping caveat as .gridFilterInput
above: only .in-dock .canvas has this padded scrolling ancestor.

<a id="in-dock-canvas-has-scoop-grid-toggled-data-canvas-mode-full-no"></a>
### `.in-dock > .canvas:has(> .scoop-grid.toggled[data-canvas-mode="full-nostack"])`

full-nostack fills the entire canvas by design (see the canvas-mode
comment further down, data-canvas-mode="full-nostack") — the outer
gutter the other three modes (half-stack, half-nostack, full-stack) keep
is just wasted space for it, so drop .canvas's own padding to 0 whenever
the currently-open control is full-nostack. Safe to key off :has() like
this because 'nostack' exclusivity (List._enforceCanvasExclusivity())
guarantees a full-nostack control is never open alongside anything else
in .canvas — it's always the only child when this matches. Higher
specificity than the base rules above (an attribute selector inside
:has() beats two bare classes) so source order doesn't matter, but kept
below them for readability. Must move in lockstep with the two
compensation rules below, same reasoning as the narrow-viewport collapse
above — both exist only to cancel out this same padding.

<a id="in-dock-dock-resizer"></a>
### `.in-dock > .dock-resizer`

Draggable divider between .canvas and <aside> — see
assets/ui/dock-resizer.js, which drags this to update --dock-aside-width
on .in-dock. Only meaningful (and only shown) once <aside> actually has
something open — an empty, collapsed aside has nothing to resize.

<a id="body-dock-resizing"></a>
### `body.dock-resizing`

While assets/ui/dock-resizer.js has an active drag: forces the resize
cursor everywhere (not just while hovering the thin handle itself, which
the pointer can easily outrun mid-drag) and blocks text selection, which
a fast drag would otherwise trigger across page content.

<a id="in-dock-aside"></a>
### `.in-dock > aside`

<aside> — the 'aside' dock slot (see List.DOCK_SLOT_SELECTORS in
_list.js), a peer of .canvas and .toolbar, not nested in either (same
reasoning as .action-target above: a slot that CONTAINS a docked,
position:fixed control needs no `transform` on it, and none of today's
aside-targeted types are position:fixed, so this isn't load-bearing here
the way it is for .action-target — kept as a peer anyway for the same
"one shared slot, looked up from `dock`" convention). Collapsed
(flex-basis:0, clipped) by default; :has(.scoop-grid.toggled) — same
.toggled class the TOGGLE click handler already applies, no JS wiring
needed — expands it to --dock-aside-width with its own independent
vertical scroll.

The width itself (flex-basis) is NOT what animates — it snaps open
instantly on toggle-on, and on toggle-off it holds its width for 0.2s
(transition-delay) before collapsing. What the user actually sees slide
is the docked .scoop-grid's own transform, below: opening the box first
(instantly) gives the slide-in transform full-width room to animate into;
closing keeps the box open just long enough for the slide-out transform
to finish before the now off-screen content's box collapses to 0.

<a id="in-dock-aside-active"></a>
### `.in-dock > aside.active`

.active mirrors .toggled from whatever's currently open inside (see the
TOGGLE click handler in assets/ui/_list.js) — scoped to it so these
bounds don't fight the collapsed (flex-basis:0) default above when
nothing's open. Must stay textually BEFORE the mobile @media override
below: both target the same property at equal specificity, so on a
narrow screen the LATER one in source order wins — this rule needs to
lose that tiebreak once the media query's condition is also true.

<a id="media-max-width-32rem"></a>
### `@media (max-width: 32rem)`

Below this, splitting the screen between .canvas and <aside> leaves too
little of either to be usable — <aside> takes over as a full-screen
panel instead of a side column. .canvas and .dock-resizer are hidden
outright rather than left to shrink: .canvas's own min-width:0 (further
up) would let it collapse to 0 width on its own, but .dock-resizer's
flex:0 0 auto (further up) doesn't shrink, so without this it would sit
as a stray sliver eating into aside's own 100% and overflowing the row
by its own width. Same :has(> aside .scoop-grid.toggled) condition the
resizer's own visibility rule (further up) already keys off.

<a id="in-dock-aside-scoop-grid"></a>
### `.in-dock aside > .scoop-grid`

Docked "aside" grid (currently just Cabinet/"Flavor Plan" — see
_config.php's `'target' => 'aside'`) gives up the general .scoop-grid
rule's own margin/width — <aside> above now owns the sizing/scrolling
for the whole panel, so the grid just needs to be a normal in-flow block
filling it, same pattern as .scoop-grid.Batch filling .action-target.
translateX(100%)/(0) — off to the right of its own (already full-width)
box by default, slid into place once .toggled — is the actual slide-in/
slide-out the <aside> box's own transition (above) is timed around.

<a id="in-dock-aside-scoop-grid-form"></a>
### `.in-dock aside > .scoop-grid > form`

The form's own open/close pop (.in-dock .scoop-grid > form further down —
translateY(100%) scale(0.9), a vertical "pop up" meant for canvas/
action-target controls) would compound with the horizontal slide above
into a diagonal motion. Neutralized here so aside's only visible motion
is the container's slide; opacity fade is left alone, it reads fine
alongside a slide.

<a id="in-dock-aside-2"></a>
### `.in-dock aside`

Compact density for whatever's docked in <aside> — a narrow sidebar has
much less room than a full-width .canvas panel. font-size:0.67rem sets
the baseline (inherited by anything that doesn't set its own), which
covers most of a grid's text on its own; the one place within a grid
that's KNOWN to render bigger is a group-header row's <th> (table.zList
tbody tr th, normally 0.75rem — see "TABLE - CELLS (td)" below — sized up
deliberately for a full-width grid, wrong in a narrow sidebar), capped
here explicitly. Not a blanket `* { font-size }` override — that would
also shrink icon/glyph-as-text content (e.g. the .oc collapse chevrons)
sized in em/rem against the inherited font-size, which isn't wanted.

<a id="in-dock-canvas-scoop-grid"></a>
### `.in-dock .canvas > .scoop-grid`

Per-type canvas placement strategy (see BaseGridModel's canvasMode
comment in assets/models/_base-grid-model.js and 'canvas_mode' in
scoop_routes_config()) — List's constructor stamps data-canvas-mode onto
the host from the model, so no per-type selector is needed here, just
one rule per mode name:
  half-stack (default) — roughly half .canvas's width, wraps via
    .canvas's flex-wrap so up to 2 sit side by side, and any number of
    rows can stack above/below.
  full-stack  — the whole row width, but other rows can still stack
    above/below it.
  half-nostack / full-nostack — 'nostack' expects the whole canvas
    HEIGHT, not just its own row: min-height:100% (of .canvas — "at
    least fill it, taller if content needs more") plus JS-enforced
    exclusivity (List._enforceCanvasExclusivity() in assets/ui/_list.js)
    closes every other open .canvas control the moment a nostack one
    opens, and vice versa — nothing is meant to render above/below it.
    The same JS method also closes every other open control, regardless
    of canvasMode, below a narrow-viewport breakpoint (see
    List.CANVAS_SINGLE_OPEN_BREAKPOINT) — .canvas only ever shows one
    open control at a time on a small enough phone.
Overrides the general .scoop-grid rule's own `width: calc(100% - 2rem)
!important` (nearly full-width regardless of content).

<a id="in-dock-canvas-scoop-grid-margin-0-important"></a>
### `.in-dock .canvas > .scoop-grid { margin: 0 !important }`

.canvas now owns all spacing itself (padding for the outer gutter, gap
between items — both further up) — without this, every canvas item
also carried the general .scoop-grid rule's own `margin: 1rem
!important` (see "LAYOUT - MAIN & FOOTER" further up, still in effect
since .in-dock still nests under `main`), stacking on top of .canvas's
gap/padding for uneven, doubled-up spacing.

<a id="in-dock-canvas-scoop-grid-data-canvas-mode-half-stack-in-do"></a>
### `.in-dock .canvas > .scoop-grid[data-canvas-mode="half-stack"], .in-dock .canvas > .scoop-grid[data-canvas-mode="half-nostack"]`

half-stack / half-nostack: flex-grow:1 is what lets a LONE control
stretch to fill its row (toward the 40rem ceiling below) instead of
sitting frozen at its --dimension-canvas-control-min basis; .canvas's own
flex-wrap (further up) is what drops a second/third control to a new row
once they can't all fit at that basis in the remaining width — no
per-breakpoint column-count rule needed, flexbox works it out from the
floor/ceiling alone. See --dimension-canvas-control-min's own comment (
:root, further up) for how 30rem/40rem were picked.

min-width: min(30rem, 100%) — NOT a bare var() — on purpose: a 'half'
control must never be wider than the screen actually available to it,
full stop, so the floor has to be able to yield once .canvas itself is
narrower than 30rem, rather than forcing this control past the edge and
into .canvas's own horizontal scroll (that scroll — see .canvas's
overflow-x further up — is reserved for 'full-stack'/'full-nostack'
content that's intrinsically wide, e.g. ItemPivot's own table, which
IS expected to scroll; a half control shrinking to fit is not the same
situation and shouldn't reach for the same fallback). 100% here
resolves against .canvas's own content box, same as any percentage on a
flex item's min-width.

!important is needed on max-width to beat the general .scoop-grid rule's
own `max-width: 40rem !important` (see "GRID CONTAINER" further up),
which otherwise wins over this regardless of specificity since this had
no !important before — same value today, but this rule needs to be able
to move independently of that one later.

<a id="in-dock-canvas-scoop-grid-data-canvas-mode-full-stack-in-do"></a>
### `.in-dock .canvas > .scoop-grid[data-canvas-mode="full-stack"], .in-dock .canvas > .scoop-grid[data-canvas-mode="full-nostack"]`

!important — needed to beat the general .scoop-grid rule's own
`max-width: 40rem !important` (see "GRID CONTAINER" further up), which
otherwise wins over this regardless of specificity since it had no
!important before. Without it, full-nostack was silently capped at
40rem instead of actually reaching 100% width.

<a id="in-dock-canvas-scoop-grid-2"></a>
### `.in-dock .canvas > .scoop-grid`

Docked controls left in .canvas (as opposed to a slot — .action-target
or <aside>, see List.dockToggle()) start fully collapsed: scale(0)
hides the WHOLE host, not just its <form> (below) — which also covers
the loading shimmer (a ::after on .scoop-grid itself, see "GRID
CONTAINER" further up), otherwise visible on an untoggled panel while
its bundle data is still loading even though nothing about it is
actually on screen yet. Scales up together with the same .toggled class
the TOGGLE click handler already applies (see the form rule below).

position:absolute while collapsed (same technique as .scoop-grid.Batch/
form.batch-history-embedded further up) takes it OUT of .canvas's flex
layout entirely — transform alone doesn't do that, a scaled-to-0 box
still gets measured at full size for flex-wrap purposes, which is what
let a closed FlavorTub (unbounded table height, no scroll of its own)
push a newly-opened full-nostack ItemPivot thousands of pixels down into
a wrapped row instead of alone at the top of an empty canvas. Back to
position:relative once .toggled so it actually participates in layout
again.

<a id="in-dock-scoop-grid-form"></a>
### `.in-dock .scoop-grid > form`

Open/close animation for a docked control's form — .toggled lands on the
.scoop-grid host itself when its toolbar button is clicked (see List's
TOGGLE click handler in assets/ui/_list.js). display:none can't be
transitioned (it's a hard jump either direction), so this stays rendered
at all times and animates opacity/transform instead — which is what makes
"close" automatically play as the reverse of "open": a CSS transition
always animates toward whatever the current target state is, so removing
.toggled naturally reverses the same transition, no separate close
animation needed. pointer-events is what actually keeps a collapsed form
from intercepting clicks/tab focus once it's invisible.

## TUBS - Docked view

<a id="html-body-main-entry-content-in-dock-display-flex"></a>
### `html body main .entry-content > .in-dock { display: flex }`

display:grid with no explicit columns used to stack EVERY child
(.canvas, .dock-resizer, <aside>, plus the position:fixed .toolbar/
.action-target overlays) as separate implicit grid rows — one per
child, top to bottom. That's what put <aside> at the bottom instead
of beside .canvas: the general .in-dock > .canvas/aside/.dock-resizer
rules further up rely on .in-dock being flex-row (see their comments
there), and this more-specific rule was overriding just `display`,
silently breaking that layout for this context. flex-row matches the
general rule now; .toolbar/.action-target are unaffected either way
since position:fixed already escapes normal flow regardless of the
parent's display type.

<a id="scoop-grid-toggled-not-aside-scoop-grid"></a>
### `.scoop-grid.toggled:not(aside > .scoop-grid)`

Excludes aside's docked grid — it gets its own slide-from-the-right
transition instead (see .in-dock aside > .scoop-grid further up).
animation always wins over a transition on the same property, so
leaving flyup unscoped here silently overrode that slide with this
bottom-up entrance instead.

## TABLE - ROW GROUPS

<a id="groupcell"></a>
### `.groupCell`

Shared between Grid's `<th class="groupCell">` (inside table.zList's
tr.group) and Tile's `<div class="groupCell">` (inside .group's header) —
one rule set for both instead of a parallel Tile-only copy, so a group
header looks and behaves the same regardless of which control renders it
(see tile.js/grid.js's own buildGroupDom). Not scoped to table.zList:
background/padding/border are given here directly rather than inherited
from the generic `table.zList th` rule, since Tile's div never gets that
for free.

<a id="collapsible-closed-groupcell"></a>
### `.collapsible.closed .groupCell`

Closed-state chevron swap + border cleanup — shared by both controls'
`.groupCell` (see above). The actual item-hiding declarations below it
still differ per control (`.row td`/`.row th` vs `.groupBody`'s own `> ul >
li` rule further down) since a table row and a flex/block card need
different techniques to collapse — but both are driven by the same
`.collapsible.opened`/`.collapsible.closed` classes on the same
`.groupBody` container (`tbody`/`section` — see tile.js/grid.js's
buildGroupDom).

<a id="and-row-td-and-row-th"></a>
### `& .row td, & .row th`

.row th: ItemPivot's row-label cell (item-pivot-grid.js renders it as a
<th>, not a <td>, for its sticky/header semantics — see css.css's
ItemPivot section) — without this, collapsing a group hid every td's
content but left that leading label fully visible. Its text is a bare
text node (no wrapper element), so it's collapsed directly here rather
than via the '& > *' trick below, which only td's square buttons need.

<a id="groupbody-collapsible-closed-ul-li"></a>
### `.groupBody.collapsible.closed > ul > li`

Tile's counterpart to the Grid `.row td`/`.row th` rule above — same
`.collapsible.closed` hook, different technique because the DOM shape
differs: a `<tr>` can't be height-collapsed directly in table layout (a
row's height is driven by its tallest cell, so Grid zeroes each cell's own
`line-height`/`max-height` instead — see above), but a Tile `<li>` is a
normal flex box and can just be collapsed itself directly — `height`,
`max-height`, `padding`, and `border` all zeroed on the `<li>` rather than
threaded through each cell. `pointer-events: none` stands in for Grid's
`& button, & input { display: none }` (Tile's cards don't reliably have
their own interactive children at a fixed nesting depth the way a `<td>`
does) — nothing inside a closed card should be clickable either way. The
`.collapsible.opened > ul > li { max-height: 20rem }` counterpart (just
above, alongside Grid's own opened-state rule) exists for the same reason
Grid's does: `max-height: 0` and `max-height: none`/`auto` can't be
transitioned between smoothly, so both states need a concrete number for
the `* { transition: all 0.3s ease 0.05s }` base rule to animate against.

## GRID FILTER INPUT

<a id="gridfilterinput-top-0"></a>
### `.gridFilterInput { top: 0 }`

Flush with the very top of the scrolling container — its own static
position (wherever that falls in normal flow) is untouched, this only
controls where it stops once scrolled up. .scoop-grid thead's own
top: 2rem (further up) is what then sticks it just below this bar
instead of underneath it.

## ROW STATE — loading / autosave / dirty-edit / emptied-opened

<a id="zgrid-form-autosave-not-autosave-partial-button-save"></a>
### `.zList-form.autosave:has(> table):not(.autosave-partial) > button.save`

── Autosave grids ──────────────────────────────────────────────────────────
Grids whose model sets `autosave = true` persist each change immediately and
render no save button. The button is also hidden via the `hidden` attribute
in JS; this rule is a backup. Per-cell flashes confirm a save (or an error).
A model may still opt some fields into autosave and leave the rest manual
(`autosaveFields` — no grid currently does this; autosave has turned out to
need to be all-or-nothing per grid, see flavor-tub-grid-model.js) — that
gets the extra `autosave-partial` class and keeps the button.

`:has(> table)` is new since the zGRID/zTILE → zList rename: this was
`.zGRID-form...`, implicitly Grid-only because only Grid's form carried
that class. Now that Grid and Tile share `zList-form`, the `:has(> table)`
guard preserves that same Grid-only scoping explicitly — no CabinetWorkflow
(Tile) grid currently has a visible save button to hide this way regardless
(it's hidden unconditionally, see "ANALYTICS" section), so this is a
same-behavior rename, not a scope change.

<a id="state-emptied"></a>
### `.state-Emptied`

── Emptied tub rows ──────────────────────────────────────────────────────
.state-Emptied comes from FlavorTubGridModel's rowClassFields (generic
List._rowClasses mechanism — see _list.js), not a bespoke flag. !important
so it always wins over .row-dirty/.row-dirty-clearing below: Emptied is
the more important signal at the row level, even while she's actively
correcting it. The one cell she's actually typing into still gets its own
orange via .cell-dirty (also !important, more local/urgent than either
row-level color).

<a id="data-row-id-row-dirty"></a>
### `[data-row-id].row-dirty`

── Unsaved edits ("dirty" state) ────────────────────────────────────────
Covers two states that read the same visually — changed-not-saved
(dirtySet) and changed-but-not-yet-confirmed-by-a-real-refresh
(awaitingRefreshSet) — see the constructor comment on awaitingRefreshSet
in _list.js for why the color deliberately does NOT clear the instant a
save POST confirms: the server can rewrite/revert what was sent (see
scoop_enforce_tub_rules), so only a genuine domain refetch actually
proves the value stuck. Tag-agnostic on purpose (Grid uses <tr>/<td>,
Tile uses <li>/<div>) so both views pick these up the same way.

The *-clearing variants aren't toggled by a timer — List._onDomainUpdated
stamps them onto the freshly-rebuilt elements that just got confirmed by
a real refresh (see _flashResolvedMarks/_flashClearing), so the fade only
ever plays once resolution is genuine. DIRTY_CLEAR_FADE_MS there must
match the transition durations here.

<a id="scoop-grid-data-grid-type-batchhistory-form-batch-history-embedd"></a>
### `.scoop-grid[data-grid-type="BatchHistory"], form.batch-history-embedded`

BatchHistory's "Created" column (key: post_date — see
BatchHistoryGridModel.buildCols) is the least essential of its four
columns when the GRID ITSELF is cramped (Flavor/Tubs/Author still tell
you what happened; "when" becomes a scroll-up-to-check detail) — a
viewport media query would be the wrong tool here, since this can be
narrow because <aside> ate the width, not because the screen is small.
container-type establishes each of BatchHistory's two host elements
(standalone and embedded — same two contexts as the container-name
below) as a size query subject for their own descendants; the query
below then reads THAT box's width, not the viewport's. Every column's
key is already stamped as a class on both its <th> and <td> (see
grid.js's buildMetaFieldDom/_list.js's _renderFieldValue — same
mechanism th.current_flavor/td.current_flavor further up relies on), so
hiding it is a pure display:none, no JS/layout change needed — fully
reactive both directions, exactly like a media query, just against this
element's own box instead of the viewport. Both hosts already size
themselves from CSS rather than their content (.scoop-grid's own width
rules further up; form.batch-history-embedded's `width: var(--dimension-
batch-grid-width) !important` further up), so inline-size containment
doesn't change how either renders.

28rem, not 32rem: --dimension-batch-grid-width (see :root further up)
caps out at a flat 30rem and never goes wider, on ANY screen — so a
32rem threshold could never be satisfied in the embedded context and the
column would look permanently hidden, never "coming back" no matter how
wide the window got. 28rem leaves that popup 2rem of room to actually
cross back over the threshold before it hits its own 30rem ceiling.

<a id="scoop-grid-data-grid-type-cabinet"></a>
### `.scoop-grid[data-grid-type="Cabinet"]`

Cabinet's "Next Flavor" column (key: next_flavor — see _specs.php's
slot columns) is the planning grid's own least-essential column when
its host is cramped, same reasoning/mechanism as BatchHistory's
Created above. Unlike BatchHistory, no width ceiling problem here:
Cabinet is the grid docked to <aside> ('target' => 'aside' in
_config.php), and .in-dock > aside.active's own range (16rem-32rem —
see "DOCKING" further up) straddles this 28rem threshold on both
sides, so dragging .dock-resizer wider/narrower genuinely crosses it
either way. Also covers Cabinet rendered as a plain, non-docked page
grid, where .scoop-grid's own width rules (further up) can just as
easily land under 28rem on a narrow phone.

<a id="scoop-grid-data-grid-type-flavortub"></a>
### `.scoop-grid[data-grid-type="FlavorTub"]`

FlavorTub sheds columns progressively as its host narrows, same
mechanism as Cabinet/BatchHistory above — least-essential first:
"Updated" (key: post_modified, label from _specs.php) goes first at
38rem, then "Amount" (key: amount) and "Editor" (key: editor_name,
label from _specs.php — see FlavorTubGridModel's setShowList) at 32rem.
FlavorTub has no fixed-width ceiling like BatchHistory's embedded
popup — as a canvas half-stack control it ranges from --dimension-
canvas-control-min (30rem) up to 40rem, or below 30rem via .canvas's
own gutter-collapse/scroll fallback (further up) — so both thresholds
are comfortably crossable in both directions.

## CABINET WORKFLOW - state indicators

<a id="and-confirming"></a>
### `&.confirming`

Optimistic-repaint marker (see CabinetWorkflowTile._paintOptimistic)
— a slot showing its assumed post-write state while the
confirmation refetch is still in flight. Crude/dev-visible on
purpose, same as needs-tub/impossible above.

<a id="ztile-mismatch-tiletools-after-ztile-conforms-tiletools"></a>
### `.zList.mismatch > .tileTools::after, .zList.conforms > .tileTools::after, .zList.all-paired > .tileTools::after`

Crude dev-only QA indicator — see CabinetWorkflowTile._applyConformanceStatus.
State class lives on .zList (component root, was .zTILE before the zGRID/
zTILE → zList rename — unambiguous either way since a Grid's table never
carries a .tileTools child); glyph renders on the
always-present .tileTools child so it's visible without digging into
the group/list markup. Remove once the feature's confirmed working.

## ITEM PIVOT - "where are the tubs" matrix

<a id="and-tr-group-data-group-label-non-dairy-groupcell"></a>
### `& tr.group[data-group-label="Non-Dairy"] .groupCell`

Row-group header (flavor rows are grouped Dairy / Non-Dairy — see
item-pivot-grid-model.js's getRowDefs) — matched by the group label
Grid.buildGroupDom already stamps onto the header <tr>, not a hardcoded
class, so this doesn't need updating if the label text ever changes.

## POPULAR - Scatter plot view

<a id="popularchart"></a>
### `.popularChart`

Fixed 1rem outer gutters and 0.5rem inner gaps around the axis-label/
tick-number columns+rows (each sized to its own content via `auto`) —
only the plot cell (the SVG, column 6/row 2) is `1fr` in BOTH dimensions,
so it's the one thing that actually grows or shrinks as the container
resizes; every label and every gap around it stays a constant physical
size regardless.

height is set here only as a fallback for the instant before JS runs —
popular-plot.js's _syncChartHeight() immediately overrides it with the
real, MEASURED available viewport space (window.innerHeight minus
wherever the chart's top edge actually landed on the page, re-measured
on resize). A CSS vh-formula can't know how far down the page this
control sits, so it was leaving the X axis scrolled off-screen on pages
with more chrome above the chart than expected — a real measurement
can't be wrong the way a guessed formula can.

<a id="popularchart-grid-template-columns-1rem-auto-0-5rem-auto-0-5rem-mi"></a>
### `.popularChart { grid-template-columns: 1rem auto 0.5rem auto 0.5rem minmax(0, 1fr) 1rem }`

Plot track is minmax(0, 1fr), not bare 1fr — a bare 1fr's implicit
minimum size is auto (its content's intrinsic size), not 0, so the
track refuses to shrink below the SVG's own intrinsic size. The SVG
has no explicit width/height attributes or viewBox (only CSS
width:100%/height:100% — see popular-plot.js), so its intrinsic
replaced-element size falls back to the browser default (300x150),
which without a minmax(0, ...) floor can be taller than the space
actually available — and popular-plot.js's _syncChartHeight() setting
.popularChart's own height in px doesn't help: the row still won't
shrink to fit it, so the SVG paints past the bottom of .popularChart
instead of resizing with it. minmax(0, 1fr) removes that implicit
floor so the track (and the SVG, at height:100% of it) actually
tracks the row's real allotted size — which in turn is what lets the
ResizeObserver in popular-plot.js's render() see the real size change
and redraw the plot content to match (see _drawPlotContent).

<a id="popularsvg-min-width-0"></a>
### `.popularSvg { min-width: 0 }`

Belt-and-suspenders alongside .popularChart's minmax(0, 1fr) tracks —
a grid ITEM also defaults to min-height/min-width:auto independent of
its track, so both need the explicit 0 floor for the SVG to actually
shrink instead of overflowing its cell.

<a id="popularplotarea"></a>
### `.popularPlotArea`

Same translucent-over-page-background tone as the key table's own cells
(table.zList's background) — scoped to the plot rect only (fills the
whole SVG now — margins are .popularChart's grid tracks, not part of
this SVG at all anymore), not .popularPlotShell's own more-transparent
background that the surrounding label cells still sit on.

<a id="populartick-popularaxislabel"></a>
### `.popularTick, .popularAxisLabel`

.popularTick/.popularAxisLabel are plain HTML now (spans/divs outside
the SVG — see .popularYTicks/.popularXTicks/.popularYLabel/.popularXLabel
above), not SVG <text>, so their own size is set by ordinary CSS
font-size (fixed, never scaled by the plot's viewBox) rather than
needing a CSS-length workaround the way circle radii do. color replaces
the old SVG `fill`.

<a id="popularpointhalo"></a>
### `.popularPointHalo`

Decorative halo (trend-magnitude size, dairy/stock-level hue — see
_stockFillColor/_strokeColor in popular-plot.js, set as SVG presentation
attributes rather than here, since a stylesheet fill/stroke rule would
win the cascade over those). Never the hover/click target — the center
dot below is.

<a id="popularpointhalo-dairy-is-active"></a>
### `.popularPointHalo.dairy.is-active`

Hover/active brightens the halo back to full saturation of its own hue
(the resting rgb(0,204,0)/rgb(204,204,0) from _strokeColor, darkened for
legibility) instead of the generic pale-yellow highlight below — three
classes beats that rule's two regardless of source order.

