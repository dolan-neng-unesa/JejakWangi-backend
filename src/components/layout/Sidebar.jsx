import { NavLink } from "react-router-dom";
import { Activity, BarChart3, Bell, CalendarDays, Cpu, BookOpen, Flag, Footprints, Leaf, Recycle, LayoutDashboard, LogOut, MapPin, Navigation, Settings, Smile, Users, X } from "lucide-react";
import { useAuth } from "../../context/AuthContext";
import { useSettings } from "../../context/SettingsContext";
import { useNotifBadge } from "../../context/NotifContext";

export const NAV = [
  { to: "/", label: "Dashboard", icon: LayoutDashboard, end: true },
  { to: "/monitoring", label: "Monitoring Lingkungan", icon: Activity },
  { to: "/smart-green-route", label: "Smart Green Route", icon: Navigation },
  { to: "/kalender-festival", label: "Kalender Festival BWI", icon: CalendarDays },
  { to: "/sensor-iot", label: "Sensor IoT", icon: Cpu },
  { to: "/mood-wellbeing", label: "Mood & Wellbeing", icon: Smile },
  { to: "/sampah-pangan", label: "Sampah & Pangan", icon: Recycle },
  { to: "/jejak-wangi", label: "Jejak Wangi", icon: Footprints },
  { to: "/geofencing", label: "Geofencing", icon: MapPin },
  { to: "/laporan", label: "Laporan & Analitik", icon: BarChart3 },
  { to: "/pengguna", label: "Pengguna", icon: Users },
  { to: "/notifikasi", label: "Notifikasi", icon: Bell, badge: true },
  { to: "/pengaturan", label: "Pengaturan", icon: Settings },
  { to: "/lapor-koridor", label: "Lapor Koridor", icon: Flag },
  { to: "/cara-menghitung", label: "Cara Kami Menghitung", icon: BookOpen },
];

export function Logo() {
  const { values } = useSettings();
  return (
    <div className="flex items-center gap-3">
      <span className="h-10 w-10 rounded-xl bg-white inline-flex items-center justify-center shrink-0">
        <Leaf size={20} className="text-brand-700" />
      </span>
      <div className="leading-none">
        <p className="text-2xl font-bold text-white tracking-wide">{values["app.name"]}</p>
        <p className="text-[10px] font-medium text-white/70 tracking-[0.12em] mt-1">BANYUWANGI</p>
      </div>
    </div>
  );
}

export function Sidebar({ open, onClose }) {
  const { user, logout } = useAuth();
  const { unread } = useNotifBadge();

  return (
    <>
      {open && <div className="fixed inset-0 bg-black/40 z-40 lg:hidden no-print" onClick={onClose} />}
      <aside className={`fixed lg:static inset-y-0 left-0 z-50 w-[280px] shrink-0 bg-brand-700 flex flex-col transition-transform duration-200 ${open ? "translate-x-0" : "-translate-x-full lg:translate-x-0"}`}>
        <div className="px-5 pt-6 pb-5 flex items-center justify-between">
          <Logo />
          <button onClick={onClose} aria-label="Tutup menu" className="lg:hidden text-white/80 hover:text-white"><X size={22} /></button>
        </div>
        <div className="mx-5 border-t border-white/15" />

        <nav className="flex-1 overflow-y-auto px-5 py-5 flex flex-col gap-1" aria-label="Menu utama">
          {NAV.map(({ to, label, icon: Icon, end, badge }) => (
            <NavLink key={to} to={to} end={end} onClick={onClose}
              className={({ isActive }) => `group flex items-center gap-3.5 h-11 px-3 rounded-lg text-[15px] transition-colors ${isActive ? "text-white font-semibold" : "text-white/55 hover:text-white hover:bg-white/5 font-medium"}`}>
              <Icon size={20} strokeWidth={2} />
              <span className="flex-1 truncate">{label}</span>
              {badge && unread > 0 && (
                <span className="min-w-5 h-5 px-1.5 rounded-full bg-white text-brand-700 text-[11px] font-bold inline-flex items-center justify-center">{unread > 99 ? "99+" : unread}</span>
              )}
            </NavLink>
          ))}
        </nav>

        <div className="p-5">
          <div className="flex items-center gap-3 rounded-xl bg-white/10 px-4 py-3.5">
            <div className="min-w-0 flex-1">
              <p className="text-sm font-semibold text-white truncate">{user?.name}</p>
              <p className="text-xs text-white/60">Super Admin</p>
            </div>
            <button onClick={logout} aria-label="Keluar" title="Keluar" className="text-white/70 hover:text-white p-1.5 rounded-lg hover:bg-white/10"><LogOut size={19} /></button>
          </div>
        </div>
      </aside>
    </>
  );
}
