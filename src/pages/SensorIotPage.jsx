import { useState } from "react";
import { Check, Copy, Cpu, Eye, KeyRound, Map as MapIcon, Pencil, Plus, Trash2, WifiOff, Wrench } from "lucide-react";
import { CircleMarker, Tooltip } from "react-leaflet";
import { PageHeader } from "../components/ui/PageHeader";
import { StatCard } from "../components/ui/StatCard";
import { Card, CardTitle } from "../components/ui/Card";
import { Badge } from "../components/ui/Badge";
import { Button, IconButton } from "../components/ui/Button";
import { ConfirmDialog, Modal } from "../components/ui/Modal";
import { Field, FilterSelect, Input, Select, Toggle } from "../components/ui/Field";
import { EmptyState, ErrorState, TableSkeleton } from "../components/ui/States";
import { FitTo, MapView } from "../components/map/MapView";
import { useSettings } from "../context/SettingsContext";
import { useAsync } from "../hooks/useAsync";
import { koridorLabel } from "../hooks/useEnvironment";
import { useToast } from "../context/ToastContext";
import { content, devices as api, geofences as zonesApi } from "../services/jejakwangi";
import { SENSOR_LABEL, SENSOR_UNIT, koridorPosition } from "../lib/env";
import { ago, formatTanggalJam, fmtNum, pct } from "../lib/format";

const STATUS = {
  aktif: { label: "Aktif", tone: "ok" },
  offline: { label: "Offline", tone: "danger" },
  perawatan: { label: "Perawatan", tone: "warn" },
  nonaktif: { label: "NonAktif", tone: "muted" },
};
const Th = ({ children }) => <th className="text-left text-xs font-bold text-brand-700 uppercase tracking-wide px-4 py-3.5 whitespace-nowrap">{children}</th>;

export default function SensorIotPage() {
  const toast = useToast();
  const [loc, setLoc] = useState("all");
  const devs = useAsync(() => api.list(), [], { interval: useSettings().values["sensor.auto_refresh_seconds"] * 1000 });
  const koridors = useAsync(() => content.koridors(), []);
  const [form, setForm] = useState(null);
  const [view, setView] = useState(null);
  const [del, setDel] = useState(null);
  const [busy, setBusy] = useState(false);
  const [showMap, setShowMap] = useState(false);
  const [keyInfo, setKeyInfo] = useState(null); // { code, key }

  const ks = koridors.data ?? [];
  const all = devs.data?.data ?? [];
  const list = loc === "all" ? all : all.filter((d) => String(d.koridor_id) === String(loc));
  const s = devs.data?.summary;

  async function remove() {
    setBusy(true);
    try { await api.remove(del.id); toast.success(`Sensor ${del.device_code} dihapus.`); setDel(null); devs.reload({ silent: true }); }
    catch (e) { toast.error(e.message); } finally { setBusy(false); }
  }

  return (
    <>
      <PageHeader title="Sensor IoT" chip="Live Network" subtitle="Manajemen dan monitoring status sensor IoT Jejak Wangi di seluruh wilayah Banyuwangi.">
        <FilterSelect label="Lokasi" value={loc} onChange={setLoc} options={[{ value: "all", label: "Semua Lokasi" }, ...ks.map((k) => ({ value: k.id, label: koridorLabel(k) }))]} />
        <Button icon={Plus} onClick={() => setForm({})}>Tambah Sensor</Button>
      </PageHeader>

      <div className="grid gap-4 grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 mb-4">
        <StatCard label="Total Sensor" icon={Cpu} iconColor="#0288d1" loading={!s && devs.loading} value={fmtNum(s?.total)} badge={{ text: "Sistem", tone: "info" }} sub={s ? `Terpasang di ${new Set(all.map((d) => d.koridor_id).filter(Boolean)).size} lokasi` : ""} />
        <StatCard label="Sensor Aktif" icon={Check} iconColor="#2e7d32" loading={!s && devs.loading} value={fmtNum(s?.aktif)} badge={s && { text: `${pct(s.aktif, s.total)}%`, tone: "ok" }} sub="Mengirim data dalam batas waktu" />
        <StatCard label="Sensor Offline" icon={WifiOff} iconColor="#d32f2f" loading={!s && devs.loading} value={fmtNum(s?.offline)} badge={{ text: "Siaga", tone: "danger" }} sub="Butuh re-koneksi segera" />
        <StatCard label="Perlu Perbaikan" icon={Wrench} iconColor="#ed6c02" loading={!s && devs.loading} value={fmtNum(s?.perawatan)} badge={{ text: "Urgent", tone: "warn" }} sub="Sedang dalam mode perawatan" />
      </div>

      <Card className="p-5">
        <CardTitle>Daftar Sensor Aktif & Offline</CardTitle>
        <div className="mt-4 overflow-x-auto">
          <table className="w-full text-sm min-w-[820px]">
            <thead className="bg-canvas"><tr><Th>Nama Sensor</Th><Th>Lokasi</Th><Th>Jenis Sensor</Th><Th>Status</Th><Th>Update Terakhir</Th><Th>Aksi</Th></tr></thead>
            {devs.loading && !devs.data ? <TableSkeleton cols={6} /> : (
              <tbody>
                {list.map((d) => (
                  <tr key={d.id} className="border-b border-brand-200/70 last:border-0 hover:bg-canvas/60">
                    <td className="px-4 py-3.5"><p className="font-semibold text-brand-700">{d.name || d.device_code}</p>{d.name && <p className="text-xs text-muted">{d.device_code}</p>}</td>
                    <td className="px-4 py-3.5 text-brand-700">{d.koridor_name ? d.koridor_name.split(":").pop().trim() : "—"}</td>
                    <td className="px-4 py-3.5"><div className="flex flex-wrap gap-1">{d.types.length ? d.types.slice(0, 2).map((t) => <span key={t} className="text-xs bg-brand-100 text-brand-700 rounded px-2 py-0.5">{SENSOR_LABEL[t] || t}</span>) : <span className="text-muted">Belum mengirim</span>}{d.types.length > 2 && <span className="text-xs text-muted">+{d.types.length - 2}</span>}</div></td>
                    <td className="px-4 py-3.5"><Badge tone={STATUS[d.status].tone}>{STATUS[d.status].label}</Badge></td>
                    <td className="px-4 py-3.5 text-brand-700 whitespace-nowrap">{ago(d.last_seen_at)}</td>
                    <td className="px-4 py-3.5"><div className="flex gap-1.5"><IconButton icon={Eye} tone="plain" label="Lihat detail" onClick={() => setView(d)} /><IconButton icon={Pencil} tone="plain" label="Ubah" onClick={() => setForm(d)} /></div></td>
                  </tr>
                ))}
              </tbody>
            )}
          </table>
          {devs.error && <ErrorState error={devs.error} onRetry={devs.reload} />}
          {!devs.error && devs.data && list.length === 0 && <EmptyState icon={Cpu} title="Belum ada sensor" message="Daftarkan perangkat ESP32 lewat tombol Tambah Sensor, lalu masukkan kunci API-nya ke firmware." />}
        </div>
      </Card>

      <div className="text-center mt-5">
        <button onClick={() => setShowMap(true)} className="inline-flex items-center gap-2 text-sm font-semibold text-brand-700 hover:underline"><MapIcon size={16} />Lihat Peta Sensor Geografis</button>
      </div>

      {form && <DeviceForm device={form.id ? form : null} koridors={ks} onDelete={form.id ? () => { setDel(form); setForm(null); } : null} onClose={() => setForm(null)} onSaved={(res) => { setForm(null); devs.reload({ silent: true }); if (res?.api_key) setKeyInfo({ code: res.data.device_code, key: res.api_key }); }} />}
      {view && <DeviceDetail d={view} onClose={() => setView(null)} onKey={(k) => { setKeyInfo(k); devs.reload({ silent: true }); }} />}
      <ConfirmDialog open={Boolean(del)} loading={busy} onClose={() => setDel(null)} onConfirm={remove} title="Hapus sensor?" confirmLabel="Hapus"
        message={<>Perangkat <b>{del?.device_code}</b> akan dihapus dan tidak bisa lagi mengirim data. Riwayat bacaan yang sudah tersimpan tetap ada.</>} />
      {keyInfo && <KeyModal info={keyInfo} onClose={() => setKeyInfo(null)} />}
      {showMap && <SensorMap devices={all} koridors={ks} onClose={() => setShowMap(false)} />}
    </>
  );
}

function DeviceForm({ device, koridors, onClose, onSaved, onDelete }) {
  const toast = useToast();
  const edit = Boolean(device);
  const [f, setF] = useState({ device_code: device?.device_code ?? "", name: device?.name ?? "", koridor_id: device?.koridor_id ?? "", is_active: device?.is_active ?? true, is_maintenance: device?.is_maintenance ?? false });
  const [errors, setErrors] = useState({});
  const [busy, setBusy] = useState(false);
  const set = (k) => (v) => setF((s) => ({ ...s, [k]: v }));

  async function submit() {
    const err = {};
    if (!edit && !/^[A-Za-z0-9_.-]{3,100}$/.test(f.device_code)) err.device_code = "3–100 karakter: huruf, angka, titik, minus, garis bawah (contoh: ESP32-KOR1-DIORAMA).";
    setErrors(err);
    if (Object.keys(err).length) return;
    setBusy(true);
    try {
      const body = { name: f.name || null, koridor_id: f.koridor_id || null, is_active: f.is_active, is_maintenance: f.is_maintenance };
      const res = edit ? await api.update(device.id, body) : await api.create({ ...body, device_code: f.device_code });
      toast.success(edit ? "Sensor diperbarui." : "Sensor terdaftar.");
      onSaved(res);
    } catch (e) {
      if (e.errors) setErrors(Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]]))); else toast.error(e.message);
    } finally { setBusy(false); }
  }

  return (
    <Modal open onClose={onClose} title={edit ? "Ubah Sensor" : "Tambah Sensor"} subtitle={edit ? device.device_code : "Kunci API dibuat otomatis dan hanya ditampilkan sekali."}
      footer={<>{onDelete && <Button variant="danger" icon={Trash2} className="mr-auto" onClick={onDelete}>Hapus</Button>}<Button variant="secondary" onClick={onClose}>Batal</Button><Button loading={busy} onClick={submit}>{edit ? "Simpan" : "Daftarkan Sensor"}</Button></>}>
      <div className="space-y-4">
        <Field label="Kode perangkat" required error={errors.device_code} htmlFor="d-code" hint={edit ? "Kode tidak bisa diubah (dipakai firmware)." : undefined}>
          <Input id="d-code" value={f.device_code} disabled={edit} onChange={(e) => set("device_code")(e.target.value)} invalid={!!errors.device_code} placeholder="ESP32-KOR1-DIORAMA" autoFocus />
        </Field>
        <Field label="Nama tampilan" htmlFor="d-name" error={errors.name}><Input id="d-name" value={f.name} onChange={(e) => set("name")(e.target.value)} placeholder="mis. AQI - Taman Blambangan" /></Field>
        <Field label="Lokasi / koridor" htmlFor="d-kor" error={errors.koridor_id}>
          <Select id="d-kor" value={f.koridor_id} onChange={(e) => set("koridor_id")(e.target.value)}><option value="">— Belum ditentukan —</option>{koridors.map((k) => <option key={k.id} value={k.id}>{k.formal_name}</option>)}</Select>
        </Field>
        <div className="grid sm:grid-cols-2 gap-4">
          <Field label="Perangkat aktif" hint="Nonaktif = ditolak saat mengirim data.">
            <div className="flex items-center gap-3 h-11"><Toggle checked={f.is_active} onChange={set("is_active")} label="Aktif" /><span className="text-sm text-brand-700">{f.is_active ? "Aktif" : "NonAktif"}</span></div>
          </Field>
          <Field label="Mode perawatan" hint="Ditandai 'Perlu Perbaikan'.">
            <div className="flex items-center gap-3 h-11"><Toggle checked={f.is_maintenance} onChange={set("is_maintenance")} label="Perawatan" /><span className="text-sm text-brand-700">{f.is_maintenance ? "Ya" : "Tidak"}</span></div>
          </Field>
        </div>
      </div>
    </Modal>
  );
}

function DeviceDetail({ d, onClose, onKey }) {
  const toast = useToast();
  const [confirm, setConfirm] = useState(false);
  const [busy, setBusy] = useState(false);
  const entries = Object.entries(d.readings || {});

  async function rotate() {
    setBusy(true);
    try { const r = await api.rotateKey(d.id); setConfirm(false); onClose(); onKey({ code: d.device_code, key: r.api_key }); }
    catch (e) { toast.error(e.message); } finally { setBusy(false); }
  }

  return (
    <Modal open onClose={onClose} size="lg" title={d.name || d.device_code} subtitle={d.device_code}
      footer={<><Button variant="secondary" icon={KeyRound} onClick={() => setConfirm(true)}>Putar Kunci API</Button><Button onClick={onClose}>Tutup</Button></>}>
      <div className="flex flex-wrap gap-x-8 gap-y-2 text-sm mb-5">
        <p><span className="text-muted">Status: </span><Badge tone={STATUS[d.status].tone}>{STATUS[d.status].label}</Badge></p>
        <p><span className="text-muted">Lokasi: </span><b className="text-brand-700">{d.koridor_name || "—"}</b></p>
        <p><span className="text-muted">Terakhir aktif: </span><b className="text-brand-700">{d.last_seen_at ? formatTanggalJam(d.last_seen_at) : "Belum pernah"}</b></p>
      </div>
      <p className="text-sm font-semibold text-brand-700 mb-2">Bacaan terakhir per jenis sensor</p>
      {entries.length === 0 ? <EmptyState title="Belum ada bacaan" message="Perangkat belum pernah mengirim data." /> : (
        <div className="grid gap-3 grid-cols-2 md:grid-cols-3">
          {entries.map(([type, r]) => (
            <div key={type} className="rounded-xl bg-canvas px-4 py-3">
              <p className="text-xs text-muted">{SENSOR_LABEL[type] || type}</p>
              <p className="text-xl font-bold text-brand-700 mt-0.5">{fmtNum(r.value, 1)} <span className="text-xs font-medium text-muted">{r.unit || SENSOR_UNIT[type]}</span></p>
              <p className="text-[11px] text-muted mt-1">{ago(r.recorded_at)}</p>
            </div>
          ))}
        </div>
      )}
      <ConfirmDialog open={confirm} loading={busy} onClose={() => setConfirm(false)} onConfirm={rotate} tone="primary" title="Putar kunci API?" confirmLabel="Putar Kunci"
        message="Kunci lama langsung tidak berlaku. Firmware ESP32 harus diperbarui dengan kunci baru agar bisa mengirim data lagi." />
    </Modal>
  );
}

function KeyModal({ info, onClose }) {
  const [copied, setCopied] = useState(false);
  const copy = async () => { try { await navigator.clipboard.writeText(info.key); setCopied(true); setTimeout(() => setCopied(false), 2000); } catch { /* clipboard diblokir */ } };
  return (
    <Modal open onClose={onClose} dismissable={false} title="Simpan kunci API sekarang" subtitle={`Perangkat ${info.code}`} footer={<Button onClick={onClose}>Sudah saya simpan</Button>}>
      <p className="text-sm text-brand-800 mb-3">Kunci ini <b>hanya ditampilkan sekali</b> dan tidak bisa dilihat lagi. Masukkan ke firmware ESP32 sebagai header <code className="bg-canvas px-1.5 py-0.5 rounded">X-Device-Key</code>.</p>
      <div className="flex items-center gap-2 bg-canvas border border-brand-200 rounded-xl p-3">
        <code className="flex-1 text-[13px] break-all text-brand-800" data-testid="api-key">{info.key}</code>
        <Button variant="secondary" size="sm" icon={copied ? Check : Copy} onClick={copy}>{copied ? "Tersalin" : "Salin"}</Button>
      </div>
    </Modal>
  );
}

function SensorMap({ devices, koridors, onClose }) {
  const zones = useAsync(() => zonesApi.list().catch(() => []), []);
  const gs = useAsync(() => content.greenSpaces().catch(() => []), []);
  const pts = devices.map((d) => {
    const k = koridors.find((x) => String(x.id) === String(d.koridor_id));
    return { d, pos: k ? koridorPosition(k, gs.data ?? [], zones.data ?? []) : null };
  }).filter((p) => p.pos);
  const color = { aktif: "#2e7d32", offline: "#d32f2f", perawatan: "#ed6c02", nonaktif: "#8fa38f" };
  return (
    <Modal open onClose={onClose} size="xl" title="Peta Sensor Geografis" subtitle="Posisi mengikuti lokasi/koridor tiap perangkat." footer={<Button onClick={onClose}>Tutup</Button>}>
      <MapView height={420}>
        <FitTo points={pts.map((p) => p.pos)} maxZoom={16} />
        {pts.map(({ d, pos }) => (
          <CircleMarker key={d.id} center={pos} radius={11} pathOptions={{ color: "#fff", weight: 3, fillColor: color[d.status], fillOpacity: 1 }}>
            <Tooltip direction="top" permanent={false}>{d.name || d.device_code} — {STATUS[d.status].label}</Tooltip>
          </CircleMarker>
        ))}
      </MapView>
      {pts.length === 0 && <p className="text-sm text-muted mt-3">Belum ada sensor yang punya koordinat. Tetapkan lokasi/koridor pada sensor yang koridornya punya jalur atau terhubung ke taman/zona.</p>}
      <div className="flex gap-4 mt-3 text-xs text-brand-700">{Object.entries(STATUS).map(([k, v]) => <span key={k} className="inline-flex items-center gap-1.5"><span className="h-2.5 w-2.5 rounded-full" style={{ background: color[k] }} />{v.label}</span>)}</div>
    </Modal>
  );
}
