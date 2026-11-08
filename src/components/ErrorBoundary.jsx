import { Component } from "react";

/**
 * Menangkap galat render — termasuk gagal memuat chunk halaman setelah deploy baru (nama file
 * lama sudah tidak ada) — supaya admin melihat pesan + tombol muat ulang, bukan layar putih.
 * `resetKey` (mis. pathname) membersihkan galat saat berpindah halaman.
 */
export default class ErrorBoundary extends Component {
  state = { error: null, resetKey: this.props.resetKey };

  static getDerivedStateFromError(error) {
    return { error };
  }

  static getDerivedStateFromProps(props, state) {
    return props.resetKey !== state.resetKey ? { error: null, resetKey: props.resetKey } : null;
  }

  componentDidCatch(error) {
    console.error("[JEJAK WANGI Admin] Galat tak tertangani di UI:", error);
  }

  render() {
    if (!this.state.error) return this.props.children;
    return (
      <div role="alert" className="h-full min-h-[60vh] flex flex-col items-center justify-center gap-3 text-center px-6">
        <h1 className="text-lg font-semibold">Halaman tidak dapat dimuat</h1>
        <p className="text-sm text-muted max-w-sm">Terjadi kendala saat membuka halaman ini. Periksa koneksi, lalu muat ulang.</p>
        <button onClick={() => window.location.reload()} className="mt-2 rounded-lg bg-brand-700 text-white px-5 py-2.5 text-sm font-medium">
          Muat Ulang
        </button>
      </div>
    );
  }
}
