import { TONE } from "../../lib/env";

/** Pil status kecil — warna mengikuti `tone` (ok | warn | info | danger | muted | dark | brand). */
export function Badge({ tone = "ok", children, className = "", solid = false }) {
  const t = tone === "brand" ? { fg: "#3f6e45", bg: "#e8f0ea" } : TONE[tone] || TONE.muted;
  return (
    <span
      className={`inline-flex items-center rounded-md px-2.5 py-1 text-xs font-semibold leading-none whitespace-nowrap ${className}`}
      style={solid ? { background: t.solid || t.fg, color: "#fff" } : { background: t.bg, color: t.fg }}
    >
      {children}
    </span>
  );
}
