import { Badge } from "./Badge";
import { Card } from "./Card";
import { Skeleton } from "./States";

/** Kartu statistik: label + ikon, angka besar + badge, keterangan. */
export function StatCard({ label, icon: Icon, iconColor = "#3f6e45", value, unit, badge, sub, loading, subTone }) {
  return (
    <Card className="p-4 xl:p-[18px] min-w-0">
      <div className="flex items-center justify-between gap-2 mb-3">
        <p className="text-[13px] text-brand-700 whitespace-nowrap truncate">{label}</p>
        {Icon && <Icon size={18} style={{ color: iconColor }} className="shrink-0" />}
      </div>
      {loading ? (
        <><Skeleton className="h-9 w-28 mb-3" /><Skeleton className="h-4 w-36" /></>
      ) : (
        <>
          <div className="flex items-center gap-3 flex-wrap">
            <span className="text-[28px] leading-none font-bold text-brand-700">{value}{unit && <span>{unit}</span>}</span>
            {badge && <Badge tone={badge.tone}>{badge.text}</Badge>}
          </div>
          {sub && <p className={`text-[13px] mt-3 ${subTone === "ok" ? "text-ok" : subTone === "warn" ? "text-warn" : "text-muted"}`}>{sub}</p>}
        </>
      )}
    </Card>
  );
}
