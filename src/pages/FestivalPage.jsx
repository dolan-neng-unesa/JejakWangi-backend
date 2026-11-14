import { useMemo, useState } from "react";
import { CalendarDays, ChevronLeft, ChevronRight, ImageOff, MapPin, Pencil, Plus, Trash2 } from "lucide-react";
import { PageHeader } from "../components/ui/PageHeader";
import { Card } from "../components/ui/Card";
import { Badge } from "../components/ui/Badge";
import { Button, IconButton } from "../components/ui/Button";
import { ConfirmDialog, Modal } from "../components/ui/Modal";
import { Field, FilterSelect, Input, Select, Textarea, Toggle } from "../components/ui/Field";
import { EmptyState, ErrorState, Skeleton } from "../components/ui/States";
import { useAsync } from "../hooks/useAsync";
import { useToast } from "../context/ToastContext";
import { festivals as api } from "../services/jejakwangi";
import { HARI, bulanNama, formatTanggal, isoDate, todayISO } from "../lib/format";

const CATS = [{ value: "", label: "Semua Kategori" }, { value: "budaya", label: "Budaya" }, { value: "pariwisata", label: "Pariwisata" }, { value: "seni", label: "Seni" }];
const catLabel = (c) => CATS.find((x) => x.value === c)?.label ?? "—";
const DOW = ["S", "S", "R", "K", "J", "S", "M"]; // Senin … Minggu (sesuai mockup)

const hm = (t) => (t ? String(t).slice(0, 5) : "");
const timeRange = (e) => (e.start_time ? `${hm(e.start_time)}${e.end_time ? ` - ${hm(e.end_time)}` : ""} WIB` : "Waktu belum ditentukan");

function durasi(e) {
  if (!e.start_time || !e.end_time) return "—";
  const [a, b] = [hm(e.start_time), hm(e.end_time)].map((t) => { const [h, m] = t.split(":").map(Number); return h * 60 + m; });
  const mins = b - a;
  if (mins <= 0) return "—";
  return `${Math.floor(mins / 60)} jam${mins % 60 ? ` ${mins % 60} mnt` : ""}`;
}
function countdown(date) {
  const diff = Math.round((new Date(date + "T00:00:00Z") - new Date(todayISO() + "T00:00:00Z")) / 86400000);
  return diff === 0 ? "Hari ini" : diff > 0 ? `${diff} hari lagi` : "Sudah selesai";
}

export default function FestivalPage() {
  const toast = useToast();
  const now = todayISO().split("-").map(Number);
  const [cat, setCat] = useState("");
  const [loc, setLoc] = useState("");
  const [ym, setYm] = useState({ y: now[0], m: now[1] - 1 });
  const [selId, setSelId] = useState(null);
  const [form, setForm] = useState(null);
  const [all, setAll] = useState(false);
  const [del, setDel] = useState(null);
  const [busy, setBusy] = useState(false);

  const res = useAsync(() => api.list(), []);
  const events = res.data?.data ?? [];
  const lokasiOpts = useMemo(() => [...new Set(events.map((e) => e.location_name).filter(Boolean))].sort(), [events]);
  const filtered = events.filter((e) => (!cat || e.category === cat) && (!loc || e.location_name === loc));
  const byDate = useMemo(() => { const m = {}; filtered.forEach((e) => (m[e.date] ||= []).push(e)); return m; }, [filtered]);
  const today = todayISO();
  const upcoming = filtered.filter((e) => e.date >= today && e.is_active).sort((a, b) => a.date.localeCompare(b.date));
  const selected = filtered.find((e) => e.id === selId) ?? upcoming[0] ?? filtered[0] ?? null;

  // Grid kalender, awal pekan Senin.
  const first = new Date(Date.UTC(ym.y, ym.m, 1));
  const lead = (first.getUTCDay() + 6) % 7;
  const cells = Array.from({ length: 42 }, (_, i) => { const d = new Date(Date.UTC(ym.y, ym.m, 1 - lead + i)); return { y: d.getUTCFullYear(), m: d.getUTCMonth(), d: d.getUTCDate() }; });
  const shift = (n) => setYm(({ y, m }) => { const d = new Date(Date.UTC(y, m + n, 1)); return { y: d.getUTCFullYear(), m: d.getUTCMonth() }; });

  async function remove() {
    setBusy(true);
    try { await api.remove(del.id); toast.success(`Event "${del.bfest_name}" dihapus.`); setDel(null); setSelId(null); res.reload({ silent: true }); }
    catch (e) { toast.error(e.message); } finally { setBusy(false); }
  }

  return (
    <>
      <PageHeader title="Kalender Festival BWI" chip="Kalender Event" subtitle="Pusat informasi agenda festival budaya dan pariwisata Banyuwangi." showRegion>
        <Button icon={Plus} onClick={() => setForm({})}>Tambah Event</Button>
      </PageHeader>

      <div className="flex flex-wrap gap-3 mb-5">
        <FilterSelect label="Kategori" value={cat} onChange={setCat} options={CATS} />
        <FilterSelect label="Lokasi" value={loc} onChange={setLoc} options={[{ value: "", label: "Semua Lokasi" }, ...lokasiOpts.map((l) => ({ value: l, label: l }))]} />
      </div>

      {res.error && !res.data ? <Card><ErrorState error={res.error} onRetry={res.reload} /></Card> : (
        <div className="grid gap-4 grid-cols-1 xl:grid-cols-[minmax(320px,400px)_1fr] items-start">
          <div className="space-y-4">
            <Card className="p-5">
              <div className="flex items-center justify-between mb-4">
                <h2 className="text-[17px] font-semibold text-brand-700">{bulanNama(ym.m)} {ym.y}</h2>
                <div className="flex gap-1"><IconButton icon={ChevronLeft} tone="plain" label="Bulan sebelumnya" onClick={() => shift(-1)} /><IconButton icon={ChevronRight} tone="plain" label="Bulan berikutnya" onClick={() => shift(1)} /></div>
              </div>
              <div className="grid grid-cols-7 text-center text-xs font-semibold text-brand-700 mb-1">{DOW.map((d, i) => <span key={i} className="py-2">{d}</span>)}</div>
              <div className="grid grid-cols-7 gap-y-1">
                {cells.map((c, i) => {
                  const iso = isoDate(c.y, c.m, c.d);
                  const has = byDate[iso]; const out = c.m !== ym.m; const sel = selected && selected.date === iso; const isToday = iso === today;
                  return (
                    <button key={i} disabled={!has} onClick={() => setSelId(has[0].id)} aria-label={`${c.d} ${bulanNama(c.m)}${has ? `, ${has.length} event` : ""}`}
                      className={`mx-auto h-9 w-9 rounded-full text-[13px] font-medium transition-colors ${has ? (sel ? "bg-brand-900 text-white font-bold" : "bg-brand-700 text-white font-bold hover:bg-brand-800") : out ? "text-brand-700/35" : "text-brand-700"} ${isToday && !has ? "ring-1 ring-brand-700" : ""} disabled:cursor-default`}>
                      {c.d}
                    </button>
                  );
                })}
              </div>
            </Card>

            <Card className="p-5">
              <h2 className="text-[17px] font-semibold text-brand-700 mb-3">Event Terdekat</h2>
              {res.loading && !res.data ? <Skeleton className="h-40" /> : upcoming.length === 0 ? <p className="text-sm text-muted py-4">Tidak ada event mendatang untuk filter ini.</p> : (
                <ul className="space-y-3.5">
                  {upcoming.slice(0, 5).map((e) => (
                    <li key={e.id}><button onClick={() => { setSelId(e.id); const [y, m] = e.date.split("-").map(Number); setYm({ y, m: m - 1 }); }} className="flex items-center gap-3 text-left w-full group">
                      <Thumb e={e} size={52} />
                      <span className="min-w-0"><span className="block font-semibold text-sm text-brand-700 truncate group-hover:underline">{e.bfest_name}</span><span className="block text-xs text-muted truncate">{formatTanggal(e.date)} • {e.location_name || "—"}</span></span>
                    </button></li>
                  ))}
                </ul>
              )}
            </Card>
          </div>

          <Card className="p-6">
            {res.loading && !res.data ? <Skeleton className="h-96" /> : !selected ? (
              <EmptyState icon={CalendarDays} title="Belum ada event" message="Tambahkan event festival pertama lewat tombol Tambah Event." action={<Button icon={Plus} onClick={() => setForm({})}>Tambah Event</Button>} />
            ) : (
              <>
                <div className="flex items-start justify-between gap-3 mb-4">
                  <h2 className="text-xl font-bold text-brand-700">{selected.bfest_name}</h2>
                  <div className="flex gap-2 shrink-0"><Badge tone={selected.is_active ? "ok" : "muted"}>{selected.is_active ? "Aktif" : "NonAktif"}</Badge></div>
                </div>
                {selected.image ? <img src={selected.image} alt={selected.bfest_name} className="w-full max-w-[500px] aspect-[2.4/1] object-cover rounded-2xl" /> : (
                  <div className="w-full max-w-[500px] aspect-[2.4/1] rounded-2xl bg-gradient-to-br from-brand-100 to-brand-200 text-brand-500 flex flex-col items-center justify-center gap-1"><ImageOff size={26} /><span className="text-xs">Belum ada gambar event</span></div>
                )}
                <ul className="mt-4 space-y-2.5 text-sm text-brand-700">
                  <li className="flex items-center gap-3"><CalendarDays size={17} className="text-brand-600" />{formatTanggal(selected.date)}, {timeRange(selected)}</li>
                  <li className="flex items-center gap-3"><MapPin size={17} className="text-brand-600" />{[selected.location_name, selected.address].filter(Boolean).join(" — ") || "Lokasi belum ditentukan"}</li>
                </ul>
                <hr className="my-4 border-brand-200" />
                <p className="text-sm text-brand-800 leading-relaxed whitespace-pre-line">{selected.description || "Belum ada deskripsi."}</p>
                <div className="grid gap-3 sm:grid-cols-3 mt-5">
                  {[["Kategori", catLabel(selected.category)], ["Durasi", durasi(selected)], ["Hitung Mundur", countdown(selected.date)]].map(([l, v]) => (
                    <div key={l} className="rounded-xl bg-canvas px-4 py-3"><p className="text-xs text-muted">{l}</p><p className="font-bold text-brand-700 mt-0.5">{v}</p></div>
                  ))}
                </div>
                <div className="flex flex-wrap gap-3 mt-5">
                  <Button onClick={() => setForm(selected)}>Kelola Event</Button>
                  <Button variant="soft" onClick={() => setAll(true)}>Lihat Semua Event</Button>
                </div>
              </>
            )}
          </Card>
        </div>
      )}

      {form && <EventForm event={form.id ? form : null} onClose={() => setForm(null)} onDelete={form.id ? () => { setDel(form); setForm(null); } : null}
        onSaved={(saved) => { setForm(null); if (saved?.id) { setSelId(saved.id); const [y, m] = saved.date.split("-").map(Number); setYm({ y, m: m - 1 }); } res.reload({ silent: true }); }} />}
      {all && <AllEvents events={events} onClose={() => setAll(false)} onAdd={() => { setAll(false); setForm({}); }} onEdit={(e) => { setAll(false); setForm(e); }} onDelete={(e) => { setAll(false); setDel(e); }} />}
      <ConfirmDialog open={Boolean(del)} loading={busy} onClose={() => setDel(null)} onConfirm={remove} title="Hapus event?" confirmLabel="Hapus" message={<>Event <b>{del?.bfest_name}</b> akan dihapus dari kalender aplikasi warga.</>} />
    </>
  );
}

function Thumb({ e, size = 48 }) {
  return e.image ? <img src={e.image} alt="" style={{ width: size, height: size }} className="rounded-lg object-cover shrink-0" />
    : <span style={{ width: size, height: size }} className="rounded-lg bg-brand-100 text-brand-500 inline-flex items-center justify-center shrink-0"><CalendarDays size={18} /></span>;
}

function AllEvents({ events, onClose, onAdd, onEdit, onDelete }) {
  const [q, setQ] = useState("");
  const list = [...events].filter((e) => e.bfest_name.toLowerCase().includes(q.toLowerCase())).sort((a, b) => b.date.localeCompare(a.date));
  return (
    <Modal open onClose={onClose} size="lg" title="Semua Event" subtitle={`${events.length} event terdaftar (termasuk nonaktif)`} footer={<><Button variant="secondary" onClick={onClose}>Tutup</Button><Button icon={Plus} onClick={onAdd}>Tambah Event</Button></>}>
      <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Cari nama event…" aria-label="Cari event" className="mb-4" />
      {list.length === 0 ? <EmptyState title="Event tidak ditemukan" /> : (
        <ul className="divide-y divide-brand-200">
          {list.map((e) => (
            <li key={e.id} className="flex items-center gap-3 py-3">
              <Thumb e={e} />
              <div className="min-w-0 flex-1"><p className="font-semibold text-sm text-brand-700 truncate">{e.bfest_name}</p><p className="text-xs text-muted truncate">{formatTanggal(e.date)} • {e.location_name || "—"} • {catLabel(e.category)}</p></div>
              <Badge tone={e.is_active ? "ok" : "muted"}>{e.is_active ? "Aktif" : "NonAktif"}</Badge>
              <IconButton icon={Pencil} tone="plain" label="Ubah" onClick={() => onEdit(e)} /><IconButton icon={Trash2} tone="danger" label="Hapus" onClick={() => onDelete(e)} />
            </li>
          ))}
        </ul>
      )}
    </Modal>
  );
}

function EventForm({ event, onClose, onSaved, onDelete }) {
  const toast = useToast();
  const edit = Boolean(event);
  const [f, setF] = useState({ name: event?.bfest_name ?? "", event_date: event?.date ?? todayISO(), start_time: hm(event?.start_time), end_time: hm(event?.end_time),
    location_name: event?.location_name ?? "", address: event?.address ?? "", category: event?.category ?? "budaya", description: event?.description ?? "", image_url: "", is_active: event?.is_active ?? true });
  const [errors, setErrors] = useState({});
  const [busy, setBusy] = useState(false);
  const set = (k) => (v) => setF((s) => ({ ...s, [k]: v }));

  async function submit() {
    const err = {};
    if (!f.name.trim()) err.name = "Nama event wajib diisi.";
    if (!f.location_name.trim()) err.location_name = "Lokasi wajib diisi.";
    if (!f.event_date) err.event_date = "Tanggal wajib diisi.";
    if (f.start_time && f.end_time && f.end_time <= f.start_time) err.end_time = "Jam selesai harus setelah jam mulai.";
    setErrors(err);
    if (Object.keys(err).length) return;
    setBusy(true);
    try {
      const body = { name: f.name.trim(), event_date: f.event_date, location_name: f.location_name.trim(), address: f.address || null, category: f.category || null,
        description: f.description || null, start_time: f.start_time || null, end_time: f.end_time || null, is_active: f.is_active };
      if (f.image_url.trim()) body.image_url = f.image_url.trim();
      const res = edit ? await api.update(event.id, body) : await api.create(body);
      toast.success(edit ? "Event diperbarui." : "Event ditambahkan.");
      onSaved({ id: res?.data?.id, date: res?.data?.date ?? f.event_date });
    } catch (e) {
      if (e.errors) setErrors(Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]]))); else toast.error(e.message);
    } finally { setBusy(false); }
  }

  return (
    <Modal open onClose={onClose} size="lg" title={edit ? "Kelola Event" : "Tambah Event"} subtitle={edit ? event.bfest_name : "Event tampil di kalender aplikasi warga saat aktif."}
      footer={<>{onDelete && <Button variant="danger" icon={Trash2} className="mr-auto" onClick={onDelete}>Hapus</Button>}<Button variant="secondary" onClick={onClose}>Batal</Button><Button loading={busy} onClick={submit}>{edit ? "Simpan" : "Tambah Event"}</Button></>}>
      <div className="grid gap-4 md:grid-cols-2">
        <Field className="md:col-span-2" label="Nama event" required error={errors.name} htmlFor="e-name"><Input id="e-name" value={f.name} onChange={(e) => set("name")(e.target.value)} invalid={!!errors.name} autoFocus /></Field>
        <Field label="Tanggal" required error={errors.event_date} htmlFor="e-date"><Input id="e-date" type="date" value={f.event_date} onChange={(e) => set("event_date")(e.target.value)} invalid={!!errors.event_date} /></Field>
        <Field label="Kategori" htmlFor="e-cat" error={errors.category}><Select id="e-cat" value={f.category} onChange={(e) => set("category")(e.target.value)}>{CATS.slice(1).map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}</Select></Field>
        <Field label="Jam mulai" htmlFor="e-st" error={errors.start_time}><Input id="e-st" type="time" value={f.start_time} onChange={(e) => set("start_time")(e.target.value)} /></Field>
        <Field label="Jam selesai" htmlFor="e-et" error={errors.end_time}><Input id="e-et" type="time" value={f.end_time} onChange={(e) => set("end_time")(e.target.value)} invalid={!!errors.end_time} /></Field>
        <Field label="Nama lokasi" required error={errors.location_name} htmlFor="e-loc"><Input id="e-loc" value={f.location_name} onChange={(e) => set("location_name")(e.target.value)} invalid={!!errors.location_name} placeholder="Taman Blambangan" /></Field>
        <Field label="Alamat / rute" htmlFor="e-addr" error={errors.address}><Input id="e-addr" value={f.address} onChange={(e) => set("address")(e.target.value)} /></Field>
        <Field className="md:col-span-2" label="Deskripsi" htmlFor="e-desc" error={errors.description}><Textarea id="e-desc" rows={4} value={f.description} onChange={(e) => set("description")(e.target.value)} /></Field>
        <Field className="md:col-span-2" label="Gambar (URL atau path publik)" htmlFor="e-img" error={errors.image_url} hint={edit && event.image ? "Biarkan kosong untuk mempertahankan gambar saat ini." : "Mis. https://… atau images/festival/bec.png (di folder public backend)."}><Input id="e-img" value={f.image_url} onChange={(e) => set("image_url")(e.target.value)} /></Field>
        <div className="md:col-span-2 flex items-center gap-3"><Toggle checked={f.is_active} onChange={set("is_active")} label="Event aktif" /><span className="text-sm text-brand-700">{f.is_active ? "Aktif — tampil di aplikasi warga" : "NonAktif — disembunyikan dari aplikasi warga"}</span></div>
      </div>
    </Modal>
  );
}
