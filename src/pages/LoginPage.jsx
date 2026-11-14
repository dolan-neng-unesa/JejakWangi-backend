import { useState } from "react";
import { Navigate, useLocation, useNavigate } from "react-router-dom";
import { Eye, EyeOff, Leaf, Loader2 } from "lucide-react";
import { useAuth } from "../context/AuthContext";
import { Modal } from "../components/ui/Modal";
import { Button } from "../components/ui/Button";

const pill =
  "w-full h-[60px] rounded-full border border-white/80 bg-white/[0.04] px-8 text-base text-white placeholder:text-white/90 focus:outline-none focus:ring-2 focus:ring-white/60 focus:bg-white/10 transition";

export default function LoginPage() {
  const { user, login } = useAuth();
  const nav = useNavigate();
  const loc = useLocation();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [show, setShow] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [forgot, setForgot] = useState(false);

  if (user) return <Navigate to={loc.state?.from || "/"} replace />;

  async function submit(e) {
    e.preventDefault();
    setError("");
    if (!/^\S+@\S+\.\S+$/.test(email.trim())) return setError("Masukkan alamat email yang valid (contoh: admin@jejakwangi.id).");
    if (!password) return setError("Kata sandi wajib diisi.");
    setBusy(true);
    try {
      await login(email.trim(), password);
      nav(loc.state?.from || "/", { replace: true });
    } catch (err) {
      setError(err.status === 422 ? err.firstError : err.message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="min-h-full bg-brand-700 flex items-center justify-center px-5 py-10">
      <form onSubmit={submit} className="w-full max-w-[462px] flex flex-col items-center" noValidate>
        <div className="flex items-center gap-3">
          <span className="h-11 w-11 rounded-xl bg-white inline-flex items-center justify-center"><Leaf size={22} className="text-brand-700" /></span>
          <h1 className="text-[44px] leading-none font-bold text-white tracking-wide">JEJAK WANGI</h1>
        </div>
        <p className="text-white text-lg mt-6 mb-12">Sehat. Nyaman. Terhubung.</p>

        <label className="sr-only" htmlFor="email">Email/Username</label>
        <input id="email" type="email" autoComplete="username" autoFocus value={email} onChange={(e) => setEmail(e.target.value)} placeholder="Email/Username" className={pill} />

        <div className="relative w-full mt-10">
          <label className="sr-only" htmlFor="password">Password</label>
          <input id="password" type={show ? "text" : "password"} autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} placeholder="Password" className={`${pill} pr-16`} />
          <button type="button" onClick={() => setShow((s) => !s)} aria-label={show ? "Sembunyikan kata sandi" : "Tampilkan kata sandi"} className="absolute right-6 top-1/2 -translate-y-1/2 text-white/80 hover:text-white">
            {show ? <EyeOff size={20} /> : <Eye size={20} />}
          </button>
        </div>

        <div className="w-full flex justify-end mt-3 pr-6">
          <button type="button" onClick={() => setForgot(true)} className="text-[13px] text-white hover:underline">Forgot password?</button>
        </div>

        <div className="h-12 w-full mt-3 text-center">
          {error && <p role="alert" className="text-sm text-white bg-danger/80 rounded-xl px-4 py-2 inline-block">{error}</p>}
        </div>

        <button type="submit" disabled={busy}
          className="mt-4 h-[68px] w-[206px] rounded-[26px] bg-[#e1e8e3] hover:bg-white text-brand-700 text-[26px] font-medium shadow-[0_2px_6px_rgba(0,0,0,.25)] transition-colors disabled:opacity-80 inline-flex items-center justify-center gap-2">
          {busy ? <Loader2 className="animate-spin" /> : "Login"}
        </button>
      </form>

      <Modal open={forgot} onClose={() => setForgot(false)} title="Lupa kata sandi?" subtitle="Reset dilakukan oleh administrator — JEJAK WANGI belum memakai tautan reset lewat email."
        footer={<Button onClick={() => setForgot(false)}>Mengerti</Button>}>
        <div className="text-sm text-brand-800 space-y-4 leading-relaxed">
          <div>
            <p className="font-semibold text-brand-700 mb-1">Cara 1 — minta admin lain</p>
            <p>Admin yang sedang masuk membuka menu <b>Pengguna</b> → ikon pensil pada akun Anda → isi <b>Kata sandi baru</b>.</p>
          </div>
          <div>
            <p className="font-semibold text-brand-700 mb-1">Cara 2 — lewat server (jika satu-satunya admin)</p>
            <pre className="bg-canvas border border-brand-200 rounded-lg p-3 text-xs overflow-x-auto">{`php artisan tinker
>>> App\\Models\\UserModel::where('email','admin@jejakwangi.id')
...     ->first()->update(['password' => 'sandi-baru']);`}</pre>
          </div>
        </div>
      </Modal>
    </div>
  );
}
