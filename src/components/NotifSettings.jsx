import { useEffect, useState } from "react";
import { Toggle } from "./ui/Field";
import { Button } from "./ui/Button";
import { useSettings } from "../context/SettingsContext";
import { useToast } from "../context/ToastContext";

const CHANNELS = [
  ["notify.push", "Notifikasi Push", "Terima instan di browser/web panel"],
  ["notify.email", "Email Berita", "Ringkasan harian kualitas lingkungan"],
  ["notify.sms", "Pesan SMS (Alert)", "Hanya untuk peringatan kritis"],
];
const CATS = [
  ["notify.cat.lingkungan", "Kualitas Udara & Lingkungan", "AQI kritis, indeks UV tinggi"],
  ["notify.cat.sensor", "Pemantauan Perangkat IoT", "Sensor mati, butuh kalibrasi perangkat"],
  ["notify.cat.pengguna", "Pendaftaran Pengguna Baru", "Pemberitahuan pendaftaran akun"],
  ["notify.cat.geofence", "Geofencing Wisata", "Pembaruan zona & area wisata"],
  ["notify.cat.festival", "Festival & Event", "Event baru ditambahkan / diubah"],
  ["notify.cat.mood", "Laporan Mood", "Rekap mood masyarakat"],
];

const Row = ({ title, desc, checked, onChange }) => (
  <div className="flex items-center justify-between gap-4 py-3.5 border-b border-brand-200 last:border-0">
    <div className="min-w-0"><p className="text-sm font-semibold text-brand-700">{title}</p><p className="text-xs text-muted mt-0.5">{desc}</p></div>
    <Toggle checked={checked} onChange={onChange} label={title} />
  </div>
);

/** Panel "Pengaturan Notifikasi" — dipakai di halaman Notifikasi dan tab Pengaturan → Notifikasi. */
export function NotifSettings({ bare = false }) {
  const { values, save } = useSettings();
  const toast = useToast();
  const keys = [...CHANNELS, ...CATS].map((r) => r[0]);
  const pick = () => Object.fromEntries(keys.map((k) => [k, values[k]]));
  const [draft, setDraft] = useState(pick);
  const [busy, setBusy] = useState(false);
  useEffect(() => { setDraft(pick()); /* eslint-disable-next-line */ }, [values]);
  const dirty = keys.some((k) => draft[k] !== values[k]);

  async function setPush(on) {
    if (on && typeof Notification !== "undefined" && Notification.permission !== "granted") {
      const p = Notification.permission === "denied" ? "denied" : await Notification.requestPermission();
      if (p !== "granted") { toast.error("Izin notifikasi browser ditolak. Aktifkan lewat pengaturan situs di browser."); return; }
    }
    setDraft((d) => ({ ...d, "notify.push": on }));
  }

  async function submit() {
    setBusy(true);
    try { await save(draft); toast.success("Konfigurasi notifikasi disimpan."); }
    catch (e) { toast.error(e.message); } finally { setBusy(false); }
  }

  return (
    <div className={bare ? "" : "bg-white rounded-2xl border border-brand-200 p-5"}>
      <h2 className="text-[17px] font-semibold text-brand-700">Pengaturan Notifikasi</h2>
      <p className="text-[13px] text-muted mt-1 pb-3 border-b border-brand-200">Kelola bagaimana dan di mana Anda menerima pesan alert sistem.</p>
      <p className="text-[11px] font-bold tracking-wide text-brand-700 mt-4 mb-1">SALURAN NOTIFIKASI</p>
      {CHANNELS.map(([k, t, d]) => <Row key={k} title={t} desc={d} checked={!!draft[k]} onChange={k === "notify.push" ? setPush : (v) => setDraft((s) => ({ ...s, [k]: v }))} />)}
      <p className="text-[11px] font-bold tracking-wide text-brand-700 mt-4 mb-1">KATEGORI BERLANGGANAN</p>
      {CATS.map(([k, t, d]) => <Row key={k} title={t} desc={d} checked={!!draft[k]} onChange={(v) => setDraft((s) => ({ ...s, [k]: v }))} />)}
      <p className="text-[11px] text-muted mt-3 leading-relaxed">Push browser aktif selama dashboard terbuka. Email &amp; SMS tersimpan sebagai preferensi — pengiriman butuh layanan email/SMS di server.</p>
      <Button className="w-full mt-4" loading={busy} disabled={!dirty} onClick={submit}>Simpan Konfigurasi</Button>
    </div>
  );
}
