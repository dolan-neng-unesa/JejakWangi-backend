import { Loader2 } from "lucide-react";

const VARIANT = {
  primary: "bg-brand-700 text-white hover:bg-brand-800 disabled:bg-brand-300",
  secondary: "bg-white text-brand-700 border border-brand-200 hover:bg-brand-50 disabled:text-brand-400",
  soft: "bg-canvas text-brand-700 border border-brand-200 hover:bg-brand-100",
  danger: "bg-danger text-white hover:bg-[#b52626] disabled:bg-[#e8a5a5]",
  info: "bg-info text-white hover:bg-[#0277b6] disabled:bg-[#9fd1ec]",
  ghost: "text-brand-700 hover:bg-brand-100",
};
const SIZE = {
  sm: "h-9 px-3.5 text-[13px] rounded-lg gap-1.5",
  md: "h-11 px-5 text-sm rounded-xl gap-2",
};

export function Button({ variant = "primary", size = "md", loading = false, icon: Icon, children, className = "", type = "button", ...rest }) {
  return (
    <button
      type={type}
      disabled={loading || rest.disabled}
      className={`inline-flex items-center justify-center font-semibold transition-colors whitespace-nowrap ${VARIANT[variant]} ${SIZE[size]} ${className}`}
      {...rest}
    >
      {loading ? <Loader2 size={16} className="animate-spin" /> : Icon ? <Icon size={size === "sm" ? 15 : 17} /> : null}
      {children}
    </button>
  );
}

/** Tombol ikon persegi kecil (aksi tabel: lihat / ubah / hapus). */
export function IconButton({ icon: Icon, label, tone = "brand", className = "", ...rest }) {
  const tones = {
    brand: "text-brand-700 bg-brand-100 hover:bg-brand-200",
    danger: "text-danger bg-danger-bg hover:bg-[#f9d6d6]",
    plain: "text-brand-700 hover:bg-brand-100",
  };
  return (
    <button type="button" aria-label={label} title={label} className={`h-8 w-8 inline-flex items-center justify-center rounded-lg transition-colors ${tones[tone]} ${className}`} {...rest}>
      <Icon size={16} />
    </button>
  );
}
