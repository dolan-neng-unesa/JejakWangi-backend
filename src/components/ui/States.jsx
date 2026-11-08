import { AlertCircle, Inbox, RefreshCw } from "lucide-react";
import { Button } from "./Button";

export function Skeleton({ className = "" }) { return <div className={`skeleton ${className}`} />; }

export function EmptyState({ title = "Belum ada data", message, icon: Icon = Inbox, action }) {
  return (
    <div className="py-10 px-4 flex flex-col items-center text-center">
      <span className="h-12 w-12 rounded-full bg-brand-100 text-brand-600 inline-flex items-center justify-center mb-3"><Icon size={22} /></span>
      <p className="font-semibold text-brand-700">{title}</p>
      {message && <p className="text-sm text-muted mt-1 max-w-sm">{message}</p>}
      {action && <div className="mt-4">{action}</div>}
    </div>
  );
}

export function ErrorState({ error, onRetry, compact = false }) {
  return (
    <div className={`flex flex-col items-center text-center ${compact ? "py-6" : "py-10"} px-4`}>
      <span className="h-12 w-12 rounded-full bg-danger-bg text-danger inline-flex items-center justify-center mb-3"><AlertCircle size={22} /></span>
      <p className="font-semibold text-brand-700">Data gagal dimuat</p>
      <p className="text-sm text-muted mt-1 max-w-md">{error?.message || "Terjadi kesalahan."}</p>
      {onRetry && <Button variant="secondary" size="sm" icon={RefreshCw} className="mt-4" onClick={() => onRetry()}>Coba lagi</Button>}
    </div>
  );
}

/** Baris skeleton untuk tabel yang sedang memuat. */
export function TableSkeleton({ rows = 5, cols = 5 }) {
  return (
    <tbody>
      {Array.from({ length: rows }).map((_, r) => (
        <tr key={r} className="border-b border-brand-200/70">
          {Array.from({ length: cols }).map((__, c) => <td key={c} className="px-4 py-4"><Skeleton className="h-4 w-full max-w-[140px]" /></td>)}
        </tr>
      ))}
    </tbody>
  );
}
