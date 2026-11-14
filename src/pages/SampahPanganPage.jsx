import { useMemo, useState } from "react";
import { Link, Route, Routes, useNavigate, useParams } from "react-router-dom";
import { ArrowLeft, Check, Plus, Trash2 } from "lucide-react";
import { PageHeader } from "../components/ui/PageHeader";
import { StatCard } from "../components/ui/StatCard";
import { Card, CardTitle } from "../components/ui/Card";
import { Badge } from "../components/ui/Badge";
import { Button } from "../components/ui/Button";
import { Modal } from "../components/ui/Modal";
import { Tabs } from "../components/ui/Tabs";
import { Field, Input, Select, Textarea } from "../components/ui/Field";
import { Pagination } from "../components/ui/Pagination";
import { EmptyState, ErrorState, Skeleton, TableSkeleton } from "../components/ui/States";
import { useAsync, useDebounced } from "../hooks/useAsync";
import { useToast } from "../context/ToastContext";
import { users as usersApi, waste } from "../services/jejakwangi";
import { downloadCsv, fmtNum, formatTanggalJam, todayISO } from "../lib/format";

const TONE = { terkonfirmasi: "ok", menunggu_konfirmasi: "info", perlu_koreksi: "warn", draft: "muted" };
const kg = (n) => `${fmtNum(n, n % 1 ? 1 : 0)} kg`;
const PERIODS = [{ value: "month", label: "Bulan ini" }, { value: "7", label: "7 hari terakhir" }, { value: "30", label: "30 hari terakhir" }];

function range(period) {
  const now = new Date(), to = todayISO();
  if (period === "month") return { from: `${to.slice(0, 8)}01`, to };
  const d = new Date(now.getTime() - (Number(period) - 1) * 86400000);
  return { from: `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`, to };
}

export default function SampahPanganPage() {
  return (
    <Routes>
      <Route index element={<Overview />} />
      <Route path="catat" element={<Weigh />} />
      <Route path="catat/:id" element={<Weigh />} />
    </Routes>
  );
}

function Bar({ label, total, done, doneLabel, waitLabel, color }) {
  const pctDone = total ? Math.min(100, (done / total) * 100) : 0;
  return (
    <div className="mb-5">
      <div className="flex justify-between text-sm font-semibold text-brand-700"><span>{label}</span><span>{kg(total)}</span></div>
      <div className="h-3 rounded-full bg-brand-100 mt-2 overflow-hidden" role="img" aria-label={`${label}: ${Math.round(pctDone)}% sudah diolah`}><div className="h-full rounded-full" style={{ width: `${pctDone}%`, background: color }} /></div>
      <p className="text-[13px] text-muted mt-1.5">{kg(done)} {doneLabel} • {kg(Math.max(0, total - done))} {waitLabel}</p>
    </div>
  );
}

function Overview() {
  const toast = useToast();
  const [kind, setKind] = useState("sampah");
  const [period, setPeriod] = useState("month");
  const [station, setStation] = useState("");
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState("");
  const [q, setQ] = useState("");
  const [procOpen, setProcOpen] = useState(false);
  const dq = useDebounced(q);
  const p = { kind, ...range(period), station_code: station };
  const meta = useAsync(() => waste.meta(), []);
  const sum = useAsync(() => waste.summary(p), [kind, period, station], { interval: 60_000 });
  const list = useAsync(() => waste.list({ ...p, page, per_page: 6, status, q: dq }), [kind, period, station, page, status, dq]);
  const s = sum.data, l = list.data, rows = l?.data ?? [];
  const isP = kind === "pangan";

  async function exportCsv() {
    try {
      const all = await waste.list({ ...p, per_page: 100, status, q: dq });
      downloadCsv(`rekap-${kind}-${todayISO()}.csv`, [["Transaksi", "Waktu", "Penyetor", "Tipe", "Tempat timbang", "Berat (kg)", "Poin", "Status"], ...all.data.map((r) => [r.code, r.weighed_at, r.depositor_name, r.depositor_type, r.station_name, r.total_kg, r.points, r.status_label])]);
    } catch (e) { toast.error(e.message); }
  }

  return (
    <>
      <PageHeader title="Sampah & Pangan" subtitle="Pantau setoran, pengolahan sampah, dan kontribusi warga dalam satu tempat.">
        <Link to="catat"><Button icon={Plus}>Catat penimbangan</Button></Link>
      </PageHeader>
      <div className="flex flex-wrap items-center gap-3 mb-6">
        <Tabs value={kind} onChange={(v) => { setKind(v); setPage(1); }} tabs={[{ value: "sampah", label: "Timbang Sampah" }, { value: "pangan", label: "Pangan" }]} />
        <Select aria-label="Periode" className="!w-48" value={period} onChange={(e) => { setPeriod(e.target.value); setPage(1); }}>{PERIODS.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</Select>
        <Select aria-label="Tempat timbang" className="!w-56" value={station} onChange={(e) => { setStation(e.target.value); setPage(1); }}><option value="">Semua tempat timbang</option>{(meta.data?.stasiun ?? []).map((t) => <option key={t.kode} value={t.kode}>{t.nama}</option>)}</Select>
      </div>

      {sum.error ? <ErrorState error={sum.error} onRetry={sum.reload} /> : (
        <>
          <div className="grid gap-4 md:grid-cols-3 mb-6">
            <StatCard label="Total terkumpul" value={kg(s?.total_kg ?? 0)} sub={isP ? `${s?.jumlah_penimbangan ?? 0} penimbangan terkonfirmasi` : `${kg(s?.organik_kg ?? 0)} organik • ${kg(s?.anorganik_kg ?? 0)} anorganik`} loading={sum.loading && !s} />
            <StatCard label={isP ? "Disalurkan" : "Didaur ulang"} value={kg(isP ? s?.pangan_disalurkan_kg ?? 0 : s?.anorganik_didaur_kg ?? 0)} sub={isP ? `Dari total ${kg(s?.pangan_kg ?? 0)} pangan` : `Dari total ${kg(s?.anorganik_kg ?? 0)} anorganik`} loading={sum.loading && !s} />
            <StatCard label={isP ? "Menunggu disalurkan" : "Diolah jadi kompos"} value={kg(isP ? s?.pangan_menunggu_kg ?? 0 : s?.organik_diolah_kg ?? 0)} sub={isP ? "Belum dihitung sebagai tersalurkan" : `Dari total ${kg(s?.organik_kg ?? 0)} organik`} loading={sum.loading && !s} />
          </div>
          <div className="grid gap-6 xl:grid-cols-[1.1fr_1fr] mb-6 items-start">
            <Card className="p-5">
              <CardTitle>Dari setoran menjadi manfaat</CardTitle>
              <p className="text-sm text-muted -mt-2 mb-4">Berat terkumpul berbeda dari berat yang sudah diolah.</p>
              {isP ? <Bar label="Pangan layak" total={s?.pangan_kg ?? 0} done={s?.pangan_disalurkan_kg ?? 0} doneLabel="disalurkan" waitLabel="menunggu" color="#3f6e45" /> : <>
                <Bar label="Organik" total={s?.organik_kg ?? 0} done={s?.organik_diolah_kg ?? 0} doneLabel="jadi kompos" waitLabel="menunggu" color="#3f6e45" />
                <Bar label="Anorganik" total={s?.anorganik_kg ?? 0} done={s?.anorganik_didaur_kg ?? 0} doneLabel="didaur ulang" waitLabel="menunggu" color="#b7791f" />
              </>}
              <div className="rounded-xl bg-canvas p-4"><p className="font-semibold text-brand-700">{kg(s?.menunggu_pengolahan_kg ?? 0)} menunggu {isP ? "disalurkan" : "pengolahan"}</p><p className="text-[13px] text-muted mt-1">Perbarui status saat {isP ? "pangan sudah disalurkan" : "sampah selesai diolah"}. Berat ini belum dihitung sebagai {isP ? "tersalurkan" : "daur ulang atau kompos"}.</p></div>
              <div className="text-center mt-4"><Button variant="ghost" onClick={() => setProcOpen(true)}>Perbarui pengolahan →</Button></div>
            </Card>
            <Card className="p-5">
              <CardTitle>Kontribusi lapak & pengguna</CardTitle>
              <p className="text-sm text-muted -mt-2 mb-3">Lapak: {kg(s?.lapak_kg ?? 0)} • Pengguna: {kg(s?.pengguna_kg ?? 0)}</p>
              {(s?.kontributor ?? []).length === 0 ? <EmptyState title="Belum ada setoran terkonfirmasi" /> : s.kontributor.map((c) => (
                <div key={c.nama + c.tipe} className="flex items-center justify-between rounded-xl bg-canvas px-4 py-3 mb-2"><div><p className="font-semibold text-sm text-brand-800">{c.nama}</p><p className="text-xs text-muted capitalize">{c.tipe}</p></div><p className="font-bold text-brand-700">{kg(c.kg)}</p></div>
              ))}
              {s?.pengguna_lainnya_kg > 0 && <p className="text-[13px] text-muted mt-2">Pengguna lainnya: {kg(s.pengguna_lainnya_kg)}</p>}
            </Card>
          </div>
        </>
      )}

      <Card className="p-5">
        <div className="flex flex-wrap items-center justify-between gap-3 mb-4"><CardTitle>Riwayat penimbangan</CardTitle><Button variant="ghost" onClick={exportCsv}>Ekspor rekap ↓</Button></div>
        <div className="grid gap-3 md:grid-cols-[1fr_220px] mb-4">
          <Input aria-label="Cari penimbangan" placeholder="Cari nama / nomor transaksi" value={q} onChange={(e) => { setQ(e.target.value); setPage(1); }} />
          <Select aria-label="Filter status" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }}><option value="">Semua status</option>{(meta.data?.status ?? []).map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</Select>
        </div>
        {list.error ? <ErrorState error={list.error} onRetry={list.reload} compact /> : list.loading && !l ? <TableSkeleton rows={4} cols={7} /> : rows.length === 0 ? <EmptyState title="Belum ada penimbangan" message="Tidak ada penimbangan pada periode & filter ini." /> : (
          <div className="overflow-x-auto"><table className="w-full text-sm min-w-[760px]">
            <thead><tr className="text-left text-brand-700 text-[13px] bg-canvas">{["Transaksi", "Penyetor", "Tempat timbang", "Berat", "Poin", "Status", "Aksi"].map((h) => <th key={h} className="py-2.5 px-3 font-semibold">{h}</th>)}</tr></thead>
            <tbody>{rows.map((r) => (
              <tr key={r.id} className="border-t border-brand-100">
                <td className="py-3 px-3"><p className="text-xs">{r.code}</p><p className="text-xs text-muted">{formatTanggalJam(r.weighed_at)}</p></td>
                <td className="py-3 px-3"><p className="font-semibold">{r.depositor_name}</p><p className="text-xs text-muted capitalize">{r.depositor_type}</p></td>
                <td className="py-3 px-3">{r.station_name}</td>
                <td className="py-3 px-3">{kg(r.total_kg)}</td>
                <td className="py-3 px-3">{r.status === "terkonfirmasi" ? `+${r.points}` : "Belum"}</td>
                <td className="py-3 px-3"><Badge tone={TONE[r.status]}>{r.status_label}</Badge></td>
                <td className="py-3 px-3 whitespace-nowrap">{["draft", "perlu_koreksi"].includes(r.status) ? <Link className="font-semibold text-brand-700 hover:underline" to={`catat/${r.id}`}>Periksa →</Link> : <DetailLink id={r.id} />}</td>
              </tr>))}</tbody></table></div>)}
        {l && l.filtered_total > 0 && <div className="flex flex-wrap items-center justify-between gap-3 mt-4"><p className="text-[13px] text-muted">Menampilkan {rows.length} dari {l.filtered_total} penimbangan</p><Pagination page={l.page} lastPage={l.last_page} onChange={setPage} /></div>}
      </Card>
      <ProcessModal open={procOpen} onClose={() => { setProcOpen(false); sum.reload(); list.reload(); }} kind={kind} />
    </>
  );
}

function DetailLink({ id }) {
  const [open, setOpen] = useState(false);
  const res = useAsync(() => waste.get(id), [id], { enabled: open });
  const w = res.data;
  return (
    <>
      <button className="font-semibold text-brand-700 hover:underline" onClick={() => setOpen(true)}>Lihat detail →</button>
      <Modal open={open} onClose={() => setOpen(false)} title={w ? `${w.code} • ${w.depositor_name}` : "Detail penimbangan"} footer={<Button variant="secondary" onClick={() => setOpen(false)}>Tutup</Button>}>
        {!w ? <Skeleton className="h-32" /> : <div className="grid gap-2 text-sm">
          {w.items.map((i, n) => <div key={n} className="flex justify-between rounded-lg bg-canvas px-3 py-2"><span>{i.label} • {kg(i.kg)} × {i.poin_per_kg}</span><b>{i.poin} poin</b></div>)}
          <p className="mt-2"><b>Total:</b> {kg(w.total_kg)} • {w.points} poin • {w.station_name}</p>
          {w.note && <p><b>Catatan petugas:</b> {w.note}</p>}{w.correction_note && <p className="text-warn"><b>Koreksi diminta:</b> {w.correction_note}</p>}
          <p className="text-muted">{w.processed ? `Diolah ${formatTanggalJam(w.processed_at)}` : "Belum diolah"}</p></div>}
      </Modal>
    </>
  );
}

function ProcessModal({ open, onClose, kind }) {
  const toast = useToast();
  const res = useAsync(() => waste.list({ kind, pending: 1, per_page: 50, from: "2020-01-01", to: todayISO() }), [kind, open], { enabled: open });
  const [busy, setBusy] = useState(null);
  async function mark(id) {
    setBusy(id);
    try { await waste.process(id); toast.success("Ditandai selesai diolah."); res.reload(); } catch (e) { toast.error(e.message); } finally { setBusy(null); }
  }
  const rows = res.data?.data ?? [];
  return (
    <Modal open={open} onClose={onClose} title="Perbarui pengolahan" subtitle="Tandai setoran yang sudah selesai diolah / disalurkan." footer={<Button variant="secondary" onClick={onClose}>Selesai</Button>}>
      {res.loading && !res.data ? <Skeleton className="h-24" /> : rows.length === 0 ? <EmptyState title="Tidak ada yang menunggu" message="Semua setoran terkonfirmasi sudah diolah." /> : rows.map((r) => (
        <div key={r.id} className="flex items-center justify-between gap-3 rounded-xl bg-canvas px-4 py-3 mb-2 text-sm"><div><p className="font-semibold">{r.code} • {r.depositor_name}</p><p className="text-xs text-muted">{kg(r.total_kg)} • {r.station_name}</p></div><Button size="sm" loading={busy === r.id} onClick={() => mark(r.id)}>Tandai diolah</Button></div>
      ))}
    </Modal>
  );
}

const STEPS = ["1. Lokasi & penyetor", "2. Jenis & berat", "3. Konfirmasi pengguna"];

function Weigh() {
  const { id } = useParams();
  const nav = useNavigate();
  const toast = useToast();
  const meta = useAsync(() => waste.meta(), []);
  const existing = useAsync(() => waste.get(id), [id], { enabled: Boolean(id) });
  const [station, setStation] = useState("");
  const [depositor, setDepositor] = useState(""); // "u:12" | "l:3"
  const [rows, setRows] = useState([{ jenis: "", kg: "" }]);
  const [note, setNote] = useState("");
  const [ready, setReady] = useState(false);
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState("");
  const [seeded, setSeeded] = useState(false);
  const [q, setQ] = useState("");
  const dq = useDebounced(q);

  const checkins = useAsync(() => waste.checkins({ station_code: station }), [station], { interval: 20_000 });
  const found = useAsync(() => usersApi.list({ per_page: 20, q: dq, status: "aktif" }), [dq]);
  const m = meta.data;
  const jenis = m?.jenis ?? [];

  if (existing.data && !seeded) {
    const w = existing.data;
    setSeeded(true); setStation(w.station_code); setDepositor(w.depositor_type === "lapak" ? `l:${w.umkm_id}` : `u:${w.user_id}`);
    setRows(w.items.map((i) => ({ jenis: i.jenis, kg: String(i.kg) }))); setNote(w.note ?? "");
  }

  const calc = useMemo(() => rows.map((r) => {
    const j = jenis.find((x) => x.kode === r.jenis), n = parseFloat(String(r.kg).replace(",", "."));
    return { j, n: Number.isFinite(n) && n > 0 ? n : 0, poin: j && n > 0 ? Math.round(n * j.poin_per_kg) : 0 };
  }), [rows, jenis]);
  const total = calc.reduce((a, c) => a + c.n, 0), poin = calc.reduce((a, c) => a + c.poin, 0);
  const isLapak = depositor.startsWith("l:");
  const scanned = !isLapak && depositor && (checkins.data ?? []).find((c) => `u:${c.user_id}` === depositor);
  const grups = new Set(calc.filter((c) => c.j).map((c) => c.j.grup === "pangan"));
  const mixed = grups.size > 1;
  const setRow = (i, patch) => setRows(rows.map((r, n) => (n === i ? { ...r, ...patch } : r)));
  const userOpts = useMemo(() => {
    const map = new Map();
    (checkins.data ?? []).forEach((c) => map.set(c.user_id, { id: c.user_id, name: c.nama, scanned: true }));
    (found.data?.data ?? []).forEach((u) => { if (!map.has(u.id)) map.set(u.id, { id: u.id, name: u.nama ?? u.name }); });
    return [...map.values()];
  }, [checkins.data, found.data]);

  async function submit(draft) {
    const e = {};
    if (!station) e.station_code = "Pilih tempat timbang.";
    if (!depositor) e.depositor = "Pilih penyetor.";
    if (!calc.some((c) => c.n > 0)) e.items = "Isi minimal satu jenis dengan berat bersih.";
    else if (calc.some((c, i) => (rows[i].jenis || rows[i].kg) && (!c.j || c.n <= 0))) e.items = "Lengkapi jenis dan berat (lebih dari 0) di setiap baris.";
    if (mixed) e.items = "Sampah dan pangan dicatat pada penimbangan terpisah.";
    if (!draft && !ready) e.ready = "Centang bahwa jenis dan berat sudah diperiksa bersama penyetor.";
    setErrors(e);
    if (Object.keys(e).length) return;
    const body = {
      station_code: station, depositor_type: isLapak ? "lapak" : "pengguna", note: note || null, draft,
      ...(isLapak ? { umkm_id: Number(depositor.slice(2)) } : { user_id: Number(depositor.slice(2)) }),
      items: calc.map((c, i) => ({ jenis: rows[i].jenis, kg: c.n })).filter((x) => x.jenis),
    };
    setSaving(draft ? "draft" : "send");
    try {
      const r = id ? await waste.update(id, body) : await waste.create(body);
      toast.success(r.message);
      nav("/sampah-pangan");
    } catch (ex) { setErrors(ex.errors ? Object.fromEntries(Object.entries(ex.errors).map(([k, v]) => [k.split(".")[0], v[0]])) : { form: ex.message }); toast.error(ex.firstError || ex.message); } finally { setSaving(""); }
  }

  const ex = existing.data;
  return (
    <>
      <Link to="/sampah-pangan" className="inline-flex items-center gap-1.5 text-sm font-semibold text-brand-700 hover:underline mb-4"><ArrowLeft size={15} />Sampah & Pangan</Link>
      <PageHeader title="Catat penimbangan" subtitle="Timbang sampah sesuai jenisnya. Pengguna memeriksa hasil sebelum poin masuk." />
      {ex?.status === "perlu_koreksi" && <div className="rounded-xl bg-[#fef1e4] text-warn px-4 py-3 text-sm mb-4"><b>Pengguna meminta koreksi:</b> {ex.correction_note}</div>}
      {errors.form && <div role="alert" className="rounded-xl bg-[#fdeeee] text-danger px-4 py-3 text-sm mb-4">{errors.form}</div>}
      <ol className="grid gap-3 md:grid-cols-3 mb-6">{STEPS.map((s, i) => <li key={s} className={`rounded-xl px-4 py-3 text-sm font-semibold ${i === 1 ? "bg-brand-700 text-white" : "bg-brand-100 text-brand-700"}`}>{i === 0 && station && depositor ? "✓ " : ""}{s}</li>)}</ol>
      <div className="grid gap-6 xl:grid-cols-[1fr_360px] items-start">
        <div className="grid gap-6">
          <Card className="p-5">
            <CardTitle>1. Lokasi & penyetor</CardTitle>
            <div className="grid gap-4 md:grid-cols-2">
              <Field label="Tempat penimbangan" required error={errors.station_code} htmlFor="wg-st"><Select id="wg-st" value={station} onChange={(e) => setStation(e.target.value)} invalid={!!errors.station_code}><option value="">Pilih tempat</option>{(m?.stasiun ?? []).map((t) => <option key={t.kode} value={t.kode}>{t.nama} • {t.kode}</option>)}</Select></Field>
              <Field label="Penyetor" required error={errors.depositor || errors.user_id || errors.umkm_id} htmlFor="wg-dep">
                <Select id="wg-dep" value={depositor} onChange={(e) => setDepositor(e.target.value)} invalid={!!errors.depositor}>
                  <option value="">Pilih penyetor</option>
                  <optgroup label="Pengguna">{userOpts.map((u) => <option key={u.id} value={`u:${u.id}`}>{u.name}{u.scanned ? " ✓ QR dipindai" : ""} • Pengguna</option>)}</optgroup>
                  <optgroup label="Lapak">{(m?.lapak ?? []).map((l) => <option key={l.id} value={`l:${l.id}`}>{l.nama} • Lapak</option>)}</optgroup>
                </Select>
              </Field>
            </div>
            <Input aria-label="Cari pengguna" className="mt-3" placeholder="Cari nama / email pengguna…" value={q} onChange={(e) => setQ(e.target.value)} />
            {depositor && (isLapak ? <p className="rounded-xl bg-canvas px-4 py-3 text-sm text-muted mt-3">Setoran lapak langsung terkonfirmasi (tanpa konfirmasi aplikasi).</p>
              : scanned ? <div className="rounded-xl bg-[#e4efe4] px-4 py-3 mt-3 text-sm"><p className="font-semibold text-ok flex items-center gap-1.5"><Check size={15} />QR lokasi sudah dipindai pengguna</p><p className="text-muted">Terhubung • {formatTanggalJam(scanned.scanned_at)}</p></div>
              : <p className="rounded-xl bg-[#fef1e4] px-4 py-3 mt-3 text-sm text-warn">Pengguna belum memindai QR lokasi. Minta pengguna memindai QR {station || "tempat timbang"} di aplikasi (opsional, tetap bisa dicatat).</p>)}
          </Card>
          <Card className="p-5">
            <CardTitle>2. Jenis & berat sampah</CardTitle>
            <p className="text-sm text-muted -mt-2 mb-4">Gunakan berat bersih tanpa wadah. Pisahkan setiap jenis pada baris berbeda.</p>
            {errors.items && <p role="alert" className="text-sm text-danger mb-3">{errors.items}</p>}
            {rows.map((r, i) => {
              const c = calc[i];
              return (
                <div key={i} className="rounded-xl bg-canvas p-4 mb-3">
                  <div className="flex justify-between text-xs text-muted mb-2"><span className="capitalize">{c.j?.grup ?? "Jenis"}</span>{rows.length > 1 && <button type="button" onClick={() => setRows(rows.filter((_, n) => n !== i))} className="text-warn font-semibold inline-flex items-center gap-1"><Trash2 size={13} />Hapus</button>}</div>
                  <div className="grid gap-3 md:grid-cols-[1fr_1fr_120px] items-end">
                    <Field label="Jenis" htmlFor={`wg-j${i}`}><Select id={`wg-j${i}`} value={r.jenis} onChange={(e) => setRow(i, { jenis: e.target.value })}><option value="">Pilih jenis</option>{jenis.map((j) => <option key={j.kode} value={j.kode}>{j.label} ({j.grup})</option>)}</Select></Field>
                    <Field label="Berat bersih (kg)" htmlFor={`wg-k${i}`}><Input id={`wg-k${i}`} inputMode="decimal" value={r.kg} onChange={(e) => setRow(i, { kg: e.target.value })} placeholder="0,0" /></Field>
                    <div><p className="text-xs text-muted">Poin otomatis</p><p className="font-bold text-brand-700 h-11 flex items-center">{c.poin} poin</p></div>
                  </div>
                </div>
              );
            })}
            <div className="text-center"><Button variant="ghost" icon={Plus} disabled={rows.length >= (m?.maks_baris ?? 10)} onClick={() => setRows([...rows, { jenis: "", kg: "" }])}>Tambah jenis sampah</Button></div>
            <Field label="Catatan petugas (opsional)" className="mt-3" htmlFor="wg-note"><Textarea id="wg-note" rows={2} maxLength={500} value={note} onChange={(e) => setNote(e.target.value)} /></Field>
          </Card>
        </div>
        <Card className="p-5 xl:sticky xl:top-4">
          <CardTitle>Ringkasan setoran</CardTitle>
          <div className="flex justify-between text-sm mb-1"><span className="text-muted">Jumlah jenis</span><b>{calc.filter((c) => c.n > 0).length} jenis</b></div>
          <div className="rounded-xl bg-[#e4efe4] p-4 my-3"><p className="text-sm text-muted">Total berat</p><p className="text-3xl font-bold text-brand-700">{fmtNum(total, 1)} kg</p><p className="font-semibold text-brand-700 mt-1">Estimasi poin: {poin}</p></div>
          <p className="font-semibold text-brand-700 text-sm mb-1">Perhitungan poin</p>
          <div className="text-[13px] text-muted mb-3">{calc.filter((c) => c.j && c.n > 0).map((c, i) => <p key={i}>{c.j.label}: {fmtNum(c.n, 1)} kg × {c.j.poin_per_kg} = {c.poin}</p>)}{total === 0 && <p>Belum ada berat.</p>}</div>
          {!isLapak && <div className="rounded-xl bg-canvas p-3 mb-3"><p className="font-semibold text-sm text-brand-700">Pengguna perlu konfirmasi</p><p className="text-[13px] text-muted">Kirim hasil ke aplikasi pengguna. Poin baru diberikan setelah hasil dinyatakan sesuai.</p></div>}
          <label className="flex items-start gap-2 text-sm mb-1 cursor-pointer"><input type="checkbox" className="mt-1" checked={ready} onChange={(e) => setReady(e.target.checked)} />Saya sudah memeriksa jenis dan berat bersama penyetor.</label>
          {errors.ready && <p className="text-xs text-danger mb-2">{errors.ready}</p>}
          <Button className="w-full mt-3" loading={saving === "send"} onClick={() => submit(false)}>{isLapak ? "Catat setoran lapak" : "Kirim hasil ke pengguna"}</Button>
          <Button variant="ghost" className="w-full mt-2" loading={saving === "draft"} onClick={() => submit(true)}>Simpan draf</Button>
          <p className="text-xs text-muted mt-3">Jika pengguna meminta koreksi, perbaiki data lalu kirim ulang hasilnya.</p>
        </Card>
      </div>
    </>
  );
}
