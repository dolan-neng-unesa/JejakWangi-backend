import { forwardRef, useId } from "react";
import { ChevronDown } from "lucide-react";

export const inputCls =
  "w-full h-11 rounded-xl border border-brand-200 bg-canvas px-3.5 text-sm text-brand-800 placeholder:text-brand-400 transition focus:outline-none focus:ring-2 focus:ring-brand-700/25 focus:border-brand-700 disabled:opacity-60 aria-[invalid=true]:border-danger";

export function Field({ label, error, hint, required, children, className = "", htmlFor }) {
  return (
    <div className={className}>
      {label && (
        <label htmlFor={htmlFor} className="block text-[13px] font-semibold text-brand-700 mb-1.5">
          {label}{required && <span className="text-danger"> *</span>}
        </label>
      )}
      {children}
      {error ? <p className="text-xs text-danger mt-1.5">{error}</p> : hint ? <p className="text-xs text-muted mt-1.5">{hint}</p> : null}
    </div>
  );
}

export const Input = forwardRef(function Input({ className = "", invalid, ...rest }, ref) {
  return <input ref={ref} aria-invalid={invalid ? "true" : undefined} className={`${inputCls} ${className}`} {...rest} />;
});

export function Textarea({ className = "", invalid, ...rest }) {
  return <textarea aria-invalid={invalid ? "true" : undefined} className={`${inputCls} h-auto py-2.5 min-h-24 resize-y ${className}`} {...rest} />;
}

export function Select({ className = "", invalid, children, ...rest }) {
  return (
    <div className="relative">
      <select aria-invalid={invalid ? "true" : undefined} className={`${inputCls} appearance-none pr-9 ${className}`} {...rest}>{children}</select>
      <ChevronDown size={16} className="absolute right-3 top-1/2 -translate-y-1/2 text-brand-400 pointer-events-none" />
    </div>
  );
}

/** Saklar seperti di panel Pengaturan Notifikasi (aktif = hijau tua, mati = abu-hijau). */
export function Toggle({ checked, onChange, disabled, label }) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={checked}
      aria-label={label}
      disabled={disabled}
      onClick={() => onChange(!checked)}
      className={`relative h-6 w-11 shrink-0 rounded-full transition-colors ${checked ? "bg-brand-700" : "bg-brand-400/70"} ${disabled ? "opacity-50" : ""}`}
    >
      <span className={`absolute top-0.5 h-5 w-5 rounded-full bg-white shadow transition-all ${checked ? "left-[22px]" : "left-0.5"}`} />
    </button>
  );
}

/** Pilihan inline bergaya mockup: label tebal + nilai, mis. "Periode: 7 Hari Terakhir ⌄". */
export function FilterSelect({ label, value, onChange, options, className = "" }) {
  const id = useId();
  const current = options.find((o) => String(o.value) === String(value));
  return (
    <label htmlFor={id} className={`relative inline-flex items-center gap-1.5 h-11 pl-4 pr-3 bg-white border border-brand-200 rounded-xl text-sm cursor-pointer hover:border-brand-400 transition-colors focus-within:ring-2 focus-within:ring-brand-700/25 ${className}`}>
      <span className="font-semibold text-brand-700">{label}:</span>
      <span className="text-brand-700">{current?.label ?? "—"}</span>
      <ChevronDown size={15} className="text-brand-700 ml-1" />
      <select id={id} value={value} onChange={(e) => onChange(e.target.value)} className="absolute inset-0 opacity-0 w-full h-full cursor-pointer">
        {options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
      </select>
    </label>
  );
}
