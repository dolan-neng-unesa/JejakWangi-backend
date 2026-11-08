import { createContext, useCallback, useContext, useMemo, useState } from "react";
import { CheckCircle2, Info, XCircle, X } from "lucide-react";

const ToastCtx = createContext(null);
export const useToast = () => useContext(ToastCtx);

export function ToastProvider({ children }) {
  const [items, setItems] = useState([]);
  const remove = useCallback((id) => setItems((l) => l.filter((t) => t.id !== id)), []);
  const push = useCallback((type, message) => {
    const id = Math.random().toString(36).slice(2);
    setItems((l) => [...l.slice(-3), { id, type, message }]);
    setTimeout(() => remove(id), type === "error" ? 6000 : 3800);
  }, [remove]);

  const api = useMemo(() => ({
    success: (m) => push("success", m),
    error: (m) => push("error", m),
    info: (m) => push("info", m),
  }), [push]);

  const icon = { success: <CheckCircle2 size={18} className="text-ok" />, error: <XCircle size={18} className="text-danger" />, info: <Info size={18} className="text-info" /> };

  return (
    <ToastCtx.Provider value={api}>
      {children}
      <div className="fixed bottom-5 right-5 z-[2000] flex flex-col gap-2 w-[min(92vw,360px)] no-print" role="status" aria-live="polite">
        {items.map((t) => (
          <div key={t.id} className="anim-slide flex items-start gap-3 bg-white border border-brand-200 shadow-lg rounded-xl px-4 py-3 text-sm text-brand-800">
            <span className="mt-0.5 shrink-0">{icon[t.type]}</span>
            <span className="flex-1 leading-snug">{t.message}</span>
            <button onClick={() => remove(t.id)} aria-label="Tutup" className="text-brand-400 hover:text-brand-700"><X size={16} /></button>
          </div>
        ))}
      </div>
    </ToastCtx.Provider>
  );
}
