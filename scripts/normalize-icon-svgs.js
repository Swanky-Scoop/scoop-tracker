// Preprocessing step for `npm run build:icons` (see package.json).
//
// icon-font-generator turns each SVG's <path> `d` geometry straight into a
// filled font glyph — it has no concept of SVG paint at all (fill/stroke/
// fill-rule="none"/opacity/etc are all ignored, every path's raw geometry
// just becomes part of the glyph). That breaks two common Streamline export
// shapes:
//   - Line-art/outline style (fill="none", stroke="...", stroke-width="..."):
//     the stroke centerline gets filled solid instead of the visual outline.
//   - "Fill" style icons that include an invisible background/hitbox path
//     (typically a full-canvas square wrapped in <g fill="none">, sometimes
//     with a decorative corner-fold sliver) alongside the real glyph path —
//     the invisible path isn't actually invisible to the font generator, so
//     it renders as a solid filled background behind the real icon.
//
// This script reads every SVG in assets/icon-font/svg/, resolves each
// <path>'s effective fill/stroke (own attrs, falling back to a wrapping <g>,
// then the root <svg>, then the SVG-spec default of fill:black/stroke:none),
// and per path:
//   - fill:none + stroke:<color>  -> outline the stroke into real fill
//     geometry via svg-path-outline (same idea as Illustrator/Inkscape's
//     "Outline Stroke"), so it survives as visible line art.
//   - fill:none + stroke:none     -> genuinely invisible (background/hitbox
//     placeholder) — dropped entirely, never reaches the font.
//   - anything else               -> already real fill geometry, kept as-is.
// Output goes to assets/icon-font/.normalized/ — an ignored build folder,
// regenerated every run, never committed. icon-font-generator is pointed at
// .normalized/, not svg/ directly, so svg/ stays exactly what Streamline
// exported, whichever style was picked.

const fs = require('fs');
const path = require('path');
const spo = require('svg-path-outline');

const SRC_DIR = path.join(__dirname, '..', 'assets', 'icon-font', 'svg');
const OUT_DIR = path.join(__dirname, '..', 'assets', 'icon-font', '.normalized');

const PATH_TAG_RE = /<path\b[^>]*\/?>/gi;
const ATTR_RE = /([a-zA-Z-]+)\s*=\s*"([^"]*)"/g;
const SVG_OPEN_RE = /<svg\b[^>]*>/i;
const GROUP_OPEN_RE = /<g\b[^>]*>/i;

function parseAttrs(tag) {
  const attrs = {};
  let m;
  ATTR_RE.lastIndex = 0;
  while ((m = ATTR_RE.exec(tag))) attrs[m[1]] = m[2];
  return attrs;
}

// Only handles this project's flat svg > (g)? > path*  export shape (that's
// all Streamline has produced so far) — not general SVG inheritance.
function normalizeOne(svgSource) {
  const svgOpenMatch = svgSource.match(SVG_OPEN_RE);
  if (!svgOpenMatch) throw new Error('No <svg> root found');
  const svgAttrs = parseAttrs(svgOpenMatch[0]);

  const groupOpenMatch = svgSource.match(GROUP_OPEN_RE);
  const groupAttrs = groupOpenMatch ? parseAttrs(groupOpenMatch[0]) : {};

  const pathTags = svgSource.match(PATH_TAG_RE) ?? [];
  if (!pathTags.length) return { svg: svgSource, dropped: 0, outlined: 0 };

  const parts = [];
  let dropped = 0;
  let outlined = 0;

  for (const tag of pathTags) {
    const a = parseAttrs(tag);
    if (!a.d) continue;

    const fill = a.fill ?? groupAttrs.fill ?? svgAttrs.fill ?? '#000000';
    const stroke = a.stroke ?? groupAttrs.stroke ?? svgAttrs.stroke ?? 'none';

    if (fill === 'none' && stroke === 'none') {
      dropped++;
      continue;
    }

    if (fill === 'none' && stroke !== 'none') {
      const width = parseFloat(a['stroke-width'] ?? groupAttrs['stroke-width'] ?? svgAttrs['stroke-width'] ?? '1');
      const distance = (Number.isFinite(width) ? width : 1) / 2;
      const linejoin = a['stroke-linejoin'] ?? groupAttrs['stroke-linejoin'];
      const joints = linejoin === 'round' ? 0 : linejoin === 'bevel' ? 2 : 1;
      parts.push(spo(a.d, distance, { joints, inside: true, outside: true }));
      outlined++;
      continue;
    }

    parts.push(a.d); // already real fill geometry
  }

  const combinedD = parts.filter(Boolean).join(' ');
  const viewBox = svgAttrs.viewBox ?? `0 0 ${svgAttrs.width ?? 24} ${svgAttrs.height ?? 24}`;

  const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="${viewBox}" width="${svgAttrs.width ?? 24}" height="${svgAttrs.height ?? 24}">` +
    `<path d="${combinedD}" fill="#000000" fill-rule="evenodd"></path></svg>`;

  return { svg, dropped, outlined };
}

function main() {
  if (!fs.existsSync(SRC_DIR)) throw new Error(`Missing ${SRC_DIR}`);
  fs.mkdirSync(OUT_DIR, { recursive: true });

  const files = fs.readdirSync(SRC_DIR).filter(f => f.toLowerCase().endsWith('.svg'));
  let droppedTotal = 0;
  let outlinedTotal = 0;

  for (const file of files) {
    const src = fs.readFileSync(path.join(SRC_DIR, file), 'utf8');
    const { svg, dropped, outlined } = normalizeOne(src);
    droppedTotal += dropped;
    outlinedTotal += outlined;
    fs.writeFileSync(path.join(OUT_DIR, file), svg, 'utf8');
  }

  console.log(`normalize-icon-svgs: ${files.length} source SVGs, ${outlinedTotal} path(s) stroke-outlined, ${droppedTotal} invisible path(s) dropped -> ${path.relative(process.cwd(), OUT_DIR)}`);
}

main();
