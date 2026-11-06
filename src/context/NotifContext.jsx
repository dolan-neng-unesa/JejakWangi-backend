import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from "react";
import { notifications } from "../services/svarga";
import { useAuth } from "./AuthContext";
import { useSettings } from "./SettingsContext";

const Ctx = createContext({ unread: 0, refresh: () => {} });
export const useNotifBadge = () => useContext(Ctx);

const POLL_MS = 30_000;

/**
 * Polling ringan jumlah notifikasi belum dibaca (badge sidebar) + notifikasi push
 * browser untuk item baru — hanya bila "Notifikasi Push" aktif di Pengaturan,
 * kategorinya dilanggan, dan izin browser sudah diberikan.
 */
export function NotifProvider({ children }) {
  const { user } = useAuth();
  const { values } = useSettings();
  const [unread, setUnread] = useState(0);
  const lastId = useRef(null);
  const settingsRef = useRef(values);
  settingsRef.current = values;

  const refresh = useCallback(async () => {
    try {
      const res = await notifications.list({ per_page: 10 });
      setUnread(res.belum_dibaca ?? 0);
      const items = res.data || [];
      const maxId = items.reduce((m, n) => Math.max(m, n.id), 0);
      if (lastId.current == null) { lastId.current = maxId; return; } // jangan banjiri notifikasi lama saat pertama buka
      const s = settingsRef.current;
      if (s["notify.push"] && typeof Notification !== "undefined" && Notification.permission === "granted") {
        items
          .filter((n) => n.id > lastId.current && n.unread && s[`notify.cat.${n.kategori}`] !== false)
          .reverse()
          .forEach((n) => { try { new Notification(n.title, { body: n.desc, icon: "/favicon.svg", tag: `svarga-${n.id}` }); } catch { /* browser menolak */ } });
      }
      lastId.current = Math.max(lastId.current, maxId);
    } catch { /* diam — badge tidak kritis */ }
  }, []);

  useEffect(() => {
    if (!user) { lastId.current = null; setUnread(0); return undefined; }
    refresh();
    // Jangan polling saat tab tersembunyi; segarkan langsung begitu terlihat lagi.
    const tick = () => { if (!document.hidden) refresh(); };
    const t = setInterval(tick, POLL_MS);
    document.addEventListener("visibilitychange", tick);
    return () => { clearInterval(t); document.removeEventListener("visibilitychange", tick); };
  }, [user, refresh]);

  const v = useMemo(() => ({ unread, refresh, setUnread }), [unread, refresh]);
  return <Ctx.Provider value={v}>{children}</Ctx.Provider>;
}
