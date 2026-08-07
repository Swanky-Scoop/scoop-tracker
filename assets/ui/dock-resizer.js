///////////////////////////////////
// Drag-to-resize for .in-dock's .canvas / <aside> split — see the DOCKING
// section in assets/css.css and List.DOCK_SLOT_SELECTORS in
// assets/ui/_list.js. One .dock-resizer per [scoop_dock] instance (see
// includes/shortcode.php's markup), each a plain drag handle sitting
// between .canvas and <aside>; dragging it updates --dock-aside-width on
// its own .in-dock ancestor, which is what CSS actually reads for
// <aside>'s width (see `.in-dock > aside:has(.scoop-grid.toggled)`).
//////////////////////////////////

// Narrower than this and a docked grid's own content stops being usable.
const MIN_ASIDE_PX = 160;

// Never let <aside> swallow more than 3/4 of .in-dock — .canvas needs SOME
// room left over regardless of how far the handle gets dragged.
const MAX_ASIDE_FRACTION = 0.75;

export function initDockResizers(root = document) {
  root.querySelectorAll('.in-dock > .dock-resizer').forEach(bindResizer);
}

function bindResizer(resizer) {
  const dock = resizer.closest('.in-dock');
  if (!dock) return;

  let dragging = false;

  // <aside> sits to the right of .canvas (see shortcode.php's markup
  // order) — its width is the distance from the pointer to the dock's own
  // right edge, clamped to a sane range regardless of how far dragged.
  const onPointerMove = (e) => {
    if (!dragging) return;

    const rect = dock.getBoundingClientRect();
    const rawWidth = rect.right - e.clientX;
    const clamped = Math.min(Math.max(rawWidth, MIN_ASIDE_PX), rect.width * MAX_ASIDE_FRACTION);

    dock.style.setProperty('--dock-aside-width', `${clamped}px`);
  };

  const stopDragging = () => {
    if (!dragging) return;
    dragging = false;
    document.body.classList.remove('dock-resizing');
    document.removeEventListener('pointermove', onPointerMove);
    document.removeEventListener('pointerup', stopDragging);
  };

  resizer.addEventListener('pointerdown', (e) => {
    dragging = true;
    document.body.classList.add('dock-resizing');
    document.addEventListener('pointermove', onPointerMove);
    document.addEventListener('pointerup', stopDragging);
    e.preventDefault();
  });
}
