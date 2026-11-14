import { act, createElement } from "react";
import { createRoot } from "react-dom/client";
import { MemoryRouter } from "react-router-dom";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

globalThis.IS_REACT_ACT_ENVIRONMENT = true;
const json = (body, status = 200) => new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });
const mounted = [];
let host;

async function render(path, Page) {
  const { ToastProvider } = await import("../context/ToastContext");
  host = document.createElement("div");
  document.body.appendChild(host);
  const root = createRoot(host);
  mounted.push(root);
  await act(async () => root.render(createElement(ToastProvider, null, createElement(MemoryRouter, { initialEntries: [path] }, createElement(Page)))));
}
async function mountAt(path, base, Page) {
  const { Route, Routes } = await import("react-router-dom");
  await render(path, () => createElement(Routes, null, createElement(Route, { path: `${base}/*`, element: createElement(Page) })));
}
const flush = () => act(async () => { await new Promise((r) => setTimeout(r, 30)); });
function type(el, value) {
  const proto = el.tagName === "SELECT" ? HTMLSelectElement.prototype : el.tagName === "TEXTAREA" ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
  Object.getOwnPropertyDescriptor(proto, "value").set.call(el, value);
  el.dispatchEvent(new Event(el.tagName === "SELECT" ? "change" : "input", { bubbles: true }));
}
const byText = (re, tag = "button") => [...host.querySelectorAll(tag)].find((n) => re.test(n.textContent));

const META = { data: {
  stasiun: [{ kode: "BSS-001", nama: "Sritanjung" }],
  jenis: [{ kode: "sisa_sayur", label: "Sisa sayur", grup: "organik", poin_per_kg: 10 }, { kode: "botol_plastik", label: "Botol plastik", grup: "anorganik", poin_per_kg: 40 }, { kode: "nasi_lauk", label: "Nasi & lauk layak", grup: "pangan", poin_per_kg: 20 }],
  status: [], maks_baris: 10, lapak: [{ id: 3, nama: "Lapak Bu Sri" }],
} };

beforeEach(() => { localStorage.clear(); vi.resetModules(); });
afterEach(async () => {
  while (mounted.length) await act(async () => mounted.pop().unmount());
  host?.remove();
  vi.unstubAllGlobals();
});

describe("Catat penimbangan", () => {
  function stub(post) {
    const fetchMock = vi.fn(async (url, init = {}) => {
      const u = String(url);
      if (init.method === "POST" && u.endsWith("/admin/waste")) return post(init);
      if (u.includes("/admin/waste/meta")) return json(META);
      if (u.includes("/admin/waste/checkins")) return json({ data: [{ user_id: 7, nama: "Sari", station_code: "BSS-001", scanned_at: new Date().toISOString() }] });
      if (u.includes("/admin/users")) return json({ data: [] });
      return json({});
    });
    vi.stubGlobal("fetch", fetchMock);
    return fetchMock;
  }

  it("menghitung poin otomatis seperti desain (2 kg×10 + 1,5 kg×40 = 80) dan menandai QR dipindai", async () => {
    stub(() => json({}));
    const { default: Page } = await import("../pages/SampahPanganPage");
    await mountAt("/sampah-pangan/catat", "sampah-pangan", Page);
    await flush();
    type(host.querySelector("#wg-st"), "BSS-001");
    await flush();
    type(host.querySelector("#wg-dep"), "u:7");
    type(host.querySelector("#wg-j0"), "sisa_sayur");
    type(host.querySelector("#wg-k0"), "2,0");
    await act(async () => byText(/Tambah jenis/).click());
    type(host.querySelector("#wg-j1"), "botol_plastik");
    type(host.querySelector("#wg-k1"), "1,5");
    expect(host.textContent).toContain("Estimasi poin: 80");
    expect(host.textContent).toContain("3,5 kg");
    expect(host.textContent).toContain("QR lokasi sudah dipindai pengguna");
  });

  it("menolak kirim tanpa centang pemeriksaan, lalu mengirim body yang benar", async () => {
    const post = vi.fn(async () => json({ message: "Hasil dikirim ke Sari." }, 201));
    const fm = stub(post);
    const { default: Page } = await import("../pages/SampahPanganPage");
    await mountAt("/sampah-pangan/catat", "sampah-pangan", Page);
    await flush();
    type(host.querySelector("#wg-st"), "BSS-001");
    await flush();
    type(host.querySelector("#wg-dep"), "u:7");
    type(host.querySelector("#wg-j0"), "sisa_sayur");
    type(host.querySelector("#wg-k0"), "2");
    await act(async () => byText(/Kirim hasil ke pengguna/).click());
    expect(post).not.toHaveBeenCalled();
    expect(host.textContent).toContain("Centang bahwa jenis dan berat sudah diperiksa");
    await act(async () => host.querySelector("input[type=checkbox]").click());
    await act(async () => byText(/Kirim hasil ke pengguna/).click());
    await flush();
    expect(post).toHaveBeenCalledTimes(1);
    const body = JSON.parse(post.mock.calls[0][0].body);
    expect(body).toMatchObject({ station_code: "BSS-001", depositor_type: "pengguna", user_id: 7, draft: false, items: [{ jenis: "sisa_sayur", kg: 2 }] });
    expect(fm).toHaveBeenCalled();
  });

  it("menolak mencampur sampah dengan pangan", async () => {
    const post = vi.fn(async () => json({}));
    stub(post);
    const { default: Page } = await import("../pages/SampahPanganPage");
    await mountAt("/sampah-pangan/catat", "sampah-pangan", Page);
    await flush();
    type(host.querySelector("#wg-st"), "BSS-001");
    type(host.querySelector("#wg-dep"), "l:3");
    type(host.querySelector("#wg-j0"), "sisa_sayur");
    type(host.querySelector("#wg-k0"), "1");
    await act(async () => byText(/Tambah jenis/).click());
    type(host.querySelector("#wg-j1"), "nasi_lauk");
    type(host.querySelector("#wg-k1"), "1");
    await act(async () => host.querySelector("input[type=checkbox]").click());
    await act(async () => byText(/Catat setoran lapak/).click());
    expect(post).not.toHaveBeenCalled();
    expect(host.textContent).toContain("terpisah");
  });
});

describe("Detail Lapor Koridor", () => {
  it("status Selesai tanpa bukti tindakan tidak dikirim ke server", async () => {
    const report = { id: 5, code: "KRD-0005", title: "Sampah menumpuk", koridor: "Koridor 2", category_label: "Sampah & kebersihan", status: "diproses", status_label: "Diproses DLH", assignee: "Tim Kebersihan", location: "Jl. A", description: "x", evidence: [], action_evidence: [], history: [{ title: "Laporan diterima", desc: "ok", at: new Date().toISOString() }], created_at: new Date().toISOString(), updated_at: new Date().toISOString() };
    const fetchMock = vi.fn(async (url, init = {}) => {
      const u = String(url);
      if (init.method === "POST") return json({});
      if (u.includes("/reports/meta")) return json({ data: { status: [{ value: "diproses", label: "Diproses DLH" }, { value: "selesai", label: "Selesai" }], penanggung_jawab: ["Tim Kebersihan"], kategori: [], koridor: [] } });
      return json({ data: report });
    });
    vi.stubGlobal("fetch", fetchMock);
    const { default: Page } = await import("../pages/LaporKoridorPage");
    await mountAt("/lapor-koridor/5", "lapor-koridor", Page);
    await flush();
    expect(host.textContent).toContain("KRD-0005");
    type(host.querySelector("#tl-st"), "selesai");
    type(host.querySelector("#tl-note"), "Sudah dibersihkan");
    await act(async () => byText(/Simpan & kabari pelapor/).click());
    expect(host.textContent).toContain("Bukti tindakan wajib dilampirkan");
    expect(fetchMock.mock.calls.some(([, i]) => i?.method === "POST")).toBe(false);
  });
});
