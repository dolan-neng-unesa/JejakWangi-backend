import { useRef, useState } from "react";
import { Link, Route, Routes, useNavigate, useParams } from "react-router-dom";
import { ArrowLeft, Paperclip, Plus, X } from "lucide-react";
import { PageHeader } from "../components/ui/PageHeader";
import { StatCard } from "../components/ui/StatCard";
import { Card, CardTitle } from "../components/ui/Card";
import { Badge } from "../components/ui/Badge";
import { Button } from "../components/ui/Button";
import { Field, Input, Select, Textarea } from "../components/ui/Field";
import { Pagination } from "../components/ui/Pagination";
import { EmptyState, ErrorState, Skeleton, TableSkeleton } from "../components/ui/States";
import { useAsync, useDebounced } from "../hooks/useAsync";
import { useToast } from "../context/ToastContext";
import { reports } from "../services/jejakwangi";
import { formatTanggal, formatTanggalJam } from "../lib/format";

const TONE = { menunggu: "info", diproses: "warn", selesai: "ok", ditolak: "danger" };
const MAX_FILES = 3, MAX_MB = 5, OK_TYPES = ["image/jpeg", "image/png", "application/pdf"];
const kb = (n) => (n >= 1048576 ? `${(n / 1048576).toFixed(1).replace(".", ",")} MB` : `${Math.max(1, Math.round(n / 1024))} KB`);

export default function LaporKoridorPage() {
  return (
    <Routes>
      <Route index element={<ReportList />} />
      <Route path="baru" element={<ReportForm />} />
      <Route path=":id" element={<ReportDetail />} />
    </Routes>
  );
}

function BackLink({ to, children }) {
  return <Link to={to} className="inline-flex items-center gap-1.5 text-sm font-semibold text-brand-700 hover:underline mb-4"><ArrowLeft size={15} />{children}</Link>;
}

function ReportList() {
  const [page, setPage] = useState(1);
  const [q, setQ] = useState("");
  const [koridor, setKoridor] = useState("");
  const [status, setStatus] = useState("");
  const dq = useDebounced(q);
  const meta = useAsync(() => reports.meta(), []);
  const res = useAsync(() => reports.list({ page, per_page: 6, q: dq, koridor_id: koridor, status }), [page, dq, koridor, status], { interval: 60_000 });
  const d = res.data, rows = d?.data ?? [];
  const reset = (fn) => (e) => { fn(e.target.value); setPage(1); };

  return (
    <>
      <PageHeader title="Lapor Koridor" subtitle="Hubungkan temuan warga dengan tindak lanjut Dinas Lingkungan Hidup.">
        <Link to="baru"><Button icon={Plus}>Lapor Koridor</Button></Link>
      </PageHeader>

      <div className="grid gap-4 grid-cols-2 xl:grid-cols-4 mb-6">
        <StatCard label="Total laporan" value={d?.total ?? 0} loading={res.loading && !d} />
        <StatCard label="Menunggu verifikasi" value={d?.menunggu ?? 0} loading={res.loading && !d} />
        <StatCard label="Diproses DLH" value={d?.diproses ?? 0} loading={res.loading && !d} />
        <StatCard label="Selesai" value={d?.selesai ?? 0} loading={res.loading && !d} />
      </div>

      <Card className="p-5 mb-6">
        <CardTitle>Laporan warga</CardTitle>
        <p className="text-sm text-muted -mt-2 mb-4">Pantau penerimaan, penanggung jawab, dan kabar kembali ke pelapor.</p>
        <div className="grid gap-3 md:grid-cols-[1fr_200px_200px] mb-4">
          <Input aria-label="Cari laporan" placeholder="Cari ID atau judul laporan" value={q} onChange={reset(setQ)} />
          <Select aria-label="Filter koridor" value={koridor} onChange={reset(setKoridor)}>
            <option value="">Semua koridor</option>
            {(meta.data?.koridor ?? []).map((k) => <option key={k.id} value={k.id}>{k.nama}</option>)}
          </Select>
          <Select aria-label="Filter status" value={status} onChange={reset(setStatus)}>
            <option value="">Semua status</option>
            {(meta.data?.status ?? []).map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
          </Select>
        </div>

        {res.error ? <ErrorState error={res.error} onRetry={res.reload} compact /> : res.loading && !d ? <TableSkeleton rows={6} cols={5} /> : rows.length === 0 ? (
          <EmptyState title="Belum ada laporan" message="Tidak ada laporan yang cocok dengan filter." />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm min-w-[760px]">
              <thead><tr className="text-left text-brand-700 text-[13px]">{["ID / tanggal", "Laporan", "Koridor", "Status", "Penanggung jawab", "Aksi"].map((h) => <th key={h} className="py-2.5 px-3 font-semibold">{h}</th>)}</tr></thead>
              <tbody>
                {rows.map((r) => (
                  <tr key={r.id} className="border-t border-brand-100 hover:bg-canvas/60">
                    <td className="py-3 px-3"><p className="text-xs text-muted">{r.code}</p><p className="text-xs text-muted">{formatTanggal(r.created_at, { short: true })}</p></td>
                    <td className="py-3 px-3 font-semibold text-brand-800">{r.title}</td>
                    <td className="py-3 px-3">{r.koridor ?? "—"}</td>
                    <td className="py-3 px-3"><Badge tone={TONE[r.status]}>{r.status_label}</Badge></td>
                    <td className="py-3 px-3">{r.assignee ?? "—"}</td>
                    <td className="py-3 px-3"><Link to={String(r.id)} className="font-semibold text-brand-700 hover:underline">Detail →</Link></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        {d && d.filtered_total > 0 && (
          <div className="flex flex-wrap items-center justify-between gap-3 mt-4">
            <p className="text-[13px] text-muted">Menampilkan {(d.page - 1) * d.per_page + 1}–{(d.page - 1) * d.per_page + rows.length} dari {d.filtered_total} laporan</p>
            <Pagination page={d.page} lastPage={d.last_page} onChange={setPage} />
          </div>
        )}
      </Card>

      <Card className="p-5">
        <CardTitle>Setiap laporan punya jejak tindak lanjut</CardTitle>
        <p className="text-sm font-semibold text-brand-700">01 Dikirim warga → 02 Diverifikasi admin → 03 Ditangani DLH → 04 Warga menerima hasil</p>
        <p className="text-[13px] text-muted mt-3">Penolakan wajib disertai alasan; penyelesaian wajib memiliki catatan dan bukti tindakan.</p>
      </Card>
    </>
  );
}

function FilePicker({ files, setFiles, label, hint, error }) {
  const ref = useRef(null);
  const [msg, setMsg] = useState("");
  function add(list) {
    const next = [...files];
    let m = "";
    for (const f of list) {
      if (!OK_TYPES.includes(f.type)) m = `${f.name}: hanya JPG, PNG, atau PDF.`;
      else if (f.size > MAX_MB * 1048576) m = `${f.name}: maksimal ${MAX_MB} MB per berkas.`;
      else if (next.length >= MAX_FILES) m = `Maksimal ${MAX_FILES} berkas.`;
      else next.push(f);
    }
    setMsg(m); setFiles(next);
    if (ref.current) ref.current.value = "";
  }
  return (
    <div>
      <p className="text-[13px] font-semibold text-brand-700 mb-1.5">{label}</p>
      <div className="rounded-xl bg-canvas border border-dashed border-brand-300 p-4">
        <input ref={ref} type="file" multiple accept=".jpg,.jpeg,.png,.pdf" className="sr-only" aria-label={label} onChange={(e) => add([...e.target.files])} />
        <Button variant="secondary" size="sm" icon={Paperclip} onClick={() => ref.current?.click()}>Tambahkan foto atau dokumen</Button>
        <p className="text-xs text-muted mt-2">JPG, PNG, atau PDF • Maks. {MAX_MB} MB per berkas • Maks. {MAX_FILES} berkas</p>
        {files.map((f, i) => (
          <div key={i} className="flex items-center justify-between gap-2 mt-2 rounded-lg bg-white px-3 py-2 text-sm">
            <span className="truncate">{f.name} • {kb(f.size)}</span>
            <button type="button" aria-label={`Hapus ${f.name}`} onClick={() => setFiles(files.filter((_, j) => j !== i))} className="text-muted hover:text-danger"><X size={15} /></button>
          </div>
        ))}
      </div>
      {(msg || error) ? <p className="text-xs text-danger mt-1.5">{msg || error}</p> : hint && <p className="text-xs text-muted mt-1.5">{hint}</p>}
    </div>
  );
}

function ReportForm() {
  const nav = useNavigate();
  const toast = useToast();
  const meta = useAsync(() => reports.meta(), []);
  const [f, setF] = useState({ koridor_id: "", location: "", category: "", title: "", description: "" });
  const [files, setFiles] = useState([]);
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);
  const set = (k) => (e) => setF((s) => ({ ...s, [k]: e.target.value }));
  const err = (k) => errors[k]?.[0];

  async function submit(e) {
    e.preventDefault();
    const local = {};
    ["koridor_id", "location", "category", "title", "description"].forEach((k) => { if (!String(f[k]).trim()) local[k] = ["Wajib diisi."]; });
    setErrors(local);
    if (Object.keys(local).length) return;
    const body = new FormData();
    Object.entries(f).forEach(([k, v]) => body.append(k, v));
    files.forEach((file) => body.append("evidence[]", file));
    setSaving(true);
    try {
      const r = await reports.create(body);
      toast.success(`Laporan ${r.data.code} terkirim untuk verifikasi.`);
      nav(`/lapor-koridor/${r.data.id}`);
    } catch (ex) {
      setErrors(ex.errors ?? {});
      toast.error(ex.firstError || ex.message);
    } finally { setSaving(false); }
  }

  return (
    <>
      <BackLink to="/lapor-koridor">Daftar laporan</BackLink>
      <PageHeader title="Lapor Koridor kepada DLH" subtitle="Catat temuan warga untuk diverifikasi dan diteruskan kepada DLH." />
      <form onSubmit={submit} noValidate className="grid gap-6 xl:grid-cols-[1fr_360px] items-start">
        <Card className="p-6 grid gap-5">
          <div><CardTitle>Detail temuan</CardTitle><p className="text-xs text-muted -mt-2">Informasi bertanda * wajib diisi.</p></div>
          <Field label="Koridor" required error={err("koridor_id")} htmlFor="lk-koridor">
            <Select id="lk-koridor" value={f.koridor_id} onChange={set("koridor_id")} invalid={!!err("koridor_id")}>
              <option value="">Pilih koridor</option>
              {(meta.data?.koridor ?? []).map((k) => <option key={k.id} value={k.id}>{k.nama}</option>)}
            </Select>
          </Field>
          <Field label="Lokasi kejadian" required error={err("location")} htmlFor="lk-lokasi"><Input id="lk-lokasi" value={f.location} onChange={set("location")} invalid={!!err("location")} placeholder="Jl. Diponegoro, dekat halte koridor 2" maxLength={200} /></Field>
          <Field label="Kategori laporan" required error={err("category")} htmlFor="lk-kat">
            <Select id="lk-kat" value={f.category} onChange={set("category")} invalid={!!err("category")}>
              <option value="">Pilih kategori</option>
              {(meta.data?.kategori ?? []).map((k) => <option key={k.value} value={k.value}>{k.label}</option>)}
            </Select>
          </Field>
          <Field label="Judul laporan" required error={err("title")} htmlFor="lk-judul"><Input id="lk-judul" value={f.title} onChange={set("title")} invalid={!!err("title")} maxLength={120} placeholder="Sampah menumpuk di dekat halte" /></Field>
          <Field label="Deskripsi kejadian" required error={err("description")} htmlFor="lk-desk"><Textarea id="lk-desk" rows={4} value={f.description} onChange={set("description")} invalid={!!err("description")} maxLength={2000} /></Field>
          <FilePicker label="Bukti pendukung" files={files} setFiles={setFiles} hint="Hindari wajah, nomor telepon, dan informasi pribadi dalam bukti." error={err("evidence") || err("evidence.0")} />
        </Card>
        <div className="grid gap-4">
          <Card className="p-5">
            <CardTitle>Tujuan laporan</CardTitle>
            <Badge tone="brand">Dinas Lingkungan Hidup</Badge>
            <p className="font-semibold text-brand-700 mt-3">Banyuwangi</p>
            <p className="text-sm text-muted mt-1">Admin memeriksa kelengkapan sebelum laporan ditugaskan kepada tim DLH yang sesuai.</p>
            <p className="font-semibold text-brand-700 mt-4">Kabar untuk warga</p>
            <p className="text-sm text-muted mt-1">ID laporan dan perubahan status dapat dilihat pelapor. Identitas hanya terlihat oleh petugas berwenang.</p>
          </Card>
          <div className="px-1"><p className="font-semibold text-brand-700 text-sm">Persetujuan pelapor</p><p className="text-[13px] text-muted mt-1">Data digunakan untuk penanganan laporan dan pemberitahuan status. Jangan kirim laporan darurat melalui layanan ini.</p></div>
        </div>
        <div className="xl:col-span-2 flex items-center justify-between gap-3">
          <Link to="/lapor-koridor" className="text-sm font-semibold text-brand-700 hover:underline">Batal</Link>
          <div className="text-right"><Button type="submit" loading={saving}>Kirim untuk verifikasi</Button><p className="text-xs text-muted mt-2">Setelah dikirim, status awal: Menunggu verifikasi.</p></div>
        </div>
      </form>
    </>
  );
}

function ReportDetail() {
  const { id } = useParams();
  const toast = useToast();
  const meta = useAsync(() => reports.meta(), []);
  const res = useAsync(() => reports.get(id), [id]);
  const r = res.data;
  const [form, setForm] = useState(null);
  const [files, setFiles] = useState([]);
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);
  const cur = form ?? (r ? { status: r.status, assignee: r.assignee ?? "", note: "" } : null);
  const set = (k) => (e) => setForm({ ...cur, [k]: e.target.value });

  async function save() {
    const local = {};
    if (!cur.note.trim()) local.note = ["Catatan untuk pelapor wajib diisi."];
    if (cur.status === "selesai" && !files.length && !(r.action_evidence?.length)) local.action_evidence = ["Bukti tindakan wajib dilampirkan jika status Selesai."];
    setErrors(local);
    if (Object.keys(local).length) return;
    const body = new FormData();
    body.append("status", cur.status); body.append("note", cur.note);
    if (cur.assignee) body.append("assignee", cur.assignee);
    files.forEach((f) => body.append("action_evidence[]", f));
    setSaving(true);
    try {
      await reports.update(id, body);
      toast.success("Tindak lanjut disimpan; pelapor akan mendapat kabar.");
      setForm(null); setFiles([]); res.reload();
    } catch (ex) { setErrors(ex.errors ?? {}); toast.error(ex.firstError || ex.message); } finally { setSaving(false); }
  }

  if (res.error) return <><BackLink to="/lapor-koridor">Daftar laporan</BackLink><ErrorState error={res.error} onRetry={res.reload} /></>;
  if (!r) return <Skeleton className="h-96" />;
  const steps = [...(r.history ?? [])];
  const open = !["selesai", "ditolak"].includes(r.status);

  return (
    <>
      <BackLink to="/lapor-koridor">Daftar laporan</BackLink>
      <PageHeader title={`${r.code} • ${r.title}`} subtitle={`${r.koridor ?? "—"} • Dikirim ${formatTanggalJam(r.created_at)}`} />
      <Card className="p-5 mb-6">
        <p className={`text-2xl font-bold ${r.status === "selesai" ? "text-ok" : r.status === "ditolak" ? "text-danger" : "text-warn"}`}>{r.status_label}</p>
        <p className="text-sm text-muted mt-1">Penanggung jawab: {r.assignee ?? "belum ditetapkan"} • Pembaruan terakhir {formatTanggalJam(r.updated_at)}</p>
      </Card>
      <div className="grid gap-6 xl:grid-cols-[1fr_340px] items-start">
        <div className="grid gap-6">
          <Card className="p-5">
            <CardTitle>Informasi kejadian</CardTitle>
            <dl className="grid gap-3 text-sm">
              <div className="grid grid-cols-[120px_1fr]"><dt className="text-muted">Kategori</dt><dd className="font-semibold text-brand-700">{r.category_label}</dd></div>
              <div className="grid grid-cols-[120px_1fr]"><dt className="text-muted">Lokasi</dt><dd className="font-semibold text-brand-700">{r.location}</dd></div>
              <div><dt className="text-muted mb-1">Deskripsi pelapor</dt><dd className="whitespace-pre-line">{r.description}</dd></div>
            </dl>
          </Card>
          <Card className="p-5">
            <CardTitle>Bukti dari pelapor</CardTitle>
            {r.evidence.length === 0 ? <p className="text-sm text-muted">Pelapor tidak melampirkan bukti.</p> : r.evidence.map((f) => (
              <div key={f.url} className="flex items-center justify-between gap-3 rounded-xl bg-canvas px-4 py-3 mb-2 text-sm"><span className="truncate">{f.name} • {kb(f.size)}</span><a href={f.url} target="_blank" rel="noreferrer" className="font-semibold text-brand-700 hover:underline whitespace-nowrap">Lihat bukti →</a></div>
            ))}
            {r.action_evidence.length > 0 && <><p className="text-[13px] font-semibold text-brand-700 mt-4 mb-2">Bukti tindakan DLH</p>{r.action_evidence.map((f) => <div key={f.url} className="flex items-center justify-between gap-3 rounded-xl bg-canvas px-4 py-3 mb-2 text-sm"><span className="truncate">{f.name} • {kb(f.size)}</span><a href={f.url} target="_blank" rel="noreferrer" className="font-semibold text-brand-700 hover:underline whitespace-nowrap">Lihat bukti →</a></div>)}</>}
          </Card>
          <Card className="p-5">
            <CardTitle>Riwayat laporan</CardTitle>
            <ol className="grid gap-4">
              {steps.map((s, i) => (
                <li key={i} className="flex gap-3"><span className="h-7 w-9 shrink-0 rounded-full bg-brand-700 text-white text-xs font-bold inline-flex items-center justify-center">{String(i + 1).padStart(2, "0")}</span>
                  <div><p className="font-semibold text-brand-800">{s.title}</p><p className="text-xs text-muted">{formatTanggalJam(s.at)}</p><p className="text-sm text-muted">{s.desc}</p></div></li>
              ))}
              {open && <li className="flex gap-3 opacity-70"><span className="h-7 w-9 shrink-0 rounded-full bg-canvas text-brand-700 text-xs font-bold inline-flex items-center justify-center">{String(steps.length + 1).padStart(2, "0")}</span><div><p className="font-semibold text-brand-800">Menunggu penyelesaian</p><p className="text-sm text-muted">Bukti tindakan dan notifikasi warga diperlukan.</p></div></li>}
            </ol>
          </Card>
        </div>
        <div className="grid gap-4">
          <Card className="p-5 grid gap-4">
            <CardTitle>Tindak lanjut DLH</CardTitle>
            <Field label="Penanggung jawab" htmlFor="tl-pj"><Select id="tl-pj" value={cur.assignee} onChange={set("assignee")}><option value="">Belum ditetapkan</option>{(meta.data?.penanggung_jawab ?? []).map((a) => <option key={a}>{a}</option>)}</Select></Field>
            <Field label="Ubah status" htmlFor="tl-st"><Select id="tl-st" value={cur.status} onChange={set("status")}>{(meta.data?.status ?? []).map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}</Select></Field>
            <Field label="Catatan untuk pelapor" required error={errors.note?.[0]} htmlFor="tl-note"><Textarea id="tl-note" rows={4} value={cur.note} onChange={set("note")} invalid={!!errors.note} placeholder={r.admin_note || "Tim dijadwalkan membersihkan area halte."} /></Field>
            <FilePicker label="Bukti tindakan" files={files} setFiles={setFiles} hint="Wajib dilampirkan jika status Selesai." error={errors.action_evidence?.[0]} />
            <p className="text-xs text-muted">Pembaruan dibagikan ke pelapor agar warga mengetahui tindakan DLH.</p>
            <Button loading={saving} onClick={save}>Simpan & kabari pelapor</Button>
          </Card>
          <div className="px-1 text-[13px] text-muted grid gap-2"><p className="font-semibold text-brand-700 text-sm">Tutup lingkaran laporan</p><p>Selesai: sertakan hasil dan bukti tindakan.</p><p>Ditolak: jelaskan alasan penolakan.</p><p>Pelapor dapat mengonfirmasi hasil atau meminta peninjauan ulang.</p></div>
        </div>
      </div>
    </>
  );
}
