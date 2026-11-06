import { useEffect, useState } from "react";
import { CalendarDays, ChevronRight, Cpu, Download, Mail, MapPin } from "lucide-react";
import { PageHeader } from "../components/ui/PageHeader";
import { Card, CardTitle } from "../components/ui/Card";
import { Badge } from "../components/ui/Badge";
import { Button } from "../components/ui/Button";
import { ConfirmDialog, Modal } from "../components/ui/Modal";
import { Field, Input, Select, Textarea, Toggle } from "../components/ui/Field";
import { Tabs } from "../components/ui/Tabs";
import { NotifSettings } from "../components/NotifSettings";
import { useSettings } from "../context/SettingsContext";
import { useAuth } from "../context/AuthContext";
import { useToast } from "../context/ToastContext";
import { settings as settingsApi, users } from "../services/svarga";
import { TZ_LABEL, formatTanggalJam, formatTanggal } from "../lib/format";

const TABS = ["Profil", "Aplikasi", "Sensor IoT", "Geofencing", "Notifikasi", "Integrasi"].map((l) => ({ value: l, label: l }));

export default function SettingsPage() {
  const [tab, setTab] = useState("Integrasi");
  return (
    <>
      <PageHeader title="Pengaturan" chip={formatTanggal(new Date())} subtitle="Kelola preferensi sistem, data sensor, integrasi modul eksternal, dan konfigurasi data backup." showRegion />
      <Tabs variant="underline" tabs={TABS} value={tab} onChange={setTab} className="mb-5" />
      <div className="grid gap-4 grid-cols-1 xl:grid-cols-[1fr_340px] items-start">
        <div>
          {tab === "Profil" && <ProfileTab />}
          {tab === "Aplikasi" && <AppTab />}
          {tab === "Sensor IoT" && <SensorTab />}
          {tab === "Geofencing" && <GeofenceTab />}
          {tab === "Notifikasi" && <Card className="p-5"><NotifSettings bare /></Card>}
          {tab === "Integrasi" && <IntegrationTab />}
        </div>
        <div className="space-y-4"><SystemCard /><BackupCard /></div>
      </div>
    </>
  );
}

/** Form kecil yang menyimpan sebagian kunci pengaturan. */
function useSection(keys) {
  const { values, save } = useSettings();
  const toast = useToast();
  const [d, setD] = useState(() => Object.fromEntries(keys.map((k) => [k, values[k]])));
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState({});
  useEffect(() => { setD(Object.fromEntries(keys.map((k) => [k, values[k]]))); /* eslint-disable-next-line */ }, [values]);
  const set = (k) => (v) => setD((s) => ({ ...s, [k]: v }));
  const dirty = keys.some((k) => d[k] !== values[k]);
  async function submit(validate) {
    const e = validate?.(d) ?? {};
    setErr(e);
    if (Object.keys(e).length) return;
    setBusy(true);
    try { await save(d); toast.success("Pengaturan disimpan."); } catch (x) { if (x.errors) setErr(Object.fromEntries(Object.entries(x.errors).map(([k, v]) => [k.replace("settings.", ""), v[0]]))); else toast.error(x.message); }
    finally { setBusy(false); }
  }
  return { d, set, dirty, busy, err, submit };
}

const SaveBar = ({ s, validate }) => <div className="pt-2"><Button loading={s.busy} disabled={!s.dirty} onClick={() => s.submit(validate)}>Simpan Pengaturan</Button></div>;
const num = (v, lo, hi) => (Number.isInteger(Number(v)) && Number(v) >= lo && Number(v) <= hi ? null : `Isi bilangan bulat ${lo}–${hi}.`);

function ProfileTab() {
  const { user, setUser } = useAuth();
  const toast = useToast();
  const [f, setF] = useState({ name: user.name, email: user.email, password: "", confirm: "" });
  const [err, setErr] = useState({});
  const [busy, setBusy] = useState(false);
  const set = (k) => (e) => setF((s) => ({ ...s, [k]: e.target.value }));
  async function submit() {
    const e = {};
    if (!f.name.trim()) e.name = "Nama wajib diisi.";
    if (!/^\S+@\S+\.\S+$/.test(f.email)) e.email = "Email tidak valid.";
    if (f.password && f.password.length < 8) e.password = "Minimal 8 karakter.";
    if (f.password !== f.confirm) e.confirm = "Konfirmasi tidak sama.";
    setErr(e);
    if (Object.keys(e).length) return;
    setBusy(true);
    try {
      const body = { name: f.name.trim(), email: f.email.trim() };
      if (f.password) body.password = f.password;
      const r = await users.update(user.id, body);
      setUser({ ...user, name: r.data.nama, email: r.data.email });
      setF((s) => ({ ...s, password: "", confirm: "" }));
      toast.success("Profil diperbarui.");
    } catch (x) { if (x.errors) setErr(Object.fromEntries(Object.entries(x.errors).map(([k, v]) => [k, v[0]]))); else toast.error(x.message); }
    finally { setBusy(false); }
  }
  return (
    <Card className="p-6 space-y-4 max-w-2xl">
      <CardTitle>Profil Admin</CardTitle>
      <Field label="Nama" error={err.name} htmlFor="p-n"><Input id="p-n" value={f.name} onChange={set("name")} invalid={!!err.name} /></Field>
      <Field label="Email" error={err.email} htmlFor="p-e"><Input id="p-e" type="email" value={f.email} onChange={set("email")} invalid={!!err.email} /></Field>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Kata sandi baru" error={err.password} hint="Kosongkan bila tidak diubah." htmlFor="p-p"><Input id="p-p" type="password" autoComplete="new-password" value={f.password} onChange={set("password")} invalid={!!err.password} /></Field>
        <Field label="Ulangi kata sandi" error={err.confirm} htmlFor="p-c"><Input id="p-c" type="password" autoComplete="new-password" value={f.confirm} onChange={set("confirm")} invalid={!!err.confirm} /></Field>
      </div>
      <Button loading={busy} onClick={submit}>Simpan Profil</Button>
    </Card>
  );
}

function AppTab() {
  const s = useSection(["app.name", "app.region_label", "app.timezone"]);
  return (
    <Card className="p-6 space-y-4 max-w-2xl">
      <CardTitle>Pengaturan Aplikasi</CardTitle>
      <Field label="Nama aplikasi" error={s.err["settings.app.name"] || s.err["app.name"]} htmlFor="a-n"><Input id="a-n" value={s.d["app.name"]} maxLength={30} onChange={(e) => s.set("app.name")(e.target.value)} /></Field>
      <Field label="Label wilayah (chip pojok kanan atas)" htmlFor="a-r"><Input id="a-r" value={s.d["app.region_label"]} maxLength={40} onChange={(e) => s.set("app.region_label")(e.target.value)} /></Field>
      <Field label="Zona waktu tampilan" htmlFor="a-t" hint="Dipakai untuk semua tanggal & jam di dashboard."><Select id="a-t" value={s.d["app.timezone"]} onChange={(e) => s.set("app.timezone")(e.target.value)}>{Object.entries(TZ_LABEL).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</Select></Field>
      <Field label="Bahasa"><Input value="Bahasa Indonesia" disabled /></Field>
      <SaveBar s={s} validate={(d) => (d["app.name"].trim() ? {} : { "app.name": "Nama wajib diisi." })} />
    </Card>
  );
}

function SensorTab() {
  const s = useSection(["sensor.offline_minutes", "sensor.retention_days", "sensor.auto_refresh_seconds"]);
  const v = (d) => Object.fromEntries([["sensor.offline_minutes", num(d["sensor.offline_minutes"], 1, 120)], ["sensor.retention_days", num(d["sensor.retention_days"], 1, 365)], ["sensor.auto_refresh_seconds", num(d["sensor.auto_refresh_seconds"], 10, 300)]].filter(([, e]) => e));
  const err = (k) => s.err[k] || s.err[`settings.${k}`];
  return (
    <Card className="p-6 space-y-4 max-w-2xl">
      <CardTitle>Pengaturan Sensor IoT</CardTitle>
      <Field label="Batas sensor dianggap Offline (menit)" error={err("sensor.offline_minutes")} hint="Perangkat yang tidak mengirim data lebih lama dari ini berstatus Offline." htmlFor="s1"><Input id="s1" type="number" value={s.d["sensor.offline_minutes"]} onChange={(e) => s.set("sensor.offline_minutes")(e.target.value === "" ? "" : Number(e.target.value))} /></Field>
      <Field label="Simpan riwayat bacaan (hari)" error={err("sensor.retention_days")} hint="Bacaan lebih lama dihapus otomatis oleh jadwal harian sensors:prune." htmlFor="s2"><Input id="s2" type="number" value={s.d["sensor.retention_days"]} onChange={(e) => s.set("sensor.retention_days")(e.target.value === "" ? "" : Number(e.target.value))} /></Field>
      <Field label="Muat ulang otomatis dashboard (detik)" error={err("sensor.auto_refresh_seconds")} hint="Interval polling data lingkungan & sensor di halaman dashboard." htmlFor="s3"><Input id="s3" type="number" value={s.d["sensor.auto_refresh_seconds"]} onChange={(e) => s.set("sensor.auto_refresh_seconds")(e.target.value === "" ? "" : Number(e.target.value))} /></Field>
      <SaveBar s={s} validate={v} />
    </Card>
  );
}

function GeofenceTab() {
  const s = useSection(["geofence.default_radius", "geofence.default_active"]);
  return (
    <Card className="p-6 space-y-4 max-w-2xl">
      <CardTitle>Pengaturan Geofencing</CardTitle>
      <Field label="Radius bawaan zona baru (meter)" error={s.err["geofence.default_radius"]} htmlFor="g1"><Input id="g1" type="number" value={s.d["geofence.default_radius"]} onChange={(e) => s.set("geofence.default_radius")(e.target.value === "" ? "" : Number(e.target.value))} /></Field>
      <Field label="Zona baru langsung aktif"><div className="flex items-center gap-3 h-11"><Toggle checked={!!s.d["geofence.default_active"]} onChange={s.set("geofence.default_active")} label="Aktif bawaan" /><span className="text-sm text-brand-700">{s.d["geofence.default_active"] ? "Ya" : "Tidak"}</span></div></Field>
      <SaveBar s={s} validate={(d) => { const e = num(d["geofence.default_radius"], 1, 5000); return e ? { "geofence.default_radius": e } : {}; }} />
    </Card>
  );
}

const INTEGRATIONS = [
  { key: "festival", icon: CalendarDays, title: "Integrasi Kalender Festival BWI", desc: "Sinkronisasi event festival dan sistem BWI agar jadwal pariwisata terupdate real-time." },
  { key: "sensor", icon: Cpu, title: "Integrasi Sensor IoT", desc: "Kelola koneksi, transmisi, dan telemetri informasi sensor di seluruh wilayah." },
  { key: "geofence", icon: MapPin, title: "Integrasi Geofencing", desc: "Konfigurasi konten audio otomatis, trigger notifikasi zonasi, dan log presensi pengunjung." },
  { key: "email", icon: Mail, title: "Integrasi Email", desc: "Pengiriman otomatis laporan analisis mingguan dan notifikasi peringatan polusi via email." },
];

function IntegrationTab() {
  const { values, system } = useSettings();
  const [open, setOpen] = useState(null);
  const info = system?.integrations ?? {};
  const status = (k) => {
    if (!values[`integration.${k}.enabled`]) return { tone: "muted", text: "Nonaktif" };
    if (k === "email" && info.email?.mailer === "log") return { tone: "warn", text: "Perlu Konfigurasi" };
    return { tone: "ok", text: "Terhubung" };
  };
  return (
    <>
      <div className="space-y-3">
        {INTEGRATIONS.map(({ key, icon: Icon, title, desc }) => {
          const st = status(key);
          return (
            <Card key={key} className="p-5 flex items-center gap-4">
              <span className="h-12 w-12 rounded-xl bg-canvas text-brand-700 inline-flex items-center justify-center shrink-0"><Icon size={22} /></span>
              <div className="min-w-0 flex-1"><p className="font-semibold text-brand-700">{title}</p><p className="text-[13px] text-muted-2 mt-1 leading-relaxed">{desc}</p></div>
              <Badge tone={st.tone}>{st.text}</Badge>
              <Button variant="secondary" size="sm" onClick={() => setOpen(key)}>Kelola</Button>
            </Card>
          );
        })}
      </div>
      {open && <IntegrationModal k={open} item={INTEGRATIONS.find((i) => i.key === open)} info={info[open]} onClose={() => setOpen(null)} />}
    </>
  );
}

function IntegrationModal({ k, item, info, onClose }) {
  const { values, save } = useSettings();
  const toast = useToast();
  const [en, setEn] = useState(!!values[`integration.${k}.enabled`]);
  const [rec, setRec] = useState(values["integration.email.recipients"] ?? "");
  const [digest, setDigest] = useState(values["integration.email.digest"] ?? "harian");
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState("");

  const facts = {
    festival: [["Event terdaftar", info?.count ?? "—"], ["Event aktif", info?.active ?? "—"]],
    sensor: [["Perangkat terdaftar", info?.count ?? "—"], ["Terakhir aktif", info?.last_seen_at ? formatTanggalJam(info.last_seen_at) : "Belum ada"]],
    geofence: [["Zona terdaftar", info?.count ?? "—"], ["Zona aktif", info?.active ?? "—"]],
    email: [["Mailer server", info?.mailer ?? "—"]],
  }[k];

  async function submit() {
    if (k === "email" && rec.trim()) {
      const bad = rec.split(",").map((s) => s.trim()).filter(Boolean).find((s) => !/^\S+@\S+\.\S+$/.test(s));
      if (bad) return setErr(`Email tidak valid: ${bad}`);
    }
    setBusy(true);
    try {
      await save({ [`integration.${k}.enabled`]: en, ...(k === "email" ? { "integration.email.recipients": rec.trim(), "integration.email.digest": digest } : {}) });
      toast.success("Integrasi disimpan."); onClose();
    } catch (e) { toast.error(e.message); } finally { setBusy(false); }
  }

  return (
    <Modal open onClose={onClose} title={item.title} subtitle={item.desc} footer={<><Button variant="secondary" onClick={onClose}>Batal</Button><Button loading={busy} onClick={submit}>Simpan</Button></>}>
      <div className="space-y-4">
        <div className="grid grid-cols-2 gap-3">{facts.map(([l, v]) => <div key={l} className="rounded-xl bg-canvas px-4 py-3"><p className="text-xs text-muted">{l}</p><p className="font-bold text-brand-700 mt-0.5">{String(v)}</p></div>)}</div>
        <div className="flex items-center justify-between gap-3 py-2"><div><p className="text-sm font-semibold text-brand-700">Integrasi aktif</p><p className="text-xs text-muted">Matikan untuk menjeda modul ini.</p></div><Toggle checked={en} onChange={setEn} label="Integrasi aktif" /></div>
        {k === "email" && (
          <>
            {info?.mailer === "log" && <p className="text-xs rounded-lg bg-warn-bg text-warn px-3 py-2">Server memakai <code>MAIL_MAILER=log</code> — email hanya ditulis ke log, belum terkirim. Atur SMTP di <code>.env</code> backend agar benar-benar terkirim.</p>}
            <Field label="Penerima laporan (pisahkan dengan koma)" error={err} htmlFor="i-r"><Textarea id="i-r" rows={2} value={rec} onChange={(e) => { setRec(e.target.value); setErr(""); }} placeholder="admin@svarga.id, dinas@banyuwangikab.go.id" /></Field>
            <Field label="Frekuensi ringkasan" htmlFor="i-d"><Select id="i-d" value={digest} onChange={(e) => setDigest(e.target.value)}><option value="harian">Harian</option><option value="mingguan">Mingguan</option><option value="nonaktif">Nonaktif</option></Select></Field>
          </>
        )}
      </div>
    </Modal>
  );
}

function SystemCard() {
  const { system, values, save } = useSettings();
  const toast = useToast();
  const [tz, setTz] = useState(false);
  const [val, setVal] = useState(values["app.timezone"]);
  const row = "flex items-center justify-between py-3 border-b border-brand-200 last:border-0 text-sm";
  async function submit() { try { await save({ "app.timezone": val }); toast.success("Zona waktu diperbarui."); setTz(false); } catch (e) { toast.error(e.message); } }
  return (
    <Card className="p-5">
      <CardTitle>Informasi Sistem</CardTitle>
      <div className="mt-2">
        <div className={row}><span className="text-muted-2">Versi Aplikasi</span><b className="text-brand-700">{system?.version ?? "—"}</b></div>
        <div className={row}><span className="text-muted-2 whitespace-nowrap mr-3">Terakhir Update</span><b className="text-brand-700 text-right text-[13px]">{system?.updated_at ? formatTanggalJam(system.updated_at) : "—"}</b></div>
        <div className={row}><span className="text-muted-2">Bahasa Utama</span><b className="text-brand-700">Bahasa Indonesia</b></div>
        <button onClick={() => setTz(true)} className={`${row} w-full`}><span className="text-muted-2">Zona Waktu</span><b className="text-brand-700 inline-flex items-center gap-1">{TZ_LABEL[values["app.timezone"]] ?? values["app.timezone"]}<ChevronRight size={15} /></b></button>
      </div>
      <Modal open={tz} onClose={() => setTz(false)} size="sm" title="Zona Waktu" footer={<><Button variant="secondary" onClick={() => setTz(false)}>Batal</Button><Button onClick={submit}>Simpan</Button></>}>
        <Select value={val} onChange={(e) => setVal(e.target.value)} aria-label="Zona waktu">{Object.entries(TZ_LABEL).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</Select>
      </Modal>
    </Card>
  );
}

function BackupCard() {
  const { system, reload } = useSettings();
  const toast = useToast();
  const [confirm, setConfirm] = useState(false);
  const [busy, setBusy] = useState(false);
  const file = system?.last_backup_file;
  async function run() {
    setBusy(true);
    try { const r = await settingsApi.backup(); toast.success(`Backup selesai: ${r.data.file}`); setConfirm(false); await reload(); }
    catch (e) { toast.error(e.message); } finally { setBusy(false); }
  }
  async function dl() { try { await settingsApi.downloadBackup(file); } catch (e) { toast.error(e.message); } }
  return (
    <Card className="p-5">
      <CardTitle>Backup & Data</CardTitle>
      <p className="text-xs text-muted-2 mt-3">Backup Terakhir</p>
      <p className="font-bold text-brand-700 mt-0.5">{system?.last_backup_at ? formatTanggalJam(system.last_backup_at) : "Belum pernah"}</p>
      {file && <button onClick={dl} className="mt-2 inline-flex items-center gap-1.5 text-xs font-semibold text-brand-700 hover:underline"><Download size={13} />Unduh {file}</button>}
      <Button variant="danger" className="w-full mt-4" onClick={() => setConfirm(true)}>Backup Sekarang</Button>
      <ConfirmDialog open={confirm} loading={busy} onClose={() => setConfirm(false)} onConfirm={run} tone="primary" title="Buat backup data?" confirmLabel="Backup Sekarang"
        message="Seluruh data (pengguna, event, zona, sensor, mood, notifikasi) disalin ke satu berkas JSON di server. Proses beberapa detik." />
    </Card>
  );
}
