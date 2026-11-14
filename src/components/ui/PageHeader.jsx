import { useSettings } from "../../context/SettingsContext";

/** Chip wilayah di pojok kanan atas (teks dari Pengaturan → Aplikasi). */
export function RegionChip() {
  const { values } = useSettings();
  return (
    <span className="inline-flex items-center h-10 px-4 rounded-lg bg-white border border-brand-200 text-[13px] font-semibold text-brand-700 whitespace-nowrap">
      {values["app.region_label"]}
    </span>
  );
}

export function PageHeader({ title, chip, subtitle, children, showRegion = false }) {
  return (
    <header className="flex flex-wrap items-start justify-between gap-x-6 gap-y-3 mb-6">
      <div className="min-w-0">
        <div className="flex items-center gap-3 flex-wrap">
          <h1 className="text-[28px] leading-tight font-bold text-brand-700">{title}</h1>
          {chip && <span className="inline-flex items-center h-7 px-3 rounded-md bg-brand-100 text-xs font-semibold text-brand-700">{chip}</span>}
        </div>
        {subtitle && <p className="text-sm text-muted mt-1.5 max-w-2xl">{subtitle}</p>}
      </div>
      <div className="flex flex-wrap items-center gap-3 no-print">
        {children}
        {showRegion && <RegionChip />}
      </div>
    </header>
  );
}
