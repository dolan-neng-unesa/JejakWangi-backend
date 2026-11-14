import { useState } from "react";
import { Sparkles } from "lucide-react";
import { Card, CardTitle } from "./ui/Card";
import { Button } from "./ui/Button";
import { ApiError } from "../lib/api";
import { ecoCoach } from "../services/jejakwangi";

/**
 * Asisten Laporan (Eco-Coach mode laporan): 3 temuan + 3 usulan tindakan dari data minggu ini.
 * Angka dihitung server; AI hanya menjelaskan. Usulan bersifat saran — keputusan tetap di pengelola.
 */
export default function AsistenLaporan() {
  const [state, setState] = useState({ loading: false, hasil: null, error: null });

  async function buat() {
    setState({ loading: true, hasil: null, error: null });
    try {
      const hasil = await ecoCoach.laporan();
      setState({ loading: false, hasil, error: null });
    } catch (err) {
      const pesan = err instanceof ApiError && err.status === 429
        ? "Terlalu sering meminta laporan. Coba lagi sebentar lagi."
        : "Asisten Laporan belum bisa dihubungi. Coba lagi nanti.";
      setState({ loading: false, hasil: null, error: pesan });
    }
  }

  const { loading, hasil, error } = state;
  return (
    <Card className="p-5 mb-4 no-print">
      <CardTitle right={<Button variant="soft" size="sm" icon={Sparkles} loading={loading} onClick={buat}>{hasil ? "Buat ulang" : "Buat laporan mingguan"}</Button>}>Asisten Laporan Eco-Coach</CardTitle>
      <div aria-live="polite">
        {!hasil && !error && !loading && <p className="mt-3 text-[13px] text-brand-700">Ringkasan temuan dan usulan tindakan minggu ini dari data aktivitas, kemasan sekali pakai, dan sensor koridor.</p>}
        {loading && <p className="mt-3 text-[13px] text-brand-700" role="status">Menyusun laporan…</p>}
        {error && <p className="mt-3 text-[13px] text-danger" role="alert">{error}</p>}
        {hasil && (
          <>
            <p className="mt-3 text-[13px] leading-relaxed text-ink whitespace-pre-line" data-testid="laporan-teks">{hasil.teks}</p>
            <p className="mt-2 text-xs text-brand-700" data-testid="laporan-sumber">{hasil.sumber === "ai" ? "Dijelaskan AI" : "Ringkasan otomatis"} · usulan bersifat saran, keputusan tetap di pengelola.</p>
          </>
        )}
      </div>
    </Card>
  );
}
