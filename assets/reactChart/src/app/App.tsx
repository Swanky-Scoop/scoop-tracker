import { Fragment, useCallback, useEffect, useRef, useState } from "react";

// ═══════════════════════════════════════════════════════════════════════════════
// ENCODING TYPES
// Supports up to 6 numeric channels and 3 ordinal channels beyond x/y.
// ═══════════════════════════════════════════════════════════════════════════════

/** Any flat data record with an id field. */
type RawPoint = { id: string | number } & Record<string, number | string>;

/**
 * Binds one data field to a numeric visual channel.
 * domain: the min/max of this field in the dataset.
 */
interface NumericChannel {
  field: string;
  domain: [number, number];
}

/**
 * Binds one data field to a categorical visual channel.
 * domain: the ordered list of all possible string values.
 */
interface OrdinalChannel {
  field: string;
  domain: readonly string[];
}

/**
 * Full encoding map. x and y are required.
 *
 * Numeric channels (6 available beyond x/y):
 *   decoRadius  (z) — outer decoration radius, scales with display WIDTH only
 *   decoOpacity (a) — outer decoration fill+stroke opacity
 *   outerSides  (d) — polygon side count, interpolated to integer 3–12
 *   outerStroke (e) — outer stroke width in px
 *
 * Ordinal channels (3 available):
 *   centerShape (b) — center point shape
 *   outerShape  (c) — outer decoration shape type
 *   color       (f) — hue from the fixed palette
 */
interface Encoding {
  x: NumericChannel;
  y: NumericChannel;
  // --- numeric ---
  decoRadius?:  NumericChannel;
  decoOpacity?: NumericChannel;
  outerSides?:  NumericChannel;
  outerStroke?: NumericChannel;
  // --- ordinal ---
  centerShape?: OrdinalChannel;
  outerShape?:  OrdinalChannel;
  color?:       OrdinalChannel;
}

// ═══════════════════════════════════════════════════════════════════════════════
// VISUAL CONSTANTS
// ═══════════════════════════════════════════════════════════════════════════════

/** Pixel budget for fixed-size gutters — never scales with the display box. */
const G = { left: 64, right: 200, top: 32, bottom: 52 } as const;

/**
 * Decoration max radius = display-box WIDTH × this ratio.
 * Height changes to the display box have zero effect on decoration size.
 */
const DECO_MAX_RATIO = 0.07;

/** Center point radius in px — fixed, ~0.5em diameter at 14px base font. */
const CP_R = 3.5;

const GRID = { x: 5, y: 5 };

/** Fixed categorical color palette — never cycled past 5 entries. */
const COLOR_PALETTE = [
  "var(--color-chart-1)",
  "var(--color-chart-2)",
  "var(--color-chart-3)",
  "var(--color-chart-4)",
  "var(--color-chart-5)",
] as const;

/** Output ranges for each numeric channel. */
const VISUAL = {
  decoOpacity: [0.06, 0.55] as [number, number],
  outerSides:  [3, 12]      as [number, number],
  outerStroke: [0.75, 4]    as [number, number],
} as const;

const CENTER_SHAPES = ["circle", "square", "diamond", "triangle", "cross"] as const;
type CenterShape = (typeof CENTER_SHAPES)[number];

const OUTER_SHAPES = ["circle", "polygon"] as const;
type OuterShape = (typeof OUTER_SHAPES)[number];

// ═══════════════════════════════════════════════════════════════════════════════
// SCALE FUNCTIONS
// ═══════════════════════════════════════════════════════════════════════════════

/** Linear interpolation, clamped to [0,1] in t. */
function lscale(v: number, domain: [number, number], range: [number, number]): number {
  const t = Math.max(0, Math.min(1, (v - domain[0]) / (domain[1] - domain[0])));
  return range[0] + t * (range[1] - range[0]);
}

/** Maps a string value through an ordered domain to an options array. */
function oscale<T>(v: string, domain: readonly string[], opts: readonly T[]): T {
  const i = Math.max(0, domain.indexOf(v));
  return opts[i % opts.length];
}

// ═══════════════════════════════════════════════════════════════════════════════
// RESOLVED MARK — all visual properties for one data point
// ═══════════════════════════════════════════════════════════════════════════════

interface ResolvedMark {
  /** Plot-space coordinate [0, 100]. toSvgCoords converts to SVG px. */
  plotX: number;
  plotY: number;
  color: string;
  colorKey: string;
  /** Radius in px — derived from display WIDTH, never HEIGHT. */
  decoRadius: number;
  decoOpacity: number;
  outerShape: OuterShape;
  /** Integer 3–12 when outerShape is "polygon". */
  outerSides: number;
  /** Stroke width in px. */
  outerStroke: number;
  centerShape: CenterShape;
}

function resolvePoint(
  pt: RawPoint,
  enc: Encoding,
  /** Decoration max radius in px for the current display width. */
  decoMaxR: number,
): ResolvedMark {
  const plotX = lscale(pt[enc.x.field] as number, enc.x.domain, [0, 100]);
  const plotY = lscale(pt[enc.y.field] as number, enc.y.domain, [0, 100]);

  const colorKey = enc.color ? String(pt[enc.color.field]) : "__";
  const color = enc.color
    ? oscale(colorKey, enc.color.domain, COLOR_PALETTE)
    : COLOR_PALETTE[0];

  const decoRadius = enc.decoRadius
    ? lscale(pt[enc.decoRadius.field] as number, enc.decoRadius.domain, [0, decoMaxR])
    : decoMaxR * 0.5;

  const decoOpacity = enc.decoOpacity
    ? lscale(pt[enc.decoOpacity.field] as number, enc.decoOpacity.domain, VISUAL.decoOpacity)
    : 0.2;

  const outerShape: OuterShape = enc.outerShape
    ? oscale(String(pt[enc.outerShape.field]), enc.outerShape.domain, OUTER_SHAPES)
    : "circle";

  const outerSides = Math.round(
    enc.outerSides
      ? lscale(pt[enc.outerSides.field] as number, enc.outerSides.domain, VISUAL.outerSides)
      : 6,
  );

  const outerStroke = enc.outerStroke
    ? lscale(pt[enc.outerStroke.field] as number, enc.outerStroke.domain, VISUAL.outerStroke)
    : 1.25;

  const centerShape: CenterShape = enc.centerShape
    ? oscale(String(pt[enc.centerShape.field]), enc.centerShape.domain, CENTER_SHAPES)
    : "circle";

  return {
    plotX, plotY, color, colorKey,
    decoRadius, decoOpacity,
    outerShape, outerSides, outerStroke,
    centerShape,
  };
}

// ═══════════════════════════════════════════════════════════════════════════════
// COORDINATE HELPERS
// ═══════════════════════════════════════════════════════════════════════════════

function toSvg(plotX: number, plotY: number, plotW: number, plotH: number) {
  return {
    px: (plotX / 100) * plotW,
    py: plotH - (plotY / 100) * plotH,
  };
}

function fmt(v: number, domain: [number, number]): string {
  const range = domain[1] - domain[0];
  const decimals = range <= 2 ? 2 : range <= 20 ? 1 : 0;
  return v.toFixed(decimals);
}

function regularPolygonPts(cx: number, cy: number, r: number, n: number): string {
  return Array.from({ length: n }, (_, i) => {
    const a = (i / n) * Math.PI * 2 - Math.PI / 2;
    return `${cx + r * Math.cos(a)},${cy + r * Math.sin(a)}`;
  }).join(" ");
}

// ═══════════════════════════════════════════════════════════════════════════════
// SHAPE RENDERERS
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Center point — the authoritative x,y position marker.
 * Fixed radius (CP_R). This is the ONLY hover/click target.
 */
function CenterMark({
  shape, cx, cy, r, color, opacity = 1,
}: {
  shape: CenterShape; cx: number; cy: number; r: number;
  color: string; opacity?: number;
}) {
  const s: React.CSSProperties = {
    fill: color,
    stroke: "var(--color-background)",
    strokeWidth: 1.5,
    opacity,
  };
  switch (shape) {
    case "circle":
      return <circle cx={cx} cy={cy} r={r} style={s} />;
    case "square": {
      const h = r * 1.45;
      return <rect x={cx - h} y={cy - h} width={h * 2} height={h * 2} style={s} />;
    }
    case "diamond": {
      const rx = r * 1.25, ry = r * 1.65;
      return (
        <polygon
          points={`${cx},${cy - ry} ${cx + rx},${cy} ${cx},${cy + ry} ${cx - rx},${cy}`}
          style={s}
        />
      );
    }
    case "triangle": {
      const h = r * 1.75, w = r * 1.55;
      return (
        <polygon
          points={`${cx},${cy - h} ${cx + w},${cy + h * 0.65} ${cx - w},${cy + h * 0.65}`}
          style={s}
        />
      );
    }
    case "cross": {
      const t = r * 0.42, b = r * 1.35;
      return (
        <polygon
          points={[
            [cx - t, cy - b], [cx + t, cy - b],
            [cx + t, cy - t], [cx + b, cy - t],
            [cx + b, cy + t], [cx + t, cy + t],
            [cx + t, cy + b], [cx - t, cy + b],
            [cx - t, cy + t], [cx - b, cy + t],
            [cx - b, cy - t], [cx - t, cy - t],
          ].map(([x, y]) => `${x},${y}`).join(" ")}
          style={s}
        />
      );
    }
  }
}

/**
 * Decoration mark — visual only, no pointer events.
 * For "circle": a plain circle.
 * For "polygon": a regular polygon with `sides` sides.
 * Radius is derived from display-box WIDTH only — never affected by HEIGHT changes.
 */
function DecoMark({
  outerShape, sides, cx, cy, r, color, opacity, strokeWidth,
}: {
  outerShape: OuterShape; sides: number;
  cx: number; cy: number; r: number;
  color: string; opacity: number; strokeWidth: number;
}) {
  const s: React.CSSProperties = {
    fill: color,
    fillOpacity: opacity,
    stroke: color,
    strokeWidth,
    strokeOpacity: Math.min(1, opacity * 2.4),
  };
  if (outerShape === "circle") {
    return <circle cx={cx} cy={cy} r={r} style={s} />;
  }
  const n = Math.round(Math.max(3, Math.min(12, sides)));
  return <polygon points={regularPolygonPts(cx, cy, r, n)} style={s} />;
}

// ═══════════════════════════════════════════════════════════════════════════════
// SCATTER PLOT COMPONENT
// ═══════════════════════════════════════════════════════════════════════════════

interface HoverState {
  svgX: number; svgY: number;
  pt: RawPoint; mark: ResolvedMark;
}

interface ScatterPlotProps {
  data: RawPoint[];
  encoding: Encoding;
  xLabel?: string;
  yLabel?: string;
}

function ScatterPlot({ data, encoding, xLabel = "X", yLabel = "Y" }: ScatterPlotProps) {
  const boxRef = useRef<HTMLDivElement>(null);
  const [boxSize, setBoxSize] = useState({ w: 0, h: 0 });
  const [hover, setHover] = useState<HoverState | null>(null);
  const [activeKey, setActiveKey] = useState<string | null>(null);

  useEffect(() => {
    const el = boxRef.current;
    if (!el) return;
    const obs = new ResizeObserver(([entry]) => {
      const { width, height } = entry.contentRect;
      setBoxSize({ w: width, h: height });
    });
    obs.observe(el);
    return () => obs.disconnect();
  }, []);

  // SVG canvas fills the ref div minus padding
  const svgW = Math.max(0, boxSize.w - 32);
  const svgH = Math.max(0, boxSize.h - 16);
  // Plot area = SVG canvas minus fixed gutters
  const plotW = Math.max(0, svgW - G.left - G.right);
  const plotH = Math.max(0, svgH - G.top - G.bottom);
  // Decoration radius budget — derived from display WIDTH only
  const decoMaxR = boxSize.w * DECO_MAX_RATIO;

  // Resolve all marks each render
  const resolved = data.map((pt) => ({ pt, mark: resolvePoint(pt, encoding, decoMaxR) }));

  const xTicks = Array.from({ length: GRID.x + 1 }, (_, i) => (i / GRID.x) * 100);
  const yTicks = Array.from({ length: GRID.y + 1 }, (_, i) => (i / GRID.y) * 100);

  const handleEnter = useCallback(
    (pt: RawPoint, mark: ResolvedMark) => {
      const { px, py } = toSvg(mark.plotX, mark.plotY, plotW, plotH);
      setHover({ svgX: G.left + px, svgY: G.top + py, pt, mark });
    },
    [plotW, plotH],
  );

  const hasSize = svgW > 10 && svgH > 10;

  // Flip tooltip to left when point is in the right 45% of the SVG
  const ttLeft = hover
    ? hover.svgX > svgW * 0.55
      ? hover.svgX - 172
      : hover.svgX + CP_R + 12
    : 0;
  const ttTop = hover ? Math.max(4, hover.svgY - 44) : 0;

  // Build color legend entries from the ordinal domain
  const colorEntries = encoding.color
    ? encoding.color.domain.map((key, i) => ({
        key,
        color: COLOR_PALETTE[i % COLOR_PALETTE.length] as string,
      }))
    : [];

  // Legend layout: color section height + gap + size section
  const colorSectionH = colorEntries.length > 0 ? colorEntries.length * 24 + 20 : 0;

  return (
    <div ref={boxRef} className="relative w-full h-full min-h-0 min-w-0">
      {hasSize && (
        <svg
          width={svgW}
          height={svgH}
          className="absolute"
          style={{ left: 16, top: 0 }}
          onMouseLeave={() => setHover(null)}
        >
          {/* ── Plot area background ── */}
          <rect
            x={G.left} y={G.top} width={plotW} height={plotH}
            style={{ fill: "var(--color-muted)", opacity: 0.2 }}
          />

          {/* ── Grid lines ── */}
          {xTicks.map((v) => {
            const { px } = toSvg(v, 0, plotW, plotH);
            return (
              <line key={`gx-${v}`}
                x1={G.left + px} y1={G.top} x2={G.left + px} y2={G.top + plotH}
                style={{ stroke: "var(--color-border)", strokeWidth: 1 }}
              />
            );
          })}
          {yTicks.map((v) => {
            const { py } = toSvg(0, v, plotW, plotH);
            return (
              <line key={`gy-${v}`}
                x1={G.left} y1={G.top + py} x2={G.left + plotW} y2={G.top + py}
                style={{ stroke: "var(--color-border)", strokeWidth: 1 }}
              />
            );
          })}

          {/* ── Plot frame ── */}
          <rect
            x={G.left} y={G.top} width={plotW} height={plotH}
            fill="none"
            style={{ stroke: "var(--color-border)", strokeWidth: 1.5 }}
          />

          {/* ── X axis: ticks + data-domain labels ── */}
          {xTicks.map((v) => {
            const { px } = toSvg(v, 0, plotW, plotH);
            const label = fmt(lscale(v, [0, 100], encoding.x.domain), encoding.x.domain);
            return (
              <g key={`xt-${v}`}>
                <line
                  x1={G.left + px} y1={G.top + plotH}
                  x2={G.left + px} y2={G.top + plotH + 6}
                  style={{ stroke: "var(--color-muted-foreground)", strokeWidth: 1 }}
                />
                <text
                  x={G.left + px} y={G.top + plotH + 20}
                  textAnchor="middle"
                  style={{ fill: "var(--color-muted-foreground)", fontSize: 11, fontFamily: "'JetBrains Mono', monospace" }}
                >{label}</text>
              </g>
            );
          })}
          <text
            x={G.left + plotW / 2} y={G.top + plotH + 43}
            textAnchor="middle"
            style={{ fill: "var(--color-foreground)", fontSize: 11, fontWeight: 500, letterSpacing: "0.06em", fontFamily: "'Inter', system-ui, sans-serif" }}
          >{xLabel.toUpperCase()}</text>

          {/* ── Y axis: ticks + data-domain labels ── */}
          {yTicks.map((v) => {
            const { py } = toSvg(0, v, plotW, plotH);
            const label = fmt(lscale(v, [0, 100], encoding.y.domain), encoding.y.domain);
            return (
              <g key={`yt-${v}`}>
                <line
                  x1={G.left - 6} y1={G.top + py} x2={G.left} y2={G.top + py}
                  style={{ stroke: "var(--color-muted-foreground)", strokeWidth: 1 }}
                />
                <text
                  x={G.left - 10} y={G.top + py + 4}
                  textAnchor="end"
                  style={{ fill: "var(--color-muted-foreground)", fontSize: 11, fontFamily: "'JetBrains Mono', monospace" }}
                >{label}</text>
              </g>
            );
          })}
          <text
            x={12} y={G.top + plotH / 2}
            textAnchor="middle"
            transform={`rotate(-90, 12, ${G.top + plotH / 2})`}
            style={{ fill: "var(--color-foreground)", fontSize: 11, fontWeight: 500, letterSpacing: "0.06em", fontFamily: "'Inter', system-ui, sans-serif" }}
          >{yLabel.toUpperCase()}</text>

          {/* ══════════════════════════════════════════════════════════════
              LAYER 1 — Decoration shapes
              pointerEvents="none" on the entire group ensures the decorations
              are never hover targets. Only center points receive events.
              Radius derived from display WIDTH only; vertical resizing is ignored.
              ══════════════════════════════════════════════════════════════ */}
          <g pointerEvents="none">
            {resolved.map(({ pt, mark }) => {
              const { px, py } = toSvg(mark.plotX, mark.plotY, plotW, plotH);
              const active = activeKey === null || activeKey === mark.colorKey;
              return (
                <DecoMark
                  key={`deco-${pt.id}`}
                  outerShape={mark.outerShape}
                  sides={mark.outerSides}
                  cx={G.left + px} cy={G.top + py}
                  r={mark.decoRadius}
                  color={mark.color}
                  opacity={active ? mark.decoOpacity : mark.decoOpacity * 0.1}
                  strokeWidth={mark.outerStroke}
                />
              );
            })}
          </g>

          {/* ══════════════════════════════════════════════════════════════
              LAYER 2 — Center points
              Fixed radius CP_R. These are the ONLY interactive elements.
              A 10px invisible hit-area ring ensures easy targeting even
              though the visual mark is small (~0.5em diameter).
              ══════════════════════════════════════════════════════════════ */}
          {resolved.map(({ pt, mark }) => {
            const { px, py } = toSvg(mark.plotX, mark.plotY, plotW, plotH);
            const active = activeKey === null || activeKey === mark.colorKey;
            const hovered = hover?.pt === pt;
            return (
              <g
                key={`cp-${pt.id}`}
                onMouseEnter={() => handleEnter(pt, mark)}
                style={{ cursor: "crosshair" }}
              >
                {/* Invisible hit-area — larger than the visual mark */}
                <circle cx={G.left + px} cy={G.top + py} r={10} fill="transparent" />
                {/* Glow ring on hover */}
                {hovered && (
                  <circle
                    cx={G.left + px} cy={G.top + py} r={CP_R + 5}
                    pointerEvents="none"
                    style={{ fill: mark.color, opacity: 0.2 }}
                  />
                )}
                <CenterMark
                  shape={mark.centerShape}
                  cx={G.left + px} cy={G.top + py}
                  r={CP_R}
                  color={mark.color}
                  opacity={active ? 1 : 0.18}
                />
              </g>
            );
          })}

          {/* ── Crosshair ── */}
          {hover && (
            <g pointerEvents="none">
              <line
                x1={hover.svgX} y1={G.top} x2={hover.svgX} y2={G.top + plotH}
                style={{ stroke: "var(--color-muted-foreground)", strokeWidth: 1, strokeDasharray: "4 3" }}
              />
              <line
                x1={G.left} y1={hover.svgY} x2={G.left + plotW} y2={hover.svgY}
                style={{ stroke: "var(--color-muted-foreground)", strokeWidth: 1, strokeDasharray: "4 3" }}
              />
            </g>
          )}

          {/* ── Right gutter legend ── */}
          <g transform={`translate(${G.left + plotW + 22}, ${G.top + 4})`}>
            {/* Color / category legend */}
            {colorEntries.length > 0 && (
              <g>
                <text style={{ fill: "var(--color-muted-foreground)", fontSize: 9, fontWeight: 600, letterSpacing: "0.1em", fontFamily: "'Inter', system-ui, sans-serif" }}>
                  {encoding.color!.field.toUpperCase()}
                </text>
                {colorEntries.map((entry, i) => (
                  <g
                    key={entry.key}
                    transform={`translate(0, ${i * 24 + 16})`}
                    style={{ cursor: "pointer" }}
                    onClick={() => setActiveKey(activeKey === entry.key ? null : entry.key)}
                  >
                    <circle
                      cx={6} cy={5} r={5}
                      style={{
                        fill: entry.color,
                        stroke: "var(--color-background)",
                        strokeWidth: 1.5,
                        opacity: activeKey !== null && activeKey !== entry.key ? 0.25 : 1,
                      }}
                    />
                    <text
                      x={18} y={9}
                      style={{
                        fill: activeKey !== null && activeKey !== entry.key
                          ? "var(--color-muted-foreground)"
                          : "var(--color-foreground)",
                        fontSize: 12,
                        fontWeight: activeKey === entry.key ? 600 : 400,
                        fontFamily: "'Inter', system-ui, sans-serif",
                      }}
                    >{entry.key}</text>
                  </g>
                ))}
              </g>
            )}

            {/* Decoration radius scale */}
            {encoding.decoRadius && (() => {
              const capR = Math.min(decoMaxR, 26);
              const refs: [number, string][] = [
                [0.25, fmt(lscale(0.25, [0, 1], encoding.decoRadius.domain), encoding.decoRadius.domain)],
                [0.60, fmt(lscale(0.60, [0, 1], encoding.decoRadius.domain), encoding.decoRadius.domain)],
                [1.00, fmt(lscale(1.00, [0, 1], encoding.decoRadius.domain), encoding.decoRadius.domain)],
              ];
              let cy = colorSectionH + 16;
              return (
                <g transform={`translate(0, ${colorSectionH > 0 ? colorSectionH + 8 : 0})`}>
                  <text style={{ fill: "var(--color-muted-foreground)", fontSize: 9, fontWeight: 600, letterSpacing: "0.1em", fontFamily: "'Inter', system-ui, sans-serif" }}>
                    {encoding.decoRadius.field.toUpperCase()} (RADIUS)
                  </text>
                  {refs.map(([t, label]) => {
                    const r = t * capR;
                    const thisCy = cy + r;
                    cy += r * 2 + 8;
                    return (
                      <g key={t}>
                        <circle
                          cx={capR + 4} cy={thisCy} r={r}
                          style={{ fill: "none", stroke: "var(--color-muted-foreground)", strokeWidth: 1, strokeDasharray: "2 2" }}
                        />
                        <circle cx={capR + 4} cy={thisCy} r={CP_R}
                          style={{ fill: "var(--color-foreground)" }} />
                        <text
                          x={capR * 2 + 14} y={thisCy + 4}
                          style={{ fill: "var(--color-muted-foreground)", fontSize: 10, fontFamily: "'JetBrains Mono', monospace" }}
                        >{label}</text>
                      </g>
                    );
                  })}
                </g>
              );
            })()}
          </g>
        </svg>
      )}

      {/* ── HTML tooltip — outside SVG for crisp text ── */}
      {hover && (
        <div
          className="absolute pointer-events-none z-20 rounded-lg border border-border bg-card shadow-lg px-3 py-2.5 text-xs min-w-[140px]"
          style={{ left: ttLeft + 16, top: ttTop }}
        >
          {/* Colored header */}
          <div className="flex items-center gap-2 mb-2">
            <div
              className="w-2 h-2 rounded-full shrink-0"
              style={{ background: hover.mark.color }}
            />
            <span className="font-semibold text-foreground text-[11px]">
              #{hover.pt.id}
            </span>
          </div>
          {/* All raw data dimensions */}
          <div
            className="grid gap-y-0.5 text-[11px]"
            style={{
              gridTemplateColumns: "auto 1fr",
              columnGap: 10,
              fontFamily: "'JetBrains Mono', monospace",
            }}
          >
            {Object.entries(hover.pt)
              .filter(([k]) => k !== "id")
              .map(([k, v]) => (
                <Fragment key={k}>
                  <span className="text-muted-foreground">{k}</span>
                  <span className="text-foreground">
                    {typeof v === "number" ? v.toFixed(2) : v}
                  </span>
                </Fragment>
              ))}
          </div>
        </div>
      )}
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════════════════════
// DEMO — all 6 numeric + 3 ordinal channels active simultaneously
// ═══════════════════════════════════════════════════════════════════════════════

function mkRng(seed: number) {
  let s = seed >>> 0;
  return () => {
    s = Math.imul(s ^ (s >>> 15), s | 1);
    s ^= s + Math.imul(s ^ (s >>> 7), s | 61);
    return ((s ^ (s >>> 14)) >>> 0) / 2 ** 32;
  };
}

const rng = mkRng(0xc0ffee42);

const CENTER_SHAPE_VALS  = ["circle", "square", "diamond", "triangle", "cross"] as const;
const OUTER_SHAPE_VALS   = ["circle", "polygon"] as const;
const COLOR_VALS         = ["alpha", "beta", "gamma"] as const;

const DEMO_DATA: RawPoint[] = Array.from({ length: 42 }, (_, id) => ({
  id,
  x:  rng() * 95 + 2.5,           // numeric — x position
  y:  rng() * 95 + 2.5,           // numeric — y position
  z:  rng(),                       // numeric — decoration radius
  a:  rng(),                       // numeric — decoration opacity
  d:  rng(),                       // numeric — outer polygon sides
  e:  rng(),                       // numeric — outer stroke weight
  b:  CENTER_SHAPE_VALS[Math.floor(rng() * CENTER_SHAPE_VALS.length)],  // ordinal — center shape
  c:  OUTER_SHAPE_VALS[Math.floor(rng() * OUTER_SHAPE_VALS.length)],    // ordinal — outer shape
  f:  COLOR_VALS[Math.floor(rng() * COLOR_VALS.length)],                // ordinal — color hue
}));

const DEMO_ENCODING: Encoding = {
  x:           { field: "x", domain: [0, 100] },
  y:           { field: "y", domain: [0, 100] },
  decoRadius:  { field: "z", domain: [0, 1] },
  decoOpacity: { field: "a", domain: [0, 1] },
  outerSides:  { field: "d", domain: [0, 1] },
  outerStroke: { field: "e", domain: [0, 1] },
  centerShape: { field: "b", domain: CENTER_SHAPE_VALS },
  outerShape:  { field: "c", domain: OUTER_SHAPE_VALS },
  color:       { field: "f", domain: COLOR_VALS },
};

export default function App() {
  return (
    <div
      className="size-full flex flex-col bg-background"
      style={{ fontFamily: "'Inter', system-ui, sans-serif" }}
    >
      <div className="px-6 pt-5 pb-3 shrink-0">
        <h2 className="text-base font-semibold text-foreground leading-tight tracking-tight">
          Multi-dimensional Scatter
        </h2>
        <p className="text-xs text-muted-foreground mt-0.5">
          6 numeric · 3 ordinal · center point is the x,y target · decoration scales with width only
        </p>
      </div>
      <div className="flex-1 min-h-0 min-w-0 px-4 pb-4">
        <ScatterPlot
          data={DEMO_DATA}
          encoding={DEMO_ENCODING}
          xLabel={DEMO_ENCODING.x.field}
          yLabel={DEMO_ENCODING.y.field}
        />
      </div>
    </div>
  );
}
