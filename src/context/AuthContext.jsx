import { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react";
import { ApiError, getToken, setToken, setUnauthorizedHandler } from "../lib/api";
import { auth } from "../services/jejakwangi";

const AuthCtx = createContext(null);
export const useAuth = () => useContext(AuthCtx);

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [booting, setBooting] = useState(Boolean(getToken()));

  const clear = useCallback(() => { setToken(null); setUser(null); }, []);

  useEffect(() => { setUnauthorizedHandler(clear); }, [clear]);

  // Pulihkan sesi dari token tersimpan.
  // Hanya penolakan dari server (401 token dicabut/kedaluwarsa, 403 akun dinonaktifkan) yang
  // menghapus token. Server yang sedang restart / jaringan putus TIDAK boleh membuat admin
  // ter-logout: dicoba ulang beberapa kali, dan bila tetap gagal token dipertahankan
  // (halaman login muncul, muat ulang akan mencoba lagi).
  useEffect(() => {
    if (!getToken()) return undefined;
    let cancelled = false;

    (async () => {
      for (let attempt = 0; attempt < 4 && !cancelled; attempt++) {
        try {
          const u = await auth.me();
          if (cancelled) return;
          if (u?.is_admin) setUser(u); else clear();
          break;
        } catch (err) {
          if (err instanceof ApiError && (err.status === 401 || err.status === 403)) { if (!cancelled) clear(); break; }
          if (attempt < 3) await new Promise((r) => setTimeout(r, 1500 * (attempt + 1)));
        }
      }
      if (!cancelled) setBooting(false);
    })();

    return () => { cancelled = true; };
  }, [clear]);

  const login = useCallback(async (email, password) => {
    const res = await auth.login(email, password);
    if (!res?.user?.is_admin) {
      // Token sudah terbit untuk akun biasa — cabut supaya tidak menggantung.
      setToken(res?.token);
      try { await auth.logout(); } catch { /* abaikan */ }
      setToken(null);
      throw new ApiError("Akun ini bukan admin. Dashboard hanya untuk administrator JEJAKWANGI.", 403);
    }
    setToken(res.token);
    setUser(res.user);
    return res.user;
  }, []);

  const logout = useCallback(async () => {
    try { await auth.logout(); } catch { /* token mungkin sudah kedaluwarsa */ }
    clear();
  }, [clear]);

  const value = useMemo(() => ({ user, booting, login, logout, setUser }), [user, booting, login, logout]);
  return <AuthCtx.Provider value={value}>{children}</AuthCtx.Provider>;
}
