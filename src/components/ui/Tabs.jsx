/** Tab pil (Monitoring Lingkungan) atau garis bawah (Pengaturan). */
export function Tabs({ tabs, value, onChange, variant = "pill", className = "" }) {
  if (variant === "underline") {
    return (
      <div className={`flex gap-8 border-b border-brand-200 overflow-x-auto no-scrollbar ${className}`} role="tablist">
        {tabs.map((t) => {
          const on = t.value === value;
          return (
            <button key={t.value} role="tab" aria-selected={on} onClick={() => onChange(t.value)}
              className={`relative pb-3 text-[15px] font-semibold whitespace-nowrap transition-colors ${on ? "text-brand-700" : "text-brand-700/80 hover:text-brand-700"}`}>
              {t.label}
              {on && <span className="absolute left-0 right-0 -bottom-px h-[3px] rounded-full bg-brand-700" />}
            </button>
          );
        })}
      </div>
    );
  }
  return (
    <div className={`flex flex-wrap gap-2 ${className}`} role="tablist">
      {tabs.map((t) => {
        const on = t.value === value;
        return (
          <button key={t.value} role="tab" aria-selected={on} onClick={() => onChange(t.value)}
            className={`h-10 px-5 rounded-lg text-sm font-semibold transition-colors inline-flex items-center gap-2 ${on ? "bg-brand-700 text-white" : "text-brand-700 hover:bg-brand-100"}`}>
            {t.label}
            {t.count != null && (
              <span className={`text-[11px] px-1.5 py-0.5 rounded ${on ? "bg-white/20 text-white" : "bg-brand-100 text-brand-700"}`}>{t.count}</span>
            )}
          </button>
        );
      })}
    </div>
  );
}
