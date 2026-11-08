import { useState } from "react";
import { useWidth } from "./useWidth";

const AXIS = "#7b8a7b";

function niceMax(v) {
  if (v <= 0) return 10;
  const pow = 10 ** Math.floor(Math.log10(v));
  const n = v / pow;
  const step = n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10;
  return step * pow;
}

export function Legend({ items, className = "" }) {
  return (
    <div className={`flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-brand-700 ${className}`}>
      {items.map((i) => (
        <span key={i.label} className="inline-flex items-center gap-1.5">
          <span className="h-2 w-2 rounded-full" style={{ background: i.color }} />{i.label}
        </span>
      ))}
    </div>
  );
}

/** Grafik batang berkelompok. data: [{ label, values: [n, n] }] */
export function BarChart({ data, colors, names = [], height = 280 }) {
  const [ref, width] = useWidth(520);
  const [hover, setHover] = useState(null);
  const pad = { l: 34, r: 8, t: 12, b: 28 };
  const innerW = width - pad.l - pad.r;
  const innerH = height - pad.t - pad.b;
  const max = niceMax(Math.max(0, ...data.flatMap((d) => d.values.map((v) => v || 0))));
  const groupW = innerW / Math.max(1, data.length);
  const series = colors.length;
  const barW = Math.min(18, (groupW * 0.6) / series);
  const y = (v) => pad.t + innerH - (v / max) * innerH;
  const ticks = [0, 0.25, 0.5, 0.75, 1].map((t) => t * max);

  return (
    <div ref={ref} className="relative w-full">
      <svg width={width} height={height} role="img" aria-label="Grafik batang">
        {ticks.map((t) => (
          <g key={t}>
            <line x1={pad.l} x2={width - pad.r} y1={y(t)} y2={y(t)} stroke="#e1e8e3" strokeDasharray="3 4" />
            <text x={pad.l - 8} y={y(t) + 4} textAnchor="end" fontSize="10" fill={AXIS}>{Math.round(t)}</text>
          </g>
        ))}
        {data.map((d, i) => {
          const gx = pad.l + i * groupW + groupW / 2;
          const total = barW * series + 4 * (series - 1);
          return (
            <g key={d.label} onMouseEnter={() => setHover(i)} onMouseLeave={() => setHover(null)}>
              <rect x={pad.l + i * groupW} y={pad.t} width={groupW} height={innerH} fill="transparent" />
              {d.values.map((v, s) => {
                const h = Math.max(0, innerH - (y(v || 0) - pad.t));
                return <rect key={s} x={gx - total / 2 + s * (barW + 4)} y={y(v || 0)} width={barW} height={h} rx="3" fill={colors[s]} opacity={hover == null || hover === i ? 1 : 0.55} />;
              })}
              <text x={gx} y={height - 8} textAnchor="middle" fontSize="12" fill="#4f634f">{d.label}</text>
            </g>
          );
        })}
      </svg>
      {hover != null && (
        <div className="absolute z-10 pointer-events-none bg-white border border-brand-200 shadow-lg rounded-lg px-3 py-2 text-xs"
          style={{ left: Math.min(width - 150, Math.max(0, pad.l + hover * groupW + groupW / 2 - 60)), top: 0 }}>
          <p className="font-semibold text-brand-700 mb-1">{data[hover].label}</p>
          {data[hover].values.map((v, s) => (
            <p key={s} className="flex items-center gap-1.5 text-brand-700"><span className="h-2 w-2 rounded-full" style={{ background: colors[s] }} />{names[s] || `Seri ${s + 1}`}: <b>{v ?? 0}</b></p>
          ))}
        </div>
      )}
    </div>
  );
}

/** Grafik garis multi-seri. series: [{ name, color, values: [n|null] }], labels: string[] */
export function LineChart({ series, labels, height = 300, markers = false, yMin, yMax, unit = "" }) {
  const [ref, width] = useWidth(640);
  const [hover, setHover] = useState(null);
  const pad = { l: 40, r: 16, t: 14, b: 30 };
  const innerW = width - pad.l - pad.r;
  const innerH = height - pad.t - pad.b;
  const all = series.flatMap((s) => s.values).filter((v) => v != null);
  const hasData = all.length > 0;
  let lo = yMin ?? (hasData ? Math.min(...all) : 0);
  let hi = yMax ?? (hasData ? Math.max(...all) : 100);
  if (yMin == null && hasData) lo = Math.max(0, lo - (hi - lo) * 0.15 - 1);
  if (yMax == null && hasData) hi = hi + (hi - lo) * 0.15 + 1;
  if (hi - lo < 1) hi = lo + 1;
  const n = labels.length;
  const x = (i) => pad.l + (n <= 1 ? innerW / 2 : (i / (n - 1)) * innerW);
  const y = (v) => pad.t + innerH - ((v - lo) / (hi - lo)) * innerH;
  const ticks = [0, 1, 2, 3, 4].map((i) => lo + ((hi - lo) * i) / 4);
  const labelStep = Math.ceil(n / Math.max(2, Math.floor(innerW / 64)));

  const segments = (vals) => {
    const out = []; let cur = [];
    vals.forEach((v, i) => { if (v == null) { if (cur.length) out.push(cur); cur = []; } else cur.push([x(i), y(v)]); });
    if (cur.length) out.push(cur);
    return out;
  };

  const onMove = (e) => {
    const rect = e.currentTarget.getBoundingClientRect();
    const px = e.clientX - rect.left;
    const i = Math.round(((px - pad.l) / Math.max(1, innerW)) * (n - 1));
    setHover(Math.min(n - 1, Math.max(0, n <= 1 ? 0 : i)));
  };

  return (
    <div ref={ref} className="relative w-full">
      <svg width={width} height={height} role="img" aria-label="Grafik garis" onMouseMove={onMove} onMouseLeave={() => setHover(null)}>
        {ticks.map((t, i) => (
          <g key={i}>
            <line x1={pad.l} x2={width - pad.r} y1={y(t)} y2={y(t)} stroke="#e1e8e3" strokeDasharray="3 4" />
            <text x={pad.l - 8} y={y(t) + 4} textAnchor="end" fontSize="10" fill={AXIS}>{hi - lo >= 6 ? Math.round(t) : Math.round(t * 10) / 10}</text>
          </g>
        ))}
        {labels.map((l, i) => (i % labelStep === 0 ? <text key={i} x={x(i)} y={height - 8} textAnchor="middle" fontSize="11" fill="#4f634f">{l}</text> : null))}
        {hover != null && <line x1={x(hover)} x2={x(hover)} y1={pad.t} y2={pad.t + innerH} stroke="#b9c8ba" />}
        {series.map((s) => (
          <g key={s.name}>
            {segments(s.values).map((seg, i) => seg.length > 1 && (
              <polyline key={i} points={seg.map((p) => p.join(",")).join(" ")} fill="none" stroke={s.color} strokeWidth="2.5" strokeLinejoin="round" strokeLinecap="round" />
            ))}
            {s.values.map((v, i) => v != null && (markers || n === 1 || hover === i || segments(s.values).every((sg) => sg.length === 1)) && (
              <circle key={i} cx={x(i)} cy={y(v)} r={hover === i ? 5 : 3.5} fill="#fff" stroke={s.color} strokeWidth="2" />
            ))}
          </g>
        ))}
        {!hasData && <text x={width / 2} y={height / 2} textAnchor="middle" fontSize="13" fill={AXIS}>Belum ada data pada periode ini</text>}
      </svg>
      {hover != null && hasData && (
        <div className="absolute z-10 pointer-events-none bg-white border border-brand-200 shadow-lg rounded-lg px-3 py-2 text-xs min-w-[140px]"
          style={{ left: Math.min(width - 170, Math.max(0, x(hover) + 10)), top: 6 }}>
          <p className="font-semibold text-brand-700 mb-1">{labels[hover]}</p>
          {series.map((s) => (
            <p key={s.name} className="flex items-center gap-1.5 text-brand-700"><span className="h-2 w-2 rounded-full" style={{ background: s.color }} />{s.name}: <b>{s.values[hover] ?? "—"}{s.values[hover] != null ? unit : ""}</b></p>
          ))}
        </div>
      )}
    </div>
  );
}

/** Donat. segments: [{ label, value, color }] */
export function Donut({ segments, size = 150, thickness = 24, center }) {
  const total = segments.reduce((a, s) => a + (s.value || 0), 0);
  const r = (size - thickness) / 2;
  const c = 2 * Math.PI * r;
  let offset = 0;
  return (
    <div className="relative inline-flex items-center justify-center" style={{ width: size, height: size }}>
      <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} className="-rotate-90" role="img" aria-label="Grafik donat">
        <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke="#e8f0ea" strokeWidth={thickness} />
        {total > 0 && segments.map((s) => {
          const len = (s.value / total) * c;
          const el = (
            <circle key={s.label} cx={size / 2} cy={size / 2} r={r} fill="none" stroke={s.color} strokeWidth={thickness}
              strokeDasharray={`${len} ${c - len}`} strokeDashoffset={-offset}>
              <title>{`${s.label}: ${s.value}`}</title>
            </circle>
          );
          offset += len;
          return el;
        })}
      </svg>
      {center && (
        <div className="absolute inset-0 flex flex-col items-center justify-center text-center">
          <span className="text-[26px] leading-none font-bold text-brand-700">{center.value}</span>
          <span className="text-[11px] text-muted mt-1">{center.label}</span>
        </div>
      )}
    </div>
  );
}
