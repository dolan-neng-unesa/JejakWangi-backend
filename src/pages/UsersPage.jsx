import { useEffect, useState } from "react";
import { Eye, Pencil, Plus, Search, Trash2, UserMinus, UserPlus, Users, Activity, Mail, CalendarDays, ShieldCheck } from "lucide-react";
import { PageHeader } from "../components/ui/PageHeader";
import { StatCard } from "../components/ui/StatCard";
import { Card, CardTitle } from "../components/ui/Card";
import { Badge } from "../components/ui/Badge";
import { Button, IconButton } from "../components/ui/Button";
import { ConfirmDialog, Modal } from "../components/ui/Modal";
import { Field, Input, Select, Toggle, FilterSelect } from "../components/ui/Field";
import { Pagination } from "../components/ui/Pagination";
import { EmptyState, ErrorState, TableSkeleton } from "../components/ui/States";
import { useAsync, useDebounced } from "../hooks/useAsync";
import { useToast } from "../context/ToastContext";
import { useAuth } from "../context/AuthContext";
import { users as usersApi } from "../services/svarga";
import { fmtNum, pct } from "../lib/format";

const AVATAR = ["#3f6e45", "#0288d1", "#ed6c02", "#8e24aa", "#00897b", "#6d4c41", "#455a64"];
const initials = (n = "") => n.trim().split(/\s+/).slice(0, 2).map((s) => s[0]?.toUpperCase()).join("") || "?";
const colorOf = (n = "") => AVATAR[[...n].reduce((a, c) => a + c.charCodeAt(0), 0) % AVATAR.length];

export function Avatar({ name, size = 36 }) {
  return <span className="rounded-full inline-flex items-center justify-center text-white font-semibold shrink-0" style={{ width: size, height: size, background: colorOf(name), fontSize: size * 0.38 }}>{initials(name)}</span>;
}

const STATUS_OPTIONS = [{ value: "", label: "Semua" }, { value: "aktif", label: "Aktif" }, { value: "nonaktif", label: "NonAktif" }];

export default function UsersPage() {
  const toast = useToast();
  const { user: me } = useAuth();
  const [q, setQ] = useState("");
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(5);
  const dq = useDebounced(q);

  useEffect(() => { setPage(1); }, [dq, status, perPage]);

  const list = useAsync(() => usersApi.list({ q: dq, status, page, per_page: perPage }), [dq, status, page, perPage]);
  const d = list.data;

  const [form, setForm] = useState(null);   // { mode: 'create'|'edit', user? }
  const [view, setView] = useState(null);
  const [del, setDel] = useState(null);
  const [deleting, setDeleting] = useState(false);

  async function confirmDelete() {
    setDeleting(true);
    try {
      await usersApi.remove(del.id);
      toast.success(`Pengguna "${del.nama}" dihapus.`);
      setDel(null);
      // Bila itu item terakhir di halaman, mundur satu halaman.
      if (d?.data?.length === 1 && page > 1) setPage(page - 1); else list.reload({ silent: true });
    } catch (e) { toast.error(e.message); } finally { setDeleting(false); }
  }

  const from = d && d.filtered_total ? (d.page - 1) * d.per_page + 1 : 0;
  const to = d ? Math.min(d.page * d.per_page, d.filtered_total) : 0;

  return (
    <>
      <PageHeader title="Pengguna" chip="Semua User" subtitle="Manajemen akun pengguna, verifikasi peran, dan pemantauan keaktifan masyarakat Svarga." showRegion>
        <label className="relative">
          <Search size={17} className="absolute left-3.5 top-1/2 -translate-y-1/2 text-brand-700" />
          <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Cari pengguna..." aria-label="Cari pengguna"
            className="h-11 w-52 rounded-xl bg-white border border-brand-200 pl-10 pr-3 text-sm text-brand-800 placeholder:text-brand-700/70 focus:outline-none focus:ring-2 focus:ring-brand-700/25" />
        </label>
        <FilterSelect label="Status" value={status} onChange={setStatus} options={STATUS_OPTIONS} />
        <Button icon={Plus} onClick={() => setForm({ mode: "create" })}>Tambah Pengguna</Button>
      </PageHeader>

      <div className="grid gap-4 grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 mb-4">
        <StatCard label="Total Pengguna" icon={Users} loading={!d && list.loading} value={fmtNum(d?.total)} badge={d && { text: `+${fmtNum(d.baru_7_hari)}`, tone: "info" }} sub="Total terdaftar dalam sistem" />
        <StatCard label="Pengguna Aktif" icon={Activity} loading={!d && list.loading} value={fmtNum(d?.aktif)} badge={d && { text: `${pct(d.aktif, d.total)}%`, tone: "info" }} sub="Akun berstatus aktif" />
        <StatCard label="Pengguna Baru (7 Hari)" icon={UserPlus} loading={!d && list.loading} value={fmtNum(d?.baru_7_hari)} badge={d && { text: `${pct(d.baru_7_hari, d.total)}%`, tone: "ok" }} sub="Pendaftaran seminggu terakhir" />
        <StatCard label="Pengguna NonAktif" icon={UserMinus} loading={!d && list.loading} value={fmtNum(d?.nonaktif)} badge={d && { text: `${pct(d.nonaktif, d.total)}%`, tone: "danger" }} sub="Akun dinonaktifkan sementara" />
      </div>

      <Card className="p-5">
        <CardTitle>Daftar Akun Pengguna Svarga</CardTitle>
        <div className="mt-4 overflow-x-auto">
          <table className="w-full text-sm min-w-[760px]">
            <thead className="bg-canvas">
              <tr>{["Nama", "Email", "Peran", "Status", "Bergabung", "Aksi"].map((h) => <th key={h} className="text-left text-xs font-bold text-brand-700 uppercase tracking-wide px-4 py-3.5">{h}</th>)}</tr>
            </thead>
            {list.loading && !d ? <TableSkeleton cols={6} /> : (
              <tbody>
                {(d?.data ?? []).map((u) => (
                  <tr key={u.id} className="border-b border-brand-200/70 last:border-0 hover:bg-canvas/60">
                    <td className="px-4 py-3">
                      <div className="flex items-center gap-3"><Avatar name={u.nama} /><span className="font-semibold text-brand-700">{u.nama}</span>{u.id === me?.id && <Badge tone="brand">Anda</Badge>}</div>
                    </td>
                    <td className="px-4 py-3 text-brand-700">{u.email}</td>
                    <td className="px-4 py-3 text-brand-700">{u.peran}</td>
                    <td className="px-4 py-3"><Badge tone={u.is_active ? "ok" : "danger"}>{u.status}</Badge></td>
                    <td className="px-4 py-3 text-brand-700 whitespace-nowrap">{u.bergabung}</td>
                    <td className="px-4 py-3">
                      <div className="flex gap-1.5">
                        <IconButton icon={Eye} label="Lihat detail" onClick={() => setView(u)} />
                        <IconButton icon={Pencil} label="Ubah" onClick={() => setForm({ mode: "edit", user: u })} />
                        <IconButton icon={Trash2} tone="danger" label={u.id === me?.id ? "Tidak bisa menghapus akun sendiri" : "Hapus"} disabled={u.id === me?.id} className="disabled:opacity-40" onClick={() => setDel(u)} />
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            )}
          </table>
          {list.error && <ErrorState error={list.error} onRetry={list.reload} />}
          {!list.error && d && d.data.length === 0 && <EmptyState title="Pengguna tidak ditemukan" message={dq || status ? "Coba ubah kata kunci atau filter status." : "Belum ada pengguna terdaftar."} />}
        </div>

        {d && (
          <div className="flex flex-wrap items-center justify-between gap-3 mt-4">
            <div className="flex items-center gap-3 text-[13px] text-brand-700">
              <span>Menampilkan {from}-{to} dari {fmtNum(d.filtered_total)} pengguna</span>
              <select value={perPage} onChange={(e) => setPerPage(Number(e.target.value))} aria-label="Jumlah per halaman" className="h-8 rounded-md border border-brand-200 bg-white px-2 text-xs">
                {[5, 10, 25, 50].map((n) => <option key={n} value={n}>{n} / hal</option>)}
              </select>
            </div>
            {d.last_page > 1 && <Pagination page={d.page} lastPage={d.last_page} onChange={setPage} />}
          </div>
        )}
      </Card>

      {form && <UserForm mode={form.mode} user={form.user} me={me} onClose={() => setForm(null)} onSaved={() => { setForm(null); list.reload({ silent: true }); }} />}
      <ViewModal u={view} onClose={() => setView(null)} onEdit={(u) => { setView(null); setForm({ mode: "edit", user: u }); }} />
      <ConfirmDialog open={Boolean(del)} loading={deleting} onClose={() => setDel(null)} onConfirm={confirmDelete} title="Hapus pengguna?" confirmLabel="Hapus"
        message={<>Akun <b>{del?.nama}</b> ({del?.email}) akan dihapus permanen. Catatan mood yang pernah dikirim tetap tersimpan secara anonim.</>} />
    </>
  );
}

function UserForm({ mode, user, me, onClose, onSaved }) {
  const toast = useToast();
  const edit = mode === "edit";
  const self = edit && user.id === me?.id;
  const [f, setF] = useState({ name: user?.nama ?? "", email: user?.email ?? "", password: "", is_admin: user?.is_admin ?? false, is_active: user?.is_active ?? true, umkm_id: user?.umkm_id ?? "" });
  const [errors, setErrors] = useState({});
  const [busy, setBusy] = useState(false);
  const set = (k) => (v) => setF((s) => ({ ...s, [k]: v }));

  async function submit(e) {
    e.preventDefault();
    const err = {};
    if (!f.name.trim()) err.name = "Nama wajib diisi.";
    if (!/^\S+@\S+\.\S+$/.test(f.email)) err.email = "Email tidak valid.";
    if (!edit && f.password.length < 8) err.password = "Kata sandi minimal 8 karakter.";
    if (edit && f.password && f.password.length < 8) err.password = "Kata sandi minimal 8 karakter.";
    setErrors(err);
    if (Object.keys(err).length) return;

    setBusy(true);
    try {
      const body = { name: f.name.trim(), email: f.email.trim(), is_admin: f.is_admin, is_active: f.is_active };
      if (f.password) body.password = f.password;
      // Akun kasir UMKM: kosong = bukan kasir. Saat ubah, kirim null agar tautan lama bisa dicabut.
      const umkm = String(f.umkm_id).trim();
      if (umkm) body.umkm_id = Number(umkm); else if (edit) body.umkm_id = null;
      if (edit) await usersApi.update(user.id, body); else await usersApi.create(body);
      toast.success(edit ? "Pengguna diperbarui." : "Pengguna ditambahkan.");
      onSaved();
    } catch (e2) {
      if (e2.errors) setErrors(Object.fromEntries(Object.entries(e2.errors).map(([k, v]) => [k, v[0]])));
      else toast.error(e2.message);
    } finally { setBusy(false); }
  }

  return (
    <Modal open onClose={onClose} title={edit ? "Ubah Pengguna" : "Tambah Pengguna"} subtitle={edit ? user.email : "Akun baru langsung dapat login ke aplikasi SVARGA."}
      footer={<><Button variant="secondary" onClick={onClose}>Batal</Button><Button loading={busy} onClick={submit}>{edit ? "Simpan Perubahan" : "Tambah Pengguna"}</Button></>}>
      <form onSubmit={submit} className="space-y-4" noValidate>
        <Field label="Nama lengkap" required error={errors.name} htmlFor="u-name"><Input id="u-name" value={f.name} onChange={(e) => set("name")(e.target.value)} invalid={!!errors.name} autoFocus /></Field>
        <Field label="Email" required error={errors.email} htmlFor="u-email"><Input id="u-email" type="email" value={f.email} onChange={(e) => set("email")(e.target.value)} invalid={!!errors.email} /></Field>
        <Field label={edit ? "Kata sandi baru" : "Kata sandi"} required={!edit} error={errors.password} hint={edit ? "Kosongkan bila tidak ingin mengubah." : "Minimal 8 karakter."} htmlFor="u-pass">
          <Input id="u-pass" type="password" autoComplete="new-password" value={f.password} onChange={(e) => set("password")(e.target.value)} invalid={!!errors.password} />
        </Field>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Peran" htmlFor="u-role" hint={self ? "Anda tidak dapat mencabut hak admin akun sendiri." : undefined}>
            <Select id="u-role" value={f.is_admin ? "admin" : "user"} disabled={self} onChange={(e) => set("is_admin")(e.target.value === "admin")}><option value="user">User</option><option value="admin">Admin</option></Select>
          </Field>
          <Field label="Status akun" hint={self ? "Anda tidak dapat menonaktifkan akun sendiri." : undefined}>
            <div className="h-11 flex items-center gap-3"><Toggle checked={f.is_active} onChange={set("is_active")} disabled={self} label="Status aktif" /><span className="text-sm text-brand-700">{f.is_active ? "Aktif" : "NonAktif"}</span></div>
          </Field>
        </div>
        <Field label="ID UMKM (akun kasir)" error={errors.umkm_id} htmlFor="u-umkm" hint="Isi untuk menjadikan akun ini kasir UMKM tersebut (dapat membuat QR “membawa tempat sendiri”). Kosongkan bila bukan kasir.">
          <Input id="u-umkm" inputMode="numeric" value={f.umkm_id} onChange={(e) => set("umkm_id")(e.target.value.replace(/\D/g, ""))} invalid={!!errors.umkm_id} placeholder="mis. 1" />
        </Field>
        <button type="submit" className="hidden" />
      </form>
    </Modal>
  );
}

function ViewModal({ u, onClose, onEdit }) {
  if (!u) return null;
  const rows = [[Mail, "Email", u.email], [ShieldCheck, "Peran", u.peran], [CalendarDays, "Bergabung", u.bergabung]];
  return (
    <Modal open onClose={onClose} title="Detail Pengguna" footer={<><Button variant="secondary" onClick={onClose}>Tutup</Button><Button icon={Pencil} onClick={() => onEdit(u)}>Ubah</Button></>}>
      <div className="flex items-center gap-4 mb-5">
        <Avatar name={u.nama} size={56} />
        <div><p className="text-lg font-semibold text-brand-700">{u.nama}</p><Badge tone={u.is_active ? "ok" : "danger"}>{u.status}</Badge></div>
      </div>
      <ul className="divide-y divide-brand-200 rounded-xl border border-brand-200">
        {rows.map(([Icon, label, value]) => (
          <li key={label} className="flex items-center gap-3 px-4 py-3 text-sm"><Icon size={17} className="text-brand-500" /><span className="w-24 text-muted">{label}</span><span className="font-medium text-brand-700">{value}</span></li>
        ))}
        <li className="flex items-center gap-3 px-4 py-3 text-sm"><span className="w-[17px]" /><span className="w-24 text-muted">ID Akun</span><span className="font-medium text-brand-700">#{u.id}</span></li>
      </ul>
    </Modal>
  );
}
