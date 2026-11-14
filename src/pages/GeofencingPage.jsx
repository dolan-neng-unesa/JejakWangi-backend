import { useMemo, useState } from "react";
import { Circle, CircleMarker, Tooltip, useMapEvents } from "react-leaflet";
import { Eye, Pencil, Plus, Trash2, MapPin } from "lucide-react";
import { PageHeader } from "../components/ui/PageHeader";
import { Card, CardTitle } from "../components/ui/Card";
import { Badge } from "../components/ui/Badge";
import { Button, IconButton } from "../components/ui/Button";
import { ConfirmDialog, Modal } from "../components/ui/Modal";
import { Field, FilterSelect, Input, Select, Textarea, Toggle } from "../components/ui/Field";
import { EmptyState, ErrorState, TableSkeleton } from "../components/ui/States";
import { FitTo, MAP_CENTER, MapView } from "../components/map/MapView";
import { useAsync } from "../hooks/useAsync";
import { koridorLabel } from "../hooks/useEnvironment";
import { useToast } from "../context/ToastContext";
import { useSettings } from "../context/SettingsContext";
import { content, geofences as api } from "../services/jejakwangi";
import { circleAreaHa } from "../lib/geo";
import { fmtNum } from "../lib/format";

const CAT = { gamelan_using: "Gamelan Using", alam: "Suara Alam" };
const Th = ({ children }) => <th className="text-left text-xs font-bold text-brand-700 uppercase tracking-wide px-4 py-3.5 whitespace-nowrap">{children}</th>;

/** Jenis konten broadcast diturunkan dari isi zona: audio (soundscape) dan/atau info (pesan sambutan). */
export function broadcastType(z, soundscapes) {
  const audio = z.soundscape_id ? soundscapes.find((s) => s.id === z.soundscape_id) : null;
  const info = Boolean(z.welcome_title || z.welcome_desc);
  const parts = [audio && "Audio", info && "Info"].filter(Boolean);
  return { label: parts.length ? parts.join(" & ") : "—", audio };
}

export default function GeofencingPage() {
  const toast = useToast();
  const [filter, setFilter] = useState("");
  const zones = useAsync(() => api.list(), []);
  const refs = useAsync(async () => {
    const [soundscapes, koridors] = await Promise.all([content.soundscapes().catch(() => []), content.koridors().catch(() => [])]);
    return { soundscapes, koridors };
  }, []);
  const [form, setForm] = useState(null);
  const [view, setView] = useState(null);
  const [del, setDel] = useState(null);
  const [busy, setBusy] = useState(false);
  const [focus, setFocus] = useState(null);

  const sounds = refs.data?.soundscapes ?? [];
  const koridors = refs.data?.koridors ?? [];
  const all = zones.data ?? [];
  const list = all.filter((z) => (filter === "" ? true : filter === "aktif" ? z.is_active : !z.is_active));
  const pts = useMemo(() => list.map((z) => [z.latitude, z.longitude]), [list]);
  const lokasi = (z) => { const k = koridors.find((x) => String(x.id) === String(z.koridor_id)); return k ? koridorLabel(k) : "Banyuwangi Kota"; };

  async function remove() {
    setBusy(true);
    try { await api.remove(del.id); toast.success(`Zona "${del.name}" dihapus.`); setDel(null); zones.reload({ silent: true }); }
    catch (e) { toast.error(e.message); } finally { setBusy(false); }
  }
  async function toggleActive(z) {
    try { await api.update(z.id, { is_active: !z.is_active }); toast.success(`Zona ${z.name} ${z.is_active ? "dinonaktifkan" : "diaktifkan"}.`); zones.reload({ silent: true }); }
    catch (e) { toast.error(e.message); }
  }

  return (
    <>
      <PageHeader title="Geofencing" chip="Spatial Zones" subtitle="Pemetaan wilayah geofence aktif untuk pengiriman notifikasi berbasis lokasi (mood, audio, dan festival info).">
        <FilterSelect label="Status" value={filter} onChange={setFilter} options={[{ value: "", label: "Semua Zona" }, { value: "aktif", label: "Aktif" }, { value: "nonaktif", label: "NonAktif" }]} />
        <Button icon={Plus} onClick={() => setForm({})}>Tambah Zona</Button>
      </PageHeader>

      <Card className="p-5 mb-4">
        <CardTitle>Manajemen Zona Geofence</CardTitle>
        <div className="mt-4 overflow-x-auto">
          <table className="w-full text-sm min-w-[780px]">
            <thead className="bg-canvas"><tr><Th>Nama Zona</Th><Th>Lokasi</Th><Th>Luas Wilayah</Th><Th>Status</Th><Th>Tipe Konten Broadcast</Th><Th>Aksi</Th></tr></thead>
            {zones.loading && !zones.data ? <TableSkeleton cols={6} /> : (
              <tbody>
                {list.map((z) => (
                  <tr key={z.id} className={`border-b border-brand-200/70 last:border-0 hover:bg-canvas/60 ${focus === z.id ? "bg-brand-50" : ""}`}>
                    <td className="px-4 py-3.5 font-semibold text-brand-700">{z.name}</td>
                    <td className="px-4 py-3.5 text-brand-700">{lokasi(z)}</td>
                    <td className="px-4 py-3.5 text-brand-700">{fmtNum(circleAreaHa(z.radius_meter), 1)} ha <span className="text-xs text-muted">(r {z.radius_meter} m)</span></td>
                    <td className="px-4 py-3.5">
                      <button onClick={() => toggleActive(z)} title="Klik untuk mengubah status" className="rounded-md"><Badge tone={z.is_active ? "ok" : "danger"}>{z.is_active ? "Aktif" : "NonAktif"}</Badge></button>
                    </td>
                    <td className="px-4 py-3.5 text-brand-700" title={broadcastType(z, sounds).audio?.title}>{broadcastType(z, sounds).label}</td>
                    <td className="px-4 py-3.5"><div className="flex gap-1.5">
                      <IconButton icon={Eye} tone="plain" label="Lihat detail" onClick={() => { setFocus(z.id); setView(z); }} />
                      <IconButton icon={Pencil} tone="plain" label="Ubah" onClick={() => setForm(z)} />
                      <IconButton icon={Trash2} tone="danger" label="Hapus" onClick={() => setDel(z)} />
                    </div></td>
                  </tr>
                ))}
              </tbody>
            )}
          </table>
          {zones.error && <ErrorState error={zones.error} onRetry={zones.reload} />}
          {!zones.error && zones.data && list.length === 0 && <EmptyState icon={MapPin} title="Belum ada zona" message="Tambahkan zona agar aplikasi warga dapat memutar audio & pesan sambutan saat mereka masuk area." />}
        </div>
      </Card>

      <Card className="p-5">
        <CardTitle>Peta Geofencing & Cakupan Notifikasi Wilayah</CardTitle>
        <div className="mt-4">
          <MapView height={380} dark zoom={15}>
            <FitTo points={pts} maxZoom={16} padding={[60, 60]} />
            {list.map((z) => (
              <Circle key={z.id} center={[z.latitude, z.longitude]} radius={z.radius_meter}
                eventHandlers={{ click: () => { setFocus(z.id); setView(z); } }}
                pathOptions={{ color: z.is_active ? "#4ade80" : "#ef4444", weight: focus === z.id ? 4 : 2, dashArray: z.is_active ? undefined : "6 6", fillColor: z.is_active ? "#22c55e" : "#ef4444", fillOpacity: 0.2 }}>
                <Tooltip permanent direction="center" className="!bg-transparent !border-0 !shadow-none !text-white !font-semibold !text-[11px]">{z.name}</Tooltip>
              </Circle>
            ))}
          </MapView>
        </div>
        <div className="flex gap-5 mt-3 text-xs text-brand-700"><span className="inline-flex items-center gap-1.5"><span className="h-2.5 w-2.5 rounded-full bg-[#22c55e]" />Zona aktif</span><span className="inline-flex items-center gap-1.5"><span className="h-2.5 w-2.5 rounded-full bg-[#ef4444]" />Zona nonaktif</span><span className="text-muted">Klik lingkaran untuk detail.</span></div>
      </Card>

      {form && <ZoneForm zone={form.id ? form : null} sounds={sounds} koridors={koridors} onClose={() => setForm(null)} onSaved={() => { setForm(null); zones.reload({ silent: true }); }} />}
      {view && <ZoneDetail z={view} lokasi={lokasi(view)} sounds={sounds} onClose={() => setView(null)} onEdit={() => { setForm(view); setView(null); }} />}
      <ConfirmDialog open={Boolean(del)} loading={busy} onClose={() => setDel(null)} onConfirm={remove} title="Hapus zona?" confirmLabel="Hapus"
        message={<>Zona <b>{del?.name}</b> akan dihapus. Aplikasi warga tidak lagi memicu notifikasi di area ini.</>} />
    </>
  );
}

function ClickPicker({ onPick }) {
  useMapEvents({ click: (e) => onPick(+e.latlng.lat.toFixed(6), +e.latlng.lng.toFixed(6)) });
  return null;
}

function ZoneForm({ zone, sounds, koridors, onClose, onSaved }) {
  const toast = useToast();
  const { values } = useSettings();
  const edit = Boolean(zone);
  const [f, setF] = useState({
    name: zone?.name ?? "", latitude: zone?.latitude ?? MAP_CENTER[0], longitude: zone?.longitude ?? MAP_CENTER[1],
    radius_meter: zone?.radius_meter ?? values["geofence.default_radius"], is_active: zone?.is_active ?? values["geofence.default_active"],
    soundscape_id: zone?.soundscape_id ?? "", koridor_id: zone?.koridor_id ?? "", welcome_title: zone?.welcome_title ?? "", welcome_desc: zone?.welcome_desc ?? "",
  });
  const [errors, setErrors] = useState({});
  const [busy, setBusy] = useState(false);
  const set = (k) => (v) => setF((s) => ({ ...s, [k]: v }));
  const lat = Number(f.latitude), lng = Number(f.longitude), r = Number(f.radius_meter);
  const validPos = Number.isFinite(lat) && Number.isFinite(lng) && Math.abs(lat) <= 90 && Math.abs(lng) <= 180;

  async function submit() {
    const err = {};
    if (!f.name.trim()) err.name = "Nama zona wajib diisi.";
    if (!validPos) err.latitude = "Koordinat tidak valid (lat −90…90, lng −180…180).";
    if (!Number.isInteger(r) || r < 1) err.radius_meter = "Radius harus bilangan bulat ≥ 1 meter.";
    setErrors(err);
    if (Object.keys(err).length) return;
    setBusy(true);
    try {
      const body = { name: f.name.trim(), latitude: lat, longitude: lng, radius_meter: r, is_active: f.is_active,
        soundscape_id: f.soundscape_id ? Number(f.soundscape_id) : null, koridor_id: f.koridor_id ? Number(f.koridor_id) : null,
        welcome_title: f.welcome_title || null, welcome_desc: f.welcome_desc || null };
      if (edit) await api.update(zone.id, body); else await api.create(body);
      toast.success(edit ? "Zona diperbarui." : "Zona ditambahkan.");
      onSaved();
    } catch (e) {
      if (e.errors) setErrors(Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]]))); else toast.error(e.message);
    } finally { setBusy(false); }
  }

  return (
    <Modal open onClose={onClose} size="lg" title={edit ? "Ubah Zona Geofence" : "Tambah Zona Geofence"} subtitle="Klik peta untuk menentukan titik pusat zona."
      footer={<><Button variant="secondary" onClick={onClose}>Batal</Button><Button loading={busy} onClick={submit}>{edit ? "Simpan Perubahan" : "Tambah Zona"}</Button></>}>
      <div className="grid gap-5 md:grid-cols-2">
        <div className="space-y-4">
          <Field label="Nama zona" required error={errors.name} htmlFor="z-name"><Input id="z-name" value={f.name} onChange={(e) => set("name")(e.target.value)} invalid={!!errors.name} autoFocus /></Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Latitude" error={errors.latitude} htmlFor="z-lat"><Input id="z-lat" inputMode="decimal" value={f.latitude} onChange={(e) => set("latitude")(e.target.value)} invalid={!!errors.latitude} /></Field>
            <Field label="Longitude" htmlFor="z-lng"><Input id="z-lng" inputMode="decimal" value={f.longitude} onChange={(e) => set("longitude")(e.target.value)} /></Field>
          </div>
          <Field label="Radius (meter)" error={errors.radius_meter} htmlFor="z-r" hint={Number.isFinite(r) && r > 0 ? `Luas ± ${fmtNum(circleAreaHa(r), 2)} ha` : undefined}><Input id="z-r" type="number" min="1" value={f.radius_meter} onChange={(e) => set("radius_meter")(e.target.value)} invalid={!!errors.radius_meter} /></Field>
          <Field label="Koridor terkait" htmlFor="z-kor" error={errors.koridor_id}><Select id="z-kor" value={f.koridor_id} onChange={(e) => set("koridor_id")(e.target.value)}><option value="">— Tidak ada —</option>{koridors.map((k) => <option key={k.id} value={k.id}>{k.formal_name}</option>)}</Select></Field>
          <Field label="Audio soundscape" htmlFor="z-snd" error={errors.soundscape_id}><Select id="z-snd" value={f.soundscape_id} onChange={(e) => set("soundscape_id")(e.target.value)}><option value="">— Tanpa audio —</option>{sounds.map((s) => <option key={s.id} value={s.id}>{s.title} ({CAT[s.category] || s.category}, {s.duration})</option>)}</Select></Field>
        </div>
        <div className="space-y-4">
          <div>
            <p className="text-[13px] font-semibold text-brand-700 mb-1.5">Titik pusat</p>
            <MapView height={230} zoom={16} center={validPos ? [lat, lng] : MAP_CENTER}>
              <ClickPicker onPick={(la, ln) => setF((s) => ({ ...s, latitude: la, longitude: ln }))} />
              {validPos && <><Circle center={[lat, lng]} radius={Math.max(1, r || 1)} pathOptions={{ color: "#3f6e45", fillOpacity: 0.2 }} /><CircleMarker center={[lat, lng]} radius={6} pathOptions={{ color: "#fff", weight: 2, fillColor: "#3f6e45", fillOpacity: 1 }} /></>}
            </MapView>
          </div>
          <Field label="Judul pesan sambutan" htmlFor="z-wt"><Input id="z-wt" value={f.welcome_title} onChange={(e) => set("welcome_title")(e.target.value)} placeholder="Selamat datang di …!" /></Field>
          <Field label="Deskripsi pesan" htmlFor="z-wd"><Textarea id="z-wd" rows={3} value={f.welcome_desc} onChange={(e) => set("welcome_desc")(e.target.value)} /></Field>
          <div className="flex items-center gap-3"><Toggle checked={f.is_active} onChange={set("is_active")} label="Zona aktif" /><span className="text-sm text-brand-700">{f.is_active ? "Zona aktif" : "Zona nonaktif"}</span></div>
        </div>
      </div>
    </Modal>
  );
}

function ZoneDetail({ z, lokasi, sounds, onClose, onEdit }) {
  const t = broadcastType(z, sounds);
  return (
    <Modal open onClose={onClose} size="lg" title={z.name} subtitle={`${lokasi} · ${z.latitude.toFixed(5)}, ${z.longitude.toFixed(5)}`}
      footer={<><Button variant="secondary" onClick={onClose}>Tutup</Button><Button icon={Pencil} onClick={onEdit}>Ubah</Button></>}>
      <div className="grid gap-5 md:grid-cols-2">
        <MapView height={240} zoom={16} center={[z.latitude, z.longitude]}>
          <Circle center={[z.latitude, z.longitude]} radius={z.radius_meter} pathOptions={{ color: z.is_active ? "#3f6e45" : "#d32f2f", fillOpacity: 0.2 }} />
        </MapView>
        <dl className="text-sm space-y-3">
          <div className="flex justify-between"><dt className="text-muted">Status</dt><dd><Badge tone={z.is_active ? "ok" : "danger"}>{z.is_active ? "Aktif" : "NonAktif"}</Badge></dd></div>
          <div className="flex justify-between"><dt className="text-muted">Radius / luas</dt><dd className="font-semibold text-brand-700">{z.radius_meter} m · {fmtNum(circleAreaHa(z.radius_meter), 1)} ha</dd></div>
          <div className="flex justify-between"><dt className="text-muted">Konten broadcast</dt><dd className="font-semibold text-brand-700">{t.label}</dd></div>
          <div className="flex justify-between gap-3"><dt className="text-muted">Audio</dt><dd className="font-semibold text-brand-700 text-right">{t.audio ? `${t.audio.title} (${t.audio.duration})` : "—"}</dd></div>
          <div><dt className="text-muted mb-1">Pesan sambutan</dt><dd className="rounded-lg bg-canvas p-3 text-brand-800">{z.welcome_title ? <><b>{z.welcome_title}</b><br />{z.welcome_desc}</> : "—"}</dd></div>
        </dl>
      </div>
    </Modal>
  );
}
