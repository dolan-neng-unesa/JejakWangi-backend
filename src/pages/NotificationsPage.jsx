import { useState } from "react";
import { Activity, AlertCircle, Bell, CalendarDays, CheckCheck, Clock, Cpu, MapPin, Smile, Users } from "lucide-react";
import { PageHeader } from "../components/ui/PageHeader";
import { StatCard } from "../components/ui/StatCard";
import { Card } from "../components/ui/Card";
import { Button } from "../components/ui/Button";
import { Tabs } from "../components/ui/Tabs";
import { EmptyState, ErrorState, Skeleton } from "../components/ui/States";
import { NotifSettings } from "../components/NotifSettings";
import { useAsync } from "../hooks/useAsync";
import { useNotifBadge } from "../context/NotifContext";
import { useToast } from "../context/ToastContext";
import { notifications as api } from "../services/jejakwangi";
import { formatTanggal, fmtNum, relativeTime, todayISO } from "../lib/format";

const CAT = {
  lingkungan: { icon: Activity, fg: "#d32f2f", bg: "#fdeeee", label: "Lingkungan" },
  sensor: { icon: Cpu, fg: "#ed6c02", bg: "#fef1e4", label: "Sensor" },
  festival: { icon: CalendarDays, fg: "#2e7d32", bg: "#e8f3e8", label: "Festival" },
  pengguna: { icon: Users, fg: "#0288d1", bg: "#e6f4fb", label: "Pengguna" },
  geofence: { icon: MapPin, fg: "#d32f2f", bg: "#fdeeee", label: "Geofencing" },
  mood: { icon: Smile, fg: "#2e7d32", bg: "#e8f3e8", label: "Mood" },
};
const FALLBACK = { icon: Bell, fg: "#647664", bg: "#eef2ee", label: "Lainnya" };
const PAGE = 15;

export default function NotificationsPage() {
  const toast = useToast();
  const badge = useNotifBadge();
  const [cat, setCat] = useState("");
  const [limit, setLimit] = useState(PAGE);
  const [marking, setMarking] = useState(false);
  const res = useAsync(() => api.list({ per_page: limit, category: cat }), [cat, limit], { interval: 30_000 });
  const d = res.data;
  const items = d?.data ?? [];
  const counts = d?.counts ?? {};
  const shown = d?.filtered_total ?? d?.total ?? 0;

  async function markAll() {
    setMarking(true);
    try { await api.markAllRead(); toast.success("Semua notifikasi ditandai terbaca."); await res.reload({ silent: true }); badge.refresh(); badge.setUnread?.(0); }
    catch (e) { toast.error(e.message); } finally { setMarking(false); }
  }

  const tabs = [{ value: "", label: "Semua" }, ...["lingkungan", "festival", "sensor", "pengguna", "geofence", "mood"].map((c) => ({ value: c, label: CAT[c].label, count: counts[c] ?? 0 }))];

  return (
    <>
      <PageHeader title="Notifikasi" chip={formatTanggal(todayISO())} subtitle="Pantau peringatan sistem, status sensor IoT, laporan geofencing, dan aktivitas warga secara real-time.">
        <Button variant="secondary" icon={CheckCheck} loading={marking} disabled={!d?.belum_dibaca} onClick={markAll}>Tandai Semua Dibaca</Button>
      </PageHeader>

      <div className="grid gap-4 grid-cols-1 md:grid-cols-3 mb-5">
        <StatCard label="Total Notifikasi" icon={Bell} loading={!d && res.loading} value={fmtNum(d?.total)} sub="Akumulasi seluruh log notifikasi" />
        <StatCard label="Belum Dibaca" icon={AlertCircle} loading={!d && res.loading} value={fmtNum(d?.belum_dibaca)} sub="Butuh perhatian segera" />
        <StatCard label="Notifikasi Hari Ini" icon={Clock} loading={!d && res.loading} value={fmtNum(d?.hari_ini)} sub="Pembaruan masuk sejak pukul 00:00" />
      </div>

      <div className="grid gap-4 grid-cols-1 xl:grid-cols-[1fr_380px] items-start">
        <div>
          <Tabs tabs={tabs} value={cat} onChange={(v) => { setCat(v); setLimit(PAGE); }} className="mb-4" />
          <Card className="overflow-hidden">
            {res.error && !d ? <ErrorState error={res.error} onRetry={res.reload} /> : !d ? (
              <div className="p-4 space-y-3">{Array.from({ length: 5 }).map((_, i) => <Skeleton key={i} className="h-16" />)}</div>
            ) : items.length === 0 ? <EmptyState icon={Bell} title="Tidak ada notifikasi" message={cat ? "Belum ada notifikasi pada kategori ini." : "Peringatan sistem akan muncul di sini."} /> : (
              <ul>
                {items.map((n) => {
                  const c = CAT[n.kategori] ?? FALLBACK; const Icon = c.icon;
                  return (
                    <li key={n.id} className={`flex items-start gap-4 px-5 py-4 border-b border-brand-200 last:border-0 ${n.unread ? "bg-canvas" : "bg-white"}`}>
                      <span className="h-10 w-10 rounded-xl inline-flex items-center justify-center shrink-0" style={{ background: c.bg, color: c.fg }}><Icon size={19} /></span>
                      <div className="min-w-0 flex-1">
                        <div className="flex items-start justify-between gap-3"><p className={`text-[15px] text-brand-700 ${n.unread ? "font-bold" : "font-semibold"}`}>{n.title}</p><span className="text-xs text-muted whitespace-nowrap">{relativeTime(n.time)}</span></div>
                        <p className="text-[13px] text-muted-2 mt-1 leading-relaxed">{n.desc}</p>
                      </div>
                      {n.unread && <span className="h-2 w-2 rounded-full bg-brand-700 mt-2 shrink-0" aria-label="Belum dibaca" />}
                    </li>
                  );
                })}
              </ul>
            )}
            {d && items.length < shown && (
              <div className="p-4 text-center border-t border-brand-200"><Button variant="soft" size="sm" onClick={() => setLimit((l) => l + PAGE)}>Muat lebih banyak ({shown - items.length} lagi)</Button></div>
            )}
          </Card>
        </div>
        <NotifSettings />
      </div>
    </>
  );
}
