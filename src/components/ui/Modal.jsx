import { useEffect, useRef } from "react";
import { createPortal } from "react-dom";
import { X, AlertTriangle } from "lucide-react";
import { Button } from "./Button";

const WIDTH = { sm: "max-w-md", md: "max-w-xl", lg: "max-w-3xl", xl: "max-w-5xl" };

export function Modal({ open, onClose, title, subtitle, size = "md", children, footer, dismissable = true }) {
  const panel = useRef(null);

  useEffect(() => {
    if (!open) return undefined;
    const onKey = (e) => { if (e.key === "Escape" && dismissable) onClose?.(); };
    document.addEventListener("keydown", onKey);
    const prev = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    // Fokus ke panel supaya Esc & tab langsung berlaku di dalam dialog.
    setTimeout(() => panel.current?.focus(), 0);
    return () => { document.removeEventListener("keydown", onKey); document.body.style.overflow = prev; };
  }, [open, onClose, dismissable]);

  if (!open) return null;
  return createPortal(
    <div className="fixed inset-0 z-[1500] flex items-center justify-center p-4 no-print" role="dialog" aria-modal="true" aria-label={title}>
      <div className="absolute inset-0 bg-[#16241a]/50 anim-fade" onClick={dismissable ? onClose : undefined} />
      <div ref={panel} tabIndex={-1} className={`relative anim-pop w-full ${WIDTH[size]} max-h-[92vh] flex flex-col bg-white rounded-2xl shadow-2xl outline-none`}>
        <div className="flex items-start justify-between gap-4 px-6 pt-5 pb-3">
          <div>
            <h3 className="text-lg font-semibold text-brand-700">{title}</h3>
            {subtitle && <p className="text-[13px] text-muted mt-0.5">{subtitle}</p>}
          </div>
          <button onClick={onClose} aria-label="Tutup" className="h-8 w-8 -mr-2 inline-flex items-center justify-center rounded-lg text-brand-400 hover:bg-brand-100 hover:text-brand-700"><X size={18} /></button>
        </div>
        <div className="px-6 pb-5 overflow-y-auto flex-1">{children}</div>
        {footer && <div className="px-6 py-4 border-t border-brand-200 bg-canvas rounded-b-2xl flex flex-wrap justify-end gap-2.5">{footer}</div>}
      </div>
    </div>,
    document.body
  );
}

export function ConfirmDialog({ open, onClose, onConfirm, title, message, confirmLabel = "Ya, lanjutkan", tone = "danger", loading = false }) {
  return (
    <Modal
      open={open}
      onClose={loading ? undefined : onClose}
      size="sm"
      title={title}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={loading}>Batal</Button>
          <Button variant={tone === "danger" ? "danger" : "primary"} onClick={onConfirm} loading={loading}>{confirmLabel}</Button>
        </>
      }
    >
      <div className="flex gap-3 items-start">
        <span className={`mt-0.5 h-9 w-9 shrink-0 rounded-full inline-flex items-center justify-center ${tone === "danger" ? "bg-danger-bg text-danger" : "bg-warn-bg text-warn"}`}><AlertTriangle size={18} /></span>
        <div className="text-sm text-brand-800 leading-relaxed">{message}</div>
      </div>
    </Modal>
  );
}
