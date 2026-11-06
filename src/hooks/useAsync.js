import { useCallback, useEffect, useRef, useState } from "react";

/**
 * Jalankan fungsi async saat mount / deps berubah.
 * - `interval` (ms): polling senyap (tanpa kedip skeleton).
 * - hasil lama tetap tampil saat memuat ulang (stale-while-revalidate).
 */
export function useAsync(fn, deps = [], { interval = 0, enabled = true } = {}) {
  const [state, setState] = useState({ data: null, error: null, loading: enabled });
  const fnRef = useRef(fn);
  fnRef.current = fn;
  const seq = useRef(0);
  const pending = useRef(0); // jumlah request yang sedang berjalan (untuk mencegah polling menumpuk)

  const run = useCallback(async ({ silent = false } = {}) => {
    const id = ++seq.current;
    if (!silent) setState((s) => ({ ...s, loading: true, error: null }));
    pending.current++;
    try {
      const data = await fnRef.current();
      if (id === seq.current) setState({ data, error: null, loading: false });
    } catch (error) {
      if (id === seq.current) setState((s) => ({ data: silent ? s.data : null, error, loading: false }));
    } finally {
      pending.current--;
    }
  }, []);

  useEffect(() => {
    if (!enabled) { setState((s) => ({ ...s, loading: false })); return undefined; }
    run();
    let timer;
    let onVisible;
    if (interval > 0) {
      // Polling senyap: lewati tick bila tab tersembunyi (dashboard yang dibiarkan terbuka di
      // tab latar tidak perlu menembak server tiap interval) atau request sebelumnya belum
      // selesai (server lambat → request menumpuk). Saat tab kembali terlihat, segarkan langsung.
      const tick = () => { if (!document.hidden && pending.current === 0) run({ silent: true }); };
      timer = setInterval(tick, interval);
      onVisible = tick;
      document.addEventListener("visibilitychange", onVisible);
    }
    return () => {
      clearInterval(timer);
      if (onVisible) document.removeEventListener("visibilitychange", onVisible);
      seq.current++;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [...deps, interval, enabled]);

  const setData = useCallback((updater) => setState((s) => ({ ...s, data: typeof updater === "function" ? updater(s.data) : updater })), []);

  return { ...state, reload: run, setData };
}

/** Debounce nilai (untuk kolom cari). */
export function useDebounced(value, ms = 350) {
  const [v, setV] = useState(value);
  useEffect(() => { const t = setTimeout(() => setV(value), ms); return () => clearTimeout(t); }, [value, ms]);
  return v;
}
