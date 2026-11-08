import { useEffect, useState } from "react";
import { Navigate, Outlet, useLocation } from "react-router-dom";
import { Menu } from "lucide-react";
import { Sidebar, Logo } from "./Sidebar";
import { useAuth } from "../../context/AuthContext";
import { NotifProvider } from "../../context/NotifContext";

export function AppLayout() {
  const { user, booting } = useAuth();
  const [open, setOpen] = useState(false);
  const loc = useLocation();

  useEffect(() => { setOpen(false); window.scrollTo(0, 0); }, [loc.pathname]);

  if (booting) return <div className="h-full flex items-center justify-center text-muted text-sm">Memuat sesi…</div>;
  if (!user) return <Navigate to="/login" replace state={{ from: loc.pathname }} />;

  return (
    <NotifProvider>
      <div className="flex h-full">
        <Sidebar open={open} onClose={() => setOpen(false)} />
        <div className="flex-1 min-w-0 flex flex-col">
          <div className="lg:hidden flex items-center gap-3 bg-brand-700 px-4 h-14 shrink-0 no-print">
            <button onClick={() => setOpen(true)} aria-label="Buka menu" className="text-white"><Menu size={24} /></button>
            <Logo />
          </div>
          <main className="flex-1 overflow-y-auto px-5 py-6 lg:px-8 lg:py-8">
            <div className="max-w-[1400px] mx-auto"><Outlet /></div>
          </main>
        </div>
      </div>
    </NotifProvider>
  );
}
