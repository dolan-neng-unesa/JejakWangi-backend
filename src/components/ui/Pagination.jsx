import { ChevronLeft, ChevronRight } from "lucide-react";

function pageList(page, last) {
  if (last <= 7) return Array.from({ length: last }, (_, i) => i + 1);
  const set = new Set([1, 2, 3, last, page - 1, page, page + 1]);
  const nums = [...set].filter((n) => n >= 1 && n <= last).sort((a, b) => a - b);
  const out = [];
  nums.forEach((n, i) => { if (i && n - nums[i - 1] > 1) out.push("…"); out.push(n); });
  return out;
}

export function Pagination({ page, lastPage, onChange }) {
  const btn = "h-8 min-w-8 px-2 inline-flex items-center justify-center rounded-md text-[13px] font-semibold transition-colors";
  return (
    <div className="flex items-center gap-1.5">
      <button aria-label="Sebelumnya" disabled={page <= 1} onClick={() => onChange(page - 1)} className={`${btn} bg-canvas text-brand-700 disabled:opacity-40`}><ChevronLeft size={15} /></button>
      {pageList(page, lastPage).map((n, i) =>
        n === "…" ? <span key={`e${i}`} className="px-1 text-muted">…</span> : (
          <button key={n} onClick={() => onChange(n)} aria-current={n === page ? "page" : undefined}
            className={`${btn} ${n === page ? "bg-brand-700 text-white" : "bg-canvas text-brand-700 hover:bg-brand-100"}`}>{n}</button>
        )
      )}
      <button aria-label="Berikutnya" disabled={page >= lastPage} onClick={() => onChange(page + 1)} className={`${btn} bg-canvas text-brand-700 disabled:opacity-40`}><ChevronRight size={15} /></button>
    </div>
  );
}
